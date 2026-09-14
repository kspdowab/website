<?php
/**
 * KSPDOWA — Member Portal: My Grievances
 * ============================================================
 * Section 9 Specification:
 * - Logged-in Members ONLY
 * - Required file-upload restriction:
 *     - Maximum 2 MB per file
 *     - Allowed types ONLY: .jpg, .png, .pdf
 *     - Enforce strictly server-side
 * - Preserves full Phase 5 multi-tier forwarding for Phase 5
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId   = Auth::getCurrentUserId();
$currentMemberId = Auth::getCurrentMemberId();

if ($currentMemberId === null) {
    header('Location: /login.php');
    exit;
}

// Fetch categories & services
$categories = Database::fetchAll("SELECT * FROM grievance_categories WHERE status = 'active' ORDER BY sort_order, name");
$services   = Database::fetchAll("SELECT * FROM grievance_services WHERE status = 'active' ORDER BY sort_order, name");

// ─── POST: Submit Grievance ──────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();
    $action = Sanitize::string($_POST['action'] ?? '', 30);

    if ($action === 'create') {
        $categoryId  = Sanitize::positiveInt($_POST['category_id'] ?? null);
        $serviceId   = Sanitize::positiveInt($_POST['service_id'] ?? null);
        $subject     = Sanitize::string($_POST['subject'] ?? '', 500);
        $description = Sanitize::string($_POST['description'] ?? '', 5000);

        $errors = [];

        if (!$categoryId) {
            $errors[] = 'Please select a grievance category.';
        }
        if (!$serviceId) {
            // Fallback: use first service in category if not selected
            $firstSrv = Database::fetchOne("SELECT id FROM grievance_services WHERE category_id = ?", [$categoryId]);
            $serviceId = $firstSrv ? (int)$firstSrv['id'] : 1;
        }
        if ($subject === '') {
            $errors[] = 'Subject is required.';
        }
        if ($description === '') {
            $errors[] = 'Detailed description is required.';
        }

        // ── MANDATORY FILE UPLOAD RESTRICTIONS (Section 9) ──
        // 1. Maximum 2 MB per file
        // 2. Allowed types ONLY: .jpg, .png, .pdf
        // 3. Enforced strictly server-side
        $uploadedDoc = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['attachment'];
            $maxBytes = 2 * 1024 * 1024; // 2 MB STRICT LIMIT

            if ($file['size'] > $maxBytes) {
                $errors[] = 'Attachment file exceeds the maximum allowed size of 2 MB.';
            }

            $rawExt = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'png', 'pdf'];

            if (!in_array($rawExt, $allowedExtensions, true)) {
                $errors[] = 'Invalid file type. Only .jpg, .png, and .pdf files are permitted.';
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($file['tmp_name']);
            $allowedMimes = [
                'image/jpeg',
                'image/png',
                'application/pdf'
            ];

            if (!in_array($mime, $allowedMimes, true)) {
                $errors[] = 'Invalid file MIME type (' . htmlspecialchars($mime) . '). Only JPEG, PNG, and PDF files are permitted.';
            }

            if (empty($errors)) {
                $targetDir = UPLOADS_DIR . '/grievances';
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }

                $safeFilename = 'grv_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $rawExt;
                $destPath     = $targetDir . '/' . $safeFilename;

                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    $uploadedDoc = [
                        'file_path'         => 'grievances/' . $safeFilename,
                        'original_filename' => basename((string)$file['name']),
                        'document_type'     => $rawExt === 'pdf' ? 'PDF Document' : 'Image'
                    ];
                } else {
                    $errors[] = 'Failed to upload attachment file.';
                }
            }
        }

        if (empty($errors)) {
            // Generate Grievance No: KSPDOWA-GRV-YYYY-NNNNN
            $yearStr = date('Y');
            $latest = Database::fetchOne(
                "SELECT grievance_no FROM grievances WHERE grievance_no LIKE ? ORDER BY id DESC LIMIT 1",
                ['KSPDOWA-GRV-' . $yearStr . '-%']
            );
            $seq = 1;
            if ($latest && preg_match('/-(\d+)$/', $latest['grievance_no'], $m)) {
                $seq = (int)$m[1] + 1;
            }
            $grievanceNo = sprintf('KSPDOWA-GRV-%s-%05d', $yearStr, $seq);

            Database::execute(
                "INSERT INTO grievances (grievance_no, member_id, category_id, service_id, subject, description, current_association_level, current_status, submitted_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'taluk', 'Submitted', NOW())",
                [$grievanceNo, $currentMemberId, $categoryId, $serviceId, $subject, $description]
            );
            $grievanceId = (int)Database::lastInsertId();

            // Insert initial timeline event
            Database::execute(
                "INSERT INTO grievance_events (grievance_id, performed_by, association_level, event_type, old_status, new_status, remarks)
                 VALUES (?, ?, 'member', 'Submitted', NULL, 'Submitted', 'Grievance submitted by member.')",
                [$grievanceId, $currentUserId]
            );
            $eventId = (int)Database::lastInsertId();

            // Insert attachment if uploaded
            if ($uploadedDoc !== null) {
                Database::execute(
                    "INSERT INTO grievance_documents (grievance_id, event_id, uploaded_by, document_type, file_path, original_filename)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [$grievanceId, $eventId, $currentUserId, $uploadedDoc['document_type'], $uploadedDoc['file_path'], $uploadedDoc['original_filename']]
                );
            }

            AuditLogger::log('CREATE', 'grievances', $grievanceId, null, ['grievance_no' => $grievanceNo]);
            Session::flash('success', "Grievance {$grievanceNo} submitted successfully. You will receive updates as it progresses.");
            header('Location: /member/grievances.php');
            exit;
        } else {
            Session::flash('error', implode(' ', $errors));
        }
    }
}

// ─── Fetch Member Grievances ─────────────────────────────────────────────────
$myGrievances = Database::fetchAll(
    "SELECT g.*, gc.name AS category_name, gs.name AS service_name
     FROM grievances g
     LEFT JOIN grievance_categories gc ON gc.id = g.category_id
     LEFT JOIN grievance_services gs   ON gs.id = g.service_id
     WHERE g.member_id = ?
     ORDER BY g.submitted_at DESC, g.id DESC",
    [$currentMemberId]
);

$pageTitle  = 'My Grievances';
$activeMenu = 'grievances';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/member/index.php'],
    ['label' => 'My Grievances', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">My Grievances &amp; Service Tracking</h1>
        <p class="page-heading-subtitle">Submit and track official association and government grievances</p>
    </div>
    <div>
        <button type="button" class="btn btn-primary" onclick="document.getElementById('newGrievanceModal').classList.add('open');">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Submit New Grievance
        </button>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     SUBMIT NEW GRIEVANCE MODAL (with 2 MB JPG/PNG/PDF Restriction)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="newGrievanceModal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Submit Grievance</h3>
            <button type="button" class="modal-close-btn" onclick="document.getElementById('newGrievanceModal').classList.remove('open');">✕</button>
        </div>
        <form method="post" action="/member/grievances.php" enctype="multipart/form-data">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="create">

            <div class="modal-body">
                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" for="category_id">Category *</label>
                    <select name="category_id" id="category_id" class="form-select" required>
                        <option value="">— Select Category —</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>"><?= Sanitize::html($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" for="subject">Subject *</label>
                    <input type="text" name="subject" id="subject" class="form-control" required maxlength="300" placeholder="Brief subject of grievance">
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" for="description">Detailed Description *</label>
                    <textarea name="description" id="description" rows="4" class="form-control" required placeholder="Provide all relevant details, dates, orders or references..."></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label" for="attachment">
                        Supporting Document
                        <span style="font-weight:400; color:var(--text-muted);">(Max 2 MB • .jpg, .png, .pdf only)</span>
                    </label>
                    <input type="file" name="attachment" id="attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                    <div style="font-size:0.78rem; color:var(--text-muted); margin-top:4px;">
                        Strict server-side enforcement: Maximum 2 MB. Allowed types: JPG, PNG, PDF only.
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('newGrievanceModal').classList.remove('open');">Cancel</button>
                <button type="submit" class="btn btn-primary">Submit Grievance</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     GRIEVANCES TABLE
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">Submitted Grievances</span>
            <span class="table-card-count">(<?= count($myGrievances) ?> records)</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:60px;">Sl No</th>
                    <th>Grievance No.</th>
                    <th>Category</th>
                    <th>Subject</th>
                    <th>Association Level</th>
                    <th>Status</th>
                    <th>Submitted Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($myGrievances)): ?>
                <tr>
                    <td colspan="7" style="text-align:center; padding:40px; color:var(--text-muted);">
                        You have not submitted any grievances yet. Click "Submit New Grievance" above if you require association support.
                    </td>
                </tr>
                <?php else: ?>
                    <?php $idx = 1; foreach ($myGrievances as $grv): ?>
                    <tr>
                        <td style="color:var(--text-muted); font-weight:600;"><?= $idx++ ?></td>
                        <td>
                            <code style="background:var(--blue-50); color:var(--blue-700); padding:3px 8px; border-radius:4px; font-weight:700;">
                                <?= Sanitize::html($grv['grievance_no']) ?>
                            </code>
                        </td>
                        <td>
                            <span class="badge badge-purple"><?= Sanitize::html($grv['category_name'] ?? 'General') ?></span>
                        </td>
                        <td style="font-weight:600; color:var(--text-main);">
                            <?= Sanitize::html($grv['subject']) ?>
                        </td>
                        <td>
                            <span class="badge badge-neutral"><?= ucfirst(Sanitize::html($grv['current_association_level'])) ?></span>
                        </td>
                        <td>
                            <?php
                            $st = strtolower($grv['current_status']);
                            if (in_array($st, ['resolved', 'action taken', 'closed'], true)): ?>
                                <span class="badge badge-success"><?= Sanitize::html($grv['current_status']) ?></span>
                            <?php elseif (in_array($st, ['rejected'], true)): ?>
                                <span class="badge badge-danger"><?= Sanitize::html($grv['current_status']) ?></span>
                            <?php else: ?>
                                <span class="badge badge-warning"><?= Sanitize::html($grv['current_status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('d M Y', strtotime($grv['submitted_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
