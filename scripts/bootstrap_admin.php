<?php
/**
 * KSPDOWA — First Admin Account Bootstrap
 * ============================================================
 * One-time CLI tool to create the very first admin login, since
 * the `users` table starts empty and nobody can sign in without
 * one existing account. Not exposed as a web endpoint.
 *
 * Usage:
 *   php scripts/bootstrap_admin.php admin@example.com
 *   php scripts/bootstrap_admin.php admin@example.com --reset   (regenerate password for an EXISTING account)
 *
 * Assigns the seeded 'State Super Admin' role (statewide scope,
 * association_unit_id = NULL) to the new/target account.
 *
 * The generated password is printed ONCE. It is not stored anywhere
 * in plain text and cannot be recovered — save it immediately and
 * change it after first login (Phase 2 will add self-service password
 * change/reset; until then, re-run with --reset if it's lost).
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$publicHtml = dirname(__DIR__) . '/public_html';

if (!is_dir($publicHtml)) {
    exit("ERROR: public_html directory not found at {$publicHtml}\n");
}

require_once $publicHtml . '/config/app.php';
require_once $publicHtml . '/config/db.php';
require_once $publicHtml . '/includes/Database.php';
require_once $publicHtml . '/includes/Auth.php';

// ---------------------------------------------------------------------------
// Parse arguments
// ---------------------------------------------------------------------------
$args  = array_slice($argv, 1);
$reset = false;
$email = null;

foreach ($args as $arg) {
    if ($arg === '--reset') {
        $reset = true;
    } elseif ($email === null) {
        $email = $arg;
    }
}

if ($email === null) {
    fwrite(STDERR, "Usage: php scripts/bootstrap_admin.php <email> [--reset]\n");
    exit(1);
}

$email = filter_var(trim($email), FILTER_VALIDATE_EMAIL);
if ($email === false) {
    fwrite(STDERR, "ERROR: '{$args[0]}' is not a valid email address.\n");
    exit(1);
}
$email = strtolower($email);

// ---------------------------------------------------------------------------
// Generate a strong random password (satisfies Sanitize::password() rules:
// min length, upper, lower, digit).
// ---------------------------------------------------------------------------
function generateStrongPassword(int $length = 14): string
{
    $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; // no I/O to avoid visual ambiguity
    $lower   = 'abcdefghijkmnpqrstuvwxyz';
    $digits  = '23456789';
    $symbols = '!@#%*-_+=';
    $all     = $upper . $lower . $digits . $symbols;

    // Guarantee at least one of each required class, then fill the rest randomly.
    $password = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
    ];

    for ($i = count($password); $i < $length; $i++) {
        $password[] = $all[random_int(0, strlen($all) - 1)];
    }

    shuffle($password);
    return implode('', $password);
}

try {
    $existing = Database::fetchOne(
        'SELECT id FROM users WHERE email = ? LIMIT 1',
        [$email]
    );

    $password = generateStrongPassword();
    $hash     = Auth::hashPassword($password);

    if ($existing) {
        if (!$reset) {
            fwrite(STDERR, "ERROR: A user with email '{$email}' already exists (id {$existing['id']}).\n");
            fwrite(STDERR, "       Re-run with --reset to regenerate their password, or pick a different email.\n");
            exit(1);
        }

        $userId = (int) $existing['id'];
        Database::execute(
            'UPDATE users SET password_hash = ?, status = ?, updated_at = NOW() WHERE id = ?',
            [$hash, 'active', $userId]
        );

        echo "Password reset for existing account (user id {$userId}).\n";
    } else {
        Database::execute(
            'INSERT INTO users (email, password_hash, status, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())',
            [$email, $hash, 'active']
        );
        $userId = (int) Database::lastInsertId();

        $role = Database::fetchOne(
            "SELECT id FROM roles WHERE name = 'State Super Admin' AND status = 'active' LIMIT 1"
        );

        if (!$role) {
            fwrite(STDERR, "ERROR: 'State Super Admin' role not found — has seeds/001_roles_permissions.sql been run?\n");
            exit(1);
        }

        Database::execute(
            'INSERT IGNORE INTO user_roles (user_id, role_id, association_unit_id) VALUES (?, ?, NULL)',
            [$userId, (int) $role['id']]
        );

        echo "Created new admin account (user id {$userId}) with role 'State Super Admin'.\n";
    }

    echo "\n";
    echo "==================================================\n";
    echo "  Email:    {$email}\n";
    echo "  Password: {$password}\n";
    echo "==================================================\n";
    echo "Save this now — it will not be shown again. Log in at /login.php\n";
    echo "and treat this as a temporary password until self-service password\n";
    echo "change ships in Phase 2.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
