<?php

declare(strict_types=1);

namespace BNT\Core;

final class GameArtwork
{
    public static function ship(string $type): string
    {
        if (!in_array($type, ['scout', 'merchant', 'warship', 'balanced'], true)) {
            $type = 'balanced';
        }
        return '/assets/art/ship-' . $type . '.webp';
    }

    public static function sectorTheme(int $sectorId): string
    {
        return ['sector-teal', 'sector-violet', 'sector-amber'][abs($sectorId % 3)];
    }
}
