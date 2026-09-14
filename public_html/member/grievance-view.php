<?php
/**
 * KSPDOWA — Member Portal: Grievance Detail & Tracking View
 * ============================================================
 * Section 8, 10, 11: Member Grievance View & Immutable Timeline
 * - Strict ownership check: member can only view their own grievance.
 * - Displays member-visible events only (never exposes private officer notes).
 * - Allows member to respond to clarification requests.
 * - Allows member to request reopening on resolved/closed cases.
 * - Secure document download via /download-grievance-doc.php.
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

$id = Sanitize::positiveInt($_GET['id'] ?? null);
if (!$id) {
    ErrorHandler::abort(404, 'Grievance not found.');
}

// Fetch grievance with joined master data
$grievance = Database::fetchOne(
    "SELECT g.*, gc.name AS category_name, gs.name AS service_name, ga.name AS authority_name, ga.code AS authority_code
     FROM grievances g
     LEFT JOIN grievance_categories gc ON gc.id = g.category_id
     LEFT JOIN grievance_services gs   ON gs.id = g.service_id
     LEFT JOIN grievance_authorities ga ON ga.id = g.current_authority
     WHERE g.id = ?",
    [$id]
);

if (!$grievance) {
    ErrorHandler::abort(404, 'Grievance not found.');
}

// Ownership check: strict member isolation (prevent IDOR)
if ((int)$grievance['member_id'] !== $currentMemberId) {
    AuditLogger::log('ACCESS_DENIED', 'grievances', $id, null, [
        'user_id' => $currentUserId,
        'reason'  => 'Cross-member grievance access attempt',
    ]);
    ErrorHandler::abort(403, 'Access denied. You can only view your own grievances.');
}

// ─── Handle Member Actions (Clarification Response & Reopen) ─────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();
    $action = Sanitize::string($_POST['action'] ?? '', 40);

    if ($action === 'respond_clarification') {
        if ($grievance['current_status'] !== Grievance::STATUS_CLARIFICATION_REQUIRED) {
            Session::flash('error', 'Clarification is not currently requested for this grievance.');
        } else {
            $responseRemarks = Sanitize::string($_POST['response_remarks'] ?? '', 5000);
            $fileUpload = (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE)
                ? $_FILES['attachment']
                : null;

            $res = Grievance::submitClarificationResponse($id, $currentUserId, $responseRemarks, $fileUpload);
            if ($res['success']) {
                Session::flash('success', 'Your clarification response has been submitted successfully to the reviewing committee.');
                header('Location: /member/grievance-view.php?id=' . $id);
                exit;
            } else {
                Session::flash('error', $res['error'] ?? 'Failed to submit response.');
            }
        }
    } elseif ($action === 'reopen') {
        if (!in_array($grievance['current_status'], [Grievance::STATUS_RESOLVED, Grievance::STATUS_CLOSED], true)) {
            Session::flash('error', 'Only resolved or closed grievances can be reopened.');
        } else {
            $reopenReason = Sanitize::string($_POST['reopen_reason'] ?? '', 3000);
            if ($reopenReason === '') {
                Session::flash('error', 'Please provide a clear reason for reopening.');
            } else {
                Grievance::reopenGrievance($id, $currentUserId, Grievance::LEVEL_MEMBER, $reopenReason, 1);
                Session::flash('success', 'Your grievance has been reopened and queued for officer review.');
                header('Location: /member/grievance-view.php?id=' . $id);
                exit;
            }
        }
    }
}

// Fetch member-visible immutable events
$events = Database::fetchAll(
    "SELECT ge.*, u.username, u.email,
            ga_old.name AS old_auth_name, ga_new.name AS new_auth_name
     FROM grievance_events ge
     LEFT JOIN users u ON u.id = ge.performed_by
     LEFT JOIN grievance_authorities ga_old ON ga_old.id = ge.old_authority
     LEFT JOIN grievance_authorities ga_new ON ga_new.id = ge.new_authority
     WHERE ge.grievance_id = ? AND ge.is_member_visible = 1
     ORDER BY ge.created_at ASC, ge.id ASC",
    [$id]
);

// Fetch attached documents
$documents = Database::fetchAll(
    "SELECT gd.*, u.username
     FROM grievance_documents gd
     LEFT JOIN users u ON u.id = gd.uploaded_by
     WHERE gd.grievance_id = ?
     ORDER BY gd.uploaded_at ASC",
    [$id]
);

// Calculate pending duration
$submittedTs = strtotime((string)$grievance['submitted_at']);
$pendingDays = max(0, (int)floor((time() - $submittedTs) / 86400));

$pageTitle   = 'Grievance ' . $grievance['grievance_no'];
$activeMenu  = 'grievances';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/member/index.php'],
    ['label' => 'My Grievances', 'url' => '/member/grievances.php'],
    ['label' => $grievance['grievance_no'], 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';

$st = $grievance['current_status'];
$badgeClass = 'badge-neutral';
if ($st === Grievance::STATUS_RESOLVED || $st === Grievance::STATUS_ACTION_TAKEN) {
    $badgeClass = 'badge-success';
} elseif ($st === Grievance::STATUS_CLARIFICATION_REQUIRED) {
    $badgeClass = 'badge-warning';
} elseif ($st === Grievance::STATUS_REJECTED) {
    $badgeClass = 'badge-danger';
} elseif ($st === Grievance::STATUS_SUBMITTED) {
    $badgeClass = 'badge-neutral';
} else {
    $badgeClass = 'badge-purple';
}
?>

<div class="page-header-row">
    <div>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <h1 class="page-heading-title" style="margin:0;"><?= Sanitize::html($grievance['grievance_no']) ?></h1>
            <span class="badge <?= $badgeClass ?>" style="font-size:0.9rem; padding:6px 14px;">
                ● <?= Sanitize::html($st) ?>
            </span>
            <span class="badge badge-neutral" style="text-transform:capitalize;">
                <?= ucfirst(Sanitize::html($grievance['current_association_level'])) ?> Level
            </span>
        </div>
        <p class="page-heading-subtitle" style="margin-top:6px;">
            Submitted on <?= date('d M Y, h:i A', $submittedTs) ?> • Pending for <strong><?= $pendingDays ?> days</strong>
        </p>
    </div>
    <div>
        <a href="/member/grievances.php" class="btn btn-outline">&larr; Back to My Grievances</a>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     1. GRIEVANCE OVERVIEW CARD
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card" style="margin-bottom:24px;">
    <div class="table-card-header">
        <span class="table-card-title">Grievance Information</span>
        <span style="font-size:0.82rem; color:var(--text-muted);">
            Last activity: <?= date('d M Y, h:i A', strtotime((string)$grievance['last_updated_at'])) ?>
        </span>
    </div>
    <div style="padding:24px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:18px; margin-bottom:20px;">
            <div>
                <label class="form-label" style="color:var(--text-muted); font-size:0.78rem; text-transform:uppercase; font-weight:600;">Service Category</label>
                <div style="font-size:1.05rem; font-weight:700; color:var(--text-main);">
                    <?= Sanitize::html($grievance['category_name'] ?? 'General') ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted); font-size:0.78rem; text-transform:uppercase; font-weight:600;">Specific Service / Issue</label>
                <div style="font-size:1rem; font-weight:600; color:var(--blue-700);">
                    <?= Sanitize::html($grievance['service_name'] ?? 'General Matter') ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted); font-size:0.78rem; text-transform:uppercase; font-weight:600;">Government / Dept Authority</label>
                <div style="font-size:1rem; font-weight:600; color:var(--text-main);">
                    <?= !empty($grievance['authority_name']) ? Sanitize::html($grievance['authority_name']) : '<span style="color:var(--text-muted);">Association Review</span>' ?>
                </div>
            </div>

            <div>
                <label class="form-label" style="color:var(--text-muted); font-size:0.78rem; text-transform:uppercase; font-weight:600;">Responsible Association Level</label>
                <div style="font-size:1rem; font-weight:600; text-transform:capitalize; color:var(--text-main);">
                    <?= Sanitize::html($grievance['current_association_level']) ?> Committee
                </div>
            </div>
        </div>

        <div style="border-top:1px solid #e2e8f0; padding-top:16px; margin-bottom:18px;">
            <label class="form-label" style="color:var(--text-muted); font-size:0.78rem; text-transform:uppercase; font-weight:600;">Subject</label>
            <div style="font-size:1.15rem; font-weight:700; color:var(--text-main); margin-bottom:12px;">
                <?= Sanitize::html($grievance['subject']) ?>
            </div>

            <label class="form-label" style="color:var(--text-muted); font-size:0.78rem; text-transform:uppercase; font-weight:600;">Detailed Grievance Description</label>
            <div style="background:var(--bg-page, #f8fafc); border:1px solid #e2e8f0; border-radius:8px; padding:16px; line-height:1.6; color:var(--text-main); white-space:pre-wrap; font-size:0.95rem;">
                <?= Sanitize::html($grievance['description']) ?>
            </div>
        </div>

        <!-- Attached Documents -->
        <div>
            <label class="form-label" style="color:var(--text-muted); font-size:0.78rem; text-transform:uppercase; font-weight:600;">
                Attached Supporting Documents (<?= count($documents) ?>)
            </label>
            <?php if (empty($documents)): ?>
                <p style="color:var(--text-muted); font-size:0.86rem; margin:4px 0 0 0;">No supporting documents attached.</p>
            <?php else: ?>
                <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:8px;">
                    <?php foreach ($documents as $doc): ?>
                        <div style="display:inline-flex; align-items:center; gap:8px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; padding:8px 14px; font-size:0.88rem;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--blue-600);"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                            <span style="font-weight:600; max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                <?= Sanitize::html($doc['original_filename']) ?>
                            </span>
                            <span style="color:var(--text-muted); font-size:0.75rem;">(<?= Sanitize::html($doc['document_type']) ?>)</span>
                            <a href="/download-grievance-doc.php?id=<?= (int)$doc['id'] ?>" target="_blank" class="btn btn-outline btn-sm" style="padding:3px 8px; font-size:0.75rem;">
                                Download
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     2. CLARIFICATION RESPONSE BOX (WHEN STATUS IS CLARIFICATION REQUIRED)
     ═══════════════════════════════════════════════════════════════════════════ -->
<?php if ($grievance['current_status'] === Grievance::STATUS_CLARIFICATION_REQUIRED): ?>
<div class="table-card" style="margin-bottom:24px; border:2px solid #f59e0b;">
    <div class="table-card-header" style="background:#fef3c7;">
        <span class="table-card-title" style="color:#92400e;">
            ⚠️ Clarification Requested from You
        </span>
    </div>
    <div style="padding:24px;">
        <p style="color:#78350f; font-size:0.92rem; margin-top:0; margin-bottom:16px;">
            The reviewing committee has requested additional clarification or supporting information regarding this grievance. Please enter your reply and attach any requested documents below.
        </p>
        <form method="post" action="/member/grievance-view.php?id=<?= (int)$grievance['id'] ?>" enctype="multipart/form-data">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="respond_clarification">

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label" for="response_remarks">Your Clarification Response *</label>
                <textarea name="response_remarks" id="response_remarks" rows="4" class="form-control" required placeholder="Type your detailed clarification here..."></textarea>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label" for="attachment">
                    Additional Supporting Document
                    <span style="font-weight:400; color:var(--text-muted);">(Max 2 MB • .jpg, .png, .pdf only)</span>
                </label>
                <input type="file" name="attachment" id="attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
            </div>

            <button type="submit" class="btn btn-primary" style="padding:10px 24px;">
                Submit Clarification Response &rarr;
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     3. IMMUTABLE TIMELINE & EVENT HISTORY (MEMBER-VISIBLE ONLY)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card" style="margin-bottom:24px;">
    <div class="table-card-header">
        <span class="table-card-title">Immutable Progress Timeline</span>
        <span style="font-size:0.82rem; color:var(--text-muted);">
            Chronological audit of official updates
        </span>
    </div>
    <div style="padding:24px;">
        <?php if (empty($events)): ?>
            <p style="color:var(--text-muted); text-align:center; padding:20px;">No timeline events recorded.</p>
        <?php else: ?>
            <div style="position:relative; padding-left:24px; border-left:2px solid var(--blue-200, #bfdbfe);">
                <?php foreach ($events as $ev): ?>
                    <div style="position:relative; margin-bottom:22px;">
                        <!-- Timeline bullet -->
                        <div style="position:absolute; left:-31px; top:3px; width:12px; height:12px; border-radius:50%; background:var(--blue-600); border:2px solid #fff; box-shadow:0 0 0 2px var(--blue-200, #bfdbfe);"></div>

                        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:8px;">
                            <div>
                                <strong style="color:var(--blue-800); font-size:0.95rem;">
                                    <?= Sanitize::html($ev['event_type']) ?>
                                </strong>
                                <span class="badge badge-neutral" style="margin-left:6px; font-size:0.75rem; text-transform:capitalize;">
                                    <?= Sanitize::html($ev['association_level']) ?> Level
                                </span>
                                <?php if (!empty($ev['new_status']) && $ev['new_status'] !== $ev['old_status']): ?>
                                    <span style="font-size:0.82rem; color:var(--text-muted); margin-left:6px;">
                                        Status: <strong><?= Sanitize::html($ev['new_status']) ?></strong>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <span style="font-size:0.8rem; color:var(--text-muted);">
                                <?= date('d M Y, h:i A', strtotime((string)$ev['created_at'])) ?>
                            </span>
                        </div>

                        <?php if (!empty($ev['remarks'])): ?>
                            <div style="margin-top:6px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:10px 14px; font-size:0.88rem; color:var(--text-main); line-height:1.5;">
                                <?= Sanitize::html($ev['remarks']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     4. REOPEN GRIEVANCE (IF RESOLVED OR CLOSED)
     ═══════════════════════════════════════════════════════════════════════════ -->
<?php if (in_array($grievance['current_status'], [Grievance::STATUS_RESOLVED, Grievance::STATUS_CLOSED], true)): ?>
<div class="table-card" style="margin-bottom:24px;">
    <div class="table-card-header">
        <span class="table-card-title">Need to Reopen this Grievance?</span>
    </div>
    <div style="padding:24px;">
        <p style="color:var(--text-muted); font-size:0.88rem; margin-top:0; margin-bottom:14px;">
            If the issue has recurred or was not satisfactorily resolved, you may request reopening by explaining the grounds below.
        </p>
        <form method="post" action="/member/grievance-view.php?id=<?= (int)$grievance['id'] ?>">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="reopen">

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label" for="reopen_reason">Reason for Reopening *</label>
                <textarea name="reopen_reason" id="reopen_reason" rows="3" class="form-control" required placeholder="State why this grievance requires further association review..."></textarea>
            </div>

            <button type="submit" class="btn btn-outline" style="border-color:var(--blue-600); color:var(--blue-700);">
                Request to Reopen Grievance
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
