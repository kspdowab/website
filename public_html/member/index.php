<?php
/**
 * KSPDOWA — Member Portal (landing page)
 * ============================================================
 * Minimal Phase 3 foundation landing page for an authenticated,
 * current-year-eligible member. Deliberately small: this unit is
 * about the authentication/eligibility gate itself, not the full
 * member portal feature set (profile editing, grievances, payment
 * history, etc. -- those are separate, later Phase 3+ units).
 *
 * Independently re-checks must_change_password and current-year
 * eligibility (defense in depth) rather than relying solely on
 * login.php having checked them at login time -- e.g. a member could
 * bookmark this URL, or an admin could change the current financial
 * year mid-session.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();

$userId   = Auth::getCurrentUserId();
$memberId = Auth::getCurrentMemberId();

if ($memberId === null) {
    // Not a member account -- this area is not for admin/officer logins.
    header('Location: /admin/office-bearers.php');
    exit;
}

$userRow = Database::fetchOne('SELECT must_change_password FROM users WHERE id = ?', [$userId]);
if ($userRow && (int) $userRow['must_change_password'] === 1) {
    header('Location: /member/change-password.php');
    exit;
}

$currentYear = Membership::getCurrentYear();
if ($currentYear === null || !Membership::isEligibleForYear($memberId, (int) $currentYear['id'])) {
    AuditLogger::log('LOGIN_DENIED_INELIGIBLE', 'users', $userId);
    Session::destroy();
    Session::flash('error', 'Your current-year annual membership fee has not been verified yet. Please complete payment to access the member portal.');
    header('Location: /member-login.php');
    exit;
}

$member  = Database::fetchOne('SELECT * FROM members WHERE id = ?', [$memberId]);
$profile = Database::fetchOne('SELECT * FROM member_profiles WHERE member_id = ?', [$memberId]);
$success = Session::getFlash('success');

$pageTitle = 'Member Portal';
require dirname(__DIR__) . '/includes/partials/header.php';
?>

<h1 class="page-title">Welcome, <?= Sanitize::html($member['name'] ?? '') ?></h1>
<p class="page-subtitle">Member portal — <?= Sanitize::html(APP_SHORT_NAME) ?></p>

<?php if ($success !== null): ?>
    <div class="alert alert-success" role="status"><?= Sanitize::html($success) ?></div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title">Your Membership</h2>
    <table class="plain">
        <tr><th>Member No.</th><td><?= Sanitize::html($member['member_no'] ?? '') ?></td></tr>
        <tr><th>Designation</th><td><?= Sanitize::html($member['designation'] ?? '') ?></td></tr>
        <tr><th>Registered Email</th><td><?= Sanitize::html($profile['personal_email'] ?? '') ?></td></tr>
        <tr><th>Current Financial Year</th><td><?= Sanitize::html($currentYear['financial_year']) ?></td></tr>
        <tr><th>Status</th><td><span class="badge badge-green">Current-year membership verified</span></td></tr>
    </table>
</div>

<p><a href="/logout.php" class="btn btn-outline">Log Out</a></p>

<?php require dirname(__DIR__) . '/includes/partials/footer.php'; ?>
