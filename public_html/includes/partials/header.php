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
$siteTagline   = Settings::get('site_tagline', 'Government-Recognized Service Association');
$siteAddress   = Settings::get('site_address', '');
$siteFullName  = $siteName;

// Right-side header emblem -- reuses the same admin-configurable
// receipt_logo_right setting already used on PDF receipts (no new
// setting/migration needed). Left logo intentionally left untouched
// (still the fixed /assets/images/logo.jpg used before this change).
$receiptLogoRightSetting = Settings::get('receipt_logo_right', '');
$rightLogoRelative       = $receiptLogoRightSetting !== '' && is_file(PUBLIC_HTML . '/' . ltrim($receiptLogoRightSetting, '/'))
    ? '/' . ltrim($receiptLogoRightSetting, '/')
    : '/assets/images/receipt-logo-right.png';
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
    <meta name="theme-color" content="#173F67">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon.png">
    <link rel="icon" type="image/png" href="/assets/images/logo.png">
    <link rel="apple-touch-icon" href="/assets/images/logo.png">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= Sanitize::attr($siteShortName) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Kannada:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ==============================================================
           DESIGN TOKENS — KSPDOWA Public Site (Professional Blue)
           Primary Navy: #173F67 | Professional Blue: #1769AA | Deep Blue: #0F4C81
           Background: #F5F7FA | Cards: #FFFFFF | Light Blue: #EEF5FA
           Table Header: #F0F4F8 | Border: #DCE3EA | Main Text: #17202A
           ============================================================== */
        :root {
            --font-sans: 'Inter', 'Noto Sans Kannada', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;

            /* Professional Blue Palette */
            --primary-navy: #173F67;
            --prof-blue: #1769AA;
            --deep-blue: #0F4C81;
            --brand-red: #C00000;
            --bg-page: #F5F7FA;
            --surface-card: #FFFFFF;
            --light-blue: #EEF5FA;
            --table-header: #F0F4F8;
            --border-color: #DCE3EA;
            --text-main: #17202A;
            --text-secondary: #667085;

            /* Semantic Accents */
            --accent-saffron: #D99A2B;
            --accent-green: #287A5A;
            --accent-purple: #665AA8;
            --accent-teal: #168A8A;

            /* Backwards-compatible aliases */
            --ink-900: #17202A;
            --ink-700: #17202A;
            --ink-500: #667085;
            --ink-300: #98A2B3;
            --surface: #FFFFFF;
            --surface-alt: #F0F4F8;
            --border: #DCE3EA;
            --border-soft: #E9EEF4;
            --cream: #F5F7FA;

            --blue-700: #173F67;
            --blue-600: #1769AA;
            --blue-100: #EEF5FA;
            --teal-700: #168A8A;
            --teal-100: #E2F2F2;
            --lavender-700: #665AA8;
            --lavender-100: #EFEBFA;
            --green-700: #287A5A;
            --green-100: #E6F3EC;
            --amber-700: #D99A2B;
            --amber-100: #FDF5E6;
            --red-700: #C00000;
            --red-100: #FDE8E8;
            --brand-name-red: #C00000;

            /* Shape / elevation / spacing */
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 12px;
            --shadow-sm: 0 1px 3px rgba(23, 63, 103, 0.05);
            --shadow-md: 0 4px 16px rgba(23, 63, 103, 0.08);
            --shadow-lg: 0 10px 30px rgba(23, 63, 103, 0.12);
            --space-1: 4px; --space-2: 8px; --space-3: 12px; --space-4: 16px;
            --space-5: 24px; --space-6: 32px; --space-7: 48px; --space-8: 64px;
            --content-width: 1160px;

            --brand-dark: var(--primary-navy);
            --brand-darker: var(--deep-blue);
            --brand-bg: var(--bg-page);
            --brand-text: var(--text-main);
            --brand-muted: var(--text-secondary);
            --brand-border: var(--border-color);
        }

        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; scroll-behavior: smooth; }
        body {
            font-family: var(--font-sans);
            background: var(--bg-page);
            color: var(--text-main);
            margin: 0;
            line-height: 1.65;
            -webkit-font-smoothing: antialiased;
        }
        img { max-width: 100%; }
        a { color: var(--prof-blue); transition: color 0.15s ease; }
        a:hover { color: var(--deep-blue); }
        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible {
            outline: 2px solid var(--prof-blue);
            outline-offset: 2px;
            border-radius: 4px;
        }

        h1, h2, h3, h4, h5, h6 {
            font-family: var(--font-sans);
            color: var(--primary-navy);
            font-weight: 700;
            letter-spacing: -0.01em;
        }

        /* ---------------- Header / Navigation ---------------- */
        .site-header {
            background: linear-gradient(180deg, #F8FAFD 0%, #EAF1F8 100%);
            border-bottom: 1px solid #CADAE8;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 3px 12px rgba(16, 49, 84, 0.12);
        }
        .site-header-inner {
            max-width: var(--content-width);
            margin: 0 auto;
            padding: 16px 24px 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            text-align: center;
        }
        .brand {
            display: flex;
            flex-direction: row;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            text-decoration: none;
            width: 100%;
        }
        .brand-mark {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: #ffffff;
            border: 1px solid var(--border-soft);
            flex: none;
            box-shadow: 0 2px 8px rgba(23, 63, 103, 0.08);
            overflow: hidden;
        }
        .brand-mark img {
            height: 80px;
            width: 80px;
            object-fit: contain;
        }
        .brand-text {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            min-width: 0;
            flex: 1;
        }
        .brand-text .brand-eyebrow {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--accent-green);
            margin-bottom: 3px;
            white-space: nowrap;
        }
        .brand-text .brand-full {
            display: block;
            font-weight: 800;
            color: var(--brand-red);
            font-size: 1.28rem;
            line-height: 1.25;
            letter-spacing: -0.01em;
            white-space: nowrap;
        }
        .brand-text .brand-address {
            display: block;
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 4px;
            white-space: nowrap;
        }
        
        nav.main-nav {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            flex-wrap: wrap;
            padding-top: 4px;
            width: 100%;
            border-top: 1px solid #D5E2EE;
        }
        nav.main-nav a.nav-link {
            text-decoration: none;
            color: var(--primary-navy);
            font-size: 0.88rem;
            font-weight: 600;
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            position: relative;
            transition: all 0.15s ease;
        }
        nav.main-nav a.nav-link:hover {
            background: #ffffff;
            color: var(--prof-blue);
            box-shadow: 0 1px 4px rgba(23, 63, 103, 0.08);
        }
        nav.main-nav a.nav-link.active {
            color: var(--prof-blue);
            font-weight: 700;
        }
        nav.main-nav a.nav-link.active::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 12px;
            right: 12px;
            height: 3px;
            background: var(--prof-blue);
            border-radius: 2px;
        }
        
        /* Nav SVG icons */
        .nav-icon {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
            display: inline-block;
            vertical-align: middle;
        }
        
        /* Dropdown menus */
        nav.main-nav .nav-item-dropdown {
            position: relative;
            display: inline-flex;
            align-items: center;
        }
        nav.main-nav .nav-item-dropdown .nav-caret {
            font-size: 0.72rem;
            opacity: 0.75;
            transition: transform 0.2s ease;
            pointer-events: none;
        }
        nav.main-nav .nav-item-dropdown:hover .nav-caret,
        nav.main-nav .nav-item-dropdown.is-open .nav-caret {
            transform: rotate(180deg);
        }
        nav.main-nav .nav-item-dropdown .nav-sub-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            margin-top: 4px;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-md);
            min-width: 230px;
            z-index: 150;
            padding: 6px;
        }
        nav.main-nav .nav-item-dropdown:hover .nav-sub-menu,
        nav.main-nav .nav-item-dropdown:focus-within .nav-sub-menu,
        nav.main-nav .nav-item-dropdown.is-open .nav-sub-menu {
            display: block;
        }
        nav.main-nav .nav-item-dropdown .nav-sub-menu a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border-radius: 6px;
            font-size: 0.86rem;
            color: var(--primary-navy);
            text-decoration: none;
            white-space: nowrap;
            text-align: left;
            transition: all 0.15s ease;
        }
        nav.main-nav .nav-item-dropdown .nav-sub-menu a:hover {
            background: var(--light-blue);
            color: var(--prof-blue);
        }
        .sub-bullet {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            flex-shrink: 0;
        }
        .sub-bullet.blue { background: var(--prof-blue); }
        .sub-bullet.navy { background: var(--primary-navy); }
        .sub-bullet.red  { background: var(--brand-red); }
        .sub-bullet.green{ background: var(--accent-green); }

        /* Login button & chooser dropdown */
        nav.main-nav details.nav-dropdown {
            position: relative;
            margin-left: 6px;
        }
        nav.main-nav details.nav-dropdown summary {
            list-style: none;
            cursor: pointer;
            background: var(--primary-navy);
            color: #ffffff;
            font-size: 0.88rem;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all 0.15s ease;
            box-shadow: var(--shadow-sm);
        }
        nav.main-nav details.nav-dropdown summary::-webkit-details-marker { display: none; }
        nav.main-nav details.nav-dropdown summary:hover,
        nav.main-nav details.nav-dropdown[open] summary {
            background: var(--deep-blue);
            color: #ffffff;
        }
        nav.main-nav details.nav-dropdown .nav-dropdown-menu {
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 6px;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-md);
            min-width: 175px;
            z-index: 150;
            padding: 6px;
        }
        nav.main-nav details.nav-dropdown .nav-dropdown-menu a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border-radius: 6px;
            font-size: 0.86rem;
            color: var(--primary-navy);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.15s ease;
        }
        nav.main-nav details.nav-dropdown .nav-dropdown-menu a:hover {
            background: var(--light-blue);
            color: var(--prof-blue);
        }

        /* ---------------- Layout & Cards ---------------- */
        main {
            max-width: var(--content-width);
            margin: 0 auto;
            padding: 24px 20px 64px;
        }
        main.wide-portal-main {
            max-width: 1540px;
            padding: 20px 20px 64px;
        }
        .page-title {
            color: var(--primary-navy);
            font-size: 1.85rem;
            margin: 0 0 6px;
            line-height: 1.25;
            font-weight: 800;
        }
        .page-subtitle {
            color: var(--text-secondary);
            margin: 0 0 var(--space-5);
            font-size: 1rem;
        }
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--prof-blue);
            background: var(--light-blue);
            padding: 4px 12px;
            border-radius: 999px;
            margin-bottom: var(--space-3);
            border: 1px solid rgba(23, 105, 170, 0.15);
        }

        .card {
            background: var(--surface-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 28px;
            margin-bottom: var(--space-5);
            box-shadow: var(--shadow-sm);
            transition: box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .card:hover {
            box-shadow: var(--shadow-md);
        }
        .card h2.card-title, .card > h2 {
            margin-top: 0;
            color: var(--primary-navy);
            font-size: 1.2rem;
            margin-bottom: var(--space-4);
            padding-bottom: var(--space-3);
            border-bottom: 1px solid var(--border-soft);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .empty-state {
            color: var(--text-secondary);
            font-style: italic;
            padding: var(--space-2) 0;
        }

        /* ---------------- Section Header Badges ---------------- */
        .icon-badge {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .icon-badge.blue {
            background: var(--light-blue);
            color: var(--prof-blue);
        }
        .icon-badge.saffron {
            background: var(--amber-100);
            color: var(--accent-saffron);
        }
        .icon-badge.purple {
            background: var(--lavender-100);
            color: var(--accent-purple);
        }
        .icon-badge.green {
            background: var(--green-100);
            color: var(--accent-green);
        }

        /* ---------------- Buttons ---------------- */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary-navy);
            color: #ffffff !important;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.9rem;
            border: 1px solid var(--primary-navy);
            cursor: pointer;
            transition: all 0.15s ease;
            box-shadow: var(--shadow-sm);
        }
        .btn:hover {
            background: var(--deep-blue);
            border-color: var(--deep-blue);
            color: #ffffff !important;
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }
        .btn-outline, .btn-secondary {
            background: #ffffff !important;
            color: var(--prof-blue) !important;
            border: 1px solid var(--prof-blue) !important;
            box-shadow: none;
        }
        .btn-outline:hover, .btn-secondary:hover {
            background: var(--light-blue) !important;
            color: var(--deep-blue) !important;
            border-color: var(--deep-blue) !important;
        }
        .btn-sm {
            padding: 7px 14px;
            font-size: 0.84rem;
        }

        /* ---------------- Tables ---------------- */
        table.plain {
            width: 100%;
            border-collapse: collapse;
        }
        table.plain th, table.plain td {
            text-align: left;
            padding: 13px 16px;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.92rem;
        }
        table.plain th {
            background: var(--table-header);
            color: var(--primary-navy);
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        table.plain th:first-child { border-top-left-radius: var(--radius-sm); }
        table.plain th:last-child { border-top-right-radius: var(--radius-sm); }
        table.plain tbody tr {
            background: #ffffff;
            transition: background 0.15s ease;
        }
        table.plain tbody tr:hover {
            background: #F7FAFC;
        }
        table.plain tr:last-child td { border-bottom: none; }
        .table-wrap {
            overflow-x: auto;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
        }

        /* ---------------- Forms & Inputs ---------------- */
        .form-group { margin-bottom: var(--space-4); }
        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 6px;
        }
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="password"],
        .form-group input[type="number"],
        .form-group input[type="date"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 0.96rem;
            font-family: var(--font-sans);
            background: #ffffff;
            color: var(--text-main);
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--prof-blue);
            box-shadow: 0 0 0 3px rgba(23, 105, 170, 0.15);
        }
        .form-hint {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 5px;
        }
        .form-narrow {
            max-width: 440px;
            margin: 0 auto;
        }

        /* ---------------- Badges ---------------- */
        .badge {
            display: inline-block;
            font-size: 0.74rem;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 999px;
            letter-spacing: 0.02em;
        }
        .badge-blue   { background: var(--light-blue); color: var(--prof-blue); border: 1px solid #BAE6FD; }
        .badge-navy   { background: #E2E8F0; color: var(--primary-navy); border: 1px solid #CBD5E1; }
        .badge-teal   { background: var(--teal-100);   color: var(--teal-700);   border: 1px solid #99F6E4; }
        .badge-lav    { background: var(--lavender-100); color: var(--lavender-700); border: 1px solid #DDD6FE; }
        .badge-green  { background: var(--green-100);  color: var(--green-700);  border: 1px solid #BBF7D0; }
        .badge-muted  { background: var(--table-header); color: var(--text-secondary); border: 1px solid var(--border-color); }
        .badge-red    { background: var(--red-100); color: var(--brand-red); border: 1px solid #FECACA; }

        /* ---------------- Alerts / Notices ---------------- */
        .alert {
            padding: 14px 18px;
            border-radius: var(--radius-md);
            font-size: 0.92rem;
            border: 1px solid transparent;
            margin: var(--space-4) 0;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .alert-info    { background: var(--light-blue); color: var(--primary-navy); border-color: #BAE6FD; }
        .alert-error   { background: var(--red-100);   color: var(--brand-red);   border-color: #FECACA; }
        .alert-success { background: var(--green-100); color: var(--accent-green); border-color: #BBF7D0; }

        /* ---------------- Responsive Tweaks ---------------- */
        @media (max-width: 768px) {
            .site-header-inner { padding: 12px 14px 10px; gap: 8px; }
            .brand-mark { width: 56px; height: 56px; }
            .brand-mark img { height: 48px; width: 48px; }
            .brand-text .brand-eyebrow { font-size: 0.65rem; }
            .brand-text .brand-full { font-size: 0.85rem; white-space: normal; }
            .brand-text .brand-address { font-size: 0.65rem; white-space: normal; }
            nav.main-nav { gap: 2px; }
            nav.main-nav a.nav-link { padding: 6px 9px; font-size: 0.82rem; }
            main { padding: 16px 14px 48px; }
            .card { padding: 18px; }
            .page-title { font-size: 1.5rem; }
        }
        @media (max-width: 480px) {
            .brand { gap: 10px; }
            .brand-mark { width: 44px; height: 44px; }
            .brand-mark img { height: 38px; width: 38px; }
            .brand-text .brand-full { font-size: 0.76rem; }
            nav.main-nav a.nav-link { font-size: 0.78rem; padding: 6px 8px; }
        }
    </style>
</head>
<body>
<header class="site-header">
    <div class="site-header-inner">
        <a class="brand" href="/" title="<?= Sanitize::attr($siteFullName) ?>">
            <span class="brand-mark">
                <img src="/assets/images/logo.png" alt="<?= Sanitize::attr($siteShortName) ?> emblem">
            </span>
            <span class="brand-text">
                <?php if ($siteTagline !== ''): ?>
                    <span class="brand-eyebrow"><?= Sanitize::html($siteTagline) ?></span>
                <?php endif; ?>
                <span class="brand-full"><?= Sanitize::html($siteFullName) ?></span>
                <?php if ($siteAddress !== ''): ?>
                    <span class="brand-address"><?= Sanitize::html($siteAddress) ?></span>
                <?php endif; ?>
            </span>
            <span class="brand-mark">
                <img src="<?= Sanitize::attr($rightLogoRelative) ?>" alt="<?= Sanitize::attr($siteShortName) ?> emblem">
            </span>
        </a>
        <nav class="main-nav">
            <a href="/" class="nav-link<?= nav_active('/', $currentPath) ? ' active' : '' ?>">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>Home</span>
            </a>
            <a href="/about.php" class="nav-link<?= nav_active('/about.php', $currentPath) ? ' active' : '' ?>">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                <span>About</span>
            </a>
            <div class="nav-item-dropdown">
                <a href="/office-bearers.php" class="nav-link<?= nav_active('/office-bearers.php', $currentPath) ? ' active' : '' ?>">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span>Office Bearers</span>
                    <span class="nav-caret">▾</span>
                </a>
                <div class="nav-sub-menu">
                    <a href="/office-bearers.php#state-council"><span class="sub-bullet blue"></span> State Council (ರಾಜ್ಯ ಪರಿಷತ್ತು)</a>
                    <a href="/office-bearers.php#state"><span class="sub-bullet navy"></span> State Committee (ರಾಜ್ಯ ಸಂಘ)</a>
                    <a href="/office-bearers.php#district"><span class="sub-bullet red"></span> District Committee (ಜಿಲ್ಲಾ ಸಂಘ)</a>
                    <a href="/office-bearers.php#taluk"><span class="sub-bullet green"></span> Taluk Committee (ತಾಲ್ಲೂಕು ಸಂಘ)</a>
                </div>
            </div>
            <a href="/recognition.php" class="nav-link<?= nav_active('/recognition.php', $currentPath) ? ' active' : '' ?>">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.45 1-1 1H7v2h10v-2h-2c-.55 0-1-.45-1-1v-2.34"/><path d="M6 4h12v7a6 6 0 0 1-12 0V4Z"/></svg>
                <span>Recognition</span>
            </a>
            <a href="/news.php" class="nav-link<?= nav_active('/news.php', $currentPath) ? ' active' : '' ?>">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8V6Z"/></svg>
                <span>News</span>
            </a>
            <a href="/contact.php" class="nav-link<?= nav_active('/contact.php', $currentPath) ? ' active' : '' ?>">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                <span>Contact</span>
            </a>
            <a href="/donate.php" class="nav-link<?= nav_active('/donate.php', $currentPath) ? ' active' : '' ?>">
                <svg class="nav-icon" style="color:var(--brand-red);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                <span>Donate</span>
            </a>
            <?php if (Auth::isLoggedIn()): ?>
                <?php if (Auth::getCurrentMemberId() !== null): ?>
                    <a href="/member/index.php" class="nav-link active" style="background:var(--prof-blue); color:#fff; padding:8px 14px; border-radius:var(--radius-sm);">
                        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span>Member Portal</span>
                    </a>
                <?php else: ?>
                    <a href="/admin/index.php" class="nav-link active" style="background:var(--primary-navy); color:#fff; padding:8px 14px; border-radius:var(--radius-sm);">
                        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
                        <span>Admin</span>
                    </a>
                <?php endif; ?>
            <?php else: ?>
                <a href="/register.php" class="nav-link<?= nav_active('/register.php', $currentPath) ? ' active' : '' ?>">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>
                    <span>Register</span>
                </a>
                <details class="nav-dropdown">
                    <summary>
                        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <span>Login</span>
                        <span style="font-size:0.7rem; opacity:0.8;">▾</span>
                    </summary>
                    <div class="nav-dropdown-menu">
                        <a href="/login.php">
                            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
                            <span>Admin Login</span>
                        </a>
                        <a href="/member-login.php">
                            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <span>Member Login</span>
                        </a>
                    </div>
                </details>
            <?php endif; ?>
        </nav>
    </div>
</header>
<script>
(function () {
    // Close any open nav-dropdown <details> or .nav-item-dropdown when click lands outside
    document.addEventListener('click', function (e) {
        document.querySelectorAll('nav.main-nav details.nav-dropdown[open]').forEach(function (d) {
            if (!d.contains(e.target)) { d.removeAttribute('open'); }
        });
        document.querySelectorAll('nav.main-nav .nav-item-dropdown.is-open').forEach(function (d) {
            if (!d.contains(e.target)) { d.classList.remove('is-open'); }
        });
    });

    // Touch device support for Office Bearers dropdown
    document.querySelectorAll('nav.main-nav .nav-item-dropdown > a').forEach(function (trigger) {
        trigger.addEventListener('click', function (e) {
            var parent = this.parentElement;
            if (window.innerWidth <= 768 || ('ontouchstart' in window)) {
                if (!parent.classList.contains('is-open')) {
                    e.preventDefault();
                    parent.classList.add('is-open');
                }
            }
        });
    });
})();
</script>
<main<?= !empty($mainClass) ? ' class="' . htmlspecialchars((string)$mainClass, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
