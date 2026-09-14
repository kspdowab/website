<?php
/**
 * KSPDOWA — Contact Page (Phase 1)
 * ============================================================
 * Info display only. No contact form -- the spec (docs/04) lists
 * "Public contact" as information to display, not a submission
 * form/backend module; adding one would be inventing a feature
 * outside approved scope.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Contact';
require __DIR__ . '/includes/partials/header.php';

$email    = Settings::get('site_email', '');
$phone    = Settings::get('site_phone', '');
$address  = Settings::get('site_address', '');
$website  = Settings::get('site_website', '');
$siteName = Settings::get('site_name', APP_FULL_NAME);

// Website link display text -- strip the scheme so it reads as a
// plain domain (https://kspdowa.org -> kspdowa.org), same convention
// used elsewhere for admin-entered URLs.
$websiteDisplay = preg_replace('#^https?://#i', '', $website);
?>

<span class="eyebrow">Get in Touch</span>
<h1 class="page-title">Contact Us</h1>
<p class="page-subtitle">Official communication channels and headquarters address for Karnataka State Panchayat Development Officer Welfare Association.</p>

<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:20px; margin-bottom:24px;">
    <!-- Address Card -->
    <div class="card" style="margin-bottom:0; display:flex; flex-direction:column;">
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:14px;">
            <span class="icon-badge blue" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
            </span>
            <div>
                <span class="badge badge-blue">Registered Office</span>
            </div>
        </div>
        <div style="color:var(--text-main); font-size:0.92rem; line-height:1.6; flex:1;">
            <strong style="display:block; color:var(--primary-navy); margin-bottom:6px; font-size:0.96rem;"><?= Sanitize::html($siteName) ?></strong>
            <?= $address !== '' ? Sanitize::html($address) : '<span class="empty-state">Not yet published</span>' ?>
        </div>
    </div>

    <!-- Email Card -->
    <div class="card" style="margin-bottom:0; display:flex; flex-direction:column;">
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:14px;">
            <span class="icon-badge blue" style="background:var(--teal-100); color:var(--accent-teal);" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
            </span>
            <div>
                <span class="badge badge-teal">Official Email</span>
            </div>
        </div>
        <div style="color:var(--text-main); font-size:0.92rem; line-height:1.6; flex:1;">
            <?php if ($email !== ''): ?>
                <a href="mailto:<?= Sanitize::attr($email) ?>" style="font-weight:600; font-size:1.02rem; text-decoration:none;">
                    <?= Sanitize::html($email) ?>
                </a>
                <p style="margin:6px 0 0; color:var(--text-secondary); font-size:0.84rem;">For official queries, representation letters, and administrative submissions.</p>
            <?php else: ?>
                <span class="empty-state">Not yet published</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Phone Card -->
    <div class="card" style="margin-bottom:0; display:flex; flex-direction:column;">
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:14px;">
            <span class="icon-badge blue" style="background:var(--lavender-100); color:var(--accent-purple);" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            </span>
            <div>
                <span class="badge badge-lav">Helpline &amp; Support</span>
            </div>
        </div>
        <div style="color:var(--text-main); font-size:0.92rem; line-height:1.6; flex:1;">
            <?php if ($phone !== ''): ?>
                <a href="tel:<?= Sanitize::attr($phone) ?>" style="font-weight:700; font-size:1.15rem; color:var(--primary-navy); text-decoration:none;">
                    <?= Sanitize::html($phone) ?>
                </a>
                <p style="margin:6px 0 0; color:var(--text-secondary); font-size:0.84rem;">Available during normal office hours on working days.</p>
            <?php else: ?>
                <span class="empty-state">Not yet published</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Website Card -->
    <div class="card" style="margin-bottom:0; display:flex; flex-direction:column;">
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:14px;">
            <span class="icon-badge blue" style="background:var(--green-100); color:var(--accent-green);" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
            </span>
            <div>
                <span class="badge badge-green">Portal Website</span>
            </div>
        </div>
        <div style="color:var(--text-main); font-size:0.92rem; line-height:1.6; flex:1;">
            <?php if ($website !== ''): ?>
                <a href="<?= Sanitize::attr($website) ?>" target="_blank" rel="noopener" style="font-weight:600; font-size:1.02rem; text-decoration:none;">
                    <?= Sanitize::html($websiteDisplay) ?> &rarr;
                </a>
                <p style="margin:6px 0 0; color:var(--text-secondary); font-size:0.84rem;">Official web portal for member services, receipts and news.</p>
            <?php else: ?>
                <span class="empty-state">Not yet published</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div>
        <h2 style="border-bottom:none; padding-bottom:0; margin:0 0 4px; font-size:1.15rem;">Member / Officer Portals</h2>
        <p style="margin:0; color:var(--text-secondary); font-size:0.88rem;">Registered PDOs and elected office bearers can access protected services.</p>
    </div>
    <div style="display:flex; gap:12px; flex-wrap:wrap;">
        <a href="/office-bearers.php" class="btn btn-secondary btn-sm">Office Bearers Directory</a>
        <a href="/login.php" class="btn btn-sm">Sign In to Portal</a>
    </div>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
