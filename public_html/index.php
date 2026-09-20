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

// Fetch gallery photos configured for the home page carousel
$galleryItems = [];
try {
    $dbGalleryPhotos = Database::fetchAll(
        "SELECT id, title, file_path, photo_date, category, description 
         FROM gallery_photos 
         WHERE status = 'active' AND show_on_home = 1 
         ORDER BY sort_order ASC, photo_date DESC, id DESC 
         LIMIT 15"
    );
    if (!empty($dbGalleryPhotos)) {
        foreach ($dbGalleryPhotos as $photo) {
            $dateStr = !empty($photo['photo_date']) ? date('d M Y', strtotime($photo['photo_date'])) : '';
            $galleryItems[] = [
                'image' => $photo['file_path'],
                'title' => $photo['title'],
                'date'  => $dateStr,
                'alt'   => $photo['title']
            ];
        }
    }
} catch (Throwable $e) {
    // Graceful fallback to static list
}

// Fallback to verified association photos if table is empty or unmigrated
if (empty($galleryItems)) {
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
}

// Member login session state
$isMemberLoggedIn = false;
$loggedInMember = null;
if (Session::get('logged_in') && Session::get('member_id')) {
    $memberId = (int)Session::get('member_id');
    $loggedInMember = Database::fetchOne(
        "SELECT m.id, m.name, m.member_no, m.membership_status, d.name AS district_name, t.name AS taluk_name 
         FROM members m
         LEFT JOIN districts d ON m.district_id = d.id
         LEFT JOIN taluks t ON m.taluk_id = t.id
         WHERE m.id = ? LIMIT 1",
        [$memberId]
    );
    if ($loggedInMember) {
        $isMemberLoggedIn = true;
    }
}

// State President details
$statePresident = Database::fetchOne(
    "SELECT name, association_designation, photo_path FROM office_bearers 
     WHERE (association_designation LIKE '%ಅಧ್ಯಕ್ಷ%' OR association_designation LIKE '%President%')
       AND association_designation NOT LIKE '%ಕಾರ್ಯಾಧ್ಯಕ್ಷ%' 
       AND association_designation NOT LIKE '%ಉಪಾಧ್ಯಕ್ಷ%'
       AND association_designation NOT LIKE '%ಗೌರವಾಧ್ಯಕ್ಷ%'
       AND district_id IS NULL AND taluk_id IS NULL
     LIMIT 1"
);
if (!$statePresident) {
    $statePresident = [
        'name' => 'ಶ್ರೀ ದಿಲೀಪ್ ಕುಮಾರ ಬಿ.ಎಂ',
        'association_designation' => 'ರಾಜ್ಯಾಧ್ಯಕ್ಷರು',
        'photo_path' => 'assets/images/office-bearers/ob_2879451e8df71b1b2c4bddf84a4e1046.jpg'
    ];
}

// Upcoming events
$upcomingEvents = [];
try {
    $upcomingEvents = Database::fetchAll(
        "SELECT id, title, event_date, location, event_type
         FROM events
         WHERE status = 'published' AND event_date >= CURDATE()
         ORDER BY event_date ASC LIMIT 3"
    );
} catch (Throwable $e) {
    // Graceful fallback
}
if (empty($upcomingEvents)) {
    $upcomingEvents = [
        [
            'id' => 1,
            'title' => 'ರಾಜ್ಯ ಕಾರ್ಯಕಾರಿಣಿ ಸಮಾಲೋಚನೆ ಸಭೆ',
            'event_date' => date('d M Y', strtotime('+5 days')),
            'location' => 'ಕೇಂದ್ರ ಕಚೇರಿ, ಬೆಂಗಳೂರು',
            'type' => 'ಸಭೆ'
        ],
        [
            'id' => 2,
            'title' => 'ವಿಭಾಗೀಯ PDO ಸಮನ್ವಯ ಕಾರ್ಯಾಗಾರ',
            'event_date' => date('d M Y', strtotime('+18 days')),
            'location' => 'ಮೈಸೂರು ವಿಭಾಗ',
            'type' => 'ಕಾರ್ಯಾಗಾರ'
        ]
    ];
}

$sitePhone = Settings::get('site_phone', '+91 94808 55555');
$siteEmail = Settings::get('site_email', 'info@kspdowa.org');
$siteAddress = Settings::get('site_address', 'ಕರ್ನಾಟಕ ರಾಜ್ಯ ಪಂಚಾಯತ್ ಅಭಿವೃದ್ಧಿ ಅಧಿಕಾರಿಗಳ ಕ್ಷೇಮಾಭಿವೃದ್ಧಿ ಸಂಘ, ಬೆಂಗಳೂರು');

$pageTitle = 'Home';
$mainClass = 'wide-portal-main';
require __DIR__ . '/includes/partials/header.php';
?>

<div class="home-portal-grid">

    <!-- ═══════════════════════════════════════════════════════════════════
         LEFT SIDEBAR: President Desk, Member Services (Auth-Aware), Helpline
         ═══════════════════════════════════════════════════════════════════ -->
    <aside class="portal-sidebar portal-sidebar-left" aria-label="ಸದಸ್ಯರ ಸೇವೆಗಳು ಮತ್ತು ಮಾಹಿತಿ">

        <!-- 1. President's Desk Widget -->
        <div class="card sidebar-widget president-widget">
            <div class="sidebar-widget-header">
                <span class="icon-badge saffron" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </span>
                <h3 class="sidebar-widget-title">ಅಧ್ಯಕ್ಷರ ಸಂದೇಶ</h3>
            </div>
            <div class="president-profile">
                <div class="president-avatar-wrap">
                    <?php if (!empty($statePresident['photo_path'])): ?>
                        <img src="/<?= Sanitize::attr(ltrim($statePresident['photo_path'], '/')) ?>" alt="<?= Sanitize::attr($statePresident['name']) ?>" class="president-photo">
                    <?php else: ?>
                        <div class="president-avatar-fallback">PDO</div>
                    <?php endif; ?>
                </div>
                <div class="president-meta">
                    <h4 class="president-name"><?= Sanitize::html($statePresident['name']) ?></h4>
                    <span class="president-title"><?= Sanitize::html($statePresident['association_designation'] ?? 'ರಾಜ್ಯಾಧ್ಯಕ್ಷರು') ?></span>
                </div>
            </div>
            <blockquote class="president-quote">
                "ಗ್ರಾಮೀಣಾಭಿವೃದ್ಧಿಯ ಚಾಲನಾ ಶಕ್ತಿಯಾದ ಪಂಚಾಯತ್ ಅಭಿವೃದ್ಧಿ ಅಧಿಕಾರಿಗಳ ಹಿತರಕ್ಷಣೆ, ವೃತ್ತಿಪರ ಗೌರವ ಮತ್ತು ಸೇವಾ ಸೌಲಭ್ಯಗಳ ಸಬಲೀಕರಣವೇ ನಮ್ಮ ಸಂಘದ ಪ್ರಮುಖ ಧ್ಯೇಯ."
            </blockquote>
            <a href="/office-bearers.php" class="sidebar-widget-footer-link">
                <span>ಪೂರ್ಣ ಪದಾಧಿಕಾರಿಗಳ ಪಟ್ಟಿ</span>
                <span aria-hidden="true">&rarr;</span>
            </a>
        </div>

        <!-- 2. Member Services Widget (Auth-Aware) -->
        <div class="card sidebar-widget member-services-widget">
            <div class="sidebar-widget-header">
                <span class="icon-badge blue" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 7h10"/><path d="M7 12h10"/><path d="M7 17h10"/></svg>
                </span>
                <h3 class="sidebar-widget-title">ಸದಸ್ಯರ ಸೇವೆಗಳು</h3>
            </div>

            <?php if ($isMemberLoggedIn): ?>
                <!-- Logged In Member Access -->
                <div class="member-badge-active">
                    <div class="member-badge-avatar">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </div>
                    <div>
                        <div class="member-badge-name"><?= Sanitize::html($loggedInMember['name']) ?></div>
                        <div class="member-badge-sub">ಸದಸ್ಯತ್ವ ಸಂ: <strong><?= Sanitize::html($loggedInMember['member_no'] ?? 'ಸಕ್ರಿಯ') ?></strong></div>
                    </div>
                </div>

                <div class="widget-action-links">
                    <a href="/member/fee.php" class="widget-action-link primary-action">
                        <span class="action-icon">💳</span>
                        <div class="action-text">
                            <strong>ವಾರ್ಷಿಕ ಶುಲ್ಕ ನವೀಕರಣ</strong>
                            <small>Annual Fee Renewal</small>
                        </div>
                    </a>
                    <a href="/member/id-card.php" class="widget-action-link">
                        <span class="action-icon">🪪</span>
                        <div class="action-text">
                            <strong>ಡಿಜಿಟಲ್ ಗುರುತಿನ ಚೀಟಿ / ರಶೀದಿ</strong>
                            <small>Digital ID Card & Receipt</small>
                        </div>
                    </a>
                    <div class="grievance-quick-search-box">
                        <label for="quickGrvInput" class="grv-search-label">
                            <span class="action-icon">🔍</span>
                            <strong>ಕುಂದುಕೊರತೆ ಟ್ರ್ಯಾಕರ್</strong>
                        </label>
                        <form action="/member/grievances.php" method="get" class="grv-search-form">
                            <input type="text" id="quickGrvInput" name="search" placeholder="KSPDOWA-GRV-..." class="grv-search-input" required>
                            <button type="submit" class="grv-search-btn" title="Track">ಹುಡುಕಿ</button>
                        </form>
                    </div>
                    <a href="/member/orders-circulars.php" class="widget-action-link">
                        <span class="action-icon">📁</span>
                        <div class="action-text">
                            <strong>ಸದಸ್ಯರ ಸುತ್ತೋಲೆಗಳು</strong>
                            <small>Member Orders & Circulars</small>
                        </div>
                    </a>
                </div>
                <a href="/member/index.php" class="btn btn-secondary btn-sm btn-block" style="margin-top: 14px;">
                    <span>ಸದಸ್ಯರ ಡ್ಯಾಶ್‌ಬೋರ್ಡ್‌ಗೆ ಹೋಗಿ &rarr;</span>
                </a>
            <?php else: ?>
                <!-- Unauthenticated / Public Gate -->
                <div class="member-locked-banner">
                    <div class="locked-icon-badge">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </div>
                    <p class="locked-desc">
                        ವಾರ್ಷಿಕ ಶುಲ್ಕ ಪಾವತಿ, ಡಿಜಿಟಲ್ ಗುರುತಿನ ಚೀಟಿ ಹಾಗೂ ಕುಂದುಕೊರತೆ ಟ್ರ್ಯಾಕರ್ ಆಯ್ಕೆಗಳು <strong>ಸಂಘದ ಸದಸ್ಯರಿಗೆ ಮಾತ್ರ</strong> ಮೀಸಲಾಗಿವೆ.
                    </p>
                </div>
                <div class="sidebar-login-actions">
                    <a href="/member-login.php" class="btn btn-primary btn-block">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                        <span>ಸದಸ್ಯರ ಲಾಗಿನ್ (Member Login)</span>
                    </a>
                    <a href="/register.php" class="btn btn-secondary btn-block">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                        <span>ಹೊಸ ಸದಸ್ಯತ್ವ ನೋಂದಣಿ</span>
                    </a>
                </div>
                <div class="public-service-list">
                    <a href="/donate.php" class="public-service-item">
                        <span class="sub-bullet saffron"></span>
                        <span>ಕ್ಷೇಮಾಭಿವೃದ್ಧಿ ನಿಧಿಗೆ ದೇಣಿಗೆ</span>
                    </a>
                    <a href="/recognition.php" class="public-service-item">
                        <span class="sub-bullet blue"></span>
                        <span>ಸರ್ಕಾರಿ ಮಾನ್ಯತೆ & ಉಪನಿಯಮಗಳು</span>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- 3. Association Helpdesk Widget -->
        <div class="card sidebar-widget helpline-widget">
            <div class="sidebar-widget-header">
                <span class="icon-badge green" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                </span>
                <h3 class="sidebar-widget-title">ಸಹಾಯವಾಣಿ & ಸಂಪರ್ಕ</h3>
            </div>
            <div class="helpline-body">
                <div class="helpline-item">
                    <span class="helpline-label">ಕೇಂದ್ರ ಕಾರ್ಯಾಲಯ:</span>
                    <span class="helpline-val"><?= Sanitize::html($siteAddress) ?></span>
                </div>
                <div class="helpline-item">
                    <span class="helpline-label">ದೂರವಾಣಿ ಸಂಖ್ಯೆ:</span>
                    <a href="tel:<?= Sanitize::attr($sitePhone) ?>" class="helpline-link"><?= Sanitize::html($sitePhone) ?></a>
                </div>
                <div class="helpline-item">
                    <span class="helpline-label">ಇಮೇಲ್:</span>
                    <a href="mailto:<?= Sanitize::attr($siteEmail) ?>" class="helpline-link"><?= Sanitize::html($siteEmail) ?></a>
                </div>
                <div class="helpline-item">
                    <span class="helpline-label">ಕಾರ್ಯಾವಧಿ:</span>
                    <span class="helpline-val">ಸೋಮ - ಶನಿ: 10:00 AM – 5:00 PM</span>
                </div>
            </div>
            <a href="/contact.php" class="sidebar-widget-footer-link">
                <span>ಸಂಪರ್ಕ ಪುಟಕ್ಕೆ ಹೋಗಿ</span>
                <span aria-hidden="true">&rarr;</span>
            </a>
        </div>

    </aside>

    <!-- ═══════════════════════════════════════════════════════════════════
         CENTER COLUMN: Main Stream (Carousel, Stats, News, Bearers, CTA)
         ═══════════════════════════════════════════════════════════════════ -->
    <div class="portal-main-stream">

        <!-- ASSOCIATION GALLERY CAROUSEL -->
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
    <?php if (count($galleryItems) > 1): ?>
    <div class="gallery-dots" id="galleryDots" role="tablist" aria-label="Gallery pagination">
        <?php foreach ($galleryItems as $d => $g): ?>
            <button type="button" class="gallery-dot <?= $d === 0 ? 'active' : '' ?>" data-slide="<?= $d ?>" aria-label="Slide <?= $d + 1 ?>"></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
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

    </div> <!-- /.portal-main-stream -->

    <!-- ═══════════════════════════════════════════════════════════════════
         RIGHT SIDEBAR: Govt Portals, Events, Mobile App
         ═══════════════════════════════════════════════════════════════════ -->
    <aside class="portal-sidebar portal-sidebar-right" aria-label="ಸರ್ಕಾರಿ ಕೊಂಡಿಗಳು ಮತ್ತು ಆದೇಶಗಳು">

        <!-- 1. Useful Karnataka Govt Portals (Exact User List) -->
        <div class="card sidebar-widget govt-portals-widget">
            <div class="sidebar-widget-header">
                <span class="icon-badge blue" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                </span>
                <h3 class="sidebar-widget-title">ಉಪಯುಕ್ತ ಸರ್ಕಾರಿ ಕೊಂಡಿಗಳು</h3>
            </div>
            <div class="govt-portals-list">
                <!-- Portal 1: eSwathu -->
                <a href="https://eswathu.karnataka.gov.in" target="_blank" rel="noopener noreferrer" class="govt-portal-card" title="Open eSwathu in new tab">
                    <div class="portal-badge-icon">🌐</div>
                    <div class="portal-meta">
                        <strong class="portal-name">eSwathu (ಇ-ಸ್ವತ್ತು)</strong>
                        <span class="portal-desc">ಗ್ರಾಮೀಣ ಆಸ್ತಿಗಳ ನಮೂನೆ 9 & 11 ನೋಂದಣಿ</span>
                    </div>
                    <span class="portal-ext-arrow" aria-hidden="true">&nearr;</span>
                </a>

                <!-- Portal 2: Panchatantra 2.0 -->
                <a href="https://panchatantra.karnataka.gov.in" target="_blank" rel="noopener noreferrer" class="govt-portal-card" title="Open Panchatantra 2.0 in new tab">
                    <div class="portal-badge-icon">💻</div>
                    <div class="portal-meta">
                        <strong class="portal-name">Panchatantra 2.0 (ಪಂಚತಂತ್ರ ೨.೦)</strong>
                        <span class="portal-desc">ಗ್ರಾ.ಪಂ ಆಡಳಿತ & ಲೆಕ್ಕಪತ್ರ ತಂತ್ರಾಂಶ</span>
                    </div>
                    <span class="portal-ext-arrow" aria-hidden="true">&nearr;</span>
                </a>

                <!-- Portal 3: BSK -->
                <a href="https://bsk.karnataka.gov.in" target="_blank" rel="noopener noreferrer" class="govt-portal-card" title="Open BSK in new tab">
                    <div class="portal-badge-icon">🏢</div>
                    <div class="portal-meta">
                        <strong class="portal-name">BSK (ಬಾಪೂಜಿ ಸೇವಾ ಕೇಂದ್ರ)</strong>
                        <span class="portal-desc">ಗ್ರಾ.ಪಂ ನಾಗರಿಕ ಸೇವೆಗಳ ಪೋರ್ಟಲ್</span>
                    </div>
                    <span class="portal-ext-arrow" aria-hidden="true">&nearr;</span>
                </a>

                <!-- Portal 4: e-GramSwaraj -->
                <a href="https://egramswaraj.gov.in" target="_blank" rel="noopener noreferrer" class="govt-portal-card" title="Open e-GramSwaraj in new tab">
                    <div class="portal-badge-icon">🏛️</div>
                    <div class="portal-meta">
                        <strong class="portal-name">e-GramSwaraj (ಇ-ಗ್ರಾಮ್ ಸ್ವರಾಜ್)</strong>
                        <span class="portal-desc">ಪಂಚಾಯತ್ ಯೋಜನೆ & ಆಡಿಟ್ ಆನ್‌ಲೈನ್</span>
                    </div>
                    <span class="portal-ext-arrow" aria-hidden="true">&nearr;</span>
                </a>

                <!-- Portal 5: VB-G RAM G -->
                <a href="https://vbgramg.dord.gov.in/vbgramg/home.aspx" target="_blank" rel="noopener noreferrer" class="govt-portal-card" title="Open VB-G RAM G in new tab">
                    <div class="portal-badge-icon">📱</div>
                    <div class="portal-meta">
                        <strong class="portal-name">VB-G RAM G (ವಿ.ಬಿ ಗ್ರಾಮ್ ಜಿ)</strong>
                        <span class="portal-desc">ಗ್ರಾಮ ಪಂಚಾಯತ್ ಅಭಿವೃದ್ಧಿ ವೇದಿಕೆ</span>
                    </div>
                    <span class="portal-ext-arrow" aria-hidden="true">&nearr;</span>
                </a>
            </div>
        </div>

        <!-- 3. Upcoming Events & Calendar Widget -->
        <div class="card sidebar-widget events-widget">
            <div class="sidebar-widget-header">
                <span class="icon-badge saffron" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </span>
                <h3 class="sidebar-widget-title">ದಿನದರ್ಶಿ & ಸಭೆಗಳು</h3>
            </div>
            <div class="events-list-compact">
                <?php foreach ($upcomingEvents as $ev): ?>
                <div class="event-mini-item">
                    <div class="event-mini-badge">
                        <span class="event-mini-type"><?= Sanitize::html($ev['type'] ?? 'ಸಭೆ') ?></span>
                    </div>
                    <div class="event-mini-details">
                        <h4 class="event-mini-title"><?= Sanitize::html($ev['title']) ?></h4>
                        <div class="event-mini-meta">
                            <span>📅 <?= Sanitize::html($ev['event_date']) ?></span>
                            <?php if (!empty($ev['location'])): ?>
                            <span>📍 <?= Sanitize::html($ev['location']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <a href="/events.php" class="sidebar-widget-footer-link">
                <span>ಎಲ್ಲಾ ಕಾರ್ಯಕ್ರಮಗಳು</span>
                <span aria-hidden="true">&rarr;</span>
            </a>
        </div>

        <!-- 4. PWA Mobile App Card -->
        <div class="card sidebar-widget pwa-widget">
            <div class="pwa-widget-content">
                <span class="icon-badge green" style="width: 42px; height: 42px;" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                </span>
                <div>
                    <h4 class="pwa-title">KSPDOWA ಮೊಬೈಲ್ ಆ್ಯಪ್</h4>
                    <p class="pwa-desc">ಆಫ್‌ಲೈನ್ ಪ್ರವೇಶ & ತ್ವರಿತ ಅಪ್‌ಡೇಟ್‌ಗಳಿಗಾಗಿ ನಿಮ್ಮ ಮೊಬೈಲ್‌ನಲ್ಲಿ ಆ್ಯಪ್ ಇನ್‌ಸ್ಟಾಲ್ ಮಾಡಿ.</p>
                </div>
            </div>
            <a href="/login.php" class="btn btn-secondary btn-sm btn-block" style="margin-top: 10px;">
                <span>ಆ್ಯಪ್ ಸ್ಥಾಪಿಸಿ (Install PWA)</span>
            </a>
        </div>

    </aside>

</div> <!-- /.home-portal-grid -->

<!-- ═══════════════════════════════════════════════════════════════════
     HOMEPAGE INTERACTION STYLES & CAROUSEL SCRIPT
     ═══════════════════════════════════════════════════════════════════ -->
<style>
/* Page Background - dark blue per user request */
body {
    background-color: #103154 !important;
}
main.wide-portal-main {
    background-color: transparent !important;
}

/* Portal Grid Layout (3 Column Desktop) */
.home-portal-grid {
    display: grid;
    grid-template-columns: 290px minmax(0, 1fr) 290px;
    gap: 22px;
    align-items: start;
    width: 100%;
    margin-bottom: 24px;
}
.portal-sidebar {
    display: flex;
    flex-direction: column;
    gap: 20px;
}
.portal-main-stream {
    min-width: 0;
    display: flex;
    flex-direction: column;
}

/* Sidebar Widgets — Distinct Styling Harmonized with Header */
.portal-sidebar .sidebar-widget {
    padding: 20px 20px 18px;
    margin-bottom: 0;
    border-radius: var(--radius-lg);
    border: 1px solid #C5D8EA;
    box-shadow: 0 3px 12px rgba(23, 63, 103, 0.08);
    transition: box-shadow 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
    overflow: hidden;
}
.portal-sidebar-left .sidebar-widget {
    border-top: 4px solid #173F67; /* Primary Navy accent on left */
    background: linear-gradient(180deg, #FFFFFF 0%, #F1F6FB 100%);
}
.portal-sidebar-right .sidebar-widget {
    border-top: 4px solid #1769AA; /* Professional Blue accent on right */
    background: linear-gradient(180deg, #FFFFFF 0%, #F1F6FB 100%);
}
.portal-sidebar .sidebar-widget:hover {
    border-color: #1769AA;
    box-shadow: 0 6px 20px rgba(23, 63, 103, 0.14);
}
.portal-sidebar .sidebar-widget-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: -20px -20px 14px -20px;
    padding: 12px 18px;
    background: #EAF2F9;
    border-bottom: 1px solid #D2E2F0;
    border-top-left-radius: var(--radius-lg);
    border-top-right-radius: var(--radius-lg);
}
.portal-sidebar .sidebar-widget-title {
    margin: 0;
    font-size: 1.02rem;
    font-weight: 700;
    color: #173F67;
    line-height: 1.25;
}
.sidebar-widget-footer-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--prof-blue);
    text-decoration: none;
    margin-top: 12px;
    transition: color 0.15s ease;
}
.sidebar-widget-footer-link:hover {
    color: var(--deep-blue);
    text-decoration: underline;
}

/* President Widget */
.president-profile {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}
.president-avatar-wrap {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    overflow: hidden;
    flex-shrink: 0;
    background: var(--light-blue);
    border: 2px solid var(--prof-blue);
    box-shadow: 0 2px 8px rgba(23, 63, 103, 0.12);
}
.president-photo {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.president-avatar-fallback {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    color: var(--prof-blue);
    font-size: 1rem;
}
.president-meta {
    flex: 1;
    min-width: 0;
}
.president-name {
    margin: 0 0 2px;
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--primary-navy);
    line-height: 1.3;
}
.president-title {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--accent-saffron);
    display: block;
}
.president-quote {
    margin: 0 0 4px;
    font-size: 0.81rem;
    color: var(--text-secondary);
    line-height: 1.45;
    background: var(--light-blue);
    padding: 10px 12px;
    border-radius: var(--radius-sm);
    border-left: 3px solid var(--prof-blue);
    font-style: italic;
}

/* Member Services Widget */
.member-badge-active {
    background: #EBF7F0;
    border: 1px solid rgba(40, 122, 90, 0.25);
    padding: 10px 12px;
    border-radius: var(--radius-sm);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.member-badge-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--accent-green);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.member-badge-name {
    font-size: 0.88rem;
    font-weight: 700;
    color: var(--primary-navy);
}
.member-badge-sub {
    font-size: 0.76rem;
    color: var(--accent-green);
}
.widget-action-links {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.widget-action-link {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    border-radius: var(--radius-sm);
    background: var(--light-blue);
    border: 1px solid rgba(23, 105, 170, 0.1);
    text-decoration: none;
    color: var(--primary-navy);
    transition: all 0.15s ease;
}
.widget-action-link:hover {
    background: #E1EDF7;
    color: var(--prof-blue);
    transform: translateX(2px);
    border-color: var(--prof-blue);
}
.widget-action-link.primary-action {
    background: #F0FDF4;
    border-color: rgba(40, 122, 90, 0.2);
}
.widget-action-link.primary-action:hover {
    background: #DCFCE7;
    border-color: var(--accent-green);
}
.action-icon {
    font-size: 1.15rem;
    flex-shrink: 0;
}
.action-text strong {
    display: block;
    font-size: 0.85rem;
    font-weight: 700;
    line-height: 1.25;
}
.action-text small {
    display: block;
    font-size: 0.72rem;
    color: var(--text-secondary);
}

/* Grievance Quick Search Box */
.grievance-quick-search-box {
    background: var(--light-blue);
    border: 1px solid rgba(23, 105, 170, 0.15);
    border-radius: var(--radius-sm);
    padding: 10px 12px;
}
.grv-search-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.84rem;
    color: var(--primary-navy);
    margin-bottom: 6px;
}
.grv-search-form {
    display: flex;
    gap: 6px;
}
.grv-search-input {
    flex: 1;
    min-width: 0;
    padding: 6px 10px;
    font-size: 0.8rem;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    background: #ffffff;
    color: var(--text-main);
}
.grv-search-input:focus {
    outline: none;
    border-color: var(--prof-blue);
}
.grv-search-btn {
    background: var(--primary-navy);
    color: #ffffff;
    border: none;
    border-radius: var(--radius-sm);
    padding: 6px 10px;
    font-size: 0.78rem;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s ease;
    white-space: nowrap;
}
.grv-search-btn:hover {
    background: var(--prof-blue);
}

/* Member Locked Banner (Public Visitor) */
.member-locked-banner {
    background: #FFFBEB;
    border: 1px solid rgba(217, 154, 43, 0.25);
    border-radius: var(--radius-sm);
    padding: 12px;
    margin-bottom: 12px;
    display: flex;
    gap: 10px;
    align-items: flex-start;
}
.locked-icon-badge {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--amber-100);
    color: var(--accent-saffron);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.locked-desc {
    margin: 0;
    font-size: 0.8rem;
    color: #78350F;
    line-height: 1.45;
}
.sidebar-login-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 14px;
}
.btn-block {
    width: 100%;
    justify-content: center;
    box-sizing: border-box;
}
.public-service-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding-top: 10px;
    border-top: 1px solid var(--border-soft);
}
.public-service-item {
    font-size: 0.83rem;
    font-weight: 600;
    color: var(--primary-navy);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 4px;
    border-radius: 4px;
    transition: all 0.15s ease;
}
.public-service-item:hover {
    background: var(--light-blue);
    color: var(--prof-blue);
}

/* Helpline Widget */
.helpline-body {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.helpline-item {
    font-size: 0.81rem;
    line-height: 1.4;
    display: flex;
    flex-direction: column;
}
.helpline-label {
    font-weight: 700;
    color: var(--text-secondary);
    font-size: 0.74rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 1px;
}
.helpline-val {
    color: var(--primary-navy);
}
.helpline-link {
    color: var(--prof-blue);
    text-decoration: none;
    font-weight: 600;
}
.helpline-link:hover {
    text-decoration: underline;
}

/* Recognition Widget */
.recog-spotlight-content {
    background: var(--lavender-100);
    border: 1px solid rgba(102, 90, 168, 0.2);
    border-radius: var(--radius-sm);
    padding: 14px;
}
.recog-tag {
    display: inline-block;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--accent-purple);
    margin-bottom: 4px;
}
.recog-order-num {
    font-size: 0.88rem;
    font-weight: 800;
    color: var(--primary-navy);
    margin-bottom: 6px;
    line-height: 1.3;
}
.recog-desc {
    margin: 0 0 12px;
    font-size: 0.79rem;
    color: var(--text-main);
    line-height: 1.45;
}

/* Useful Karnataka Govt Portals Widget */
.govt-portals-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.govt-portal-card {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    text-decoration: none;
    transition: all 0.15s ease;
}
.govt-portal-card:hover {
    border-color: var(--prof-blue);
    background: var(--light-blue);
    transform: translateX(3px);
    box-shadow: 0 2px 6px rgba(23, 63, 103, 0.08);
}
.portal-badge-icon {
    font-size: 1.2rem;
    flex-shrink: 0;
}
.portal-meta {
    flex: 1;
    min-width: 0;
}
.portal-name {
    display: block;
    font-size: 0.84rem;
    font-weight: 700;
    color: var(--primary-navy);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.portal-desc {
    display: block;
    font-size: 0.71rem;
    color: var(--text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.portal-ext-arrow {
    font-size: 0.95rem;
    color: var(--prof-blue);
    font-weight: 700;
    flex-shrink: 0;
}

/* Events Widget */
.events-list-compact {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.event-mini-item {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    padding: 8px 10px;
    background: var(--light-blue);
    border-radius: var(--radius-sm);
    border: 1px solid rgba(23, 105, 170, 0.08);
}
.event-mini-badge {
    background: var(--amber-100);
    color: var(--accent-saffron);
    font-size: 0.7rem;
    font-weight: 700;
    padding: 3px 6px;
    border-radius: 4px;
    white-space: nowrap;
    margin-top: 2px;
}
.event-mini-details {
    flex: 1;
    min-width: 0;
}
.event-mini-title {
    margin: 0 0 3px;
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--primary-navy);
    line-height: 1.3;
}
.event-mini-meta {
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: 0.72rem;
    color: var(--text-secondary);
}

/* PWA Mobile App Card */
.pwa-widget-content {
    display: flex;
    align-items: center;
    gap: 12px;
}
.pwa-title {
    margin: 0 0 3px;
    font-size: 0.88rem;
    font-weight: 700;
    color: var(--primary-navy);
}
.pwa-desc {
    margin: 0;
    font-size: 0.75rem;
    color: var(--text-secondary);
    line-height: 1.35;
}

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
    flex: 0 0 calc((100% - 18px) / 2);
    display: flex;
    flex-direction: column;
    border-radius: var(--radius-md);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.gallery-image-wrap {
    width: 100%;
    height: 270px;
    overflow: hidden;
    border-radius: var(--radius-md);
    background: var(--light-blue);
    box-shadow: 0 4px 12px rgba(23, 63, 103, 0.12);
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
    padding: 12px 4px 4px;
}
.gallery-item-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--prof-blue);
    margin: 0 0 4px;
    line-height: 1.35;
    font-family: var(--font-sans);
}
.gallery-item-date {
    font-size: 0.84rem;
    color: var(--text-secondary);
    display: block;
}

/* Nav Buttons */
.gallery-nav-btn {
    position: absolute;
    top: 42%;
    transform: translateY(-50%);
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: var(--primary-navy);
    color: #ffffff;
    border: 2px solid #ffffff;
    box-shadow: 0 3px 10px rgba(23, 63, 103, 0.25);
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
@media (max-width: 1280px) {
    .home-portal-grid {
        grid-template-columns: 260px minmax(0, 1fr) 260px;
        gap: 16px;
    }
}
@media (max-width: 1040px) {
    .home-portal-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    .portal-main-stream {
        order: 1;
    }
    .portal-sidebar-left {
        order: 2;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 16px;
    }
    .portal-sidebar-right {
        order: 3;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 16px;
    }
}
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
    .gallery-image-wrap {
        height: 220px;
    }
    .gallery-card, .section-card, .contact-action-card, .sidebar-widget {
        padding: 16px 14px;
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
    .portal-sidebar-left,
    .portal-sidebar-right {
        grid-template-columns: 1fr;
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

