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

<h1 class="page-title">Contact Us</h1>
<p class="page-subtitle">Reach the Association through the details below.</p>

<div class="card" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:20px;">
    <div>
        <span class="badge badge-blue">Address</span>
        <p style="margin:10px 0 0; color:var(--ink-700);">
            <strong style="display:block; color:var(--ink-900); margin-bottom:4px;"><?= Sanitize::html($siteName) ?></strong>
            <?= $address !== '' ? Sanitize::html($address) : '<span class="empty-state">Not yet published</span>' ?>
        </p>
    </div>
    <div>
        <span class="badge badge-teal">Email</span>
        <p style="margin:10px 0 0; color:var(--ink-700);">
            <?= $email !== '' ? '<a href="mailto:' . Sanitize::attr($email) . '">' . Sanitize::html($email) . '</a>' : '<span class="empty-state">Not yet published</span>' ?>
        </p>
    </div>
    <div>
        <span class="badge badge-lav">Phone</span>
        <p style="margin:10px 0 0; color:var(--ink-700);">
            <?= $phone !== '' ? Sanitize::html($phone) : '<span class="empty-state">Not yet published</span>' ?>
        </p>
    </div>
    <div>
        <span class="badge badge-green">Website</span>
        <p style="margin:10px 0 0; color:var(--ink-700);">
            <?= $website !== '' ? '<a href="' . Sanitize::attr($website) . '" target="_blank" rel="noopener">' . Sanitize::html($websiteDisplay) . '</a>' : '<span class="empty-state">Not yet published</span>' ?>
        </p>
    </div>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
