<?php
/**
 * KSPDOWA — System Settings Accessor
 * ============================================================
 * Thin read cache over the `system_settings` table (seeded in
 * Phase 0 — seeds/003_system_settings.sql). Public pages use this
 * instead of querying system_settings directly, so a display
 * change never means editing PHP templates.
 * ============================================================
 */

declare(strict_types=1);

class Settings
{
    private static ?array $cache = null;

    public static function get(string $key, string $default = ''): string
    {
        self::loadAll();
        return array_key_exists($key, self::$cache) ? self::$cache[$key] : $default;
    }

    public static function all(): array
    {
        self::loadAll();
        return self::$cache;
    }

    private static function loadAll(): void
    {
        if (self::$cache !== null) {
            return;
        }

        self::$cache = [];

        try {
            $rows = Database::fetchAll('SELECT setting_key, setting_value FROM system_settings');
            foreach ($rows as $row) {
                self::$cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            // Never let a settings lookup break page rendering.
            error_log('[KSPDOWA][Settings] Failed to load system_settings: ' . $e->getMessage());
        }
    }
}
