<?php
/**
 * KSPDOWA — Member Portal: View Suggestion Details
 * ============================================================
 * Section 28.1, 28.7, 28.8, 28.16:
 * - Logged-in eligible Members ONLY.
 * - Strict member ownership check: Member must NEVER see another member's suggestion.
 * - View suggestion details, status, submission date, attachment.
 * - View official Association response, responding role, and response date.
 * - Internal administrative notes are NEVER visible to ordinary members.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/Suggestion.php';

Auth::requireLogin();
$currentUserId   = Auth::getCurrentUserId();
$currentMemberId = Auth::getCurrentMemberId();

if ($currentMemberId === null) {
    header('Location: /login.php');
    exit;
}

$id = Sanitize::positiveInt($_GET['id'] ?? null);
if (!$id) {
    ErrorHandler::abort(404, 'Suggestion not found.');
}

// Fetch suggestion with strict member ownership verification
$suggestion = Database::fetchOne(
    "SELECT s.*,
            u_resp.username AS responder_username,
            r_resp.name AS responder_role_name
     FROM suggestions s
     LEFT JOIN users u_resp ON u_resp.id = s.responded_by
     LEFT JOIN user_roles ur_resp ON ur_resp.user_id = u_resp.id
     LEFT JOIN roles r_resp ON r_resp.id = ur_resp.role_id
     WHERE s.id = ?",
    [$id]
);

if (!$suggestion) {
    ErrorHandler::abort(404, 'Suggestion not found.');
}

// Strict IDOR Protection: Member can ONLY see their own suggestions
if ((int)$suggestion['member_id'] !== $currentMemberId) {
    AuditLogger::log('ACCESS_DENIED', 'suggestions', $id, null, [
        'user_id'   => $currentUserId,
        'member_id' => $currentMemberId,
        'reason'    => 'Attempted to view suggestion belonging to another member',
    ]);
    ErrorHandler::abort(403, 'Access denied. You are only authorized to view your own suggestions.');
}

// Fetch supporting documents
$documents = Database::fetchAll(
    "SELECT * FROM suggestion_documents WHERE suggestion_id = ? ORDER BY id ASC",
    [$id]
);

// Fetch member-visible timeline events ONLY (is_member_visible = 1)
$events = Database::fetchAll(
    "SELECT se.*, u.username AS performed_by_username
     FROM suggestion_events se
     LEFT JOIN users u ON u.id = se.performed_by
     WHERE se.suggestion_id = ? AND se.is_member_visible = 1
     ORDER BY se.created_at ASC, se.id ASC",
    [$id]
);

$pageTitle  = 'Suggestion Details — ' . $suggestion['suggestion_no'];
$activeMenu = 'suggestions';
$breadcrumbs = [
    ['label' => 'Member Portal', 'url' => '/member/index.php'],
    ['label' => 'My Suggestions', 'url' => '/member/suggestions.php'],
    ['label' => $suggestion['suggestion_no'], 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';
?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     HEADER ROW
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="page-header-row" style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px; flex-wrap:wrap; gap:16px;">
    <div>
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:6px;">
            <a href="/member/suggestions.php" class="btn btn-secondary btn-sm" style="display:inline-flex; align-items:center; gap:4px; padding:6px 12px; border-radius:6px; text-decoration:none;">
                ← Back to List
            </a>
            <h1 class="page-heading-title" style="margin:0; font-size:1.5rem; font-weight:800; color:var(--text-main);">
                <?= Sanitize::html($suggestion['suggestion_no']) ?>
            </h1>
            <?= Suggestion::getStatusBadge((string)$suggestion['current_status']) ?>
        </div>
        <p class="page-heading-subtitle" style="margin:0; color:var(--text-muted); font-size:0.88rem;">
            Submitted on <?= date('d M Y, h:i A', strtotime((string)$suggestion['submitted_at'])) ?>
            • Last updated <?= date('d M Y, h:i A', strtotime((string)$suggestion['last_updated_at'])) ?>
        </p>
    </div>
</div>

<div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; align-items:start;">
    <!-- ═══════════════════════════════════════════════════════════════════════
         LEFT COLUMN: DETAILS & ASSOCIATION RESPONSE
         ═══════════════════════════════════════════════════════════════════════ -->
    <div style="display:flex; flex-direction:column; gap:20px;">
        <!-- Suggestion Content Card -->
        <div class="table-card" style="padding:24px;">
            <h2 style="font-size:1.15rem; font-weight:700; color:var(--text-main); margin:0 0 16px 0; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
                <?= Sanitize::html($suggestion['subject']) ?>
            </h2>

            <div style="color:var(--text-muted); font-size:0.8rem; text-transform:uppercase; font-weight:600; margin-bottom:8px;">
                Description / Recommendation
            </div>
            <div style="font-size:0.95rem; line-height:1.7; color:var(--text-main); white-space:pre-line; background:#f8fafc; padding:16px; border-radius:8px; border:1px solid #e2e8f0;">
                <?= Sanitize::html($suggestion['description']) ?>
            </div>

            <!-- Supporting Document -->
            <?php if (!empty($documents)): ?>
                <div style="margin-top:20px; border-top:1px solid #f1f5f9; padding-top:16px;">
                    <div style="color:var(--text-muted); font-size:0.8rem; text-transform:uppercase; font-weight:600; margin-bottom:10px;">
                        Attached Supporting Document
                    </div>
                    <?php foreach ($documents as $doc): ?>
                        <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:#fff; border:1px solid #e2e8f0; border-radius:8px;">
                            <div style="display:flex; align-items:center; gap:12px;">
                                <div style="width:36px; height:36px; background:#eff6ff; border-radius:8px; display:flex; align-items:center; justify-content:center; color:var(--primary); font-size:1.1rem;">
                                    <?php if (str_contains((string)$doc['mime_type'], 'pdf')): ?>
                                        📄
                                    <?php else: ?>
                                        🖼️
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div style="font-weight:600; font-size:0.88rem; color:var(--text-main);">
                                        <?= Sanitize::html($doc['original_filename']) ?>
                                    </div>
                                    <div style="font-size:0.78rem; color:var(--text-muted);">
                                        <?= number_format((int)$doc['file_size'] / 1024, 1) ?> KB • Uploaded on <?= date('d M Y', strtotime((string)$doc['uploaded_at'])) ?>
                                    </div>
                                </div>
                            </div>
                            <a href="/download-suggestion-doc.php?id=<?= (int)$doc['id'] ?>" target="_blank" class="btn btn-secondary btn-sm" style="display:inline-flex; align-items:center; gap:6px; padding:6px 14px; font-size:0.82rem; text-decoration:none;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                View / Download
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Official Association Response Card (§28.7) -->
        <div class="table-card" style="padding:24px; border-left:4px solid <?= !empty($suggestion['association_response']) ? 'var(--success-green, #10b981)' : 'var(--blue-600)' ?>;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">
                <h3 style="margin:0; font-size:1.05rem; font-weight:700; color:var(--text-main); display:flex; align-items:center; gap:8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                    Official Association Response
                </h3>
                <?php if (!empty($suggestion['association_response'])): ?>
                    <span class="badge badge-success">Response Received</span>
                <?php else: ?>
                    <span class="badge badge-neutral">Awaiting Review</span>
                <?php endif; ?>
            </div>

            <?php if (!empty($suggestion['association_response'])): ?>
                <div style="font-size:0.95rem; line-height:1.7; color:#0f172a; background:#f0fdf4; padding:18px; border-radius:8px; border:1px solid #bbf7d0; white-space:pre-line;">
                    <?= Sanitize::html($suggestion['association_response']) ?>
                </div>
                <div style="margin-top:14px; display:flex; flex-wrap:wrap; justify-content:space-between; font-size:0.82rem; color:var(--text-muted);">
                    <div>
                        Responding Authority:
                        <strong style="color:var(--text-main);">
                            <?= Sanitize::html($suggestion['responder_role_name'] ?? 'Association Leadership') ?>
                        </strong>
                    </div>
                    <div>
                        Response Date:
                        <strong style="color:var(--text-main);">
                            <?= date('d M Y, h:i A', strtotime((string)$suggestion['responded_at'])) ?>
                        </strong>
                    </div>
                </div>
            <?php else: ?>
                <div style="padding:20px; text-align:center; color:var(--text-muted); background:#f8fafc; border-radius:8px;">
                    <div style="font-size:1.5rem; margin-bottom:6px;">⏳</div>
                    <div style="font-weight:600; color:var(--text-main); font-size:0.92rem;">Your suggestion is under consideration.</div>
                    <div style="font-size:0.84rem; margin-top:4px;">
                        The Association leadership reviews member suggestions periodically. Once an official review or response is recorded, it will be posted here.
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         RIGHT COLUMN: TIMELINE & SUMMARY
         ═══════════════════════════════════════════════════════════════════════ -->
    <div style="display:flex; flex-direction:column; gap:20px;">
        <!-- Status & Meta Card -->
        <div class="table-card" style="padding:20px;">
            <h3 style="margin:0 0 14px 0; font-size:0.95rem; font-weight:700; color:var(--text-main); text-transform:uppercase; letter-spacing:0.5px;">
                Suggestion Overview
            </h3>
            <div style="display:flex; flex-direction:column; gap:12px; font-size:0.88rem;">
                <div style="display:flex; justify-content:space-between;">
                    <span style="color:var(--text-muted);">Status:</span>
                    <span><?= Suggestion::getStatusBadge((string)$suggestion['current_status']) ?></span>
                </div>
                <div style="display:flex; justify-content:space-between;">
                    <span style="color:var(--text-muted);">Reference No:</span>
                    <strong style="color:var(--text-main);"><?= Sanitize::html($suggestion['suggestion_no']) ?></strong>
                </div>
                <div style="display:flex; justify-content:space-between;">
                    <span style="color:var(--text-muted);">Submitted Date:</span>
                    <span style="color:var(--text-main);"><?= date('d M Y', strtotime((string)$suggestion['submitted_at'])) ?></span>
                </div>
                <?php if ($suggestion['closed_at']): ?>
                    <div style="display:flex; justify-content:space-between;">
                        <span style="color:var(--text-muted);">Closed Date:</span>
                        <span style="color:var(--text-main);"><?= date('d M Y', strtotime((string)$suggestion['closed_at'])) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Timeline / History Card (§28.8) -->
        <div class="table-card" style="padding:20px;">
            <h3 style="margin:0 0 16px 0; font-size:0.95rem; font-weight:700; color:var(--text-main); text-transform:uppercase; letter-spacing:0.5px;">
                Activity Timeline
            </h3>

            <div class="timeline" style="position:relative; padding-left:24px; display:flex; flex-direction:column; gap:16px;">
                <div style="position:absolute; left:7px; top:4px; bottom:4px; width:2px; background:#e2e8f0;"></div>

                <?php foreach ($events as $ev): ?>
                    <div style="position:relative;">
                        <div style="position:absolute; left:-24px; top:4px; width:14px; height:14px; border-radius:50%; background:var(--primary); border:2px solid #fff; box-shadow:0 0 0 2px var(--primary);"></div>
                        <div>
                            <div style="font-weight:700; font-size:0.86rem; color:var(--text-main);">
                                <?php if ($ev['event_type'] === 'SUBMIT'): ?>
                                    Suggestion Submitted
                                <?php elseif ($ev['event_type'] === 'RESPONSE'): ?>
                                    Association Response Added
                                <?php elseif ($ev['event_type'] === 'STATUS_CHANGE'): ?>
                                    Status Changed to <?= Sanitize::html($ev['new_status'] ?? '') ?>
                                <?php else: ?>
                                    <?= Sanitize::html($ev['event_type']) ?>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:0.78rem; color:var(--text-muted); margin-top:2px;">
                                <?= date('d M Y, h:i A', strtotime((string)$ev['created_at'])) ?>
                            </div>
                            <?php if (!empty($ev['remarks']) && $ev['event_type'] !== 'RESPONSE'): ?>
                                <div style="font-size:0.82rem; color:var(--text-muted); margin-top:4px;">
                                    <?= Sanitize::html($ev['remarks']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
