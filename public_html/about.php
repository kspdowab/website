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

<span class="eyebrow">Official Profile</span>
<h1 class="page-title">About <?= Sanitize::html(Settings::get('site_short_name', APP_SHORT_NAME)) ?></h1>
<p class="page-subtitle"><?= Sanitize::html(Settings::get('site_name', APP_FULL_NAME)) ?></p>

<div class="card">
    <p style="font-size:1.02rem; line-height:1.7; color:var(--text-main); margin-top:0;">
        The <strong><?= Sanitize::html(Settings::get('site_name', APP_FULL_NAME)) ?></strong> represents Panchayat
        Development Officers (PDOs) working across Gram Panchayats in Karnataka, within the Rural
        Development and Panchayat Raj (RDPR) administration.
    </p>
    <p style="margin-bottom:0; font-size:0.96rem; color:var(--text-secondary); line-height:1.65;">
        The Association is a government-recognized service association under the Karnataka Civil
        Services (Recognition of Service Associations) Rules, 2015 — see the
        <a href="/recognition.php" style="font-weight:600;">Recognition page</a> for the official order.
    </p>
</div>

<div class="card">
    <h2 style="display:flex; align-items:center; gap:10px;">
        <span class="icon-badge blue" style="width:36px; height:36px;" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </span>
        <span>Association Structure</span>
    </h2>
    <p style="color:var(--text-secondary); margin-top:4px;">The Association is organized in a democratic leadership hierarchy representing members across Karnataka:</p>
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:18px; margin-top:16px;">
        <div style="background:var(--light-blue); border:1px solid rgba(23, 105, 170, 0.15); border-radius:var(--radius-md); padding:18px; border-top:3px solid var(--prof-blue);">
            <span class="badge badge-blue">State Council</span>
            <h3 style="margin:10px 0 4px; font-size:0.98rem; color:var(--primary-navy);">State Council (ರಾಜ್ಯ ಪರಿಷತ್ತು)</h3>
            <p style="margin:0; font-size:0.86rem; color:var(--text-secondary);">Statewide council including district-elected presidents, council members and treasurers</p>
        </div>
        <div style="background:#ffffff; border:1px solid var(--border-color); border-radius:var(--radius-md); padding:18px; border-top:3px solid var(--primary-navy); box-shadow:var(--shadow-sm);">
            <span class="badge badge-navy">State Committee</span>
            <h3 style="margin:10px 0 4px; font-size:0.98rem; color:var(--primary-navy);">State Committee (ರಾಜ್ಯ ಸಂಘ)</h3>
            <p style="margin:0; font-size:0.86rem; color:var(--text-secondary);">Central executive leadership guiding statewide policy and member welfare</p>
        </div>
        <div style="background:#ffffff; border:1px solid var(--border-color); border-radius:var(--radius-md); padding:18px; border-top:3px solid var(--brand-red); box-shadow:var(--shadow-sm);">
            <span class="badge badge-red">District Committee</span>
            <h3 style="margin:10px 0 4px; font-size:0.98rem; color:var(--primary-navy);">District Committee (ಜಿಲ್ಲಾ ಸಂಘ)</h3>
            <p style="margin:0; font-size:0.86rem; color:var(--text-secondary);">District-level committees coordinating across all 31 revenue districts</p>
        </div>
        <div style="background:#ffffff; border:1px solid var(--border-color); border-radius:var(--radius-md); padding:18px; border-top:3px solid var(--accent-green); box-shadow:var(--shadow-sm);">
            <span class="badge badge-green">Taluk Committee</span>
            <h3 style="margin:10px 0 4px; font-size:0.98rem; color:var(--primary-navy);">Taluk Committee (ತಾಲ್ಲೂಕು ಸಂಘ)</h3>
            <p style="margin:0; font-size:0.86rem; color:var(--text-secondary);">Grassroots taluk-level office bearers supporting local PDO members</p>
        </div>
    </div>
    <p style="margin-top:24px; margin-bottom:0;">
        <a href="/office-bearers.php" class="btn btn-secondary btn-sm">
            <span>View Current Office Bearers</span>
            <span aria-hidden="true">&rarr;</span>
        </a>
    </p>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
