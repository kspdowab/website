<?php
/**
 * KSPDOWA BENGALURU — Public Home Page
 * ============================================================
 * Professional Blue visual system (Design 1)
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$recognitionDoc = Database::fetchOne(
    "SELECT id, title FROM documents WHERE access_level = 'public' AND status = 'active'
     AND file_path LIKE 'documents/kspdowa-recognition-order%' LIMIT 1"
);

$latestNews = Database::fetchAll(
    "SELECT id, title, slug, published_at FROM news
     WHERE status = 'published' AND published_at IS NOT NULL
     ORDER BY published_at DESC LIMIT 3"
);

$officeBearerHighlights = Database::fetchAll(
    "SELECT name, association_designation FROM office_bearers
     WHERE status = 'active' AND district_id IS NULL AND taluk_id IS NULL
     ORDER BY sort_order LIMIT 4"
);

$officeBearerCount = Database::fetchOne("SELECT COUNT(*) c FROM office_bearers WHERE status = 'active'");
$publishedNewsCount = Database::fetchOne(
    "SELECT COUNT(*) c FROM news WHERE status = 'published' AND published_at IS NOT NULL"
);

// Real association gallery photos from verified historical association events
$galleryItems = [
    [
        'image' => '/assets/images/gallery/meeting-state-executive.jpg',
        'title' => 'ರಾಜ್ಯ ಕಾರ್ಯಕಾರಿಣಿ ಸಭೆ, ಬೆಂಗಳೂರು',
        'date'  => '15 Aug 2026',
        'alt'   => 'State Executive Meeting Bengaluru'
    ],
    [
        'image' => '/assets/images/gallery/technical-training.jpg',
        'title' => 'ತಾಂತ್ರಿಕ ತರಬೇತಿ ಕಾರ್ಯಕ್ರಮ',
        'date'  => '22 Jul 2026',
        'alt'   => 'Technical Training Programme'
    ],
    [
        'image' => '/assets/images/gallery/district-pdo-coordination.jpg',
        'title' => 'ಜಿಲ್ಲಾ PDOಗಳ ಸಮನ್ವಯ ಸಭೆ',
        'date'  => '05 Jul 2026',
        'alt'   => 'District PDO Coordination Meeting'
    ],
    [
        'image' => '/assets/images/gallery/felicitation-ceremony.jpg',
        'title' => 'ಸನ್ಮಾನ ಕಾರ್ಯಕ್ರಮ',
        'date'  => '18 Jun 2026',
        'alt'   => 'Felicitation Ceremony'
    ],
    [
        'image' => '/assets/images/gallery/general-meeting.jpg',
        'title' => 'ರಾಜ್ಯ ಮಟ್ಟದ ಮಹಾಸಭೆ',
        'date'  => '28 May 2026',
        'alt'   => 'State Level General Meeting'
    ],
    [
        'image' => '/assets/images/gallery/association-deliberation.jpg',
        'title' => 'ಸಂಘದ ಕಾರ್ಯಕಾರಿಣಿ ಸಮಾಲೋಚನೆ',
        'date'  => '17 May 2026',
        'alt'   => 'Association Deliberation'
    ]
];

$pageTitle = 'Home';
require __DIR__ . '/includes/partials/header.php';
?>

<!-- ═══════════════════════════════════════════════════════════════════
     ASSOCIATION GALLERY CAROUSEL (Directly below Header)
     ═══════════════════════════════════════════════════════════════════ -->
<section class="card gallery-card" aria-label="Association Photo Gallery">
    <div class="gallery-header">
        <div class="gallery-title-area">
            <span class="icon-badge blue" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>
            </span>
            <div>
                <h2 class="gallery-title">Association Gallery</h2>
                <p class="gallery-subtitle">Moments from our activities, meetings and programmes</p>
            </div>
        </div>
        <a href="/gallery.php" class="btn btn-secondary btn-sm">
            <span>View All Photos</span>
            <span aria-hidden="true">&rarr;</span>
        </a>
    </div>

    <!-- Carousel Container -->
    <div class="gallery-carousel" id="associationGalleryCarousel">
        <button type="button" class="gallery-nav-btn prev" id="galleryPrevBtn" aria-label="Previous photos">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        </button>

        <div class="gallery-track" id="galleryTrack" tabindex="0" role="region" aria-label="Gallery photo slides">
            <?php foreach ($galleryItems as $index => $item): ?>
                <div class="gallery-item" data-index="<?= $index ?>">
                    <div class="gallery-image-wrap">
                        <img src="<?= Sanitize::attr($item['image']) ?>" alt="<?= Sanitize::attr($item['alt']) ?>" loading="lazy">
                    </div>
                    <div class="gallery-item-meta">
                        <h3 class="gallery-item-title"><?= Sanitize::html($item['title']) ?></h3>
                        <span class="gallery-item-date"><?= Sanitize::html($item['date']) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <button type="button" class="gallery-nav-btn next" id="galleryNextBtn" aria-label="Next photos">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
    </div>

    <!-- Pagination Dots -->
    <div class="gallery-dots" id="galleryDots" role="tablist" aria-label="Gallery pagination">
        <button type="button" class="gallery-dot active" data-slide="0" aria-label="Slide 1"></button>
        <button type="button" class="gallery-dot" data-slide="1" aria-label="Slide 2"></button>
        <button type="button" class="gallery-dot" data-slide="2" aria-label="Slide 3"></button>
        <button type="button" class="gallery-dot" data-slide="3" aria-label="Slide 4"></button>
        <button type="button" class="gallery-dot" data-slide="4" aria-label="Slide 5"></button>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════
     KEY METRICS / STATS CARDS (3-Column Layout)
     ═══════════════════════════════════════════════════════════════════ -->
<section class="stats-overview-grid" aria-label="Association Overview">
    <!-- Stat 1: Active Office Bearers -->
    <div class="stat-card-pro">
        <span class="icon-badge-pro blue" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </span>
        <div class="stat-card-text">
            <div class="stat-card-value"><?= (int) ($officeBearerCount['c'] ?? 0) ?></div>
            <div class="stat-card-label">Active Office Bearers</div>
            <div class="stat-card-desc">Working for a stronger PDO community</div>
        </div>
    </div>

    <!-- Stat 2: News Updates Published -->
    <div class="stat-card-pro">
        <span class="icon-badge-pro saffron" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        </span>
        <div class="stat-card-text">
            <div class="stat-card-value"><?= (int) ($publishedNewsCount['c'] ?? 0) ?></div>
            <div class="stat-card-label">News Updates Published</div>
            <div class="stat-card-desc">Latest activities and announcements</div>
        </div>
    </div>

    <!-- Stat 3: Recognized -->
    <div class="stat-card-pro">
        <span class="icon-badge-pro purple" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>
        </span>
        <div class="stat-card-text">
            <div class="stat-card-value" style="font-size: 1.55rem; line-height: 1.15;">Recognized</div>
            <div class="stat-card-label">Govt. of Karnataka, RDPR Dept.</div>
            <div class="stat-card-desc" style="font-size: 0.74rem;">Under the Karnataka Civil Services (Recognition of Service Associations) Rules, 2015.</div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════
     LATEST NEWS SECTION
     ═══════════════════════════════════════════════════════════════════ -->
<section class="card section-card">
    <div class="section-card-header">
        <div class="section-title-wrap">
            <span class="icon-badge blue" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8V6Z"/></svg>
            </span>
            <h2 class="section-heading">Latest News</h2>
        </div>
        <a href="/news.php" class="section-link">
            <span>View all news</span>
            <span aria-hidden="true">&rarr;</span>
        </a>
    </div>

    <?php if (!empty($latestNews)): ?>
        <div class="news-list-container">
            <?php foreach ($latestNews as $item): ?>
                <div class="news-item-box">
                    <div class="news-item-content">
                        <a href="/news.php#news-<?= (int)$item['id'] ?>" class="news-item-title"><?= Sanitize::html($item['title']) ?></a>
                        <p class="news-item-desc">Association meeting schedule and key discussions.</p>
                    </div>
                    <div class="news-item-date">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <span><?= Sanitize::html(date('d M Y', strtotime((string) $item['published_at']))) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="news-item-box">
            <div class="news-item-content">
                <span class="news-item-title">ಸಂಘದ ಪದಾಧಿಕಾರಿಗಳ ಸಭೆ</span>
                <p class="news-item-desc">Association meeting schedule and key discussions.</p>
            </div>
            <div class="news-item-date">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span>12 Sep 2026</span>
            </div>
        </div>
    <?php endif; ?>
</section>

<!-- ═══════════════════════════════════════════════════════════════════
     STATE OFFICE BEARERS HIGHLIGHT TABLE
     ═══════════════════════════════════════════════════════════════════ -->
<section class="card section-card">
    <div class="section-card-header">
        <div class="section-title-wrap">
            <span class="icon-badge blue" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </span>
            <h2 class="section-heading">State Office Bearers</h2>
        </div>
        <a href="/office-bearers.php" class="section-link">
            <span>View full office bearer list</span>
            <span aria-hidden="true">&rarr;</span>
        </a>
    </div>

    <div class="table-wrap" style="border:none;">
        <table class="plain plain-clean">
            <tbody>
            <?php if (!empty($officeBearerHighlights)): ?>
                <?php foreach ($officeBearerHighlights as $ob): ?>
                <tr>
                    <td style="font-weight: 600; color: var(--primary-navy); width: 50%; font-size: 0.95rem;"><?= Sanitize::html($ob['name']) ?></td>
                    <td style="color: var(--text-secondary); width: 50%; font-size: 0.92rem;"><?= Sanitize::html($ob['association_designation']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td style="font-weight: 600; color: var(--primary-navy); width: 50%;">ರಾಜಶೇಖರ ಬಾದವ</td>
                    <td style="color: var(--text-secondary); width: 50%;">ಗೌರವಾಧ್ಯಕ್ಷರು</td>
                </tr>
                <tr>
                    <td style="font-weight: 600; color: var(--primary-navy); width: 50%;">ದಿಲೀಪ್ ಮಹಪರ ಬಿ.ಎಂ</td>
                    <td style="color: var(--text-secondary); width: 50%;">ಅಧ್ಯಕ್ಷರು</td>
                </tr>
                <tr>
                    <td style="font-weight: 600; color: var(--primary-navy); width: 50%;">ಜುಬಾಸಿಂಗ್ ಜಾಧವ</td>
                    <td style="color: var(--text-secondary); width: 50%;">ಕಾರ್ಯಾಧ್ಯಕ್ಷರು</td>
                </tr>
                <tr>
                    <td style="font-weight: 600; color: var(--primary-navy); width: 50%;">ದಿನೇಶ್ ಕೆ. ಚೀಲಿಬಾರ್</td>
                    <td style="color: var(--text-secondary); width: 50%;">ಉಪಾಧ್ಯಕ್ಷರ ಸ್ಥಾನ (ಸಾಮಾನ್ಯ)</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════
     GET IN TOUCH / PORTAL ACCESS
     ═══════════════════════════════════════════════════════════════════ -->
<section class="card contact-action-card">
    <div class="contact-action-left">
        <span class="icon-badge blue" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
        </span>
        <div>
            <h2 class="contact-action-title">Get in Touch</h2>
            <p class="contact-action-desc">Reach the association or sign in to the member/officer portal.</p>
        </div>
    </div>
    <div class="contact-action-buttons">
        <a class="btn btn-secondary" href="/contact.php">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            <span>Contact</span>
        </a>
        <a class="btn" href="/login.php">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <span>Login</span>
        </a>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════
     HOMEPAGE INTERACTION STYLES & CAROUSEL SCRIPT
     ═══════════════════════════════════════════════════════════════════ -->
<style>
/* Gallery Card */
.gallery-card {
    padding: 24px 28px 20px;
    margin-bottom: 24px;
}
.gallery-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 20px;
}
.gallery-title-area {
    display: flex;
    align-items: center;
    gap: 14px;
}
.gallery-title {
    margin: 0;
    font-size: 1.35rem;
    font-weight: 700;
    color: var(--primary-navy);
    line-height: 1.25;
}
.gallery-subtitle {
    margin: 3px 0 0;
    font-size: 0.88rem;
    color: var(--text-secondary);
}

/* Gallery Carousel */
.gallery-carousel {
    position: relative;
    padding: 0 12px;
}
.gallery-track {
    display: flex;
    gap: 16px;
    overflow-x: auto;
    scroll-behavior: smooth;
    padding: 6px 4px 12px;
    scrollbar-width: none;
    -ms-overflow-style: none;
    -webkit-overflow-scrolling: touch;
}
.gallery-track::-webkit-scrollbar {
    display: none;
}
.gallery-item {
    flex: 0 0 calc((100% - 48px) / 4);
    display: flex;
    flex-direction: column;
    border-radius: var(--radius-md);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.gallery-image-wrap {
    width: 100%;
    aspect-ratio: 16 / 10;
    overflow: hidden;
    border-radius: var(--radius-md);
    background: var(--light-blue);
    box-shadow: 0 2px 6px rgba(23, 63, 103, 0.08);
}
.gallery-image-wrap img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.3s ease;
}
.gallery-item:hover .gallery-image-wrap img {
    transform: scale(1.04);
}
.gallery-item-meta {
    padding: 10px 2px 4px;
}
.gallery-item-title {
    font-size: 0.94rem;
    font-weight: 700;
    color: var(--prof-blue);
    margin: 0 0 3px;
    line-height: 1.35;
    font-family: var(--font-sans);
}
.gallery-item-date {
    font-size: 0.8rem;
    color: var(--text-secondary);
    display: block;
}

/* Nav Buttons */
.gallery-nav-btn {
    position: absolute;
    top: 38%;
    transform: translateY(-50%);
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--primary-navy);
    color: #ffffff;
    border: 2px solid #ffffff;
    box-shadow: 0 3px 8px rgba(23, 63, 103, 0.22);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 10;
    transition: all 0.15s ease;
}
.gallery-nav-btn.prev { left: -8px; }
.gallery-nav-btn.next { right: -8px; }
.gallery-nav-btn:hover {
    background: var(--prof-blue);
    transform: translateY(-50%) scale(1.08);
}

/* Dots */
.gallery-dots {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    margin-top: 14px;
}
.gallery-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #CBD5E1;
    border: none;
    padding: 0;
    cursor: pointer;
    transition: all 0.2s ease;
}
.gallery-dot.active {
    background: var(--prof-blue);
    width: 10px;
    height: 10px;
}

/* Stats Overview (3 Column) */
.stats-overview-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 24px;
}
.stat-card-pro {
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 24px 22px;
    display: flex;
    align-items: center;
    gap: 18px;
    box-shadow: var(--shadow-sm);
    transition: box-shadow 0.2s ease, border-color 0.2s ease;
}
.stat-card-pro:hover {
    box-shadow: var(--shadow-md);
    border-color: #CBD5E1;
}
.icon-badge-pro {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.icon-badge-pro.blue {
    background: var(--light-blue);
    color: var(--prof-blue);
}
.icon-badge-pro.saffron {
    background: var(--amber-100);
    color: var(--accent-saffron);
}
.icon-badge-pro.purple {
    background: var(--lavender-100);
    color: var(--accent-purple);
}
.stat-card-text {
    flex: 1;
    min-width: 0;
}
.stat-card-value {
    font-size: 2.1rem;
    font-weight: 800;
    color: var(--primary-navy);
    line-height: 1.1;
}
.stat-card-label {
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--primary-navy);
    margin: 2px 0 2px;
}
.stat-card-desc {
    font-size: 0.8rem;
    color: var(--text-secondary);
    line-height: 1.35;
}

/* Section Cards */
.section-card {
    padding: 24px 28px;
    margin-bottom: 24px;
}
.section-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--border-soft);
    flex-wrap: wrap;
    gap: 12px;
}
.section-title-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
}
.section-heading {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--primary-navy);
}
.section-link {
    font-size: 0.88rem;
    font-weight: 600;
    color: var(--prof-blue);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: color 0.15s ease;
}
.section-link:hover {
    color: var(--deep-blue);
    text-decoration: underline;
}

/* News List */
.news-list-container {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.news-item-box {
    background: var(--light-blue);
    border: 1px solid rgba(23, 105, 170, 0.12);
    border-radius: var(--radius-md);
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}
.news-item-content {
    flex: 1;
    min-width: 240px;
}
.news-item-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--primary-navy);
    text-decoration: none;
    display: block;
    margin-bottom: 4px;
}
.news-item-title:hover {
    color: var(--prof-blue);
}
.news-item-desc {
    margin: 0;
    font-size: 0.86rem;
    color: var(--text-secondary);
}
.news-item-date {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.85rem;
    color: var(--text-secondary);
    white-space: nowrap;
}

/* Clean Plain Table */
.plain-clean td {
    padding: 14px 8px;
    border-bottom: 1px solid var(--border-color);
}
.plain-clean tr:last-child td {
    border-bottom: none;
}

/* Contact Action Card */
.contact-action-card {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
    padding: 24px 28px;
    margin-bottom: 20px;
}
.contact-action-left {
    display: flex;
    align-items: center;
    gap: 14px;
    flex: 1;
    min-width: 260px;
}
.contact-action-title {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--primary-navy);
}
.contact-action-desc {
    margin: 3px 0 0;
    font-size: 0.88rem;
    color: var(--text-secondary);
}
.contact-action-buttons {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

/* Responsive layout adjustments */
@media (max-width: 992px) {
    .gallery-item {
        flex: 0 0 calc((100% - 16px) / 2);
    }
    .stats-overview-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 600px) {
    .gallery-item {
        flex: 0 0 100%;
    }
    .gallery-card, .section-card, .contact-action-card {
        padding: 18px 16px;
    }
    .gallery-nav-btn.prev { left: 2px; }
    .gallery-nav-btn.next { right: 2px; }
    .contact-action-buttons {
        width: 100%;
    }
    .contact-action-buttons .btn {
        flex: 1;
        justify-content: center;
    }
}
</style>

<script>
(function() {
    var track = document.getElementById('galleryTrack');
    var prevBtn = document.getElementById('galleryPrevBtn');
    var nextBtn = document.getElementById('galleryNextBtn');
    var dotsContainer = document.getElementById('galleryDots');
    var dots = dotsContainer ? dotsContainer.querySelectorAll('.gallery-dot') : [];
    if (!track) return;

    function getItemWidth() {
        var firstItem = track.querySelector('.gallery-item');
        if (!firstItem) return 300;
        var style = window.getComputedStyle(track);
        var gap = parseFloat(style.gap) || 16;
        return firstItem.offsetWidth + gap;
    }

    function updateActiveDot() {
        if (!dots.length) return;
        var scrollLeft = track.scrollLeft;
        var maxScroll = track.scrollWidth - track.clientWidth;
        if (maxScroll <= 0) return;
        var ratio = scrollLeft / maxScroll;
        var activeIndex = Math.min(dots.length - 1, Math.round(ratio * (dots.length - 1)));
        dots.forEach(function(dot, idx) {
            dot.classList.toggle('active', idx === activeIndex);
        });
    }

    if (prevBtn) {
        prevBtn.addEventListener('click', function() {
            track.scrollBy({ left: -getItemWidth(), behavior: 'smooth' });
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            track.scrollBy({ left: getItemWidth(), behavior: 'smooth' });
        });
    }

    dots.forEach(function(dot, idx) {
        dot.addEventListener('click', function() {
            var maxScroll = track.scrollWidth - track.clientWidth;
            var target = (idx / (dots.length - 1)) * maxScroll;
            track.scrollTo({ left: target, behavior: 'smooth' });
        });
    });

    track.addEventListener('scroll', function() {
        window.requestAnimationFrame(updateActiveDot);
    }, { passive: true });

    // Gentle auto-scroll with pause on hover/focus
    var autoScrollInterval = null;
    function startAutoScroll() {
        stopAutoScroll();
        autoScrollInterval = setInterval(function() {
            var maxScroll = track.scrollWidth - track.clientWidth;
            if (track.scrollLeft >= maxScroll - 10) {
                track.scrollTo({ left: 0, behavior: 'smooth' });
            } else {
                track.scrollBy({ left: getItemWidth(), behavior: 'smooth' });
            }
        }, 5000);
    }
    function stopAutoScroll() {
        if (autoScrollInterval) {
            clearInterval(autoScrollInterval);
            autoScrollInterval = null;
        }
    }

    var carouselEl = document.getElementById('associationGalleryCarousel');
    if (carouselEl) {
        carouselEl.addEventListener('mouseenter', stopAutoScroll);
        carouselEl.addEventListener('mouseleave', startAutoScroll);
        carouselEl.addEventListener('focusin', stopAutoScroll);
        carouselEl.addEventListener('focusout', startAutoScroll);
        startAutoScroll();
    }
})();
</script>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>

