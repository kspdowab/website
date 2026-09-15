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

$allowedBloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

// Handle Blood Group Update
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();
    $action = Sanitize::string($_POST['action'] ?? '', 30);

    if ($action === 'update_blood_group') {
        $bloodGroup = trim(Sanitize::string($_POST['blood_group'] ?? '', 10));

        if ($bloodGroup !== '' && !in_array($bloodGroup, $allowedBloodGroups, true)) {
            Session::flash('error', 'Please select a valid blood group from the list.');
            header('Location: /member/profile.php');
            exit;
        }

        $existingProfile = Database::fetchOne('SELECT id FROM member_profiles WHERE member_id = ?', [$currentMemberId]);

        if ($existingProfile) {
            Database::execute(
                'UPDATE member_profiles SET blood_group = ?, updated_at = NOW() WHERE member_id = ?',
                [$bloodGroup !== '' ? $bloodGroup : null, $currentMemberId]
            );
        } else {
            Database::execute(
                'INSERT INTO member_profiles (member_id, blood_group, created_at, updated_at) VALUES (?, ?, NOW(), NOW())',
                [$currentMemberId, $bloodGroup !== '' ? $bloodGroup : null]
            );
        }

        Session::flash('success', 'Blood group updated successfully!');
        header('Location: /member/profile.php');
        exit;
    }
}

$pageTitle  = 'My Profile';
$activeMenu = 'profile';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/member/index.php'],
    ['label' => 'My Profile', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';

$profile    = Database::fetchOne('SELECT * FROM member_profiles WHERE member_id = ?', [$currentMemberId]);
$successMsg = Session::getFlash('success');
$errorMsg   = Session::getFlash('error');
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

<?php if ($successMsg): ?>
    <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-weight:500; font-size:0.9rem; display:flex; align-items:center; gap:8px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        <?= Sanitize::html($successMsg) ?>
    </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-weight:500; font-size:0.9rem; display:flex; align-items:center; gap:8px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        <?= Sanitize::html($errorMsg) ?>
    </div>
<?php endif; ?>

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
                <div style="display:flex; align-items:center; gap:10px; margin-top:2px;">
                    <div style="font-size:1.05rem; font-weight:700; color:var(--text-main);">
                        <?= !empty($profile['blood_group']) ? Sanitize::html($profile['blood_group']) : '<span style="color:var(--text-muted); font-size:0.95rem; font-weight:500;">—</span>' ?>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" onclick="openBloodGroupModal();" style="padding:2px 10px; font-size:0.75rem; border-radius:4px; display:inline-flex; align-items:center; gap:4px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                        <?= !empty($profile['blood_group']) ? 'Change' : 'Update' ?>
                    </button>
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

<!-- ═══════════════════════════════════════════════════════════════════════════
     UPDATE BLOOD GROUP MODAL
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="bloodGroupModal" onclick="if(event.target===this) closeBloodGroupModal();">
    <div class="modal-dialog" style="max-width:440px;">
        <div class="modal-header">
            <h3 class="modal-title">Update Blood Group (ರಕ್ತದ ಗುಂಪು)</h3>
            <button type="button" class="modal-close-btn" onclick="closeBloodGroupModal();">&times;</button>
        </div>
        <form method="post" action="/member/profile.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="update_blood_group">

            <div class="modal-body">
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label" for="blood_group">Select Blood Group *</label>
                    <select name="blood_group" id="blood_group" class="form-select" required style="font-size:1rem; font-weight:600;">
                        <option value="">— Select Blood Group —</option>
                        <?php foreach ($allowedBloodGroups as $bg): ?>
                            <option value="<?= $bg ?>" <?= (($profile['blood_group'] ?? '') === $bg) ? 'selected' : '' ?>>
                                <?= $bg ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="font-size:0.8rem; color:var(--text-muted); line-height:1.4;">
                    Your blood group will be displayed on your digital membership ID card and emergency records.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeBloodGroupModal();">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Blood Group</button>
            </div>
        </form>
    </div>
</div>

<script>
function openBloodGroupModal() {
    document.getElementById('bloodGroupModal').classList.add('open');
}
function closeBloodGroupModal() {
    document.getElementById('bloodGroupModal').classList.remove('open');
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeBloodGroupModal();
    }
});
</script>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
