<?php
/**
 * KSPDOWA — Member: Forced Temporary Password Change
 * ============================================================
 * Approved Phase 3 spec: "On first login, force the member to
 * change/reset the temporary password before accessing the member
 * portal." This page is that forced step, and ONLY that step -- it
 * is not a general self-service password-change screen and not the
 * generic password-reset system the spec explicitly excludes from
 * this unit.
 *
 * Reached from login.php immediately after a successful password
 * check when users.must_change_password = 1. Requires an active
 * login (Auth::requireLogin()) but does not require member-portal
 * eligibility to reach THIS page specifically -- the whole point of
 * the temporary password is to get the member far enough to set a
 * real one; eligibility is re-checked once the change succeeds,
 * before they are sent on to the portal.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();

$userId   = Auth::getCurrentUserId();
$memberId = Auth::getCurrentMemberId();

// This page is for member accounts on a temporary password only.
if ($memberId === null) {
    header('Location: /admin/office-bearers.php');
    exit;
}

$userRow = Database::fetchOne('SELECT must_change_password FROM users WHERE id = ?', [$userId]);
if (!$userRow || (int) $userRow['must_change_password'] !== 1) {
    // Nothing to force -- send them to the portal (which will itself
    // re-check eligibility).
    header('Location: /member/index.php');
    exit;
}

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();

    $newPassword     = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    $errors = Sanitize::password($newPassword);

    if ($newPassword !== $confirmPassword) {
        $errors[] = 'The two passwords do not match.';
    }

    if (empty($errors)) {
        Database::execute(
            'UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = NOW() WHERE id = ?',
            [Auth::hashPassword($newPassword), $userId]
        );
        AuditLogger::log('PASSWORD_CHANGE', 'users', $userId);

        // Re-check eligibility now that the forced step is done -- if
        // it somehow lapsed between activation and this moment, don't
        // grant portal access.
        $currentYear = Membership::getCurrentYear();
        if ($currentYear === null || !Membership::isEligibleForYear($memberId, (int) $currentYear['id'])) {
            Session::destroy();
            Session::flash('error', 'Your password has been updated, but your current-year annual membership fee has not been verified yet.');
            header('Location: /member-login.php');
            exit;
        }

        Session::flash('success', 'Your password has been updated.');
        header('Location: /member/index.php');
        exit;
    }
}

$pageTitle = 'Change Password';
require dirname(__DIR__) . '/includes/partials/header.php';
?>

<h1 class="page-title">Set Your Password</h1>
<p class="page-subtitle">You're signing in with a temporary password. Please choose your own password to continue.</p>

<div class="card form-narrow">
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error" role="alert">
            <?= implode('<br>', array_map('Sanitize::html', $errors)) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="/member/change-password.php" autocomplete="off">
        <?= CSRF::htmlField() ?>
        <div class="form-group">
            <label for="new_password">New Password</label>
            <input type="password" id="new_password" name="new_password" required minlength="8">
        </div>
        <div class="form-group">
            <label for="confirm_password">Confirm New Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
        </div>
        <p class="form-hint">At least 8 characters, with an uppercase letter, a lowercase letter, and a digit.</p>
        <button type="submit" class="btn" style="width:100%; justify-content:center;">Set Password</button>
    </form>
</div>

<?php require dirname(__DIR__) . '/includes/partials/footer.php'; ?>
