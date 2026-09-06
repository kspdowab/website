<?php
/**
 * KSPDOWA — Seed Data Runner
 * ============================================================
 * Run from the project root via command line AFTER migrations:
 *
 *   php migrations/run_migrations.php
 *   php seeds/run_seeds.php
 *
 * Seeds are idempotent (INSERT IGNORE) — safe to run multiple times.
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

function info(string $msg): void { echo $msg . "\n"; }
function ok(string $msg): void   { echo "\033[32m  [OK]\033[0m  {$msg}\n"; }
function fail(string $msg): void { echo "\033[31m  [FAIL]\033[0m {$msg}\n"; }

/**
 * Split a SQL file into individual statements, respecting quoted string
 * literals and comments. A naive explode(';', $sql) / line-based comment
 * strip corrupts any statement whose string literal happens to contain
 * ';', '--' or '/*' (this bit migrations/003_rbac.sql, whose COMMENT text
 * contains a semicolon) — so this scans character-by-character and only
 * treats ';', '--' and '/* *\/' as terminators/comments when they are not
 * inside a '...'/"..."/`...` literal.
 */
function splitSql(string $sql): array
{
    $statements    = [];
    $current       = '';
    $length        = strlen($sql);
    $inSingle      = false;
    $inDouble      = false;
    $inBacktick    = false;
    $inLineComment = false;
    $inBlockComment = false;

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
                $current .= $sql[$i + 1];
                $i++;
                continue;
            }
            if ($ch === $quoteChar) {
                if ($next === $quoteChar) {
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

info("\n=== KSPDOWA Seed Runner ===\n");
info("Database: " . DB_NAME . " @ " . DB_HOST . "\n");

$seedsDir = __DIR__;
$files    = glob($seedsDir . '/*.sql');
sort($files);

if (empty($files)) {
    info("No seed files found.");
    exit(0);
}

$appliedCount = 0;

foreach ($files as $filePath) {
    $filename = basename($filePath);
    echo "  Seeding {$filename} ... ";

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

        Database::commit();
        ok("done");
        $appliedCount++;
    } catch (Throwable $e) {
        Database::rollBack();
        fail("FAILED");
        echo "         ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
}

info("\n--- Summary ---");
info("Seeded: {$appliedCount} file(s)");
info("Done.\n");
exit(0);
