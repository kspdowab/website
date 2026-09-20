<?php
/**
 * KSPDOWA — Member Portal: Photo Gallery
 * ============================================================
 * Official Resources: Association Photo Gallery
 * Gated for member access.
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

$categoryFilter = trim(Sanitize::string($_GET['cat'] ?? '', 50));

$galleryItems = [];
try {
    $whereClauses = ["status = 'active'"];
    $params = [];

    if ($categoryFilter !== '') {
        $whereClauses[] = "category = ?";
        $params[] = $categoryFilter;
    }

    $whereSql = implode(' AND ', $whereClauses);
    $dbPhotos = Database::fetchAll(
        "SELECT id, title, category, photo_date, description, file_path 
         FROM gallery_photos 
         WHERE {$whereSql} 
         ORDER BY sort_order ASC, photo_date DESC, id DESC",
        $params
    );

    if (!empty($dbPhotos)) {
        foreach ($dbPhotos as $p) {
            $dateStr = !empty($p['photo_date']) ? date('d M Y', strtotime($p['photo_date'])) : '';
            $galleryItems[] = [
                'image'    => $p['file_path'],
                'title'    => $p['title'],
                'category' => $p['category'] ?: 'General',
                'date'     => $dateStr,
                'desc'     => $p['description'] ?: ''
            ];
        }
    }
} catch (Throwable $e) {
    // Graceful fallback
}

// Default fallback images if database has no active rows
if (empty($galleryItems) && $categoryFilter === '') {
    $galleryItems = [
        [
            'image' => '/assets/images/gallery/meeting-state-executive.jpg',
            'title' => 'ರಾಜ್ಯ ಕಾರ್ಯಕಾರಿಣಿ ಸಭೆ, ಬೆಂಗಳೂರು',
            'category' => 'State Executive',
            'date'  => '15 Aug 2026',
            'desc'  => 'State Executive Committee meeting held at association headquarters.'
        ],
        [
            'image' => '/assets/images/gallery/technical-training.jpg',
            'title' => 'ತಾಂತ್ರಿಕ ತರಬೇತಿ ಕಾರ್ಯಕ್ರಮ',
            'category' => 'Training',
            'date'  => '22 Jul 2026',
            'desc'  => 'Technical skill development and Panchatantra 2.0 digital software training session.'
        ],
        [
            'image' => '/assets/images/gallery/district-pdo-coordination.jpg',
            'title' => 'ಜಿಲ್ಲಾ PDOಗಳ ಸಮನ್ವಯ ಸಭೆ',
            'category' => 'Coordination',
            'date'  => '05 Jul 2026',
            'desc'  => 'District-level Panchayat Development Officers coordination and welfare review conference.'
        ],
        [
            'image' => '/assets/images/gallery/association-deliberation.jpg',
            'title' => 'ಸಂಘದ ಸಮಾಲೋಚನಾ ಸಭೆ',
            'category' => 'Deliberation',
            'date'  => '18 Jun 2026',
            'desc'  => 'Special consultative session on service rules and cadre reorganization.'
        ],
        [
            'image' => '/assets/images/gallery/felicitation-ceremony.jpg',
            'title' => 'ಅಭಿನಂದನಾ ಸಮಾರಂಭ',
            'category' => 'Felicitation',
            'date'  => '30 May 2026',
            'desc'  => 'Honouring retired and exemplary Panchayat Development Officers for distinguished rural service.'
        ],
        [
            'image' => '/assets/images/gallery/general-meeting.jpg',
            'title' => 'ವಾರ್ಷಿಕ ಮಹಾಸಭೆ (AGM)',
            'category' => 'General Meeting',
            'date'  => '10 Apr 2026',
            'desc'  => 'Annual General Body Meeting of Karnataka State Panchayat Development Officers Welfare Association.'
        ]
    ];
}

$pageTitle  = 'Photo Gallery';
$activeMenu = 'gallery';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/member/index.php'],
    ['label' => 'Official Resources', 'url' => ''],
    ['label' => 'Photo Gallery', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Association Photo Gallery (ಭಾವಚಿತ್ರ ಗ್ಯಾಲರಿ)</h1>
        <p class="page-heading-subtitle">Visual archives of conventions, executive sessions, training workshops, and official welfare milestones</p>
    </div>
    <div>
        <span class="badge badge-purple" style="font-size:0.85rem; padding:6px 14px;">
            <?= count($galleryItems) ?> Photos
        </span>
    </div>
</div>

<div class="filter-card" style="margin-bottom:24px; padding:16px 20px; background:#fff; border:1px solid var(--border-color, #e2e8f0); border-radius:12px; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
    <span style="font-size:0.85rem; font-weight:700; color:var(--text-muted, #64748b); margin-right:6px;">Filter Category:</span>
    <a href="/member/gallery.php" class="btn <?= $categoryFilter === '' ? 'btn-primary' : 'btn-outline' ?> btn-sm">All Categories</a>
    <a href="/member/gallery.php?cat=State+Executive" class="btn <?= $categoryFilter === 'State Executive' ? 'btn-primary' : 'btn-outline' ?> btn-sm">State Executive</a>
    <a href="/member/gallery.php?cat=Training" class="btn <?= $categoryFilter === 'Training' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Training</a>
    <a href="/member/gallery.php?cat=Coordination" class="btn <?= $categoryFilter === 'Coordination' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Coordination</a>
    <a href="/member/gallery.php?cat=Events" class="btn <?= $categoryFilter === 'Events' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Events</a>
</div>

<?php if (empty($galleryItems)): ?>
    <div style="text-align:center; padding:60px 20px; background:#fff; border:1px solid #e2e8f0; border-radius:12px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="#94a3b8" stroke-width="1.5" style="margin-bottom:12px;"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        <h3 style="font-size:1.1rem; color:var(--text-main, #1e293b); margin-bottom:6px;">No photos found in this category</h3>
        <p style="font-size:0.9rem; color:var(--text-muted, #64748b);">Check back soon or choose another category.</p>
    </div>
<?php else: ?>
    <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:20px;">
        <?php foreach ($galleryItems as $item): ?>
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; box-shadow:0 2px 6px rgba(0,0,0,0.04); display:flex; flex-direction:column; transition:transform 0.2s, box-shadow 0.2s;">
                <div style="height:190px; overflow:hidden; position:relative; background:#0f172a; cursor:pointer;" onclick="openPhotoPreview('<?= Sanitize::attr($item['image']) ?>', '<?= Sanitize::attr($item['title']) ?>')">
                    <img src="<?= Sanitize::attr($item['image']) ?>" alt="<?= Sanitize::attr($item['title']) ?>" style="width:100%; height:100%; object-fit:cover; transition:transform 0.3s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                    <span style="position:absolute; top:10px; right:10px; background:rgba(15,23,42,0.75); color:#fff; font-size:0.75rem; font-weight:600; padding:3px 8px; border-radius:6px; backdrop-filter:blur(4px);">
                        <?= Sanitize::html($item['category']) ?>
                    </span>
                </div>
                <div style="padding:16px; flex-grow:1; display:flex; flex-direction:column;">
                    <div style="font-size:0.8rem; font-weight:600; color:var(--blue-600, #2563eb); margin-bottom:4px;">
                        <?= Sanitize::html($item['date']) ?>
                    </div>
                    <h3 style="font-size:1rem; font-weight:700; color:var(--text-main, #1e293b); margin:0 0 8px; line-height:1.4;">
                        <?= Sanitize::html($item['title']) ?>
                    </h3>
                    <?php if (!empty($item['desc'])): ?>
                        <p style="font-size:0.85rem; color:var(--text-muted, #64748b); margin:0 0 12px; line-height:1.5; flex-grow:1;">
                            <?= Sanitize::html($item['desc']) ?>
                        </p>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline btn-sm" style="align-self:flex-start; margin-top:auto;" onclick="openPhotoPreview('<?= Sanitize::attr($item['image']) ?>', '<?= Sanitize::attr($item['title']) ?>')">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        View Full Photo
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Lightbox Modal -->
<div id="photoLightboxModal" class="modal-backdrop" onclick="if(event.target===this) closePhotoPreview();" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.85); z-index:9999; align-items:center; justify-content:center; padding:20px;">
    <div style="position:relative; max-width:900px; width:100%; max-height:90vh; background:#0f172a; border-radius:12px; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.5); display:flex; flex-direction:column;">
        <div style="padding:12px 18px; display:flex; justify-content:space-between; align-items:center; background:#1e293b; border-bottom:1px solid #334155;">
            <h4 id="lightboxTitle" style="color:#fff; margin:0; font-size:1rem; font-weight:600;">Photo Preview</h4>
            <button type="button" onclick="closePhotoPreview();" style="background:none; border:none; color:#94a3b8; font-size:24px; cursor:pointer; line-height:1;">&times;</button>
        </div>
        <div style="padding:16px; display:flex; align-items:center; justify-content:center; flex-grow:1; max-height:calc(90vh - 60px);">
            <img id="lightboxImg" src="" alt="Full Preview" style="max-width:100%; max-height:75vh; object-fit:contain; border-radius:6px;">
        </div>
    </div>
</div>

<script>
function openPhotoPreview(src, title) {
    const modal = document.getElementById('photoLightboxModal');
    const img   = document.getElementById('lightboxImg');
    const t     = document.getElementById('lightboxTitle');
    img.src = src;
    t.textContent = title;
    modal.style.display = 'flex';
}
function closePhotoPreview() {
    const modal = document.getElementById('photoLightboxModal');
    modal.style.display = 'none';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closePhotoPreview();
});
</script>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
