<?php
/**
 * KSPDOWA — Member Portal: Official Digital ID Card
 * ============================================================
 * Section 8: Digital ID Card (Front & Back, .jpg & .pdf download, print)
 * Global Standard Vertical (Portrait) CR80 Format (54mm x 85.6mm)
 * Print-ready, flat 2D graphic design matching official specification
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
$siteName     = Settings::get('site_name', 'KARNATAKA STATE PANCHAYAT DEVELOPMENT OFFICER WELFARE ASSOCIATION (R)');
$siteTagline  = Settings::get('site_tagline', 'Government-Recognized Service Association');
$siteAddress  = Settings::get('site_address', '# 204, 2nd Floor, Karnataka Panchayat Raj Commissionerate, K. G. Road, Bengaluru – 560009');
$sitePhone    = Settings::get('site_phone', '9964010162');
$siteEmail    = Settings::get('site_email', 'kspdowab@gmail.com');
$siteWebsite  = Settings::get('site_website', 'https://kspdowa.in');

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

// Formatting helpers
$memberName = strtoupper((string)($portalMember['name'] ?? ''));
$memberNo   = (string)($portalMember['member_no'] ?? 'KSPDOWA-BGK-0001');
$kgidNo     = (string)($profile['kgid_no'] ?? '—');
$officialPost = (string)($portalMember['designation'] ?? 'PDO');
$gpName     = (string)($portalMember['gp_name'] ?? '—');
$talukName  = (string)($portalMember['taluk_name'] ?? '—');
$districtName = (string)($portalMember['district_name'] ?? '—');
$locationStr = strtoupper(trim(($talukName !== '—' ? $talukName : '') . ($districtName !== '—' ? ($talukName !== '—' ? ', ' : '') . $districtName : '—')));
$bloodGroup = (string)($profile['blood_group'] ?? '—');
$mobileNumber = (string)($profile['personal_mobile'] ?? $portalMember['mobile'] ?? '—');
$nativeDistrict = (string)($profile['native_district'] ?? '—');
?>

<!-- Load html2canvas for instant 300-DPI high-res JPG export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<style>
/* ─────────────────────────────────────────────────────────────────────────────
   PRINT-READY FLAT 2D GRAPHIC DESIGN: VERTICAL CR80 ID CARD (375px x 595px)
   ───────────────────────────────────────────────────────────────────────────── */
.id-preview-wrapper {
    background: #ffffff;
    padding: 30px 20px 40px;
    border-radius: 12px;
    width: 100%;
    box-sizing: border-box;
}

.id-card-side-col {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.id-side-title-label {
    font-size: 14px;
    font-weight: 700;
    color: #475569;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    margin-bottom: 12px;
    font-family: inherit;
}

.id-card-portrait {
    width: 375px;
    height: 595px;
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 10px 25px -4px rgba(15, 23, 42, 0.12), 0 0 0 1.5px rgba(203, 213, 225, 0.9);
    overflow: hidden;
    position: relative;
    user-select: none;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-sizing: border-box;
    flex-shrink: 0;
}

/* Watermark */
.id-portrait-watermark {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-28deg);
    font-size: 3.6rem;
    font-weight: 900;
    color: rgba(30, 64, 175, 0.04);
    pointer-events: none;
    white-space: nowrap;
    letter-spacing: 4px;
    z-index: 0;
}

/* ── FRONT CARD STYLES ─────────────────────────────────────────────────────── */
.id-front-header {
    background: linear-gradient(135deg, #1b3a7b 0%, #1e40af 50%, #2563eb 100%);
    color: #ffffff;
    padding: 6px 10px 6px;
    border-bottom: 2.5px solid #f59e0b; /* Thin yellow horizontal line */
    position: relative;
    z-index: 1;
}

.id-gov-recon-line {
    font-size: 7.2px;
    font-weight: 600;
    color: #f1f5f9;
    text-align: center;
    letter-spacing: 0.3px;
    line-height: 1.1;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.id-front-header-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.id-front-logo-box {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.25);
    flex-shrink: 0;
}
.id-front-logo-box img {
    width: 32px;
    height: 32px;
    object-fit: contain;
}

.id-front-header-center {
    flex: 1;
    text-align: center;
    line-height: 1.15;
}

.id-front-title-kannada {
    font-size: 9.5px;
    font-weight: 800;
    color: #ffffff;
    line-height: 1.2;
}

.id-front-title-english {
    font-size: 8px;
    font-weight: 800;
    color: #fde047; /* Yellow accent */
    letter-spacing: 0.2px;
    margin-top: 2px;
    line-height: 1.15;
    text-transform: uppercase;
}

/* Upper-Middle: Association Designation */
.id-assoc-sec {
    text-align: center;
    margin-top: 6px;
    z-index: 1;
    position: relative;
}
.id-assoc-subtitle {
    font-size: 8px;
    font-weight: 800;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 2px;
}
.id-assoc-box {
    display: inline-block;
    background: #f0fdf4;
    border: 1.5px solid #16a34a;
    color: #166534;
    font-size: 11px;
    font-weight: 800;
    padding: 3px 12px;
    border-radius: 6px;
    line-height: 1.2;
}
.id-assoc-box.is-rep {
    background: #fffbeb;
    border-color: #d97706;
    color: #92400e;
}
.id-assoc-rep-sub {
    font-size: 8px;
    font-weight: 700;
    color: #b45309;
    margin-top: 1px;
}

/* Portrait Photo */
.id-portrait-photo-wrap {
    text-align: center;
    margin: 6px 0 4px;
    z-index: 1;
    position: relative;
}
.id-portrait-photo {
    width: 106px;
    height: 126px;
    border: 1.5px solid #cbd5e1;
    border-radius: 8px;
    background: #f8fafc;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}
.id-portrait-photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

/* Name & ID Badges */
.id-name-sec {
    text-align: center;
    z-index: 1;
    position: relative;
}
.id-name-text {
    font-size: 14.5px;
    font-weight: 900;
    color: #0f172a;
    letter-spacing: 0.4px;
    text-transform: uppercase;
    line-height: 1.2;
}
.id-badges-row {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    margin-top: 4px;
}
.id-badge-memberno {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1e40af;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-weight: 800;
    font-size: 10.5px;
    padding: 2px 8px;
    border-radius: 4px;
}
.id-badge-verified {
    background: #dcfce7;
    border: 1px solid #bbf7d0;
    color: #15803d;
    font-weight: 800;
    font-size: 10px;
    padding: 2px 7px;
    border-radius: 4px;
}

/* Info Section Block */
.id-info-block {
    margin: 6px 14px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 7px;
    padding: 7px 12px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 3px 10px;
    font-size: 9.5px;
    z-index: 1;
    position: relative;
}
.id-info-cell-label {
    font-size: 8px;
    color: #64748b;
    display: block;
    line-height: 1.1;
}
.id-info-cell-val {
    color: #0f172a;
    font-weight: 700;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Bottom Badges: Blood & Valid Till */
.id-bottom-badges-row {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
    margin: 4px 14px 8px;
    z-index: 1;
    position: relative;
}
.id-badge-blood {
    background: #fee2e2;
    border: 1px solid #fecaca;
    color: #b91c1c;
    font-weight: 800;
    font-size: 10.5px;
    padding: 3px 14px;
    border-radius: 5px;
}
.id-badge-validtill {
    background: #dcfce7;
    border: 1px solid #bbf7d0;
    color: #15803d;
    font-weight: 800;
    font-size: 10.5px;
    padding: 3px 14px;
    border-radius: 5px;
}

/* Front Footer */
.id-front-footer {
    background: #1e3a8a;
    color: #ffffff;
    text-align: center;
    padding: 7px 10px;
    font-weight: 800;
    font-size: 9.5px;
    letter-spacing: 0.8px;
    text-transform: uppercase;
}
.id-under-card-label {
    font-size: 8.5px;
    color: #64748b;
    text-align: center;
    margin-top: 6px;
    font-weight: 600;
}

/* ── BACK CARD STYLES ──────────────────────────────────────────────────────── */
.id-back-header {
    background: #0f172a;
    color: #ffffff;
    padding: 8px 12px;
    text-align: center;
    border-bottom: 2.5px solid #f59e0b; /* Yellow stripe */
}
.id-back-recon-line {
    font-size: 7.2px;
    font-weight: 600;
    color: #cbd5e1;
    letter-spacing: 0.8px;
    text-transform: uppercase;
}
.id-back-header-title {
    font-size: 9.5px;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    margin-top: 2px;
}
.id-back-header-assoc {
    font-size: 7.5px;
    color: #cbd5e1;
    font-weight: 700;
    margin-top: 2px;
}

/* Emergency Box */
.id-back-emergency-sec {
    margin: 8px 14px 4px;
}
.id-back-emergency-title {
    font-size: 9px;
    font-weight: 800;
    color: #1e40af;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 3px;
}
.id-back-emergency-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 7px;
    padding: 8px 12px;
    display: grid;
    grid-template-columns: 1.15fr 0.85fr;
    gap: 4px 8px;
    font-size: 8.5px;
}

/* Association Office Box */
.id-back-assoc-box {
    margin: 8px 14px 6px;
    background: #f8fafc;
    border: 1.5px solid #cbd5e1;
    border-radius: 7px;
    padding: 8px 12px;
    line-height: 1.35;
    font-size: 8px;
    color: #334155;
}
.id-back-assoc-title {
    font-weight: 800;
    font-size: 8.8px;
    color: #0f172a;
    margin-bottom: 2px;
}

/* Fine Print & Signatory */
.id-back-sign-sec {
    margin: 8px 14px 0;
    border-top: 1.5px dashed #cbd5e1;
    padding-top: 7px;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
}
.id-back-fineprint {
    font-size: 7px;
    color: #64748b;
    line-height: 1.35;
    max-width: 215px;
}
.id-back-sign-box {
    text-align: center;
    width: 110px;
    flex-shrink: 0;
}
.id-back-sign-sd {
    font-size: 9px;
    font-weight: 900;
    color: #0f172a;
}
.id-back-sign-role {
    font-size: 8px;
    font-weight: 800;
    color: #1e40af;
}
.id-back-sign-comm {
    font-size: 6.8px;
    color: #64748b;
}

/* Back Footer */
.id-back-footer {
    background: #e2e8f0;
    color: #334155;
    padding: 7px 14px;
    font-size: 7.5px;
    font-weight: 700;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-top: 1px solid #cbd5e1;
}

/* ── SIDE-BY-SIDE EXPORT CONTAINER ────────────────────────────────────────── */
.id-export-container {
    display: flex;
    flex-direction: row;
    gap: 48px;
    justify-content: center;
    align-items: flex-start;
    background: #ffffff;
    padding: 30px;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
    width: fit-content;
    margin: 0 auto;
}

@media (max-width: 860px) {
    .id-export-container {
        flex-direction: column;
        align-items: center;
        gap: 32px;
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
        gap: 30px !important;
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
        <p class="page-heading-subtitle">Print-ready flat 2D vertical CR80 membership card • Front &amp; Back</p>
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
        💡 <strong>Print-Ready Format:</strong> Standard portrait membership card matching official association specifications. Clicking <strong>Download Image (.JPG)</strong> exports both FRONT and BACK side-by-side at 300 DPI on a seamless white background.
    </div>
    <a href="/member/profile.php" class="btn btn-outline btn-sm" style="background:#ffffff; color:#1e40af; font-weight:700;">Update Profile &rarr;</a>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     SIDE-BY-SIDE CARDS CONTAINER (CAPTURED FOR COMBINED JPG EXPORT)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="id-preview-wrapper">
    <div id="idCardExportContainer" class="id-export-container">

        <!-- ───────────────────────────────────────────────────────────────────
             LEFT CARD: FRONT SIDE (VERTICAL / PORTRAIT)
             ─────────────────────────────────────────────────────────────────── -->
        <div class="id-card-side-col">
            <div class="id-side-title-label">FRONT</div>

            <div class="id-card-portrait" id="idCardFront">
                <div class="id-portrait-watermark">KSPDOWA</div>

                <div>
                    <!-- Top Header: Royal Blue Band -->
                    <div class="id-front-header">
                        <!-- Top recognition line with Kannada & English -->
                        <div class="id-gov-recon-line">
                            ಸರ್ಕಾರದ ಮಾನ್ಯತೆ ಪಡೆದ ಸೇವಾ ಸಂಘ  •  <?= Sanitize::html($siteTagline) ?>
                        </div>

                        <!-- Header Row: Logos + Titles -->
                        <div class="id-front-header-row">
                            <!-- Left Emblem: Blue-white circular crest -->
                            <div class="id-front-logo-box">
                                <?php if ($logoLeftDataUri): ?>
                                    <img src="<?= $logoLeftDataUri ?>" alt="Left Emblem">
                                <?php else: ?>
                                    <div style="font-size:7px; font-weight:900; color:#1e3a8a;">KSPDOWA</div>
                                <?php endif; ?>
                            </div>

                            <!-- Center Titles -->
                            <div class="id-front-header-center">
                                <div class="id-front-title-kannada">ಕರ್ನಾಟಕ ರಾಜ್ಯ ಪಂಚಾಯತ್ ಅಭಿವೃದ್ಧಿ ಅಧಿಕಾರಿಗಳ ಕ್ಷೇಮಾಭಿವೃದ್ಧಿ ಸಂಘ (ರಿ.)</div>
                                <div class="id-front-title-english"><?= Sanitize::html($siteName) ?></div>
                            </div>

                            <!-- Right Emblem: Colorful crest -->
                            <div class="id-front-logo-box">
                                <?php if ($logoRightDataUri): ?>
                                    <img src="<?= $logoRightDataUri ?>" alt="Right Emblem">
                                <?php else: ?>
                                    <div style="font-size:7px; font-weight:900; color:#1e3a8a;">EMBLEM</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Upper-Middle: Association Designation -->
                    <div class="id-assoc-sec">
                        <div class="id-assoc-subtitle">ASSOCIATION DESIGNATION (ಸಂಘದ ಹುದ್ದೆ)</div>
                        <div class="id-assoc-box <?= $isRepresentative ? 'is-rep' : '' ?>">
                            <?= Sanitize::html($assocDesignation) ?>
                        </div>
                        <?php if ($assocSubLabel): ?>
                            <div class="id-assoc-rep-sub">★ <?= Sanitize::html($assocSubLabel) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Photo Section -->
                    <div class="id-portrait-photo-wrap">
                        <div class="id-portrait-photo">
                            <?php if ($photoDataUri): ?>
                                <img src="<?= $photoDataUri ?>" alt="Member Photo">
                            <?php else: ?>
                                <div style="display:flex; flex-direction:column; align-items:center; color:#94a3b8;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    <span style="font-size:7px; font-weight:700; text-transform:uppercase; margin-top:2px;">No Photo</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Name & ID Section -->
                    <div class="id-name-sec">
                        <div class="id-name-text"><?= Sanitize::html($memberName) ?></div>
                        <div class="id-badges-row">
                            <span class="id-badge-memberno"><?= Sanitize::html($memberNo) ?></span>
                            <span class="id-badge-verified">✔ VERIFIED</span>
                        </div>
                    </div>

                    <!-- Info Section: 2-Column Grounded Block -->
                    <div class="id-info-block">
                        <div>
                            <span class="id-info-cell-label">Official Post:</span>
                            <span class="id-info-cell-val"><?= Sanitize::html($officialPost) ?></span>
                        </div>

                        <div>
                            <span class="id-info-cell-label">KGID Number:</span>
                            <span class="id-info-cell-val" style="font-family:monospace;"><?= Sanitize::html($kgidNo) ?></span>
                        </div>

                        <div>
                            <span class="id-info-cell-label">Gram Panchayat:</span>
                            <span class="id-info-cell-val"><?= Sanitize::html($gpName) ?></span>
                        </div>

                        <div>
                            <span class="id-info-cell-label">Taluk / District:</span>
                            <span class="id-info-cell-val"><?= Sanitize::html($locationStr) ?></span>
                        </div>
                    </div>

                    <!-- Badges Row: Blood & Valid Till -->
                    <div class="id-bottom-badges-row">
                        <div class="id-badge-blood">Blood: <?= Sanitize::html($bloodGroup) ?></div>
                        <div class="id-badge-validtill">VALID TILL <?= Sanitize::html($validTillDate) ?></div>
                    </div>
                </div>

                <!-- Footer: Dark Blue Banner -->
                <div class="id-front-footer">
                    • UNITED WE STAND, TOGETHER WE SERVE •
                </div>
            </div>

            <!-- Tiny centered sub-label -->
            <div class="id-under-card-label">• Official Welfare Association Member Card</div>
        </div>

        <!-- ───────────────────────────────────────────────────────────────────
             RIGHT CARD: BACK SIDE (VERTICAL / PORTRAIT)
             ─────────────────────────────────────────────────────────────────── -->
        <div class="id-card-side-col">
            <div class="id-side-title-label">BACK</div>

            <div class="id-card-portrait" id="idCardBack">
                <div>
                    <!-- Top Header: Dark Charcoal/Black Band -->
                    <div class="id-back-header">
                        <div class="id-back-recon-line">GOVERNMENT-RECOGNIZED SERVICE ASSOCIATION</div>
                        <div class="id-back-header-title">MEMBERSHIP IDENTITY &amp; CONTACT INFORMATION</div>
                        <div class="id-back-header-assoc"><?= Sanitize::html($siteName) ?></div>
                    </div>

                    <!-- Emergency & Personal Information Section -->
                    <div class="id-back-emergency-sec">
                        <div class="id-back-emergency-title">EMERGENCY &amp; PERSONAL INFORMATION</div>
                        <div class="id-back-emergency-box">
                            <div>
                                <span style="color:#64748b; font-size:7px; display:block;">Registered Mobile:</span>
                                <strong style="color:#0f172a; font-size:8.8px;"><?= Sanitize::html($mobileNumber) ?></strong>
                            </div>
                            <div>
                                <span style="color:#64748b; font-size:7px; display:block;">Native District:</span>
                                <strong style="color:#0f172a; font-size:8.8px;"><?= Sanitize::html($nativeDistrict) ?></strong>
                            </div>
                            <div>
                                <span style="color:#64748b; font-size:7px; display:block;">Blood Group:</span>
                                <strong style="color:#dc2626; font-size:8.8px;"><?= Sanitize::html($bloodGroup) ?></strong>
                            </div>
                            <div>
                                <span style="color:#64748b; font-size:7px; display:block;">Financial Year:</span>
                                <strong style="color:#15803d; font-size:8.8px;"><?= Sanitize::html($fy) ?></strong>
                            </div>
                        </div>
                    </div>

                    <!-- Association Central Office Box -->
                    <div class="id-back-assoc-box">
                        <div class="id-back-assoc-title"><?= Sanitize::html($siteName) ?></div>
                        <div><?= Sanitize::html($siteAddress) ?></div>
                        <div style="margin-top:2px;">
                            Helpline: <strong><?= Sanitize::html($sitePhone) ?></strong> • Email: <strong><?= Sanitize::html($siteEmail) ?></strong>
                        </div>
                        <div>Website: <strong><?= Sanitize::html($siteWebsite) ?></strong></div>
                    </div>

                    <!-- Sub-footer: Fine Print & Signatory -->
                    <div class="id-back-sign-sec">
                        <div class="id-back-fineprint">
                            1. This identity card is the official property of KSPDOWA and non-transferable.<br>
                            2. If found, return to nearest Taluk/District Association office or call helpline.<br>
                            3. Card validity: Valid Till: <?= Sanitize::html($validTillDate) ?> (FY <?= Sanitize::html($fy) ?>).
                        </div>

                        <div class="id-back-sign-box">
                            <div class="id-back-sign-sd">Sd/-</div>
                            <div class="id-back-sign-role">General Secretary</div>
                            <div class="id-back-sign-comm">KSPDOWA State Committee</div>
                        </div>
                    </div>
                </div>

                <!-- Footer: Pale Gray Bar -->
                <div class="id-back-footer">
                    <div><?= Sanitize::html($siteTagline) ?></div>
                    <div>BENGALURU • KARNATAKA</div>
                </div>
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
    exportEl.style.width = '860px';
    exportEl.style.maxWidth = 'none';
    exportEl.style.gap = '48px';
    exportEl.style.padding = '30px';
    exportEl.style.background = '#ffffff';
    exportEl.style.justifyContent = 'center';
    exportEl.style.alignItems = 'flex-start';

    try {
        const canvas = await html2canvas(exportEl, {
            scale: 2.5, // Crisp 300 DPI high resolution
            useCORS: true,
            allowTaint: true,
            backgroundColor: '#ffffff'
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
