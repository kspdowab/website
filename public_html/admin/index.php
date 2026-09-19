<?php
/**
 * KSPDOWA — Admin Dashboard
 * ============================================================
 * Primary landing page for authenticated Admins and Officers.
 * Displays role- and scope-aware statistics:
 * - State / Super Admin: Statewide scope
 * - District Officer: Own District scope
 * - Taluk Officer: Own Taluk scope
 * Server-side RBAC only (no is_admin).
 * Styled according to Formax Pay and NEXUS visual specifications.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

// ─── Scope Resolution (Strict Server-Side RBAC) ──────────────────────────────
$associationUnitId = RBAC::getUserAssociationUnit($currentUserId);
$lockedDistrictId  = null;
$lockedTalukId     = null;
$scopeDescription  = 'All Karnataka Districts (Statewide Scope)';

if ($associationUnitId) {
    $unit = Database::fetchOne("SELECT * FROM association_units WHERE id = ?", [$associationUnitId]);
    if ($unit) {
        if ($unit['unit_type'] === 'district') {
            $lockedDistrictId = (int)$unit['district_id'];
            $districtRow = Database::fetchOne("SELECT name FROM districts WHERE id = ?", [$lockedDistrictId]);
            $scopeDescription = ($districtRow['name'] ?? 'District') . ' District Scope';
        } elseif ($unit['unit_type'] === 'taluk') {
            $lockedDistrictId = (int)$unit['district_id'];
            $lockedTalukId    = (int)$unit['taluk_id'];
            $talukRow = Database::fetchOne("SELECT name FROM taluks WHERE id = ?", [$lockedTalukId]);
            $districtRow = Database::fetchOne("SELECT name FROM districts WHERE id = ?", [$lockedDistrictId]);
            $scopeDescription = ($talukRow['name'] ?? 'Taluk') . ' Taluk, ' . ($districtRow['name'] ?? '') . ' Scope';
        }
    }
}

// Build member scope WHERE clause
$scopeWhere  = ["1=1"];
$scopeParams = [];

if ($lockedTalukId) {
    $scopeWhere[]  = "m.taluk_id = ?";
    $scopeParams[] = $lockedTalukId;
} elseif ($lockedDistrictId) {
    $scopeWhere[]  = "m.district_id = ?";
    $scopeParams[] = $lockedDistrictId;
}
$scopeSql = implode(' AND ', $scopeWhere);

// ─── Current Financial Year ──────────────────────────────────────────────────
$currentYear = Membership::getCurrentYear();
$currentFyId = $currentYear ? (int)$currentYear['id'] : 0;
$fyLabel     = $currentYear['financial_year'] ?? 'Current FY';
$feeAmount   = (float)($currentYear['fee_amount'] ?? 0);

// ─── Role/Scope-Aware Statistics ─────────────────────────────────────────────
// 1. Total Members
$rowTotal = Database::fetchOne("SELECT COUNT(*) AS total FROM members m WHERE {$scopeSql}", $scopeParams);
$totalMembers = (int)($rowTotal['total'] ?? 0);

// 2. Active Members
$rowActive = Database::fetchOne("SELECT COUNT(*) AS total FROM members m WHERE {$scopeSql} AND m.membership_status = 'active'", $scopeParams);
$activeMembers = (int)($rowActive['total'] ?? 0);

// 3. Current FY Paid Members
$paidParams = array_merge([$currentFyId], $scopeParams);
$rowPaid = Database::fetchOne(
    "SELECT COUNT(DISTINCT m.id) AS total, COALESCE(SUM(p.amount), 0) AS total_amount
     FROM members m
     INNER JOIN membership_payments p ON p.member_id = m.id AND p.membership_year_id = ? AND p.status = 'completed'
     WHERE {$scopeSql}",
    $paidParams
);
$paidMembers = (int)($rowPaid['total'] ?? 0);
$paidAmount  = (float)($rowPaid['total_amount'] ?? 0);

// 4. Current FY Unpaid Members
$unpaidMembers = max(0, $totalMembers - $paidMembers);
$unpaidEstAmount = $unpaidMembers * $feeAmount;

// 5. Relevant Pending Items
// Pending Grievances
$pendingGrievanceParams = [];
$grvWhere = ["g.current_status IN ('Submitted', 'Under Verification', 'Under Review', 'Pending', 'Forwarded')"];
if ($lockedTalukId) {
    $grvWhere[] = "m.taluk_id = ?";
    $pendingGrievanceParams[] = $lockedTalukId;
} elseif ($lockedDistrictId) {
    $grvWhere[] = "m.district_id = ?";
    $pendingGrievanceParams[] = $lockedDistrictId;
}
$grvSql = implode(' AND ', $grvWhere);
$rowGrv = Database::fetchOne(
    "SELECT COUNT(*) AS total FROM grievances g
     INNER JOIN members m ON m.id = g.member_id
     WHERE {$grvSql}",
    $pendingGrievanceParams
);
$pendingGrievancesCount = (int)($rowGrv['total'] ?? 0);

// Suggestions Stats (Scope Aware §28.11)
$canViewSuggestions = admin_can($currentUserId, 'suggestions', 'view');
$sugTotal           = 0;
$sugSubmitted       = 0;
$sugUnderReview     = 0;
$sugPendingResponse = 0;

if ($canViewSuggestions) {
    try {
        $sugWhere = ["1=1"];
        $sugParams = [];
        if ($lockedTalukId) {
            $sugWhere[]  = "m.taluk_id = ?";
            $sugParams[] = $lockedTalukId;
        } elseif ($lockedDistrictId) {
            $sugWhere[]  = "m.district_id = ?";
            $sugParams[] = $lockedDistrictId;
        }
        $sugSql = implode(' AND ', $sugWhere);

        $sugRow = Database::fetchOne(
            "SELECT COUNT(*) AS total_all,
                    SUM(CASE WHEN s.current_status = 'Submitted' THEN 1 ELSE 0 END) AS count_submitted,
                    SUM(CASE WHEN s.current_status IN ('Under Review', 'Under Consideration') THEN 1 ELSE 0 END) AS count_review,
                    SUM(CASE WHEN s.association_response IS NULL OR s.association_response = '' THEN 1 ELSE 0 END) AS count_pending_resp
             FROM suggestions s
             INNER JOIN members m ON m.id = s.member_id
             WHERE {$sugSql}",
            $sugParams
        );
        if ($sugRow) {
            $sugTotal           = (int)($sugRow['total_all'] ?? 0);
            $sugSubmitted       = (int)($sugRow['count_submitted'] ?? 0);
            $sugUnderReview     = (int)($sugRow['count_review'] ?? 0);
            $sugPendingResponse = (int)($sugRow['count_pending_resp'] ?? 0);
        }
    } catch (Throwable $e) {
        // Defensive fallback
    }
}

// Content counts
$totalOrders = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM orders")['total'] ?? 0);
$totalCirculars = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM circulars")['total'] ?? 0);
$totalDocuments = (int)(Database::fetchOne("SELECT COUNT(*) AS total FROM documents WHERE status = 'active'")['total'] ?? 0);

// ─── Scope-Specific Administrative Breakdown Dataset ────────────────────────
$districtBreakdowns = [];
$talukBreakdowns    = [];
$talukGpStats       = [];

if ($lockedTalukId) {
    // Taluk Administration: Gram Panchayat level coverage summary
    $talukGpStats = Database::fetchAll(
        "SELECT gp.id, gp.name AS gp_name, gp.code AS gp_code,
                COUNT(DISTINCT m.id) AS total_members,
                COUNT(DISTINCT CASE WHEN m.membership_status = 'active' THEN m.id END) AS active_members,
                COUNT(DISTINCT CASE WHEN p.id IS NOT NULL THEN m.id END) AS paid_members,
                COUNT(DISTINCT m.id) - COUNT(DISTINCT CASE WHEN p.id IS NOT NULL THEN m.id END) AS unpaid_members,
                COALESCE(grv.pending_count, 0) AS pending_grievances
         FROM gram_panchayatis gp
         LEFT JOIN members m ON m.gp_id = gp.id
         LEFT JOIN membership_payments p ON p.member_id = m.id AND p.membership_year_id = ? AND p.status = 'completed'
         LEFT JOIN (
             SELECT m2.gp_id, COUNT(g.id) AS pending_count
             FROM grievances g
             JOIN members m2 ON m2.id = g.member_id
             WHERE g.current_status IN ('Submitted', 'Under Verification', 'Under Review', 'Pending', 'Forwarded')
             GROUP BY m2.gp_id
         ) grv ON grv.gp_id = gp.id
         WHERE gp.taluk_id = ?
         GROUP BY gp.id, gp.name, gp.code, grv.pending_count
         ORDER BY total_members DESC, gp.name ASC",
        [$currentFyId, $lockedTalukId]
    );
} elseif ($lockedDistrictId) {
    // District Administration: Taluk-wise summary breakdown
    $talukBreakdowns = Database::fetchAll(
        "SELECT t.id, t.name AS taluk_name,
                COUNT(DISTINCT m.id) AS total_members,
                COUNT(DISTINCT CASE WHEN m.membership_status = 'active' THEN m.id END) AS active_members,
                COUNT(DISTINCT CASE WHEN p.id IS NOT NULL THEN m.id END) AS paid_members,
                COUNT(DISTINCT m.id) - COUNT(DISTINCT CASE WHEN p.id IS NOT NULL THEN m.id END) AS unpaid_members,
                COALESCE(SUM(p.amount), 0) AS paid_amount,
                COALESCE(grv.pending_count, 0) AS pending_grievances
         FROM taluks t
         LEFT JOIN members m ON m.taluk_id = t.id
         LEFT JOIN membership_payments p ON p.member_id = m.id AND p.membership_year_id = ? AND p.status = 'completed'
         LEFT JOIN (
             SELECT m2.taluk_id, COUNT(g.id) AS pending_count
             FROM grievances g
             JOIN members m2 ON m2.id = g.member_id
             WHERE g.current_status IN ('Submitted', 'Under Verification', 'Under Review', 'Pending', 'Forwarded')
             GROUP BY m2.taluk_id
         ) grv ON grv.taluk_id = t.id
         WHERE t.district_id = ?
         GROUP BY t.id, t.name, grv.pending_count
         ORDER BY total_members DESC, t.name ASC",
        [$currentFyId, $lockedDistrictId]
    );
} else {
    // State Administration: District-wise membership and grievance summary
    $districtBreakdowns = Database::fetchAll(
        "SELECT d.id, d.name AS district_name,
                COUNT(DISTINCT m.id) AS total_members,
                COUNT(DISTINCT CASE WHEN m.membership_status = 'active' THEN m.id END) AS active_members,
                COUNT(DISTINCT CASE WHEN p.id IS NOT NULL THEN m.id END) AS paid_members,
                COUNT(DISTINCT m.id) - COUNT(DISTINCT CASE WHEN p.id IS NOT NULL THEN m.id END) AS unpaid_members,
                COALESCE(SUM(p.amount), 0) AS paid_amount,
                COALESCE(grv.pending_count, 0) AS pending_grievances
         FROM districts d
         LEFT JOIN members m ON m.district_id = d.id
         LEFT JOIN membership_payments p ON p.member_id = m.id AND p.membership_year_id = ? AND p.status = 'completed'
         LEFT JOIN (
             SELECT m2.district_id, COUNT(g.id) AS pending_count
             FROM grievances g
             JOIN members m2 ON m2.id = g.member_id
             WHERE g.current_status IN ('Submitted', 'Under Verification', 'Under Review', 'Pending', 'Forwarded')
             GROUP BY m2.district_id
         ) grv ON grv.district_id = d.id
         GROUP BY d.id, d.name, grv.pending_count
         ORDER BY total_members DESC, d.name ASC",
        [$currentFyId]
    );
}

// ─── Recent Members Sample (Scope Aware) ─────────────────────────────────────
$recentMembers = Database::fetchAll(
    "SELECT m.id, m.member_no, m.name, m.membership_status, m.created_at,
            d.name AS district_name, t.name AS taluk_name,
            p.id AS payment_id, p.status AS payment_status
     FROM members m
     LEFT JOIN districts d ON d.id = m.district_id
     LEFT JOIN taluks t ON t.id = m.taluk_id
     LEFT JOIN membership_payments p ON p.member_id = m.id AND p.membership_year_id = {$currentFyId} AND p.status = 'completed'
     WHERE {$scopeSql}
     ORDER BY m.created_at DESC
     LIMIT 8",
    $scopeParams
);

// ─── Recent Orders & Circulars Sample ─────────────────────────────────────────
$recentOrders = Database::fetchAll(
    "SELECT o.id, o.title, o.order_no, o.order_date, o.document_id,
            dc.name AS category_name
     FROM orders o
     LEFT JOIN documents d ON d.id = o.document_id
     LEFT JOIN document_categories dc ON dc.id = d.category_id
     ORDER BY o.order_date DESC, o.id DESC
     LIMIT 5"
);

$pageTitle  = 'Admin Dashboard';
$activeMenu = 'dashboard';
$breadcrumbs = [
    ['label' => 'Admin Portal', 'url' => '/admin/index.php'],
    ['label' => 'Dashboard', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     WELCOME HERO BANNER (Blue-to-Purple Accent Gradient)
     ═══════════════════════════════════════════════════════════════════════════ -->
<section class="welcome-hero-banner">
    <div class="hero-content">
        <h1 class="hero-title">Welcome back, <?= Sanitize::html($currentUser['username'] ?? 'Officer') ?>!</h1>
        <p class="hero-subtitle">
            Overview of Karnataka State Panchayat Development Officer Welfare Association administration activities, memberships, payments, and private official resources.
        </p>
        <div class="hero-meta-badges">
            <span class="hero-badge">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline-block; vertical-align:-2px;"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <?= Sanitize::html($scopeDescription) ?>
            </span>
            <span class="hero-badge">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline-block; vertical-align:-2px;"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                Financial Year: <?= Sanitize::html($fyLabel) ?>
            </span>
            <span class="hero-badge">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline-block; vertical-align:-2px;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                Role: <?= Sanitize::html($primaryRole) ?>
            </span>
        </div>
    </div>
    <div class="hero-visual-card">
        <div class="hero-visual-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
        </div>
        <div>
            <div style="font-size:0.68rem; text-transform:uppercase; letter-spacing:0.5px; opacity:0.85;">Authorized Scope</div>
            <div style="font-size:0.95rem; font-weight:700; line-height:1.2;"><?= Sanitize::html($unitName) ?></div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════
     METRIC STAT CARDS (Role / Scope Aware)
     ═══════════════════════════════════════════════════════════════════════════ -->
<section class="stats-grid">
    <!-- Total Members -->
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Total Members</span>
            <span class="stat-number"><?= number_format($totalMembers) ?></span>
            <span class="stat-subtext">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
                Registered in your scope
            </span>
        </div>
        <div class="stat-icon-box stat-icon-blue">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
        </div>
    </div>

    <!-- Current FY Paid Members -->
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Current FY Paid</span>
            <span class="stat-number" style="color:var(--success-dark);"><?= number_format($paidMembers) ?></span>
            <span class="stat-subtext" style="color:var(--success-dark);">
                <?= $totalMembers > 0 ? round(($paidMembers / $totalMembers) * 100, 1) : 0 ?>% collection rate (<?= Sanitize::html($fyLabel) ?>)
            </span>
        </div>
        <div class="stat-icon-box stat-icon-green">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </div>

    <!-- Current FY Unpaid Members -->
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Current FY Unpaid</span>
            <span class="stat-number" style="color:var(--danger);"><?= number_format($unpaidMembers) ?></span>
            <span class="stat-subtext" style="color:var(--danger);">
                Fee pending for <?= Sanitize::html($fyLabel) ?>
            </span>
        </div>
        <div class="stat-icon-box stat-icon-red">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </div>

    <!-- Active Members -->
    <div class="stat-card">
        <div class="stat-content">
            <span class="stat-label">Active Members</span>
            <span class="stat-number" style="color:var(--purple-600);"><?= number_format($activeMembers) ?></span>
            <span class="stat-subtext">
                Good standing lifecycle status
            </span>
        </div>
        <div class="stat-icon-box stat-icon-purple">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════
     SUMMARY STATUS BARS (Formax Pay Visual Reference Style)
     ═══════════════════════════════════════════════════════════════════════════ -->
<section class="summary-bars-grid">
    <div class="summary-bar-card summary-bar-success">
        <div>
            <div class="summary-bar-num">₹<?= number_format($paidAmount, 2) ?></div>
            <div class="summary-bar-label">PAID • <?= number_format($paidMembers) ?> Members Verified (<?= Sanitize::html($fyLabel) ?>)</div>
        </div>
        <div class="summary-bar-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        </div>
    </div>

    <div class="summary-bar-card summary-bar-danger">
        <div>
            <div class="summary-bar-num">₹<?= number_format($unpaidEstAmount, 2) ?></div>
            <div class="summary-bar-label">UNPAID • <?= number_format($unpaidMembers) ?> Pending Annual Dues</div>
        </div>
        <div class="summary-bar-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </div>
    </div>

    <div class="summary-bar-card summary-bar-warning">
        <div>
            <div class="summary-bar-num"><?= number_format($pendingGrievancesCount) ?></div>
            <div class="summary-bar-label">PENDING • <?= number_format($pendingGrievancesCount) ?> Grievances in Scope</div>
        </div>
        <div class="summary-bar-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        </div>
    </div>

    <?php if ($canViewSuggestions): ?>
    <a href="/admin/suggestions.php" class="summary-bar-card summary-bar-purple" style="text-decoration:none;">
        <div>
            <div class="summary-bar-num"><?= number_format($sugPendingResponse) ?></div>
            <div class="summary-bar-label">SUGGESTIONS • <?= number_format($sugPendingResponse) ?> Pending Response (<?= number_format($sugTotal) ?> Total)</div>
        </div>
        <div class="summary-bar-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
        </div>
    </a>
    <?php endif; ?>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════
     QUICK ACTIONS FOR AUTHORIZED MODULES (NEXUS Visual Style)
     ═══════════════════════════════════════════════════════════════════════════ -->
<section class="quick-actions-section">
    <h2 class="section-heading">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
        Quick Actions
    </h2>
    <div class="quick-actions-grid">
        <?php if (admin_can($currentUserId, 'members', 'create') || admin_can($currentUserId, 'members', 'manage')): ?>
        <a href="/admin/members.php?add=1" class="quick-action-tile tile-blue">
            <div class="tile-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
            </div>
            <div>
                <div class="tile-title">Add Member</div>
                <div class="tile-desc">Register new PDO member</div>
            </div>
        </a>
        <?php endif; ?>

        <?php if (admin_can($currentUserId, 'members', 'manage')): ?>
        <a href="/admin/members-import.php" class="quick-action-tile tile-green">
            <div class="tile-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
            </div>
            <div>
                <div class="tile-title">Bulk Import</div>
                <div class="tile-desc">Import members via CSV</div>
            </div>
        </a>
        <?php endif; ?>

        <?php if (admin_can($currentUserId, 'orders', 'view')): ?>
        <a href="/admin/orders.php" class="quick-action-tile tile-purple">
            <div class="tile-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            </div>
            <div>
                <div class="tile-title">Orders</div>
                <div class="tile-desc"><?= $totalOrders ?> Government orders</div>
            </div>
        </a>
        <?php endif; ?>

        <?php if (admin_can($currentUserId, 'circulars', 'view')): ?>
        <a href="/admin/circulars.php" class="quick-action-tile tile-cyan">
            <div class="tile-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
            </div>
            <div>
                <div class="tile-title">Circulars</div>
                <div class="tile-desc"><?= $totalCirculars ?> Circulars</div>
            </div>
        </a>
        <?php endif; ?>

        <?php if (admin_can($currentUserId, 'documents', 'view')): ?>
        <a href="/admin/documents.php" class="quick-action-tile tile-orange">
            <div class="tile-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg>
            </div>
            <div>
                <div class="tile-title">Documents</div>
                <div class="tile-desc"><?= $totalDocuments ?> Official files</div>
            </div>
        </a>
        <?php endif; ?>

        <?php if (admin_can($currentUserId, 'activities', 'view')): ?>
        <a href="/admin/activities.php" class="quick-action-tile tile-indigo">
            <div class="tile-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
            <div>
                <div class="tile-title">Activities</div>
                <div class="tile-desc">Association programs</div>
            </div>
        </a>
        <?php endif; ?>

        <?php if (admin_can($currentUserId, 'office_bearers', 'view')): ?>
        <a href="/admin/office-bearers.php" class="quick-action-tile tile-pink">
            <div class="tile-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
            </div>
            <div>
                <div class="tile-title">Office Bearers</div>
                <div class="tile-desc">Directory &amp; Executive</div>
            </div>
        </a>
        <?php endif; ?>

        <?php if (admin_can($currentUserId, 'reports', 'view')): ?>
        <a href="/admin/members-reports.php" class="quick-action-tile tile-slate">
            <div class="tile-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            </div>
            <div>
                <div class="tile-title">Abstract Reports</div>
                <div class="tile-desc">District &amp; Taluk abstracts</div>
            </div>
        </a>
        <?php endif; ?>

        <?php if ($canViewSuggestions): ?>
        <a href="/admin/suggestions.php" class="quick-action-tile tile-indigo">
            <div class="tile-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
            </div>
            <div>
                <div class="tile-title">Suggestions</div>
                <div class="tile-desc"><?= $sugPendingResponse ?> Pending review</div>
            </div>
        </a>
        <?php endif; ?>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════
     ADMINISTRATIVE JURISDICTION BREAKDOWN (State / District / Taluk Scoped)
     ═══════════════════════════════════════════════════════════════════════════ -->
<?php if (!empty($districtBreakdowns)): ?>
<div class="table-card" style="margin-bottom: 24px;">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">District-Wise Administrative Summary</span>
            <span class="table-card-count">(Statewide Overview — <?= count($districtBreakdowns) ?> Districts)</span>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
            <input type="text" id="districtSearchInput" class="form-control form-control-sm" placeholder="Search district..." onkeyup="filterAdminTable('districtSearchInput', 'districtTableBody')" style="max-width:180px; padding:4px 10px; font-size:0.82rem;">
            <?php if (admin_can($currentUserId, 'reports', 'view')): ?>
            <a href="/admin/members-reports.php" class="btn btn-outline btn-sm">Full Report →</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 50px;">Sl No</th>
                    <th>District</th>
                    <th style="text-align:center;">Total PDOs</th>
                    <th style="text-align:center;">Active</th>
                    <th style="text-align:center;"><?= Sanitize::html($fyLabel) ?> Paid</th>
                    <th style="text-align:center;">Unpaid</th>
                    <th style="text-align:center;">Collection %</th>
                    <th style="text-align:center;">Pending Grievances</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody id="districtTableBody">
                <?php $sl = 1; foreach ($districtBreakdowns as $row): 
                    $pct = $row['total_members'] > 0 ? round(($row['paid_members'] / $row['total_members']) * 100, 1) : 0;
                    $badgeClass = $pct >= 80 ? 'badge-success' : ($pct >= 50 ? 'badge-warning' : 'badge-danger');
                ?>
                <tr>
                    <td style="color:var(--text-muted);"><?= $sl++ ?></td>
                    <td style="font-weight:700; color:var(--blue-700);">
                        <?= Sanitize::html($row['district_name']) ?>
                    </td>
                    <td style="text-align:center; font-weight:600;"><?= number_format((int)$row['total_members']) ?></td>
                    <td style="text-align:center; color:var(--success-dark); font-weight:600;"><?= number_format((int)$row['active_members']) ?></td>
                    <td style="text-align:center; font-weight:700; color:var(--success-dark);">
                        <?= number_format((int)$row['paid_members']) ?>
                    </td>
                    <td style="text-align:center; font-weight:600; color:var(--danger-dark);">
                        <?= number_format((int)$row['unpaid_members']) ?>
                    </td>
                    <td style="text-align:center;">
                        <span class="badge <?= $badgeClass ?>"><?= $pct ?>%</span>
                    </td>
                    <td style="text-align:center;">
                        <?php if ((int)$row['pending_grievances'] > 0): ?>
                            <span class="badge badge-warning"><?= (int)$row['pending_grievances'] ?> Pending</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">0</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <a href="/admin/members.php?district_id=<?= (int)$row['id'] ?>" class="btn btn-outline btn-sm" title="View members in <?= Sanitize::html($row['district_name']) ?>">
                            View PDOs
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php elseif (!empty($talukBreakdowns)): ?>
<div class="table-card" style="margin-bottom: 24px;">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">Taluk-Wise Administrative Summary</span>
            <span class="table-card-count">(<?= count($talukBreakdowns) ?> Taluks)</span>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
            <input type="text" id="talukSearchInput" class="form-control form-control-sm" placeholder="Search taluk..." onkeyup="filterAdminTable('talukSearchInput', 'talukTableBody')" style="max-width:180px; padding:4px 10px; font-size:0.82rem;">
            <?php if (admin_can($currentUserId, 'reports', 'view')): ?>
            <a href="/admin/members-reports.php" class="btn btn-outline btn-sm">Full Report →</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 50px;">Sl No</th>
                    <th>Taluk</th>
                    <th style="text-align:center;">Total PDOs</th>
                    <th style="text-align:center;">Active</th>
                    <th style="text-align:center;"><?= Sanitize::html($fyLabel) ?> Paid</th>
                    <th style="text-align:center;">Unpaid</th>
                    <th style="text-align:center;">Collection %</th>
                    <th style="text-align:center;">Pending Grievances</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody id="talukTableBody">
                <?php $sl = 1; foreach ($talukBreakdowns as $row): 
                    $pct = $row['total_members'] > 0 ? round(($row['paid_members'] / $row['total_members']) * 100, 1) : 0;
                    $badgeClass = $pct >= 80 ? 'badge-success' : ($pct >= 50 ? 'badge-warning' : 'badge-danger');
                ?>
                <tr>
                    <td style="color:var(--text-muted);"><?= $sl++ ?></td>
                    <td style="font-weight:700; color:var(--blue-700);">
                        <?= Sanitize::html($row['taluk_name']) ?>
                    </td>
                    <td style="text-align:center; font-weight:600;"><?= number_format((int)$row['total_members']) ?></td>
                    <td style="text-align:center; color:var(--success-dark); font-weight:600;"><?= number_format((int)$row['active_members']) ?></td>
                    <td style="text-align:center; font-weight:700; color:var(--success-dark);">
                        <?= number_format((int)$row['paid_members']) ?>
                    </td>
                    <td style="text-align:center; font-weight:600; color:var(--danger-dark);">
                        <?= number_format((int)$row['unpaid_members']) ?>
                    </td>
                    <td style="text-align:center;">
                        <span class="badge <?= $badgeClass ?>"><?= $pct ?>%</span>
                    </td>
                    <td style="text-align:center;">
                        <?php if ((int)$row['pending_grievances'] > 0): ?>
                            <span class="badge badge-warning"><?= (int)$row['pending_grievances'] ?> Pending</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">0</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <a href="/admin/members.php?taluk_id=<?= (int)$row['id'] ?>" class="btn btn-outline btn-sm" title="View members in <?= Sanitize::html($row['taluk_name']) ?>">
                            View PDOs
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php elseif (!empty($talukGpStats)): ?>
<div class="table-card" style="margin-bottom: 24px;">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">Gram Panchayat Coverage Summary</span>
            <span class="table-card-count">(<?= count($talukGpStats) ?> Gram Panchayats)</span>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
            <input type="text" id="gpSearchInput" class="form-control form-control-sm" placeholder="Search GP..." onkeyup="filterAdminTable('gpSearchInput', 'gpTableBody')" style="max-width:180px; padding:4px 10px; font-size:0.82rem;">
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 50px;">Sl No</th>
                    <th>Gram Panchayat</th>
                    <th>Code</th>
                    <th style="text-align:center;">PDO Count</th>
                    <th style="text-align:center;">Active</th>
                    <th style="text-align:center;"><?= Sanitize::html($fyLabel) ?> Paid</th>
                    <th style="text-align:center;">Unpaid</th>
                    <th style="text-align:center;">Pending Grievances</th>
                    <th style="text-align:center;">Coverage Status</th>
                </tr>
            </thead>
            <tbody id="gpTableBody">
                <?php $sl = 1; foreach ($talukGpStats as $row): 
                    $hasMembers = (int)$row['total_members'] > 0;
                ?>
                <tr>
                    <td style="color:var(--text-muted);"><?= $sl++ ?></td>
                    <td style="font-weight:700; color:var(--text-main);">
                        <?= Sanitize::html($row['gp_name']) ?>
                    </td>
                    <td><code><?= Sanitize::html($row['gp_code'] ?? '—') ?></code></td>
                    <td style="text-align:center; font-weight:600;"><?= number_format((int)$row['total_members']) ?></td>
                    <td style="text-align:center; color:var(--success-dark);"><?= number_format((int)$row['active_members']) ?></td>
                    <td style="text-align:center; font-weight:700; color:var(--success-dark);"><?= number_format((int)$row['paid_members']) ?></td>
                    <td style="text-align:center; font-weight:600; color:var(--danger-dark);"><?= number_format((int)$row['unpaid_members']) ?></td>
                    <td style="text-align:center;">
                        <?php if ((int)$row['pending_grievances'] > 0): ?>
                            <span class="badge badge-warning"><?= (int)$row['pending_grievances'] ?> Pending</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">0</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($hasMembers): ?>
                            <span class="badge badge-success">● Covered</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">○ Unassigned</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
function filterAdminTable(inputId, tbodyId) {
    const filter = document.getElementById(inputId).value.toLowerCase();
    const rows = document.getElementById(tbodyId).getElementsByTagName('tr');
    for (let i = 0; i < rows.length; i++) {
        const text = rows[i].textContent.toLowerCase();
        rows[i].style.display = text.includes(filter) ? '' : 'none';
    }
}
</script>

<!-- ═══════════════════════════════════════════════════════════════════════════
     RECENT MEMBERS (Scope-Aware Table)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">Recent Member Registrations</span>
            <span class="table-card-count">(Latest 8 in your scope)</span>
        </div>
        <div>
            <a href="/admin/members.php" class="btn btn-outline btn-sm">View All Members →</a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Member No.</th>
                    <th>Full Name</th>
                    <th>District</th>
                    <th>Taluk</th>
                    <th><?= Sanitize::html($fyLabel) ?> Fee</th>
                    <th>Status</th>
                    <th>Registration Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentMembers)): ?>
                <tr>
                    <td colspan="8" style="text-align:center; padding:32px; color:var(--text-muted);">
                        No members found within your authorized scope.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($recentMembers as $m): ?>
                    <tr>
                        <td style="font-weight:700; color:var(--blue-700);">
                            <?= Sanitize::html($m['member_no']) ?>
                        </td>
                        <td style="font-weight:600;">
                            <?= Sanitize::html($m['name']) ?>
                        </td>
                        <td><?= Sanitize::html($m['district_name'] ?? '—') ?></td>
                        <td><?= Sanitize::html($m['taluk_name'] ?? '—') ?></td>
                        <td>
                            <?php if ($m['payment_status'] === 'completed'): ?>
                                <span class="badge badge-success">● Paid</span>
                            <?php else: ?>
                                <span class="badge badge-danger">○ Unpaid</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($m['membership_status'] === 'active'): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-neutral"><?= ucfirst(Sanitize::html($m['membership_status'] ?? 'inactive')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('d M Y', strtotime($m['created_at'])) ?></td>
                        <td>
                            <a href="/admin/member-view.php?id=<?= (int)$m['id'] ?>" class="btn btn-outline btn-sm">View Profile</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     RECENT ORDERS & CIRCULARS PREVIEW
     ═══════════════════════════════════════════════════════════════════════════ -->
<?php if (!empty($recentOrders)): ?>
<div class="table-card">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">Latest Government Orders</span>
            <span class="table-card-count">(Official Records)</span>
        </div>
        <div>
            <a href="/admin/orders.php" class="btn btn-outline btn-sm">Manage Orders →</a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Subject</th>
                    <th>Order No.</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentOrders as $ro): ?>
                <tr>
                    <td>
                        <span class="badge badge-purple"><?= Sanitize::html($ro['category_name'] ?? 'General') ?></span>
                    </td>
                    <td style="font-weight:600;"><?= Sanitize::html($ro['title']) ?></td>
                    <td><code><?= Sanitize::html($ro['order_no']) ?></code></td>
                    <td><?= $ro['order_date'] ? date('d M Y', strtotime($ro['order_date'])) : '—' ?></td>
                    <td>
                        <?php if ($ro['document_id']): ?>
                            <a href="/document.php?id=<?= (int)$ro['document_id'] ?>" target="_blank" class="btn btn-primary btn-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                View File
                            </a>
                        <?php else: ?>
                            <span style="color:var(--text-muted); font-size:0.8rem;">No file</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
