<?php

declare(strict_types=1);

namespace BNT\NpcAgent;

/** Blocks outgoing NPC messages that repeat a run of words from the prompt templates. */
class PromptGuard
{
    /** @var array<string,true> */
    private array $grams = [];

    public function __construct(string $promptText, private int $window = 6)
    {
        $words = self::words($promptText);
        for ($i = 0; $i + $window <= count($words); $i++) {
            $this->grams[implode(' ', array_slice($words, $i, $window))] = true;
        }
    }

    public function leaks(string $text): bool
    {
        $words = self::words($text);
        for ($i = 0; $i + $this->window <= count($words); $i++) {
            if (isset($this->grams[implode(' ', array_slice($words, $i, $this->window))])) {
                return true;
            }
        }
        return false;
    }

    private static function words(string $s): array
    {
        return preg_split('/\W+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
