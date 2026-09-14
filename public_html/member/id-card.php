<?php
/**
 * KSPDOWA — Member Portal: Digital ID Card
 * ============================================================
 * Section 8: Digital ID
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

$pageTitle  = 'Digital ID Card';
$activeMenu = 'id-card';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/member/index.php'],
    ['label' => 'Digital ID', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';

$profile = Database::fetchOne('SELECT * FROM member_profiles WHERE member_id = ?', [$currentMemberId]);
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Official Digital ID Card</h1>
        <p class="page-heading-subtitle">Verified membership identification card for Karnataka State PDO Welfare Association</p>
    </div>
    <div>
        <button type="button" class="btn btn-primary" onclick="window.print();">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            Print ID Card
        </button>
    </div>
</div>

<div style="display:flex; justify-content:center; padding:20px 0;">
    <!-- DIGITAL ID CARD CONTAINER -->
    <div style="width:100%; max-width:480px; background:#ffffff; border-radius:18px; box-shadow:0 12px 35px -4px rgba(15,23,42,0.15); border:1px solid #cbd5e1; overflow:hidden;">
        <!-- Card Header with Blue-to-Purple Gradient -->
        <div style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 60%, #7c3aed 100%); color:#ffffff; padding:20px 24px; text-align:center; position:relative;">
            <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:1px; opacity:0.9;">Karnataka State</div>
            <div style="font-size:1.15rem; font-weight:800; letter-spacing:0.5px; margin-top:2px; line-height:1.2;">
                PANCHAYAT DEVELOPMENT OFFICER WELFARE ASSOCIATION (R)
            </div>
            <div style="font-size:0.75rem; opacity:0.85; margin-top:3px;">
                Registration No. DRB/SOR/534/2012-13 • Bengaluru
            </div>
        </div>

        <!-- Card Body -->
        <div style="padding:24px 28px; background:#ffffff;">
            <div style="display:flex; gap:20px; align-items:center; margin-bottom:20px;">
                <div style="width:90px; height:105px; border-radius:10px; background:#f1f5f9; border:2px solid #cbd5e1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#94a3b8; flex-shrink:0;">
                    <?php if (!empty($portalMember['photo_path']) && is_file(PUBLIC_HTML . '/' . ltrim($portalMember['photo_path'], '/'))): ?>
                        <img src="/<?= ltrim($portalMember['photo_path'], '/') ?>" alt="Photo" style="width:100%; height:100%; object-fit:cover; border-radius:8px;">
                    <?php else: ?>
                        <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        <span style="font-size:0.65rem; font-weight:600; text-transform:uppercase;">Photo</span>
                    <?php endif; ?>
                </div>

                <div style="flex:1;">
                    <div style="font-size:1.25rem; font-weight:800; color:var(--text-main); line-height:1.2;">
                        <?= Sanitize::html($portalMember['name'] ?? '') ?>
                    </div>
                    <div style="font-size:0.9rem; font-weight:600; color:var(--blue-700); margin-top:3px;">
                        <?= Sanitize::html($portalMember['designation'] ?? 'Panchayat Development Officer') ?>
                    </div>
                    <div style="margin-top:8px;">
                        <span class="badge badge-purple" style="font-size:0.8rem; font-weight:700;">
                            ID: <?= Sanitize::html($portalMember['member_no'] ?? '') ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Detail Grid -->
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; font-size:0.84rem; background:#f8fafc; padding:14px 16px; border-radius:10px; border:1px solid #e2e8f0;">
                <div>
                    <span style="color:var(--text-muted); font-size:0.75rem; display:block;">District:</span>
                    <strong style="color:var(--text-main);"><?= Sanitize::html($portalMember['district_name'] ?? '—') ?></strong>
                </div>
                <div>
                    <span style="color:var(--text-muted); font-size:0.75rem; display:block;">Taluk:</span>
                    <strong style="color:var(--text-main);"><?= Sanitize::html($portalMember['taluk_name'] ?? '—') ?></strong>
                </div>
                <div>
                    <span style="color:var(--text-muted); font-size:0.75rem; display:block;">Gram Panchayati:</span>
                    <strong style="color:var(--text-main);"><?= Sanitize::html($portalMember['gp_name'] ?? '—') ?></strong>
                </div>
                <div>
                    <span style="color:var(--text-muted); font-size:0.75rem; display:block;">KGID No.:</span>
                    <strong style="color:var(--text-main);"><?= Sanitize::html($profile['kgid_no'] ?? '—') ?></strong>
                </div>
                <div>
                    <span style="color:var(--text-muted); font-size:0.75rem; display:block;">Blood Group:</span>
                    <strong style="color:var(--text-main);"><?= Sanitize::html($profile['blood_group'] ?? '—') ?></strong>
                </div>
                <div>
                    <span style="color:var(--text-muted); font-size:0.75rem; display:block;">Valid For FY:</span>
                    <strong style="color:var(--success-dark);"><?= Sanitize::html($currentYear['financial_year'] ?? '') ?></strong>
                </div>
            </div>
        </div>

        <!-- Card Footer -->
        <div style="background:#f1f5f9; border-top:1px solid #e2e8f0; padding:12px 24px; display:flex; justify-content:space-between; align-items:center; font-size:0.75rem; color:var(--text-muted);">
            <div>● Official Welfare Member</div>
            <div style="font-weight:700; color:var(--blue-700);">VERIFIED ACTIVE</div>
        </div>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
