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

<h1 class="page-title">Government Recognition</h1>

<div class="card">
    <table class="plain">
        <tr><td style="width:200px; font-weight:600;">Order Number</td><td>RDPR 184 GPS 2020</td></tr>
        <tr><td style="font-weight:600;">Order Date</td><td>30-12-2020</td></tr>
        <tr><td style="font-weight:600;">Issuing Authority</td>
            <td>Government of Karnataka, Rural Development and Panchayat Raj Department
                (signed by B. Naveen Kumar, Under Secretary to Government (ZP), Addl. charge)</td></tr>
        <tr><td style="font-weight:600;">Subject</td>
            <td>Granting recognition to the Karnataka State Panchayat Development Officers'
                Welfare Association (Regd.)</td></tr>
        <tr><td style="font-weight:600;">Rule / Reference</td>
            <td>Karnataka Civil Services (Recognition of Service Associations) Rules, 2015,
                referencing Government Notification No. SiKaSu 6 ESBM 2013 dated 04-01-2016</td></tr>
    </table>

    <p style="margin-top:18px; padding:12px 14px; background:#fdf6e8; border:1px solid #f0dfa8; border-radius:6px; font-size:0.88rem;">
        Per the order text itself, recognition was granted <strong>for a period of 2 years from the date
        of this order, subject to conditions and subsequent renewal</strong>. This page reflects only
        what the order document states.
    </p>

    <?php if ($doc): ?>
        <p style="margin-top:18px;">
            <a class="btn" href="/document.php?id=<?= (int) $doc['id'] ?>" target="_blank" rel="noopener">
                View / Download Original Order (PDF) &rarr;
            </a>
        </p>
    <?php else: ?>
        <p class="empty-state">The original scanned order will be available for download shortly.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
