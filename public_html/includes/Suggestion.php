<?php
/**
 * KSPDOWA — Members' Suggestions to Association Engine
 * ============================================================
 * Section 28 Specification:
 * - Separate authenticated Member feature: "Members' Suggestions to Association"
 * - Separate workflow, numbering, status lifecycle, and tables.
 * - Distinct from grievances (no grievance IDs, no escalation, no forwarding).
 * - Numbering format: KSPDOWA-SUG-YYYY-NNNNN (configurable via system_settings).
 * - Strict 2 MB file restrictions (.jpg, .png, .pdf with finfo MIME check).
 * - Immutable timeline events (suggestion_events).
 * - Scope-aware RBAC access for officers.
 * - Member isolation: members can ONLY view their own suggestions.
 * ============================================================
 */

declare(strict_types=1);

class Suggestion
{
    // ------------------------------------------------------------------
    // 7 Approved Status Values (§28.4)
    // ------------------------------------------------------------------
    public const STATUS_SUBMITTED           = 'Submitted';
    public const STATUS_UNDER_REVIEW        = 'Under Review';
    public const STATUS_ACCEPTED            = 'Accepted';
    public const STATUS_UNDER_CONSIDERATION = 'Under Consideration';
    public const STATUS_IMPLEMENTED         = 'Implemented';
    public const STATUS_NOT_ACCEPTED        = 'Not Accepted';
    public const STATUS_CLOSED              = 'Closed';

    public const ALL_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_ACCEPTED,
        self::STATUS_UNDER_CONSIDERATION,
        self::STATUS_IMPLEMENTED,
        self::STATUS_NOT_ACCEPTED,
        self::STATUS_CLOSED,
    ];

    /**
     * Map statuses to badge styles for UI display.
     */
    public static function getStatusBadge(string $status): string
    {
        $map = [
            self::STATUS_SUBMITTED           => ['class' => 'badge-info',    'label' => 'Submitted'],
            self::STATUS_UNDER_REVIEW        => ['class' => 'badge-warning', 'label' => 'Under Review'],
            self::STATUS_ACCEPTED            => ['class' => 'badge-success', 'label' => 'Accepted'],
            self::STATUS_UNDER_CONSIDERATION => ['class' => 'badge-purple',  'label' => 'Under Consideration'],
            self::STATUS_IMPLEMENTED         => ['class' => 'badge-primary', 'label' => 'Implemented'],
            self::STATUS_NOT_ACCEPTED        => ['class' => 'badge-danger',  'label' => 'Not Accepted'],
            self::STATUS_CLOSED              => ['class' => 'badge-neutral', 'label' => 'Closed'],
        ];

        $conf = $map[$status] ?? ['class' => 'badge-neutral', 'label' => $status];
        return '<span class="badge ' . htmlspecialchars($conf['class']) . '">' . htmlspecialchars($conf['label']) . '</span>';
    }

    // ------------------------------------------------------------------
    // Suggestion Number Generation: Configurable via system_settings
    // Default: KSPDOWA-SUG-YYYY-NNNNN (§28.3)
    // ------------------------------------------------------------------
    public static function generateSuggestionNo(): string
    {
        $prefixKey = Settings::get('suggestion_no_prefix', 'KSPDOWA-SUG');
        $yearFmt   = Settings::get('suggestion_no_year_format', 'Y');
        $padLen    = (int) Settings::get('suggestion_no_pad_length', '5');
        if ($padLen < 1 || $padLen > 10) {
            $padLen = 5;
        }

        $year = date($yearFmt);
        $likePattern = "{$prefixKey}-{$year}-%";

        $latest = Database::fetchOne(
            "SELECT suggestion_no FROM suggestions WHERE suggestion_no LIKE ? ORDER BY id DESC LIMIT 1",
            [$likePattern]
        );

        $seq = 1;
        if ($latest && preg_match('/-(\d+)$/', (string)$latest['suggestion_no'], $m)) {
            $seq = (int)$m[1] + 1;
        }

        return sprintf("%s-%s-%0{$padLen}d", $prefixKey, $year, $seq);
    }

    // ------------------------------------------------------------------
    // Supporting Document Validation (§28.2)
    // Strict 2 MB, .jpg, .png, .pdf ONLY, finfo MIME verification.
    // ------------------------------------------------------------------
    public static function validateAttachment(?array $file): array
    {
        if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['valid' => true, 'hasFile' => false];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => 'File upload error code: ' . $file['error']];
        }

        $maxBytes = 2 * 1024 * 1024; // Strict 2 MB limit
        if ($file['size'] > $maxBytes) {
            return ['valid' => false, 'error' => 'Supporting document exceeds the maximum permitted size of 2 MB.'];
        }

        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
        if (!in_array($ext, $allowedExtensions, true)) {
            return ['valid' => false, 'error' => 'Invalid file format. Allowed file types are .jpg, .png, and .pdf only. Documents such as DOC, DOCX, XLS, XLSX, ZIP, or EXE are strictly prohibited.'];
        }

        if (!file_exists($file['tmp_name']) || !is_readable($file['tmp_name'])) {
            return ['valid' => false, 'error' => 'Uploaded temporary file could not be read.'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string)$finfo->file($file['tmp_name']);
        $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];

        if (!in_array($mime, $allowedMimes, true)) {
            return ['valid' => false, 'error' => 'Invalid file MIME type (' . htmlspecialchars($mime) . '). Only standard JPEG, PNG, and PDF files are allowed.'];
        }

        $normExt = ($ext === 'jpeg') ? 'jpg' : $ext;
        $safeFilename = 'sug_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $normExt;
        $docType = ($normExt === 'pdf') ? 'PDF Document' : 'Image Attachment';

        return [
            'valid'        => true,
            'hasFile'      => true,
            'tmpPath'      => $file['tmp_name'],
            'safeFilename' => $safeFilename,
            'originalName' => basename((string)$file['name']),
            'fileSize'     => (int)$file['size'],
            'mimeType'     => $mime,
            'docType'      => $docType,
        ];
    }

    /**
     * Save a validated attachment into uploads/suggestions/
     */
    public static function saveAttachment(array $validated): ?string
    {
        if (empty($validated['hasFile']) || empty($validated['safeFilename'])) {
            return null;
        }

        $targetDir = UPLOADS_DIR . '/suggestions';
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0750, true) && !is_dir($targetDir)) {
                throw new RuntimeException('Failed to create suggestions upload directory.');
            }
            // Put a protecting index.html / .htaccess in directory
            file_put_contents($targetDir . '/.htaccess', "Order Deny,Allow\nDeny from all\n");
        }

        $dest = $targetDir . '/' . $validated['safeFilename'];
        if (is_uploaded_file($validated['tmpPath'])) {
            if (!move_uploaded_file($validated['tmpPath'], $dest)) {
                throw new RuntimeException('Failed to save uploaded attachment.');
            }
        } else {
            if (!copy($validated['tmpPath'], $dest)) {
                throw new RuntimeException('Failed to copy attachment file.');
            }
        }

        return 'suggestions/' . $validated['safeFilename'];
    }

    // ------------------------------------------------------------------
    // Member Submission Engine (§28.1, §28.2, §28.3)
    // ------------------------------------------------------------------
    public static function create(
        int $memberId,
        int $userId,
        string $subject,
        string $description,
        ?array $fileUpload = null
    ): array {
        $subject     = trim($subject);
        $description = trim($description);

        if ($subject === '') {
            return ['success' => false, 'error' => 'Subject is required.'];
        }
        if ($description === '') {
            return ['success' => false, 'error' => 'Suggestion description is required.'];
        }

        // Validate attachment
        $val = self::validateAttachment($fileUpload);
        if (!$val['valid']) {
            return ['success' => false, 'error' => $val['error']];
        }

        // Save attachment to disk before transaction
        $savedPath = null;
        if (!empty($val['hasFile'])) {
            try {
                $savedPath = self::saveAttachment($val);
            } catch (Throwable $e) {
                return ['success' => false, 'error' => 'Attachment save failed: ' . $e->getMessage()];
            }
        }

        try {
            $created = Database::transaction(function () use (
                $memberId,
                $userId,
                $subject,
                $description,
                $val,
                $savedPath
            ) {
                $suggestionNo = self::generateSuggestionNo();

                // 1. Insert master suggestion
                Database::execute(
                    "INSERT INTO suggestions
                     (suggestion_no, member_id, subject, description, current_status, submitted_at, last_updated_at)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
                    [$suggestionNo, $memberId, $subject, $description, self::STATUS_SUBMITTED]
                );
                $sugId = (int) Database::lastInsertId();

                // 2. Insert initial timeline event
                Database::execute(
                    "INSERT INTO suggestion_events
                     (suggestion_id, performed_by, event_type, old_status, new_status, remarks, is_member_visible, created_at)
                     VALUES (?, ?, 'SUBMIT', NULL, ?, 'Suggestion submitted by member.', 1, NOW())",
                    [$sugId, $userId, self::STATUS_SUBMITTED]
                );
                $eventId = (int) Database::lastInsertId();

                // 3. Attach document if uploaded
                $docId = null;
                if ($savedPath !== null) {
                    Database::execute(
                        "INSERT INTO suggestion_documents
                         (suggestion_id, event_id, uploaded_by, document_type, file_path, original_filename, file_size, mime_type, uploaded_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                        [
                            $sugId,
                            $eventId,
                            $userId,
                            $val['docType'] ?? 'supporting_document',
                            $savedPath,
                            $val['originalName'],
                            $val['fileSize'] ?? 0,
                            $val['mimeType'] ?? '',
                        ]
                    );
                    $docId = (int) Database::lastInsertId();
                }

                // 4. Audit Log
                AuditLogger::log('SUGGESTION_CREATED', 'suggestions', $sugId, null, [
                    'suggestion_no' => $suggestionNo,
                    'member_id'     => $memberId,
                    'subject'       => $subject,
                    'has_document'  => ($savedPath !== null),
                ]);

                // 5. In-app notification to the member
                self::notify(
                    $userId,
                    'SUGGESTION_SUBMITTED',
                    "Suggestion Submitted ({$suggestionNo})",
                    "Your suggestion '{$subject}' has been submitted to the Association for review.",
                    $sugId
                );

                return [
                    'id'            => $sugId,
                    'suggestion_no' => $suggestionNo,
                    'document_id'   => $docId,
                ];
            });

            return [
                'success'       => true,
                'suggestion_id' => $created['id'],
                'suggestion_no' => $created['suggestion_no'],
            ];
        } catch (Throwable $e) {
            error_log('[SUGGESTION CREATE ERROR] ' . $e->getMessage());
            // Cleanup saved file if transaction failed
            if ($savedPath !== null) {
                @unlink(UPLOADS_DIR . '/' . $savedPath);
            }
            return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }

    // ------------------------------------------------------------------
    // Status Transitions (§28.4, §28.5)
    // ------------------------------------------------------------------
    public static function updateStatus(
        int $suggestionId,
        int $officerUserId,
        string $newStatus,
        ?string $remarks = null,
        bool $isMemberVisible = true
    ): void {
        if (!in_array($newStatus, self::ALL_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid suggestion status: {$newStatus}");
        }

        $sug = Database::fetchOne("SELECT * FROM suggestions WHERE id = ?", [$suggestionId]);
        if (!$sug) {
            throw new RuntimeException("Suggestion #{$suggestionId} not found.");
        }

        $oldStatus = $sug['current_status'];
        $remarksText = trim($remarks ?? '') ?: "Status changed from {$oldStatus} to {$newStatus}.";

        Database::transaction(function () use (
            $suggestionId,
            $officerUserId,
            $oldStatus,
            $newStatus,
            $remarksText,
            $isMemberVisible,
            $sug
        ) {
            $isClosing = ($newStatus === self::STATUS_CLOSED && $oldStatus !== self::STATUS_CLOSED);
            $closedSql = $isClosing ? ", closed_at = NOW()" : "";

            Database::execute(
                "UPDATE suggestions
                 SET current_status = ?, last_updated_at = NOW() {$closedSql}
                 WHERE id = ?",
                [$newStatus, $suggestionId]
            );

            Database::execute(
                "INSERT INTO suggestion_events
                 (suggestion_id, performed_by, event_type, old_status, new_status, remarks, is_member_visible, created_at)
                 VALUES (?, ?, 'STATUS_CHANGE', ?, ?, ?, ?, NOW())",
                [
                    $suggestionId,
                    $officerUserId,
                    $oldStatus,
                    $newStatus,
                    $remarksText,
                    $isMemberVisible ? 1 : 0,
                ]
            );

            AuditLogger::log('SUGGESTION_STATUS_CHANGED', 'suggestions', $suggestionId, null, [
                'suggestion_no' => $sug['suggestion_no'],
                'old_status'    => $oldStatus,
                'new_status'    => $newStatus,
                'remarks'       => $remarksText,
            ]);

            // Notify member if visible
            if ($isMemberVisible) {
                $memberUserId = self::getMemberUserId((int)$sug['member_id']);
                if ($memberUserId) {
                    self::notify(
                        $memberUserId,
                        'SUGGESTION_STATUS_CHANGE',
                        "Suggestion Update: {$sug['suggestion_no']}",
                        "Your suggestion status has been updated to '{$newStatus}'.",
                        $suggestionId
                    );
                }
            }
        });
    }

    // ------------------------------------------------------------------
    // Association Response Engine (§28.7)
    // ------------------------------------------------------------------
    public static function addResponse(
        int $suggestionId,
        int $officerUserId,
        string $responseText,
        ?string $newStatus = null
    ): void {
        $responseText = trim($responseText);
        if ($responseText === '') {
            throw new InvalidArgumentException('Association response text cannot be empty.');
        }

        $sug = Database::fetchOne("SELECT * FROM suggestions WHERE id = ?", [$suggestionId]);
        if (!$sug) {
            throw new RuntimeException("Suggestion #{$suggestionId} not found.");
        }

        $oldStatus = $sug['current_status'];
        $finalStatus = ($newStatus && in_array($newStatus, self::ALL_STATUSES, true)) ? $newStatus : $oldStatus;

        Database::transaction(function () use (
            $suggestionId,
            $officerUserId,
            $responseText,
            $oldStatus,
            $finalStatus,
            $sug
        ) {
            $isClosing = ($finalStatus === self::STATUS_CLOSED && $oldStatus !== self::STATUS_CLOSED);
            $closedSql = $isClosing ? ", closed_at = NOW()" : "";

            Database::execute(
                "UPDATE suggestions
                 SET association_response = ?,
                     responded_by = ?,
                     responded_at = NOW(),
                     current_status = ?,
                     last_updated_at = NOW() {$closedSql}
                 WHERE id = ?",
                [$responseText, $officerUserId, $finalStatus, $suggestionId]
            );

            Database::execute(
                "INSERT INTO suggestion_events
                 (suggestion_id, performed_by, event_type, old_status, new_status, remarks, is_member_visible, created_at)
                 VALUES (?, ?, 'RESPONSE', ?, ?, ?, 1, NOW())",
                [
                    $suggestionId,
                    $officerUserId,
                    $oldStatus,
                    $finalStatus,
                    $responseText,
                ]
            );

            AuditLogger::log('SUGGESTION_RESPONSE_ADDED', 'suggestions', $suggestionId, null, [
                'suggestion_no' => $sug['suggestion_no'],
                'officer_id'    => $officerUserId,
                'final_status'  => $finalStatus,
            ]);

            // Notify member
            $memberUserId = self::getMemberUserId((int)$sug['member_id']);
            if ($memberUserId) {
                self::notify(
                    $memberUserId,
                    'SUGGESTION_RESPONSE_ADDED',
                    "Association Response: {$sug['suggestion_no']}",
                    "The Association has provided an official response to your suggestion.",
                    $suggestionId
                );
            }
        });
    }

    // ------------------------------------------------------------------
    // Internal Admin Notes (§28.7) — Hidden from members
    // ------------------------------------------------------------------
    public static function addAdminNote(
        int $suggestionId,
        int $officerUserId,
        string $noteText
    ): void {
        $noteText = trim($noteText);
        if ($noteText === '') {
            throw new InvalidArgumentException('Note text cannot be empty.');
        }

        $sug = Database::fetchOne("SELECT * FROM suggestions WHERE id = ?", [$suggestionId]);
        if (!$sug) {
            throw new RuntimeException("Suggestion #{$suggestionId} not found.");
        }

        Database::transaction(function () use ($suggestionId, $officerUserId, $noteText, $sug) {
            // Append note with timestamp to admin_notes column
            $existingNotes = (string)($sug['admin_notes'] ?? '');
            $stamp = date('Y-m-d H:i:s');
            $newEntry = "[{$stamp}] Officer #{$officerUserId}:\n{$noteText}";
            $updatedNotes = ($existingNotes !== '') ? $existingNotes . "\n\n---\n\n" . $newEntry : $newEntry;

            Database::execute(
                "UPDATE suggestions SET admin_notes = ?, last_updated_at = NOW() WHERE id = ?",
                [$updatedNotes, $suggestionId]
            );

            Database::execute(
                "INSERT INTO suggestion_events
                 (suggestion_id, performed_by, event_type, old_status, new_status, remarks, is_member_visible, created_at)
                 VALUES (?, ?, 'NOTE', ?, ?, ?, 0, NOW())",
                [
                    $suggestionId,
                    $officerUserId,
                    $sug['current_status'],
                    $sug['current_status'],
                    $noteText,
                ]
            );

            AuditLogger::log('SUGGESTION_NOTE_ADDED', 'suggestions', $suggestionId, null, [
                'suggestion_no' => $sug['suggestion_no'],
                'officer_id'    => $officerUserId,
            ]);
        });
    }

    // ------------------------------------------------------------------
    // RBAC & Scope Helpers (§28.10)
    // ------------------------------------------------------------------
    public static function getOfficerScope(int $userId): array
    {
        $unitId = RBAC::getUserAssociationUnit($userId);
        if (!$unitId) {
            return ['district_id' => null, 'taluk_id' => null, 'unit_type' => 'state'];
        }

        $unit = Database::fetchOne("SELECT * FROM association_units WHERE id = ?", [$unitId]);
        if (!$unit) {
            return ['district_id' => null, 'taluk_id' => null, 'unit_type' => 'state'];
        }

        return [
            'district_id' => $unit['district_id'] ? (int)$unit['district_id'] : null,
            'taluk_id'    => $unit['taluk_id']    ? (int)$unit['taluk_id']    : null,
            'unit_type'   => (string)$unit['unit_type'],
        ];
    }

    /**
     * Check if an officer can access a suggestion based on geographic scope.
     */
    public static function canOfficerAccess(int $userId, array $suggestion): bool
    {
        // Must hold suggestions.view
        if (!RBAC::hasPermission($userId, 'suggestions', 'view')) {
            return false;
        }

        $scope = self::getOfficerScope($userId);
        if ($scope['unit_type'] === 'state' || (!$scope['district_id'] && !$scope['taluk_id'])) {
            return true;
        }

        // Fetch submitting member's location
        $member = Database::fetchOne("SELECT district_id, taluk_id FROM members WHERE id = ?", [(int)$suggestion['member_id']]);
        if (!$member) {
            return false;
        }

        if ($scope['taluk_id'] !== null) {
            return (int)$member['taluk_id'] === $scope['taluk_id'];
        }

        if ($scope['district_id'] !== null) {
            return (int)$member['district_id'] === $scope['district_id'];
        }

        return true;
    }

    /**
     * Check if a member owns a suggestion.
     */
    public static function canMemberAccess(int $memberId, array $suggestion): bool
    {
        return (int)$suggestion['member_id'] === $memberId;
    }

    // ------------------------------------------------------------------
    // Notifications Helper (§28.9)
    // ------------------------------------------------------------------
    public static function notify(int $userId, string $type, string $title, string $message, ?int $suggestionId = null): void
    {
        try {
            Database::execute(
                "INSERT INTO notifications (user_id, type, title, message, related_module, related_id, created_at)
                 VALUES (?, ?, ?, ?, 'suggestions', ?, NOW())",
                [$userId, $type, $title, $message, $suggestionId]
            );
        } catch (Throwable $e) {
            // Notifications should never crash the main transaction
            error_log('[SUGGESTION NOTIFY ERROR] ' . $e->getMessage());
        }
    }

    public static function getMemberUserId(int $memberId): ?int
    {
        $u = Database::fetchOne("SELECT id FROM users WHERE member_id = ? LIMIT 1", [$memberId]);
        return $u ? (int)$u['id'] : null;
    }
}
