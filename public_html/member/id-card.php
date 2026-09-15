<?php
/**
 * KSPDOWA — Member Portal: Official Digital ID Card
 * ============================================================
 * Section 8: Digital ID Card (Front & Back, .jpg & .pdf download, print)
 * Global Standard Horizontal CR80 Format (85.6mm x 54mm ratio)
 * Side-by-side combined JPG & PDF export with dynamic settings & logos
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

$profile     = Database::fetchOne('SELECT * FROM member_profiles WHERE member_id = ?', [$currentMemberId]) ?: [];
$currentYear = Membership::getCurrentYear();
$fy          = $currentYear['financial_year'] ?? date('Y') . '-' . (date('y') + 1);
$validTillDate = !empty($currentYear['end_date'])
    ? date('d-m-Y', strtotime($currentYear['end_date']))
    : '31-03-' . (date('Y') + 1);
$validityDisplay = 'Valid Till: ' . $validTillDate . ' (FY ' . $fy . ')';

// Association Identity & Contact Settings
$siteName     = Settings::get('site_name', 'Karnataka State Panchayat Development Officers Welfare Association (R)');
$siteTagline  = Settings::get('site_tagline', 'Reg. No. DRB/SOR/534/2012-13 • Bengaluru');
$siteAddress  = Settings::get('site_address', 'State Central Office, Bengaluru, Karnataka');
$sitePhone    = Settings::get('site_phone', '9036880026');
$siteEmail    = Settings::get('site_email', 'contact@kspdowa.org');
$siteWebsite  = Settings::get('site_website', 'https://kspdowa.org');

// Helper to resolve logo to absolute path
$resolveLogoPath = function(string $settingKey, string $defaultRel): string {
    $val = trim(Settings::get($settingKey, ''));
    if ($val !== '') {
        $cand = PUBLIC_HTML . '/' . ltrim($val, '/');
        if (is_file($cand)) {
            return $cand;
        }
    }
    return PUBLIC_HTML . '/' . $defaultRel;
};

$logoLeftAbs  = $resolveLogoPath('receipt_logo_left', 'assets/images/receipt-logo-left.png');
$logoRightAbs = $resolveLogoPath('receipt_logo_right', 'assets/images/receipt-logo-right.png');

// Encode images as base64 Data URIs to eliminate cross-origin & canvas taint issues in html2canvas
$toBase64DataUri = function(string $path): string {
    if (!is_file($path)) {
        return '';
    }
    $mime = mime_content_type($path) ?: 'image/png';
    $raw  = file_get_contents($path);
    return 'data:' . $mime . ';base64,' . base64_encode($raw);
};

$logoLeftDataUri  = is_file($logoLeftAbs) ? $toBase64DataUri($logoLeftAbs) : '';
$logoRightDataUri = is_file($logoRightAbs) ? $toBase64DataUri($logoRightAbs) : '';

// Member Photo Data URI
$photoDataUri = null;
if (!empty($portalMember['photo_path'])) {
    $photoAbs = PUBLIC_HTML . '/' . ltrim($portalMember['photo_path'], '/');
    if (is_file($photoAbs)) {
        $photoDataUri = $toBase64DataUri($photoAbs);
    }
}

// Check if member is an active Office Bearer (State, District, or Taluk representative)
$officeBearer = Database::fetchOne(
    'SELECT ob.*, d.name AS ob_district_name, t.name AS ob_taluk_name
     FROM office_bearers ob
     LEFT JOIN districts d ON d.id = ob.district_id
     LEFT JOIN taluks t    ON t.id = ob.taluk_id
     WHERE ob.member_id = ? AND ob.status = "active"
     ORDER BY ob.sort_order ASC, ob.id DESC
     LIMIT 1',
    [$currentMemberId]
);

$assocDesignation = '';
$assocSubLabel    = '';
$isRepresentative = false;

if ($officeBearer) {
    $isRepresentative = true;
    $assocDesignation = $officeBearer['association_designation'];

    // Level indicator
    if (!empty($officeBearer['taluk_id']) && !empty($officeBearer['ob_taluk_name'])) {
        $assocSubLabel = 'Taluk Unit (' . $officeBearer['ob_taluk_name'] . ')';
    } elseif (!empty($officeBearer['district_id']) && !empty($officeBearer['ob_district_name'])) {
        $assocSubLabel = 'District Unit (' . $officeBearer['ob_district_name'] . ')';
    } else {
        $assocSubLabel = 'State Committee (ರಾಜ್ಯ ಸಮಿತಿ)';
    }

    if (!empty($officeBearer['term_start']) || !empty($officeBearer['term_end'])) {
        $startYear = !empty($officeBearer['term_start']) ? date('Y', strtotime($officeBearer['term_start'])) : '';
        $endYear   = !empty($officeBearer['term_end'])   ? date('Y', strtotime($officeBearer['term_end']))   : 'Present';
        $assocSubLabel .= ' • ' . ($startYear ? $startYear . '–' : '') . $endYear;
    }
} elseif (!empty($portalMember['association_designation'])) {
    $assocDesignation = $portalMember['association_designation'];
} else {
    $assocDesignation = 'Active Member (ಸಕ್ರಿಯ ಸದಸ್ಯರು)';
}
?>

<!-- Load html2canvas for instant 300-DPI high-res JPG export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<style>
/* ─────────────────────────────────────────────────────────────────────────────
   GLOBAL STANDARD HORIZONTAL CR80 ID CARD (520px x 328px — 1.585:1 Aspect Ratio)
   ───────────────────────────────────────────────────────────────────────────── */
.id-card-cr80 {
    width: 520px;
    height: 328px;
    background: #ffffff;
    border-radius: 14px;
    box-shadow: 0 10px 25px -4px rgba(15, 23, 42, 0.16), 0 0 0 1px rgba(203, 213, 225, 0.9);
    overflow: hidden;
    position: relative;
    user-select: none;
    display: flex;
    flex-direction: column;
    box-sizing: border-box;
    flex-shrink: 0;
}

/* Header Gradient */
.id-cr80-header {
    background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 45%, #2563eb 100%);
    color: #ffffff;
    padding: 7px 12px;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 2.5px solid #f59e0b;
    box-sizing: border-box;
    position: relative;
}
.id-cr80-logo {
    width: 46px;
    height: 46px;
    border-radius: 6px;
    background: #ffffff;
    padding: 2px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.id-cr80-logo img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}
.id-cr80-header-center {
    flex: 1;
    text-align: center;
    padding: 0 8px;
    overflow: hidden;
}
.id-cr80-header-kannada {
    font-size: 9.5px;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: 0.2px;
    line-height: 1.15;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.id-cr80-header-title {
    font-size: 8.5px;
    font-weight: 800;
    color: #fde047;
    letter-spacing: 0.3px;
    line-height: 1.15;
    text-transform: uppercase;
    margin-top: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.id-cr80-header-tagline {
    font-size: 7.5px;
    color: #e0e7ff;
    letter-spacing: 0.2px;
    line-height: 1.1;
    margin-top: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Body Layout */
.id-cr80-body {
    flex: 1;
    display: flex;
    padding: 8px 12px 6px;
    position: relative;
    background: #ffffff;
    overflow: hidden;
    box-sizing: border-box;
}
.id-cr80-watermark {
    position: absolute;
    top: 52%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-18deg);
    font-size: 3.2rem;
    font-weight: 900;
    color: rgba(30, 64, 175, 0.035);
    pointer-events: none;
    white-space: nowrap;
    letter-spacing: 3px;
    z-index: 0;
}

/* Left Column: Photo, Blood Group, Validity */
.id-cr80-col-photo {
    width: 106px;
    display: flex;
    flex-direction: column;
    align-items: center;
    flex-shrink: 0;
    margin-right: 12px;
    z-index: 1;
}
.id-cr80-photo-box {
    width: 104px;
    height: 122px;
    border-radius: 8px;
    background: #f8fafc;
    border: 2px solid #3b82f6;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1);
}
.id-cr80-photo-box img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.id-cr80-blood-pill {
    width: 104px;
    margin-top: 5px;
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #dc2626;
    font-size: 10px;
    font-weight: 800;
    text-align: center;
    border-radius: 4px;
    padding: 2px 0;
    line-height: 1.1;
}
.id-cr80-validity-pill {
    width: 104px;
    margin-top: 4px;
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    color: #065f46;
    font-size: 8px;
    font-weight: 700;
    text-align: center;
    border-radius: 4px;
    padding: 2px 1px;
    line-height: 1.15;
}

/* Right Column: Member Details */
.id-cr80-col-details {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    z-index: 1;
    overflow: hidden;
}
.id-cr80-member-name {
    font-size: 14px;
    font-weight: 900;
    color: #0f172a;
    line-height: 1.2;
    text-transform: uppercase;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.id-cr80-badges-row {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 2px;
}
.id-cr80-member-no {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 10.5px;
    font-weight: 800;
    color: #1e40af;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    padding: 1px 7px;
    border-radius: 4px;
}
.id-cr80-status-pill {
    font-size: 8.5px;
    font-weight: 800;
    color: #15803d;
    background: #dcfce7;
    border: 1px solid #bbf7d0;
    padding: 1px 6px;
    border-radius: 4px;
}

/* Association Designation Banner */
.id-cr80-assoc-banner {
    margin-top: 5px;
    padding: 4px 8px;
    border-radius: 5px;
    border-left: 3.5px solid #2563eb;
    background: #f0fdf4;
    border-top: 1px solid #e2e8f0;
    border-right: 1px solid #e2e8f0;
    border-bottom: 1px solid #e2e8f0;
}
.id-cr80-assoc-rep {
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
    border-left-color: #d97706;
    border-color: #fde68a;
}
.id-cr80-assoc-label {
    font-size: 7.5px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    line-height: 1;
}
.id-cr80-assoc-title {
    font-size: 11.5px;
    font-weight: 900;
    color: #1e3a8a;
    line-height: 1.25;
    margin-top: 1px;
}
.id-cr80-assoc-rep .id-cr80-assoc-title {
    color: #92400e;
}
.id-cr80-assoc-sub {
    font-size: 8.5px;
    font-weight: 700;
    color: #b45309;
    margin-top: 1px;
}

/* Official Service Details Grid */
.id-cr80-info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 3px 8px;
    font-size: 9px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 5px 8px;
    margin-top: 5px;
}
.id-cr80-info-label {
    color: #64748b;
    font-size: 7.5px;
    display: block;
    line-height: 1.1;
}
.id-cr80-info-val {
    color: #0f172a;
    font-weight: 700;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Footer Bar */
.id-cr80-footer {
    height: 32px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    padding: 0 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 8px;
    color: #64748b;
    box-sizing: border-box;
}

/* ─────────────────────────────────────────────────────────────────────────────
   BACK SIDE STYLES
   ───────────────────────────────────────────────────────────────────────────── */
.id-cr80-back-header {
    background: #0f172a;
    color: #ffffff;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-bottom: 2.5px solid #f59e0b;
    text-align: center;
    padding: 0 12px;
}
.id-cr80-back-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 10px 14px 8px;
    background: #ffffff;
    box-sizing: border-box;
    font-size: 8.5px;
    color: #334155;
    position: relative;
}
.id-cr80-back-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px 12px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 6px 8px;
}
.id-cr80-office-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 6px 8px;
    margin-top: 6px;
    line-height: 1.35;
    font-size: 8px;
}

/* Responsive side-by-side export container */
.id-export-container {
    display: flex;
    flex-direction: row;
    gap: 28px;
    justify-content: center;
    align-items: flex-start;
    background: #ffffff;
    padding: 24px;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
    width: fit-content;
    margin: 0 auto;
}

@media (max-width: 1140px) {
    .id-export-container {
        flex-direction: column;
        align-items: center;
        padding: 16px;
    }
}

/* Print CSS */
@media print {
    body * { visibility: hidden; }
    #idCardExportContainer, #idCardExportContainer * { visibility: visible; }
    #idCardExportContainer {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        display: flex !important;
        flex-direction: row !important;
        gap: 20px !important;
        justify-content: center !important;
        background: none !important;
        box-shadow: none !important;
        padding: 0 !important;
    }
    .no-print { display: none !important; }
}
</style>

<!-- Top Action Row -->
<div class="page-header-row no-print">
    <div>
        <h1 class="page-heading-title">Official Digital ID Card</h1>
        <p class="page-heading-subtitle">CR80 Global Standard Horizontal Membership Card • Front &amp; Back</p>
    </div>

    <!-- Download & Print Action Buttons -->
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <!-- 1. Download Combined JPG (Front & Back side by side) -->
        <button type="button" class="btn btn-primary" onclick="downloadCardAsJpg();" id="btnDownloadJpg" style="background:#059669; border-color:#059669; display:inline-flex; align-items:center; gap:6px; font-weight:700;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            Download Image (.JPG)
        </button>

        <!-- 2. Download PDF -->
        <a href="/member/id-card-pdf.php?dl=1" class="btn btn-primary" style="background:#2563eb; border-color:#2563eb; display:inline-flex; align-items:center; gap:6px; font-weight:700;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Download PDF (.PDF)
        </a>

        <!-- 3. Print -->
        <button type="button" class="btn btn-outline" onclick="window.print();" style="display:inline-flex; align-items:center; gap:6px; font-weight:600;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            Print Card
        </button>
    </div>
</div>

<!-- Guidance notice -->
<div class="no-print" style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; padding:12px 18px; border-radius:10px; margin-bottom:24px; font-size:0.86rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    <div>
        💡 <strong>CR80 Standard Format:</strong> Standard 85.6mm × 54mm horizontal identity card. Clicking <strong>Download Image (.JPG)</strong> generates both Front and Back sides side-by-side in high-resolution (300 DPI) for instant PVC printing.
    </div>
    <a href="/member/profile.php" class="btn btn-outline btn-sm" style="background:#ffffff; color:#1e40af; font-weight:700;">Update Photo in Profile &rarr;</a>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     SIDE-BY-SIDE CARDS WRAPPER (CAPTURED FOR SINGLE COMBINED JPG EXPORT)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div id="idCardExportContainer" class="id-export-container">

    <!-- ───────────────────────────────────────────────────────────────────
         SIDE 1: FRONT FACE (HORIZONTAL CR80)
         ─────────────────────────────────────────────────────────────────── -->
    <div>
        <div style="text-align:center; margin-bottom:8px; font-size:0.75rem; font-weight:800; color:var(--text-muted); text-transform:uppercase; letter-spacing:1px;" class="no-print">
            Front Face (ಮುಂಭಾಗ)
        </div>

        <div class="id-card-cr80" id="idCardFront">
            <!-- Header with Dual Logos & Association Identity -->
            <div class="id-cr80-header">
                <!-- Left Logo -->
                <div class="id-cr80-logo">
                    <?php if ($logoLeftDataUri): ?>
                        <img src="<?= $logoLeftDataUri ?>" alt="Left Logo">
                    <?php else: ?>
                        <div style="font-size:8px; font-weight:900; color:#1e3a8a;">KSPDOWA</div>
                    <?php endif; ?>
                </div>

                <!-- Center Header Titles -->
                <div class="id-cr80-header-center">
                    <div class="id-cr80-header-kannada">ಕರ್ನಾಟಕ ರಾಜ್ಯ ಪಂಚಾಯತ್ ಅಭಿವೃದ್ಧಿ ಅಧಿಕಾರಿಗಳ ಕ್ಷೇಮಾಭಿವೃದ್ಧಿ ಸಂಘ (ರಿ.)</div>
                    <div class="id-cr80-header-title"><?= Sanitize::html($siteName) ?></div>
                    <div class="id-cr80-header-tagline"><?= Sanitize::html($siteTagline) ?></div>
                </div>

                <!-- Right Logo -->
                <div class="id-cr80-logo">
                    <?php if ($logoRightDataUri): ?>
                        <img src="<?= $logoRightDataUri ?>" alt="Right Logo">
                    <?php else: ?>
                        <div style="font-size:8px; font-weight:900; color:#1e3a8a;">EMBLEM</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Card Body -->
            <div class="id-cr80-body">
                <div class="id-cr80-watermark">KSPDOWA</div>

                <!-- Left Column: Photo & Badges -->
                <div class="id-cr80-col-photo">
                    <div class="id-cr80-photo-box">
                        <?php if ($photoDataUri): ?>
                            <img src="<?= $photoDataUri ?>" alt="Member Photo">
                        <?php else: ?>
                            <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="#94a3b8" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            <span style="font-size:7px; font-weight:700; color:#94a3b8; text-transform:uppercase; margin-top:2px;">No Photo</span>
                        <?php endif; ?>
                    </div>

                    <!-- Blood Group -->
                    <div class="id-cr80-blood-pill">
                        Blood: <?= Sanitize::html($profile['blood_group'] ?? '—') ?>
                    </div>

                    <!-- Validity Period -->
                    <div class="id-cr80-validity-pill">
                        <span style="display:block; font-size:6.5px; color:#047857; font-weight:800; text-transform:uppercase;">VALID TILL</span>
                        <strong><?= $validTillDate ?></strong>
                    </div>
                </div>

                <!-- Right Column: Name, Association Designation, Official Details -->
                <div class="id-cr80-col-details">
                    <div>
                        <!-- Member Name -->
                        <div class="id-cr80-member-name">
                            <?= Sanitize::html($portalMember['name'] ?? '') ?>
                        </div>

                        <!-- Member No & Verified Status -->
                        <div class="id-cr80-badges-row">
                            <span class="id-cr80-member-no"><?= Sanitize::html($portalMember['member_no'] ?? '') ?></span>
                            <span class="id-cr80-status-pill">✓ VERIFIED</span>
                        </div>

                        <!-- Association Designation (Dynamic replacing PDO) -->
                        <div class="id-cr80-assoc-banner <?= $isRepresentative ? 'id-cr80-assoc-rep' : '' ?>">
                            <div class="id-cr80-assoc-label">Association Designation (ಸಂಘದ ಹುದ್ದೆ)</div>
                            <div class="id-cr80-assoc-title"><?= Sanitize::html($assocDesignation) ?></div>
                            <?php if ($assocSubLabel): ?>
                                <div class="id-cr80-assoc-sub">★ <?= Sanitize::html($assocSubLabel) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Official Civil Post & Location Grid -->
                    <div class="id-cr80-info-grid">
                        <div>
                            <span class="id-cr80-info-label">Official Post:</span>
                            <span class="id-cr80-info-val"><?= Sanitize::html($portalMember['designation'] ?? 'Panchayat Development Officer') ?></span>
                        </div>

                        <div>
                            <span class="id-cr80-info-label">KGID Number:</span>
                            <span class="id-cr80-info-val" style="font-family:monospace;"><?= Sanitize::html($profile['kgid_no'] ?? '—') ?></span>
                        </div>

                        <div>
                            <span class="id-cr80-info-label">Gram Panchayat:</span>
                            <span class="id-cr80-info-val"><?= Sanitize::html($portalMember['gp_name'] ?? '—') ?></span>
                        </div>

                        <div>
                            <span class="id-cr80-info-label">Taluk / District:</span>
                            <span class="id-cr80-info-val"><?= Sanitize::html($portalMember['taluk_name'] ?? '—') ?>, <?= Sanitize::html($portalMember['district_name'] ?? '—') ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card Footer -->
            <div class="id-cr80-footer">
                <div>● Official Welfare Association Member Card</div>
                <div style="font-weight:800; color:#1e40af; letter-spacing:0.3px;">KARNATAKA STATE PDO WELFARE ASSOCIATION</div>
            </div>
        </div>
    </div>

    <!-- ───────────────────────────────────────────────────────────────────
         SIDE 2: BACK FACE (HORIZONTAL CR80 — SAME EXACT DIMENSIONS)
         ─────────────────────────────────────────────────────────────────── -->
    <div>
        <div style="text-align:center; margin-bottom:8px; font-size:0.75rem; font-weight:800; color:var(--text-muted); text-transform:uppercase; letter-spacing:1px;" class="no-print">
            Back Face (ಹಿಂಭಾಗ)
        </div>

        <div class="id-card-cr80" id="idCardBack">
            <!-- Back Header -->
            <div class="id-cr80-back-header">
                <div>
                    <div style="font-size:9px; font-weight:800; letter-spacing:0.5px; text-transform:uppercase;">MEMBERSHIP IDENTITY &amp; CONTACT INFORMATION</div>
                    <div style="font-size:7.5px; color:#94a3b8; letter-spacing:0.2px;"><?= Sanitize::html($siteName) ?></div>
                </div>
            </div>

            <!-- Back Body -->
            <div class="id-cr80-back-body">
                <div>
                    <!-- Emergency & Personal Details -->
                    <div style="font-size:8px; font-weight:800; color:#1e40af; text-transform:uppercase; margin-bottom:3px;">
                        Emergency &amp; Personal Information
                    </div>
                    <div class="id-cr80-back-grid">
                        <div>
                            <span style="color:#64748b; font-size:7px; display:block;">Registered Mobile:</span>
                            <strong><?= Sanitize::html($profile['personal_mobile'] ?? $portalMember['mobile'] ?? '—') ?></strong>
                        </div>
                        <div>
                            <span style="color:#64748b; font-size:7px; display:block;">Native District:</span>
                            <strong><?= Sanitize::html($profile['native_district'] ?? '—') ?></strong>
                        </div>
                        <div>
                            <span style="color:#64748b; font-size:7px; display:block;">Blood Group:</span>
                            <strong style="color:#dc2626;"><?= Sanitize::html($profile['blood_group'] ?? '—') ?></strong>
                        </div>
                        <div>
                            <span style="color:#64748b; font-size:7px; display:block;">Financial Year:</span>
                            <strong style="color:#15803d;"><?= Sanitize::html($fy) ?></strong>
                        </div>
                    </div>

                    <!-- Association Central Office (Settings Integration) -->
                    <div class="id-cr80-office-card">
                        <div style="font-weight:800; color:#1e3a8a; font-size:8.5px; margin-bottom:2px;">
                            <?= Sanitize::html($siteName) ?>
                        </div>
                        <div><?= Sanitize::html($siteAddress) ?></div>
                        <div style="margin-top:2px;">
                            Helpline: <strong><?= Sanitize::html($sitePhone) ?></strong> &bull; Email: <strong><?= Sanitize::html($siteEmail) ?></strong>
                        </div>
                        <div>Website: <strong><?= Sanitize::html($siteWebsite) ?></strong></div>
                    </div>
                </div>

                <!-- Terms, Instructions & Signatory -->
                <div>
                    <div style="display:flex; justify-content:space-between; align-items:flex-end; border-top:1px dashed #cbd5e1; padding-top:6px; margin-top:4px;">
                        <div style="flex:1; color:#64748b; font-size:7px; line-height:1.35; padding-right:12px;">
                            1. This identity card is the official property of KSPDOWA and non-transferable.<br>
                            2. If found, return to nearest Taluk/District Association office or call helpline.<br>
                            3. Card validity: <strong><?= Sanitize::html($validityDisplay) ?></strong>.
                        </div>

                        <!-- Authorized Signatory Seal -->
                        <div style="text-align:center; flex-shrink:0; width:120px;">
                            <div style="font-size:9px; font-weight:900; color:#0f172a;">Sd/-</div>
                            <div style="font-size:8.5px; font-weight:800; color:#1e40af;">General Secretary</div>
                            <div style="font-size:7px; color:#64748b;">KSPDOWA State Committee</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Back Footer -->
            <div class="id-cr80-footer" style="background:#f1f5f9;">
                <div><?= Sanitize::html($siteTagline) ?></div>
                <div style="font-weight:700; color:#475569;">BENGALURU • KARNATAKA</div>
            </div>
        </div>
    </div>
</div>

<script>
// High-resolution 300-DPI JPG Image Download (Side-by-Side Combined Front & Back)
async function downloadCardAsJpg() {
    const exportEl = document.getElementById('idCardExportContainer');
    const btn = document.getElementById('btnDownloadJpg');
    const originalText = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = '<span style="display:inline-block; animation:spin 1s infinite linear;">↻</span> Generating High-Res Image...';

    // Store original inline style
    const origStyle = exportEl.getAttribute('style') || '';

    // Enforce desktop side-by-side flex layout during capture
    exportEl.style.display = 'flex';
    exportEl.style.flexDirection = 'row';
    exportEl.style.width = '1120px';
    exportEl.style.maxWidth = 'none';
    exportEl.style.gap = '28px';
    exportEl.style.padding = '24px';
    exportEl.style.background = '#ffffff';
    exportEl.style.justifyContent = 'center';
    exportEl.style.alignItems = 'flex-start';

    try {
        const canvas = await html2canvas(exportEl, {
            scale: 2.5, // Crisp 300 DPI high resolution
            useCORS: true,
            allowTaint: true,
            backgroundColor: '#ffffff',
            ignoreElements: (element) => {
                return element.classList.contains('no-print');
            }
        });

        const link = document.createElement('a');
        link.download = 'KSPDOWA-ID-CARD-<?= Sanitize::attr($portalMember['member_no'] ?? 'MEMBER') ?>-FRONT-BACK.jpg';
        link.href = canvas.toDataURL('image/jpeg', 0.95);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    } catch (err) {
        alert('Could not generate JPG image: ' + err);
    } finally {
        exportEl.setAttribute('style', origStyle);
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}
</script>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
