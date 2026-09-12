<?php
/**
 * KSPDOWA — Shared Public Page Header
 * ============================================================
 * Include after bootstrap.php. Expects (optionally) $pageTitle
 * to be set by the including page before this file is required.
 * ============================================================
 */

declare(strict_types=1);

$siteShortName = Settings::get('site_short_name', APP_SHORT_NAME);
$siteName      = Settings::get('site_name', APP_FULL_NAME);
$siteTagline   = Settings::get('site_tagline', '');
$pageTitle     = $pageTitle ?? $siteShortName;
$currentPath   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

function nav_active(string $path, string $current): string
{
    return $path === $current ? ' aria-current="page" class="active"' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Sanitize::html($pageTitle) ?> — <?= Sanitize::html($siteShortName) ?></title>
    <meta name="description" content="<?= Sanitize::attr($siteName) ?>">
    <style>
        :root {
            --brand-dark: #1a3a6b;
            --brand-darker: #142c52;
            --brand-bg: #f5f7fa;
            --brand-text: #1a1a2e;
            --brand-muted: #5a6472;
            --brand-border: #e2e6ec;
        }
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--brand-bg);
            color: var(--brand-text);
            margin: 0;
            line-height: 1.6;
        }
        a { color: var(--brand-dark); }
        .site-header {
            background: #fff;
            border-bottom: 1px solid var(--brand-border);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .site-header-inner {
            max-width: 1080px;
            margin: 0 auto;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .brand img { height: 44px; width: 44px; object-fit: contain; }
        .brand-text .brand-short { font-weight: 700; color: var(--brand-dark); font-size: 1.05rem; display: block; }
        .brand-text .brand-tagline { font-size: 0.72rem; color: var(--brand-muted); display: block; }
        nav.main-nav { display: flex; gap: 4px; flex-wrap: wrap; }
        nav.main-nav a {
            text-decoration: none;
            color: var(--brand-text);
            font-size: 0.9rem;
            font-weight: 500;
            padding: 8px 12px;
            border-radius: 6px;
        }
        nav.main-nav a:hover, nav.main-nav a.active { background: var(--brand-bg); color: var(--brand-dark); }
        nav.main-nav a.cta { background: var(--brand-dark); color: #fff; }
        nav.main-nav a.cta:hover { background: var(--brand-darker); }
        main { max-width: 1080px; margin: 0 auto; padding: 32px 20px 60px; }
        .page-title { color: var(--brand-dark); font-size: 1.6rem; margin: 0 0 8px; }
        .page-subtitle { color: var(--brand-muted); margin: 0 0 28px; }
        .card {
            background: #fff;
            border: 1px solid var(--brand-border);
            border-radius: 10px;
            padding: 24px;
            margin-bottom: 20px;
        }
        .empty-state { color: var(--brand-muted); font-style: italic; padding: 12px 0; }
        .btn {
            display: inline-block;
            background: var(--brand-dark);
            color: #fff;
            text-decoration: none;
            padding: 10px 18px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .btn:hover { background: var(--brand-darker); }
        .btn-outline { background: transparent; color: var(--brand-dark); border: 1px solid var(--brand-dark); }
        table.plain { width: 100%; border-collapse: collapse; }
        table.plain th, table.plain td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--brand-border); font-size: 0.9rem; }
        @media (max-width: 640px) {
            .site-header-inner { flex-direction: column; align-items: flex-start; }
            nav.main-nav { width: 100%; }
            table.plain { display: block; overflow-x: auto; white-space: nowrap; }
            main { padding: 24px 14px 48px; }
        }
    </style>
</head>
<body>
<header class="site-header">
    <div class="site-header-inner">
        <a class="brand" href="/">
            <img src="/assets/images/logo.jpg" alt="<?= Sanitize::attr($siteShortName) ?> emblem">
            <span class="brand-text">
                <span class="brand-short"><?= Sanitize::html($siteShortName) ?></span>
                <?php if ($siteTagline !== ''): ?>
                    <span class="brand-tagline"><?= Sanitize::html($siteTagline) ?></span>
                <?php endif; ?>
            </span>
        </a>
        <nav class="main-nav">
            <a href="/"<?= nav_active('/', $currentPath) ?>>Home</a>
            <a href="/about.php"<?= nav_active('/about.php', $currentPath) ?>>About</a>
            <a href="/office-bearers.php"<?= nav_active('/office-bearers.php', $currentPath) ?>>Office Bearers</a>
            <a href="/recognition.php"<?= nav_active('/recognition.php', $currentPath) ?>>Recognition</a>
            <a href="/news.php"<?= nav_active('/news.php', $currentPath) ?>>News</a>
            <a href="/contact.php"<?= nav_active('/contact.php', $currentPath) ?>>Contact</a>
            <?php if (Auth::isLoggedIn()): ?>
                <a href="/admin/office-bearers.php" class="cta">Admin</a>
            <?php else: ?>
                <a href="/login.php" class="cta">Login</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main>
