<?php

declare(strict_types=1);

namespace BNT\Services;

/**
 * Validates LLM-written text before players see it. The model writes prose around fact slots
 * ({{sector}}, {{attacker}}, ...); this class rejects anything that adds a fact of its own.
 *
 * An output is rejected if it:
 *  - contains a digit outside a slot,
 *  - contains a capitalised word that is not a slot, an allow-listed game term, or a sentence start,
 *  - uses a slot that is not in its fact sheet, or omits a required one,
 *  - exceeds its length limit,
 *  - fails the player profanity filter or matches the real-world blocklist,
 *  - places an accusation term within 10 words of a player slot.
 */
class ContentValidator
{
    /** Game terms that may be capitalised mid-sentence. */
    private const GAME_TERMS = ['Federation', 'Xenobe', 'Xenobes', 'Galactic', 'Courier', 'Raiders', 'Raider', 'Guild', 'Void', 'Relics',
        'Ore', 'Organics', 'Goods', 'Energy', 'Free', 'Captains', 'Police', 'Paragon', 'Lawful', 'Neutral', 'Outlaw', 'Pirate', 'I'];

    /** Real-world places, brands and people the in-universe voice must never mention. */
    private const REAL_WORLD = ['america', 'american', 'united states', 'usa', 'canada', 'mexico', 'china', 'chinese', 'japan', 'russia', 'russian',
        'ukraine', 'india', 'germany', 'france', 'england', 'britain', 'europe', 'european', 'africa', 'asia', 'australia', 'israel', 'iran', 'iraq',
        'london', 'paris', 'berlin', 'moscow', 'tokyo', 'beijing', 'new york', 'los angeles', 'washington', 'texas', 'california',
        'google', 'apple', 'microsoft', 'amazon', 'facebook', 'meta', 'twitter', 'tesla', 'spacex', 'nasa', 'openai', 'anthropic', 'netflix', 'disney',
        'trump', 'biden', 'obama', 'putin', 'musk', 'bezos', 'zuckerberg', 'hitler', 'nazi', 'covid', 'earth', 'mars', 'jesus', 'allah', 'god'];

    private const ACCUSATIONS = '/^(cheat\w*|exploit\w*|bot|bots|hack\w*|multi-?account\w*|alt-?account\w*|scam\w*)$/i';

    /** Slots that stand for a player or team (accusation terms may not sit near them). */
    private const PLAYER_SLOT = '/^(attacker|defender|name|player|winner|loser|team|owner|ship\w*|captain\w*|quote_\d+|participant\w*)$/i';

    public function __construct(private TextFilter $filter, private array $config = []) {}

    /**
     * @param string[] $allowedSlots slots present in the fact sheet
     * @param string[] $requiredSlots slots the text must use
     * @param array{max_chars?: int, max_words?: int} $limits
     * @return string[] error messages; empty when valid
     */
    public function validate(string $text, array $allowedSlots, array $requiredSlots = [], array $limits = []): array
    {
        $errors = [];
        $text = trim($text);
        if ($text === '') {
            return ['empty text'];
        }
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $text, $m);
        $used = array_values(array_unique($m[1]));
        foreach ($used as $slot) {
            if (!in_array($slot, $allowedSlots, true)) {
                $errors[] = "uses unknown slot {{{$slot}}}";
            }
        }
        foreach ($requiredSlots as $slot) {
            if (!in_array($slot, $used, true)) {
                $errors[] = "omits required slot {{{$slot}}}";
            }
        }
        // Stray braces that are not a well-formed slot
        $bare = preg_replace('/\{\{\s*[a-zA-Z0-9_]+\s*\}\}/', ' ', $text) ?? '';
        if (preg_match('/[{}]/', $bare)) {
            $errors[] = 'contains a malformed slot';
        }

        if (isset($limits['max_chars']) && mb_strlen($text) > $limits['max_chars']) {
            $errors[] = "longer than {$limits['max_chars']} characters";
        }
        if (isset($limits['max_words']) && count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []) > $limits['max_words']) {
            $errors[] = "longer than {$limits['max_words']} words";
        }

        if (preg_match('/\d/', $bare)) {
            $errors[] = 'contains a digit outside a slot';
        }
        $errors = array_merge($errors, $this->capitalisation($bare));

        if ($this->filter->hasProfanity($text)) {
            $errors[] = 'fails the profanity filter';
        }
        $lower = ' ' . mb_strtolower(preg_replace('/[^\p{L}\p{N}\' -]+/u', ' ', $bare) ?? '') . ' ';
        foreach (self::REAL_WORLD as $term) {
            if (str_contains($lower, ' ' . $term . ' ') || str_contains($lower, ' ' . $term . "'s ")) {
                $errors[] = "mentions a real-world term ('$term')";
                break;
            }
        }
        if ($this->accusationNearPlayer($text)) {
            $errors[] = 'places an accusation near a player slot';
        }
        return array_values(array_unique($errors));
    }

    /** @return string[] */
    private function capitalisation(string $bare): array
    {
        $errors = [];
        // Tokenise keeping positions so sentence starts can be recognised.
        if (!preg_match_all('/[\p{L}\']+|[.!?:"“”\n]/u', $bare, $tokens)) {
            return $errors;
        }
        $startOfSentence = true;
        foreach ($tokens[0] as $tok) {
            if (preg_match('/^[.!?:"“”\n]$/u', $tok)) {
                if ($tok !== '"' && $tok !== '“' && $tok !== '”') {
                    $startOfSentence = true;
                }
                continue;
            }
            $first = mb_substr($tok, 0, 1);
            $isCap = mb_strtolower($first) !== $first;
            if ($isCap && !$startOfSentence && !in_array(rtrim($tok, "'s"), self::GAME_TERMS, true) && !in_array($tok, self::GAME_TERMS, true)) {
                $errors[] = "contains a capitalised word that is not a slot or game term ('$tok')";
            }
            $startOfSentence = false;
        }
        return $errors;
    }

    private function accusationNearPlayer(string $text): bool
    {
        $words = preg_split('/\s+/u', preg_replace('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', ' SLOT:$1 ', $text) ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $playerAt = [];
        $accuseAt = [];
        foreach ($words as $i => $w) {
            if (str_starts_with($w, 'SLOT:') && preg_match(self::PLAYER_SLOT, substr(rtrim($w, '.,;:!?'), 5))) {
                $playerAt[] = $i;
            } elseif (preg_match(self::ACCUSATIONS, trim($w, '.,;:!?"\'()'))) {
                $accuseAt[] = $i;
            }
        }
        foreach ($accuseAt as $a) {
            foreach ($playerAt as $p) {
                if (abs($a - $p) <= 10) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Replace {{slot}} markers with values. Unknown slots are left alone (validation prevents them). */
    public static function fill(string $template, array $values): string
    {
        return (string)preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function ($m) use ($values) {
            return array_key_exists($m[1], $values) ? (string)$values[$m[1]] : $m[0];
        }, $template);
    }

    /** @return string[] slot names used in a template */
    public static function slotsIn(string $template): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $template, $m);
        return array_values(array_unique($m[1]));
    }
}
