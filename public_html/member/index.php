<?php
/**
 * KSPDOWA — Member Portal Dashboard
 * ============================================================
 * Primary landing page for authenticated, current-year-eligible PDO members.
 * Implements Section 8 and UI Theme (Formax Pay / NEXUS styling):
 * - Blue/purple gradient welcome hero banner
 * - Membership and Fee Status cards
 * - Quick Action cards
 * - Detailed Membership Information card
 * - Latest Official Orders preview
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentMemberId = Auth::getCurrentMemberId();

if ($currentMemberId === null) {
    header('Location: /admin/index.php');
    exit;
}

$pageTitle  = 'Member Dashboard';
$activeMenu = 'dashboard';
$breadcrumbs = [
    ['label' => 'Member Portal', 'url' => '/member/index.php'],
    ['label' => 'Dashboard', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';

// Fetch Member Full Details
$memberProfile = Database::fetchOne('SELECT * FROM member_profiles WHERE member_id = ?', [$currentMemberId]);

// Recent Payments
$recentPayments = Database::fetchAll(
    "SELECT p.*, my.financial_year, pr.receipt_no
     FROM membership_payments p
     LEFT JOIN membership_years my ON my.id = p.membership_year_id
     LEFT JOIN payment_receipts pr ON pr.payment_id = p.id
     WHERE p.member_id = ? AND p.status = 'completed'
     ORDER BY p.paid_at DESC
     LIMIT 3",
    [$currentMemberId]
);

// Member Grievance Count
$grvStats = Database::fetchOne(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN current_status IN ('Resolved','Action Taken','Closed') THEN 1 ELSE 0 END) AS resolved
     FROM grievances WHERE member_id = ?",
    [$currentMemberId]
);
$totalGrv    = (int)($grvStats['total'] ?? 0);
$resolvedGrv = (int)($grvStats['resolved'] ?? 0);

// Member Suggestions Count
$totalSuggestions = 0;
try {
    $sugCountRow = Database::fetchOne("SELECT COUNT(*) AS total FROM suggestions WHERE member_id = ?", [$currentMemberId]);
    $totalSuggestions = (int)($sugCountRow['total'] ?? 0);
} catch (Throwable $e) {
    $totalSuggestions = 0;
}

// Total Orders & Circulars count
$totalOrders = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM orders WHERE access_level IN ('member','public')")['total'] ?? 0);
$totalCirculars = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM circulars WHERE access_level IN ('member','public')")['total'] ?? 0);
$totalOfficialDocs = $totalOrders + $totalCirculars;

// Latest Orders Preview
$latestOrders = Database::fetchAll(
    "SELECT o.id, o.title, o.order_no, o.order_date, o.document_id, dc.name AS category_name
     FROM orders o
     LEFT JOIN documents d ON d.id = o.document_id
     LEFT JOIN document_categories dc ON dc.id = d.category_id
     WHERE o.access_level IN ('member','public')
     ORDER BY o.order_date DESC, o.id DESC
     LIMIT 5"
);
?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     WELCOME HERO BANNER
     ═══════════════════════════════════════════════════════════════════════════ -->
<section class="welcome-hero-banner">
    <div class="hero-content">
        <h1 class="hero-title">Welcome, <?= Sanitize::html($portalMember['name'] ?? 'Member') ?>!</h1>
        <p class="hero-subtitle">
            Karnataka State Panchayat Development Officer Welfare Association (R), Bengaluru.
            Your digital gateway to official circulars, membership credentials, and association welfare services.
        </p>
        <div class="hero-meta-badges">
            <span class="hero-badge">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline-block; vertical-align:-2px;"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/></svg>
                Member No: <?= Sanitize::html($portalMember['member_no'] ?? '') ?>
            </span>
            <span class="hero-badge">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline-block; vertical-align:-2px;"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <?= Sanitize::html($portalMember['taluk_name'] ?? 'Taluk') ?>, <?= Sanitize::html($portalMember['district_name'] ?? 'District') ?>
            </span>
            <span class="hero-badge">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline-block; vertical-align:-2px;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Status: <?= Sanitize::html($currentYear['financial_year'] ?? '') ?> Verified
            </span>
        </div>
    </div>
    <div class="hero-visual-card">
        <div class="hero-visual-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
        </div>
        <div>
            <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; opacity:0.85;">Current FY</div>
            <div style="font-size:1.15rem; font-weight:700;">Active &amp; Eligible</div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════
     STAT CARDS
     ═══════════════════════════════════════════════════════════════════════════ -->
<section class="stats-grid">
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Membership Status</span>
            <span class="stat-number" style="color:var(--success-dark); font-size:1.6rem;">Active Member</span>
            <span class="stat-subtext">Registered with KSPDOWA</span>
        </div>
        <div class="stat-icon-box stat-icon-green">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Annual Fee (<?= Sanitize::html($currentYear['financial_year'] ?? '') ?>)</span>
            <span class="stat-number" style="color:var(--blue-700); font-size:1.6rem;">Verified</span>
            <span class="stat-subtext">Receipt issued &amp; cleared</span>
        </div>
        <div class="stat-icon-box stat-icon-blue">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Orders &amp; Circulars</span>
            <span class="stat-number" style="color:var(--purple-600);"><?= number_format($totalOfficialDocs) ?></span>
            <span class="stat-subtext">Available in repository</span>
        </div>
        <div class="stat-icon-box stat-icon-purple">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">My Grievances</span>
            <span class="stat-number"><?= $totalGrv ?></span>
            <span class="stat-subtext"><?= $resolvedGrv ?> resolved / closed</span>
        </div>
        <div class="stat-icon-box stat-icon-orange">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════
     QUICK ACTIONS (NEXUS / Formax Pay Style)
     ═══════════════════════════════════════════════════════════════════════════ -->
<section class="quick-actions-section">
    <h2 class="section-heading">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
        Quick Actions
    </h2>
    <div class="quick-actions-grid">
        <a href="/member/orders-circulars.php" class="quick-action-tile tile-blue">
            <div class="tile-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            </div>
            <div>
                <div class="tile-title">Orders &amp; Circulars</div>
                <div class="tile-desc">Search 9 categories</div>
            </div>
        </a>

        <a href="/member/id-card.php" class="quick-action-tile tile-green">
            <div class="tile-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/></svg>
            </div>
            <div>
                <div class="tile-title">Digital ID</div>
                <div class="tile-desc">Official ID card</div>
            </div>
        </a>

        <a href="/member/membership.php" class="quick-action-tile tile-purple">
            <div class="tile-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </div>
            <div>
                <div class="tile-title">My Membership</div>
                <div class="tile-desc">Dues, status &amp; receipts</div>
            </div>
        </a>

        <a href="/member/grievances.php" class="quick-action-tile tile-orange">
            <div class="tile-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
            </div>
            <div>
                <div class="tile-title">Submit Grievance</div>
                <div class="tile-desc">Track issues</div>
            </div>
        </a>

        <a href="/member/suggestions.php" class="quick-action-tile tile-purple">
            <div class="tile-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
            </div>
            <div>
                <div class="tile-title">Suggestions (<?= $totalSuggestions ?>)</div>
                <div class="tile-desc">Ideas &amp; proposals</div>
            </div>
        </a>

        <a href="/member/documents.php" class="quick-action-tile tile-cyan">
            <div class="tile-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg>
            </div>
            <div>
                <div class="tile-title">Documents</div>
                <div class="tile-desc">Rules &amp; publications</div>
            </div>
        </a>

        <a href="/member/profile.php" class="quick-action-tile tile-pink">
            <div class="tile-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            </div>
            <div>
                <div class="tile-title">My Profile</div>
                <div class="tile-desc">View details</div>
            </div>
        </a>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MEMBERSHIP DETAILS CARD
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card" style="margin-bottom:24px;">
    <div class="table-card-header">
        <span class="table-card-title">Membership Identification &amp; Posting Details</span>
        <a href="/member/profile.php" class="btn btn-outline btn-sm">Full Profile →</a>
    </div>
    <div style="padding:22px 24px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:18px;">
            <div>
                <div style="font-size:0.75rem; font-weight:600; color:var(--text-muted); text-transform:uppercase;">Member Number</div>
                <div style="font-size:1.05rem; font-weight:700; color:var(--blue-700); margin-top:2px;">
                    <?= Sanitize::html($portalMember['member_no'] ?? '') ?>
                </div>
            </div>

            <div>
                <div style="font-size:0.75rem; font-weight:600; color:var(--text-muted); text-transform:uppercase;">Official Designation</div>
                <div style="font-size:1.05rem; font-weight:700; color:var(--text-main); margin-top:2px;">
                    <?= Sanitize::html($portalMember['designation'] ?? 'Panchayat Development Officer') ?>
                </div>
            </div>

            <div>
                <div style="font-size:0.75rem; font-weight:600; color:var(--text-muted); text-transform:uppercase;">Membership District</div>
                <div style="font-size:1.05rem; font-weight:600; color:var(--text-main); margin-top:2px;">
                    <?= Sanitize::html($portalMember['district_name'] ?? '—') ?>
                </div>
            </div>

            <div>
                <div style="font-size:0.75rem; font-weight:600; color:var(--text-muted); text-transform:uppercase;">Membership Taluk</div>
                <div style="font-size:1.05rem; font-weight:600; color:var(--text-main); margin-top:2px;">
                    <?= Sanitize::html($portalMember['taluk_name'] ?? '—') ?>
                </div>
            </div>

            <div>
                <div style="font-size:0.75rem; font-weight:600; color:var(--text-muted); text-transform:uppercase;">Gram Panchayati</div>
                <div style="font-size:1.05rem; font-weight:600; color:var(--text-main); margin-top:2px;">
                    <?= Sanitize::html($portalMember['gp_name'] ?? '—') ?>
                </div>
            </div>

            <div>
                <div style="font-size:0.75rem; font-weight:600; color:var(--text-muted); text-transform:uppercase;">Eligibility Status</div>
                <div style="margin-top:4px;">
                    <span class="badge badge-success">Verified Active</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     LATEST OFFICIAL ORDERS PREVIEW
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">Recent Government Orders</span>
            <span class="table-card-count">(Latest Published)</span>
        </div>
        <a href="/member/orders-circulars.php" class="btn btn-outline btn-sm">View All Orders &amp; Circulars →</a>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Subject</th>
                    <th>Order No.</th>
                    <th>Date</th>
                    <th style="text-align:center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($latestOrders)): ?>
                <tr>
                    <td colspan="5" style="text-align:center; padding:32px; color:var(--text-muted);">
                        No orders currently available in the repository.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($latestOrders as $lo): ?>
                    <tr>
                        <td>
                            <span class="badge badge-purple"><?= Sanitize::html($lo['category_name'] ?? 'General') ?></span>
                        </td>
                        <td style="font-weight:600;"><?= Sanitize::html($lo['title']) ?></td>
                        <td>
                            <code style="background:var(--blue-50); color:var(--blue-700); padding:3px 7px; border-radius:4px;">
                                <?= Sanitize::html($lo['order_no']) ?>
                            </code>
                        </td>
                        <td><?= $lo['order_date'] ? date('d-m-Y', strtotime($lo['order_date'])) : '—' ?></td>
                        <td style="text-align:center;">
                            <?php if ($lo['document_id']): ?>
                                <a href="/document.php?id=<?= (int)$lo['document_id'] ?>" target="_blank" class="btn btn-primary btn-sm">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    View File
                                </a>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
