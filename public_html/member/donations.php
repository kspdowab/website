<?php
/**
 * KSPDOWA — Member Portal: My Donations
 * ============================================================
 * Allows members to view their voluntary donations history,
 * download 80G compliant donation receipts, and make new donations.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentMemberId = Auth::getCurrentMemberId();

if ($currentMemberId === null) {
    header('Location: /login.php');
    exit;
}

// Fetch member details
$member = Database::fetchOne('SELECT * FROM members WHERE id = ?', [$currentMemberId]);
$profile = Database::fetchOne('SELECT * FROM member_profiles WHERE member_id = ?', [$currentMemberId]) ?: [];
$user    = Database::fetchOne('SELECT * FROM users WHERE member_id = ?', [$currentMemberId]) ?: [];

$memberEmail  = $profile['personal_email'] ?? $user['email'] ?? '';
$memberMobile = $profile['personal_mobile'] ?? $user['mobile'] ?? '';

// Fetch all donations linked to this member
$donations = Database::fetchAll(
    "SELECT * FROM donations 
     WHERE member_id = ?
     ORDER BY paid_at DESC, id DESC",
    [$currentMemberId]
);

// Calculate totals
$totalDonated = 0.0;
$completedCount = 0;
$lastDonationDate = null;

foreach ($donations as $d) {
    if ($d['status'] === 'completed') {
        $totalDonated += (float)$d['amount'];
        $completedCount++;
        if ($lastDonationDate === null && !empty($d['paid_at'])) {
            $lastDonationDate = $d['paid_at'];
        }
    }
}

$pageTitle   = 'My Donations';
$activeMenu  = 'donations';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/member/index.php'],
    ['label' => 'My Membership', 'url' => '/member/membership.php'],
    ['label' => 'My Donations', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">My Donations &amp; Contributions (ಸ್ವಯಂಪ್ರೇರಿತ ದೇಣಿಗೆಗಳು)</h1>
        <p class="page-heading-subtitle">History of your voluntary contributions toward PDO Welfare Fund, legal corpus, and member welfare causes</p>
    </div>
    <div>
        <a href="/donate.php" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:8px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Make a New Donation
        </a>
    </div>
</div>

<!-- Summary Metric Cards -->
<div class="stats-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:20px; margin-bottom:24px;">
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <div style="font-size:0.8rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Total Contributed</div>
        <div style="font-size:1.8rem; font-weight:800; color:var(--blue-700); margin-top:8px;">
            ₹<?= number_format($totalDonated, 2) ?>
        </div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:4px;">Cumulative donations</div>
    </div>

    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <div style="font-size:0.8rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Completed Contributions</div>
        <div style="font-size:1.8rem; font-weight:800; color:#059669; margin-top:8px;">
            <?= $completedCount ?>
        </div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:4px;">Verified transactions</div>
    </div>

    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <div style="font-size:0.8rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Latest Donation Date</div>
        <div style="font-size:1.35rem; font-weight:700; color:var(--text-main); margin-top:12px;">
            <?= $lastDonationDate ? date('d M Y', strtotime($lastDonationDate)) : '—' ?>
        </div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:4px;">Official receipt issued</div>
    </div>
</div>

<!-- Donations History Table -->
<div class="table-card">
    <div class="table-card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="table-card-title">Donation History &amp; Official Receipts</span>
        <span class="badge badge-purple" style="font-size:0.8rem; padding:4px 10px;"><?= count($donations) ?> Records</span>
    </div>

    <?php if (empty($donations)): ?>
        <div style="text-align:center; padding:50px 20px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="#94a3b8" stroke-width="1.5" style="margin-bottom:12px;"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
            <h3 style="font-size:1.1rem; color:var(--text-main); margin-bottom:6px;">No Voluntary Donations Recorded Yet</h3>
            <p style="font-size:0.9rem; color:var(--text-muted); max-width:480px; margin:0 auto 18px;">Support fellow Panchayat Development Officers, legal aid initiatives, and statewide association welfare activities by making a voluntary contribution.</p>
            <a href="/donate.php" class="btn btn-primary btn-sm">Support the Association Welfare Fund</a>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Receipt No</th>
                        <th>Date</th>
                        <th>Cause / Purpose</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Transaction Ref</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($donations as $d): ?>
                        <tr>
                            <td>
                                <?php if (!empty($d['receipt_no'])): ?>
                                    <strong style="color:var(--blue-700); font-family:monospace;"><?= Sanitize::html($d['receipt_no']) ?></strong>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= !empty($d['paid_at']) ? date('d M Y, h:i A', strtotime($d['paid_at'])) : date('d M Y', strtotime($d['created_at'])) ?>
                            </td>
                            <td>
                                <strong><?= Sanitize::html($d['purpose'] ?: 'General Welfare Fund') ?></strong>
                            </td>
                            <td style="font-weight:700; color:var(--blue-700); font-size:0.95rem;">
                                ₹<?= number_format((float)$d['amount'], 2) ?>
                            </td>
                            <td>
                                <?php if ($d['status'] === 'completed'): ?>
                                    <span class="badge badge-success">✓ Completed</span>
                                <?php elseif ($d['status'] === 'pending'): ?>
                                    <span class="badge badge-warning">⏳ Pending</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">✕ <?= ucfirst(Sanitize::html($d['status'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code style="font-size:0.8rem; background:#f1f5f9; padding:2px 6px; border-radius:4px; color:#475569;">
                                    <?= Sanitize::html($d['gateway_payment_id'] ?: '—') ?>
                                </code>
                            </td>
                            <td style="text-align:right;">
                                <?php if ($d['status'] === 'completed'): ?>
                                    <a href="/donate-receipt.php?id=<?= (int)$d['id'] ?>" target="_blank" class="btn btn-outline btn-sm" style="display:inline-flex; align-items:center; gap:4px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        Receipt PDF
                                    </a>
                                <?php else: ?>
                                    <span style="color:var(--text-muted); font-size:0.8rem;">No receipt</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
