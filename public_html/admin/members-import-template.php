<?php
/**
 * KSPDOWA — Admin: Members Import Template Download
 * ============================================================
 * Serves a downloadable CSV template with all 19 registration fields.
 * Column headers are clean (no parenthetical notes) so the file
 * can be re-uploaded after filling without header-parsing errors.
 * Gated by RBAC 'members.manage'.
 * ============================================================
 */
declare(strict_types=1);

ini_set('display_errors', '0');
ob_start();

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();
RBAC::requirePermission($currentUserId, 'members', 'manage');

// ── Column headers — EXACTLY 19, clean names, no parenthetical notes ─────────
// These must match the column order expected by members-import.php
$columns = [
    'Full Name',              // 1
    'Father / Husband Name',  // 2
    'Gender',                 // 3  values: male / female
    'Phone',                  // 4  10-digit, starts 6-9
    'Email',                  // 5
    'KGID No.',               // 6
    'Date of Birth',          // 7  format: DD-MM-YYYY or DD/MM/YYYY
    'GP Working?',            // 8  values: yes / no
    'Organization Type',      // 9  when GP=no: secretariat|rdpr|commissionerate|zilla_panchayat|taluk_panchayat|mp_mla_mlc_pa|other
    'Organization Name',      // 10 when GP=no
    'Organization Address',   // 11 optional
    'Working District',       // 12 when GP=yes OR org type is zilla_panchayat/taluk_panchayat
    'Working Taluk',          // 13 when GP=yes OR org type is zilla_panchayat/taluk_panchayat
    'Working GP',             // 14 optional (when GP Working=yes)
    'Membership District',    // 15 when GP=no + other org type
    'Membership Taluk',       // 16 when GP=no + other org type
    'Payment Mode',           // 17 values: offline / (leave blank for unpaid)
    'Offline Reference',      // 18 when Payment Mode=offline
    'Offline Remarks',        // 19 optional
];

// ── Example rows — 3 scenarios ────────────────────────────────────────────────
$exampleRows = [
    // Row 2: GP Working = yes
    [
        'RAJESH KUMAR',          // Full Name
        'RAMESH KUMAR',          // Father / Husband Name
        'male',                  // Gender
        '9876543210',            // Phone
        'rajesh.kumar@example.com', // Email
        'KGD12345',              // KGID No.
        '15-06-1985',            // Date of Birth (DD-MM-YYYY)
        'yes',                   // GP Working?
        '',                      // Organization Type (not needed)
        '',                      // Organization Name (not needed)
        '',                      // Organization Address
        'BAGALKOTE',             // Working District
        'BADAMI',                // Working Taluk
        'KAKANUR',               // Working GP (optional)
        '',                      // Membership District (auto from working)
        '',                      // Membership Taluk (auto from working)
        '',                      // Payment Mode (unpaid)
        '',                      // Offline Reference
        '',                      // Offline Remarks
    ],
    // Row 3: GP Working = no, Zilla Panchayat (locked org type) + offline paid
    [
        'PRIYA S',
        'SURESH S',
        'female',
        '8765432109',
        'priya.s@example.com',
        'KGD67890',
        '22-03-1990',            // Date of Birth (DD-MM-YYYY)
        'no',
        'zilla_panchayat',       // Organization Type
        'Zilla Panchayat Office Bagalkote', // Organization Name
        'Main Road Bagalkote',   // Organization Address
        'BAGALKOTE',             // Working District (required for ZP)
        'BAGALKOT',              // Working Taluk (required for ZP)
        '',                      // Working GP
        '',                      // Membership District (auto from working)
        '',                      // Membership Taluk (auto from working)
        'offline',               // Payment Mode
        'RCPT-001',              // Offline Reference
        'Cash received at office', // Offline Remarks
    ],
    // Row 4: GP Working = no, other org (manual membership location)
    [
        'MEENA T',
        'TEJA T',
        'female',
        '7654321098',
        'meena.t@example.com',
        'KGD24680',
        '10-11-1988',            // Date of Birth (DD-MM-YYYY)
        'no',
        'secretariat',           // Organization Type
        'Karnataka Secretariat', // Organization Name
        '',                      // Organization Address
        '',                      // Working District (not needed for non-ZP/TP)
        '',                      // Working Taluk
        '',                      // Working GP
        'BENGALURU',             // Membership District
        'BENGALURU NORTH',       // Membership Taluk
        '',                      // Payment Mode
        '',                      // Offline Reference
        '',                      // Offline Remarks
    ],
];

$filename = 'KSPDOWA_Members_Import_Template_' . date('Ymd') . '.csv';

ob_end_clean();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

// UTF-8 BOM — required for Excel to open correctly without encoding issues
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Row 1: Column headers (clean — no notes)
fputcsv($out, $columns, ',', '"', '\\');

// Rows 2-4: Example data rows (header is row 1, examples start immediately at row 2)
foreach ($exampleRows as $row) {
    fputcsv($out, $row, ',', '"', '\\');
}

fclose($out);
exit;
