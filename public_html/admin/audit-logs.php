<?php
/**
 * KSPDOWA — Admin: Audit Logs Viewer
 * ============================================================
 * Gated by RBAC permission 'audit_logs.view'.
 * Immutable audit logs inspection.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

RBAC::requirePermission($currentUserId, 'audit_logs', 'view');

// Filters
$qAction = trim(Sanitize::string($_GET['action_name'] ?? '', 50));
$qSearch = trim(Sanitize::string($_GET['q'] ?? '', 100));

$whereClauses = ["1=1"];
$params       = [];

if ($qAction !== '') {
    $whereClauses[] = "al.action = ?";
    $params[]       = $qAction;
}

if ($qSearch !== '') {
    $whereClauses[] = "(al.entity_type LIKE ? OR al.ip_address LIKE ? OR u.username LIKE ?)";
    $like = '%' . $qSearch . '%';
    array_push($params, $like, $like, $like);
}

$whereSql = implode(' AND ', $whereClauses);

$sql = "
    SELECT al.*, u.username
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.user_id
    WHERE {$whereSql}
    ORDER BY al.created_at DESC
    LIMIT 100
";
$logs = Database::fetchAll($sql, $params);

$actionsList = Database::fetchAll("SELECT DISTINCT action FROM audit_logs ORDER BY action");

$pageTitle  = 'Audit Logs';
$activeMenu = 'audit_logs';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Audit Logs', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">System Audit Logs</h1>
        <p class="page-heading-subtitle">Immutable security and administrative event ledger</p>
    </div>
    <div>
        <span class="badge badge-purple" style="font-size:0.86rem; padding:8px 16px;">
            <?= count($logs) ?> Recent Audit Events
        </span>
    </div>
</div>

<div class="filter-card">
    <div class="filter-header-bar">
        <div class="filter-header-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
            Filter Audit Logs
        </div>
        <div class="filter-header-actions">
            <a href="/admin/audit-logs.php" class="filter-header-btn">↺ Reset</a>
        </div>
    </div>
    <form method="get" action="/admin/audit-logs.php" class="filter-body">
        <div class="filter-grid">
            <div class="form-group">
                <label class="form-label">Action</label>
                <select name="action_name" class="form-select">
                    <option value="">All Actions</option>
                    <?php foreach ($actionsList as $act): ?>
                        <option value="<?= Sanitize::attr($act['action']) ?>" <?= $qAction === $act['action'] ? 'selected' : '' ?>>
                            <?= Sanitize::html($act['action']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="grid-column: span 2;">
                <label class="form-label">Search</label>
                <div class="input-with-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" name="q" class="form-control" placeholder="User, IP, Entity..." value="<?= Sanitize::attr($qSearch) ?>">
                </div>
            </div>
            <div class="form-group" style="display:flex; gap:10px;">
                <button type="submit" class="btn btn-primary" style="flex:1;">Search</button>
                <a href="/admin/audit-logs.php" class="btn btn-outline">Clear</a>
            </div>
        </div>
    </form>
</div>

<div class="table-card">
    <div class="table-card-header">
        <span class="table-card-title">Audit Ledger (Latest 100)</span>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Entity</th>
                    <th>Entity ID</th>
                    <th>IP Address</th>
                    <th>Metadata</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="8" style="text-align:center; padding:36px; color:var(--text-muted);">
                        No audit logs matching the filter criteria.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                    <tr>
                        <td style="color:var(--text-muted);"><?= (int)$l['id'] ?></td>
                        <td><?= date('d M Y, H:i:s', strtotime((string)$l['created_at'])) ?></td>
                        <td>
                            <strong><?= Sanitize::html($l['username'] ?? 'System') ?></strong>
                        </td>
                        <td>
                            <code style="background:var(--blue-50); color:var(--blue-700); padding:2px 6px; border-radius:4px; font-weight:700;">
                                <?= Sanitize::html($l['action']) ?>
                            </code>
                        </td>
                        <td><?= Sanitize::html($l['entity_type'] ?? '—') ?></td>
                        <td><?= $l['entity_id'] ? (int)$l['entity_id'] : '—' ?></td>
                        <td><small style="color:var(--text-muted);"><?= Sanitize::html($l['ip_address'] ?? '—') ?></small></td>
                        <td style="max-width:280px; font-size:0.78rem; color:var(--text-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                            <?= Sanitize::html((string)($l['new_values'] ?? $l['old_values'] ?? '—')) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
