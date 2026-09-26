<?php
/**
 * KSPDOWA — Member Portal: Pay Annual Membership Dues
 * ============================================================
 * Secure in-portal Razorpay Standard Checkout for members.
 * Supports current financial year and any pending previous financial years.
 * Allows retry / new attempt if a payment attempt is pending or failed.
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requireLogin();
$currentMemberId = Auth::getCurrentMemberId();

if ($currentMemberId === null) {
    header('Location: /login.php');
    exit;
}

$pageTitle   = 'Pay Annual Dues';
$activeMenu  = 'membership';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/member/index.php'],
    ['label' => 'My Membership', 'url' => '/member/membership.php'],
    ['label' => 'Pay Annual Dues', 'url' => '']
];

// Determine financial year to pay
$yearId = (int)($_GET['year_id'] ?? 0);
if ($yearId <= 0) {
    $currentYear = Membership::getCurrentYear();
    $yearId = $currentYear ? (int)$currentYear['id'] : 0;
}

$year = Membership::getYearById($yearId);
if (!$year) {
    Session::flash('error', 'The requested financial year could not be found.');
    header('Location: /member/membership.php');
    exit;
}

$curYear = Membership::getCurrentYear();
$isCur = ($curYear && (int)$curYear['id'] === $yearId);

// Check if already completed
$completed = Database::fetchOne(
    "SELECT p.*, pr.receipt_no FROM membership_payments p
     LEFT JOIN payment_receipts pr ON pr.payment_id = p.id
     WHERE p.member_id = ? AND p.membership_year_id = ? AND p.status = 'completed'
     ORDER BY p.id DESC LIMIT 1",
    [$currentMemberId, $yearId]
);

if ($completed !== false) {
    Session::flash('success', 'Annual dues for Financial Year ' . $year['financial_year'] . ' are already cleared (Receipt #' . ($completed['receipt_no'] ?? $completed['id']) . ').');
    header('Location: /member/membership.php');
    exit;
}

$error  = Session::getFlash('error');
$notice = Session::getFlash('notice');

// Handle Retry / Fresh attempt request
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['pay_action'] ?? '') === 'retry') {
    CSRF::requireValid();

    $retry = PaymentGateway::startNewAttempt($currentMemberId, $yearId, true);
    if ($retry['success']) {
        Session::set('member_payment_id', (int)$retry['payment_id']);
        Session::set('member_payment_year_id', $yearId);
        Session::flash('notice', 'Fresh payment attempt initiated. You can complete your payment below.');
    } else {
        Session::flash('error', $retry['error'] ?? 'Could not initiate a new payment attempt.');
    }

    header('Location: /member/pay.php?year_id=' . $yearId);
    exit;
}

// Find or start pending payment attempt
$pendingPayment = Database::fetchOne(
    "SELECT * FROM membership_payments
     WHERE member_id = ? AND membership_year_id = ? AND status = 'pending'
     ORDER BY id DESC LIMIT 1",
    [$currentMemberId, $yearId]
);

if ($pendingPayment === false && (float)$year['fee_amount'] > 0) {
    $attempt = PaymentGateway::startNewAttempt($currentMemberId, $yearId, false);
    if ($attempt['success']) {
        $paymentId = (int)$attempt['payment_id'];
        $pendingPayment = Database::fetchOne('SELECT * FROM membership_payments WHERE id = ?', [$paymentId]);
    } else {
        $error = $attempt['error'] ?? 'Unable to create payment attempt.';
    }
}

$order = null;
if ($pendingPayment !== false) {
    Session::set('member_payment_id', (int)$pendingPayment['id']);
    Session::set('member_payment_year_id', $yearId);

    if ((float)$pendingPayment['amount'] > 0) {
        $order = PaymentGateway::ensureOrder((int)$pendingPayment['id']);
        if (!$order['success']) {
            $error = $order['error'] ?? 'Could not initialize gateway order.';
        }
    }
}

require_once dirname(__DIR__) . '/includes/partials/member-header.php';
?>

<div class="page-header-row">
    <div>
        <h1 class="page-heading-title">Pay Annual Membership Dues</h1>
        <p class="page-heading-subtitle">Financial Year: <strong><?= Sanitize::html($year['financial_year']) ?></strong> &bull; Period: <?= date('d M Y', strtotime((string)$year['start_date'])) ?> – <?= date('d M Y', strtotime((string)$year['end_date'])) ?></p>
    </div>
    <div>
        <a href="/member/membership.php" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:6px;">
            &larr; Back to My Membership
        </a>
    </div>
</div>

<?php if ($notice !== null): ?>
    <div class="alert alert-success" style="margin-bottom:20px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        <?= Sanitize::html($notice) ?>
    </div>
<?php endif; ?>

<?php if ($error !== null): ?>
    <div class="alert alert-danger" style="margin-bottom:20px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        <?= Sanitize::html($error) ?>
    </div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:24px; align-items:start; margin-bottom:32px;">

    <!-- Order Summary Card -->
    <div class="table-card">
        <div class="table-card-header">
            <span class="table-card-title">Payment Summary &amp; Member Details</span>
            <span class="badge <?= $isCur ? 'badge-purple' : 'badge-neutral' ?>">
                <?= $isCur ? 'Current Financial Year' : 'Past Financial Year' ?>
            </span>
        </div>
        <div style="padding:24px;">
            <table class="data-table" style="width:100%; border:none;">
                <tbody>
                    <tr>
                        <td style="color:var(--text-muted); width:45%; padding:10px 4px; border-bottom:1px solid #eef2f6;">Member Name</td>
                        <td style="font-weight:700; padding:10px 4px; border-bottom:1px solid #eef2f6;"><?= Sanitize::html($portalMember['name'] ?? '') ?></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-muted); padding:10px 4px; border-bottom:1px solid #eef2f6;">Membership Number</td>
                        <td style="font-weight:700; color:var(--blue-700); padding:10px 4px; border-bottom:1px solid #eef2f6;"><?= Sanitize::html($portalMember['member_no'] ?? '') ?></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-muted); padding:10px 4px; border-bottom:1px solid #eef2f6;">Financial Year</td>
                        <td style="font-weight:700; padding:10px 4px; border-bottom:1px solid #eef2f6;"><?= Sanitize::html($year['financial_year']) ?></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-muted); padding:10px 4px; border-bottom:1px solid #eef2f6;">Coverage Period</td>
                        <td style="padding:10px 4px; border-bottom:1px solid #eef2f6;"><?= date('d M Y', strtotime((string)$year['start_date'])) ?> – <?= date('d M Y', strtotime((string)$year['end_date'])) ?></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-muted); padding:12px 4px; font-size:1.05rem; font-weight:600;">Annual Fee Payable</td>
                        <td style="font-size:1.35rem; font-weight:800; color:var(--blue-700); padding:12px 4px;">
                            ₹<?= number_format((float)($order['amount'] ?? $year['fee_amount']), 2) ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Checkout Action Card -->
    <div class="table-card">
        <div class="table-card-header">
            <span class="table-card-title">Online Payment Gateway</span>
            <span class="badge badge-success">● Razorpay Secure</span>
        </div>
        <div style="padding:24px;">
            <?php if ((float)$year['fee_amount'] <= 0): ?>
                <div class="alert alert-warning" style="margin-bottom:16px;">
                    <strong>Fee Amount Not Configured</strong><br>
                    The annual subscription fee for FY <?= Sanitize::html($year['financial_year']) ?> is currently set to ₹0.00 by the administrator.
                    An active fee amount is required to process online payment. Please contact the association office or update the fee in Membership Setup.
                </div>
                <a href="/member/membership.php" class="btn btn-outline" style="width:100%; justify-content:center;">
                    Return to My Membership
                </a>

            <?php elseif ($order !== null && $order['success']): ?>
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:16px; margin-bottom:20px;">
                    <div style="display:flex; align-items:center; gap:10px; color:#166534; font-weight:600; margin-bottom:4px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        Secure 256-bit Encrypted Checkout
                    </div>
                    <p style="font-size:0.85rem; color:#15803d; margin:0;">
                        UPI, Credit/Debit Cards, Net Banking, and Wallets accepted. Your official receipt PDF will be generated immediately upon confirmation.
                    </p>
                </div>

                <button type="button" id="rzpPayBtn" class="btn btn-primary" style="width:100%; justify-content:center; padding:14px 20px; font-size:1.05rem; font-weight:700; box-shadow:0 4px 12px rgba(37,99,235,0.25);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    Pay ₹<?= Sanitize::html(number_format((float)$order['amount'], 2)) ?> Now
                </button>

                <div style="margin-top:20px; padding-top:16px; border-top:1px solid #eef2f6; text-align:center;">
                    <p style="font-size:0.82rem; color:var(--text-muted); margin-bottom:10px;">
                        Payment pending or modal closed? You can initiate a fresh attempt below:
                    </p>
                    <form method="post" action="/member/pay.php?year_id=<?= (int)$yearId ?>" style="display:inline;">
                        <?= CSRF::htmlField() ?>
                        <input type="hidden" name="pay_action" value="retry">
                        <button type="submit" class="btn btn-outline btn-sm" style="font-size:0.82rem;">
                            ↺ Restart Fresh Payment Attempt
                        </button>
                    </form>
                </div>

                <!-- Hidden verification and failure forms -->
                <form id="rzpVerifyForm" method="post" action="/member/pay-verify.php" style="display:none;">
                    <?= CSRF::htmlField() ?>
                    <input type="hidden" name="razorpay_payment_id">
                    <input type="hidden" name="razorpay_order_id">
                    <input type="hidden" name="razorpay_signature">
                </form>
                <form id="rzpFailForm" method="post" action="/member/pay-failed.php" style="display:none;">
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
                        payBtn.textContent = 'Opening Gateway...';

                        var options = {
                            key: <?= Sanitize::js($order['key_id']) ?>,
                            amount: <?= (int)$order['amount_paise'] ?>,
                            currency: "INR",
                            order_id: <?= Sanitize::js($order['order_id']) ?>,
                            name: <?= Sanitize::js(Settings::get('site_short_name', APP_SHORT_NAME)) ?>,
                            description: <?= Sanitize::js("Annual Membership Fee FY " . ($year['financial_year'] ?? '')) ?>,
                            prefill: {
                                name: <?= Sanitize::js($order['member']['name'] ?? $portalMember['name'] ?? '') ?>,
                                email: <?= Sanitize::js($order['member']['personal_email'] ?? $portalMember['personal_email'] ?? '') ?>,
                                contact: <?= Sanitize::js($order['member']['personal_mobile'] ?? $portalMember['personal_mobile'] ?? '') ?>
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
                                    payBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg> Pay ₹<?= Sanitize::html(number_format((float)$order['amount'], 2)) ?> Now';
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
                <div class="alert alert-danger" style="margin-bottom:16px;">
                    Online payment gateway could not be loaded at this moment.
                </div>
                <form method="post" action="/member/pay.php?year_id=<?= (int)$yearId ?>">
                    <?= CSRF::htmlField() ?>
                    <input type="hidden" name="pay_action" value="retry">
                    <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">
                        Try Again
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php
require_once dirname(__DIR__) . '/includes/partials/member-footer.php';
