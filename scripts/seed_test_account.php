<?php
/**
 * KSPDOWA — Test Account Seed Script
 * ============================================================
 * Creates a test member + user account for Razorpay payment
 * integration testing at https://kspdowa.in
 *
 * Credentials created:
 *   Email:    test@kspdowa.in
 *   Password: test@kspdowa123
 *
 * USAGE (run once from project root):
 *   php scripts/seed_test_account.php
 *
 * SAFE TO RE-RUN: Uses INSERT IGNORE / UPDATE — idempotent.
 *
 * DELETE AFTER TESTING: Once Razorpay domain whitelist is confirmed
 * working, remove this test account:
 *   php scripts/seed_test_account.php --delete
 * ============================================================
 */

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────
$publicHtml = __DIR__ . '/../public_html';
define('PUBLIC_HTML', $publicHtml);
define('CONFIG_DIR',  $publicHtml . '/config');
define('INCLUDES_DIR', $publicHtml . '/includes');

require_once CONFIG_DIR . '/app.php';
require_once CONFIG_DIR . '/db.php';

// Load only the classes needed (no session/header calls)
require_once INCLUDES_DIR . '/Database.php';
require_once INCLUDES_DIR . '/AuditLogger.php';
require_once INCLUDES_DIR . '/Auth.php';

// ── Handle --delete flag ────────────────────────────────────
if (in_array('--delete', $argv ?? [], true)) {
    deleteTestAccount();
    exit(0);
}

// ── Constants ──────────────────────────────────────────────
const TEST_EMAIL    = 'test@kspdowa.in';
const TEST_PASSWORD = 'test@kspdowa123';
const TEST_NAME     = 'TEST MEMBER (Razorpay QA)';
const TEST_KGID     = 'TEST001';
const TEST_MOBILE   = '9999999999';

// ── Main ───────────────────────────────────────────────────
echo "\n=== KSPDOWA Test Account Seed ===\n\n";

try {
    Database::getInstance(); // initialises the singleton connection
    createTestAccount();
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

// ── Functions ──────────────────────────────────────────────

function createTestAccount(): void
{
    // 1. Ensure FY 2026-27 exists
    Database::execute(
        "INSERT IGNORE INTO membership_years
             (financial_year, start_date, end_date, fee_amount, status)
         VALUES ('2026-27', '2026-04-01', '2027-03-31', 300.00, 'active')"
    );
    $year = Database::fetchOne(
        "SELECT id, fee_amount FROM membership_years WHERE financial_year = '2026-27'"
    );
    echo "✓ Membership year 2026-27  [ID: {$year['id']}, Fee: ₹{$year['fee_amount']}]\n";

    // 2. Ensure a district exists (use first active one, or create a stub)
    $district = Database::fetchOne("SELECT id FROM districts WHERE status = 'active' LIMIT 1");
    if (!$district) {
        Database::execute(
            "INSERT IGNORE INTO districts (name, code, status) VALUES ('BENGALURU', 'BLR', 'active')"
        );
        $district = Database::fetchOne("SELECT id FROM districts WHERE code = 'BLR'");
    }

    // 3. Ensure a taluk exists under that district
    $taluk = Database::fetchOne(
        "SELECT id FROM taluks WHERE district_id = ? AND status = 'active' LIMIT 1",
        [(int)$district['id']]
    );
    if (!$taluk) {
        Database::execute(
            "INSERT IGNORE INTO taluks (district_id, name, code, status) VALUES (?, 'BENGALURU NORTH', 'BLR-N', 'active')",
            [(int)$district['id']]
        );
        $taluk = Database::fetchOne("SELECT id FROM taluks WHERE code = 'BLR-N'");
    }

    // 4. Ensure membership type exists
    $mtype = Database::fetchOne(
        "SELECT id FROM membership_types WHERE name = 'Regular Member' AND status = 'active' LIMIT 1"
    );
    if (!$mtype) {
        Database::execute(
            "INSERT IGNORE INTO membership_types (name, description, fee_amount, status)
             VALUES ('Regular Member', 'Standard annual membership', 300.00, 'active')"
        );
        $mtype = Database::fetchOne("SELECT id FROM membership_types WHERE name = 'Regular Member'");
    }

    // 5. Create or update the test member record
    $existingMember = Database::fetchOne(
        "SELECT id FROM members WHERE member_no = 'TEST-QA-0001'"
    );

    if ($existingMember) {
        $memberId = (int)$existingMember['id'];
        Database::execute(
            "UPDATE members SET name = ?, district_id = ?, taluk_id = ?, membership_type_id = ?,
                    membership_status = 'active', updated_at = NOW()
             WHERE id = ?",
            [TEST_NAME, (int)$district['id'], (int)$taluk['id'], (int)$mtype['id'], $memberId]
        );
        echo "✓ Member record updated    [ID: $memberId, member_no: TEST-QA-0001]\n";
    } else {
        Database::execute(
            "INSERT INTO members
                 (member_no, name, designation, district_id, taluk_id, membership_type_id,
                  joining_date, membership_status)
             VALUES ('TEST-QA-0001', ?, 'PDO (TEST)', ?, ?, ?, CURDATE(), 'active')",
            [TEST_NAME, (int)$district['id'], (int)$taluk['id'], (int)$mtype['id']]
        );
        $memberId = (int)Database::lastInsertId();
        echo "✓ Member record created    [ID: $memberId, member_no: TEST-QA-0001]\n";
    }

    // 6. Create or update the member_profile
    $existingProfile = Database::fetchOne(
        "SELECT id FROM member_profiles WHERE member_id = ?", [$memberId]
    );
    if ($existingProfile) {
        Database::execute(
            "UPDATE member_profiles SET kgid_no = ?, personal_email = ?, personal_mobile = ?, updated_at = NOW()
             WHERE member_id = ?",
            [TEST_KGID, TEST_EMAIL, TEST_MOBILE, $memberId]
        );
        echo "✓ Member profile updated   [KGID: " . TEST_KGID . "]\n";
    } else {
        Database::execute(
            "INSERT INTO member_profiles (member_id, kgid_no, personal_email, personal_mobile)
             VALUES (?, ?, ?, ?)",
            [$memberId, TEST_KGID, TEST_EMAIL, TEST_MOBILE]
        );
        echo "✓ Member profile created   [KGID: " . TEST_KGID . "]\n";
    }

    // 7. Create or update the users (login) record
    $passwordHash = Auth::hashPassword(TEST_PASSWORD);

    $existingUser = Database::fetchOne(
        "SELECT id FROM users WHERE email = ? OR member_id = ?",
        [TEST_EMAIL, $memberId]
    );
    if ($existingUser) {
        Database::execute(
            "UPDATE users SET email = ?, mobile = ?, password_hash = ?, status = 'active',
                    must_change_password = 0, updated_at = NOW()
             WHERE id = ?",
            [TEST_EMAIL, TEST_MOBILE, $passwordHash, (int)$existingUser['id']]
        );
        $userId = (int)$existingUser['id'];
        echo "✓ User account updated     [ID: $userId]\n";
    } else {
        Database::execute(
            "INSERT INTO users (member_id, email, mobile, password_hash, status, must_change_password)
             VALUES (?, ?, ?, ?, 'active', 0)",
            [$memberId, TEST_EMAIL, TEST_MOBILE, $passwordHash]
        );
        $userId = (int)Database::lastInsertId();
        echo "✓ User account created     [ID: $userId]\n";
    }

    // 8. Assign 'Regular Member' role
    $role = Database::fetchOne("SELECT id FROM roles WHERE name = 'Regular Member'");
    if ($role) {
        Database::execute(
            "INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)",
            [$userId, (int)$role['id']]
        );
        echo "✓ Role assigned            [Regular Member]\n";
    }

    // 9. Create a PENDING membership payment for 2026-27 (so payment flow can be tested)
    $existingPayment = Database::fetchOne(
        "SELECT id, status FROM membership_payments
         WHERE member_id = ? AND membership_year_id = ?",
        [$memberId, (int)$year['id']]
    );

    if ($existingPayment && $existingPayment['status'] === 'completed') {
        echo "ℹ  Payment already completed for 2026-27. Reset to 'pending' for re-testing? (y/n): ";
        $line = trim((string)fgets(STDIN));
        if (strtolower($line) === 'y') {
            Database::execute(
                "UPDATE membership_payments
                 SET status = 'pending', gateway_order_id = NULL, gateway_payment_id = NULL,
                     gateway_signature = NULL, paid_at = NULL, updated_at = NOW()
                 WHERE id = ?",
                [(int)$existingPayment['id']]
            );
            echo "✓ Payment reset to pending [ID: {$existingPayment['id']}]\n";
        } else {
            echo "  Skipped payment reset.\n";
        }
    } elseif ($existingPayment) {
        echo "✓ Payment row exists       [ID: {$existingPayment['id']}, status: {$existingPayment['status']}]\n";
    } else {
        $idemKey = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        Database::execute(
            "INSERT INTO membership_payments
                 (member_id, membership_year_id, amount, status, idempotency_key, created_at)
             VALUES (?, ?, ?, 'pending', ?, NOW())",
            [$memberId, (int)$year['id'], (float)$year['fee_amount'], $idemKey]
        );
        $paymentId = (int)Database::lastInsertId();
        echo "✓ Payment row created      [ID: $paymentId, amount: ₹{$year['fee_amount']}, status: pending]\n";
    }

    echo "\n=== Test Account Ready ===\n";
    echo "  Login URL : https://kspdowa.in/member-login.php\n";
    echo "  Email     : " . TEST_EMAIL . "\n";
    echo "  Password  : " . TEST_PASSWORD . "\n";
    echo "  Member No : TEST-QA-0001\n";
    echo "  KGID      : " . TEST_KGID . "\n";
    echo "\n  Payment URL: https://kspdowa.in/payment.php\n";
    echo "  (Login first, then navigate to /payment.php or use member portal)\n\n";
    echo "  Razorpay TEST Key ID: rzp_test_TbDU2n0khZo5A0\n";
    echo "  Domain to whitelist : https://kspdowa.in\n\n";
    echo "  To delete this test account after testing:\n";
    echo "    php scripts/seed_test_account.php --delete\n\n";
}

function deleteTestAccount(): void
{
    Database::getInstance(); // initialises the singleton connection

    echo "\n=== Deleting Test Account ===\n\n";

    $member = Database::fetchOne("SELECT id FROM members WHERE member_no = 'TEST-QA-0001'");
    if (!$member) {
        echo "No test account found. Nothing to delete.\n\n";
        return;
    }
    $memberId = (int)$member['id'];

    $user = Database::fetchOne("SELECT id FROM users WHERE member_id = ?", [$memberId]);

    // Delete in FK-safe order
    if ($user) {
        Database::execute("DELETE FROM user_roles WHERE user_id = ?", [(int)$user['id']]);
        Database::execute("DELETE FROM password_resets WHERE user_id = ?", [(int)$user['id']]);
    }

    // Payment receipts → payments
    $payments = Database::fetchAll(
        "SELECT id FROM membership_payments WHERE member_id = ?", [$memberId]
    );
    foreach ($payments as $p) {
        Database::execute("DELETE FROM payment_receipts WHERE payment_id = ?", [(int)$p['id']]);
    }
    Database::execute("DELETE FROM membership_payments WHERE member_id = ?", [$memberId]);

    if ($user) {
        Database::execute("DELETE FROM users WHERE id = ?", [(int)$user['id']]);
        echo "✓ User account deleted     [ID: {$user['id']}]\n";
    }

    Database::execute("DELETE FROM member_profiles WHERE member_id = ?", [$memberId]);
    Database::execute("DELETE FROM members WHERE id = ?", [$memberId]);
    echo "✓ Member + profile deleted [ID: $memberId]\n";
    echo "\nTest account removed successfully.\n\n";
}
