<?php
/**
 * KSPDOWA — One-time content import: Recognition Order document
 * ============================================================
 * Registers the already-copied PDF (public_html/uploads/documents/
 * kspdowa-recognition-order-rdpr184gps2020.pdf) in the `documents`
 * table so /document.php and the Recognition page can serve it.
 *
 * Run AFTER scripts/bootstrap_admin.php — `documents.uploaded_by`
 * is a NOT NULL foreign key to `users`, so at least one user must
 * already exist. This can't live in seeds/*.sql because seeds run
 * before any user account exists.
 *
 * Usage: php scripts/seed_recognition_document.php
 * Safe to re-run — checks for an existing row by file_path first.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require dirname(__DIR__) . '/public_html/config/db.php';
require dirname(__DIR__) . '/public_html/includes/Database.php';

const FILE_PATH = 'documents/kspdowa-recognition-order-rdpr184gps2020.pdf';

try {
    $existing = Database::fetchOne('SELECT id FROM documents WHERE file_path = ?', [FILE_PATH]);
    if ($existing) {
        echo "Already registered as document id {$existing['id']} — nothing to do.\n";
        exit(0);
    }

    $user = Database::fetchOne(
        "SELECT u.id FROM users u
         JOIN user_roles ur ON ur.user_id = u.id
         JOIN roles r ON r.id = ur.role_id
         WHERE r.name = 'State Super Admin'
         ORDER BY u.id LIMIT 1"
    );
    if (!$user) {
        fwrite(STDERR, "ERROR: No 'State Super Admin' user found. Run scripts/bootstrap_admin.php first.\n");
        exit(1);
    }
    $userId = (int) $user['id'];

    $category = Database::fetchOne("SELECT id FROM document_categories WHERE name = 'Official Documents'");
    if (!$category) {
        Database::execute(
            "INSERT INTO document_categories (name, access_level, status) VALUES (?, 'public', 'active')",
            ['Official Documents']
        );
        $categoryId = (int) Database::lastInsertId();
    } else {
        $categoryId = (int) $category['id'];
    }

    $description = "Order No. RDPR 184 GPS 2020, dated 30-12-2020.\n"
        . "Issuing authority: Government of Karnataka, Rural Development and Panchayat Raj Department "
        . "(signed by B. Naveen Kumar, Under Secretary to Government (ZP), Addl. charge).\n"
        . "Subject: Granting recognition to the Karnataka State Panchayat Development Officers' "
        . "Welfare Association (Regd.).\n"
        . "Issued under the Karnataka Civil Services (Recognition of Service Associations) Rules, 2015, "
        . "referencing Government Notification No. SiKaSu 6 ESBM 2013 dated 04-01-2016.\n"
        . "Per the order text, recognition was granted for a period of 2 years from this order, "
        . "subject to conditions and subsequent renewal.";

    Database::execute(
        "INSERT INTO documents
            (title, category_id, description, file_path, access_level, published_at, uploaded_by, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'public', NOW(), ?, 'active', NOW(), NOW())",
        [
            'Government Order — Recognition of KSPDOWA (RDPR 184 GPS 2020)',
            $categoryId,
            $description,
            FILE_PATH,
            $userId,
        ]
    );

    $newId = (int) Database::lastInsertId();
    echo "Registered Recognition Order as document id {$newId}.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
