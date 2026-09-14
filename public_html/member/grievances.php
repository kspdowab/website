<?php
/**
 * KSPDOWA — Member Portal: My Grievances
 * ============================================================
 * Section 9 & Phase 5 Specification:
 * - Logged-in Members ONLY
 * - Category, Service, Authority selection
 * - Strict 2 MB file restrictions (.jpg, .png, .pdf)
 * - Unique permanent ID: KSPDOWA-GRV-YYYY-NNNNN
 * - Hierarchical tracking & detail view
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

// Fetch active master data
$categories  = Database::fetchAll("SELECT * FROM grievance_categories WHERE status = 'active' ORDER BY sort_order, name");
$services    = Database::fetchAll("SELECT * FROM grievance_services WHERE status = 'active' ORDER BY category_id, sort_order, name");
$authorities = Database::fetchAll("SELECT * FROM grievance_authorities WHERE status = 'active' ORDER BY id ASC");

// Map services by category for dependent dropdown
$servicesByCategory = [];
foreach ($services as $srv) {
    $cId = (int)$srv['category_id'];
    if (!isset($servicesByCategory[$cId])) {
        $servicesByCategory[$cId] = [];
    }
    $servicesByCategory[$cId][] = [
        'id'   => (int)$srv['id'],
        'name' => $srv['name'],
    ];
}

// ─── Handle Grievance Submission ─────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();
    $action = Sanitize::string($_POST['action'] ?? '', 30);

    if ($action === 'create') {
        $categoryId  = Sanitize::positiveInt($_POST['category_id'] ?? null);
        $serviceId   = Sanitize::positiveInt($_POST['service_id'] ?? null);
        $authorityId = Sanitize::positiveInt($_POST['authority_id'] ?? null);
        $subject     = Sanitize::string($_POST['subject'] ?? '', 500);
        $description = Sanitize::string($_POST['description'] ?? '', 5000);

        $errors = [];
        if (!$categoryId) {
            $errors[] = 'Please select a grievance category.';
        }
        if (!$serviceId) {
            // Default to first service in category if not chosen
            if ($categoryId && !empty($servicesByCategory[$categoryId])) {
                $serviceId = (int)$servicesByCategory[$categoryId][0]['id'];
            } else {
                $errors[] = 'Please select a service type.';
            }
        }
        if ($subject === '') {
            $errors[] = 'Subject is required.';
        }
        if ($description === '') {
            $errors[] = 'Detailed description is required.';
        }

        $fileUpload = (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE)
            ? $_FILES['attachment']
            : null;

        if (empty($errors)) {
            $result = Grievance::create(
                $currentMemberId,
                $currentUserId,
                $categoryId,
                $serviceId,
                $authorityId,
                $subject,
                $description,
                $fileUpload
            );

            if ($result['success']) {
                Session::flash('success', "Grievance {$result['grievance_no']} submitted successfully! You can track its progress below.");
                header('Location: /member/grievances.php');
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
$qSearch   = trim(Sanitize::string($_GET['q'] ?? '', 100));
$qCategory = Sanitize::positiveInt($_GET['category_id'] ?? null);
$qStatus   = trim(Sanitize::string($_GET['status'] ?? '', 50));

$where = ["g.member_id = ?"];
$params = [$currentMemberId];

if ($qCategory) {
    $where[] = "g.category_id = ?";
    $params[] = $qCategory;
}

if ($qStatus !== '' && $qStatus !== 'all') {
    $where[] = "g.current_status = ?";
    $params[] = $qStatus;
}

if ($qSearch !== '') {
    $where[] = "(g.grievance_no LIKE ? OR g.subject LIKE ? OR g.description LIKE ?)";
    $like = '%' . $qSearch . '%';
    array_push($params, $like, $like, $like);
}

$whereSql = implode(' AND ', $where);

$myGrievances = Database::fetchAll(
    "SELECT g.*, gc.name AS category_name, gs.name AS service_name, ga.name AS authority_name
     FROM grievances g
     LEFT JOIN grievance_categories gc ON gc.id = g.category_id
     LEFT JOIN grievance_services gs   ON gs.id = g.service_id
     LEFT JOIN grievance_authorities ga ON ga.id = g.current_authority
     WHERE {$whereSql}
     ORDER BY g.submitted_at DESC, g.id DESC",
    $params
);

// Summary metrics
$totalCount = count($myGrievances);
$inProgressCount = 0;
$clarificationCount = 0;
$resolvedCount = 0;

$allUserGrvs = Database::fetchAll("SELECT current_status FROM grievances WHERE member_id = ?", [$currentMemberId]);
foreach ($allUserGrvs as $r) {
    $st = $r['current_status'];
    if ($st === Grievance::STATUS_CLARIFICATION_REQUIRED) {
        $clarificationCount++;
    } elseif (in_array($st, [Grievance::STATUS_RESOLVED, Grievance::STATUS_CLOSED], true)) {
        $resolvedCount++;
    } else {
        $inProgressCount++;
    }
}

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
        <h1 class="page-heading-title">My Grievances &amp; Service Tracking (ಕುಂದುಕೊರತೆಗಳು)</h1>
        <p class="page-heading-subtitle">Submit, track, and resolve official association and department matters</p>
    </div>
    <div>
        <button type="button" class="btn btn-primary" onclick="openNewGrievanceModal();" style="display:inline-flex; align-items:center; gap:6px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Submit New Grievance
        </button>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     STATISTICS CARDS
     ═══════════════════════════════════════════════════════════════════════════ -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
    <div class="table-card" style="padding:18px 20px;">
        <span style="font-size:0.78rem; font-weight:600; color:var(--text-muted); text-transform:uppercase;">Total Grievances</span>
        <div style="font-size:1.8rem; font-weight:800; color:var(--text-main); margin-top:4px;">
            <?= count($allUserGrvs) ?>
        </div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">Submitted by you</div>
    </div>

    <div class="table-card" style="padding:18px 20px; border-left:4px solid var(--blue-600);">
        <span style="font-size:0.78rem; font-weight:600; color:var(--blue-700); text-transform:uppercase;">Under Processing</span>
        <div style="font-size:1.8rem; font-weight:800; color:var(--blue-700); margin-top:4px;">
            <?= $inProgressCount ?>
        </div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">Taluk / District / State review</div>
    </div>

    <div class="table-card" style="padding:18px 20px; border-left:4px solid #f59e0b;">
        <span style="font-size:0.78rem; font-weight:600; color:#b45309; text-transform:uppercase;">Clarification Needed</span>
        <div style="font-size:1.8rem; font-weight:800; color:#b45309; margin-top:4px;">
            <?= $clarificationCount ?>
        </div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">Response requested from you</div>
    </div>

    <div class="table-card" style="padding:18px 20px; border-left:4px solid var(--success-green, #10b981);">
        <span style="font-size:0.78rem; font-weight:600; color:#047857; text-transform:uppercase;">Resolved / Closed</span>
        <div style="font-size:1.8rem; font-weight:800; color:#047857; margin-top:4px;">
            <?= $resolvedCount ?>
        </div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">Successfully addressed</div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     SUBMIT NEW GRIEVANCE MODAL (with 2 MB JPG/PNG/PDF Restriction)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="newGrievanceModal">
    <div class="modal-dialog" style="max-width:680px;">
        <div class="modal-header">
            <h3 class="modal-title">Submit New Grievance (ಹೊಸ ಕುಂದುಕೊರತೆ ಸಲ್ಲಿಕೆ)</h3>
            <button type="button" class="modal-close-btn" onclick="closeNewGrievanceModal();">✕</button>
        </div>
        <form method="post" action="/member/grievances.php" enctype="multipart/form-data" id="grvForm">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="create">

            <div class="modal-body">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-bottom:14px;">
                    <div class="form-group">
                        <label class="form-label" for="category_id">Service Category *</label>
                        <select name="category_id" id="category_id" class="form-select" required onchange="onCategoryChange();">
                            <option value="">— Select Category —</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>"><?= Sanitize::html($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="service_id">Service Type / Issue *</label>
                        <select name="service_id" id="service_id" class="form-select" required>
                            <option value="">— Select Category First —</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label" for="authority_id">Relevant Government / Department Authority</label>
                    <select name="authority_id" id="authority_id" class="form-select">
                        <option value="">— Select Relevant Authority (Optional) —</option>
                        <?php foreach ($authorities as $auth): ?>
                            <option value="<?= (int)$auth['id'] ?>">
                                <?= Sanitize::html($auth['name']) ?> (<?= Sanitize::html($auth['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size:0.78rem; color:var(--text-muted); margin-top:3px;">
                        Specify the government level where your issue or order is currently pending (RDPR, ZP, TP, etc.).
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label" for="subject">Subject *</label>
                    <input type="text" name="subject" id="subject" class="form-control" required maxlength="300" placeholder="Brief subject of grievance / matter">
                </div>

                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label" for="description">Detailed Description *</label>
                    <textarea name="description" id="description" rows="4" class="form-control" required placeholder="Provide clear facts, dates, department orders, employee register references, or details..."></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label" for="attachment">
                        Supporting Document
                        <span style="font-weight:400; color:var(--text-muted);">(Max 2 MB • .jpg, .png, .pdf only)</span>
                    </label>
                    <input type="file" name="attachment" id="attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                    <div style="font-size:0.78rem; color:var(--text-muted); margin-top:4px;">
                        Strict server-side security: Files over 2 MB or other extensions will be rejected.
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeNewGrievanceModal();">Cancel</button>
                <button type="submit" class="btn btn-primary">Submit Official Grievance</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     FILTERS & SEARCH
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="filter-card" style="margin-bottom:24px;">
    <form method="get" action="/member/grievances.php" class="filter-body" style="padding:16px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; align-items:end;">
            <div class="form-group">
                <label class="form-label" style="font-size:0.78rem;">Search</label>
                <input type="text" name="q" class="form-control" placeholder="Grievance ID, subject..." value="<?= Sanitize::attr($qSearch) ?>">
            </div>

            <div class="form-group">
                <label class="form-label" style="font-size:0.78rem;">Category</label>
                <select name="category_id" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>" <?= $qCategory === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= Sanitize::html($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" style="font-size:0.78rem;">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <?php foreach (Grievance::ALL_STATUSES as $st): ?>
                        <option value="<?= Sanitize::attr($st) ?>" <?= $qStatus === $st ? 'selected' : '' ?>>
                            <?= Sanitize::html($st) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex; gap:8px;">
                <button type="submit" class="btn btn-primary" style="flex:1;">Filter</button>
                <a href="/member/grievances.php" class="btn btn-outline">Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     GRIEVANCES TABLE
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="table-card">
    <div class="table-card-header">
        <div>
            <span class="table-card-title">My Grievance Register</span>
            <span class="table-card-count">(<?= count($myGrievances) ?> records)</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:50px;">Sl No</th>
                    <th>Grievance ID</th>
                    <th>Category &amp; Service</th>
                    <th>Authority</th>
                    <th>Subject</th>
                    <th>Level</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th style="text-align:center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($myGrievances)): ?>
                <tr>
                    <td colspan="9" style="text-align:center; padding:40px; color:var(--text-muted);">
                        No grievances found. Click <strong>Submit New Grievance</strong> above to register an issue.
                    </td>
                </tr>
                <?php else: ?>
                    <?php $idx = 1; foreach ($myGrievances as $g): ?>
                        <?php
                        $st = $g['current_status'];
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
                        <tr>
                            <td style="color:var(--text-muted); font-weight:600;"><?= $idx++ ?></td>
                            <td>
                                <a href="/member/grievance-view.php?id=<?= (int)$g['id'] ?>" style="font-weight:700; color:var(--blue-700); text-decoration:none;">
                                    <?= Sanitize::html($g['grievance_no']) ?>
                                </a>
                            </td>
                            <td>
                                <strong style="display:block; color:var(--text-main); font-size:0.88rem;"><?= Sanitize::html($g['category_name'] ?? 'General') ?></strong>
                                <span style="font-size:0.78rem; color:var(--text-muted);"><?= Sanitize::html($g['service_name'] ?? '') ?></span>
                            </td>
                            <td>
                                <?= !empty($g['authority_name']) ? Sanitize::html($g['authority_name']) : '<span style="color:var(--text-muted);">Association</span>' ?>
                            </td>
                            <td style="max-width:240px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                <?= Sanitize::html($g['subject']) ?>
                            </td>
                            <td>
                                <span class="badge badge-neutral" style="text-transform:capitalize;">
                                    <?= Sanitize::html($g['current_association_level']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $badgeClass ?>"><?= Sanitize::html($st) ?></span>
                            </td>
                            <td><?= date('d M Y', strtotime((string)$g['submitted_at'])) ?></td>
                            <td style="text-align:center;">
                                <a href="/member/grievance-view.php?id=<?= (int)$g['id'] ?>" class="btn btn-outline btn-sm">
                                    Track &rarr;
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
const servicesMap = <?= json_encode($servicesByCategory, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function onCategoryChange() {
    const catSelect = document.getElementById('category_id');
    const srvSelect = document.getElementById('service_id');
    const selectedCat = parseInt(catSelect.value, 10);

    srvSelect.innerHTML = '<option value="">— Select Service Type —</option>';

    if (selectedCat && servicesMap[selectedCat]) {
        servicesMap[selectedCat].forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.name;
            srvSelect.appendChild(opt);
        });
    } else {
        srvSelect.innerHTML = '<option value="">— Select Category First —</option>';
    }
}

function openNewGrievanceModal() {
    document.getElementById('newGrievanceModal').classList.add('open');
}

function closeNewGrievanceModal() {
    document.getElementById('newGrievanceModal').classList.remove('open');
}
</script>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
