<?php

declare(strict_types=1);

namespace BNT\Tests;

use BNT\NpcAgent\ApiClient;
use BNT\NpcAgent\OpenRouterClient;
use BNT\NpcAgent\PromptGuard;
use BNT\NpcAgent\Prompts;
use BNT\NpcAgent\Store;
use BNT\NpcAgent\Tools;
use BNT\NpcAgent\Worker;

/**
 * Shared plumbing for tests that run the LLM worker against the real game API.
 * The using class must define: static int $gamePort, static string $tokensFile, static string $openRouterUrl,
 * static string $openRouterKey, static string $mockDir (mock only).
 */
trait WorkerHarness
{
    // ------------------------------------------------------------ helpers

    private function settings(array $kv): void
    {
        foreach ($kv as $k => $v) {
            $this->svc('npcSettings')->set($k, $v);
        }
    }

    private function queue(array $responses): void
    {
        file_put_contents(self::$mockDir . '/queue.json', json_encode($responses));
    }

    private function requests(): array
    {
        return json_decode((string)file_get_contents(self::$mockDir . '/requests.json'), true) ?: [];
    }

    /** @return array{0:int,1:string} */
    private function llmNpc(string $faction = 'free', array $opts = []): array
    {
        $n = $this->svc('npcService')->spawn($faction, $opts + ['sector' => 5, 'controller' => 'llm']);
        $token = $this->svc('npcService')->issueToken($n['ship_id'])['token'];
        $tokens = is_file(self::$tokensFile) ? json_decode((string)file_get_contents(self::$tokensFile), true) : [];
        $tokens[(string)$n['ship_id']] = $token;
        file_put_contents(self::$tokensFile, json_encode($tokens));
        $this->db()->execute('UPDATE ships SET turns = 300 WHERE ship_id = :id', ['id' => $n['ship_id']]);
        return [$n['ship_id'], $token];
    }

    private function worker(array $npcOverrides = []): Worker
    {
        $npc = $npcOverrides + self::$config['npc'];
        $store = new Store(Store::connect(self::$config['database']), $npc);
        $prompts = new Prompts(dirname(__DIR__, 2) . '/config/npc_prompts');
        $api = new ApiClient('http://127.0.0.1:' . self::$gamePort . '/api/v1');
        return new Worker(
            $store, $api,
            new OpenRouterClient(self::$openRouterUrl, self::$openRouterKey),
            new Tools($api, new PromptGuard($prompts->allText())),
            $prompts, $npc, self::$tokensFile, static function (string $l): void {}
        );
    }

    private function row(int $shipId): array
    {
        foreach ((new Store(Store::connect(self::$config['database']), self::$config['npc']))->llmNpcs() as $r) {
            if ((int)$r['ship_id'] === $shipId) {
                return $r;
            }
        }
        throw new \RuntimeException('NPC not found');
    }

    private function logRows(int $shipId): array
    {
        return $this->db()->fetchAll('SELECT * FROM npc_action_log WHERE ship_id = :id ORDER BY id', ['id' => $shipId]);
    }

    private function tool(string $name, array $args = []): array
    {
        return ['name' => $name, 'arguments' => $args];
    }

}
