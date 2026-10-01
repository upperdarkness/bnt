<?php

declare(strict_types=1);

namespace BNT\NpcAgent;

/** Builds the system prompt from the templates in config/npc_prompts/. */
class Prompts
{
    private const PARTS = ['rules.md', 'persona.md', 'untrusted.md', 'output.md'];

    public function __construct(private string $dir) {}

    /** All template text concatenated (used to build the prompt-leak guard). */
    public function allText(): string
    {
        $text = '';
        foreach (self::PARTS as $part) {
            $text .= $this->read($part) . "\n";
        }
        return $text;
    }

    /**
     * Four fixed parts, in order: rules digest, persona, untrusted-data rule, output contract.
     * The persona (including standing goals) is restated on every wake.
     */
    public function system(array $profile, string $factionLabel, string $factionRole): string
    {
        $persona = is_array($profile['persona']) ? $profile['persona'] : (json_decode((string)$profile['persona'], true) ?: []);
        $goals = array_map(static fn($g) => '  - ' . Tools::clean((string)$g), $persona['goals'] ?? []);
        $vars = [
            '{{name}}' => Tools::clean((string)($persona['name'] ?? $profile['character_name'] ?? 'Captain')),
            '{{faction_label}}' => $factionLabel,
            '{{faction_role}}' => $factionRole,
            '{{temperament}}' => Tools::clean((string)($persona['temperament'] ?? 'steady')),
            '{{speech_style}}' => Tools::clean((string)($persona['speech_style'] ?? 'brief')),
            '{{goals}}' => $goals ? implode("\n", $goals) : '  - Survive and prosper.',
        ];
        $notebook = trim((string)($profile['notebook'] ?? ''));
        $parts = [
            trim($this->read('rules.md')),
            trim(strtr($this->read('persona.md'), $vars)),
            trim($this->read('untrusted.md')),
            trim($this->read('output.md')),
        ];
        $prompt = implode("\n\n", $parts);
        // The notebook is the NPC's own earlier writing, but it may quote players: keep it delimited.
        $prompt .= "\n\nYour private notebook (your own notes from earlier wakes):\n"
            . ($notebook !== '' ? '<<<' . self::cleanNotebook($notebook) . '>>>' : '(empty)');
        return $prompt;
    }

    private static function cleanNotebook(string $text): string
    {
        $text = preg_replace('/[^\P{C}\n]+/u', '', $text) ?? '';
        $text = str_replace(['<', '>'], '', $text);
        return mb_substr(trim($text), 0, 2000);
    }

    private function read(string $file): string
    {
        $path = rtrim($this->dir, '/') . '/' . $file;
        if (!is_readable($path)) {
            throw new \RuntimeException("Prompt template missing: $path");
        }
        return (string)file_get_contents($path);
    }
}
