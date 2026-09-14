<?php
/**
 * KSPDOWA — Member Portal: Membership Fee (Legacy Route)
 * ============================================================
 * Fee status and payment details are now merged into "My Membership"
 * (Section 8: My Membership) with zero duplication.
 * Redirects seamlessly to /member/membership.php.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();

header('Location: /member/membership.php', true, 301);
exit;
