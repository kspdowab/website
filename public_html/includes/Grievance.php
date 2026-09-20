<?php
/**
 * KSPDOWA — Grievance Management Engine
 * ============================================================
 * Implements Phase 5: Grievance Module specifications
 * (03_GRIEVANCE_WORKFLOW.md, 01_MASTER_PROJECT_SPECIFICATION.md, 06_ROLES_PERMISSIONS_MATRIX.md)
 *
 * Core Principles:
 *   1. ONE MASTER GRIEVANCE (permanent ID: KSPDOWA-GRV-YYYY-NNNNN).
 *   2. Hierarchical Association Access: Member -> Taluk -> District -> State.
 *   3. Government Authority tracking is separate from Association workflow.
 *   4. IMMUTABLE TIMELINE: grievance_events rows are NEVER edited or deleted.
 *   5. Forwarding & Escalation preserve history, release previous assignment,
 *      create new assignment, and update current responsibility.
 *   6. Strict 2 MB file restrictions (.jpg, .png, .pdf with finfo MIME validation).
 * ============================================================
 */

declare(strict_types=1);

class Grievance
{
    // ------------------------------------------------------------------
    // 12 Approved Status Values (03_GRIEVANCE_WORKFLOW.md §6)
    // ------------------------------------------------------------------
    public const STATUS_SUBMITTED              = 'Submitted';
    public const STATUS_UNDER_VERIFICATION     = 'Under Verification';
    public const STATUS_ACCEPTED               = 'Accepted';
    public const STATUS_UNDER_REVIEW           = 'Under Review';
    public const STATUS_FORWARDED              = 'Forwarded';
    public const STATUS_PENDING                = 'Pending';
    public const STATUS_CLARIFICATION_REQUIRED = 'Clarification Required';
    public const STATUS_ACTION_TAKEN           = 'Action Taken';
    public const STATUS_RESOLVED               = 'Resolved';
    public const STATUS_REJECTED               = 'Rejected';
    public const STATUS_CLOSED                 = 'Closed';
    public const STATUS_REOPENED               = 'Reopened';

    public const ALL_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_UNDER_VERIFICATION,
        self::STATUS_ACCEPTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_FORWARDED,
        self::STATUS_PENDING,
        self::STATUS_CLARIFICATION_REQUIRED,
        self::STATUS_ACTION_TAKEN,
        self::STATUS_RESOLVED,
        self::STATUS_REJECTED,
        self::STATUS_CLOSED,
        self::STATUS_REOPENED,
    ];

    // Association Levels
    public const LEVEL_MEMBER   = 'member';
    public const LEVEL_TALUK    = 'taluk';
    public const LEVEL_DISTRICT = 'district';
    public const LEVEL_STATE    = 'state';

    // ------------------------------------------------------------------
    // Grievance ID Generation: KSPDOWA-GRV-YYYY-NNNNN
    // ------------------------------------------------------------------
    public static function generateGrievanceNo(): string
    {
        $year = date('Y');
        $prefix = "KSPDOWA-GRV-{$year}-%";

        $latest = Database::fetchOne(
            "SELECT grievance_no FROM grievances WHERE grievance_no LIKE ? ORDER BY id DESC LIMIT 1",
            [$prefix]
        );

        $seq = 1;
        if ($latest && preg_match('/-(\d+)$/', (string)$latest['grievance_no'], $m)) {
            $seq = (int)$m[1] + 1;
        }

        return sprintf('KSPDOWA-GRV-%s-%05d', $year, $seq);
    }

    // ------------------------------------------------------------------
    // Document Upload Validation (Strict 2 MB, JPG/PNG/PDF, MIME check)
    // ------------------------------------------------------------------
    public static function validateAttachment(?array $file): array
    {
        if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['valid' => true, 'hasFile' => false];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => 'File upload error code: ' . $file['error']];
        }

        $maxBytes = 2 * 1024 * 1024; // 2 MB STRICT
        if ($file['size'] > $maxBytes) {
            return ['valid' => false, 'error' => 'Attachment file exceeds the maximum permitted size of 2 MB.'];
        }

        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
        if (!in_array($ext, $allowedExtensions, true)) {
            return ['valid' => false, 'error' => 'Invalid file extension. Only .jpg, .png, and .pdf files are permitted.'];
        }

        if (!file_exists($file['tmp_name']) || !is_readable($file['tmp_name'])) {
            return ['valid' => false, 'error' => 'Uploaded temporary file could not be read.'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];

        if (!in_array($mime, $allowedMimes, true)) {
            return ['valid' => false, 'error' => 'Invalid file MIME type (' . htmlspecialchars((string)$mime) . '). Only standard JPEG, PNG, and PDF files are allowed.'];
        }

        $normExt = ($ext === 'jpeg') ? 'jpg' : $ext;
        $safeFilename = 'grv_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $normExt;
        $docType = ($normExt === 'pdf') ? 'PDF Document' : 'Image';

        return [
            'valid'        => true,
            'hasFile'      => true,
            'tmpPath'      => $file['tmp_name'],
            'safeFilename' => $safeFilename,
            'originalName' => basename((string)$file['name']),
            'docType'      => $docType,
        ];
    }

    /**
     * Save a validated attachment into uploads/grievances/
     */
    public static function saveAttachment(array $validated): ?string
    {
        if (empty($validated['hasFile']) || empty($validated['safeFilename'])) {
            return null;
        }

        $targetDir = UPLOADS_DIR . '/grievances';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $destPath = $targetDir . '/' . $validated['safeFilename'];
        if (move_uploaded_file($validated['tmpPath'], $destPath)) {
            return 'grievances/' . $validated['safeFilename'];
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Member Submission
    // ------------------------------------------------------------------
    public static function create(
        int $memberId,
        int $userId,
        int $categoryId,
        int $serviceId,
        ?int $authorityId,
        string $subject,
        string $description,
        ?array $file = null
    ): array {
        $subject     = trim($subject);
        $description = trim($description);

        if ($subject === '') {
            return ['success' => false, 'error' => 'Subject is required.'];
        }
        if ($description === '') {
            return ['success' => false, 'error' => 'Description is required.'];
        }

        // Validate attachment if provided
        $valFile = self::validateAttachment($file);
        if (!$valFile['valid']) {
            return ['success' => false, 'error' => $valFile['error']];
        }

        $grievanceNo = self::generateGrievanceNo();

        // Save grievance
        Database::execute(
            "INSERT INTO grievances
                (grievance_no, member_id, category_id, service_id, subject, description,
                 current_association_level, current_assignee, current_authority, current_status, submitted_at)
             VALUES (?, ?, ?, ?, ?, ?, 'taluk', NULL, ?, ?, NOW())",
            [$grievanceNo, $memberId, $categoryId, $serviceId, $subject, $description, $authorityId, self::STATUS_SUBMITTED]
        );
        $grievanceId = (int)Database::lastInsertId();

        // Insert initial immutable event
        $eventId = self::addEvent(
            $grievanceId,
            $userId,
            self::LEVEL_MEMBER,
            self::STATUS_SUBMITTED,
            null,
            self::STATUS_SUBMITTED,
            'Grievance submitted by member.',
            1,
            null,
            $authorityId
        );

        // Store attachment if present
        if (!empty($valFile['hasFile'])) {
            $savedRelPath = self::saveAttachment($valFile);
            if ($savedRelPath) {
                Database::execute(
                    "INSERT INTO grievance_documents
                        (grievance_id, event_id, uploaded_by, document_type, file_path, original_filename, uploaded_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())",
                    [$grievanceId, $eventId, $userId, $valFile['docType'], $savedRelPath, $valFile['originalName']]
                );
            }
        }

        // Send in-app notification to member
        self::notify(
            $userId,
            'GRIEVANCE_SUBMITTED',
            "Grievance {$grievanceNo} Submitted",
            "Your grievance \"{$subject}\" has been successfully submitted and forwarded to the Taluk Committee for verification.",
            $grievanceId
        );

        // Audit log (administrative / security log)
        AuditLogger::log('CREATE', 'grievances', $grievanceId, null, [
            'grievance_no' => $grievanceNo,
            'category_id'  => $categoryId,
            'service_id'   => $serviceId,
        ]);

        return [
            'success'      => true,
            'grievance_id' => $grievanceId,
            'grievance_no' => $grievanceNo,
        ];
    }

    // ------------------------------------------------------------------
    // Immutable Event Timeline (03_GRIEVANCE_WORKFLOW.md §10)
    // ------------------------------------------------------------------
    public static function addEvent(
        int $grievanceId,
        int $performedBy,
        string $associationLevel,
        string $eventType,
        ?string $oldStatus,
        ?string $newStatus,
        ?string $remarks,
        int $isMemberVisible = 1,
        ?int $oldAuthority = null,
        ?int $newAuthority = null
    ): int {
        Database::execute(
            "INSERT INTO grievance_events
                (grievance_id, performed_by, association_level, event_type,
                 old_status, new_status, old_authority, new_authority, remarks, is_member_visible, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $grievanceId,
                $performedBy,
                $associationLevel,
                $eventType,
                $oldStatus,
                $newStatus,
                $oldAuthority,
                $newAuthority,
                $remarks,
                $isMemberVisible
            ]
        );
        return (int)Database::lastInsertId();
    }

    // ------------------------------------------------------------------
    // Assignment Management (03_GRIEVANCE_WORKFLOW.md §7)
    // ------------------------------------------------------------------
    public static function assignOfficer(
        int $grievanceId,
        int $actorUserId,
        int $assigneeUserId,
        string $associationLevel,
        string $remarks,
        int $isMemberVisible = 1
    ): bool {
        $grv = Database::fetchOne("SELECT * FROM grievances WHERE id = ?", [$grievanceId]);
        if (!$grv) {
            return false;
        }

        $oldStatus = $grv['current_status'];
        $newStatus = ($oldStatus === self::STATUS_SUBMITTED || $oldStatus === self::STATUS_UNDER_VERIFICATION)
            ? self::STATUS_UNDER_REVIEW
            : $oldStatus;

        // Release previous active assignment
        Database::execute(
            "UPDATE grievance_assignments SET is_current = 0, released_at = NOW()
             WHERE grievance_id = ? AND is_current = 1",
            [$grievanceId]
        );

        // Create new assignment
        Database::execute(
            "INSERT INTO grievance_assignments (grievance_id, assigned_to, association_level, assigned_at, is_current)
             VALUES (?, ?, ?, NOW(), 1)",
            [$grievanceId, $assigneeUserId, $associationLevel]
        );

        // Update master grievance
        Database::execute(
            "UPDATE grievances SET current_assignee = ?, current_association_level = ?, current_status = ?, last_updated_at = NOW()
             WHERE id = ?",
            [$assigneeUserId, $associationLevel, $newStatus, $grievanceId]
        );

        // Add event
        self::addEvent(
            $grievanceId,
            $actorUserId,
            $associationLevel,
            'Assigned',
            $oldStatus,
            $newStatus,
            $remarks,
            $isMemberVisible
        );

        // Notify newly assigned officer
        self::notify(
            $assigneeUserId,
            'GRIEVANCE_ASSIGNED',
            "Grievance {$grv['grievance_no']} Assigned",
            "You have been assigned to handle grievance {$grv['grievance_no']}: \"{$grv['subject']}\".",
            $grievanceId
        );

        // Audit log
        AuditLogger::log('ASSIGN', 'grievances', $grievanceId, null, [
            'assigned_to' => $assigneeUserId,
            'level'       => $associationLevel,
            'remarks'     => $remarks,
        ]);

        return true;
    }

    // ------------------------------------------------------------------
    // Forwarding (03_GRIEVANCE_WORKFLOW.md §8)
    // ------------------------------------------------------------------
    public static function forwardGrievance(
        int $grievanceId,
        int $actorUserId,
        string $toLevel,
        ?int $toAuthorityId,
        ?int $toAssigneeUserId,
        string $remarks,
        int $isMemberVisible = 1
    ): bool {
        $grv = Database::fetchOne("SELECT * FROM grievances WHERE id = ?", [$grievanceId]);
        if (!$grv) {
            return false;
        }

        $oldStatus    = $grv['current_status'];
        $newStatus    = self::STATUS_FORWARDED;
        $oldAuthority = $grv['current_authority'] ? (int)$grv['current_authority'] : null;
        $newAuthority = $toAuthorityId ?: $oldAuthority;

        // Release previous active assignment
        Database::execute(
            "UPDATE grievance_assignments SET is_current = 0, released_at = NOW()
             WHERE grievance_id = ? AND is_current = 1",
            [$grievanceId]
        );

        // Assign to new officer if provided
        if ($toAssigneeUserId) {
            Database::execute(
                "INSERT INTO grievance_assignments (grievance_id, assigned_to, association_level, assigned_at, is_current)
                 VALUES (?, ?, ?, NOW(), 1)",
                [$grievanceId, $toAssigneeUserId, $toLevel]
            );
        }

        // Update master grievance
        Database::execute(
            "UPDATE grievances
             SET current_association_level = ?,
                 current_assignee = ?,
                 current_authority = ?,
                 current_status = ?,
                 last_updated_at = NOW()
             WHERE id = ?",
            [$toLevel, $toAssigneeUserId, $newAuthority, $newStatus, $grievanceId]
        );

        // Add immutable event
        self::addEvent(
            $grievanceId,
            $actorUserId,
            $toLevel,
            self::STATUS_FORWARDED,
            $oldStatus,
            $newStatus,
            $remarks,
            $isMemberVisible,
            $oldAuthority,
            $newAuthority
        );

        // Notify member
        $memberUser = self::getMemberUserId((int)$grv['member_id']);
        if ($memberUser) {
            self::notify(
                $memberUser,
                'GRIEVANCE_FORWARDED',
                "Grievance {$grv['grievance_no']} Forwarded",
                "Your grievance has been forwarded to the " . ucfirst($toLevel) . " level for further processing.",
                $grievanceId
            );
        }

        // Notify target officer if assigned
        if ($toAssigneeUserId) {
            self::notify(
                $toAssigneeUserId,
                'GRIEVANCE_FORWARDED_TO_YOU',
                "Forwarded Grievance {$grv['grievance_no']}",
                "Grievance {$grv['grievance_no']} has been forwarded to your scope at " . ucfirst($toLevel) . " level.",
                $grievanceId
            );
        }

        AuditLogger::log('FORWARD', 'grievances', $grievanceId, null, [
            'to_level'     => $toLevel,
            'to_assignee'  => $toAssigneeUserId,
            'to_authority' => $toAuthorityId,
            'remarks'      => $remarks,
        ]);

        return true;
    }

    // ------------------------------------------------------------------
    // Escalation (03_GRIEVANCE_WORKFLOW.md §9)
    // ------------------------------------------------------------------
    public static function escalateGrievance(
        int $grievanceId,
        int $actorUserId,
        string $toLevel,
        string $remarks,
        int $isMemberVisible = 1
    ): bool {
        $grv = Database::fetchOne("SELECT * FROM grievances WHERE id = ?", [$grievanceId]);
        if (!$grv) {
            return false;
        }

        $oldStatus = $grv['current_status'];
        $newStatus = self::STATUS_UNDER_REVIEW;

        // Release previous assignment
        Database::execute(
            "UPDATE grievance_assignments SET is_current = 0, released_at = NOW()
             WHERE grievance_id = ? AND is_current = 1",
            [$grievanceId]
        );

        Database::execute(
            "UPDATE grievances
             SET current_association_level = ?,
                 current_assignee = NULL,
                 current_status = ?,
                 last_updated_at = NOW()
             WHERE id = ?",
            [$toLevel, $newStatus, $grievanceId]
        );

        self::addEvent(
            $grievanceId,
            $actorUserId,
            $toLevel,
            'Escalated',
            $oldStatus,
            $newStatus,
            $remarks,
            $isMemberVisible
        );

        // Notify member
        $memberUser = self::getMemberUserId((int)$grv['member_id']);
        if ($memberUser) {
            self::notify(
                $memberUser,
                'GRIEVANCE_ESCALATED',
                "Grievance {$grv['grievance_no']} Escalated",
                "Your grievance has been escalated to " . ucfirst($toLevel) . " level for priority review.",
                $grievanceId
            );
        }

        AuditLogger::log('ESCALATE', 'grievances', $grievanceId, null, [
            'to_level' => $toLevel,
            'remarks'  => $remarks,
        ]);

        return true;
    }

    // ------------------------------------------------------------------
    // Clarification Workflow (03_GRIEVANCE_WORKFLOW.md §11)
    // ------------------------------------------------------------------
    public static function requestClarification(
        int $grievanceId,
        int $actorUserId,
        string $associationLevel,
        string $remarks
    ): bool {
        $grv = Database::fetchOne("SELECT * FROM grievances WHERE id = ?", [$grievanceId]);
        if (!$grv) {
            return false;
        }

        $oldStatus = $grv['current_status'];
        $newStatus = self::STATUS_CLARIFICATION_REQUIRED;

        Database::execute(
            "UPDATE grievances SET current_status = ?, last_updated_at = NOW() WHERE id = ?",
            [$newStatus, $grievanceId]
        );

        self::addEvent(
            $grievanceId,
            $actorUserId,
            $associationLevel,
            'Clarification Requested',
            $oldStatus,
            $newStatus,
            $remarks,
            1 // always visible to member
        );

        $memberUser = self::getMemberUserId((int)$grv['member_id']);
        if ($memberUser) {
            self::notify(
                $memberUser,
                'CLARIFICATION_REQUIRED',
                "Clarification Requested: {$grv['grievance_no']}",
                "The association has requested clarification on your grievance: \"{$remarks}\". Please log in to respond.",
                $grievanceId
            );
        }

        AuditLogger::log('CLARIFICATION_REQUEST', 'grievances', $grievanceId, null, ['remarks' => $remarks]);
        return true;
    }

    public static function submitClarificationResponse(
        int $grievanceId,
        int $memberUserId,
        string $responseRemarks,
        ?array $file = null
    ): array {
        $grv = Database::fetchOne("SELECT * FROM grievances WHERE id = ?", [$grievanceId]);
        if (!$grv) {
            return ['success' => false, 'error' => 'Grievance not found.'];
        }

        $responseRemarks = trim($responseRemarks);
        if ($responseRemarks === '') {
            return ['success' => false, 'error' => 'Clarification response text is required.'];
        }

        $valFile = self::validateAttachment($file);
        if (!$valFile['valid']) {
            return ['success' => false, 'error' => $valFile['error']];
        }

        $oldStatus = $grv['current_status'];
        $newStatus = self::STATUS_UNDER_REVIEW;

        Database::execute(
            "UPDATE grievances SET current_status = ?, last_updated_at = NOW() WHERE id = ?",
            [$newStatus, $grievanceId]
        );

        $eventId = self::addEvent(
            $grievanceId,
            $memberUserId,
            self::LEVEL_MEMBER,
            'Clarification Responded',
            $oldStatus,
            $newStatus,
            $responseRemarks,
            1
        );

        // Store attachment if provided
        if (!empty($valFile['hasFile'])) {
            $savedRelPath = self::saveAttachment($valFile);
            if ($savedRelPath) {
                Database::execute(
                    "INSERT INTO grievance_documents
                        (grievance_id, event_id, uploaded_by, document_type, file_path, original_filename, uploaded_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())",
                    [$grievanceId, $eventId, $memberUserId, $valFile['docType'], $savedRelPath, $valFile['originalName']]
                );
            }
        }

        // Notify assigned officer or taluk/district officers
        if (!empty($grv['current_assignee'])) {
            self::notify(
                (int)$grv['current_assignee'],
                'CLARIFICATION_RESPONDED',
                "Clarification Received: {$grv['grievance_no']}",
                "The member has submitted clarification response for grievance {$grv['grievance_no']}.",
                $grievanceId
            );
        }

        AuditLogger::log('CLARIFICATION_RESPONSE', 'grievances', $grievanceId, null, [
            'response' => $responseRemarks,
        ]);

        return ['success' => true];
    }

    // ------------------------------------------------------------------
    // Resolution, Action Taken, Rejection, Closure & Reopen
    // ------------------------------------------------------------------
    public static function recordActionTaken(
        int $grievanceId,
        int $actorUserId,
        string $associationLevel,
        string $remarks,
        ?int $authorityId = null,
        ?array $file = null,
        int $isMemberVisible = 1
    ): array {
        $grv = Database::fetchOne("SELECT * FROM grievances WHERE id = ?", [$grievanceId]);
        if (!$grv) {
            return ['success' => false, 'error' => 'Grievance not found.'];
        }

        $valFile = self::validateAttachment($file);
        if (!$valFile['valid']) {
            return ['success' => false, 'error' => $valFile['error']];
        }

        $oldStatus    = $grv['current_status'];
        $newStatus    = self::STATUS_ACTION_TAKEN;
        $oldAuthority = $grv['current_authority'] ? (int)$grv['current_authority'] : null;
        $newAuthority = $authorityId ?: $oldAuthority;

        Database::execute(
            "UPDATE grievances
             SET current_status = ?, current_authority = ?, last_updated_at = NOW()
             WHERE id = ?",
            [$newStatus, $newAuthority, $grievanceId]
        );

        $eventId = self::addEvent(
            $grievanceId,
            $actorUserId,
            $associationLevel,
            self::STATUS_ACTION_TAKEN,
            $oldStatus,
            $newStatus,
            $remarks,
            $isMemberVisible,
            $oldAuthority,
            $newAuthority
        );

        if (!empty($valFile['hasFile'])) {
            $savedRelPath = self::saveAttachment($valFile);
            if ($savedRelPath) {
                Database::execute(
                    "INSERT INTO grievance_documents
                        (grievance_id, event_id, uploaded_by, document_type, file_path, original_filename, uploaded_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())",
                    [$grievanceId, $eventId, $actorUserId, $valFile['docType'], $savedRelPath, $valFile['originalName']]
                );
            }
        }

        if ($isMemberVisible) {
            $memberUser = self::getMemberUserId((int)$grv['member_id']);
            if ($memberUser) {
                self::notify(
                    $memberUser,
                    'GRIEVANCE_ACTION_TAKEN',
                    "Action Taken: {$grv['grievance_no']}",
                    "Action has been taken on your grievance: \"{$remarks}\".",
                    $grievanceId
                );
            }
        }

        AuditLogger::log('ACTION_TAKEN', 'grievances', $grievanceId, null, ['remarks' => $remarks]);
        return ['success' => true];
    }

    public static function resolveGrievance(
        int $grievanceId,
        int $actorUserId,
        string $associationLevel,
        string $remarks,
        ?array $file = null,
        int $isMemberVisible = 1
    ): array {
        $grv = Database::fetchOne("SELECT * FROM grievances WHERE id = ?", [$grievanceId]);
        if (!$grv) {
            return ['success' => false, 'error' => 'Grievance not found.'];
        }

        $valFile = self::validateAttachment($file);
        if (!$valFile['valid']) {
            return ['success' => false, 'error' => $valFile['error']];
        }

        $oldStatus = $grv['current_status'];
        $newStatus = self::STATUS_RESOLVED;

        Database::execute(
            "UPDATE grievances SET current_status = ?, last_updated_at = NOW() WHERE id = ?",
            [$newStatus, $grievanceId]
        );

        $eventId = self::addEvent(
            $grievanceId,
            $actorUserId,
            $associationLevel,
            self::STATUS_RESOLVED,
            $oldStatus,
            $newStatus,
            $remarks,
            $isMemberVisible
        );

        if (!empty($valFile['hasFile'])) {
            $savedRelPath = self::saveAttachment($valFile);
            if ($savedRelPath) {
                Database::execute(
                    "INSERT INTO grievance_documents
                        (grievance_id, event_id, uploaded_by, document_type, file_path, original_filename, uploaded_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())",
                    [$grievanceId, $eventId, $actorUserId, $valFile['docType'], $savedRelPath, $valFile['originalName']]
                );
            }
        }

        $memberUser = self::getMemberUserId((int)$grv['member_id']);
        if ($memberUser) {
            self::notify(
                $memberUser,
                'GRIEVANCE_RESOLVED',
                "Grievance Resolved: {$grv['grievance_no']}",
                "Your grievance has been marked as Resolved. Remarks: \"{$remarks}\".",
                $grievanceId
            );
        }

        AuditLogger::log('RESOLVE', 'grievances', $grievanceId, null, ['remarks' => $remarks]);
        return ['success' => true];
    }

    public static function rejectGrievance(
        int $grievanceId,
        int $actorUserId,
        string $associationLevel,
        string $remarks,
        int $isMemberVisible = 1
    ): bool {
        $grv = Database::fetchOne("SELECT * FROM grievances WHERE id = ?", [$grievanceId]);
        if (!$grv) {
            return false;
        }

        $oldStatus = $grv['current_status'];
        $newStatus = self::STATUS_REJECTED;

        Database::execute(
            "UPDATE grievances SET current_status = ?, last_updated_at = NOW() WHERE id = ?",
            [$newStatus, $grievanceId]
        );

        self::addEvent(
            $grievanceId,
            $actorUserId,
            $associationLevel,
            self::STATUS_REJECTED,
            $oldStatus,
            $newStatus,
            $remarks,
            $isMemberVisible
        );

        $memberUser = self::getMemberUserId((int)$grv['member_id']);
        if ($memberUser) {
            self::notify(
                $memberUser,
                'GRIEVANCE_REJECTED',
                "Grievance Rejected: {$grv['grievance_no']}",
                "Your grievance has been rejected. Reason: \"{$remarks}\".",
                $grievanceId
            );
        }

        AuditLogger::log('REJECT', 'grievances', $grievanceId, null, ['remarks' => $remarks]);
        return true;
    }

    public static function closeGrievance(
        int $grievanceId,
        int $actorUserId,
        string $associationLevel,
        string $remarks,
        int $isMemberVisible = 1
    ): bool {
        $grv = Database::fetchOne("SELECT * FROM grievances WHERE id = ?", [$grievanceId]);
        if (!$grv) {
            return false;
        }

        $oldStatus = $grv['current_status'];
        $newStatus = self::STATUS_CLOSED;

        Database::execute(
            "UPDATE grievances SET current_status = ?, closed_at = NOW(), last_updated_at = NOW() WHERE id = ?",
            [$newStatus, $grievanceId]
        );

        self::addEvent(
            $grievanceId,
            $actorUserId,
            $associationLevel,
            self::STATUS_CLOSED,
            $oldStatus,
            $newStatus,
            $remarks,
            $isMemberVisible
        );

        $memberUser = self::getMemberUserId((int)$grv['member_id']);
        if ($memberUser) {
            self::notify(
                $memberUser,
                'GRIEVANCE_CLOSED',
                "Grievance Closed: {$grv['grievance_no']}",
                "Your grievance has been closed. Final remarks: \"{$remarks}\".",
                $grievanceId
            );
        }

        AuditLogger::log('CLOSE', 'grievances', $grievanceId, null, ['remarks' => $remarks]);
        return true;
    }

    public static function reopenGrievance(
        int $grievanceId,
        int $actorUserId,
        string $associationLevel,
        string $remarks,
        int $isMemberVisible = 1
    ): bool {
        $grv = Database::fetchOne("SELECT * FROM grievances WHERE id = ?", [$grievanceId]);
        if (!$grv) {
            return false;
        }

        $oldStatus = $grv['current_status'];
        $newStatus = self::STATUS_REOPENED;

        Database::execute(
            "UPDATE grievances SET current_status = ?, closed_at = NULL, last_updated_at = NOW() WHERE id = ?",
            [$newStatus, $grievanceId]
        );

        self::addEvent(
            $grievanceId,
            $actorUserId,
            $associationLevel,
            self::STATUS_REOPENED,
            $oldStatus,
            $newStatus,
            $remarks,
            $isMemberVisible
        );

        // Notify member
        $memberUser = self::getMemberUserId((int)$grv['member_id']);
        if ($memberUser && $memberUser !== $actorUserId) {
            self::notify(
                $memberUser,
                'GRIEVANCE_REOPENED',
                "Grievance Reopened: {$grv['grievance_no']}",
                "Your grievance has been reopened for review. Reason: \"{$remarks}\".",
                $grievanceId
            );
        }

        // If member reopened, notify assigned officer
        if (!empty($grv['current_assignee'])) {
            self::notify(
                (int)$grv['current_assignee'],
                'GRIEVANCE_REOPENED_OFFICER',
                "Grievance {$grv['grievance_no']} Reopened",
                "Grievance {$grv['grievance_no']} has been reopened. Reason: \"{$remarks}\".",
                $grievanceId
            );
        }

        AuditLogger::log('REOPEN', 'grievances', $grievanceId, null, ['remarks' => $remarks]);
        return true;
    }

    public static function updateStatusDirect(
        int $grievanceId,
        int $actorUserId,
        string $associationLevel,
        string $newStatus,
        string $remarks,
        int $isMemberVisible = 1
    ): bool {
        $grv = Database::fetchOne("SELECT * FROM grievances WHERE id = ?", [$grievanceId]);
        if (!$grv) {
            return false;
        }

        if (!in_array($newStatus, self::ALL_STATUSES, true)) {
            return false;
        }

        $oldStatus = $grv['current_status'];
        Database::execute(
            "UPDATE grievances SET current_status = ?, last_updated_at = NOW() WHERE id = ?",
            [$newStatus, $grievanceId]
        );

        self::addEvent(
            $grievanceId,
            $actorUserId,
            $associationLevel,
            $newStatus,
            $oldStatus,
            $newStatus,
            $remarks,
            $isMemberVisible
        );

        if ($isMemberVisible) {
            $memberUser = self::getMemberUserId((int)$grv['member_id']);
            if ($memberUser) {
                self::notify(
                    $memberUser,
                    'GRIEVANCE_STATUS_UPDATE',
                    "Grievance Status: {$newStatus}",
                    "Your grievance {$grv['grievance_no']} status has been updated to {$newStatus}.",
                    $grievanceId
                );
            }
        }

        AuditLogger::log('STATUS_CHANGE', 'grievances', $grievanceId, null, [
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'remarks'    => $remarks,
        ]);

        return true;
    }

    // ------------------------------------------------------------------
    // RBAC Scope Checking Helpers
    // ------------------------------------------------------------------
    public static function getOfficerScope(int $userId): array
    {
        $unitId = RBAC::getUserAssociationUnit($userId);
        if (!$unitId) {
            // Super Admin or State officer with no locked unit
            return [
                'type'        => 'state',
                'district_id' => null,
                'taluk_id'    => null,
            ];
        }

        $unit = Database::fetchOne("SELECT * FROM association_units WHERE id = ?", [$unitId]);
        if (!$unit) {
            return [
                'type'        => 'state',
                'district_id' => null,
                'taluk_id'    => null,
            ];
        }

        if ($unit['unit_type'] === 'taluk') {
            return [
                'type'        => 'taluk',
                'district_id' => (int)$unit['district_id'],
                'taluk_id'    => (int)$unit['taluk_id'],
            ];
        }

        if ($unit['unit_type'] === 'district') {
            return [
                'type'        => 'district',
                'district_id' => (int)$unit['district_id'],
                'taluk_id'    => null,
            ];
        }

        return [
            'type'        => 'state',
            'district_id' => null,
            'taluk_id'    => null,
        ];
    }

    /**
     * Determine whether an officer can access a grievance record based on geographic scope.
     */
    public static function canOfficerAccess(int $userId, array $grievance): bool
    {
        $scope = self::getOfficerScope($userId);

        if ($scope['type'] === 'state') {
            return true;
        }

        // Check member location attached to grievance
        $grvTalukId    = isset($grievance['taluk_id']) ? (int)$grievance['taluk_id'] : null;
        $grvDistrictId = isset($grievance['district_id']) ? (int)$grievance['district_id'] : null;

        if ($grvTalukId === null || $grvDistrictId === null) {
            $m = Database::fetchOne("SELECT district_id, taluk_id FROM members WHERE id = ?", [$grievance['member_id']]);
            if ($m) {
                $grvDistrictId = (int)$m['district_id'];
                $grvTalukId    = (int)$m['taluk_id'];
            }
        }

        if ($scope['type'] === 'taluk') {
            return ($grvTalukId === $scope['taluk_id']);
        }

        if ($scope['type'] === 'district') {
            return ($grvDistrictId === $scope['district_id']);
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Notifications Helper
    // ------------------------------------------------------------------
    public static function notify(int $userId, string $type, string $title, string $message, ?int $grievanceId = null): void
    {
        try {
            if (class_exists('NotificationService')) {
                $extra = [];
                if ($grievanceId !== null) {
                    $grv = Database::fetchOne("SELECT grievance_no, subject, status FROM grievances WHERE id = ? LIMIT 1", [$grievanceId]);
                    if ($grv) {
                        $extra['summary_table'] = [
                            'Grievance No' => $grv['grievance_no'],
                            'Subject'      => $grv['subject'],
                            'Status'       => $grv['status'],
                        ];
                        $extra['action_url'] = (defined('APP_URL') ? APP_URL : '') . '/member/grievance-view.php?id=' . $grievanceId;
                        $extra['action_label'] = 'View Grievance Status';
                    }
                }
                NotificationService::sendToUser($userId, $type, $title, $message, 'grievances', $grievanceId, $extra);
            } else {
                Database::execute(
                    "INSERT INTO notifications (user_id, type, title, message, related_module, related_id, created_at)
                     VALUES (?, ?, ?, ?, 'grievances', ?, NOW())",
                    [$userId, $type, $title, $message, $grievanceId]
                );
            }
        } catch (\Throwable $e) {
            // Notifications should never throw uncaught fatal errors
            error_log('[GRIEVANCE NOTIFY ERROR] ' . $e->getMessage());
        }
    }

    public static function getMemberUserId(int $memberId): ?int
    {
        $u = Database::fetchOne("SELECT id FROM users WHERE member_id = ? LIMIT 1", [$memberId]);
        return $u ? (int)$u['id'] : null;
    }
}
