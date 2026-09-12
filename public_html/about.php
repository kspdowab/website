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
    <p style="margin-bottom:0;">
        The Association is a government-recognized service association under the Karnataka Civil
        Services (Recognition of Service Associations) Rules, 2015 — see the
        <a href="/recognition.php">Recognition page</a> for the official order.
    </p>
</div>

<div class="card">
    <h2>Association Structure</h2>
    <p style="color:var(--ink-500);">The Association is organized in a four-level hierarchy:</p>
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-top:8px;">
        <div style="background:var(--cream); border:1px solid var(--border-soft); border-radius:var(--radius-md); padding:16px;">
            <span class="badge badge-green">State</span>
            <p style="margin:10px 0 0; font-size:0.9rem; color:var(--ink-700);">Statewide committee and office bearers</p>
        </div>
        <div style="background:var(--cream); border:1px solid var(--border-soft); border-radius:var(--radius-md); padding:16px;">
            <span class="badge badge-lav">District</span>
            <p style="margin:10px 0 0; font-size:0.9rem; color:var(--ink-700);">District-level committee and office bearers</p>
        </div>
        <div style="background:var(--cream); border:1px solid var(--border-soft); border-radius:var(--radius-md); padding:16px;">
            <span class="badge badge-teal">Taluk</span>
            <p style="margin:10px 0 0; font-size:0.9rem; color:var(--ink-700);">Taluk-level committee and office bearers</p>
        </div>
        <div style="background:var(--cream); border:1px solid var(--border-soft); border-radius:var(--radius-md); padding:16px;">
            <span class="badge badge-blue">Member</span>
            <p style="margin:10px 0 0; font-size:0.9rem; color:var(--ink-700);">Individual Panchayat Development Officers</p>
        </div>
    </div>
    <p style="margin-top:20px; margin-bottom:0;"><a href="/office-bearers.php">View current office bearers &rarr;</a></p>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
