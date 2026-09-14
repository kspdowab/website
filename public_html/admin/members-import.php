<?php
/**
 * KSPDOWA — Admin: Bulk Import Members
 * ============================================================
 * Gated by RBAC 'members.manage'.
 * Spec: docs/11_MEMBERS_MODULE_SPECIFICATION.md §13
 *
 * Fields: EXACTLY same as member registration form.
 * Supports: CSV (.csv) and Excel (.xls / .xlsx via basic xml parse).
 * Duplicate rule: KGID + Financial Year ONLY.
 * Membership Number: auto-generated on activation (never from CSV).
 * Workflow: Upload → Validate → Preview → Confirm → Import
 * ============================================================
 */
declare(strict_types=1);

// Suppress PHP notices/warnings from appearing in the HTML output
ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/MembershipNumber.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();
RBAC::requirePermission($currentUserId, 'members', 'manage');

$successMsg = Session::getFlash('success');
$errorMsg   = Session::getFlash('error');

// Geographic scope
$associationUnitId = RBAC::getUserAssociationUnit($currentUserId);
$lockedDistrictId  = null;
$lockedTalukId     = null;
if ($associationUnitId) {
    $unit = Database::fetchOne("SELECT * FROM association_units WHERE id = ?", [$associationUnitId]);
    if ($unit) {
        if ($unit['unit_type'] === 'district') { $lockedDistrictId = (int)$unit['district_id']; }
        elseif ($unit['unit_type'] === 'taluk') { $lockedDistrictId = (int)$unit['district_id']; $lockedTalukId = (int)$unit['taluk_id']; }
    }
}

// Active year
$years      = Database::fetchAll("SELECT id, financial_year, status FROM membership_years ORDER BY start_date DESC");
$activeYear = Database::fetchOne("SELECT * FROM membership_years WHERE status='active' ORDER BY start_date DESC LIMIT 1");
$fyId       = $activeYear ? (int)$activeYear['id'] : 0;

// Selectable FY for import
$importFyId = Sanitize::positiveInt($_GET['fy_id'] ?? null) ?: $fyId;
$importYear = null;
foreach ($years as $y) { if ((int)$y['id'] === $importFyId) { $importYear = $y; break; } }

// Geography lookups (by name, case-insensitive)
$districts = Database::fetchAll("SELECT id, name FROM districts WHERE status='active' ORDER BY name");
$taluks    = Database::fetchAll("SELECT id, name, district_id FROM taluks WHERE status='active' ORDER BY name");
$gps       = Database::fetchAll("SELECT id, name, taluk_id FROM gram_panchayatis WHERE status='active' ORDER BY name");

// Build lookup maps: lowercased name → id
$dMap = [];
foreach ($districts as $d) { $dMap[strtolower(trim($d['name']))] = (int)$d['id']; }
$tMap = [];  // keyed as "district_id:lower_name"
$tById = []; // taluk_id → ['district_id', 'name']
foreach ($taluks as $t) {
    $tMap[(int)$t['district_id'] . ':' . strtolower(trim($t['name']))] = (int)$t['id'];
    $tById[(int)$t['id']] = $t;
}
$gpMap = []; // taluk_id:lower_name → gp_id
foreach ($gps as $g) {
    $gpMap[(int)$g['taluk_id'] . ':' . strtolower(trim($g['name']))] = (int)$g['id'];
}

// ─── CSV/Excel row reader helpers ─────────────────────────────────────────────

/**
 * Parse a CSV file; return array of rows (each row is an indexed array).
 * Skips blank rows and the header row.
 *
 * PHP 8.1+: fgetcsv() requires explicit $escape parameter — use '\\' (backslash).
 * Also strips the UTF-8 BOM (\xEF\xBB\xBF) that Excel writes at the start of
 * the file; without stripping it, the first field of the header row gets a
 * 3-byte prefix and downstream code may miscount columns.
 */
function parseCSVFile(string $filePath): array {
    $rows = [];
    if (($h = fopen($filePath, 'r')) !== false) {
        // Skip header row; strip BOM from first field if present
        $header = fgetcsv($h, 0, ',', '"', '\\');
        if ($header && isset($header[0])) {
            $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
        }
        while (($data = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
            if (count(array_filter($data, fn($v) => trim($v) !== '')) === 0) { continue; }
            $rows[] = $data;
        }
        fclose($h);
    }
    return $rows;
}

/**
 * Parse a simple xlsx file (Office Open XML) without library.
 * Extracts shared strings + sheet1 data. Returns array of rows.
 * Only handles string/number cell types. Skips header row.
 */
function parseXlsxFile(string $filePath): array {
    $rows = [];
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) { return $rows; }

    // Shared strings
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = simplexml_load_string($ssXml);
        if ($ss) {
            foreach ($ss->si as $si) {
                // collect all <t> text nodes
                $text = '';
                foreach ($si->r as $r) { $text .= (string)$r->t; }
                if ($text === '' && isset($si->t)) { $text = (string)$si->t; }
                $sharedStrings[] = $text;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) { return $rows; }

    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet) { return $rows; }

    $headerSkipped = false;
    foreach ($sheet->sheetData->row as $row) {
        if (!$headerSkipped) { $headerSkipped = true; continue; }
        $cells = [];
        foreach ($row->c as $c) {
            $t = (string)($c['t'] ?? '');
            $v = isset($c->v) ? (string)$c->v : '';
            if ($t === 's' && isset($sharedStrings[(int)$v])) {
                $cells[] = $sharedStrings[(int)$v];
            } else {
                $cells[] = $v;
            }
        }
        if (count(array_filter($cells, fn($v) => trim($v) !== '')) === 0) { continue; }
        $rows[] = $cells;
    }
    return $rows;
}

// ─── Expected CSV columns (0-indexed) ────────────────────────────────────────
// 0  Full Name
// 1  Father / Husband Name
// 2  Gender (male/female)
// 3  Phone (10-digit)
// 4  Email
// 5  KGID No.
// 6  Date of Birth (YYYY-MM-DD or DD-MM-YYYY)
// 7  GP Working? (yes/no)
// 8  Organization Type (when GP Working = no)
// 9  Organization Name (when GP Working = no)
// 10 Organization Address (optional, when GP Working = no)
// 11 Working District (name — required when GP Working = yes or locked org type)
// 12 Working Taluk (name — required when GP Working = yes or locked org type)
// 13 Working GP (name — optional when GP Working = yes)
// 14 Membership District (name — required when GP Working = no + non-locked org type)
// 15 Membership Taluk (name — required when GP Working = no + non-locked org type)
// 16 Payment Mode (online/offline/blank) — if offline, row can be marked paid
// 17 Offline Reference (when payment_mode = offline)
// 18 Offline Remarks (optional)

$LOCKED_ORG_TYPES = ['zilla_panchayat', 'taluk_panchayat'];

/**
 * Validate and parse a single import row.
 * Returns: ['valid'=>bool, 'data'=>array, 'errors'=>array]
 */
function validateImportRow(array $cells, array $dMap, array $tMap, array $tById, array $gpMap, array $LOCKED_ORG_TYPES, int $importFyId, ?int $lockedDistrictId, ?int $lockedTalukId): array {
    $pad  = function(int $i) use ($cells) { return trim((string)($cells[$i] ?? '')); };
    $errors = [];

    $fullName    = $pad(0);
    $fatherName  = $pad(1);
    $gender      = strtolower($pad(2));
    $phone       = preg_replace('/\s+/', '', $pad(3));
    $email       = strtolower(trim($pad(4)));
    $kgid        = $pad(5);
    $dobRaw      = $pad(6);
    $gpWorkingRaw= strtolower($pad(7));
    $orgTypeRaw  = strtolower($pad(8));
    $orgName     = $pad(9);
    $orgAddress  = $pad(10);
    $wDistrictRaw= strtolower($pad(11));
    $wTalukRaw   = strtolower($pad(12));
    $wGpRaw      = strtolower($pad(13));
    $mDistrictRaw= strtolower($pad(14));
    $mTalukRaw   = strtolower($pad(15));
    $payMode     = strtolower($pad(16));
    $offlineRef  = $pad(17);
    $offlineRem  = $pad(18);

    // Required fields
    if ($fullName === '') { $errors[] = 'Full Name required'; }
    if ($fatherName === '') { $errors[] = 'Father/Husband Name required'; }
    if (!in_array($gender, ['male','female'])) { $errors[] = 'Gender must be male or female'; }
    if (!preg_match('/^[6-9][0-9]{9}$/', $phone)) { $errors[] = 'Phone must be 10-digit starting 6-9'; }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Valid email required'; }
    if ($kgid === '') { $errors[] = 'KGID required'; }
    if ($gpWorkingRaw === '' || !in_array($gpWorkingRaw, ['yes','no'])) { $errors[] = 'GP Working must be yes or no'; }

    // DOB
    $dob = null;
    if ($dobRaw !== '') {
        // Accept YYYY-MM-DD or DD-MM-YYYY
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dobRaw)) {
            $dob = $dobRaw;
        } elseif (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $dobRaw, $m)) {
            $dob = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        if ($dob === null) { $errors[] = 'Date of Birth format must be YYYY-MM-DD'; }
    } else {
        $errors[] = 'Date of Birth required';
    }

    // Org type
    $orgTypeKey = str_replace([' ','-'], '_', $orgTypeRaw);
    $validOrgTypes = array_keys(Registration::ORG_TYPES);

    // Location logic
    $workingDistrictId   = null;
    $workingTalukId      = null;
    $workingGpId         = null;
    $membershipDistrictId= null;
    $membershipTalukId   = null;

    $gpWorking = ($gpWorkingRaw === 'yes') ? 'yes' : (($gpWorkingRaw === 'no') ? 'no' : null);
    $orgIsLocked = in_array($orgTypeKey, $LOCKED_ORG_TYPES);

    if ($gpWorking === 'yes' || ($gpWorking === 'no' && $orgIsLocked)) {
        // Working location required
        $wDid = $dMap[$wDistrictRaw] ?? null;
        if (!$wDid) { $errors[] = "Working District '$wDistrictRaw' not found"; }
        else {
            $workingDistrictId = $wDid;
            $wTid = $tMap[$wDid . ':' . $wTalukRaw] ?? null;
            if (!$wTid) { $errors[] = "Working Taluk '$wTalukRaw' not found in that district"; }
            else {
                $workingTalukId = $wTid;
                if ($gpWorking === 'yes' && $wGpRaw !== '') {
                    $wGpId = $gpMap[$wTid . ':' . $wGpRaw] ?? null;
                    if (!$wGpId) { $errors[] = "Working GP '$wGpRaw' not found in that taluk"; }
                    else { $workingGpId = $wGpId; }
                }
            }
        }
        // Membership = Working (auto-locked)
        $membershipDistrictId = $workingDistrictId;
        $membershipTalukId    = $workingTalukId;

        if ($gpWorking === 'no') {
            // Validate org fields
            if (!in_array($orgTypeKey, $validOrgTypes)) { $errors[] = "Organization Type '$orgTypeRaw' invalid"; }
            if ($orgName === '') { $errors[] = 'Organization Name required'; }
        }
    } else {
        // Manual membership location
        if ($gpWorking === 'no') {
            if (!in_array($orgTypeKey, $validOrgTypes)) { $errors[] = "Organization Type '$orgTypeRaw' invalid"; }
            if ($orgName === '') { $errors[] = 'Organization Name required'; }
        }
        $mDid = $dMap[$mDistrictRaw] ?? null;
        if (!$mDid) { $errors[] = "Membership District '$mDistrictRaw' not found"; }
        else {
            $membershipDistrictId = $mDid;
            $mTid = $tMap[$mDid . ':' . $mTalukRaw] ?? null;
            if (!$mTid) { $errors[] = "Membership Taluk '$mTalukRaw' not found in that district"; }
            else { $membershipTalukId = $mTid; }
        }
    }

    // RBAC scope check
    if ($lockedDistrictId && $membershipDistrictId && $membershipDistrictId !== $lockedDistrictId) {
        $errors[] = 'Member is outside your authorized district';
    }
    if ($lockedTalukId && $membershipTalukId && $membershipTalukId !== $lockedTalukId) {
        $errors[] = 'Member is outside your authorized taluk';
    }

    // KGID + FY duplicate check
    $dupeStatus = null;
    if ($kgid !== '' && empty($errors)) {
        $profileRow = Database::fetchOne("SELECT member_id FROM member_profiles WHERE kgid_no = ?", [$kgid]);
        if ($profileRow) {
            $mId  = $profileRow['member_id'];
            $paid = $importFyId ? Database::fetchOne("SELECT id FROM membership_payments WHERE member_id = ? AND membership_year_id = ? AND status='completed'", [$mId, $importFyId]) : false;
            if ($paid) { $dupeStatus = 'already_paid'; $errors[] = "KGID $kgid already PAID for this FY"; }
            else       { $dupeStatus = 'existing_unpaid'; /* existing member, no FY payment — can update */ }
        }
    }

    // Payment mode
    $payModeClean = in_array($payMode, ['offline','online']) ? $payMode : null;
    $importPaid   = ($payModeClean === 'offline');

    return [
        'valid'  => empty($errors),
        'errors' => $errors,
        'dupe_status' => $dupeStatus,
        'data'   => [
            'full_name'             => $fullName,
            'father_spouse_name'    => $fatherName,
            'gender'                => $gender,
            'phone'                 => $phone,
            'email'                 => $email,
            'kgid_no'               => $kgid,
            'dob'                   => $dob,
            'gp_working'            => $gpWorking,
            'organization_type'     => $orgIsLocked ? $orgTypeKey : ($gpWorking === 'no' ? $orgTypeKey : null),
            'organization_name'     => $orgName ?: null,
            'organization_address'  => $orgAddress ?: null,
            'working_district_id'   => $workingDistrictId,
            'working_taluk_id'      => $workingTalukId,
            'working_gp_id'         => $workingGpId,
            'membership_district_id'=> $membershipDistrictId,
            'membership_taluk_id'   => $membershipTalukId,
            'payment_mode'          => $payModeClean,
            'import_paid'           => $importPaid,
            'offline_reference'     => $offlineRef ?: null,
            'offline_remarks'       => $offlineRem ?: null,
        ],
    ];
}

// ─── POST handlers ────────────────────────────────────────────────────────────
$previewData = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            Session::flash('error', 'Please select a valid CSV or Excel file.');
            header('Location: /admin/members-import.php');
            exit;
        }
        $tmpName  = $_FILES['import_file']['tmp_name'];
        $origName = strtolower(basename($_FILES['import_file']['name']));

        // Parse file
        if (str_ends_with($origName, '.xlsx') || str_ends_with($origName, '.xls')) {
            if (!class_exists('ZipArchive')) {
                Session::flash('error', 'Excel import requires the PHP Zip extension. Please use CSV instead.');
                header('Location: /admin/members-import.php');
                exit;
            }
            $rawRows = parseXlsxFile($tmpName);
        } else {
            $rawRows = parseCSVFile($tmpName);
        }

        if (empty($rawRows)) {
            Session::flash('error', 'No data rows found in the uploaded file (make sure row 1 is the header).');
            header('Location: /admin/members-import.php');
            exit;
        }

        $importFyIdPost = Sanitize::positiveInt($_POST['import_fy_id'] ?? null) ?: $fyId;

        $valid   = [];
        $invalid = [];

        foreach ($rawRows as $cells) {
            $result = validateImportRow($cells, $dMap, $tMap, $tById, $gpMap, $LOCKED_ORG_TYPES, $importFyIdPost, $lockedDistrictId, $lockedTalukId);
            $result['data']['_import_fy_id'] = $importFyIdPost;
            $result['data']['_row_display']  = ($cells[0] ?? '') . ' | ' . ($cells[5] ?? '');

            if ($result['valid']) {
                $valid[] = $result['data'];
            } else {
                $result['data']['_errors'] = $result['errors'];
                $invalid[] = $result['data'];
            }
        }

        $previewData = ['valid' => $valid, 'invalid' => $invalid, 'fy_id' => $importFyIdPost];
        $_SESSION['members_import_preview'] = $previewData;
    }

    if ($action === 'commit') {
        $data = $_SESSION['members_import_preview'] ?? null;
        if (!$data || empty($data['valid'])) {
            Session::flash('error', 'No valid rows to import.');
            header('Location: /admin/members-import.php');
            exit;
        }

        $count    = 0;
        $paidCount= 0;
        $importFyIdCommit = (int)($data['fy_id'] ?? $fyId);

        foreach ($data['valid'] as $row) {
            // Check KGID again — someone may have registered between preview and commit
            $existingProfile = Database::fetchOne("SELECT member_id FROM member_profiles WHERE kgid_no = ?", [$row['kgid_no']]);
            $memberId = null;

            if ($existingProfile) {
                // Existing member — do NOT re-insert, just add FY payment if needed
                $memberId = (int)$existingProfile['member_id'];
            } else {
                // Insert new member
                Database::execute(
                    "INSERT INTO members (name, designation, gp_id, taluk_id, district_id, joining_date, membership_status) VALUES (?, 'PDO', ?, ?, ?, CURDATE(), 'active')",
                    [$row['full_name'], $row['working_gp_id'], $row['membership_taluk_id'], $row['membership_district_id']]
                );
                $memberId = (int)Database::lastInsertId();

                // Profile
                Database::execute(
                    "INSERT INTO member_profiles (member_id, kgid_no, date_of_birth, gender, father_spouse_name, personal_email, personal_mobile, organization_type, organization_name, organization_address) VALUES (?,?,?,?,?,?,?,?,?,?)",
                    [
                        $memberId,
                        $row['kgid_no'],
                        $row['dob'],
                        $row['gender'],
                        $row['father_spouse_name'],
                        $row['email'],
                        $row['phone'],
                        $row['organization_type'],
                        $row['organization_name'],
                        $row['organization_address'],
                    ]
                );

                // Working location on member row
                if ($row['working_district_id']) {
                    Database::execute(
                        "UPDATE members SET working_district_id=?, working_taluk_id=?, working_gp_id=? WHERE id=?",
                        [$row['working_district_id'], $row['working_taluk_id'], $row['working_gp_id'], $memberId]
                    );
                }

                // Assign placeholder membership number
                MembershipNumber::assignIfPlaceholder($memberId);
                $count++;
            }

            // Offline payment → mark paid
            if ($row['import_paid'] && $importFyIdCommit && $memberId) {
                $already = Database::fetchOne("SELECT id FROM membership_payments WHERE member_id=? AND membership_year_id=? AND status='completed'", [$memberId, $importFyIdCommit]);
                if (!$already) {
                    $fyRow = Database::fetchOne("SELECT fee_amount FROM membership_years WHERE id=?", [$importFyIdCommit]);
                    $feeAmt = $fyRow ? (float)$fyRow['fee_amount'] : 0;
                    Database::execute(
                        "INSERT INTO membership_payments (member_id, membership_year_id, amount, status, payment_mode, offline_reference, offline_remarks, paid_at, created_at) VALUES (?,?,?,'completed','offline',?,?,NOW(),NOW())",
                        [$memberId, $importFyIdCommit, $feeAmt, $row['offline_reference'], $row['offline_remarks']]
                    );
                    // On verified payment, generate proper membership number
                    MembershipNumber::assignIfPlaceholder($memberId);
                    $paidCount++;
                }
            }
        }

        unset($_SESSION['members_import_preview']);
        AuditLogger::log('CREATE', 'members', null, null, ['action' => 'bulk_import', 'new_members' => $count, 'paid_activated' => $paidCount]);
        Session::flash('success', "Bulk import complete. New members: $count. Paid/activated: $paidCount.");
        header('Location: /admin/members.php');
        exit;
    }

    if ($action === 'cancel') {
        unset($_SESSION['members_import_preview']);
        header('Location: /admin/members-import.php');
        exit;
    }
}

// Restore preview from session if returning to page
if ($previewData === null && isset($_SESSION['members_import_preview'])) {
    $previewData = $_SESSION['members_import_preview'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Import — Admin — <?= Sanitize::html(APP_SHORT_NAME) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f7fa; color: #1a1a2e; margin: 0; padding: 0 0 60px; }
        header { background: #1a3a6b; color: #fff; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        header h1 { font-size: 1.1rem; margin: 0; }
        header nav a { color: #cfe0ff; text-decoration: none; font-size: 0.85rem; margin-left: 14px; }
        header nav a.active { color: #fff; font-weight: 700; text-decoration: underline; }
        .sub-nav { background: #fff; border-bottom: 1px solid #cbd5e1; padding: 0 24px; display: flex; gap: 20px; }
        .sub-nav a { display: inline-block; padding: 12px 4px; color: #556; text-decoration: none; font-weight: 600; font-size: 0.9rem; border-bottom: 3px solid transparent; }
        .sub-nav a:hover { color: #1a3a6b; }
        .sub-nav a.active { color: #1a3a6b; border-bottom-color: #1a3a6b; }
        main { max-width: 960px; margin: 24px auto; padding: 0 16px; }
        .panel { background: #fff; border-radius: 8px; box-shadow: 0 1px 6px rgba(26,58,107,0.08); padding: 24px; margin-bottom: 24px; }
        .panel h2 { font-size: 1.15rem; color: #1a3a6b; margin: 0 0 16px; border-bottom: 2px solid #eef1f5; padding-bottom: 12px; }
        .msg { padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 16px; }
        .msg.success { background: #e7f6ec; color: #1e6b3a; border: 1px solid #b9e5c6; }
        .msg.error   { background: #fdecea; color: #a12622; border: 1px solid #f5c2be; }
        .btn { display: inline-block; background: #1a3a6b; color: #fff; border: none; border-radius: 6px; padding: 10px 18px; font-size: 0.9rem; font-weight: 600; cursor: pointer; text-decoration: none; }
        .btn:hover { background: #142c52; }
        select, input[type="text"], input[type="file"] { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.9rem; font-family: inherit; }
        label { display: block; font-size: 0.8rem; font-weight: 600; color: #33415c; margin: 10px 0 4px; }
        table { width: 100%; border-collapse: collapse; font-size: 0.8rem; margin-top: 16px; }
        th, td { text-align: left; padding: 7px 10px; border-bottom: 1px solid #eef1f5; }
        th { background: #f8fafc; color: #33415c; font-weight: 600; border-bottom: 2px solid #e2e8f0; }
        .err-cell { color: #a12622; font-size: 0.78rem; }
        .ok-cell  { color: #1e6b3a; font-size: 0.78rem; }
        code { background: #f0f3f7; padding: 1px 5px; border-radius: 3px; font-size: 0.85em; }
        .drop-zone { border: 2px dashed #cbd5e1; border-radius: 10px; padding: 36px 24px; text-align: center; background: #f8fafc; }
    </style>
</head>
<body>
<header>
    <h1><?= Sanitize::html(APP_SHORT_NAME) ?> — Members Management</h1>
    <nav>
        <a href="/admin/office-bearers.php">Office Bearers</a>
        <a href="/admin/members.php" class="active">Members</a>
        <a href="/admin/news.php">News</a>
        <a href="/admin/users.php">Users &amp; Roles</a>
        <a href="/admin/membership-setup.php">Membership Setup</a>
        <a href="/admin/donations.php">Donations</a>
        <a href="/admin/settings.php">Association Settings</a>
        <a href="/logout.php">Logout</a>
    </nav>
</header>
<div class="sub-nav">
    <a href="/admin/members.php">Members List</a>
    <a href="/admin/members-import.php" class="active">Bulk Import</a>
    <a href="/admin/members-reports.php">Abstract Reports</a>
</div>
<main>
    <?php if ($successMsg): ?><div class="msg success"><?= Sanitize::html($successMsg) ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="msg error"><?= Sanitize::html($errorMsg) ?></div><?php endif; ?>

    <?php if ($previewData): ?>
    <!-- ── Preview / Confirm step ──────────────────────────────────────────── -->
    <div class="panel">
        <h2>Preview &amp; Confirm Import</h2>
        <p>
            <strong><?= count($previewData['valid']) ?></strong> valid rows ready to import.
            <strong style="color:#a12622;"><?= count($previewData['invalid']) ?></strong> rows have errors and will be skipped.
        </p>

        <?php if (!empty($previewData['valid'])): ?>
        <h3 style="color:#1e6b3a;">✓ Valid Rows (<?= count($previewData['valid']) ?>)</h3>
        <div style="overflow-x:auto;">
        <table>
            <thead><tr><th>#</th><th>Full Name</th><th>KGID</th><th>Phone</th><th>Email</th><th>Membership District</th><th>Membership Taluk</th><th>Paid?</th></tr></thead>
            <tbody>
            <?php $i=1; foreach ($previewData['valid'] as $row): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= Sanitize::html($row['full_name']) ?></td>
                <td><?= Sanitize::html($row['kgid_no']) ?></td>
                <td><?= Sanitize::html($row['phone']) ?></td>
                <td><?= Sanitize::html($row['email']) ?></td>
                <td><?= Sanitize::html($row['membership_district_id'] ? (Database::fetchOne("SELECT name FROM districts WHERE id=?", [$row['membership_district_id']])['name'] ?? $row['membership_district_id']) : '—') ?></td>
                <td><?= Sanitize::html($row['membership_taluk_id'] ? (Database::fetchOne("SELECT name FROM taluks WHERE id=?", [$row['membership_taluk_id']])['name'] ?? $row['membership_taluk_id']) : '—') ?></td>
                <td><?= $row['import_paid'] ? '<span class="ok-cell">Offline Paid</span>' : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($previewData['invalid'])): ?>
        <h3 style="color:#a12622; margin-top:24px;">✗ Invalid Rows (<?= count($previewData['invalid']) ?>) — will be skipped</h3>
        <div style="overflow-x:auto;">
        <table>
            <thead><tr><th>#</th><th>Full Name</th><th>KGID</th><th>Errors</th></tr></thead>
            <tbody>
            <?php $i=1; foreach ($previewData['invalid'] as $row): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= Sanitize::html($row['full_name'] ?? '') ?></td>
                <td><?= Sanitize::html($row['kgid_no'] ?? '') ?></td>
                <td class="err-cell"><?= Sanitize::html(implode('; ', $row['_errors'] ?? [])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <form method="post" style="margin-top:24px;">
            <?= CSRF::htmlField() ?>
            <button type="submit" name="action" value="commit" class="btn" style="background:#1e6b3a;" <?= empty($previewData['valid']) ? 'disabled' : '' ?>>Confirm &amp; Import <?= count($previewData['valid']) ?> Rows</button>
            <button type="submit" name="action" value="cancel" class="btn" style="background:#64748b; margin-left:12px;">Cancel</button>
        </form>
    </div>

    <?php else: ?>
    <!-- ── Upload step ─────────────────────────────────────────────────────── -->
    <div class="panel">
        <h2>Bulk Import Members</h2>

        <p style="color:#556; line-height:1.7;">
            Upload a <strong>CSV</strong> or <strong>Excel (.xlsx)</strong> file with member data.<br>
            <strong>Row 1 must be the header row</strong> (column names — ignored during import).<br>
            Duplicate rule: <code>KGID + Financial Year</code> only. Name, phone and email are not duplicate keys.<br>
            Membership Number is auto-generated — do NOT include it in the file.<br>
            <a href="/admin/members-import-template.php" class="btn" style="background:#2C6B67; padding:6px 14px; font-size:0.85rem; display:inline-block; margin-top:8px;">⬇ Download Template (CSV)</a>
        </p>

        <form method="post" enctype="multipart/form-data" style="margin-top:20px;">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="upload">

            <div style="margin-bottom:16px;">
                <label>Financial Year for this import</label>
                <select name="import_fy_id" style="max-width:280px;">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y['id'] ?>" <?= $y['id'] == $importFyId ? 'selected' : '' ?>><?= Sanitize::html($y['financial_year']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="drop-zone">
                <p style="color:#556; margin:0 0 14px;">Select a CSV or Excel (.xlsx) file</p>
                <input type="file" name="import_file" accept=".csv,.xlsx,.xls" required style="border:none; background:transparent; width:auto;">
                <br><br>
                <button type="submit" class="btn">Analyse &amp; Preview File</button>
            </div>
        </form>

        <div style="margin-top:28px; padding:16px; background:#f0f5ff; border-radius:8px; font-size:0.85rem; color:#33415c;">
            <strong>Expected Columns (in this order):</strong><br><br>
            <table style="font-size:0.82rem; width:auto;">
                <thead><tr><th style="padding:4px 12px 4px 0;">#</th><th style="padding:4px 12px 4px 0;">Column</th><th style="padding:4px 0;">Required?</th></tr></thead>
                <tbody>
                <?php
                $cols = [
                    ['Full Name',              'Yes'],
                    ['Father / Husband Name',  'Yes'],
                    ['Gender',                 'Yes (male/female)'],
                    ['Phone',                  'Yes (10-digit)'],
                    ['Email',                  'Yes'],
                    ['KGID No.',               'Yes'],
                    ['Date of Birth',          'Yes (YYYY-MM-DD)'],
                    ['GP Working?',            'Yes (yes/no)'],
                    ['Organization Type',      'When GP=no'],
                    ['Organization Name',      'When GP=no'],
                    ['Organization Address',   'Optional'],
                    ['Working District',       'When GP=yes or ZP/TP org'],
                    ['Working Taluk',          'When GP=yes or ZP/TP org'],
                    ['Working GP',             'Optional (when GP=yes)'],
                    ['Membership District',    'When GP=no + other org'],
                    ['Membership Taluk',       'When GP=no + other org'],
                    ['Payment Mode',           'Optional (offline/blank)'],
                    ['Offline Reference',      'When mode=offline'],
                    ['Offline Remarks',        'Optional'],
                ];
                foreach ($cols as $i => [$name, $req]):
                ?>
                <tr>
                    <td style="padding:3px 12px 3px 0; color:#888;"><?= $i+1 ?></td>
                    <td style="padding:3px 12px 3px 0;"><strong><?= htmlspecialchars($name) ?></strong></td>
                    <td style="padding:3px 0; color:#556;"><?= htmlspecialchars($req) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</main>
</body>
</html>
