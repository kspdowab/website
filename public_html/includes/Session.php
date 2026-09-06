<?php
/**
 * KSPDOWA — Secure Session Management
 * ============================================================
 * All pages must call Session::start() (done in bootstrap.php).
 *
 * Security properties:
 *   - HttpOnly, Secure (HTTPS), SameSite=Strict cookies
 *   - Custom session name
 *   - Session ID regeneration on auth events and periodically
 *   - Flash message support
 * ============================================================
 */

declare(strict_types=1);

class Session
{
    private static bool $started = false;

    // How often (seconds) to auto-regenerate session ID during a live session
    private const REGEN_INTERVAL = 300; // 5 minutes

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                || (defined('APP_ENV') && APP_ENV === 'development'); // allow non-HTTPS locally

        $sessionName = defined('SESSION_NAME') ? SESSION_NAME : 'KSPDOWA_SESS';

        session_name($sessionName);

        session_set_cookie_params([
            'lifetime' => 0,          // browser-session cookie (server controls expiry)
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        if (!session_start()) {
            throw new RuntimeException('Failed to start session.');
        }

        self::$started = true;
        self::periodicRegenerate();
    }

    /**
     * Regenerate session ID (call after any privilege change — login, role change, etc.)
     */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
        $_SESSION['_regen_at'] = time();
    }

    /**
     * Completely destroy the current session (call on logout).
     */
    public static function destroy(): void
    {
        self::start();

        $_SESSION = [];

        // Clear the session cookie
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 86400,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );

        session_destroy();
        self::$started = false;
    }

    // ------------------------------------------------------------------
    // Data accessors
    // ------------------------------------------------------------------

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    // ------------------------------------------------------------------
    // Flash messages (survive one redirect then vanish)
    // ------------------------------------------------------------------

    public static function flash(string $key, mixed $value): void
    {
        self::start();
        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Read and remove a flash value. Returns $default if not set.
     */
    public static function getFlash(string $key, mixed $default = null): mixed
    {
        self::start();
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    public static function hasFlash(string $key): bool
    {
        self::start();
        return isset($_SESSION['_flash'][$key]);
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private static function periodicRegenerate(): void
    {
        if (!isset($_SESSION['_regen_at'])) {
            $_SESSION['_regen_at'] = time();
            return;
        }

        if ((time() - $_SESSION['_regen_at']) > self::REGEN_INTERVAL) {
            self::regenerate();
        }
    }
}
