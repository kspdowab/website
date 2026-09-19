<?php
/**
 * KSPDOWA — Member Portal: Members' Suggestions to Association
 * ============================================================
 * Section 28 Specification:
 * - Logged-in eligible Members ONLY.
 * - Submit constructive suggestions, ideas, and recommendations.
 * - View member's own submitted suggestions with status & response.
 * - Member can NEVER see another member's suggestions.
 * - Separate suggestion record / workflow (distinct from grievances).
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

// ─── Handle Suggestion Submission ──────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();
    $action = Sanitize::string($_POST['action'] ?? '', 30);

    if ($action === 'create') {
        $subject     = Sanitize::string($_POST['subject'] ?? '', 500);
        $description = Sanitize::string($_POST['description'] ?? '', 5000);

        $errors = [];
        if ($subject === '') {
            $errors[] = 'Subject is required.';
        }
        if ($description === '') {
            $errors[] = 'Suggestion / Description is required.';
        }

        $fileUpload = (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE)
            ? $_FILES['attachment']
            : null;

        if (empty($errors)) {
            $result = Suggestion::create(
                $currentMemberId,
                $currentUserId,
                $subject,
                $description,
                $fileUpload
            );

            if ($result['success']) {
                Session::flash('success', "Suggestion {$result['suggestion_no']} submitted successfully! The Association will review your proposal.");
                header('Location: /member/suggestions.php');
                exit;
            } else {
                Session::flash('error', $result['error'] ?? 'Submission failed.');
            }
        } else {
            Session::flash('error', implode(' ', $errors));
        }
    }
}

// ─── Filters & Search ────────────────────────────────────────────────────────
$qSearch = trim(Sanitize::string($_GET['q'] ?? '', 100));
$qStatus = trim(Sanitize::string($_GET['status'] ?? '', 50));

$where  = ["s.member_id = ?"];
$params = [$currentMemberId];

if ($qStatus !== '' && $qStatus !== 'all') {
    $where[]  = "s.current_status = ?";
    $params[] = $qStatus;
}

if ($qSearch !== '') {
    $where[] = "(s.suggestion_no LIKE ? OR s.subject LIKE ? OR s.description LIKE ?)";
    $like = '%' . $qSearch . '%';
    array_push($params, $like, $like, $like);
}

$whereSql = implode(' AND ', $where);

$mySuggestions = Database::fetchAll(
    "SELECT s.*,
            u_resp.username AS responder_username
     FROM suggestions s
     LEFT JOIN users u_resp ON u_resp.id = s.responded_by
     WHERE {$whereSql}
     ORDER BY s.submitted_at DESC, s.id DESC",
    $params
);

// Summary metrics for the member
$allUserSuggestions = Database::fetchAll(
    "SELECT current_status, association_response FROM suggestions WHERE member_id = ?",
    [$currentMemberId]
);

$totalCount         = count($allUserSuggestions);
$underReviewCount   = 0;
$implementedCount   = 0;
$withResponseCount  = 0;

foreach ($allUserSuggestions as $row) {
    $st = $row['current_status'];
    if (in_array($st, [Suggestion::STATUS_UNDER_REVIEW, Suggestion::STATUS_UNDER_CONSIDERATION], true)) {
        $underReviewCount++;
    }
    if (in_array($st, [Suggestion::STATUS_IMPLEMENTED, Suggestion::STATUS_ACCEPTED], true)) {
        $implementedCount++;
    }
    if (!empty($row['association_response'])) {
        $withResponseCount++;
    }
}

$pageTitle  = "My Suggestions";
$activeMenu = 'suggestions';
$breadcrumbs = [
    ['label' => 'Member Portal', 'url' => '/member/index.php'],
    ['label' => "My Suggestions", 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';
?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     PAGE HEADER & ACTIONS
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="page-header-row" style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px; flex-wrap:wrap; gap:16px;">
    <div>
        <h1 class="page-heading-title" style="margin:0 0 6px 0; font-size:1.6rem; font-weight:800; color:var(--text-main);">
            My Suggestions
        </h1>
        <p class="page-heading-subtitle" style="margin:0; color:var(--text-muted); font-size:0.92rem;">
            Submit constructive recommendations, ideas, and improvement proposals directly to the Association leadership.
        </p>
    </div>
    <div>
        <button type="button" class="btn btn-primary" onclick="openNewSuggestionModal();" style="display:inline-flex; align-items:center; gap:8px; font-weight:600; padding:10px 18px; border-radius:8px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Submit Suggestion
        </button>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     STATISTICS CARDS
     ═══════════════════════════════════════════════════════════════════════════ -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
    <div class="table-card" style="padding:18px 20px;">
        <span style="font-size:0.78rem; font-weight:600; color:var(--text-muted); text-transform:uppercase;">Total Suggestions</span>
        <div style="font-size:1.8rem; font-weight:800; color:var(--text-main); margin-top:4px;">
            <?= $totalCount ?>
        </div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">Submitted by you</div>
    </div>

    <div class="table-card" style="padding:18px 20px; border-left:4px solid var(--blue-600);">
        <span style="font-size:0.78rem; font-weight:600; color:var(--blue-700); text-transform:uppercase;">Under Review / Consideration</span>
        <div style="font-size:1.8rem; font-weight:800; color:var(--blue-700); margin-top:4px;">
            <?= $underReviewCount ?>
        </div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">Association evaluation</div>
    </div>

    <div class="table-card" style="padding:18px 20px; border-left:4px solid var(--success-green, #10b981);">
        <span style="font-size:0.78rem; font-weight:600; color:#047857; text-transform:uppercase;">Accepted / Implemented</span>
        <div style="font-size:1.8rem; font-weight:800; color:#047857; margin-top:4px;">
            <?= $implementedCount ?>
        </div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">Constructive ideas adopted</div>
    </div>

    <div class="table-card" style="padding:18px 20px; border-left:4px solid var(--purple-600, #8b5cf6);">
        <span style="font-size:0.78rem; font-weight:600; color:#6d28d9; text-transform:uppercase;">Responses Received</span>
        <div style="font-size:1.8rem; font-weight:800; color:#6d28d9; margin-top:4px;">
            <?= $withResponseCount ?>
        </div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">Official Association replies</div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     SUBMIT NEW SUGGESTION MODAL (§28.2)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="newSuggestionModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); z-index:1050; align-items:center; justify-content:center; padding:16px;">
    <div class="modal-dialog" style="background:#fff; border-radius:12px; max-width:640px; width:100%; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); overflow:hidden;">
        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; padding:18px 24px; border-bottom:1px solid #e2e8f0;">
            <h3 class="modal-title" style="margin:0; font-size:1.15rem; font-weight:700; color:var(--text-main);">
                Submit Suggestion to Association (ಸಂಘಕ್ಕೆ ಸಲಹೆ ಸಲ್ಲಿಕೆ)
            </h3>
            <button type="button" class="modal-close-btn" onclick="closeNewSuggestionModal();" style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:#94a3b8;">✕</button>
        </div>
        <form method="post" action="/member/suggestions.php" enctype="multipart/form-data" id="suggestionForm">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="create">

            <div class="modal-body" style="padding:24px;">
                <div class="alert alert-info" style="margin-bottom:18px; font-size:0.86rem; background:#eff6ff; border-left:4px solid var(--blue-600); color:#1e40af; padding:12px 16px; border-radius:6px;">
                    <strong>Note:</strong> Suggestions are ideas, proposals, and constructive recommendations to strengthen the Association. For personal official grievances or service disputes, please use the <a href="/member/grievances.php" style="color:#1d4ed8; text-decoration:underline;">Grievance module</a>.
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" for="subject" style="display:block; font-weight:600; font-size:0.86rem; margin-bottom:6px; color:var(--text-main);">
                        Subject *
                    </label>
                    <input type="text" name="subject" id="subject" class="form-control" required maxlength="300" placeholder="Brief subject or title of your suggestion" style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.9rem;">
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" for="description" style="display:block; font-weight:600; font-size:0.86rem; margin-bottom:6px; color:var(--text-main);">
                        Suggestion / Description *
                    </label>
                    <textarea name="description" id="description" rows="5" class="form-control" required placeholder="Describe your suggestion, proposal, or recommendation in detail..." style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.9rem;"></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label" for="attachment" style="display:block; font-weight:600; font-size:0.86rem; margin-bottom:6px; color:var(--text-main);">
                        Supporting Document (Optional)
                    </label>
                    <input type="file" name="attachment" id="attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.86rem;">
                    <div style="font-size:0.78rem; color:var(--text-muted); margin-top:5px;">
                        Allowed formats: <strong>.jpg, .png, .pdf</strong> only. Maximum size: <strong>2 MB</strong>. Office docs, spreadsheets, ZIPs, or executables are strictly prohibited.
                    </div>
                </div>
            </div>

            <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:12px; padding:16px 24px; border-top:1px solid #e2e8f0; background:#f8fafc;">
                <button type="button" class="btn btn-secondary" onclick="closeNewSuggestionModal();" style="padding:8px 16px; border-radius:8px;">Cancel</button>
                <button type="submit" class="btn btn-primary" id="submitBtn" style="padding:8px 20px; border-radius:8px; font-weight:600;">
                    Submit Suggestion
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     FILTERS & SEARCH BAR (§28.6)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card" style="padding:16px 20px; margin-bottom:20px;">
    <form method="get" action="/member/suggestions.php" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
        <div style="flex:1; min-width:240px;">
            <label class="form-label" style="font-size:0.78rem; font-weight:600; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px; display:block;">
                Search Suggestions
            </label>
            <input type="text" name="q" class="form-control" placeholder="Search by Suggestion No. or Subject..." value="<?= Sanitize::attr($qSearch) ?>" style="padding:8px 12px; border-radius:6px; font-size:0.88rem; width:100%;">
        </div>

        <div style="min-width:180px;">
            <label class="form-label" style="font-size:0.78rem; font-weight:600; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px; display:block;">
                Status
            </label>
            <select name="status" class="form-select" style="padding:8px 12px; border-radius:6px; font-size:0.88rem; width:100%;">
                <option value="all" <?= ($qStatus === '' || $qStatus === 'all') ? 'selected' : '' ?>>All Statuses</option>
                <?php foreach (Suggestion::ALL_STATUSES as $st): ?>
                    <option value="<?= Sanitize::attr($st) ?>" <?= $qStatus === $st ? 'selected' : '' ?>>
                        <?= Sanitize::html($st) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex; gap:8px;">
            <button type="submit" class="btn btn-primary" style="padding:8px 16px; border-radius:6px; font-size:0.88rem;">Filter</button>
            <?php if ($qSearch !== '' || ($qStatus !== '' && $qStatus !== 'all')): ?>
                <a href="/member/suggestions.php" class="btn btn-secondary" style="padding:8px 14px; border-radius:6px; font-size:0.88rem;">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     SUGGESTIONS DATA TABLE
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card">
    <div class="table-card-header" style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #e2e8f0;">
        <span class="table-card-title" style="font-weight:700; color:var(--text-main);">
            My Submitted Suggestions (<?= count($mySuggestions) ?>)
        </span>
    </div>

    <div class="table-responsive">
        <table class="data-table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="width:55px;">Sl No</th>
                    <th>Suggestion No.</th>
                    <th>Subject</th>
                    <th>Submitted Date</th>
                    <th>Status</th>
                    <th>Association Response</th>
                    <th>Last Updated</th>
                    <th style="text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($mySuggestions)): ?>
                <tr>
                    <td colspan="8" style="text-align:center; padding:48px 16px; color:var(--text-muted);">
                        <div style="font-size:2rem; margin-bottom:8px;">💡</div>
                        <div style="font-weight:600; font-size:1rem; color:var(--text-main);">No suggestions found</div>
                        <div style="font-size:0.86rem; margin-top:4px;">
                            <?php if ($qSearch !== '' || ($qStatus !== '' && $qStatus !== 'all')): ?>
                                No suggestions matched your current filter criteria. <a href="/member/suggestions.php" style="color:var(--primary); text-decoration:underline;">Clear filters</a>.
                            <?php else: ?>
                                You have not submitted any suggestions yet. Have an idea for the Association?
                                <div style="margin-top:12px;">
                                    <button type="button" class="btn btn-primary btn-sm" onclick="openNewSuggestionModal();">Submit Your First Suggestion</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                    <?php $idx = 1; foreach ($mySuggestions as $s): ?>
                    <tr>
                        <td style="color:var(--text-muted); font-weight:600;"><?= $idx++ ?></td>
                        <td>
                            <a href="/member/suggestion-view.php?id=<?= (int)$s['id'] ?>" style="font-weight:700; color:var(--primary); text-decoration:none;">
                                <?= Sanitize::html($s['suggestion_no']) ?>
                            </a>
                        </td>
                        <td>
                            <div style="font-weight:600; color:var(--text-main); max-width:320px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                <?= Sanitize::html($s['subject']) ?>
                            </div>
                        </td>
                        <td style="font-size:0.86rem; color:var(--text-muted);">
                            <?= date('d M Y, h:i A', strtotime((string)$s['submitted_at'])) ?>
                        </td>
                        <td>
                            <?= Suggestion::getStatusBadge((string)$s['current_status']) ?>
                        </td>
                        <td>
                            <?php if (!empty($s['association_response'])): ?>
                                <span class="badge badge-success" style="display:inline-flex; align-items:center; gap:4px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                    Response Provided
                                </span>
                            <?php else: ?>
                                <span style="font-size:0.82rem; color:var(--text-muted);">Pending Review</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.84rem; color:var(--text-muted);">
                            <?= date('d M Y', strtotime((string)$s['last_updated_at'])) ?>
                        </td>
                        <td style="text-align:right;">
                            <a href="/member/suggestion-view.php?id=<?= (int)$s['id'] ?>" class="btn btn-secondary btn-sm" style="padding:5px 12px; font-size:0.82rem; text-decoration:none;">
                                View Details
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function openNewSuggestionModal() {
    const m = document.getElementById('newSuggestionModal');
    if (m) {
        m.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function closeNewSuggestionModal() {
    const m = document.getElementById('newSuggestionModal');
    if (m) {
        m.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// Close on backdrop click
document.addEventListener('click', function(e) {
    const m = document.getElementById('newSuggestionModal');
    if (e.target === m) {
        closeNewSuggestionModal();
    }
});

// Client-side file validation feedback
document.getElementById('attachment')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;

    if (file.size > 2 * 1024 * 1024) {
        alert('File size exceeds the 2 MB limit. Please select a smaller file.');
        e.target.value = '';
        return;
    }

    const ext = file.name.split('.').pop().toLowerCase();
    if (!['jpg', 'jpeg', 'png', 'pdf'].includes(ext)) {
        alert('Invalid file format. Only .jpg, .png, and .pdf files are permitted.');
        e.target.value = '';
    }
});
</script>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
