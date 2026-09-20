<?php
/**
 * KSPDOWA — Recognition Page (Phase 1)
 * ============================================================
 * Per docs/05_OFFICIAL_DOCUMENTS.md: display exact order metadata
 * transcribed from the scanned order, plus view/download of the
 * original PDF. Do not overstate what the order itself says (it
 * grants recognition for 2 years subject to renewal -- it does not
 * say "permanent" or "only recognized association").
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$doc = Database::fetchOne(
    "SELECT * FROM documents WHERE access_level = 'public' AND status = 'active'
     AND file_path LIKE 'documents/kspdowa-recognition-order%' LIMIT 1"
);

$pageTitle = 'Recognition';
require __DIR__ . '/includes/partials/header.php';
?>

<span class="eyebrow" style="color:var(--accent-saffron); background:var(--amber-100); border-color:rgba(217,154,43,0.25);">Official Status</span>
<h1 class="page-title">Government Recognition</h1>
<p class="page-subtitle">Official service association recognition order under Karnataka Civil Services Rules.</p>

<div class="card" style="border-top: 3px solid var(--accent-saffron);">
    <div style="display:flex; align-items:center; gap:12px; margin-bottom:18px;">
        <span class="icon-badge saffron" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>
        </span>
        <h2 style="margin:0; border-bottom:none; padding-bottom:0;">Recognition Order Details</h2>
    </div>

    <div class="table-wrap">
    <table class="plain">
        <tr><td style="width:200px; font-weight:700; color:var(--primary-navy);">Order Number</td><td>RDPR 184 GPS 2020</td></tr>
        <tr><td style="font-weight:700; color:var(--primary-navy);">Order Date</td><td>30-12-2020</td></tr>
        <tr><td style="font-weight:700; color:var(--primary-navy);">Issuing Authority</td>
            <td>Government of Karnataka, Rural Development and Panchayat Raj Department
                (signed by B. Naveen Kumar, Under Secretary to Government (ZP), Addl. charge)</td></tr>
        <tr><td style="font-weight:700; color:var(--primary-navy);">Subject</td>
            <td>Granting recognition to the Karnataka State Panchayat Development Officers'
                Welfare Association (Regd.)</td></tr>
        <tr><td style="font-weight:700; color:var(--primary-navy);">Rule / Reference</td>
            <td>Karnataka Civil Services (Recognition of Service Associations) Rules, 2015,
                referencing Government Notification No. SiKaSu 6 ESBM 2013 dated 04-01-2016</td></tr>
    </table>
    </div>


    <?php if ($doc): ?>
        <p style="margin-top:22px; margin-bottom:0;">
            <a class="btn" href="/document.php?id=<?= (int) $doc['id'] ?>" target="_blank" rel="noopener">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="12" y2="18"/><line x1="15" y1="15" x2="12" y2="18"/></svg>
                <span>View / Download Original Order (PDF)</span>
            </a>
        </p>
    <?php else: ?>
        <p class="empty-state">The original scanned order will be available for download shortly.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
