<?php
/**
 * KSPDOWA — Logout
 * ============================================================
 * Auth::logout() destroys the session, logs the event, and
 * redirects to /login.php on its own.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Auth::logout();
