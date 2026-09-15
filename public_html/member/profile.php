<?php
/**
 * KSPDOWA — Member Portal: My Profile
 * ============================================================
 * Section 8: Professional Employee Profile & Welfare Records
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId   = Auth::getCurrentUserId();
$currentMemberId = Auth::getCurrentMemberId();

if ($currentMemberId === null) {
    header('Location: /login.php');
    exit;
}

$allowedBloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$allowedQualifications = [
    'SSLC / 10th',
    'PUC / 12th / Intermediate',
    'Diploma',
    'Bachelor Degree (BA / B.Sc / B.Com / BBA)',
    'Engineering / Technical (B.E / B.Tech / BCA)',
    'Post Graduate (MA / M.Sc / M.Com / MBA)',
    'Master of Social Work (MSW)',
    'Post Graduate Diploma',
    'M.Phil / Ph.D / Doctorate',
    'Other'
];
$allowedRecruitmentTypes = [
    'Direct Recruitment (KPSC)',
    'Compassionate Appointment',
    'In-Service Promotion',
    'Deputation / Other'
];

// Helper to ensure member_profiles row exists
function ensureProfileExists(int $memberId): void {
    $exists = Database::fetchOne('SELECT id FROM member_profiles WHERE member_id = ?', [$memberId]);
    if (!$exists) {
        Database::execute(
            'INSERT INTO member_profiles (member_id, created_at, updated_at) VALUES (?, NOW(), NOW())',
            [$memberId]
        );
    }
}

// ─── Handle Form Actions ──────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();
    $action = Sanitize::string($_POST['action'] ?? '', 40);

    // 1. Photo Upload
    if ($action === 'upload_photo') {
        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            Session::flash('error', 'Please select a valid photo file to upload.');
            header('Location: /member/profile.php');
            exit;
        }

        $file = $_FILES['photo'];
        $maxBytes = 2 * 1024 * 1024; // 2 MB

        if ($file['size'] > $maxBytes) {
            Session::flash('error', 'Photo exceeds maximum allowed size of 2 MB.');
            header('Location: /member/profile.php');
            exit;
        }

        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) {
            Session::flash('error', 'Only JPG, PNG, and WebP photo formats are allowed.');
            header('Location: /member/profile.php');
            exit;
        }

        // Validate image content
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowedMimes, true)) {
            Session::flash('error', 'Uploaded file is not a valid image.');
            header('Location: /member/profile.php');
            exit;
        }

        $targetDir = PUBLIC_HTML . '/uploads/members/photos';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $newFileName = sprintf('photo_%d_%d_%s.%s', $currentMemberId, time(), bin2hex(random_bytes(4)), $ext);
        $targetPath  = $targetDir . '/' . $newFileName;
        $dbPath      = 'uploads/members/photos/' . $newFileName;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            // Remove previous custom photo if exists
            $old = Database::fetchOne('SELECT photo_path FROM members WHERE id = ?', [$currentMemberId]);
            if (!empty($old['photo_path'])) {
                $oldFullPath = PUBLIC_HTML . '/' . ltrim($old['photo_path'], '/');
                if (is_file($oldFullPath) && str_contains($oldFullPath, 'uploads/members/photos')) {
                    @unlink($oldFullPath);
                }
            }

            Database::execute('UPDATE members SET photo_path = ?, updated_at = NOW() WHERE id = ?', [$dbPath, $currentMemberId]);
            AuditLogger::log('PROFILE_PHOTO_UPDATED', 'members', $currentMemberId, null, ['photo_path' => $dbPath]);
            Session::flash('success', 'Profile photo updated successfully! It is now active on your profile and Digital ID card.');
        } else {
            Session::flash('error', 'Failed to save uploaded photo on server.');
        }

        header('Location: /member/profile.php');
        exit;
    }

    // 2. Personal & Service Details (Blood group, native district, batch, etc.)
    if ($action === 'update_personal_service') {
        ensureProfileExists($currentMemberId);
        $bloodGroup      = trim(Sanitize::string($_POST['blood_group'] ?? '', 10));
        $nativeDistrict  = trim(Sanitize::string($_POST['native_district'] ?? '', 100));
        $recruitmentBatch= trim(Sanitize::string($_POST['recruitment_batch'] ?? '', 50));
        $recruitmentType = trim(Sanitize::string($_POST['recruitment_type'] ?? '', 100));

        if ($bloodGroup !== '' && !in_array($bloodGroup, $allowedBloodGroups, true)) {
            Session::flash('error', 'Invalid blood group selected.');
            header('Location: /member/profile.php');
            exit;
        }

        Database::execute(
            'UPDATE member_profiles
             SET blood_group = ?, native_district = ?, recruitment_batch = ?, recruitment_type = ?, updated_at = NOW()
             WHERE member_id = ?',
            [
                $bloodGroup !== '' ? $bloodGroup : null,
                $nativeDistrict !== '' ? $nativeDistrict : null,
                $recruitmentBatch !== '' ? $recruitmentBatch : null,
                $recruitmentType !== '' ? $recruitmentType : null,
                $currentMemberId
            ]
        );

        Session::flash('success', 'Personal and service details updated successfully.');
        header('Location: /member/profile.php');
        exit;
    }

    // 3. Qualifications & Hobbies
    if ($action === 'update_qualifications') {
        ensureProfileExists($currentMemberId);
        $highestQual   = trim(Sanitize::string($_POST['highest_qualification'] ?? '', 100));
        $qualDetails   = trim(Sanitize::string($_POST['qualification_details'] ?? '', 255));
        $certifications= trim(Sanitize::string($_POST['additional_certifications'] ?? '', 255));
        $hobbies       = trim(Sanitize::string($_POST['hobbies_talents'] ?? '', 2000));

        Database::execute(
            'UPDATE member_profiles
             SET highest_qualification = ?, qualification_details = ?, additional_certifications = ?, hobbies_talents = ?, updated_at = NOW()
             WHERE member_id = ?',
            [
                $highestQual !== '' ? $highestQual : null,
                $qualDetails !== '' ? $qualDetails : null,
                $certifications !== '' ? $certifications : null,
                $hobbies !== '' ? $hobbies : null,
                $currentMemberId
            ]
        );

        Session::flash('success', 'Qualifications, certifications, and talents updated successfully.');
        header('Location: /member/profile.php');
        exit;
    }

    // 4. Welfare Survey: Housing Society & Co-Operative Bank
    if ($action === 'update_welfare_survey') {
        ensureProfileExists($currentMemberId);
        $housingInterest = Sanitize::string($_POST['interest_housing_society'] ?? 'considering', 20);
        $housingLocation = trim(Sanitize::string($_POST['housing_preferred_location'] ?? '', 150));
        $housingType     = trim(Sanitize::string($_POST['housing_preferred_type'] ?? '', 100));
        $bankInterest    = Sanitize::string($_POST['interest_coop_bank'] ?? 'considering', 20);
        $bankServices    = trim(Sanitize::string($_POST['coop_bank_services'] ?? '', 200));

        if (!in_array($housingInterest, ['yes', 'no', 'considering'], true)) {
            $housingInterest = 'considering';
        }
        if (!in_array($bankInterest, ['yes', 'no', 'considering'], true)) {
            $bankInterest = 'considering';
        }

        Database::execute(
            'UPDATE member_profiles
             SET interest_housing_society = ?, housing_preferred_location = ?, housing_preferred_type = ?,
                 interest_coop_bank = ?, coop_bank_services = ?, updated_at = NOW()
             WHERE member_id = ?',
            [
                $housingInterest,
                $housingLocation !== '' ? $housingLocation : null,
                $housingType !== '' ? $housingType : null,
                $bankInterest,
                $bankServices !== '' ? $bankServices : null,
                $currentMemberId
            ]
        );

        Session::flash('success', 'Thank you! Your preferences for Association Housing Society & Co-Operative Bank have been recorded.');
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

// Reload refreshed data
$memberRow = Database::fetchOne('SELECT * FROM members WHERE id = ?', [$currentMemberId]);
$profile   = Database::fetchOne('SELECT * FROM member_profiles WHERE member_id = ?', [$currentMemberId]) ?: [];
$successMsg = Session::getFlash('success');
$errorMsg   = Session::getFlash('error');

$photoUrl = null;
if (!empty($memberRow['photo_path']) && is_file(PUBLIC_HTML . '/' . ltrim($memberRow['photo_path'], '/'))) {
    $photoUrl = '/' . ltrim($memberRow['photo_path'], '/');
}
?>

<style>
.profile-hero-card {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #1e3a8a 100%);
    border-radius: 16px;
    padding: 28px 32px;
    color: #ffffff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 24px;
    margin-bottom: 24px;
    box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.25);
    border: 1px solid rgba(255, 255, 255, 0.1);
}
.profile-avatar-box {
    position: relative;
    width: 100px;
    height: 115px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.1);
    border: 2px solid rgba(255, 255, 255, 0.25);
    overflow: hidden;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}
.profile-avatar-box img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.profile-avatar-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #cbd5e1;
    font-size: 0.75rem;
    font-weight: 600;
}
.profile-avatar-trigger {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: rgba(15, 23, 42, 0.85);
    color: #ffffff;
    font-size: 0.65rem;
    font-weight: 600;
    text-align: center;
    padding: 3px 0;
    cursor: pointer;
    transition: background 0.2s;
}
.profile-avatar-trigger:hover {
    background: var(--blue-600);
}
.profile-details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px 28px;
}
.survey-card {
    background: linear-gradient(to bottom right, #ffffff, #f8fafc);
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    position: relative;
}
.badge-pill-yes {
    background: #dcfce7;
    color: #15803d;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.78rem;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.badge-pill-no {
    background: #fee2e2;
    color: #b91c1c;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.78rem;
}
.badge-pill-considering {
    background: #fef3c7;
    color: #b45309;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.78rem;
}
</style>

<!-- Page Notification Banners -->
<?php if ($successMsg): ?>
    <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:14px 18px; border-radius:10px; margin-bottom:20px; font-weight:600; font-size:0.92rem; display:flex; align-items:center; gap:10px; box-shadow:0 2px 4px rgba(0,0,0,0.04);">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        <?= Sanitize::html($successMsg) ?>
    </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:14px 18px; border-radius:10px; margin-bottom:20px; font-weight:600; font-size:0.92rem; display:flex; align-items:center; gap:10px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        <?= Sanitize::html($errorMsg) ?>
    </div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     1. PROFILE HERO BANNER WITH AVATAR & QUICK ACTIONS
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="profile-hero-card">
    <div style="display:flex; align-items:center; gap:22px; flex-wrap:wrap;">
        <!-- Avatar Photo Box -->
        <div class="profile-avatar-box">
            <?php if ($photoUrl): ?>
                <img src="<?= Sanitize::attr($photoUrl) ?>" alt="Member Photo">
            <?php else: ?>
                <div class="profile-avatar-placeholder">
                    <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    <span>No Photo</span>
                </div>
            <?php endif; ?>
            <div class="profile-avatar-trigger" onclick="openPhotoModal();" title="Click to upload/change photo">
                <?= $photoUrl ? 'Change' : 'Upload' ?>
            </div>
        </div>

        <div>
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <h1 style="font-size:1.5rem; font-weight:800; letter-spacing:-0.3px; margin:0; line-height:1.2;">
                    <?= Sanitize::html($portalMember['name'] ?? '') ?>
                </h1>
                <span class="badge badge-success" style="background:#10b981; color:#ffffff; font-weight:700;">
                    ✓ Active Member
                </span>
            </div>
            <div style="font-size:1rem; opacity:0.9; margin-top:4px; font-weight:500;">
                <?= Sanitize::html($portalMember['designation'] ?? 'Panchayat Development Officer') ?>
            </div>
            <div style="display:flex; align-items:center; gap:16px; margin-top:8px; font-size:0.85rem; opacity:0.85; flex-wrap:wrap;">
                <span>ID: <strong style="color:#67e8f9; font-family:monospace; font-size:0.95rem;"><?= Sanitize::html($portalMember['member_no'] ?? '') ?></strong></span>
                <span>•</span>
                <span>District: <strong><?= Sanitize::html($portalMember['district_name'] ?? '—') ?></strong></span>
                <span>•</span>
                <span>Blood Group: <strong style="color:#fca5a5;"><?= Sanitize::html($profile['blood_group'] ?? '—') ?></strong></span>
            </div>
        </div>
    </div>

    <!-- Quick Buttons -->
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <button type="button" class="btn btn-outline" onclick="openPhotoModal();" style="border-color:rgba(255,255,255,0.4); color:#ffffff; display:inline-flex; align-items:center; gap:6px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            <?= $photoUrl ? 'Change Photo' : 'Upload Photo' ?>
        </button>
        <a href="/member/id-card.php" class="btn btn-primary" style="background:#2563eb; display:inline-flex; align-items:center; gap:6px; box-shadow:0 4px 12px rgba(37,99,235,0.4);">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/></svg>
            Download Digital ID
        </a>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     2. PERSONAL & GOVERNMENT SERVICE RECORD
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card" style="margin-bottom:24px;">
    <div class="table-card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="table-card-title">Personal &amp; Service Information (ವೈಯಕ್ತಿಕ ಮತ್ತು ಸೇವಾ ವಿವರಗಳು)</span>
        <button type="button" class="btn btn-outline btn-sm" onclick="openPersonalModal();" style="display:inline-flex; align-items:center; gap:5px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
            Edit Personal Details
        </button>
    </div>
    <div style="padding:24px;">
        <div class="profile-details-grid">
            <div>
                <label class="form-label" style="color:var(--text-muted);">Member Number</label>
                <div style="font-size:1.15rem; font-weight:700; color:var(--blue-700); font-family:monospace;">
                    <?= Sanitize::html($portalMember['member_no'] ?? '') ?>
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
                <label class="form-label" style="color:var(--text-muted);">Blood Group (ರಕ್ತದ ಗುಂಪು)</label>
                <div style="font-size:1.1rem; font-weight:700; color:#dc2626;">
                    <?= !empty($profile['blood_group']) ? Sanitize::html($profile['blood_group']) : '<span style="color:var(--text-muted); font-size:0.9rem; font-weight:normal;">Not specified</span>' ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Recruitment Batch</label>
                <div style="font-size:1rem; font-weight:600; color:var(--text-main);">
                    <?= !empty($profile['recruitment_batch']) ? Sanitize::html($profile['recruitment_batch']) : '—' ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Recruitment Mode</label>
                <div style="font-size:1rem; font-weight:600; color:var(--text-main);">
                    <?= !empty($profile['recruitment_type']) ? Sanitize::html($profile['recruitment_type']) : '—' ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Native District (ಸ್ವಂತ ಜಿಲ್ಲೆ)</label>
                <div style="font-size:1rem; font-weight:600; color:var(--text-main);">
                    <?= !empty($profile['native_district']) ? Sanitize::html($profile['native_district']) : '—' ?>
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

<!-- ═══════════════════════════════════════════════════════════════════════════
     3. POSTING & OFFICIAL JURISDICTION
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card" style="margin-bottom:24px;">
    <div class="table-card-header">
        <span class="table-card-title">Official Posting &amp; Jurisdiction Details (ಸೇವಾ ಸ್ಥಳ ವಿವರ)</span>
    </div>
    <div style="padding:24px;">
        <div class="profile-details-grid">
            <div>
                <label class="form-label" style="color:var(--text-muted);">Working District</label>
                <div style="font-size:1.15rem; font-weight:700; color:var(--text-main);">
                    <?= Sanitize::html($portalMember['district_name'] ?? '—') ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Working Taluk</label>
                <div style="font-size:1.15rem; font-weight:700; color:var(--text-main);">
                    <?= Sanitize::html($portalMember['taluk_name'] ?? '—') ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Gram Panchayati</label>
                <div style="font-size:1.15rem; font-weight:700; color:var(--text-main);">
                    <?= Sanitize::html($portalMember['gp_name'] ?? '—') ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted);">Official Designation</label>
                <div style="font-size:1.05rem; font-weight:600; color:var(--text-main);">
                    <?= Sanitize::html($portalMember['designation'] ?? 'Panchayat Development Officer') ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     4. QUALIFICATIONS, SPECIAL TALENTS & HOBBIES
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card" style="margin-bottom:24px;">
    <div class="table-card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="table-card-title">Qualifications, Professional Skills &amp; Talents (ವಿದ್ಯಾರ್ಹತೆ ಮತ್ತು ಹವ್ಯಾಸಗಳು)</span>
        <button type="button" class="btn btn-outline btn-sm" onclick="openQualModal();" style="display:inline-flex; align-items:center; gap:5px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
            Update Qualifications &amp; Talents
        </button>
    </div>
    <div style="padding:24px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:24px;">
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:18px;">
                <div style="font-size:0.8rem; font-weight:700; color:var(--blue-700); text-transform:uppercase; letter-spacing:0.5px;">
                    Educational Background
                </div>
                <div style="font-size:1.15rem; font-weight:800; color:var(--text-main); margin-top:6px;">
                    <?= !empty($profile['highest_qualification']) ? Sanitize::html($profile['highest_qualification']) : '<span style="color:var(--text-muted); font-size:0.95rem; font-weight:normal;">Not specified</span>' ?>
                </div>
                <div style="font-size:0.92rem; color:var(--text-muted); margin-top:4px;">
                    <?= !empty($profile['qualification_details']) ? Sanitize::html($profile['qualification_details']) : 'Specialization / Degree details not added' ?>
                </div>
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:18px;">
                <div style="font-size:0.8rem; font-weight:700; color:var(--blue-700); text-transform:uppercase; letter-spacing:0.5px;">
                    Additional Certifications &amp; Training
                </div>
                <div style="font-size:1rem; font-weight:600; color:var(--text-main); margin-top:6px;">
                    <?= !empty($profile['additional_certifications']) ? Sanitize::html($profile['additional_certifications']) : '<span style="color:var(--text-muted); font-weight:normal;">None recorded</span>' ?>
                </div>
                <div style="font-size:0.8rem; color:var(--text-muted); margin-top:4px;">
                    Computer knowledge (CCC), Departmental examinations, etc.
                </div>
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:18px; grid-column: 1 / -1;">
                <div style="font-size:0.8rem; font-weight:700; color:var(--blue-700); text-transform:uppercase; letter-spacing:0.5px;">
                    Hobbies, Sports &amp; Cultural Talents (ಕ್ರೀಡೆ ಮತ್ತು ಸಾಂಸ್ಕೃತಿಕ ಹವ್ಯಾಸಗಳು)
                </div>
                <div style="font-size:0.95rem; font-weight:500; color:var(--text-main); margin-top:6px; line-height:1.5;">
                    <?= !empty($profile['hobbies_talents']) ? nl2br(Sanitize::html($profile['hobbies_talents'])) : '<span style="color:var(--text-muted);">No hobbies or special talents specified yet. Click above to add sports (Cricket, Volleyball), singing, writing, drama, or volunteer interests.</span>' ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     5. ASSOCIATION WELFARE INITIATIVES (HOUSING SOCIETY & CO-OP BANK)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card" style="margin-bottom:24px;">
    <div class="table-card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <div>
            <span class="table-card-title">Association Welfare Initiatives Survey (ಸಂಘದ ಕಲ್ಯಾಣ ಉಪಕ್ರಮಗಳು)</span>
            <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">
                Express your preference for upcoming association projects
            </div>
        </div>
        <button type="button" class="btn btn-primary btn-sm" onclick="openWelfareModal();" style="display:inline-flex; align-items:center; gap:5px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            Update Welfare Preferences
        </button>
    </div>
    <div style="padding:24px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:24px;">
            <!-- Housing Society Card -->
            <div class="survey-card" style="border-left:4px solid #3b82f6;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                    <div style="font-size:1.05rem; font-weight:800; color:var(--text-main);">
                        🏡 KSPDOWA Employees Housing Society
                    </div>
                    <?php
                    $hStat = $profile['interest_housing_society'] ?? 'considering';
                    if ($hStat === 'yes') {
                        echo '<span class="badge-pill-yes">✓ Interested</span>';
                    } elseif ($hStat === 'no') {
                        echo '<span class="badge-pill-no">✕ Not Interested</span>';
                    } else {
                        echo '<span class="badge-pill-considering">Seeking Details</span>';
                    }
                    ?>
                </div>
                <div style="font-size:0.82rem; color:var(--text-muted); margin-top:6px; line-height:1.4;">
                    Karnataka State PDO Welfare Association Employees Housing Co-Operative Society (ಕರ್ನಾಟಕ ರಾಜ್ಯ ಪಿ.ಡಿ.ಒ ವಸತಿ ನಿರ್ಮಾಣ ಸಹಕಾರ ಸಂಘ).
                </div>
                <div style="margin-top:14px; padding-top:12px; border-top:1px dashed #e2e8f0; font-size:0.88rem;">
                    <div>Preferred Location: <strong><?= !empty($profile['housing_preferred_location']) ? Sanitize::html($profile['housing_preferred_location']) : '<span style="color:var(--text-muted);">Not chosen</span>' ?></strong></div>
                    <div style="margin-top:4px;">Preferred Site Type: <strong><?= !empty($profile['housing_preferred_type']) ? Sanitize::html($profile['housing_preferred_type']) : '<span style="color:var(--text-muted);">Not chosen</span>' ?></strong></div>
                </div>
            </div>

            <!-- Co-Operative Bank Card -->
            <div class="survey-card" style="border-left:4px solid #10b981;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                    <div style="font-size:1.05rem; font-weight:800; color:var(--text-main);">
                        🏦 KSPDOWA Co-Operative Bank / Society
                    </div>
                    <?php
                    $bStat = $profile['interest_coop_bank'] ?? 'considering';
                    if ($bStat === 'yes') {
                        echo '<span class="badge-pill-yes">✓ Interested</span>';
                    } elseif ($bStat === 'no') {
                        echo '<span class="badge-pill-no">✕ Not Interested</span>';
                    } else {
                        echo '<span class="badge-pill-considering">Seeking Details</span>';
                    }
                    ?>
                </div>
                <div style="font-size:0.82rem; color:var(--text-muted); margin-top:6px; line-height:1.4;">
                    Karnataka State PDO Souharda Co-Operative Credit Society (ಸೌಹಾರ್ದ ಸಹಕಾರಿ ಬ್ಯಾಂಕ್ / ನೌಕರರ ಪತ್ತಿನ ಸಹಕಾರ ಸಂಘ).
                </div>
                <div style="margin-top:14px; padding-top:12px; border-top:1px dashed #e2e8f0; font-size:0.88rem;">
                    <div>Interest / Services: <strong><?= !empty($profile['coop_bank_services']) ? Sanitize::html($profile['coop_bank_services']) : '<span style="color:var(--text-muted);">Not chosen</span>' ?></strong></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL 1: PHOTO UPLOAD MODAL (With Live Preview)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="photoModal" onclick="if(event.target===this) closePhotoModal();">
    <div class="modal-dialog" style="max-width:440px;">
        <div class="modal-header">
            <h3 class="modal-title">Upload Member Photo (ಭಾವಚಿತ್ರ ಅಪ್‌ಲೋಡ್)</h3>
            <button type="button" class="modal-close-btn" onclick="closePhotoModal();">&times;</button>
        </div>
        <form method="post" action="/member/profile.php" enctype="multipart/form-data">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="upload_photo">

            <div class="modal-body">
                <div style="text-align:center; margin-bottom:18px;">
                    <div id="photoPreviewContainer" style="width:120px; height:140px; margin:0 auto; border-radius:10px; border:2px dashed #94a3b8; display:flex; align-items:center; justify-content:center; overflow:hidden; background:#f8fafc;">
                        <?php if ($photoUrl): ?>
                            <img id="photoPreviewImg" src="<?= Sanitize::attr($photoUrl) ?>" style="width:100%; height:100%; object-fit:cover;">
                        <?php else: ?>
                            <div id="photoPlaceholderText" style="color:#94a3b8; font-size:0.8rem; text-align:center; padding:10px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                <div>Preview</div>
                            </div>
                            <img id="photoPreviewImg" src="" style="width:100%; height:100%; object-fit:cover; display:none;">
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label" for="photo">Select Passport Photo *</label>
                    <input type="file" name="photo" id="photoInput" class="form-control" accept=".jpg,.jpeg,.png,.webp" required onchange="previewSelectedPhoto(this);">
                </div>
                <div style="font-size:0.78rem; color:var(--text-muted); line-height:1.4;">
                    ● Max size: <strong>2 MB</strong> • Formats: <strong>JPG, PNG, WebP</strong><br>
                    ● Please use a clear passport-size photo with plain background. This photo will be printed on your official Digital ID Card.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closePhotoModal();">Cancel</button>
                <button type="submit" class="btn btn-primary">Upload &amp; Save Photo</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL 2: EDIT PERSONAL & SERVICE DETAILS
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="personalModal" onclick="if(event.target===this) closePersonalModal();">
    <div class="modal-dialog" style="max-width:520px;">
        <div class="modal-header">
            <h3 class="modal-title">Edit Personal &amp; Service Details</h3>
            <button type="button" class="modal-close-btn" onclick="closePersonalModal();">&times;</button>
        </div>
        <form method="post" action="/member/profile.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="update_personal_service">

            <div class="modal-body">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-bottom:14px;">
                    <div class="form-group">
                        <label class="form-label" for="modal_blood_group">Blood Group *</label>
                        <select name="blood_group" id="modal_blood_group" class="form-select">
                            <option value="">— Select Blood Group —</option>
                            <?php foreach ($allowedBloodGroups as $bg): ?>
                                <option value="<?= $bg ?>" <?= (($profile['blood_group'] ?? '') === $bg) ? 'selected' : '' ?>>
                                    <?= $bg ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="native_district">Native District (ಸ್ವಂತ ಜಿಲ್ಲೆ)</label>
                        <input type="text" name="native_district" id="native_district" class="form-control" value="<?= Sanitize::attr($profile['native_district'] ?? '') ?>" placeholder="e.g. Belagavi, Mandya...">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-bottom:14px;">
                    <div class="form-group">
                        <label class="form-label" for="recruitment_batch">Recruitment Batch Year</label>
                        <input type="text" name="recruitment_batch" id="recruitment_batch" class="form-control" value="<?= Sanitize::attr($profile['recruitment_batch'] ?? '') ?>" placeholder="e.g. 2011 Batch, 2017 Batch">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="recruitment_type">Mode of Recruitment</label>
                        <select name="recruitment_type" id="recruitment_type" class="form-select">
                            <option value="">— Select Mode —</option>
                            <?php foreach ($allowedRecruitmentTypes as $rt): ?>
                                <option value="<?= $rt ?>" <?= (($profile['recruitment_type'] ?? '') === $rt) ? 'selected' : '' ?>>
                                    <?= $rt ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="font-size:0.8rem; color:var(--text-muted);">
                    Note: To correct KGID, Date of Birth, or Posting Jurisdiction, please submit an official grievance or contact your District Association administrator.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closePersonalModal();">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Details</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL 3: QUALIFICATIONS & TALENTS MODAL
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="qualModal" onclick="if(event.target===this) closeQualModal();">
    <div class="modal-dialog" style="max-width:540px;">
        <div class="modal-header">
            <h3 class="modal-title">Qualifications &amp; Personal Talents</h3>
            <button type="button" class="modal-close-btn" onclick="closeQualModal();">&times;</button>
        </div>
        <form method="post" action="/member/profile.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="update_qualifications">

            <div class="modal-body">
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label" for="highest_qualification">Highest Qualification *</label>
                    <select name="highest_qualification" id="highest_qualification" class="form-select" required>
                        <option value="">— Select Highest Education —</option>
                        <?php foreach ($allowedQualifications as $q): ?>
                            <option value="<?= $q ?>" <?= (($profile['highest_qualification'] ?? '') === $q) ? 'selected' : '' ?>>
                                <?= $q ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label" for="qualification_details">Degree / Subject Specialization</label>
                    <input type="text" name="qualification_details" id="qualification_details" class="form-control" value="<?= Sanitize::attr($profile['qualification_details'] ?? '') ?>" placeholder="e.g. B.A. Economics, M.Sc Rural Development, MBA HR...">
                </div>

                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label" for="additional_certifications">Additional Certifications &amp; Departmental Tests</label>
                    <input type="text" name="additional_certifications" id="additional_certifications" class="form-control" value="<?= Sanitize::attr($profile['additional_certifications'] ?? '') ?>" placeholder="e.g. CCC Computer Certificate, Revenue Higher, Accounts Higher...">
                </div>

                <div class="form-group" style="margin-bottom:10px;">
                    <label class="form-label" for="hobbies_talents">Hobbies, Sports &amp; Cultural Talents (ಕ್ರೀಡೆ ಮತ್ತು ಸಾಂಸ್ಕೃತಿಕ ಆಸಕ್ತಿ)</label>
                    <textarea name="hobbies_talents" id="hobbies_talents" rows="3" class="form-control" placeholder="e.g. Cricket, Badminton player, Kannada Bhavageethe singer, Drama/Theatre, Literature/Poetry, Blood Donor..."><?= Sanitize::html($profile['hobbies_talents'] ?? '') ?></textarea>
                    <div style="font-size:0.78rem; color:var(--text-muted); margin-top:3px;">
                        This helps the association identify members for state/district level sports meets, cultural programs, and committees.
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeQualModal();">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Qualifications</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL 4: WELFARE SURVEY MODAL (HOUSING & CO-OP BANK)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="welfareModal" onclick="if(event.target===this) closeWelfareModal();">
    <div class="modal-dialog" style="max-width:560px;">
        <div class="modal-header">
            <h3 class="modal-title">Association Welfare Survey (ಕಲ್ಯಾಣ ಯೋಜನೆಗಳು)</h3>
            <button type="button" class="modal-close-btn" onclick="closeWelfareModal();">&times;</button>
        </div>
        <form method="post" action="/member/profile.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="update_welfare_survey">

            <div class="modal-body">
                <!-- Section A: Housing Society -->
                <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:16px; margin-bottom:18px;">
                    <div style="font-weight:700; color:#1e40af; font-size:0.95rem; margin-bottom:8px;">
                        🏡 KSPDOWA Employees Housing Society (ವಸತಿ ಗೃಹ ನಿರ್ಮಾಣ ಸಹಕಾರ ಸಂಘ)
                    </div>
                    <div class="form-group" style="margin-bottom:10px;">
                        <label class="form-label" style="font-size:0.85rem;">Are you interested in acquiring a site/house through the Association?</label>
                        <select name="interest_housing_society" class="form-select" style="font-size:0.9rem;">
                            <option value="yes" <?= (($profile['interest_housing_society'] ?? '') === 'yes') ? 'selected' : '' ?>>Yes, I am interested (ಹೌದು, ಆಸಕ್ತಿ ಇದೆ)</option>
                            <option value="considering" <?= (($profile['interest_housing_society'] ?? '') === 'considering') ? 'selected' : '' ?>>Seeking more details / Considering (ವಿವರ ತಿಳಿದು ನಿರ್ಧರಿಸುವೆ)</option>
                            <option value="no" <?= (($profile['interest_housing_society'] ?? '') === 'no') ? 'selected' : '' ?>>No, not interested (ಆಸಕ್ತಿ ಇಲ್ಲ)</option>
                        </select>
                    </div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                        <div class="form-group">
                            <label class="form-label" style="font-size:0.8rem;">Preferred Location</label>
                            <input type="text" name="housing_preferred_location" class="form-control" value="<?= Sanitize::attr($profile['housing_preferred_location'] ?? '') ?>" placeholder="e.g. Bengaluru, District HQ...">
                        </div>
                        <div class="form-group">
                            <label class="form-label" style="font-size:0.8rem;">Preferred Type</label>
                            <select name="housing_preferred_type" class="form-select" style="font-size:0.85rem;">
                                <option value="">— Select Type —</option>
                                <option value="30x40 Site" <?= (($profile['housing_preferred_type'] ?? '') === '30x40 Site') ? 'selected' : '' ?>>30x40 Site (ನಿವೇಶನ)</option>
                                <option value="40x60 Site" <?= (($profile['housing_preferred_type'] ?? '') === '40x60 Site') ? 'selected' : '' ?>>40x60 Site (ನಿವೇಶನ)</option>
                                <option value="2BHK Flat / Villa" <?= (($profile['housing_preferred_type'] ?? '') === '2BHK Flat / Villa') ? 'selected' : '' ?>>2BHK Flat / Villa</option>
                                <option value="3BHK Flat / Villa" <?= (($profile['housing_preferred_type'] ?? '') === '3BHK Flat / Villa') ? 'selected' : '' ?>>3BHK Flat / Villa</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Section B: Co-Operative Bank -->
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:16px;">
                    <div style="font-weight:700; color:#15803d; font-size:0.95rem; margin-bottom:8px;">
                        🏦 KSPDOWA Co-Operative Credit Society / Bank (ಸೌಹಾರ್ದ ಸಹಕಾರಿ ಬ್ಯಾಂಕ್)
                    </div>
                    <div class="form-group" style="margin-bottom:10px;">
                        <label class="form-label" style="font-size:0.85rem;">Are you interested in participating in the Association Credit Society?</label>
                        <select name="interest_coop_bank" class="form-select" style="font-size:0.9rem;">
                            <option value="yes" <?= (($profile['interest_coop_bank'] ?? '') === 'yes') ? 'selected' : '' ?>>Yes, I am interested (ಹೌದು, ಆಸಕ್ತಿ ಇದೆ)</option>
                            <option value="considering" <?= (($profile['interest_coop_bank'] ?? '') === 'considering') ? 'selected' : '' ?>>Seeking more details / Considering (ವಿವರ ತಿಳಿದು ನಿರ್ಧರಿಸುವೆ)</option>
                            <option value="no" <?= (($profile['interest_coop_bank'] ?? '') === 'no') ? 'selected' : '' ?>>No, not interested (ಆಸಕ್ತಿ ಇಲ್ಲ)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-size:0.8rem;">Preferred Services / Participation</label>
                        <input type="text" name="coop_bank_services" class="form-control" value="<?= Sanitize::attr($profile['coop_bank_services'] ?? '') ?>" placeholder="e.g. Shareholder, Low-interest personal loan, Recurring deposit...">
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeWelfareModal();">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Welfare Preferences</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPhotoModal() { document.getElementById('photoModal').classList.add('open'); }
function closePhotoModal() { document.getElementById('photoModal').classList.remove('open'); }

function openPersonalModal() { document.getElementById('personalModal').classList.add('open'); }
function closePersonalModal() { document.getElementById('personalModal').classList.remove('open'); }

function openQualModal() { document.getElementById('qualModal').classList.add('open'); }
function closeQualModal() { document.getElementById('qualModal').classList.remove('open'); }

function openWelfareModal() { document.getElementById('welfareModal').classList.add('open'); }
function closeWelfareModal() { document.getElementById('welfareModal').classList.remove('open'); }

function previewSelectedPhoto(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const previewImg = document.getElementById('photoPreviewImg');
            const placeholder = document.getElementById('photoPlaceholderText');
            previewImg.src = e.target.result;
            previewImg.style.display = 'block';
            if (placeholder) {
                placeholder.style.display = 'none';
            }
        };
        reader.readAsDataURL(input.files[0]);
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePhotoModal();
        closePersonalModal();
        closeQualModal();
        closeWelfareModal();
    }
});
</script>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
