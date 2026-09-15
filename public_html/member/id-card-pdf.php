<?php
/**
 * KSPDOWA — Member Portal: Official Digital ID Card PDF Generator
 * ============================================================
 * Generates an official printable ID Card document with Front & Back
 * sides formatted to the Global Standard Vertical (Portrait) CR80 specification
 * (54.0mm x 85.6mm) on a standard printable A4 page with cut guidelines.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/lib/fpdf.php';

Auth::requireLogin();
$currentMemberId = Auth::getCurrentMemberId();

if ($currentMemberId === null) {
    header('Location: /login.php');
    exit;
}

$member = Database::fetchOne(
    'SELECT m.*, d.name AS district_name, t.name AS taluk_name, gp.name AS gp_name
     FROM members m
     LEFT JOIN districts d ON d.id = m.district_id
     LEFT JOIN taluks t    ON t.id = m.taluk_id
     LEFT JOIN gram_panchayatis gp ON gp.id = m.gp_id
     WHERE m.id = ?',
    [$currentMemberId]
);

if (!$member) {
    exit('Member record not found.');
}

$profile     = Database::fetchOne('SELECT * FROM member_profiles WHERE member_id = ?', [$currentMemberId]) ?: [];
$currentYear = Membership::getCurrentYear();
$fy          = $currentYear['financial_year'] ?? date('Y') . '-' . (date('y') + 1);
$validTillDate = !empty($currentYear['end_date'])
    ? date('d-m-Y', strtotime($currentYear['end_date']))
    : '31-03-' . (date('Y') + 1);

// Association Identity & Contact Settings
$siteName     = Settings::get('site_name', 'KARNATAKA STATE PANCHAYAT DEVELOPMENT OFFICER WELFARE ASSOCIATION (R)');
$siteTagline  = Settings::get('site_tagline', 'Government-Recognized Service Association');
$siteAddress  = Settings::get('site_address', '# 204, 2nd Floor, Karnataka Panchayat Raj Commissionerate, K. G. Road, Bengaluru – 560009');
$sitePhone    = Settings::get('site_phone', '9964010162');
$siteEmail    = Settings::get('site_email', 'kspdowab@gmail.com');
$siteWebsite  = Settings::get('site_website', 'https://kspdowa.in');

// Helper to resolve logo file
$resolveLogo = function(string $settingKey, string $defaultRel): string {
    $val = trim(Settings::get($settingKey, ''));
    if ($val !== '') {
        $cand = PUBLIC_HTML . '/' . ltrim($val, '/');
        if (is_file($cand)) {
            return $cand;
        }
    }
    return PUBLIC_HTML . '/' . $defaultRel;
};

$logoLeftAbs  = $resolveLogo('receipt_logo_left', 'assets/images/receipt-logo-left.png');
$logoRightAbs = $resolveLogo('receipt_logo_right', 'assets/images/receipt-logo-right.png');

// Check Association Designation from office_bearers or member record
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
        $assocSubLabel = 'State Committee';
    }

    if (!empty($officeBearer['term_start']) || !empty($officeBearer['term_end'])) {
        $startYear = !empty($officeBearer['term_start']) ? date('Y', strtotime($officeBearer['term_start'])) : '';
        $endYear   = !empty($officeBearer['term_end'])   ? date('Y', strtotime($officeBearer['term_end']))   : 'Present';
        $assocSubLabel .= ' (' . ($startYear ? $startYear . '-' : '') . $endYear . ')';
    }
} elseif (!empty($member['association_designation'])) {
    $assocDesignation = $member['association_designation'];
} else {
    $assocDesignation = 'Active Member (Welfare Association)';
}

// Initialize PDF in Portrait A4
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetTitle('KSPDOWA Digital ID Card - ' . ($member['member_no'] ?? 'MEMBER'));
$pdf->SetAuthor('Karnataka State PDO Welfare Association');
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

// Helper to convert UTF-8 string to ISO-8859-1 for standard FPDF
$latin1 = function(string $s): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s) ?: $s;
};

// Document Header on A4 sheet
$pdf->SetFont('Arial', 'B', 15);
$pdf->SetTextColor(30, 58, 138); // Royal blue
$pdf->Cell(0, 8, $latin1('KARNATAKA STATE PANCHAYAT DEVELOPMENT OFFICER'), 0, 1, 'C');
$pdf->SetFont('Arial', 'B', 13);
$pdf->Cell(0, 6, $latin1('WELFARE ASSOCIATION (R) • BENGALURU'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 8.5);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(0, 5, $latin1('Official CR80 Standard Vertical Identity Card • ' . $siteTagline), 0, 1, 'C');
$pdf->Ln(4);

// ─────────────────────────────────────────────────────────────────────────────
// CR80 STANDARD VERTICAL (PORTRAIT) CARD DIMENSIONS: 54.0mm Width x 85.6mm Height
// ─────────────────────────────────────────────────────────────────────────────
$cardW = 54.0;
$cardH = 85.6;
$gap   = 16.0;
$startX = (210.0 - ($cardW * 2 + $gap)) / 2.0; // Centered on A4
$startY = 42.0;

// Labels above cards
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(71, 85, 105);
$pdf->SetXY($startX, $startY - 7);
$pdf->Cell($cardW, 5, $latin1('FRONT'), 0, 0, 'C');
$pdf->SetXY($startX + $cardW + $gap, $startY - 7);
$pdf->Cell($cardW, 5, $latin1('BACK'), 0, 1, 'C');

// ═════════════════════════════════════════════════════════════════════════════
// 1. FRONT SIDE OF ID CARD (PORTRAIT: 54.0mm x 85.6mm)
// ═════════════════════════════════════════════════════════════════════════════

// Outer Card Border
$pdf->SetDrawColor(148, 163, 184);
$pdf->SetLineWidth(0.35);
$pdf->Rect($startX, $startY, $cardW, $cardH, 'D');

// Header Background (Royal Blue)
$pdf->SetFillColor(30, 58, 138);
$pdf->Rect($startX, $startY, $cardW, 13.5, 'F');

// Yellow accent line under header
$pdf->SetFillColor(245, 158, 11);
$pdf->Rect($startX, $startY + 13.5, $cardW, 0.7, 'F');

// 1st Line: Tiny white text
$pdf->SetXY($startX, $startY + 0.8);
$pdf->SetFont('Arial', '', 3.8);
$pdf->SetTextColor(241, 245, 249);
$pdf->Cell($cardW, 2.2, $latin1('Government-Recognized Service Association'), 0, 1, 'C');

// Left Logo (6.5mm x 6.5mm)
if (is_file($logoLeftAbs)) {
    try {
        $pdf->Image($logoLeftAbs, $startX + 1.5, $startY + 3.8, 6.5, 6.5);
    } catch (Throwable $e) {}
}

// Right Logo (6.5mm x 6.5mm)
if (is_file($logoRightAbs)) {
    try {
        $pdf->Image($logoRightAbs, $startX + $cardW - 8.0, $startY + 3.8, 6.5, 6.5);
    } catch (Throwable $e) {}
}

// Center Title
$pdf->SetXY($startX + 8.5, $startY + 3.6);
$pdf->SetFont('Arial', 'B', 4.5);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell($cardW - 17.0, 2.8, $latin1('KARNATAKA STATE PDO'), 0, 1, 'C');
$pdf->SetXY($startX + 8.5, $startY + 6.4);
$pdf->SetFont('Arial', 'B', 4.2);
$pdf->SetTextColor(253, 224, 71); // Yellow accent
$pdf->Cell($cardW - 17.0, 2.8, $latin1('WELFARE ASSOCIATION (R)'), 0, 1, 'C');

// Association Designation
$pdf->SetXY($startX, $startY + 15.2);
$pdf->SetFont('Arial', 'B', 3.8);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell($cardW, 2.2, $latin1('ASSOCIATION DESIGNATION'), 0, 1, 'C');

$assocBoxW = 42.0;
$assocBoxX = $startX + ($cardW - $assocBoxW) / 2.0;
if ($isRepresentative) {
    $pdf->SetFillColor(254, 243, 199);
    $pdf->SetDrawColor(245, 158, 11);
} else {
    $pdf->SetFillColor(240, 253, 244);
    $pdf->SetDrawColor(22, 163, 74);
}
$pdf->Rect($assocBoxX, $startY + 17.6, $assocBoxW, 4.2, 'FD');
$pdf->SetXY($assocBoxX, $startY + 18.2);
$pdf->SetFont('Arial', 'B', 5.0);
$pdf->SetTextColor($isRepresentative ? 146 : 22, $isRepresentative ? 64 : 101, $isRepresentative ? 14 : 52);
$pdf->Cell($assocBoxW, 3.0, $latin1($assocDesignation), 0, 1, 'C');

// Photo Box (Centered: 18mm width x 22mm height)
$photoW = 18.0;
$photoH = 22.0;
$photoX = $startX + ($cardW - $photoW) / 2.0;
$photoY = $startY + 23.0;

$photoRendered = false;
if (!empty($member['photo_path'])) {
    $fullPhotoPath = PUBLIC_HTML . '/' . ltrim($member['photo_path'], '/');
    if (is_file($fullPhotoPath)) {
        try {
            $pdf->Image($fullPhotoPath, $photoX, $photoY, $photoW, $photoH);
            $photoRendered = true;
        } catch (Throwable $e) {}
    }
}

if (!$photoRendered) {
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->Rect($photoX, $photoY, $photoW, $photoH, 'FD');
    $pdf->SetXY($photoX, $photoY + 9);
    $pdf->SetFont('Arial', 'B', 6);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->Cell($photoW, 4, $latin1('PHOTO'), 0, 1, 'C');
} else {
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->SetLineWidth(0.3);
    $pdf->Rect($photoX, $photoY, $photoW, $photoH, 'D');
}

// Member Name
$pdf->SetXY($startX + 2, $photoY + $photoH + 1.5);
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell($cardW - 4, 3.5, $latin1(strtoupper((string)($member['name'] ?? ''))), 0, 1, 'C');

// Member No & Verified Badges
$badgeW = 22.0;
$pdf->SetFillColor(239, 246, 255);
$pdf->SetDrawColor(191, 219, 254);
$pdf->Rect($startX + 4.5, $photoY + $photoH + 5.5, $badgeW, 3.6, 'FD');
$pdf->SetXY($startX + 4.5, $photoY + $photoH + 5.8);
$pdf->SetFont('Arial', 'B', 4.8);
$pdf->SetTextColor(30, 64, 175);
$pdf->Cell($badgeW, 3.0, $latin1((string)($member['member_no'] ?? '')), 0, 0, 'C');

$verW = 19.0;
$pdf->SetFillColor(220, 252, 231);
$pdf->SetDrawColor(187, 247, 208);
$pdf->Rect($startX + 28.5, $photoY + $photoH + 5.5, $verW, 3.6, 'FD');
$pdf->SetXY($startX + 28.5, $photoY + $photoH + 5.8);
$pdf->SetFont('Arial', 'B', 4.8);
$pdf->SetTextColor(21, 128, 61);
$pdf->Cell($verW, 3.0, $latin1('VERIFIED'), 0, 1, 'C');

// Info Section (2-Column Box)
$infoBoxY = $photoY + $photoH + 10.2;
$pdf->SetFillColor(248, 250, 252);
$pdf->SetDrawColor(226, 232, 240);
$pdf->Rect($startX + 3, $infoBoxY, $cardW - 6, 12.0, 'FD');

$leftInfoX = $startX + 4.5;
$rightInfoX = $startX + 27.5;

// Left column: Official Post & Gram Panchayat
$pdf->SetXY($leftInfoX, $infoBoxY + 1.0);
$pdf->SetFont('Arial', '', 3.8);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(22, 1.8, $latin1('Official Post:'), 0, 1, 'L');
$pdf->SetX($leftInfoX);
$pdf->SetFont('Arial', 'B', 4.5);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(22, 2.5, $latin1((string)($member['designation'] ?? 'PDO')), 0, 1, 'L');

$pdf->SetXY($leftInfoX, $infoBoxY + 6.2);
$pdf->SetFont('Arial', '', 3.8);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(22, 1.8, $latin1('Gram Panchayat:'), 0, 1, 'L');
$pdf->SetX($leftInfoX);
$pdf->SetFont('Arial', 'B', 4.5);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(22, 2.5, $latin1((string)($member['gp_name'] ?? '—')), 0, 1, 'L');

// Right column: KGID Number & Taluk/District
$pdf->SetXY($rightInfoX, $infoBoxY + 1.0);
$pdf->SetFont('Arial', '', 3.8);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(22, 1.8, $latin1('KGID Number:'), 0, 1, 'L');
$pdf->SetX($rightInfoX);
$pdf->SetFont('Arial', 'B', 4.5);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(22, 2.5, $latin1((string)($profile['kgid_no'] ?? '—')), 0, 1, 'L');

$pdf->SetXY($rightInfoX, $infoBoxY + 6.2);
$pdf->SetFont('Arial', '', 3.8);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(22, 1.8, $latin1('Taluk / District:'), 0, 1, 'L');
$pdf->SetX($rightInfoX);
$pdf->SetFont('Arial', 'B', 4.2);
$pdf->SetTextColor(15, 23, 42);
$locStr = (string)($member['taluk_name'] ?? '') . ', ' . (string)($member['district_name'] ?? '');
$pdf->Cell(22, 2.5, $latin1(strtoupper(trim($locStr, ', '))), 0, 1, 'L');

// Bottom Badges: Blood & Valid Till
$badgeY = $infoBoxY + 13.2;
$pdf->SetFillColor(254, 226, 226);
$pdf->SetDrawColor(254, 202, 202);
$pdf->Rect($startX + 3, $badgeY, 21, 4.0, 'FD');
$pdf->SetXY($startX + 3, $badgeY + 0.8);
$pdf->SetFont('Arial', 'B', 4.8);
$pdf->SetTextColor(185, 28, 28);
$pdf->Cell(21, 2.6, $latin1('Blood: ' . ($profile['blood_group'] ?? '—')), 0, 0, 'C');

$pdf->SetFillColor(220, 252, 231);
$pdf->SetDrawColor(187, 247, 208);
$pdf->Rect($startX + 27, $badgeY, 24, 4.0, 'FD');
$pdf->SetXY($startX + 27, $badgeY + 0.8);
$pdf->SetFont('Arial', 'B', 4.5);
$pdf->SetTextColor(21, 128, 61);
$pdf->Cell(24, 2.6, $latin1('VALID TILL ' . $validTillDate), 0, 1, 'C');

// Footer: Dark Blue Banner
$pdf->SetFillColor(30, 58, 138);
$pdf->Rect($startX, $startY + $cardH - 5.5, $cardW, 5.5, 'F');
$pdf->SetXY($startX, $startY + $cardH - 4.4);
$pdf->SetFont('Arial', 'B', 4.5);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell($cardW, 3.2, $latin1('UNITED WE STAND, TOGETHER WE SERVE'), 0, 1, 'C');

// Centered label under front card
$pdf->SetXY($startX, $startY + $cardH + 2.0);
$pdf->SetFont('Arial', '', 5.5);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell($cardW, 3.0, $latin1('Official Welfare Association Member Card'), 0, 1, 'C');

// ═════════════════════════════════════════════════════════════════════════════
// 2. BACK SIDE OF ID CARD (PORTRAIT: 54.0mm x 85.6mm)
// ═════════════════════════════════════════════════════════════════════════════
$backStartX = $startX + $cardW + $gap;
$backStartY = $startY;

// Outer Card Border
$pdf->SetDrawColor(148, 163, 184);
$pdf->SetLineWidth(0.35);
$pdf->Rect($backStartX, $backStartY, $cardW, $cardH, 'D');

// Header Background (Dark Charcoal)
$pdf->SetFillColor(15, 23, 42);
$pdf->Rect($backStartX, $backStartY, $cardW, 11.0, 'F');

// Yellow stripe
$pdf->SetFillColor(245, 158, 11);
$pdf->Rect($backStartX, $backStartY + 11.0, $cardW, 0.6, 'F');

$pdf->SetXY($backStartX, $backStartY + 1.2);
$pdf->SetFont('Arial', '', 3.6);
$pdf->SetTextColor(203, 213, 225);
$pdf->Cell($cardW, 2.0, $latin1('GOVERNMENT-RECOGNIZED SERVICE ASSOCIATION'), 0, 1, 'C');

$pdf->SetXY($backStartX, $backStartY + 3.6);
$pdf->SetFont('Arial', 'B', 4.5);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell($cardW, 2.6, $latin1('MEMBERSHIP IDENTITY & CONTACT INFORMATION'), 0, 1, 'C');

$pdf->SetXY($backStartX, $backStartY + 6.6);
$pdf->SetFont('Arial', '', 3.8);
$pdf->SetTextColor(203, 213, 225);
$pdf->Cell($cardW, 2.4, $latin1('KARNATAKA STATE PDO WELFARE ASSOCIATION (R)'), 0, 1, 'C');

// Emergency Section
$pdf->SetXY($backStartX + 3, $backStartY + 13.0);
$pdf->SetFont('Arial', 'B', 4.5);
$pdf->SetTextColor(30, 64, 175);
$pdf->Cell($cardW - 6, 2.6, $latin1('EMERGENCY & PERSONAL INFORMATION'), 0, 1, 'L');

$pdf->SetFillColor(248, 250, 252);
$pdf->SetDrawColor(226, 232, 240);
$pdf->Rect($backStartX + 3, $backStartY + 16.0, $cardW - 6, 12.0, 'FD');

$bLeftX = $backStartX + 4.5;
$bRightX = $backStartX + 29.0;

$pdf->SetXY($bLeftX, $backStartY + 17.0);
$pdf->SetFont('Arial', '', 3.6);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(20, 1.8, $latin1('Registered Mobile:'), 0, 1, 'L');
$pdf->SetX($bLeftX);
$pdf->SetFont('Arial', 'B', 4.5);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(20, 2.5, $latin1((string)($profile['personal_mobile'] ?? $member['mobile'] ?? '—')), 0, 1, 'L');

$pdf->SetXY($bLeftX, $backStartY + 22.0);
$pdf->SetFont('Arial', '', 3.6);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(20, 1.8, $latin1('Blood Group:'), 0, 1, 'L');
$pdf->SetX($bLeftX);
$pdf->SetFont('Arial', 'B', 4.5);
$pdf->SetTextColor(220, 38, 38);
$pdf->Cell(20, 2.5, $latin1((string)($profile['blood_group'] ?? '—')), 0, 1, 'L');

$pdf->SetXY($bRightX, $backStartY + 17.0);
$pdf->SetFont('Arial', '', 3.6);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(20, 1.8, $latin1('Native District:'), 0, 1, 'L');
$pdf->SetX($bRightX);
$pdf->SetFont('Arial', 'B', 4.5);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(20, 2.5, $latin1((string)($profile['native_district'] ?? '—')), 0, 1, 'L');

$pdf->SetXY($bRightX, $backStartY + 22.0);
$pdf->SetFont('Arial', '', 3.6);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(20, 1.8, $latin1('Financial Year:'), 0, 1, 'L');
$pdf->SetX($bRightX);
$pdf->SetFont('Arial', 'B', 4.5);
$pdf->SetTextColor(21, 128, 61);
$pdf->Cell(20, 2.5, $latin1((string)$fy), 0, 1, 'L');

// Association Box
$assocBoxY2 = $backStartY + 29.5;
$pdf->SetFillColor(248, 250, 252);
$pdf->SetDrawColor(203, 213, 225);
$pdf->Rect($backStartX + 3, $assocBoxY2, $cardW - 6, 21.0, 'FD');

$pdf->SetXY($backStartX + 4.5, $assocBoxY2 + 1.5);
$pdf->SetFont('Arial', 'B', 4.0);
$pdf->SetTextColor(15, 23, 42);
$pdf->MultiCell($cardW - 9, 2.0, $latin1($siteName));

$pdf->SetXY($backStartX + 4.5, $assocBoxY2 + 6.2);
$pdf->SetFont('Arial', '', 3.6);
$pdf->SetTextColor(71, 85, 105);
$pdf->MultiCell($cardW - 9, 2.0, $latin1($siteAddress));

$pdf->SetXY($backStartX + 4.5, $assocBoxY2 + 12.8);
$pdf->SetFont('Arial', '', 3.6);
$pdf->Cell($cardW - 9, 2.0, $latin1('Helpline: ' . $sitePhone . ' • Email: ' . $siteEmail), 0, 1, 'L');

$pdf->SetXY($backStartX + 4.5, $assocBoxY2 + 16.0);
$pdf->Cell($cardW - 9, 2.0, $latin1('Website: ' . $siteWebsite), 0, 1, 'L');

// Fine print & Signatory
$signSecY = $backStartY + 52.0;
$pdf->SetDrawColor(203, 213, 225);
$pdf->Line($backStartX + 3, $signSecY, $backStartX + $cardW - 3, $signSecY);

$pdf->SetXY($backStartX + 3, $signSecY + 1.5);
$pdf->SetFont('Arial', '', 3.2);
$pdf->SetTextColor(100, 116, 139);
$pdf->MultiCell(32, 1.8, $latin1(
    "1. Property of KSPDOWA; non-transferable.\n" .
    "2. If found, return to nearest office.\n" .
    "3. Valid till: " . $validTillDate . " (FY " . $fy . ")."
));

$pdf->SetXY($backStartX + $cardW - 19, $signSecY + 2.0);
$pdf->SetFont('Arial', 'B', 4.5);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(17, 2.0, $latin1('Sd/-'), 0, 1, 'C');
$pdf->SetX($backStartX + $cardW - 19);
$pdf->SetFont('Arial', 'B', 4.0);
$pdf->SetTextColor(30, 64, 175);
$pdf->Cell(17, 2.0, $latin1('General Secretary'), 0, 1, 'C');
$pdf->SetX($backStartX + $cardW - 19);
$pdf->SetFont('Arial', '', 3.2);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(17, 1.8, $latin1('KSPDOWA State Comm.'), 0, 1, 'C');

// Back Footer
$pdf->SetFillColor(226, 232, 240);
$pdf->Rect($backStartX, $backStartY + $cardH - 5.5, $cardW, 5.5, 'F');
$pdf->SetXY($backStartX + 2, $backStartY + $cardH - 4.4);
$pdf->SetFont('Arial', 'B', 3.8);
$pdf->SetTextColor(51, 65, 85);
$pdf->Cell(28, 3.2, $latin1('Government-Recognized Service Assn.'), 0, 0, 'L');
$pdf->Cell($cardW - 32, 3.2, $latin1('BENGALURU • KARNATAKA'), 0, 1, 'R');

// Label under back card
$pdf->SetXY($backStartX, $backStartY + $cardH + 2.0);
$pdf->SetFont('Arial', '', 5.5);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell($cardW, 3.0, $latin1('Back Side Information'), 0, 1, 'C');

// Instructions for printing
$pdf->SetXY(15, $startY + $cardH + 12.0);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(30, 64, 175);
$pdf->Cell(0, 5, $latin1('CR80 Printing & Lamination Instructions:'), 0, 1, 'L');
$pdf->SetFont('Arial', '', 8.5);
$pdf->SetTextColor(71, 85, 105);
$pdf->MultiCell(0, 4.4, $latin1(
    "1. Print at 100% actual scale on photo paper or 280+ GSM synthetic PVC paper.\n" .
    "2. Cut along the outer rectangle guides for Front and Back sides.\n" .
    "3. Standard CR80 Size (54.0 mm x 85.6 mm) fits all standard vertical badge holders and pouches."
));

// Output PDF
$filename = 'KSPDOWA-ID-' . ($member['member_no'] ?? 'CARD') . '.pdf';
$dest = (isset($_GET['dl']) && $_GET['dl'] === '1') ? 'D' : 'I';
$pdf->Output($dest, $filename);
