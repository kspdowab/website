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

$email   = Settings::get('site_email', '');
$phone   = Settings::get('site_phone', '');
$address = Settings::get('site_address', '');
?>

<h1 class="page-title">Contact Us</h1>

<div class="card">
    <table class="plain">
        <tr>
            <td style="width:140px; font-weight:600;">Address</td>
            <td><?= $address !== '' ? Sanitize::html($address) : '<span class="empty-state">Not yet published</span>' ?></td>
        </tr>
        <tr>
            <td style="font-weight:600;">Email</td>
            <td><?= $email !== '' ? '<a href="mailto:' . Sanitize::attr($email) . '">' . Sanitize::html($email) . '</a>' : '<span class="empty-state">Not yet published</span>' ?></td>
        </tr>
        <tr>
            <td style="font-weight:600;">Phone</td>
            <td><?= $phone !== '' ? Sanitize::html($phone) : '<span class="empty-state">Not yet published</span>' ?></td>
        </tr>
    </table>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
