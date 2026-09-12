<?php
/**
 * KSPDOWA — Authentication Foundation
 * ============================================================
 * Handles login, logout, session binding, and password utilities.
 *
 * Authentication: identifier may be email, mobile, or username.
 * Password hashing: bcrypt with cost 12 per project security spec.
 * Session timeout: enforced by checkSessionExpiry().
 *
 * Phase 2 additions: registration, password reset, OTP, email verification.
 * ============================================================
 */

declare(strict_types=1);

class Auth
{
    // ------------------------------------------------------------------
    // Login / Logout
    // ------------------------------------------------------------------

    /**
     * Attempt to log in a user.
     *
     * @param string $identifier Email, mobile number, or username
     * @param string $password   Plain-text password to verify
     * @return array ['success' => bool, 'error' => string|null, 'user_id' => int|null]
     */
    public static function login(string $identifier, string $password): array
    {
        $identifier = trim($identifier);

        if ($identifier === '' || $password === '') {
            return ['success' => false, 'error' => 'Identifier and password are required.'];
        }

        // Look up user by any of the three identifier columns
        $user = Database::fetchOne(
            'SELECT id, member_id, username, email, mobile, password_hash, status
             FROM users
             WHERE (email = ? OR mobile = ? OR username = ?)
             LIMIT 1',
            [$identifier, $identifier, $identifier]
        );

        // Constant-time failure path (prevents user enumeration via timing)
        if (!$user) {
            password_verify($password, '$2y$12$invalidhashpadding00000000000000000000000000000000000');
            return ['success' => false, 'error' => 'Invalid credentials.'];
        }

        // Locked before password check (still timing-safe via verify below)
        if ($user['status'] === 'locked') {
            password_verify($password, $user['password_hash']); // consume time
            return ['success' => false, 'error' => 'Account is locked. Please contact the administrator.'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Invalid credentials.'];
        }

        if ($user['status'] === 'inactive') {
            return ['success' => false, 'error' => 'Account is inactive.'];
        }

        if ($user['status'] === 'pending') {
            return ['success' => false, 'error' => 'Account is pending approval.'];
        }

        // Upgrade hash if bcrypt cost has changed
        $cost = defined('BCRYPT_COST') ? BCRYPT_COST : 12;
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => $cost])) {
            $newHash = self::hashPassword($password);
            Database::execute(
                'UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?',
                [$newHash, $user['id']]
            );
        }

        // Record last login
        Database::execute(
            'UPDATE users SET last_login_at = NOW() WHERE id = ?',
            [(int) $user['id']]
        );

        // Bind user to session (regenerate ID to prevent session fixation)
        Session::start();
        Session::regenerate();

        Session::set('user_id',     (int) $user['id']);
        Session::set('member_id',   $user['member_id'] ? (int) $user['member_id'] : null);
        Session::set('user_status', $user['status']);
        Session::set('logged_in',   true);
        Session::set('login_at',    time());

        AuditLogger::log('LOGIN', 'users', (int) $user['id']);

        return ['success' => true, 'error' => null, 'user_id' => (int) $user['id']];
    }

    /**
     * Log out the current user, destroy the session, and redirect to login.
     */
    public static function logout(): never
    {
        Session::start();

        if (Session::get('logged_in') === true) {
            AuditLogger::log('LOGOUT', 'users', Session::get('user_id'));
        }

        Session::destroy();

        header('Location: /login.php');
        exit;
    }

    // ------------------------------------------------------------------
    // Session state checks
    // ------------------------------------------------------------------

    public static function isLoggedIn(): bool
    {
        Session::start();
        return Session::get('logged_in') === true;
    }

    /**
     * Require an active login or redirect to login page.
     * Call at the top of every protected page.
     */
    public static function requireLogin(): void
    {
        self::checkSessionExpiry();

        if (!self::isLoggedIn()) {
            // Preserve intended destination for post-login redirect (Phase 2)
            Session::flash('redirect_after_login', $_SERVER['REQUEST_URI'] ?? '/');
            header('Location: /login.php');
            exit;
        }
    }

    /**
     * Enforce session idle timeout (SESSION_LIFETIME seconds of inactivity).
     * Call before any authorization check on protected pages.
     */
    public static function checkSessionExpiry(): void
    {
        if (!self::isLoggedIn()) {
            return;
        }

        $lifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 3600;
        $loginAt  = Session::get('login_at');

        if ($loginAt !== null && (time() - (int) $loginAt) > $lifetime) {
            Session::flash('info', 'Your session has expired. Please log in again.');
            Session::destroy();
            header('Location: /login.php');
            exit;
        }

        // Refresh activity timestamp
        Session::set('login_at', time());
    }

    // ------------------------------------------------------------------
    // Current user helpers
    // ------------------------------------------------------------------

    public static function getCurrentUserId(): ?int
    {
        $id = Session::get('user_id');
        return $id !== null ? (int) $id : null;
    }

    public static function getCurrentMemberId(): ?int
    {
        $id = Session::get('member_id');
        return $id !== null ? (int) $id : null;
    }

    /**
     * Fetch the current user row (safe columns only — no password hash).
     */
    public static function getCurrentUser(): array|false
    {
        $userId = self::getCurrentUserId();

        if ($userId === null) {
            return false;
        }

        return Database::fetchOne(
            'SELECT id, member_id, username, email, mobile, status, last_login_at, created_at
             FROM users
             WHERE id = ?
             LIMIT 1',
            [$userId]
        );
    }

    // ------------------------------------------------------------------
    // Password utilities
    // ------------------------------------------------------------------

    /**
     * Hash a plain-text password using bcrypt.
     * Always use this — never store plain-text passwords.
     */
    public static function hashPassword(string $password): string
    {
        $cost = defined('BCRYPT_COST') ? BCRYPT_COST : 12;
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
    }

    /**
     * Verify a plain-text password against a stored hash.
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Generate a cryptographically secure random token (hex-encoded).
     *
     * @param int $bytes Number of random bytes (output length = 2 × bytes)
     */
    public static function generateSecureToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Generate a cryptographically secure, human-typeable temporary
     * password (e.g. for member account activation -- see Auth::login()
     * doc block and the must_change_password flow). Excludes visually
     * ambiguous characters (0/O, 1/l/I) and guarantees at least one
     * uppercase letter, one lowercase letter and one digit so the
     * generated value already satisfies Sanitize::password().
     *
     * Never persisted in plain text -- callers must hash it with
     * hashPassword() immediately and must never write it to the audit
     * log or anywhere else in clear text; it is only ever meant to be
     * delivered once, out-of-band, to the account holder.
     */
    public static function generateTemporaryPassword(int $length = 12): string
    {
        $upper  = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; // no I, O
        $lower  = 'abcdefghijkmnopqrstuvwxyz'; // no l
        $digits = '23456789';                  // no 0, 1
        $all    = $upper . $lower . $digits;

        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
        ];
        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        // Fisher-Yates shuffle using random_int (mt_rand-based shuffle()
        // is not cryptographically secure and must not be used here).
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
