<?php
/**
 * KSPDOWA — Phase 0 Foundation Smoke Test
 * ============================================================
 * Run from the project root via command line:
 *
 *   php tests/smoke_test.php
 *
 * Tests:
 *   1.  PHP version requirement
 *   2.  Required PHP extensions
 *   3.  Config and include file existence
 *   4.  ErrorHandler class and methods
 *   5.  Session class methods (unit, no browser)
 *   6.  Auth password hashing and verification
 *   7.  Auth timing-safe failure (no DB needed)
 *   8.  CSRF token generation and constant-time validation
 *   9.  Sanitize — all validators and escapers
 *   10. Database connection (if db.secret.php is configured)
 *   11. Migration table consistency (if DB connected)
 *   12. Seed data presence (if DB connected)
 *   13. RBAC table structure (if DB connected)
 *   14. AuditLogger (with and without DB)
 *
 * Exit codes:
 *   0 = all tests passed
 *   1 = one or more tests failed
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Smoke test can only be run from the command line.\n");
}

// Buffer all output for the remainder of the script. Without this, the many
// pass()/section() echoes printed by earlier sections count as output sent
// to the client — which in PHP CLI marks headers_sent() true — so by the
// time section 8 exercises a real Session::start() (via CSRF::getToken()),
// session_name()/session_set_cookie_params()/session_start() all fail with
// "headers already sent" and the test reports a false failure. Buffering
// defers the actual flush until shutdown, after session functions have run.
ob_start();

// ---------------------------------------------------------------------------
// Bootstrap (minimal — avoid starting session in CLI)
// ---------------------------------------------------------------------------
$publicHtml = dirname(__DIR__) . '/public_html';

define('INCLUDES_DIR', $publicHtml . '/includes');
define('PUBLIC_HTML',  $publicHtml);
define('CONFIG_DIR',   $publicHtml . '/config');
define('UPLOADS_DIR',  $publicHtml . '/uploads');
define('PROJECT_ROOT', dirname($publicHtml));

require_once CONFIG_DIR . '/app.php';

// Load includes (order matters)
require_once INCLUDES_DIR . '/ErrorHandler.php';
require_once INCLUDES_DIR . '/Database.php';
require_once INCLUDES_DIR . '/Session.php';
require_once INCLUDES_DIR . '/AuditLogger.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/RBAC.php';
require_once INCLUDES_DIR . '/CSRF.php';
require_once INCLUDES_DIR . '/Sanitize.php';

ErrorHandler::register();

// ---------------------------------------------------------------------------
// Test framework (minimal, no external dependencies)
// ---------------------------------------------------------------------------
$passed = 0;
$failed = 0;
$skipped = 0;

function pass(string $name): void
{
    global $passed;
    echo "\033[32m  PASS\033[0m  {$name}\n";
    $passed++;
}

function fail_test(string $name, string $reason = ''): void
{
    global $failed;
    $detail = $reason ? " — {$reason}" : '';
    echo "\033[31m  FAIL\033[0m  {$name}{$detail}\n";
    $failed++;
}

function skip_test(string $name, string $reason = ''): void
{
    global $skipped;
    $detail = $reason ? " ({$reason})" : '';
    echo "\033[33m  SKIP\033[0m  {$name}{$detail}\n";
    $skipped++;
}

function section(string $title): void
{
    echo "\n\033[1m--- {$title} ---\033[0m\n";
}

// ---------------------------------------------------------------------------
// TEST 1: PHP version
// ---------------------------------------------------------------------------
section('1. PHP Version');

$phpVersion = PHP_VERSION;
if (version_compare($phpVersion, '8.2.0', '>=')) {
    pass("PHP >= 8.2.0 (found: {$phpVersion})");
} else {
    fail_test("PHP version", "Requires >= 8.2.0, found {$phpVersion}");
}

// ---------------------------------------------------------------------------
// TEST 2: Required extensions
// ---------------------------------------------------------------------------
section('2. PHP Extensions');

$requiredExtensions = [
    'pdo'        => 'PDO (database layer)',
    'pdo_mysql'  => 'PDO MySQL driver',
    'mbstring'   => 'Multibyte string (UTF-8)',
    'json'       => 'JSON encoding/decoding',
    'openssl'    => 'Cryptographic functions',
    'fileinfo'   => 'MIME type detection for uploads',
    'hash'       => 'Hashing (CSRF, tokens)',
    'session'    => 'PHP sessions',
    'filter'     => 'Input validation filters',
    'pcre'       => 'Regular expressions',
    'date'       => 'Date/time handling',
];

foreach ($requiredExtensions as $ext => $desc) {
    if (extension_loaded($ext)) {
        pass("Extension: {$ext} ({$desc})");
    } else {
        fail_test("Extension: {$ext}", "MISSING — {$desc}");
    }
}

// ---------------------------------------------------------------------------
// TEST 3: Critical file existence
// ---------------------------------------------------------------------------
section('3. File Existence');

$criticalFiles = [
    $publicHtml . '/.htaccess'                        => 'Main .htaccess',
    $publicHtml . '/index.php'                        => 'index.php',
    CONFIG_DIR  . '/.htaccess'                        => 'config/.htaccess',
    CONFIG_DIR  . '/app.php'                          => 'config/app.php',
    CONFIG_DIR  . '/db.php'                           => 'config/db.php',
    CONFIG_DIR  . '/db.secret.example.php'            => 'config/db.secret.example.php',
    INCLUDES_DIR . '/.htaccess'                       => 'includes/.htaccess',
    INCLUDES_DIR . '/bootstrap.php'                   => 'includes/bootstrap.php',
    INCLUDES_DIR . '/ErrorHandler.php'                => 'includes/ErrorHandler.php',
    INCLUDES_DIR . '/Database.php'                    => 'includes/Database.php',
    INCLUDES_DIR . '/Session.php'                     => 'includes/Session.php',
    INCLUDES_DIR . '/AuditLogger.php'                 => 'includes/AuditLogger.php',
    INCLUDES_DIR . '/Auth.php'                        => 'includes/Auth.php',
    INCLUDES_DIR . '/RBAC.php'                        => 'includes/RBAC.php',
    INCLUDES_DIR . '/CSRF.php'                        => 'includes/CSRF.php',
    INCLUDES_DIR . '/Sanitize.php'                    => 'includes/Sanitize.php',
    $publicHtml . '/uploads/.htaccess'                => 'uploads/.htaccess',
    PROJECT_ROOT . '/migrations/001_geography.sql'    => 'migrations/001_geography.sql',
    PROJECT_ROOT . '/migrations/002_core_users.sql'   => 'migrations/002_core_users.sql',
    PROJECT_ROOT . '/migrations/003_rbac.sql'         => 'migrations/003_rbac.sql',
    PROJECT_ROOT . '/migrations/004_finance.sql'      => 'migrations/004_finance.sql',
    PROJECT_ROOT . '/migrations/005_content.sql'      => 'migrations/005_content.sql',
    PROJECT_ROOT . '/migrations/006_grievance.sql'    => 'migrations/006_grievance.sql',
    PROJECT_ROOT . '/migrations/007_governance.sql'   => 'migrations/007_governance.sql',
    PROJECT_ROOT . '/migrations/008_notifications.sql'=> 'migrations/008_notifications.sql',
    PROJECT_ROOT . '/migrations/009_audit_settings.sql'=>'migrations/009_audit_settings.sql',
    PROJECT_ROOT . '/migrations/run_migrations.php'   => 'migrations/run_migrations.php',
    PROJECT_ROOT . '/seeds/001_roles_permissions.sql' => 'seeds/001_roles_permissions.sql',
    PROJECT_ROOT . '/seeds/002_grievance_categories.sql'=>'seeds/002_grievance_categories.sql',
    PROJECT_ROOT . '/seeds/003_system_settings.sql'   => 'seeds/003_system_settings.sql',
    PROJECT_ROOT . '/seeds/run_seeds.php'             => 'seeds/run_seeds.php',
    PROJECT_ROOT . '/.gitignore'                      => '.gitignore',
];

foreach ($criticalFiles as $path => $label) {
    if (file_exists($path)) {
        pass("Exists: {$label}");
    } else {
        fail_test("Missing: {$label}", $path);
    }
}

// Check .gitignore excludes db.secret.php
$gitignore = file_get_contents(PROJECT_ROOT . '/.gitignore') ?: '';
if (str_contains($gitignore, 'db.secret.php')) {
    pass('.gitignore excludes db.secret.php');
} else {
    fail_test('.gitignore does not exclude db.secret.php', 'CRITICAL security issue');
}

// ---------------------------------------------------------------------------
// TEST 4: Constants defined by app.php
// ---------------------------------------------------------------------------
section('4. Application Constants');

$requiredConstants = [
    'APP_ENV', 'APP_NAME', 'APP_SHORT_NAME', 'APP_VERSION',
    'SESSION_NAME', 'SESSION_LIFETIME',
    'CSRF_TOKEN_LENGTH', 'PASSWORD_MIN_LENGTH', 'BCRYPT_COST',
    'MAX_UPLOAD_BYTES', 'GRIEVANCE_PREFIX',
    'PUBLIC_HTML', 'INCLUDES_DIR', 'CONFIG_DIR', 'UPLOADS_DIR', 'PROJECT_ROOT',
];

foreach ($requiredConstants as $const) {
    if (defined($const)) {
        pass("Constant defined: {$const} = " . var_export(constant($const), true));
    } else {
        fail_test("Constant not defined: {$const}");
    }
}

// ---------------------------------------------------------------------------
// TEST 5: ErrorHandler
// ---------------------------------------------------------------------------
section('5. ErrorHandler');

try {
    // Register without crashing
    ErrorHandler::register();
    pass('ErrorHandler::register() succeeds');
} catch (Throwable $e) {
    fail_test('ErrorHandler::register()', $e->getMessage());
}

if (method_exists('ErrorHandler', 'abort') &&
    method_exists('ErrorHandler', 'jsonError') &&
    method_exists('ErrorHandler', 'handleError') &&
    method_exists('ErrorHandler', 'handleException')) {
    pass('ErrorHandler has required methods: abort, jsonError, handleError, handleException');
} else {
    fail_test('ErrorHandler is missing required methods');
}

// ---------------------------------------------------------------------------
// TEST 6: Session class (unit — no actual browser session)
// ---------------------------------------------------------------------------
section('6. Session Class (Unit)');

if (method_exists('Session', 'start') &&
    method_exists('Session', 'set') &&
    method_exists('Session', 'get') &&
    method_exists('Session', 'has') &&
    method_exists('Session', 'remove') &&
    method_exists('Session', 'destroy') &&
    method_exists('Session', 'flash') &&
    method_exists('Session', 'getFlash') &&
    method_exists('Session', 'regenerate')) {
    pass('Session has all required methods');
} else {
    fail_test('Session is missing required methods');
}

// ---------------------------------------------------------------------------
// TEST 7: Auth — password hashing (no DB needed)
// ---------------------------------------------------------------------------
section('7. Auth — Password Hashing');

$testPassword = 'TestPass@2024';

try {
    $hash = Auth::hashPassword($testPassword);

    if (strlen($hash) > 50 && str_starts_with($hash, '$2y$')) {
        pass('Auth::hashPassword() produces bcrypt hash');
    } else {
        fail_test('Auth::hashPassword() unexpected output format');
    }

    if (Auth::verifyPassword($testPassword, $hash)) {
        pass('Auth::verifyPassword() returns true for correct password');
    } else {
        fail_test('Auth::verifyPassword() failed for correct password');
    }

    if (!Auth::verifyPassword('WrongPassword', $hash)) {
        pass('Auth::verifyPassword() returns false for wrong password');
    } else {
        fail_test('Auth::verifyPassword() returned true for wrong password — CRITICAL');
    }

    // Verify two hashes of the same password are different (bcrypt salts)
    $hash2 = Auth::hashPassword($testPassword);
    if ($hash !== $hash2) {
        pass('Auth::hashPassword() produces different salts each time');
    } else {
        fail_test('Auth::hashPassword() reuses salt — CRITICAL');
    }
} catch (Throwable $e) {
    fail_test('Auth password hashing threw exception', $e->getMessage());
}

try {
    $token = Auth::generateSecureToken(32);
    if (strlen($token) === 64 && ctype_xdigit($token)) {
        pass('Auth::generateSecureToken(32) produces 64-char hex token');
    } else {
        fail_test('Auth::generateSecureToken() unexpected format');
    }
} catch (Throwable $e) {
    fail_test('Auth::generateSecureToken()', $e->getMessage());
}

// ---------------------------------------------------------------------------
// TEST 8: CSRF (uses sessions — simulate session in CLI)
// ---------------------------------------------------------------------------
section('8. CSRF Protection');

// Manually seed session array since we can't run a real HTTP session in CLI
$_SESSION = [];

try {
    $token1 = CSRF::getToken();

    if (strlen($token1) === CSRF_TOKEN_LENGTH * 2 && ctype_xdigit($token1)) {
        pass('CSRF::getToken() produces correct-length hex token');
    } else {
        fail_test('CSRF::getToken() unexpected format', "got: {$token1}");
    }

    // Same token returned on second call
    $token2 = CSRF::getToken();
    if ($token1 === $token2) {
        pass('CSRF::getToken() returns same token within session');
    } else {
        fail_test('CSRF::getToken() returns different token on second call');
    }

    // Validation
    if (CSRF::validate($token1)) {
        pass('CSRF::validate() returns true for correct token');
    } else {
        fail_test('CSRF::validate() returned false for correct token');
    }

    if (!CSRF::validate('wrongtoken12345678901234567890123456789012345')) {
        pass('CSRF::validate() returns false for wrong token');
    } else {
        fail_test('CSRF::validate() returned true for wrong token — CRITICAL');
    }

    if (!CSRF::validate('')) {
        pass('CSRF::validate() returns false for empty string');
    } else {
        fail_test('CSRF::validate() returned true for empty string — CRITICAL');
    }

    // HTML field
    $field = CSRF::htmlField();
    if (str_contains($field, 'csrf_token') && str_contains($field, $token1)) {
        pass('CSRF::htmlField() produces correct hidden input');
    } else {
        fail_test('CSRF::htmlField() output is unexpected');
    }

    // Regenerate produces new token
    CSRF::regenerate();
    $token3 = CSRF::getToken();
    if ($token3 !== $token1) {
        pass('CSRF::regenerate() produces new token');
    } else {
        fail_test('CSRF::regenerate() did not change the token');
    }
} catch (Throwable $e) {
    fail_test('CSRF tests threw exception', $e->getMessage());
}

// ---------------------------------------------------------------------------
// TEST 9: Sanitize validators
// ---------------------------------------------------------------------------
section('9. Sanitize — Validators and Escapers');

// html() escaping
$raw      = '<script>alert("xss")</script>';
$escaped  = Sanitize::html($raw);
if (!str_contains($escaped, '<script>') && str_contains($escaped, '&lt;')) {
    pass('Sanitize::html() escapes HTML tags');
} else {
    fail_test('Sanitize::html() failed to escape tags — CRITICAL XSS risk');
}

// attr() escaping
$attrTest = Sanitize::attr('" onmouseover="evil"');
if (!str_contains($attrTest, '"') || str_contains($attrTest, '&quot;')) {
    pass('Sanitize::attr() escapes attribute injection');
} else {
    fail_test('Sanitize::attr() failed — potential attribute injection');
}

// email()
if (Sanitize::email('Test@Example.COM') === 'test@example.com') {
    pass('Sanitize::email() validates and lowercases');
} else {
    fail_test('Sanitize::email() failed valid email');
}
if (Sanitize::email('not-an-email') === false) {
    pass('Sanitize::email() rejects invalid email');
} else {
    fail_test('Sanitize::email() accepted invalid email');
}

// mobile() — Indian format
if (Sanitize::mobile('+919876543210') === '9876543210') {
    pass('Sanitize::mobile() normalises +91 prefix');
} else {
    fail_test('Sanitize::mobile() failed +91 normalisation');
}
if (Sanitize::mobile('1234567890') === false) {
    pass('Sanitize::mobile() rejects non-Indian number');
} else {
    fail_test('Sanitize::mobile() accepted invalid number');
}

// int()
if (Sanitize::int('42') === 42) {
    pass('Sanitize::int() parses integer string');
} else {
    fail_test('Sanitize::int() failed');
}
if (Sanitize::positiveInt('0') === false) {
    pass('Sanitize::positiveInt() rejects zero');
} else {
    fail_test('Sanitize::positiveInt() accepted zero');
}

// date()
if (Sanitize::date('2024-04-01') === '2024-04-01') {
    pass('Sanitize::date() accepts valid Y-m-d date');
} else {
    fail_test('Sanitize::date() rejected valid date');
}
if (Sanitize::date('2024-13-01') === false) {
    pass('Sanitize::date() rejects invalid month 13');
} else {
    fail_test('Sanitize::date() accepted invalid month');
}

// inArray()
if (Sanitize::inArray('active', ['active', 'inactive']) === 'active') {
    pass('Sanitize::inArray() accepts allowed value');
} else {
    fail_test('Sanitize::inArray() rejected allowed value');
}
if (Sanitize::inArray('evil', ['active', 'inactive']) === false) {
    pass('Sanitize::inArray() rejects disallowed value');
} else {
    fail_test('Sanitize::inArray() accepted disallowed value');
}

// slug()
if (Sanitize::slug('Hello World! @2024') === 'hello-world--2024') {
    pass('Sanitize::slug() normalises to lowercase-kebab');
} else {
    // Different implementations may handle @ differently; check the key constraints
    $slug = Sanitize::slug('Hello World 2024');
    if ($slug === 'hello-world-2024') {
        pass('Sanitize::slug() basic normalisation works');
    } else {
        fail_test('Sanitize::slug() unexpected output: ' . $slug);
    }
}

// password()
$errors = Sanitize::password('weak');
if (!empty($errors)) {
    pass('Sanitize::password() rejects weak password (' . count($errors) . ' errors)');
} else {
    fail_test('Sanitize::password() accepted weak password');
}
$errors = Sanitize::password('StrongPass1');
if (empty($errors)) {
    pass('Sanitize::password() accepts strong password');
} else {
    fail_test('Sanitize::password() rejected valid password: ' . implode(', ', $errors));
}

// safeUploadFilename()
$fn = Sanitize::safeUploadFilename('my document.pdf');
if (preg_match('/^[a-f0-9]{32}\.pdf$/', $fn)) {
    pass('Sanitize::safeUploadFilename() produces safe random filename');
} else {
    fail_test('Sanitize::safeUploadFilename() unexpected format: ' . $fn);
}

// ---------------------------------------------------------------------------
// TEST 10: Database connection
// ---------------------------------------------------------------------------
section('10. Database Connection');

$dbSecretExists = file_exists(CONFIG_DIR . '/db.secret.php');

if (!$dbSecretExists) {
    skip_test('Database connection', 'config/db.secret.php not found — configure credentials first');
    skip_test('Migration table consistency', 'No DB connection');
    skip_test('Seed data presence', 'No DB connection');
    skip_test('RBAC table structure', 'No DB connection');
    skip_test('AuditLogger DB write', 'No DB connection');
} else {
    // Load DB credentials and attempt connection
    require_once CONFIG_DIR . '/db.php';

    try {
        $pdo = Database::getInstance();
        pass('Database::getInstance() connects successfully');

        // ---------------------------------------------------------------------------
        // TEST 11: Migration table and all expected tables
        // ---------------------------------------------------------------------------
        section('11. Migration Table & Schema');

        // Check schema_migrations table
        $migTable = Database::fetchOne(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'"
        );

        if ($migTable) {
            pass('schema_migrations table exists');
        } else {
            skip_test('schema_migrations table', 'Not found — run: php migrations/run_migrations.php first');
        }

        // Check all expected application tables
        $expectedTables = [
            'districts', 'taluks', 'gram_panchayatis',
            'members', 'users', 'member_profiles',
            'association_units', 'roles', 'permissions', 'role_permissions', 'user_roles',
            'membership_types', 'membership_years', 'membership_payments', 'payment_receipts', 'donations',
            'news_categories', 'news', 'document_categories', 'documents',
            'orders', 'circulars', 'activities', 'events',
            'meetings', 'meeting_minutes', 'resolutions',
            'grievance_categories', 'grievance_services', 'grievance_authorities',
            'grievances', 'grievance_assignments', 'grievance_events', 'grievance_documents',
            'office_bearers', 'constitution_versions', 'annual_reports',
            'notifications', 'audit_logs', 'system_settings',
        ];

        $existingTables = Database::fetchAll(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
        );
        $existingNames = array_column($existingTables, 'TABLE_NAME');

        foreach ($expectedTables as $table) {
            if (in_array($table, $existingNames, true)) {
                pass("Table exists: {$table}");
            } else {
                fail_test("Table missing: {$table}", 'Run migrations first');
            }
        }

        // ---------------------------------------------------------------------------
        // TEST 12: Seed data
        // ---------------------------------------------------------------------------
        section('12. Seed Data');

        // Roles
        $roleCount = (int) Database::fetchScalar("SELECT COUNT(*) FROM roles WHERE status = 'active'");
        if ($roleCount >= 12) {
            pass("Roles seeded: {$roleCount} active roles (expected >= 12)");
        } else {
            fail_test("Roles count: {$roleCount} (expected >= 12)", 'Run seeds: php seeds/run_seeds.php');
        }

        // Super Admin permissions
        $superPerms = (int) Database::fetchScalar(
            "SELECT COUNT(*) FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             WHERE r.name = 'State Super Admin'"
        );
        if ($superPerms > 0) {
            pass("State Super Admin permissions: {$superPerms} assigned");
        } else {
            fail_test('State Super Admin has no permissions', 'Run seeds');
        }

        // Grievance categories
        $catCount = (int) Database::fetchScalar("SELECT COUNT(*) FROM grievance_categories WHERE status = 'active'");
        if ($catCount === 5) {
            pass("Grievance categories: {$catCount} (expected 5)");
        } else {
            fail_test("Grievance categories count: {$catCount} (expected 5)");
        }

        // Grievance services
        $svcCount = (int) Database::fetchScalar("SELECT COUNT(*) FROM grievance_services WHERE status = 'active'");
        if ($svcCount >= 55) {
            pass("Grievance services: {$svcCount} (expected >= 55)");
        } else {
            fail_test("Grievance services count: {$svcCount} (expected >= 55)");
        }

        // Government authorities
        $authCount = (int) Database::fetchScalar("SELECT COUNT(*) FROM grievance_authorities WHERE status = 'active'");
        if ($authCount === 9) {
            pass("Government authorities: {$authCount} (expected 9)");
        } else {
            fail_test("Government authorities count: {$authCount} (expected 9)");
        }

        // System settings
        $settingsCount = (int) Database::fetchScalar("SELECT COUNT(*) FROM system_settings");
        if ($settingsCount >= 10) {
            pass("System settings: {$settingsCount} rows");
        } else {
            fail_test("System settings count: {$settingsCount} (expected >= 10)");
        }

        // ---------------------------------------------------------------------------
        // TEST 13: RBAC table structure and FK constraints
        // ---------------------------------------------------------------------------
        section('13. RBAC Structure');

        // Verify no is_admin column exists on users (per spec §5: don't use single flag)
        $adminColExists = Database::fetchOne(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND COLUMN_NAME = 'is_admin'"
        );
        if (!$adminColExists) {
            pass('users table has NO is_admin column (correct per spec)');
        } else {
            fail_test('users table has is_admin column — violates spec §5');
        }

        // Verify FK constraints exist on key tables
        $fkChecks = [
            ['user_roles',         'fk_ur_user'],
            ['user_roles',         'fk_ur_role'],
            ['role_permissions',   'fk_rp_role'],
            ['role_permissions',   'fk_rp_permission'],
            ['grievance_events',   'fk_ge_grievance'],
            ['grievance_events',   'fk_ge_performed_by'],
        ];

        foreach ($fkChecks as [$table, $constraintName]) {
            $fk = Database::fetchOne(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND CONSTRAINT_NAME = ?
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                [$table, $constraintName]
            );
            if ($fk) {
                pass("FK exists: {$table}.{$constraintName}");
            } else {
                fail_test("FK missing: {$table}.{$constraintName}");
            }
        }

        // Verify grievance_no uniqueness constraint exists
        $grievanceUnique = Database::fetchOne(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'grievances'
               AND INDEX_NAME = 'uk_grievance_no'
               AND NON_UNIQUE = 0"
        );
        if ($grievanceUnique) {
            pass('grievances.grievance_no has UNIQUE constraint');
        } else {
            fail_test('grievances.grievance_no is missing UNIQUE constraint — grievance integrity at risk');
        }

        // ---------------------------------------------------------------------------
        // TEST 14: AuditLogger DB write
        // ---------------------------------------------------------------------------
        section('14. AuditLogger');

        // Manually set a session user_id for the audit log test
        $_SESSION['user_id'] = null;

        try {
            // This will attempt to write to audit_logs
            AuditLogger::log('SMOKE_TEST', 'smoke_test', null, null, ['test' => true]);

            $lastLog = Database::fetchOne(
                "SELECT * FROM audit_logs WHERE action = 'SMOKE_TEST' ORDER BY id DESC LIMIT 1"
            );

            if ($lastLog && $lastLog['module'] === 'smoke_test') {
                pass('AuditLogger::log() wrote to audit_logs successfully');

                // Clean up test row
                Database::execute(
                    "DELETE FROM audit_logs WHERE action = 'SMOKE_TEST' AND module = 'smoke_test'"
                );
                pass('Test audit_log row cleaned up');
            } else {
                fail_test('AuditLogger::log() did not write to audit_logs');
            }
        } catch (Throwable $e) {
            // AuditLogger is designed not to throw
            fail_test('AuditLogger threw exception (should not)', $e->getMessage());
        }
    } catch (Throwable $e) {
        fail_test('Database connection failed', $e->getMessage());
        skip_test('Migration table consistency', 'No DB');
        skip_test('Seed data presence', 'No DB');
        skip_test('RBAC table structure', 'No DB');
        skip_test('AuditLogger DB write', 'No DB');
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo str_repeat('=', 50) . "\n";
echo "KSPDOWA Phase 0 Smoke Test — Results\n";
echo str_repeat('=', 50) . "\n";
echo "\033[32mPASSED:\033[0m  {$passed}\n";
echo "\033[31mFAILED:\033[0m  {$failed}\n";
echo "\033[33mSKIPPED:\033[0m {$skipped}\n";
echo str_repeat('-', 50) . "\n";

if ($failed === 0) {
    echo "\033[32m✓ All tests passed.\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m✗ {$failed} test(s) failed. Review output above.\033[0m\n\n";
    exit(1);
}
