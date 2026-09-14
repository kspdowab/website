<?php
/**
 * KSPDOWA — Admin: Membership Setup (Phase 3 foundation unit)
 * ============================================================
 * Gated by RBAC permission 'membership.manage' (seeded in Phase 0).
 *
 * Manages the two reference tables every other Phase 3 piece
 * depends on: `membership_types` and `membership_years`
 * (migrations/004_finance.sql — no schema changes here). Neither
 * table has any seed data, and this page does not add any either:
 * fee amounts, type names, and financial-year boundaries are real
 * association facts not documented anywhere in the project docs,
 * so an authorized officer enters them directly here rather than
 * having any of it invented.
 *
 * "Current year" semantics: `membership_years.status` is an enum
 * ('active','inactive','closed'). The rest of the system (member
 * login eligibility, payment recording) needs a single unambiguous
 * "current year" to check against, so this page enforces — at the
 * application level, not by altering the approved schema — that at
 * most one financial year is 'active' at a time: marking a year
 * active automatically demotes any other currently-active year to
 * 'inactive' (never to 'closed' — closing a year is a separate,
 * deliberate action an officer takes on its own, not an automatic
 * side effect). This is an implementation detail for making
 * "current year" well-defined, not a business rule about fees or
 * eligibility.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();

$currentUserId = Auth::getCurrentUserId();
RBAC::requirePermission($currentUserId, 'membership', 'manage');

$successMsg = Session::getFlash('success');
$errorMsg   = Session::getFlash('error');

// ---------------------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();

    $action = Sanitize::string($_POST['action'] ?? '', 40);

    // ---- Membership Types ----
    if ($action === 'create_type' || $action === 'update_type') {
        $id          = $action === 'update_type' ? Sanitize::positiveInt($_POST['id'] ?? null) : null;
        $name        = trim(Sanitize::string($_POST['name'] ?? '', 150));
        $description = trim(Sanitize::string($_POST['description'] ?? '', 2000));
        $feeAmount   = Sanitize::amount($_POST['fee_amount'] ?? null);
        $status      = Sanitize::inArray($_POST['status'] ?? '', ['active', 'inactive']);

        $errors = [];
        if ($name === '') { $errors[] = 'Name is required.'; }
        if ($feeAmount === false) { $errors[] = 'Fee amount must be a positive number.'; }
        if ($status === false) { $errors[] = 'Please choose a valid status.'; }
        if ($action === 'update_type' && $id === false) { $errors[] = 'Invalid membership type reference.'; }

        if ($name !== '') {
            $dupe = Database::fetchOne(
                'SELECT id FROM membership_types WHERE name = ?' . ($id ? ' AND id != ?' : ''),
                $id ? [$name, $id] : [$name]
            );
            if ($dupe) { $errors[] = 'A membership type named "' . $name . '" already exists.'; }
        }

        if (!empty($errors)) {
            Session::flash('error', implode(' ', $errors));
        } elseif ($action === 'create_type') {
            Database::execute(
                'INSERT INTO membership_types (name, description, fee_amount, status) VALUES (?, ?, ?, ?)',
                [$name, $description !== '' ? $description : null, $feeAmount, $status]
            );
            $newId = (int) Database::lastInsertId();
            AuditLogger::log('CREATE', 'membership_types', $newId, null, ['name' => $name, 'fee_amount' => $feeAmount, 'status' => $status]);
            Session::flash('success', 'Membership type added.');
        } else {
            $existing = Database::fetchOne('SELECT * FROM membership_types WHERE id = ?', [$id]);
            if (!$existing) {
                Session::flash('error', 'Membership type not found.');
            } else {
                Database::execute(
                    'UPDATE membership_types SET name = ?, description = ?, fee_amount = ?, status = ? WHERE id = ?',
                    [$name, $description !== '' ? $description : null, $feeAmount, $status, $id]
                );
                AuditLogger::log('UPDATE', 'membership_types', (int) $id, $existing, ['name' => $name, 'fee_amount' => $feeAmount, 'status' => $status]);
                Session::flash('success', 'Membership type updated.');
            }
        }

        header('Location: /admin/membership-setup.php');
        exit;
    }

    // ---- Financial (Membership) Years ----
    if ($action === 'create_year' || $action === 'update_year') {
        $id             = $action === 'update_year' ? Sanitize::positiveInt($_POST['id'] ?? null) : null;
        $financialYear  = trim(Sanitize::string($_POST['financial_year'] ?? '', 20));
        $startDateRaw   = trim((string) ($_POST['start_date'] ?? ''));
        $endDateRaw     = trim((string) ($_POST['end_date'] ?? ''));
        $feeAmount      = Sanitize::amount($_POST['fee_amount'] ?? null);
        $status         = Sanitize::inArray($_POST['status'] ?? '', ['active', 'inactive', 'closed']);

        $errors = [];
        if ($financialYear === '') { $errors[] = 'Financial year label is required (e.g. 2026-27).'; }
        if ($feeAmount === false) { $errors[] = 'Fee amount must be a positive number.'; }
        if ($status === false) { $errors[] = 'Please choose a valid status.'; }

        $startDate = Sanitize::date($startDateRaw);
        if ($startDate === false) { $errors[] = 'Start date is required and must be valid.'; }
        $endDate = Sanitize::date($endDateRaw);
        if ($endDate === false) { $errors[] = 'End date is required and must be valid.'; }
        if ($startDate !== false && $endDate !== false && $startDate >= $endDate) {
            $errors[] = 'End date must be after start date.';
        }
        if ($action === 'update_year' && $id === false) { $errors[] = 'Invalid financial year reference.'; }

        if ($financialYear !== '') {
            $dupe = Database::fetchOne(
                'SELECT id FROM membership_years WHERE financial_year = ?' . ($id ? ' AND id != ?' : ''),
                $id ? [$financialYear, $id] : [$financialYear]
            );
            if ($dupe) { $errors[] = 'Financial year "' . $financialYear . '" already exists.'; }
        }

        if (!empty($errors)) {
            Session::flash('error', implode(' ', $errors));
        } else {
            if ($action === 'create_year') {
                if ($status === 'active') {
                    Database::execute("UPDATE membership_years SET status = 'inactive' WHERE status = 'active'");
                }
                Database::execute(
                    'INSERT INTO membership_years (financial_year, start_date, end_date, fee_amount, status) VALUES (?, ?, ?, ?, ?)',
                    [$financialYear, $startDate, $endDate, $feeAmount, $status]
                );
                $newId = (int) Database::lastInsertId();
                AuditLogger::log('CREATE', 'membership_years', $newId, null, [
                    'financial_year' => $financialYear, 'fee_amount' => $feeAmount, 'status' => $status,
                ]);
                Session::flash('success', 'Financial year added.' . ($status === 'active' ? ' Any previously active year was set to inactive.' : ''));
            } else {
                $existing = Database::fetchOne('SELECT * FROM membership_years WHERE id = ?', [$id]);
                if (!$existing) {
                    Session::flash('error', 'Financial year not found.');
                } else {
                    if ($status === 'active' && $existing['status'] !== 'active') {
                        Database::execute("UPDATE membership_years SET status = 'inactive' WHERE status = 'active' AND id != ?", [$id]);
                    }
                    Database::execute(
                        'UPDATE membership_years SET financial_year = ?, start_date = ?, end_date = ?, fee_amount = ?, status = ? WHERE id = ?',
                        [$financialYear, $startDate, $endDate, $feeAmount, $status, $id]
                    );
                    AuditLogger::log('UPDATE', 'membership_years', (int) $id, $existing, [
                        'financial_year' => $financialYear, 'fee_amount' => $feeAmount, 'status' => $status,
                    ]);
                    Session::flash('success', 'Financial year updated.' . ($status === 'active' ? ' Any previously active year was set to inactive.' : ''));
                }
            }
        }

        header('Location: /admin/membership-setup.php');
        exit;
    }
}

// ---------------------------------------------------------------------------
// Load data
// ---------------------------------------------------------------------------
$editTypeId = Sanitize::positiveInt($_GET['edit_type'] ?? null);
$editType   = $editTypeId !== false ? Database::fetchOne('SELECT * FROM membership_types WHERE id = ?', [$editTypeId]) : null;

$editYearId = Sanitize::positiveInt($_GET['edit_year'] ?? null);
$editYear   = $editYearId !== false ? Database::fetchOne('SELECT * FROM membership_years WHERE id = ?', [$editYearId]) : null;

$membershipTypes = Database::fetchAll('SELECT * FROM membership_types ORDER BY name');
$membershipYears = Database::fetchAll('SELECT * FROM membership_years ORDER BY start_date DESC');
$currentYear     = Membership::getCurrentYear(); // single source of truth -- see includes/Membership.php

$pageTitle   = 'Membership Setup';
$activeMenu  = 'membership';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Members', 'url' => '/admin/members.php'],
    ['label' => 'Membership Setup', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>
<style>
    .row { display: flex; gap: 16px; flex-wrap: wrap; }
    .row > div { flex: 1; min-width: 180px; }
    .actions a { font-size: 0.8rem; color: #1a3a6b; font-weight: 600; text-decoration: underline; margin-right: 10px; }
    .msg.notice { background: #eef2f9; color: #1a3a6b; border: 1px solid #cddcf0; }
</style>

    <div class="msg notice" role="status">
        Current financial year:
        <strong><?= $currentYear ? Sanitize::html($currentYear['financial_year']) . ' (fee ₹' . Sanitize::html(number_format((float) $currentYear['fee_amount'], 2)) . ')' : 'not set' ?></strong>
        <?php if (!$currentYear): ?> — add one below and mark it Active so member login eligibility has a year to check against.<?php endif; ?>
    </div>

    <div class="panel">
        <h2><?= $editType ? 'Edit Membership Type' : 'Add Membership Type' ?></h2>
        <form method="post" action="/admin/membership-setup.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="<?= $editType ? 'update_type' : 'create_type' ?>">
            <?php if ($editType): ?><input type="hidden" name="id" value="<?= (int) $editType['id'] ?>"><?php endif; ?>
            <div class="row">
                <div>
                    <label for="type_name">Name</label>
                    <input type="text" id="type_name" name="name" required maxlength="150"
                           value="<?= Sanitize::attr($editType['name'] ?? '') ?>">
                </div>
                <div>
                    <label for="type_fee">Annual Fee (&#8377;)</label>
                    <input type="number" id="type_fee" name="fee_amount" required step="0.01" min="0.01"
                           value="<?= Sanitize::attr($editType['fee_amount'] ?? '') ?>">
                </div>
                <div>
                    <label for="type_status">Status</label>
                    <select id="type_status" name="status">
                        <option value="active" <?= (($editType['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= (($editType['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <label for="type_description">Description — optional</label>
            <textarea id="type_description" name="description" rows="2" maxlength="2000"><?= Sanitize::html($editType['description'] ?? '') ?></textarea>
            <button type="submit"><?= $editType ? 'Save Changes' : 'Add Membership Type' ?></button>
            <?php if ($editType): ?><a class="btn" style="background:#888; text-decoration:none; display:inline-block;" href="/admin/membership-setup.php">Cancel</a><?php endif; ?>
        </form>
    </div>

    <div class="panel">
        <h2>Membership Types (<?= count($membershipTypes) ?>)</h2>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Name</th><th>Fee</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($membershipTypes as $t): ?>
                <tr>
                    <td data-label="Name"><?= Sanitize::html($t['name']) ?><?php if ($t['description']): ?><div style="color:#888; font-size:0.78rem;"><?= Sanitize::html($t['description']) ?></div><?php endif; ?></td>
                    <td data-label="Fee">&#8377;<?= Sanitize::html(number_format((float) $t['fee_amount'], 2)) ?></td>
                    <td data-label="Status"><span class="badge <?= Sanitize::html($t['status']) ?>"><?= Sanitize::html($t['status']) ?></span></td>
                    <td data-label="Actions" class="actions"><a href="/admin/membership-setup.php?edit_type=<?= (int) $t['id'] ?>">Edit</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($membershipTypes)): ?><tr><td colspan="4">No membership types added yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="panel">
        <h2><?= $editYear ? 'Edit Financial Year' : 'Add Financial Year' ?></h2>
        <p class="section-hint">Marking a year Active automatically sets any other currently-active year to Inactive — only one year is ever "current" at a time.</p>
        <form method="post" action="/admin/membership-setup.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="<?= $editYear ? 'update_year' : 'create_year' ?>">
            <?php if ($editYear): ?><input type="hidden" name="id" value="<?= (int) $editYear['id'] ?>"><?php endif; ?>
            <div class="row">
                <div>
                    <label for="year_label">Financial Year</label>
                    <input type="text" id="year_label" name="financial_year" required maxlength="20" placeholder="e.g. 2026-27"
                           value="<?= Sanitize::attr($editYear['financial_year'] ?? '') ?>">
                </div>
                <div>
                    <label for="year_fee">Annual Fee (&#8377;)</label>
                    <input type="number" id="year_fee" name="fee_amount" required step="0.01" min="0.01"
                           value="<?= Sanitize::attr($editYear['fee_amount'] ?? '') ?>">
                </div>
                <div>
                    <label for="year_status">Status</label>
                    <select id="year_status" name="status">
                        <?php foreach (['active', 'inactive', 'closed'] as $s): ?>
                            <option value="<?= $s ?>" <?= (($editYear['status'] ?? 'inactive') === $s) ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row">
                <div>
                    <label for="year_start">Start Date</label>
                    <input type="date" id="year_start" name="start_date" required
                           value="<?= Sanitize::attr($editYear['start_date'] ?? '') ?>">
                </div>
                <div>
                    <label for="year_end">End Date</label>
                    <input type="date" id="year_end" name="end_date" required
                           value="<?= Sanitize::attr($editYear['end_date'] ?? '') ?>">
                </div>
            </div>
            <button type="submit"><?= $editYear ? 'Save Changes' : 'Add Financial Year' ?></button>
            <?php if ($editYear): ?><a class="btn" style="background:#888; text-decoration:none; display:inline-block;" href="/admin/membership-setup.php">Cancel</a><?php endif; ?>
        </form>
    </div>

    <div class="panel">
        <h2>Financial Years (<?= count($membershipYears) ?>)</h2>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Year</th><th>Period</th><th>Fee</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($membershipYears as $y): ?>
                <tr>
                    <td data-label="Year"><?= Sanitize::html($y['financial_year']) ?></td>
                    <td data-label="Period"><?= Sanitize::html($y['start_date']) ?> &ndash; <?= Sanitize::html($y['end_date']) ?></td>
                    <td data-label="Fee">&#8377;<?= Sanitize::html(number_format((float) $y['fee_amount'], 2)) ?></td>
                    <td data-label="Status"><span class="badge <?= Sanitize::html($y['status']) ?>"><?= Sanitize::html($y['status']) ?></span></td>
                    <td data-label="Actions" class="actions"><a href="/admin/membership-setup.php?edit_year=<?= (int) $y['id'] ?>">Edit</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($membershipYears)): ?><tr><td colspan="5">No financial years added yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';

