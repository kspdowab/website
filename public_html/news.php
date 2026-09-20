<?php
/**
 * KSPDOWA — Public News Listing (Phase 1)
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$news = Database::fetchAll(
    "SELECT id, title, content, featured_image, published_at FROM news
     WHERE status = 'published' AND published_at IS NOT NULL
     ORDER BY published_at DESC LIMIT 50"
);

$pageTitle = 'News';
require __DIR__ . '/includes/partials/header.php';
?>

<span class="eyebrow">Official Announcements</span>
<h1 class="page-title">News &amp; Circulars</h1>
<p class="page-subtitle">Latest official updates, administrative announcements and welfare communications from the Association.</p>

<?php if (!empty($news)): ?>
    <div style="display: flex; flex-direction: column; gap: 16px;">
    <?php foreach ($news as $item): ?>
        <article class="card" id="news-<?= (int)$item['id'] ?>" style="margin-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                <span class="icon-badge blue" style="width: 32px; height: 32px;" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </span>
                <span style="font-size: 0.82rem; font-weight: 600; color: var(--text-secondary);">
                    <?= Sanitize::html(date('d F Y', strtotime((string) $item['published_at']))) ?>
                </span>
            </div>
            <h2 style="border-bottom: none; padding-bottom: 0; margin: 0 0 10px; font-size: 1.2rem; color: var(--primary-navy); line-height: 1.35;">
                <?= Sanitize::html($item['title']) ?>
            </h2>
            <div style="margin: 0; color: var(--text-main); font-size: 0.94rem; line-height: 1.65;">
                <?= nl2br(Sanitize::html((string) $item['content'])) ?>
            </div>
            <?php if (!empty($item['featured_image'])): ?>
                <div style="margin-top: 14px; border-top: 1px solid #f1f5f9; padding-top: 12px;">
                    <?php if (preg_match('/\.(jpg|jpeg|png|webp)$/i', (string)$item['featured_image'])): ?>
                        <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px;">RDPR Paper Cutting / Media Attachment:</div>
                        <a href="/<?= ltrim(Sanitize::attr($item['featured_image']), '/') ?>" target="_blank" style="display:inline-block; max-width:100%;">
                            <img src="/<?= ltrim(Sanitize::attr($item['featured_image']), '/') ?>" alt="RDPR Paper Cutting" style="max-width: 100%; max-height: 420px; border-radius: 8px; border: 1px solid #e2e8f0; object-fit: contain; box-shadow: 0 2px 8px rgba(0,0,0,0.06);">
                        </a>
                    <?php else: ?>
                        <a href="/<?= ltrim(Sanitize::attr($item['featured_image']), '/') ?>" target="_blank" class="btn btn-outline btn-sm" style="display:inline-flex; align-items:center; gap:6px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            View Attached Document (PDF)
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="card">
        <p class="empty-state">No news or announcements have been published yet.</p>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
