<?php
/**
 * KSPDOWA — Shared Public Page Footer
 * ============================================================
 * Include at the end of every public page (after main content;
 * this file itself closes </main>). Styled from the same design
 * tokens defined in partials/header.php.
 * ============================================================
 */

declare(strict_types=1);

$footerAddress = Settings::get('site_address', '');
$footerEmail   = Settings::get('site_email', '');
$footerPhone   = Settings::get('site_phone', '');
$footerName    = Settings::get('site_name', APP_FULL_NAME);
$footerShort   = Settings::get('site_short_name', APP_SHORT_NAME);
?>
</main>
<footer style="background: var(--surface-alt); border-top: 1px solid var(--border); margin-top: var(--space-7);">
    <div style="max-width: var(--content-width); margin: 0 auto; padding: var(--space-7) 24px var(--space-6);
                display: flex; flex-wrap: wrap; gap: var(--space-6); justify-content: space-between;">

        <div style="flex: 2; min-width: 220px;">
            <p style="margin: 0 0 6px; font-weight: 800; color: var(--ink-900); font-size: 1.02rem;">
                <?= Sanitize::html($footerShort) ?>
            </p>
            <p style="margin: 0 0 12px; color: var(--ink-500); font-size: 0.85rem; max-width: 380px;">
                <?= Sanitize::html($footerName) ?>
            </p>
            <p style="margin: 0; font-size: 0.85rem; color: var(--ink-700); line-height: 1.8;">
                <?php if ($footerAddress !== ''): ?><?= Sanitize::html($footerAddress) ?><br><?php endif; ?>
                <?php if ($footerEmail !== ''): ?><a href="mailto:<?= Sanitize::attr($footerEmail) ?>" style="color: var(--blue-700);"><?= Sanitize::html($footerEmail) ?></a><?php endif; ?>
                <?php if ($footerPhone !== ''): ?> &nbsp;&middot;&nbsp; <?= Sanitize::html($footerPhone) ?><?php endif; ?>
            </p>
        </div>

        <div style="flex: 1; min-width: 160px;">
            <p style="margin: 0 0 10px; font-weight: 700; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--teal-700);">
                Quick Links
            </p>
            <nav style="display: flex; flex-direction: column; gap: 8px;">
                <a href="/" style="color: var(--ink-700); text-decoration: none; font-size: 0.88rem;">Home</a>
                <a href="/about.php" style="color: var(--ink-700); text-decoration: none; font-size: 0.88rem;">About</a>
                <a href="/office-bearers.php" style="color: var(--ink-700); text-decoration: none; font-size: 0.88rem;">Office Bearers</a>
                <a href="/recognition.php" style="color: var(--ink-700); text-decoration: none; font-size: 0.88rem;">Recognition</a>
                <a href="/news.php" style="color: var(--ink-700); text-decoration: none; font-size: 0.88rem;">News</a>
                <a href="/contact.php" style="color: var(--ink-700); text-decoration: none; font-size: 0.88rem;">Contact</a>
            </nav>
        </div>
    </div>

    <div style="border-top: 1px solid var(--border); padding: 16px 24px; text-align: center;">
        <p style="margin: 0; font-size: 0.78rem; color: var(--ink-500);">
            &copy; <?= date('Y') ?> <?= Sanitize::html($footerShort) ?>. Platform under active development.
        </p>
    </div>
</footer>
</body>
</html>
