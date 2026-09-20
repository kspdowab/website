<?php
/**
 * KSPDOWA — Simple OpenXML Spreadsheet (.xlsx) Writer
 *
 * Generates valid, native Microsoft Excel (.xlsx) files without external dependencies.
 * Uses PHP's built-in ZipArchive extension.
 */
class SimpleXLSXWriter
{
    /**
     * Generate an .xlsx file in a temporary location and return the file path.
     */
    public static function create(
        string $title,
        array $columns,
        array $rows,
        ?array $totalRow = null,
        array $colWidths = [],
        string $subtitle = ''
    ): string {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException("ZipArchive extension is required for XLSX generation.");
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();
        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create zip file for XLSX export.");
        }

        // Shared strings collector
        $strings = [];
        $stringMap = [];
        $getStringIndex = function(string $val) use (&$strings, &$stringMap): int {
            if (isset($stringMap[$val])) {
                return $stringMap[$val];
            }
            $idx = count($strings);
            $strings[] = $val;
            $stringMap[$val] = $idx;
            return $idx;
        };

        // Styles
        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="5">
    <font><sz val="10"/><name val="Segoe UI"/><color rgb="FF1E293B"/></font>
    <font><b/><sz val="12"/><name val="Segoe UI"/><color rgb="FF1A3A6B"/></font>
    <font><b/><sz val="10"/><name val="Segoe UI"/><color rgb="FFFFFFFF"/></font>
    <font><b/><sz val="10"/><name val="Segoe UI"/><color rgb="FF1A3A6B"/></font>
    <font><i/><sz val="9"/><name val="Segoe UI"/><color rgb="FF64748B"/></font>
  </fonts>
  <fills count="4">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1A3A6B"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill>
  </fills>
  <borders count="3">
    <border><left/><right/><top/><bottom/></border>
    <border>
      <left style="thin"><color rgb="FFE2E8F0"/></left>
      <right style="thin"><color rgb="FFE2E8F0"/></right>
      <top style="thin"><color rgb="FFE2E8F0"/></top>
      <bottom style="thin"><color rgb="FFE2E8F0"/></bottom>
    </border>
    <border>
      <left style="thin"><color rgb="FFCBD5E1"/></left>
      <right style="thin"><color rgb="FFCBD5E1"/></right>
      <top style="thin"><color rgb="FF64748B"/></top>
      <bottom style="double"><color rgb="FF64748B"/></bottom>
    </border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="9">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <!-- 1: Title Style -->
    <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 2: Header Style -->
    <xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center" wrapText="1"/>
    </xf>
    <!-- 3: Data Text (Left) -->
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="left" vertical="center"/>
    </xf>
    <!-- 4: Data Center -->
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
    <!-- 5: Data Number (Right) -->
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="right" vertical="center"/>
    </xf>
    <!-- 6: Total Label (Right, Bold, Filled) -->
    <xf numFmtId="0" fontId="3" fillId="3" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="right" vertical="center"/>
    </xf>
    <!-- 7: Total Number (Right, Bold, Filled) -->
    <xf numFmtId="0" fontId="3" fillId="3" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="right" vertical="center"/>
    </xf>
    <!-- 8: Subtitle Style -->
    <xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center"/>
    </xf>
  </cellXfs>
</styleSheet>';
        $zip->addFromString('xl/styles.xml', $stylesXml);

        $colCount = count($columns);
        $lastColLetter = self::colLetter($colCount);

        $colsXml = '';
        if (!empty($colWidths)) {
            $colsXml .= '<cols>';
            foreach ($colWidths as $idx => $w) {
                $cNum = $idx + 1;
                $colsXml .= '<col min="' . $cNum . '" max="' . $cNum . '" width="' . (float)$w . '" customWidth="1"/>';
            }
            $colsXml .= '</cols>';
        }

        $sheetData = '';
        $r = 1;
        $merges = [];

        // Title row
        if ($title !== '') {
            $sIdx = $getStringIndex($title);
            $sheetData .= '<row r="' . $r . '" ht="28" customHeight="1">';
            $sheetData .= '<c r="A' . $r . '" t="s" s="1"><v>' . $sIdx . '</v></c>';
            $sheetData .= '</row>';
            $merges[] = 'A' . $r . ':' . $lastColLetter . $r;
            $r++;

            if ($subtitle !== '') {
                $subIdx = $getStringIndex($subtitle);
                $sheetData .= '<row r="' . $r . '" ht="18" customHeight="1">';
                $sheetData .= '<c r="A' . $r . '" t="s" s="8"><v>' . $subIdx . '</v></c>';
                $sheetData .= '</row>';
                $merges[] = 'A' . $r . ':' . $lastColLetter . $r;
                $r++;
            }

            // Blank spacer
            $sheetData .= '<row r="' . $r . '" ht="8" customHeight="1"/>';
            $r++;
        }

        // Header row
        $sheetData .= '<row r="' . $r . '" ht="24" customHeight="1">';
        foreach ($columns as $cIdx => $colName) {
            $colLet = self::colLetter($cIdx + 1);
            $sIdx = $getStringIndex((string)$colName);
            $sheetData .= '<c r="' . $colLet . $r . '" t="s" s="2"><v>' . $sIdx . '</v></c>';
        }
        $sheetData .= '</row>';
        $r++;

        // Data rows
        foreach ($rows as $row) {
            $sheetData .= '<row r="' . $r . '" ht="20" customHeight="1">';
            foreach (array_values($row) as $cIdx => $val) {
                $colLet = self::colLetter($cIdx + 1);
                $cleanVal = is_string($val) ? trim($val) : $val;
                // Treat as numeric only if pure number without leading zeroes (unless '0')
                if (is_numeric($cleanVal) && (!str_starts_with((string)$cleanVal, '0') || (string)$cleanVal === '0') && strlen((string)$cleanVal) < 12) {
                    $sheetData .= '<c r="' . $colLet . $r . '" s="5"><v>' . $cleanVal . '</v></c>';
                } else {
                    $valStr = (string)$val;
                    $style = ($cIdx === 0 || $valStr === '-' || preg_match('/^\d{2}-\d{2}-\d{4}$/', $valStr)) ? '4' : '3';
                    $sIdx = $getStringIndex($valStr);
                    $sheetData .= '<c r="' . $colLet . $r . '" t="s" s="' . $style . '"><v>' . $sIdx . '</v></c>';
                }
            }
            $sheetData .= '</row>';
            $r++;
        }

        // Total row (optional)
        if (!empty($totalRow)) {
            $sheetData .= '<row r="' . $r . '" ht="22" customHeight="1">';
            $mergeCols = isset($totalRow['_merge_cols']) ? (int)$totalRow['_merge_cols'] : 0;
            unset($totalRow['_merge_cols']);

            foreach (array_values($totalRow) as $cIdx => $val) {
                $colLet = self::colLetter($cIdx + 1);
                if ($val === null || $val === '') {
                    $sheetData .= '<c r="' . $colLet . $r . '" s="6"/>';
                } elseif (is_numeric($val)) {
                    $sheetData .= '<c r="' . $colLet . $r . '" s="7"><v>' . $val . '</v></c>';
                } else {
                    $sIdx = $getStringIndex((string)$val);
                    $sheetData .= '<c r="' . $colLet . $r . '" t="s" s="6"><v>' . $sIdx . '</v></c>';
                }
            }
            $sheetData .= '</row>';

            if ($mergeCols > 1) {
                $merges[] = 'A' . $r . ':' . self::colLetter($mergeCols) . $r;
            }
            $r++;
        }

        // Footer note row
        $sheetData .= '<row r="' . $r . '" ht="16" customHeight="1">';
        $footIdx = $getStringIndex('Designed & Developed by : KHUBAASING JADAV • Karnataka State Panchayat Development Officers Welfare Association (R.)');
        $sheetData .= '<c r="A' . $r . '" t="s" s="8"><v>' . $footIdx . '</v></c>';
        $sheetData .= '</row>';
        $merges[] = 'A' . $r . ':' . $lastColLetter . $r;

        $mergesXml = '';
        if (!empty($merges)) {
            $mergesXml .= '<mergeCells count="' . count($merges) . '">';
            foreach ($merges as $m) {
                $mergesXml .= '<mergeCell ref="' . $m . '"/>';
            }
            $mergesXml .= '</mergeCells>';
        }

        $worksheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
' . $colsXml . '
<sheetData>
' . $sheetData . '
</sheetData>
' . $mergesXml . '
<pageMargins left="0.5" right="0.5" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>
</worksheet>';

        $zip->addFromString('xl/worksheets/sheet1.xml', $worksheetXml);

        // xl/sharedStrings.xml
        $sstXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
        foreach ($strings as $s) {
            $sstXml .= '<si><t>' . htmlspecialchars($s, ENT_XML1, 'UTF-8') . '</t></si>';
        }
        $sstXml .= '</sst>';
        $zip->addFromString('xl/sharedStrings.xml', $sstXml);

        // [Content_Types].xml
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>');

        // _rels/.rels
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');

        // xl/workbook.xml
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Report" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>');

        // xl/_rels/workbook.xml.rels
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>');

        $zip->close();
        return $tempFile;
    }

    /**
     * Send download headers and stream the .xlsx file directly to output.
     */
    public static function streamDownload(
        string $downloadName,
        string $title,
        array $columns,
        array $rows,
        ?array $totalRow = null,
        array $colWidths = [],
        string $subtitle = ''
    ): void {
        if (!str_ends_with(strtolower($downloadName), '.xlsx')) {
            $downloadName .= '.xlsx';
        }

        // If ZipArchive is missing, fallback to CSV with UTF-8 BOM
        if (!class_exists('ZipArchive')) {
            $csvName = preg_replace('/\.xlsx$/i', '.csv', $downloadName);
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $csvName . '"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            if ($title !== '') { fputcsv($out, [$title]); }
            if ($subtitle !== '') { fputcsv($out, [$subtitle]); }
            fputcsv($out, $columns);
            foreach ($rows as $r) { fputcsv($out, array_values($r)); }
            if (!empty($totalRow)) {
                unset($totalRow['_merge_cols']);
                fputcsv($out, array_values($totalRow));
            }
            fclose($out);
            exit;
        }

        $tempFile = self::create($title, $columns, $rows, $totalRow, $colWidths, $subtitle);

        // Suppress any PHP warnings/notices that would corrupt the binary download
        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($tempFile));
        header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        header('Pragma: public');

        readfile($tempFile);
        @unlink($tempFile);
        exit;
    }

    private static function colLetter(int $colNum): string {
        $letter = '';
        while ($colNum > 0) {
            $mod = ($colNum - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $colNum = (int)(($colNum - $mod) / 26);
        }
        return $letter ?: 'A';
    }
}
