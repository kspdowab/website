<?php
/**
 * KSPDOWA — Donation Payment (Razorpay Standard Checkout)
 * ============================================================
 * Reached only via donate.php, which sets Session::set('donation_id').
 * This page NEVER trusts a query-string donation id -- reading it only
 * from the session is what stops one donor from viewing or paying
 * another's pending donation (no login exists for a donor to gate the
 * page any other way).
 *
 * All actual gateway interaction is delegated to
 * DonationGateway::ensureOrder(); this file is purely the controller +
 * Standard Checkout view -- structurally identical to payment.php.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$donationId = Session::get('donation_id');
if ($donationId === null) {
    Session::flash('notice', 'Please start with the donation form first.');
    header('Location: /donate.php');
    exit;
}
$donationId = (int) $donationId;

$error  = Session::getFlash('error');
$notice = Session::getFlash('notice');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['pay_action'] ?? '') === 'retry') {
    CSRF::requireValid();

    $retry = DonationGateway::startNewAttempt($donationId);
    if ($retry['success']) {
        Session::set('donation_id', $retry['donation_id']);
        Session::flash('notice', 'You can try your payment again below.');
    } else {
        Session::flash('error', $retry['error'] ?? 'Could not start a new payment attempt.');
    }

    header('Location: /donate-payment.php');
    exit;
}

$donation = Database::fetchOne('SELECT * FROM donations WHERE id = ?', [$donationId]);

if ($donation === false) {
    Session::remove('donation_id');
    Session::flash('error', 'That donation attempt could not be found. Please start again.');
    header('Location: /donate.php');
    exit;
}

$order = null;
if ($donation['status'] === 'pending') {
    $order = DonationGateway::ensureOrder($donationId);
    if (!$order['success']) {
        $error = $order['error'];
    }
}

$pageTitle = 'Complete Your Donation';
require __DIR__ . '/includes/partials/header.php';
?>
<style>
    .reg-review-table { width: 100%; border-collapse: collapse; }
    .reg-review-table td { padding: 8px 4px; border-bottom: 1px solid var(--border-soft, #eef1f5); vertical-align: top; }
    .reg-review-table td:first-child { color: var(--ink-500); width: 55%; }
</style>

<h1 class="page-title">Complete Your Donation</h1>
<p class="page-subtitle">Pay your donation securely via Razorpay.</p>

<div class="card form-narrow">
    <?php if ($notice !== null): ?>
        <div class="alert alert-success" role="status"><?= Sanitize::html($notice) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert alert-error" role="alert"><?= Sanitize::html($error) ?></div>
    <?php endif; ?>

    <?php if ($donation['status'] === 'completed'): ?>
        <div class="alert alert-success" role="status">
            This donation has already been completed. Thank you for your support.
        </div>
        <p style="text-align:center; margin-top:var(--space-4);">
            <a class="btn" href="/donate-receipt.php">View Receipt</a>
        </p>

    <?php elseif ($donation['status'] === 'failed'): ?>
        <div class="alert alert-info" role="alert">
            Your previous donation attempt did not complete (declined, cancelled, or timed out). No amount from
            that attempt was applied. You can try again below with a fresh payment attempt.
        </div>
        <form method="post" action="/donate-payment.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="pay_action" value="retry">
            <button type="submit" class="btn btn-teal" style="width:100%; justify-content:center;">Try Payment Again</button>
        </form>

    <?php elseif ($order !== null && $order['success']): ?>
        <table class="reg-review-table" style="margin-bottom:16px;">
            <tr><td>Donor Name</td><td><strong><?= Sanitize::html($order['donor_name']) ?></strong></td></tr>
            <?php if (!empty($order['donor_mobile'])): ?>
                <tr><td>Mobile Number</td><td><strong><?= Sanitize::html($order['donor_mobile']) ?></strong></td></tr>
            <?php endif; ?>
            <tr><td>Donation Amount</td><td><strong>&#8377;<?= Sanitize::html(number_format((float) $order['amount'], 2)) ?></strong></td></tr>
            <tr><td>Convenience Charges</td><td>As per Razorpay</td></tr>
        </table>

        <button type="button" id="rzpPayBtn" class="btn btn-teal" style="width:100%; justify-content:center; margin-top:16px;">
            Pay &#8377;<?= Sanitize::html(number_format((float) $order['amount'], 2)) ?> Now
        </button>

        <form id="rzpVerifyForm" method="post" action="/donate-verify.php" style="display:none;">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="razorpay_payment_id">
            <input type="hidden" name="razorpay_order_id">
            <input type="hidden" name="razorpay_signature">
        </form>
        <form id="rzpFailForm" method="post" action="/donate-failed.php" style="display:none;">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="reason">
        </form>

        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
        <script>
        (function () {
            var payBtn = document.getElementById('rzpPayBtn');
            var paymentHandled = false;

            payBtn.addEventListener('click', function () {
                payBtn.disabled = true;

                var options = {
                    key: <?= Sanitize::js($order['key_id']) ?>,
                    amount: <?= (int) $order['amount_paise'] ?>,
                    currency: "INR",
                    order_id: <?= Sanitize::js($order['order_id']) ?>,
                    name: <?= Sanitize::js(Settings::get('site_short_name', APP_SHORT_NAME)) ?>,
                    description: "Donation",
                    prefill: {
                        name: <?= Sanitize::js($order['donor_name']) ?>,
                        contact: <?= Sanitize::js($order['donor_mobile'] ?? '') ?>
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
            Online payment is temporarily unavailable. Please try again later.
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
