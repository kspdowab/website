<?php
/**
 * KSPDOWA — Admin: Members Import Template Download
 * ============================================================
 * Serves a downloadable CSV template with all registration fields.
 * Gated by RBAC 'members.manage'.
 * No real member data is included.
 * ============================================================
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();
RBAC::requirePermission($currentUserId, 'members', 'manage');

$columns = [
    'Full Name',
    'Father / Husband Name',
    'Gender (male/female)',
    'Phone (10-digit, starts 6-9)',
    'Email',
    'KGID No.',
    'Date of Birth (YYYY-MM-DD)',
    'GP Working? (yes/no)',
    'Organization Type (when GP=no: secretariat/rdpr/commissionerate/zilla_panchayat/taluk_panchayat/mp_mla_mlc_pa/other)',
    'Organization Name (when GP=no)',
    'Organization Address (optional)',
    'Working District Name (when GP=yes OR org type is zilla_panchayat/taluk_panchayat)',
    'Working Taluk Name (when GP=yes OR org type is zilla_panchayat/taluk_panchayat)',
    'Working GP Name (optional, when GP Working=yes)',
    'Membership District Name (when GP=no + non-ZP/TP org)',
    'Membership Taluk Name (when GP=no + non-ZP/TP org)',
    'Payment Mode (offline or leave blank)',
    'Offline Reference / Receipt No. (when mode=offline)',
    'Offline Remarks (optional)',
];

$exampleRows = [
    // GP Working = yes example
    [
        'RAJESH KUMAR', 'RAMESH KUMAR', 'male', '9876543210', 'rajesh.kumar@example.com',
        'KGD12345', '1985-06-15', 'yes',
        '', '', '',
        'BAGALKOTE', 'BAGALKOTE',  '', '', '',
        '', '', ''
    ],
    // GP Working = no, ZP example
    [
        'PRIYA S', 'SURESH S', 'female', '8765432109', 'priya.s@example.com',
        'KGD67890', '1990-03-22', 'no',
        'zilla_panchayat', 'Zilla Panchayat Office Bagalkote', 'Main Road Bagalkote',
        'BAGALKOTE', 'BAGALKOTE', '', '', '',
        'offline', 'RCPT-001', 'Cash received at office'
    ],
    // GP Working = no, other org example
    [
        'MEENA T', 'TEJA T', 'female', '7654321098', 'meena.t@example.com',
        'KGD24680', '1988-11-10', 'no',
        'secretariat', 'Karnataka Secretariat', '',
        '', '', '',
        'BENGALURU URBAN', 'BENGALURU NORTH', '', '', ''
    ],
];

$filename = 'KSPDOWA_Members_Import_Template_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

// BOM for Excel UTF-8 compatibility
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Header row
fputcsv($out, $columns);

// Instructions row
fputcsv($out, ['--- EXAMPLES BELOW — DELETE BEFORE IMPORTING ---']);

// Example rows
foreach ($exampleRows as $row) {
    fputcsv($out, $row);
}

fclose($out);
exit;
