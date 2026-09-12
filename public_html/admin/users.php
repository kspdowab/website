<?php
/**
 * KSPDOWA — Admin: Users & Roles Management
 * ============================================================
 * Gated by RBAC permission 'users.manage' (seeded in Phase 0 —
 * see seeds/001_roles_permissions.sql). Only State Super Admin
 * holds this permission by default.
 *
 * Scope of this page (Phase 2 / RBAC unit):
 *   - Create non-member officer/admin accounts (users.member_id
 *     stays NULL — "NULL for non-member admin accounts" per
 *     migrations/002_core_users.sql). This is the same account
 *     shape scripts/bootstrap_admin.php already creates; this page
 *     just makes it possible without shell access to the server.
 *   - Assign / revoke roles via the existing roles/user_roles
 *     tables (migrations/003_rbac.sql). No new tables, no new
 *     columns — pure use of already-approved RBAC schema.
 *   - Toggle a user between active/inactive. 'pending' and
 *     'locked' statuses are left alone here because they belong to
 *     the not-yet-built self-service Registration / login-lockout
 *     workflows (docs explicitly warn against inventing missing
 *     association rules) — this page must not guess at those.
 *
 * Explicitly OUT of scope here (do not add without a spec/approval):
 *   - Member self-registration (Phase 2 "Registration") — the
 *     approved docs do not define an approval workflow for it, and
 *     the `pending` user status strongly implies one is needed.
 *     Inventing one would violate 09/10's "do not invent missing
 *     association rules."
 *   - Any new database table/column (e.g. password reset tokens —
 *     no such table exists in 02_DATABASE_SCHEMA.md or any
 *     migration; adding one here would be an unapproved schema
 *     change).
 *
 * District/Taluk-scoped roles require an `association_units` row
 * of the matching type. That table is currently empty (it is
 * derived from districts/taluks, which are deliberately empty per
 * migrations/001_geography.sql). Until geography is imported, only
 * state-scope roles can actually be assigned — this page degrades
 * gracefully the same way admin/office-bearers.php does.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();

$currentUserId = Auth::getCurrentUserId();
RBAC::requirePermission($currentUserId, 'users', 'manage');

function generateStrongPassword(int $length = 14): string
{
    $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; // no I/O to avoid visual ambiguity
    $lower   = 'abcdefghijkmnpqrstuvwxyz';
    $digits  = '23456789';
    $symbols = '!@#%*-_+=';
    $all     = $upper . $lower . $digits . $symbols;

    $password = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
    ];
    for ($i = count($password); $i < $length; $i++) {
        $password[] = $all[random_int(0, strlen($all) - 1)];
    }
    shuffle($password);
    return implode('', $password);
}

$successMsg   = Session::getFlash('success');
$errorMsg     = Session::getFlash('error');
$generatedPwd = Session::getFlash('generated_password');
$generatedFor = Session::getFlash('generated_for');

// ---------------------------------------------------------------------------
// POST: create_user / assign_role / revoke_role / toggle_status
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();

    $action = Sanitize::string($_POST['action'] ?? '', 30);

    if ($action === 'create_user') {
        $username = trim(Sanitize::string($_POST['username'] ?? '', 100));
        $email    = Sanitize::email($_POST['email'] ?? '');
        $mobile   = Sanitize::mobile($_POST['mobile'] ?? '');

        $errors = [];
        if ($username === '') {
            $username = null;
        }
        if ($email === false && $mobile === false) {
            $errors[] = 'Provide at least a valid email or a valid mobile number.';
        }

        if ($email !== false) {
            $exists = Database::fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
            if ($exists) {
                $errors[] = 'A user with this email already exists.';
            }
        }
        if ($mobile !== false) {
            $exists = Database::fetchOne('SELECT id FROM users WHERE mobile = ?', [$mobile]);
            if ($exists) {
                $errors[] = 'A user with this mobile number already exists.';
            }
        }

        if (!empty($errors)) {
            Session::flash('error', implode(' ', $errors));
        } else {
            $plainPassword = generateStrongPassword(14);
            $hash = Auth::hashPassword($plainPassword);

            Database::execute(
                'INSERT INTO users (member_id, username, email, mobile, password_hash, status)
                 VALUES (NULL, ?, ?, ?, ?, ?)',
                [$username, $email !== false ? $email : null, $mobile !== false ? $mobile : null, $hash, 'active']
            );
            $newUserId = (int) Database::lastInsertId();

            AuditLogger::log('CREATE', 'users', $newUserId, null, [
                'username' => $username,
                'email'    => $email !== false ? $email : null,
                'mobile'   => $mobile !== false ? $mobile : null,
                'status'   => 'active',
            ]);

            Session::flash('success', 'Officer/admin account created. Assign a role below to grant access.');
            Session::flash('generated_password', $plainPassword);
            Session::flash('generated_for', $email !== false ? $email : ($mobile !== false ? $mobile : $username));
        }

        header('Location: /admin/users.php');
        exit;
    }

    if ($action === 'assign_role') {
        $userId = Sanitize::positiveInt($_POST['user_id'] ?? null);
        $roleId = Sanitize::positiveInt($_POST['role_id'] ?? null);
        $unitId = Sanitize::positiveInt($_POST['association_unit_id'] ?? null);
        if ($unitId === false) {
            $unitId = null;
        }

        if ($userId === false || $roleId === false) {
            Session::flash('error', 'Invalid user or role reference.');
        } else {
            $role = Database::fetchOne('SELECT * FROM roles WHERE id = ?', [$roleId]);
            $user = Database::fetchOne('SELECT id FROM users WHERE id = ?', [$userId]);

            if (!$role || !$user) {
                Session::flash('error', 'User or role not found.');
            } elseif (in_array($role['scope_type'], ['district', 'taluk'], true)) {
                $unit = $unitId !== null
                    ? Database::fetchOne(
                        'SELECT * FROM association_units WHERE id = ? AND unit_type = ?',
                        [$unitId, $role['scope_type']]
                      )
                    : null;

                if (!$unit) {
                    Session::flash('error',
                        ucfirst($role['scope_type']) . ' association units are not set up yet, '
                        . 'so a "' . $role['name'] . '" role cannot be assigned to a specific unit. '
                        . 'Geography/association-unit master data needs to be imported first.'
                    );
                    header('Location: /admin/users.php');
                    exit;
                }

                $existing = Database::fetchOne(
                    'SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ?',
                    [$userId, $roleId]
                );
                if ($existing) {
                    Session::flash('error', 'This user already holds that role.');
                } else {
                    Database::execute(
                        'INSERT INTO user_roles (user_id, role_id, association_unit_id) VALUES (?, ?, ?)',
                        [$userId, $roleId, $unitId]
                    );
                    AuditLogger::log('CREATE', 'user_roles', $userId, null, [
                        'role_id' => $roleId, 'role_name' => $role['name'], 'association_unit_id' => $unitId,
                    ]);
                    Session::flash('success', 'Role "' . $role['name'] . '" assigned.');
                }
            } else {
                $existing = Database::fetchOne(
                    'SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ?',
                    [$userId, $roleId]
                );
                if ($existing) {
                    Session::flash('error', 'This user already holds that role.');
                } else {
                    Database::execute(
                        'INSERT INTO user_roles (user_id, role_id, association_unit_id) VALUES (?, ?, NULL)',
                        [$userId, $roleId]
                    );
                    AuditLogger::log('CREATE', 'user_roles', $userId, null, [
                        'role_id' => $roleId, 'role_name' => $role['name'], 'association_unit_id' => null,
                    ]);
                    Session::flash('success', 'Role "' . $role['name'] . '" assigned.');
                }
            }
        }

        header('Location: /admin/users.php');
        exit;
    }

    if ($action === 'revoke_role') {
        $userId = Sanitize::positiveInt($_POST['user_id'] ?? null);
        $roleId = Sanitize::positiveInt($_POST['role_id'] ?? null);

        if ($userId === false || $roleId === false) {
            Session::flash('error', 'Invalid user or role reference.');
        } elseif ($userId === $currentUserId) {
            Session::flash('error', 'You cannot change your own role assignments from here.');
        } else {
            $role = Database::fetchOne('SELECT name FROM roles WHERE id = ?', [$roleId]);
            Database::execute('DELETE FROM user_roles WHERE user_id = ? AND role_id = ?', [$userId, $roleId]);
            AuditLogger::log('DELETE', 'user_roles', $userId, [
                'role_id' => $roleId, 'role_name' => $role['name'] ?? null,
            ], null);
            Session::flash('success', 'Role revoked.');
        }

        header('Location: /admin/users.php');
        exit;
    }

    if ($action === 'toggle_status') {
        $userId = Sanitize::positiveInt($_POST['user_id'] ?? null);

        if ($userId === false) {
            Session::flash('error', 'Invalid user reference.');
        } elseif ($userId === $currentUserId) {
            Session::flash('error', 'You cannot deactivate your own account.');
        } else {
            $existing = Database::fetchOne('SELECT * FROM users WHERE id = ?', [$userId]);
            if (!$existing || !in_array($existing['status'], ['active', 'inactive'], true)) {
                Session::flash('error', 'This account status cannot be toggled here.');
            } else {
                $newStatus = $existing['status'] === 'active' ? 'inactive' : 'active';
                Database::execute('UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?', [$newStatus, $userId]);
                AuditLogger::log('UPDATE', 'users', $userId, ['status' => $existing['status']], ['status' => $newStatus]);
                Session::flash('success', 'Status updated.');
            }
        }

        header('Location: /admin/users.php');
        exit;
    }
}

// ---------------------------------------------------------------------------
// Load data for display
// ---------------------------------------------------------------------------
$users = Database::fetchAll(
    "SELECT u.id, u.username, u.email, u.mobile, u.status, u.last_login_at, m.name AS member_name
     FROM users u
     LEFT JOIN members m ON m.id = u.member_id
     ORDER BY u.created_at DESC"
);

$rolesByUser = [];
$roleRows = Database::fetchAll(
    "SELECT ur.user_id, r.id AS role_id, r.name, r.scope_type, au.name AS unit_name
     FROM user_roles ur
     JOIN roles r ON r.id = ur.role_id
     LEFT JOIN association_units au ON au.id = ur.association_unit_id
     ORDER BY r.scope_type, r.name"
);
foreach ($roleRows as $rr) {
    $rolesByUser[(int) $rr['user_id']][] = $rr;
}

$roles = Database::fetchAll("SELECT * FROM roles WHERE status = 'active' ORDER BY scope_type, name");
$associationUnits = Database::fetchAll(
    "SELECT * FROM association_units WHERE status = 'active' ORDER BY unit_type, name"
);
$unitsByType = ['district' => [], 'taluk' => []];
foreach ($associationUnits as $au) {
    if (isset($unitsByType[$au['unit_type']])) {
        $unitsByType[$au['unit_type']][] = $au;
    }
}

$pageTitle = 'Users & Roles';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users &amp; Roles — Admin — <?= Sanitize::html(APP_SHORT_NAME) ?></title>
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
        header nav a { color: #cfe0ff; text-decoration: none; font-size: 0.85rem; margin-left: 14px; }
        header nav a.active { color: #fff; font-weight: 700; text-decoration: underline; }
        main { max-width: 1040px; margin: 24px auto; padding: 0 16px; }
        .panel {
            background: #fff; border-radius: 8px; box-shadow: 0 1px 6px rgba(26,58,107,0.08);
            padding: 20px; margin-bottom: 24px;
        }
        .panel h2 { font-size: 1rem; color: #1a3a6b; margin: 0 0 16px; }
        .msg { padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 16px; }
        .msg.success { background: #e7f6ec; color: #1e6b3a; border: 1px solid #b9e5c6; }
        .msg.error   { background: #fdecea; color: #a12622; border: 1px solid #f5c2be; }
        .msg.notice  { background: #fff8e1; color: #7a5c00; border: 1px solid #f2dd9a; }
        .msg code { background: rgba(0,0,0,0.06); padding: 2px 6px; border-radius: 4px; font-size: 0.95em; }
        label { display: block; font-size: 0.8rem; font-weight: 600; color: #33415c; margin: 12px 0 4px; }
        input[type="text"], input[type="email"], select {
            width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;
        }
        .row { display: flex; gap: 16px; flex-wrap: wrap; }
        .row > div { flex: 1; min-width: 200px; }
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
        .badge.locked, .badge.pending { background: #fdecea; color: #a12622; }
        .role-chip {
            display: inline-flex; align-items: center; gap: 6px; background: #eef2f9; color: #1a3a6b;
            border-radius: 12px; padding: 2px 4px 2px 10px; font-size: 0.75rem; margin: 2px 4px 2px 0;
        }
        .role-chip form { display: inline; }
        .role-chip button {
            all: unset; cursor: pointer; color: #a12622; font-weight: 700; padding: 0 6px; margin: 0;
        }
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
    <h1><?= Sanitize::html(APP_SHORT_NAME) ?> — Users &amp; Roles</h1>
    <nav>
        <a href="/admin/office-bearers.php">Office Bearers</a>
        <a href="/admin/members.php">Members</a>
        <a href="/admin/news.php">News</a>
        <a href="/admin/users.php" class="active">Users &amp; Roles</a>
        <a href="/logout.php">Logout</a>
    </nav>
</header>
<main>

    <?php if ($successMsg): ?>
        <div class="msg success" role="status"><?= Sanitize::html($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="msg error" role="alert"><?= Sanitize::html($errorMsg) ?></div>
    <?php endif; ?>
    <?php if ($generatedPwd): ?>
        <div class="msg notice" role="alert">
            Account created for <strong><?= Sanitize::html((string) $generatedFor) ?></strong>.
            Temporary password (shown once — it is not recoverable, only resettable):
            <br><code><?= Sanitize::html($generatedPwd) ?></code>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Create Officer/Admin Account</h2>
        <p class="hint">
            Creates a login-only account (not a member record) — the same shape as
            scripts/bootstrap_admin.php creates. Assign a role to it below to grant
            any actual access; a new account has no permissions until a role is assigned.
        </p>
        <form method="post" action="/admin/users.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="create_user">
            <div class="row">
                <div>
                    <label for="username">Username — optional</label>
                    <input type="text" id="username" name="username" maxlength="100">
                </div>
                <div>
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" maxlength="190">
                </div>
                <div>
                    <label for="mobile">Mobile</label>
                    <input type="text" id="mobile" name="mobile" maxlength="20">
                </div>
            </div>
            <p class="hint">Provide at least an email or a mobile number. A strong random password is generated automatically.</p>
            <button type="submit">Create Account</button>
        </form>
    </div>

    <div class="panel">
        <h2>Assign Role</h2>
        <?php if (empty($unitsByType['district']) && empty($unitsByType['taluk'])): ?>
            <div class="msg notice" role="alert">
                District/Taluk association units are not set up yet, so only State-scope
                and Member-scope roles can be assigned right now. This depends on the
                geography (districts/taluks) master data being imported first.
            </div>
        <?php endif; ?>
        <form method="post" action="/admin/users.php" id="assign-role-form">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="assign_role">
            <div class="row">
                <div>
                    <label for="user_id">User</label>
                    <select id="user_id" name="user_id" required>
                        <option value="">— Select user —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>">
                                <?= Sanitize::html($u['username'] ?? ($u['email'] ?? $u['mobile'] ?? ('User #' . $u['id']))) ?>
                                <?= $u['member_name'] ? ' (' . Sanitize::html($u['member_name']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="role_id">Role</label>
                    <select id="role_id" name="role_id" required onchange="onRoleChange()">
                        <option value="">— Select role —</option>
                        <?php foreach ($roles as $r): ?>
                            <?php $needsUnit = in_array($r['scope_type'], ['district', 'taluk'], true); ?>
                            <?php $unitAvailable = $needsUnit ? !empty($unitsByType[$r['scope_type']]) : true; ?>
                            <option value="<?= (int) $r['id'] ?>"
                                data-scope="<?= Sanitize::attr($r['scope_type']) ?>"
                                <?= (!$unitAvailable) ? 'disabled' : '' ?>>
                                <?= Sanitize::html($r['name']) ?> (<?= Sanitize::html(ucfirst($r['scope_type'])) ?>)
                                <?= (!$unitAvailable) ? ' — unit master data not imported' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="unit-field" style="display:none;">
                    <label for="association_unit_id">District/Taluk Unit</label>
                    <select id="association_unit_id" name="association_unit_id">
                        <option value="">— Select unit —</option>
                        <?php foreach (['district', 'taluk'] as $t): ?>
                            <?php foreach ($unitsByType[$t] as $au): ?>
                                <option value="<?= (int) $au['id'] ?>" data-scope="<?= $t ?>">
                                    <?= Sanitize::html($au['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit">Assign Role</button>
        </form>
    </div>

    <div class="panel">
        <h2>Users (<?= count($users) ?>)</h2>
        <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Identifier</th><th>Linked Member</th><th>Roles</th><th>Status</th><th>Last Login</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td data-label="Identifier">
                        <?= Sanitize::html($u['username'] ?? '—') ?>
                        <?php if ($u['email']): ?><div class="hint"><?= Sanitize::html($u['email']) ?></div><?php endif; ?>
                        <?php if ($u['mobile']): ?><div class="hint"><?= Sanitize::html($u['mobile']) ?></div><?php endif; ?>
                    </td>
                    <td data-label="Linked Member"><?= Sanitize::html($u['member_name'] ?? '—') ?></td>
                    <td data-label="Roles">
                        <?php if (empty($rolesByUser[(int) $u['id']])): ?>
                            <span class="hint">No roles assigned</span>
                        <?php else: ?>
                            <?php foreach ($rolesByUser[(int) $u['id']] as $ur): ?>
                                <span class="role-chip">
                                    <?= Sanitize::html($ur['name']) ?><?= $ur['unit_name'] ? ' — ' . Sanitize::html($ur['unit_name']) : '' ?>
                                    <?php if ((int) $u['id'] !== $currentUserId): ?>
                                    <form method="post" action="/admin/users.php">
                                        <?= CSRF::htmlField() ?>
                                        <input type="hidden" name="action" value="revoke_role">
                                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                        <input type="hidden" name="role_id" value="<?= (int) $ur['role_id'] ?>">
                                        <button type="submit" title="Revoke">&times;</button>
                                    </form>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Status">
                        <span class="badge <?= Sanitize::html($u['status']) ?>"><?= Sanitize::html($u['status']) ?></span>
                    </td>
                    <td data-label="Last Login"><?= Sanitize::html($u['last_login_at'] ?? 'Never') ?></td>
                    <td data-label="Actions" class="actions">
                        <?php if ((int) $u['id'] !== $currentUserId && in_array($u['status'], ['active', 'inactive'], true)): ?>
                        <form method="post" action="/admin/users.php">
                            <?= CSRF::htmlField() ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                            <button type="submit" class="link">
                                <?= $u['status'] === 'active' ? 'Deactivate' : 'Reactivate' ?>
                            </button>
                        </form>
                        <?php else: ?>
                            <span class="hint">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                <tr><td colspan="6">No users found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</main>
<script>
function onRoleChange() {
    var sel = document.getElementById('role_id');
    var opt = sel.options[sel.selectedIndex];
    var scope = opt ? opt.getAttribute('data-scope') : '';
    var unitField = document.getElementById('unit-field');
    var unitSelect = document.getElementById('association_unit_id');
    if (scope === 'district' || scope === 'taluk') {
        unitField.style.display = 'block';
        Array.prototype.forEach.call(unitSelect.options, function (o) {
            if (!o.value) { o.hidden = false; return; }
            o.hidden = (o.getAttribute('data-scope') !== scope);
        });
    } else {
        unitField.style.display = 'none';
    }
}
</script>
</body>
</html>
