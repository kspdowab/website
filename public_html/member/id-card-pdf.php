<?php
/**
 * KSPDOWA — Member Portal: Official Digital ID Card PDF Generator
 * ============================================================
 * Generates an official printable ID Card document with Front & Back
 * sides on a standard printable page with cut guidelines.
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

// Page Title & Header
$pdf->SetFont('Arial', 'B', 16);
$pdf->SetTextColor(30, 64, 175);
$pdf->Cell(0, 10, $latin1('KARNATAKA STATE PANCHAYAT DEVELOPMENT OFFICER'), 0, 1, 'C');
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 7, $latin1('WELFARE ASSOCIATION (R) • BENGALURU'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(0, 5, $latin1('Official Membership Identity Card • Reg. No. DRB/SOR/534/2012-13'), 0, 1, 'C');
$pdf->Ln(6);

// Card Dimensions (Standard CR80 size scaled slightly for A4 print: 90mm width x 60mm height)
$cardW = 90;
$cardH = 60;
$startX = 15;
$startY = 42;

// ═════════════════════════════════════════════════════════════════════════════
// 1. FRONT SIDE OF ID CARD
// ═════════════════════════════════════════════════════════════════════════════
$pdf->SetDrawColor(203, 213, 225);
$pdf->SetLineWidth(0.4);
$pdf->Rect($startX, $startY, $cardW, $cardH, 'D');

// Card Header Background (Blue)
$pdf->SetFillColor(30, 64, 175);
$pdf->Rect($startX, $startY, $cardW, 14, 'F');

// Association Title in Header
$pdf->SetXY($startX, $startY + 1.5);
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell($cardW, 4, $latin1('KARNATAKA STATE PDO WELFARE ASSOCIATION (R)'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 5.5);
$pdf->SetTextColor(224, 231, 255);
$pdf->SetX($startX);
$pdf->Cell($cardW, 3, $latin1('Reg. No. DRB/SOR/534/2012-13 • Bengaluru'), 0, 1, 'C');
$pdf->SetFont('Arial', 'B', 5.5);
$pdf->SetTextColor(253, 224, 71); // Gold accent
$pdf->SetX($startX);
$pdf->Cell($cardW, 3, $latin1('OFFICIAL MEMBER IDENTITY CARD'), 0, 1, 'C');

// Photo Box (Left side: 20mm x 24mm)
$photoX = $startX + 4;
$photoY = $startY + 16;
$photoW = 18;
$photoH = 22;

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
    // Border around image
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->Rect($photoX, $photoY, $photoW, $photoH, 'D');
}

// ID & Member No badge under photo
$pdf->SetXY($photoX, $photoY + $photoH + 1);
$pdf->SetFont('Arial', 'B', 5);
$pdf->SetTextColor(30, 64, 175);
$pdf->Cell($photoW, 3, $latin1($member['member_no'] ?? ''), 0, 1, 'C');

// Member Details on Right of Photo
$detailX = $photoX + $photoW + 3;
$detailY = $startY + 16;

$pdf->SetXY($detailX, $detailY);
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell($cardW - $photoW - 10, 4, $latin1(strtoupper((string)($member['name'] ?? ''))), 0, 1, 'L');

$pdf->SetX($detailX);
$pdf->SetFont('Arial', 'B', 6.5);
$pdf->SetTextColor(37, 99, 235);
$pdf->Cell($cardW - $photoW - 10, 3.5, $latin1((string)($member['designation'] ?? 'Panchayat Development Officer')), 0, 1, 'L');

// Grid of details
$details = [
    ['KGID No:', (string)($profile['kgid_no'] ?? '—')],
    ['Blood Group:', (string)($profile['blood_group'] ?? '—')],
    ['District:', (string)($member['district_name'] ?? '—')],
    ['Taluk:', (string)($member['taluk_name'] ?? '—')],
    ['Gram Panchayat:', (string)($member['gp_name'] ?? '—')],
    ['Valid For FY:', (string)$fy]
];

$pdf->SetFont('Arial', '', 5.5);
$pdf->Ln(0.5);

foreach ($details as [$label, $val]) {
    $pdf->SetX($detailX);
    $pdf->SetFont('Arial', 'B', 5.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(20, 3, $latin1($label), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 5.5);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(45, 3, $latin1($val), 0, 1, 'L');
}

// Card Front Footer
$pdf->SetFillColor(241, 245, 249);
$pdf->Rect($startX, $startY + $cardH - 6, $cardW, 6, 'F');
$pdf->SetXY($startX + 3, $startY + $cardH - 5);
$pdf->SetFont('Arial', 'B', 5);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(40, 4, $latin1('KSPDOWA • VERIFIED MEMBER'), 0, 0, 'L');
$pdf->SetTextColor(22, 101, 52);
$pdf->Cell($cardW - 46, 4, $latin1('ACTIVE MEMBERSHIP'), 0, 1, 'R');

// Label under front card
$pdf->SetXY($startX, $startY + $cardH + 2);
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell($cardW, 4, $latin1('FRONT SIDE'), 0, 1, 'C');

// ═════════════════════════════════════════════════════════════════════════════
// 2. BACK SIDE OF ID CARD
// ═════════════════════════════════════════════════════════════════════════════
$backStartX = $startX + $cardW + 10;
$backStartY = $startY;

$pdf->SetDrawColor(203, 213, 225);
$pdf->Rect($backStartX, $backStartY, $cardW, $cardH, 'D');

// Back Header
$pdf->SetFillColor(15, 23, 42); // Slate dark
$pdf->Rect($backStartX, $backStartY, $cardW, 9, 'F');
$pdf->SetXY($backStartX, $backStartY + 2);
$pdf->SetFont('Arial', 'B', 6.5);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell($cardW, 5, $latin1('MEMBERSHIP TERMS & ASSOCIATION INFORMATION'), 0, 1, 'C');

// Back Content
$pdf->SetXY($backStartX + 4, $backStartY + 11);
$pdf->SetFont('Arial', 'B', 6);
$pdf->SetTextColor(30, 64, 175);
$pdf->Cell($cardW - 8, 3.5, $latin1('EMERGENCY & CONTACT INFORMATION'), 0, 1, 'L');

$pdf->SetFont('Arial', '', 5.5);
$pdf->SetTextColor(51, 65, 85);
$pdf->SetX($backStartX + 4);
$pdf->Cell(26, 3.2, $latin1('Registered Mobile:'), 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 5.5);
$pdf->Cell(0, 3.2, $latin1((string)($profile['personal_mobile'] ?? '—')), 0, 1, 'L');

$pdf->SetFont('Arial', '', 5.5);
$pdf->SetX($backStartX + 4);
$pdf->Cell(26, 3.2, $latin1('Native District:'), 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 5.5);
$pdf->Cell(0, 3.2, $latin1((string)($profile['native_district'] ?? '—')), 0, 1, 'L');

$pdf->SetFont('Arial', '', 5.5);
$pdf->SetX($backStartX + 4);
$pdf->Cell(26, 3.2, $latin1('Highest Qualification:'), 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 5.5);
$pdf->Cell(0, 3.2, $latin1((string)($profile['highest_qualification'] ?? '—')), 0, 1, 'L');

// Association Registered Office
$pdf->Ln(1);
$pdf->SetX($backStartX + 4);
$pdf->SetFont('Arial', 'B', 5.5);
$pdf->SetTextColor(30, 64, 175);
$pdf->Cell($cardW - 8, 3, $latin1('CENTRAL ASSOCIATION OFFICE:'), 0, 1, 'L');

$pdf->SetX($backStartX + 4);
$pdf->SetFont('Arial', '', 5);
$pdf->SetTextColor(71, 85, 105);
$pdf->MultiCell($cardW - 8, 2.7, $latin1(
    "Karnataka State Panchayat Development Officer Welfare Association (R)\n" .
    "Bengaluru, Karnataka • Website: kspdowa.org • Helpline: 9036880026"
));

// Terms note
$pdf->Ln(1);
$pdf->SetX($backStartX + 4);
$pdf->SetFont('Arial', 'I', 4.5);
$pdf->SetTextColor(148, 163, 184);
$pdf->MultiCell($cardW - 8, 2.2, $latin1(
    "1. This card is non-transferable and is the property of KSPDOWA.\n" .
    "2. If found, please return to the nearest Taluk/District Association unit.\n" .
    "3. Valid subject to annual membership fee renewal."
));

// Signature Box
$pdf->SetXY($backStartX + $cardW - 32, $backStartY + $cardH - 12);
$pdf->SetFont('Arial', 'B', 5);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(28, 3, $latin1('Sd/-'), 0, 1, 'C');
$pdf->SetX($backStartX + $cardW - 32);
$pdf->Cell(28, 3, $latin1('General Secretary'), 0, 1, 'C');
$pdf->SetX($backStartX + $cardW - 32);
$pdf->SetFont('Arial', '', 4.5);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(28, 2.5, $latin1('KSPDOWA State Committee'), 0, 1, 'C');

// Label under back card
$pdf->SetXY($backStartX, $backStartY + $cardH + 2);
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell($cardW, 4, $latin1('BACK SIDE'), 0, 1, 'C');

// Instructions for printing
$pdf->SetXY(15, $startY + $cardH + 14);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(30, 64, 175);
$pdf->Cell(0, 5, $latin1('Printing & Lamination Instructions (ಮುದ್ರಣ ಮಾರ್ಗಸೂಚಿ):'), 0, 1, 'L');

$pdf->SetFont('Arial', '', 8.5);
$pdf->SetTextColor(71, 85, 105);
$pdf->MultiCell(0, 4.5, $latin1(
    "● Print at 100% actual scale (do not fit to page) on photo paper or 250+ GSM cardstock.\n" .
    "● Cut along the thin rectangular borders for both the Front and Back sides.\n" .
    "● Place front and back side-by-side or back-to-back inside a standard PVC pouch or laminate for daily official use."
));

// Output PDF
$filename = 'KSPDOWA-ID-' . ($member['member_no'] ?? 'CARD') . '.pdf';
$dest = (isset($_GET['dl']) && $_GET['dl'] === '1') ? 'D' : 'I';
$pdf->Output($dest, $filename);
