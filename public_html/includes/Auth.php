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

        // Look up user by email, mobile, username, KGID, or member_no
        $user = Database::fetchOne(
            'SELECT u.id, u.member_id, u.username, u.email, u.mobile, u.password_hash, u.status
             FROM users u
             LEFT JOIN member_profiles mp ON mp.member_id = u.member_id
             LEFT JOIN members m ON m.id = u.member_id
             WHERE (u.email = ? OR u.mobile = ? OR u.username = ? OR mp.kgid_no = ? OR mp.personal_mobile = ? OR m.member_no = ?)
             LIMIT 1',
            [$identifier, $identifier, $identifier, $identifier, $identifier, $identifier]
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

        $rememberMe = Session::get('remember_me') === true;
        $lifetime   = $rememberMe ? (30 * 86400) : (defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 3600);
        $loginAt    = Session::get('login_at');

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
     * Activate member portal access for a member with a server-verified
     * current-year payment: create the `users` login row (must_change_
     * password = 1) with a system-generated temporary password and the
     * "Regular Member" role, exactly once per member.
     *
     * Shared by member-login.php (existing "I already paid, activate my
     * account" flow) and PaymentGateway::confirmPayment() (new Razorpay
     * Standard Checkout flow) so both callers agree on exactly one
     * activation code path -- approved spec: "Generate temporary
     * password and activate login exactly once after verified payment."
     *
     * Idempotent: if a `users` row already exists for this member
     * (created by an earlier call, or a concurrent one that won the
     * race -- users.member_id has a UNIQUE key since migration 013),
     * nothing new is created and temp_password is null. Callers must
     * only email a temporary password when created === true.
     *
     * @param array  $member Row from `members` (must include 'id' and 'name').
     * @param string $email  The member's registered personal email --
     *                       becomes the login identifier.
     * @return array{created: bool, temp_password: ?string, user_id: int}
     */
    public static function activateMemberPortalAccess(array $member, string $email): array
    {
        $existing = Database::fetchOne('SELECT id FROM users WHERE member_id = ?', [$member['id']]);
        if ($existing !== false) {
            return ['created' => false, 'temp_password' => null, 'user_id' => (int) $existing['id']];
        }

        $tempPassword = self::generateTemporaryPassword();
        $passwordHash = self::hashPassword($tempPassword);

        try {
            $userId = Database::transaction(function () use ($member, $email, $passwordHash) {
                // Approved district-coded Membership Number
                // (KSPDOWA-{CODE}-{4-digit serial}), assigned here --
                // the single existing membership-activation choke
                // point -- and nowhere else. No-op (returns null) for
                // a member whose member_no is not the auto-generated
                // REG-NNNNNN placeholder (see MembershipNumber.php's
                // own doc comment for the full rationale). Locks the
                // member and district rows for the rest of this same
                // transaction, so this and the users-row insert below
                // commit -- or roll back -- together.
                MembershipNumber::assignIfPlaceholder((int) $member['id']);

                Database::execute(
                    'INSERT INTO users (member_id, email, password_hash, status, must_change_password)
                     VALUES (?, ?, ?, ?, 1)',
                    [$member['id'], $email, $passwordHash, 'active']
                );
                $userId = (int) Database::lastInsertId();

                $role = Database::fetchOne("SELECT id FROM roles WHERE name = 'Regular Member'");
                if ($role) {
                    Database::execute(
                        'INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)',
                        [$userId, (int) $role['id']]
                    );
                }

                AuditLogger::log('ACTIVATE', 'users', $userId, null, [
                    'member_id' => $member['id'],
                    'email'     => $email,
                    'source'    => 'member_portal_activation',
                ]);

                return $userId;
            });
        } catch (PDOException $e) {
            // 23000 = integrity constraint violation -- most likely the
            // uk_user_member unique key, meaning a concurrent call (e.g.
            // a duplicate webhook racing the browser callback) already
            // created this member's account a moment ago. Treat as
            // "already activated", never as an error, so the caller
            // never sends a second temporary password.
            if ($e->getCode() === '23000') {
                $existing = Database::fetchOne('SELECT id FROM users WHERE member_id = ?', [$member['id']]);
                if ($existing !== false) {
                    return ['created' => false, 'temp_password' => null, 'user_id' => (int) $existing['id']];
                }
            }
            throw $e;
        }

        return ['created' => true, 'temp_password' => $tempPassword, 'user_id' => $userId];
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

    // ------------------------------------------------------------------
    // Password Reset Operations
    // ------------------------------------------------------------------

    /**
     * Mask an email address for safe public/semi-public display (e.g. k****@gmail.com).
     */
    public static function maskEmail(?string $email): string
    {
        $email = trim((string)$email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        $parts = explode('@', $email);
        $namePart = $parts[0];
        $domainPart = $parts[1] ?? '';
        $len = strlen($namePart);
        if ($len <= 2) {
            $masked = substr($namePart, 0, 1) . '*';
        } else {
            $masked = substr($namePart, 0, 1) . str_repeat('*', min(6, $len - 2)) . substr($namePart, -1);
        }
        return $masked . '@' . $domainPart;
    }

    /**
     * Ensure a member has an active `users` authentication record.
     * Generates a cryptographically unguessable random hash so no default
     * predictable password is ever assigned.
     */
    public static function ensureUserAccountForMember(int $memberId): ?int
    {
        $existing = Database::fetchOne('SELECT id FROM users WHERE member_id = ?', [$memberId]);
        if ($existing) {
            return (int)$existing['id'];
        }

        $profile = Database::fetchOne('SELECT kgid_no, personal_mobile, personal_email FROM member_profiles WHERE member_id = ?', [$memberId]);
        $kgid    = trim((string)($profile['kgid_no'] ?? ''));
        $mobile  = trim((string)($profile['personal_mobile'] ?? ''));
        $email   = trim((string)($profile['personal_email'] ?? ''));

        // Generate an unguessable random password hash so the account cannot be accessed
        // until the member creates their own password via the secure reset token.
        $randomSecret = bin2hex(random_bytes(24));
        $hash = self::hashPassword($randomSecret);

        try {
            Database::execute(
                'INSERT INTO users (member_id, username, email, mobile, password_hash, status, must_change_password)
                 VALUES (?, ?, ?, ?, ?, "active", 1)',
                [$memberId, $kgid ?: null, $email ?: null, $mobile ?: null, $hash]
            );
            $newUserId = (int)Database::lastInsertId();

            $role = Database::fetchOne("SELECT id FROM roles WHERE name = 'Regular Member'");
            if ($role) {
                Database::execute('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)', [$newUserId, (int)$role['id']]);
            }

            return $newUserId;
        } catch (\Throwable $e) {
            error_log('[Auth::ensureUserAccountForMember] Error creating user: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Find a user for password reset by identifier (email, mobile, username, or KGID).
     * If member exists in members/profiles but not yet in users table, initializes their account.
     */
    public static function findUserForReset(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $user = Database::fetchOne(
            'SELECT u.id, u.member_id, u.username, u.email, u.mobile, u.status, u.must_change_password,
                    mp.kgid_no, mp.personal_mobile, mp.personal_email, m.name as member_name
             FROM users u
             LEFT JOIN member_profiles mp ON mp.member_id = u.member_id
             LEFT JOIN members m ON m.id = u.member_id
             WHERE (u.email = ? OR u.mobile = ? OR u.username = ? OR mp.kgid_no = ? OR mp.personal_mobile = ? OR m.member_no = ?)
             LIMIT 1',
            [$identifier, $identifier, $identifier, $identifier, $identifier, $identifier]
        );

        if ($user) {
            return $user;
        }

        // Check if member exists in members/member_profiles table without a users row yet
        $member = Database::fetchOne(
            'SELECT m.id as member_id, m.name as member_name, mp.kgid_no, mp.personal_mobile, mp.personal_email
             FROM members m
             JOIN member_profiles mp ON mp.member_id = m.id
             WHERE mp.kgid_no = ? OR mp.personal_mobile = ? OR mp.personal_email = ? OR m.member_no = ?
             LIMIT 1',
            [$identifier, $identifier, $identifier, $identifier]
        );

        if ($member) {
            $userId = self::ensureUserAccountForMember((int)$member['member_id']);
            if ($userId) {
                return Database::fetchOne(
                    'SELECT u.id, u.member_id, u.username, u.email, u.mobile, u.status, u.must_change_password,
                            mp.kgid_no, mp.personal_mobile, mp.personal_email, m.name as member_name
                     FROM users u
                     LEFT JOIN member_profiles mp ON mp.member_id = u.member_id
                     LEFT JOIN members m ON m.id = u.member_id
                     WHERE u.id = ? LIMIT 1',
                    [$userId]
                ) ?: null;
            }
        }

        return null;
    }

    /**
     * Create a password reset token for a user.
     */
    public static function createPasswordResetToken(int $userId): string
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour

        Database::execute(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, NOW())',
            [$userId, $tokenHash, $expiresAt]
        );

        return $rawToken;
    }

    /**
     * Generate and dispatch a secure password setup / reset link for a member.
     */
    public static function sendPasswordResetLinkForMember(int $memberId, ?string $overrideEmail = null): array
    {
        $userId = self::ensureUserAccountForMember($memberId);
        if (!$userId) {
            return ['success' => false, 'error' => 'Could not locate or initialize member account.'];
        }

        $user = Database::fetchOne(
            'SELECT u.id, u.email, mp.kgid_no, mp.personal_email, mp.personal_mobile, m.name as member_name
             FROM users u
             JOIN members m ON m.id = u.member_id
             JOIN member_profiles mp ON mp.member_id = m.id
             WHERE u.id = ? LIMIT 1',
            [$userId]
        );
        if (!$user) {
            return ['success' => false, 'error' => 'Member profile not found.'];
        }

        $token = self::createPasswordResetToken($userId);
        $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'https://kspdowa.in';
        $resetLink = $baseUrl . '/reset-password.php?token=' . urlencode($token);

        $recipientEmail = trim((string)($overrideEmail ?: ($user['email'] ?: ($user['personal_email'] ?? ''))));
        $recipientMobile = trim((string)($user['personal_mobile'] ?? ''));
        $memberName = trim((string)($user['member_name'] ?? 'Member'));
        $siteShort = class_exists('Settings') ? Settings::get('site_short_name', APP_SHORT_NAME) : APP_SHORT_NAME;

        $emailSent = false;
        $maskedEmail = self::maskEmail($recipientEmail);

        if ($recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $subject = 'Password Setup / Reset Request — ' . $siteShort;
            $plainText = "Dear {$memberName},\n\n"
                . "A request has been made to set up or reset your password for the {$siteShort} member portal.\n\n"
                . "Click the link below to set your password:\n"
                . "{$resetLink}\n\n"
                . "This link is valid for 1 hour. If you did not request this, you can safely ignore this email.\n\n"
                . "Regards,\n{$siteShort}";

            $htmlBody = '<p>Dear <strong>' . htmlspecialchars($memberName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
                . '<p>A request was received to set up or reset your password for the <strong>' . htmlspecialchars($siteShort, ENT_QUOTES, 'UTF-8') . '</strong> member portal.</p>'
                . '<p>Please click the button below to create your secure password:</p>'
                . '<p style="margin:24px 0;"><a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '" style="background:#173F67; color:#ffffff; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:bold; display:inline-block;">Set Your Password</a></p>'
                . '<p style="font-size:0.85rem; color:#64748b;">This secure link is valid for 1 hour. If you did not make this request, please contact the association office.</p>';

            if (class_exists('EmailTemplates')) {
                $fullHtml = EmailTemplates::wrap($subject, $htmlBody, $resetLink, 'Set Your Password');
            } else {
                $fullHtml = $htmlBody;
            }

            $emailSent = Mailer::send($recipientEmail, $subject, $plainText, $fullHtml);
        }

        // WhatsApp notification alert if enabled
        $waSent = false;
        if ($recipientMobile !== '' && class_exists('WhatsApp') && class_exists('Settings') && Settings::get('notif_whatsapp_enabled', '0') === '1') {
            $waMsg = "*{$siteShort} Security Alert*\n\nDear {$memberName},\n\nClick the link below to set your secure member portal password (valid for 1 hour):\n{$resetLink}";
            $waErr = null;
            $waSent = WhatsApp::send($recipientMobile, $waMsg, $waErr);
        }

        return [
            'success'       => true,
            'email_sent'    => $emailSent,
            'whatsapp_sent' => $waSent,
            'email'         => $recipientEmail,
            'masked_email'  => $maskedEmail,
            'name'          => $memberName,
            'reset_link'    => $resetLink,
        ];
    }

    /**
     * Verify a password reset token.
     */
    public static function verifyPasswordResetToken(string $rawToken): ?array
    {
        $tokenHash = hash('sha256', trim($rawToken));
        $row = Database::fetchOne(
            'SELECT r.id as reset_id, r.user_id, r.expires_at, u.email, u.username, u.member_id,
                    mp.kgid_no, m.name as member_name
             FROM password_resets r
             JOIN users u ON u.id = r.user_id
             LEFT JOIN member_profiles mp ON mp.member_id = u.member_id
             LEFT JOIN members m ON m.id = u.member_id
             WHERE r.token_hash = ? AND r.expires_at > NOW() AND r.used_at IS NULL
             LIMIT 1',
            [$tokenHash]
        );

        return $row ?: null;
    }

    /**
     * Complete a password reset using a verified token.
     */
    public static function completePasswordReset(string $rawToken, string $newPassword): array
    {
        $reset = self::verifyPasswordResetToken($rawToken);
        if (!$reset) {
            return ['success' => false, 'error' => 'This password reset link is invalid or has expired. Please request a new one.'];
        }

        $pwdErrors = Sanitize::password($newPassword);
        if (!empty($pwdErrors)) {
            return ['success' => false, 'error' => implode(' ', $pwdErrors)];
        }

        $newHash = self::hashPassword($newPassword);
        Database::transaction(function () use ($reset, $newHash) {
            Database::execute(
                'UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = NOW() WHERE id = ?',
                [$newHash, (int)$reset['user_id']]
            );
            Database::execute(
                'UPDATE password_resets SET used_at = NOW() WHERE id = ?',
                [(int)$reset['reset_id']]
            );
        });

        AuditLogger::log('PASSWORD_RESET', 'users', (int)$reset['user_id']);
        return ['success' => true, 'error' => null];
    }

    /**
     * Deprecated: replaced with secure token reset links via sendPasswordResetLinkForMember().
     */
    public static function resetMemberPasswordToDefault(int $memberId): array
    {
        return self::sendPasswordResetLinkForMember($memberId);
    }
}
