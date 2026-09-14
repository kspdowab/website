<?php
/**
 * KSPDOWA — Member Portal: My Profile
 * ============================================================
 * Section 8: My Profile
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

$pageTitle  = 'My Profile';
$activeMenu = 'profile';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/member/index.php'],
    ['label' => 'My Profile', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';

$profile = Database::fetchOne('SELECT * FROM member_profiles WHERE member_id = ?', [$currentMemberId]);
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">My Profile</h1>
        <p class="page-heading-subtitle">Verified membership and personal service records</p>
    </div>
    <div>
        <a href="/member/id-card.php" class="btn btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/></svg>
            View Digital ID
        </a>
    </div>
</div>

<div class="table-card" style="margin-bottom:24px;">
    <div class="table-card-header">
        <span class="table-card-title">Personal &amp; Service Information</span>
    </div>
    <div style="padding:24px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:22px 28px;">
            <div>
                <label class="form-label" style="color:var(--text-muted);">Member Number</label>
                <div style="font-size:1.15rem; font-weight:700; color:var(--blue-700);">
                    <?= Sanitize::html($portalMember['member_no'] ?? '') ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Full Name</label>
                <div style="font-size:1.1rem; font-weight:700; color:var(--text-main);">
                    <?= Sanitize::html($portalMember['name'] ?? '') ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Designation</label>
                <div style="font-size:1rem; font-weight:600; color:var(--text-main);">
                    <?= Sanitize::html($portalMember['designation'] ?? 'Panchayat Development Officer') ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">KGID Number</label>
                <div style="font-size:1rem; font-weight:600; color:var(--text-main);">
                    <?= Sanitize::html($profile['kgid_no'] ?? '—') ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Father / Spouse Name</label>
                <div style="font-size:1rem; font-weight:600; color:var(--text-main);">
                    <?= Sanitize::html($profile['father_spouse_name'] ?? '—') ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Gender</label>
                <div style="font-size:1rem; font-weight:600; color:var(--text-main);">
                    <?= ucfirst(Sanitize::html($profile['gender'] ?? '—')) ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Date of Birth</label>
                <div style="font-size:1rem; font-weight:600; color:var(--text-main);">
                    <?= !empty($profile['date_of_birth']) ? date('d M Y', strtotime((string)$profile['date_of_birth'])) : '—' ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Blood Group</label>
                <div style="font-size:1rem; font-weight:600; color:var(--text-main);">
                    <?= Sanitize::html($profile['blood_group'] ?? '—') ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Registered Mobile</label>
                <div style="font-size:1rem; font-weight:600; color:var(--text-main);">
                    <?= Sanitize::html($profile['personal_mobile'] ?? '—') ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Registered Email</label>
                <div style="font-size:1rem; font-weight:600; color:var(--text-main);">
                    <?= Sanitize::html($profile['personal_email'] ?? '—') ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="table-card">
    <div class="table-card-header">
        <span class="table-card-title">Posting &amp; Jurisdiction Details</span>
    </div>
    <div style="padding:24px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:22px 28px;">
            <div>
                <label class="form-label" style="color:var(--text-muted);">District</label>
                <div style="font-size:1.1rem; font-weight:700; color:var(--text-main);">
                    <?= Sanitize::html($portalMember['district_name'] ?? '—') ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Taluk</label>
                <div style="font-size:1.1rem; font-weight:700; color:var(--text-main);">
                    <?= Sanitize::html($portalMember['taluk_name'] ?? '—') ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Gram Panchayati</label>
                <div style="font-size:1.1rem; font-weight:700; color:var(--text-main);">
                    <?= Sanitize::html($portalMember['gp_name'] ?? '—') ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Membership Lifecycle</label>
                <div style="margin-top:4px;">
                    <span class="badge badge-success">Active Member</span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
