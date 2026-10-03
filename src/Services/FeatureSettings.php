<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;

/**
 * Admin-togglable switches for the protection, news and rumour features. Config-file values are the defaults;
 * rows in npc_settings (keys like "news.review_mode") override them at runtime.
 */
class FeatureSettings
{
    /** Keys an admin may change at runtime. */
    public const KEYS = ['protection.enabled', 'news.journalist_enabled', 'news.review_mode', 'rumours.enabled', 'rumours.require_line_approval'];

    /** @return array the config with overrides applied */
    public static function apply(Database $db, array $config): array
    {
        try {
            $rows = $db->fetchAll("SELECT key, value FROM npc_settings WHERE key LIKE 'news.%' OR key LIKE 'rumours.%' OR key LIKE 'protection.%'");
        } catch (\Throwable) {
            return $config;   // table not there yet
        }
        foreach ($rows as $r) {
            if (!in_array($r['key'], self::KEYS, true)) {
                continue;
            }
            [$section, $name] = explode('.', $r['key'], 2);
            $config[$section][$name] = in_array(strtolower((string)$r['value']), ['1', 't', 'true', 'yes', 'on'], true);
        }
        return $config;
    }

    public static function set(Database $db, string $key, bool $value): void
    {
        if (!in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException("$key cannot be changed at runtime");
        }
        // Straight PDO: Database::query() would turn the strings 'true'/'false' into booleans.
        $db->getConnection()->prepare(
            'INSERT INTO npc_settings (key, value, updated_at) VALUES (:k, :v, now())
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()'
        )->execute(['k' => $key, 'v' => $value ? 'true' : 'false']);
    }
}
