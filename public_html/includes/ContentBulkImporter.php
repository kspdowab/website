<?php
/**
 * KSPDOWA — Content Bulk Importer (Orders, Circulars, Documents)
 * ============================================================
 * Handles CSV parsing, ZIP package extraction, file validation,
 * document record creation, and bulk database insertion.
 * ============================================================
 */

declare(strict_types=1);

class ContentBulkImporter
{
    /**
     * Mandatory categories for Orders and Circulars per Phase 4 specification.
     */
    public const MANDATORY_CATEGORIES = [
        '16th Finance',
        'VB-G RAM G',
        'eSwathu',
        'eGramSwaraj',
        'GP Staff',
        'Act/Rules',
        'OSR',
        'SC/ST',
        'PH',
    ];

    public const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];

    public const ALLOWED_MIMES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg',
        'image/png',
    ];

    /**
     * Send sample CSV template as download to browser.
     */
    public static function downloadSample(string $type): void
    {
        ob_clean();
        $dateStr = date('Ymd');

        if ($type === 'orders') {
            $filename = "KSPDOWA_Orders_Sample_Template_{$dateStr}.csv";
            $headers = ['Category', 'Order Number', 'Subject', 'Order Date', 'Department', 'Access Level', 'Description', 'File Name'];
            $rows = [
                ['16th Finance', 'RDPR-16FC-2026-001', 'Implementation Guidelines for 16th Finance Commission Grants', '15-01-2026', 'Rural Development and Panchayat Raj Department', 'member', 'Guidelines for release and utilization of 16th Finance Commission grants at GP level', '16fc_guidelines.pdf'],
                ['eSwathu', 'ESWATHU-2026-042', 'Revised eSwathu Property Verification Workflow', '10-02-2026', 'Rural Development and Panchayat Raj Department', 'member', 'Standard Operating Procedure for eSwathu verification and property mutation', 'eswathu_sop.pdf'],
                ['Act/Rules', 'GO-RDPR-2026-108', 'Amendments to Karnataka Panchayat Raj Rules 2026', '01-03-2026', 'Rural Development and Panchayat Raj Department', 'public', 'Government order regarding revised service conditions and welfare rules', 'go_rdpr_108.pdf'],
                ['GP Staff', 'RDPR-GPS-2026-019', 'Guidelines for Gram Panchayati Administrative Cadre Review', '18-03-2026', 'Rural Development and Panchayat Raj Department', 'member', 'Clarification on staffing patterns and promotions', ''],
            ];
        } elseif ($type === 'circulars') {
            $filename = "KSPDOWA_Circulars_Sample_Template_{$dateStr}.csv";
            $headers = ['Category', 'Circular Number', 'Subject', 'Circular Date', 'Department', 'Access Level', 'Description', 'File Name'];
            $rows = [
                ['GP Staff', 'CIR-RDPR-2026-015', 'Staff Attendance and Biometric Reporting Instructions', '20-01-2026', 'Rural Development and Panchayat Raj Department', 'member', 'Instructions regarding mandatory attendance capture in Gram Panchayatis', 'staff_attendance_circ.pdf'],
                ['OSR', 'CIR-OSR-2026-003', 'Own Source Revenue (OSR) Collection Drive Target 2025-26', '05-02-2026', 'Rural Development and Panchayat Raj Department', 'member', 'Quarterly revenue collection targets and monitoring protocol', 'osr_drive_targets.pdf'],
                ['eGramSwaraj', 'CIR-EGS-2026-088', 'eGramSwaraj - PFMS Integration and Online Vouchers', '12-03-2026', 'Rural Development and Panchayat Raj Department', 'member', 'Guidelines for digital payments and voucher closing on eGramSwaraj portal', 'egramswaraj_pfms.pdf'],
                ['VB-G RAM G', 'CIR-VBG-2026-021', 'Village Beautification and Model GP Guidelines', '22-03-2026', 'Rural Development and Panchayat Raj Department', 'member', 'Implementation timeline and reporting format', ''],
            ];
        } elseif ($type === 'documents') {
            $filename = "KSPDOWA_Documents_Sample_Template_{$dateStr}.csv";
            $headers = ['Category', 'Title', 'Description', 'Access Level', 'File Name'];
            $rows = [
                ['Official Documents', 'KSPDOWA Service Bye-Laws 2026', 'Approved service association bye-laws and official regulations', 'public', 'kspdowa_byelaws_2026.pdf'],
                ['Meeting Proceedings', 'State Executive Committee Minutes - January 2026', 'Minutes of meeting held at Bangalore Central Office', 'member', 'state_exec_minutes_jan2026.pdf'],
                ['Forms & Formats', 'Member Welfare Assistance Claim Form', 'Official application format for emergency medical assistance scheme', 'member', 'welfare_claim_form.pdf'],
            ];
        } else {
            http_response_code(400);
            exit('Invalid sample template type.');
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');

        // Output UTF-8 BOM for Microsoft Excel compatibility
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');
        fputcsv($out, $headers, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

    /**
     * Parse uploaded file (CSV or ZIP) and gather any attached documents.
     *
     * @return array{csv_path: ?string, rows: array, temp_dir: ?string, files_map: array, error: ?string}
     */
    public static function extractUpload(array $mainFile, ?array $additionalFiles = null): array
    {
        $result = [
            'csv_path'  => null,
            'rows'      => [],
            'temp_dir'  => null,
            'files_map' => [],
            'error'     => null,
        ];

        if (!isset($mainFile['tmp_name']) || $mainFile['error'] !== UPLOAD_ERR_OK) {
            $result['error'] = 'No valid file uploaded or upload error occurred.';
            return $result;
        }

        $origName = (string)$mainFile['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        // Index any additional files uploaded in the same form
        if ($additionalFiles && !empty($additionalFiles['name'])) {
            $numFiles = is_array($additionalFiles['name']) ? count($additionalFiles['name']) : 0;
            for ($i = 0; $i < $numFiles; $i++) {
                if (($additionalFiles['error'][$i] ?? 1) === UPLOAD_ERR_OK) {
                    $attName = (string)$additionalFiles['name'][$i];
                    $attTmp  = (string)$additionalFiles['tmp_name'][$i];
                    $cleanKey = strtolower(trim($attName));
                    $result['files_map'][$cleanKey] = $attTmp;
                    // Also key without extension
                    $noExt = strtolower(pathinfo($attName, PATHINFO_FILENAME));
                    $result['files_map'][$noExt] = $attTmp;
                }
            }
        }

        if ($ext === 'csv' || $ext === 'txt') {
            $parsed = self::parseCsv($mainFile['tmp_name']);
            $result['csv_path'] = $mainFile['tmp_name'];
            $result['rows']     = $parsed;
            return $result;
        }

        if ($ext === 'zip') {
            if (!class_exists('ZipArchive')) {
                $result['error'] = 'ZipArchive PHP extension is not available on this server.';
                return $result;
            }

            $zip = new ZipArchive();
            if ($zip->open($mainFile['tmp_name']) !== true) {
                $result['error'] = 'Could not open ZIP archive. File may be corrupted.';
                return $result;
            }

            $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kspdowa_bulk_' . bin2hex(random_bytes(8));
            if (!mkdir($tempDir, 0755, true)) {
                $zip->close();
                $result['error'] = 'Failed to create temporary directory for ZIP extraction.';
                return $result;
            }

            $zip->extractTo($tempDir);
            $zip->close();
            $result['temp_dir'] = $tempDir;

            // Search for CSV file inside extracted folder
            $csvFile = null;
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->isFile()) {
                    $filename = $file->getFilename();
                    $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $baseName = strtolower(trim($filename));
                    $result['files_map'][$baseName] = $file->getPathname();
                    $noExt = strtolower(pathinfo($filename, PATHINFO_FILENAME));
                    $result['files_map'][$noExt] = $file->getPathname();

                    if ($fileExt === 'csv' && $csvFile === null) {
                        $csvFile = $file->getPathname();
                    }
                }
            }

            if (!$csvFile) {
                $result['error'] = 'No .csv manifest found inside the uploaded ZIP archive.';
                return $result;
            }

            $result['csv_path'] = $csvFile;
            $result['rows']     = self::parseCsv($csvFile);
            return $result;
        }

        $result['error'] = "Unsupported file format (.{$ext}). Please upload a .csv or .zip file.";
        return $result;
    }

    /**
     * Parse CSV into an associative array with normalized column keys.
     */
    public static function parseCsv(string $filePath): array
    {
        $rows = [];
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return [];
        }

        $rawHeader = fgetcsv($handle, 0, ',', '"', '\\');
        if (!$rawHeader) {
            fclose($handle);
            return [];
        }

        // Strip UTF-8 BOM if present on first header
        if (isset($rawHeader[0])) {
            $rawHeader[0] = ltrim($rawHeader[0], "\xEF\xBB\xBF");
        }

        $headers = [];
        foreach ($rawHeader as $col) {
            $clean = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$col));
            $headers[] = $clean;
        }

        $lineNum = 1;
        while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $lineNum++;
            // Skip empty rows
            if (empty(array_filter($data, fn($v) => trim((string)$v) !== ''))) {
                continue;
            }

            $mapped = ['_line' => $lineNum];
            foreach ($headers as $idx => $key) {
                $val = trim((string)($data[$idx] ?? ''));
                $mapped[$key] = $val;
            }

            // Normalization mappings
            $mapped['category']    = $mapped['category'] ?? $mapped['cat'] ?? '';
            $mapped['order_no']    = $mapped['ordernumber'] ?? $mapped['orderno'] ?? $mapped['number'] ?? $mapped['orderno'] ?? '';
            $mapped['circular_no'] = $mapped['circularnumber'] ?? $mapped['circularno'] ?? $mapped['number'] ?? '';
            $mapped['title']       = $mapped['subject'] ?? $mapped['title'] ?? $mapped['subjecttitle'] ?? '';
            $mapped['date']        = $mapped['orderdate'] ?? $mapped['circulardate'] ?? $mapped['date'] ?? '';
            $mapped['department']  = $mapped['department'] ?? $mapped['dept'] ?? '';
            $mapped['access_level']= $mapped['accesslevel'] ?? $mapped['access'] ?? 'member';
            $mapped['description'] = $mapped['description'] ?? $mapped['desc'] ?? $mapped['remarks'] ?? '';
            $mapped['file_name']   = $mapped['filename'] ?? $mapped['file'] ?? $mapped['attachment'] ?? '';

            $rows[] = $mapped;
        }

        fclose($handle);
        return $rows;
    }

    /**
     * Clean up temporary directory extracted during ZIP upload.
     */
    public static function cleanupTempDir(?string $dir): void
    {
        if (!$dir || !is_dir($dir)) {
            return;
        }

        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        @rmdir($dir);
    }

    /**
     * Normalize date into YYYY-MM-DD.
     */
    public static function normalizeDate(string $rawDate): string
    {
        $raw = trim($rawDate);
        if ($raw === '') {
            return date('Y-m-d');
        }

        // YYYY-MM-DD
        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $raw, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }

        // DD-MM-YYYY or DD/MM/YYYY
        if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $raw, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }

        $ts = strtotime($raw);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }

        return date('Y-m-d');
    }

    /**
     * Get or create category in document_categories table.
     */
    public static function getOrCreateCategoryId(string $categoryName): int
    {
        $clean = trim($categoryName);
        if ($clean === '') {
            $clean = 'General';
        }

        $row = Database::fetchOne('SELECT id FROM document_categories WHERE LOWER(name) = LOWER(?)', [$clean]);
        if ($row) {
            return (int)$row['id'];
        }

        Database::execute(
            "INSERT INTO document_categories (name, access_level, status) VALUES (?, 'member', 'active')",
            [$clean]
        );
        return (int)Database::lastInsertId();
    }

    /**
     * Move and register an attached document file.
     *
     * @return int|null Document ID if successfully attached, null otherwise
     */
    public static function attachDocumentFile(
        string $sourceFilePath,
        string $title,
        int $categoryId,
        string $accessLevel,
        string $subDir,
        int $userId,
        string $description = ''
    ): ?int {
        if (!file_exists($sourceFilePath)) {
            return null;
        }

        $ext = strtolower(pathinfo($sourceFilePath, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return null;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($sourceFilePath);
        if ($mime && !in_array($mime, self::ALLOWED_MIMES, true)) {
            return null;
        }

        $targetDir = UPLOADS_DIR . '/' . trim($subDir, '/');
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        $prefix = ($subDir === 'orders') ? 'order_' : (($subDir === 'circulars') ? 'circular_' : 'doc_');
        $safeName = $prefix . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $destPath = $targetDir . '/' . $safeName;

        if (!@copy($sourceFilePath, $destPath)) {
            return null;
        }

        $relPath = trim($subDir, '/') . '/' . $safeName;
        Database::execute(
            "INSERT INTO documents (title, category_id, description, file_path, access_level, published_at, uploaded_by, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), ?, 'active', NOW(), NOW())",
            [$title, $categoryId, $description, $relPath, $accessLevel, $userId]
        );

        return (int)Database::lastInsertId();
    }

    /**
     * Import Orders from parsed upload.
     */
    public static function importOrders(array $parsedData, int $userId): array
    {
        $rows     = $parsedData['rows'] ?? [];
        $filesMap = $parsedData['files_map'] ?? [];

        $result = [
            'total_rows' => count($rows),
            'imported'   => 0,
            'with_files' => 0,
            'skipped'    => 0,
            'errors'     => [],
        ];

        if (empty($rows)) {
            $result['errors'][] = 'The file does not contain any valid data rows.';
            return $result;
        }

        foreach ($rows as $r) {
            $line    = $r['_line'] ?? '?';
            $catName = trim($r['category'] ?? '');
            $orderNo = trim($r['order_no'] ?? '');
            $subject = trim($r['title'] ?? '');
            $date    = self::normalizeDate($r['date'] ?? '');
            $dept    = trim($r['department'] ?? '');
            $access  = in_array($r['access_level'], ['public', 'member', 'officer', 'admin'], true) ? $r['access_level'] : 'member';
            $desc    = trim($r['description'] ?? '');
            $fileRef = trim($r['file_name'] ?? '');

            if ($orderNo === '') {
                $result['skipped']++;
                $result['errors'][] = "Row {$line}: Skipped — Order Number is missing.";
                continue;
            }

            if ($subject === '') {
                $result['skipped']++;
                $result['errors'][] = "Row {$line} [{$orderNo}]: Skipped — Subject / Title is required.";
                continue;
            }

            // Check if duplicate order_no already exists
            $existing = Database::fetchOne('SELECT id FROM orders WHERE order_no = ?', [$orderNo]);
            if ($existing) {
                $result['skipped']++;
                $result['errors'][] = "Row {$line} [{$orderNo}]: Skipped — Order Number already exists in the system.";
                continue;
            }

            // Category resolution: match against mandatory categories or create
            $catId = self::getOrCreateCategoryId($catName !== '' ? $catName : 'Act/Rules');

            // Find matching file if any
            $docId = null;
            $matchedFile = null;
            if ($fileRef !== '') {
                $cleanRef = strtolower($fileRef);
                $matchedFile = $filesMap[$cleanRef] ?? $filesMap[strtolower(pathinfo($cleanRef, PATHINFO_FILENAME))] ?? null;
            }

            // Fallback: match by order number
            if (!$matchedFile && $orderNo !== '') {
                $cleanNo = strtolower($orderNo);
                $matchedFile = $filesMap[$cleanNo] ?? null;
            }

            if ($matchedFile && file_exists($matchedFile)) {
                $docId = self::attachDocumentFile(
                    $matchedFile,
                    $subject,
                    $catId,
                    $access,
                    'orders',
                    $userId,
                    $desc
                );
            }

            try {
                Database::execute(
                    "INSERT INTO orders (title, order_no, order_date, department, description, document_id, access_level, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [$subject, $orderNo, $date, ($dept !== '' ? $dept : $catName), $desc, $docId, $access]
                );

                $orderId = (int)Database::lastInsertId();
                AuditLogger::log('CREATE', 'orders', $orderId, null, ['order_no' => $orderNo, 'bulk_import' => true]);

                $result['imported']++;
                if ($docId !== null) {
                    $result['with_files']++;
                }
            } catch (Throwable $e) {
                $result['skipped']++;
                $result['errors'][] = "Row {$line} [{$orderNo}]: Database error — " . $e->getMessage();
            }
        }

        self::cleanupTempDir($parsedData['temp_dir'] ?? null);
        return $result;
    }

    /**
     * Import Circulars from parsed upload.
     */
    public static function importCirculars(array $parsedData, int $userId): array
    {
        $rows     = $parsedData['rows'] ?? [];
        $filesMap = $parsedData['files_map'] ?? [];

        $result = [
            'total_rows' => count($rows),
            'imported'   => 0,
            'with_files' => 0,
            'skipped'    => 0,
            'errors'     => [],
        ];

        if (empty($rows)) {
            $result['errors'][] = 'The file does not contain any valid data rows.';
            return $result;
        }

        foreach ($rows as $r) {
            $line    = $r['_line'] ?? '?';
            $catName = trim($r['category'] ?? '');
            $circNo  = trim($r['circular_no'] ?? '');
            $subject = trim($r['title'] ?? '');
            $date    = self::normalizeDate($r['date'] ?? '');
            $dept    = trim($r['department'] ?? '');
            $access  = in_array($r['access_level'], ['public', 'member', 'officer', 'admin'], true) ? $r['access_level'] : 'member';
            $desc    = trim($r['description'] ?? '');
            $fileRef = trim($r['file_name'] ?? '');

            if ($circNo === '') {
                $result['skipped']++;
                $result['errors'][] = "Row {$line}: Skipped — Circular Number is missing.";
                continue;
            }

            if ($subject === '') {
                $result['skipped']++;
                $result['errors'][] = "Row {$line} [{$circNo}]: Skipped — Subject / Title is required.";
                continue;
            }

            $existing = Database::fetchOne('SELECT id FROM circulars WHERE circular_no = ?', [$circNo]);
            if ($existing) {
                $result['skipped']++;
                $result['errors'][] = "Row {$line} [{$circNo}]: Skipped — Circular Number already exists.";
                continue;
            }

            $catId = self::getOrCreateCategoryId($catName !== '' ? $catName : 'GP Staff');

            $docId = null;
            $matchedFile = null;
            if ($fileRef !== '') {
                $cleanRef = strtolower($fileRef);
                $matchedFile = $filesMap[$cleanRef] ?? $filesMap[strtolower(pathinfo($cleanRef, PATHINFO_FILENAME))] ?? null;
            }

            if (!$matchedFile && $circNo !== '') {
                $cleanNo = strtolower($circNo);
                $matchedFile = $filesMap[$cleanNo] ?? null;
            }

            if ($matchedFile && file_exists($matchedFile)) {
                $docId = self::attachDocumentFile(
                    $matchedFile,
                    $subject,
                    $catId,
                    $access,
                    'circulars',
                    $userId,
                    $desc
                );
            }

            try {
                Database::execute(
                    "INSERT INTO circulars (title, circular_no, circular_date, department, description, document_id, access_level, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [$subject, $circNo, $date, ($dept !== '' ? $dept : $catName), $desc, $docId, $access]
                );

                $circId = (int)Database::lastInsertId();
                AuditLogger::log('CREATE', 'circulars', $circId, null, ['circular_no' => $circNo, 'bulk_import' => true]);

                $result['imported']++;
                if ($docId !== null) {
                    $result['with_files']++;
                }
            } catch (Throwable $e) {
                $result['skipped']++;
                $result['errors'][] = "Row {$line} [{$circNo}]: Database error — " . $e->getMessage();
            }
        }

        self::cleanupTempDir($parsedData['temp_dir'] ?? null);
        return $result;
    }

    /**
     * Import Documents from parsed upload or direct batch files.
     */
    public static function importDocuments(array $parsedData, int $userId, ?int $defaultCatId = null, string $defaultAccess = 'member'): array
    {
        $rows     = $parsedData['rows'] ?? [];
        $filesMap = $parsedData['files_map'] ?? [];

        $result = [
            'total_rows' => count($rows),
            'imported'   => 0,
            'with_files' => 0,
            'skipped'    => 0,
            'errors'     => [],
        ];

        // If no CSV rows were provided, but files were uploaded directly in bulk:
        if (empty($rows) && !empty($filesMap)) {
            $catId = $defaultCatId ?: self::getOrCreateCategoryId('Official Documents');
            // Distinct file paths to avoid duplicates from double indexing
            $distinctFiles = array_unique(array_values($filesMap));
            $result['total_rows'] = count($distinctFiles);

            foreach ($distinctFiles as $idx => $filePath) {
                $base = basename($filePath);
                $title = ucwords(str_replace(['_', '-'], ' ', pathinfo($base, PATHINFO_FILENAME)));
                $docId = self::attachDocumentFile($filePath, $title, $catId, $defaultAccess, 'documents', $userId);
                if ($docId) {
                    $result['imported']++;
                    $result['with_files']++;
                    AuditLogger::log('UPLOAD', 'documents', $docId, null, ['title' => $title, 'bulk_import' => true]);
                } else {
                    $result['skipped']++;
                    $result['errors'][] = "File '{$base}': Skipped — Invalid format, MIME mismatch, or copy failure.";
                }
            }

            self::cleanupTempDir($parsedData['temp_dir'] ?? null);
            return $result;
        }

        if (empty($rows)) {
            $result['errors'][] = 'No valid records or files were found to import.';
            return $result;
        }

        foreach ($rows as $r) {
            $line    = $r['_line'] ?? '?';
            $title   = trim($r['title'] ?? '');
            $catName = trim($r['category'] ?? '');
            $desc    = trim($r['description'] ?? '');
            $access  = in_array($r['access_level'], ['public', 'member', 'officer', 'admin'], true) ? $r['access_level'] : $defaultAccess;
            $fileRef = trim($r['file_name'] ?? '');

            if ($title === '') {
                $result['skipped']++;
                $result['errors'][] = "Row {$line}: Skipped — Document Title is required.";
                continue;
            }

            $catId = $defaultCatId;
            if ($catName !== '') {
                $catId = self::getOrCreateCategoryId($catName);
            }
            if (!$catId) {
                $catId = self::getOrCreateCategoryId('Official Documents');
            }

            $matchedFile = null;
            if ($fileRef !== '') {
                $cleanRef = strtolower($fileRef);
                $matchedFile = $filesMap[$cleanRef] ?? $filesMap[strtolower(pathinfo($cleanRef, PATHINFO_FILENAME))] ?? null;
            }

            if (!$matchedFile) {
                $result['skipped']++;
                $result['errors'][] = "Row {$line} [{$title}]: Skipped — File '{$fileRef}' not found in the upload.";
                continue;
            }

            $docId = self::attachDocumentFile($matchedFile, $title, $catId, $access, 'documents', $userId, $desc);
            if ($docId) {
                $result['imported']++;
                $result['with_files']++;
                AuditLogger::log('UPLOAD', 'documents', $docId, null, ['title' => $title, 'bulk_import' => true]);
            } else {
                $result['skipped']++;
                $result['errors'][] = "Row {$line} [{$title}]: Skipped — File copy or MIME validation failed.";
            }
        }

        self::cleanupTempDir($parsedData['temp_dir'] ?? null);
        return $result;
    }
}
