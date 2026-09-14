<?php
/**
 * KSPDOWA BENGALURU — Public Photo Gallery
 * ============================================================
 * Professional Blue visual system (Design 1)
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Association Gallery';
require __DIR__ . '/includes/partials/header.php';

$galleryItems = [
    [
        'image' => '/assets/images/gallery/meeting-state-executive.jpg',
        'title' => 'ರಾಜ್ಯ ಕಾರ್ಯಕಾರಿಣಿ ಸಭೆ, ಬೆಂಗಳೂರು',
        'category' => 'State Executive',
        'date'  => '15 Aug 2026',
        'desc'  => 'State Executive Committee meeting held at the association headquarters in Bengaluru to discuss key PDO welfare initiatives.'
    ],
    [
        'image' => '/assets/images/gallery/technical-training.jpg',
        'title' => 'ತಾಂತ್ರಿಕ ತರಬೇತಿ ಕಾರ್ಯಕ್ರಮ',
        'category' => 'Training',
        'date'  => '22 Jul 2026',
        'desc'  => 'Technical skill development and Panchatantra 2.0 digital software training session conducted for Panchayat Development Officers.'
    ],
    [
        'image' => '/assets/images/gallery/district-pdo-coordination.jpg',
        'title' => 'ಜಿಲ್ಲಾ PDOಗಳ ಸಮನ್ವಯ ಸಭೆ',
        'category' => 'Coordination',
        'date'  => '05 Jul 2026',
        'desc'  => 'District-level PDO coordination conference reviewing rural developmental projects and administrative coordination.'
    ],
    [
        'image' => '/assets/images/gallery/felicitation-ceremony.jpg',
        'title' => 'ಸನ್ಮಾನ ಕಾರ್ಯಕ್ರಮ',
        'category' => 'Felicitation',
        'date'  => '18 Jun 2026',
        'desc'  => 'Felicitation ceremony recognizing outstanding service, dedication, and exemplary administrative excellence by association officers.'
    ],
    [
        'image' => '/assets/images/gallery/general-meeting.jpg',
        'title' => 'ರಾಜ್ಯ ಮಟ್ಟದ ಮಹಾಸಭೆ',
        'category' => 'General Body',
        'date'  => '28 May 2026',
        'desc'  => 'Annual state level general body convention with representative delegates from all 31 districts of Karnataka.'
    ],
    [
        'image' => '/assets/images/gallery/association-deliberation.jpg',
        'title' => 'ಸಂಘದ ಕಾರ್ಯಕಾರಿಣಿ ಸಮಾಲೋಚನೆ',
        'category' => 'Executive Consultation',
        'date'  => '17 May 2026',
        'desc'  => 'Executive deliberations on service rule amendments, cadre review, and welfare representations.'
    ]
];
?>

<div style="margin-bottom: 24px;">
    <span class="eyebrow">Visual Archives</span>
    <h1 class="page-title">Association Photo Gallery</h1>
    <p class="page-subtitle">Moments from our official activities, state conventions, training sessions, and welfare programmes across Karnataka.</p>
</div>

<div class="gallery-grid">
    <?php foreach ($galleryItems as $idx => $photo): ?>
        <div class="gallery-grid-card" onclick="openLightbox(<?= $idx ?>)">
            <div class="gallery-grid-image">
                <img src="<?= Sanitize::attr($photo['image']) ?>" alt="<?= Sanitize::attr($photo['title']) ?>" loading="lazy">
                <div class="gallery-grid-badge"><?= Sanitize::html($photo['category']) ?></div>
            </div>
            <div class="gallery-grid-body">
                <h2 class="gallery-grid-title"><?= Sanitize::html($photo['title']) ?></h2>
                <div class="gallery-grid-date">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span><?= Sanitize::html($photo['date']) ?></span>
                </div>
                <p class="gallery-grid-desc"><?= Sanitize::html($photo['desc']) ?></p>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Modal Lightbox -->
<div id="galleryLightbox" class="gallery-lightbox" role="dialog" aria-modal="true" aria-label="Photo Lightbox" style="display:none;">
    <div class="gallery-lightbox-backdrop" onclick="closeLightbox()"></div>
    <div class="gallery-lightbox-modal">
        <button type="button" class="gallery-lightbox-close" onclick="closeLightbox()" aria-label="Close photo view">&times;</button>
        <div class="gallery-lightbox-content">
            <img id="lightboxImg" src="" alt="Enlarged view">
            <div class="gallery-lightbox-info">
                <h3 id="lightboxTitle" style="margin:0 0 4px; color:var(--primary-navy); font-size:1.15rem;"></h3>
                <span id="lightboxDate" style="color:var(--text-secondary); font-size:0.85rem; display:block; margin-bottom:8px;"></span>
                <p id="lightboxDesc" style="margin:0; color:var(--text-main); font-size:0.9rem;"></p>
            </div>
        </div>
    </div>
</div>

<style>
.gallery-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
    margin-bottom: 40px;
}
.gallery-grid-card {
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    display: flex;
    flex-direction: column;
}
.gallery-grid-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-md);
    border-color: #CBD5E1;
}
.gallery-grid-image {
    position: relative;
    aspect-ratio: 16 / 10;
    overflow: hidden;
    background: var(--light-blue);
}
.gallery-grid-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
    display: block;
}
.gallery-grid-card:hover .gallery-grid-image img {
    transform: scale(1.05);
}
.gallery-grid-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    background: rgba(23, 63, 103, 0.85);
    color: #ffffff;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 999px;
    backdrop-filter: blur(4px);
    letter-spacing: 0.02em;
}
.gallery-grid-body {
    padding: 18px 20px 20px;
    display: flex;
    flex-direction: column;
    flex: 1;
}
.gallery-grid-title {
    margin: 0 0 6px;
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--primary-navy);
    line-height: 1.35;
}
.gallery-grid-date {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin-bottom: 10px;
}
.gallery-grid-desc {
    margin: 0;
    font-size: 0.86rem;
    color: var(--text-secondary);
    line-height: 1.45;
}

/* Lightbox */
.gallery-lightbox {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.gallery-lightbox-backdrop {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(15, 23, 42, 0.75);
    backdrop-filter: blur(4px);
}
.gallery-lightbox-modal {
    position: relative;
    background: #ffffff;
    border-radius: var(--radius-lg);
    max-width: 800px;
    width: 100%;
    overflow: hidden;
    box-shadow: var(--shadow-lg);
    z-index: 10;
}
.gallery-lightbox-close {
    position: absolute;
    top: 12px;
    right: 12px;
    width: 36px;
    height: 36px;
    background: rgba(23, 63, 103, 0.85);
    color: #ffffff;
    border: none;
    border-radius: 50%;
    font-size: 1.5rem;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 12;
    transition: background 0.15s ease;
}
.gallery-lightbox-close:hover {
    background: var(--brand-red);
}
.gallery-lightbox-content img {
    width: 100%;
    max-height: 500px;
    object-fit: cover;
    display: block;
}
.gallery-lightbox-info {
    padding: 20px 24px;
}

@media (max-width: 900px) {
    .gallery-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 580px) {
    .gallery-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
var galleryData = <?= json_encode($galleryItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

function openLightbox(idx) {
    var item = galleryData[idx];
    if (!item) return;
    document.getElementById('lightboxImg').src = item.image;
    document.getElementById('lightboxTitle').textContent = item.title;
    document.getElementById('lightboxDate').textContent = item.date + ' • ' + item.category;
    document.getElementById('lightboxDesc').textContent = item.desc;
    document.getElementById('galleryLightbox').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    document.getElementById('galleryLightbox').style.display = 'none';
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLightbox();
});
</script>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
