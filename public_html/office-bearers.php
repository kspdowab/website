<?php
/**
 * KSPDOWA — Public Office Bearers Page (Phase 1)
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$stateBearers = Database::fetchAll(
    "SELECT * FROM office_bearers
     WHERE status = 'active' AND district_id IS NULL AND taluk_id IS NULL
     ORDER BY sort_order, name"
);

$districtBearers = Database::fetchAll(
    "SELECT ob.*, d.name AS district_name FROM office_bearers ob
     JOIN districts d ON d.id = ob.district_id
     WHERE ob.status = 'active' AND ob.taluk_id IS NULL
     ORDER BY d.name, ob.sort_order, ob.name"
);

$talukBearers = Database::fetchAll(
    "SELECT ob.*, d.name AS district_name, t.name AS taluk_name FROM office_bearers ob
     JOIN taluks t ON t.id = ob.taluk_id
     JOIN districts d ON d.id = t.district_id
     WHERE ob.status = 'active'
     ORDER BY d.name, t.name, ob.sort_order, ob.name"
);

$pageTitle = 'Office Bearers';
require __DIR__ . '/includes/partials/header.php';
?>

<span class="eyebrow">Association Leadership</span>
<h1 class="page-title">Office Bearers</h1>
<p class="page-subtitle">Current state, district, and taluk office bearers of the Association.</p>

<div class="card">
    <h2><span class="badge badge-green" style="margin-right:8px;">State</span>Committee</h2>
    <?php if (!empty($stateBearers)): ?>
        <div class="table-wrap">
        <table class="plain">
            <thead><tr><th>Name</th><th>Designation</th><th>Term</th></tr></thead>
            <?php foreach ($stateBearers as $ob): ?>
            <tr>
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
                            <strong><?= Sanitize::html($ob['name']) ?></strong>
                        </div>
                    </div>
                </td>
                <td><?= Sanitize::html($ob['association_designation']) ?></td>
                <td style="white-space:nowrap; color:var(--ink-300);">
                    <?= Sanitize::html($ob['term_start'] ?? '—') ?> – <?= Sanitize::html($ob['term_end'] ?? '—') ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
    <?php else: ?>
        <p class="empty-state">State committee details will appear here shortly.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2><span class="badge badge-lav" style="margin-right:8px;">District</span>Committees</h2>
    <?php if (!empty($districtBearers)): ?>
        <div class="table-wrap">
        <table class="plain">
            <thead><tr><th>District</th><th>Name</th><th>Designation</th></tr></thead>
            <?php foreach ($districtBearers as $ob): ?>
            <tr>
                <td><?= Sanitize::html($ob['district_name']) ?></td>
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
                            <strong><?= Sanitize::html($ob['name']) ?></strong>
                        </div>
                    </div>
                </td>
                <td><?= Sanitize::html($ob['association_designation']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
    <?php else: ?>
        <p class="empty-state">District office bearer details have not been added yet.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2><span class="badge badge-teal" style="margin-right:8px;">Taluk</span>Committees</h2>
    <?php if (!empty($talukBearers)): ?>
        <div class="table-wrap">
        <table class="plain">
            <thead><tr><th>Taluk</th><th>District</th><th>Name</th><th>Designation</th></tr></thead>
            <?php foreach ($talukBearers as $ob): ?>
            <tr>
                <td><?= Sanitize::html($ob['taluk_name']) ?></td>
                <td><?= Sanitize::html($ob['district_name']) ?></td>
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
                            <strong><?= Sanitize::html($ob['name']) ?></strong>
                        </div>
                    </div>
                </td>
                <td><?= Sanitize::html($ob['association_designation']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
    <?php else: ?>
        <p class="empty-state">Taluk office bearer details have not been added yet.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
