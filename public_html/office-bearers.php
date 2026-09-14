<?php
/**
 * KSPDOWA — Public Office Bearers Page
 * ============================================================
 * Displays State Council, State Committee, District Committee,
 * and Taluk Committee leadership with smooth section navigation,
 * district/taluk dropdown filtering, and live counters.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// Check if viewer is an authenticated member/user
$canViewContact = Auth::isLoggedIn();

// 1. Fetch State Council Members
// District-wise elected: President, State Council Member, and Treasurer
$stateCouncilBearers = Database::fetchAll(
    "SELECT ob.*, d.name AS district_name FROM office_bearers ob
     JOIN districts d ON d.id = ob.district_id
     WHERE ob.status = 'active' AND ob.taluk_id IS NULL
       AND (
           (ob.association_designation LIKE '%ಅಧ್ಯಕ್ಷ%' AND ob.association_designation NOT LIKE '%ಉಪಾಧ್ಯಕ್ಷ%')
           OR ob.association_designation LIKE '%ರಾಜ್ಯ ಪರಿಷತ್%'
           OR ob.association_designation LIKE '%ಖಜಾಂಚಿ%'
           OR (ob.association_designation LIKE '%President%' AND ob.association_designation NOT LIKE '%Vice%')
           OR ob.association_designation LIKE '%Council%'
           OR ob.association_designation LIKE '%Treasurer%'
       )
     ORDER BY d.name, ob.sort_order, ob.name"
);

// Group unique districts in State Council
$councilDistrictOptions = [];
foreach ($stateCouncilBearers as $ob) {
    $dId = (int) $ob['district_id'];
    if (!isset($councilDistrictOptions[$dId])) {
        $councilDistrictOptions[$dId] = [
            'id'    => $dId,
            'name'  => $ob['district_name'],
            'count' => 0,
        ];
    }
    $councilDistrictOptions[$dId]['count']++;
}
uasort($councilDistrictOptions, fn($a, $b) => strcmp($a['name'], $b['name']));

// 2. Fetch State Committee Bearers
$stateBearers = Database::fetchAll(
    "SELECT * FROM office_bearers
     WHERE status = 'active' AND district_id IS NULL AND taluk_id IS NULL
     ORDER BY sort_order, name"
);

// 3. Fetch District Committee Bearers
$districtBearers = Database::fetchAll(
    "SELECT ob.*, d.name AS district_name FROM office_bearers ob
     JOIN districts d ON d.id = ob.district_id
     WHERE ob.status = 'active' AND ob.taluk_id IS NULL
     ORDER BY d.name, ob.sort_order, ob.name"
);

// Group unique districts in District Committee
$districtOptions = [];
foreach ($districtBearers as $ob) {
    $dId = (int) $ob['district_id'];
    if (!isset($districtOptions[$dId])) {
        $districtOptions[$dId] = [
            'id'    => $dId,
            'name'  => $ob['district_name'],
            'count' => 0,
        ];
    }
    $districtOptions[$dId]['count']++;
}
uasort($districtOptions, fn($a, $b) => strcmp($a['name'], $b['name']));

// 4. Fetch Taluk Committee Bearers
$talukBearers = Database::fetchAll(
    "SELECT ob.*, d.name AS district_name, t.name AS taluk_name FROM office_bearers ob
     JOIN taluks t ON t.id = ob.taluk_id
     JOIN districts d ON d.id = t.district_id
     WHERE ob.status = 'active'
     ORDER BY d.name, t.name, ob.sort_order, ob.name"
);

// Group districts and taluks for Taluk Committee
$talukDistricts = [];
$taluksByDistrict = [];
foreach ($talukBearers as $ob) {
    $dId = (int) $ob['district_id'];
    $tId = (int) $ob['taluk_id'];
    if (!isset($talukDistricts[$dId])) {
        $talukDistricts[$dId] = [
            'id'    => $dId,
            'name'  => $ob['district_name'],
            'count' => 0,
        ];
        $taluksByDistrict[$dId] = [];
    }
    $talukDistricts[$dId]['count']++;

    if (!isset($taluksByDistrict[$dId][$tId])) {
        $taluksByDistrict[$dId][$tId] = [
            'id'    => $tId,
            'name'  => $ob['taluk_name'],
            'count' => 0,
        ];
    }
    $taluksByDistrict[$dId][$tId]['count']++;
}
uasort($talukDistricts, fn($a, $b) => strcmp($a['name'], $b['name']));
foreach ($taluksByDistrict as &$tList) {
    uasort($tList, fn($a, $b) => strcmp($a['name'], $b['name']));
}
unset($tList);

$pageTitle = 'Office Bearers';
require __DIR__ . '/includes/partials/header.php';
?>

<style>
    html {
        scroll-behavior: smooth;
    }
    .office-bearer-section {
        scroll-margin-top: 155px;
    }
    .quick-jump-nav {
        display: flex;
        gap: 8px;
        margin: -8px 0 24px;
        flex-wrap: nowrap;
        align-items: center;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        padding-bottom: 6px;
    }
    .quick-jump-nav::-webkit-scrollbar {
        height: 4px;
    }
    .quick-jump-nav::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
    }
    .quick-jump-label {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--ink-500);
        white-space: nowrap;
        flex-shrink: 0;
    }
    .quick-jump-nav a.jump-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 13px;
        font-size: 0.82rem;
        font-weight: 600;
        color: var(--blue-700);
        background: #ffffff;
        border: 1px solid var(--border);
        border-radius: 999px;
        text-decoration: none;
        transition: all 0.15s ease;
        box-shadow: var(--shadow-sm);
        white-space: nowrap;
        flex-shrink: 0;
    }
    .quick-jump-nav a.jump-btn:hover {
        background: var(--blue-100);
        border-color: var(--blue-600);
        color: var(--blue-700);
        transform: translateY(-1px);
    }
    .filter-toolbar {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        background: var(--surface-alt, #f5f1e7);
        border: 1px solid var(--border, #e5dfd1);
        border-radius: var(--radius-md, 10px);
        padding: 12px 16px;
        margin-bottom: 18px;
    }
    .filter-toolbar .filter-group {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .filter-toolbar label {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--ink-700, #33415a);
        white-space: nowrap;
    }
    .filter-toolbar select {
        padding: 8px 12px;
        font-size: 0.9rem;
        font-family: var(--font-sans);
        border: 1px solid var(--border, #cbd5e1);
        border-radius: var(--radius-sm, 6px);
        background: #ffffff;
        color: var(--ink-900, #1e293b);
        cursor: pointer;
        min-width: 190px;
    }
    .filter-toolbar select:focus {
        outline: none;
        border-color: var(--blue-600);
        box-shadow: 0 0 0 3px var(--blue-100);
    }
    .filter-clear-btn {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 7px 12px;
        font-size: 0.82rem;
        font-weight: 600;
        background: #ffffff;
        color: var(--red-700, #b91c1c);
        border: 1px solid #fca5a5;
        border-radius: var(--radius-sm, 6px);
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .filter-clear-btn:hover {
        background: #fee2e2;
    }
    .filter-count-badge {
        font-size: 0.82rem;
        font-weight: 600;
        color: var(--teal-700);
        background: var(--teal-100);
        padding: 4px 12px;
        border-radius: 999px;
        margin-left: auto;
    }
    @media (max-width: 640px) {
        .filter-toolbar select {
            min-width: 100%;
            width: 100%;
        }
        .filter-toolbar .filter-group {
            width: 100%;
            flex-direction: column;
            align-items: flex-start;
        }
        .filter-count-badge {
            margin-left: 0;
            width: 100%;
            text-align: center;
        }
    }
</style>

<span class="eyebrow">Association Leadership</span>
<h1 class="page-title">Office Bearers (ಪದಾಧಿಕಾರಿಗಳು)</h1>
<p class="page-subtitle">Current leadership hierarchy of Karnataka State Postmen, Postwoman and MTS Association.</p>

<!-- Quick Jump Bar -->
<div class="quick-jump-nav">
    <span class="quick-jump-label">Jump to:</span>
    <a href="#state-council" class="jump-btn">🏛️ State Council (ರಾಜ್ಯ ಪರಿಷತ್ತು)</a>
    <a href="#state" class="jump-btn">🏛️ State Committee (ರಾಜ್ಯ ಸಂಘ)</a>
    <a href="#district" class="jump-btn">📍 District Committee (ಜಿಲ್ಲಾ ಸಂಘ)</a>
    <a href="#taluk" class="jump-btn">🏙️ Taluk Committee (ತಾಲ್ಲೂಕು ಸಂಘ)</a>
</div>

<?php if (!$canViewContact): ?>
    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-left:4px solid #2563eb; border-radius:6px; padding:10px 16px; margin-bottom:20px; font-size:0.84rem; color:var(--ink-700); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
        <div style="display:flex; align-items:center; gap:8px;">
            <span>🔒</span>
            <span><strong>ಗಮನಿಸಿ / Member Notice:</strong> ಪದಾಧಿಕಾರಿಗಳ ಸಂಪರ್ಕ ಸಂಖ್ಯೆಗಳನ್ನು (Contact Numbers) ವೀಕ್ಷಿಸಲು ಸಂಘದ ಸದಸ್ಯರು ಲಾಗಿನ್ ಆಗುವುದು ಕಡ್ಡಾಯವಾಗಿದೆ.</span>
        </div>
        <a href="/login.php" class="btn btn-sm btn-outline" style="white-space:nowrap; padding:4px 12px; font-size:0.8rem; font-weight:600; text-decoration:none;">
            🔑 Member Login (ಲಾಗಿನ್)
        </a>
    </div>
<?php endif; ?>

<!-- =======================================================================
     1) STATE COUNCIL SECTION (ರಾಜ್ಯ ಪರಿಷತ್ತು)
     ======================================================================= -->
<div class="card office-bearer-section" id="state-council">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:12px; border-bottom:1px solid var(--border-soft); padding-bottom:12px;">
        <div>
            <h2 style="margin:0; border:none; padding:0;">
                <span class="badge badge-blue" style="margin-right:8px;">Council</span>State Council (ರಾಜ್ಯ ಪರಿಷತ್ತು)
            </h2>
            <div style="font-size:0.82rem; color:var(--ink-500); margin-top:4px;">
                ಜಿಲ್ಲಾವಾರು ಚುನಾಯಿತ ಅಧ್ಯಕ್ಷರು, ರಾಜ್ಯ ಪರಿಷತ್ ಸದಸ್ಯರು ಹಾಗೂ ಖಜಾಂಚಿಗಳು ರಾಜ್ಯ ಪರಿಷತ್ತಿನ ಸದಸ್ಯರಾಗಿರುತ್ತಾರೆ.
            </div>
        </div>
        <span class="badge badge-muted"><?= count($stateCouncilBearers) ?> Members</span>
    </div>

    <!-- Council District Filter Toolbar -->
    <div class="filter-toolbar">
        <div class="filter-group">
            <label for="councilDistrictSelect">📍 Select District (ಜಿಲ್ಲೆ):</label>
            <select id="councilDistrictSelect" onchange="filterCouncilBearers(this.value)">
                <option value="all">All Districts (ಎಲ್ಲಾ ಜಿಲ್ಲೆಗಳು) — <?= count($stateCouncilBearers) ?> Members</option>
                <?php foreach ($councilDistrictOptions as $d): ?>
                    <option value="<?= $d['id'] ?>">
                        <?= Sanitize::html($d['name']) ?> (<?= $d['count'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="button" id="councilClearBtn" class="filter-clear-btn" style="display:none;" onclick="resetCouncilFilter()">
            ✕ Show All Districts
        </button>
        <span id="councilCountBadge" class="filter-count-badge">
            Showing all <?= count($stateCouncilBearers) ?> Council Members
        </span>
    </div>

    <?php if (!empty($stateCouncilBearers)): ?>
        <div class="table-wrap">
        <table class="plain">
            <thead>
                <tr>
                    <th style="width:45px; text-align:center;">#</th>
                    <th style="width:65px; text-align:center;">Photo (ಭಾವಚಿತ್ರ)</th>
                    <th>Name (ಹೆಸರು)</th>
                    <th>Designation (ಹುದ್ದೆ)</th>
                    <th>Term (ಅವಧಿ)</th>
                    <?php if ($canViewContact): ?>
                        <th>Contact Number (ಸಂಪರ್ಕ ಸಂಖ್ಯೆ)</th>
                    <?php endif; ?>
                    <th>Representative District (ಪ್ರತಿನಿಧಿಸುವ ಜಿಲ್ಲೆ)</th>
                </tr>
            </thead>
            <tbody id="councilTableBody">
                <?php foreach ($stateCouncilBearers as $idx => $ob): ?>
                <tr class="council-row" data-district-id="<?= (int)$ob['district_id'] ?>">
                    <td style="color:var(--ink-300); font-weight:700; text-align:center; vertical-align:middle;"><?= $idx + 1 ?></td>
                    <td style="text-align:center; vertical-align:middle;">
                        <?php if (!empty($ob['photo_path']) && file_exists(PUBLIC_HTML . '/' . ltrim($ob['photo_path'], '/'))): ?>
                            <img src="/<?= ltrim(Sanitize::attr($ob['photo_path']), '/') ?>" 
                                 alt="<?= Sanitize::attr($ob['name']) ?>" 
                                 style="width:44px; height:44px; object-fit:cover; border-radius:50%; border:2px solid #cbd5e1; display:inline-block;">
                        <?php else: ?>
                            <div style="width:44px; height:44px; border-radius:50%; background:#eff6ff; color:#1e40af; display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:0.85rem; border:1px solid #dbeafe;">
                                <?= mb_substr($ob['name'], 0, 1, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="vertical-align:middle;">
                        <strong style="color:var(--ink-900);"><?= Sanitize::html($ob['name']) ?></strong>
                        <?php if (!empty($ob['official_designation'])): ?>
                            <div style="font-size:0.8rem; color:var(--ink-500);"><?= Sanitize::html($ob['official_designation']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-family:'Noto Sans Kannada', sans-serif; font-weight:600; color:#1e3a8a; vertical-align:middle;">
                        <?= Sanitize::html($ob['association_designation']) ?>
                    </td>
                    <td style="white-space:nowrap; color:var(--ink-500); font-size:0.86rem; vertical-align:middle;">
                        <?= Sanitize::html($ob['term_start'] ?? '—') ?> to <?= Sanitize::html($ob['term_end'] ?? '—') ?>
                    </td>
                    <?php if ($canViewContact): ?>
                    <td style="white-space:nowrap; font-weight:500; vertical-align:middle;">
                        <?php if (!empty($ob['contact_number'])): ?>
                            <a href="tel:<?= Sanitize::attr($ob['contact_number']) ?>" style="color:#1d4ed8; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                📞 <?= Sanitize::html($ob['contact_number']) ?>
                            </a>
                        <?php else: ?>
                            <span style="color:var(--ink-400);">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td style="vertical-align:middle;">
                        <span class="badge badge-lav"><?= Sanitize::html($ob['district_name']) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr id="councilEmptyRow" style="display:none;">
                    <td colspan="<?= $canViewContact ? 7 : 6 ?>" style="text-align:center; padding:28px; color:var(--ink-500);">
                        No council members found for the selected district.
                    </td>
                </tr>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <p class="empty-state">State Council member details will appear here as district office bearers are added.</p>
    <?php endif; ?>
</div>

<!-- =======================================================================
     2) STATE COMMITTEE SECTION (ರಾಜ್ಯ ಸಂಘ)
     ======================================================================= -->
<div class="card office-bearer-section" id="state">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:18px; border-bottom:1px solid var(--border-soft); padding-bottom:12px;">
        <h2 style="margin:0; border:none; padding:0;">
            <span class="badge badge-green" style="margin-right:8px;">State</span>State Committee (ರಾಜ್ಯ ಸಂಘ)
        </h2>
        <span class="badge badge-muted"><?= count($stateBearers) ?> Bearers</span>
    </div>

    <?php if (!empty($stateBearers)): ?>
        <div class="table-wrap">
        <table class="plain">
            <thead>
                <tr>
                    <th style="width:45px; text-align:center;">#</th>
                    <th style="width:65px; text-align:center;">Photo (ಭಾವಚಿತ್ರ)</th>
                    <th>Name (ಹೆಸರು)</th>
                    <th>Designation (ಹುದ್ದೆ)</th>
                    <?php if ($canViewContact): ?>
                        <th>Contact Number (ಸಂಪರ್ಕ ಸಂಖ್ಯೆ)</th>
                    <?php endif; ?>
                    <th>Term (ಅವಧಿ)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stateBearers as $idx => $ob): ?>
                <tr>
                    <td style="color:var(--ink-300); font-weight:700; text-align:center; vertical-align:middle;"><?= $idx + 1 ?></td>
                    <td style="text-align:center; vertical-align:middle;">
                        <?php if (!empty($ob['photo_path']) && file_exists(PUBLIC_HTML . '/' . ltrim($ob['photo_path'], '/'))): ?>
                            <img src="/<?= ltrim(Sanitize::attr($ob['photo_path']), '/') ?>" 
                                 alt="<?= Sanitize::attr($ob['name']) ?>" 
                                 style="width:44px; height:44px; object-fit:cover; border-radius:50%; border:2px solid #cbd5e1; display:inline-block;">
                        <?php else: ?>
                            <div style="width:44px; height:44px; border-radius:50%; background:#eff6ff; color:#1e40af; display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:0.85rem; border:1px solid #dbeafe;">
                                <?= mb_substr($ob['name'], 0, 1, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="vertical-align:middle;">
                        <strong style="color:var(--ink-900); font-size:0.95rem;"><?= Sanitize::html($ob['name']) ?></strong>
                        <?php if (!empty($ob['official_designation'])): ?>
                            <div style="font-size:0.8rem; color:var(--ink-500);"><?= Sanitize::html($ob['official_designation']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-family:'Noto Sans Kannada', sans-serif; font-weight:600; color:#1e3a8a; vertical-align:middle;">
                        <?= Sanitize::html($ob['association_designation']) ?>
                    </td>
                    <?php if ($canViewContact): ?>
                    <td style="white-space:nowrap; font-weight:500; vertical-align:middle;">
                        <?php if (!empty($ob['contact_number'])): ?>
                            <a href="tel:<?= Sanitize::attr($ob['contact_number']) ?>" style="color:#1d4ed8; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                📞 <?= Sanitize::html($ob['contact_number']) ?>
                            </a>
                        <?php else: ?>
                            <span style="color:var(--ink-400);">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td style="white-space:nowrap; color:var(--ink-500); font-size:0.86rem; vertical-align:middle;">
                        <?= Sanitize::html($ob['term_start'] ?? '—') ?> to <?= Sanitize::html($ob['term_end'] ?? '—') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <p class="empty-state">State committee details will appear here shortly.</p>
    <?php endif; ?>
</div>

<!-- =======================================================================
     3) DISTRICT COMMITTEE SECTION (ಜಿಲ್ಲಾ ಸಂಘ)
     ======================================================================= -->
<div class="card office-bearer-section" id="district">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:14px; border-bottom:1px solid var(--border-soft); padding-bottom:12px;">
        <h2 style="margin:0; border:none; padding:0;">
            <span class="badge badge-lav" style="margin-right:8px;">District</span>District Committee (ಜಿಲ್ಲಾ ಸಂಘ)
        </h2>
        <span class="badge badge-muted"><?= count($districtBearers) ?> Bearers</span>
    </div>

    <!-- District Filter Toolbar -->
    <div class="filter-toolbar">
        <div class="filter-group">
            <label for="districtSelect">📍 Select District (ಜಿಲ್ಲೆ):</label>
            <select id="districtSelect" onchange="filterDistrictBearers(this.value)">
                <option value="all">All Districts (ಎಲ್ಲಾ ಜಿಲ್ಲೆಗಳು) — <?= count($districtBearers) ?> Bearers</option>
                <?php foreach ($districtOptions as $d): ?>
                    <option value="<?= $d['id'] ?>">
                        <?= Sanitize::html($d['name']) ?> (<?= $d['count'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="button" id="districtClearBtn" class="filter-clear-btn" style="display:none;" onclick="resetDistrictFilter()">
            ✕ Show All Districts
        </button>
        <span id="districtCountBadge" class="filter-count-badge">
            Showing all <?= count($districtBearers) ?> District Bearers
        </span>
    </div>

    <?php if (!empty($districtBearers)): ?>
        <div class="table-wrap">
        <table class="plain">
            <thead>
                <tr>
                    <th style="width:45px; text-align:center;">#</th>
                    <th style="width:65px; text-align:center;">Photo (ಭಾವಚಿತ್ರ)</th>
                    <th>Name (ಹೆಸರು)</th>
                    <th>Designation (ಹುದ್ದೆ)</th>
                    <th>Term (ಅವಧಿ)</th>
                    <?php if ($canViewContact): ?>
                        <th>Contact Number (ಸಂಪರ್ಕ ಸಂಖ್ಯೆ)</th>
                    <?php endif; ?>
                    <th>Representative District (ಪ್ರತಿನಿಧಿಸುವ ಜಿಲ್ಲೆ)</th>
                </tr>
            </thead>
            <tbody id="districtTableBody">
                <?php foreach ($districtBearers as $idx => $ob): ?>
                <tr class="district-row" data-district-id="<?= (int)$ob['district_id'] ?>">
                    <td style="color:var(--ink-300); font-weight:700; text-align:center; vertical-align:middle;"><?= $idx + 1 ?></td>
                    <td style="text-align:center; vertical-align:middle;">
                        <?php if (!empty($ob['photo_path']) && file_exists(PUBLIC_HTML . '/' . ltrim($ob['photo_path'], '/'))): ?>
                            <img src="/<?= ltrim(Sanitize::attr($ob['photo_path']), '/') ?>" 
                                 alt="<?= Sanitize::attr($ob['name']) ?>" 
                                 style="width:44px; height:44px; object-fit:cover; border-radius:50%; border:2px solid #cbd5e1; display:inline-block;">
                        <?php else: ?>
                            <div style="width:44px; height:44px; border-radius:50%; background:#eff6ff; color:#1e40af; display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:0.85rem; border:1px solid #dbeafe;">
                                <?= mb_substr($ob['name'], 0, 1, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="vertical-align:middle;">
                        <strong style="color:var(--ink-900);"><?= Sanitize::html($ob['name']) ?></strong>
                        <?php if (!empty($ob['official_designation'])): ?>
                            <div style="font-size:0.8rem; color:var(--ink-500);"><?= Sanitize::html($ob['official_designation']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-family:'Noto Sans Kannada', sans-serif; font-weight:600; color:#1e3a8a; vertical-align:middle;">
                        <?= Sanitize::html($ob['association_designation']) ?>
                    </td>
                    <td style="white-space:nowrap; color:var(--ink-500); font-size:0.86rem; vertical-align:middle;">
                        <?= Sanitize::html($ob['term_start'] ?? '—') ?> to <?= Sanitize::html($ob['term_end'] ?? '—') ?>
                    </td>
                    <?php if ($canViewContact): ?>
                    <td style="white-space:nowrap; font-weight:500; vertical-align:middle;">
                        <?php if (!empty($ob['contact_number'])): ?>
                            <a href="tel:<?= Sanitize::attr($ob['contact_number']) ?>" style="color:#1d4ed8; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                📞 <?= Sanitize::html($ob['contact_number']) ?>
                            </a>
                        <?php else: ?>
                            <span style="color:var(--ink-400);">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td style="vertical-align:middle;">
                        <span class="badge badge-lav"><?= Sanitize::html($ob['district_name']) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr id="districtEmptyRow" style="display:none;">
                    <td colspan="<?= $canViewContact ? 7 : 6 ?>" style="text-align:center; padding:28px; color:var(--ink-500);">
                        No office bearers found for the selected district.
                    </td>
                </tr>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <p class="empty-state">District office bearer details have not been added yet.</p>
    <?php endif; ?>
</div>

<!-- =======================================================================
     4) TALUK COMMITTEE SECTION (ತಾಲ್ಲೂಕು ಸಂಘ)
     ======================================================================= -->
<div class="card office-bearer-section" id="taluk">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:14px; border-bottom:1px solid var(--border-soft); padding-bottom:12px;">
        <h2 style="margin:0; border:none; padding:0;">
            <span class="badge badge-teal" style="margin-right:8px;">Taluk</span>Taluk Committee (ತಾಲ್ಲೂಕು ಸಂಘ)
        </h2>
        <span class="badge badge-muted"><?= count($talukBearers) ?> Bearers</span>
    </div>

    <!-- Taluk Filter Toolbar -->
    <div class="filter-toolbar">
        <div class="filter-group">
            <label for="talukDistrictSelect">📍 District (ಜಿಲ್ಲೆ):</label>
            <select id="talukDistrictSelect" onchange="onTalukDistrictChange(this.value)">
                <option value="all">All Districts (ಎಲ್ಲಾ ಜಿಲ್ಲೆಗಳು)</option>
                <?php foreach ($talukDistricts as $d): ?>
                    <option value="<?= $d['id'] ?>"><?= Sanitize::html($d['name']) ?> (<?= $d['count'] ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label for="talukSelect">🏙️ Taluk (ತಾಲ್ಲೂಕು):</label>
            <select id="talukSelect" onchange="filterTalukBearers()">
                <option value="all">All Taluks (ಎಲ್ಲಾ ತಾಲ್ಲೂಕುಗಳು)</option>
                <?php foreach ($talukDistricts as $d): ?>
                    <optgroup label="<?= Sanitize::attr($d['name']) ?>" data-district-id="<?= $d['id'] ?>">
                        <?php foreach ($taluksByDistrict[$d['id']] as $t): ?>
                            <option value="<?= $t['id'] ?>" data-district-id="<?= $d['id'] ?>">
                                <?= Sanitize::html($t['name']) ?> (<?= $t['count'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="button" id="talukClearBtn" class="filter-clear-btn" style="display:none;" onclick="resetTalukFilter()">
            ✕ Show All Taluks
        </button>
        <span id="talukCountBadge" class="filter-count-badge">
            Showing all <?= count($talukBearers) ?> Taluk Bearers
        </span>
    </div>

    <?php if (!empty($talukBearers)): ?>
        <div class="table-wrap">
        <table class="plain">
            <thead>
                <tr>
                    <th style="width:45px; text-align:center;">#</th>
                    <th style="width:65px; text-align:center;">Photo (ಭಾವಚಿತ್ರ)</th>
                    <th>Name (ಹೆಸರು)</th>
                    <th>Designation (ಹುದ್ದೆ)</th>
                    <th>Term (ಅವಧಿ)</th>
                    <?php if ($canViewContact): ?>
                        <th>Contact Number (ಸಂಪರ್ಕ ಸಂಖ್ಯೆ)</th>
                    <?php endif; ?>
                    <th>Taluk (ತಾಲ್ಲೂಕು)</th>
                    <th>Representative District (ಪ್ರತಿನಿಧಿಸುವ ಜಿಲ್ಲೆ)</th>
                </tr>
            </thead>
            <tbody id="talukTableBody">
                <?php foreach ($talukBearers as $idx => $ob): ?>
                <tr class="taluk-row" data-district-id="<?= (int)$ob['district_id'] ?>" data-taluk-id="<?= (int)$ob['taluk_id'] ?>">
                    <td style="color:var(--ink-300); font-weight:700; text-align:center; vertical-align:middle;"><?= $idx + 1 ?></td>
                    <td style="text-align:center; vertical-align:middle;">
                        <?php if (!empty($ob['photo_path']) && file_exists(PUBLIC_HTML . '/' . ltrim($ob['photo_path'], '/'))): ?>
                            <img src="/<?= ltrim(Sanitize::attr($ob['photo_path']), '/') ?>" 
                                 alt="<?= Sanitize::attr($ob['name']) ?>" 
                                 style="width:44px; height:44px; object-fit:cover; border-radius:50%; border:2px solid #cbd5e1; display:inline-block;">
                        <?php else: ?>
                            <div style="width:44px; height:44px; border-radius:50%; background:#eff6ff; color:#1e40af; display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:0.85rem; border:1px solid #dbeafe;">
                                <?= mb_substr($ob['name'], 0, 1, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="vertical-align:middle;">
                        <strong style="color:var(--ink-900);"><?= Sanitize::html($ob['name']) ?></strong>
                        <?php if (!empty($ob['official_designation'])): ?>
                            <div style="font-size:0.8rem; color:var(--ink-500);"><?= Sanitize::html($ob['official_designation']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-family:'Noto Sans Kannada', sans-serif; font-weight:600; color:#1e3a8a; vertical-align:middle;">
                        <?= Sanitize::html($ob['association_designation']) ?>
                    </td>
                    <td style="white-space:nowrap; color:var(--ink-500); font-size:0.86rem; vertical-align:middle;">
                        <?= Sanitize::html($ob['term_start'] ?? '—') ?> to <?= Sanitize::html($ob['term_end'] ?? '—') ?>
                    </td>
                    <?php if ($canViewContact): ?>
                    <td style="white-space:nowrap; font-weight:500; vertical-align:middle;">
                        <?php if (!empty($ob['contact_number'])): ?>
                            <a href="tel:<?= Sanitize::attr($ob['contact_number']) ?>" style="color:#1d4ed8; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                📞 <?= Sanitize::html($ob['contact_number']) ?>
                            </a>
                        <?php else: ?>
                            <span style="color:var(--ink-400);">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td style="vertical-align:middle;">
                        <span class="badge badge-teal"><?= Sanitize::html($ob['taluk_name']) ?></span>
                    </td>
                    <td style="vertical-align:middle;">
                        <span class="badge badge-lav"><?= Sanitize::html($ob['district_name']) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr id="talukEmptyRow" style="display:none;">
                    <td colspan="<?= $canViewContact ? 8 : 7 ?>" style="text-align:center; padding:28px; color:var(--ink-500);">
                        No office bearers found for the selected taluk.
                    </td>
                </tr>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <p class="empty-state">Taluk office bearer details have not been added yet.</p>
    <?php endif; ?>
</div>

<script>
// --- STATE COUNCIL FILTER ---
function filterCouncilBearers(districtId) {
    var rows = document.querySelectorAll('.council-row');
    var visible = 0;

    rows.forEach(function(row) {
        if (districtId === 'all' || row.getAttribute('data-district-id') === districtId) {
            row.style.display = '';
            visible++;
        } else {
            row.style.display = 'none';
        }
    });

    var emptyRow = document.getElementById('councilEmptyRow');
    if (emptyRow) emptyRow.style.display = (visible === 0) ? '' : 'none';

    var clearBtn = document.getElementById('councilClearBtn');
    if (clearBtn) clearBtn.style.display = (districtId !== 'all') ? 'inline-flex' : 'none';

    var badge = document.getElementById('councilCountBadge');
    if (badge) {
        var sel = document.getElementById('councilDistrictSelect');
        var name = (districtId !== 'all' && sel.selectedIndex >= 0)
            ? sel.options[sel.selectedIndex].text.split('(')[0].trim()
            : 'all';
        badge.textContent = (districtId === 'all')
            ? 'Showing all ' + visible + ' Council Members'
            : 'Showing ' + visible + ' in ' + name;
    }
}

function resetCouncilFilter() {
    var sel = document.getElementById('councilDistrictSelect');
    if (sel) {
        sel.value = 'all';
        filterCouncilBearers('all');
    }
}

// --- DISTRICT FILTER ---
function filterDistrictBearers(districtId) {
    var rows = document.querySelectorAll('.district-row');
    var visible = 0;

    rows.forEach(function(row) {
        if (districtId === 'all' || row.getAttribute('data-district-id') === districtId) {
            row.style.display = '';
            visible++;
        } else {
            row.style.display = 'none';
        }
    });

    var emptyRow = document.getElementById('districtEmptyRow');
    if (emptyRow) emptyRow.style.display = (visible === 0) ? '' : 'none';

    var clearBtn = document.getElementById('districtClearBtn');
    if (clearBtn) clearBtn.style.display = (districtId !== 'all') ? 'inline-flex' : 'none';

    var badge = document.getElementById('districtCountBadge');
    if (badge) {
        var sel = document.getElementById('districtSelect');
        var name = (districtId !== 'all' && sel.selectedIndex >= 0)
            ? sel.options[sel.selectedIndex].text.split('(')[0].trim()
            : 'all';
        badge.textContent = (districtId === 'all')
            ? 'Showing all ' + visible + ' District Bearers'
            : 'Showing ' + visible + ' in ' + name;
    }
}

function resetDistrictFilter() {
    var sel = document.getElementById('districtSelect');
    if (sel) {
        sel.value = 'all';
        filterDistrictBearers('all');
    }
}

// --- TALUK FILTER ---
function onTalukDistrictChange(districtId) {
    var talukSel = document.getElementById('talukSelect');
    if (!talukSel) return;

    var groups = talukSel.querySelectorAll('optgroup');
    groups.forEach(function(grp) {
        if (districtId === 'all' || grp.getAttribute('data-district-id') === districtId) {
            grp.style.display = '';
        } else {
            grp.style.display = 'none';
        }
    });

    talukSel.value = 'all';
    filterTalukBearers();
}

function filterTalukBearers() {
    var distId = document.getElementById('talukDistrictSelect').value;
    var talukId = document.getElementById('talukSelect').value;

    var rows = document.querySelectorAll('.taluk-row');
    var visible = 0;

    rows.forEach(function(row) {
        var rowDist = row.getAttribute('data-district-id');
        var rowTaluk = row.getAttribute('data-taluk-id');

        var matchDist = (distId === 'all' || rowDist === distId);
        var matchTaluk = (talukId === 'all' || rowTaluk === talukId);

        if (matchDist && matchTaluk) {
            row.style.display = '';
            visible++;
        } else {
            row.style.display = 'none';
        }
    });

    var emptyRow = document.getElementById('talukEmptyRow');
    if (emptyRow) emptyRow.style.display = (visible === 0) ? '' : 'none';

    var isFiltered = (distId !== 'all' || talukId !== 'all');
    var clearBtn = document.getElementById('talukClearBtn');
    if (clearBtn) clearBtn.style.display = isFiltered ? 'inline-flex' : 'none';

    var badge = document.getElementById('talukCountBadge');
    if (badge) {
        if (!isFiltered) {
            badge.textContent = 'Showing all ' + visible + ' Taluk Bearers';
        } else {
            var label = '';
            if (talukId !== 'all') {
                var tSel = document.getElementById('talukSelect');
                label = tSel.options[tSel.selectedIndex].text.split('(')[0].trim() + ' Taluk';
            } else {
                var dSel = document.getElementById('talukDistrictSelect');
                label = dSel.options[dSel.selectedIndex].text.split('(')[0].trim() + ' District';
            }
            badge.textContent = 'Showing ' + visible + ' in ' + label;
        }
    }
}

function resetTalukFilter() {
    var dSel = document.getElementById('talukDistrictSelect');
    var tSel = document.getElementById('talukSelect');
    if (dSel) dSel.value = 'all';
    if (tSel) {
        tSel.value = 'all';
        var groups = tSel.querySelectorAll('optgroup');
        groups.forEach(function(grp) { grp.style.display = ''; });
    }
    filterTalukBearers();
}

// Auto-select on initial page load from URL parameters
document.addEventListener('DOMContentLoaded', function() {
    var params = new URLSearchParams(window.location.search);
    var dParam = params.get('district');
    var tParam = params.get('taluk');

    if (dParam) {
        var cSel = document.getElementById('councilDistrictSelect');
        if (cSel && cSel.querySelector('option[value="' + dParam + '"]')) {
            cSel.value = dParam;
            filterCouncilBearers(dParam);
        }
        var dSel = document.getElementById('districtSelect');
        if (dSel && dSel.querySelector('option[value="' + dParam + '"]')) {
            dSel.value = dParam;
            filterDistrictBearers(dParam);
        }
        var tdSel = document.getElementById('talukDistrictSelect');
        if (tdSel && tdSel.querySelector('option[value="' + dParam + '"]')) {
            tdSel.value = dParam;
            onTalukDistrictChange(dParam);
        }
    }

    if (tParam) {
        var tSel = document.getElementById('talukSelect');
        if (tSel && tSel.querySelector('option[value="' + tParam + '"]')) {
            var opt = tSel.querySelector('option[value="' + tParam + '"]');
            var parentDist = opt.getAttribute('data-district-id');
            if (parentDist) {
                var tdSel = document.getElementById('talukDistrictSelect');
                if (tdSel) tdSel.value = parentDist;
                onTalukDistrictChange(parentDist);
            }
            tSel.value = tParam;
            filterTalukBearers();
        }
    }
});
</script>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
