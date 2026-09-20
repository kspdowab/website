<?php
/**
 * KSPDOWA — Admin: Grievance Detail & Workflow Management
 * ============================================================
 * Section 16, 17, 19, 20: Admin Grievance Detail & Action Engine
 * - Scope-controlled (Taluk / District / State / Super Admin).
 * - Full Immutable Timeline (internal + member-visible events).
 * - Multi-action workflow:
 *     - Verify, Accept, Mark Under Review
 *     - Assign Officer (releases old, creates new assignment)
 *     - Forward (Taluk -> District -> State, Authority tracking)
 *     - Escalate (authorized escalation with justification)
 *     - Request Clarification from Member
 *     - Record Action Taken (with remarks, authority update, document)
 *     - Resolve, Reject, Close, Reopen
 *     - Internal Administrative Notes
 * - Secure document serving via /download-grievance-doc.php.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

RBAC::requirePermission($currentUserId, 'grievances', 'view');

$id = Sanitize::positiveInt($_GET['id'] ?? null);
if (!$id) {
    ErrorHandler::abort(404, 'Grievance not found.');
}

// Fetch grievance with joined member and master data
$grievance = Database::fetchOne(
    "SELECT g.*,
            m.name AS member_name, m.member_no, m.designation AS member_designation,
            COALESCE(mp.personal_mobile, u_member.mobile) AS member_phone,
            COALESCE(mp.personal_email, u_member.email) AS member_email,
            mp.kgid_no,
            d.name AS district_name, t.name AS taluk_name, gp.name AS gp_name,
            gc.name AS category_name, gs.name AS service_name,
            ga.name AS authority_name, ga.code AS authority_code,
            u_assignee.username AS assignee_username, u_assignee.email AS assignee_email
     FROM grievances g
     INNER JOIN members m ON m.id = g.member_id
     LEFT JOIN member_profiles mp ON mp.member_id = m.id
     LEFT JOIN users u_member ON u_member.member_id = m.id
     LEFT JOIN districts d ON d.id = m.district_id
     LEFT JOIN taluks t    ON t.id = m.taluk_id
     LEFT JOIN gram_panchayatis gp ON gp.id = m.gp_id
     LEFT JOIN grievance_categories gc ON gc.id = g.category_id
     LEFT JOIN grievance_services gs   ON gs.id = g.service_id
     LEFT JOIN grievance_authorities ga ON ga.id = g.current_authority
     LEFT JOIN users u_assignee ON u_assignee.id = g.current_assignee
     WHERE g.id = ?",
    [$id]
);

if (!$grievance) {
    ErrorHandler::abort(404, 'Grievance not found.');
}

// Strict Scope Verification (URL / ID manipulation prevention)
if (!Grievance::canOfficerAccess($currentUserId, $grievance)) {
    AuditLogger::log('ACCESS_DENIED', 'grievances', $id, null, [
        'user_id' => $currentUserId,
        'reason'  => 'Out-of-scope grievance access attempt',
    ]);
    ErrorHandler::abort(403, 'Access denied. This grievance is outside your jurisdiction.');
}

$scope = Grievance::getOfficerScope($currentUserId);
$currentLevel = $grievance['current_association_level'];

// Master authorities list
$authorities = Database::fetchAll("SELECT * FROM grievance_authorities WHERE status = 'active' ORDER BY id ASC");

// Fetch officers eligible for assignment (active officers with grievances permission)
$officers = Database::fetchAll(
    "SELECT DISTINCT u.id, u.username, u.email, m.name AS member_name, r.name AS role_name
     FROM users u
     LEFT JOIN members m ON m.id = u.member_id
     JOIN user_roles ur ON ur.user_id = u.id
     JOIN roles r ON r.id = ur.role_id
     WHERE u.status = 'active' AND r.name != 'Registered Member'
     ORDER BY u.username ASC"
);

// ─── Handle Workflow Actions ─────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();
    $action = Sanitize::string($_POST['action'] ?? '', 40);

    // 1. Status Update: Verify / Accept / Review
    if (in_array($action, ['verify', 'accept', 'review'], true)) {
        RBAC::requirePermission($currentUserId, 'grievances', 'edit');
        $statusMap = [
            'verify' => Grievance::STATUS_UNDER_VERIFICATION,
            'accept' => Grievance::STATUS_ACCEPTED,
            'review' => Grievance::STATUS_UNDER_REVIEW,
        ];
        $newStatus = $statusMap[$action];
        $remarks   = trim(Sanitize::string($_POST['remarks'] ?? '', 2000)) ?: "Status transitioned to {$newStatus}.";
        $isMemberVisible = isset($_POST['is_member_visible']) ? 1 : 0;

        Grievance::updateStatusDirect($id, $currentUserId, $currentLevel, $newStatus, $remarks, $isMemberVisible);
        Session::flash('success', "Grievance status updated to {$newStatus}.");
        header('Location: /admin/grievance-view.php?id=' . $id);
        exit;
    }

    // 2. Assign Responsible Officer
    if ($action === 'assign') {
        RBAC::requirePermission($currentUserId, 'grievances', 'assign');
        $assigneeId = Sanitize::positiveInt($_POST['assignee_id'] ?? null);
        $remarks    = trim(Sanitize::string($_POST['remarks'] ?? '', 2000)) ?: 'Assigned to officer for review.';
        $isMemberVisible = isset($_POST['is_member_visible']) ? 1 : 1;

        if (!$assigneeId) {
            Session::flash('error', 'Please select an officer to assign.');
        } else {
            Grievance::assignOfficer($id, $currentUserId, $assigneeId, $currentLevel, $remarks, $isMemberVisible);
            Session::flash('success', 'Grievance assigned successfully.');
            header('Location: /admin/grievance-view.php?id=' . $id);
            exit;
        }
    }

    // 3. Forward Grievance (Level forwarding or Authority update)
    if ($action === 'forward') {
        RBAC::requirePermission($currentUserId, 'grievances', 'forward');
        $toLevel       = Sanitize::inArray($_POST['to_level'] ?? '', [Grievance::LEVEL_TALUK, Grievance::LEVEL_DISTRICT, Grievance::LEVEL_STATE]) ?: $currentLevel;
        $toAuthorityId = Sanitize::positiveInt($_POST['to_authority_id'] ?? null);
        $toAssigneeId  = Sanitize::positiveInt($_POST['to_assignee_id'] ?? null);
        $remarks       = trim(Sanitize::string($_POST['remarks'] ?? '', 3000));
        $isMemberVisible = isset($_POST['is_member_visible']) ? 1 : 1;

        if ($remarks === '') {
            Session::flash('error', 'Forwarding remarks are required.');
        } else {
            Grievance::forwardGrievance($id, $currentUserId, $toLevel, $toAuthorityId, $toAssigneeId, $remarks, $isMemberVisible);
            Session::flash('success', 'Grievance forwarded successfully.');
            header('Location: /admin/grievance-view.php?id=' . $id);
            exit;
        }
    }

    // 4. Escalate Grievance
    if ($action === 'escalate') {
        RBAC::requirePermission($currentUserId, 'grievances', 'escalate');
        $toLevel = Sanitize::inArray($_POST['to_level'] ?? '', [Grievance::LEVEL_DISTRICT, Grievance::LEVEL_STATE]) ?: Grievance::LEVEL_STATE;
        $remarks = trim(Sanitize::string($_POST['remarks'] ?? '', 3000));
        $isMemberVisible = isset($_POST['is_member_visible']) ? 1 : 1;

        if ($remarks === '') {
            Session::flash('error', 'Escalation justification remarks are required.');
        } else {
            Grievance::escalateGrievance($id, $currentUserId, $toLevel, $remarks, $isMemberVisible);
            Session::flash('success', 'Grievance escalated successfully.');
            header('Location: /admin/grievance-view.php?id=' . $id);
            exit;
        }
    }

    // 5. Request Clarification from Member
    if ($action === 'request_clarification') {
        RBAC::requirePermission($currentUserId, 'grievances', 'edit');
        $remarks = trim(Sanitize::string($_POST['remarks'] ?? '', 3000));

        if ($remarks === '') {
            Session::flash('error', 'Clarification details are required.');
        } else {
            Grievance::requestClarification($id, $currentUserId, $currentLevel, $remarks);
            Session::flash('success', 'Clarification request sent to member.');
            header('Location: /admin/grievance-view.php?id=' . $id);
            exit;
        }
    }

    // 6. Action Taken
    if ($action === 'action_taken') {
        RBAC::requirePermission($currentUserId, 'grievances', 'edit');
        $remarks     = trim(Sanitize::string($_POST['remarks'] ?? '', 4000));
        $authorityId = Sanitize::positiveInt($_POST['authority_id'] ?? null);
        $isMemberVisible = isset($_POST['is_member_visible']) ? 1 : 1;
        $fileUpload  = (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE)
            ? $_FILES['attachment']
            : null;

        if ($remarks === '') {
            Session::flash('error', 'Action taken remarks are required.');
        } else {
            $res = Grievance::recordActionTaken($id, $currentUserId, $currentLevel, $remarks, $authorityId, $fileUpload, $isMemberVisible);
            if ($res['success']) {
                Session::flash('success', 'Action Taken recorded successfully.');
                header('Location: /admin/grievance-view.php?id=' . $id);
                exit;
            } else {
                Session::flash('error', $res['error'] ?? 'Failed to record Action Taken.');
            }
        }
    }

    // 7. Resolve Grievance
    if ($action === 'resolve') {
        RBAC::requirePermission($currentUserId, 'grievances', 'manage');
        $remarks    = trim(Sanitize::string($_POST['remarks'] ?? '', 4000));
        $fileUpload = (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE)
            ? $_FILES['attachment']
            : null;

        if ($remarks === '') {
            Session::flash('error', 'Resolution remarks are required.');
        } else {
            $res = Grievance::resolveGrievance($id, $currentUserId, $currentLevel, $remarks, $fileUpload, 1);
            if ($res['success']) {
                Session::flash('success', 'Grievance resolved successfully.');
                header('Location: /admin/grievance-view.php?id=' . $id);
                exit;
            } else {
                Session::flash('error', $res['error'] ?? 'Failed to resolve grievance.');
            }
        }
    }

    // 8. Reject Grievance
    if ($action === 'reject') {
        RBAC::requirePermission($currentUserId, 'grievances', 'manage');
        $remarks = trim(Sanitize::string($_POST['remarks'] ?? '', 3000));

        if ($remarks === '') {
            Session::flash('error', 'Rejection reason is required.');
        } else {
            Grievance::rejectGrievance($id, $currentUserId, $currentLevel, $remarks, 1);
            Session::flash('success', 'Grievance has been rejected.');
            header('Location: /admin/grievance-view.php?id=' . $id);
            exit;
        }
    }

    // 9. Close Grievance
    if ($action === 'close') {
        RBAC::requirePermission($currentUserId, 'grievances', 'manage');
        $remarks = trim(Sanitize::string($_POST['remarks'] ?? '', 2000)) ?: 'Grievance closed.';
        Grievance::closeGrievance($id, $currentUserId, $currentLevel, $remarks, 1);
        Session::flash('success', 'Grievance closed.');
        header('Location: /admin/grievance-view.php?id=' . $id);
        exit;
    }

    // 10. Reopen Grievance
    if ($action === 'reopen') {
        RBAC::requirePermission($currentUserId, 'grievances', 'manage');
        $remarks = trim(Sanitize::string($_POST['remarks'] ?? '', 2000)) ?: 'Grievance reopened.';
        Grievance::reopenGrievance($id, $currentUserId, $currentLevel, $remarks, 1);
        Session::flash('success', 'Grievance reopened.');
        header('Location: /admin/grievance-view.php?id=' . $id);
        exit;
    }

    // 11. Internal Note
    if ($action === 'add_note') {
        RBAC::requirePermission($currentUserId, 'grievances', 'edit');
        $remarks = trim(Sanitize::string($_POST['remarks'] ?? '', 3000));
        if ($remarks === '') {
            Session::flash('error', 'Note content is required.');
        } else {
            Grievance::addEvent($id, $currentUserId, $currentLevel, 'Internal Note', $grievance['current_status'], $grievance['current_status'], $remarks, 0);
            AuditLogger::log('NOTE', 'grievances', $id, null, ['note' => $remarks]);
            Session::flash('success', 'Internal note added to timeline.');
            header('Location: /admin/grievance-view.php?id=' . $id);
            exit;
        }
    }
}

// Fetch complete timeline events
$events = Database::fetchAll(
    "SELECT ge.*, u.username, u.email,
            ga_old.name AS old_auth_name, ga_new.name AS new_auth_name
     FROM grievance_events ge
     LEFT JOIN users u ON u.id = ge.performed_by
     LEFT JOIN grievance_authorities ga_old ON ga_old.id = ge.old_authority
     LEFT JOIN grievance_authorities ga_new ON ga_new.id = ge.new_authority
     WHERE ge.grievance_id = ?
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

// Pending duration calculation
$subTs = strtotime((string)$grievance['submitted_at']);
$daysPending = max(0, (int)floor((time() - $subTs) / 86400));

$pageTitle  = 'Grievance ' . $grievance['grievance_no'];
$activeMenu = 'grievances';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Grievances', 'url' => '/admin/grievances.php'],
    ['label' => $grievance['grievance_no'], 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';

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
            <span class="badge <?= $badgeClass ?>" style="font-size:0.92rem; padding:6px 14px;">
                ● <?= Sanitize::html($st) ?>
            </span>
            <span class="badge badge-neutral" style="text-transform:capitalize;">
                <?= ucfirst(Sanitize::html($grievance['current_association_level'])) ?> Committee
            </span>
        </div>
        <p class="page-heading-subtitle" style="margin-top:6px;">
            Submitted by <strong><?= Sanitize::html($grievance['member_name']) ?></strong> (<?= Sanitize::html($grievance['member_no']) ?>) on <?= date('d M Y, h:i A', $subTs) ?> • Pending: <strong><?= $daysPending ?> days</strong>
        </p>
    </div>
    <div style="display:flex; gap:8px;">
        <a href="/admin/grievances.php" class="btn btn-outline">&larr; Back to Register</a>
    </div>
</div>

<div style="display:grid; grid-template-columns: 1.25fr 1fr; gap:24px; align-items:start;">

    <!-- ═══════════════════════════════════════════════════════════════════════
         LEFT COLUMN: GRIEVANCE DETAILS & TIMELINE
         ═══════════════════════════════════════════════════════════════════════ -->
    <div>
        <!-- Card 1: Details -->
        <div class="table-card" style="margin-bottom:24px;">
            <div class="table-card-header">
                <span class="table-card-title">Grievance &amp; Member Profile</span>
                <span style="font-size:0.8rem; color:var(--text-muted);">
                    Last Activity: <?= date('d M Y, h:i A', strtotime((string)$grievance['last_updated_at'])) ?>
                </span>
            </div>
            <div style="padding:22px;">
                <!-- Member Summary -->
                <div style="background:var(--bg-page, #f8fafc); border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:18px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <span style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Complainant Member</span>
                        <a href="/admin/member-view.php?id=<?= (int)$grievance['member_id'] ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size:0.75rem; padding:2px 8px;">
                            View Member Profile &rarr;
                        </a>
                    </div>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap:12px; font-size:0.88rem;">
                        <div>
                            <span style="color:var(--text-muted); display:block; font-size:0.75rem;">Name &amp; ID:</span>
                            <strong><?= Sanitize::html($grievance['member_name']) ?></strong> (<?= Sanitize::html($grievance['member_no']) ?>)
                        </div>
                        <div>
                            <span style="color:var(--text-muted); display:block; font-size:0.75rem;">KGID No:</span>
                            <?= Sanitize::html($grievance['kgid_no'] ?? '—') ?>
                        </div>
                        <div>
                            <span style="color:var(--text-muted); display:block; font-size:0.75rem;">Contact:</span>
                            <?= Sanitize::html($grievance['member_phone'] ?? $grievance['member_email'] ?? '—') ?>
                        </div>
                        <div>
                            <span style="color:var(--text-muted); display:block; font-size:0.75rem;">Location:</span>
                            <?= Sanitize::html($grievance['district_name'] ?? '') ?>, <?= Sanitize::html($grievance['taluk_name'] ?? '') ?>
                        </div>
                    </div>
                </div>

                <!-- Case Parameters -->
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:18px;">
                    <div>
                        <span style="color:var(--text-muted); display:block; font-size:0.75rem; text-transform:uppercase; font-weight:600;">Category:</span>
                        <strong><?= Sanitize::html($grievance['category_name'] ?? 'General') ?></strong>
                    </div>
                    <div>
                        <span style="color:var(--text-muted); display:block; font-size:0.75rem; text-transform:uppercase; font-weight:600;">Service Issue:</span>
                        <strong style="color:var(--blue-700);"><?= Sanitize::html($grievance['service_name'] ?? 'General Matter') ?></strong>
                    </div>
                    <div>
                        <span style="color:var(--text-muted); display:block; font-size:0.75rem; text-transform:uppercase; font-weight:600;">Government Authority:</span>
                        <?= !empty($grievance['authority_name']) ? Sanitize::html($grievance['authority_name']) : '<span style="color:var(--text-muted);">Association Review</span>' ?>
                    </div>
                    <div>
                        <span style="color:var(--text-muted); display:block; font-size:0.75rem; text-transform:uppercase; font-weight:600;">Assigned Officer:</span>
                        <strong><?= !empty($grievance['assignee_username']) ? Sanitize::html($grievance['assignee_username']) : '<span style="color:#b45309;">Unassigned</span>' ?></strong>
                    </div>
                </div>

                <!-- Subject & Description -->
                <div style="border-top:1px solid #e2e8f0; padding-top:16px; margin-bottom:18px;">
                    <span style="color:var(--text-muted); display:block; font-size:0.75rem; text-transform:uppercase; font-weight:600;">Subject:</span>
                    <h3 style="margin:4px 0 12px 0; font-size:1.1rem; color:var(--text-main);"><?= Sanitize::html($grievance['subject']) ?></h3>

                    <span style="color:var(--text-muted); display:block; font-size:0.75rem; text-transform:uppercase; font-weight:600;">Description:</span>
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:14px; font-size:0.92rem; line-height:1.6; white-space:pre-wrap; color:var(--text-main);">
                        <?= Sanitize::html($grievance['description']) ?>
                    </div>
                </div>

                <!-- Attached Documents -->
                <div>
                    <span style="color:var(--text-muted); display:block; font-size:0.75rem; text-transform:uppercase; font-weight:600; margin-bottom:8px;">
                        Supporting Documents (<?= count($documents) ?>):
                    </span>
                    <?php if (empty($documents)): ?>
                        <p style="color:var(--text-muted); font-size:0.86rem; margin:0;">No documents attached.</p>
                    <?php else: ?>
                        <div style="display:flex; flex-wrap:wrap; gap:8px;">
                            <?php foreach ($documents as $doc): ?>
                                <div style="display:inline-flex; align-items:center; gap:8px; background:var(--blue-50); border:1px solid #c7d9ec; border-radius:6px; padding:6px 12px; font-size:0.84rem;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--blue-600);"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                    <span style="font-weight:600; max-width:180px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                        <?= Sanitize::html($doc['original_filename']) ?>
                                    </span>
                                    <a href="/download-grievance-doc.php?id=<?= (int)$doc['id'] ?>" target="_blank" class="btn btn-outline btn-sm" style="background:#fff; padding:2px 6px; font-size:0.72rem;">
                                        Download
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Card 2: Immutable Event Timeline -->
        <div class="table-card">
            <div class="table-card-header">
                <span class="table-card-title">Full Immutable Timeline (<?= count($events) ?> Events)</span>
                <span style="font-size:0.78rem; color:var(--text-muted);">Permanently Audited</span>
            </div>
            <div style="padding:22px;">
                <?php if (empty($events)): ?>
                    <p style="color:var(--text-muted); text-align:center;">No timeline events recorded.</p>
                <?php else: ?>
                    <div style="position:relative; padding-left:24px; border-left:2px solid var(--blue-200, #bfdbfe);">
                        <?php foreach ($events as $ev): ?>
                            <div style="position:relative; margin-bottom:20px;">
                                <div style="position:absolute; left:-31px; top:4px; width:12px; height:12px; border-radius:50%; background:<?= $ev['is_member_visible'] ? 'var(--blue-600)' : '#94a3b8' ?>; border:2px solid #fff; box-shadow:0 0 0 2px #e2e8f0;"></div>

                                <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:6px;">
                                    <div>
                                        <strong style="color:var(--blue-900); font-size:0.92rem;">
                                            <?= Sanitize::html($ev['event_type']) ?>
                                        </strong>
                                        <span class="badge badge-neutral" style="font-size:0.72rem; text-transform:capitalize; margin-left:4px;">
                                            <?= Sanitize::html($ev['association_level']) ?>
                                        </span>
                                        <?php if ($ev['is_member_visible']): ?>
                                            <span class="badge badge-success" style="font-size:0.68rem; padding:2px 6px; margin-left:4px;">Member Visible</span>
                                        <?php else: ?>
                                            <span class="badge badge-neutral" style="font-size:0.68rem; padding:2px 6px; margin-left:4px; background:#e2e8f0; color:#475569;">Internal Only</span>
                                        <?php endif; ?>
                                        <div style="font-size:0.76rem; color:var(--text-muted); margin-top:2px;">
                                            Actor: <?= Sanitize::html($ev['username'] ?? $ev['email'] ?? 'System') ?>
                                        </div>
                                    </div>
                                    <span style="font-size:0.78rem; color:var(--text-muted);">
                                        <?= date('d M Y, h:i A', strtotime((string)$ev['created_at'])) ?>
                                    </span>
                                </div>

                                <?php if (!empty($ev['remarks'])): ?>
                                    <div style="margin-top:6px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:8px 12px; font-size:0.86rem; color:var(--text-main); line-height:1.45;">
                                        <?= Sanitize::html($ev['remarks']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         RIGHT COLUMN: WORKFLOW ACTION ENGINE (ROLE & SCOPE PERMITTED)
         ═══════════════════════════════════════════════════════════════════════ -->
    <div>
        <div class="table-card" style="margin-bottom:24px;">
            <div class="table-card-header" style="background:#f1f5f9;">
                <span class="table-card-title">Grievance Action Panel</span>
                <span class="badge <?= $badgeClass ?>"><?= Sanitize::html($st) ?></span>
            </div>
            <div style="padding:20px;">
                <p style="font-size:0.82rem; color:var(--text-muted); margin-top:0; margin-bottom:16px;">
                    Execute permitted workflow transitions according to your association level and committee authorization.
                </p>

                <!-- Quick Verification / Review (if Submitted or Under Verification) -->
                <?php if (in_array($st, [Grievance::STATUS_SUBMITTED, Grievance::STATUS_UNDER_VERIFICATION], true)): ?>
                <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:16px; margin-bottom:18px;">
                    <strong style="color:var(--blue-900); font-size:0.9rem; display:block; margin-bottom:8px;">1. Verification &amp; Acceptance</strong>
                    <form method="post" action="/admin/grievance-view.php?id=<?= (int)$grievance['id'] ?>">
                        <?= CSRF::htmlField() ?>
                        <div class="form-group" style="margin-bottom:10px;">
                            <label class="form-label" style="font-size:0.75rem;">Action</label>
                            <select name="action" class="form-select" style="font-size:0.85rem;">
                                <option value="verify">Mark as Under Verification</option>
                                <option value="accept">Accept Grievance (Awaiting Review)</option>
                                <option value="review">Accept &amp; Mark Under Review</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:10px;">
                            <label class="form-label" style="font-size:0.75rem;">Remarks (Optional)</label>
                            <input type="text" name="remarks" class="form-control" style="font-size:0.85rem;" placeholder="e.g. Initial scrutiny completed...">
                        </div>
                        <div style="margin-bottom:12px;">
                            <label style="font-size:0.8rem; color:var(--text-main); cursor:pointer;">
                                <input type="checkbox" name="is_member_visible" value="1" checked> Make remarks visible to member
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm" style="width:100%;">
                            Update Verification Status
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Assign Officer -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:18px;">
                    <strong style="color:var(--text-main); font-size:0.9rem; display:block; margin-bottom:8px;">2. Assign Responsible Officer</strong>
                    <form method="post" action="/admin/grievance-view.php?id=<?= (int)$grievance['id'] ?>">
                        <?= CSRF::htmlField() ?>
                        <input type="hidden" name="action" value="assign">
                        <div class="form-group" style="margin-bottom:10px;">
                            <label class="form-label" style="font-size:0.75rem;">Assignee Officer *</label>
                            <select name="assignee_id" class="form-select" required style="font-size:0.85rem;">
                                <option value="">— Select Active Officer —</option>
                                <?php foreach ($officers as $off): ?>
                                    <option value="<?= (int)$off['id'] ?>" <?= ((int)$grievance['current_assignee'] === (int)$off['id']) ? 'selected' : '' ?>>
                                        <?= Sanitize::html($off['username']) ?> (<?= Sanitize::html($off['role_name'] ?? 'Officer') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:10px;">
                            <label class="form-label" style="font-size:0.75rem;">Assignment Note</label>
                            <input type="text" name="remarks" class="form-control" style="font-size:0.85rem;" placeholder="Instructions for the assigned officer...">
                        </div>
                        <button type="submit" class="btn btn-outline btn-sm" style="width:100%;">
                            Assign Responsibility
                        </button>
                    </form>
                </div>

                <!-- Forward Grievance -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:18px;">
                    <strong style="color:var(--text-main); font-size:0.9rem; display:block; margin-bottom:8px;">3. Controlled Forwarding (Hierarchy &amp; Authority)</strong>
                    <form method="post" action="/admin/grievance-view.php?id=<?= (int)$grievance['id'] ?>">
                        <?= CSRF::htmlField() ?>
                        <input type="hidden" name="action" value="forward">
                        <div class="form-group" style="margin-bottom:10px;">
                            <label class="form-label" style="font-size:0.75rem;">Target Association Level *</label>
                            <select name="to_level" class="form-select" required style="font-size:0.85rem;">
                                <option value="taluk" <?= $currentLevel === 'taluk' ? 'selected' : '' ?>>Taluk Committee</option>
                                <option value="district" <?= $currentLevel === 'district' ? 'selected' : '' ?>>District Committee</option>
                                <option value="state" <?= $currentLevel === 'state' ? 'selected' : '' ?>>State Committee</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:10px;">
                            <label class="form-label" style="font-size:0.75rem;">Target Government Authority (Optional)</label>
                            <select name="to_authority_id" class="form-select" style="font-size:0.85rem;">
                                <option value="">— Unchanged (Current) —</option>
                                <?php foreach ($authorities as $auth): ?>
                                    <option value="<?= (int)$auth['id'] ?>" <?= ((int)$grievance['current_authority'] === (int)$auth['id']) ? 'selected' : '' ?>>
                                        <?= Sanitize::html($auth['name']) ?> (<?= Sanitize::html($auth['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:10px;">
                            <label class="form-label" style="font-size:0.75rem;">Forwarding Remarks / Reason *</label>
                            <textarea name="remarks" rows="2" class="form-control" required style="font-size:0.85rem;" placeholder="State reason for forwarding..."></textarea>
                        </div>
                        <div style="margin-bottom:12px;">
                            <label style="font-size:0.8rem; color:var(--text-main); cursor:pointer;">
                                <input type="checkbox" name="is_member_visible" value="1" checked> Notify member of forwarding
                            </label>
                        </div>
                        <button type="submit" class="btn btn-outline btn-sm" style="width:100%; border-color:var(--blue-600); color:var(--blue-700);">
                            Forward Case &rarr;
                        </button>
                    </form>
                </div>

                <!-- Escalate Grievance -->
                <div style="background:#fffbeb; border:1px solid #fef3c7; border-radius:8px; padding:16px; margin-bottom:18px;">
                    <strong style="color:#b45309; font-size:0.9rem; display:block; margin-bottom:8px;">4. Authorized Escalation</strong>
                    <form method="post" action="/admin/grievance-view.php?id=<?= (int)$grievance['id'] ?>">
                        <?= CSRF::htmlField() ?>
                        <input type="hidden" name="action" value="escalate">
                        <div class="form-group" style="margin-bottom:10px;">
                            <label class="form-label" style="font-size:0.75rem;">Escalate To</label>
                            <select name="to_level" class="form-select" style="font-size:0.85rem;">
                                <option value="district">District Committee</option>
                                <option value="state" selected>State Executive Committee</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:10px;">
                            <label class="form-label" style="font-size:0.75rem;">Escalation Reason *</label>
                            <input type="text" name="remarks" class="form-control" required style="font-size:0.85rem;" placeholder="e.g. Policy matter requiring State intervention...">
                        </div>
                        <button type="submit" class="btn btn-outline btn-sm" style="width:100%; border-color:#f59e0b; color:#b45309;">
                            Escalate Grievance
                        </button>
                    </form>
                </div>

                <!-- Request Clarification from Member -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:18px;">
                    <strong style="color:var(--text-main); font-size:0.9rem; display:block; margin-bottom:8px;">5. Request Clarification</strong>
                    <form method="post" action="/admin/grievance-view.php?id=<?= (int)$grievance['id'] ?>">
                        <?= CSRF::htmlField() ?>
                        <input type="hidden" name="action" value="request_clarification">
                        <div class="form-group" style="margin-bottom:10px;">
                            <label class="form-label" style="font-size:0.75rem;">Clarification Question *</label>
                            <textarea name="remarks" rows="2" class="form-control" required style="font-size:0.85rem;" placeholder="Ask member for specific documents or details..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-outline btn-sm" style="width:100%;">
                            Request Clarification from Member
                        </button>
                    </form>
                </div>

                <!-- Record Action Taken -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:18px;">
                    <strong style="color:var(--text-main); font-size:0.9rem; display:block; margin-bottom:8px;">6. Record Action Taken</strong>
                    <form method="post" action="/admin/grievance-view.php?id=<?= (int)$grievance['id'] ?>" enctype="multipart/form-data">
                        <?= CSRF::htmlField() ?>
                        <input type="hidden" name="action" value="action_taken">
                        <div class="form-group" style="margin-bottom:10px;">
                            <label class="form-label" style="font-size:0.75rem;">Action Details *</label>
                            <textarea name="remarks" rows="2" class="form-control" required style="font-size:0.85rem;" placeholder="e.g. Letter submitted to RDPR Commissionerate on..."></textarea>
                        </div>
                        <div class="form-group" style="margin-bottom:10px;">
                            <label class="form-label" style="font-size:0.75rem;">Update Government Authority (Optional)</label>
                            <select name="authority_id" class="form-select" style="font-size:0.85rem;">
                                <option value="">— Unchanged —</option>
                                <?php foreach ($authorities as $auth): ?>
                                    <option value="<?= (int)$auth['id'] ?>"><?= Sanitize::html($auth['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:10px;">
                            <label class="form-label" style="font-size:0.75rem;">Attach Action Document (Max 2 MB)</label>
                            <input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf" style="font-size:0.82rem;">
                        </div>
                        <div style="margin-bottom:12px;">
                            <label style="font-size:0.8rem; color:var(--text-main); cursor:pointer;">
                                <input type="checkbox" name="is_member_visible" value="1" checked> Visible to member
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm" style="width:100%;">
                            Save Action Taken
                        </button>
                    </form>
                </div>

                <!-- Resolve / Reject / Close / Reopen -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">
                    <strong style="color:var(--text-main); font-size:0.9rem; display:block; margin-bottom:12px;">7. Final Resolution &amp; Closure</strong>

                    <!-- Resolve -->
                    <?php if (!in_array($st, [Grievance::STATUS_RESOLVED, Grievance::STATUS_CLOSED], true)): ?>
                    <form method="post" action="/admin/grievance-view.php?id=<?= (int)$grievance['id'] ?>" enctype="multipart/form-data" style="margin-bottom:14px;">
                        <?= CSRF::htmlField() ?>
                        <input type="hidden" name="action" value="resolve">
                        <div class="form-group" style="margin-bottom:8px;">
                            <label class="form-label" style="font-size:0.75rem;">Resolution Summary *</label>
                            <textarea name="remarks" rows="2" class="form-control" required style="font-size:0.85rem;" placeholder="Explain resolution outcome..."></textarea>
                        </div>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label class="form-label" style="font-size:0.75rem;">Resolution Document (Optional, Max 2 MB)</label>
                            <input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf" style="font-size:0.82rem;">
                        </div>
                        <button type="submit" class="btn btn-success btn-sm" style="width:100%; background:#059669; color:#fff; border:none; padding:8px;">
                            ✓ Resolve Grievance
                        </button>
                    </form>
                    <?php endif; ?>

                    <!-- Reject -->
                    <?php if (!in_array($st, [Grievance::STATUS_REJECTED, Grievance::STATUS_CLOSED], true)): ?>
                    <form method="post" action="/admin/grievance-view.php?id=<?= (int)$grievance['id'] ?>" style="margin-bottom:14px;">
                        <?= CSRF::htmlField() ?>
                        <input type="hidden" name="action" value="reject">
                        <div class="form-group" style="margin-bottom:8px;">
                            <label class="form-label" style="font-size:0.75rem;">Rejection Grounds *</label>
                            <input type="text" name="remarks" class="form-control" required style="font-size:0.85rem;" placeholder="Mandatory ground for rejection...">
                        </div>
                        <button type="submit" class="btn btn-danger btn-sm" style="width:100%; background:#dc2626; color:#fff; border:none; padding:8px;">
                            ✕ Reject Grievance
                        </button>
                    </form>
                    <?php endif; ?>

                    <!-- Close -->
                    <?php if ($st !== Grievance::STATUS_CLOSED): ?>
                    <form method="post" action="/admin/grievance-view.php?id=<?= (int)$grievance['id'] ?>" style="margin-bottom:14px;">
                        <?= CSRF::htmlField() ?>
                        <input type="hidden" name="action" value="close">
                        <input type="hidden" name="remarks" value="Closed by authorized officer.">
                        <button type="submit" class="btn btn-outline btn-sm" style="width:100%;" onclick="return confirm('Are you sure you want to close this grievance?');">
                            Close Grievance File
                        </button>
                    </form>
                    <?php endif; ?>

                    <!-- Reopen -->
                    <?php if (in_array($st, [Grievance::STATUS_RESOLVED, Grievance::STATUS_CLOSED, Grievance::STATUS_REJECTED], true)): ?>
                    <form method="post" action="/admin/grievance-view.php?id=<?= (int)$grievance['id'] ?>">
                        <?= CSRF::htmlField() ?>
                        <input type="hidden" name="action" value="reopen">
                        <div class="form-group" style="margin-bottom:8px;">
                            <label class="form-label" style="font-size:0.75rem;">Reopen Justification</label>
                            <input type="text" name="remarks" class="form-control" required style="font-size:0.85rem;" placeholder="Reason for reopening...">
                        </div>
                        <button type="submit" class="btn btn-outline btn-sm" style="width:100%; border-color:var(--blue-600); color:var(--blue-700);">
                            ↺ Reopen Grievance
                        </button>
                    </form>
                    <?php endif; ?>

                    <!-- Internal Note -->
                    <div style="border-top:1px solid #e2e8f0; padding-top:14px; margin-top:14px;">
                        <strong style="color:var(--text-muted); font-size:0.8rem; display:block; margin-bottom:8px;">Add Internal Note (Private to Officers)</strong>
                        <form method="post" action="/admin/grievance-view.php?id=<?= (int)$grievance['id'] ?>">
                            <?= CSRF::htmlField() ?>
                            <input type="hidden" name="action" value="add_note">
                            <div class="form-group" style="margin-bottom:8px;">
                                <textarea name="remarks" rows="2" class="form-control" required style="font-size:0.85rem;" placeholder="Internal deliberation or officer note..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-outline btn-sm" style="width:100%;">
                                Save Internal Note
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
