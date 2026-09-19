<?php
declare(strict_types=1);

$jsonPath = dirname(__DIR__) . '/docs/gp_master.json';
if (!file_exists($jsonPath)) {
    exit("gp_master.json not found\n");
}

$data = json_decode(file_get_contents($jsonPath), true);
if (!$data) {
    exit("Failed to parse JSON\n");
}

$districts = [];
$taluks = [];
$gps = [];

foreach ($data as $row) {
    $dCode = trim($row['district_code']);
    $dName = trim($row['district']);
    $tCode = trim($row['taluk_code']);
    $tName = trim($row['taluk']);
    $gCode = trim($row['gp_code']);
    $gName = trim($row['gp_name']);

    if (!isset($districts[$dCode])) {
        $districts[$dCode] = $dName;
    }
    if (!isset($taluks[$tCode])) {
        $taluks[$tCode] = [
            'name' => $tName,
            'district_code' => $dCode
        ];
    }
    if (!isset($gps[$gCode])) {
        $gps[$gCode] = [
            'name' => $gName,
            'taluk_code' => $tCode
        ];
    }
}

$out = [];
$out[] = "-- ============================================================";
$out[] = "-- KSPDOWA Seed 006 — Geography Master Data (Districts, Taluks, GPs)";
$out[] = "-- ============================================================";
$out[] = "-- Portable MariaDB / MySQL compatible master data loader";
$out[] = "-- ============================================================\n";

$out[] = "-- Districts";
$out[] = "INSERT IGNORE INTO `districts` (`name`, `code`, `status`) VALUES";
$dRows = [];
foreach ($districts as $code => $name) {
    $escapedName = str_replace("'", "''", $name);
    $dRows[] = "('" . $escapedName . "', '" . $code . "', 'active')";
}
$out[] = implode(",\n", $dRows) . ";\n";

$out[] = "-- Taluks";
$out[] = "CREATE TEMPORARY TABLE IF NOT EXISTS `_tmp_taluks` (";
$out[] = "  `name` VARCHAR(150) NOT NULL,";
$out[] = "  `code` VARCHAR(50) NOT NULL,";
$out[] = "  `district_code` VARCHAR(50) NOT NULL";
$out[] = ") ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n";

$tChunks = array_chunk($taluks, 500, true);
foreach ($tChunks as $chunk) {
    $out[] = "INSERT INTO `_tmp_taluks` (`name`, `code`, `district_code`) VALUES";
    $tRows = [];
    foreach ($chunk as $code => $info) {
        $escapedName = str_replace("'", "''", $info['name']);
        $tRows[] = "('" . $escapedName . "', '" . $code . "', '" . $info['district_code'] . "')";
    }
    $out[] = implode(",\n", $tRows) . ";\n";
}

$out[] = "INSERT IGNORE INTO `taluks` (`district_id`, `name`, `code`, `status`)";
$out[] = "SELECT d.id, t.name, t.code, 'active'";
$out[] = "FROM `_tmp_taluks` t";
$out[] = "JOIN `districts` d ON d.code = t.district_code;\n";
$out[] = "DROP TEMPORARY TABLE IF EXISTS `_tmp_taluks`;\n";

$out[] = "-- Gram Panchayatis";
$out[] = "CREATE TEMPORARY TABLE IF NOT EXISTS `_tmp_gps` (";
$out[] = "  `name` VARCHAR(200) NOT NULL,";
$out[] = "  `code` VARCHAR(50) NOT NULL,";
$out[] = "  `taluk_code` VARCHAR(50) NOT NULL";
$out[] = ") ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n";

$gChunks = array_chunk($gps, 500, true);
foreach ($gChunks as $chunk) {
    $out[] = "INSERT INTO `_tmp_gps` (`name`, `code`, `taluk_code`) VALUES";
    $gRows = [];
    foreach ($chunk as $code => $info) {
        $escapedName = str_replace("'", "''", $info['name']);
        $gRows[] = "('" . $escapedName . "', '" . $code . "', '" . $info['taluk_code'] . "')";
    }
    $out[] = implode(",\n", $gRows) . ";\n";
}

$out[] = "INSERT IGNORE INTO `gram_panchayatis` (`taluk_id`, `name`, `code`, `status`)";
$out[] = "SELECT t.id, g.name, g.code, 'active'";
$out[] = "FROM `_tmp_gps` g";
$out[] = "JOIN `taluks` t ON t.code = g.taluk_code;\n";
$out[] = "DROP TEMPORARY TABLE IF EXISTS `_tmp_gps`;\n";

$targetSql = dirname(__DIR__) . '/seeds/006_geography_master_data.sql';
file_put_contents($targetSql, implode("\n", $out));
echo "Generated " . strlen(implode("\n", $out)) . " bytes to " . $targetSql . "\n";
