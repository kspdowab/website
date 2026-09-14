<?php
/**
 * KSPDOWA — Admin: Members Management
 * ============================================================
 * Gated by RBAC permission 'members.manage'.
 * Spec: docs/11_MEMBERS_MODULE_SPECIFICATION.md
 *
 * Add Member form is EXACTLY SAME as register.php:
 *   - Same fields, same conditional working-location logic
 *   - Same District→Taluk→GP cascading
 *   - Membership Number is auto-generated (never manual)
 * Members List: Financial Year + Status columns (separate)
 * Actions: single dropdown per row
 * Filters: District→Taluk dependent dropdown
 * ============================================================
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/MembershipNumber.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();
RBAC::requirePermission($currentUserId, 'members', 'manage');

$successMsg = Session::getFlash('success');
$errorMsg   = Session::getFlash('error');

// ─── Geographic scope locking ─────────────────────────────────────────────────
$associationUnitId = RBAC::getUserAssociationUnit($currentUserId);
$lockedDistrictId  = null;
$lockedTalukId     = null;

if ($associationUnitId) {
    $unit = Database::fetchOne("SELECT * FROM association_units WHERE id = ?", [$associationUnitId]);
    if ($unit) {
        if ($unit['unit_type'] === 'district') {
            $lockedDistrictId = (int)$unit['district_id'];
        } elseif ($unit['unit_type'] === 'taluk') {
            $lockedDistrictId = (int)$unit['district_id'];
            $lockedTalukId    = (int)$unit['taluk_id'];
        }
    }
}

// ─── Geography data for dropdowns / JS cascading ─────────────────────────────
$districts = Database::fetchAll("SELECT id, name FROM districts WHERE status = 'active' ORDER BY name");
$taluks    = Database::fetchAll("SELECT id, name, district_id FROM taluks WHERE status = 'active' ORDER BY district_id, name");
$gps       = Database::fetchAll("SELECT id, name, taluk_id FROM gram_panchayatis WHERE status = 'active' ORDER BY taluk_id, name");

$geoJson = json_encode([
    'districts' => array_map(fn($d) => ['id' => (int)$d['id'], 'name' => $d['name']], $districts),
    'taluks'    => array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name'], 'district_id' => (int)$t['district_id']], $taluks),
    'gps'       => array_map(fn($g) => ['id' => (int)$g['id'], 'name' => $g['name'], 'taluk_id' => (int)$g['taluk_id']], $gps),
], JSON_UNESCAPED_UNICODE);

$years           = Database::fetchAll("SELECT id, financial_year, status, fee_amount FROM membership_years ORDER BY start_date DESC");
$membershipTypes = Database::fetchAll("SELECT id, name FROM membership_types WHERE status = 'active' ORDER BY name");

// ─── POST handlers ────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();
    $action = Sanitize::string($_POST['action'] ?? '', 30);

    // ── Create / Update member ──────────────────────────────────────────────
    if ($action === 'create' || $action === 'update') {
        $id = ($action === 'update') ? Sanitize::positiveInt($_POST['id'] ?? null) : null;

        // Use Registration::validate() for create (same rules as register.php)
        if ($action === 'create') {
            $validated = Registration::validate($_POST);
            $errors    = $validated['errors'];
            $clean     = $validated['clean'];

            // RBAC scope check on membership location
            if ($lockedDistrictId && !empty($clean['membership_district_id']) && (int)$clean['membership_district_id'] !== $lockedDistrictId) {
                $errors['membership_district_id'] = 'You are not authorized to add members outside your district.';
            }
            if ($lockedTalukId && !empty($clean['membership_taluk_id']) && (int)$clean['membership_taluk_id'] !== $lockedTalukId) {
                $errors['membership_taluk_id'] = 'You are not authorized to add members outside your taluk.';
            }

            if (empty($errors)) {
                $name           = $clean['full_name'];
                $districtId     = $clean['membership_district_id'];
                $talukId        = $clean['membership_taluk_id'];
                $gpId           = $clean['membership_gp_id'] ?? null;
                $joiningDate    = date('Y-m-d');

                $tempMemberNo   = 'PENDING-' . bin2hex(random_bytes(8));
                Database::execute(
                    "INSERT INTO members (member_no, name, designation, gp_id, taluk_id, district_id, joining_date, membership_status) VALUES (?, ?, 'PDO', ?, ?, ?, ?, 'active')",
                    [$tempMemberNo, $name, $gpId, $talukId, $districtId, $joiningDate]
                );
                $memberId = (int)Database::lastInsertId();
                $regPlaceholder = 'REG-' . str_pad((string)$memberId, 6, '0', STR_PAD_LEFT);
                Database::execute("UPDATE members SET member_no = ? WHERE id = ?", [$regPlaceholder, $memberId]);
                Database::transaction(function () use ($memberId) {
                    MembershipNumber::assignIfPlaceholder($memberId);
                });

                // Profile fields
                Database::execute(
                    "INSERT INTO member_profiles (member_id, kgid_no, date_of_birth, gender, father_spouse_name, personal_email, personal_mobile, organization_type, organization_name, organization_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $memberId,
                        $clean['kgid_no'],
                        $clean['dob']                  ?: null,
                        $clean['gender']               ?: null,
                        $clean['father_spouse_name']   ?: null,
                        $clean['email']                ?: null,
                        $clean['phone']                ?: null,
                        $clean['organization_type']    ?? null,
                        $clean['organization_name']    ?? null,
                        $clean['organization_address'] ?? null,
                    ]
                );

                // Working location fields stored on the member row where applicable
                if (!empty($clean['working_district_id'])) {
                    Database::execute(
                        "UPDATE members SET working_district_id = ?, working_taluk_id = ?, working_gp_id = ? WHERE id = ?",
                        [$clean['working_district_id'], $clean['working_taluk_id'] ?? null, $clean['working_gp_id'] ?? null, $memberId]
                    );
                }

                // Assign placeholder member number (will be replaced on first verified payment)
                MembershipNumber::assignIfPlaceholder($memberId);

                AuditLogger::log('CREATE', 'members', $memberId, null, ['name' => $name, 'kgid' => $clean['kgid_no']]);
                Session::flash('success', 'Member added successfully.');
            } else {
                Session::flash('error', implode(' ', $errors));
            }
            header('Location: /admin/members.php');
            exit;
        }

        // Update path — simpler field set (not a full re-registration)
        if ($action === 'update' && $id) {
            $name           = trim(Sanitize::string($_POST['name'] ?? '', 200));
            $status         = Sanitize::inArray($_POST['membership_status'] ?? '', ['active','inactive','retired','terminated','resigned','deceased']);
            $districtId     = Sanitize::positiveInt($_POST['district_id']    ?? null) ?: null;
            $talukId        = Sanitize::positiveInt($_POST['taluk_id']        ?? null) ?: null;
            $gpId           = Sanitize::positiveInt($_POST['gp_id']           ?? null) ?: null;

            // RBAC
            if ($lockedDistrictId && $districtId !== $lockedDistrictId) {
                $districtId = $lockedDistrictId;
            }
            if ($lockedTalukId && $talukId !== $lockedTalukId) {
                $talukId = $lockedTalukId;
            }

            $errors = [];
            if ($name === '') { $errors[] = 'Name is required.'; }
            if ($status === false) { $errors[] = 'Status is required.'; }

            $kgidClean = trim(Sanitize::string($_POST['kgid_no'] ?? '', 100)) ?: null;
            if ($kgidClean !== null && !preg_match('/^\d+$/', $kgidClean)) {
                $errors[] = 'KGID No. must contain only numeric digits.';
            }

            if (empty($errors)) {
                $existing = Database::fetchOne('SELECT * FROM members WHERE id = ?', [$id]);
                if ($existing) {
                    Database::execute(
                        "UPDATE members SET name=?, designation='PDO', gp_id=?, taluk_id=?, district_id=?, membership_status=?, updated_at=NOW() WHERE id=?",
                        [$name, $gpId, $talukId, $districtId, $status, $id]
                    );

                    // Profile
                    $emailClean  = Sanitize::email($_POST['personal_email'] ?? '') ?: null;
                    $mobileClean = Sanitize::mobile($_POST['personal_mobile'] ?? '') ?: null;
                    $father      = trim(Sanitize::string($_POST['father_spouse_name'] ?? '', 200)) ?: null;
                    $dob         = Sanitize::date(trim($_POST['date_of_birth'] ?? '')) ?: null;
                    $gender      = Sanitize::inArray($_POST['gender'] ?? '', ['male','female','other']) ?: null;

                    $profileExists = Database::fetchOne('SELECT id FROM member_profiles WHERE member_id = ?', [$id]);
                    if ($profileExists) {
                        Database::execute(
                            "UPDATE member_profiles SET kgid_no=?, date_of_birth=?, gender=?, father_spouse_name=?, personal_email=?, personal_mobile=?, updated_at=NOW() WHERE member_id=?",
                            [$kgidClean, $dob, $gender, $father, $emailClean, $mobileClean, $id]
                        );
                    } else {
                        Database::execute(
                            "INSERT INTO member_profiles (member_id, kgid_no, date_of_birth, gender, father_spouse_name, personal_email, personal_mobile) VALUES (?,?,?,?,?,?,?)",
                            [$id, $kgidClean, $dob, $gender, $father, $emailClean, $mobileClean]
                        );
                    }
                    AuditLogger::log('UPDATE', 'members', $id, $existing, ['name' => $name, 'status' => $status]);
                    Session::flash('success', 'Member updated.');
                }
            } else {
                Session::flash('error', implode(' ', $errors));
            }
            header("Location: /admin/members.php?edit=$id");
            exit;
        }
    }

    // ── Offline payment ─────────────────────────────────────────────────────
    if ($action === 'offline_payment') {
        $memberId  = Sanitize::positiveInt($_POST['member_id'] ?? null);
        $fyIdPost  = Sanitize::positiveInt($_POST['fy_id']     ?? null);
        $amount    = (float)($_POST['amount'] ?? 0);
        $ref       = trim(Sanitize::string($_POST['offline_reference'] ?? '', 100));
        $remarks   = trim(Sanitize::string($_POST['offline_remarks']   ?? '', 1000));

        if ($memberId && $fyIdPost && $amount > 0) {
            // Scope check
            if ($lockedDistrictId) {
                $mRow = Database::fetchOne("SELECT district_id, taluk_id FROM members WHERE id = ?", [$memberId]);
                if (!$mRow || $mRow['district_id'] != $lockedDistrictId) {
                    Session::flash('error', 'Member is outside your authorized district.');
                    header('Location: /admin/members.php');
                    exit;
                }
                if ($lockedTalukId && $mRow['taluk_id'] != $lockedTalukId) {
                    Session::flash('error', 'Member is outside your authorized taluk.');
                    header('Location: /admin/members.php');
                    exit;
                }
            }

            $existingPayment = Database::fetchOne(
                "SELECT id FROM membership_payments WHERE member_id = ? AND membership_year_id = ? AND status = 'completed'",
                [$memberId, $fyIdPost]
            );
            if ($existingPayment) {
                Session::flash('error', 'Member has already paid for this financial year.');
            } else {
                Database::execute(
                    "INSERT INTO membership_payments (member_id, membership_year_id, amount, status, payment_mode, offline_reference, offline_remarks, paid_at, created_at) VALUES (?, ?, ?, 'completed', 'offline', ?, ?, NOW(), NOW())",
                    [$memberId, $fyIdPost, $amount, $ref !== '' ? $ref : null, $remarks !== '' ? $remarks : null]
                );
                MembershipNumber::assignIfPlaceholder($memberId);
                AuditLogger::log('CREATE', 'membership_payments', (int)Database::lastInsertId(), null, ['member_id' => $memberId, 'mode' => 'offline']);
                Session::flash('success', 'Offline payment recorded. Member is now ACTIVE for this financial year.');
            }
        } else {
            Session::flash('error', 'Invalid input for offline payment.');
        }
        header('Location: /admin/members.php?' . http_build_query(array_filter([
            'fy_id'          => $_POST['redirect_fy']       ?? null,
            'district_id'    => $_POST['redirect_district'] ?? null,
            'taluk_id'       => $_POST['redirect_taluk']    ?? null,
            'payment_status' => $_POST['redirect_ps']       ?? null,
            'q'              => $_POST['redirect_q']        ?? null,
        ])));
        exit;
    }
}

// ─── Filters ──────────────────────────────────────────────────────────────────
$search           = trim(Sanitize::string($_GET['q']              ?? '', 100));
$fyId             = Sanitize::positiveInt($_GET['fy_id']          ?? null);
$filterDistrictId = Sanitize::positiveInt($_GET['district_id']    ?? null);
$filterTalukId    = Sanitize::positiveInt($_GET['taluk_id']       ?? null);
$paymentStatus    = Sanitize::inArray($_GET['payment_status']     ?? '', ['all','paid','unpaid']) ?: 'all';

if ($lockedDistrictId) { $filterDistrictId = $lockedDistrictId; }
if ($lockedTalukId)    { $filterTalukId    = $lockedTalukId; }

if (!$fyId && !empty($years)) {
    $activeYears = array_filter($years, fn($y) => $y['status'] === 'active');
    $fyId = $activeYears ? (int)reset($activeYears)['id'] : (int)$years[0]['id'];
}
$selectedYear = null;
foreach ($years as $y) { if ((int)$y['id'] === $fyId) { $selectedYear = $y; break; } }

// ─── Members query ────────────────────────────────────────────────────────────
$params      = [];
$whereClause = ["1=1"];

if ($search !== '') {
    $whereClause[] = "(m.name LIKE ? OR m.member_no LIKE ? OR mp.kgid_no LIKE ? OR mp.personal_mobile LIKE ? OR mp.personal_email LIKE ? OR p.gateway_payment_id LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($filterDistrictId) { $whereClause[] = "m.district_id = ?"; $params[] = $filterDistrictId; }
if ($filterTalukId)    { $whereClause[] = "m.taluk_id = ?";    $params[] = $filterTalukId; }

$fyJoinId  = $fyId ?: 0;
$joinPayment = "LEFT JOIN membership_payments p ON p.member_id = m.id AND p.membership_year_id = {$fyJoinId} AND p.status = 'completed'";

if ($paymentStatus === 'paid')   { $whereClause[] = "p.id IS NOT NULL"; }
elseif ($paymentStatus === 'unpaid') { $whereClause[] = "p.id IS NULL"; }

$sql = "SELECT m.id, m.member_no, m.name, mp.gender, mp.father_spouse_name, mp.kgid_no, mp.personal_mobile, mp.personal_email,
               t.name AS taluk_name, d.name AS district_name,
               p.payment_mode, p.gateway_payment_id, p.id AS payment_id, p.offline_reference,
               pr.receipt_no, m.membership_status
        FROM members m
        LEFT JOIN member_profiles mp ON mp.member_id = m.id
        LEFT JOIN districts d ON d.id = m.district_id
        LEFT JOIN taluks t ON t.id = m.taluk_id
        {$joinPayment}
        LEFT JOIN payment_receipts pr ON pr.payment_id = p.id
        WHERE " . implode(' AND ', $whereClause) . "
        ORDER BY m.created_at DESC";

$members = Database::fetchAll($sql, $params);

// ─── Edit mode ────────────────────────────────────────────────────────────────
$editId      = Sanitize::positiveInt($_GET['edit'] ?? null);
$editRow     = null;
$editProfile = null;
if ($editId !== false && $editId) {
    $editRow = Database::fetchOne('SELECT * FROM members WHERE id = ?', [$editId]);
    if ($editRow) {
        // Scope guard
        if ($lockedDistrictId && $editRow['district_id'] != $lockedDistrictId) { $editRow = null; }
    }
    if ($editRow) {
        $editProfile = Database::fetchOne('SELECT * FROM member_profiles WHERE member_id = ?', [$editId]);
    }
}

$showAddForm = isset($_GET['add']) || $editRow;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Members List — Admin — <?= Sanitize::html(APP_SHORT_NAME) ?></title>
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

        main { max-width: 1400px; margin: 24px auto; padding: 0 16px; }
        .panel { background: #fff; border-radius: 8px; box-shadow: 0 1px 6px rgba(26,58,107,0.08); padding: 20px; margin-bottom: 24px; }
        .panel-h { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .panel-h h2 { font-size: 1.1rem; color: #1a3a6b; margin: 0; }

        .msg { padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 16px; }
        .msg.success { background: #e7f6ec; color: #1e6b3a; border: 1px solid #b9e5c6; }
        .msg.error   { background: #fdecea; color: #a12622; border: 1px solid #f5c2be; }

        label { display: block; font-size: 0.8rem; font-weight: 600; color: #33415c; margin: 10px 0 4px; }
        input[type="text"], input[type="email"], input[type="date"], select, textarea { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.9rem; font-family: inherit; }
        input[readonly], input[disabled] { background: #f0f3f7; color: #888; cursor: not-allowed; }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px 20px; }
        .span2 { grid-column: 1 / -1; }
        .radio-group { display: flex; gap: 20px; flex-wrap: wrap; margin-top: 4px; }
        .radio-group label { font-weight: 500; color: #33415c; display: flex; align-items: center; gap: 6px; }
        .locked-info { background: #eef4ff; border: 1px solid #93b4e9; border-radius: 6px; padding: 10px 14px; font-size: 0.85rem; color: #1a3a6b; }
        .section-title { font-size: 0.95rem; font-weight: 700; color: #1a3a6b; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin: 20px 0 12px; }
        .btn { background: #1a3a6b; color: #fff; border: none; border-radius: 6px; padding: 9px 18px; font-size: 0.9rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn:hover { background: #142c52; }
        .btn-sm { padding: 5px 12px; font-size: 0.8rem; }

        .filter-row { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
        .filter-row > div { flex: 1; min-width: 160px; }

        table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef1f5; vertical-align: middle; }
        th { color: #556; font-weight: 600; background: #f8fafc; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
        tr:hover { background: #f8fafc; }

        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.72rem; font-weight: 600; }
        .badge.paid, .badge.active { background: #e7f6ec; color: #1e6b3a; }
        .badge.unpaid, .badge.inactive { background: #f1f2f4; color: #666; }
        .badge.retired, .badge.resigned { background: #fdf6e8; color: #8a5a22; }
        .badge.terminated, .badge.deceased { background: #fdecea; color: #a12622; }
        .hint { font-size: 0.75rem; color: #888; }

        .table-wrap { overflow-x: auto; }

        /* Actions dropdown */
        .act-select { padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.8rem; cursor: pointer; }

        /* Collapsible Add/Edit form */
        #member-form-wrap { display: none; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 24px; }
        #member-form-wrap.visible { display: block; }
        [hidden] { display: none !important; }

        /* Modal */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; }
        .modal-overlay.open { display: flex; }
        .modal { background: #fff; padding: 24px; border-radius: 8px; max-width: 540px; width: 96%; max-height: 92vh; overflow-y: auto; }
        .modal h3 { margin-top: 0; color: #1a3a6b; }
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
    <a href="/admin/members.php" class="active">Members List</a>
    <a href="/admin/members-import.php">Bulk Import</a>
    <a href="/admin/members-reports.php">Abstract Reports</a>
</div>
<main>
    <?php if ($successMsg): ?><div class="msg success"><?= Sanitize::html($successMsg) ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="msg error"><?= Sanitize::html($errorMsg) ?></div><?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════════
         ADD / EDIT MEMBER FORM — mirrors register.php exactly
    ════════════════════════════════════════════════════════════════════════ -->
    <div id="member-form-wrap"<?= $showAddForm ? ' class="visible"' : '' ?>>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h2 style="margin:0; color:#1a3a6b;"><?= $editRow ? 'Edit Member' : 'Add Member' ?></h2>
            <button type="button" class="btn btn-sm" style="background:#64748b;" onclick="hideMemberForm()">✕ Cancel</button>
        </div>

        <?php if ($editRow): ?>
        <!-- ── EDIT MODE: simplified field set ───────────────────────────── -->
        <form method="post" action="/admin/members.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">

            <div class="section-title">Personal Details</div>
            <div class="form-grid">
                <div>
                    <label>Membership No. <span style="font-weight:400;color:#888;">(auto-assigned)</span></label>
                    <input type="text" value="<?= Sanitize::attr($editRow['member_no'] ?? '') ?>" readonly>
                </div>
                <div>
                    <label>Full Name *</label>
                    <input type="text" name="name" required maxlength="200" value="<?= Sanitize::attr($editRow['name'] ?? '') ?>">
                </div>
                <div>
                    <label>Father / Husband Name</label>
                    <input type="text" name="father_spouse_name" maxlength="200" value="<?= Sanitize::attr($editProfile['father_spouse_name'] ?? '') ?>">
                </div>
                <div>
                    <label>Gender</label>
                    <select name="gender">
                        <option value="">— Select —</option>
                        <?php foreach (['male'=>'Male','female'=>'Female'] as $v=>$l): ?>
                            <option value="<?= $v ?>" <?= ($editProfile['gender'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>KGID No.</label>
                    <input type="text" name="kgid_no" maxlength="100" inputmode="numeric" pattern="[0-9]*" title="Numeric digits only" value="<?= Sanitize::attr($editProfile['kgid_no'] ?? '') ?>">
                </div>
                <div>
                    <label>Date of Birth</label>
                    <input type="date" name="date_of_birth" value="<?= Sanitize::attr($editProfile['date_of_birth'] ?? '') ?>">
                </div>
                <div>
                    <label>Phone</label>
                    <input type="text" name="personal_mobile" maxlength="20" value="<?= Sanitize::attr($editProfile['personal_mobile'] ?? '') ?>">
                </div>
                <div>
                    <label>Email</label>
                    <input type="email" name="personal_email" maxlength="190" value="<?= Sanitize::attr($editProfile['personal_email'] ?? '') ?>">
                </div>
                <div>
                    <label>Designation</label>
                    <input type="text" value="PDO" readonly>
                </div>
            </div>

            <div class="section-title">Membership Location</div>
            <div class="form-grid">
                <div>
                    <label>District</label>
                    <?php if ($lockedDistrictId): ?>
                        <input type="text" value="<?= Sanitize::attr(array_column($districts,'name','id')[$lockedDistrictId] ?? '') ?>" readonly>
                        <input type="hidden" name="district_id" value="<?= $lockedDistrictId ?>">
                    <?php else: ?>
                    <select name="district_id" id="editDistrictSel">
                        <option value="">— Select —</option>
                        <?php foreach ($districts as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= $editRow['district_id'] == $d['id'] ? 'selected' : '' ?>><?= Sanitize::html($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
                <div>
                    <label>Taluk</label>
                    <?php if ($lockedTalukId): ?>
                        <input type="text" value="<?= Sanitize::attr(array_column($taluks,'name','id')[$lockedTalukId] ?? '') ?>" readonly>
                        <input type="hidden" name="taluk_id" value="<?= $lockedTalukId ?>">
                    <?php else: ?>
                    <select name="taluk_id" id="editTalukSel">
                        <option value="">— Select District first —</option>
                        <?php foreach ($taluks as $t): ?>
                            <?php if ($editRow['district_id'] && $t['district_id'] == $editRow['district_id']): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= $editRow['taluk_id'] == $t['id'] ? 'selected' : '' ?>><?= Sanitize::html($t['name']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
                <div>
                    <label>Lifecycle Status</label>
                    <select name="membership_status">
                        <?php foreach (['active','inactive','retired','terminated','resigned','deceased'] as $s): ?>
                            <option value="<?= $s ?>" <?= ($editRow['membership_status'] ?? 'active') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="margin-top:20px;">
                <button type="submit" class="btn">Save Changes</button>
            </div>
        </form>

        <?php else: ?>
        <!-- ── ADD MODE: full registration form, same as register.php ────── -->
        <form method="post" action="/admin/members.php" id="addMemberForm">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="create">

            <div class="section-title">Personal Details</div>
            <div class="form-grid">
                <div>
                    <label>Full Name *</label>
                    <input type="text" name="full_name" required maxlength="200">
                </div>
                <div>
                    <label>Father / Husband Name *</label>
                    <input type="text" name="father_spouse_name" required maxlength="200">
                </div>
                <div>
                    <label>Gender *</label>
                    <div class="radio-group">
                        <label><input type="radio" name="gender" value="male" required> Male</label>
                        <label><input type="radio" name="gender" value="female"> Female</label>
                    </div>
                </div>
                <div>
                    <label>Phone *</label>
                    <input type="text" name="phone" required maxlength="10" inputmode="numeric" pattern="[6-9][0-9]{9}" placeholder="10-digit mobile">
                </div>
                <div>
                    <label>Email *</label>
                    <input type="email" name="email" required maxlength="190">
                </div>
                <div>
                    <label>KGID No. *</label>
                    <input type="text" name="kgid_no" required maxlength="50" inputmode="numeric" pattern="[0-9]+" title="Numeric digits only">
                </div>
                <div>
                    <label>Date of Birth *</label>
                    <input type="date" name="dob" required>
                </div>
                <div>
                    <label>Designation</label>
                    <input type="text" value="PDO" readonly>
                </div>
            </div>

            <div class="section-title">Current Working Details</div>
            <div>
                <label>Currently working in a Gram Panchayati? *</label>
                <div class="radio-group">
                    <label><input type="radio" name="gp_working" value="yes" id="addGpYes" required> Yes</label>
                    <label><input type="radio" name="gp_working" value="no" id="addGpNo"> No</label>
                </div>
            </div>

            <!-- Organisation block (shown when gp_working=no) -->
            <div id="addOrgBlock" class="form-grid" style="margin-top:14px;" hidden>
                <div>
                    <label>Office/Organization Type *</label>
                    <select id="addOrgType" name="organization_type">
                        <option value="">— Select —</option>
                        <?php foreach (Registration::ORG_TYPES as $val => $label): ?>
                            <option value="<?= Sanitize::attr($val) ?>"><?= Sanitize::html($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Office/Organization Name *</label>
                    <input type="text" id="addOrgName" name="organization_name" maxlength="200">
                </div>
                <div class="span2">
                    <label>Office Address (optional)</label>
                    <input type="text" name="organization_address" maxlength="500">
                </div>
            </div>

            <!-- Working location block -->
            <div id="addWorkingBlock" class="form-grid" style="margin-top:14px;" hidden>
                <div>
                    <label>Working District *</label>
                    <?php if ($lockedDistrictId): ?>
                        <input type="text" value="<?= Sanitize::attr(array_column($districts,'name','id')[$lockedDistrictId] ?? '') ?>" readonly>
                        <input type="hidden" name="working_district_id" value="<?= $lockedDistrictId ?>">
                    <?php else: ?>
                    <select id="addWorkingDistrict" name="working_district_id">
                        <option value="">— Select —</option>
                        <?php foreach ($districts as $d): ?>
                            <option value="<?= (int)$d['id'] ?>"><?= Sanitize::html($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
                <div>
                    <label>Working Taluk *</label>
                    <select id="addWorkingTaluk" name="working_taluk_id">
                        <option value="">— Select District first —</option>
                    </select>
                </div>
                <div id="addWorkingGpField">
                    <label>Gram Panchayati (optional)</label>
                    <select id="addWorkingGp" name="working_gp_id">
                        <option value="">— Select Taluk first —</option>
                    </select>
                </div>
            </div>

            <!-- Membership manual block (shown for non-locked org types when gp_working=no) -->
            <div id="addMembershipManualBlock" class="form-grid" style="margin-top:14px;" hidden>
                <div>
                    <label>Membership District *</label>
                    <?php if ($lockedDistrictId): ?>
                        <input type="text" value="<?= Sanitize::attr(array_column($districts,'name','id')[$lockedDistrictId] ?? '') ?>" readonly>
                        <input type="hidden" name="membership_district_id" value="<?= $lockedDistrictId ?>">
                    <?php else: ?>
                    <select id="addMembershipDistrict" name="membership_district_id">
                        <option value="">— Select —</option>
                        <?php foreach ($districts as $d): ?>
                            <option value="<?= (int)$d['id'] ?>"><?= Sanitize::html($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
                <div>
                    <label>Membership Taluk *</label>
                    <select id="addMembershipTaluk" name="membership_taluk_id">
                        <option value="">— Select District first —</option>
                    </select>
                </div>
            </div>

            <!-- Locked info (shown when auto-assigned) -->
            <div id="addMembershipLockedBlock" style="margin-top:14px;" hidden>
                <div class="locked-info">Membership District/Taluk will be automatically assigned from your Working location and cannot be changed manually.</div>
            </div>

            <div style="margin-top:20px;">
                <button type="submit" class="btn">Add Member</button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         FILTERS PANEL
    ════════════════════════════════════════════════════════════════════════ -->
    <div class="panel">
        <div class="panel-h">
            <h2>Members List (<?= count($members) ?>)</h2>
            <button type="button" class="btn" onclick="showMemberForm()">+ Add Member</button>
        </div>
        <form method="get" action="/admin/members.php" id="filterForm">
            <div class="filter-row">
                <div>
                    <label>Financial Year</label>
                    <select name="fy_id" onchange="this.form.submit()">
                        <?php foreach ($years as $y): ?>
                            <option value="<?= $y['id'] ?>" <?= $y['id'] == $fyId ? 'selected' : '' ?>><?= Sanitize::html($y['financial_year']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>District</label>
                    <?php if ($lockedDistrictId): ?>
                        <input type="text" value="<?= Sanitize::attr(array_column($districts,'name','id')[$lockedDistrictId] ?? 'Your District') ?>" readonly>
                        <input type="hidden" name="district_id" value="<?= $lockedDistrictId ?>">
                    <?php else: ?>
                    <select name="district_id" id="filterDistrictSel">
                        <option value="">All Districts</option>
                        <?php foreach ($districts as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= $d['id'] == $filterDistrictId ? 'selected' : '' ?>><?= Sanitize::html($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
                <div>
                    <label>Taluk</label>
                    <?php if ($lockedTalukId): ?>
                        <input type="text" value="<?= Sanitize::attr(array_column($taluks,'name','id')[$lockedTalukId] ?? 'Your Taluk') ?>" readonly>
                        <input type="hidden" name="taluk_id" value="<?= $lockedTalukId ?>">
                    <?php else: ?>
                    <select name="taluk_id" id="filterTalukSel">
                        <option value="">All Taluks</option>
                        <?php foreach ($taluks as $t): ?>
                            <?php if (!$filterDistrictId || $t['district_id'] == $filterDistrictId): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= $t['id'] == $filterTalukId ? 'selected' : '' ?>><?= Sanitize::html($t['name']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
                <div>
                    <label>Payment Status</label>
                    <select name="payment_status">
                        <option value="all"    <?= $paymentStatus === 'all'    ? 'selected' : '' ?>>All</option>
                        <option value="paid"   <?= $paymentStatus === 'paid'   ? 'selected' : '' ?>>Paid</option>
                        <option value="unpaid" <?= $paymentStatus === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                    </select>
                </div>
                <div style="flex:2;">
                    <label>Search (Name, Member No, KGID, Phone, Email, Payment ID)</label>
                    <input type="text" name="q" value="<?= Sanitize::attr($search) ?>">
                </div>
                <div style="flex:0;"><button type="submit" class="btn">Filter</button></div>
            </div>
            <div style="margin-top:12px;">
                <a href="/admin/members-export.php?format=pdf&<?= http_build_query(['fy_id'=>$fyId,'district_id'=>$filterDistrictId,'taluk_id'=>$filterTalukId,'payment_status'=>$paymentStatus,'q'=>$search]) ?>" target="_blank" class="btn btn-sm" style="background:#2C6B67;">Export PDF</a>
                <a href="/admin/members-export.php?format=excel&<?= http_build_query(['fy_id'=>$fyId,'district_id'=>$filterDistrictId,'taluk_id'=>$filterTalukId,'payment_status'=>$paymentStatus,'q'=>$search]) ?>" class="btn btn-sm" style="background:#245A57;">Export Excel</a>
            </div>
        </form>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         MEMBERS TABLE
    ════════════════════════════════════════════════════════════════════════ -->
    <div class="panel">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Sl.No</th>
                        <th>Member No</th>
                        <th>Name</th>
                        <th>Gender</th>
                        <th>KGID</th>
                        <th>Contact</th>
                        <th>Taluk / District</th>
                        <th>Financial Year</th>
                        <th>Status</th>
                        <th>Payment Mode / ID</th>
                        <th>Receipt</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($members as $m): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td style="white-space:nowrap;"><?= Sanitize::html($m['member_no'] ?? '—') ?></td>
                        <td>
                            <strong><?= Sanitize::html($m['name']) ?></strong>
                            <?php if ($m['father_spouse_name']): ?>
                                <br><span class="hint"><?= Sanitize::html($m['father_spouse_name']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= Sanitize::html(ucfirst($m['gender'] ?? '—')) ?></td>
                        <td><?= Sanitize::html($m['kgid_no'] ?? '—') ?></td>
                        <td>
                            <?= Sanitize::html($m['personal_mobile'] ?? '—') ?>
                            <?php if ($m['personal_email']): ?>
                                <br><span class="hint"><?= Sanitize::html($m['personal_email']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= Sanitize::html($m['taluk_name'] ?? '—') ?>
                            <br><span class="hint"><?= Sanitize::html($m['district_name'] ?? '—') ?></span>
                        </td>
                        <td style="white-space:nowrap;"><?= Sanitize::html($selectedYear['financial_year'] ?? '—') ?></td>
                        <td>
                            <?php if ($m['payment_id']): ?>
                                <span class="badge paid">Paid</span>
                            <?php else: ?>
                                <span class="badge unpaid">Unpaid</span>
                            <?php endif; ?>
                            <br><span class="badge <?= Sanitize::html($m['membership_status']) ?>" style="margin-top:4px;"><?= Sanitize::html(ucfirst($m['membership_status'])) ?></span>
                        </td>
                        <td>
                            <?= Sanitize::html(($m['payment_mode'] === 'online' || $m['payment_mode'] === 'razorpay') ? 'Razorpay' : ($m['payment_mode'] ? ucfirst($m['payment_mode']) : '—')) ?>
                            <?php if ($m['gateway_payment_id'] || $m['offline_reference']): ?>
                                <br><span class="hint"><?= Sanitize::html($m['gateway_payment_id'] ?? $m['offline_reference'] ?? '') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($m['receipt_no']): ?>
                                <a href="/admin/receipt.php?id=<?= $m['payment_id'] ?>" target="_blank" class="btn btn-sm" style="background:#2C6B67;">View</a>
                            <?php else: ?>
                                <span class="hint">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <!-- Single Actions dropdown -->
                            <select class="act-select" onchange="handleAction(this, <?= (int)$m['id'] ?>, '<?= Sanitize::attr($m['name']) ?>', <?= $selectedYear ? (int)$selectedYear['id'] : 0 ?>, <?= $selectedYear ? (float)$selectedYear['fee_amount'] : 0 ?>)">
                                <option value="">Actions</option>
                                <option value="view">View Details</option>
                                <option value="edit">Edit</option>
                                <?php if (!$m['payment_id'] && $selectedYear && in_array($m['membership_status'], ['active','inactive'])): ?>
                                    <option value="offline_pay">+ Offline Payment</option>
                                <?php endif; ?>
                                <?php if ($m['receipt_no']): ?>
                                    <option value="receipt">View Receipt</option>
                                <?php endif; ?>
                                <option value="lifecycle">Lifecycle / Transfer</option>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($members)): ?>
                    <tr><td colspan="12" style="text-align:center; color:#888; padding:24px;">No members found for the selected filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- Offline Payment Modal -->
<div class="modal-overlay" id="offline-modal">
    <div class="modal">
        <h3>Record Offline Payment</h3>
        <p class="hint">Verify the manual/offline payment before recording it here. This marks the selected Financial Year as Paid and activates the member's portal access.</p>
        <form method="post" action="/admin/members.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="offline_payment">
            <input type="hidden" name="member_id" id="om_member_id">
            <input type="hidden" name="fy_id" id="om_fy_id">
            <!-- preserve current filters for redirect -->
            <input type="hidden" name="redirect_fy"       value="<?= (int)$fyId ?>">
            <input type="hidden" name="redirect_district" value="<?= (int)$filterDistrictId ?>">
            <input type="hidden" name="redirect_taluk"    value="<?= (int)$filterTalukId ?>">
            <input type="hidden" name="redirect_ps"       value="<?= Sanitize::attr($paymentStatus) ?>">
            <input type="hidden" name="redirect_q"        value="<?= Sanitize::attr($search) ?>">

            <label>Member</label>
            <input type="text" id="om_member_name" readonly style="background:#f1f2f4; margin-bottom:12px;">

            <label>Amount (₹)</label>
            <input type="number" name="amount" id="om_amount" required step="0.01" min="1" style="margin-bottom:12px;">

            <label>Offline Reference / Receipt Number</label>
            <input type="text" name="offline_reference" style="margin-bottom:12px;">

            <label>Remarks / Notes</label>
            <textarea name="offline_remarks" rows="2" style="margin-bottom:16px;"></textarea>

            <button type="submit" class="btn">Record Payment</button>
            <button type="button" class="btn" style="background:#64748b; margin-left:10px;" onclick="closeModal()">Cancel</button>
        </form>
    </div>
</div>

<script>
var geo = <?= $geoJson ?>;

function optionsHtml(list, selectedId) {
    var h = '<option value="">— Select —</option>';
    list.forEach(function(item) {
        var sel = (String(item.id) === String(selectedId)) ? ' selected' : '';
        h += '<option value="' + item.id + '"' + sel + '>' + item.name.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</option>';
    });
    return h;
}
function taluksForDistrict(districtId) {
    return geo.taluks.filter(function(t) { return String(t.district_id) === String(districtId); });
}
function gpsForTaluk(talukId) {
    return geo.gps.filter(function(g) { return String(g.taluk_id) === String(talukId); });
}

// ── Filter form: District→Taluk dependency ─────────────────────────────────
var filterDistrictSel = document.getElementById('filterDistrictSel');
var filterTalukSel    = document.getElementById('filterTalukSel');
if (filterDistrictSel && filterTalukSel) {
    filterDistrictSel.addEventListener('change', function() {
        var list = taluksForDistrict(filterDistrictSel.value);
        filterTalukSel.innerHTML = '<option value="">All Taluks</option>' +
            list.map(function(t) { return '<option value="' + t.id + '">' + t.name.replace(/&/g,'&amp;') + '</option>'; }).join('');
        // auto-submit filter
        document.getElementById('filterForm').submit();
    });
}

// ── Edit form: District→Taluk dependency ───────────────────────────────────
var editDistrictSel = document.getElementById('editDistrictSel');
var editTalukSel    = document.getElementById('editTalukSel');
if (editDistrictSel && editTalukSel) {
    editDistrictSel.addEventListener('change', function() {
        var list = taluksForDistrict(editDistrictSel.value);
        editTalukSel.innerHTML = optionsHtml(list, '');
    });
}

// ── Add Member form conditional logic (mirrors register.php) ───────────────
var addForm = document.getElementById('addMemberForm');
if (addForm) {
    var addGpYes     = document.getElementById('addGpYes');
    var addGpNo      = document.getElementById('addGpNo');
    var addOrgBlock  = document.getElementById('addOrgBlock');
    var addOrgType   = document.getElementById('addOrgType');
    var addOrgName   = document.getElementById('addOrgName');
    var addWorkingBlock  = document.getElementById('addWorkingBlock');
    var addWorkingGpField= document.getElementById('addWorkingGpField');
    var addWorkingDistrict = document.getElementById('addWorkingDistrict');
    var addWorkingTaluk    = document.getElementById('addWorkingTaluk');
    var addWorkingGp       = document.getElementById('addWorkingGp');
    var addMembershipManualBlock = document.getElementById('addMembershipManualBlock');
    var addMembershipLockedBlock = document.getElementById('addMembershipLockedBlock');
    var addMembershipDistrict    = document.getElementById('addMembershipDistrict');
    var addMembershipTaluk       = document.getElementById('addMembershipTaluk');

    var lockedOrgTypes = ['zilla_panchayat', 'taluk_panchayat'];

    function refreshAddForm() {
        var gpWorking  = addGpYes && addGpYes.checked ? 'yes' : (addGpNo && addGpNo.checked ? 'no' : '');
        var orgType    = addOrgType ? addOrgType.value : '';
        var orgLocked  = lockedOrgTypes.indexOf(orgType) !== -1;

        addOrgBlock.hidden  = (gpWorking !== 'no');
        if (gpWorking === 'yes') {
            addWorkingBlock.hidden         = false;
            addWorkingGpField.hidden       = false;
            addMembershipManualBlock.hidden= true;
            addMembershipLockedBlock.hidden= false;
        } else if (gpWorking === 'no' && orgLocked) {
            addWorkingBlock.hidden         = false;
            addWorkingGpField.hidden       = true;
            addMembershipManualBlock.hidden= true;
            addMembershipLockedBlock.hidden= false;
        } else if (gpWorking === 'no' && !orgLocked && orgType !== '') {
            addWorkingBlock.hidden         = true;
            addMembershipManualBlock.hidden= false;
            addMembershipLockedBlock.hidden= true;
        } else {
            addWorkingBlock.hidden         = true;
            addMembershipManualBlock.hidden= true;
            addMembershipLockedBlock.hidden= true;
        }

        // required bookkeeping
        if (addWorkingDistrict) addWorkingDistrict.required = !addWorkingBlock.hidden;
        if (addWorkingTaluk)    addWorkingTaluk.required    = !addWorkingBlock.hidden;
        if (addOrgType)         addOrgType.required         = !addOrgBlock.hidden;
        if (addOrgName)         addOrgName.required         = !addOrgBlock.hidden;
        if (addMembershipDistrict) addMembershipDistrict.required = !addMembershipManualBlock.hidden;
        if (addMembershipTaluk)    addMembershipTaluk.required    = !addMembershipManualBlock.hidden;
    }

    if (addGpYes)  addGpYes.addEventListener('change', refreshAddForm);
    if (addGpNo)   addGpNo.addEventListener('change', refreshAddForm);
    if (addOrgType) addOrgType.addEventListener('change', refreshAddForm);
    refreshAddForm();

    // Working location cascades
    if (addWorkingDistrict) {
        addWorkingDistrict.addEventListener('change', function() {
            var list = taluksForDistrict(addWorkingDistrict.value);
            addWorkingTaluk.innerHTML = optionsHtml(list, '');
            if (addWorkingGp) { addWorkingGp.innerHTML = '<option value="">— Select Taluk first —</option>'; }
        });
    }
    if (addWorkingTaluk) {
        addWorkingTaluk.addEventListener('change', function() {
            var list = gpsForTaluk(addWorkingTaluk.value);
            if (addWorkingGp) {
                addWorkingGp.innerHTML = '<option value="">— Select (optional) —</option>' +
                    list.map(function(g) { return '<option value="' + g.id + '">' + g.name.replace(/&/g,'&amp;') + '</option>'; }).join('');
            }
        });
    }
    if (addMembershipDistrict) {
        addMembershipDistrict.addEventListener('change', function() {
            var list = taluksForDistrict(addMembershipDistrict.value);
            if (addMembershipTaluk) { addMembershipTaluk.innerHTML = optionsHtml(list, ''); }
        });
    }
}

// ── Add Member form show/hide ──────────────────────────────────────────────
function showMemberForm() {
    document.getElementById('member-form-wrap').classList.add('visible');
    document.getElementById('member-form-wrap').scrollIntoView({behavior:'smooth'});
}
function hideMemberForm() {
    document.getElementById('member-form-wrap').classList.remove('visible');
}

// ── Actions dropdown handler ───────────────────────────────────────────────
function handleAction(sel, memberId, memberName, fyId, feeAmount) {
    var val = sel.value;
    if (!val) return;
    sel.value = ''; // reset

    if (val === 'view') {
        window.location.href = '/admin/member-view.php?id=' + memberId;
    } else if (val === 'edit') {
        window.location.href = '/admin/members.php?edit=' + memberId;
    } else if (val === 'offline_pay') {
        openOfflineModal(memberId, memberName, fyId, feeAmount);
    } else if (val === 'receipt') {
        // Find the receipt link from within the row
        window.open('/admin/receipt.php?id=' + memberId, '_blank');
    } else if (val === 'lifecycle') {
        window.location.href = '/admin/member-view.php?id=' + memberId + '#lifecycle';
    }
}

// ── Offline Payment Modal ──────────────────────────────────────────────────
function openOfflineModal(memberId, name, fyId, feeAmount) {
    document.getElementById('om_member_id').value  = memberId;
    document.getElementById('om_member_name').value= name;
    document.getElementById('om_fy_id').value      = fyId;
    document.getElementById('om_amount').value     = feeAmount;
    document.getElementById('offline-modal').classList.add('open');
}
function closeModal() {
    document.getElementById('offline-modal').classList.remove('open');
}
document.getElementById('offline-modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
</body>
</html>
