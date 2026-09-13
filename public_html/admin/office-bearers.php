<?php
/**
 * KSPDOWA — Admin: Office Bearers Management
 * ============================================================
 * Add / edit / activate-deactivate State, District, and Taluk
 * office bearers. Gated by RBAC permission 'office_bearers.manage'
 * (seeded in Phase 0 — see seeds/001_roles_permissions.sql).
 *
 * Names are recorded in Kannada script only, per project decision.
 *
 * District/Taluk entries require geography master data (districts,
 * taluks) to already be imported — migration 001_geography.sql
 * deliberately ships those tables empty ("Do not invent geography.
 * Geography master data must be imported by the association from
 * verified official records."). Until that import happens, this
 * page only allows State-level entries.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();

$currentUserId = Auth::getCurrentUserId();
RBAC::requirePermission($currentUserId, 'office_bearers', 'manage');

// ---------------------------------------------------------------------------
// POST: create / update / toggle_status
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();

    $action = Sanitize::string($_POST['action'] ?? '', 30);

    if ($action === 'toggle_status') {
        $id = Sanitize::positiveInt($_POST['id'] ?? null);

        if ($id === false) {
            Session::flash('error', 'Invalid office bearer reference.');
        } else {
            $existing = Database::fetchOne('SELECT * FROM office_bearers WHERE id = ?', [$id]);

            if (!$existing) {
                Session::flash('error', 'Office bearer not found.');
            } else {
                $newStatus = $existing['status'] === 'active' ? 'former' : 'active';
                Database::execute(
                    'UPDATE office_bearers SET status = ?, updated_at = NOW() WHERE id = ?',
                    [$newStatus, $id]
                );
                AuditLogger::log('UPDATE', 'office_bearers', $id, $existing, ['status' => $newStatus]);
                Session::flash('success', 'Status updated.');
            }
        }

        header('Location: /admin/office-bearers.php');
        exit;
    }

    if ($action === 'create' || $action === 'update') {
        $id                     = $action === 'update' ? Sanitize::positiveInt($_POST['id'] ?? null) : null;
        $name                   = trim(Sanitize::string($_POST['name'] ?? '', 200));
        $associationDesignation = trim(Sanitize::string($_POST['association_designation'] ?? '', 200));
        $officialDesignationRaw = trim(Sanitize::string($_POST['official_designation'] ?? '', 200));
        $officialDesignation    = $officialDesignationRaw !== '' ? $officialDesignationRaw : null;
        $level                  = Sanitize::inArray($_POST['level'] ?? '', ['state', 'district', 'taluk']);
        $sortOrder              = Sanitize::nonNegativeInt($_POST['sort_order'] ?? 0);
        $termStartRaw           = trim((string) ($_POST['term_start'] ?? ''));
        $termEndRaw             = trim((string) ($_POST['term_end'] ?? ''));

        $errors = [];

        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($associationDesignation === '') {
            $errors[] = 'Association designation is required.';
        }
        if ($level === false) {
            $errors[] = 'Please choose a valid level (State / District / Taluk).';
        }
        if ($sortOrder === false) {
            $sortOrder = 0;
        }

        $termStart = null;
        if ($termStartRaw !== '') {
            $termStart = Sanitize::date($termStartRaw);
            if ($termStart === false) {
                $errors[] = 'Term start date is invalid.';
                $termStart = null;
            }
        }

        $termEnd = null;
        if ($termEndRaw !== '') {
            $termEnd = Sanitize::date($termEndRaw);
            if ($termEnd === false) {
                $errors[] = 'Term end date is invalid.';
                $termEnd = null;
            }
        }

        if ($termStart !== null && $termEnd !== null && $termStart > $termEnd) {
            $errors[] = 'Term end date cannot be before term start date.';
        }

        $districtId = null;
        $talukId    = null;

        if ($level === 'district') {
            $districtId = Sanitize::positiveInt($_POST['district_id'] ?? null);
            if ($districtId === false) {
                $errors[] = 'Please choose a district.';
                $districtId = null;
            } else {
                $district = Database::fetchOne(
                    "SELECT id FROM districts WHERE id = ? AND status = 'active'",
                    [$districtId]
                );
                if (!$district) {
                    $errors[] = 'Selected district was not found.';
                    $districtId = null;
                }
            }
        } elseif ($level === 'taluk') {
            $talukId = Sanitize::positiveInt($_POST['taluk_id'] ?? null);
            if ($talukId === false) {
                $errors[] = 'Please choose a taluk.';
                $talukId = null;
            } else {
                $taluk = Database::fetchOne(
                    "SELECT id, district_id FROM taluks WHERE id = ? AND status = 'active'",
                    [$talukId]
                );
                if (!$taluk) {
                    $errors[] = 'Selected taluk was not found.';
                    $talukId = null;
                } else {
                    // District is derived from the taluk — never taken directly from the form.
                    $districtId = (int) $taluk['district_id'];
                }
            }
        }

        if ($action === 'update' && $id === false) {
            $errors[] = 'Invalid office bearer reference.';
        }

        if (!empty($errors)) {
            Session::flash('error', implode(' ', $errors));
            header('Location: /admin/office-bearers.php' . ($id ? '?edit=' . $id : ''));
            exit;
        }

        $data = [
            'name'                     => $name,
            'association_designation'  => $associationDesignation,
            'official_designation'     => $officialDesignation,
            'district_id'              => $districtId,
            'taluk_id'                 => $talukId,
            'term_start'               => $termStart,
            'term_end'                 => $termEnd,
            'sort_order'               => $sortOrder,
        ];

        if ($action === 'create') {
            Database::execute(
                'INSERT INTO office_bearers
                    (name, association_designation, official_designation, district_id, taluk_id,
                     term_start, term_end, status, sort_order, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $data['name'], $data['association_designation'], $data['official_designation'],
                    $data['district_id'], $data['taluk_id'], $data['term_start'], $data['term_end'],
                    'active', $data['sort_order'],
                ]
            );
            $newId = (int) Database::lastInsertId();
            AuditLogger::log('CREATE', 'office_bearers', $newId, null, $data);
            Session::flash('success', 'Office bearer added.');
        } else {
            $existing = Database::fetchOne('SELECT * FROM office_bearers WHERE id = ?', [$id]);

            if (!$existing) {
                Session::flash('error', 'Office bearer not found.');
                header('Location: /admin/office-bearers.php');
                exit;
            }

            Database::execute(
                'UPDATE office_bearers SET
                    name = ?, association_designation = ?, official_designation = ?,
                    district_id = ?, taluk_id = ?, term_start = ?, term_end = ?,
                    sort_order = ?, updated_at = NOW()
                 WHERE id = ?',
                [
                    $data['name'], $data['association_designation'], $data['official_designation'],
                    $data['district_id'], $data['taluk_id'], $data['term_start'], $data['term_end'],
                    $data['sort_order'], $id,
                ]
            );
            AuditLogger::log('UPDATE', 'office_bearers', $id, $existing, $data);
            Session::flash('success', 'Office bearer updated.');
        }

        header('Location: /admin/office-bearers.php');
        exit;
    }

    ErrorHandler::abort(400, 'Unknown action.');
}

// ---------------------------------------------------------------------------
// GET: render list + form
// ---------------------------------------------------------------------------
$districts = Database::fetchAll("SELECT id, name FROM districts WHERE status = 'active' ORDER BY name");
$taluks    = Database::fetchAll(
    "SELECT t.id, t.name, t.district_id, d.name AS district_name
     FROM taluks t
     JOIN districts d ON d.id = t.district_id
     WHERE t.status = 'active' AND d.status = 'active'
     ORDER BY d.name, t.name"
);

$officeBearers = Database::fetchAll(
    "SELECT ob.*, d.name AS district_name, t.name AS taluk_name
     FROM office_bearers ob
     LEFT JOIN districts d ON d.id = ob.district_id
     LEFT JOIN taluks t    ON t.id = ob.taluk_id
     ORDER BY
        CASE WHEN ob.taluk_id IS NOT NULL THEN 3
             WHEN ob.district_id IS NOT NULL THEN 2
             ELSE 1 END,
        ob.sort_order, ob.name"
);

$editRow = null;
$editId  = Sanitize::positiveInt($_GET['edit'] ?? null);
if ($editId !== false) {
    $editRow = Database::fetchOne('SELECT * FROM office_bearers WHERE id = ?', [$editId]);
}

function ob_level(array $row): string
{
    if (!empty($row['taluk_id']))    return 'taluk';
    if (!empty($row['district_id'])) return 'district';
    return 'state';
}

$successMsg = Session::getFlash('success');
$errorMsg   = Session::getFlash('error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Office Bearers — Admin — <?= Sanitize::html(APP_SHORT_NAME) ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f7fa;
            color: #1a1a2e;
            margin: 0;
            padding: 0 0 60px;
        }
        header {
            background: #1a3a6b;
            color: #fff;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        header h1 { font-size: 1.1rem; margin: 0; }
        header a { color: #cfe0ff; text-decoration: none; font-size: 0.85rem; }
        header .links a { margin-left: 14px; }
        main { max-width: 960px; margin: 24px auto; padding: 0 16px; }
        .panel {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 6px rgba(26,58,107,0.08);
            padding: 20px;
            margin-bottom: 24px;
        }
        .panel h2 { font-size: 1rem; color: #1a3a6b; margin: 0 0 16px; }
        .msg {
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 0.85rem;
            margin-bottom: 16px;
        }
        .msg.success { background: #e7f6ec; color: #1e6b3a; border: 1px solid #b9e5c6; }
        .msg.error   { background: #fdecea; color: #a12622; border: 1px solid #f5c2be; }
        label { display: block; font-size: 0.8rem; font-weight: 600; color: #33415c; margin: 12px 0 4px; }
        input[type="text"], input[type="number"], input[type="date"], select {
            width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;
        }
        .row { display: flex; gap: 16px; flex-wrap: wrap; }
        .row > div { flex: 1; min-width: 200px; }
        .level-choice { display: flex; gap: 16px; margin: 12px 0 4px; flex-wrap: wrap; }
        .level-choice label { display: flex; align-items: center; gap: 6px; font-weight: 500; margin: 0; }
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
        .badge.active { background: #e7f6ec; color: #1e6b3a; }
        .badge.former { background: #f1f2f4; color: #666; }
        .level-tag { font-size: 0.72rem; color: #1a3a6b; font-weight: 600; text-transform: uppercase; }
        .actions form { display: inline; }
        .actions a, .actions button.link {
            font-size: 0.8rem; margin-right: 10px; background: none; color: #1a3a6b;
            border: none; padding: 0; font-weight: 600; cursor: pointer; text-decoration: underline;
        }
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
    <h1><?= Sanitize::html(APP_SHORT_NAME) ?> — Office Bearers</h1>
    <div class="links">
        <a href="/admin/members.php">Members</a>
        <a href="/admin/news.php">News</a>
        <a href="/admin/users.php">Users &amp; Roles</a>
        <a href="/admin/membership-setup.php">Membership Setup</a>
        <a href="/admin/donations.php">Donations</a>
        <a href="/admin/settings.php">Association Settings</a>
        <a href="/logout.php">Logout</a>
    </div>
</header>
<main>

    <?php if ($successMsg): ?>
        <div class="msg success" role="status"><?= Sanitize::html($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="msg error" role="alert"><?= Sanitize::html($errorMsg) ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2><?= $editRow ? 'Edit Office Bearer' : 'Add Office Bearer' ?></h2>

        <?php if (empty($districts)): ?>
            <div class="msg error" role="alert">
                No districts are set up yet, so only State-level entries can be added right now.
                District/Taluk geography master data needs to be imported first.
            </div>
        <?php endif; ?>

        <form method="post" action="/admin/office-bearers.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
            <?php if ($editRow): ?>
                <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
            <?php endif; ?>

            <?php $currentLevel = $editRow ? ob_level($editRow) : 'state'; ?>
            <label>Level</label>
            <div class="level-choice">
                <label><input type="radio" name="level" value="state" onchange="obUpdateLevel()"
                        <?= $currentLevel === 'state' ? 'checked' : '' ?>> State</label>
                <label><input type="radio" name="level" value="district" onchange="obUpdateLevel()"
                        <?= empty($districts) ? 'disabled' : '' ?>
                        <?= $currentLevel === 'district' ? 'checked' : '' ?>> District</label>
                <label><input type="radio" name="level" value="taluk" onchange="obUpdateLevel()"
                        <?= empty($taluks) ? 'disabled' : '' ?>
                        <?= $currentLevel === 'taluk' ? 'checked' : '' ?>> Taluk</label>
            </div>

            <div id="ob-district-field" style="display:none;">
                <label for="district_id">District</label>
                <select name="district_id" id="district_id">
                    <option value="">— Select district —</option>
                    <?php foreach ($districts as $d): ?>
                        <option value="<?= (int) $d['id'] ?>"
                            <?= ($editRow && (int) ($editRow['district_id'] ?? 0) === (int) $d['id']) ? 'selected' : '' ?>>
                            <?= Sanitize::html($d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="ob-taluk-field" style="display:none;">
                <label for="taluk_id">Taluk</label>
                <select name="taluk_id" id="taluk_id">
                    <option value="">— Select taluk —</option>
                    <?php foreach ($taluks as $t): ?>
                        <option value="<?= (int) $t['id'] ?>"
                            <?= ($editRow && (int) ($editRow['taluk_id'] ?? 0) === (int) $t['id']) ? 'selected' : '' ?>>
                            <?= Sanitize::html($t['name']) ?> — <?= Sanitize::html($t['district_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="row">
                <div>
                    <label for="name">Name (Kannada)</label>
                    <input type="text" id="name" name="name" required lang="kn"
                           value="<?= Sanitize::attr($editRow['name'] ?? '') ?>">
                    <div class="hint">ಉದಾ: ದಿಲೀಪ್ ಕುಮಾರ ಬಿ.ಎಂ</div>
                </div>
                <div>
                    <label for="association_designation">Association Designation (Kannada)</label>
                    <input type="text" id="association_designation" name="association_designation" required lang="kn"
                           value="<?= Sanitize::attr($editRow['association_designation'] ?? '') ?>">
                    <div class="hint">ಉದಾ: ಅಧ್ಯಕ್ಷರ ಸ್ಥಾನ</div>
                </div>
            </div>

            <div class="row">
                <div>
                    <label for="official_designation">Official (Govt.) Designation — optional</label>
                    <input type="text" id="official_designation" name="official_designation"
                           value="<?= Sanitize::attr($editRow['official_designation'] ?? '') ?>">
                </div>
                <div>
                    <label for="sort_order">Display Order</label>
                    <input type="number" id="sort_order" name="sort_order" min="0"
                           value="<?= (int) ($editRow['sort_order'] ?? 0) ?>">
                </div>
            </div>

            <div class="row">
                <div>
                    <label for="term_start">Term Start — optional</label>
                    <input type="date" id="term_start" name="term_start"
                           value="<?= Sanitize::attr($editRow['term_start'] ?? '') ?>">
                </div>
                <div>
                    <label for="term_end">Term End — optional</label>
                    <input type="date" id="term_end" name="term_end"
                           value="<?= Sanitize::attr($editRow['term_end'] ?? '') ?>">
                </div>
            </div>

            <button type="submit"><?= $editRow ? 'Save Changes' : 'Add Office Bearer' ?></button>
            <?php if ($editRow): ?>
                <a class="btn" href="/admin/office-bearers.php" style="background:#888; text-decoration:none; display:inline-block;">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="panel">
        <h2>Existing Office Bearers (<?= count($officeBearers) ?>)</h2>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Level</th><th>Name</th><th>Designation</th><th>Term</th><th>Status</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($officeBearers as $row): $level = ob_level($row); ?>
                <tr>
                    <td data-label="Level">
                        <span class="level-tag"><?= strtoupper($level) ?></span>
                        <?php if ($level === 'district'): ?>
                            <div><?= Sanitize::html($row['district_name']) ?></div>
                        <?php elseif ($level === 'taluk'): ?>
                            <div><?= Sanitize::html($row['taluk_name']) ?> (<?= Sanitize::html($row['district_name']) ?>)</div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Name"><?= Sanitize::html($row['name']) ?></td>
                    <td data-label="Designation">
                        <?= Sanitize::html($row['association_designation']) ?>
                        <?php if (!empty($row['official_designation'])): ?>
                            <div class="hint"><?= Sanitize::html($row['official_designation']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Term">
                        <?= Sanitize::html($row['term_start'] ?? '—') ?> to <?= Sanitize::html($row['term_end'] ?? '—') ?>
                    </td>
                    <td data-label="Status">
                        <span class="badge <?= $row['status'] === 'active' ? 'active' : 'former' ?>">
                            <?= Sanitize::html($row['status']) ?>
                        </span>
                    </td>
                    <td data-label="Actions" class="actions">
                        <a href="/admin/office-bearers.php?edit=<?= (int) $row['id'] ?>">Edit</a>
                        <form method="post" action="/admin/office-bearers.php" style="display:inline;">
                            <?= CSRF::htmlField() ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button type="submit" class="link">
                                <?= $row['status'] === 'active' ? 'Mark Former' : 'Reactivate' ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($officeBearers)): ?>
                <tr><td colspan="6">No office bearers added yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</main>
<script>
function obUpdateLevel() {
    var level = document.querySelector('input[name="level"]:checked').value;
    document.getElementById('ob-district-field').style.display = (level === 'district' || level === 'taluk') ? 'block' : 'none';
    document.getElementById('ob-taluk-field').style.display = (level === 'taluk') ? 'block' : 'none';
}
obUpdateLevel();
</script>
</body>
</html>

