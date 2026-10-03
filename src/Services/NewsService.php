<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;

/**
 * The NPC journalist. The game picks the stories (a deterministic newsworthiness score) and supplies every
 * fact; the LLM only writes prose around slot names, and ContentValidator rejects anything that adds a fact.
 * Names and interview quotes are inserted by this class, never by the model.
 */
class NewsService
{
    /** Event keys: id + type offset, so one BIGINT[] column can reference several source tables. */
    private const OFFSET = ['attack' => 1_000_000_000_000, 'align' => 2_000_000_000_000, 'bounty' => 3_000_000_000_000, 'rank' => 4_000_000_000_000];

    public function __construct(
        private Database $db,
        private ContentValidator $validator,
        private TextFilter $filter,
        private PressService $press,
        private ProtectionService $protection,
        private array $config
    ) {}

    private function cfg(string $key, mixed $default = null): mixed
    {
        return $this->config['news'][$key] ?? $default;
    }

    public function enabled(): bool
    {
        return (bool)$this->cfg('journalist_enabled', false);
    }

    // =================================================================== scoring (pure)

    /** score = base x magnitude x prominence x rivalry x recency */
    public function score(string $type, float $magnitude, ?int $bestRank, int $priorClashes, float $ageHours): float
    {
        $base = (float)($this->cfg('base_scores', [])[$type] ?? 5);
        return round($base * $this->magnitude($magnitude) * $this->prominence($bestRank) * $this->rivalry($priorClashes) * $this->recency($ageHours), 2);
    }

    /** Log-scaled involvement (credits, fighters or score), from 1 to 3. */
    public function magnitude(float $amount): float
    {
        return max(1.0, min(3.0, 1 + log10(max(1.0, $amount)) / 2));
    }

    /** 1 + 0.1 per rank place inside the top 20 (rank 1 gives the max of 3). */
    public function prominence(?int $rank): float
    {
        return $rank === null || $rank < 1 || $rank > 20 ? 1.0 : min(3.0, 1 + 0.1 * (21 - $rank));
    }

    /** 1.5 if the same two players or teams clashed in the last 7 days, 2 if three or more times. */
    public function rivalry(int $priorClashes): float
    {
        return $priorClashes >= 3 ? 2.0 : ($priorClashes >= 1 ? 1.5 : 1.0);
    }

    /** Halves every 12 hours. */
    public function recency(float $ageHours): float
    {
        return pow(0.5, max(0.0, $ageHours) / (float)$this->cfg('recency_half_life_hours', 12));
    }

    // =================================================================== candidate scan (every 30 minutes)

    /**
     * Score recent events and queue candidates; start interviews for the big ones.
     * @return array{candidates: int, interviews: int, skipped: int}
     */
    public function scan(): array
    {
        $out = ['candidates' => 0, 'interviews' => 0, 'skipped' => 0];
        if (!$this->enabled()) {
            return $out;
        }
        $ranks = $this->topRanks();
        $events = array_merge($this->combatEvents(), $this->alignmentEvents(), $this->bountyEvents(), $this->rankEvents($ranks));
        $covered = $this->coveredEventKeys();
        $events = array_values(array_filter($events, fn($e) => !isset($covered[$e['key']])));
        if (!$this->cfg('include_npc_only', false)) {
            $events = array_values(array_filter($events, fn($e) => $this->hasHuman($e['participants'])));
        }
        foreach ($events as &$e) {
            $best = null;
            foreach ($e['participants'] as $p) {
                if (isset($ranks[$p]) && ($best === null || $ranks[$p] < $best)) {
                    $best = $ranks[$p];
                }
            }
            $clashes = count($e['participants']) >= 2 ? $this->priorClashes((int)$e['participants'][0], (int)$e['participants'][1], (int)$e['key']) : 0;
            $e['score'] = $this->score($e['type'], (float)$e['magnitude'], $best, $clashes, (time() - $e['at']) / 3600);
            $e['clashes'] = $clashes;
        }
        unset($e);

        // Merge runs of events between the same two parties into one candidate.
        $groups = [];
        foreach ($events as $e) {
            $k = count($e['participants']) >= 2 ? $e['type'] . ':' . implode('-', $this->sortedPair($e['participants'])) : 'solo:' . $e['key'];
            $groups[$k][] = $e;
        }
        $min = (float)$this->cfg('min_score', 20);
        foreach ($groups as $group) {
            usort($group, fn($a, $b) => $b['score'] <=> $a['score']);
            $lead = $group[0];
            $score = round($lead['score'] * (1 + 0.1 * (count($group) - 1)), 2);
            if ($score < $min) {
                $out['skipped']++;
                continue;
            }
            $sheet = $this->factSheet($lead, count($group));
            $interview = $score >= (float)$this->cfg('interview_score', 50) && $lead['type'] !== 'rank_top10';
            $row = $this->db->fetchOne(
                "INSERT INTO news_candidates (score, fact_sheet, event_ids, status, kind)
                 VALUES (:s, CAST(:f AS JSONB), CAST(:e AS BIGINT[]), 'open', 'event') RETURNING id",
                ['s' => $score, 'f' => json_encode($sheet), 'e' => '{' . implode(',', array_column($group, 'key')) . '}']
            );
            $out['candidates']++;
            if ($interview && $this->startInterviews((int)$row['id'], $sheet)) {
                $out['interviews']++;
            }
        }
        $this->db->execute("UPDATE news_candidates SET status = 'skipped' WHERE status = 'open' AND created_at < now() - make_interval(hours => :h)",
            ['h' => (int)$this->cfg('lookback_hours', 48)]);
        $this->maybeDigest();
        return $out;
    }

    /** @return array<int,int> ship_id => rank (1 = highest score) for the top 20 players */
    private function topRanks(): array
    {
        $ranks = [];
        foreach ($this->db->fetchAll('SELECT ship_id FROM ships WHERE is_npc = FALSE AND ship_destroyed = FALSE ORDER BY score DESC, ship_id LIMIT 20') as $i => $r) {
            $ranks[(int)$r['ship_id']] = $i + 1;
        }
        return $ranks;
    }

    private function combatEvents(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT a.log_id, a.attacker_id, a.defender_id, a.attack_type, a.sector, a.damage_dealt,
                    EXTRACT(EPOCH FROM a.timestamp) AS at, COALESCE(d.score, 0) AS defender_score
             FROM attack_logs a LEFT JOIN ships d ON d.ship_id = a.defender_id
             WHERE a.result = 'destroyed' AND a.attack_type IN ('ship', 'planet') AND a.defender_id IS NOT NULL
               AND a.timestamp > now() - make_interval(hours => :h)",
            ['h' => (int)$this->cfg('lookback_hours', 48)]
        );
        return array_map(fn($r) => [
            'key' => self::OFFSET['attack'] + (int)$r['log_id'], 'type' => $r['attack_type'] === 'planet' ? 'planet_captured' : 'ship_destroyed',
            'participants' => [(int)$r['attacker_id'], (int)$r['defender_id']], 'sector' => (int)$r['sector'],
            'magnitude' => max((float)$r['damage_dealt'], (float)$r['defender_score']), 'at' => (int)$r['at'],
        ], $rows);
    }

    private function alignmentEvents(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT l.id, l.ship_id, l.delta, EXTRACT(EPOCH FROM l.created_at) AS at, s.sector
             FROM alignment_log l JOIN ships s ON s.ship_id = l.ship_id
             WHERE l.reason IN ('fedspace_hostile_action', 'contraband_in_fedspace') AND l.created_at > now() - make_interval(hours => :h)",
            ['h' => (int)$this->cfg('lookback_hours', 48)]
        );
        return array_map(fn($r) => [
            'key' => self::OFFSET['align'] + (int)$r['id'], 'type' => 'wanted_set', 'participants' => [(int)$r['ship_id']],
            'sector' => (int)$r['sector'], 'magnitude' => abs((float)$r['delta']), 'at' => (int)$r['at'],
        ], $rows);
    }

    private function bountyEvents(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, target_id, claimed_by, amount, EXTRACT(EPOCH FROM claimed_at) AS at FROM bounties
             WHERE claimed_by IS NOT NULL AND claimed_at > now() - make_interval(hours => :h)",
            ['h' => (int)$this->cfg('lookback_hours', 48)]
        );
        return array_map(fn($r) => [
            'key' => self::OFFSET['bounty'] + (int)$r['id'], 'type' => 'bounty_claimed', 'participants' => [(int)$r['claimed_by'], (int)$r['target_id']],
            'sector' => null, 'magnitude' => (float)$r['amount'], 'at' => (int)$r['at'],
        ], $rows);
    }

    /** New entrants to the top 10 since the last scan (the previous top 10 is remembered in npc_settings). */
    private function rankEvents(array $ranks): array
    {
        $top = array_keys(array_filter($ranks, fn($r) => $r <= 10));
        $prev = $this->db->fetchOne("SELECT value FROM npc_settings WHERE key = 'news_top10'");
        $this->db->getConnection()->prepare(
            "INSERT INTO npc_settings (key, value, updated_at) VALUES ('news_top10', :v, now())
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()"
        )->execute(['v' => implode(',', $top)]);
        if (!$prev) {
            return [];   // first scan only records the baseline
        }
        $before = array_map('intval', array_filter(explode(',', (string)$prev['value'])));
        $out = [];
        foreach (array_diff($top, $before) as $shipId) {
            $row = $this->db->fetchOne('SELECT score, sector FROM ships WHERE ship_id = :id', ['id' => $shipId]);
            $out[] = ['key' => self::OFFSET['rank'] + $shipId * 10000 + (intdiv(time(), 86400) % 10000), 'type' => 'rank_top10',
                'participants' => [$shipId], 'sector' => (int)($row['sector'] ?? 0), 'magnitude' => (float)($row['score'] ?? 0), 'at' => time(), 'rank' => $ranks[$shipId] ?? null];
        }
        return $out;
    }

    /** @return array<int,true> event keys already used by a candidate within the lookback window */
    private function coveredEventKeys(): array
    {
        $out = [];
        foreach ($this->db->fetchAll(
            "SELECT unnest(event_ids) AS k FROM news_candidates WHERE created_at > now() - make_interval(hours => :h)",
            ['h' => (int)$this->cfg('lookback_hours', 48) * 2]
        ) as $r) {
            $out[(int)$r['k']] = true;
        }
        return $out;
    }

    private function hasHuman(array $shipIds): bool
    {
        $ids = array_filter(array_map('intval', $shipIds));
        if (!$ids) {
            return false;
        }
        return (bool)$this->db->fetchOne('SELECT 1 AS x FROM ships WHERE is_npc = FALSE AND ship_id = ANY(CAST(:ids AS INT[])) LIMIT 1', ['ids' => '{' . implode(',', $ids) . '}']);
    }

    private function sortedPair(array $participants): array
    {
        $p = array_map('intval', array_slice($participants, 0, 2));
        sort($p);
        return $p;
    }

    /** Earlier clashes (7 days) between the same two players, or their teams, excluding the event itself. */
    private function priorClashes(int $a, int $b, int $exceptKey): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM attack_logs l
             JOIN ships x ON x.ship_id = l.attacker_id JOIN ships y ON y.ship_id = l.defender_id
             WHERE l.timestamp > now() - interval '7 days' AND l.log_id <> :self
               AND l.attack_type IN ('ship', 'planet') AND l.defender_id IS NOT NULL
               AND ((l.attacker_id = :a AND l.defender_id = :b) OR (l.attacker_id = :b2 AND l.defender_id = :a2)
                    OR (x.team <> 0 AND y.team <> 0 AND x.team <> y.team
                        AND ((x.team = (SELECT team FROM ships WHERE ship_id = :a3) AND y.team = (SELECT team FROM ships WHERE ship_id = :b3))
                          OR (x.team = (SELECT team FROM ships WHERE ship_id = :b4) AND y.team = (SELECT team FROM ships WHERE ship_id = :a4)))))",
            ['self' => $exceptKey - self::OFFSET['attack'], 'a' => $a, 'b' => $b, 'b2' => $b, 'a2' => $a, 'a3' => $a, 'b3' => $b, 'b4' => $b, 'a4' => $a]
        );
        return (int)$row['c'];
    }

    // =================================================================== fact sheets

    /** Everything the story may say. Slot values are what the system substitutes; the model only sees slot names. */
    private function factSheet(array $e, int $merged): array
    {
        $slots = [];
        $roles = [];
        $names = [];
        foreach ($e['participants'] as $i => $shipId) {
            $row = $this->db->fetchOne('SELECT s.character_name, s.alignment, s.is_npc, s.team, t.team_name, p.faction FROM ships s
                LEFT JOIN teams t ON t.id = s.team LEFT JOIN npc_profiles p ON p.ship_id = s.ship_id WHERE s.ship_id = :id', ['id' => (int)$shipId]);
            if (!$row) {
                continue;
            }
            $slot = match ($e['type']) {
                'ship_destroyed', 'planet_captured' => $i === 0 ? 'attacker' : 'defender',
                'bounty_claimed' => $i === 0 ? 'hunter' : 'target',
                default => 'captain',
            };
            $names[$slot] = (string)$row['character_name'];
            $tier = $this->tierLabel((int)$row['alignment']);
            $roles[$slot] = ['tier' => $tier, 'kind' => $row['is_npc'] ? 'NPC ' . ($row['faction'] ?? '') : 'player', 'ship_id' => (int)$shipId];
            $slots[$slot] = (string)$row['character_name'];
            if (!empty($row['team_name']) && !isset($slots['team'])) {
                $slots['team'] = (string)$row['team_name'];
            }
        }
        $facts = ['event_type' => $e['type'], 'roles' => $roles, 'merged_events' => $merged, 'prior_clashes_7d' => $e['clashes'] ?? 0];
        if (!empty($e['sector'])) {
            $slots['sector'] = 'sector ' . (int)$e['sector'];
        }
        if ($e['type'] === 'bounty_claimed') {
            $slots['credits'] = number_format((int)$e['magnitude']) . ' credits';
        }
        $required = array_values(array_intersect(['attacker', 'defender', 'hunter', 'target', 'captain'], array_keys($slots)));
        return ['kind' => 'event', 'facts' => $facts, 'slots' => $slots, 'required' => $required, 'age_hours' => round((time() - $e['at']) / 3600, 1)];
    }

    private function tierLabel(int $alignment): string
    {
        $t = $this->config['alignment']['tiers'];
        return match (true) {
            $alignment >= $t['paragon'] => 'Paragon', $alignment >= $t['lawful'] => 'Lawful', $alignment >= $t['neutral'] => 'Neutral',
            $alignment >= $t['outlaw'] => 'Outlaw', default => 'Pirate',
        };
    }

    /** The view of a candidate the worker gets: player-written strings only inside <<< >>>, never raw. */
    public function promptView(array $candidate): array
    {
        $sheet = json_decode((string)$candidate['fact_sheet'], true) ?: [];
        $sheet = $this->withQuotes((int)$candidate['id'], $sheet);
        $slotsForModel = [];
        foreach ($sheet['slots'] as $name => $value) {
            $slotsForModel[$name] = str_starts_with($name, 'quote_') || in_array($name, ['sector', 'credits'], true)
                ? ($name === 'sector' || $name === 'credits' ? (string)$value : UntrustedText::wrap((string)$value, 200))
                : UntrustedText::wrap((string)$value, 60);
        }
        return [
            'id' => (int)$candidate['id'], 'score' => (float)$candidate['score'], 'kind' => $candidate['kind'] ?? 'event',
            'facts' => $sheet['facts'] ?? $sheet['items'] ?? [], 'slots' => $slotsForModel, 'required_slots' => $sheet['required'] ?? [],
            'slot_names' => array_keys($sheet['slots']),
        ];
    }

    // =================================================================== interviews

    private function startInterviews(int $candidateId, array $sheet): bool
    {
        $ids = [];
        foreach (($sheet['facts']['roles'] ?? []) as $role) {
            $ids[] = (int)$role['ship_id'];
        }
        $asked = 0;
        $max = (int)$this->cfg('interview_max_participants', 2);
        foreach ($ids as $shipId) {
            if ($asked >= $max) {
                break;
            }
            $ship = $this->db->fetchOne('SELECT * FROM ships WHERE ship_id = :id AND is_npc = FALSE AND ship_destroyed = FALSE', ['id' => $shipId]);
            if (!$ship || $ship['interview_opt_out'] || $this->protection->isProtected($ship)) {
                continue;
            }
            $question = $this->question($sheet, $shipId, (string)$ship['character_name']);
            $this->db->execute('INSERT INTO interview_requests (candidate_id, ship_id, question) VALUES (:c, :s, :q)',
                ['c' => $candidateId, 's' => $shipId, 'q' => $question]);
            $hours = (int)$this->cfg('interview_window_hours', 2);
            $this->press->notify($shipId, 'A question from the Galactic Courier',
                $question . "\n\nReply to this message within $hours hours to be quoted in the Galactic News. Only your first reply is used, cut to "
                . (int)$this->cfg('quote_max_words', 25) . ' words. Replying means you agree to be quoted. You can opt out of interview requests on your status page.');
            $asked++;
        }
        if ($asked > 0) {
            $this->db->execute("UPDATE news_candidates SET status = 'interviewing', ready_at = now() + make_interval(hours => :h) WHERE id = :id",
                ['h' => (int)$this->cfg('interview_window_hours', 2), 'id' => $candidateId]);
        }
        return $asked > 0;
    }

    /** One short question built from the fact sheet (templated, so it cannot add facts either). */
    private function question(array $sheet, int $shipId, string $name): string
    {
        $slots = $sheet['slots'];
        $where = $slots['sector'] ?? 'the lanes';
        $role = null;
        foreach ($sheet['facts']['roles'] as $slot => $r) {
            if ((int)$r['ship_id'] === $shipId) {
                $role = $slot;
            }
        }
        return match ($sheet['facts']['event_type'] . ':' . $role) {
            'ship_destroyed:attacker' => "$name, you took out " . ($slots['defender'] ?? 'a rival') . " at $where. What drove that?",
            'ship_destroyed:defender' => "$name, you lost your ship to " . ($slots['attacker'] ?? 'a rival') . " at $where. What do you make of it?",
            'planet_captured:attacker' => "$name, you took a world from " . ($slots['defender'] ?? 'a rival') . " at $where. Why that one?",
            'planet_captured:defender' => "$name, " . ($slots['attacker'] ?? 'a rival') . " has taken your world at $where. What next?",
            'bounty_claimed:hunter' => "$name, you collected the bounty on " . ($slots['target'] ?? 'a target') . ". Was it worth it?",
            'bounty_claimed:target' => "$name, the bounty on you has been claimed. Any words for the hunter?",
            'wanted_set:captain' => "$name, the Federation has named you Wanted. Care to respond?",
            default => "$name, the Courier would like a word about recent events. What is your side?",
        };
    }

    /** Attach the first in-window reply from each interviewed player as {{quote_n}} slots. */
    private function withQuotes(int $candidateId, array $sheet): array
    {
        if (isset($sheet['slots']['quote_1'])) {
            return $sheet;
        }
        $n = 0;
        foreach ($this->db->fetchAll('SELECT * FROM interview_requests WHERE candidate_id = :c ORDER BY id', ['c' => $candidateId]) as $req) {
            $reply = $req['reply'];
            if ($reply === null) {
                $reply = $this->captureReply($req);
            }
            if ($reply === null || $reply === '') {
                continue;
            }
            $n++;
            $sheet['slots']["quote_$n"] = $reply;
            $sheet['facts']['quotes']["quote_$n"] = ['speaker_ship_id' => (int)$req['ship_id']];
        }
        return $sheet;
    }

    /** First reply to the Courier inside the window; 25 words, profanity filtered. */
    private function captureReply(array $req): ?string
    {
        $press = $this->press->reporterId(false);
        if ($press === null) {
            return null;
        }
        $msg = $this->db->fetchOne(
            "SELECT message FROM messages WHERE from_id = :s AND to_id = :p AND sent_at >= :asked AND sent_at <= CAST(:asked2 AS TIMESTAMP) + make_interval(hours => :h)
             ORDER BY sent_at, message_id LIMIT 1",
            ['s' => (int)$req['ship_id'], 'p' => $press, 'asked' => $req['asked_at'], 'asked2' => $req['asked_at'], 'h' => (int)$this->cfg('interview_window_hours', 2)]
        );
        if (!$msg) {
            return null;
        }
        $words = preg_split('/\s+/u', trim(strip_tags((string)$msg['message'])), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $text = implode(' ', array_slice($words, 0, (int)$this->cfg('quote_max_words', 25)));
        $text = str_replace(['{{', '}}', '"', '“', '”'], '', $text);
        if ($text === '' || $this->filter->hasProfanity($text)) {
            return null;
        }
        $this->db->execute('UPDATE interview_requests SET reply = :r, replied_at = now() WHERE id = :id', ['r' => mb_substr($text, 0, 300), 'id' => (int)$req['id']]);
        return $text;
    }

    // =================================================================== what the worker may write

    /** Candidates ready to be written, best first, limited by what is left of today's quota. */
    public function readyCandidates(): array
    {
        if (!$this->enabled()) {
            return [];
        }
        $remaining = (int)$this->cfg('max_stories_per_day', 6) - $this->storiesToday();
        $rows = $this->db->fetchAll(
            "SELECT c.*, (c.ready_at IS NOT NULL AND c.ready_at > now()) AS window_open FROM news_candidates c
             WHERE c.status IN ('open', 'interviewing') ORDER BY (c.kind = 'digest') DESC, c.score DESC LIMIT 20"
        );
        $out = [];
        foreach ($rows as $r) {
            if ($r['status'] === 'interviewing') {
                // Pick up any replies; the story waits until everyone has answered or the window closes.
                $this->withQuotes((int)$r['id'], json_decode((string)$r['fact_sheet'], true) ?: ['slots' => []]);
                $unanswered = (int)$this->db->fetchOne('SELECT COUNT(*) AS c FROM interview_requests WHERE candidate_id = :c AND reply IS NULL', ['c' => (int)$r['id']])['c'];
                if ($unanswered > 0 && $r['window_open']) {
                    continue;
                }
            }
            if ($r['kind'] !== 'digest') {
                if ($remaining <= 0) {
                    continue;
                }
                $remaining--;
            }
            $out[] = $this->promptView($r);
        }
        return $out;
    }

    public function storiesToday(): int
    {
        return (int)$this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM news WHERE source = 'journalist' AND news_type = 'journalist' AND status IN ('published', 'pending_review')
             AND date >= date_trunc('day', now())"
        )['c'];
    }

    /**
     * Validate and publish a story. On rejection the worker retries once, then submits fallback = true.
     * @return array{accepted: bool, errors?: string[], news_id?: int, status?: string, code?: string}
     */
    public function submitStory(int $candidateId, string $headline, string $body, bool $fallback = false): array
    {
        $cand = $this->db->fetchOne('SELECT * FROM news_candidates WHERE id = :id', ['id' => $candidateId]);
        if (!$cand || !in_array($cand['status'], ['open', 'interviewing'], true)) {
            return ['accepted' => false, 'code' => 'NOT_FOUND', 'errors' => ['candidate not found or already handled']];
        }
        if ($fallback) {
            // The existing template news item already tells the story accurately.
            $this->db->execute("UPDATE news_candidates SET status = 'fallback' WHERE id = :id", ['id' => $candidateId]);
            return ['accepted' => true, 'status' => 'fallback'];
        }
        $isDigest = ($cand['kind'] ?? 'event') === 'digest';
        if (!$isDigest && $this->storiesToday() >= (int)$this->cfg('max_stories_per_day', 6)) {
            return ['accepted' => false, 'code' => 'QUOTA', 'errors' => ['daily story limit reached']];
        }
        $sheet = $this->withQuotes($candidateId, json_decode((string)$cand['fact_sheet'], true) ?: []);
        $allowed = array_keys($sheet['slots']);
        $required = $sheet['required'] ?? [];
        $headline = trim($headline);
        $body = trim($body);
        $errors = [];
        foreach ($this->validator->validate($headline, $allowed, [], ['max_chars' => 80]) as $e) {
            $errors[] = "headline $e";
        }
        foreach ($this->validator->validate($body, $allowed, $required, ['max_words' => 120]) as $e) {
            $errors[] = "story $e";
        }
        // Quotation marks are only allowed around a {{quote_n}} slot: the system inserts every quote.
        $stripped = preg_replace('/["“]\s*\{\{\s*quote_\d+\s*\}\}\s*[,.!?]?\s*["”]/u', ' ', $body) ?? '';
        if (preg_match('/["“”]/u', $stripped) || preg_match('/["“”]/u', $headline)) {
            $errors[] = 'story contains an unattributed quotation';
        }
        if ($errors) {
            return ['accepted' => false, 'code' => 'STORY_REJECTED', 'errors' => array_values(array_unique($errors))];
        }
        $values = $sheet['slots'];
        $text = ContentValidator::fill($body, $values);
        $title = ContentValidator::fill($headline, $values);
        $status = $this->cfg('review_mode', true) ? 'pending_review' : 'published';
        $news = $this->db->fetchOne(
            "INSERT INTO news (headline, newstext, user_id, news_type, source, status)
             VALUES (:h, :t, :u, :type, 'journalist', :st) RETURNING news_id",
            ['h' => mb_substr($title, 0, 200), 't' => $text, 'u' => $this->press->reporterId(false), 'type' => $isDigest ? 'digest' : 'journalist', 'st' => $status]
        );
        $this->db->execute("UPDATE news_candidates SET status = 'written', news_id = :n WHERE id = :id", ['n' => (int)$news['news_id'], 'id' => $candidateId]);
        return ['accepted' => true, 'news_id' => (int)$news['news_id'], 'status' => $status];
    }

    // =================================================================== daily digest

    private function maybeDigest(): void
    {
        $recent = $this->db->fetchOne("SELECT 1 AS x FROM news_candidates WHERE kind = 'digest' AND created_at > now() - interval '23 hours'");
        if ($recent) {
            return;
        }
        $rest = $this->db->fetchAll(
            "SELECT * FROM news_candidates WHERE kind = 'event' AND status IN ('open', 'skipped', 'fallback') AND created_at > now() - interval '24 hours'
             ORDER BY score DESC LIMIT 5"
        );
        if (count($rest) < 2) {
            return;
        }
        $slots = [];
        $items = [];
        $required = [];
        foreach ($rest as $i => $r) {
            $sheet = json_decode((string)$r['fact_sheet'], true) ?: [];
            $n = $i + 1;
            $items[] = ['event_type' => $sheet['facts']['event_type'] ?? 'event', 'item' => $n];
            foreach ($sheet['slots'] as $name => $value) {
                if (in_array($name, ['attacker', 'defender', 'hunter', 'target', 'captain', 'sector'], true)) {
                    $slots["i{$n}_$name"] = $value;
                }
            }
            foreach (['attacker', 'hunter', 'captain'] as $lead) {
                if ($i < 2 && isset($sheet['slots'][$lead])) {
                    $required[] = "i{$n}_$lead";
                }
            }
        }
        $this->db->execute(
            "INSERT INTO news_candidates (score, fact_sheet, event_ids, status, kind) VALUES (0, CAST(:f AS JSONB), '{}', 'open', 'digest')",
            ['f' => json_encode(['kind' => 'digest', 'items' => $items, 'slots' => $slots, 'required' => $required])]
        );
    }

    // =================================================================== admin

    public function pending(): array
    {
        return $this->db->fetchAll(
            "SELECT n.news_id, n.headline, n.newstext, n.date, n.news_type, c.id AS candidate_id, c.score, c.fact_sheet
             FROM news n LEFT JOIN news_candidates c ON c.news_id = n.news_id
             WHERE n.source = 'journalist' AND n.status = 'pending_review' ORDER BY n.date"
        );
    }

    public function approve(int $newsId, ?string $headline = null, ?string $body = null): bool
    {
        $sets = "status = 'published', date = now()";
        $params = ['id' => $newsId];
        if ($headline !== null && $body !== null) {
            $sets .= ', headline = :h, newstext = :t';
            $params += ['h' => mb_substr(trim($headline), 0, 200), 't' => trim($body)];
        }
        return $this->db->execute("UPDATE news SET $sets WHERE news_id = :id AND source = 'journalist' AND status = 'pending_review'", $params);
    }

    public function reject(int $newsId, string $reason): bool
    {
        $row = $this->db->fetchOne("SELECT * FROM news WHERE news_id = :id AND source = 'journalist' AND status = 'pending_review'", ['id' => $newsId]);
        if (!$row) {
            return false;
        }
        $this->db->execute("UPDATE news SET status = 'rejected' WHERE news_id = :id", ['id' => $newsId]);
        $this->audit('_news_rejected', ['news_id' => $newsId, 'reason' => $reason, 'headline' => $row['headline']]);
        return true;
    }

    /** Replace a published story with a one-line notice. The original is kept in the audit log. */
    public function retract(int $newsId, string $reason): bool
    {
        $row = $this->db->fetchOne("SELECT * FROM news WHERE news_id = :id AND source = 'journalist' AND status = 'published'", ['id' => $newsId]);
        if (!$row) {
            return false;
        }
        $this->db->execute(
            "UPDATE news SET headline = 'Courier retraction', newstext = :t, news_type = 'retraction' WHERE news_id = :id",
            ['t' => 'The Galactic Courier has retracted an earlier story.', 'id' => $newsId]
        );
        $this->audit('_news_retracted', ['news_id' => $newsId, 'reason' => $reason, 'headline' => $row['headline'], 'text' => $row['newstext']]);
        return true;
    }

    private function audit(string $tool, array $result): void
    {
        $press = $this->press->reporterId(false);
        $this->db->getConnection()->prepare(
            'INSERT INTO npc_action_log (ship_id, wake_id, step, tool, result) VALUES (:s, CAST(:w AS UUID), 0, :t, CAST(:r AS JSONB))'
        )->execute(['s' => $press ?? 0, 'w' => self::uuid(), 't' => $tool, 'r' => json_encode($result)]);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
