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

$pageTitle = 'Donations';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donations — Admin — <?= Sanitize::html(APP_SHORT_NAME) ?></title>
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
        main { max-width: 1200px; margin: 24px auto; padding: 0 16px; }
        .panel {
            background: #fff; border-radius: 8px; box-shadow: 0 1px 6px rgba(26,58,107,0.08);
            padding: 20px; margin-bottom: 24px;
        }
        .panel h2 { font-size: 1.1rem; color: #1a3a6b; margin: 0 0 16px; }
        label { display: block; font-size: 0.8rem; font-weight: 600; color: #33415c; margin: 12px 0 4px; }
        input[type="text"], select {
            width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;
        }
        .row { display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end; }
        .row > div { flex: 1; min-width: 200px; }
        button, .btn {
            background: #1a3a6b; color: #fff; border: none; border-radius: 6px;
            padding: 9px 18px; font-size: 0.9rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block;
        }
        button:hover, .btn:hover { background: #142c52; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th, td { text-align: left; padding: 10px; border-bottom: 1px solid #eef1f5; vertical-align: top; }
        th { color: #556; font-weight: 600; background: #f8fafc; border-bottom: 2px solid #e2e8f0; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.72rem; font-weight: 600; }
        .badge.completed { background: #e7f6ec; color: #1e6b3a; }
        .badge.failed   { background: #fdecea; color: #a12622; }
        .badge.pending  { background: #f1f2f4; color: #666; }
        .actions a {
            font-size: 0.8rem; margin-right: 10px; background: none; color: #1a3a6b;
            border: none; padding: 0; font-weight: 600; cursor: pointer; text-decoration: underline;
        }
        .table-wrap { overflow-x: auto; }
        .hint { font-size: 0.75rem; color: #888; margin-top: 4px; }
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
    <h1><?= Sanitize::html(APP_SHORT_NAME) ?> — Donations</h1>
    <nav>
        <a href="/admin/office-bearers.php">Office Bearers</a>
        <a href="/admin/members.php">Members</a>
        <a href="/admin/news.php">News</a>
        <a href="/admin/users.php">Users &amp; Roles</a>
        <a href="/admin/membership-setup.php">Membership Setup</a>
        <a href="/admin/donations.php" class="active">Donations</a>
        <a href="/admin/settings.php">Association Settings</a>
        <a href="/logout.php">Logout</a>
    </nav>
</header>
<main>
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
</main>
</body>
</html>
