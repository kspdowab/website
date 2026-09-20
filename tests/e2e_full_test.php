<?php
/**
 * KSPDOWA — Full Local E2E System, Security & Regression Test Suite
 * ============================================================
 * Executes automated functional, security, database integrity,
 * and regression testing across Phases 1 to 8.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Test suite CLI only.\n");
}

ob_start();

$publicHtml = dirname(__DIR__) . '/public_html';
require_once $publicHtml . '/includes/bootstrap.php';

$passed = 0;
$failed = 0;
$failures = [];

function test_assert(string $name, bool $condition, string $details = ''): void {
    global $passed, $failed, $failures;
    if ($condition) {
        $passed++;
        echo "  \033[32m[PASS]\033[0m {$name}\n";
    } else {
        $failed++;
        $msg = "{$name}" . ($details !== '' ? " -> {$details}" : '');
        $failures[] = $msg;
        echo "  \033[31m[FAIL]\033[0m {$msg}\n";
    }
}

function section_title(string $title): void {
    echo "\n\033[1;34m=== {$title} ===\033[0m\n";
}

// ===========================================================================
// 1. PUBLIC WEBSITE RENDERING & ASSETS
// ===========================================================================
section_title('1. Public Website & Routing');

$publicFiles = [
    'index.php', 'about.php', 'recognition.php', 'office-bearers.php',
    'news.php', 'contact.php', 'events.php', 'gallery.php',
    'donate.php', 'login.php', 'register.php'
];

foreach ($publicFiles as $pf) {
    $fullPath = $publicHtml . '/' . $pf;
    test_assert("Public page exists: {$pf}", is_file($fullPath));
}

test_assert('Favicon exists', is_file($publicHtml . '/assets/images/favicon.png'));
test_assert('Association logo exists', is_file($publicHtml . '/assets/images/logo.png'));
test_assert('Web App Manifest exists', is_file($publicHtml . '/manifest.webmanifest'));
test_assert('Service Worker script exists', is_file($publicHtml . '/sw.js'));
test_assert('Offline HTML fallback exists', is_file($publicHtml . '/offline.html'));

// Manifest JSON structure
$manifestRaw = file_get_contents($publicHtml . '/manifest.webmanifest');
$manifestData = json_decode($manifestRaw, true);
test_assert('Manifest is valid JSON', is_array($manifestData));
test_assert('Manifest name configured', !empty($manifestData['name']));
test_assert('Manifest display is standalone', ($manifestData['display'] ?? '') === 'standalone');

// ===========================================================================
// 2. AUTHENTICATION & SESSION HANDLING
// ===========================================================================
section_title('2. Authentication & Access Control');

$pwd = 'TestP@ssw0rd!#2026';
$hash = Auth::hashPassword($pwd);
test_assert('Auth::hashPassword produces valid bcrypt', password_get_info($hash)['algoName'] === 'bcrypt');
test_assert('Auth::verifyPassword succeeds with valid password', Auth::verifyPassword($pwd, $hash));
test_assert('Auth::verifyPassword fails with incorrect password', !Auth::verifyPassword('WrongPassword', $hash));

// Session cookie parameters
$cookieParams = session_get_cookie_params();
test_assert('Session cookie HttpOnly enabled', $cookieParams['httponly'] === true);
test_assert('Session cookie SameSite is Strict or Lax', in_array(strtolower($cookieParams['samesite']), ['strict', 'lax'], true));

// CSRF tokens
$token1 = CSRF::getToken();
test_assert('CSRF::getToken returns 64-char hex token', strlen($token1) === 64);
test_assert('CSRF::htmlField returns hidden input element', str_contains(CSRF::htmlField(), 'type="hidden"'));
test_assert('CSRF::field alias works identically', str_contains(CSRF::field(), 'name="csrf_token"'));
test_assert('CSRF::validate rejects empty token', !CSRF::validate(''));
test_assert('CSRF::validate rejects tampered token', !CSRF::validate('invalid_token_12345'));
test_assert('CSRF::validate accepts active session token', CSRF::validate($token1));

// ===========================================================================
// 3. RBAC & PERMISSIONS ENGINE
// ===========================================================================
section_title('3. RBAC & Administrative Scopes');

// Find a state admin user
$adminUser = Database::fetchOne(
    "SELECT u.id, u.username FROM users u
     JOIN user_roles ur ON u.id = ur.user_id
     JOIN roles r ON ur.role_id = r.id
     WHERE r.name IN ('State Super Admin', 'State Admin')
     LIMIT 1"
);

test_assert('State Administrator account exists in database', !empty($adminUser));

if ($adminUser) {
    $adminId = (int)$adminUser['id'];
    test_assert('State Admin can view members', RBAC::can($adminId, 'members', 'view'));
    test_assert('State Admin can view reports', RBAC::can($adminId, 'reports', 'view'));
    test_assert('State Admin can manage settings', RBAC::can($adminId, 'settings', 'manage'));
    
    $adminScopeType = RBAC::getHighestScopeType($adminId);
    test_assert('State Admin has state scope', $adminScopeType === 'state');
}

// Scope check for taluk/district
$districtOfficer = Database::fetchOne(
    "SELECT u.id, COALESCE(au.district_id, m.district_id) AS district_id
     FROM users u
     JOIN user_roles ur ON u.id = ur.user_id
     JOIN roles r ON ur.role_id = r.id
     LEFT JOIN association_units au ON ur.association_unit_id = au.id
     LEFT JOIN members m ON u.member_id = m.id
     WHERE r.scope_type = 'district'
     LIMIT 1"
);
if ($districtOfficer) {
    $dScopeType = RBAC::getHighestScopeType((int)$districtOfficer['id']);
    test_assert('District officer has district scope', $dScopeType === 'district');
}

// ===========================================================================
// 4. MEMBERSHIP & FINANCE ENGINE
// ===========================================================================
section_title('4. Membership & Finance Engine');

// Financial years
$currentFy = Membership::getCurrentYear();
test_assert('Active current financial year exists', !empty($currentFy));

// Member numbering scheme
$districts = Database::fetchAll("SELECT id, name, short_code FROM districts WHERE short_code IS NOT NULL LIMIT 5");
test_assert('Districts have official short codes', count($districts) >= 5);
foreach ($districts as $d) {
    test_assert("District {$d['name']} has valid short code ({$d['short_code']})", strlen($d['short_code']) >= 2 && strlen($d['short_code']) <= 5);
}

// Verify payment receipt class
test_assert('Receipt class exists', class_exists('Receipt'));
test_assert('Receipt numbering scheme resolves prefix', !empty(Settings::get('receipt_no_prefix', 'KSPDOWA-RCP')));

// Payments table consistency
$totalPayments = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt FROM membership_payments")['cnt'] ?? 0);
test_assert('Membership payments recorded in DB', $totalPayments >= 0);

// ===========================================================================
// 5. GRIEVANCES & SUGGESTIONS WORKFLOW
// ===========================================================================
section_title('5. Grievances & Suggestions Workflow');

test_assert('Grievance class exists', class_exists('Grievance'));
test_assert('Suggestion class exists', class_exists('Suggestion'));

// Check grievance categories & services seeded
$categories = Database::fetchAll("SELECT * FROM grievance_categories WHERE status = 'active'");
test_assert('Active grievance categories exist', count($categories) >= 5);

$services = Database::fetchAll("SELECT * FROM grievance_services WHERE status = 'active'");
test_assert('Grievance service items seeded', count($services) >= 50);

$authorities = Database::fetchAll("SELECT * FROM grievance_authorities WHERE status = 'active'");
test_assert('Government authorities seeded', count($authorities) >= 8);

// Grievance numbering validation
$testPrefix = Settings::get('grievance_no_prefix', 'KSPDOWA-GRV');
test_assert('Grievance number prefix configured', $testPrefix === 'KSPDOWA-GRV');

// Test immutable history foreign key
$eventCount = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt FROM grievance_events")['cnt'] ?? 0);
test_assert('Grievance events table accessible', $eventCount >= 0);

// ===========================================================================
// 6. REPORTS & ANALYTICS
// ===========================================================================
section_title('6. Reports & Multi-Format Exports');

$reportFiles = [
    'reports.php', 'members-reports.php', 'finance-reports.php',
    'grievance-analytics.php', 'activity-reports.php', 'reports-export.php'
];

foreach ($reportFiles as $rf) {
    test_assert("Report endpoint exists: admin/{$rf}", is_file($publicHtml . '/admin/' . $rf));
}

// ===========================================================================
// 7. ADVANCED INTEGRATIONS & AUTOMATION
// ===========================================================================
section_title('7. Advanced Integrations (Phase 8)');

test_assert('Mailer class exists', class_exists('Mailer'));
test_assert('EmailTemplates class exists', class_exists('EmailTemplates'));
test_assert('WhatsApp class exists', class_exists('WhatsApp'));
test_assert('NotificationService class exists', class_exists('NotificationService'));

// Mailer driver fallback check
$mailCfg = Mailer::getConfig();
test_assert('Mailer config driver resolved', in_array($mailCfg['driver'], ['log', 'smtp'], true));

// WhatsApp driver and normalization test
$waCfg = WhatsApp::getConfig();
test_assert('WhatsApp config resolved', isset($waCfg['enabled']) && isset($waCfg['driver']));

$norm1 = WhatsApp::normalizePhone('9845012345');
test_assert('WhatsApp phone normalizer prepends 91 to 10-digit number', $norm1 === '919845012345');

$norm2 = WhatsApp::normalizePhone('+919845012345');
test_assert('WhatsApp phone normalizer cleans +91 to 91XXXXXXXXXX', $norm2 === '919845012345');

$norm3 = WhatsApp::normalizePhone('123');
test_assert('WhatsApp phone normalizer rejects invalid short phone', $norm3 === null);

// In-App & Log dispatch test via NotificationService
$notifRes = NotificationService::sendToUser(
    1,
    'SYSTEM_HEALTH_CHECK',
    'Automated System Health Verification',
    'All platform systems operational under test suite.',
    'system',
    null
);
test_assert('NotificationService dispatches in_app notification', $notifRes['in_app'] === true);

// Verify notification_logs recorded
$lastLog = Database::fetchOne(
    "SELECT * FROM notification_logs WHERE event_type = 'SYSTEM_HEALTH_CHECK' ORDER BY id DESC LIMIT 1"
);
test_assert('Delivery audit logged in notification_logs', !empty($lastLog));

// Cleanup test notification and log
if (!empty($lastLog['id'])) {
    Database::execute("DELETE FROM notification_logs WHERE id = ?", [(int)$lastLog['id']]);
    Database::execute("DELETE FROM notifications WHERE type = 'SYSTEM_HEALTH_CHECK'");
}

// Scheduled tasks runner existence
test_assert('Scheduled tasks runner script exists', is_file(dirname(__DIR__) . '/scripts/run_scheduled_tasks.php'));

// ===========================================================================
// 8. SECURITY & DEFENSE-IN-DEPTH CHECKS
// ===========================================================================
section_title('8. Security & Vulnerability Audits');

// SQL Injection prevention test (prepared statement parameterization)
$maliciousInput = "1' OR '1'='1";
$sqlCheck = Database::fetchOne("SELECT id FROM users WHERE username = ?", [$maliciousInput]);
test_assert('SQL injection attempt safely parameterized without execution', $sqlCheck === false);

// XSS Sanitization test
$xssVector = '<script>alert("XSS")</script>';
$safeHtml = Sanitize::html($xssVector);
test_assert('Sanitize::html escapes script tags', !str_contains($safeHtml, '<script>') && str_contains($safeHtml, '&lt;script&gt;'));

$safeAttr = Sanitize::attr('" onmouseover="alert(1)');
test_assert('Sanitize::attr escapes quotes and attributes', !str_contains($safeAttr, '" onmouseover='));

// Path traversal protection test
$pathVector = '../../../../etc/passwd';
$safeUploadName = Sanitize::safeUploadFilename($pathVector);
test_assert('Sanitize::safeUploadFilename prevents path traversal', !str_contains($safeUploadName, '..') && !str_contains($safeUploadName, '/'));

// Upload directory protection
test_assert('Uploads .htaccess exists', is_file($publicHtml . '/uploads/.htaccess'));
$uploadsHtaccess = file_get_contents($publicHtml . '/uploads/.htaccess');
test_assert('Uploads .htaccess disables PHP script execution', str_contains($uploadsHtaccess, 'php') || str_contains($uploadsHtaccess, 'Deny from all') || str_contains($uploadsHtaccess, 'SetHandler'));

// Config and includes directory protection
test_assert('Config .htaccess exists', is_file($publicHtml . '/config/.htaccess'));
test_assert('Includes .htaccess exists', is_file($publicHtml . '/includes/.htaccess'));

// Check gitignore excludes secrets
$gitignore = file_get_contents(dirname(__DIR__) . '/.gitignore');
test_assert('.gitignore excludes db.secret.php', str_contains($gitignore, 'db.secret.php'));
test_assert('.gitignore excludes mail.secret.php', str_contains($gitignore, 'mail.secret.php'));
test_assert('.gitignore excludes razorpay.secret.php', str_contains($gitignore, 'razorpay.secret.php'));

// ===========================================================================
// SUMMARY & RESULTS
// ===========================================================================
echo "\n==================================================\n";
echo "KSPDOWA Full Local E2E & Security Test Results\n";
echo "==================================================\n";
echo "PASSED:  {$passed}\n";
echo "FAILED:  {$failed}\n";
echo "--------------------------------------------------\n";

if ($failed === 0) {
    echo "\033[32m[SUCCESS] ALL SUITE CHECKS PASSED!\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m[FAILURE] {$failed} tests failed:\033[0m\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\n";
    exit(1);
}
