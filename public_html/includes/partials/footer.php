<?php
/**
 * KSPDOWA — Shared Public Page Footer
 * ============================================================
 * Include at the end of every public page (after </main> content,
 * this file itself closes </main>).
 * ============================================================
 */

declare(strict_types=1);

$footerAddress = Settings::get('site_address', '');
$footerEmail   = Settings::get('site_email', '');
$footerPhone   = Settings::get('site_phone', '');
$footerName    = Settings::get('site_name', APP_FULL_NAME);
?>
</main>
<footer style="background:#fff; border-top:1px solid #e2e6ec; padding:28px 20px; margin-top:40px;">
    <div style="max-width:1080px; margin:0 auto; font-size:0.85rem; color:#5a6472;">
        <p style="margin:0 0 6px; font-weight:600; color:#1a3a6b;"><?= Sanitize::html($footerName) ?></p>
        <p style="margin:0 0 4px;">
            <?php if ($footerAddress !== ''): ?><?= Sanitize::html($footerAddress) ?><?php endif; ?>
            <?php if ($footerEmail !== ''): ?> · <a href="mailto:<?= Sanitize::attr($footerEmail) ?>"><?= Sanitize::html($footerEmail) ?></a><?php endif; ?>
            <?php if ($footerPhone !== ''): ?> · <?= Sanitize::html($footerPhone) ?><?php endif; ?>
        </p>
        <p style="margin:12px 0 0; font-size:0.75rem; color:#9aa4b2;">
            &copy; <?= date('Y') ?> <?= Sanitize::html($footerName) ?>. Platform under active development.
        </p>
    </div>
</footer>
</body>
</html>
