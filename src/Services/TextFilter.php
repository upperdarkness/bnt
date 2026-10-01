<?php

declare(strict_types=1);

namespace BNT\Services;

/**
 * Message content checks: a basic profanity filter shared by players and NPCs,
 * and a prompt-leak filter that stops an NPC repeating its own prompt text.
 */
class TextFilter
{
    private const DEFAULT_WORDS = ['fuck', 'shit', 'cunt', 'bitch', 'asshole', 'bastard', 'dick', 'piss', 'slut', 'whore', 'nigger', 'faggot'];

    private array $words;

    public function __construct(array $config = [], private ?string $promptDir = null)
    {
        $this->words = $config['messages']['blocked_words'] ?? self::DEFAULT_WORDS;
        $this->promptDir ??= $config['npc']['prompt_dir'] ?? null;
    }

    public function hasProfanity(string $text): bool
    {
        $norm = strtolower(strtr($text, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '@' => 'a', '$' => 's']));
        foreach ($this->words as $w) {
            if (preg_match('/\b' . preg_quote(strtolower($w), '/') . '(?:s|ed|ing)?\b/', $norm)) {
                return true;
            }
        }
        return false;
    }

    /** True when the text repeats a run of $window or more consecutive words from any prompt template. */
    public function leaksPrompt(string $text, int $window = 6): bool
    {
        $needle = $this->words($text);
        if (count($needle) < $window) {
            return false;
        }
        $grams = [];
        for ($i = 0; $i + $window <= count($needle); $i++) {
            $grams[implode(' ', array_slice($needle, $i, $window))] = true;
        }
        foreach ($this->promptSources() as $source) {
            $hay = $this->words($source);
            for ($i = 0; $i + $window <= count($hay); $i++) {
                if (isset($grams[implode(' ', array_slice($hay, $i, $window))])) {
                    return true;
                }
            }
        }
        return false;
    }

    private function words(string $s): array
    {
        return preg_split('/\W+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private array $sources = [];

    private function promptSources(): array
    {
        if (!$this->sources && $this->promptDir && is_dir($this->promptDir)) {
            foreach (glob(rtrim($this->promptDir, '/') . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    $this->sources[] = (string)file_get_contents($file);
                }
            }
        }
        return $this->sources;
    }
}
