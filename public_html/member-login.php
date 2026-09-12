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
 * Deliberately NOT built here (out of scope for this unit): the new
 * local geography-aware payment form (waiting on official Gram
 * Panchayati master data), the Razorpay webhook/verification
 * integration, and bulk import. Until those exist, "the payment form"
 * is the existing hosted Razorpay page the association already uses.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// Already an active session? Send them where that account belongs.
if (Auth::isLoggedIn()) {
    header('Location: ' . (Auth::getCurrentMemberId() !== null ? '/member/index.php' : '/admin/office-bearers.php'));
    exit;
}

const RAZORPAY_ANNUAL_FEE_URL = 'https://pages.razorpay.com/KSPDOWAFEE2026';

$error        = Session::getFlash('error');
$notice       = Session::getFlash('notice');
$notEligible  = false;
$alreadyExists = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();

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
        } else {
            $existingUser = Database::fetchOne('SELECT id FROM users WHERE member_id = ?', [$member['id']]);

            if ($existingUser) {
                $alreadyExists = true;
            } else {
                $tempPassword = Auth::generateTemporaryPassword();
                $passwordHash = Auth::hashPassword($tempPassword);

                $newUserId = Database::transaction(function () use ($member, $email, $passwordHash) {
                    Database::execute(
                        'INSERT INTO users (member_id, email, password_hash, status, must_change_password)
                         VALUES (?, ?, ?, ?, 1)',
                        [$member['id'], $email, $passwordHash, 'active']
                    );
                    $userId = (int) Database::lastInsertId();

                    $role = Database::fetchOne("SELECT id FROM roles WHERE name = 'Regular Member'");
                    if ($role) {
                        Database::execute(
                            'INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)',
                            [$userId, (int) $role['id']]
                        );
                    }

                    AuditLogger::log('ACTIVATE', 'users', $userId, null, [
                        'member_id' => $member['id'],
                        'email'     => $email,
                        'source'    => 'member_login_self_activation',
                    ]);

                    return $userId;
                });

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
            If you are a member, please complete (or renew) your annual membership fee payment below,
            and try again once it has been confirmed.
        </div>
        <p style="text-align:center; margin-top:var(--space-4);">
            <a class="btn btn-teal" href="<?= Sanitize::attr(RAZORPAY_ANNUAL_FEE_URL) ?>" target="_blank" rel="noopener">
                Pay Annual Membership Fee
            </a>
        </p>
        <p class="form-hint" style="text-align:center;">Already paid? It can take a little time to verify -- please try again shortly, or contact the association office.</p>
    <?php elseif ($alreadyExists): ?>
        <div class="alert alert-info" role="alert">
            An account already exists for that email address.
        </div>
        <p style="text-align:center; margin-top:var(--space-4);">
            <a class="btn" href="/login.php">Go to Sign In</a>
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
