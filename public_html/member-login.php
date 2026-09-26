<?php
/**
 * KSPDOWA — Member Portal Access & Identification
 * ============================================================
 * Allows members to identify their account using KGID No., Mobile No.,
 * or Registered Email. If membership and payment are active, they are
 * directed to sign in with their password (default Kspdowa@<KGID> on
 * initial login). If unpaid, they are guided to complete their fee.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// Already an active session? Send them where that account belongs.
if (Auth::isLoggedIn()) {
    header('Location: ' . (Auth::getCurrentMemberId() !== null ? '/member/index.php' : '/admin/index.php'));
    exit;
}

$error            = Session::getFlash('error');
$notice           = Session::getFlash('notice');
$notEligible      = false;
$resumeEmailValue = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();

    $formAction = (string)($_POST['form_action'] ?? '');

    if ($formAction === 'resume') {
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
                // Also update users.email if not taken
                $existingEmailUser = Database::fetchOne("SELECT id FROM users WHERE email = ? AND member_id != ?", [$emailInput, $memberId]);
                if (!$existingEmailUser) {
                    Database::execute(
                        "UPDATE users SET email = ? WHERE member_id = ?",
                        [$emailInput, $memberId]
                    );
                }
            }

            // Ensure user account exists
            Auth::resetMemberPasswordToDefault($memberId);

            // Check if current-year annual fee is completed
            if (Membership::isCurrentYearEligible($memberId)) {
                Session::flash('notice', "Welcome back! Your annual membership fee is verified and active. Please sign in with your KGID No. ({$kgid}) and password. (Default initial password: Kspdowa@{$kgid})");
                header('Location: /login.php?identifier=' . rawurlencode($kgid));
                exit;
            } else {
                // Check if pending payment attempt exists to resume
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

                // Ensure user account exists
                Auth::resetMemberPasswordToDefault($memberId);

                // Check eligibility for current year
                if (Membership::isCurrentYearEligible($memberId)) {
                    $userRow = Database::fetchOne("SELECT must_change_password FROM users WHERE member_id = ?", [$memberId]);
                    $isDefault = $userRow && (int)$userRow['must_change_password'] === 1;

                    if ($isDefault && $kgid !== '') {
                        Session::flash('notice', "Account found! Please sign in using your Password. (Default initial password: Kspdowa@{$kgid})");
                    } else {
                        Session::flash('notice', "Account found! Please enter your password to sign in.");
                    }

                    $loginIdentifier = $kgid !== '' ? $kgid : ($member['personal_mobile'] ?? $member['personal_email'] ?? $cleanInput);
                    header('Location: /login.php?identifier=' . rawurlencode($loginIdentifier));
                    exit;
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
<p class="page-subtitle">Verify your membership account to access member services and receipts.</p>

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

    <?php if ($notEligible): ?>
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
