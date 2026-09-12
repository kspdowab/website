<?php
/**
 * KSPDOWA — Shared Public Page Header
 * ============================================================
 * Include after bootstrap.php. Expects (optionally) $pageTitle
 * to be set by the including page before this file is required.
 *
 * Design system: CSS custom properties under :root below are the
 * single source of truth for the public site's colour/spacing
 * tokens (pastel institutional palette — soft blue, muted teal,
 * light lavender, warm cream, subtle green accent). Change the
 * token values here to re-theme every public page at once.
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
    <meta name="theme-color" content="#EAF1F8">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Kannada:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ==============================================================
           DESIGN TOKENS — KSPDOWA Public Site
           Pastel institutional palette. Each accent has a deep "-700"
           tone (text/icons/buttons — meets WCAG AA on white/cream) and
           a light "-100" tone (tinted backgrounds/badges).
           ============================================================== */
        :root {
            --font-sans: 'Inter', 'Noto Sans Kannada', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;

            /* Neutral ink */
            --ink-900: #1E293B;
            --ink-700: #33415A;
            --ink-500: #55637A;
            --ink-300: #94A0B2;

            /* Soft blue — primary brand accent */
            --blue-700: #2E5077;
            --blue-600: #3E6690;
            --blue-100: #E7F0F8;

            /* Muted teal — secondary accent */
            --teal-700: #2C6B67;
            --teal-100: #E1F2EF;

            /* Light lavender — tertiary accent */
            --lavender-700: #665C9E;
            --lavender-100: #ECE9F7;

            /* Subtle green — success / positive accent */
            --green-700: #3C7A54;
            --green-100: #E6F3EA;

            /* Amber — informational caveats (not a primary accent, used sparingly) */
            --amber-700: #8A5A22;
            --amber-100: #FBF1DE;

            /* Red — errors only */
            --red-700: #A23B32;
            --red-100: #FBEAE7;

            /* Warm surfaces */
            --cream: #FAF7F0;
            --surface: #FFFFFF;
            --surface-alt: #F5F1E7;
            --border: #E5DFD1;
            --border-soft: #EEE9DC;

            /* Shape / elevation / spacing */
            --radius-sm: 6px;
            --radius-md: 12px;
            --radius-lg: 18px;
            --shadow-sm: 0 1px 3px rgba(46, 80, 119, 0.08);
            --shadow-md: 0 6px 20px rgba(46, 80, 119, 0.10);
            --space-1: 4px; --space-2: 8px; --space-3: 12px; --space-4: 16px;
            --space-5: 24px; --space-6: 32px; --space-7: 48px; --space-8: 64px;
            --content-width: 1120px;

            /* Backwards-compatible aliases (older inline styles on some
               pages still reference these — keep them pointed at the
               new palette so nothing silently reverts to navy). */
            --brand-dark: var(--blue-700);
            --brand-darker: var(--blue-700);
            --brand-bg: var(--cream);
            --brand-text: var(--ink-900);
            --brand-muted: var(--ink-500);
            --brand-border: var(--border);
        }

        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            font-family: var(--font-sans);
            background: var(--cream);
            color: var(--ink-700);
            margin: 0;
            line-height: 1.65;
            -webkit-font-smoothing: antialiased;
        }
        img { max-width: 100%; }
        a { color: var(--blue-700); }
        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible {
            outline: 2px solid var(--blue-600);
            outline-offset: 2px;
            border-radius: 2px;
        }

        h1, h2, h3 { font-family: var(--font-sans); color: var(--ink-900); font-weight: 700; letter-spacing: -0.01em; }

        /* ---------------- Header / navigation ---------------- */
        .site-header {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .site-header-inner {
            max-width: var(--content-width);
            margin: 0 auto;
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }
        .brand { display: flex; align-items: center; gap: 14px; text-decoration: none; }
        .brand-mark {
            display: flex; align-items: center; justify-content: center;
            width: 52px; height: 52px; border-radius: 50%;
            background: var(--blue-100);
            flex: none;
        }
        .brand-mark img { height: 38px; width: 38px; object-fit: contain; }
        .brand-text .brand-short { font-weight: 800; color: var(--ink-900); font-size: 1.08rem; display: block; }
        .brand-text .brand-tagline { font-size: 0.75rem; color: var(--ink-500); display: block; margin-top: 1px; }
        nav.main-nav { display: flex; align-items: center; gap: 2px; flex-wrap: wrap; }
        nav.main-nav a {
            text-decoration: none;
            color: var(--ink-700);
            font-size: 0.9rem;
            font-weight: 600;
            padding: 9px 14px;
            border-radius: var(--radius-sm);
            border-bottom: 2px solid transparent;
        }
        nav.main-nav a:hover { background: var(--blue-100); color: var(--blue-700); }
        nav.main-nav a.active { color: var(--blue-700); border-bottom-color: var(--teal-700); }
        nav.main-nav a.cta { background: var(--blue-700); color: #fff; margin-left: 6px; }
        nav.main-nav a.cta:hover { background: var(--blue-600); color: #fff; }

        /* ---------------- Layout ---------------- */
        main { max-width: var(--content-width); margin: 0 auto; padding: var(--space-6) 24px 72px; }
        .eyebrow {
            display: inline-block; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em;
            text-transform: uppercase; color: var(--teal-700); background: var(--teal-100);
            padding: 4px 10px; border-radius: 999px; margin-bottom: var(--space-3);
        }
        .page-title { color: var(--ink-900); font-size: 1.9rem; margin: 0 0 8px; line-height: 1.25; }
        .page-subtitle { color: var(--ink-500); margin: 0 0 var(--space-6); font-size: 1.02rem; }

        /* ---------------- Cards ---------------- */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 28px;
            margin-bottom: var(--space-5);
            box-shadow: var(--shadow-sm);
        }
        .card h2.card-title, .card > h2 {
            margin-top: 0; color: var(--ink-900); font-size: 1.15rem; margin-bottom: var(--space-4);
            padding-bottom: var(--space-3); border-bottom: 1px solid var(--border-soft);
        }
        .empty-state { color: var(--ink-500); font-style: italic; padding: var(--space-2) 0; }

        /* ---------------- Buttons ---------------- */
        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--blue-700);
            color: #fff !important;
            text-decoration: none;
            padding: 11px 20px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.9rem;
            border: 1px solid var(--blue-700);
            cursor: pointer;
        }
        .btn:hover { background: var(--blue-600); border-color: var(--blue-600); }
        .btn-outline { background: transparent; color: var(--blue-700) !important; border: 1px solid var(--blue-700); }
        .btn-outline:hover { background: var(--blue-100); }
        .btn-teal { background: var(--teal-700); border-color: var(--teal-700); }
        .btn-teal:hover { background: #245A57; border-color: #245A57; }

        /* ---------------- Badges ---------------- */
        .badge {
            display: inline-block; font-size: 0.72rem; font-weight: 700;
            padding: 3px 10px; border-radius: 999px; letter-spacing: 0.02em;
        }
        .badge-blue   { background: var(--blue-100);   color: var(--blue-700); }
        .badge-teal   { background: var(--teal-100);   color: var(--teal-700); }
        .badge-lav    { background: var(--lavender-100); color: var(--lavender-700); }
        .badge-green  { background: var(--green-100);  color: var(--green-700); }
        .badge-muted  { background: var(--surface-alt); color: var(--ink-500); }

        /* ---------------- Alerts / notices ---------------- */
        .alert { padding: 14px 16px; border-radius: var(--radius-md); font-size: 0.9rem; border: 1px solid transparent; margin: var(--space-4) 0; }
        .alert-info    { background: var(--amber-100); color: var(--amber-700); border-color: #F0DFB4; }
        .alert-error   { background: var(--red-100);   color: var(--red-700);   border-color: #F3C9C2; }
        .alert-success { background: var(--green-100); color: var(--green-700); border-color: #C9E6D1; }

        /* ---------------- Tables ---------------- */
        table.plain { width: 100%; border-collapse: collapse; }
        table.plain th, table.plain td { text-align: left; padding: 12px 14px; border-bottom: 1px solid var(--border-soft); font-size: 0.92rem; }
        table.plain th { background: var(--surface-alt); color: var(--ink-700); font-weight: 700; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.03em; }
        table.plain th:first-child { border-top-left-radius: var(--radius-sm); }
        table.plain th:last-child { border-top-right-radius: var(--radius-sm); }
        table.plain tbody tr:hover { background: var(--cream); }
        table.plain tr:last-child td { border-bottom: none; }
        .table-wrap { overflow-x: auto; }

        /* ---------------- Stats strip ---------------- */
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: var(--space-4); margin-bottom: var(--space-6); }
        .stat-card {
            background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg);
            padding: 20px; text-align: center; box-shadow: var(--shadow-sm);
        }
        .stat-card .stat-value { font-size: 1.7rem; font-weight: 800; color: var(--blue-700); display: block; }
        .stat-card .stat-label { font-size: 0.8rem; color: var(--ink-500); margin-top: 4px; display: block; }
        .stat-card:nth-child(2) .stat-value { color: var(--teal-700); }
        .stat-card:nth-child(3) .stat-value { color: var(--lavender-700); }
        .stat-card:nth-child(4) .stat-value { color: var(--green-700); }

        /* ---------------- Hero ---------------- */
        .hero {
            background: var(--blue-100);
            border-radius: var(--radius-lg);
            padding: var(--space-7) var(--space-6);
            margin: -8px 0 var(--space-6);
            text-align: center;
            border: 1px solid var(--border-soft);
        }
        .hero .page-title { font-size: 2.1rem; margin: 0 auto 10px; max-width: 720px; }
        .hero .page-subtitle { max-width: 620px; margin: 0 auto var(--space-5); }
        .hero .hero-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }

        /* ---------------- Forms (member/payment-facing screens) ---------------- */
        .form-group { margin-bottom: var(--space-4); }
        .form-group label {
            display: block; font-size: 0.85rem; font-weight: 600;
            color: var(--ink-700); margin-bottom: 6px;
        }
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="password"],
        .form-group select {
            width: 100%; padding: 10px 12px; border: 1px solid var(--border);
            border-radius: var(--radius-sm); font-size: 1rem; font-family: var(--font-sans);
            background: var(--surface); color: var(--ink-900);
        }
        .form-group input:focus, .form-group select:focus {
            outline: none; border-color: var(--blue-600);
            box-shadow: 0 0 0 3px var(--blue-100);
        }
        .form-hint { font-size: 0.8rem; color: var(--ink-500); margin-top: 4px; }
        .form-narrow { max-width: 420px; margin: 0 auto; }

        @media (max-width: 640px) {
            .site-header-inner { flex-direction: column; align-items: flex-start; }
            nav.main-nav { width: 100%; }
            main { padding: var(--space-5) 16px 56px; }
            .hero { padding: var(--space-6) var(--space-4); }
            .hero .page-title { font-size: 1.6rem; }
            table.plain { display: block; overflow-x: auto; white-space: nowrap; }
            .card { padding: 20px; }
        }
    </style>
</head>
<body>
<header class="site-header">
    <div class="site-header-inner">
        <a class="brand" href="/">
            <span class="brand-mark">
                <img src="/assets/images/logo.jpg" alt="<?= Sanitize::attr($siteShortName) ?> emblem">
            </span>
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
                <?php if (Auth::getCurrentMemberId() !== null): ?>
                    <a href="/member/index.php" class="cta">Member Portal</a>
                <?php else: ?>
                    <a href="/admin/office-bearers.php" class="cta">Admin</a>
                <?php endif; ?>
            <?php else: ?>
                <a href="/login.php" class="cta">Login</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main>
