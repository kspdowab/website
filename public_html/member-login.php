<?php
/**
 * KSPDOWA — Member Portal Access (activation / eligibility gate)
 * ============================================================
 * Approved Phase 3 spec, "AUTHENTICATION + ANNUAL MEMBERSHIP":
 *
 *   - A member reaches the portal by registered email, not by
 *     self-registering a password.
 *   - If that email belongs to a member who has a server-verified
 *     CURRENT YEAR payment, a login account is created (if one
 *     doesn't already exist) with a system-generated temporary
 *     password emailed to them, OR -- if an account already exists
 *     -- they are told to sign in normally. Either way NO account is
 *     ever created just because this form was submitted; it is only
 *     created after Membership::findEligibleMemberByEmail() confirms
 *     a completed payment for the current year.
 *   - If the email does not belong to a current-year-eligible member,
 *     nothing is created and the person is pointed at the existing
 *     official annual-fee payment page. The two "not eligible" cases
 *     (email unknown vs. known-but-unpaid) are deliberately shown the
 *     SAME message so this form cannot be used to discover which
 *     email addresses belong to registered members.
 *
 * As of the Razorpay Standard Checkout + Idempotency unit, new
 * registrations pay via the in-app checkout on payment.php, which
 * calls Auth::activateMemberPortalAccess() automatically the moment a
 * payment is server-verified (see PaymentGateway::confirmPayment()) --
 * a member coming from that flow does not need to visit this page at
 * all. This page remains the activation path for the pre-existing
 * hosted Razorpay page (member-initiated renewals for a subsequent
 * year, where no local pending payment row exists yet) and as a
 * self-healing fallback: if automatic activation is ever missed for
 * any reason, entering the same registered email here re-checks
 * eligibility and activates the account exactly once, via the exact
 * same Auth::activateMemberPortalAccess() code path.
 *
 * Bulk import remains out of scope for this unit.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// Already an active session? Send them where that account belongs.
if (Auth::isLoggedIn()) {
    header('Location: ' . (Auth::getCurrentMemberId() !== null ? '/member/index.php' : '/admin/office-bearers.php'));
    exit;
}


$error            = Session::getFlash('error');
$notice           = Session::getFlash('notice');
$notEligible      = false;
$resumeEmailValue = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();

    if (($_POST['form_action'] ?? '') === 'resume') {
        // Lightweight resume for a registered-but-unpaid member: the
        // SAME two-factor (email + KGID No.) check register.php's safe
        // retry path already uses (Registration::resumePendingPayment()),
        // just without re-entering the whole registration form. Never
        // creates a new `members` row -- only reuses/creates a 'pending'
        // payment attempt for an existing member.
        $emailInput       = (string) ($_POST['email'] ?? '');
        $email            = Sanitize::email($emailInput);
        $kgid             = Sanitize::string($_POST['kgid_no'] ?? '', 50);
        $resumeEmailValue = $emailInput;

        $resume = ($email !== false && $kgid !== '')
            ? Registration::resumePendingPayment($email, $kgid)
            : null;

        if ($resume === null) {
            // Deliberately identical to the plain "not eligible" case
            // below -- a wrong KGID, a wrong email, or a fully unknown
            // pair all look the same from here, so this form can never
            // be used to discover which emails/KGID numbers belong to
            // registered members.
            $notEligible = true;
        } else {
            Session::set('registration_payment_id', $resume['payment_id']);
            Session::set('registration_member_id', $resume['member_id']);
            header('Location: /payment.php');
            exit;
        }
    } else {

    $emailInput = (string) ($_POST['email'] ?? '');
    $email      = Sanitize::email($emailInput);

    if ($email === false) {
        $error = 'Please enter a valid email address.';
    } else {
        $member = Membership::findEligibleMemberByEmail($email);

        if ($member === null) {
            // Deliberately identical whether the email is unregistered
            // or belongs to a member who hasn't paid the current year.
            $notEligible = true;
            $resumeEmailValue = $emailInput;
        } else {
            $existingUser = Database::fetchOne('SELECT id FROM users WHERE member_id = ?', [$member['id']]);

            if ($existingUser) {
                // Active account already exists -- no interstitial
                // message needed, send them straight to the password
                // screen with the email already filled in.
                header('Location: /login.php?identifier=' . rawurlencode($email));
                exit;
            } else {
                // Shared with PaymentGateway::confirmPayment() -- see
                // Auth::activateMemberPortalAccess() doc block. Both
                // callers agree on exactly one activation code path, so
                // it is impossible for this form and a Razorpay
                // callback/webhook to ever issue two different temporary
                // passwords for the same member.
                $activation = Auth::activateMemberPortalAccess($member, $email);

                if ($activation['temp_password'] === null) {
                    // Lost a race to a concurrent activation (e.g. a
                    // Razorpay callback/webhook activated this member a
                    // moment ago). Nothing to email -- same as the
                    // "account already exists" case above, send them
                    // straight to the password screen.
                    header('Location: /login.php?identifier=' . rawurlencode($email));
                    exit;
                } else {
                    $tempPassword = $activation['temp_password'];

                    $mailSent = Mailer::send(
                        $email,
                        'Your ' . APP_SHORT_NAME . ' Member Portal Access',
                        "Dear " . $member['name'] . ",\n\n"
                            . "Your " . APP_SHORT_NAME . " member portal account has been activated.\n\n"
                            . "Registered email: " . $email . "\n"
                            . "Temporary password: " . $tempPassword . "\n\n"
                            . "Sign in at " . (defined('BASE_URL') ? BASE_URL : '') . "login.php and you will be "
                            . "asked to set your own password before continuing.\n\n"
                            . "If you did not request this, please contact the association office.\n"
                    );

                    if ($mailSent) {
                        Session::flash('notice', 'An account has been created for ' . Sanitize::html($email)
                            . '. A temporary password has been sent to that email address -- please check your inbox and sign in.');
                    } else {
                        Session::flash('notice', 'Your account was created, but the confirmation email could not be sent. '
                            . 'Please contact the association office for your temporary password.');
                    }

                    header('Location: /member-login.php');
                    exit;
                }
            }
        }
    }
    }
}

$pageTitle = 'Member Portal Access';
require __DIR__ . '/includes/partials/header.php';
?>

<h1 class="page-title">Member Portal Access</h1>
<p class="page-subtitle">Enter the email address registered with your current-year annual membership payment.</p>

<div class="card form-narrow">
    <?php if ($notice !== null): ?>
        <div class="alert alert-success" role="status"><?= Sanitize::html($notice) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert alert-error" role="alert"><?= Sanitize::html($error) ?></div>
    <?php endif; ?>

    <?php if ($notEligible): ?>
        <div class="alert alert-info" role="alert">
            We couldn't find a verified current-year annual membership payment for that email address.
        </div>

        <form method="post" action="/member-login.php" autocomplete="off">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="form_action" value="resume">
            <input type="hidden" name="email" value="<?= Sanitize::attr($resumeEmailValue) ?>">
            <div class="form-group">
                <label for="kgid_no">KGID No.</label>
                <input type="text" id="kgid_no" name="kgid_no" required autofocus maxlength="50"
                       value="<?= Sanitize::attr($_POST['kgid_no'] ?? '') ?>">
            </div>
            <p class="form-hint">
                Already registered? Enter your KGID No. above to resume your existing payment for
                <strong><?= Sanitize::html($resumeEmailValue) ?></strong> -- this will not create a
                duplicate registration.
            </p>
            <button type="submit" class="btn btn-teal" style="width:100%; justify-content:center;">Resume My Payment</button>
        </form>

        <p class="form-hint" style="text-align:center; margin-top:var(--space-4);">
            New here, or need to correct your registration details?
            <a href="/register.php">Use the full registration form</a> instead.
        </p>
        <p class="form-hint" style="text-align:center;">
            Already paid? It can take a little time to verify -- please try again shortly, or contact the association office.
        </p>
    <?php else: ?>
        <form method="post" action="/member-login.php" autocomplete="off">
            <?= CSRF::htmlField() ?>
            <div class="form-group">
                <label for="email">Registered Email Address</label>
                <input type="email" id="email" name="email" required autofocus maxlength="190"
                       value="<?= Sanitize::attr($_POST['email'] ?? '') ?>">
            </div>
            <button type="submit" class="btn" style="width:100%; justify-content:center;">Continue</button>
        </form>
        <p class="form-hint" style="text-align:center; margin-top:var(--space-4);">
            Already have a password? <a href="/login.php">Sign in here</a>.
        </p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
