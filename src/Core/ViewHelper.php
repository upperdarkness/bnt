<?php

declare(strict_types=1);

namespace BNT\Core;

use BNT\Services\AlignmentService;

/** Small HTML helpers shared by views. */
class ViewHelper
{
    /**
     * A ship/player name coloured by alignment tier, with a WANTED badge and NPC marker.
     * Falls back to a plain escaped name when alignment data is unavailable.
     */
    public static function shipName(array $ship, ?AlignmentService $alignment): string
    {
        $name = (string)($ship['character_name'] ?? '');
        if ($alignment === null || !$alignment->enabled() || !array_key_exists('alignment', $ship)) {
            return htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        }
        $html = $alignment->rules()->styledName($name, (int)$ship['alignment'], $alignment->isWanted($ship));
        if (!empty($ship['is_npc'])) {
            $html .= ' <span class="npc-badge" title="Non-player character" style="font-size:0.75em;opacity:.7">[NPC]</span>';
        }
        return $html;
    }

    /** Tier label only ("Lawful"), never the number. */
    public static function tierLabel(array $ship, ?AlignmentService $alignment): string
    {
        if ($alignment === null || !$alignment->enabled() || !array_key_exists('alignment', $ship)) {
            return '';
        }
        return $alignment->rules()->label((int)$ship['alignment']);
    }
}
