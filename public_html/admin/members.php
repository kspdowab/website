<?php
/**
 * KSPDOWA — Admin: Members Management (Phase 2 "Member profile" unit)
 * ============================================================
 * Gated by RBAC permission 'members.manage' (seeded in Phase 0 —
 * see seeds/001_roles_permissions.sql).
 *
 * Scope, deliberately: this page manages the association's MEMBER
 * ROSTER (the `members` + `member_profiles` tables from
 * migrations/002_core_users.sql) as data an authorized officer
 * enters directly — it does NOT create a login account, and it
 * does NOT touch self-service Registration.
 *
 * Why split it this way: docs/01 lists "Member registration/login"
 * as one core module, but the only approved schema/workflow gap is
 * on the self-service *registration* side — `users.status`
 * defaults to 'pending' with no documented approval rule, and
 * that's explicitly out of scope for this unit (see
 * admin/users.php's header comment and the project's "do not
 * invent missing association rules" rule in docs/09/10). Nothing
 * about *maintaining a member roster* is ambiguous, though: the
 * `members`/`member_profiles` tables and the `members.view /
 * create / edit / manage / export` permissions already exist and
 * are already seeded, clearly anticipating admin-entered member
 * records independent of self-registration (e.g. enrolling members
 * from a physical/offline form). This page just makes that possible.
 *
 * Bank fields on member_profiles (bank_name/bank_account_no/
 * bank_ifsc) are intentionally NOT exposed here — they exist for
 * Phase 3 (Membership & Finance) fee/refund handling, which isn't
 * built yet, so collecting them now would be premature.
 *
 * member_no is a plain required text field, not auto-generated:
 * the association has its own numbering convention that isn't
 * documented anywhere in the project docs, so inventing one would
 * violate the same "do not invent business rules" constraint.
 *
 * District/Taluk/Gram Panchayat are optional posting fields here
 * (unlike office_bearers, no level selector) and degrade
 * gracefully — same pattern as admin/office-bearers.php — until
 * geography master data is imported.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();

$currentUserId = Auth::getCurrentUserId();
RBAC::requirePermission($currentUserId, 'members', 'manage');

$successMsg = Session::getFlash('success');
$errorMsg   = Session::getFlash('error');

// ---------------------------------------------------------------------------
// POST: create / update
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();

    $action = Sanitize::string($_POST['action'] ?? '', 30);

    if ($action === 'create' || $action === 'update') {
        $id             = $action === 'update' ? Sanitize::positiveInt($_POST['id'] ?? null) : null;
        $memberNo       = trim(Sanitize::string($_POST['member_no'] ?? '', 50));
        $name           = trim(Sanitize::string($_POST['name'] ?? '', 200));
        $designation    = trim(Sanitize::string($_POST['designation'] ?? '', 150));
        $joiningDateRaw = trim((string) ($_POST['joining_date'] ?? ''));
        $status         = Sanitize::inArray($_POST['membership_status'] ?? '', ['active', 'inactive', 'resigned', 'deceased']);

        $errors = [];

        if ($memberNo === '') {
            $errors[] = 'Member number is required.';
        }
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($status === false) {
            $errors[] = 'Please choose a valid membership status.';
        }

        $joiningDate = null;
        if ($joiningDateRaw !== '') {
            $joiningDate = Sanitize::date($joiningDateRaw);
            if ($joiningDate === false) {
                $errors[] = 'Joining date is invalid.';
            }
        }

        // Optional geography — validated only if provided, otherwise left NULL.
        $districtId = Sanitize::positiveInt($_POST['district_id'] ?? null);
        $districtId = $districtId === false ? null : $districtId;
        $talukId    = Sanitize::positiveInt($_POST['taluk_id'] ?? null);
        $talukId    = $talukId === false ? null : $talukId;
        $gpId       = Sanitize::positiveInt($_POST['gp_id'] ?? null);
        $gpId       = $gpId === false ? null : $gpId;

        if ($districtId !== null && !Database::fetchOne("SELECT id FROM districts WHERE id = ? AND status = 'active'", [$districtId])) {
            $errors[] = 'Selected district was not found.';
            $districtId = null;
        }
        if ($talukId !== null && !Database::fetchOne("SELECT id FROM taluks WHERE id = ? AND status = 'active'", [$talukId])) {
            $errors[] = 'Selected taluk was not found.';
            $talukId = null;
        }
        if ($gpId !== null && !Database::fetchOne("SELECT id FROM gram_panchayatis WHERE id = ? AND status = 'active'", [$gpId])) {
            $errors[] = 'Selected Gram Panchayat was not found.';
            $gpId = null;
        }

        // Uniqueness check on member_no (real unique key exists — check first for a friendly error).
        if ($memberNo !== '') {
            $dupe = Database::fetchOne(
                'SELECT id FROM members WHERE member_no = ?' . ($id ? ' AND id != ?' : ''),
                $id ? [$memberNo, $id] : [$memberNo]
            );
            if ($dupe) {
                $errors[] = 'Member number "' . $memberNo . '" is already in use.';
            }
        }

        if ($action === 'update' && $id === false) {
            $errors[] = 'Invalid member reference.';
        }

        if (!empty($errors)) {
            Session::flash('error', implode(' ', $errors));
        } else {
            if ($action === 'create') {
                Database::execute(
                    'INSERT INTO members (member_no, name, designation, gp_id, taluk_id, district_id, joining_date, membership_status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$memberNo, $name, $designation, $gpId, $talukId, $districtId, $joiningDate, $status]
                );
                $memberId = (int) Database::lastInsertId();
                AuditLogger::log('CREATE', 'members', $memberId, null, [
                    'member_no' => $memberNo, 'name' => $name, 'membership_status' => $status,
                ]);
            } else {
                $existing = Database::fetchOne('SELECT * FROM members WHERE id = ?', [$id]);
                if (!$existing) {
                    Session::flash('error', 'Member not found.');
                    header('Location: /admin/members.php');
                    exit;
                }
                $memberId = (int) $id;
                Database::execute(
                    'UPDATE members SET member_no = ?, name = ?, designation = ?, gp_id = ?, taluk_id = ?,
                     district_id = ?, joining_date = ?, membership_status = ?, updated_at = NOW() WHERE id = ?',
                    [$memberNo, $name, $designation, $gpId, $talukId, $districtId, $joiningDate, $status, $memberId]
                );
                AuditLogger::log('UPDATE', 'members', $memberId, $existing, [
                    'member_no' => $memberNo, 'name' => $name, 'membership_status' => $status,
                ]);
            }

            // ---- Optional 1:1 profile fields ----
            $profileFields = [
                'date_of_birth'            => Sanitize::date(trim((string) ($_POST['date_of_birth'] ?? '')), 'Y-m-d'),
                'gender'                   => Sanitize::inArray($_POST['gender'] ?? '', ['male', 'female', 'other']),
                'father_spouse_name'       => trim(Sanitize::string($_POST['father_spouse_name'] ?? '', 200)),
                'personal_address'         => trim(Sanitize::string($_POST['personal_address'] ?? '', 1000)),
                'city'                     => trim(Sanitize::string($_POST['city'] ?? '', 100)),
                'pin_code'                 => trim(Sanitize::string($_POST['pin_code'] ?? '', 10)),
                'personal_email'           => Sanitize::email($_POST['personal_email'] ?? ''),
                'personal_mobile'          => Sanitize::mobile($_POST['personal_mobile'] ?? ''),
                'emergency_contact_name'   => trim(Sanitize::string($_POST['emergency_contact_name'] ?? '', 200)),
                'emergency_contact_mobile' => Sanitize::mobile($_POST['emergency_contact_mobile'] ?? ''),
            ];
            // Normalize failed/false validations and blanks to NULL rather than storing garbage.
            foreach ($profileFields as $k => $v) {
                if ($v === false || $v === '') {
                    $profileFields[$k] = null;
                }
            }

            $hasAnyProfileData = count(array_filter($profileFields, static fn ($v) => $v !== null)) > 0;
            $existingProfile   = Database::fetchOne('SELECT id FROM member_profiles WHERE member_id = ?', [$memberId]);

            if ($existingProfile) {
                Database::execute(
                    'UPDATE member_profiles SET date_of_birth=?, gender=?, father_spouse_name=?, personal_address=?,
                     city=?, pin_code=?, personal_email=?, personal_mobile=?, emergency_contact_name=?,
                     emergency_contact_mobile=?, updated_at = NOW() WHERE member_id = ?',
                    [...array_values($profileFields), $memberId]
                );
            } elseif ($hasAnyProfileData) {
                Database::execute(
                    'INSERT INTO member_profiles (member_id, date_of_birth, gender, father_spouse_name, personal_address,
                     city, pin_code, personal_email, personal_mobile, emergency_contact_name, emergency_contact_mobile)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$memberId, ...array_values($profileFields)]
                );
            }

            Session::flash('success', $action === 'create' ? 'Member added.' : 'Member updated.');
        }

        header('Location: /admin/members.php' . ($id ? '?edit=' . $id : ''));
        exit;
    }
}

// ---------------------------------------------------------------------------
// Load data for display
// ---------------------------------------------------------------------------
$editId   = Sanitize::positiveInt($_GET['edit'] ?? null);
$editRow  = null;
$editProfile = null;
if ($editId !== false) {
    $editRow = Database::fetchOne('SELECT * FROM members WHERE id = ?', [$editId]);
    if ($editRow) {
        $editProfile = Database::fetchOne('SELECT * FROM member_profiles WHERE member_id = ?', [$editId]);
    }
}

$districts = Database::fetchAll("SELECT id, name FROM districts WHERE status = 'active' ORDER BY name");
$taluks    = Database::fetchAll(
    "SELECT t.id, t.name, t.district_id FROM taluks t
     JOIN districts d ON d.id = t.district_id
     WHERE t.status = 'active' ORDER BY d.name, t.name"
);
$gps = Database::fetchAll(
    "SELECT g.id, g.name, g.taluk_id FROM gram_panchayatis g
     JOIN taluks t ON t.id = g.taluk_id
     WHERE g.status = 'active' ORDER BY t.name, g.name"
);
$membershipTypes = Database::fetchAll("SELECT id, name FROM membership_types WHERE status = 'active' ORDER BY name");

$members = Database::fetchAll(
    "SELECT m.*, d.name AS district_name, t.name AS taluk_name
     FROM members m
     LEFT JOIN districts d ON d.id = m.district_id
     LEFT JOIN taluks t ON t.id = m.taluk_id
     ORDER BY m.created_at DESC"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Members — Admin — <?= Sanitize::html(APP_SHORT_NAME) ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f7fa; color: #1a1a2e; margin: 0; padding: 0 0 60px;
        }
        header {
            background: #1a3a6b; color: #fff; padding: 16px 24px;
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;
        }
        header h1 { font-size: 1.1rem; margin: 0; }
        header .links a { color: #cfe0ff; text-decoration: none; font-size: 0.85rem; margin-left: 14px; }
        main { max-width: 1040px; margin: 24px auto; padding: 0 16px; }
        .panel {
            background: #fff; border-radius: 8px; box-shadow: 0 1px 6px rgba(26,58,107,0.08);
            padding: 20px; margin-bottom: 24px;
        }
        .panel h2 { font-size: 1rem; color: #1a3a6b; margin: 0 0 4px; }
        .panel .section-hint { font-size: 0.78rem; color: #888; margin: 0 0 16px; }
        .msg { padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 16px; }
        .msg.success { background: #e7f6ec; color: #1e6b3a; border: 1px solid #b9e5c6; }
        .msg.error   { background: #fdecea; color: #a12622; border: 1px solid #f5c2be; }
        label { display: block; font-size: 0.8rem; font-weight: 600; color: #33415c; margin: 12px 0 4px; }
        input[type="text"], input[type="email"], input[type="date"], select, textarea {
            width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem; font-family: inherit;
        }
        .row { display: flex; gap: 16px; flex-wrap: wrap; }
        .row > div { flex: 1; min-width: 200px; }
        .fieldset-title {
            font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em;
            color: #556; margin: 22px 0 4px; padding-top: 14px; border-top: 1px solid #eef1f5;
        }
        .hint { font-size: 0.75rem; color: #888; margin-top: 4px; }
        button, .btn {
            background: #1a3a6b; color: #fff; border: none; border-radius: 6px;
            padding: 10px 18px; font-size: 0.9rem; font-weight: 600; cursor: pointer; margin-top: 18px;
        }
        button:hover, .btn:hover { background: #142c52; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef1f5; vertical-align: top; }
        th { color: #556; font-weight: 600; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.72rem; font-weight: 600; }
        .badge.active   { background: #e7f6ec; color: #1e6b3a; }
        .badge.inactive { background: #f1f2f4; color: #666; }
        .badge.resigned { background: #fdf6e8; color: #8a5a22; }
        .badge.deceased { background: #f1f2f4; color: #666; }
        .actions a { font-size: 0.8rem; color: #1a3a6b; font-weight: 600; text-decoration: underline; }
        .table-wrap { overflow-x: auto; }
        @media (max-width: 640px) {
            table, thead, tbody, th, td, tr { display: block; }
            thead { display: none; }
            tr { border-bottom: 2px solid #e2e6ec; padding: 10px 0; }
            td { border: none; padding: 4px 0; }
            td::before { content: attr(data-label) ": "; font-weight: 600; color: #556; }
        }
    </style>
</head>
<body>
<header>
    <h1><?= Sanitize::html(APP_SHORT_NAME) ?> — Members</h1>
    <div class="links">
        <a href="/admin/office-bearers.php">Office Bearers</a>
        <a href="/admin/members.php" style="text-decoration:underline;">Members</a>
        <a href="/admin/news.php">News</a>
        <a href="/admin/users.php">Users &amp; Roles</a>
        <a href="/admin/membership-setup.php">Membership Setup</a>
        <a href="/logout.php">Logout</a>
    </div>
</header>
<main>

    <?php if ($successMsg): ?><div class="msg success" role="status"><?= Sanitize::html($successMsg) ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="msg error" role="alert"><?= Sanitize::html($errorMsg) ?></div><?php endif; ?>

    <div class="panel">
        <h2><?= $editRow ? 'Edit Member' : 'Add Member' ?></h2>
        <p class="section-hint">
            This creates an association member roster record only — not a login account.
            Self-service registration/login is a separate, not-yet-built feature.
        </p>
        <form method="post" action="/admin/members.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
            <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>

            <div class="row">
                <div>
                    <label for="member_no">Member Number</label>
                    <input type="text" id="member_no" name="member_no" required maxlength="50"
                           value="<?= Sanitize::attr($editRow['member_no'] ?? '') ?>">
                    <p class="hint">Assigned by the association per its own numbering convention.</p>
                </div>
                <div>
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" required maxlength="200"
                           value="<?= Sanitize::attr($editRow['name'] ?? '') ?>">
                </div>
                <div>
                    <label for="designation">Designation</label>
                    <input type="text" id="designation" name="designation" maxlength="150"
                           value="<?= Sanitize::attr($editRow['designation'] ?? '') ?>">
                </div>
            </div>

            <div class="row">
                <div>
                    <label for="district_id">District — optional</label>
                    <select id="district_id" name="district_id" <?= empty($districts) ? 'disabled' : '' ?>>
                        <option value="">— None —</option>
                        <?php foreach ($districts as $d): ?>
                            <option value="<?= (int) $d['id'] ?>" <?= ($editRow && (int) ($editRow['district_id'] ?? 0) === (int) $d['id']) ? 'selected' : '' ?>>
                                <?= Sanitize::html($d['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($districts)): ?><p class="hint">No districts set up yet.</p><?php endif; ?>
                </div>
                <div>
                    <label for="taluk_id">Taluk — optional</label>
                    <select id="taluk_id" name="taluk_id" <?= empty($taluks) ? 'disabled' : '' ?>>
                        <option value="">— None —</option>
                        <?php foreach ($taluks as $t): ?>
                            <option value="<?= (int) $t['id'] ?>" <?= ($editRow && (int) ($editRow['taluk_id'] ?? 0) === (int) $t['id']) ? 'selected' : '' ?>>
                                <?= Sanitize::html($t['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($taluks)): ?><p class="hint">No taluks set up yet.</p><?php endif; ?>
                </div>
                <div>
                    <label for="gp_id">Gram Panchayat — optional</label>
                    <select id="gp_id" name="gp_id" <?= empty($gps) ? 'disabled' : '' ?>>
                        <option value="">— None —</option>
                        <?php foreach ($gps as $g): ?>
                            <option value="<?= (int) $g['id'] ?>" <?= ($editRow && (int) ($editRow['gp_id'] ?? 0) === (int) $g['id']) ? 'selected' : '' ?>>
                                <?= Sanitize::html($g['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($gps)): ?><p class="hint">No Gram Panchayatis set up yet.</p><?php endif; ?>
                </div>
            </div>

            <div class="row">
                <div>
                    <label for="joining_date">Joining Date — optional</label>
                    <input type="date" id="joining_date" name="joining_date"
                           value="<?= Sanitize::attr($editRow['joining_date'] ?? '') ?>">
                </div>
                <div>
                    <label for="membership_status">Membership Status</label>
                    <select id="membership_status" name="membership_status">
                        <?php foreach (['active', 'inactive', 'resigned', 'deceased'] as $s): ?>
                            <option value="<?= $s ?>" <?= ($editRow ? $editRow['membership_status'] : 'active') === $s ? 'selected' : '' ?>>
                                <?= ucfirst($s) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php if (empty($membershipTypes)): ?>
                <p class="hint">Membership types (Phase 3) are not set up yet — this record isn't linked to one.</p>
            <?php endif; ?>

            <div class="fieldset-title">Personal Profile — optional</div>
            <div class="row">
                <div>
                    <label for="date_of_birth">Date of Birth</label>
                    <input type="date" id="date_of_birth" name="date_of_birth"
                           value="<?= Sanitize::attr($editProfile['date_of_birth'] ?? '') ?>">
                </div>
                <div>
                    <label for="gender">Gender</label>
                    <select id="gender" name="gender">
                        <option value="">— Not specified —</option>
                        <?php foreach (['male', 'female', 'other'] as $g): ?>
                            <option value="<?= $g ?>" <?= (($editProfile['gender'] ?? '') === $g) ? 'selected' : '' ?>><?= ucfirst($g) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="father_spouse_name">Father's/Spouse's Name</label>
                    <input type="text" id="father_spouse_name" name="father_spouse_name" maxlength="200"
                           value="<?= Sanitize::attr($editProfile['father_spouse_name'] ?? '') ?>">
                </div>
            </div>
            <div class="row">
                <div style="flex:2;">
                    <label for="personal_address">Personal Address</label>
                    <textarea id="personal_address" name="personal_address" rows="2" maxlength="1000"><?= Sanitize::html($editProfile['personal_address'] ?? '') ?></textarea>
                </div>
                <div>
                    <label for="city">City</label>
                    <input type="text" id="city" name="city" maxlength="100"
                           value="<?= Sanitize::attr($editProfile['city'] ?? '') ?>">
                </div>
                <div>
                    <label for="pin_code">PIN Code</label>
                    <input type="text" id="pin_code" name="pin_code" maxlength="10"
                           value="<?= Sanitize::attr($editProfile['pin_code'] ?? '') ?>">
                </div>
            </div>
            <div class="row">
                <div>
                    <label for="personal_email">Personal Email</label>
                    <input type="email" id="personal_email" name="personal_email" maxlength="190"
                           value="<?= Sanitize::attr($editProfile['personal_email'] ?? '') ?>">
                </div>
                <div>
                    <label for="personal_mobile">Personal Mobile</label>
                    <input type="text" id="personal_mobile" name="personal_mobile" maxlength="20"
                           value="<?= Sanitize::attr($editProfile['personal_mobile'] ?? '') ?>">
                </div>
            </div>
            <div class="row">
                <div>
                    <label for="emergency_contact_name">Emergency Contact Name</label>
                    <input type="text" id="emergency_contact_name" name="emergency_contact_name" maxlength="200"
                           value="<?= Sanitize::attr($editProfile['emergency_contact_name'] ?? '') ?>">
                </div>
                <div>
                    <label for="emergency_contact_mobile">Emergency Contact Mobile</label>
                    <input type="text" id="emergency_contact_mobile" name="emergency_contact_mobile" maxlength="20"
                           value="<?= Sanitize::attr($editProfile['emergency_contact_mobile'] ?? '') ?>">
                </div>
            </div>

            <button type="submit"><?= $editRow ? 'Save Changes' : 'Add Member' ?></button>
            <?php if ($editRow): ?><a class="btn" style="background:#888; text-decoration:none; display:inline-block;" href="/admin/members.php">Cancel</a><?php endif; ?>
        </form>
    </div>

    <div class="panel">
        <h2>Members (<?= count($members) ?>)</h2>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Member No.</th><th>Name</th><th>Designation</th><th>District/Taluk</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($members as $m): ?>
                <tr>
                    <td data-label="Member No."><?= Sanitize::html($m['member_no']) ?></td>
                    <td data-label="Name"><?= Sanitize::html($m['name']) ?></td>
                    <td data-label="Designation"><?= Sanitize::html($m['designation'] ?: '—') ?></td>
                    <td data-label="District/Taluk">
                        <?= $m['taluk_name'] ? Sanitize::html($m['taluk_name']) . ', ' : '' ?><?= Sanitize::html($m['district_name'] ?? '—') ?>
                    </td>
                    <td data-label="Status"><span class="badge <?= Sanitize::html($m['membership_status']) ?>"><?= Sanitize::html($m['membership_status']) ?></span></td>
                    <td data-label="Actions" class="actions"><a href="/admin/members.php?edit=<?= (int) $m['id'] ?>">Edit</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($members)): ?>
                <tr><td colspan="6">No members added yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</main>
</body>
</html>
