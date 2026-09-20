<?php
/**
 * KSPDOWA — Admin: Advanced Integrations & Notification Center
 * ============================================================
 * Phase 8: Advanced Integrations
 *
 * Configures and monitors:
 *   - Email / SMTP Settings & Diagnostics
 *   - WhatsApp Cloud API / Webhook Messaging
 *   - Event Triggers & Notification Rules
 *   - PWA Health & Service Worker Status
 *   - Delivery Audit Trail (notification_logs)
 *
 * RBAC: 'settings.view' to view, 'settings.manage' to modify.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentUserId = Auth::getCurrentUserId();
RBAC::requirePermission($currentUserId, 'settings', 'view');

$canManage = RBAC::can($currentUserId, 'settings', 'manage');
$successMsg = Session::getFlash('success');
$errorMsg   = Session::getFlash('error');

// Handle Form Submissions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canManage) {
    CSRF::requireValid();
    $action = trim(Sanitize::string($_POST['action'] ?? 'save_settings'));

    // 1. Test Email Diagnostic
    if ($action === 'test_email') {
        $testRecipient = trim(Sanitize::email($_POST['test_email_recipient'] ?? ''));
        if ($testRecipient === '' || $testRecipient === false) {
            Session::flash('error', 'Please provide a valid recipient email address for testing.');
        } else {
            $diagMsg = null;
            $ok = Mailer::sendTest($testRecipient, $diagMsg);
            if ($ok) {
                Session::flash('success', 'Test email dispatched successfully! ' . ($diagMsg ?? ''));
            } else {
                Session::flash('error', 'Test email failed: ' . ($diagMsg ?? 'Unknown error. Check error log.'));
            }
        }
        header('Location: /admin/integrations.php?tab=email');
        exit;
    }

    // 2. Test WhatsApp Diagnostic
    if ($action === 'test_whatsapp') {
        $testPhone = trim(Sanitize::string($_POST['test_wa_phone'] ?? ''));
        if ($testPhone === '') {
            Session::flash('error', 'Please provide a valid 10-digit mobile number for testing.');
        } else {
            $diagMsg = null;
            $ok = WhatsApp::sendTest($testPhone, $diagMsg);
            if ($ok) {
                Session::flash('success', 'Test WhatsApp message dispatched! ' . ($diagMsg ?? ''));
            } else {
                Session::flash('error', 'Test WhatsApp failed: ' . ($diagMsg ?? 'Unknown error.'));
            }
        }
        header('Location: /admin/integrations.php?tab=whatsapp');
        exit;
    }

    // 3. Trigger Scheduled Automation On-Demand
    if ($action === 'run_scheduled') {
        define('INTERNAL_ADMIN_TRIGGER', true);
        ob_start();
        include PROJECT_ROOT . '/scripts/run_scheduled_tasks.php';
        $output = ob_get_clean();
        Session::flash('success', 'Scheduled automation workflows executed successfully.');
        header('Location: /admin/integrations.php?tab=automation');
        exit;
    }

    // 4. Save Settings
    if ($action === 'save_settings') {
        $clean = [];

        // Email settings
        $clean['email_notifications_enabled'] = isset($_POST['email_notifications_enabled']) ? 'true' : 'false';
        $clean['mail_driver']                 = in_array($_POST['mail_driver'] ?? 'log', ['log', 'smtp'], true) ? $_POST['mail_driver'] : 'log';
        $clean['smtp_host']                   = trim(Sanitize::string($_POST['smtp_host'] ?? '', 255));
        $clean['smtp_port']                   = (string)max(1, min(65535, (int)($_POST['smtp_port'] ?? 587)));
        $clean['smtp_encryption']             = in_array($_POST['smtp_encryption'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_encryption'] : 'tls';
        $clean['smtp_user']                   = trim(Sanitize::string($_POST['smtp_user'] ?? '', 255));
        if (!empty($_POST['smtp_pass'])) {
            $clean['smtp_pass']               = trim($_POST['smtp_pass']);
        }
        $clean['mail_from_address']           = trim(Sanitize::string($_POST['mail_from_address'] ?? 'no-reply@kspdowa.org', 255));
        $clean['mail_from_name']              = trim(Sanitize::string($_POST['mail_from_name'] ?? 'KSPDOWA', 100));

        // WhatsApp settings
        $clean['whatsapp_enabled']            = isset($_POST['whatsapp_enabled']) ? 'true' : 'false';
        $clean['whatsapp_driver']             = in_array($_POST['whatsapp_driver'] ?? 'log', ['log', 'cloud_api', 'webhook'], true) ? $_POST['whatsapp_driver'] : 'log';
        $clean['whatsapp_endpoint']           = trim(Sanitize::string($_POST['whatsapp_endpoint'] ?? '', 500));
        $clean['whatsapp_phone_number_id']    = trim(Sanitize::string($_POST['whatsapp_phone_number_id'] ?? '', 100));
        if (!empty($_POST['whatsapp_token'])) {
            $clean['whatsapp_token']          = trim($_POST['whatsapp_token']);
        }

        // Event toggles
        $clean['notif_event_membership']      = isset($_POST['notif_event_membership']) ? 'true' : 'false';
        $clean['notif_event_payment']         = isset($_POST['notif_event_payment']) ? 'true' : 'false';
        $clean['notif_event_grievance']       = isset($_POST['notif_event_grievance']) ? 'true' : 'false';
        $clean['notif_event_suggestion']      = isset($_POST['notif_event_suggestion']) ? 'true' : 'false';
        $clean['pwa_enabled']                 = isset($_POST['pwa_enabled']) ? 'true' : 'false';

        try {
            Database::transaction(function () use ($clean, $currentUserId) {
                foreach ($clean as $k => $v) {
                    Database::execute(
                        "INSERT INTO system_settings (setting_key, setting_value, setting_type, updated_by)
                         VALUES (?, ?, 'string', ?)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)",
                        [$k, $v, $currentUserId]
                    );
                }
                AuditLogger::log('UPDATE', 'system_settings', null, null, ['integrations_updated' => array_keys($clean)]);
            });
            Session::flash('success', 'Integration settings saved successfully.');
        } catch (Throwable $e) {
            error_log('[KSPDOWA][integrations] save failed: ' . $e->getMessage());
            Session::flash('error', 'Could not save settings: ' . $e->getMessage());
        }

        $activeTab = Sanitize::string($_POST['current_tab'] ?? 'email');
        header('Location: /admin/integrations.php?tab=' . urlencode($activeTab));
        exit;
    }
}

$activeTab = Sanitize::string($_GET['tab'] ?? 'email');
$current = Settings::all();

// Recent notification logs
$logs = Database::fetchAll(
    "SELECT id, channel, recipient, event_type, title, status, error_message, created_at
     FROM notification_logs
     ORDER BY created_at DESC
     LIMIT 30"
);

$pageTitle  = 'Advanced Integrations';
$activeMenu = 'integrations';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'System & Settings', 'url' => '/admin/settings.php'],
    ['label' => 'Integrations', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>

<style>
    .integrations-tabs {
        display: flex; gap: 8px; border-bottom: 2px solid #e2e8f0; margin-bottom: 24px; flex-wrap: wrap;
    }
    .tab-btn {
        padding: 10px 18px; font-weight: 600; font-size: 0.9rem; color: #64748b; background: none;
        border: none; border-bottom: 3px solid transparent; cursor: pointer; text-decoration: none;
        display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;
    }
    .tab-btn:hover { color: #1769AA; }
    .tab-btn.active { color: #1769AA; border-bottom-color: #1769AA; }
    .config-card {
        background: #fff; border-radius: 8px; box-shadow: 0 1px 6px rgba(26,58,107,0.08);
        border: 1px solid #e2e8f0; margin-bottom: 24px; overflow: hidden;
    }
    .config-card-header {
        background: #f8fafc; padding: 16px 20px; border-bottom: 1px solid #e2e8f0;
        display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;
    }
    .config-card-title { font-size: 1.05rem; font-weight: 700; color: #173F67; margin: 0; }
    .config-card-body { padding: 24px 20px; }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
    .form-group { margin-bottom: 16px; }
    .form-label { display: block; font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 6px; }
    .form-hint { font-size: 0.78rem; color: #64748b; margin-top: 4px; }
    .form-control {
        width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px;
        font-size: 0.9rem; color: #1e293b; background: #fff; box-sizing: border-box;
    }
    .form-control:focus { outline: none; border-color: #1769AA; box-shadow: 0 0 0 3px rgba(23,105,170,0.15); }
    .toggle-switch { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; font-size: 0.9rem; font-weight: 600; color: #1e293b; }
    .toggle-switch input { width: 18px; height: 18px; accent-color: #1769AA; cursor: pointer; }
    .status-badge {
        display: inline-block; padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;
    }
    .status-sent { background: #dcfce7; color: #15803d; }
    .status-logged { background: #dbeafe; color: #1d4ed8; }
    .status-failed { background: #fee2e2; color: #b91c1c; }
    .status-disabled { background: #f1f5f9; color: #64748b; }
    .channel-pill {
        display: inline-flex; align-items: center; gap: 5px; font-size: 0.78rem; font-weight: 600;
        padding: 3px 8px; border-radius: 4px; background: #f1f5f9; color: #334155;
    }
    .diag-box {
        background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 14px; margin-top: 20px;
    }
    .diag-box-title { font-weight: 700; color: #166534; margin-bottom: 6px; font-size: 0.9rem; }
    .footer-credit {
        text-align: center; margin-top: 36px; padding: 18px 0; font-size: 0.82rem; color: #64748b; border-top: 1px solid #e2e8f0;
    }
</style>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Advanced Integrations</h1>
        <p class="page-heading-subtitle">Transactional Email (SMTP), WhatsApp Cloud API, PWA Service Worker, and Workflow Automation</p>
    </div>
</div>

<?php if ($successMsg): ?>
    <div class="alert alert-success" style="background:#dcfce7; color:#15803d; padding:12px 18px; border-radius:6px; margin-bottom:20px; border:1px solid #86efac;">
        <?= Sanitize::html($successMsg) ?>
    </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger" style="background:#fee2e2; color:#b91c1c; padding:12px 18px; border-radius:6px; margin-bottom:20px; border:1px solid #fca5a5;">
        <?= Sanitize::html($errorMsg) ?>
    </div>
<?php endif; ?>

<!-- Tabs -->
<div class="integrations-tabs">
    <a href="?tab=email" class="tab-btn <?= $activeTab === 'email' ? 'active' : '' ?>">
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        Email &amp; SMTP
    </a>
    <a href="?tab=whatsapp" class="tab-btn <?= $activeTab === 'whatsapp' ? 'active' : '' ?>">
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
        WhatsApp Messaging
    </a>
    <a href="?tab=automation" class="tab-btn <?= $activeTab === 'automation' ? 'active' : '' ?>">
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
        Workflow Automation
    </a>
    <a href="?tab=pwa" class="tab-btn <?= $activeTab === 'pwa' ? 'active' : '' ?>">
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
        PWA Health
    </a>
    <a href="?tab=logs" class="tab-btn <?= $activeTab === 'logs' ? 'active' : '' ?>">
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        Delivery Logs
    </a>
</div>

<!-- ======================================================================= -->
<!-- TAB 1: EMAIL & SMTP                                                     -->
<!-- ======================================================================= -->
<?php if ($activeTab === 'email'): ?>
<div class="config-card">
    <div class="config-card-header">
        <h2 class="config-card-title">SMTP Server &amp; Outgoing Mail Settings</h2>
        <span class="status-badge <?= ($current['email_notifications_enabled'] ?? '') === 'true' ? 'status-sent' : 'status-disabled' ?>">
            <?= ($current['email_notifications_enabled'] ?? '') === 'true' ? 'Active' : 'Disabled' ?>
        </span>
    </div>
    <div class="config-card-body">
        <form method="POST" action="/admin/integrations.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="current_tab" value="email">

            <div class="form-group">
                <label class="toggle-switch">
                    <input type="checkbox" name="email_notifications_enabled" value="true" <?= ($current['email_notifications_enabled'] ?? '') === 'true' ? 'checked' : '' ?> <?= !$canManage ? 'disabled' : '' ?>>
                    Enable Outgoing Email Notifications
                </label>
                <div class="form-hint">Controls whether automated emails are dispatched for registrations, payments, and grievance updates.</div>
            </div>

            <div class="form-grid" style="margin-top: 20px;">
                <div class="form-group">
                    <label class="form-label">Mail Driver</label>
                    <select name="mail_driver" class="form-control" <?= !$canManage ? 'disabled' : '' ?>>
                        <option value="log" <?= ($current['mail_driver'] ?? 'log') === 'log' ? 'selected' : '' ?>>Log Driver (Safe for development / storage/mail.log)</option>
                        <option value="smtp" <?= ($current['mail_driver'] ?? '') === 'smtp' ? 'selected' : '' ?>>SMTP (Live Mail Server)</option>
                    </select>
                    <div class="form-hint">In 'log' mode, emails are safely logged to storage/mail.log without hitting external servers.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">SMTP Hostname</label>
                    <input type="text" name="smtp_host" class="form-control" value="<?= Sanitize::attr($current['smtp_host'] ?? '') ?>" placeholder="e.g. smtp.hostinger.com or smtp.gmail.com" <?= !$canManage ? 'disabled' : '' ?>>
                </div>

                <div class="form-group">
                    <label class="form-label">SMTP Port</label>
                    <input type="number" name="smtp_port" class="form-control" value="<?= Sanitize::attr($current['smtp_port'] ?? '587') ?>" placeholder="587 or 465" <?= !$canManage ? 'disabled' : '' ?>>
                    <div class="form-hint">Common ports: 587 (TLS/STARTTLS), 465 (SSL).</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Encryption Protocol</label>
                    <select name="smtp_encryption" class="form-control" <?= !$canManage ? 'disabled' : '' ?>>
                        <option value="tls" <?= ($current['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS - Recommended)</option>
                        <option value="ssl" <?= ($current['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                        <option value="none" <?= ($current['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None (Unencrypted)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">SMTP Username</label>
                    <input type="text" name="smtp_user" class="form-control" value="<?= Sanitize::attr($current['smtp_user'] ?? '') ?>" placeholder="e.g. info@kspdowa.org" <?= !$canManage ? 'disabled' : '' ?>>
                </div>

                <div class="form-group">
                    <label class="form-label">SMTP Password</label>
                    <input type="password" name="smtp_pass" class="form-control" placeholder="<?= !empty($current['smtp_pass']) ? '•••••••••••• (Leave blank to keep unchanged)' : 'Enter SMTP password' ?>" autocomplete="new-password" <?= !$canManage ? 'disabled' : '' ?>>
                    <div class="form-hint">Stored securely on server. Never exposed in cleartext.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">From Email Address</label>
                    <input type="email" name="mail_from_address" class="form-control" value="<?= Sanitize::attr($current['mail_from_address'] ?? 'no-reply@kspdowa.org') ?>" <?= !$canManage ? 'disabled' : '' ?>>
                </div>

                <div class="form-group">
                    <label class="form-label">From Display Name</label>
                    <input type="text" name="mail_from_name" class="form-control" value="<?= Sanitize::attr($current['mail_from_name'] ?? 'KSPDOWA') ?>" <?= !$canManage ? 'disabled' : '' ?>>
                </div>
            </div>

            <?php if ($canManage): ?>
            <div style="margin-top: 20px;">
                <button type="submit" class="btn btn-primary" style="background:#1769AA; color:#fff; padding:10px 24px; border-radius:6px; border:none; font-weight:600; cursor:pointer;">
                    Save Email Settings
                </button>
            </div>
            <?php endif; ?>
        </form>

        <!-- Diagnostics Box -->
        <?php if ($canManage): ?>
        <div class="diag-box">
            <div class="diag-box-title">SMTP Diagnostics Tool</div>
            <p style="font-size: 0.85rem; color: #374151; margin-bottom: 12px;">
                Dispatch an immediate test email to verify your mail server handshake, STARTTLS encryption, and authentication credentials.
            </p>
            <form method="POST" action="/admin/integrations.php" style="display: flex; gap: 10px; max-width: 500px; flex-wrap: wrap;">
                <?= CSRF::htmlField() ?>
                <input type="hidden" name="action" value="test_email">
                <input type="email" name="test_email_recipient" class="form-control" style="flex: 1; min-width: 220px;" placeholder="recipient@example.com" required>
                <button type="submit" class="btn" style="background:#15803d; color:#fff; border:none; padding:9px 18px; border-radius:6px; font-weight:600; cursor:pointer;">
                    Send Test Email
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ======================================================================= -->
<!-- TAB 2: WHATSAPP MESSAGING                                               -->
<!-- ======================================================================= -->
<?php if ($activeTab === 'whatsapp'): ?>
<div class="config-card">
    <div class="config-card-header">
        <h2 class="config-card-title">WhatsApp Cloud API &amp; Webhook Messaging</h2>
        <span class="status-badge <?= ($current['whatsapp_enabled'] ?? '') === 'true' ? 'status-sent' : 'status-disabled' ?>">
            <?= ($current['whatsapp_enabled'] ?? '') === 'true' ? 'Active' : 'Disabled' ?>
        </span>
    </div>
    <div class="config-card-body">
        <form method="POST" action="/admin/integrations.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="current_tab" value="whatsapp">

            <div class="form-group">
                <label class="toggle-switch">
                    <input type="checkbox" name="whatsapp_enabled" value="true" <?= ($current['whatsapp_enabled'] ?? '') === 'true' ? 'checked' : '' ?> <?= !$canManage ? 'disabled' : '' ?>>
                    Enable WhatsApp Transactional Alerts
                </label>
                <div class="form-hint">Allows automated SMS/WhatsApp alerts for membership approvals, fee receipts, and grievance updates.</div>
            </div>

            <div class="form-grid" style="margin-top: 20px;">
                <div class="form-group">
                    <label class="form-label">Integration Driver</label>
                    <select name="whatsapp_driver" class="form-control" <?= !$canManage ? 'disabled' : '' ?>>
                        <option value="log" <?= ($current['whatsapp_driver'] ?? 'log') === 'log' ? 'selected' : '' ?>>Log Driver (storage/whatsapp.log)</option>
                        <option value="cloud_api" <?= ($current['whatsapp_driver'] ?? '') === 'cloud_api' ? 'selected' : '' ?>>Meta WhatsApp Business Cloud API</option>
                        <option value="webhook" <?= ($current['whatsapp_driver'] ?? '') === 'webhook' ? 'selected' : '' ?>>Custom Webhook / SMS Aggregator</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">API Endpoint URL</label>
                    <input type="url" name="whatsapp_endpoint" class="form-control" value="<?= Sanitize::attr($current['whatsapp_endpoint'] ?? 'https://graph.facebook.com/v18.0') ?>" placeholder="https://graph.facebook.com/v18.0" <?= !$canManage ? 'disabled' : '' ?>>
                </div>

                <div class="form-group">
                    <label class="form-label">Phone Number ID</label>
                    <input type="text" name="whatsapp_phone_number_id" class="form-control" value="<?= Sanitize::attr($current['whatsapp_phone_number_id'] ?? '') ?>" placeholder="Meta Phone Number ID" <?= !$canManage ? 'disabled' : '' ?>>
                </div>

                <div class="form-group">
                    <label class="form-label">API Access Token / Bearer Key</label>
                    <input type="password" name="whatsapp_token" class="form-control" placeholder="<?= !empty($current['whatsapp_token']) ? '•••••••••••• (Leave blank to keep unchanged)' : 'Enter Access Token' ?>" autocomplete="new-password" <?= !$canManage ? 'disabled' : '' ?>>
                </div>
            </div>

            <?php if ($canManage): ?>
            <div style="margin-top: 20px;">
                <button type="submit" class="btn btn-primary" style="background:#1769AA; color:#fff; padding:10px 24px; border-radius:6px; border:none; font-weight:600; cursor:pointer;">
                    Save WhatsApp Settings
                </button>
            </div>
            <?php endif; ?>
        </form>

        <!-- Diagnostics Box -->
        <?php if ($canManage): ?>
        <div class="diag-box">
            <div class="diag-box-title">WhatsApp Diagnostics Tool</div>
            <p style="font-size: 0.85rem; color: #374151; margin-bottom: 12px;">
                Send a test WhatsApp message to verify phone normalization and provider connectivity.
            </p>
            <form method="POST" action="/admin/integrations.php" style="display: flex; gap: 10px; max-width: 500px; flex-wrap: wrap;">
                <?= CSRF::htmlField() ?>
                <input type="hidden" name="action" value="test_whatsapp">
                <input type="text" name="test_wa_phone" class="form-control" style="flex: 1; min-width: 220px;" placeholder="10-digit mobile (e.g. 9845012345)" required>
                <button type="submit" class="btn" style="background:#15803d; color:#fff; border:none; padding:9px 18px; border-radius:6px; font-weight:600; cursor:pointer;">
                    Send Test Alert
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ======================================================================= -->
<!-- TAB 3: WORKFLOW AUTOMATION & EVENT TRIGGERS                             -->
<!-- ======================================================================= -->
<?php if ($activeTab === 'automation'): ?>
<div class="config-card">
    <div class="config-card-header">
        <h2 class="config-card-title">Automated Event Triggers &amp; Notification Rules</h2>
    </div>
    <div class="config-card-body">
        <form method="POST" action="/admin/integrations.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="current_tab" value="automation">

            <div style="display: flex; flex-direction: column; gap: 18px;">
                <label class="toggle-switch">
                    <input type="checkbox" name="notif_event_membership" value="true" <?= ($current['notif_event_membership'] ?? 'true') === 'true' ? 'checked' : '' ?> <?= !$canManage ? 'disabled' : '' ?>>
                    Membership Events (Registration receipt, account credentials, renewal reminders)
                </label>

                <label class="toggle-switch">
                    <input type="checkbox" name="notif_event_payment" value="true" <?= ($current['notif_event_payment'] ?? 'true') === 'true' ? 'checked' : '' ?> <?= !$canManage ? 'disabled' : '' ?>>
                    Payment Events (Razorpay success confirmation, manual fee receipt generation)
                </label>

                <label class="toggle-switch">
                    <input type="checkbox" name="notif_event_grievance" value="true" <?= ($current['notif_event_grievance'] ?? 'true') === 'true' ? 'checked' : '' ?> <?= !$canManage ? 'disabled' : '' ?>>
                    Grievance Events (Acknowledgement number, forwardings, officer assignments, resolution alerts)
                </label>

                <label class="toggle-switch">
                    <input type="checkbox" name="notif_event_suggestion" value="true" <?= ($current['notif_event_suggestion'] ?? 'true') === 'true' ? 'checked' : '' ?> <?= !$canManage ? 'disabled' : '' ?>>
                    Suggestion Events (Acknowledgement, administrative review updates)
                </label>
            </div>

            <?php if ($canManage): ?>
            <div style="margin-top: 24px;">
                <button type="submit" class="btn btn-primary" style="background:#1769AA; color:#fff; padding:10px 24px; border-radius:6px; border:none; font-weight:600; cursor:pointer;">
                    Save Trigger Preferences
                </button>
            </div>
            <?php endif; ?>
        </form>

        <!-- On-Demand Scheduled Task Runner -->
        <?php if ($canManage): ?>
        <div class="diag-box" style="margin-top: 30px; background: #eff6ff; border-color: #bfdbfe;">
            <div class="diag-box-title" style="color: #1d4ed8;">On-Demand Scheduled Automation Runner</div>
            <p style="font-size: 0.85rem; color: #374151; margin-bottom: 12px;">
                Trigger the background SLA ageing checks, overdue grievance alerts, and membership fee renewal checks immediately.
            </p>
            <form method="POST" action="/admin/integrations.php">
                <?= CSRF::htmlField() ?>
                <input type="hidden" name="action" value="run_scheduled">
                <button type="submit" class="btn" style="background:#2563eb; color:#fff; border:none; padding:9px 18px; border-radius:6px; font-weight:600; cursor:pointer;">
                    Run Scheduled Automation Now
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ======================================================================= -->
<!-- TAB 4: PWA HEALTH                                                       -->
<!-- ======================================================================= -->
<?php if ($activeTab === 'pwa'): ?>
<div class="config-card">
    <div class="config-card-header">
        <h2 class="config-card-title">Progressive Web App (PWA) Health &amp; Installability</h2>
    </div>
    <div class="config-card-body">
        <?php
        $manifestExists = is_file(PUBLIC_HTML . '/manifest.webmanifest');
        $swExists       = is_file(PUBLIC_HTML . '/sw.js');
        $offlineExists  = is_file(PUBLIC_HTML . '/offline.html');
        ?>
        <div class="form-grid">
            <div style="padding: 16px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-weight: 700; color: #173F67; margin-bottom: 6px;">Web App Manifest</div>
                <div style="font-size: 0.85rem; color: #475569;">
                    File: <code>public_html/manifest.webmanifest</code><br>
                    Status: <strong style="color: <?= $manifestExists ? '#15803d' : '#b91c1c' ?>"><?= $manifestExists ? 'Installed &amp; Valid' : 'Missing' ?></strong>
                </div>
            </div>

            <div style="padding: 16px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-weight: 700; color: #173F67; margin-bottom: 6px;">Service Worker Engine</div>
                <div style="font-size: 0.85rem; color: #475569;">
                    File: <code>public_html/sw.js</code><br>
                    Status: <strong style="color: <?= $swExists ? '#15803d' : '#b91c1c' ?>"><?= $swExists ? 'Active &amp; Precaching' : 'Missing' ?></strong>
                </div>
            </div>

            <div style="padding: 16px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-weight: 700; color: #173F67; margin-bottom: 6px;">Offline Fallback Shell</div>
                <div style="font-size: 0.85rem; color: #475569;">
                    File: <code>public_html/offline.html</code><br>
                    Status: <strong style="color: <?= $offlineExists ? '#15803d' : '#b91c1c' ?>"><?= $offlineExists ? 'Ready' : 'Missing' ?></strong>
                </div>
            </div>
        </div>

        <div style="margin-top: 24px; padding: 16px; background: #fdf4ff; border-radius: 8px; border: 1px solid #f0abfc;">
            <div style="font-weight: 700; color: #86198f; margin-bottom: 6px;">Security &amp; Caching Compliance</div>
            <p style="font-size: 0.85rem; color: #4a044e; margin: 0; line-height: 1.5;">
                Per project security guidelines, all authenticated portal endpoints (<code>/admin/</code>, <code>/member/</code>, payment workflows, and document downloads) strictly bypass local service worker cache to protect private officer and member records.
            </p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ======================================================================= -->
<!-- TAB 5: DELIVERY LOGS                                                    -->
<!-- ======================================================================= -->
<?php if ($activeTab === 'logs'): ?>
<div class="config-card">
    <div class="config-card-header">
        <h2 class="config-card-title">Recent Notification Dispatches (Last 30 Events)</h2>
    </div>
    <div class="table-responsive">
        <table class="data-table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left;">
                    <th style="padding: 10px 14px;">Timestamp</th>
                    <th style="padding: 10px 14px;">Channel</th>
                    <th style="padding: 10px 14px;">Recipient</th>
                    <th style="padding: 10px 14px;">Event / Title</th>
                    <th style="padding: 10px 14px;">Status</th>
                    <th style="padding: 10px 14px;">Diagnostic Error</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" style="padding: 24px; text-align: center; color: #64748b;">No notification dispatches recorded yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 10px 14px; white-space: nowrap; color: #64748b;"><?= Sanitize::html($l['created_at']) ?></td>
                        <td style="padding: 10px 14px;">
                            <span class="channel-pill">
                                <?= strtoupper(Sanitize::html($l['channel'])) ?>
                            </span>
                        </td>
                        <td style="padding: 10px 14px; font-weight: 600;"><?= Sanitize::html($l['recipient']) ?></td>
                        <td style="padding: 10px 14px;">
                            <div style="font-weight: 600; color: #1e293b;"><?= Sanitize::html($l['title']) ?></div>
                            <div style="font-size: 0.75rem; color: #64748b;"><?= Sanitize::html($l['event_type']) ?></div>
                        </td>
                        <td style="padding: 10px 14px;">
                            <?php
                            $badgeClass = match ($l['status']) {
                                'sent'   => 'status-sent',
                                'logged' => 'status-logged',
                                'failed' => 'status-failed',
                                default  => 'status-disabled',
                            };
                            ?>
                            <span class="status-badge <?= $badgeClass ?>"><?= Sanitize::html($l['status']) ?></span>
                        </td>
                        <td style="padding: 10px 14px; color: #b91c1c; font-size: 0.78rem;">
                            <?= !empty($l['error_message']) ? Sanitize::html($l['error_message']) : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="footer-credit">
    Designed &amp; Developed by : KHUBAASING JADAV
</div>

<?php require_once dirname(__DIR__) . '/includes/partials/admin-footer.php'; ?>
