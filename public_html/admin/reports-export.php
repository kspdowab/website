<?php
/**
 * KSPDOWA — Admin: Universal Reports Exporter (Phase 7)
 * ============================================================
 * Export engine for Finance, Grievances, and Activity reports.
 * Formats: PDF (via FPDF) and Excel (XML Spreadsheet).
 *
 * Export Rules (per spec §6):
 *  - Professional KSPDOWA formatting
 *  - Dynamic, accurate title and filter information
 *  - Repeated table headers on every PDF page
 *  - Page numbers for PDF: Page X/{nb}
 *  - Footer: Designed & Developed by : KHUBAASING JADAV
 *  - Strict RBAC scope enforcement (no unauthorized data leak)
 * ============================================================
 */

declare(strict_types=1);

// Suppress notices/warnings from corrupting PDF/Excel binary output
ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/lib/fpdf.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

if (!RBAC::can($currentUserId, 'reports', 'export') && !RBAC::can($currentUserId, 'reports', 'view')) {
    ErrorHandler::abort(403, 'You do not have permission to export reports.');
}

// ─── Scope Locking ────────────────────────────────────────────────────────────
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

// ─── Input Parameters ────────────────────────────────────────────────────────
$reportType = Sanitize::inArray($_GET['report_type'] ?? 'finance', ['finance', 'grievance', 'activity']) ?: 'finance';
$format     = Sanitize::inArray($_GET['format']      ?? 'pdf', ['pdf', 'excel']) ?: 'pdf';
$fyId       = Sanitize::positiveInt($_GET['fy_id']   ?? null);

// Scope overrides
$filterDistrictId = Sanitize::positiveInt($_GET['district_id'] ?? null);
$filterTalukId    = Sanitize::positiveInt($_GET['taluk_id']    ?? null);
if ($lockedDistrictId) { $filterDistrictId = $lockedDistrictId; }
if ($lockedTalukId)    { $filterTalukId    = $lockedTalukId; }

// Date filters
$dateFrom = Sanitize::date($_GET['date_from'] ?? null) ?: null;
$dateTo   = Sanitize::date($_GET['date_to']   ?? null) ?: null;

// FY details
$years = Database::fetchAll("SELECT id, financial_year, status FROM membership_years ORDER BY start_date DESC");
if (!$fyId && !empty($years)) {
    $activeYears = array_filter($years, fn($y) => $y['status'] === 'active');
    $fyId = $activeYears ? (int)reset($activeYears)['id'] : (int)$years[0]['id'];
}
$selectedFy = null;
foreach ($years as $y) { if ((int)$y['id'] === $fyId) { $selectedFy = $y; break; } }
$fyName = $selectedFy['financial_year'] ?? 'Current FY';

// Scope text
$scopeText = 'Karnataka Statewide';
if ($filterDistrictId) {
    $dName = Database::fetchOne("SELECT name FROM districts WHERE id = ?", [$filterDistrictId]);
    $scopeText = ($dName['name'] ?? 'District') . ' District';
}
if ($filterTalukId) {
    $tName = Database::fetchOne("SELECT name FROM taluks WHERE id = ?", [$filterTalukId]);
    $scopeText .= ' - ' . ($tName['name'] ?? 'Taluk') . ' Taluka';
}

$nowStr = date('d-m-Y h:i A');

// ═══════════════════════════════════════════════════════════════════════════════
// DATA GATHERING PER REPORT TYPE
// ═══════════════════════════════════════════════════════════════════════════════

$title        = '';
$filterInfo   = "FY: {$fyName} | Scope: {$scopeText} | Generated: {$nowStr}";
$columns      = [];
$colWidths    = [];
$data         = [];
$hasTotals    = false;
$grandTotals  = [];

if ($reportType === 'finance') {
    $title = "KSPDOWA — MEMBERSHIP FEE & FINANCE COLLECTION REPORT";
    $columns = ['Sl.No', 'District', 'Taluk', 'Total Members', 'Paid Members', 'Unpaid Members', 'Paid %', 'Collections (INR)', 'Pending Dues (INR)'];
    $colWidths = [12, 45, 45, 25, 25, 25, 22, 40, 40]; // Total 279mm for A4 landscape

    $wClauses = ["1=1"];
    $params = [];
    if ($filterDistrictId) {
        $wClauses[] = "m.district_id = ?";
        $params[]   = $filterDistrictId;
    }
    if ($filterTalukId) {
        $wClauses[] = "m.taluk_id = ?";
        $params[]   = $filterTalukId;
    }
    if ($dateFrom) {
        $wClauses[] = "p.paid_at >= ?";
        $params[]   = $dateFrom . ' 00:00:00';
    }
    if ($dateTo) {
        $wClauses[] = "p.paid_at <= ?";
        $params[]   = $dateTo . ' 23:59:59';
    }
    $wSql = implode(' AND ', $wClauses);

    $sql = "SELECT d.name AS district_name, t.name AS taluk_name,
                   COUNT(m.id) AS total_members,
                   SUM(CASE WHEN p.id IS NOT NULL THEN 1 ELSE 0 END) AS paid_members,
                   SUM(CASE WHEN p.id IS NULL     THEN 1 ELSE 0 END) AS unpaid_members,
                   COALESCE(SUM(p.amount), 0) AS total_collected,
                   COALESCE(SUM(CASE WHEN p.id IS NULL THEN (SELECT fee_amount FROM membership_years WHERE id = " . ($fyId ?: 0) . ") ELSE 0 END), 0) AS total_pending
            FROM taluks t
            JOIN districts d ON d.id = t.district_id
            JOIN members   m ON m.taluk_id = t.id
            LEFT JOIN membership_payments p ON p.member_id = m.id AND p.membership_year_id = " . ($fyId ?: 0) . " AND p.status = 'completed'
            WHERE $wSql
            GROUP BY d.name, t.name
            ORDER BY total_collected DESC, d.name ASC, t.name ASC";
    $rows = Database::fetchAll($sql, $params);

    $totM = 0; $totP = 0; $totU = 0; $totAmt = 0.0; $totDue = 0.0;
    $i = 1;
    foreach ($rows as $r) {
        $pct = (int)$r['total_members'] > 0 ? round(((int)$r['paid_members'] / (int)$r['total_members']) * 100, 1) : 0;
        $data[] = [
            $i++,
            $r['district_name'],
            $r['taluk_name'],
            (int)$r['total_members'],
            (int)$r['paid_members'],
            (int)$r['unpaid_members'],
            $pct . '%',
            number_format((float)$r['total_collected'], 2),
            number_format((float)$r['total_pending'], 2)
        ];
        $totM += (int)$r['total_members'];
        $totP += (int)$r['paid_members'];
        $totU += (int)$r['unpaid_members'];
        $totAmt += (float)$r['total_collected'];
        $totDue += (float)$r['total_pending'];
    }
    $hasTotals = true;
    $overallPct = $totM > 0 ? round(($totP / $totM) * 100, 1) : 0;
    $grandTotals = ['GRAND TOTAL', '', '', $totM, $totP, $totU, $overallPct . '%', number_format($totAmt, 2), number_format($totDue, 2)];

} elseif ($reportType === 'grievance') {
    $title = "KSPDOWA — GRIEVANCE RESOLUTION & BACKLOG ANALYTICS REPORT";
    $columns = ['Sl.No', 'Authority / Department', 'Total Registered', 'Under Review', 'Forwarded', 'Resolved / Closed', 'Rejected', 'Resolution Rate'];
    $colWidths = [12, 85, 32, 30, 30, 36, 26, 28]; // Total 279mm

    $wClauses = ["1=1"];
    $params = [];
    if ($filterDistrictId) {
        $wClauses[] = "m.district_id = ?";
        $params[]   = $filterDistrictId;
    }
    if ($filterTalukId) {
        $wClauses[] = "m.taluk_id = ?";
        $params[]   = $filterTalukId;
    }
    if ($dateFrom) {
        $wClauses[] = "g.submitted_at >= ?";
        $params[]   = $dateFrom . ' 00:00:00';
    }
    if ($dateTo) {
        $wClauses[] = "g.submitted_at <= ?";
        $params[]   = $dateTo . ' 23:59:59';
    }
    $wSql = implode(' AND ', $wClauses);

    $sql = "SELECT ga.name AS authority_name, ga.code AS authority_code,
                   COUNT(g.id) AS total_count,
                   SUM(CASE WHEN g.current_status IN ('Submitted', 'Under Verification', 'Under Review') THEN 1 ELSE 0 END) AS review_count,
                   SUM(CASE WHEN g.current_status IN ('Forwarded', 'Escalated', 'Pending') THEN 1 ELSE 0 END) AS forwarded_count,
                   SUM(CASE WHEN g.current_status IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS resolved_count,
                   SUM(CASE WHEN g.current_status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count
            FROM grievance_authorities ga
            LEFT JOIN grievances g ON g.current_authority = ga.id
            LEFT JOIN members m    ON m.id = g.member_id AND $wSql
            WHERE ga.status = 'active'
            GROUP BY ga.id, ga.name, ga.code
            ORDER BY total_count DESC, ga.name ASC";
    $rows = Database::fetchAll($sql, $params);

    $totAll = 0; $totRev = 0; $totFwd = 0; $totRes = 0; $totRej = 0;
    $i = 1;
    foreach ($rows as $r) {
        $rate = (int)$r['total_count'] > 0 ? round(((int)$r['resolved_count'] / (int)$r['total_count']) * 100, 1) : 0;
        $data[] = [
            $i++,
            $r['authority_name'] . ' (' . $r['authority_code'] . ')',
            (int)$r['total_count'],
            (int)$r['review_count'],
            (int)$r['forwarded_count'],
            (int)$r['resolved_count'],
            (int)$r['rejected_count'],
            $rate . '%'
        ];
        $totAll += (int)$r['total_count'];
        $totRev += (int)$r['review_count'];
        $totFwd += (int)$r['forwarded_count'];
        $totRes += (int)$r['resolved_count'];
        $totRej += (int)$r['rejected_count'];
    }
    $hasTotals = true;
    $totRate = $totAll > 0 ? round(($totRes / $totAll) * 100, 1) : 0;
    $grandTotals = ['GRAND TOTAL', '', $totAll, $totRev, $totFwd, $totRes, $totRej, $totRate . '%'];

} elseif ($reportType === 'activity') {
    $title = "KSPDOWA — ASSOCIATION ACTIVITIES & MEETINGS SUMMARY REPORT";
    $columns = ['Sl.No', 'Activity / Meeting Title', 'Level / Type', 'Date', 'Venue / Location', 'Status', 'Resolutions Passed'];
    $colWidths = [12, 80, 45, 30, 52, 30, 30]; // Total 279mm

    // Gather activities and meetings
    $meetings = Database::fetchAll(
        "SELECT m.title, m.meeting_type AS event_type, m.unit_type, m.meeting_date AS event_date, m.venue, m.status,
                (SELECT COUNT(*) FROM resolutions r WHERE r.meeting_id = m.id) AS res_count
         FROM meetings m
         ORDER BY m.meeting_date DESC
         LIMIT 100"
    );

    $activities = Database::fetchAll(
        "SELECT a.title, 'Association Activity' AS event_type, 'state' AS unit_type, a.activity_date AS event_date, a.location AS venue, a.status,
                0 AS res_count
         FROM activities a
         ORDER BY a.activity_date DESC
         LIMIT 100"
    );

    $combined = array_merge($meetings, $activities);
    usort($combined, fn($a, $b) => strcmp((string)$b['event_date'], (string)$a['event_date']));

    $i = 1;
    $totalRes = 0;
    foreach ($combined as $c) {
        $resCnt = (int)($c['res_count'] ?? 0);
        $totalRes += $resCnt;
        $data[] = [
            $i++,
            $c['title'],
            ucwords(str_replace('_', ' ', (string)($c['event_type'] ?? 'General'))),
            date('d-m-Y', strtotime((string)$c['event_date'])),
            $c['venue'] ?: 'State HQ / Digital',
            ucfirst((string)($c['status'] ?? 'Completed')),
            $resCnt > 0 ? $resCnt : '-'
        ];
    }
    $hasTotals = true;
    $grandTotals = ['TOTAL RECORDS', '', count($combined) . ' Events/Meetings', '', '', '', $totalRes . ' Resolutions'];
}

// ═══════════════════════════════════════════════════════════════════════════════
// PDF EXPORT (A4 Landscape)
// ═══════════════════════════════════════════════════════════════════════════════
if ($format === 'pdf') {

    class UniversalReportPDF extends FPDF {
        public string $reportTitle  = '';
        public string $filterMeta   = '';
        public array  $colWidths    = [];
        public array  $colHeaders   = [];

        function Header(): void {
            // Association main header
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(26, 58, 107);
            $this->Cell(0, 6, 'KARNATAKA STATE PANCHAYAT DEVELOPMENT OFFICERS WELFARE ASSOCIATION (R.)', 0, 1, 'C');
            
            // Subtitle
            $this->SetFont('Arial', 'B', 9);
            $this->SetTextColor(30, 41, 59);
            $this->Cell(0, 5, $this->reportTitle, 0, 1, 'C');

            // Metadata banner
            $this->SetFont('Arial', '', 7.5);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, $this->filterMeta, 0, 1, 'C');
            $this->Ln(2);

            // Repeated table headers
            $this->SetFont('Arial', 'B', 7.5);
            $this->SetFillColor(26, 58, 107);
            $this->SetTextColor(255, 255, 255);
            foreach ($this->colHeaders as $i => $col) {
                $w = $this->colWidths[$i] ?? 30;
                $align = ($i === 0) ? 'C' : (($i >= 3 && $i <= 8) ? 'R' : 'L');
                $this->Cell($w, 7, $col, 1, 0, $align, true);
            }
            $this->Ln();
            $this->SetTextColor(0, 0, 0);
        }

        function Footer(): void {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7.5);
            $this->SetTextColor(71, 85, 105);
            $this->Cell(0, 5, 'Designed & Developed by : KHUBAASING JADAV', 0, 0, 'L');
            $this->Cell(0, 5, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
        }
    }

    $pdf = new UniversalReportPDF('L', 'mm', 'A4'); // 297mm x 210mm
    $pdf->reportTitle = $title;
    $pdf->filterMeta  = $filterInfo;
    $pdf->colWidths   = $colWidths;
    $pdf->colHeaders  = $columns;
    $pdf->AliasNbPages();
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(9, 8, 9);
    $pdf->AddPage();

    $pdf->SetFont('Arial', '', 7.5);
    $fill = false;

    foreach ($data as $row) {
        $pdf->SetFillColor(248, 250, 252);
        foreach ($row as $idx => $val) {
            $w = $colWidths[$idx] ?? 30;
            $align = ($idx === 0) ? 'C' : (($idx >= 3 && $idx <= 8) ? 'R' : 'L');
            $pdf->Cell($w, 6, (string)$val, 1, 0, $align, $fill);
        }
        $pdf->Ln();
        $fill = !$fill;
    }

    // Grand Totals row
    if ($hasTotals && !empty($grandTotals)) {
        $pdf->SetFont('Arial', 'B', 7.5);
        $pdf->SetFillColor(226, 232, 240);
        foreach ($grandTotals as $idx => $val) {
            $w = $colWidths[$idx] ?? 30;
            $align = ($idx === 0) ? 'C' : (($idx >= 3 && $idx <= 8) ? 'R' : 'L');
            $pdf->Cell($w, 7, (string)$val, 1, 0, $align, true);
        }
        $pdf->Ln();
    }

    $filename = "KSPDOWA_{$reportType}_report_" . date('Ymd_His') . ".pdf";
    $pdf->Output('I', $filename);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// EXCEL EXPORT (XML Spreadsheet)
// ═══════════════════════════════════════════════════════════════════════════════
if ($format === 'excel') {
    $filename = "KSPDOWA_{$reportType}_report_" . date('Ymd_His') . ".xls";

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $colCount = count($columns);

    echo '<?xml version="1.0"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    ?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Alignment ss:Vertical="Center"/>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#000000"/>
  </Style>
  <Style ss:ID="Title">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Segoe UI" ss:Size="12" ss:Bold="1" ss:Color="#1A3A6B"/>
  </Style>
  <Style ss:ID="Subtitle">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Segoe UI" ss:Size="9" ss:Italic="1" ss:Color="#475569"/>
  </Style>
  <Style ss:ID="Header">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#1A3A6B" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="Data">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="9"/>
  </Style>
  <Style ss:ID="DataNum">
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="9"/>
  </Style>
  <Style ss:ID="Total">
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#64748B"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#64748B"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Bold="1" ss:Color="#1A3A6B"/>
   <Interior ss:Color="#F1F5F9" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="Footer">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Segoe UI" ss:Size="8" ss:Italic="1" ss:Color="#64748B"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="Report">
  <Table>
   <?php foreach ($columns as $c): ?>
   <Column ss:AutoFitWidth="1" ss:Width="110"/>
   <?php endforeach; ?>

   <Row ss:Height="24">
    <Cell ss:MergeAcross="<?= $colCount - 1 ?>" ss:StyleID="Title">
     <Data ss:Type="String"><?= htmlspecialchars($title) ?></Data>
    </Cell>
   </Row>
   <Row ss:Height="18">
    <Cell ss:MergeAcross="<?= $colCount - 1 ?>" ss:StyleID="Subtitle">
     <Data ss:Type="String"><?= htmlspecialchars($filterInfo) ?></Data>
    </Cell>
   </Row>
   <Row ss:Height="8"></Row>

   <!-- Column Headers -->
   <Row ss:Height="22">
    <?php foreach ($columns as $c): ?>
    <Cell ss:StyleID="Header"><Data ss:Type="String"><?= htmlspecialchars($c) ?></Data></Cell>
    <?php endforeach; ?>
   </Row>

   <!-- Rows -->
   <?php foreach ($data as $row): ?>
   <Row ss:Height="18">
    <?php foreach ($row as $idx => $val): 
        $isNum = ($idx === 0 || ($idx >= 3 && $idx <= 8));
        $style = $isNum ? 'DataNum' : 'Data';
    ?>
    <Cell ss:StyleID="<?= $style ?>"><Data ss:Type="<?= is_numeric(str_replace(',', '', (string)$val)) ? 'Number' : 'String' ?>"><?= htmlspecialchars((string)$val) ?></Data></Cell>
    <?php endforeach; ?>
   </Row>
   <?php endforeach; ?>

   <!-- Grand Totals -->
   <?php if ($hasTotals && !empty($grandTotals)): ?>
   <Row ss:Height="22">
    <?php foreach ($grandTotals as $idx => $val): ?>
    <Cell ss:StyleID="Total"><Data ss:Type="<?= is_numeric(str_replace(',', '', (string)$val)) ? 'Number' : 'String' ?>"><?= htmlspecialchars((string)$val) ?></Data></Cell>
    <?php endforeach; ?>
   </Row>
   <?php endif; ?>

   <Row ss:Height="14"></Row>
   <Row ss:Height="18">
    <Cell ss:MergeAcross="<?= $colCount - 1 ?>" ss:StyleID="Footer">
     <Data ss:Type="String">Designed &amp; Developed by : KHUBAASING JADAV • Karnataka State Panchayat Development Officers Welfare Association (R.)</Data>
    </Cell>
   </Row>
  </Table>
 </Worksheet>
</Workbook>
    <?php
    exit;
}
