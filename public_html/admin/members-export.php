<?php
/**
 * KSPDOWA — Admin: Members Export (PDF / Excel XML)
 * ============================================================
 * Specification: docs/11_MEMBERS_MODULE_SPECIFICATION.md
 *
 * Sections 15, 16, 17 — Detailed Members Export
 * Sections 21, 22, 23 — Abstract Export
 *
 * Detailed columns (exact spec order):
 *   Sl.No | Name | Father Name | Gender | Phone | Email | KGID | Taluk | District | Date
 *
 * Masking: 3 random numeric digits masked with * in KGID, Phone, Email
 * Title:   Single-line dynamic per spec §17 / §22
 * Footer:  Designed & Developed by : KHUBAASING JADAV
 * ============================================================
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/lib/fpdf.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();
if (!RBAC::can($currentUserId, 'reports', 'export') && !RBAC::can($currentUserId, 'members', 'export') && !RBAC::can($currentUserId, 'reports', 'view') && !RBAC::can($currentUserId, 'members', 'manage')) {
    ErrorHandler::abort(403, 'You do not have permission to export this report.');
}

// ─── RBAC / Geographic scope ────────────────────────────────────────────────
$associationUnitId = RBAC::getUserAssociationUnit($currentUserId);
$lockedDistrictId  = null;
$lockedTalukId     = null;

if ($associationUnitId) {
    $unit = Database::fetchOne("SELECT * FROM association_units WHERE id = ?", [$associationUnitId]);
    if ($unit) {
        if ($unit['unit_type'] === 'district') {
            $lockedDistrictId = (int)$unit['district_id'];
        } elseif ($unit['unit_type'] === 'taluk') {
            $lockedDistrictId = (int)$unit['district_id'];
            $lockedTalukId    = (int)$unit['taluk_id'];
        }
    }
}

// ─── Input parameters ────────────────────────────────────────────────────────
$format     = Sanitize::inArray($_GET['format']      ?? '', ['pdf','excel']) ?: 'pdf';
$reportType = Sanitize::inArray($_GET['report_type'] ?? '', ['detailed','abstract_district','abstract_taluk']) ?: 'detailed';

$fyId              = Sanitize::positiveInt($_GET['fy_id']           ?? null);
$filterDistrictId  = Sanitize::positiveInt($_GET['district_id']     ?? null);
$filterTalukId     = Sanitize::positiveInt($_GET['taluk_id']        ?? null);
$paymentStatus     = Sanitize::inArray($_GET['payment_status']      ?? '', ['all','paid','unpaid']) ?: 'all';
$search            = trim(Sanitize::string($_GET['q']               ?? '', 100));

// Sort params (used by abstract exports to match report page sort)
$allowedSortDistrict = ['district_name','total_members','paid_members','unpaid_members'];
$allowedSortTaluk    = ['district_name','taluk_name','total_members','paid_members','unpaid_members'];
$allowedSort         = ($reportType === 'abstract_taluk') ? $allowedSortTaluk : $allowedSortDistrict;
$sortBy  = Sanitize::inArray($_GET['sort_by']  ?? '', $allowedSort)  ?: 'paid_members';
$sortDir = Sanitize::inArray($_GET['sort_dir'] ?? '', ['asc','desc']) ?: 'desc';
$sqlDir  = $sortDir === 'asc' ? 'ASC' : 'DESC';

// Server-side RBAC override — cannot be bypassed via GET params
if ($lockedDistrictId) { $filterDistrictId = $lockedDistrictId; }
if ($lockedTalukId)    { $filterTalukId    = $lockedTalukId;    }

// ─── Financial Year lookup ───────────────────────────────────────────────────
$selectedYearName = '';
if ($fyId) {
    $yRow = Database::fetchOne("SELECT financial_year FROM membership_years WHERE id = ?", [$fyId]);
    if ($yRow) {
        $selectedYearName = $yRow['financial_year']; // e.g. "2026-27"
    }
}
if ($selectedYearName === '') {
    $activeYear = Database::fetchOne("SELECT financial_year FROM membership_years WHERE status='active' ORDER BY start_date DESC LIMIT 1");
    $selectedYearName = $activeYear ? $activeYear['financial_year'] : date('Y').'-'.substr((string)(date('Y')+1),-2);
}

// ─── District / Taluk name for title ─────────────────────────────────────────
$districtName = 'ALL DISTRICTS';
$talukName    = '';

if ($filterDistrictId) {
    $dRow = Database::fetchOne("SELECT name FROM districts WHERE id = ?", [$filterDistrictId]);
    if ($dRow) { $districtName = strtoupper($dRow['name']); }
}
if ($filterTalukId) {
    $tRow = Database::fetchOne("SELECT name FROM taluks WHERE id = ?", [$filterTalukId]);
    if ($tRow) { $talukName = strtoupper($tRow['name']); }
}

// ─── Title (spec §17 / §22) — single line ────────────────────────────────────
$nowDate = date('d-m-Y');
$nowTime = date('h:i A');   // AM/PM

if ($reportType === 'detailed') {
    if ($filterTalukId && $talukName !== '') {
        // Taluk title
        $reportTitle = "KSPDOWA BENGALURU - {$talukName} - ({$districtName}) TALUKA {$selectedYearName} MEMBERS LIST AS ON : {$nowDate} {$nowTime}";
    } elseif ($filterDistrictId && $districtName !== 'ALL DISTRICTS') {
        // District title
        $reportTitle = "KSPDOWA BENGALURU - {$districtName} DISTRICT {$selectedYearName} MEMBERS LIST AS ON : {$nowDate} {$nowTime}";
    } else {
        // All Districts title
        $reportTitle = "KSPDOWA BENGALURU - ALL DISTRICTS {$selectedYearName} MEMBERS LIST AS ON : {$nowDate} {$nowTime}";
    }
} elseif ($reportType === 'abstract_district') {
    $reportTitle = "KSPDOWA BENGALURU - DISTRICT WISE ABSTRACT {$selectedYearName} AS ON : {$nowDate} {$nowTime}";
} else {
    $reportTitle = "KSPDOWA BENGALURU - TALUK WISE ABSTRACT {$selectedYearName} AS ON : {$nowDate} {$nowTime}";
}

// ─── Masking helper (spec §3 of the prompt) ─────────────────────────────────
/**
 * Mask exactly 3 random numeric digits with '*'.
 * If the value has fewer than 3 digits, mask all digits.
 * For email: preserve '@' and domain structure; apply masking
 * only to the local part and numeric digits in domain where found.
 */
function maskNumericDigits(string $value): string
{
    if ($value === '' || $value === '-') {
        return $value;
    }
    // Find positions of all numeric digits
    $positions = [];
    $len = strlen($value);
    for ($i = 0; $i < $len; $i++) {
        if (ctype_digit($value[$i])) {
            $positions[] = $i;
        }
    }
    if (empty($positions)) {
        return $value;
    }
    // Pick 3 (or fewer) random positions to mask
    $maskCount = min(3, count($positions));
    // Use array_rand safely
    if (count($positions) <= 3) {
        $chosen = $positions;
    } else {
        $keys = array_rand($positions, $maskCount);
        if (!is_array($keys)) { $keys = [$keys]; }
        $chosen = array_map(fn($k) => $positions[$k], $keys);
    }
    foreach ($chosen as $pos) {
        $value[$pos] = '*';
    }
    return $value;
}

// ─── Data fetch & column definitions ─────────────────────────────────────────
$data    = [];
$columns = [];

if ($reportType === 'detailed') {
    // Exact spec columns §15:
    // Sl.No | Name | Father Name | Gender | Phone | Email | KGID | Taluk | District | Date
    $params      = [];
    $whereClause = ["1=1"];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $whereClause[] = "(m.name LIKE ? OR mp.kgid_no LIKE ? OR mp.personal_mobile LIKE ? OR mp.personal_email LIKE ? OR p.gateway_payment_id LIKE ?)";
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($filterDistrictId) {
        $whereClause[] = "m.district_id = ?";
        $params[] = $filterDistrictId;
    }
    if ($filterTalukId) {
        $whereClause[] = "m.taluk_id = ?";
        $params[] = $filterTalukId;
    }

    // Payment join for the selected FY — p.paid_at is the verified payment date
    $fyJoinId = $fyId ?: 0;
    $payJoin  = "LEFT JOIN membership_payments p ON p.member_id = m.id AND p.membership_year_id = {$fyJoinId} AND p.status = 'completed'";

    if ($paymentStatus === 'paid')   { $whereClause[] = "p.id IS NOT NULL"; }
    elseif ($paymentStatus === 'unpaid') { $whereClause[] = "p.id IS NULL"; }

    $sql = "SELECT m.name,
                   mp.father_spouse_name,
                   mp.gender,
                   mp.personal_mobile,
                   mp.personal_email,
                   mp.kgid_no,
                   t.name  AS taluk_name,
                   d.name  AS district_name,
                   p.paid_at
            FROM members m
            LEFT JOIN member_profiles mp ON mp.member_id = m.id
            LEFT JOIN districts d ON d.id = m.district_id
            LEFT JOIN taluks   t ON t.id  = m.taluk_id
            {$payJoin}
            WHERE " . implode(' AND ', $whereClause) . "
            ORDER BY d.name, t.name, m.name";

    $rawData = Database::fetchAll($sql, $params);

    // Apply masking for export — do NOT modify DB values
    foreach ($rawData as &$row) {
        $row['_masked_kgid']   = maskNumericDigits((string)($row['kgid_no']        ?? ''));
        $row['_masked_phone']  = maskNumericDigits((string)($row['personal_mobile'] ?? ''));
        $row['_masked_email']  = maskNumericDigits((string)($row['personal_email']  ?? ''));
        $row['_payment_date']  = $row['paid_at'] ? date('d-m-Y', strtotime($row['paid_at'])) : '-';
    }
    unset($row);

    $data    = $rawData;
    $columns = ['Sl.No', 'Name', 'Father Name', 'Gender', 'Phone', 'Email', 'KGID', 'Taluk', 'District', 'Date'];

} else {
    // Abstracts — no masking needed
    if ($reportType === 'abstract_district') {
        $whereStr = '';
        if ($lockedDistrictId) { $whereStr .= " AND m.district_id = {$lockedDistrictId}"; }
        elseif ($filterDistrictId) { $whereStr .= " AND m.district_id = {$filterDistrictId}"; }

        $orderExpr = "{$sortBy} {$sqlDir}";

        $sql = "SELECT d.name AS district_name,
                       COUNT(m.id) AS total_members,
                       SUM(CASE WHEN p.id IS NOT NULL THEN 1 ELSE 0 END) AS paid_members,
                       SUM(CASE WHEN p.id IS NULL     THEN 1 ELSE 0 END) AS unpaid_members
                FROM districts d
                JOIN members m ON m.district_id = d.id
                LEFT JOIN membership_payments p ON p.member_id = m.id AND p.membership_year_id = " . ($fyId ?: 0) . " AND p.status = 'completed'
                WHERE 1=1 {$whereStr}
                GROUP BY d.id, d.name
                ORDER BY {$orderExpr}";
        $data    = Database::fetchAll($sql);
        $columns = ['Sl.No', 'District', 'Total Members', 'Paid Members', 'Unpaid Members'];

    } else { // abstract_taluk
        $whereStr = '';
        if ($lockedDistrictId) { $whereStr .= " AND m.district_id = {$lockedDistrictId}"; }
        elseif ($filterDistrictId) { $whereStr .= " AND m.district_id = {$filterDistrictId}"; }
        if ($lockedTalukId)        { $whereStr .= " AND m.taluk_id = {$lockedTalukId}"; }
        elseif ($filterTalukId)    { $whereStr .= " AND m.taluk_id = {$filterTalukId}"; }

        $orderExpr = "{$sortBy} {$sqlDir}";

        $sql = "SELECT d.name AS district_name,
                       t.name AS taluk_name,
                       COUNT(m.id) AS total_members,
                       SUM(CASE WHEN p.id IS NOT NULL THEN 1 ELSE 0 END) AS paid_members,
                       SUM(CASE WHEN p.id IS NULL     THEN 1 ELSE 0 END) AS unpaid_members
                FROM taluks t
                JOIN districts d ON d.id = t.district_id
                JOIN members   m ON m.taluk_id = t.id
                LEFT JOIN membership_payments p ON p.member_id = m.id AND p.membership_year_id = " . ($fyId ?: 0) . " AND p.status = 'completed'
                WHERE 1=1 {$whereStr}
                GROUP BY d.name, t.name
                ORDER BY {$orderExpr}";
        $data    = Database::fetchAll($sql);
        $columns = ['Sl.No', 'District', 'Taluk', 'Total Members', 'Paid Members', 'Unpaid Members'];
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// PDF OUTPUT
// ═══════════════════════════════════════════════════════════════════════════════
if ($format === 'pdf') {

    class MembersReportPDF extends FPDF {
        public string $titleTxt  = '';
        public array  $colWidths = [];
        public array  $colNames  = [];
        public bool   $isDetailed = true;

        function Header(): void {
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(0, 7, $this->titleTxt, 0, 1, 'C');
            $this->Ln(2);
            // Repeat column headers on every page
            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(26, 58, 107);
            $this->SetTextColor(255, 255, 255);
            foreach ($this->colNames as $i => $col) {
                $align = ($i === 0) ? 'C' : 'L';
                // Right-align numeric abstract columns
                if (!$this->isDetailed && $i >= 2) { $align = 'R'; }
                $this->Cell($this->colWidths[$i], 7, $col, 1, 0, $align, true);
            }
            $this->Ln();
            $this->SetFillColor(255, 255, 255);
            $this->SetTextColor(0, 0, 0);
        }

        function Footer(): void {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(80, 80, 80);
            $this->Cell(0, 4, 'Designed & Developed by : KHUBAASING JADAV', 0, 0, 'L');
            $this->Cell(0, 4, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
            $this->SetTextColor(0, 0, 0);
        }
    }

    // Orientation / size per spec
    if ($reportType === 'detailed') {
        // §16: Legal Landscape
        $pdf = new MembersReportPDF('L', 'mm', [355.6, 215.9]); // Legal = 14" x 8.5"
    } else {
        // §21: A4 Portrait
        $pdf = new MembersReportPDF('P', 'mm', 'A4');
    }

    $pdf->titleTxt   = $reportTitle;
    $pdf->isDetailed = ($reportType === 'detailed');
    $pdf->AliasNbPages();
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(8, 8, 8);

    // Column widths (Legal Landscape printable width ≈ 339mm with 8mm margins each side)
    if ($reportType === 'detailed') {
        // Sl | Name | Father | Gender | Phone | Email | KGID | Taluk | District | Date
        $pdf->colWidths = [10, 52, 42, 16, 32, 52, 28, 38, 38, 28]; // total = 336
        $pdf->colNames  = $columns;
    } elseif ($reportType === 'abstract_district') {
        // A4 Portrait printable width ≈ 194mm
        $pdf->colWidths = [15, 90, 30, 30, 29]; // total = 194
        $pdf->colNames  = $columns;
    } else {
        $pdf->colWidths = [12, 55, 50, 26, 26, 25]; // total = 194
        $pdf->colNames  = $columns;
    }

    $pdf->AddPage();

    // ── Data rows ────────────────────────────────────────────────────────────
    $pdf->SetFont('Arial', '', 8);
    $i = 1;
    $grandTotals = [0, 0, 0];

    foreach ($data as $row) {
        // Auto page break: FPDF handles it but we add a check for header repetition
        $pdf->SetFont('Arial', '', 8);

        if ($reportType === 'detailed') {
            // Estimate height needed; use MultiCell for wrapping columns
            // We render with Cell for speed; names are truncated at safe widths
            $pdf->Cell($pdf->colWidths[0], 6, (string)$i, 1, 0, 'C');
            $pdf->Cell($pdf->colWidths[1], 6, mb_strimwidth((string)($row['name'] ?? ''), 0, 30, '..'), 1, 0, 'L');
            $pdf->Cell($pdf->colWidths[2], 6, mb_strimwidth((string)($row['father_spouse_name'] ?? ''), 0, 25, '..'), 1, 0, 'L');
            $pdf->Cell($pdf->colWidths[3], 6, ucfirst(strtolower((string)($row['gender'] ?? ''))), 1, 0, 'C');
            $pdf->Cell($pdf->colWidths[4], 6, $row['_masked_phone'], 1, 0, 'L');
            $pdf->Cell($pdf->colWidths[5], 6, mb_strimwidth($row['_masked_email'], 0, 30, '..'), 1, 0, 'L');
            $pdf->Cell($pdf->colWidths[6], 6, $row['_masked_kgid'], 1, 0, 'L');
            $pdf->Cell($pdf->colWidths[7], 6, mb_strimwidth((string)($row['taluk_name'] ?? ''), 0, 20, '..'), 1, 0, 'L');
            $pdf->Cell($pdf->colWidths[8], 6, mb_strimwidth((string)($row['district_name'] ?? ''), 0, 20, '..'), 1, 0, 'L');
            $pdf->Cell($pdf->colWidths[9], 6, $row['_payment_date'], 1, 0, 'C');
        } elseif ($reportType === 'abstract_district') {
            $pdf->Cell($pdf->colWidths[0], 6, (string)$i, 1, 0, 'C');
            $pdf->Cell($pdf->colWidths[1], 6, (string)($row['district_name'] ?? ''), 1, 0, 'L');
            $pdf->Cell($pdf->colWidths[2], 6, (string)(int)$row['total_members'],   1, 0, 'R');
            $pdf->Cell($pdf->colWidths[3], 6, (string)(int)$row['paid_members'],    1, 0, 'R');
            $pdf->Cell($pdf->colWidths[4], 6, (string)(int)$row['unpaid_members'],  1, 0, 'R');
            $grandTotals[0] += (int)$row['total_members'];
            $grandTotals[1] += (int)$row['paid_members'];
            $grandTotals[2] += (int)$row['unpaid_members'];
        } else {
            $pdf->Cell($pdf->colWidths[0], 6, (string)$i, 1, 0, 'C');
            $pdf->Cell($pdf->colWidths[1], 6, mb_strimwidth((string)($row['district_name'] ?? ''), 0, 25, '..'), 1, 0, 'L');
            $pdf->Cell($pdf->colWidths[2], 6, mb_strimwidth((string)($row['taluk_name']    ?? ''), 0, 25, '..'), 1, 0, 'L');
            $pdf->Cell($pdf->colWidths[3], 6, (string)(int)$row['total_members'],  1, 0, 'R');
            $pdf->Cell($pdf->colWidths[4], 6, (string)(int)$row['paid_members'],   1, 0, 'R');
            $pdf->Cell($pdf->colWidths[5], 6, (string)(int)$row['unpaid_members'], 1, 0, 'R');
            $grandTotals[0] += (int)$row['total_members'];
            $grandTotals[1] += (int)$row['paid_members'];
            $grandTotals[2] += (int)$row['unpaid_members'];
        }

        $pdf->Ln();
        $i++;
    }

    // Grand Total row for abstracts
    if ($reportType !== 'detailed' && !empty($data)) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetFillColor(26, 58, 107);
        $pdf->SetTextColor(255, 255, 255);
        if ($reportType === 'abstract_district') {
            $pdf->Cell($pdf->colWidths[0] + $pdf->colWidths[1], 6, 'GRAND TOTAL', 1, 0, 'R', true);
            $pdf->Cell($pdf->colWidths[2], 6, (string)$grandTotals[0], 1, 0, 'R', true);
            $pdf->Cell($pdf->colWidths[3], 6, (string)$grandTotals[1], 1, 0, 'R', true);
            $pdf->Cell($pdf->colWidths[4], 6, (string)$grandTotals[2], 1, 0, 'R', true);
        } else {
            $pdf->Cell($pdf->colWidths[0] + $pdf->colWidths[1] + $pdf->colWidths[2], 6, 'GRAND TOTAL', 1, 0, 'R', true);
            $pdf->Cell($pdf->colWidths[3], 6, (string)$grandTotals[0], 1, 0, 'R', true);
            $pdf->Cell($pdf->colWidths[4], 6, (string)$grandTotals[1], 1, 0, 'R', true);
            $pdf->Cell($pdf->colWidths[5], 6, (string)$grandTotals[2], 1, 0, 'R', true);
        }
        $pdf->Ln();
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFillColor(255, 255, 255);
    }

    $filename = 'KSPDOWA_' . ($filterDistrictId ? strtoupper(str_replace(' ', '_', $districtName)) : 'ALL') . '_' . date('dmY') . '_' . date('His') . '.pdf';
    $pdf->Output('I', $filename);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// EXCEL OUTPUT (XML Spreadsheet 2003)
// ═══════════════════════════════════════════════════════════════════════════════
if ($format === 'excel') {
    $colCount = count($columns);

    // Shared styles
    $styles = '<?xml version="1.0"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Alignment ss:Vertical="Bottom"/>
   <Borders/>
   <Font ss:FontName="Calibri" ss:Size="11"/>
  </Style>
  <Style ss:ID="TitleStyle">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
   <Font ss:FontName="Calibri" ss:Size="12" ss:Bold="1"/>
  </Style>
  <Style ss:ID="Header">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Font ss:FontName="Calibri" ss:Size="11" ss:Color="#FFFFFF" ss:Bold="1"/>
   <Interior ss:Color="#1a3a6b" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="Data">
   <Alignment ss:Vertical="Top" ss:WrapText="1"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
   </Borders>
   <Font ss:FontName="Calibri" ss:Size="10"/>
  </Style>
  <Style ss:ID="DataNum">
   <Alignment ss:Horizontal="Right" ss:Vertical="Top"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
    <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
   </Borders>
   <Font ss:FontName="Calibri" ss:Size="10"/>
  </Style>
  <Style ss:ID="Footer">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" ss:Size="9" ss:Italic="1" ss:Color="#555555"/>
  </Style>
  <Style ss:ID="GrandTotal">
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#1a3a6b" ss:Pattern="Solid"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="2"/>
   </Borders>
  </Style>
 </Styles>
 <Worksheet ss:Name="Members Report">
  <Table>';

    $xml = $styles;

    // Column width hints (in points; 1 pt ≈ 0.75px)
    if ($reportType === 'detailed') {
        $cwPts = [30, 130, 110, 45, 85, 130, 75, 100, 100, 75];
    } elseif ($reportType === 'abstract_district') {
        $cwPts = [40, 200, 80, 80, 80];
    } else {
        $cwPts = [35, 140, 140, 70, 70, 70];
    }
    foreach ($cwPts as $cw) {
        $xml .= '<Column ss:Width="' . $cw . '"/>';
    }

    // Title row (merged)
    $xml .= '<Row ss:Height="28">'
         . '<Cell ss:MergeAcross="' . ($colCount - 1) . '" ss:StyleID="TitleStyle">'
         . '<Data ss:Type="String">' . htmlspecialchars($reportTitle) . '</Data>'
         . '</Cell></Row>';

    // Blank spacer
    $xml .= '<Row ss:Height="6"></Row>';

    // Header row
    $xml .= '<Row ss:Height="18">';
    foreach ($columns as $col) {
        $xml .= '<Cell ss:StyleID="Header"><Data ss:Type="String">' . htmlspecialchars($col) . '</Data></Cell>';
    }
    $xml .= '</Row>';

    // Data rows
    $i = 1;
    $gt = [0, 0, 0];

    foreach ($data as $row) {
        $xml .= '<Row ss:Height="16">';

        if ($reportType === 'detailed') {
            $xml .= '<Cell ss:StyleID="DataNum"><Data ss:Type="Number">' . $i . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars((string)($row['name'] ?? '')) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars((string)($row['father_spouse_name'] ?? '')) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars(ucfirst(strtolower((string)($row['gender'] ?? '')))) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars($row['_masked_phone']) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars($row['_masked_email']) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars($row['_masked_kgid']) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars((string)($row['taluk_name']    ?? '')) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars((string)($row['district_name'] ?? '')) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars($row['_payment_date']) . '</Data></Cell>';
        } elseif ($reportType === 'abstract_district') {
            $xml .= '<Cell ss:StyleID="DataNum"><Data ss:Type="Number">' . $i . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars((string)$row['district_name']) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataNum"><Data ss:Type="Number">' . (int)$row['total_members']   . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataNum"><Data ss:Type="Number">' . (int)$row['paid_members']    . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataNum"><Data ss:Type="Number">' . (int)$row['unpaid_members']  . '</Data></Cell>';
            $gt[0] += (int)$row['total_members']; $gt[1] += (int)$row['paid_members']; $gt[2] += (int)$row['unpaid_members'];
        } else {
            $xml .= '<Cell ss:StyleID="DataNum"><Data ss:Type="Number">' . $i . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars((string)$row['district_name']) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars((string)$row['taluk_name']) . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataNum"><Data ss:Type="Number">' . (int)$row['total_members']   . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataNum"><Data ss:Type="Number">' . (int)$row['paid_members']    . '</Data></Cell>';
            $xml .= '<Cell ss:StyleID="DataNum"><Data ss:Type="Number">' . (int)$row['unpaid_members']  . '</Data></Cell>';
            $gt[0] += (int)$row['total_members']; $gt[1] += (int)$row['paid_members']; $gt[2] += (int)$row['unpaid_members'];
        }

        $xml .= '</Row>';
        $i++;
    }

    // Grand Total row for abstracts
    if ($reportType !== 'detailed' && !empty($data)) {
        if ($reportType === 'abstract_district') {
            $mergeLabel = 1; // merges col 0+1
        } else {
            $mergeLabel = 2; // merges col 0+1+2
        }
        $xml .= '<Row ss:Height="18">';
        $xml .= '<Cell ss:MergeAcross="' . $mergeLabel . '" ss:StyleID="GrandTotal"><Data ss:Type="String">GRAND TOTAL</Data></Cell>';
        $xml .= '<Cell ss:StyleID="GrandTotal"><Data ss:Type="Number">' . $gt[0] . '</Data></Cell>';
        $xml .= '<Cell ss:StyleID="GrandTotal"><Data ss:Type="Number">' . $gt[1] . '</Data></Cell>';
        $xml .= '<Cell ss:StyleID="GrandTotal"><Data ss:Type="Number">' . $gt[2] . '</Data></Cell>';
        $xml .= '</Row>';
    }

    // Footer row
    $xml .= '<Row ss:Height="16">';
    $xml .= '<Cell ss:MergeAcross="' . ($colCount - 1) . '" ss:StyleID="Footer">';
    $xml .= '<Data ss:Type="String">Designed &amp; Developed by : KHUBAASING JADAV</Data>';
    $xml .= '</Cell></Row>';

    $xml .= '</Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <PageSetup>
    <Layout x:Orientation="' . ($reportType === 'detailed' ? 'Landscape' : 'Portrait') . '"/>
    <PageMargins x:Left="0.5" x:Right="0.5" x:Top="0.75" x:Bottom="0.75"/>
   </PageSetup>
   <Print>
    <FitWidth>1</FitWidth>
    <FitHeight>0</FitHeight>
   </Print>
   <FreezePanes/>
   <FrozenNoSplit/>
   <SplitHorizontal>3</SplitHorizontal>
   <TopRowBottomPane>3</TopRowBottomPane>
   <ActivePane>2</ActivePane>
  </WorksheetOptions>
  <AutoFilter x:Range="R3C1:R3C' . $colCount . '" xmlns="urn:schemas-microsoft-com:office:excel"></AutoFilter>
 </Worksheet>
</Workbook>';

    $filename = 'KSPDOWA_' . ($filterDistrictId ? strtoupper(str_replace(' ', '_', $districtName)) : 'ALL') . '_' . date('dmY') . '_' . date('His') . '.xml';
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    echo $xml;
    exit;
}
