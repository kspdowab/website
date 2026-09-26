<?php
/**
 * KSPDOWA — Annual Membership Fee Payment (Razorpay Standard Checkout)
 * ============================================================
 * Reached only via the "Proceed to Payment" link on register.php's
 * success step, which sets Session::set('registration_payment_id'/
 * 'registration_member_id'). This page NEVER trusts a query-string
 * payment/member id -- reading these only from the session is what
 * stops one registrant from viewing or paying another's pending
 * payment (no login exists yet at this stage to gate the page any
 * other way).
 *
 * All actual gateway interaction (Order creation/reuse, exact
 * current-year amount) is delegated to PaymentGateway::ensureOrder();
 * this file is purely the controller + Standard Checkout view.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (Auth::isLoggedIn()) {
    $yearId = (int)($_GET['year_id'] ?? 0);
    $target = '/member/pay.php' . ($yearId > 0 ? '?year_id=' . $yearId : '');
    header('Location: ' . $target);
    exit;
}

$paymentId = Session::get('registration_payment_id');
$memberId  = Session::get('registration_member_id');

if ($paymentId === null || $memberId === null) {
    Session::flash('notice', 'Please complete the registration form first.');
    header('Location: /register.php');
    exit;
}
$paymentId = (int) $paymentId;
$memberId  = (int) $memberId;

$error  = Session::getFlash('error');
$notice = Session::getFlash('notice');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['pay_action'] ?? '') === 'retry') {
    CSRF::requireValid();

    $retry = PaymentGateway::startNewAttempt($memberId);
    if ($retry['success']) {
        Session::set('registration_payment_id', $retry['payment_id']);
        Session::flash('notice', 'You can try your payment again below.');
    } else {
        Session::flash('error', $retry['error'] ?? 'Could not start a new payment attempt.');
    }

    header('Location: /payment.php');
    exit;
}

$payment = Database::fetchOne(
    'SELECT * FROM membership_payments WHERE id = ? AND member_id = ?',
    [$paymentId, $memberId]
);

if ($payment === false) {
    Session::remove('registration_payment_id');
    Session::remove('registration_member_id');
    Session::flash('error', 'That payment attempt could not be found. Please register again or contact the Association.');
    header('Location: /register.php');
    exit;
}

$order = null;
if ($payment['status'] === 'pending') {
    $order = PaymentGateway::ensureOrder($paymentId);
    if (!$order['success']) {
        $error = $order['error'];
    }
}

$pageTitle = 'Complete Your Payment';
require __DIR__ . '/includes/partials/header.php';
?>
<style>
    /* Page-scoped, same design tokens as register.php -- no new palette. */
    .reg-review-table { width: 100%; border-collapse: collapse; }
    .reg-review-table td { padding: 8px 4px; border-bottom: 1px solid var(--border-soft, #eef1f5); vertical-align: top; }
    .reg-review-table td:first-child { color: var(--ink-500); width: 55%; }
</style>

<h1 class="page-title">Complete Your Payment</h1>
<p class="page-subtitle">Pay your annual KSPDOWA membership fee securely via Razorpay.</p>

<div class="card form-narrow">
    <?php if ($notice !== null): ?>
        <div class="alert alert-success" role="status"><?= Sanitize::html($notice) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert alert-error" role="alert"><?= Sanitize::html($error) ?></div>
    <?php endif; ?>

    <?php if ($payment['status'] === 'completed'): ?>
        <div class="alert alert-success" role="status">
            This payment has already been completed. A temporary login password has been emailed to your
            registered email address (if it had not been sent already).
        </div>
        <p style="text-align:center; margin-top:var(--space-4);">
            <a class="btn" href="/login.php">Go to Sign In</a>
        </p>

    <?php elseif ($payment['status'] === 'failed'): ?>
        <div class="alert alert-info" role="alert">
            Your previous payment attempt did not complete (declined, cancelled, or timed out). No amount from
            that attempt was applied to your membership. You can try again below with a fresh payment attempt.
        </div>
        <form method="post" action="/payment.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="pay_action" value="retry">
            <button type="submit" class="btn btn-teal" style="width:100%; justify-content:center;">Try Payment Again</button>
        </form>

    <?php elseif ($order !== null && $order['success']): ?>
        <table class="reg-review-table" style="margin-bottom:16px;">
            <tr><td>Annual Membership Fee</td><td><strong>&#8377;<?= Sanitize::html(number_format((float) $order['amount'], 2)) ?></strong></td></tr>
            <tr><td>Convenience Charges</td><td>As per Razorpay</td></tr>
        </table>
        <p class="form-hint">
            After payment is verified, a system-generated temporary login password will be emailed to
            <?= Sanitize::html($order['member']['personal_email'] ?? '') ?>.
        </p>
        <button type="button" id="rzpPayBtn" class="btn btn-teal" style="width:100%; justify-content:center; margin-top:16px;">
            Pay &#8377;<?= Sanitize::html(number_format((float) $order['amount'], 2)) ?> Now
        </button>

        <form id="rzpVerifyForm" method="post" action="/payment-verify.php" style="display:none;">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="razorpay_payment_id">
            <input type="hidden" name="razorpay_order_id">
            <input type="hidden" name="razorpay_signature">
        </form>
        <form id="rzpFailForm" method="post" action="/payment-failed.php" style="display:none;">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="reason">
        </form>

        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
        <script>
        (function () {
            var payBtn = document.getElementById('rzpPayBtn');
            // Razorpay's checkout.js can fire modal.ondismiss even after a
            // successful payment (the modal closing after handler() runs
            // still counts as a "dismiss" in some versions). Without this
            // guard that would submit BOTH the verify form and the fail
            // form for one successful payment -- whichever request the
            // server processes second would then see a row that is no
            // longer 'pending' and reject, which for the verify request
            // would wrongly turn a real success into an error shown to
            // the member. Once handler() has taken over navigation, every
            // other checkout.js event is ignored.
            var paymentHandled = false;

            payBtn.addEventListener('click', function () {
                payBtn.disabled = true;

                var options = {
                    key: <?= Sanitize::js($order['key_id']) ?>,
                    amount: <?= (int) $order['amount_paise'] ?>,
                    currency: "INR",
                    order_id: <?= Sanitize::js($order['order_id']) ?>,
                    name: <?= Sanitize::js(Settings::get('site_short_name', APP_SHORT_NAME)) ?>,
                    description: "Annual Membership Fee",
                    prefill: {
                        name: <?= Sanitize::js($order['member']['name'] ?? '') ?>,
                        email: <?= Sanitize::js($order['member']['personal_email'] ?? '') ?>,
                        contact: <?= Sanitize::js($order['member']['personal_mobile'] ?? '') ?>
                    },
                    handler: function (response) {
                        paymentHandled = true;
                        var f = document.getElementById('rzpVerifyForm');
                        f.razorpay_payment_id.value = response.razorpay_payment_id;
                        f.razorpay_order_id.value   = response.razorpay_order_id;
                        f.razorpay_signature.value  = response.razorpay_signature;
                        f.submit();
                    },
                    modal: {
                        ondismiss: function () {
                            if (paymentHandled) { return; }
                            payBtn.disabled = false;
                            var f = document.getElementById('rzpFailForm');
                            f.reason.value = 'modal_dismissed';
                            f.submit();
                        }
                    }
                };

                var rzp = new Razorpay(options);
                rzp.on('payment.failed', function (resp) {
                    if (paymentHandled) { return; }
                    paymentHandled = true;
                    var f = document.getElementById('rzpFailForm');
                    f.reason.value = (resp && resp.error && resp.error.description) ? resp.error.description : 'payment_failed';
                    f.submit();
                });
                rzp.open();
            });
        })();
        </script>

    <?php else: ?>
        <div class="alert alert-error" role="alert">
            Online payment is temporarily unavailable. Please contact the Association office to complete your
            membership payment.
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
