<?php
/**
 * Synchronize member accounts:
 * Sets default initial password (Kspdowa@<KGID>) for members who haven't changed password yet,
 * and ensures username (KGID) and mobile are mapped so members can log in via KGID/Mobile/Email.
 */

declare(strict_types=1);

if (file_exists(__DIR__ . '/includes/bootstrap.php')) {
    require_once __DIR__ . '/includes/bootstrap.php';
} elseif (file_exists(dirname(__DIR__) . '/public_html/includes/bootstrap.php')) {
    require_once dirname(__DIR__) . '/public_html/includes/bootstrap.php';
} else {
    require_once __DIR__ . '/../public_html/includes/bootstrap.php';
}

$members = Database::fetchAll(
    "SELECT m.id as member_id, mp.kgid_no, mp.personal_mobile, mp.personal_email
     FROM members m
     JOIN member_profiles mp ON mp.member_id = m.id
     WHERE mp.kgid_no IS NOT NULL AND mp.kgid_no != ''
     ORDER BY m.id ASC"
);

$role = Database::fetchOne("SELECT id FROM roles WHERE name = 'Regular Member'");
$roleId = $role ? (int)$role['id'] : null;

$created = 0;
$updated = 0;
$skippedCustom = 0;

foreach ($members as $m) {
    $memberId = (int)$m['member_id'];
    $kgid = trim((string)$m['kgid_no']);
    $mobile = !empty($m['personal_mobile']) ? trim((string)$m['personal_mobile']) : null;
    $email = !empty($m['personal_email']) ? trim((string)$m['personal_email']) : null;

    // Sanitize duplicate check for email
    if ($email !== null) {
        $existingEmailUser = Database::fetchOne("SELECT id, member_id FROM users WHERE email = ?", [$email]);
        if ($existingEmailUser && (int)$existingEmailUser['member_id'] !== $memberId) {
            // Email already assigned to another user; cannot reuse in users.email unique index
            $email = null;
        }
    }

    // Sanitize duplicate check for mobile
    if ($mobile !== null) {
        $existingMobileUser = Database::fetchOne("SELECT id, member_id FROM users WHERE mobile = ?", [$mobile]);
        if ($existingMobileUser && (int)$existingMobileUser['member_id'] !== $memberId) {
            $mobile = null;
        }
    }

    $defaultPassword = 'Kspdowa@' . $kgid;
    $hash = Auth::hashPassword($defaultPassword);

    $user = Database::fetchOne("SELECT id, must_change_password, email, mobile, username FROM users WHERE member_id = ?", [$memberId]);

    if ($user) {
        if ((int)$user['must_change_password'] === 1) {
            // Still on temporary/initial password — update to standard Kspdowa@KGID
            Database::execute(
                "UPDATE users SET password_hash = ?, username = ?, mobile = COALESCE(?, mobile), email = COALESCE(email, ?), updated_at = NOW() WHERE id = ?",
                [$hash, $kgid, $mobile, $email, (int)$user['id']]
            );
            $updated++;
        } else {
            // Already customized password — preserve password_hash, only update username & mobile
            Database::execute(
                "UPDATE users SET username = COALESCE(username, ?), mobile = COALESCE(mobile, ?), email = COALESCE(email, ?) WHERE id = ?",
                [$kgid, $mobile, $email, (int)$user['id']]
            );
            $skippedCustom++;
        }
    } else {
        // Check if username already used
        $existingUsernameUser = Database::fetchOne("SELECT id FROM users WHERE username = ?", [$kgid]);
        if ($existingUsernameUser) {
            $kgid = $kgid . '_' . $memberId;
        }

        // Create new user account
        Database::execute(
            "INSERT INTO users (member_id, username, email, mobile, password_hash, status, must_change_password, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'active', 1, NOW(), NOW())",
            [$memberId, $kgid, $email, $mobile, $hash]
        );
        $newUserId = (int)Database::lastInsertId();
        if ($roleId) {
            Database::execute("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)", [$newUserId, $roleId]);
        }
        $created++;
    }
}

echo "COMPLETED:\n";
echo "Total Members checked: " . count($members) . "\n";
echo "User accounts updated to Kspdowa@KGID: {$updated}\n";
echo "New user accounts created: {$created}\n";
echo "User accounts with custom password preserved: {$skippedCustom}\n";
