<?php
/**
 * KSPDOWA — Admin: Donations Management
 * ============================================================
 * Gated by RBAC permission 'donations.view'.
 * Lists all donations with search and filter functionality.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();

$currentUserId = Auth::getCurrentUserId();
RBAC::requirePermission($currentUserId, 'donations', 'view');

$search = trim(Sanitize::string($_GET['q'] ?? '', 100));
$status = Sanitize::string($_GET['status'] ?? '', 20);

$params = [];
$whereClause = "1=1";

if ($search !== '') {
    $whereClause .= " AND (d.donor_name LIKE ? OR d.purpose LIKE ? OR d.receipt_no LIKE ? OR d.gateway_payment_id LIKE ?)";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like, $like];
}

if ($status !== '' && in_array($status, ['pending', 'completed', 'failed'], true)) {
    $whereClause .= " AND d.status = ?";
    $params[] = $status;
}

$sql = "SELECT d.* 
        FROM donations d 
        WHERE {$whereClause} 
        ORDER BY d.created_at DESC";

$donations = Database::fetchAll($sql, $params);

$pageTitle   = 'Donations';
$activeMenu  = 'donations';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Finance', 'url' => '/admin/payments.php'],
    ['label' => 'Donations', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>
<style>
    .row { display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end; }
    .row > div { flex: 1; min-width: 200px; }
    .actions a {
        font-size: 0.8rem; margin-right: 10px; background: none; color: #1a3a6b;
        border: none; padding: 0; font-weight: 600; cursor: pointer; text-decoration: underline;
    }
</style>
    <div class="panel">
        <h2>Search &amp; Filter</h2>
        <form method="get" action="/admin/donations.php">
            <div class="row">
                <div style="flex: 2;">
                    <label for="q">Search (Name, Purpose, Receipt, Payment Ref)</label>
                    <input type="text" id="q" name="q" value="<?= Sanitize::html($search) ?>">
                </div>
                <div>
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All</option>
                        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Failed</option>
                    </select>
                </div>
                <div style="flex: 0 0 auto;">
                    <button type="submit">Filter</button>
                    <a href="/admin/donations.php" class="btn" style="background:#eef2f9; color:#1a3a6b; margin-left:8px;">Clear</a>
                </div>
            </div>
        </form>
    </div>

    <div class="panel">
        <h2>Donations (<?= count($donations) ?>)</h2>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Donor Name</th>
                    <th>Purpose</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Payment Ref</th>
                    <th>Receipt No</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($donations as $d): ?>
                <tr>
                    <td data-label="Date">
                        <?= Sanitize::html(date('d M Y, H:i', strtotime((string) $d['created_at']))) ?>
                    </td>
                    <td data-label="Donor Name">
                        <strong><?= Sanitize::html($d['donor_name']) ?></strong>
                    </td>
                    <td data-label="Purpose">
                        <?= Sanitize::html($d['purpose'] ?: 'General Donation') ?>
                    </td>
                    <td data-label="Amount">
                        Rs. <?= number_format((float) $d['amount'], 2) ?>
                    </td>
                    <td data-label="Status">
                        <span class="badge <?= Sanitize::html($d['status']) ?>"><?= Sanitize::html($d['status']) ?></span>
                    </td>
                    <td data-label="Payment Ref">
                        <?= Sanitize::html($d['gateway_payment_id'] ?? '—') ?>
                    </td>
                    <td data-label="Receipt No">
                        <?= Sanitize::html($d['receipt_no'] ?? '—') ?>
                    </td>
                    <td data-label="Actions" class="actions">
                        <?php if ($d['status'] === 'completed' && !empty($d['receipt_file_path'])): ?>
                            <a href="/admin/donation-receipt.php?id=<?= (int) $d['id'] ?>" target="_blank">Download Receipt</a>
                        <?php else: ?>
                            <span class="hint">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($donations)): ?>
                <tr><td colspan="8">No donations found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
