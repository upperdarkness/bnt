<?php

declare(strict_types=1);

namespace BNT\NpcAgent;

/**
 * The LLM's tool surface. Each tool maps to exactly one public API call; there is
 * deliberately no tool for IGB transfers, team management or moving credits to
 * another player. Arguments are validated against a JSON schema before anything
 * is sent, and every result is sanitised so player-written text is delimited.
 */
class Tools
{
    private const PLAYER_TEXT_KEYS = ['character_name', 'planet_name', 'owner_name', 'team_name', 'sector_name', 'ship_name',
        'from_name', 'subject', 'message', 'beacon', 'name', 'text'];
    private const DROP_KEYS = ['password_hash', 'email', 'token', 'trade_credit_accum', 'api_key'];
    private const SHIP_KEEP = ['ship_id', 'sector', 'turns', 'credits', 'armor_pts', 'ship_fighters', 'torps', 'ship_ore', 'ship_organics', 'ship_goods', 'ship_energy', 'ship_colonists'];
    private const COMMODITIES = ['ore', 'organics', 'goods', 'energy'];
    private const COMPONENTS = ['hull', 'engines', 'power', 'computer', 'sensors', 'beams', 'torp_launchers', 'shields', 'armor', 'cloak'];

    public function __construct(private ApiClient $api, private ?PromptGuard $guard = null) {}

    /** OpenAI/OpenRouter tool definitions. */
    public function definitions(): array
    {
        $int = static fn(int $min = 1) => ['type' => 'integer', 'minimum' => $min];
        $tools = [
            ['go_to', 'Travel to a sector along the shortest route. Stops early at mines, sector fighters or a threatening ship.',
                ['sector' => $int()], ['sector']],
            ['scan', 'Detailed scan of the current sector: ships, planets, defences, links.', [], []],
            ['find_trade', 'Best known buy/sell pairs among ports you have discovered, within max_hops.',
                ['max_hops' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]], ['max_hops']],
            ['trade', 'Buy or sell a commodity at the port in this sector. "contraband" only trades at black markets and costs alignment.',
                ['commodity' => ['type' => 'string', 'enum' => [...self::COMMODITIES, 'contraband']], 'action' => ['type' => 'string', 'enum' => ['buy', 'sell']], 'amount' => $int()],
                ['commodity', 'action', 'amount']],
            ['attack_ship', 'Attack a ship in this sector (costs a turn). Only attack clearly weaker ships.', ['ship_id' => $int()], ['ship_id']],
            ['deploy_defences', 'Deploy fighters and/or mines in this sector.',
                ['fighters' => $int(0), 'mines' => $int(0)], []],
            ['land', 'Land on a planet in this sector.', ['planet_id' => $int()], ['planet_id']],
            ['leave', 'Leave the planet you are on.', ['planet_id' => $int()], ['planet_id']],
            ['planet_transfer', 'Move cargo to or from a planet you own and are landed on.',
                ['planet_id' => $int(), 'commodity' => ['type' => 'string', 'enum' => [...self::COMMODITIES, 'colonists', 'fighters', 'credits']],
                 'amount' => $int(), 'direction' => ['type' => 'string', 'enum' => ['to_planet', 'to_ship']]],
                ['planet_id', 'commodity', 'amount', 'direction']],
            ['buy_upgrade', 'Buy one upgrade level for a ship component (starbase sector only).',
                ['component' => ['type' => 'string', 'enum' => self::COMPONENTS]], ['component']],
            ['send_message', 'Send a short in-character message (max 280 characters) to a ship in sight or that messaged you in the last 24h. 5 per hour.',
                ['ship_id' => $int(), 'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 280]], ['ship_id', 'text']],
            ['update_notebook', 'Replace your private notebook (max 2000 characters). It survives death and respawn.',
                ['text' => ['type' => 'string', 'maxLength' => 2000]], ['text']],
            ['buy_rumour', 'Buy a rumour at the port in this sector (costs a turn and credits). Rumours are usually, not always, true. "tavern" names a region; "informant" the exact sector.',
                ['tier' => ['type' => 'string', 'enum' => ['tavern', 'informant']]], ['tier']],
            ['end_turn', 'Finish this wake with a one-line summary for the admin log.',
                ['summary' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200]], ['summary']],
        ];
        $out = [];
        foreach ($tools as [$name, $desc, $props, $required]) {
            $out[] = ['type' => 'function', 'function' => [
                'name' => $name, 'description' => $desc,
                'parameters' => ['type' => 'object', 'properties' => $props === [] ? new \stdClass() : $props,
                    'required' => $required, 'additionalProperties' => false],
            ]];
        }
        return $out;
    }

    private function schemaFor(string $name): ?array
    {
        foreach ($this->definitions() as $d) {
            if ($d['function']['name'] === $name) {
                $schema = $d['function']['parameters'];
                if ($schema['properties'] instanceof \stdClass) {
                    $schema['properties'] = [];
                }
                return $schema;
            }
        }
        return null;
    }

    /**
     * Validate and run one tool call.
     *
     * @return array{result: array, terminal: bool, valid: bool, status: ?int}
     */
    public function execute(string $name, mixed $arguments, string $token): array
    {
        $schema = $this->schemaFor($name);
        if ($schema === null) {
            return $this->err("Unknown tool '$name'", false);
        }
        if (!is_array($arguments)) {
            return $this->err('Arguments must be a JSON object', false);
        }
        $errors = SchemaValidator::validate($arguments, $schema);
        if ($errors) {
            return $this->err('Invalid arguments: ' . implode('; ', $errors), false);
        }

        $a = $arguments;
        switch ($name) {
            case 'go_to':
                return $this->api('POST', 'agent/go_to/' . $a['sector'], $token);
            case 'scan':
                return $this->api('GET', 'game/scan', $token);
            case 'find_trade':
                return $this->api('GET', 'agent/trades', $token, null, ['max_hops' => $a['max_hops']]);
            case 'trade':
                return $this->api('POST', 'game/port/trade', $token, ['commodity' => $a['commodity'], 'action' => $a['action'], 'amount' => $a['amount']]);
            case 'attack_ship':
                return $this->api('POST', 'game/attack/ship/' . $a['ship_id'], $token);
            case 'deploy_defences':
                if (($a['fighters'] ?? 0) + ($a['mines'] ?? 0) <= 0) {
                    return $this->err('Provide a positive number of fighters and/or mines', false);
                }
                return $this->api('POST', 'game/defences', $token, array_filter(['fighters' => $a['fighters'] ?? 0, 'mines' => $a['mines'] ?? 0]));
            case 'land':
                return $this->api('POST', 'game/land/' . $a['planet_id'], $token);
            case 'leave':
                return $this->api('POST', 'game/leave', $token);
            case 'planet_transfer':
                return $this->api('POST', 'game/planet/' . $a['planet_id'] . '/transfer', $token,
                    ['commodity' => $a['commodity'], 'amount' => $a['amount'], 'direction' => $a['direction']]);
            case 'buy_upgrade':
                return $this->api('POST', 'game/upgrade/' . $a['component'], $token);
            case 'send_message':
                if ($this->guard && $this->guard->leaks($a['text'])) {
                    return $this->err('Message blocked: it repeats your instructions. Say something else, in character.', true);
                }
                return $this->api('POST', 'game/messages', $token, ['ship_id' => $a['ship_id'], 'text' => $a['text']]);
            case 'buy_rumour':
                return $this->api('POST', 'game/rumours/buy', $token, ['tier' => $a['tier']]);
            case 'update_notebook':
                return $this->api('POST', 'agent/notebook', $token, ['text' => $a['text']]);
            case 'end_turn':
                return ['result' => ['ok' => true, 'summary' => $a['summary']], 'terminal' => true, 'valid' => true, 'status' => 200];
        }
        return $this->err("Unknown tool '$name'", false);
    }

    private function api(string $method, string $path, string $token, ?array $json = null, array $query = []): array
    {
        $r = $this->api->call($method, $path, $token, $json, $query);
        $body = $r['body'];
        if (!empty($body['success'])) {
            $data = $body['data'] ?? [];
            $out = ['ok' => true] + (isset($body['message']) ? ['message' => $body['message']] : []) + (is_array($data) ? self::sanitize($data) : []);
        } else {
            $out = ['ok' => false, 'error' => (string)($body['error']['message'] ?? 'Request failed'), 'code' => $body['error']['code'] ?? null];
        }
        return ['result' => $out, 'terminal' => false, 'valid' => true, 'status' => $r['status']];
    }

    private function err(string $message, bool $valid): array
    {
        return ['result' => ['ok' => false, 'error' => $message], 'terminal' => false, 'valid' => $valid, 'status' => null];
    }

    /**
     * Recursively prepare API data for the model: delimit player text, drop secrets,
     * slim the ship record, and bound the size.
     */
    public static function sanitize(array $data, int $depth = 0): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array($k, self::DROP_KEYS, true)) {
                continue;
            }
            if ($k === 'ship' && is_array($v)) {
                $out['ship'] = array_intersect_key($v, array_flip(self::SHIP_KEEP));
                continue;
            }
            if (is_string($k) && in_array($k, self::PLAYER_TEXT_KEYS, true) && (is_string($v) || $v === null)) {
                $out[$k] = $v === null ? null : '<<<' . self::clean($v) . '>>>';
                continue;
            }
            if (is_array($v)) {
                $out[$k] = $depth > 6 ? '…' : self::sanitize($v, $depth + 1);
                continue;
            }
            $out[$k] = is_string($v) ? mb_substr($v, 0, 300) : $v;
        }
        return $out;
    }

    public static function clean(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = str_replace(['<', '>'], '', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        return mb_strlen($text) > 300 ? mb_substr($text, 0, 299) . '…' : $text;
    }

    /** JSON for the tool message, bounded so a huge scan cannot blow the token budget. */
    public static function encodeForModel(array $result, int $maxChars = 3500): string
    {
        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        return mb_strlen($json) > $maxChars ? mb_substr($json, 0, $maxChars - 20) . '…[truncated]' : $json;
    }
}
