<?php
/**
 * KSPDOWA — CSRF Protection
 * ============================================================
 * Per project spec: all state-changing requests (POST, PUT, DELETE)
 * must carry a valid CSRF token.
 *
 * Usage in forms:
 *   <?= CSRF::htmlField() ?>
 *
 * Usage in form-processing PHP:
 *   CSRF::requireValid();   // aborts with 403 on failure
 *
 * Usage in AJAX (send token in X-CSRF-Token header):
 *   CSRF::requireValidHeader();
 *
 * Token is stored in the session and is NOT rotated per-request
 * (double-submit-cookie pattern is not used here; session token is
 * sufficient given SameSite=Strict session cookies).
 * ============================================================
 */

declare(strict_types=1);

class CSRF
{
    private const SESSION_KEY = '_csrf_token';

    // ------------------------------------------------------------------
    // Token management
    // ------------------------------------------------------------------

    /**
     * Get (or lazily generate) the current session CSRF token.
     */
    public static function getToken(): string
    {
        Session::start();

        if (!Session::has(self::SESSION_KEY)) {
            $len = defined('CSRF_TOKEN_LENGTH') ? CSRF_TOKEN_LENGTH : 32;
            Session::set(self::SESSION_KEY, bin2hex(random_bytes($len)));
        }

        return (string) Session::get(self::SESSION_KEY);
    }

    /**
     * Alias for getToken().
     */
    public static function token(): string
    {
        return self::getToken();
    }

    /**
     * Replace the token with a freshly generated one.
     * Call after a successful form submission that changes sensitive state.
     */
    public static function regenerate(): void
    {
        $len = defined('CSRF_TOKEN_LENGTH') ? CSRF_TOKEN_LENGTH : 32;
        Session::set(self::SESSION_KEY, bin2hex(random_bytes($len)));
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * Constant-time comparison of the supplied token against the session token.
     */
    public static function validate(string $suppliedToken): bool
    {
        if ($suppliedToken === '') {
            return false;
        }

        $storedToken = Session::get(self::SESSION_KEY, '');
        return hash_equals((string) $storedToken, $suppliedToken);
    }

    /**
     * Validate the CSRF token from $_POST['csrf_token'].
     * Aborts with HTTP 403 if invalid or missing.
     * Only checks POST requests; GET/HEAD pass through.
     */
    public static function requireValid(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $token = (string) ($_POST['csrf_token'] ?? '');

        if (!self::validate($token)) {
            AuditLogger::log('CSRF_FAILURE', 'security', null, null, [
                'uri'    => $_SERVER['REQUEST_URI'] ?? '',
                'method' => $method,
            ]);

            ErrorHandler::abort(403, 'Invalid or missing security token. Please reload the page and try again.');
        }
    }

    /**
     * Validate the CSRF token from the X-CSRF-Token request header.
     * Used for AJAX / API calls.
     */
    public static function requireValidHeader(): void
    {
        $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        if (!self::validate($token)) {
            AuditLogger::log('CSRF_FAILURE', 'security', null, null, [
                'uri'    => $_SERVER['REQUEST_URI'] ?? '',
                'source' => 'header',
            ]);

            ErrorHandler::abort(403, 'Invalid CSRF token.');
        }
    }

    // ------------------------------------------------------------------
    // HTML helpers
    // ------------------------------------------------------------------

    /**
     * Render a hidden <input> element for use inside HTML forms.
     */
    public static function htmlField(): string
    {
        return '<input type="hidden" name="csrf_token" value="'
             . htmlspecialchars(self::getToken(), ENT_QUOTES, 'UTF-8')
             . '">';
    }

    /**
     * Alias for htmlField().
     */
    public static function field(): string
    {
        return self::htmlField();
    }

    /**
     * Return the token for use in a JavaScript meta tag or JSON payload.
     */
    public static function metaTag(): string
    {
        return '<meta name="csrf-token" content="'
             . htmlspecialchars(self::getToken(), ENT_QUOTES, 'UTF-8')
             . '">';
    }
}
