<?php
/**
 * KSPDOWA — Admin: Suggestion Detail & Workflow Management
 * ============================================================
 * Section 28.5, 28.7, 28.8, 28.10, 28.16:
 * - Gated by RBAC permission 'suggestions.view'.
 * - Scope-controlled (Taluk / District / State / Super Admin).
 * - Full Immutable Timeline (internal + member-visible events).
 * - Multi-action workflow:
 *     - Change Permitted Status (Submitted -> Under Review -> Accepted -> Under Consideration -> Implemented -> Not Accepted -> Closed)
 *     - Provide Official Association Response (visible to member + notification)
 *     - Add Internal Administrative Notes (hidden from ordinary members)
 * - Secure document serving via /download-suggestion-doc.php.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/Suggestion.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

RBAC::requirePermission($currentUserId, 'suggestions', 'view');

$id = Sanitize::positiveInt($_GET['id'] ?? null);
if (!$id) {
    ErrorHandler::abort(404, 'Suggestion not found.');
}

// Fetch suggestion with joined member and responder data
$suggestion = Database::fetchOne(
    "SELECT s.*,
            m.name AS member_name, m.member_no, m.designation AS member_designation,
            COALESCE(mp.personal_mobile, u_member.mobile) AS member_phone,
            COALESCE(mp.personal_email, u_member.email) AS member_email,
            mp.kgid_no,
            d.name AS district_name, t.name AS taluk_name, gp.name AS gp_name,
            u_resp.username AS responder_username
     FROM suggestions s
     INNER JOIN members m ON m.id = s.member_id
     LEFT JOIN member_profiles mp ON mp.member_id = m.id
     LEFT JOIN users u_member ON u_member.member_id = m.id
     LEFT JOIN districts d ON d.id = m.district_id
     LEFT JOIN taluks t    ON t.id = m.taluk_id
     LEFT JOIN gram_panchayatis gp ON gp.id = m.gp_id
     LEFT JOIN users u_resp ON u_resp.id = s.responded_by
     WHERE s.id = ?",
    [$id]
);

if (!$suggestion) {
    ErrorHandler::abort(404, 'Suggestion not found.');
}

// Strict Scope Verification (URL / ID manipulation prevention)
if (!Suggestion::canOfficerAccess($currentUserId, $suggestion)) {
    AuditLogger::log('ACCESS_DENIED', 'suggestions', $id, null, [
        'user_id' => $currentUserId,
        'reason'  => 'Out-of-scope suggestion access attempt',
    ]);
    ErrorHandler::abort(403, 'Access denied. This suggestion is outside your authorized jurisdiction.');
}

// ─── Handle Workflow Actions ─────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();
    RBAC::requirePermission($currentUserId, 'suggestions', 'manage');
    $action = Sanitize::string($_POST['action'] ?? '', 40);

    // 1. Status Update
    if ($action === 'update_status') {
        $newStatus       = Sanitize::string($_POST['new_status'] ?? '', 50);
        $remarks         = trim(Sanitize::string($_POST['remarks'] ?? '', 2000));
        $isMemberVisible = isset($_POST['is_member_visible']) ? true : false;

        if (!in_array($newStatus, Suggestion::ALL_STATUSES, true)) {
            Session::flash('error', 'Invalid status selected.');
        } else {
            try {
                Suggestion::updateStatus($id, $currentUserId, $newStatus, $remarks, $isMemberVisible);
                Session::flash('success', "Suggestion status updated to '{$newStatus}'.");
            } catch (Throwable $e) {
                Session::flash('error', 'Status update failed: ' . $e->getMessage());
            }
            header('Location: /admin/suggestion-view.php?id=' . $id);
            exit;
        }
    }

    // 2. Association Response
    if ($action === 'association_response') {
        $responseText = trim(Sanitize::string($_POST['association_response'] ?? '', 5000));
        $newStatus    = Sanitize::string($_POST['response_status'] ?? '', 50);

        if ($responseText === '') {
            Session::flash('error', 'Association response text cannot be empty.');
        } else {
            try {
                Suggestion::addResponse(
                    $id,
                    $currentUserId,
                    $responseText,
                    $newStatus !== '' ? $newStatus : null
                );
                Session::flash('success', 'Official Association response recorded and member notified.');
            } catch (Throwable $e) {
                Session::flash('error', 'Response recording failed: ' . $e->getMessage());
            }
            header('Location: /admin/suggestion-view.php?id=' . $id);
            exit;
        }
    }

    // 3. Internal Admin Note
    if ($action === 'admin_note') {
        $noteText = trim(Sanitize::string($_POST['admin_note'] ?? '', 3000));

        if ($noteText === '') {
            Session::flash('error', 'Internal note text cannot be empty.');
        } else {
            try {
                Suggestion::addAdminNote($id, $currentUserId, $noteText);
                Session::flash('success', 'Internal administrative note recorded (hidden from member).');
            } catch (Throwable $e) {
                Session::flash('error', 'Failed to add note: ' . $e->getMessage());
            }
            header('Location: /admin/suggestion-view.php?id=' . $id);
            exit;
        }
    }
}

// Supporting Documents
$documents = Database::fetchAll(
    "SELECT * FROM suggestion_documents WHERE suggestion_id = ? ORDER BY id ASC",
    [$id]
);

// Full Timeline Events (All events, including internal notes)
$events = Database::fetchAll(
    "SELECT se.*, u.username AS performed_by_username
     FROM suggestion_events se
     LEFT JOIN users u ON u.id = se.performed_by
     WHERE se.suggestion_id = ?
     ORDER BY se.created_at ASC, se.id ASC",
    [$id]
);

$canManage = RBAC::hasPermission($currentUserId, 'suggestions', 'manage');

$pageTitle  = 'Review Suggestion — ' . $suggestion['suggestion_no'];
$activeMenu = 'suggestions';
$breadcrumbs = [
    ['label' => 'Admin Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Members Suggestions', 'url' => '/admin/suggestions.php'],
    ['label' => $suggestion['suggestion_no'], 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     HEADER ROW
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="page-header-row" style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px; flex-wrap:wrap; gap:16px;">
    <div>
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:6px;">
            <a href="/admin/suggestions.php" class="btn btn-secondary btn-sm" style="display:inline-flex; align-items:center; gap:4px; padding:6px 12px; border-radius:6px; text-decoration:none;">
                ← Back to List
            </a>
            <h1 class="page-heading-title" style="margin:0; font-size:1.5rem; font-weight:800; color:var(--text-main);">
                <?= Sanitize::html($suggestion['suggestion_no']) ?>
            </h1>
            <?= Suggestion::getStatusBadge((string)$suggestion['current_status']) ?>
        </div>
        <p class="page-heading-subtitle" style="margin:0; color:var(--text-muted); font-size:0.88rem;">
            Submitted by <strong><?= Sanitize::html($suggestion['member_name']) ?></strong> (<?= Sanitize::html($suggestion['member_no'] ?? '') ?>)
            on <?= date('d M Y, h:i A', strtotime((string)$suggestion['submitted_at'])) ?>
            • Last updated <?= date('d M Y, h:i A', strtotime((string)$suggestion['last_updated_at'])) ?>
        </p>
    </div>
</div>

<div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; align-items:start;">
    <!-- ═══════════════════════════════════════════════════════════════════════
         LEFT COLUMN: DETAILS, ASSOCIATION RESPONSE, WORKFLOW ACTIONS
         ═══════════════════════════════════════════════════════════════════════ -->
    <div style="display:flex; flex-direction:column; gap:24px;">
        <!-- Suggestion Content Card -->
        <div class="table-card" style="padding:24px;">
            <h2 style="font-size:1.2rem; font-weight:700; color:var(--text-main); margin:0 0 16px 0; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
                <?= Sanitize::html($suggestion['subject']) ?>
            </h2>

            <div style="color:var(--text-muted); font-size:0.8rem; text-transform:uppercase; font-weight:600; margin-bottom:8px;">
                Suggestion / Description
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

        <!-- Current Official Response View -->
        <?php if (!empty($suggestion['association_response'])): ?>
            <div class="table-card" style="padding:24px; border-left:4px solid var(--success-green, #10b981);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <h3 style="margin:0; font-size:1.05rem; font-weight:700; color:var(--text-main);">
                        Official Association Response (Visible to Member)
                    </h3>
                    <span class="badge badge-success">Active Response</span>
                </div>
                <div style="font-size:0.95rem; line-height:1.7; color:#0f172a; background:#f0fdf4; padding:16px; border-radius:8px; border:1px solid #bbf7d0; white-space:pre-line;">
                    <?= Sanitize::html($suggestion['association_response']) ?>
                </div>
                <div style="margin-top:12px; display:flex; justify-content:space-between; font-size:0.82rem; color:var(--text-muted);">
                    <div>
                        Recorded by: <strong><?= Sanitize::html($suggestion['responder_username'] ?? 'Officer') ?></strong>
                    </div>
                    <div>
                        Date: <strong><?= date('d M Y, h:i A', strtotime((string)$suggestion['responded_at'])) ?></strong>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ═══════════════════════════════════════════════════════════════════
             WORKFLOW ACTION PANELS (Authorized Officers)
             ═══════════════════════════════════════════════════════════════════ -->
        <?php if ($canManage): ?>
            <!-- 1. Association Response Form (§28.7) -->
            <div class="table-card" style="padding:24px;">
                <h3 style="margin:0 0 8px 0; font-size:1.1rem; font-weight:700; color:var(--text-main); display:flex; align-items:center; gap:8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                    Provide Official Association Response
                </h3>
                <p style="margin:0 0 16px 0; color:var(--text-muted); font-size:0.86rem;">
                    This response will be visible to the submitting member on their portal. Submitting a response will also notify the member in-app.
                </p>

                <form method="post" action="/admin/suggestion-view.php?id=<?= $id ?>">
                    <?= CSRF::htmlField() ?>
                    <input type="hidden" name="action" value="association_response">

                    <div class="form-group" style="margin-bottom:14px;">
                        <label class="form-label" for="association_response" style="font-weight:600; font-size:0.86rem; margin-bottom:6px; display:block;">
                            Association Response / Remarks *
                        </label>
                        <textarea name="association_response" id="association_response" rows="4" class="form-control" required placeholder="Enter the official response, committee decision, or remarks to the member..." style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.9rem;"><?= Sanitize::html($suggestion['association_response'] ?? '') ?></textarea>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <label class="form-label" for="response_status" style="font-size:0.86rem; font-weight:600; margin:0;">
                                Update Status to:
                            </label>
                            <select name="response_status" id="response_status" class="form-select" style="padding:6px 12px; border-radius:6px; font-size:0.86rem;">
                                <option value="">— Keep Current (<?= Sanitize::html($suggestion['current_status']) ?>) —</option>
                                <?php foreach (Suggestion::ALL_STATUSES as $st): ?>
                                    <option value="<?= Sanitize::attr($st) ?>" <?= ($suggestion['current_status'] === $st) ? 'selected' : '' ?>>
                                        <?= Sanitize::html($st) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary" style="padding:8px 20px; font-weight:600; border-radius:6px;">
                            Save &amp; Publish Response
                        </button>
                    </div>
                </form>
            </div>

            <!-- 2. Status Transition Panel (§28.4) -->
            <div class="table-card" style="padding:24px;">
                <h3 style="margin:0 0 8px 0; font-size:1.1rem; font-weight:700; color:var(--text-main); display:flex; align-items:center; gap:8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Change Permitted Status
                </h3>
                <p style="margin:0 0 16px 0; color:var(--text-muted); font-size:0.86rem;">
                    Update the suggestion lifecycle state. Status changes are permanently recorded in the immutable audit timeline.
                </p>

                <form method="post" action="/admin/suggestion-view.php?id=<?= $id ?>">
                    <?= CSRF::htmlField() ?>
                    <input type="hidden" name="action" value="update_status">

                    <div style="display:grid; grid-template-columns: 1fr 2fr; gap:16px; margin-bottom:14px;">
                        <div>
                            <label class="form-label" for="new_status" style="font-weight:600; font-size:0.86rem; margin-bottom:6px; display:block;">
                                Target Status *
                            </label>
                            <select name="new_status" id="new_status" class="form-select" required style="width:100%; padding:9px 12px; border-radius:6px; font-size:0.88rem;">
                                <?php foreach (Suggestion::ALL_STATUSES as $st): ?>
                                    <option value="<?= Sanitize::attr($st) ?>" <?= ($suggestion['current_status'] === $st) ? 'selected' : '' ?>>
                                        <?= Sanitize::html($st) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="remarks" style="font-weight:600; font-size:0.86rem; margin-bottom:6px; display:block;">
                                Status Change Remarks (Optional)
                            </label>
                            <input type="text" name="remarks" id="remarks" class="form-control" placeholder="e.g. Under consideration by Executive Committee" style="width:100%; padding:9px 12px; border-radius:6px; font-size:0.88rem;">
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                        <label style="display:flex; align-items:center; gap:8px; font-size:0.86rem; cursor:pointer; color:var(--text-main);">
                            <input type="checkbox" name="is_member_visible" value="1" checked>
                            <span>Visible to member on their portal timeline</span>
                        </label>
                        <button type="submit" class="btn btn-secondary" style="padding:8px 20px; font-weight:600; border-radius:6px;">
                            Update Status
                        </button>
                    </div>
                </form>
            </div>

            <!-- 3. Internal Administrative Notes Form (§28.7) -->
            <div class="table-card" style="padding:24px; border-left:4px solid #64748b;">
                <h3 style="margin:0 0 8px 0; font-size:1.1rem; font-weight:700; color:var(--text-main); display:flex; align-items:center; gap:8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Internal Administrative Note (Leadership Only)
                </h3>
                <p style="margin:0 0 16px 0; color:var(--text-muted); font-size:0.86rem;">
                    Internal notes are recorded for Association office records only. They are <strong>never visible to ordinary members</strong>.
                </p>

                <form method="post" action="/admin/suggestion-view.php?id=<?= $id ?>">
                    <?= CSRF::htmlField() ?>
                    <input type="hidden" name="action" value="admin_note">

                    <div class="form-group" style="margin-bottom:14px;">
                        <textarea name="admin_note" rows="3" class="form-control" required placeholder="Add confidential administrative notes, committee review comments, or internal feedback..." style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.9rem;"></textarea>
                    </div>

                    <div style="display:flex; justify-content:flex-end;">
                        <button type="submit" class="btn btn-secondary" style="padding:8px 20px; font-weight:600; border-radius:6px;">
                            Record Internal Note
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         RIGHT COLUMN: MEMBER PROFILE & IMMUTABLE TIMELINE
         ═══════════════════════════════════════════════════════════════════════ -->
    <div style="display:flex; flex-direction:column; gap:24px;">
        <!-- Submitting Member Profile Card -->
        <div class="table-card" style="padding:20px;">
            <h3 style="margin:0 0 14px 0; font-size:0.95rem; font-weight:700; color:var(--text-main); text-transform:uppercase; letter-spacing:0.5px;">
                Member Details
            </h3>
            <div style="display:flex; flex-direction:column; gap:10px; font-size:0.88rem;">
                <div>
                    <span style="color:var(--text-muted); display:block; font-size:0.75rem; text-transform:uppercase;">Full Name</span>
                    <strong style="color:var(--text-main); font-size:0.95rem;"><?= Sanitize::html($suggestion['member_name']) ?></strong>
                </div>
                <div>
                    <span style="color:var(--text-muted); display:block; font-size:0.75rem; text-transform:uppercase;">Membership No.</span>
                    <strong style="color:var(--primary);"><?= Sanitize::html($suggestion['member_no'] ?? 'N/A') ?></strong>
                </div>
                <div>
                    <span style="color:var(--text-muted); display:block; font-size:0.75rem; text-transform:uppercase;">Designation</span>
                    <span><?= Sanitize::html($suggestion['member_designation'] ?? 'Panchayat Development Officer') ?></span>
                </div>
                <div>
                    <span style="color:var(--text-muted); display:block; font-size:0.75rem; text-transform:uppercase;">Jurisdiction</span>
                    <span>
                        <?= Sanitize::html($suggestion['taluk_name'] ?? 'Taluk') ?>, <?= Sanitize::html($suggestion['district_name'] ?? 'District') ?>
                        <?php if (!empty($suggestion['gp_name'])): ?>
                            <br><small style="color:var(--text-muted);">(GP: <?= Sanitize::html($suggestion['gp_name']) ?>)</small>
                        <?php endif; ?>
                    </span>
                </div>
                <div>
                    <span style="color:var(--text-muted); display:block; font-size:0.75rem; text-transform:uppercase;">Contact</span>
                    <span>
                        📞 <?= Sanitize::html($suggestion['member_phone'] ?? 'N/A') ?>
                        <?php if (!empty($suggestion['member_email'])): ?>
                            <br>✉️ <?= Sanitize::html($suggestion['member_email']) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div>
                    <span style="color:var(--text-muted); display:block; font-size:0.75rem; text-transform:uppercase;">KGID No.</span>
                    <span><?= Sanitize::html($suggestion['kgid_no'] ?? 'N/A') ?></span>
                </div>
            </div>
        </div>

        <!-- Full Immutable Timeline Card (§28.8) -->
        <div class="table-card" style="padding:20px;">
            <h3 style="margin:0 0 16px 0; font-size:0.95rem; font-weight:700; color:var(--text-main); text-transform:uppercase; letter-spacing:0.5px;">
                Immutable History Timeline
            </h3>

            <div class="timeline" style="position:relative; padding-left:24px; display:flex; flex-direction:column; gap:16px;">
                <div style="position:absolute; left:7px; top:4px; bottom:4px; width:2px; background:#e2e8f0;"></div>

                <?php foreach ($events as $ev): ?>
                    <div style="position:relative;">
                        <div style="position:absolute; left:-24px; top:4px; width:14px; height:14px; border-radius:50%; background:<?= $ev['is_member_visible'] ? 'var(--primary)' : '#64748b' ?>; border:2px solid #fff; box-shadow:0 0 0 2px <?= $ev['is_member_visible'] ? 'var(--primary)' : '#64748b' ?>;"></div>
                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
                                <div style="font-weight:700; font-size:0.86rem; color:var(--text-main);">
                                    <?php if ($ev['event_type'] === 'SUBMIT'): ?>
                                        Suggestion Submitted
                                    <?php elseif ($ev['event_type'] === 'RESPONSE'): ?>
                                        Association Response Added
                                    <?php elseif ($ev['event_type'] === 'STATUS_CHANGE'): ?>
                                        Status Changed → <?= Sanitize::html($ev['new_status'] ?? '') ?>
                                    <?php elseif ($ev['event_type'] === 'NOTE'): ?>
                                        Internal Admin Note
                                    <?php else: ?>
                                        <?= Sanitize::html($ev['event_type']) ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($ev['is_member_visible']): ?>
                                    <span class="badge badge-success" style="font-size:0.7rem; padding:2px 6px;">Public</span>
                                <?php else: ?>
                                    <span class="badge badge-neutral" style="font-size:0.7rem; padding:2px 6px;">Internal</span>
                                <?php endif; ?>
                            </div>

                            <div style="font-size:0.78rem; color:var(--text-muted); margin-top:2px;">
                                <?= date('d M Y, h:i A', strtotime((string)$ev['created_at'])) ?>
                                • By <?= Sanitize::html($ev['performed_by_username'] ?? 'User') ?>
                            </div>

                            <?php if (!empty($ev['remarks'])): ?>
                                <div style="font-size:0.82rem; color:var(--text-main); margin-top:6px; background:#f8fafc; padding:8px 10px; border-radius:6px; border:1px solid #e2e8f0; white-space:pre-line;">
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
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
