<?php

declare(strict_types=1);

namespace BNT\Services;

/**
 * Player-written text reaches LLM prompts only through this wrapper: it is
 * flattened, length-limited, stripped of the delimiter characters, and wrapped
 * in <<< >>> so the model can tell data from instructions.
 */
class UntrustedText
{
    public static function sanitize(string $text, int $maxChars = 280): string
    {
        // Remove control characters and the delimiter characters themselves.
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = str_replace(['<', '>'], '', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($text) > $maxChars) {
            $text = mb_substr($text, 0, $maxChars - 1) . '…';
        }
        return $text;
    }

    public static function wrap(?string $text, int $maxChars = 280): string
    {
        return '<<<' . self::sanitize((string)$text, $maxChars) . '>>>';
    }
}
