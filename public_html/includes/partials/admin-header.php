<?php
/**
 * KSPDOWA — Admin Portal Shared Header & Navigation
 * ============================================================
 * Gated by Authentication & RBAC.
 * Implements Section 3 navigation and visual reference theme:
 * - Formax Pay / NEXUS inspired theme with dark slate navy sidebar
 * - Blue active item pill
 * - RBAC permission-aware module visibility
 * - Clean compact topbar with breadcrumbs & user info
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

if ($currentUserId === null) {
    header('Location: /login.php');
    exit;
}

// User cannot be a regular member without admin permissions in /admin/
$userRoles     = RBAC::getUserRoles($currentUserId);
$roleNames     = array_column($userRoles, 'name');
$isOnlyMember  = count($roleNames) === 1 && in_array('Regular Member', $roleNames, true);

if ($isOnlyMember && !RBAC::hasPermission($currentUserId, 'members', 'manage')) {
    header('Location: /member/index.php');
    exit;
}

$currentUser = Database::fetchOne('SELECT username, email FROM users WHERE id = ?', [$currentUserId]);
$highestScope = RBAC::getHighestScopeType($currentUserId);
$unitId       = RBAC::getUserAssociationUnit($currentUserId);
$unitName     = 'Karnataka State';

if ($unitId) {
    $unitRow = Database::fetchOne('SELECT name, unit_type FROM association_units WHERE id = ?', [$unitId]);
    if ($unitRow) {
        $unitName = $unitRow['name'];
    }
}

$primaryRole = $roleNames[0] ?? 'Officer';
$pageTitle   = $pageTitle ?? 'Admin Dashboard';
$activeMenu  = $activeMenu ?? 'dashboard';
$breadcrumbs = $breadcrumbs ?? [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => $pageTitle, 'url' => '']
];

// Helper to check RBAC module permission
function admin_can(int $userId, string $module, string $action = 'view'): bool {
    // Super admin has all permissions
    if (RBAC::hasRole($userId, 'State Super Admin')) {
        return true;
    }
    return RBAC::hasPermission($userId, $module, $action);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Sanitize::html($pageTitle) ?> — Admin — <?= Sanitize::html(APP_SHORT_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Kannada:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin-theme.css">
</head>
<body>
<div class="app-layout">

    <!-- ═══════════════════════════════════════════════════════════════════════
         SIDEBAR NAVIGATION (Formax Pay / NEXUS Style)
         ═══════════════════════════════════════════════════════════════════════ -->
    <aside class="app-sidebar" id="appSidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand-logo">K</div>
            <div class="sidebar-brand-text">
                <span class="sidebar-brand-title"><?= Sanitize::html(APP_SHORT_NAME) ?></span>
                <span class="sidebar-brand-subtitle">Admin Portal</span>
            </div>
        </div>

        <div class="sidebar-user-pill">
            <div class="user-avatar-circle">
                <?= strtoupper(substr($currentUser['username'] ?? 'A', 0, 1)) ?>
            </div>
            <div class="user-info-text">
                <div class="user-info-name"><?= Sanitize::html($currentUser['username'] ?? 'Administrator') ?></div>
                <div class="user-info-role"><?= Sanitize::html($primaryRole) ?></div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <?php
            // Active group calculation
            $isMembersGroup    = in_array($activeMenu, ['members', 'members_add', 'members_import', 'membership', 'reports'], true);
            $isFinanceGroup    = in_array($activeMenu, ['payments', 'donations'], true);
            $isOrdersGroup     = in_array($activeMenu, ['orders', 'circulars', 'documents'], true);
            $isActivitiesGroup = in_array($activeMenu, ['activities', 'events', 'meetings', 'news'], true);
            $isSystemGroup     = in_array($activeMenu, ['users', 'audit_logs', 'settings'], true);

            // Group permission visibility
            $canViewAnyMember     = admin_can($currentUserId, 'members', 'view') || 
                                    admin_can($currentUserId, 'membership', 'view') || 
                                    admin_can($currentUserId, 'reports', 'view');

            $canViewAnyFinance    = admin_can($currentUserId, 'payments', 'view') || 
                                    admin_can($currentUserId, 'donations', 'view');

            $canViewAnyOrders     = admin_can($currentUserId, 'orders', 'view') || 
                                    admin_can($currentUserId, 'circulars', 'view') || 
                                    admin_can($currentUserId, 'documents', 'view');

            $canViewAnyActivities = admin_can($currentUserId, 'activities', 'view') || 
                                    admin_can($currentUserId, 'events', 'view') || 
                                    admin_can($currentUserId, 'meetings', 'view') || 
                                    admin_can($currentUserId, 'news', 'view');

            $canViewAnySystem     = admin_can($currentUserId, 'users', 'view') || 
                                    admin_can($currentUserId, 'roles', 'view') || 
                                    admin_can($currentUserId, 'audit_logs', 'view') || 
                                    admin_can($currentUserId, 'settings', 'view');
            ?>

            <div class="nav-group-title">Main</div>
            
            <a href="/admin/index.php" class="nav-link <?= $activeMenu === 'dashboard' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                Dashboard
            </a>

            <?php if ($canViewAnyMember): ?>
            <div class="nav-dropdown <?= $isMembersGroup ? 'open active-parent' : '' ?>">
                <button type="button" class="nav-dropdown-toggle" aria-expanded="<?= $isMembersGroup ? 'true' : 'false' ?>">
                    <span class="nav-dropdown-title">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                        Members
                    </span>
                    <svg class="nav-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
                <div class="nav-submenu">
                    <?php if (admin_can($currentUserId, 'members', 'view')): ?>
                        <a href="/admin/members.php" class="nav-sublink <?= $activeMenu === 'members' ? 'active' : '' ?>">Members List</a>
                        <?php if (admin_can($currentUserId, 'members', 'manage')): ?>
                            <a href="/admin/members.php?add=1" class="nav-sublink <?= $activeMenu === 'members_add' ? 'active' : '' ?>">Add Member</a>
                            <a href="/admin/members-import.php" class="nav-sublink <?= $activeMenu === 'members_import' ? 'active' : '' ?>">Bulk Import</a>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (admin_can($currentUserId, 'membership', 'view') || admin_can($currentUserId, 'membership', 'manage')): ?>
                        <a href="/admin/membership-setup.php" class="nav-sublink <?= $activeMenu === 'membership' ? 'active' : '' ?>">Membership Setup</a>
                    <?php endif; ?>
                    <?php if (admin_can($currentUserId, 'reports', 'view') || admin_can($currentUserId, 'members', 'manage')): ?>
                        <a href="/admin/members-reports.php" class="nav-sublink <?= $activeMenu === 'reports' ? 'active' : '' ?>">Member Reports</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($canViewAnyFinance): ?>
            <div class="nav-dropdown <?= $isFinanceGroup ? 'open active-parent' : '' ?>">
                <button type="button" class="nav-dropdown-toggle" aria-expanded="<?= $isFinanceGroup ? 'true' : 'false' ?>">
                    <span class="nav-dropdown-title">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                        Finance
                    </span>
                    <svg class="nav-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
                <div class="nav-submenu">
                    <?php if (admin_can($currentUserId, 'payments', 'view')): ?>
                        <a href="/admin/payments.php" class="nav-sublink <?= $activeMenu === 'payments' ? 'active' : '' ?>">Fee Payments</a>
                    <?php endif; ?>
                    <?php if (admin_can($currentUserId, 'donations', 'view')): ?>
                        <a href="/admin/donations.php" class="nav-sublink <?= $activeMenu === 'donations' ? 'active' : '' ?>">Donations</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="nav-group-title">Content &amp; Administration</div>

            <?php if ($canViewAnyOrders): ?>
            <div class="nav-dropdown <?= $isOrdersGroup ? 'open active-parent' : '' ?>">
                <button type="button" class="nav-dropdown-toggle" aria-expanded="<?= $isOrdersGroup ? 'true' : 'false' ?>">
                    <span class="nav-dropdown-title">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Orders &amp; Circulars
                    </span>
                    <svg class="nav-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
                <div class="nav-submenu">
                    <?php if (admin_can($currentUserId, 'orders', 'view')): ?>
                        <a href="/admin/orders.php" class="nav-sublink <?= $activeMenu === 'orders' ? 'active' : '' ?>">Orders</a>
                    <?php endif; ?>
                    <?php if (admin_can($currentUserId, 'circulars', 'view')): ?>
                        <a href="/admin/circulars.php" class="nav-sublink <?= $activeMenu === 'circulars' ? 'active' : '' ?>">Circulars</a>
                    <?php endif; ?>
                    <?php if (admin_can($currentUserId, 'documents', 'view')): ?>
                        <a href="/admin/documents.php" class="nav-sublink <?= $activeMenu === 'documents' ? 'active' : '' ?>">Documents</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($canViewAnyActivities): ?>
            <div class="nav-dropdown <?= $isActivitiesGroup ? 'open active-parent' : '' ?>">
                <button type="button" class="nav-dropdown-toggle" aria-expanded="<?= $isActivitiesGroup ? 'true' : 'false' ?>">
                    <span class="nav-dropdown-title">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        Activities &amp; News
                    </span>
                    <svg class="nav-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
                <div class="nav-submenu">
                    <?php if (admin_can($currentUserId, 'activities', 'view')): ?>
                        <a href="/admin/activities.php" class="nav-sublink <?= $activeMenu === 'activities' ? 'active' : '' ?>">Activities</a>
                    <?php endif; ?>
                    <?php if (admin_can($currentUserId, 'events', 'view')): ?>
                        <a href="/admin/events.php" class="nav-sublink <?= $activeMenu === 'events' ? 'active' : '' ?>">Events</a>
                    <?php endif; ?>
                    <?php if (admin_can($currentUserId, 'meetings', 'view')): ?>
                        <a href="/admin/meetings.php" class="nav-sublink <?= $activeMenu === 'meetings' ? 'active' : '' ?>">Meetings</a>
                    <?php endif; ?>
                    <?php if (admin_can($currentUserId, 'news', 'view')): ?>
                        <a href="/admin/news.php" class="nav-sublink <?= $activeMenu === 'news' ? 'active' : '' ?>">News &amp; Updates</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (admin_can($currentUserId, 'grievances', 'view')): ?>
            <a href="/admin/grievances.php" class="nav-link <?= $activeMenu === 'grievances' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                Grievances
            </a>
            <?php endif; ?>

            <?php if (admin_can($currentUserId, 'office_bearers', 'view')): ?>
            <a href="/admin/office-bearers.php" class="nav-link <?= $activeMenu === 'office_bearers' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                Office Bearers
            </a>
            <?php endif; ?>

            <div class="nav-group-title">System &amp; Settings</div>

            <?php if ($canViewAnySystem): ?>
            <div class="nav-dropdown <?= $isSystemGroup ? 'open active-parent' : '' ?>">
                <button type="button" class="nav-dropdown-toggle" aria-expanded="<?= $isSystemGroup ? 'true' : 'false' ?>">
                    <span class="nav-dropdown-title">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        System &amp; Settings
                    </span>
                    <svg class="nav-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
                <div class="nav-submenu">
                    <?php if (admin_can($currentUserId, 'users', 'view') || admin_can($currentUserId, 'roles', 'view')): ?>
                        <a href="/admin/users.php" class="nav-sublink <?= $activeMenu === 'users' ? 'active' : '' ?>">Users &amp; Roles</a>
                    <?php endif; ?>
                    <?php if (admin_can($currentUserId, 'audit_logs', 'view')): ?>
                        <a href="/admin/audit-logs.php" class="nav-sublink <?= $activeMenu === 'audit_logs' ? 'active' : '' ?>">Audit Logs</a>
                    <?php endif; ?>
                    <?php if (admin_can($currentUserId, 'settings', 'view')): ?>
                        <a href="/admin/settings.php" class="nav-sublink <?= $activeMenu === 'settings' ? 'active' : '' ?>">Settings</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <a href="/logout.php" class="sidebar-logout-link">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                Logout
            </a>
        </div>
    </aside>

    <!-- ═══════════════════════════════════════════════════════════════════════
         MAIN VIEWPORT (Topbar + Content)
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
                    ACTIVE SYSTEM
                </span>

                <?php if (Auth::getCurrentMemberId() !== null): ?>
                <a href="/member/index.php" class="topbar-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    Member Portal
                </a>
                <?php endif; ?>

                <div class="topbar-profile-chip">
                    <div class="topbar-avatar">
                        <?= strtoupper(substr($currentUser['username'] ?? 'A', 0, 1)) ?>
                    </div>
                    <span style="font-size:0.84rem; font-weight:600; padding-right:6px;">
                        <?= Sanitize::html($currentUser['username'] ?? 'Admin') ?>
                    </span>
                </div>

                <a href="/logout.php" title="Logout" class="topbar-btn" style="color:var(--danger);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                </a>
            </div>
        </header>

        <div class="app-content">
            <?php 
            $successFlash = Session::getFlash('success') ?: ($successMsg ?? null);
            $errorFlash   = Session::getFlash('error')   ?: ($errorMsg ?? null);
            ?>
            <?php if ($successFlash): ?>
                <div class="alert alert-success">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    <?= Sanitize::html($successFlash) ?>
                </div>
            <?php endif; ?>

            <?php if ($errorFlash): ?>
                <div class="alert alert-danger">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <?= Sanitize::html($errorFlash) ?>
                </div>
            <?php endif; ?>
