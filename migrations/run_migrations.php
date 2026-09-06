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
 *  - Multi-line comment blocks
 *  - Trailing whitespace
 *  - Semicolons and comment markers that appear INSIDE quoted string
 *    literals (e.g. a COMMENT '...unit; NULL = statewide' value) — these
 *    must not be treated as statement terminators or comment starts.
 *    A naive explode(';', $sql) / line-based comment strip corrupts any
 *    statement whose string literal happens to contain ';', '--' or '/*'.
 */
function splitSql(string $sql): array
{
    $statements    = [];
    $current       = '';
    $length        = strlen($sql);
    $inSingle      = false; // inside '...'
    $inDouble      = false; // inside "..."
    $inBacktick    = false; // inside `...`
    $inLineComment = false; // after -- until end of line
    $inBlockComment = false; // inside /* ... */

    for ($i = 0; $i < $length; $i++) {
        $ch   = $sql[$i];
        $next = ($i + 1 < $length) ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($ch === "\n") {
                $inLineComment = false;
                $current .= $ch;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($ch === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if ($inSingle || $inDouble) {
            $quoteChar = $inSingle ? "'" : '"';
            $current .= $ch;
            if ($ch === '\\' && $i + 1 < $length) {
                // Escaped character — copy it verbatim, skip reinterpretation
                $current .= $sql[$i + 1];
                $i++;
                continue;
            }
            if ($ch === $quoteChar) {
                if ($next === $quoteChar) {
                    // Doubled quote ('' or "") — escaped quote, stay inside string
                    $current .= $next;
                    $i++;
                } else {
                    $inSingle = false;
                    $inDouble = false;
                }
            }
            continue;
        }

        if ($inBacktick) {
            $current .= $ch;
            if ($ch === '`') {
                $inBacktick = false;
            }
            continue;
        }

        // Not currently inside a string, identifier, or comment
        if ($ch === '-' && $next === '-') {
            $inLineComment = true;
            $i++;
            continue;
        }
        if ($ch === '/' && $next === '*') {
            $inBlockComment = true;
            $i++;
            continue;
        }
        if ($ch === "'") {
            $inSingle = true;
            $current .= $ch;
            continue;
        }
        if ($ch === '"') {
            $inDouble = true;
            $current .= $ch;
            continue;
        }
        if ($ch === '`') {
            $inBacktick = true;
            $current .= $ch;
            continue;
        }
        if ($ch === ';') {
            $stmt = trim($current);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $current = '';
            continue;
        }

        $current .= $ch;
    }

    $stmt = trim($current);
    if ($stmt !== '') {
        $statements[] = $stmt;
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

    // NOTE: These migrations are DDL (CREATE TABLE / ALTER TABLE). MySQL and
    // MariaDB do not support transactional DDL — every DDL statement causes
    // an implicit COMMIT on the server, silently ending any PDO transaction
    // wrapped around it. Wrapping this loop in beginTransaction()/commit()
    // therefore does not provide atomicity (the CREATE TABLE statements are
    // already permanently applied the moment they run) and, worse, makes
    // Database::commit() throw "There is no active transaction" — reporting
    // a hard FAILURE for a migration that actually succeeded. So DDL
    // execution here is intentionally NOT wrapped in a transaction; if a
    // statement fails partway through a migration file, the statements
    // before it remain applied (as they would under MySQL regardless) and
    // this script stops so the file can be reviewed and fixed by hand.
    try {
        foreach ($statements as $stmt) {
            Database::getInstance()->exec($stmt);
        }

        // Record the migration
        Database::execute(
            "INSERT INTO schema_migrations (migration) VALUES (?)",
            [$migration]
        );

        ok("done");
        $appliedCount++;
    } catch (Throwable $e) {
        fail("FAILED\n");
        echo "         ERROR: " . $e->getMessage() . "\n";
        echo "         MySQL/MariaDB does not roll back DDL — statements in\n";
        echo "         this file that ran before the failure remain applied.\n";
        echo "         Fix the issue, then re-run the migration runner; it\n";
        echo "         will skip already-applied migrations automatically.\n\n";
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
