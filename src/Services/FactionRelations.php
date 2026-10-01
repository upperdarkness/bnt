<?php

declare(strict_types=1);

namespace BNT\Services;

/**
 * Fixed v1 faction relations.
 *  - Police: hostile to Raiders and any Wanted ship.
 *  - Raiders: hostile to everyone except Raiders.
 *  - Guild and Free Captains: neutral unless attacked.
 * Rows need: is_npc, faction (nullable) and a 'wanted' bool.
 */
class FactionRelations
{
    public static function hostile(array $a, array $b): bool
    {
        $fa = !empty($a['is_npc']) ? ($a['faction'] ?? null) : null;
        $fb = !empty($b['is_npc']) ? ($b['faction'] ?? null) : null;
        if ($fa === 'police') {
            return $fb === 'xenobe' || !empty($b['wanted']);
        }
        if ($fa === 'xenobe') {
            return $fb !== 'xenobe';
        }
        return false;
    }

    /**
     * Whether $other is a danger to $me when passing through (used by go_to and flee logic):
     * hostile by faction, or a lawless human/NPC when $me is law-abiding.
     */
    public static function threatens(array $me, array $other, AlignmentRules $rules): bool
    {
        if (self::hostile($other, $me)) {
            return true;
        }
        $otherLawless = $rules->isOutlaw((int)($other['alignment'] ?? 0));
        $otherPolice = !empty($other['is_npc']) && ($other['faction'] ?? null) === 'police';
        return $otherLawless && !$otherPolice && $rules->isLawAbiding((int)($me['alignment'] ?? 0));
    }
}
