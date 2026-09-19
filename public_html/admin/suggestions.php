<?php
/**
 * KSPDOWA — Admin: Suggestions Management
 * ============================================================
 * Section 28.5, 28.6, 28.10, 28.15:
 * - Gated by RBAC permission 'suggestions.view'.
 * - Scope-controlled (Taluk / District / State / Super Admin).
 * - Dynamic server-side search & filtering:
 *   - Search: Suggestion Number, Subject, Member Name
 *   - Filters: Status, District, Taluk, Date range
 * - Pagination.
 * - Respects KSPDOWA Admin UI theme.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/Suggestion.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();

RBAC::requirePermission($currentUserId, 'suggestions', 'view');

// Officer scope enforcement
$scope = Suggestion::getOfficerScope($currentUserId);
$lockedDistrictId = $scope['district_id'];
$lockedTalukId    = $scope['taluk_id'];

// Master data for filters
$districts = Database::fetchAll("SELECT id, name FROM districts WHERE status = 'active' ORDER BY name");
$taluks    = Database::fetchAll("SELECT id, name, district_id FROM taluks WHERE status = 'active' ORDER BY name");

// Map taluks by district for dependent filter
$taluksByDistrict = [];
foreach ($taluks as $tlk) {
    $dId = (int)$tlk['district_id'];
    if (!isset($taluksByDistrict[$dId])) {
        $taluksByDistrict[$dId] = [];
    }
    $taluksByDistrict[$dId][] = [
        'id'   => (int)$tlk['id'],
        'name' => $tlk['name'],
    ];
}

// ─── Filter parameters ───────────────────────────────────────────────────────
$qSearch   = trim(Sanitize::string($_GET['q'] ?? '', 100));
$qStatus   = trim(Sanitize::string($_GET['status'] ?? '', 50));
$qDistrict = $lockedDistrictId ?: Sanitize::positiveInt($_GET['district_id'] ?? null);
$qTaluk    = $lockedTalukId    ?: Sanitize::positiveInt($_GET['taluk_id'] ?? null);
$qDateFrom = trim(Sanitize::string($_GET['date_from'] ?? '', 20));
$qDateTo   = trim(Sanitize::string($_GET['date_to'] ?? '', 20));

$hasActiveFilter = ($qSearch !== '' ||
    ($qStatus !== '' && $qStatus !== 'all') ||
    (!$lockedDistrictId && $qDistrict) ||
    (!$lockedTalukId && $qTaluk) ||
    $qDateFrom !== '' || $qDateTo !== '');

$whereClauses = ["1=1"];
$params       = [];

// Geographic scope lock
if ($lockedTalukId) {
    $whereClauses[] = "m.taluk_id = ?";
    $params[]       = $lockedTalukId;
} elseif ($lockedDistrictId) {
    $whereClauses[] = "m.district_id = ?";
    $params[]       = $lockedDistrictId;
} else {
    // Open statewide scope — respect user filter
    if ($qDistrict) {
        $whereClauses[] = "m.district_id = ?";
        $params[]       = $qDistrict;
    }
    if ($qTaluk) {
        $whereClauses[] = "m.taluk_id = ?";
        $params[]       = $qTaluk;
    }
}

if ($qStatus !== '' && $qStatus !== 'all') {
    $whereClauses[] = "s.current_status = ?";
    $params[]       = $qStatus;
}

if ($qSearch !== '') {
    $whereClauses[] = "(s.suggestion_no LIKE ? OR s.subject LIKE ? OR m.name LIKE ? OR m.member_no LIKE ?)";
    $like = '%' . $qSearch . '%';
    array_push($params, $like, $like, $like, $like);
}

if ($qDateFrom !== '') {
    $whereClauses[] = "s.submitted_at >= ?";
    $params[]       = $qDateFrom . ' 00:00:00';
}

if ($qDateTo !== '') {
    $whereClauses[] = "s.submitted_at <= ?";
    $params[]       = $qDateTo . ' 23:59:59';
}

$whereSql = implode(' AND ', $whereClauses);

// ─── Pagination ─────────────────────────────────────────────────────────────
$page    = max(1, Sanitize::positiveInt($_GET['page'] ?? 1));
$perPage = 20;

$countRow = Database::fetchOne(
    "SELECT COUNT(*) AS total
     FROM suggestions s
     INNER JOIN members m ON m.id = s.member_id
     WHERE {$whereSql}",
    $params
);
$totalRecords = (int)($countRow['total'] ?? 0);
$totalPages   = max(1, (int)ceil($totalRecords / $perPage));
$page         = min($page, $totalPages);
$offset       = ($page - 1) * $perPage;

// Fetch suggestions page
$suggestions = Database::fetchAll(
    "SELECT s.*,
            m.name AS member_name, m.member_no,
            COALESCE(mp.personal_mobile, u_member.mobile) AS member_phone,
            d.name AS district_name, t.name AS taluk_name,
            u_resp.username AS responder_username
     FROM suggestions s
     INNER JOIN members m ON m.id = s.member_id
     LEFT JOIN member_profiles mp ON mp.member_id = m.id
     LEFT JOIN users u_member ON u_member.member_id = m.id
     LEFT JOIN districts d ON d.id = m.district_id
     LEFT JOIN taluks t    ON t.id = m.taluk_id
     LEFT JOIN users u_resp ON u_resp.id = s.responded_by
     WHERE {$whereSql}
     ORDER BY s.submitted_at DESC, s.id DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

// Scope stats
$scopeStatsWhere = ["1=1"];
$scopeStatsParams = [];
if ($lockedTalukId) {
    $scopeStatsWhere[] = "m.taluk_id = ?";
    $scopeStatsParams[] = $lockedTalukId;
} elseif ($lockedDistrictId) {
    $scopeStatsWhere[] = "m.district_id = ?";
    $scopeStatsParams[] = $lockedDistrictId;
}
$scopeStatsSql = implode(' AND ', $scopeStatsWhere);

$statsRow = Database::fetchOne(
    "SELECT COUNT(*) AS total_all,
            SUM(CASE WHEN s.current_status = 'Submitted' THEN 1 ELSE 0 END) AS count_submitted,
            SUM(CASE WHEN s.current_status IN ('Under Review', 'Under Consideration') THEN 1 ELSE 0 END) AS count_review,
            SUM(CASE WHEN s.association_response IS NULL OR s.association_response = '' THEN 1 ELSE 0 END) AS count_pending_response,
            SUM(CASE WHEN s.current_status IN ('Accepted', 'Implemented') THEN 1 ELSE 0 END) AS count_adopted
     FROM suggestions s
     INNER JOIN members m ON m.id = s.member_id
     WHERE {$scopeStatsSql}",
    $scopeStatsParams
);

$statTotal           = (int)($statsRow['total_all'] ?? 0);
$statSubmitted       = (int)($statsRow['count_submitted'] ?? 0);
$statReview          = (int)($statsRow['count_review'] ?? 0);
$statPendingResponse = (int)($statsRow['count_pending_response'] ?? 0);
$statAdopted         = (int)($statsRow['count_adopted'] ?? 0);

$pageTitle  = 'Members Suggestions';
$activeMenu = 'suggestions';
$breadcrumbs = [
    ['label' => 'Admin Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'Members Suggestions', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     HEADER & METRICS
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="page-header-row" style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px; flex-wrap:wrap; gap:16px;">
    <div>
        <h1 class="page-heading-title" style="margin:0 0 6px 0; font-size:1.6rem; font-weight:800; color:var(--text-main);">
            Members' Suggestions Management
        </h1>
        <p class="page-heading-subtitle" style="margin:0; color:var(--text-muted); font-size:0.92rem;">
            Review and respond to constructive proposals, ideas, and recommendations submitted by PDO members.
        </p>
    </div>
    <div>
        <?php if ($lockedTalukId): ?>
            <span class="badge badge-info" style="font-size:0.86rem; padding:6px 12px;">Jurisdiction: Taluk Scope</span>
        <?php elseif ($lockedDistrictId): ?>
            <span class="badge badge-info" style="font-size:0.86rem; padding:6px 12px;">Jurisdiction: District Scope</span>
        <?php else: ?>
            <span class="badge badge-primary" style="font-size:0.86rem; padding:6px 12px;">Jurisdiction: Statewide Scope</span>
        <?php endif; ?>
    </div>
</div>

<!-- Stat Cards -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap:16px; margin-bottom:24px;">
    <div class="table-card" style="padding:18px 20px;">
        <span style="font-size:0.78rem; font-weight:600; color:var(--text-muted); text-transform:uppercase;">Total Suggestions</span>
        <div style="font-size:1.75rem; font-weight:800; color:var(--text-main); margin-top:4px;"><?= $statTotal ?></div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">In authorized jurisdiction</div>
    </div>

    <div class="table-card" style="padding:18px 20px; border-left:4px solid var(--blue-600);">
        <span style="font-size:0.78rem; font-weight:600; color:var(--blue-700); text-transform:uppercase;">New / Submitted</span>
        <div style="font-size:1.75rem; font-weight:800; color:var(--blue-700); margin-top:4px;"><?= $statSubmitted ?></div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">Awaiting initial review</div>
    </div>

    <div class="table-card" style="padding:18px 20px; border-left:4px solid #f59e0b;">
        <span style="font-size:0.78rem; font-weight:600; color:#b45309; text-transform:uppercase;">Under Review</span>
        <div style="font-size:1.75rem; font-weight:800; color:#b45309; margin-top:4px;"><?= $statReview ?></div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">Committee evaluation</div>
    </div>

    <div class="table-card" style="padding:18px 20px; border-left:4px solid #ef4444;">
        <span style="font-size:0.78rem; font-weight:600; color:#b91c1c; text-transform:uppercase;">Pending Response</span>
        <div style="font-size:1.75rem; font-weight:800; color:#b91c1c; margin-top:4px;"><?= $statPendingResponse ?></div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">Association reply pending</div>
    </div>

    <div class="table-card" style="padding:18px 20px; border-left:4px solid var(--success-green, #10b981);">
        <span style="font-size:0.78rem; font-weight:600; color:#047857; text-transform:uppercase;">Adopted / Accepted</span>
        <div style="font-size:1.75rem; font-weight:800; color:#047857; margin-top:4px;"><?= $statAdopted ?></div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">Approved or implemented</div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     SEARCH & FILTERS (§28.6)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card" style="padding:18px 20px; margin-bottom:24px;">
    <form method="get" action="/admin/suggestions.php" id="filterForm">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:14px; align-items:flex-end;">
            <!-- Search -->
            <div>
                <label class="form-label" style="font-size:0.78rem; font-weight:600; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px; display:block;">
                    Search Suggestions
                </label>
                <input type="text" name="q" class="form-control" placeholder="Suggestion No, Subject, Member..." value="<?= Sanitize::attr($qSearch) ?>" style="padding:8px 12px; border-radius:6px; font-size:0.88rem; width:100%;">
            </div>

            <!-- Status -->
            <div>
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

            <!-- District (Statewide only) -->
            <?php if (!$lockedDistrictId): ?>
            <div>
                <label class="form-label" style="font-size:0.78rem; font-weight:600; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px; display:block;">
                    District
                </label>
                <select name="district_id" id="district_id" class="form-select" onchange="onDistrictChange();" style="padding:8px 12px; border-radius:6px; font-size:0.88rem; width:100%;">
                    <option value="">— All Districts —</option>
                    <?php foreach ($districts as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= $qDistrict === (int)$d['id'] ? 'selected' : '' ?>>
                            <?= Sanitize::html($d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- Taluk (if District or State scope) -->
            <?php if (!$lockedTalukId): ?>
            <div>
                <label class="form-label" style="font-size:0.78rem; font-weight:600; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px; display:block;">
                    Taluk
                </label>
                <select name="taluk_id" id="taluk_id" class="form-select" style="padding:8px 12px; border-radius:6px; font-size:0.88rem; width:100%;">
                    <option value="">— All Taluks —</option>
                    <?php foreach ($taluks as $t): ?>
                        <?php if (!$qDistrict || (int)$t['district_id'] === $qDistrict): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= $qTaluk === (int)$t['id'] ? 'selected' : '' ?>>
                                <?= Sanitize::html($t['name']) ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- Date From -->
            <div>
                <label class="form-label" style="font-size:0.78rem; font-weight:600; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px; display:block;">
                    Submitted From
                </label>
                <input type="date" name="date_from" class="form-control" value="<?= Sanitize::attr($qDateFrom) ?>" style="padding:8px 12px; border-radius:6px; font-size:0.88rem; width:100%;">
            </div>

            <!-- Date To -->
            <div>
                <label class="form-label" style="font-size:0.78rem; font-weight:600; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px; display:block;">
                    Submitted To
                </label>
                <input type="date" name="date_to" class="form-control" value="<?= Sanitize::attr($qDateTo) ?>" style="padding:8px 12px; border-radius:6px; font-size:0.88rem; width:100%;">
            </div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:16px;">
            <button type="submit" class="btn btn-primary" style="padding:8px 20px; font-size:0.88rem; border-radius:6px;">
                Apply Filters
            </button>
            <?php if ($hasActiveFilter): ?>
                <a href="/admin/suggestions.php" class="btn btn-secondary" style="padding:8px 16px; font-size:0.88rem; border-radius:6px; text-decoration:none;">
                    Reset Filters
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     SUGGESTIONS TABLE (§28.5)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card">
    <div class="table-card-header" style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #e2e8f0;">
        <span class="table-card-title" style="font-weight:700; color:var(--text-main);">
            Showing <?= count($suggestions) ?> of <?= $totalRecords ?> Suggestions
        </span>
        <span style="font-size:0.84rem; color:var(--text-muted);">
            Page <?= $page ?> of <?= $totalPages ?>
        </span>
    </div>

    <div class="table-responsive">
        <table class="data-table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="width:55px;">Sl No</th>
                    <th>Suggestion No.</th>
                    <th>Member</th>
                    <th>Subject</th>
                    <th>Submitted Date</th>
                    <th>Status</th>
                    <th>Association Response</th>
                    <th>Last Updated</th>
                    <th style="text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($suggestions)): ?>
                <tr>
                    <td colspan="9" style="text-align:center; padding:48px 16px; color:var(--text-muted);">
                        <div style="font-size:2rem; margin-bottom:8px;">💡</div>
                        <div style="font-weight:600; font-size:1rem; color:var(--text-main);">No suggestions found</div>
                        <div style="font-size:0.86rem; margin-top:4px;">
                            <?php if ($hasActiveFilter): ?>
                                No suggestions match your search / filter criteria. <a href="/admin/suggestions.php" style="color:var(--primary); text-decoration:underline;">Reset filters</a>.
                            <?php else: ?>
                                No suggestions have been submitted in your authorized scope yet.
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                    <?php $idx = $offset + 1; foreach ($suggestions as $s): ?>
                    <tr>
                        <td style="color:var(--text-muted); font-weight:600;"><?= $idx++ ?></td>
                        <td>
                            <a href="/admin/suggestion-view.php?id=<?= (int)$s['id'] ?>" style="font-weight:700; color:var(--primary); text-decoration:none;">
                                <?= Sanitize::html($s['suggestion_no']) ?>
                            </a>
                        </td>
                        <td>
                            <div style="font-weight:600; color:var(--text-main);"><?= Sanitize::html($s['member_name']) ?></div>
                            <div style="font-size:0.78rem; color:var(--text-muted);">
                                <?= Sanitize::html($s['member_no'] ?? '') ?> • <?= Sanitize::html($s['taluk_name'] ?? '') ?>, <?= Sanitize::html($s['district_name'] ?? '') ?>
                            </div>
                        </td>
                        <td>
                            <div style="font-weight:600; color:var(--text-main); max-width:280px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
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
                                    ✓ Response Provided
                                </span>
                            <?php else: ?>
                                <span class="badge badge-warning">Pending Response</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.84rem; color:var(--text-muted);">
                            <?= date('d M Y', strtotime((string)$s['last_updated_at'])) ?>
                        </td>
                        <td style="text-align:right;">
                            <a href="/admin/suggestion-view.php?id=<?= (int)$s['id'] ?>" class="btn btn-primary btn-sm" style="padding:6px 14px; font-size:0.82rem; text-decoration:none;">
                                Review / Manage →
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination controls -->
    <?php if ($totalPages > 1): ?>
        <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-top:1px solid #e2e8f0; flex-wrap:wrap; gap:12px;">
            <div style="font-size:0.86rem; color:var(--text-muted);">
                Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?> entries
            </div>
            <div style="display:flex; gap:6px;">
                <?php
                $queryParams = $_GET;
                $prevUrl = $page > 1 ? '?' . http_build_query(array_merge($queryParams, ['page' => $page - 1])) : null;
                $nextUrl = $page < $totalPages ? '?' . http_build_query(array_merge($queryParams, ['page' => $page + 1])) : null;
                ?>
                <?php if ($prevUrl): ?>
                    <a href="<?= $prevUrl ?>" class="btn btn-secondary btn-sm" style="padding:5px 12px;">← Previous</a>
                <?php endif; ?>

                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                    <a href="?<?= http_build_query(array_merge($queryParams, ['page' => $p])) ?>"
                       class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-secondary' ?>"
                       style="padding:5px 12px;">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>

                <?php if ($nextUrl): ?>
                    <a href="<?= $nextUrl ?>" class="btn btn-secondary btn-sm" style="padding:5px 12px;">Next →</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
const taluksByDistrict = <?= json_encode($taluksByDistrict) ?>;

function onDistrictChange() {
    const dSelect = document.getElementById('district_id');
    const tSelect = document.getElementById('taluk_id');
    if (!dSelect || !tSelect) return;

    const dId = dSelect.value;
    tSelect.innerHTML = '<option value="">— All Taluks —</option>';

    if (dId && taluksByDistrict[dId]) {
        taluksByDistrict[dId].forEach(t => {
            const opt = document.createElement('option');
            opt.value = t.id;
            opt.textContent = t.name;
            tSelect.appendChild(opt);
        });
    }
}
</script>

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';
