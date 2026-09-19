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

// ── Column headers — EXACTLY 21, clean names, no parenthetical notes ─────────
// These must match the column order expected by members-import.php
$columns = [
    'Full Name',              // 1
    'Father / Husband Name',  // 2
    'Gender',                 // 3  optional (male / female / other / blank)
    'Phone',                  // 4  10-digit, starts 6-9
    'Email',                  // 5
    'KGID No.',               // 6  format: numeric digits only (e.g. 1234567)
    'Date of Birth',          // 7  format: DD-MM-YYYY or DD/MM/YYYY
    'GP Working?',            // 8  values: yes / no
    'Organization Type',      // 9  when GP=no: secretariat|rdpr|commissionerate|zilla_panchayat|taluk_panchayat|mp_mla_mlc_pa|other
    'Organization Name',      // 10 when GP=no
    'Organization Address',   // 11 optional
    'Working District',       // 12 when GP=yes OR org type is zilla_panchayat/taluk_panchayat
    'Working Taluk',          // 13 when GP=yes OR org type is zilla_panchayat/taluk_panchayat
    'Working GP',             // 14 optional (can leave blank, member will update later)
    'Membership District',    // 15 when GP=no + other org type
    'Membership Taluk',       // 16 when GP=no + other org type
    'Payment Mode',           // 17 values: razorpay / offline / (leave blank for unpaid)
    'Payment Reference',      // 18 Razorpay Payment ID (e.g. pay_...) or offline reference / UTR
    'Payment Date & Time',    // 19 format: DD-MM-YYYY HH:MM:SS or DD/MM/YYYY
    'Received Amount',        // 20 fee amount in INR (e.g. 500)
    'Payment Remarks',        // 21 optional
];

// ── Example rows — 3 scenarios ────────────────────────────────────────────────
$exampleRows = [
    // Row 2: GP Working = yes, Legacy Razorpay payment (Gender & GP left blank)
    [
        'RAJESH KUMAR',          // Full Name
        'RAMESH KUMAR',          // Father / Husband Name
        '',                      // Gender (optional - left blank)
        '9876543210',            // Phone
        'rajesh.kumar@example.com', // Email
        '1234567',               // KGID No. (numeric digits only)
        '15-06-1985',            // Date of Birth (DD-MM-YYYY)
        'yes',                   // GP Working?
        '',                      // Organization Type (not needed)
        '',                      // Organization Name (not needed)
        '',                      // Organization Address
        'BAGALKOTE',             // Working District
        'BADAMI',                // Working Taluk
        '',                      // Working GP (optional - left blank)
        '',                      // Membership District (auto from working)
        '',                      // Membership Taluk (auto from working)
        'razorpay',              // Payment Mode
        'pay_ABC123456789',      // Payment Reference (Razorpay payment id)
        '15-06-2024 14:30:00',   // Payment Date & Time
        '500',                   // Received Amount
        'Legacy Razorpay payment', // Payment Remarks
    ],
    // Row 3: GP Working = no, Zilla Panchayat (locked org type) + offline paid
    [
        'PRIYA S',
        'SURESH S',
        'female',
        '8765432109',
        'priya.s@example.com',
        '2345678',               // KGID No. (numeric digits only)
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
        'RCPT-001',              // Payment Reference
        '20-06-2024 11:15:00',   // Payment Date & Time
        '500',                   // Received Amount
        'Cash received at office', // Payment Remarks
    ],
    // Row 4: GP Working = yes, with GP name and online Razorpay paid
    [
        'MEENA T',
        'TEJA T',
        'female',
        '7654321098',
        'meena.t@example.com',
        '3456789',               // KGID No. (numeric digits only)
        '10-11-1988',            // Date of Birth (DD-MM-YYYY)
        'yes',
        '',                      // Organization Type
        '',                      // Organization Name
        '',                      // Organization Address
        'BAGALKOTE',             // Working District
        'BADAMI',                // Working Taluk
        'KAKANUR',               // Working GP
        '',                      // Membership District
        '',                      // Membership Taluk
        'razorpay',              // Payment Mode
        'pay_NZ1234567890',      // Payment Reference
        '01-07-2024 16:45:12',   // Payment Date & Time
        '500',                   // Received Amount
        'Paid via Razorpay gateway', // Payment Remarks
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
