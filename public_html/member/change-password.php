<?php
/**
 * KSPDOWA — Member Portal: Settings & Password Management
 * ============================================================
 * Section 8: Settings
 * Supports both forced first-time password setup and voluntary
 * password changes for logged-in members.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$userId   = Auth::getCurrentUserId();
$memberId = Auth::getCurrentMemberId();

if ($memberId === null) {
    header('Location: /admin/index.php');
    exit;
}

$userRow = Database::fetchOne('SELECT must_change_password, password_hash FROM users WHERE id = ?', [$userId]);
$isForced = $userRow && (int)$userRow['must_change_password'] === 1;

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();

    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword     = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    // If voluntary change, verify current password
    if (!$isForced) {
        if ($currentPassword === '' || !password_verify($currentPassword, (string)($userRow['password_hash'] ?? ''))) {
            $errors[] = 'Current password is incorrect.';
        }
    }

    $pwdErrors = Sanitize::password($newPassword);
    $errors    = array_merge($errors, $pwdErrors);

    if ($newPassword !== $confirmPassword) {
        $errors[] = 'The two new passwords do not match.';
    }

    if (empty($errors)) {
        Database::execute(
            'UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = NOW() WHERE id = ?',
            [Auth::hashPassword($newPassword), $userId]
        );
        AuditLogger::log('PASSWORD_CHANGE', 'users', $userId);

        $currentYear = Membership::getCurrentYear();
        if ($currentYear === null || !Membership::isEligibleForYear($memberId, (int)$currentYear['id'])) {
            Session::destroy();
            Session::flash('error', 'Your password has been updated, but your current-year annual membership fee has not been verified yet.');
            header('Location: /member-login.php');
            exit;
        }

        Session::flash('success', 'Your password has been updated successfully.');
        header('Location: /member/index.php');
        exit;
    }
}

$pageTitle  = 'Settings';
$activeMenu = 'settings';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/member/index.php'],
    ['label' => 'Settings', 'url' => '']
];

require_once dirname(__DIR__) . '/includes/partials/member-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title"><?= $isForced ? 'Set Your Password' : 'Account Settings' ?></h1>
        <p class="page-heading-subtitle">
            <?= $isForced ? 'You are signing in with a temporary password. Please set a new password to continue.' : 'Manage your login credentials and security settings.' ?>
        </p>
    </div>
</div>

<div class="table-card" style="max-width:580px;">
    <div class="table-card-header">
        <span class="table-card-title"><?= $isForced ? 'Choose New Password' : 'Change Password' ?></span>
    </div>
    <div style="padding:24px;">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <div>
                    <?php foreach ($errors as $e): ?>
                        <div>• <?= Sanitize::html($e) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="post" action="/member/change-password.php">
            <?= CSRF::htmlField() ?>

            <?php if (!$isForced): ?>
            <div class="form-group" style="margin-bottom:18px;">
                <label class="form-label" for="current_password">Current Password *</label>
                <input type="password" name="current_password" id="current_password" class="form-control" required autocomplete="current-password">
            </div>
            <?php endif; ?>

            <div class="form-group" style="margin-bottom:18px;">
                <label class="form-label" for="new_password">New Password *</label>
                <input type="password" name="new_password" id="new_password" class="form-control" required minlength="8" autocomplete="new-password">
                <span style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">
                    Must be at least 8 characters long with a mix of letters and numbers.
                </span>
            </div>

            <div class="form-group" style="margin-bottom:24px;">
                <label class="form-label" for="confirm_password">Confirm New Password *</label>
                <input type="password" name="confirm_password" id="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;">
                Update Password
            </button>
        </form>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
