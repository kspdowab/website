<?php
/**
 * KSPDOWA — Member Portal: Activities
 * ============================================================
 * Gated by logged-in authenticated member access only.
 * Private official repository of Association programs.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentMemberId = Auth::getCurrentMemberId();

if ($currentMemberId === null) {
    header('Location: /login.php');
    exit;
}

$qSearch = trim(Sanitize::string($_GET['q'] ?? '', 100));

$whereClauses = ["a.status = 'active'", "a.access_level IN ('member', 'public')"];
$params       = [];

if ($qSearch !== '') {
    $whereClauses[] = "(a.title LIKE ? OR a.description LIKE ? OR a.location LIKE ?)";
    $like = '%' . $qSearch . '%';
    array_push($params, $like, $like, $like);
}

$whereSql = implode(' AND ', $whereClauses);

$sql = "
    SELECT a.*
    FROM activities a
    WHERE {$whereSql}
    ORDER BY a.activity_date DESC, a.id DESC
";
$activities = Database::fetchAll($sql, $params);

$pageTitle  = 'Activities';
$activeMenu = 'activities';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/member/index.php'],
    ['label' => 'Activities', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Association Activities</h1>
        <p class="page-heading-subtitle">Conferences, delegations, workshops, and welfare programs</p>
    </div>
    <div>
        <span class="badge badge-purple" style="font-size:0.82rem; padding:6px 14px;">
            <?= count($activities) ?> Published Programs
        </span>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     SEARCH FILTER
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="filter-card">
    <div class="filter-header-bar">
        <div class="filter-header-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            Search Activities
        </div>
        <div class="filter-header-actions">
            <a href="/member/activities.php" class="filter-header-btn">↺ Reset</a>
        </div>
    </div>
    <form method="get" action="/member/activities.php" class="filter-body">
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <div style="flex:1; min-width:240px;">
                <input type="text" name="q" class="form-control" placeholder="Search by title, location, keywords..." value="<?= Sanitize::attr($qSearch) ?>">
            </div>
            <button type="submit" class="btn btn-primary">Search</button>
            <a href="/member/activities.php" class="btn btn-outline">Clear</a>
        </div>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     ACTIVITIES LIST
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card">
    <div class="table-card-header">
        <span class="table-card-title">Activity Programs</span>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:60px;">Sl No</th>
                    <th>Activity Program</th>
                    <th style="width:140px;">Date</th>
                    <th style="width:180px;">Location</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($activities)): ?>
                <tr>
                    <td colspan="5" style="text-align:center; padding:36px; color:var(--text-muted);">
                        No activities found matching your search.
                    </td>
                </tr>
                <?php else: ?>
                    <?php $idx = 1; foreach ($activities as $act): ?>
                    <tr>
                        <td style="color:var(--text-muted); font-weight:600;"><?= $idx++ ?></td>
                        <td style="font-weight:700; color:var(--text-main);">
                            <?= Sanitize::html($act['title']) ?>
                        </td>
                        <td>
                            <span class="badge badge-info" style="font-size:0.78rem;">
                                <?= $act['activity_date'] ? date('d M Y', strtotime($act['activity_date'])) : '—' ?>
                            </span>
                        </td>
                        <td><?= Sanitize::html($act['location'] ?? '—') ?></td>
                        <td style="color:var(--text-muted); font-size:0.86rem; line-height:1.45;">
                            <?= nl2br(Sanitize::html($act['description'] ?? '—')) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
