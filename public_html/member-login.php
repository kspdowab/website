<?php
/**
 * KSPDOWA — Member Portal Access & Identification
 * ============================================================
 * Allows members to identify their account using KGID No., Mobile No.,
 * or Registered Email. If membership is active and a password exists,
 * they sign in directly. If first-time or no password set, they are guided
 * to create a secure personal password via one-time token link.
 * If unpaid, they are guided to complete their fee.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// Already an active session? Send them where that account belongs.
if (Auth::isLoggedIn()) {
    header('Location: ' . (Auth::getCurrentMemberId() !== null ? '/member/index.php' : '/admin/index.php'));
    exit;
}

$error                = Session::getFlash('error');
$notice               = Session::getFlash('notice');
$notEligible          = false;
$resumeEmailValue     = '';
$setupPasswordMember  = null;
$setupMaskedEmail     = '';
$setupHasEmail        = false;
$setupLoginIdentifier = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();

    $formAction = (string)($_POST['form_action'] ?? '');

    if ($formAction === 'send_setup_link') {
        $targetMemberId = Sanitize::positiveInt($_POST['member_id'] ?? null);
        if ($targetMemberId) {
            $res = Auth::sendPasswordResetLinkForMember($targetMemberId);
            if ($res['success']) {
                if (!empty($res['email_sent'])) {
                    Session::flash('notice', "A secure password setup link has been sent to your registered email ({$res['masked_email']}). Please check your inbox (and spam folder) and click the link to create your password.");
                } else {
                    if (defined('APP_ENV') && APP_ENV === 'development') {
                        Session::flash('notice', "Password setup link generated: " . $res['reset_link']);
                    } else {
                        Session::flash('error', "Unable to send the email to your registered address at this moment. Please contact the association administrator.");
                    }
                }
            } else {
                Session::flash('error', $res['error'] ?? 'Could not generate password setup link.');
            }
        }
        header('Location: /member-login.php');
        exit;
    } elseif ($formAction === 'update_email_and_send') {
        $targetMemberId = Sanitize::positiveInt($_POST['member_id'] ?? null);
        $newEmail       = trim((string)($_POST['new_email'] ?? ''));
        $verifyMobile   = trim((string)($_POST['verify_mobile'] ?? ''));

        if (!$targetMemberId || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            Session::flash('error', 'Please provide a valid email address.');
            header('Location: /member-login.php');
            exit;
        }

        $profile = Database::fetchOne('SELECT personal_mobile, kgid_no FROM member_profiles WHERE member_id = ?', [$targetMemberId]);
        $cleanEnteredMobile = preg_replace('/\D/', '', $verifyMobile);
        $cleanStoredMobile  = preg_replace('/\D/', '', (string)($profile['personal_mobile'] ?? ''));

        if ($cleanStoredMobile !== '' && $cleanEnteredMobile !== $cleanStoredMobile) {
            Session::flash('error', 'Mobile number verification failed. Please enter the mobile number registered with your membership.');
            header('Location: /member-login.php');
            exit;
        }

        // Update email in profile and users
        Database::execute('UPDATE member_profiles SET personal_email = ? WHERE member_id = ?', [$newEmail, $targetMemberId]);
        $existingEmailUser = Database::fetchOne('SELECT id FROM users WHERE email = ? AND member_id != ?', [$newEmail, $targetMemberId]);
        if (!$existingEmailUser) {
            Database::execute('UPDATE users SET email = ? WHERE member_id = ?', [$newEmail, $targetMemberId]);
        }

        $res = Auth::sendPasswordResetLinkForMember($targetMemberId, $newEmail);
        if ($res['success'] && !empty($res['email_sent'])) {
            Session::flash('notice', "Your registered email has been updated and a secure password setup link has been sent to {$newEmail}. Please check your inbox to create your password.");
        } else {
            Session::flash('notice', "Your registered email has been updated. Please check your inbox or request a link from the sign in page.");
        }
        header('Location: /member-login.php');
        exit;
    } elseif ($formAction === 'resume') {
        $emailInput       = trim((string)($_POST['email'] ?? ''));
        $kgidInput        = trim(Sanitize::string($_POST['kgid_no'] ?? '', 50));
        $resumeEmailValue = $emailInput;

        // Check if this KGID belongs to a registered member
        $member = null;
        if ($kgidInput !== '') {
            $member = Database::fetchOne(
                "SELECT m.*, mp.kgid_no, mp.personal_mobile, mp.personal_email
                 FROM members m
                 JOIN member_profiles mp ON mp.member_id = m.id
                 WHERE mp.kgid_no = ? LIMIT 1",
                [$kgidInput]
            );
        }

        if ($member) {
            $memberId = (int)$member['id'];
            $kgid = (string)$member['kgid_no'];

            // If user provided a valid new email, update it
            if ($emailInput !== '' && filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
                Database::execute(
                    "UPDATE member_profiles SET personal_email = ? WHERE member_id = ?",
                    [$emailInput, $memberId]
                );
                $existingEmailUser = Database::fetchOne("SELECT id FROM users WHERE email = ? AND member_id != ?", [$emailInput, $memberId]);
                if (!$existingEmailUser) {
                    Database::execute(
                        "UPDATE users SET email = ? WHERE member_id = ?",
                        [$emailInput, $memberId]
                    );
                }
            }

            // Ensure user account exists securely
            Auth::ensureUserAccountForMember($memberId);

            // Check if current-year annual fee is completed
            if (Membership::isCurrentYearEligible($memberId)) {
                $userRow = Database::fetchOne("SELECT must_change_password, email FROM users WHERE member_id = ?", [$memberId]);
                $needsSetup = !$userRow || (int)$userRow['must_change_password'] === 1;

                if (!$needsSetup) {
                    Session::flash('notice', "Welcome back! Your annual membership fee is verified and active. Please sign in with your password.");
                    header('Location: /login.php?identifier=' . rawurlencode($kgid));
                    exit;
                } else {
                    $setupPasswordMember  = $member;
                    $regEmail             = trim((string)($member['personal_email'] ?: ($userRow['email'] ?? '')));
                    $setupMaskedEmail     = Auth::maskEmail($regEmail);
                    $setupHasEmail        = ($regEmail !== '' && filter_var($regEmail, FILTER_VALIDATE_EMAIL) !== false);
                    $setupLoginIdentifier = $kgid;
                }
            } else {
                $resume = ($emailInput !== '') ? Registration::resumePendingPayment($emailInput, $kgid) : null;
                if ($resume !== null) {
                    Session::set('registration_payment_id', $resume['payment_id']);
                    Session::set('registration_member_id', $resume['member_id']);
                    header('Location: /payment.php');
                    exit;
                } else {
                    Session::flash('error', 'Your annual membership fee for the current year is pending. Please use the registration/payment form to complete payment.');
                    header('Location: /payment.php');
                    exit;
                }
            }
        } else {
            $notEligible = true;
        }
    } else {
        // Main identification lookup (KGID No., Mobile No., or Email)
        $inputVal = trim((string)($_POST['identifier'] ?? $_POST['email'] ?? ''));

        if ($inputVal === '') {
            $error = 'Please enter your KGID No., Mobile No., or Registered Email Address.';
        } else {
            $cleanInput = Sanitize::string($inputVal, 190);

            // Find member by KGID, Mobile, Email, or Member No.
            $member = Database::fetchOne(
                "SELECT m.*, mp.kgid_no, mp.personal_mobile, mp.personal_email
                 FROM members m
                 JOIN member_profiles mp ON mp.member_id = m.id
                 LEFT JOIN users u ON u.member_id = m.id
                 WHERE mp.kgid_no = ? 
                    OR mp.personal_mobile = ? 
                    OR mp.personal_email = ? 
                    OR u.email = ? 
                    OR u.username = ? 
                    OR u.mobile = ? 
                    OR m.member_no = ?
                 LIMIT 1",
                [$cleanInput, $cleanInput, $cleanInput, $cleanInput, $cleanInput, $cleanInput, $cleanInput]
            );

            if ($member === false) {
                // Not found — allow secondary lookup by KGID
                $notEligible = true;
                $resumeEmailValue = $cleanInput;
            } else {
                $memberId = (int)$member['id'];
                $kgid = (string)($member['kgid_no'] ?? '');

                // Ensure user account exists securely
                Auth::ensureUserAccountForMember($memberId);

                // Check eligibility for current year
                if (Membership::isCurrentYearEligible($memberId)) {
                    $userRow = Database::fetchOne("SELECT must_change_password, email FROM users WHERE member_id = ?", [$memberId]);
                    $needsSetup = !$userRow || (int)$userRow['must_change_password'] === 1;

                    $loginIdentifier = $kgid !== '' ? $kgid : ($member['personal_mobile'] ?? $member['personal_email'] ?? $cleanInput);

                    if (!$needsSetup) {
                        Session::flash('notice', "Account verified for " . ($member['name'] ?? 'Member') . "! Please enter your password to sign in.");
                        header('Location: /login.php?identifier=' . rawurlencode($loginIdentifier));
                        exit;
                    } else {
                        // Needs password setup
                        $setupPasswordMember  = $member;
                        $regEmail             = trim((string)($member['personal_email'] ?: ($userRow['email'] ?? '')));
                        $setupMaskedEmail     = Auth::maskEmail($regEmail);
                        $setupHasEmail        = ($regEmail !== '' && filter_var($regEmail, FILTER_VALIDATE_EMAIL) !== false);
                        $setupLoginIdentifier = $loginIdentifier;
                    }
                } else {
                    $notEligible = true;
                    $resumeEmailValue = (string)($member['personal_email'] ?? $cleanInput);
                }
            }
        }
    }
}

$pageTitle = 'Member Portal Access';
require __DIR__ . '/includes/partials/header.php';
?>

<span class="eyebrow">Member Services</span>
<h1 class="page-title">Member Portal Access</h1>
<p class="page-subtitle">Verify your membership account to access member services, receipts, and digital ID card.</p>

<div class="card form-narrow">
    <!-- Direct Sign In Callout -->
    <div style="background:#e0f2fe; border:1px solid #7dd3fc; border-radius:8px; padding:12px 16px; margin-bottom:20px; text-align:center;">
        <div style="font-weight:700; color:#0369a1; font-size:0.95rem; margin-bottom:4px;">🔑 Already have your Password?</div>
        <p style="margin:0 0 10px; font-size:0.85rem; color:#0c4a6e;">
            Sign in directly using your <strong>KGID Number</strong>, <strong>Mobile Number</strong>, or <strong>Email</strong>.
        </p>
        <a href="/login.php" class="btn" style="background:#0284c7; color:#fff; display:inline-block; font-size:0.85rem; padding:6px 18px; text-decoration:none;">
            Go to Direct Sign In &rarr;
        </a>
    </div>

    <?php if ($notice !== null): ?>
        <div class="alert alert-success" role="status"><?= Sanitize::html($notice) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert alert-error" role="alert"><?= Sanitize::html($error) ?></div>
    <?php endif; ?>

    <?php if ($setupPasswordMember !== null): ?>
        <!-- Password Setup Card for First-Time / Eligible Members -->
        <div style="background:#ECFDF5; border:1px solid #A7F3D0; border-radius:8px; padding:16px; margin-bottom:18px;">
            <div style="font-weight:700; color:#065F46; font-size:1rem; margin-bottom:4px;">✅ Account Verified</div>
            <div style="font-size:0.88rem; color:#047857; line-height:1.4;">
                Welcome, <strong><?= Sanitize::html($setupPasswordMember['name']) ?></strong> (KGID: <?= Sanitize::html($setupPasswordMember['kgid_no'] ?? 'N/A') ?>).
            </div>
        </div>

        <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:8px; padding:18px; margin-bottom:18px;">
            <div style="font-weight:700; color:#1E293B; font-size:0.98rem; margin-bottom:6px;">🔒 Create Your Personal Password</div>
            <p style="margin:0 0 14px; font-size:0.85rem; color:#475569; line-height:1.5;">
                For account security, default passwords are no longer used. Please use the secure one-time link sent to your registered contact to create your personal password.
            </p>

            <?php if ($setupHasEmail): ?>
                <p style="margin:0 0 14px; font-size:0.88rem; color:#0F172A;">
                    Registered Email: <strong><?= Sanitize::html($setupMaskedEmail) ?></strong>
                </p>
                <form method="post" action="/member-login.php">
                    <?= CSRF::htmlField() ?>
                    <input type="hidden" name="form_action" value="send_setup_link">
                    <input type="hidden" name="member_id" value="<?= (int)$setupPasswordMember['id'] ?>">
                    <button type="submit" class="btn" style="width:100%; justify-content:center; padding:12px; font-weight:600;">
                        Send Secure Password Setup Link &rarr;
                    </button>
                </form>
            <?php else: ?>
                <div class="alert alert-warning" style="margin-bottom:14px; font-size:0.85rem;">
                    No registered email is on file for your record. Please provide your email address and verify with your registered mobile number:
                </div>
                <form method="post" action="/member-login.php">
                    <?= CSRF::htmlField() ?>
                    <input type="hidden" name="form_action" value="update_email_and_send">
                    <input type="hidden" name="member_id" value="<?= (int)$setupPasswordMember['id'] ?>">
                    <div class="form-group" style="margin-bottom:12px;">
                        <label for="new_email">Active Email Address</label>
                        <input type="email" id="new_email" name="new_email" required placeholder="your.name@gmail.com">
                    </div>
                    <div class="form-group" style="margin-bottom:16px;">
                        <label for="verify_mobile">Confirm Registered Mobile Number</label>
                        <input type="tel" id="verify_mobile" name="verify_mobile" required placeholder="Enter registered 10-digit mobile number">
                    </div>
                    <button type="submit" class="btn" style="width:100%; justify-content:center; padding:12px; font-weight:600;">
                        Verify &amp; Send Setup Link &rarr;
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <p class="form-hint" style="text-align:center; margin-top:var(--space-4);">
            Already have your password? <a href="/login.php?identifier=<?= rawurlencode($setupLoginIdentifier) ?>">Sign in directly &rarr;</a>
        </p>
    <?php elseif ($notEligible): ?>
        <div class="alert alert-info" role="alert">
            We couldn't verify a current-year annual membership payment for that identifier yet.
        </div>

        <form method="post" action="/member-login.php" autocomplete="off">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="form_action" value="resume">
            <input type="hidden" name="email" value="<?= Sanitize::attr($resumeEmailValue) ?>">
            <div class="form-group">
                <label for="kgid_no">Enter Your KGID No.</label>
                <input type="text" id="kgid_no" name="kgid_no" required autofocus maxlength="50" inputmode="numeric" pattern="[0-9]+" title="KGID No. must contain only numeric digits"
                       value="<?= Sanitize::attr($_POST['kgid_no'] ?? '') ?>" placeholder="Enter your KGID number">
            </div>
            <p class="form-hint">
                Enter your <strong>KGID No.</strong> to locate your membership records and activate access.
            </p>
            <button type="submit" class="btn" style="width:100%; justify-content:center;">Verify KGID &amp; Access Portal</button>
        </form>

        <p class="form-hint" style="text-align:center; margin-top:var(--space-4);">
            New member, or need to register afresh?
            <a href="/register.php">Use the full registration form</a>.
        </p>
    <?php else: ?>
        <form method="post" action="/member-login.php" autocomplete="off">
            <?= CSRF::htmlField() ?>
            <div class="form-group">
                <label for="identifier">KGID No., Mobile No., or Registered Email</label>
                <input type="text" id="identifier" name="identifier" required autofocus maxlength="190"
                       value="<?= Sanitize::attr($_POST['identifier'] ?? $_POST['email'] ?? '') ?>"
                       placeholder="Enter KGID No., Mobile No., or Email">
            </div>
            <button type="submit" class="btn" style="width:100%; justify-content:center;">Continue &rarr;</button>
        </form>
        <p class="form-hint" style="text-align:center; margin-top:var(--space-4);">
            <a href="/login.php">Direct Sign In</a> &bull; <a href="/forgot-password.php">Forgot password?</a> &bull; <a href="/register.php">New Registration</a>
        </p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
