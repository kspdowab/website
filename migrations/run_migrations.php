<?php
/**
 * KSPDOWA — Database Migration Runner
 * ============================================================
 * Run from the project root via command line:
 *
 *   php migrations/run_migrations.php
 *
 * This script:
 *   1. Creates the schema_migrations tracking table if needed.
 *   2. Reads all *.sql files in migrations/ (sorted numerically).
 *   3. Skips already-applied migrations.
 *   4. Applies unapplied migrations inside transactions.
 *   5. Records each applied migration in schema_migrations.
 *
 * On failure the current migration is rolled back and the script exits.
 * Previously applied migrations are not rolled back.
 *
 * NOTE: This runner is CLI-only. Never expose it as a web endpoint.
 * ============================================================
 */

declare(strict_types=1);

// CLI only
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

// ---------------------------------------------------------------------------
// Bootstrap (load config and DB classes without starting a web session)
// ---------------------------------------------------------------------------
$publicHtml = dirname(__DIR__) . '/public_html';

if (!is_dir($publicHtml)) {
    exit("ERROR: public_html directory not found at {$publicHtml}\n");
}

require_once $publicHtml . '/config/app.php';
require_once $publicHtml . '/config/db.php';
require_once $publicHtml . '/includes/Database.php';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function info(string $msg): void { echo $msg . "\n"; }
function ok(string $msg): void   { echo "\033[32m  [OK]\033[0m  {$msg}\n"; }
function skip(string $msg): void { echo "\033[33m  [SKIP]\033[0m {$msg}\n"; }
function fail(string $msg): void { echo "\033[31m  [FAIL]\033[0m {$msg}\n"; }

/**
 * Split a SQL file into individual statements.
 * Handles:
 *  - Single-line -- comments
 *  - Multi-line comment blocks (not common in our DDL files)
 *  - Trailing whitespace
 */
function splitSql(string $sql): array
{
    // Remove block comments /* … */
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

    // Split on semicolon (statement terminator)
    $raw        = explode(';', $sql);
    $statements = [];

    foreach ($raw as $chunk) {
        // Remove single-line comments
        $lines = explode("\n", $chunk);
        $clean = [];
        foreach ($lines as $line) {
            $stripped = preg_replace('/--[^\n]*$/', '', $line);
            $clean[]  = $stripped;
        }
        $stmt = trim(implode("\n", $clean));
        if ($stmt !== '') {
            $statements[] = $stmt;
        }
    }

    return $statements;
}

// ---------------------------------------------------------------------------
// Create schema_migrations table
// ---------------------------------------------------------------------------
info("\n=== KSPDOWA Migration Runner ===\n");
info("Database: " . DB_NAME . " @ " . DB_HOST . "\n");

try {
    Database::execute(
        "CREATE TABLE IF NOT EXISTS `schema_migrations` (
          `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `migration`  VARCHAR(255) NOT NULL,
          `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_migration` (`migration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Throwable $e) {
    fail("Could not create schema_migrations table: " . $e->getMessage());
    exit(1);
}

// ---------------------------------------------------------------------------
// Load already-applied migrations
// ---------------------------------------------------------------------------
$applied = [];
try {
    $rows = Database::fetchAll("SELECT migration FROM schema_migrations ORDER BY id");
    foreach ($rows as $row) {
        $applied[$row['migration']] = true;
    }
} catch (Throwable $e) {
    fail("Could not read schema_migrations: " . $e->getMessage());
    exit(1);
}

// ---------------------------------------------------------------------------
// Discover migration files
// ---------------------------------------------------------------------------
$migrationsDir = __DIR__;
$files         = glob($migrationsDir . '/*.sql');
sort($files); // ensures 001 before 002, etc.

if (empty($files)) {
    info("No SQL migration files found in {$migrationsDir}");
    exit(0);
}

$appliedCount = 0;
$skippedCount = 0;

// ---------------------------------------------------------------------------
// Apply migrations
// ---------------------------------------------------------------------------
foreach ($files as $filePath) {
    $migration = basename($filePath);

    if (isset($applied[$migration])) {
        skip("{$migration} (already applied)");
        $skippedCount++;
        continue;
    }

    echo "  Applying {$migration} ... ";

    $sql = file_get_contents($filePath);
    if ($sql === false) {
        fail("Could not read {$filePath}");
        exit(1);
    }

    $statements = splitSql($sql);

    try {
        Database::beginTransaction();

        foreach ($statements as $stmt) {
            Database::getInstance()->exec($stmt);
        }

        // Record the migration
        Database::execute(
            "INSERT INTO schema_migrations (migration) VALUES (?)",
            [$migration]
        );

        Database::commit();

        ok("done");
        $appliedCount++;
    } catch (Throwable $e) {
        Database::rollBack();
        fail("FAILED\n");
        echo "         ERROR: " . $e->getMessage() . "\n";
        echo "         The failed migration has been rolled back.\n";
        echo "         Fix the issue and re-run the migration runner.\n\n";
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
info("\n--- Summary ---");
info("Applied:  {$appliedCount}");
info("Skipped:  {$skippedCount}");
info("Done.\n");
exit(0);
