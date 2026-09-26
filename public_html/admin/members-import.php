<?php
/**
 * KSPDOWA — Admin: Bulk Import Members
 * ============================================================
 * Gated by RBAC 'members.manage'.
 * Spec: docs/11_MEMBERS_MODULE_SPECIFICATION.md §13
 *
 * Fields: EXACTLY same as member registration form (19 columns).
 * Supports: CSV (.csv) and Excel (.xls / .xlsx via basic xml parse).
 * Duplicate rule: KGID + Financial Year ONLY.
 * Membership Number: auto-generated on activation (never from CSV).
 * Workflow: Upload → [Remap Locations if Unmatched] → Preview & Confirm → Import
 * ============================================================
 */
declare(strict_types=1);

// Suppress PHP notices/warnings from corrupting HTML/headers
ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/MembershipNumber.php';

if (php_sapi_name() !== 'cli') {
    Auth::requireLogin();
    $currentUserId = Auth::getCurrentUserId();
    RBAC::requirePermission($currentUserId, 'members', 'manage');
    $highestScope = RBAC::getHighestScopeType($currentUserId);
    if ($highestScope !== 'state') {
        AuditLogger::log('ACCESS_DENIED', 'members', null, null, [
            'action'  => 'import',
            'user_id' => $currentUserId,
            'reason'  => 'Bulk import restricted to State Administrators only',
        ]);
        ErrorHandler::abort(403, 'Bulk import of members is restricted to State Administrators only.');
    }
} else {
    $currentUserId = 1;
}

$successMsg = Session::getFlash('success');
$errorMsg   = Session::getFlash('error');

// Geographic scope for logged-in user
$associationUnitId = RBAC::getUserAssociationUnit($currentUserId);
$lockedDistrictId  = null;
$lockedTalukId     = null;
if ($associationUnitId) {
    $unit = Database::fetchOne("SELECT * FROM association_units WHERE id = ?", [$associationUnitId]);
    if ($unit) {
        if ($unit['unit_type'] === 'district') { $lockedDistrictId = (int)$unit['district_id']; }
        elseif ($unit['unit_type'] === 'taluk') { $lockedDistrictId = (int)$unit['district_id']; $lockedTalukId = (int)$unit['taluk_id']; }
    }
}

// Active and available financial years
$years      = Database::fetchAll("SELECT id, financial_year, status FROM membership_years ORDER BY start_date DESC");
$activeYear = Database::fetchOne("SELECT * FROM membership_years WHERE status='active' ORDER BY start_date DESC LIMIT 1");
$fyId       = $activeYear ? (int)$activeYear['id'] : 0;

// Selectable FY for import
$importFyId = Sanitize::positiveInt($_GET['fy_id'] ?? null) ?: $fyId;
$importYear = null;
foreach ($years as $y) { if ((int)$y['id'] === $importFyId) { $importYear = $y; break; } }

// ─── Geography lookups — normalized name matching ────────────────────────────
// Normalization: lowercase, collapse spaces, remove dots/hyphens
function normGeoName(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[\-\.]+/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

$districts = Database::fetchAll("SELECT id, name FROM districts WHERE status='active' ORDER BY name");
$taluks    = Database::fetchAll("SELECT id, name, district_id FROM taluks WHERE status='active' ORDER BY name");
$gps       = Database::fetchAll("SELECT id, name, taluk_id FROM gram_panchayatis WHERE status='active' ORDER BY name");

// dMap: norm(name) → district_id
// dNames: id → canonical name
$dMap   = [];
$dNames = [];
foreach ($districts as $d) {
    $key = normGeoName($d['name']);
    $dMap[$key] = (int)$d['id'];
    $dNames[(int)$d['id']] = $d['name'];
}

// tMap: "district_id:norm(taluk_name)" → taluk_id
// tById: id → taluk row
// tByDistrict: district_id → [taluk rows]
$tMap        = [];
$tById       = [];
$tByDistrict = [];
foreach ($taluks as $t) {
    $key = (int)$t['district_id'] . ':' . normGeoName($t['name']);
    $tMap[$key] = (int)$t['id'];
    $tById[(int)$t['id']] = $t;
    $tByDistrict[(int)$t['district_id']][] = $t;
}

// gpMap: "taluk_id:norm(gp_name)" → gp_id
// gpsById: id → gp row
// gpByTaluk: taluk_id → [gp rows]
$gpMap     = [];
$gpsById   = [];
$gpByTaluk = [];
foreach ($gps as $g) {
    $key = (int)$g['taluk_id'] . ':' . normGeoName($g['name']);
    $gpMap[$key] = (int)$g['id'];
    $gpsById[(int)$g['id']] = $g;
    $gpByTaluk[(int)$g['taluk_id']][] = $g;
}

// ─── Auto-Suggestion Helpers for Remap Dropdowns ─────────────────────────────
function suggestDistrict(string $raw, array $districts): ?int {
    $norm = normGeoName($raw);
    if ($norm === '') return null;
    foreach ($districts as $d) {
        if (normGeoName($d['name']) === $norm) return (int)$d['id'];
    }
    foreach ($districts as $d) {
        $dNorm = normGeoName($d['name']);
        if (str_contains($norm, $dNorm) || str_contains($dNorm, $norm)) {
            return (int)$d['id'];
        }
    }
    $bestId = null; $bestScore = 75;
    foreach ($districts as $d) {
        similar_text($norm, normGeoName($d['name']), $pct);
        if ($pct > $bestScore) { $bestScore = $pct; $bestId = (int)$d['id']; }
    }
    return $bestId;
}

function suggestTaluk(string $rawTaluk, ?int $districtId, array $taluks, array $tByDistrict): ?int {
    $norm = normGeoName($rawTaluk);
    if ($norm === '') return null;
    $candidates = ($districtId && !empty($tByDistrict[$districtId])) ? $tByDistrict[$districtId] : $taluks;
    foreach ($candidates as $t) {
        if (normGeoName($t['name']) === $norm) return (int)$t['id'];
    }
    foreach ($candidates as $t) {
        $tNorm = normGeoName($t['name']);
        if (str_contains($norm, $tNorm) || str_contains($tNorm, $norm)) {
            return (int)$t['id'];
        }
    }
    $bestId = null; $bestScore = 70;
    foreach ($candidates as $t) {
        similar_text($norm, normGeoName($t['name']), $pct);
        if ($pct > $bestScore) { $bestScore = $pct; $bestId = (int)$t['id']; }
    }
    return $bestId;
}

function suggestGp(string $rawGp, ?int $talukId, array $gpByTaluk): ?int {
    if (!$talukId || empty($gpByTaluk[$talukId])) return null;
    $norm = normGeoName($rawGp);
    if ($norm === '') return null;
    foreach ($gpByTaluk[$talukId] as $g) {
        if (normGeoName($g['name']) === $norm) return (int)$g['id'];
    }
    foreach ($gpByTaluk[$talukId] as $g) {
        $gNorm = normGeoName($g['name']);
        if (str_contains($norm, $gNorm) || str_contains($gNorm, $norm)) {
            return (int)$g['id'];
        }
    }
    $bestId = null; $bestScore = 70;
    foreach ($gpByTaluk[$talukId] as $g) {
        similar_text($norm, normGeoName($g['name']), $pct);
        if ($pct > $bestScore) { $bestScore = $pct; $bestId = (int)$g['id']; }
    }
    return $bestId;
}

// ─── CSV / Excel Parsers ─────────────────────────────────────────────────────
function parseCSVFile(string $filePath): array {
    $rows = [];
    $header = [];
    if (($h = fopen($filePath, 'r')) !== false) {
        $headerRaw = fgetcsv($h, 0, ',', '"', '\\');
        if ($headerRaw) {
            if (isset($headerRaw[0])) {
                $headerRaw[0] = ltrim($headerRaw[0], "\xEF\xBB\xBF");
            }
            $header = $headerRaw;
        }
        while (($data = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
            if (count(array_filter($data, fn($v) => trim((string)$v) !== '')) === 0) { continue; }
            $rows[] = $data;
        }
        fclose($h);
    }
    return ['header' => $header, 'rows' => $rows];
}

function colLetterToIndex(string $cellRef): int {
    preg_match('/^([A-Z]+)/', strtoupper($cellRef), $m);
    if (empty($m[1])) return -1;
    $letters = $m[1];
    $idx = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $idx = $idx * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $idx - 1;
}

function extractZipEntryUniversal(string $zipPath, string $targetEntry): ?string {
    // 1. Try PHP ZipArchive if available
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) === true) {
            $content = $zip->getFromName($targetEntry);
            $zip->close();
            if ($content !== false) {
                return $content;
            }
        }
    }

    // 2. Pure-PHP ZIP reader using core gzinflate (no ZipArchive extension required)
    $fh = @fopen($zipPath, 'rb');
    if ($fh) {
        while (!feof($fh)) {
            $sig = fread($fh, 4);
            if ($sig !== "PK\x03\x04") {
                break;
            }
            $header = fread($fh, 26);
            if (strlen($header) < 26) break;
            $fields = unpack('vversion/vflags/vmethod/vmtime/vmdate/Vcrc/VcompSize/VuncompSize/vfnLen/vextraLen', $header);
            $filename = fread($fh, $fields['fnLen']);
            if ($fields['extraLen'] > 0) { fread($fh, $fields['extraLen']); }

            $compSize = $fields['compSize'];
            $data = ($compSize > 0) ? fread($fh, $compSize) : '';

            $normFilename = str_replace('\\', '/', $filename);
            $normTarget   = str_replace('\\', '/', $targetEntry);

            if (strtolower($normFilename) === strtolower($normTarget)) {
                fclose($fh);
                if ($fields['method'] == 8) {
                    $uncompressed = @gzinflate($data);
                    return ($uncompressed !== false) ? $uncompressed : null;
                } elseif ($fields['method'] == 0) {
                    return $data;
                }
            }
        }
        fclose($fh);
    }

    // 3. Fallback to Windows built-in tar.exe
    $tempDir = sys_get_temp_dir() . '/xlsx_' . bin2hex(random_bytes(6));
    @mkdir($tempDir, 0777, true);
    $tarPath = 'C:\\Windows\\System32\\tar.exe';
    if (file_exists($tarPath)) {
        $cmd = escapeshellarg($tarPath) . ' -xf ' . escapeshellarg($zipPath) . ' -C ' . escapeshellarg($tempDir) . ' ' . escapeshellarg($targetEntry);
        exec($cmd, $out, $ret);
        $extractedFile = $tempDir . '/' . $targetEntry;
        if ($ret === 0 && file_exists($extractedFile)) {
            $content = file_get_contents($extractedFile);
            @unlink($extractedFile);
            @rmdir($tempDir);
            return $content;
        }
    }

    return null;
}

function parseExcelFile(string $filePath): array {
    $rows = [];
    $header = [];

    // Check if it's an XML Spreadsheet or HTML table saved as .xls
    $prefix = @file_get_contents($filePath, false, null, 0, 1024);
    if ($prefix && (str_contains($prefix, '<?xml') || str_contains($prefix, '<html') || str_contains($prefix, '<table'))) {
        $content = file_get_contents($filePath);
        if (str_contains($content, '<Workbook') || str_contains($content, '<workbook')) {
            $xml = @simplexml_load_string($content);
            if ($xml) {
                $headerSkipped = false;
                foreach ($xml->xpath('//Row|//ss:Row') as $row) {
                    $cells = [];
                    foreach ($row->xpath('Cell|ss:Cell') as $cell) {
                        $data = $cell->xpath('Data|ss:Data');
                        $cells[] = isset($data[0]) ? (string)$data[0] : '';
                    }
                    if (!$headerSkipped) {
                        $headerSkipped = true;
                        $header = $cells;
                        continue;
                    }
                    if (count(array_filter($cells, fn($v) => trim((string)$v) !== '')) > 0) {
                        $rows[] = $cells;
                    }
                }
                return ['header' => $header, 'rows' => $rows];
            }
        }
        if (str_contains($content, '<table') || str_contains($content, '<Table')) {
            preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $content, $trMatches);
            $headerSkipped = false;
            foreach ($trMatches[1] as $tr) {
                preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $tr, $tdMatches);
                $cells = array_map('strip_tags', $tdMatches[1]);
                $cells = array_map('html_entity_decode', $cells);
                if (!$headerSkipped) {
                    $headerSkipped = true;
                    $header = $cells;
                    continue;
                }
                if (count(array_filter($cells, fn($v) => trim((string)$v) !== '')) > 0) {
                    $rows[] = $cells;
                }
            }
            return ['header' => $header, 'rows' => $rows];
        }
    }

    // Standard OpenXML (.xlsx)
    $sharedStringsXml = extractZipEntryUniversal($filePath, 'xl/sharedStrings.xml');
    $sharedStrings = [];
    if ($sharedStringsXml) {
        $ss = @simplexml_load_string($sharedStringsXml);
        if ($ss) {
            foreach ($ss->si as $si) {
                $text = '';
                foreach ($si->r as $r) { $text .= (string)$r->t; }
                if ($text === '' && isset($si->t)) { $text = (string)$si->t; }
                $sharedStrings[] = $text;
            }
        }
    }

    $sheetXml = extractZipEntryUniversal($filePath, 'xl/worksheets/sheet1.xml');
    if (!$sheetXml) {
        $sheetXml = extractZipEntryUniversal($filePath, 'worksheets/sheet1.xml');
    }
    if (!$sheetXml) {
        return ['header' => [], 'rows' => $rows];
    }

    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet || !isset($sheet->sheetData)) {
        return ['header' => [], 'rows' => $rows];
    }

    $headerSkipped = false;
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        $colCounter = 0;
        foreach ($row->c as $c) {
            $r = (string)($c['r'] ?? '');
            $colIdx = ($r !== '') ? colLetterToIndex($r) : $colCounter;
            if ($colIdx < 0) { $colIdx = $colCounter; }

            $t = (string)($c['t'] ?? '');
            $val = '';

            if ($t === 's') {
                $v = (int)(string)$c->v;
                $val = $sharedStrings[$v] ?? '';
            } elseif ($t === 'inlineStr' && isset($c->is->t)) {
                $val = (string)$c->is->t;
            } elseif (isset($c->v)) {
                $val = (string)$c->v;
            }

            $cells[$colIdx] = $val;
            $colCounter = $colIdx + 1;
        }

        $maxCol = max(array_keys($cells) ?: [0]);
        $normRow = [];
        for ($i = 0; $i <= max(21, $maxCol); $i++) {
            $normRow[$i] = $cells[$i] ?? '';
        }
        ksort($normRow);

        if (!$headerSkipped) {
            $headerSkipped = true;
            $header = array_values($normRow);
            continue;
        }

        if (count(array_filter($normRow, fn($v) => trim((string)$v) !== '')) > 0) {
            $rows[] = array_values($normRow);
        }
    }

    return ['header' => $header, 'rows' => $rows];
}

// ─── 21 Expected Columns (0-indexed) ─────────────────────────────────────────
// 0  Full Name
// 1  Father / Husband Name
// 2  Gender (optional: male/female/other/blank)
// 3  Phone (10-digit)
// 4  Email
// 5  KGID No.
// 6  Date of Birth (YYYY-MM-DD or DD-MM-YYYY)
// 7  GP Working? (yes/no)
// 8  Organization Type (when GP Working = no)
// 9  Organization Name (when GP Working = no)
// 10 Organization Address (optional, when GP Working = no)
// 11 Working District (name — required when GP Working = yes or locked org type)
// 12 Working Taluk (name — required when GP Working = yes or locked org type)
// 13 Working GP (name — optional when GP Working = yes, member can update later)
// 14 Membership District (name — required when GP Working = no + non-locked org type)
// 15 Membership Taluk (name — required when GP Working = no + non-locked org type)
// 16 Payment Mode (optional: razorpay / online / offline / blank)
// 17 Payment Reference / ID (optional: Razorpay Payment ID pay_... or offline reference)
// 18 Payment Date & Time (optional: DD-MM-YYYY HH:MM:SS or YYYY-MM-DD)
// 19 Received Amount (optional: fee amount in INR, e.g. 500)
// 20 Payment Remarks (optional)

/**
 * Build a map of canonical field key => column index based on header names.
 */
function buildColumnIndexMap(array $header): array {
    $map = [];
    foreach ($header as $idx => $rawHeader) {
        $norm = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$rawHeader));
        if ($norm === '') continue;

        if (in_array($norm, ['fullname', 'name', 'membername', 'nameofmember'])) {
            $map['full_name'] = $idx;
        } elseif (in_array($norm, ['fatherhusbandname', 'fathername', 'husbandname', 'fatherspousename', 'spouse', 'guardianname'])) {
            $map['father_name'] = $idx;
        } elseif (in_array($norm, ['gender', 'sex'])) {
            $map['gender'] = $idx;
        } elseif (in_array($norm, ['phone', 'phoneno', 'phonenumber', 'mobile', 'mobileno', 'mobilenumber', 'contact', 'contactno'])) {
            $map['phone'] = $idx;
        } elseif (in_array($norm, ['email', 'emailid', 'emailaddress', 'mail'])) {
            $map['email'] = $idx;
        } elseif (in_array($norm, ['kgid', 'kgidno', 'kgidnumber'])) {
            $map['kgid'] = $idx;
        } elseif (in_array($norm, ['dateofbirth', 'dob', 'birthdate'])) {
            $map['dob'] = $idx;
        } elseif (in_array($norm, ['gpworking', 'gpworking?', 'isgpworking', 'workingingp'])) {
            $map['gp_working'] = $idx;
        } elseif (in_array($norm, ['organizationtype', 'orgtype'])) {
            $map['org_type'] = $idx;
        } elseif (in_array($norm, ['organizationname', 'orgname', 'office'])) {
            $map['org_name'] = $idx;
        } elseif (in_array($norm, ['organizationaddress', 'orgaddress', 'officeaddress'])) {
            $map['org_address'] = $idx;
        } elseif (in_array($norm, ['workingdistrict', 'workdistrict', 'wdistrict', 'districtworked'])) {
            $map['working_district'] = $idx;
        } elseif (in_array($norm, ['workingtaluk', 'worktaluk', 'wtaluk', 'talukworked'])) {
            $map['working_taluk'] = $idx;
        } elseif (in_array($norm, ['workinggp', 'workgp', 'wgp', 'grampanchayat', 'gpname', 'panchayat'])) {
            $map['working_gp'] = $idx;
        } elseif (in_array($norm, ['membershipdistrict', 'memdistrict', 'mdistrict', 'homedistrict'])) {
            $map['membership_district'] = $idx;
        } elseif (in_array($norm, ['membershiptaluk', 'memtaluk', 'mtaluk', 'hometaluk'])) {
            $map['membership_taluk'] = $idx;
        } elseif (in_array($norm, ['paymentmode', 'paymode', 'mode'])) {
            $map['payment_mode'] = $idx;
        } elseif (in_array($norm, ['paymentreference', 'paymentid', 'payid', 'razorpaypaymentid', 'reference', 'referenceno', 'transactionid', 'offlinereference', 'utr', 'utrno'])) {
            $map['payment_ref'] = $idx;
        } elseif (in_array($norm, ['paymentdate', 'paymentdatetime', 'paymentdateandtime', 'paydate', 'paidat', 'dateofpayment', 'paiddate', 'datetime'])) {
            $map['payment_date'] = $idx;
        } elseif (in_array($norm, ['receivedamount', 'receiveamount', 'amount', 'amountreceived', 'paidamount', 'fee', 'feeamount', 'fees', 'amountpaid', 'paymentamount'])) {
            $map['received_amount'] = $idx;
        } elseif (in_array($norm, ['paymentremarks', 'remarks', 'remark', 'notes', 'offlineremarks', 'comments'])) {
            $map['remarks'] = $idx;
        }
    }
    return $map;
}

/**
 * Parse Date of Birth from various formats into standard SQL YYYY-MM-DD.
 */
function parseDob(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;

    // 1. Excel serial date number (e.g. 31213 or 31213.0)
    if (is_numeric($raw) && (float)$raw > 1000 && (float)$raw < 100000) {
        $timestamp = ((float)$raw - 25569) * 86400;
        $d = gmdate('Y-m-d', (int)$timestamp);
        if ($d && $d !== '1970-01-01') return $d;
    }

    // 2. DD-MM-YYYY or DD/MM/YYYY or DD.MM.YYYY
    if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})$/', $raw, $m)) {
        $day   = (int)$m[1];
        $month = (int)$m[2];
        $year  = (int)$m[3];
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    // 3. YYYY-MM-DD or YYYY/MM/DD
    if (preg_match('/^(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})$/', $raw, $m)) {
        $year  = (int)$m[1];
        $month = (int)$m[2];
        $day   = (int)$m[3];
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    // 4. DateTime fallback
    try {
        $dt = new DateTime($raw);
        if ($dt) {
            return $dt->format('Y-m-d');
        }
    } catch (Exception $e) {}

    return null;
}

/**
 * Parse Payment Date & Time from various formats into standard SQL YYYY-MM-DD HH:MM:SS.
 */
function parsePaymentDateTime(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;

    // 1. Excel serial date/time (e.g. 45458 or 45458.6056)
    if (is_numeric($raw) && (float)$raw > 1000 && (float)$raw < 100000) {
        $timestamp = ((float)$raw - 25569) * 86400;
        $d = gmdate('Y-m-d H:i:s', (int)$timestamp);
        if ($d && !str_starts_with($d, '1970')) return $d;
    }

    // 2. DD-MM-YYYY or DD/MM/YYYY with optional time
    if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})(?:\s+(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?(?:\s*(am|pm))?)?$/i', $raw, $m)) {
        $day   = (int)$m[1];
        $month = (int)$m[2];
        $year  = (int)$m[3];
        if (checkdate($month, $day, $year)) {
            $hour = isset($m[4]) ? (int)$m[4] : 0;
            $min  = isset($m[5]) ? (int)$m[5] : 0;
            $sec  = isset($m[6]) ? (int)$m[6] : 0;
            $ampm = isset($m[7]) ? strtolower($m[7]) : '';
            if ($ampm === 'pm' && $hour < 12) { $hour += 12; }
            if ($ampm === 'am' && $hour === 12) { $hour = 0; }
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $min, $sec);
        }
    }

    // 3. YYYY-MM-DD with optional time
    if (preg_match('/^(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})(?:\s+(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?(?:\s*(am|pm))?)?$/i', $raw, $m)) {
        $year  = (int)$m[1];
        $month = (int)$m[2];
        $day   = (int)$m[3];
        if (checkdate($month, $day, $year)) {
            $hour = isset($m[4]) ? (int)$m[4] : 0;
            $min  = isset($m[5]) ? (int)$m[5] : 0;
            $sec  = isset($m[6]) ? (int)$m[6] : 0;
            $ampm = isset($m[7]) ? strtolower($m[7]) : '';
            if ($ampm === 'pm' && $hour < 12) { $hour += 12; }
            if ($ampm === 'am' && $hour === 12) { $hour = 0; }
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $min, $sec);
        }
    }

    // 4. DateTime fallback
    try {
        $dt = new DateTime($raw);
        if ($dt) {
            $y = (int)$dt->format('Y');
            if ($y >= 1990 && $y <= 2100) {
                return $dt->format('Y-m-d H:i:s');
            }
        }
    } catch (Exception $e) {}

    return null;
}

/**
 * Parse Received Amount into clean float (e.g. 500.00).
 */
function parseReceivedAmount(string $raw): ?float {
    $raw = trim($raw);
    if ($raw === '') return null;
    $clean = preg_replace('/[^\d\.]/', '', $raw);
    if ($clean === '' || !is_numeric($clean)) return null;
    $val = (float)$clean;
    return ($val > 0) ? $val : null;
}

$LOCKED_ORG_TYPES = ['zilla_panchayat', 'taluk_panchayat'];

/**
 * Validate and parse a single import row.
 * Returns: ['valid'=>bool, 'data'=>array, 'errors'=>array, 'dupe_status'=>?string]
 */
function validateImportRow(
    array $cells,
    array $dMap, array $dNames,
    array $tMap, array $tById, array $tByDistrict,
    array $gpMap, array $gpByTaluk, array $gpsById,
    array $LOCKED_ORG_TYPES,
    int $importFyId,
    ?int $lockedDistrictId, ?int $lockedTalukId,
    array $remaps = [],
    array $colMap = []
): array {
    $totalCols = count($cells);
    $get = function(string $key, ?int $fallbackIdx) use ($cells, $colMap): string {
        $idx = $colMap[$key] ?? $fallbackIdx;
        return ($idx !== null && isset($cells[$idx])) ? trim((string)$cells[$idx]) : '';
    };

    $fullName     = $get('full_name', 0);
    $fatherName   = $get('father_name', 1);
    $genderRaw    = strtolower($get('gender', 2));
    // Mobile normalization: strip spaces/dashes and optional country code (+91, 91, 0)
    $phone = preg_replace('/[\s\-\(\)\.]/', '', (string)$get('phone', 3));
    if (str_starts_with($phone, '+91')) {
        $phone = substr($phone, 3);
    } elseif (str_starts_with($phone, '91') && strlen($phone) === 12) {
        $phone = substr($phone, 2);
    } elseif (str_starts_with($phone, '0') && strlen($phone) === 11) {
        $phone = substr($phone, 1);
    }
    $email        = strtolower(trim($get('email', 4)));
    $kgid         = preg_replace('/\.0+$/', '', trim($get('kgid', 5)));
    $dobRaw       = $get('dob', 6);
    $gpWorkingRaw = strtolower($get('gp_working', 7));
    $orgTypeRaw   = strtolower($get('org_type', 8));
    $orgName      = $get('org_name', 9);
    $orgAddress   = $get('org_address', 10);
    $wDistrictRaw = normGeoName($get('working_district', 11));
    $wTalukRaw    = normGeoName($get('working_taluk', 12));
    $wGpRaw       = normGeoName($get('working_gp', 13));
    $mDistrictRaw = normGeoName($get('membership_district', 14));
    $mTalukRaw    = normGeoName($get('membership_taluk', 15));
    $payModeRaw   = strtolower($get('payment_mode', 16));
    $payRef       = $get('payment_ref', 17);

    // If >= 20 columns, 18=pay_date, 19=amount, 20=remarks
    // If 19 columns: 18 was remarks in old template
    $defaultDateIdx   = ($totalCols >= 20) ? 18 : null;
    $defaultAmountIdx = ($totalCols >= 20) ? 19 : null;
    $defaultRemIdx    = ($totalCols >= 21) ? 20 : (($totalCols === 19) ? 18 : null);

    $payDateRaw   = $get('payment_date', $defaultDateIdx);
    $recAmountRaw = $get('received_amount', $defaultAmountIdx);
    $offlineRem   = $get('remarks', $defaultRemIdx);

    $errors = [];

    // Remap resolution helpers
    $resolveDistrict = function(string $norm) use ($dMap, $remaps): ?int {
        if (!empty($remaps['districts'][$norm])) { return (int)$remaps['districts'][$norm]; }
        return $dMap[$norm] ?? null;
    };

    $resolveTaluk = function(?int $districtId, string $norm) use ($tMap, $remaps): ?int {
        if (!empty($remaps['taluks'][$norm])) { return (int)$remaps['taluks'][$norm]; }
        if ($districtId !== null && isset($tMap[$districtId . ':' . $norm])) {
            return $tMap[$districtId . ':' . $norm];
        }
        return null;
    };

    $resolveGp = function(?int $talukId, string $norm) use ($gpMap, $remaps): ?int {
        if (isset($remaps['gps'][$norm])) {
            if ($remaps['gps'][$norm] === 'skip') { return null; }
            return (int)$remaps['gps'][$norm];
        }
        if ($talukId !== null && isset($gpMap[$talukId . ':' . $norm])) {
            return $gpMap[$talukId . ':' . $norm];
        }
        return null;
    };

    // Required fields
    if ($fullName === '') { $errors[] = 'Full Name required'; }
    if ($fatherName === '') { $errors[] = 'Father/Husband Name required'; }

    // Gender: strictly optional (male / female / other / null). Members can update later in profile.
    $gender = null;
    if ($genderRaw !== '') {
        if (in_array($genderRaw, ['male', 'm'])) {
            $gender = 'male';
        } elseif (in_array($genderRaw, ['female', 'f'])) {
            $gender = 'female';
        } elseif (in_array($genderRaw, ['other', 'o'])) {
            $gender = 'other';
        }
    }

    if (!preg_match('/^[6-9][0-9]{9}$/', $phone)) { $errors[] = 'Phone must be 10-digit starting 6-9'; }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Valid email required'; }
    if ($kgid === '') {
        $errors[] = 'KGID required';
    } elseif (!preg_match('/^\d+$/', $kgid)) {
        $errors[] = 'KGID No. must contain only numeric digits';
    }
    if ($gpWorkingRaw === '' || !in_array($gpWorkingRaw, ['yes','no'])) { $errors[] = 'GP Working must be yes or no'; }

    // Date of Birth
    $dob = null;
    if ($dobRaw !== '') {
        $dob = parseDob($dobRaw);
        if ($dob === null) {
            $errors[] = 'Date of Birth format must be DD-MM-YYYY or DD/MM/YYYY';
        }
    } else {
        $errors[] = 'Date of Birth required';
    }

    $orgTypeKey    = str_replace([' ','-'], '_', $orgTypeRaw);
    $validOrgTypes = array_keys(Registration::ORG_TYPES);

    $workingDistrictId    = null;
    $workingTalukId       = null;
    $workingGpId          = null;
    $membershipDistrictId = null;
    $membershipTalukId    = null;

    $gpWorking  = ($gpWorkingRaw === 'yes') ? 'yes' : (($gpWorkingRaw === 'no') ? 'no' : null);
    $orgIsLocked = in_array($orgTypeKey, $LOCKED_ORG_TYPES);

    if ($gpWorking === 'yes' || ($gpWorking === 'no' && $orgIsLocked)) {
        // Working location required
        $wDid = $resolveDistrict($wDistrictRaw);
        if (!$wDid) {
            $errors[] = "Working District '" . ($cells[$colMap['working_district'] ?? 11] ?? '') . "' not found in GP master data.";
        } else {
            $workingDistrictId = $wDid;
            $wTid = $resolveTaluk($wDid, $wTalukRaw);
            if (!$wTid) {
                $distName = $dNames[$wDid] ?? 'district';
                $errors[] = "Working Taluk '" . ($cells[$colMap['working_taluk'] ?? 12] ?? '') . "' not found under $distName in GP master data.";
            } else {
                $workingTalukId = $wTid;
                // If taluk was remapped, sync parent district to master taluk's district
                if (isset($tById[$wTid])) {
                    $workingDistrictId = (int)$tById[$wTid]['district_id'];
                }

                // Working GP: optional. If present and matches master data, link it; otherwise leave null (members update later).
                if ($gpWorking === 'yes' && $wGpRaw !== '') {
                    if (isset($remaps['gps'][$wGpRaw]) && $remaps['gps'][$wGpRaw] === 'skip') {
                        $workingGpId = null;
                    } else {
                        $wGpId = $resolveGp($workingTalukId, $wGpRaw);
                        if ($wGpId) {
                            $workingGpId = $wGpId;
                        } else {
                            // GP name did not match master data, but GP is optional.
                            // Leave null without failing validation.
                            $workingGpId = null;
                        }
                    }
                }
            }
        }

        // For GP=yes or ZP/TP, membership location is auto-locked to working location
        $membershipDistrictId = $workingDistrictId;
        $membershipTalukId    = $workingTalukId;

        if ($gpWorking === 'no') {
            if (!in_array($orgTypeKey, $validOrgTypes)) { $errors[] = "Organization Type '$orgTypeRaw' invalid"; }
            if ($orgName === '') { $errors[] = 'Organization Name required'; }
        }
    } else {
        // GP Working = no with other organization
        if ($gpWorking === 'no') {
            if (!in_array($orgTypeKey, $validOrgTypes)) { $errors[] = "Organization Type '$orgTypeRaw' invalid"; }
            if ($orgName === '') { $errors[] = 'Organization Name required'; }
        }

        $mDid = $resolveDistrict($mDistrictRaw);
        if (!$mDid) {
            $errors[] = "Membership District '" . ($cells[$colMap['membership_district'] ?? 14] ?? '') . "' not found in GP master data.";
        } else {
            $membershipDistrictId = $mDid;
            $mTid = $resolveTaluk($mDid, $mTalukRaw);
            if (!$mTid) {
                $distName = $dNames[$mDid] ?? 'district';
                $errors[] = "Membership Taluk '" . ($cells[$colMap['membership_taluk'] ?? 15] ?? '') . "' not found under $distName in GP master data.";
            } else {
                $membershipTalukId = $mTid;
                if (isset($tById[$mTid])) {
                    $membershipDistrictId = (int)$tById[$mTid]['district_id'];
                }
            }
        }
    }

    // RBAC geographical scope validation
    if ($lockedDistrictId && $membershipDistrictId && $membershipDistrictId !== $lockedDistrictId) {
        $errors[] = 'Member is outside your authorized district';
    }
    if ($lockedTalukId && $membershipTalukId && $membershipTalukId !== $lockedTalukId) {
        $errors[] = 'Member is outside your authorized taluk';
    }

    // Duplicate check: KGID + Financial Year ONLY
    $dupeStatus = null;
    if ($kgid !== '' && empty($errors)) {
        $profileRow = Database::fetchOne("SELECT member_id FROM member_profiles WHERE kgid_no = ?", [$kgid]);
        if ($profileRow) {
            $mId  = $profileRow['member_id'];
            $paid = $importFyId ? Database::fetchOne("SELECT id FROM membership_payments WHERE member_id = ? AND membership_year_id = ? AND status='completed'", [$mId, $importFyId]) : false;
            if ($paid) {
                $dupeStatus = 'already_paid';
                $errors[] = "KGID $kgid already PAID for this FY";
            } else {
                $dupeStatus = 'existing_unpaid';
            }
        }
    }

    // Payment details resolution (supporting legacy Razorpay page imports & offline)
    $paidAt    = parsePaymentDateTime($payDateRaw);
    $recAmount = parseReceivedAmount($recAmountRaw);

    $isRazorpayRef = str_starts_with(strtolower($payRef), 'pay_');
    $hasPaymentDetails = ($payRef !== '' || $recAmount !== null || $paidAt !== null);

    $payModeClean = null;
    $importPaid   = false;

    if (in_array($payModeRaw, ['razorpay', 'online']) || ($payModeRaw === '' && $isRazorpayRef)) {
        $payModeClean = 'online';
        $importPaid   = true;
    } elseif ($payModeRaw === 'offline') {
        $payModeClean = 'offline';
        $importPaid   = true;
    } elseif ($hasPaymentDetails) {
        // Mode left blank, but payment reference or amount provided
        $payModeClean = $isRazorpayRef ? 'online' : 'online';
        $importPaid   = true;
    }

    // Check duplicate payment ID if starting with pay_
    if ($isRazorpayRef && $payRef !== '') {
        $existingPay = Database::fetchOne(
            "SELECT id FROM membership_payments WHERE gateway_payment_id = ?",
            [$payRef]
        );
        if ($existingPay) {
            $errors[] = "Payment ID '$payRef' has already been recorded in database";
        }
    }

    return [
        'valid'       => empty($errors),
        'errors'      => $errors,
        'dupe_status' => $dupeStatus,
        'data'        => [
            'full_name'              => $fullName,
            'father_spouse_name'     => $fatherName,
            'gender'                 => $gender,
            'phone'                  => $phone,
            'email'                  => $email,
            'kgid_no'                => $kgid,
            'dob'                    => $dob,
            'gp_working'             => $gpWorking,
            'organization_type'      => $orgIsLocked ? $orgTypeKey : ($gpWorking === 'no' ? $orgTypeKey : null),
            'organization_name'      => $orgName ?: null,
            'organization_address'   => $orgAddress ?: null,
            'working_district_id'    => $workingDistrictId,
            'working_taluk_id'       => $workingTalukId,
            'working_gp_id'          => $workingGpId,
            'membership_district_id' => $membershipDistrictId,
            'membership_taluk_id'    => $membershipTalukId,
            'payment_mode'           => $payModeClean,
            'import_paid'            => $importPaid,
            'payment_reference'      => $payRef ?: null,
            'payment_date'           => $paidAt ?: null,
            'received_amount'        => $recAmount ?: null,
            'offline_reference'      => $payRef ?: null,
            'offline_remarks'        => $offlineRem ?: null,
        ],
    ];
}

/**
 * Scan raw rows and find any district, taluk, or GP names that do not match GP master data.
 */
function detectUnmatchedLocations(
    array $rawRows,
    array $dMap,
    array $tMap,
    array $gpMap,
    array $districts,
    array $taluks,
    array $colMap = []
): array {
    $unmatchedDistricts = []; // normKey => ['raw' => string, 'count' => int]
    $unmatchedTaluks    = []; // normKey => ['raw' => string, 'district_raw' => string, 'district_id' => ?int, 'count' => int]
    $unmatchedGps       = []; // normKey => ['raw' => string, 'taluk_raw' => string, 'taluk_id' => ?int, 'count' => int]

    $get = function(array $cells, string $key, int $fallbackIdx) use ($colMap): string {
        $idx = $colMap[$key] ?? $fallbackIdx;
        return ($idx !== null && isset($cells[$idx])) ? trim((string)$cells[$idx]) : '';
    };

    foreach ($rawRows as $cells) {
        $gpWork = strtolower($get($cells, 'gp_working', 7));
        $org    = str_replace([' ','-'], '_', strtolower($get($cells, 'org_type', 8)));
        $locked = in_array($org, ['zilla_panchayat','taluk_panchayat']);

        $rawD = '';
        $rawT = '';
        $rawG = '';

        if ($gpWork === 'yes' || ($gpWork === 'no' && $locked)) {
            $rawD = $get($cells, 'working_district', 11);
            $rawT = $get($cells, 'working_taluk', 12);
            if ($gpWork === 'yes') {
                $rawG = $get($cells, 'working_gp', 13);
            }
        } else {
            $rawD = $get($cells, 'membership_district', 14);
            $rawT = $get($cells, 'membership_taluk', 15);
        }

        $normD = normGeoName($rawD);
        $normT = normGeoName($rawT);
        $normG = normGeoName($rawG);

        $resolvedDid = null;
        if ($normD !== '') {
            if (isset($dMap[$normD])) {
                $resolvedDid = $dMap[$normD];
            } else {
                if (!isset($unmatchedDistricts[$normD])) {
                    $unmatchedDistricts[$normD] = ['raw' => $rawD, 'count' => 0];
                }
                $unmatchedDistricts[$normD]['count']++;
            }
        }

        $resolvedTid = null;
        if ($normT !== '') {
            if ($resolvedDid !== null && isset($tMap[$resolvedDid . ':' . $normT])) {
                $resolvedTid = $tMap[$resolvedDid . ':' . $normT];
            } else {
                $foundAny = false;
                foreach ($districts as $d) {
                    if (isset($tMap[$d['id'] . ':' . $normT])) { $foundAny = true; break; }
                }
                if (!$foundAny || $resolvedDid !== null) {
                    if (!isset($unmatchedTaluks[$normT])) {
                        $unmatchedTaluks[$normT] = [
                            'raw'          => $rawT,
                            'district_raw' => $rawD,
                            'district_id'  => $resolvedDid,
                            'count'        => 0,
                        ];
                    }
                    $unmatchedTaluks[$normT]['count']++;
                }
            }
        }

        if ($normG !== '') {
            if ($resolvedTid !== null && isset($gpMap[$resolvedTid . ':' . $normG])) {
                // Matched
            } else {
                if (!isset($unmatchedGps[$normG])) {
                    $unmatchedGps[$normG] = [
                        'raw'       => $rawG,
                        'taluk_raw' => $rawT,
                        'taluk_id'  => $resolvedTid,
                        'count'     => 0,
                    ];
                }
                $unmatchedGps[$normG]['count']++;
            }
        }
    }

    return [
        'districts' => $unmatchedDistricts,
        'taluks'    => $unmatchedTaluks,
        'gps'       => $unmatchedGps,
    ];
}

// ─── GET Actions ──────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'download_errors') {
    Auth::requireLogin();
    $preview = $_SESSION['members_import_preview'] ?? null;
    $errorRows = [];
    if (!empty($preview['invalid'])) {
        foreach ($preview['invalid'] as $inv) {
            $errs = $inv['_errors'] ?? [];
            $realErrs = array_filter($errs, fn($e) => !str_contains($e, 'already PAID for this FY'));
            if (!empty($realErrs)) {
                $inv['_errors_clean'] = implode('; ', $realErrs);
                $errorRows[] = $inv;
            }
        }
    }

    if (empty($errorRows)) {
        Session::flash('info', 'No skipped error rows found in current session. Please upload the file first.');
        header('Location: /admin/members-import.php');
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="skipped_members_to_fix_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Full Name',
        'Father / Husband Name',
        'Gender',
        'Phone',
        'Email',
        'KGID No.',
        'Date of Birth',
        'GP Working?',
        'Organization Type',
        'Organization Name',
        'Organization Address',
        'Working District',
        'Working Taluk',
        'Working GP',
        'Membership District',
        'Membership Taluk',
        'Payment Mode',
        'Payment Reference',
        'Payment Date & Time',
        'Received Amount',
        'Payment Remarks',
        'Reason Why Skipped (Fix this column)',
    ]);

    $dNames = [];
    foreach (Database::fetchAll("SELECT id, name FROM districts") as $d) {
        $dNames[$d['id']] = $d['name'];
    }
    $tNames = [];
    foreach (Database::fetchAll("SELECT id, name FROM taluks") as $t) {
        $tNames[$t['id']] = $t['name'];
    }
    $gNames = [];
    foreach (Database::fetchAll("SELECT id, name FROM gram_panchayatis") as $g) {
        $gNames[$g['id']] = $g['name'];
    }

    foreach ($errorRows as $row) {
        fputcsv($out, [
            $row['full_name'] ?? '',
            $row['father_spouse_name'] ?? '',
            $row['gender'] ?? '',
            $row['phone'] ?? '',
            $row['email'] ?? '',
            $row['kgid_no'] ?? '',
            !empty($row['dob']) ? date('d-m-Y', strtotime($row['dob'])) : '',
            $row['gp_working'] ?? 'yes',
            $row['organization_type'] ?? '',
            $row['organization_name'] ?? '',
            $row['organization_address'] ?? '',
            $dNames[$row['working_district_id'] ?? 0] ?? '',
            $tNames[$row['working_taluk_id'] ?? 0] ?? '',
            $gNames[$row['working_gp_id'] ?? 0] ?? '',
            $dNames[$row['membership_district_id'] ?? 0] ?? '',
            $tNames[$row['membership_taluk_id'] ?? 0] ?? '',
            $row['payment_mode'] ?? '',
            $row['payment_reference'] ?? '',
            $row['payment_date'] ?? '',
            $row['received_amount'] ?? '',
            $row['offline_remarks'] ?? '',
            $row['_errors_clean'] ?? 'Validation error',
        ]);
    }

    fclose($out);
    exit;
}

// ─── POST Handlers ────────────────────────────────────────────────────────────
$previewData = null;
$remapStep   = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();
    $action = $_POST['action'] ?? '';

    // ── Upload Step: parse file, check GP master matching ──────────────────
    if ($action === 'upload') {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            Session::flash('error', 'Please select a valid CSV or Excel file.');
            header('Location: /admin/members-import.php');
            exit;
        }
        $tmpName  = $_FILES['import_file']['tmp_name'];
        $origName = strtolower(basename($_FILES['import_file']['name']));

        if (str_ends_with($origName, '.xlsx') || str_ends_with($origName, '.xls')) {
            $parsed = parseExcelFile($tmpName);
        } else {
            $parsed = parseCSVFile($tmpName);
        }

        $headerRow = $parsed['header'] ?? [];
        $rawRows   = $parsed['rows'] ?? [];

        if (empty($rawRows)) {
            Session::flash('error', 'No data rows found (make sure row 1 is the header row).');
            header('Location: /admin/members-import.php');
            exit;
        }

        $colIndexMap = buildColumnIndexMap($headerRow);
        $importFyIdPost = Sanitize::positiveInt($_POST['import_fy_id'] ?? null) ?: $fyId;

        // Store raw rows and state in session
        $_SESSION['import_raw_rows'] = $rawRows;
        $_SESSION['import_col_map']  = $colIndexMap;
        $_SESSION['import_fy_id']    = $importFyIdPost;
        $_SESSION['import_remaps']   = [];

        // Detect any unmatched locations against GP Master Data
        $unmatched = detectUnmatchedLocations($rawRows, $dMap, $tMap, $gpMap, $districts, $taluks, $colIndexMap);

        // District and Taluk are strictly required for administrative jurisdiction & member numbering.
        // Gram Panchayat is completely optional per user instruction.
        if (!empty($unmatched['districts']) || !empty($unmatched['taluks'])) {
            // Unmatched districts/taluks detected — admin must remap before preview
            $remapStep = [
                'districts'   => $unmatched['districts'],
                'taluks'      => $unmatched['taluks'],
                'gps'         => $unmatched['gps'],
                'fy_id'       => $importFyIdPost,
                'total_rows'  => count($rawRows),
            ];
            $_SESSION['import_remap_step'] = $remapStep;
            $_SESSION['import_unmatched']  = $unmatched;
            unset($_SESSION['members_import_preview']);
        } else {
            // All districts and taluks matched! Validate and show preview directly.
            $valid = $invalid = [];
            foreach ($rawRows as $cells) {
                $result = validateImportRow(
                    $cells,
                    $dMap, $dNames,
                    $tMap, $tById, $tByDistrict,
                    $gpMap, $gpByTaluk, $gpsById,
                    $LOCKED_ORG_TYPES,
                    $importFyIdPost,
                    $lockedDistrictId, $lockedTalukId,
                    [],
                    $colIndexMap
                );
                $result['data']['_import_fy_id'] = $importFyIdPost;
                if ($result['valid']) {
                    $valid[] = $result['data'];
                } else {
                    $result['data']['_errors'] = $result['errors'];
                    $invalid[] = $result['data'];
                }
            }
            $previewData = ['valid' => $valid, 'invalid' => $invalid, 'fy_id' => $importFyIdPost];
            $_SESSION['members_import_preview'] = $previewData;
            unset($_SESSION['import_remap_step']);
        }
    }

    // ── Remap Step: admin maps unrecognized names → master DB entries ────────
    if ($action === 'remap') {
        $rawRows        = $_SESSION['import_raw_rows'] ?? [];
        $colIndexMap    = $_SESSION['import_col_map'] ?? [];
        $importFyIdPost = (int)($_SESSION['import_fy_id'] ?? $fyId);

        if (empty($rawRows)) {
            Session::flash('error', 'Session expired. Please re-upload the file.');
            header('Location: /admin/members-import.php');
            exit;
        }

        $remaps = [
            'districts' => [],
            'taluks'    => [],
            'gps'       => [],
        ];
        foreach (($_POST['remap_district'] ?? []) as $normKey => $did) {
            $did = (int)$did;
            if ($did > 0) { $remaps['districts'][$normKey] = $did; }
        }
        foreach (($_POST['remap_taluk'] ?? []) as $normKey => $tid) {
            $tid = (int)$tid;
            if ($tid > 0) { $remaps['taluks'][$normKey] = $tid; }
        }
        foreach (($_POST['remap_gp'] ?? []) as $normKey => $gid) {
            if ($gid === 'skip' || $gid === '') {
                $remaps['gps'][$normKey] = 'skip';
            } else {
                $gid = (int)$gid;
                if ($gid > 0) { $remaps['gps'][$normKey] = $gid; }
            }
        }
        $_SESSION['import_remaps'] = $remaps;

        // Re-validate all rows with remaps applied
        $valid = $invalid = [];
        foreach ($rawRows as $cells) {
            $result = validateImportRow(
                $cells,
                $dMap, $dNames,
                $tMap, $tById, $tByDistrict,
                $gpMap, $gpByTaluk, $gpsById,
                $LOCKED_ORG_TYPES,
                $importFyIdPost,
                $lockedDistrictId, $lockedTalukId,
                $remaps,
                $colIndexMap
            );
            $result['data']['_import_fy_id'] = $importFyIdPost;
            if ($result['valid']) {
                $valid[] = $result['data'];
            } else {
                $result['data']['_errors'] = $result['errors'];
                $invalid[] = $result['data'];
            }
        }
        $previewData = ['valid' => $valid, 'invalid' => $invalid, 'fy_id' => $importFyIdPost];
        $_SESSION['members_import_preview'] = $previewData;
        unset($_SESSION['import_remap_step']);
        $remapStep = null;
    }

    // ── Re-open Remap from Preview ───────────────────────────────────────────
    if ($action === 'reopen_remap') {
        $unmatched = $_SESSION['import_unmatched'] ?? null;
        if ($unmatched && (!empty($unmatched['districts']) || !empty($unmatched['taluks']) || !empty($unmatched['gps']))) {
            $remapStep = [
                'districts'   => $unmatched['districts'],
                'taluks'      => $unmatched['taluks'],
                'gps'         => $unmatched['gps'],
                'fy_id'       => (int)($_SESSION['import_fy_id'] ?? $fyId),
                'total_rows'  => count($_SESSION['import_raw_rows'] ?? []),
            ];
            $_SESSION['import_remap_step'] = $remapStep;
            $previewData = null;
            unset($_SESSION['members_import_preview']);
        }
    }

    // ── AJAX Commit Chunk: Process in batches with live progress bar ────────
    if ($action === 'commit_chunk') {
        header('Content-Type: application/json; charset=utf-8');
        @set_time_limit(120);
        @ini_set('max_execution_time', '120');

        $data = $_SESSION['members_import_preview'] ?? null;
        if (!$data || empty($data['valid'])) {
            echo json_encode(['success' => false, 'error' => 'No valid rows found in session. Please re-upload.']);
            exit;
        }

        $offset    = max(0, (int)($_POST['offset'] ?? 0));
        $chunkSize = max(10, min(100, (int)($_POST['chunk_size'] ?? 50)));
        $total     = count($data['valid']);
        $importFyIdCommit = (int)($data['fy_id'] ?? $fyId);

        if (!isset($_SESSION['import_stats']) || $offset === 0) {
            $_SESSION['import_stats'] = [
                'new_members' => 0,
                'paid'        => 0,
                'skipped'     => 0,
                'errors'      => [],
            ];
        }

        $slice = array_slice($data['valid'], $offset, $chunkSize);
        $chunkNew = 0;
        $chunkPaid = 0;
        $chunkErrors = [];

        foreach ($slice as $row) {
            try {
                // Duplicate rule: Strictly KGID only
                $existingProfile = Database::fetchOne("SELECT member_id FROM member_profiles WHERE kgid_no = ?", [$row['kgid_no']]);
                $memberId = null;

                if ($existingProfile) {
                    $memberId = (int)$existingProfile['member_id'];
                } else {
                    $tempMemberNo = 'PENDING-' . bin2hex(random_bytes(8));
                    Database::execute(
                        "INSERT INTO members
                            (member_no, name, designation, gp_id, taluk_id, district_id,
                             gp_working, organization_type, organization_name, organization_address,
                             working_district_id, working_taluk_id, working_gp_id,
                             joining_date, membership_status)
                         VALUES (?, ?, 'PDO', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'active')",
                        [
                            $tempMemberNo,
                            $row['full_name'],
                            $row['working_gp_id'],
                            $row['membership_taluk_id'],
                            $row['membership_district_id'],
                            $row['gp_working'],
                            $row['organization_type'],
                            $row['organization_name'],
                            $row['organization_address'],
                            $row['working_district_id'],
                            $row['working_taluk_id'],
                            $row['working_gp_id'],
                        ]
                    );
                    $memberId = (int)Database::lastInsertId();

                    $regPlaceholder = 'REG-' . str_pad((string)$memberId, 6, '0', STR_PAD_LEFT);
                    Database::execute("UPDATE members SET member_no = ? WHERE id = ?", [$regPlaceholder, $memberId]);

                    Database::execute(
                        "INSERT INTO member_profiles
                            (member_id, kgid_no, date_of_birth, gender, father_spouse_name, personal_email, personal_mobile)
                         VALUES (?, ?, ?, ?, ?, ?, ?)",
                        [
                            $memberId,
                            $row['kgid_no'],
                            $row['dob'],
                            $row['gender'],
                            $row['father_spouse_name'],
                            $row['email'],
                            $row['phone'],
                        ]
                    );

                    Database::transaction(function () use ($memberId) {
                        MembershipNumber::assignIfPlaceholder($memberId);
                    });
                    $chunkNew++;
                    $_SESSION['import_stats']['new_members']++;
                }

                if ($row['import_paid'] && $importFyIdCommit && $memberId) {
                    $already = Database::fetchOne(
                        "SELECT id FROM membership_payments WHERE member_id=? AND membership_year_id=? AND status='completed'",
                        [$memberId, $importFyIdCommit]
                    );
                    if (!$already) {
                        $fyRow = Database::fetchOne("SELECT fee_amount FROM membership_years WHERE id=?", [$importFyIdCommit]);
                        $standardFee = $fyRow ? (float)$fyRow['fee_amount'] : 0.0;
                        $feeAmt = (!empty($row['received_amount']) && (float)$row['received_amount'] > 0)
                            ? (float)$row['received_amount']
                            : $standardFee;

                        $paidAt = !empty($row['payment_date']) ? $row['payment_date'] : date('Y-m-d H:i:s');
                        $isOnline = ($row['payment_mode'] === 'online');
                        $gatewayPaymentId = $isOnline ? ($row['payment_reference'] ?: null) : null;
                        $offlineRef = !$isOnline ? ($row['payment_reference'] ?: null) : null;
                        $remarks = $row['offline_remarks'] ?: ($isOnline && $gatewayPaymentId ? 'Imported legacy Razorpay payment' : null);

                        Database::execute(
                            "INSERT INTO membership_payments
                                (member_id, membership_year_id, amount, status, payment_mode,
                                 gateway_payment_id, offline_reference, offline_remarks, paid_at, created_at)
                             VALUES (?, ?, ?, 'completed', ?, ?, ?, ?, ?, NOW())",
                            [
                                $memberId,
                                $importFyIdCommit,
                                $feeAmt,
                                $row['payment_mode'],
                                $gatewayPaymentId,
                                $offlineRef,
                                $remarks,
                                $paidAt,
                            ]
                        );
                        $paymentId = (int)Database::lastInsertId();

                        Database::transaction(function () use ($memberId) {
                            MembershipNumber::assignIfPlaceholder($memberId);
                        });

                        $memberRow = Database::fetchOne("SELECT id, name FROM members WHERE id = ?", [$memberId]);
                        if ($memberRow) {
                            try {
                                Auth::ensureUserAccountForMember($memberId);
                            } catch (\Throwable $e) {
                                // Non-blocking if account already exists
                            }
                        }

                        // NOTE: Receipts are generated dynamically on-demand when viewed/downloaded.
                        // Generating hundreds of PDFs in bulk import loop causes timeout in fpdf.php.
                        $chunkPaid++;
                        $_SESSION['import_stats']['paid']++;
                    }
                }
            } catch (\Throwable $e) {
                if (!empty($memberId) && empty($existingProfile)) {
                    try {
                        Database::execute("DELETE FROM member_profiles WHERE member_id = ?", [$memberId]);
                        Database::execute("DELETE FROM members WHERE id = ?", [$memberId]);
                    } catch (\Throwable $cleanupEx) {}
                }
                $errMsg = "KGID " . ($row['kgid_no'] ?? 'unknown') . ": " . $e->getMessage();
                error_log("Members import chunk error: " . $errMsg);
                $chunkErrors[] = $errMsg;
                $_SESSION['import_stats']['errors'][] = $errMsg;
            }
        }

        $nextOffset = $offset + count($slice);
        $done = ($nextOffset >= $total || count($slice) === 0);

        if ($done) {
            $totalNew = $_SESSION['import_stats']['new_members'] ?? 0;
            $totalPaid = $_SESSION['import_stats']['paid'] ?? 0;
            $totalErrors = count($_SESSION['import_stats']['errors'] ?? []);

            unset(
                $_SESSION['members_import_preview'],
                $_SESSION['import_raw_rows'],
                $_SESSION['import_col_map'],
                $_SESSION['import_fy_id'],
                $_SESSION['import_remaps'],
                $_SESSION['import_unmatched'],
                $_SESSION['import_remap_step'],
                $_SESSION['import_stats']
            );

            AuditLogger::log('CREATE', 'members', null, null, [
                'action' => 'bulk_import_ajax',
                'new_members' => $totalNew,
                'paid_activated' => $totalPaid,
                'errors' => $totalErrors
            ]);

            Session::flash('success', "Bulk import complete! Successfully uploaded and processed $total members ($totalNew new, $totalPaid paid/activated).");
        }

        echo json_encode([
            'success'     => true,
            'done'        => $done,
            'processed'   => count($slice),
            'offset'      => $nextOffset,
            'total'       => $total,
            'new_members' => $_SESSION['import_stats']['new_members'] ?? 0,
            'paid'        => $_SESSION['import_stats']['paid'] ?? 0,
            'errors'      => count($_SESSION['import_stats']['errors'] ?? []),
        ]);
        exit;
    }

    // ── Fallback Standard Commit Step (Non-JS) ──────────────────────────────
    if ($action === 'commit') {
        @set_time_limit(300);
        @ini_set('max_execution_time', '300');

        $data = $_SESSION['members_import_preview'] ?? null;
        if (!$data || empty($data['valid'])) {
            Session::flash('error', 'No valid rows to import.');
            header('Location: /admin/members-import.php');
            exit;
        }

        $count     = 0;
        $paidCount = 0;
        $importFyIdCommit = (int)($data['fy_id'] ?? $fyId);

        $failedRows = [];

        foreach ($data['valid'] as $row) {
            try {
                // Check KGID duplicate status
                $existingProfile = Database::fetchOne("SELECT member_id FROM member_profiles WHERE kgid_no = ?", [$row['kgid_no']]);
                $memberId = null;

                if ($existingProfile) {
                    // Existing member — do NOT re-insert, add FY payment if applicable
                    $memberId = (int)$existingProfile['member_id'];
                } else {
                    // Insert new member into members table with temporary member_no
                    $tempMemberNo = 'PENDING-' . bin2hex(random_bytes(8));
                    Database::execute(
                        "INSERT INTO members
                            (member_no, name, designation, gp_id, taluk_id, district_id,
                             gp_working, organization_type, organization_name, organization_address,
                             working_district_id, working_taluk_id, working_gp_id,
                             joining_date, membership_status)
                         VALUES (?, ?, 'PDO', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'active')",
                        [
                            $tempMemberNo,
                            $row['full_name'],
                            $row['working_gp_id'],
                            $row['membership_taluk_id'],
                            $row['membership_district_id'],
                            $row['gp_working'],
                            $row['organization_type'],
                            $row['organization_name'],
                            $row['organization_address'],
                            $row['working_district_id'],
                            $row['working_taluk_id'],
                            $row['working_gp_id'],
                        ]
                    );
                    $memberId = (int)Database::lastInsertId();

                    // Set standard REG- placeholder so MembershipNumber recognizes it
                    $regPlaceholder = 'REG-' . str_pad((string)$memberId, 6, '0', STR_PAD_LEFT);
                    Database::execute("UPDATE members SET member_no = ? WHERE id = ?", [$regPlaceholder, $memberId]);

                    // Insert into member_profiles table (gender can be null)
                    Database::execute(
                        "INSERT INTO member_profiles
                            (member_id, kgid_no, date_of_birth, gender, father_spouse_name, personal_email, personal_mobile)
                         VALUES (?, ?, ?, ?, ?, ?, ?)",
                        [
                            $memberId,
                            $row['kgid_no'],
                            $row['dob'],
                            $row['gender'],
                            $row['father_spouse_name'],
                            $row['email'],
                            $row['phone'],
                        ]
                    );

                    // Assign provisional / official district-coded member number
                    Database::transaction(function () use ($memberId) {
                        MembershipNumber::assignIfPlaceholder($memberId);
                    });
                    $count++;
                }

                // Payment processing (offline or Razorpay / online)
                if ($row['import_paid'] && $importFyIdCommit && $memberId) {
                    $already = Database::fetchOne(
                        "SELECT id FROM membership_payments WHERE member_id=? AND membership_year_id=? AND status='completed'",
                        [$memberId, $importFyIdCommit]
                    );
                    if (!$already) {
                        $fyRow = Database::fetchOne("SELECT fee_amount FROM membership_years WHERE id=?", [$importFyIdCommit]);
                        $standardFee = $fyRow ? (float)$fyRow['fee_amount'] : 0.0;
                        $feeAmt = (!empty($row['received_amount']) && (float)$row['received_amount'] > 0)
                            ? (float)$row['received_amount']
                            : $standardFee;

                        $paidAt = !empty($row['payment_date']) ? $row['payment_date'] : date('Y-m-d H:i:s');
                        $isOnline = ($row['payment_mode'] === 'online');
                        $gatewayPaymentId = $isOnline ? ($row['payment_reference'] ?: null) : null;
                        $offlineRef = !$isOnline ? ($row['payment_reference'] ?: null) : null;
                        $remarks = $row['offline_remarks'] ?: ($isOnline && $gatewayPaymentId ? 'Imported legacy Razorpay payment' : null);

                        Database::execute(
                            "INSERT INTO membership_payments
                                (member_id, membership_year_id, amount, status, payment_mode,
                                 gateway_payment_id, offline_reference, offline_remarks, paid_at, created_at)
                             VALUES (?, ?, ?, 'completed', ?, ?, ?, ?, ?, NOW())",
                            [
                                $memberId,
                                $importFyIdCommit,
                                $feeAmt,
                                $row['payment_mode'],
                                $gatewayPaymentId,
                                $offlineRef,
                                $remarks,
                                $paidAt,
                            ]
                        );
                        $paymentId = (int)Database::lastInsertId();

                        // 1. Assign official permanent membership number
                        Database::transaction(function () use ($memberId) {
                            MembershipNumber::assignIfPlaceholder($memberId);
                        });

                        // 2. Initialize member user account in users table (requires password setup on access)
                        $memberRow = Database::fetchOne("SELECT id, name FROM members WHERE id = ?", [$memberId]);
                        if ($memberRow) {
                            try {
                                Auth::ensureUserAccountForMember($memberId);
                            } catch (\Throwable $e) {
                                // Non-blocking if account already exists
                            }
                        }

                        // NOTE: Receipts are generated dynamically on-demand when viewed/downloaded.
                        $paidCount++;
                    }
                }
            } catch (\Throwable $e) {
                // If a new member record was created but profile insert or number assignment failed, remove orphan
                if (!empty($memberId) && empty($existingProfile)) {
                    try {
                        Database::execute("DELETE FROM member_profiles WHERE member_id = ?", [$memberId]);
                        Database::execute("DELETE FROM members WHERE id = ?", [$memberId]);
                    } catch (\Throwable $cleanupEx) {
                        // ignore cleanup exception
                    }
                }
                error_log("Members import failed on KGID " . ($row['kgid_no'] ?? 'unknown') . ": " . $e->getMessage());
                $failedRows[] = "KGID " . ($row['kgid_no'] ?? 'unknown') . ": " . $e->getMessage();
            }
        }

        // Clean up session keys
        unset(
            $_SESSION['members_import_preview'],
            $_SESSION['import_raw_rows'],
            $_SESSION['import_col_map'],
            $_SESSION['import_fy_id'],
            $_SESSION['import_remaps'],
            $_SESSION['import_unmatched'],
            $_SESSION['import_remap_step']
        );

        AuditLogger::log('CREATE', 'members', null, null, ['action' => 'bulk_import', 'new_members' => $count, 'paid_activated' => $paidCount, 'errors' => count($failedRows)]);

        if (!empty($failedRows)) {
            Session::flash('warning', "Bulk import finished with warnings. Successfully imported: $count new members ($paidCount paid). Skipped " . count($failedRows) . " row(s) due to errors. First error: " . htmlspecialchars($failedRows[0]));
        } else {
            Session::flash('success', "Bulk import complete. New members: $count. Paid/activated: $paidCount.");
        }
        header('Location: /admin/members.php');
        exit;
    }

    // ── Cancel Step ──────────────────────────────────────────────────────────
    if ($action === 'cancel') {
        unset(
            $_SESSION['members_import_preview'],
            $_SESSION['import_raw_rows'],
            $_SESSION['import_col_map'],
            $_SESSION['import_fy_id'],
            $_SESSION['import_remaps'],
            $_SESSION['import_unmatched'],
            $_SESSION['import_remap_step']
        );
        header('Location: /admin/members-import.php');
        exit;
    }
}

// Restore active step from session if returning to page
if ($previewData === null && isset($_SESSION['members_import_preview'])) {
    $previewData = $_SESSION['members_import_preview'];
}
if ($remapStep === null && $previewData === null && isset($_SESSION['import_remap_step'])) {
    $remapStep = $_SESSION['import_remap_step'];
}

// Lookup maps for preview display (avoids N+1 DB queries)
$districtNameMap = [];
foreach (Database::fetchAll("SELECT id, name FROM districts") as $d) {
    $districtNameMap[(int)$d['id']] = $d['name'];
}
$talukNameMap = [];
foreach (Database::fetchAll("SELECT id, name FROM taluks") as $t) {
    $talukNameMap[(int)$t['id']] = $t['name'];
}
$gpNameMap = [];
foreach (Database::fetchAll("SELECT id, name FROM gram_panchayatis") as $g) {
    $gpNameMap[(int)$g['id']] = $g['name'];
}

if (php_sapi_name() === 'cli' && basename($_SERVER['PHP_SELF'] ?? '') !== 'members-import.php') {
    return;
}

$pageTitle   = 'Bulk Import Members';
$activeMenu  = 'members_import';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Members', 'url' => '/admin/members.php'],
    ['label' => 'Bulk Import', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>
<style>
    .sub-nav { background: #fff; border-bottom: 1px solid #cbd5e1; padding: 0 24px; display: flex; gap: 20px; margin-bottom: 24px; border-radius: 6px; }
    .sub-nav a { display: inline-block; padding: 12px 4px; color: #556; text-decoration: none; font-weight: 600; font-size: 0.9rem; border-bottom: 3px solid transparent; }
    .sub-nav a:hover { color: #1a3a6b; }
    .sub-nav a.active { color: #1a3a6b; border-bottom-color: #1a3a6b; }
    .panel { background: #fff; border-radius: 8px; box-shadow: 0 1px 6px rgba(26,58,107,0.08); padding: 24px; margin-bottom: 24px; }
    .panel h2 { font-size: 1.15rem; color: #1a3a6b; margin: 0 0 16px; border-bottom: 2px solid #eef1f5; padding-bottom: 12px; }
    .panel h3 { font-size: 1rem; color: #1a3a6b; margin: 20px 0 10px; }
    .msg { padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 16px; }
    .msg.success { background: #e7f6ec; color: #1e6b3a; border: 1px solid #b9e5c6; }
    .msg.error   { background: #fdecea; color: #a12622; border: 1px solid #f5c2be; }
    .btn { display: inline-block; background: #1a3a6b; color: #fff; border: none; border-radius: 6px; padding: 10px 18px; font-size: 0.9rem; font-weight: 600; cursor: pointer; text-decoration: none; }
    .btn:hover { background: #142c52; }
    select, input[type="text"], input[type="file"] { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.9rem; font-family: inherit; }
    label { display: block; font-size: 0.8rem; font-weight: 600; color: #33415c; margin: 10px 0 4px; }
    table { width: 100%; border-collapse: collapse; font-size: 0.84rem; margin-top: 14px; }
    th, td { text-align: left; padding: 12px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    th { color: #ffffff; font-weight: 600; background: var(--blue-800, #1e40af); font-size: 0.76rem; text-transform: uppercase; letter-spacing: 0.5px; border: none; white-space: nowrap; }
    tr:hover { background: #f8fafc; }
    .table-wrap { overflow-x: auto; background: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04); margin-top: 14px; }
    .err-cell { color: #a12622; font-size: 0.78rem; font-weight: 500; }
    .ok-cell  { color: #1e6b3a; font-size: 0.78rem; }
    code { background: #f0f3f7; padding: 1px 5px; border-radius: 3px; font-size: 0.85em; }
    .drop-zone { border: 2px dashed #cbd5e1; border-radius: 10px; padding: 36px 24px; text-align: center; background: #f8fafc; }
    .badge { display: inline-block; font-size: 0.75rem; padding: 2px 7px; border-radius: 4px; font-weight: 600; }
    .badge-suggest { background: #e0f2fe; color: #0369a1; }
    .workflow-bar { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; padding: 10px 16px; background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 0.85rem; }
    .workflow-step { padding: 4px 10px; border-radius: 4px; font-weight: 600; color: #64748b; }
    .workflow-step.active { background: #1a3a6b; color: #fff; }
    .workflow-step.completed { color: #166534; }
    .workflow-sep { color: #94a3b8; }
</style>

<div class="sub-nav">
    <a href="/admin/members.php">Members List</a>
    <a href="/admin/members.php?add=1">+ Add Member</a>
    <a href="/admin/members-import.php" class="active">Bulk Import</a>
    <a href="/admin/members-reports.php">Abstract Reports</a>
</div>

    <?php if ($remapStep): ?>
    <!-- ── Step 2: Remap Locations with GP Master Data ───────────────────────── -->
    <div class="workflow-bar">
        <span class="workflow-step completed">✓ 1. Upload File</span>
        <span class="workflow-sep">→</span>
        <span class="workflow-step active">2. Remap Locations (Action Required)</span>
        <span class="workflow-sep">→</span>
        <span class="workflow-step">3. Preview &amp; Confirm</span>
        <span class="workflow-sep">→</span>
        <span class="workflow-step">4. Import Complete</span>
    </div>

    <div class="panel">
        <h2>Remap Unmatched Locations to GP Master Data</h2>

        <div style="background:#fffbeb; border:1px solid #fde68a; color:#92400e; padding:14px 18px; border-radius:8px; margin-bottom:20px; font-size:0.9rem; line-height:1.6;">
            <strong>⚠️ Location Matching Notice:</strong><br>
            The uploaded file contains District, Taluk, or Gram Panchayat names that do not exactly match the official GP Master Data.<br>
            Please map each unrecognized name below to the correct entry in the database. Your selections will be applied across all <strong><?= (int)($remapStep['total_rows'] ?? 0) ?> rows</strong> in the file before generating the preview.
        </div>

        <form method="post">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="remap">

            <?php if (!empty($remapStep['districts'])): ?>
            <div style="margin-bottom:28px;">
                <h3>🏛️ Unmatched Districts (<?= count($remapStep['districts']) ?>)</h3>
                <p style="color:#64748b; font-size:0.82rem; margin:0 0 10px;">Select the master district corresponding to each uploaded name:</p>
                <table>
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th style="width:260px;">Uploaded District Name</th>
                            <th style="width:110px;">Rows Affected</th>
                            <th>Map to Master District</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $dIdx = 1;
                        $savedDistRemaps = $_SESSION['import_remaps']['districts'] ?? [];
                        foreach ($remapStep['districts'] as $normKey => $info):
                            $suggestedDid = $savedDistRemaps[$normKey] ?? suggestDistrict($info['raw'], $districts);
                        ?>
                        <tr>
                            <td><?= $dIdx++ ?></td>
                            <td>
                                <strong><?= Sanitize::html($info['raw']) ?></strong>
                                <?php if ($suggestedDid): ?>
                                    <span class="badge badge-suggest" style="margin-left:6px;">Suggested: <?= Sanitize::html($dNames[$suggestedDid] ?? '') ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$info['count'] ?></td>
                            <td>
                                <select name="remap_district[<?= Sanitize::html($normKey) ?>]" required style="max-width:340px;">
                                    <option value="">-- Select Master District --</option>
                                    <?php foreach ($districts as $d): ?>
                                        <option value="<?= $d['id'] ?>" <?= ($suggestedDid === (int)$d['id']) ? 'selected' : '' ?>>
                                            <?= Sanitize::html($d['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($remapStep['taluks'])): ?>
            <div style="margin-bottom:28px;">
                <h3>📍 Unmatched Taluks (<?= count($remapStep['taluks']) ?>)</h3>
                <p style="color:#64748b; font-size:0.82rem; margin:0 0 10px;">Select the master taluk corresponding to each uploaded name:</p>
                <table>
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th style="width:240px;">Uploaded Taluk Name</th>
                            <th style="width:180px;">District in File</th>
                            <th style="width:110px;">Rows Affected</th>
                            <th>Map to Master Taluk</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $tIdx = 1;
                        $savedTalukRemaps = $_SESSION['import_remaps']['taluks'] ?? [];
                        foreach ($remapStep['taluks'] as $normKey => $info):
                            $suggestedTid = $savedTalukRemaps[$normKey] ?? suggestTaluk($info['raw'], $info['district_id'], $taluks, $tByDistrict);
                        ?>
                        <tr>
                            <td><?= $tIdx++ ?></td>
                            <td>
                                <strong><?= Sanitize::html($info['raw']) ?></strong>
                                <?php if ($suggestedTid && isset($tById[$suggestedTid])): ?>
                                    <span class="badge badge-suggest" style="margin-left:6px;">Suggested: <?= Sanitize::html($tById[$suggestedTid]['name']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= Sanitize::html($info['district_raw'] ?: '—') ?></td>
                            <td><?= (int)$info['count'] ?></td>
                            <td>
                                <select name="remap_taluk[<?= Sanitize::html($normKey) ?>]" required style="max-width:380px;">
                                    <option value="">-- Select Master Taluk --</option>
                                    <?php foreach ($districts as $d): ?>
                                        <?php if (!empty($tByDistrict[$d['id']])): ?>
                                            <optgroup label="<?= Sanitize::html($d['name']) ?>">
                                                <?php foreach ($tByDistrict[$d['id']] as $t): ?>
                                                    <option value="<?= $t['id'] ?>" <?= ($suggestedTid === (int)$t['id']) ? 'selected' : '' ?>>
                                                        <?= Sanitize::html($t['name']) ?> (<?= Sanitize::html($d['name']) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($remapStep['gps'])): ?>
            <div style="margin-bottom:28px;">
                <h3>🏡 Unmatched Gram Panchayats (<?= count($remapStep['gps']) ?>)</h3>
                <p style="color:#64748b; font-size:0.82rem; margin:0 0 10px;">
                    Working GP is optional. You can map to the master GP, or choose <em>"-- Leave Blank / Skip GP --"</em> if unknown.
                </p>
                <table>
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th style="width:240px;">Uploaded GP Name</th>
                            <th style="width:180px;">Taluk in File</th>
                            <th style="width:110px;">Rows Affected</th>
                            <th>Map to Master Gram Panchayat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $gIdx = 1;
                        $savedGpRemaps = $_SESSION['import_remaps']['gps'] ?? [];
                        foreach ($remapStep['gps'] as $normKey => $info):
                            $suggestedGid = $savedGpRemaps[$normKey] ?? suggestGp($info['raw'], $info['taluk_id'], $gpByTaluk);
                        ?>
                        <tr>
                            <td><?= $gIdx++ ?></td>
                            <td>
                                <strong><?= Sanitize::html($info['raw']) ?></strong>
                                <?php if ($suggestedGid && isset($gpsById[$suggestedGid])): ?>
                                    <span class="badge badge-suggest" style="margin-left:6px;">Suggested: <?= Sanitize::html($gpsById[$suggestedGid]['name']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= Sanitize::html($info['taluk_raw'] ?: '—') ?></td>
                            <td><?= (int)$info['count'] ?></td>
                            <td>
                                <select name="remap_gp[<?= Sanitize::html($normKey) ?>]" style="max-width:380px;">
                                    <option value="skip">-- Leave Blank / Skip GP (Optional) --</option>
                                    <?php if (!empty($info['taluk_id']) && !empty($gpByTaluk[$info['taluk_id']])): ?>
                                        <optgroup label="GPs under <?= Sanitize::html($tById[$info['taluk_id']]['name'] ?? 'Taluk') ?>">
                                            <?php foreach ($gpByTaluk[$info['taluk_id']] as $g): ?>
                                                <option value="<?= $g['id'] ?>" <?= ($suggestedGid === (int)$g['id']) ? 'selected' : '' ?>>
                                                    <?= Sanitize::html($g['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endif; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div style="margin-top:24px; display:flex; gap:12px; align-items:center;">
                <button type="submit" class="btn" style="background:#1e6b3a; padding:10px 24px;">
                    Apply Location Mappings &amp; Preview Import →
                </button>
                <button type="submit" name="action" value="cancel" class="btn" style="background:#64748b;" formnovalidate>
                    Cancel Import
                </button>
            </div>
        </form>
    </div>

    <?php elseif ($previewData): ?>
    <!-- ── Step 3: Preview & Confirm Import ──────────────────────────────────── -->
    <div class="workflow-bar">
        <span class="workflow-step completed">✓ 1. Upload File</span>
        <span class="workflow-sep">→</span>
        <span class="workflow-step completed">✓ 2. Locations Matched</span>
        <span class="workflow-sep">→</span>
        <span class="workflow-step active">3. Preview &amp; Confirm Import</span>
        <span class="workflow-sep">→</span>
        <span class="workflow-step">4. Import Complete</span>
    </div>

    <div class="panel">
        <h2>Preview &amp; Confirm Import</h2>
        <p style="font-size:0.95rem; line-height:1.6;">
            <strong><?= count($previewData['valid']) ?></strong> valid row(s) ready to import.<br>
            <?php if (!empty($previewData['invalid'])): ?>
                <strong style="color:#a12622;"><?= count($previewData['invalid']) ?></strong> row(s) contain validation errors and will be skipped.
            <?php endif; ?>
        </p>

        <?php if (!empty($previewData['valid'])): ?>
        <h3 style="color:#1e6b3a;">✓ Valid Rows Ready for Import (<?= count($previewData['valid']) ?>)</h3>
        <p style="color:#64748b; font-size:0.8rem; margin:0 0 10px;">All registration columns verified against master data:</p>
        <div style="overflow-x:auto;">
        <table style="font-size:0.78rem; white-space:nowrap;">
            <thead><tr>
                <th>#</th>
                <th>Full Name</th>
                <th>Father / Husband Name</th>
                <th>Gender</th>
                <th>Phone</th>
                <th>Email</th>
                <th>KGID No.</th>
                <th>Date of Birth</th>
                <th>GP Working?</th>
                <th>Org Type</th>
                <th>Org Name</th>
                <th>Org Address</th>
                <th>Working District</th>
                <th>Working Taluk</th>
                <th>Working GP</th>
                <th>Membership District</th>
                <th>Membership Taluk</th>
                <th>Payment Mode</th>
                <th>Payment ID / Ref</th>
                <th>Payment Date &amp; Time</th>
                <th>Received Amount</th>
                <th>Remarks</th>
            </tr></thead>
            <tbody>
            <?php $i=1; foreach ($previewData['valid'] as $row): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><strong><?= Sanitize::html($row['full_name']) ?></strong></td>
                <td><?= Sanitize::html($row['father_spouse_name'] ?: '—') ?></td>
                <td><?= Sanitize::html($row['gender'] ? ucfirst($row['gender']) : '— (Not Specified)') ?></td>
                <td><?= Sanitize::html($row['phone']) ?></td>
                <td><?= Sanitize::html($row['email']) ?></td>
                <td><code><?= Sanitize::html($row['kgid_no']) ?></code></td>
                <td><?= Sanitize::html(!empty($row['dob']) ? date('d-m-Y', strtotime($row['dob'])) : '—') ?></td>
                <td><?= Sanitize::html(strtoupper($row['gp_working'] ?: '—')) ?></td>
                <td><?= Sanitize::html($row['organization_type'] ?: '—') ?></td>
                <td><?= Sanitize::html($row['organization_name'] ?: '—') ?></td>
                <td><?= Sanitize::html($row['organization_address'] ?: '—') ?></td>
                <td><?= Sanitize::html($districtNameMap[$row['working_district_id'] ?? 0] ?? '—') ?></td>
                <td><?= Sanitize::html($talukNameMap[$row['working_taluk_id'] ?? 0] ?? '—') ?></td>
                <td><?= Sanitize::html($gpNameMap[$row['working_gp_id'] ?? 0] ?? '— (Not Specified)') ?></td>
                <td><?= Sanitize::html($districtNameMap[$row['membership_district_id'] ?? 0] ?? '—') ?></td>
                <td><?= Sanitize::html($talukNameMap[$row['membership_taluk_id'] ?? 0] ?? '—') ?></td>
                <td>
                    <?php if ($row['payment_mode'] === 'online'): ?>
                        <span class="badge" style="background:#e0f2fe; color:#0369a1;">Razorpay</span>
                    <?php elseif ($row['payment_mode'] === 'offline'): ?>
                        <span class="badge" style="background:#fef3c7; color:#92400e;">Offline</span>
                    <?php else: ?>
                        <span style="color:#94a3b8;">Unpaid</span>
                    <?php endif; ?>
                </td>
                <td><?= Sanitize::html($row['payment_reference'] ?: '—') ?></td>
                <td><?= Sanitize::html(!empty($row['payment_date']) ? date('d-m-Y H:i', strtotime($row['payment_date'])) : '—') ?></td>
                <td><?= Sanitize::html(!empty($row['received_amount']) ? '₹ ' . number_format((float)$row['received_amount'], 2) : '—') ?></td>
                <td><?= Sanitize::html($row['offline_remarks'] ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($previewData['invalid'])): 
            $actualErrors = [];
            $alreadyPaidRows = [];
            foreach ($previewData['invalid'] as $row) {
                $errs = $row['_errors'] ?? [];
                $isOnlyPaid = true;
                foreach ($errs as $e) {
                    if (!str_contains($e, 'already PAID for this FY')) {
                        $isOnlyPaid = false;
                        break;
                    }
                }
                if ($isOnlyPaid) {
                    $alreadyPaidRows[] = $row;
                } else {
                    $actualErrors[] = $row;
                }
            }
        ?>

        <?php if (!empty($actualErrors)): ?>
        <div style="background:#fef2f2; border:2px solid #ef4444; border-radius:10px; padding:20px 24px; margin:28px 0; box-shadow:0 4px 12px rgba(239,68,68,0.08);">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
                <div>
                    <h3 style="color:#991b1b; margin:0; font-size:1.15rem; display:flex; align-items:center; gap:8px;">
                        <span>⚠️</span>
                        <span><?= count($actualErrors) ?> Member Rows Need Correction (To Import)</span>
                    </h3>
                    <p style="color:#7f1d1d; margin:6px 0 0; font-size:0.88rem; line-height:1.5;">
                        These <?= count($actualErrors) ?> rows were skipped due to data validation errors (e.g. invalid phone number, missing KGID, or date format).<br>
                        Review the reasons below, or download the pre-filled CSV to fix and re-upload them.
                    </p>
                </div>
                <a href="/admin/members-import.php?action=download_errors" class="btn" style="background:#b91c1c; color:#fff; text-decoration:none; padding:10px 18px; font-weight:700; border-radius:6px; font-size:0.9rem;">
                    ⬇ Download <?= count($actualErrors) ?> Skipped Rows (CSV)
                </a>
            </div>

            <div style="overflow-x:auto; margin-top:16px; background:#fff; border-radius:6px; border:1px solid #fca5a5;">
            <table>
                <thead>
                    <tr style="background:#991b1b; color:#fff;">
                        <th style="width:40px; color:#fff;">#</th>
                        <th style="width:180px; color:#fff;">Full Name</th>
                        <th style="width:120px; color:#fff;">KGID</th>
                        <th style="width:120px; color:#fff;">Phone</th>
                        <th style="width:180px; color:#fff;">Email</th>
                        <th style="color:#fff;">Reason Why Skipped (Fix This)</th>
                    </tr>
                </thead>
                <tbody>
                <?php $i=1; foreach ($actualErrors as $row): 
                    $cleanErrors = array_filter($row['_errors'] ?? [], fn($e) => !str_contains($e, 'already PAID for this FY'));
                ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><strong><?= Sanitize::html($row['full_name'] ?: '(blank)') ?></strong></td>
                    <td><code><?= Sanitize::html($row['kgid_no'] ?: '—') ?></code></td>
                    <td><?= Sanitize::html($row['phone'] ?: '—') ?></td>
                    <td><?= Sanitize::html($row['email'] ?: '—') ?></td>
                    <td class="err-cell" style="font-weight:700; color:#b91c1c;"><?= Sanitize::html(implode('; ', $cleanErrors)) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($alreadyPaidRows)): ?>
        <details style="margin:20px 0; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px;">
            <summary style="cursor:pointer; font-weight:600; color:#475569; font-size:0.9rem;">
                ✓ <?= count($alreadyPaidRows) ?> Members Already Recorded in Database (Skipped from re-importing) — Click to view
            </summary>
            <div style="overflow-x:auto; margin-top:12px;">
            <table>
                <thead><tr><th style="width:40px;">#</th><th style="width:200px;">Full Name</th><th style="width:140px;">KGID</th><th>Status</th></tr></thead>
                <tbody>
                <?php $i=1; foreach ($alreadyPaidRows as $row): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= Sanitize::html($row['full_name'] ?: '(blank)') ?></td>
                    <td><code><?= Sanitize::html($row['kgid_no'] ?: '—') ?></code></td>
                    <td style="color:#166534; font-size:0.8rem; font-weight:600;">Already registered &amp; paid for this Financial Year</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </details>
        <?php endif; ?>

        <?php endif; ?>

        <!-- Real-time Progress Bar Card -->
        <div id="import-progress-card" class="panel" style="display:none; margin-top:20px; border:2px solid #1e40af; background:#f8fafc; border-radius:10px; padding:20px 24px; box-shadow:0 4px 12px rgba(0,0,0,0.06);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <h3 id="progress-heading" style="margin:0; color:#1e40af; font-size:1.15rem; display:flex; align-items:center; gap:10px;">
                    <span id="progress-spinner" style="display:inline-block; animation:spin 1s linear infinite; font-size:1.2rem;">⏳</span>
                    <span>Importing Members...</span>
                </h3>
                <span id="progress-badge" class="badge" style="background:#dbeafe; color:#1e40af; font-size:0.85rem; padding:4px 12px; border-radius:20px; font-weight:700;">
                    In Progress
                </span>
            </div>

            <div style="margin:14px 0 8px; display:flex; justify-content:space-between; align-items:baseline;">
                <div style="font-size:1.05rem; font-weight:700; color:#1e293b;">
                    Uploaded <span id="progress-current" style="color:#1e40af;">0</span> of <span id="progress-total"><?= count($previewData['valid'] ?? []) ?></span> members
                </div>
                <div id="progress-percent" style="font-size:1.15rem; font-weight:800; color:#1e40af;">
                    0%
                </div>
            </div>

            <!-- Progress Bar Track -->
            <div style="width:100%; height:24px; background:#e2e8f0; border-radius:12px; overflow:hidden; position:relative; box-shadow:inset 0 1px 3px rgba(0,0,0,0.12);">
                <div id="progress-bar-fill" style="width:0%; height:100%; background:linear-gradient(90deg, #1e40af 0%, #0284c7 60%, #16a34a 100%); transition:width 0.25s ease; border-radius:12px;"></div>
            </div>

            <!-- Stats grid -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:12px; margin-top:16px; padding:12px 14px; background:#fff; border-radius:8px; border:1px solid #e2e8f0;">
                <div>
                    <div style="font-size:0.75rem; color:#64748b; font-weight:600; text-transform:uppercase;">New Members Added</div>
                    <div id="stat-new" style="font-size:1.2rem; font-weight:800; color:#15803d; margin-top:2px;">0</div>
                </div>
                <div>
                    <div style="font-size:0.75rem; color:#64748b; font-weight:600; text-transform:uppercase;">Paid / Activated</div>
                    <div id="stat-paid" style="font-size:1.2rem; font-weight:800; color:#0284c7; margin-top:2px;">0</div>
                </div>
                <div>
                    <div style="font-size:0.75rem; color:#64748b; font-weight:600; text-transform:uppercase;">Errors / Skipped</div>
                    <div id="stat-errors" style="font-size:1.2rem; font-weight:800; color:#b91c1c; margin-top:2px;">0</div>
                </div>
            </div>

            <div id="progress-status-msg" style="margin-top:12px; font-size:0.85rem; color:#64748b; font-style:italic;">
                Starting upload batch...
            </div>
            
            <div id="progress-done-action" style="display:none; margin-top:18px;">
                <a href="/admin/members.php" class="btn" style="background:#16a34a; padding:10px 24px; font-size:0.95rem;">
                    ✓ View Members List
                </a>
            </div>
        </div>

        <form id="import-confirm-form" method="post" style="margin-top:24px; display:flex; gap:12px; align-items:center;">
            <?= CSRF::htmlField() ?>
            <button type="submit" name="action" value="commit" id="btn-start-import" onclick="return startBatchImport(event);" class="btn" style="background:#1e6b3a; padding:10px 24px;" <?= empty($previewData['valid']) ? 'disabled' : '' ?>>
                Confirm &amp; Import <?= count($previewData['valid']) ?> Rows
            </button>
            <?php if (!empty($_SESSION['import_unmatched'])): ?>
                <button type="submit" name="action" value="reopen_remap" class="btn" style="background:#2C6B67;">
                    ← Adjust Location Mappings
                </button>
            <?php endif; ?>
            <button type="submit" name="action" value="cancel" class="btn" style="background:#64748b;">
                Cancel
            </button>
        </form>
    </div>

    <?php else: ?>
    <!-- ── Step 1: Upload File ──────────────────────────────────────────────── -->
    <div class="workflow-bar">
        <span class="workflow-step active">1. Upload File</span>
        <span class="workflow-sep">→</span>
        <span class="workflow-step">2. Remap Locations (if needed)</span>
        <span class="workflow-sep">→</span>
        <span class="workflow-step">3. Preview &amp; Confirm</span>
        <span class="workflow-sep">→</span>
        <span class="workflow-step">4. Import Complete</span>
    </div>

    <div class="panel">
        <h2>Bulk Import Members</h2>

        <p style="color:#556; line-height:1.7;">
            Upload a <strong>CSV</strong> or <strong>Excel (.xlsx)</strong> file containing member registration data.<br>
            <strong>Row 1 must be the header row</strong> (column names — ignored during import).<br>
            Location names (District, Taluk, Gram Panchayat) are automatically matched against official <strong>GP Master Data</strong>. If any District or Taluk is unrecognized, you can easily <strong>remap it before import</strong>.<br>
            Duplicate rule: <code>KGID + Financial Year</code> only. Name, phone, and email are not duplicate keys.<br>
            Membership Number is auto-generated on verified activation — do NOT include it in the file.<br>
            <a href="/admin/members-import-template.php" class="btn" style="background:#2C6B67; padding:6px 14px; font-size:0.85rem; display:inline-block; margin-top:8px;">⬇ Download Template (CSV)</a>
        </p>

        <div style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; padding:12px 16px; border-radius:8px; margin:16px 0; font-size:0.88rem; line-height:1.6;">
            <strong>💳 Legacy Razorpay Payments (1600+ members):</strong><br>
            If importing members who paid before this website via the Razorpay Payment Page, provide the <strong>Payment Reference / ID</strong> (e.g. <code>pay_...</code>), <strong>Payment Date &amp; Time</strong>, and <strong>Received Amount</strong> (e.g. <code>500</code>).<br>
            The system will automatically record the payment as completed, assign the official permanent Membership Number, activate their login account, and generate their official payment receipt.<br>
            <em>Note:</em> <strong>Gender</strong> and <strong>Gram Panchayat Name</strong> are completely optional — members can update them later in their profile.
        </div>

        <form method="post" enctype="multipart/form-data" style="margin-top:20px;">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="upload">

            <div style="margin-bottom:16px;">
                <label>Financial Year for this import</label>
                <select name="import_fy_id" style="max-width:280px;">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y['id'] ?>" <?= $y['id'] == $importFyId ? 'selected' : '' ?>>
                            <?= Sanitize::html($y['financial_year']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="drop-zone">
                <p style="color:#556; margin:0 0 14px; font-size:0.95rem;">Select a CSV or Excel (.xlsx) file</p>
                <input type="file" name="import_file" accept=".csv,.xlsx,.xls" required style="border:none; background:transparent; width:auto;">
                <br><br>
                <button type="submit" class="btn" style="padding:10px 24px;">Analyse &amp; Check File</button>
            </div>
        </form>

        <div style="margin-top:28px; padding:16px; background:#f0f5ff; border-radius:8px; font-size:0.85rem; color:#33415c;">
            <strong>Expected Columns (in this order, or matching column header names):</strong><br><br>
            <table style="font-size:0.82rem; width:auto;">
                <thead><tr><th style="padding:4px 12px 4px 0;">#</th><th style="padding:4px 12px 4px 0;">Column</th><th style="padding:4px 0;">Required?</th></tr></thead>
                <tbody>
                <?php
                $cols = [
                    ['Full Name',              'Yes'],
                    ['Father / Husband Name',  'Yes'],
                    ['Gender',                 'Optional (male / female / other / blank)'],
                    ['Phone',                  'Yes (10-digit, with or without +91)'],
                    ['Email',                  'Yes'],
                    ['KGID No.',               'Yes (Numeric digits only)'],
                    ['Date of Birth',          'Yes (DD-MM-YYYY or DD/MM/YYYY)'],
                    ['GP Working?',            'Yes (yes/no)'],
                    ['Organization Type',      'When GP=no'],
                    ['Organization Name',      'When GP=no'],
                    ['Organization Address',   'Optional'],
                    ['Working District',       'When GP=yes or ZP/TP org'],
                    ['Working Taluk',          'When GP=yes or ZP/TP org'],
                    ['Working GP',             'Optional (leave blank if unknown)'],
                    ['Membership District',    'When GP=no + other org'],
                    ['Membership Taluk',       'When GP=no + other org'],
                    ['Payment Mode',           'Optional (razorpay / online / offline / blank)'],
                    ['Payment Reference',      'Optional (Razorpay Payment ID pay_... or offline ref)'],
                    ['Payment Date & Time',    'Optional (e.g. DD-MM-YYYY HH:MM:SS or YYYY-MM-DD)'],
                    ['Received Amount',        'Optional (fee amount received, e.g. 500)'],
                    ['Payment Remarks',        'Optional'],
                ];
                foreach ($cols as $i => [$name, $req]):
                ?>
                <tr>
                    <td style="padding:3px 12px 3px 0; color:#888;"><?= $i+1 ?></td>
                    <td style="padding:3px 12px 3px 0;"><strong><?= htmlspecialchars($name) ?></strong></td>
                    <td style="padding:3px 0; color:#556;"><?= htmlspecialchars($req) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

<style>
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<script>
window.startBatchImport = function(e) {
    if (e && e.preventDefault) {
        e.preventDefault();
    }

    var totalRows = <?= (int)count($previewData['valid'] ?? []) ?>;
    var csrfToken = <?= json_encode(CSRF::getToken()) ?>;

    if (totalRows <= 0) {
        return true;
    }

    var progressCard = document.getElementById('import-progress-card');
    var btnStart     = document.getElementById('btn-start-import');
    var confirmForm  = document.getElementById('import-confirm-form');
    var progressBar  = document.getElementById('progress-bar-fill');
    var txtCurrent   = document.getElementById('progress-current');
    var txtTotal     = document.getElementById('progress-total');
    var txtPercent   = document.getElementById('progress-percent');
    var statNew      = document.getElementById('stat-new');
    var statPaid     = document.getElementById('stat-paid');
    var statErrors   = document.getElementById('stat-errors');
    var statusMsg    = document.getElementById('progress-status-msg');
    var progressHeading = document.getElementById('progress-heading');
    var progressSpinner = document.getElementById('progress-spinner');
    var progressBadge   = document.getElementById('progress-badge');
    var doneAction      = document.getElementById('progress-done-action');

    if (progressCard) {
        progressCard.style.display = 'block';
        progressCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    if (btnStart) {
        btnStart.disabled = true;
        btnStart.style.opacity = '0.5';
        btnStart.style.cursor = 'not-allowed';
    }

    if (confirmForm) {
        Array.from(confirmForm.querySelectorAll('button')).forEach(function(b) {
            b.disabled = true;
        });
    }

    if (txtTotal) txtTotal.textContent = totalRows;
    if (txtCurrent) txtCurrent.textContent = '0';
    if (txtPercent) txtPercent.textContent = '0%';

    var offset = 0;
    var chunkSize = 50;

    function runNextChunk() {
        var batchStart = offset + 1;
        var batchEnd = Math.min(offset + chunkSize, totalRows);
        if (statusMsg) {
            statusMsg.textContent = 'Processing members ' + batchStart + ' to ' + batchEnd + ' of ' + totalRows + '...';
        }

        var formData = new FormData();
        formData.append('action', 'commit_chunk');
        formData.append('csrf_token', csrfToken);
        formData.append('offset', offset);
        formData.append('chunk_size', chunkSize);

        fetch('/admin/members-import.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(res) {
            if (!res.ok) throw new Error('Server returned HTTP ' + res.status);
            return res.json();
        })
        .then(function(data) {
            if (!data.success) {
                throw new Error(data.error || 'Server error occurred during chunk import');
            }

            var current = Math.min(data.offset, totalRows);
            var percent = Math.min(100, Math.round((current / totalRows) * 100));

            if (txtCurrent) txtCurrent.textContent = current;
            if (txtPercent) txtPercent.textContent = percent + '%';
            if (progressBar) progressBar.style.width = percent + '%';

            if (data.new_members !== undefined && statNew) statNew.textContent = data.new_members;
            if (data.paid !== undefined && statPaid) statPaid.textContent = data.paid;
            if (data.errors !== undefined && statErrors) statErrors.textContent = data.errors;

            if (data.done || current >= totalRows) {
                if (progressBar) progressBar.style.width = '100%';
                if (txtCurrent) txtCurrent.textContent = totalRows;
                if (txtPercent) txtPercent.textContent = '100%';
                if (progressSpinner) {
                    progressSpinner.textContent = '✓';
                    progressSpinner.style.animation = 'none';
                }
                if (progressHeading) {
                    progressHeading.innerHTML = '<span style="color:#16a34a; font-size:1.3rem;">✓</span> Import Complete!';
                }
                if (progressBadge) {
                    progressBadge.style.background = '#dcfce7';
                    progressBadge.style.color = '#15803d';
                    progressBadge.textContent = 'Completed';
                }
                if (statusMsg) {
                    statusMsg.textContent = 'All ' + totalRows + ' rows processed successfully! Redirecting to members list...';
                }
                if (doneAction) doneAction.style.display = 'block';

                setTimeout(function() {
                    window.location.href = '/admin/members.php';
                }, 2000);
            } else {
                offset = data.offset;
                runNextChunk();
            }
        })
        .catch(function(err) {
            console.error('Import error:', err);
            if (statusMsg) {
                statusMsg.style.color = '#b91c1c';
                statusMsg.innerHTML = '<strong>Error during import:</strong> ' + err.message + '. <a href="javascript:void(0)" onclick="location.reload()" style="color:#1e40af; text-decoration:underline;">Reload to resume</a>';
            }
            if (progressSpinner) {
                progressSpinner.textContent = '⚠️';
                progressSpinner.style.animation = 'none';
            }
            if (progressBadge) {
                progressBadge.style.background = '#fee2e2';
                progressBadge.style.color = '#b91c1c';
                progressBadge.textContent = 'Halted';
            }
        });
    }

    runNextChunk();
    return false;
};
</script>

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';

