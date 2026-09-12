<?php
/**
 * KSPDOWA — About Page (Phase 1)
 * ============================================================
 * Content here is limited to facts already established in the
 * project's own official documents (Master Specification, the
 * Government Recognition Order) -- nothing about the association's
 * history or achievements is stated unless a source document backs
 * it, per docs/09/10's "do not invent missing association rules."
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'About';
require __DIR__ . '/includes/partials/header.php';
?>

<h1 class="page-title">About <?= Sanitize::html(Settings::get('site_short_name', APP_SHORT_NAME)) ?></h1>

<div class="card">
    <p>
        The <?= Sanitize::html(Settings::get('site_name', APP_FULL_NAME)) ?> represents Panchayat
        Development Officers (PDOs) working across Gram Panchayats in Karnataka, within the Rural
        Development and Panchayat Raj (RDPR) administration.
    </p>
    <p>
        The Association is a government-recognized service association under the Karnataka Civil
        Services (Recognition of Service Associations) Rules, 2015 — see the
        <a href="/recognition.php">Recognition page</a> for the official order.
    </p>
</div>

<div class="card">
    <h2 style="margin-top:0; color:#1a3a6b; font-size:1.1rem;">Association Structure</h2>
    <p>The Association is organized in a four-level hierarchy:</p>
    <table class="plain">
        <tr><td style="width:120px; font-weight:600;">Member</td><td>Individual Panchayat Development Officers</td></tr>
        <tr><td style="font-weight:600;">Taluk</td><td>Taluk-level committee and office bearers</td></tr>
        <tr><td style="font-weight:600;">District</td><td>District-level committee and office bearers</td></tr>
        <tr><td style="font-weight:600;">State</td><td>Statewide committee and office bearers</td></tr>
    </table>
    <p style="margin-top:14px;"><a href="/office-bearers.php">View current office bearers &rarr;</a></p>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
