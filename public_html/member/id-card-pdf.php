<?php
/**
 * KSPDOWA — Member Portal: Official Digital ID Card PDF Generator
 * ============================================================
 * Generates an official printable ID Card document with Front & Back
 * sides formatted to the Global Standard Horizontal CR80 specification
 * (85.6mm x 54.0mm) on a standard printable A4 page with cut guides.
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
$siteName     = Settings::get('site_name', 'Karnataka State Panchayat Development Officers Welfare Association (R)');
$siteTagline  = Settings::get('site_tagline', 'Reg. No. DRB/SOR/534/2012-13 • Bengaluru');
$siteAddress  = Settings::get('site_address', 'State Central Office, Bengaluru, Karnataka');
$sitePhone    = Settings::get('site_phone', '9036880026');
$siteEmail    = Settings::get('site_email', 'contact@kspdowa.org');
$siteWebsite  = Settings::get('site_website', 'https://kspdowa.org');

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

// Check Association Designation from office_bearers or member profile
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
$pdf->Cell(0, 5, $latin1('Official CR80 Standard Horizontal Identity Card • ' . $siteTagline), 0, 1, 'C');
$pdf->Ln(4);

// ─────────────────────────────────────────────────────────────────────────────
// CR80 STANDARD HORIZONTAL CARD DIMENSIONS: 85.6mm Width x 54.0mm Height
// ─────────────────────────────────────────────────────────────────────────────
$cardW = 85.6;
$cardH = 54.0;
$gap   = 10.0;
$startX = (210.0 - ($cardW * 2 + $gap)) / 2.0; // Centered on A4 (14.4mm margins)
$startY = 44.0;

// ═════════════════════════════════════════════════════════════════════════════
// 1. FRONT SIDE OF ID CARD (85.6mm x 54.0mm)
// ═════════════════════════════════════════════════════════════════════════════

// Outer Card Border
$pdf->SetDrawColor(148, 163, 184);
$pdf->SetLineWidth(0.35);
$pdf->Rect($startX, $startY, $cardW, $cardH, 'D');

// Header Background (Navy Blue)
$pdf->SetFillColor(30, 58, 138);
$pdf->Rect($startX, $startY, $cardW, 11.5, 'F');

// Gold accent line under header
$pdf->SetFillColor(245, 158, 11);
$pdf->Rect($startX, $startY + 11.5, $cardW, 0.7, 'F');

// Left Logo (8.5mm x 8.5mm)
if (is_file($logoLeftAbs)) {
    try {
        $pdf->Image($logoLeftAbs, $startX + 1.5, $startY + 1.5, 8.5, 8.5);
    } catch (Throwable $e) {}
}

// Right Logo (8.5mm x 8.5mm)
if (is_file($logoRightAbs)) {
    try {
        $pdf->Image($logoRightAbs, $startX + $cardW - 10.0, $startY + 1.5, 8.5, 8.5);
    } catch (Throwable $e) {}
}

// Header Titles (between logos)
$headW = $cardW - 23.0;
$headX = $startX + 11.5;

$pdf->SetXY($headX, $startY + 1.2);
$pdf->SetFont('Arial', 'B', 5.5);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell($headW, 3.2, $latin1('KARNATAKA STATE PDO WELFARE ASSOCIATION (R)'), 0, 1, 'C');

$pdf->SetXY($headX, $startY + 4.4);
$pdf->SetFont('Arial', 'B', 5);
$pdf->SetTextColor(253, 224, 71); // Gold
$pdf->Cell($headW, 3, $latin1($siteName), 0, 1, 'C');

$pdf->SetXY($headX, $startY + 7.4);
$pdf->SetFont('Arial', '', 4.5);
$pdf->SetTextColor(224, 231, 255);
$pdf->Cell($headW, 3, $latin1($siteTagline), 0, 1, 'C');

// Photo Box (Left side: 17mm width x 21mm height)
$photoX = $startX + 3.0;
$photoY = $startY + 14.0;
$photoW = 17.0;
$photoH = 21.0;

$photoRendered = false;
if (!empty($member['photo_path'])) {
    $fullPhotoPath = PUBLIC_HTML . '/' . ltrim($member['photo_path'], '/');
    if (is_file($fullPhotoPath)) {
        try {
            $pdf->Image($fullPhotoPath, $photoX, $photoY, $photoW, $photoH);
            $photoRendered = true;
        } catch (Throwable $e) {
            $photoRendered = false;
        }
    }
}

if (!$photoRendered) {
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->Rect($photoX, $photoY, $photoW, $photoH, 'FD');
    $pdf->SetXY($photoX, $photoY + 8);
    $pdf->SetFont('Arial', 'B', 6);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->Cell($photoW, 4, $latin1('PHOTO'), 0, 1, 'C');
} else {
    $pdf->SetDrawColor(37, 99, 235);
    $pdf->SetLineWidth(0.3);
    $pdf->Rect($photoX, $photoY, $photoW, $photoH, 'D');
}

// Blood Group Pill under photo
$pdf->SetFillColor(254, 242, 242);
$pdf->SetDrawColor(254, 202, 202);
$pdf->Rect($photoX, $photoY + $photoH + 1.2, $photoW, 4.2, 'FD');
$pdf->SetXY($photoX, $photoY + $photoH + 1.5);
$pdf->SetFont('Arial', 'B', 5);
$pdf->SetTextColor(220, 38, 38);
$pdf->Cell($photoW, 3.5, $latin1('Blood: ' . ($profile['blood_group'] ?? '—')), 0, 1, 'C');

// Validity Pill under blood group
$pdf->SetFillColor(236, 253, 245);
$pdf->SetDrawColor(167, 243, 208);
$pdf->Rect($photoX, $photoY + $photoH + 6.0, $photoW, 5.0, 'FD');
$pdf->SetXY($photoX, $photoY + $photoH + 6.2);
$pdf->SetFont('Arial', 'B', 4);
$pdf->SetTextColor(4, 120, 87);
$pdf->Cell($photoW, 2.3, $latin1('VALID TILL'), 0, 1, 'C');
$pdf->SetXY($photoX, $photoY + $photoH + 8.4);
$pdf->SetFont('Arial', 'B', 4.8);
$pdf->SetTextColor(6, 95, 70);
$pdf->Cell($photoW, 2.3, $latin1($validTillDate), 0, 1, 'C');

// Right Column: Member Details
$detailX = $photoX + $photoW + 3.0;
$detailW = $cardW - $photoW - 8.0;

// Member Name
$pdf->SetXY($detailX, $startY + 13.5);
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell($detailW, 3.8, $latin1(strtoupper((string)($member['name'] ?? ''))), 0, 1, 'L');

// Member No & Verified Chip
$pdf->SetXY($detailX, $startY + 17.5);
$pdf->SetFont('Arial', 'B', 6);
$pdf->SetTextColor(30, 64, 175);
$pdf->Cell(28, 3.2, $latin1($member['member_no'] ?? ''), 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 5);
$pdf->SetTextColor(22, 101, 52);
$pdf->Cell(25, 3.2, $latin1('✓ ACTIVE MEMBER'), 0, 1, 'L');

// Association Designation Banner (Replacing PDO!)
$assocBoxY = $startY + 21.5;
if ($isRepresentative) {
    $pdf->SetFillColor(254, 243, 199);
    $pdf->SetDrawColor(245, 158, 11);
} else {
    $pdf->SetFillColor(240, 249, 255);
    $pdf->SetDrawColor(186, 230, 253);
}
$pdf->Rect($detailX, $assocBoxY, $detailW, 6.8, 'FD');

$pdf->SetXY($detailX + 1.5, $assocBoxY + 0.8);
$pdf->SetFont('Arial', 'B', 4.2);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell($detailW - 3, 2, $latin1('ASSOCIATION DESIGNATION:'), 0, 1, 'L');

$pdf->SetXY($detailX + 1.5, $assocBoxY + 2.7);
$pdf->SetFont('Arial', 'B', 6.0);
$pdf->SetTextColor($isRepresentative ? 146 : 30, $isRepresentative ? 64 : 64, $isRepresentative ? 14 : 175);
$assocTitleLine = $assocDesignation . ($assocSubLabel ? ' • ' . $assocSubLabel : '');
$pdf->Cell($detailW - 3, 3.2, $latin1($assocTitleLine), 0, 1, 'L');

// Service Details Grid
$details = [
    ['Official Post:', (string)($member['designation'] ?? 'Panchayat Development Officer')],
    ['KGID No:', (string)($profile['kgid_no'] ?? '—')],
    ['Gram Panchayat:', (string)($member['gp_name'] ?? '—')],
    ['Taluk & District:', (string)($member['taluk_name'] ?? '—') . ', ' . (string)($member['district_name'] ?? '—')]
];

$gridY = $assocBoxY + 7.8;
$pdf->SetFont('Arial', '', 4.8);

foreach ($details as [$label, $val]) {
    $pdf->SetXY($detailX, $gridY);
    $pdf->SetFont('Arial', '', 4.6);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(22, 2.9, $latin1($label), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 4.8);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell($detailW - 22, 2.9, $latin1($val), 0, 1, 'L');
    $gridY += 3.0;
}

// Card Front Footer
$pdf->SetFillColor(248, 250, 252);
$pdf->Rect($startX, $startY + $cardH - 5.0, $cardW, 5.0, 'F');
$pdf->SetDrawColor(226, 232, 240);
$pdf->Line($startX, $startY + $cardH - 5.0, $startX + $cardW, $startY + $cardH - 5.0);

$pdf->SetXY($startX + 2.5, $startY + $cardH - 4.2);
$pdf->SetFont('Arial', '', 4.2);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(45, 3.5, $latin1('KSPDOWA • OFFICIAL VERIFIED MEMBER'), 0, 0, 'L');

$pdf->SetFont('Arial', 'B', 4.2);
$pdf->SetTextColor(30, 64, 175);
$pdf->Cell($cardW - 50, 3.5, $latin1('STATE OF KARNATAKA'), 0, 1, 'R');

// Label under front card
$pdf->SetXY($startX, $startY + $cardH + 2.0);
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell($cardW, 4, $latin1('FRONT SIDE (ಮುಂಭಾಗ)'), 0, 1, 'C');

// ═════════════════════════════════════════════════════════════════════════════
// 2. BACK SIDE OF ID CARD (85.6mm x 54.0mm — EXACT SAME SIZE)
// ═════════════════════════════════════════════════════════════════════════════
$backStartX = $startX + $cardW + $gap;
$backStartY = $startY;

// Outer Card Border
$pdf->SetDrawColor(148, 163, 184);
$pdf->SetLineWidth(0.35);
$pdf->Rect($backStartX, $backStartY, $cardW, $cardH, 'D');

// Back Header
$pdf->SetFillColor(15, 23, 42); // Dark slate
$pdf->Rect($backStartX, $backStartY, $cardW, 7.5, 'F');

// Gold stripe under back header
$pdf->SetFillColor(245, 158, 11);
$pdf->Rect($backStartX, $backStartY + 7.5, $cardW, 0.6, 'F');

$pdf->SetXY($backStartX, $backStartY + 1.5);
$pdf->SetFont('Arial', 'B', 5.5);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell($cardW, 3.0, $latin1('MEMBERSHIP TERMS & ASSOCIATION CONTACT'), 0, 1, 'C');

$pdf->SetXY($backStartX, $backStartY + 4.2);
$pdf->SetFont('Arial', '', 4.2);
$pdf->SetTextColor(203, 213, 225);
$pdf->Cell($cardW, 2.5, $latin1($siteName), 0, 1, 'C');

// Back Content: Emergency & Office
$backContentX = $backStartX + 3.0;
$backContentW = $cardW - 6.0;

// Emergency Contacts Box
$pdf->SetXY($backContentX, $backStartY + 9.5);
$pdf->SetFont('Arial', 'B', 5.0);
$pdf->SetTextColor(30, 64, 175);
$pdf->Cell($backContentW, 3.0, $latin1('EMERGENCY & PERSONAL INFORMATION:'), 0, 1, 'L');

$pdf->SetFillColor(248, 250, 252);
$pdf->SetDrawColor(226, 232, 240);
$pdf->Rect($backContentX, $backStartY + 12.8, $backContentW, 9.0, 'FD');

$pdf->SetXY($backContentX + 1.5, $backStartY + 13.5);
$pdf->SetFont('Arial', '', 4.6);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(24, 2.7, $latin1('Registered Mobile:'), 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 4.8);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(20, 2.7, $latin1((string)($profile['personal_mobile'] ?? $member['mobile'] ?? '—')), 0, 0, 'L');

$pdf->SetFont('Arial', '', 4.6);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(20, 2.7, $latin1('Native District:'), 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 4.8);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(0, 2.7, $latin1((string)($profile['native_district'] ?? '—')), 0, 1, 'L');

$pdf->SetXY($backContentX + 1.5, $backStartY + 17.5);
$pdf->SetFont('Arial', '', 4.6);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(24, 2.7, $latin1('Blood Group:'), 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 4.8);
$pdf->SetTextColor(220, 38, 38);
$pdf->Cell(20, 2.7, $latin1((string)($profile['blood_group'] ?? '—')), 0, 0, 'L');

$pdf->SetFont('Arial', '', 4.6);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(20, 2.7, $latin1('Financial Year:'), 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 4.8);
$pdf->SetTextColor(21, 128, 61);
$pdf->Cell(0, 2.7, $latin1((string)$fy), 0, 1, 'L');

// Central Association Office Box (Settings Integration)
$officeBoxY = $backStartY + 23.0;
$pdf->SetXY($backContentX, $officeBoxY);
$pdf->SetFont('Arial', 'B', 4.8);
$pdf->SetTextColor(30, 64, 175);
$pdf->Cell($backContentW, 2.7, $latin1('CENTRAL ASSOCIATION OFFICE:'), 0, 1, 'L');

$pdf->SetFillColor(248, 250, 252);
$pdf->Rect($backContentX, $officeBoxY + 3.0, $backContentW, 11.5, 'FD');

$pdf->SetXY($backContentX + 1.5, $officeBoxY + 3.8);
$pdf->SetFont('Arial', 'B', 4.6);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell($backContentW - 3, 2.4, $latin1($siteName), 0, 1, 'L');

$pdf->SetXY($backContentX + 1.5, $officeBoxY + 6.4);
$pdf->SetFont('Arial', '', 4.3);
$pdf->SetTextColor(71, 85, 105);
$pdf->Cell($backContentW - 3, 2.4, $latin1($siteAddress), 0, 1, 'L');

$pdf->SetXY($backContentX + 1.5, $officeBoxY + 9.0);
$pdf->Cell($backContentW - 3, 2.4, $latin1('Helpline: ' . $sitePhone . '  •  Email: ' . $siteEmail), 0, 1, 'L');

$pdf->SetXY($backContentX + 1.5, $officeBoxY + 11.6);
$pdf->Cell($backContentW - 3, 2.4, $latin1('Website: ' . $siteWebsite), 0, 1, 'L');

// Terms & Instructions + Signatory
$termsY = $officeBoxY + 16.0;
$pdf->SetDrawColor(203, 213, 225);
$pdf->Line($backContentX, $termsY, $backContentX + $backContentW, $termsY);

$pdf->SetXY($backContentX, $termsY + 1.0);
$pdf->SetFont('Arial', '', 4.0);
$pdf->SetTextColor(100, 116, 139);
$pdf->MultiCell(52, 2.1, $latin1(
    "1. Property of KSPDOWA; non-transferable.\n" .
    "2. If found, please return to nearest office.\n" .
    "3. Valid till: " . $validTillDate . " (subject to renewal)."
));

// Signatory Block (Right side of Terms)
$pdf->SetXY($backStartX + $cardW - 28.0, $termsY + 1.2);
$pdf->SetFont('Arial', 'B', 5.0);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(25, 2.2, $latin1('Sd/-'), 0, 1, 'C');
$pdf->SetX($backStartX + $cardW - 28.0);
$pdf->SetFont('Arial', 'B', 4.8);
$pdf->SetTextColor(30, 64, 175);
$pdf->Cell(25, 2.2, $latin1('General Secretary'), 0, 1, 'C');
$pdf->SetX($backStartX + $cardW - 28.0);
$pdf->SetFont('Arial', '', 4.0);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(25, 2.0, $latin1('KSPDOWA State Committee'), 0, 1, 'C');

// Card Back Footer
$pdf->SetFillColor(248, 250, 252);
$pdf->Rect($backStartX, $backStartY + $cardH - 5.0, $cardW, 5.0, 'F');
$pdf->SetDrawColor(226, 232, 240);
$pdf->Line($backStartX, $backStartY + $cardH - 5.0, $backStartX + $cardW, $backStartY + $cardH - 5.0);

$pdf->SetXY($backStartX + 2.5, $backStartY + $cardH - 4.2);
$pdf->SetFont('Arial', '', 4.2);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(45, 3.5, $latin1($siteTagline), 0, 0, 'L');

$pdf->SetFont('Arial', 'B', 4.2);
$pdf->SetTextColor(71, 85, 105);
$pdf->Cell($cardW - 50, 3.5, $latin1('BENGALURU • KARNATAKA'), 0, 1, 'R');

// Label under back card
$pdf->SetXY($backStartX, $backStartY + $cardH + 2.0);
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell($cardW, 4, $latin1('BACK SIDE (ಹಿಂಭಾಗ)'), 0, 1, 'C');

// ─────────────────────────────────────────────────────────────────────────────
// PRINTING & PVC CARD GUIDELINES SECTION
// ─────────────────────────────────────────────────────────────────────────────
$guideY = $startY + $cardH + 12.0;
$pdf->SetXY(14.4, $guideY);
$pdf->SetFont('Arial', 'B', 9.5);
$pdf->SetTextColor(30, 64, 175);
$pdf->Cell(0, 5, $latin1('CR80 Printing & Lamination Instructions (ಮುದ್ರಣ ಮತ್ತು ಲ್ಯಾಮಿನೇಷನ್ ಮಾರ್ಗಸೂಚಿ):'), 0, 1, 'L');

$pdf->SetFont('Arial', '', 8.5);
$pdf->SetTextColor(71, 85, 105);
$pdf->MultiCell(0, 4.4, $latin1(
    "1. Standard CR80 Size: Front and back cards are exactly 85.60 mm x 53.98 mm (Standard ID-1 / Credit Card dimensions).\n" .
    "2. Print Scale: Print this sheet at 100% actual scale (do NOT select 'Fit to Page' or 'Shrink to Printable Area').\n" .
    "3. Paper Recommendation: Use 280+ GSM photo paper, glossy synthetic paper, or direct PVC card printer.\n" .
    "4. Assembly: Cut along the outer rectangle guidelines and place both cards inside a standard PVC ID pouch or laminate."
));

// Output PDF
$filename = 'KSPDOWA-ID-' . ($member['member_no'] ?? 'CARD') . '.pdf';
$dest = (isset($_GET['dl']) && $_GET['dl'] === '1') ? 'D' : 'I';
$pdf->Output($dest, $filename);
