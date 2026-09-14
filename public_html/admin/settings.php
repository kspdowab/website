<?php
/**
 * KSPDOWA — Admin: Association Settings
 * ============================================================
 * Gated by RBAC permission 'settings.manage' (seeded in Phase 0 --
 * seeds/001_roles_permissions.sql already defines settings.view /
 * settings.manage; this is the first page to actually use them).
 *
 * Deliberately narrow: this edits only the association-identity and
 * contact fields already present in `system_settings` (site_name,
 * site_short_name, site_tagline, site_address, site_email,
 * site_phone, site_website -- the last added by migration 017), plus
 * the two receipt letterhead logo uploads (receipt_logo_left /
 * receipt_logo_right, added by migration 018). It does NOT attempt
 * the full settings surface (maintenance mode, upload limits,
 * grievance numbering, pagination) -- those belong to the real
 * Phase 6 "Association Administration" module per the roadmap. This
 * page exists now because the payment receipt letterhead (Phase 3
 * Receipts unit) needed these values to be admin-editable instead of
 * hard-coded in Receipt.php.
 *
 * Logo uploads reuse the existing Sanitize::fileUpload() /
 * safeUploadFilename() helpers (finfo-verified MIME type, random
 * filename -- never the client-supplied name or extension trusted
 * outright) and are stored under public_html/assets/images/, the
 * same public, unauthenticated-readable location the bundled default
 * logos already live in -- these are letterhead branding images, not
 * access-controlled documents, so this does not need the uploads/
 * .htaccess-protected path used by document.php / member/receipt.php.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();

$currentUserId = Auth::getCurrentUserId();
RBAC::requirePermission($currentUserId, 'settings', 'manage');

$successMsg = Session::getFlash('success');
$errorMsg   = Session::getFlash('error');

$fields = [
    'site_name'       => ['label' => 'Association Full Name',   'maxlen' => 255, 'required' => true],
    'site_short_name' => ['label' => 'Short Name',               'maxlen' => 50,  'required' => true],
    'site_tagline'    => ['label' => 'Tagline',                  'maxlen' => 255, 'required' => false],
    'site_address'    => ['label' => 'Registered Address',       'maxlen' => 500, 'required' => false, 'textarea' => true],
    'site_email'      => ['label' => 'Contact Email',            'maxlen' => 255, 'required' => false, 'type' => 'email'],
    'site_phone'      => ['label' => 'Contact Phone / Mobile',   'maxlen' => 30,  'required' => false],
    'site_website'    => ['label' => 'Website',                  'maxlen' => 255, 'required' => false, 'type' => 'url'],
];

// Receipt number format: PREFIX-YEAR-NNNNN (default KSPDOWA-RCP-2026-00001).
// Kept as a separate field set (rendered in its own panel below) since
// these aren't association-identity text, they're a numbering scheme --
// receipt_no_pad_length in particular needs numeric validation, not the
// generic string handling the loop above uses.
$receiptNoFields = [
    'receipt_no_prefix'      => ['label' => 'Prefix',           'maxlen' => 50, 'required' => true],
    'receipt_no_year_format' => ['label' => 'Year Format',      'maxlen' => 10, 'required' => true, 'type' => 'php_date_format'],
    'receipt_no_pad_length'  => ['label' => 'Sequence Digits',  'maxlen' => 2,  'required' => true, 'type' => 'digits'],
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();

    $errors = [];
    $clean  = [];

    foreach ($fields as $key => $def) {
        $raw = trim(Sanitize::string($_POST[$key] ?? '', $def['maxlen']));

        if ($raw === '' && $def['required']) {
            $errors[] = $def['label'] . ' is required.';
            continue;
        }

        if ($raw !== '' && ($def['type'] ?? '') === 'email') {
            $validated = Sanitize::email($raw);
            if ($validated === false) {
                $errors[] = $def['label'] . ' is not a valid email address.';
                continue;
            }
            $raw = $validated;
        }

        if ($raw !== '' && ($def['type'] ?? '') === 'url') {
            if (!preg_match('#^https?://#i', $raw)) {
                $raw = 'https://' . $raw;
            }
            if (filter_var($raw, FILTER_VALIDATE_URL) === false) {
                $errors[] = $def['label'] . ' is not a valid URL.';
                continue;
            }
        }

        $clean[$key] = $raw;
    }

    foreach ($receiptNoFields as $key => $def) {
        $raw = trim(Sanitize::string($_POST[$key] ?? '', $def['maxlen']));

        if ($raw === '') {
            $errors[] = $def['label'] . ' is required.';
            continue;
        }

        if (($def['type'] ?? '') === 'digits') {
            if (!preg_match('/^\d{1,2}$/', $raw) || (int) $raw < 1 || (int) $raw > 10) {
                $errors[] = $def['label'] . ' must be a number from 1 to 10.';
                continue;
            }
        }

        if (($def['type'] ?? '') === 'php_date_format') {
            if (!preg_match('/^[A-Za-z]{1,10}$/', $raw)) {
                $errors[] = $def['label'] . ' may only contain letters (a PHP date() format, e.g. Y or y).';
                continue;
            }
        }

        $clean[$key] = $raw;
    }

    // ---- Receipt letterhead logo uploads (optional) ----
    // Stored as system_settings values (paths relative to public_html/)
    // rather than a fixed filename, since the uploaded file's own
    // extension is kept -- see Receipt::resolveLogoPath(), which falls
    // back to the bundled assets/images/receipt-logo-*.png when empty.
    $logoFields  = ['logo_left' => 'receipt_logo_left', 'logo_right' => 'receipt_logo_right'];
    $oldLogoPaths = [];

    foreach ($logoFields as $inputName => $settingKey) {
        $file = $_FILES[$inputName] ?? null;
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue; // no file chosen for this field -- leave the existing logo as-is
        }

        $uploadErrors = Sanitize::fileUpload(
            $file,
            ['image/png', 'image/jpeg', 'image/gif', 'image/webp'],
            MAX_UPLOAD_BYTES
        );
        if (!empty($uploadErrors)) {
            $errors[] = ucfirst(str_replace('_', ' ', $inputName)) . ': ' . implode(' ', $uploadErrors);
            continue;
        }

        $safeName   = Sanitize::safeUploadFilename($file['name']);
        $destAbs    = PUBLIC_HTML . '/assets/images/' . $safeName;
        $destRel    = 'assets/images/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
            $errors[] = 'Could not save the uploaded ' . str_replace('_', ' ', $inputName) . '.';
            continue;
        }

        // Only queue the previous file for deletion if it was itself an
        // earlier admin upload (same assets/images/ dir, random hex name)
        // -- never the bundled default receipt-logo-left/right.png.
        $previous = trim(Settings::get($settingKey, ''));
        if ($previous !== '' && $previous !== 'assets/images/receipt-logo-left.png'
            && $previous !== 'assets/images/receipt-logo-right.png') {
            $oldLogoPaths[] = PUBLIC_HTML . '/' . ltrim($previous, '/');
        }

        $clean[$settingKey] = $destRel;
    }

    if (empty($errors)) {
        try {
            Database::transaction(function () use ($clean, $currentUserId) {
                foreach ($clean as $key => $value) {
                    Database::execute(
                        "INSERT INTO system_settings (setting_key, setting_value, setting_type, updated_by)
                         VALUES (?, ?, 'string', ?)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)",
                        [$key, $value, $currentUserId]
                    );
                }
                AuditLogger::log('UPDATE', 'system_settings', null, null, $clean);
            });

            foreach ($oldLogoPaths as $oldPath) {
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }

            Session::flash('success', 'Association settings updated.');
        } catch (Throwable $e) {
            error_log('[KSPDOWA][admin/settings] update failed: ' . $e->getMessage());
            Session::flash('error', 'Could not save settings. Please try again.');
        }
    } else {
        Session::flash('error', implode(' ', $errors));
    }

    header('Location: /admin/settings.php');
    exit;
}

$current = Settings::all();
$pageTitle   = 'Association Settings';
$activeMenu  = 'settings';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/index.php'],
    ['label' => 'System & Settings', 'url' => '/admin/settings.php'],
    ['label' => 'Settings', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/admin-header.php';
?>
<style>
    .panel {
        background: #fff; border-radius: 8px; box-shadow: 0 1px 6px rgba(26,58,107,0.08);
        padding: 20px; margin-bottom: 24px;
    }
    .panel h2 { font-size: 1rem; color: #1a3a6b; margin: 0 0 4px; }
    .panel .section-hint { font-size: 0.78rem; color: #888; margin: 0 0 16px; }
    .msg { padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 16px; }
    .msg.success { background: #e7f6ec; color: #1e6b3a; border: 1px solid #b9e5c6; }
    .msg.error   { background: #fdecea; color: #a12622; border: 1px solid #f5c2be; }
    label { display: block; font-size: 0.8rem; font-weight: 600; color: #33415c; margin: 12px 0 4px; }
    input[type="text"], input[type="email"], input[type="number"], textarea {
        width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem; font-family: inherit;
    }
    textarea { resize: vertical; min-height: 60px; }
    .logo-row { display: flex; gap: 24px; flex-wrap: wrap; margin-top: 8px; }
    .logo-row > div { flex: 1; min-width: 160px; }
    .logo-preview {
        display: block; width: 100px; height: 100px; object-fit: contain;
        border: 1px solid #e2e6ec; border-radius: 6px; background: #fafbfc; margin: 6px 0 8px;
    }
    input[type="file"] { font-size: 0.85rem; }
    button, .btn {
        background: #1a3a6b; color: #fff; border: none; border-radius: 6px;
        padding: 10px 18px; font-size: 0.9rem; font-weight: 600; cursor: pointer; margin-top: 18px;
    }
    button:hover, .btn:hover { background: #142c52; }
</style>

    <?php
    $leftLogoRel  = $current['receipt_logo_left']  ?? '';
    $rightLogoRel = $current['receipt_logo_right'] ?? '';
    $leftLogoRel  = $leftLogoRel  !== '' ? $leftLogoRel  : 'assets/images/receipt-logo-left.png';
    $rightLogoRel = $rightLogoRel !== '' ? $rightLogoRel : 'assets/images/receipt-logo-right.png';

    $receiptPreviewPrefix     = $current['receipt_no_prefix']      ?? 'KSPDOWA-RCP';
    $receiptPreviewYearFormat = $current['receipt_no_year_format'] ?? 'Y';
    $receiptPreviewPadLength  = (int) ($current['receipt_no_pad_length'] ?? 5);
    $receiptPreviewPadLength  = $receiptPreviewPadLength >= 1 && $receiptPreviewPadLength <= 10 ? $receiptPreviewPadLength : 5;
    $receiptPreviewYear       = @date($receiptPreviewYearFormat) ?: date('Y');
    $receiptPreviewExample    = sprintf('%s-%s-%0' . $receiptPreviewPadLength . 'd', $receiptPreviewPrefix, $receiptPreviewYear, 1);
    ?>

    <div class="panel">
        <h2>Association Identity &amp; Contact</h2>
        <p class="section-hint">Used on the public website and on generated payment receipts (letterhead, contact line).</p>

        <form method="post" action="/admin/settings.php" enctype="multipart/form-data">
            <?= CSRF::htmlField() ?>

            <?php foreach ($fields as $key => $def): ?>
                <label for="<?= Sanitize::attr($key) ?>"><?= Sanitize::html($def['label']) ?><?= $def['required'] ? ' *' : '' ?></label>
                <?php if (!empty($def['textarea'])): ?>
                    <textarea id="<?= Sanitize::attr($key) ?>" name="<?= Sanitize::attr($key) ?>" maxlength="<?= (int) $def['maxlen'] ?>"><?= Sanitize::html($current[$key] ?? '') ?></textarea>
                <?php else: ?>
                    <input
                        type="<?= ($def['type'] ?? '') === 'email' ? 'email' : 'text' ?>"
                        id="<?= Sanitize::attr($key) ?>"
                        name="<?= Sanitize::attr($key) ?>"
                        maxlength="<?= (int) $def['maxlen'] ?>"
                        value="<?= Sanitize::attr($current[$key] ?? '') ?>"
                        <?= $def['required'] ? 'required' : '' ?>
                    >
                <?php endif; ?>
            <?php endforeach; ?>

            <hr style="margin:24px 0 8px; border:none; border-top:1px solid #eef1f5;">
            <h2 style="margin-top:0;">Receipt Letterhead Logos</h2>
            <p class="section-hint">PNG, JPEG, GIF or WebP, up to <?= (int) (MAX_UPLOAD_BYTES / (1024 * 1024)) ?> MB. Leave blank to keep the current logo.</p>

            <div class="logo-row">
                <div>
                    <label for="logo_left">Left Logo</label>
                    <img class="logo-preview" src="/<?= Sanitize::attr($leftLogoRel) ?>" alt="Current left logo">
                    <input type="file" id="logo_left" name="logo_left" accept="image/png,image/jpeg,image/gif,image/webp">
                </div>
                <div>
                    <label for="logo_right">Right Logo</label>
                    <img class="logo-preview" src="/<?= Sanitize::attr($rightLogoRel) ?>" alt="Current right logo">
                    <input type="file" id="logo_right" name="logo_right" accept="image/png,image/jpeg,image/gif,image/webp">
                </div>
            </div>

            <hr style="margin:24px 0 8px; border:none; border-top:1px solid #eef1f5;">
            <h2 style="margin-top:0;">Receipt Numbering</h2>
            <p class="section-hint">
                Format: PREFIX-YEAR-SEQUENCE. Example with current values:
                <strong><?= Sanitize::html($receiptPreviewExample) ?></strong>
            </p>

            <?php foreach ($receiptNoFields as $key => $def): ?>
                <label for="<?= Sanitize::attr($key) ?>"><?= Sanitize::html($def['label']) ?> *</label>
                <input
                    type="<?= ($def['type'] ?? '') === 'digits' ? 'number' : 'text' ?>"
                    id="<?= Sanitize::attr($key) ?>"
                    name="<?= Sanitize::attr($key) ?>"
                    maxlength="<?= (int) $def['maxlen'] ?>"
                    <?= ($def['type'] ?? '') === 'digits' ? 'min="1" max="10"' : '' ?>
                    value="<?= Sanitize::attr($current[$key] ?? '') ?>"
                    required
                >
            <?php endforeach; ?>
            <p class="section-hint">Year Format is a PHP date() format (letters only) &mdash; "Y" gives a 4-digit year like 2026, "y" gives 2 digits like 26. Sequence Digits is how many digits the running number is padded to (5 gives 00001).</p>

            <button type="submit">Save Settings</button>
        </form>
    </div>

<?php
require_once dirname(__DIR__) . '/includes/partials/admin-footer.php';

