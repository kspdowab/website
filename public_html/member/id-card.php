<?php
/**
 * KSPDOWA — Member Portal: Official Digital ID Card
 * ============================================================
 * Section 8: Digital ID Card (Front & Back, .jpg & .pdf download, print)
 * Global Standard Vertical (Portrait) CR80 Format (54mm x 85.6mm)
 * Exact 1-to-1 pixel-matched implementation of reference image 8_555
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
$sitePhone    = Settings::get('site_phone', '9964010126');
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
$mobileNumber = (string)($profile['personal_mobile'] ?? $portalMember['mobile'] ?? '9036880026');
$nativeDistrict = (string)($profile['native_district'] ?? '—');
?>

<!-- Load html2canvas for instant high-res JPG export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<style>
/* ─────────────────────────────────────────────────────────────────────────────
   EXACT 1-TO-1 PIXEL MATCHING OF REFERENCE GRAPHIC (8_555)
   Card Dimensions: 284px × 438px | Canvas: 1024px × 558px
   ───────────────────────────────────────────────────────────────────────────── */
.id-preview-wrapper {
    background: #ffffff;
    padding: 30px 20px 48px;
    border-radius: 12px;
    width: 100%;
    box-sizing: border-box;
    display: flex;
    justify-content: center;
}

.id-export-container {
    display: flex;
    flex-direction: row;
    gap: 210px;
    justify-content: center;
    align-items: flex-start;
    background: #ffffff;
    padding: 35px 80px 25px;
    box-sizing: border-box;
    width: 1024px;
    margin: 0 auto;
}

.id-card-column {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 284px;
}

.id-card-top-label {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    font-size: 15px;
    font-weight: 700;
    color: #475569;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    margin-bottom: 12px;
    text-align: center;
}

/* Card Outer Box: 284px x 438px with clean slate border & drop shadow */
.id-card-portrait {
    width: 284px;
    height: 438px;
    border-radius: 14px;
    border: 1.5px solid #334155;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.15);
    overflow: hidden;
    position: relative;
    user-select: none;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-sizing: border-box;
    flex-shrink: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
}

/* Front Card Background: Soft warm peach/orange gradient matching reference 8_555 (html2canvas-compatible linear gradient) */
#idCardFront {
    background: linear-gradient(145deg, #fef4ee 0%, #ffffff 32%, #ffffff 72%, #fee6dc 100%);
}

/* Back Card Background: Clean crisp white */
#idCardBack {
    background: #ffffff;
}

/* Watermark */
.id-portrait-watermark {
    position: absolute;
    top: 52%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-28deg);
    font-size: 2.7rem;
    font-weight: 900;
    color: rgba(30, 64, 175, 0.045);
    pointer-events: none;
    white-space: nowrap;
    letter-spacing: 3px;
    z-index: 0;
}

/* ── FRONT CARD STYLES ─────────────────────────────────────────────────────── */
.id-front-header {
    background: #14367e;
    color: #ffffff;
    padding: 5px 6px 4px;
    border-bottom: 2.5px solid #f59e0b; /* Yellow accent line */
    position: relative;
    z-index: 1;
    box-sizing: border-box;
}

.id-gov-recon-line {
    font-size: 7.6px;
    font-weight: 600;
    color: #ffffff;
    text-align: center;
    letter-spacing: 0.3px;
    line-height: 1.15;
    margin-bottom: 2px;
}

.id-front-header-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 5px;
}

/* White rounded rectangular emblem boxes */
.id-front-logo-box {
    width: 38px;
    height: 44px;
    border-radius: 6px;
    background: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.18);
    flex-shrink: 0;
    padding: 2px;
    box-sizing: border-box;
}
.id-front-logo-box img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.id-front-header-center {
    flex: 1;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-width: 0;
}

.id-front-title-kannada {
    font-family: "Nirmala UI", "Tunga", "Segoe UI", sans-serif;
    font-size: 9.2px;
    font-weight: 800;
    color: #ffffff;
    line-height: 1.2;
    white-space: nowrap;
}

.id-front-title-english {
    font-family: 'Arial Narrow', 'Franklin Gothic Medium', 'Roboto Condensed', -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    font-size: 8.2px;
    font-weight: 800;
    color: #facc15; /* Warm yellow */
    letter-spacing: 0.15px;
    margin-top: 1px;
    line-height: 1.15;
    text-transform: uppercase;
    white-space: nowrap;
}

/* Front Card Body (Flex evenly to fill space with 0 dead gaps) */
.id-front-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: space-evenly;
    align-items: center;
    padding: 4px 10px;
    position: relative;
    z-index: 1;
    box-sizing: border-box;
}

/* Upper-Middle: Association Designation */
.id-assoc-sec {
    text-align: center;
    width: 100%;
}
.id-assoc-subtitle {
    font-size: 7.8px;
    font-weight: 800;
    color: #334155;
    text-transform: uppercase;
    letter-spacing: 0.35px;
    margin-bottom: 2px;
}
.id-assoc-box {
    display: inline-block;
    background: #ffffff;
    border: 1.5px solid #15803d;
    color: #15803d;
    font-size: 11.5px;
    font-weight: 800;
    padding: 2.5px 14px;
    border-radius: 6px;
    line-height: 1.2;
}
.id-assoc-box.is-rep {
    background: #fffbeb;
    border-color: #d97706;
    color: #92400e;
}
.id-assoc-rep-sub {
    font-size: 7.8px;
    font-weight: 700;
    color: #b45309;
    margin-top: 1px;
}

/* Portrait Photo */
.id-portrait-photo-wrap {
    text-align: center;
}
.id-portrait-photo {
    width: 100px;
    height: 120px;
    border: 1.5px solid #94a3b8;
    border-radius: 8px;
    background: #f8fafc;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
    display: block;
    margin: 0 auto;
    overflow: hidden;
}
.id-portrait-photo img {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: cover;
}

/* Name & ID Badges */
.id-name-sec {
    text-align: center;
    width: 100%;
}
.id-name-text {
    font-size: 15.5px;
    font-weight: 900;
    color: #0f172a;
    letter-spacing: 0.35px;
    text-transform: uppercase;
    line-height: 1.2;
}
.id-badges-row {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 6px;
    margin-top: 2px;
}
.id-badge-memberno {
    background: #eff6ff;
    border: 1.2px solid #93c5fd;
    color: #1d4ed8;
    font-family: ui-monospace, monospace;
    font-weight: 800;
    font-size: 10px;
    padding: 2px 8px;
    border-radius: 5px;
}
.id-badge-verified {
    background: #dcfce7;
    border: 1.2px solid #86efac;
    color: #15803d;
    font-weight: 800;
    font-size: 10px;
    padding: 2px 7px;
    border-radius: 5px;
}

/* Info Section Block */
.id-info-block {
    width: 100%;
    background: rgba(248, 250, 252, 0.88);
    border: 1.2px solid #cbd5e1;
    border-radius: 8px;
    padding: 6px 10px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 3px 8px;
    box-sizing: border-box;
}
.id-info-cell-label {
    font-size: 8px;
    font-weight: 600;
    color: #64748b;
    display: block;
    line-height: 1.1;
}
.id-info-cell-val {
    color: #0f172a;
    font-weight: 900;
    font-size: 11.5px;
    line-height: 1.15;
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
    width: 100%;
}
.id-badge-blood {
    background: #fee2e2;
    border: 1.2px solid #fca5a5;
    color: #b91c1c;
    font-weight: 800;
    font-size: 10.5px;
    padding: 2.5px 14px;
    border-radius: 6px;
}
.id-badge-validtill {
    background: #dcfce7;
    border: 1.2px solid #86efac;
    color: #15803d;
    font-weight: 800;
    font-size: 10.5px;
    padding: 2.5px 14px;
    border-radius: 6px;
}

/* Front Footer */
.id-front-footer {
    height: 25px;
    background: #14367e;
    color: #ffffff;
    text-align: center;
    font-weight: 800;
    font-size: 9px;
    letter-spacing: 0.6px;
    display: flex;
    align-items: center;
    justify-content: center;
    text-transform: uppercase;
    box-sizing: border-box;
}


/* ── BACK CARD STYLES ──────────────────────────────────────────────────────── */
.id-back-header {
    background: #0d1627;
    color: #ffffff;
    padding: 5px 8px 4px;
    text-align: center;
    border-bottom: 2.5px solid #f59e0b;
    display: flex;
    flex-direction: column;
    justify-content: center;
    box-sizing: border-box;
    gap: 1px;
}
.id-back-recon-line {
    font-size: 6.5px;
    font-weight: 600;
    color: #ffffff;
    letter-spacing: 0.35px;
    text-transform: uppercase;
}
.id-back-header-title {
    font-size: 9.2px;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}
.id-back-header-assoc {
    font-family: 'Arial Narrow', 'Franklin Gothic Medium', 'Roboto Condensed', -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    font-size: 8px;
    font-weight: 800;
    color: #facc15; /* Yellow */
    letter-spacing: 0.1px;
    line-height: 1.15;
    text-transform: uppercase;
    white-space: nowrap;
}

/* Back Card Body (Evenly spaced to match front) */
.id-back-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: space-evenly;
    padding: 5px 10px;
    box-sizing: border-box;
}

/* Emergency Box */
.id-back-emergency-sec {
    width: 100%;
}
.id-back-emergency-title {
    background: #1e40af;
    color: #ffffff;
    border-radius: 5px;
    padding: 2.5px 8px;
    font-size: 8.2px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    text-align: center;
    margin-bottom: 3px;
    box-sizing: border-box;
}
.id-back-emergency-box {
    background: #f8fafc;
    border: 1.2px solid #cbd5e1;
    border-radius: 8px;
    padding: 5px 8px;
    display: grid;
    grid-template-columns: 1.15fr 0.85fr;
    gap: 3px 6px;
    box-sizing: border-box;
}
.id-back-em-val {
    font-size: 11px;
    font-weight: 900;
    line-height: 1.2;
}

/* Association Office Box */
.id-back-assoc-box {
    width: 100%;
    background: #f8fafc;
    border: 1.2px solid #cbd5e1;
    border-radius: 8px;
    padding: 5px 8px;
    line-height: 1.35;
    font-size: 7.8px;
    color: #334155;
    box-sizing: border-box;
    text-align: center;
}
.id-back-assoc-title {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    font-weight: 800;
    font-size: 8.2px;
    color: #0f172a;
    margin-bottom: 2px;
    line-height: 1.2;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.id-back-assoc-icon {
    vertical-align: -1.5px;
    margin-right: 2px;
    display: inline-block;
    color: #2563eb;
}

/* Fine Print & Signatory */
.id-back-sign-sec {
    width: 100%;
    border-top: 1.2px dashed #cbd5e1;
    padding-top: 4px;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    box-sizing: border-box;
}
.id-back-fineprint {
    font-size: 6.8px;
    color: #64748b;
    line-height: 1.35;
    max-width: 172px;
}
.id-back-sign-box {
    text-align: center;
    width: 95px;
    flex-shrink: 0;
}
.id-back-sign-sd {
    font-size: 10px;
    font-weight: 900;
    color: #0f172a;
}
.id-back-sign-role {
    font-size: 8.8px;
    font-weight: 800;
    color: #1e40af;
}
.id-back-sign-comm {
    font-size: 7.2px;
    color: #64748b;
}

/* Back Footer */
.id-back-footer {
    height: 25px;
    background: #14367e;
    color: #fffff5;
    padding: 0 10px;
    font-size: 9px;
    font-weight: 800;
    display: flex;
    /* Changed from space-between to center */
    justify-content: center; 
    align-items: center;
    border-top: 1px solid #cbd5e1;
    box-sizing: border-box;
}

@media (max-width: 1040px) {
    .id-export-container {
        width: 100%;
        flex-direction: column;
        align-items: center;
        gap: 32px;
        padding: 16px;
    }
}

/* Print CSS: Enforce exact color graphics, both cards fit on A4 without cutting */
@media print {
    @page {
        size: A4 portrait;
        margin: 10mm 8mm;
    }
    *, *::before, *::after {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
    }
    html, body {
        background: #ffffff !important;
        margin: 0 !important;
        padding: 0 !important;
        height: auto !important;
        overflow: visible !important;
    }

    /* ── Hide EVERYTHING with visibility (not display) so the DOM tree stays intact ── */
    body > * {
        visibility: hidden !important;
    }

    /* ── Keep the layout wrapper rendered (not display:none) but invisible ── */
    .app-layout {
        display: block !important; /* must stay in render tree */
        visibility: hidden !important;
        background: none !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    .app-sidebar,
    .app-topbar,
    .app-header,
    .page-header-row,
    .no-print {
        display: none !important;
    }
    .app-main {
        display: block !important;
        visibility: hidden !important;
        background: none !important;
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
    }
    .app-content {
        display: block !important;
        visibility: hidden !important;
        background: none !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    .id-preview-wrapper {
        display: block !important;
        visibility: hidden !important;
        padding: 0 !important;
        margin: 0 !important;
        background: none !important;
        box-shadow: none !important;
    }

    /* ── Make only the export container and its children visible ── */
    #idCardExportContainer,
    #idCardExportContainer * {
        visibility: visible !important;
    }
    #idCardExportContainer {
        position: relative !important;
        left: auto !important;
        top: auto !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 auto !important;
        padding: 8mm 0 !important;
        background: #ffffff !important;
        box-shadow: none !important;
        display: flex !important;
        flex-direction: row !important;
        gap: 20px !important;
        justify-content: center !important;
        align-items: flex-start !important;
    }
    .id-card-portrait {
        box-shadow: none !important;
        border: 1.5px solid #334155 !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }
    /* Hide the FRONT/BACK labels above cards on print */
    .id-card-top-label {
        display: none !important;
    }
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
        💡 <strong>Print-Ready Format:</strong> Standard 1024×558 portrait membership card specification. Clicking <strong>Download Image (.JPG)</strong> exports both FRONT and BACK side-by-side at 300 DPI on a seamless white background.
    </div>
    <a href="/member/profile.php" class="btn btn-outline btn-sm" style="background:#ffffff; color:#1e40af; font-weight:700;">Update Profile &rarr;</a>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     SIDE-BY-SIDE CARDS CONTAINER (MATCHING 8_555 REFERENCE GRAPHIC)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="id-preview-wrapper">
    <div id="idCardExportContainer" class="id-export-container">

        <!-- ───────────────────────────────────────────────────────────────────
             LEFT CARD: FRONT SIDE (VERTICAL / PORTRAIT)
             ─────────────────────────────────────────────────────────────────── -->
        <div class="id-card-column">
            <div class="id-card-top-label">FRONT</div>

            <div class="id-card-portrait" id="idCardFront">
                <div class="id-portrait-watermark">KSPDOWA</div>

                <!-- Top Header: Royal Blue Band -->
                <div class="id-front-header">
                    <div class="id-gov-recon-line">
                        Government-Recognized Service Association
                    </div>

                    <div class="id-front-header-row">
                        <!-- Left Emblem: Blue-white circular crest in rounded white card -->
                        <div class="id-front-logo-box">
                            <?php if ($logoLeftDataUri): ?>
                                <img src="<?= $logoLeftDataUri ?>" alt="Left Emblem">
                            <?php else: ?>
                                <div style="font-size:7px; font-weight:900; color:#1e3a8a;">KSPDOWA</div>
                            <?php endif; ?>
                        </div>

                        <!-- Center Titles: 4 cleanly wrapped lines so text NEVER cuts -->
                        <div class="id-front-header-center">
                            <div class="id-front-title-kannada">
                                <div>ಕರ್ನಾಟಕ ರಾಜ್ಯ ಪಂಚಾಯತ ಅಭಿವೃದ್ಧಿ</div>
                                <div>ಅಧಿಕಾರಿಗಳ ಕ್ಷೇಮಾಭಿವೃದ್ಧಿ ಸಂಘ (ರಿ.)</div>
                            </div>
                            <div class="id-front-title-english">
                                <div>KARNATAKA STATE PANCHAYAT DEVELOPMENT</div>
                                <div>OFFICER WELFARE ASSOCIATION (R)</div>
                            </div>
                        </div>

                        <!-- Right Emblem: Colorful crest in rounded white card -->
                        <div class="id-front-logo-box">
                            <?php if ($logoRightDataUri): ?>
                                <img src="<?= $logoRightDataUri ?>" alt="Right Emblem">
                            <?php else: ?>
                                <div style="font-size:7px; font-weight:900; color:#1e3a8a;">EMBLEM</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Card Body (Evenly spaced, 0 dead gaps) -->
                <div class="id-front-body">
                    <!-- 1. Upper-Middle: Association Designation -->
                    <div class="id-assoc-sec">
                        <div class="id-assoc-subtitle">ASSOCIATION DESIGNATION</div>
                        <div class="id-assoc-box <?= $isRepresentative ? 'is-rep' : '' ?>">
                            <?= Sanitize::html($assocDesignation) ?>
                        </div>
                        <?php if ($assocSubLabel): ?>
                            <div class="id-assoc-rep-sub">★ <?= Sanitize::html($assocSubLabel) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- 2. Photo Section -->
                    <div class="id-portrait-photo-wrap">
                        <div class="id-portrait-photo">
                            <?php if ($photoDataUri): ?>
                                <img src="<?= $photoDataUri ?>" alt="Member Photo">
                            <?php else: ?>
                                <div style="display:flex; flex-direction:column; align-items:center; color:#94a3b8;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    <span style="font-size:7px; font-weight:700; text-transform:uppercase; margin-top:2px;">No Photo</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 3. Name & ID Section -->
                    <div class="id-name-sec">
                        <div class="id-name-text"><?= Sanitize::html($memberName) ?></div>
                        <div class="id-badges-row">
                            <span class="id-badge-memberno"><?= Sanitize::html($memberNo) ?></span>
                            <span class="id-badge-verified">✔ VERIFIED</span>
                        </div>
                    </div>

                    <!-- 4. Info Section: 2-Column Grounded Block -->
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
                            <span class="id-info-cell-val" style="font-size:9.5px;"><?= Sanitize::html($locationStr) ?></span>
                        </div>
                    </div>

                    <!-- 5. Badges Row: Blood & Valid Till -->
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

        </div>

        <!-- ───────────────────────────────────────────────────────────────────
             RIGHT CARD: BACK SIDE (VERTICAL / PORTRAIT)
             ─────────────────────────────────────────────────────────────────── -->
        <div class="id-card-column">
            <div class="id-card-top-label">BACK</div>

            <div class="id-card-portrait" id="idCardBack">
                <!-- Top Header: Dark Charcoal/Black Band -->
                <div class="id-back-header">
                    <div class="id-back-recon-line">GOVERNMENT-RECOGNIZED SERVICE ASSOCIATION</div>
                    <div class="id-back-header-assoc">
                        <div>KARNATAKA STATE PANCHAYAT DEVELOPMENT</div>
                        <div>OFFICER WELFARE ASSOCIATION (R)</div>
                    </div>
                    <div class="id-back-header-title">MEMBERSHIP IDENTITY &amp; CONTACT INFORMATION</div>
                </div>

                <!-- Back Card Body (Evenly spaced to match front) -->
                <div class="id-back-body">
                    <!-- 1. Emergency & Personal Information Section -->
                    <div class="id-back-emergency-sec">
                        <div class="id-back-emergency-title">EMERGENCY &amp; PERSONAL INFORMATION</div>
                        <div class="id-back-emergency-box">
                            <div>
                                <span style="color:#64748b; font-size:7px; display:block;">Registered Mobile:</span>
                                <strong class="id-back-em-val" style="color:#0f172a;"><?= Sanitize::html($mobileNumber) ?></strong>
                            </div>
                            <div>
                                <span style="color:#64748b; font-size:7px; display:block;">Native District:</span>
                                <strong class="id-back-em-val" style="color:#0f172a;"><?= Sanitize::html($nativeDistrict) ?></strong>
                            </div>
                            <div>
                                <span style="color:#64748b; font-size:7px; display:block;">Blood Group:</span>
                                <strong class="id-back-em-val" style="color:#dc2626;"><?= Sanitize::html($bloodGroup) ?></strong>
                            </div>
                            <div>
                                <span style="color:#64748b; font-size:7px; display:block;">Membership Year:</span>
                                <strong class="id-back-em-val" style="color:#0f172a;"><?= Sanitize::html($fy) ?></strong>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Association Central Office Box -->
                    <div class="id-back-assoc-box">
                        <div class="id-back-assoc-title">ASSOCIATION CENTRAL OFFICE ADDRESS</div>
                        <div><?= Sanitize::html($siteAddress) ?></div>
                        <div style="margin-top:2px;">
                            <span style="white-space:nowrap;">
                                <svg class="id-back-assoc-icon" width="8.5" height="8.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                                Helpline: <strong><?= Sanitize::html($sitePhone) ?></strong>
                            </span>
                            &nbsp;•&nbsp;
                            <span style="white-space:nowrap;">
                                <svg class="id-back-assoc-icon" width="8.5" height="8.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"></rect><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path></svg>
                                Email: <strong><?= Sanitize::html($siteEmail) ?></strong>
                            </span>
                        </div>
                        <div style="margin-top:1px;">
                            <span style="white-space:nowrap;">
                                <svg class="id-back-assoc-icon" width="8.5" height="8.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"></path><path d="M2 12h20"></path></svg>
                                Website: <strong><?= Sanitize::html($siteWebsite) ?></strong>
                            </span>
                        </div>
                    </div>

                    <!-- 3. Sub-footer: Fine Print & Signatory -->
                    <div class="id-back-sign-sec">
                        <div class="id-back-fineprint">
                            1. This identity card is the official property of KSPDOWA BENGALURU and non-transferable.<br>
                            2. If found, return to nearest Taluk/District Association office or call helpline.<br>
                            3. Card validity: Valid Till: <?= Sanitize::html($validTillDate) ?> (FY <?= Sanitize::html($fy) ?>).
                        </div>

                        <div class="id-back-sign-box">
                            <div class="id-back-sign-sd">Sd/-</div>
                            <div class="id-back-sign-role">General Secretary</div>
                            <div class="id-back-sign-comm">KSPDOWA BENGALURU.</div>
                        </div>
                    </div>
                </div>

                <!-- Footer: Pale Gray Bar -->
                <div class="id-back-footer">
                 
                <div style="text-align: center;">BENGALURU • KARNATAKA</div>

                </div>
            </div>
        </div>

    </div>
</div>

<script>
// High-resolution JPG Image Download — clones the container off-screen so live layout is never disturbed
async function downloadCardAsJpg() {
    const exportEl = document.getElementById('idCardExportContainer');
    const btn      = document.getElementById('btnDownloadJpg');
    const origText = btn.innerHTML;

    btn.disabled  = true;
    btn.innerHTML = '<span style="display:inline-block;animation:spin 1s linear infinite;">↻</span> Generating Image…';

    try {
        // Pre-decode all images so html2canvas doesn't race against lazy loading
        const imgs = exportEl.querySelectorAll('img');
        await Promise.all(Array.from(imgs).map(img =>
            img.complete ? Promise.resolve() : new Promise(r => { img.onload = img.onerror = r; })
        ));
        await Promise.all(Array.from(imgs).map(img => img.decode ? img.decode().catch(() => {}) : Promise.resolve()));

        // Measure the ACTUAL rendered size of the container (as shown on screen)
        const rect = exportEl.getBoundingClientRect();
        const W = Math.round(rect.width);
        const H = Math.round(rect.height);

        // Clone the container into a hidden off-screen div at the same size
        const wrapper = document.createElement('div');
        wrapper.style.cssText = [
            'position:fixed', 'left:-9999px', 'top:0',
            'width:' + W + 'px', 'height:' + H + 'px',
            'overflow:visible', 'z-index:-1', 'background:#ffffff',
            'pointer-events:none'
        ].join(';');

        const clone = exportEl.cloneNode(true);
        clone.style.cssText = '';                         // strip any forced inline overrides
        clone.style.display          = 'flex';
        clone.style.flexDirection    = 'row';
        clone.style.gap              = getComputedStyle(exportEl).gap;
        clone.style.padding          = getComputedStyle(exportEl).padding;
        clone.style.justifyContent   = 'center';
        clone.style.alignItems       = 'flex-start';
        clone.style.background       = '#ffffff';
        clone.style.width            = W + 'px';
        clone.style.boxSizing        = 'border-box';
        clone.style.margin           = '0';

        wrapper.appendChild(clone);
        document.body.appendChild(wrapper);

        // Wait one frame for the browser to lay out the clone
        await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));

        const scale  = 2;   // 2× for high-res / retina quality
        const canvas = await html2canvas(clone, {
            scale          : scale,
            useCORS        : true,
            allowTaint     : true,
            backgroundColor: '#ffffff',
            logging        : false,
            width          : W,
            height         : clone.scrollHeight,   // capture full natural height
            windowWidth    : W,
            windowHeight   : clone.scrollHeight
        });

        document.body.removeChild(wrapper);

        const link    = document.createElement('a');
        link.download = 'KSPDOWA-ID-CARD-<?= Sanitize::attr($portalMember['member_no'] ?? 'MEMBER') ?>-FRONT-BACK.jpg';
        link.href     = canvas.toDataURL('image/jpeg', 0.98);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

    } catch (err) {
        console.error('JPG export error:', err);
        alert('Could not generate JPG image: ' + err);
    } finally {
        btn.disabled  = false;
        btn.innerHTML = origText;
    }
}
</script>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
