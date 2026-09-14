<?php
/**
 * KSPDOWA — Public Office Bearers Page
 * ============================================================
 * Displays State, District, and Taluk Office Bearers with
 * smooth section navigation, district/taluk dropdown filtering,
 * and live search counters.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// Fetch State Bearers
$stateBearers = Database::fetchAll(
    "SELECT * FROM office_bearers
     WHERE status = 'active' AND district_id IS NULL AND taluk_id IS NULL
     ORDER BY sort_order, name"
);

// Fetch District Bearers
$districtBearers = Database::fetchAll(
    "SELECT ob.*, d.name AS district_name FROM office_bearers ob
     JOIN districts d ON d.id = ob.district_id
     WHERE ob.status = 'active' AND ob.taluk_id IS NULL
     ORDER BY d.name, ob.sort_order, ob.name"
);

// Fetch Taluk Bearers
$talukBearers = Database::fetchAll(
    "SELECT ob.*, d.name AS district_name, t.name AS taluk_name FROM office_bearers ob
     JOIN taluks t ON t.id = ob.taluk_id
     JOIN districts d ON d.id = t.district_id
     WHERE ob.status = 'active'
     ORDER BY d.name, t.name, ob.sort_order, ob.name"
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
        gap: 10px;
        margin: -8px 0 24px;
        flex-wrap: wrap;
        align-items: center;
    }
    .quick-jump-nav a.jump-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 16px;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--blue-700);
        background: #ffffff;
        border: 1px solid var(--border);
        border-radius: 999px;
        text-decoration: none;
        transition: all 0.15s ease;
        box-shadow: var(--shadow-sm);
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
<p class="page-subtitle">Current state, district, and taluk leadership of Karnataka State Postmen, Postwoman and MTS Association.</p>

<!-- Quick Jump Bar -->
<div class="quick-jump-nav">
    <span style="font-size:0.85rem; font-weight:700; color:var(--ink-500);">Jump to:</span>
    <a href="#state" class="jump-btn">🏛️ State Committee (ರಾಜ್ಯ)</a>
    <a href="#district" class="jump-btn">📍 District Committees (ಜಿಲ್ಲೆ)</a>
    <a href="#taluk" class="jump-btn">🏙️ Taluk Committees (ತಾಲ್ಲೂಕು)</a>
</div>

<!-- =======================================================================
     STATE COMMITTEE SECTION
     ======================================================================= -->
<div class="card office-bearer-section" id="state">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:18px; border-bottom:1px solid var(--border-soft); padding-bottom:12px;">
        <h2 style="margin:0; border:none; padding:0;">
            <span class="badge badge-green" style="margin-right:8px;">State</span>State Committee (ರಾಜ್ಯ ಸಂಘ — ಉಪನಿಯಮ 46)
        </h2>
        <span class="badge badge-muted"><?= count($stateBearers) ?> Bearers</span>
    </div>

    <?php if (!empty($stateBearers)): ?>
        <div class="table-wrap">
        <table class="plain">
            <thead>
                <tr>
                    <th style="width:50px;">#</th>
                    <th>Name</th>
                    <th>Designation (ಹುದ್ದೆ)</th>
                    <th>Term</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stateBearers as $idx => $ob): ?>
                <tr>
                    <td style="color:var(--ink-300); font-weight:700;"><?= $idx + 1 ?></td>
                    <td>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <?php if (!empty($ob['photo_path']) && file_exists(PUBLIC_HTML . '/' . ltrim($ob['photo_path'], '/'))): ?>
                                <img src="/<?= ltrim(Sanitize::attr($ob['photo_path']), '/') ?>" 
                                     alt="<?= Sanitize::attr($ob['name']) ?>" 
                                     style="width:42px; height:42px; object-fit:cover; border-radius:50%; border:2px solid #cbd5e1; flex-shrink:0;">
                            <?php else: ?>
                                <div style="width:42px; height:42px; border-radius:50%; background:#eff6ff; color:#1e40af; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.85rem; flex-shrink:0; border:1px solid #dbeafe;">
                                    <?= mb_substr($ob['name'], 0, 1, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <strong style="color:var(--ink-900); font-size:0.95rem;"><?= Sanitize::html($ob['name']) ?></strong>
                                <?php if (!empty($ob['official_designation'])): ?>
                                    <div style="font-size:0.8rem; color:var(--ink-500);"><?= Sanitize::html($ob['official_designation']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td style="font-family:'Noto Sans Kannada', sans-serif; font-weight:600; color:#1e3a8a;">
                        <?= Sanitize::html($ob['association_designation']) ?>
                    </td>
                    <td style="white-space:nowrap; color:var(--ink-500); font-size:0.86rem;">
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
     DISTRICT COMMITTEES SECTION
     ======================================================================= -->
<div class="card office-bearer-section" id="district">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:14px; border-bottom:1px solid var(--border-soft); padding-bottom:12px;">
        <h2 style="margin:0; border:none; padding:0;">
            <span class="badge badge-lav" style="margin-right:8px;">District</span>District Committees (ಜಿಲ್ಲಾ ಸಂಘ — ಉಪನಿಯಮ 26)
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
                    <th style="width:140px;">District</th>
                    <th>Name</th>
                    <th>Designation (ಹುದ್ದೆ)</th>
                    <th>Term</th>
                </tr>
            </thead>
            <tbody id="districtTableBody">
                <?php foreach ($districtBearers as $ob): ?>
                <tr class="district-row" data-district-id="<?= (int)$ob['district_id'] ?>">
                    <td>
                        <span class="badge badge-lav"><?= Sanitize::html($ob['district_name']) ?></span>
                    </td>
                    <td>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <?php if (!empty($ob['photo_path']) && file_exists(PUBLIC_HTML . '/' . ltrim($ob['photo_path'], '/'))): ?>
                                <img src="/<?= ltrim(Sanitize::attr($ob['photo_path']), '/') ?>" 
                                     alt="<?= Sanitize::attr($ob['name']) ?>" 
                                     style="width:40px; height:40px; object-fit:cover; border-radius:50%; border:2px solid #cbd5e1; flex-shrink:0;">
                            <?php else: ?>
                                <div style="width:40px; height:40px; border-radius:50%; background:#eff6ff; color:#1e40af; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.85rem; flex-shrink:0; border:1px solid #dbeafe;">
                                    <?= mb_substr($ob['name'], 0, 1, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <strong style="color:var(--ink-900);"><?= Sanitize::html($ob['name']) ?></strong>
                                <?php if (!empty($ob['official_designation'])): ?>
                                    <div style="font-size:0.8rem; color:var(--ink-500);"><?= Sanitize::html($ob['official_designation']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td style="font-family:'Noto Sans Kannada', sans-serif; font-weight:600; color:#1e3a8a;">
                        <?= Sanitize::html($ob['association_designation']) ?>
                    </td>
                    <td style="white-space:nowrap; color:var(--ink-500); font-size:0.86rem;">
                        <?= Sanitize::html($ob['term_start'] ?? '—') ?> to <?= Sanitize::html($ob['term_end'] ?? '—') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr id="districtEmptyRow" style="display:none;">
                    <td colspan="4" style="text-align:center; padding:28px; color:var(--ink-500);">
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
     TALUK COMMITTEES SECTION
     ======================================================================= -->
<div class="card office-bearer-section" id="taluk">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:14px; border-bottom:1px solid var(--border-soft); padding-bottom:12px;">
        <h2 style="margin:0; border:none; padding:0;">
            <span class="badge badge-teal" style="margin-right:8px;">Taluk</span>Taluk Committees (ತಾಲ್ಲೂಕು ಸಂಘ)
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
                    <th style="width:130px;">Taluk</th>
                    <th style="width:130px;">District</th>
                    <th>Name</th>
                    <th>Designation (ಹುದ್ದೆ)</th>
                    <th>Term</th>
                </tr>
            </thead>
            <tbody id="talukTableBody">
                <?php foreach ($talukBearers as $ob): ?>
                <tr class="taluk-row" data-district-id="<?= (int)$ob['district_id'] ?>" data-taluk-id="<?= (int)$ob['taluk_id'] ?>">
                    <td>
                        <span class="badge badge-teal"><?= Sanitize::html($ob['taluk_name']) ?></span>
                    </td>
                    <td style="color:var(--ink-500); font-weight:500;">
                        <?= Sanitize::html($ob['district_name']) ?>
                    </td>
                    <td>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <?php if (!empty($ob['photo_path']) && file_exists(PUBLIC_HTML . '/' . ltrim($ob['photo_path'], '/'))): ?>
                                <img src="/<?= ltrim(Sanitize::attr($ob['photo_path']), '/') ?>" 
                                     alt="<?= Sanitize::attr($ob['name']) ?>" 
                                     style="width:40px; height:40px; object-fit:cover; border-radius:50%; border:2px solid #cbd5e1; flex-shrink:0;">
                            <?php else: ?>
                                <div style="width:40px; height:40px; border-radius:50%; background:#eff6ff; color:#1e40af; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.85rem; flex-shrink:0; border:1px solid #dbeafe;">
                                    <?= mb_substr($ob['name'], 0, 1, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <strong style="color:var(--ink-900);"><?= Sanitize::html($ob['name']) ?></strong>
                                <?php if (!empty($ob['official_designation'])): ?>
                                    <div style="font-size:0.8rem; color:var(--ink-500);"><?= Sanitize::html($ob['official_designation']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td style="font-family:'Noto Sans Kannada', sans-serif; font-weight:600; color:#1e3a8a;">
                        <?= Sanitize::html($ob['association_designation']) ?>
                    </td>
                    <td style="white-space:nowrap; color:var(--ink-500); font-size:0.86rem;">
                        <?= Sanitize::html($ob['term_start'] ?? '—') ?> to <?= Sanitize::html($ob['term_end'] ?? '—') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr id="talukEmptyRow" style="display:none;">
                    <td colspan="5" style="text-align:center; padding:28px; color:var(--ink-500);">
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

// Auto-select on initial page load from URL parameters or hash
document.addEventListener('DOMContentLoaded', function() {
    var params = new URLSearchParams(window.location.search);
    var dParam = params.get('district');
    var tParam = params.get('taluk');

    if (dParam) {
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
