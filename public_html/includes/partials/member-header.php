<?php
/**
 * KSPDOWA — Member Portal Shared Header & Navigation
 * ============================================================
 * Gated by Member Authentication & Eligibility.
 * Implements Section 8 Navigation (Finance REMOVED, Payments KEPT):
 * - Dashboard
 * - My Profile
 * - My Membership
 * - Digital ID
 * - Membership Fee
 * - Payments
 * - Orders & Circulars
 * - Activities
 * - Documents
 * - My Grievances
 * - Notifications
 * - Settings
 * - Logout
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();

$currentUserId  = Auth::getCurrentUserId();
$currentMemberId = Auth::getCurrentMemberId();

if ($currentMemberId === null) {
    header('Location: /admin/index.php');
    exit;
}

// Force password change on temporary password
$userRow = Database::fetchOne('SELECT must_change_password FROM users WHERE id = ?', [$currentUserId]);
if ($userRow && (int)$userRow['must_change_password'] === 1 && basename($_SERVER['PHP_SELF'] ?? '') !== 'change-password.php') {
    header('Location: /member/change-password.php');
    exit;
}

// Check current financial year eligibility
$currentYear = Membership::getCurrentYear();
if ($currentYear === null || !Membership::isEligibleForYear($currentMemberId, (int)$currentYear['id'])) {
    AuditLogger::log('LOGIN_DENIED_INELIGIBLE', 'users', $currentUserId);
    Session::destroy();
    Session::flash('error', 'Your current-year annual membership fee has not been verified yet. Please complete payment to access the member portal.');
    header('Location: /member-login.php');
    exit;
}

// Member profile details
$portalMember = Database::fetchOne(
    'SELECT m.*, d.name AS district_name, t.name AS taluk_name, gp.name AS gp_name
     FROM members m
     LEFT JOIN districts d ON d.id = m.district_id
     LEFT JOIN taluks t    ON t.id = m.taluk_id
     LEFT JOIN gram_panchayatis gp ON gp.id = m.gp_id
     WHERE m.id = ?',
    [$currentMemberId]
);

$pageTitle   = $pageTitle ?? 'Member Portal';
$activeMenu  = $activeMenu ?? 'dashboard';
$breadcrumbs = $breadcrumbs ?? [
    ['label' => 'Member Portal', 'url' => '/member/index.php'],
    ['label' => $pageTitle, 'url' => '']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Sanitize::html($pageTitle) ?> — Member Portal — <?= Sanitize::html(APP_SHORT_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Kannada:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin-theme.css">
</head>
<body>
<div class="app-layout">

    <!-- ═══════════════════════════════════════════════════════════════════════
         MEMBER SIDEBAR NAVIGATION (Section 8: Finance REMOVED, Payments KEPT)
         ═══════════════════════════════════════════════════════════════════════ -->
    <aside class="app-sidebar" id="appSidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand-logo" style="background: linear-gradient(135deg, #10b981, #2563eb);">K</div>
            <div class="sidebar-brand-text">
                <span class="sidebar-brand-title"><?= Sanitize::html(APP_SHORT_NAME) ?></span>
                <span class="sidebar-brand-subtitle">Member Portal</span>
            </div>
        </div>

        <div class="sidebar-user-pill">
            <div class="user-avatar-circle" style="background: linear-gradient(135deg, #10b981, #0284c7);">
                <?= strtoupper(substr($portalMember['name'] ?? 'M', 0, 1)) ?>
            </div>
            <div class="user-info-text">
                <div class="user-info-name"><?= Sanitize::html($portalMember['name'] ?? 'Member') ?></div>
                <div class="user-info-role"><?= Sanitize::html($portalMember['member_no'] ?? 'PDO Member') ?></div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-group-title">Overview</div>

            <!-- 1. Dashboard -->
            <a href="/member/index.php" class="nav-link <?= $activeMenu === 'dashboard' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                Dashboard
            </a>

            <!-- 2. My Profile -->
            <a href="/member/profile.php" class="nav-link <?= $activeMenu === 'profile' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                My Profile
            </a>

            <!-- 3. My Membership -->
            <a href="/member/membership.php" class="nav-link <?= $activeMenu === 'membership' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                My Membership
            </a>

            <!-- 4. Digital ID -->
            <a href="/member/id-card.php" class="nav-link <?= $activeMenu === 'id-card' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/></svg>
                Digital ID
            </a>

            <div class="nav-group-title">Finance &amp; Dues</div>

            <!-- 5. Membership Fee -->
            <a href="/member/fee.php" class="nav-link <?= $activeMenu === 'fee' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Membership Fee
            </a>

            <!-- 6. Payments (KEPT) -->
            <a href="/member/payments.php" class="nav-link <?= $activeMenu === 'payments' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                Payments
            </a>

            <div class="nav-group-title">Official Resources</div>

            <!-- 7. Orders & Circulars -->
            <a href="/member/orders-circulars.php" class="nav-link <?= $activeMenu === 'orders-circulars' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Orders &amp; Circulars
            </a>

            <!-- 8. Activities -->
            <a href="/member/activities.php" class="nav-link <?= $activeMenu === 'activities' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                Activities
            </a>

            <!-- 9. Documents -->
            <a href="/member/documents.php" class="nav-link <?= $activeMenu === 'documents' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg>
                Documents
            </a>

            <!-- 10. My Grievances -->
            <a href="/member/grievances.php" class="nav-link <?= $activeMenu === 'grievances' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                My Grievances
            </a>

            <div class="nav-group-title">Account</div>

            <!-- 11. Notifications -->
            <a href="/member/notifications.php" class="nav-link <?= $activeMenu === 'notifications' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                Notifications
            </a>

            <!-- 12. Settings -->
            <a href="/member/change-password.php" class="nav-link <?= $activeMenu === 'settings' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Settings
            </a>
        </nav>

        <div class="sidebar-footer">
            <!-- 13. Logout -->
            <a href="/logout.php" class="sidebar-logout-link">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                Logout
            </a>
        </div>
    </aside>

    <!-- ═══════════════════════════════════════════════════════════════════════
         MEMBER MAIN VIEWPORT (Topbar + Content)
         ═══════════════════════════════════════════════════════════════════════ -->
    <main class="app-main">
        <header class="app-topbar">
            <div class="topbar-left">
                <button type="button" class="menu-toggle-btn" id="menuToggleBtn" aria-label="Toggle navigation">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="topbar-breadcrumb">
                    <?php foreach ($breadcrumbs as $idx => $bc): ?>
                        <?php if ($idx > 0): ?>
                            <span class="breadcrumb-separator">/</span>
                        <?php endif; ?>
                        <?php if (!empty($bc['url'])): ?>
                            <a href="<?= Sanitize::attr($bc['url']) ?>"><?= Sanitize::html($bc['label']) ?></a>
                        <?php else: ?>
                            <span class="breadcrumb-current"><?= Sanitize::html($bc['label']) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="topbar-right">
                <span class="status-indicator-pill">
                    <span class="status-dot"></span>
                    VERIFIED MEMBER
                </span>

                <div class="topbar-profile-chip">
                    <div class="topbar-avatar" style="background: linear-gradient(135deg, #10b981, #2563eb);">
                        <?= strtoupper(substr($portalMember['name'] ?? 'M', 0, 1)) ?>
                    </div>
                    <span style="font-size:0.84rem; font-weight:600; padding-right:6px;">
                        <?= Sanitize::html($portalMember['name'] ?? 'Member') ?>
                    </span>
                </div>

                <a href="/logout.php" title="Logout" class="topbar-btn" style="color:var(--danger);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                </a>
            </div>
        </header>

        <div class="app-content">
            <?php if ($successFlash = Session::getFlash('success')): ?>
                <div class="alert alert-success">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    <?= Sanitize::html($successFlash) ?>
                </div>
            <?php endif; ?>

            <?php if ($errorFlash = Session::getFlash('error')): ?>
                <div class="alert alert-danger">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <?= Sanitize::html($errorFlash) ?>
                </div>
            <?php endif; ?>
