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
<footer style="background: var(--primary-navy); border-top: 1px solid var(--deep-blue); margin-top: var(--space-7); color: #ffffff;">
    <div style="max-width: var(--content-width); margin: 0 auto; padding: 48px 24px 36px;
                display: grid; grid-template-columns: 2fr 1fr 1.5fr; gap: 40px;">

        <!-- Col 1: Association Info & Contact -->
        <div>
            <p style="margin: 0 0 6px; font-weight: 800; color: #ffffff; font-size: 1.05rem; letter-spacing: 0.03em;">
                <?= Sanitize::html($footerShort) ?>
            </p>
            <p style="margin: 0 0 12px; color: #CBD5E1; font-size: 0.82rem; line-height: 1.45; max-width: 400px; text-transform: uppercase;">
                <?= Sanitize::html($footerName) ?>
            </p>
            <?php if ($footerAddress !== ''): ?>
            <p style="margin: 0 0 16px; font-size: 0.82rem; color: #94A3B8; line-height: 1.5; max-width: 380px;">
                <?= Sanitize::html($footerAddress) ?>
            </p>
            <?php endif; ?>

            <div style="display: flex; align-items: center; gap: 14px; font-size: 0.84rem; color: #E2E8F0; flex-wrap: wrap;">
                <?php if ($footerEmail !== ''): ?>
                <a href="mailto:<?= Sanitize::attr($footerEmail) ?>" style="color: #E2E8F0; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                    <span><?= Sanitize::html($footerEmail) ?></span>
                </a>
                <?php endif; ?>
                <?php if ($footerEmail !== '' && $footerPhone !== ''): ?>
                <span style="color: #475569;">|</span>
                <?php endif; ?>
                <?php if ($footerPhone !== ''): ?>
                <a href="tel:<?= Sanitize::attr($footerPhone) ?>" style="color: #E2E8F0; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    <span><?= Sanitize::html($footerPhone) ?></span>
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Col 2: Quick Links -->
        <div>
            <p style="margin: 0 0 14px; font-weight: 700; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em; color: #94A3B8;">
                Quick Links
            </p>
            <nav style="display: flex; flex-direction: column; gap: 9px;">
                <a href="/" style="color: #CBD5E1; text-decoration: none; font-size: 0.88rem; transition: color 0.15s ease;" onmouseover="this.style.color='#ffffff'" onmouseout="this.style.color='#CBD5E1'">Home</a>
                <a href="/about.php" style="color: #CBD5E1; text-decoration: none; font-size: 0.88rem; transition: color 0.15s ease;" onmouseover="this.style.color='#ffffff'" onmouseout="this.style.color='#CBD5E1'">About</a>
                <a href="/office-bearers.php" style="color: #CBD5E1; text-decoration: none; font-size: 0.88rem; transition: color 0.15s ease;" onmouseover="this.style.color='#ffffff'" onmouseout="this.style.color='#CBD5E1'">Office Bearers</a>
                <a href="/recognition.php" style="color: #CBD5E1; text-decoration: none; font-size: 0.88rem; transition: color 0.15s ease;" onmouseover="this.style.color='#ffffff'" onmouseout="this.style.color='#CBD5E1'">Recognition</a>
                <a href="/news.php" style="color: #CBD5E1; text-decoration: none; font-size: 0.88rem; transition: color 0.15s ease;" onmouseover="this.style.color='#ffffff'" onmouseout="this.style.color='#CBD5E1'">News</a>
                <a href="/contact.php" style="color: #CBD5E1; text-decoration: none; font-size: 0.88rem; transition: color 0.15s ease;" onmouseover="this.style.color='#ffffff'" onmouseout="this.style.color='#CBD5E1'">Contact</a>
            </nav>
        </div>

        <!-- Col 3: Motto & Saffron Accent -->
        <div style="display: flex; flex-direction: column; justify-content: flex-start;">
            <p style="margin: 0 0 4px; font-family: Georgia, 'Times New Roman', serif; font-style: italic; font-size: 1.65rem; color: #E2E8F0; line-height: 1.2;">
                Together
            </p>
            <p style="margin: 0; font-size: 0.96rem; color: #F1F5F9; font-weight: 500;">
                for Stronger Panchayats
            </p>
            <p style="margin: 4px 0 12px; font-size: 0.96rem; color: #F1F5F9; font-weight: 600;">
                Stronger Rural Karnataka
            </p>
            <div style="width: 120px; height: 3px; background: var(--accent-saffron); border-radius: 2px;"></div>
        </div>
    </div>

    <!-- Bottom Bar -->
    <div style="border-top: 1px solid rgba(255, 255, 255, 0.12); padding: 18px 24px;">
        <div style="max-width: var(--content-width); margin: 0 auto; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <p style="margin: 0; font-size: 0.8rem; color: #94A3B8;">
                &copy; <?= date('Y') ?> <?= Sanitize::html($footerShort) ?>. Platform under active development.
            </p>
            <div style="display: flex; gap: 16px; font-size: 0.8rem;">
                <a href="/about.php" style="color: #94A3B8; text-decoration: none;">Privacy Policy</a>
                <span style="color: #475569;">|</span>
                <a href="/about.php" style="color: #94A3B8; text-decoration: none;">Terms of Use</a>
            </div>
        </div>
    </div>
</footer>
<style>
@media (max-width: 768px) {
    footer > div:first-child {
        grid-template-columns: 1fr !important;
        gap: 28px !important;
    }
}
</style>
</body>
</html>
