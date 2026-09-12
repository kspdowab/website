<?php
/**
 * KSPDOWA — Member Self-Registration
 * ============================================================
 * Implements the approved "MEMBER REGISTRATION + VALIDATION +
 * PAYMENT + MEMBERSHIP LOCATION RULES" spec. All validation and DB
 * writes live in includes/Registration.php; this file is the
 * controller + view for a 3-step flow:
 *
 *   form   -> full registration form (all sections + conditional
 *             Working/Office/Membership-location rules)
 *   review -> "Review & Payment" -- this is the ONLY place the
 *             annual fee is shown, per the approved spec, retrieved
 *             dynamically from Membership::getCurrentYear() (never
 *             hard-coded)
 *   success-> pending record created; explicit "Proceed to Payment"
 *             link to payment.php (in-app Razorpay Standard Checkout).
 *             Nothing here trusts a client-side payment signal --
 *             payment.php / payment-verify.php / razorpay-webhook.php
 *             (via PaymentGateway::confirmPayment()) are what actually
 *             verify and activate a payment server-side.
 *
 * Every step re-validates server-side (Registration::validate()) --
 * hidden re-posted values from the review step are never trusted on
 * their own, per "no client-side-only authorization".
 *
 * PASSWORD / CONFIRM PASSWORD: deliberately not collected on this
 * form. See the header comment of includes/Registration.php for the
 * full resolution of the conflict the approved spec itself flagged
 * (item 8) -- the short version: the already-built, already-tested
 * login flow (member-login.php) issues a system-generated temporary
 * password only after verified payment, and this form must not
 * introduce a second, weaker credential path.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// Already an active session (admin/officer or member)? Registration is
// a logged-out-only flow.
if (Auth::isLoggedIn()) {
    header('Location: /');
    exit;
}

$pageTitle = 'Member Registration';
$step      = 'form';
$errors    = [];
$clean     = [];
$result    = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValid();

    $regAction = $_POST['reg_action'] ?? '';
    $validated = Registration::validate($_POST);
    $errors    = $validated['errors'];
    $clean     = $validated['clean'];

    if ($regAction === 'review' && empty($errors)) {
        $step = 'review';
        // Marks that this session has actually seen the Review & Payment
        // step (with the fee displayed) before a 'confirm' is honoured --
        // closes off constructing a raw 'confirm' POST that skips straight
        // past the fee-display step the approved spec requires.
        Session::set('registration_reviewed', true);
    } elseif ($regAction === 'confirm') {
        if (empty($errors) && Session::get('registration_reviewed') === true) {
            $result = Registration::register($clean);
            if ($result['success']) {
                Session::remove('registration_reviewed');
                // Bind THIS session to the pending payment just created --
                // payment.php reads these back from the session only
                // (never from a query string), which is what stops one
                // registrant from viewing or paying another's pending
                // payment before either of them has a login account to
                // gate the page with.
                Session::set('registration_payment_id', $result['payment_id']);
                Session::set('registration_member_id', $result['member_id']);
                $step = 'success';
            } else {
                $errors['_general'] = $result['error'];
                $step = 'form';
            }
        } elseif (empty($errors)) {
            // Valid data, but never actually visited the Review & Payment
            // step first -- send them there instead of registering blind.
            $step = 'review';
            Session::set('registration_reviewed', true);
        } else {
            // Re-validation on the final submit found a problem (e.g. a
            // tampered hidden field, or someone else took the email/KGID
            // in between) -- send them back to the full form, not the
            // review step, so they can see and fix it.
            $step = 'form';
        }
    } else {
        $step = 'form';
    }
}

$year = Membership::getCurrentYear();

$districts = Database::fetchAll('SELECT id, name FROM districts WHERE status = "active" ORDER BY name');
$taluks    = Database::fetchAll('SELECT id, name, district_id FROM taluks WHERE status = "active" ORDER BY name');
$gps       = Database::fetchAll('SELECT id, name, taluk_id FROM gram_panchayatis WHERE status = "active" ORDER BY name');

$geoData = [
    'districts' => array_map(static fn($d) => ['id' => (int) $d['id'], 'name' => $d['name']], $districts),
    'taluks'    => array_map(static fn($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'district_id' => (int) $t['district_id']], $taluks),
    'gps'       => array_map(static fn($g) => ['id' => (int) $g['id'], 'name' => $g['name'], 'taluk_id' => (int) $g['taluk_id']], $gps),
];

function reg_old(array $clean, array $post, string $key): string
{
    // Only trust the validated $clean value when it actually holds
    // something -- a field that FAILED validation is normalised to ''
    // or null in $clean (see Registration::validate()), and the user's
    // original (invalid) input must be redisplayed instead, or their
    // typing is silently lost on a validation error.
    if (array_key_exists($key, $clean) && $clean[$key] !== null && $clean[$key] !== '') {
        return Sanitize::attr((string) $clean[$key]);
    }
    return Sanitize::attr($post[$key] ?? '');
}

$post = $_POST;

require __DIR__ . '/includes/partials/header.php';
?>
<style>
    /* Page-scoped layout for the registration form. Colours/spacing are
       drawn entirely from the existing design tokens (var(--...)) -- no
       new palette is introduced, only structural layout for this form. */
    .reg-section { margin-bottom: var(--space-6, 28px); }
    .reg-section h2 {
        font-size: 1.05rem;
        color: var(--blue-700);
        border-bottom: 1px solid var(--border, #e2e8f0);
        padding-bottom: 8px;
        margin-bottom: 18px;
    }
    .reg-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 16px 20px;
    }
    .reg-radio-group { display: flex; gap: 24px; flex-wrap: wrap; margin-top: 4px; }
    .reg-radio-group label { font-weight: 500; color: var(--ink-700); display: flex; align-items: center; gap: 6px; }
    .reg-locked-box {
        background: var(--blue-100);
        border: 1px solid var(--blue-600);
        border-radius: var(--radius-md, 8px);
        padding: 12px 16px;
        color: var(--ink-700);
        font-size: 0.92rem;
    }
    .reg-error-summary {
        background: var(--red-100, #fdecea);
        border: 1px solid var(--red-700);
        color: var(--red-700);
        border-radius: var(--radius-md, 8px);
        padding: 14px 18px;
        margin-bottom: 20px;
    }
    .reg-error-summary ul { margin: 6px 0 0; padding-left: 20px; }
    .reg-field-error { color: var(--red-700); font-size: 0.82rem; margin-top: 4px; }
    .reg-review-table { width: 100%; border-collapse: collapse; }
    .reg-review-table td { padding: 8px 4px; border-bottom: 1px solid var(--border-soft, #eef1f5); vertical-align: top; }
    .reg-review-table td:first-child { color: var(--ink-500); width: 42%; }
    .reg-fee-box {
        background: var(--teal-100);
        border: 1px solid var(--teal-700);
        border-radius: var(--radius-md, 8px);
        padding: 18px 20px;
        margin: 20px 0;
    }
    .reg-fee-box .amount { font-size: 1.6rem; font-weight: 700; color: var(--teal-700); }
    .reg-fee-box .charges { font-size: 0.85rem; color: var(--ink-500); margin-top: 4px; }
    [hidden] { display: none !important; }
</style>

<h1 class="page-title">Member Registration</h1>
<p class="page-subtitle">Register as a Panchayat Development Officer member of KSPDOWA.</p>

<?php if (!empty($errors['_general'])): ?>
    <div class="alert alert-error" role="alert"><?= Sanitize::html($errors['_general']) ?></div>
<?php endif; ?>

<?php if ($step === 'success' && $result !== null): ?>

    <div class="card">
        <span class="badge badge-green">Registration received</span>
        <h2 style="margin-top:12px;">Thank you<?= $result['retry'] ?? false ? '' : '' ?>, your registration has been recorded.</h2>
        <p style="color:var(--ink-700);">
            The annual membership fee for <strong><?= Sanitize::html($result['financial_year']) ?></strong>
            is <strong>&#8377;<?= Sanitize::html(number_format((float) $result['amount'], 2)) ?></strong>.
            Please complete payment using the button below to activate your membership.
        </p>
        <p class="form-hint">
            After your payment is verified by the Association, a system-generated login password will
            be emailed to your registered email address, with a mandatory password change on first login.
        </p>
        <p style="margin-top:20px;">
            <a class="btn btn-teal" href="/payment.php">
                Proceed to Payment
            </a>
        </p>
        <p class="form-hint" style="margin-top:16px;">
            Already paid but not yet activated? Use the
            <a href="/member-login.php">member sign-in</a> page once payment has been verified.
        </p>
    </div>

<?php elseif ($step === 'review'): ?>

    <div class="card">
        <h2 style="color:var(--blue-700);">Review Your Details</h2>
        <table class="reg-review-table">
            <tr><td>Full Name</td><td><?= Sanitize::html($clean['full_name']) ?></td></tr>
            <tr><td>Father / Husband Name</td><td><?= Sanitize::html($clean['father_spouse_name']) ?></td></tr>
            <tr><td>Gender</td><td><?= Sanitize::html(ucfirst((string) $clean['gender'])) ?></td></tr>
            <tr><td>Phone</td><td><?= Sanitize::html($clean['phone']) ?></td></tr>
            <tr><td>Email</td><td><?= Sanitize::html($clean['email']) ?></td></tr>
            <tr><td>KGID No.</td><td><?= Sanitize::html($clean['kgid_no']) ?></td></tr>
            <tr><td>Date of Birth</td><td><?= Sanitize::html($clean['dob']) ?></td></tr>
            <tr><td>Designation</td><td>PDO</td></tr>
            <tr><td>Currently working in a Gram Panchayati?</td><td><?= Sanitize::html(ucfirst((string) $clean['gp_working'])) ?></td></tr>
            <?php if ($clean['gp_working'] === 'no'): ?>
                <tr><td>Office/Organization Type</td><td><?= Sanitize::html(Registration::ORG_TYPES[$clean['organization_type']] ?? '') ?></td></tr>
                <tr><td>Office/Organization Name</td><td><?= Sanitize::html($clean['organization_name']) ?></td></tr>
                <?php if (!empty($clean['organization_address'])): ?>
                    <tr><td>Office Address</td><td><?= Sanitize::html($clean['organization_address']) ?></td></tr>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($clean['working_district_id'])): ?>
                <?php
                    $wdName = '';
                    $wtName = '';
                    $wgName = '';
                    foreach ($geoData['districts'] as $d) { if ($d['id'] === (int) $clean['working_district_id']) { $wdName = $d['name']; } }
                    foreach ($geoData['taluks'] as $t) { if ($t['id'] === (int) $clean['working_taluk_id']) { $wtName = $t['name']; } }
                    if (!empty($clean['working_gp_id'])) {
                        foreach ($geoData['gps'] as $g) { if ($g['id'] === (int) $clean['working_gp_id']) { $wgName = $g['name']; } }
                    }
                ?>
                <tr><td>Working District</td><td><?= Sanitize::html($wdName) ?></td></tr>
                <tr><td>Working Taluk</td><td><?= Sanitize::html($wtName) ?></td></tr>
                <?php if ($wgName !== ''): ?>
                    <tr><td>Working Gram Panchayati</td><td><?= Sanitize::html($wgName) ?></td></tr>
                <?php endif; ?>
            <?php endif; ?>
            <?php
                $mdName = '';
                $mtName = '';
                foreach ($geoData['districts'] as $d) { if ($d['id'] === (int) ($clean['membership_district_id'] ?? 0)) { $mdName = $d['name']; } }
                foreach ($geoData['taluks'] as $t) { if ($t['id'] === (int) ($clean['membership_taluk_id'] ?? 0)) { $mtName = $t['name']; } }
            ?>
            <tr><td>Membership District</td><td><?= Sanitize::html($mdName) ?></td></tr>
            <tr><td>Membership Taluk</td><td><?= Sanitize::html($mtName) ?></td></tr>
        </table>

        <div class="reg-fee-box">
            <?php if ($year !== null): ?>
                <div>Annual Subscription Fee (<?= Sanitize::html($year['financial_year']) ?>)</div>
                <div class="amount">&#8377;<?= Sanitize::html(number_format((float) $year['fee_amount'], 2)) ?></div>
                <div class="charges">Convenience Charges: 2% + GST 18% Extra</div>
            <?php else: ?>
                <div class="alert alert-error">The current annual membership fee has not been configured yet. Please contact the Association.</div>
            <?php endif; ?>
        </div>

        <p class="form-hint">
            Your login password is not set here. After your payment is verified, a system-generated
            temporary password will be emailed to <?= Sanitize::html($clean['email']) ?>, and you will
            be required to change it on first login.
        </p>

        <form method="post" action="/register.php">
            <?= CSRF::htmlField() ?>
            <input type="hidden" name="reg_action" value="confirm">
            <?php foreach ($clean as $k => $v): ?>
                <?php if (is_array($v)) { continue; } ?>
                <input type="hidden" name="<?= Sanitize::attr($k) ?>" value="<?= Sanitize::attr((string) ($v ?? '')) ?>">
            <?php endforeach; ?>
            <div style="display:flex; gap:12px; margin-top:20px;">
                <button type="submit" class="btn btn-teal" <?= $year === null ? 'disabled' : '' ?>>Confirm &amp; Proceed to Payment</button>
                <a class="btn btn-outline" href="/register.php">Edit Details</a>
            </div>
        </form>
    </div>

<?php else: ?>

    <?php if (!empty($errors) && (count($errors) > (isset($errors['_general']) ? 1 : 0))): ?>
        <div class="reg-error-summary">
            <strong>Please correct the following:</strong>
            <ul>
                <?php foreach ($errors as $key => $msg): ?>
                    <?php if ($key === '_general') { continue; } ?>
                    <li><?= Sanitize::html($msg) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="/register.php" id="regForm" novalidate>
        <?= CSRF::htmlField() ?>
        <input type="hidden" name="reg_action" value="review">

        <div class="card reg-section">
            <h2>Personal Details</h2>
            <div class="reg-grid">
                <div class="form-group">
                    <label for="full_name">Full Name *</label>
                    <input type="text" id="full_name" name="full_name" required maxlength="200" value="<?= reg_old($clean, $post, 'full_name') ?>">
                    <?php if (!empty($errors['full_name'])): ?><div class="reg-field-error"><?= Sanitize::html($errors['full_name']) ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="father_spouse_name">Father / Husband Name *</label>
                    <input type="text" id="father_spouse_name" name="father_spouse_name" required maxlength="200" value="<?= reg_old($clean, $post, 'father_spouse_name') ?>">
                    <?php if (!empty($errors['father_spouse_name'])): ?><div class="reg-field-error"><?= Sanitize::html($errors['father_spouse_name']) ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Gender *</label>
                    <div class="reg-radio-group">
                        <label><input type="radio" name="gender" value="male" <?= ($clean['gender'] ?? ($post['gender'] ?? '')) === 'male' ? 'checked' : '' ?> required> Male</label>
                        <label><input type="radio" name="gender" value="female" <?= ($clean['gender'] ?? ($post['gender'] ?? '')) === 'female' ? 'checked' : '' ?>> Female</label>
                    </div>
                    <?php if (!empty($errors['gender'])): ?><div class="reg-field-error"><?= Sanitize::html($errors['gender']) ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="phone">Phone *</label>
                    <input type="text" id="phone" name="phone" required maxlength="10" inputmode="numeric" pattern="[6-9][0-9]{9}"
                           placeholder="10-digit mobile number" value="<?= reg_old($clean, $post, 'phone') ?>">
                    <div class="form-hint">Exactly 10 digits, starting 6-9. No +91, spaces or letters.</div>
                    <?php if (!empty($errors['phone'])): ?><div class="reg-field-error"><?= Sanitize::html($errors['phone']) ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="email">Email *</label>
                    <input type="email" id="email" name="email" required maxlength="190" value="<?= reg_old($clean, $post, 'email') ?>">
                    <?php if (!empty($errors['email'])): ?><div class="reg-field-error"><?= Sanitize::html($errors['email']) ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="kgid_no">KGID No. *</label>
                    <input type="text" id="kgid_no" name="kgid_no" required maxlength="50" value="<?= reg_old($clean, $post, 'kgid_no') ?>">
                    <?php if (!empty($errors['kgid_no'])): ?><div class="reg-field-error"><?= Sanitize::html($errors['kgid_no']) ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="dob">Date of Birth *</label>
                    <input type="date" id="dob" name="dob" required value="<?= reg_old($clean, $post, 'dob') ?>">
                    <?php if (!empty($errors['dob'])): ?><div class="reg-field-error"><?= Sanitize::html($errors['dob']) ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="designation">Designation</label>
                    <input type="text" id="designation" value="PDO" readonly disabled>
                </div>
            </div>
            <p class="form-hint" style="margin-top:16px;">
                Your login password is generated automatically and emailed to you after your annual fee
                payment is verified -- there is no password to set here.
            </p>
        </div>

        <div class="card reg-section">
            <h2>Current Working Details</h2>
            <div class="form-group">
                <label>Currently working in a Gram Panchayati? *</label>
                <div class="reg-radio-group">
                    <label><input type="radio" name="gp_working" value="yes" id="gpWorkingYes" <?= ($clean['gp_working'] ?? ($post['gp_working'] ?? '')) === 'yes' ? 'checked' : '' ?> required> Yes</label>
                    <label><input type="radio" name="gp_working" value="no" id="gpWorkingNo" <?= ($clean['gp_working'] ?? ($post['gp_working'] ?? '')) === 'no' ? 'checked' : '' ?>> No</label>
                </div>
                <?php if (!empty($errors['gp_working'])): ?><div class="reg-field-error"><?= Sanitize::html($errors['gp_working']) ?></div><?php endif; ?>
            </div>

            <div id="orgBlock" class="reg-grid" style="margin-top:16px;" hidden>
                <div class="form-group">
                    <label for="organization_type">Office/Organization Type *</label>
                    <select id="organization_type" name="organization_type">
                        <option value="">-- Select --</option>
                        <?php foreach (Registration::ORG_TYPES as $val => $label): ?>
                            <option value="<?= Sanitize::attr($val) ?>" <?= ($clean['organization_type'] ?? ($post['organization_type'] ?? '')) === $val ? 'selected' : '' ?>><?= Sanitize::html($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty($errors['organization_type'])): ?><div class="reg-field-error"><?= Sanitize::html($errors['organization_type']) ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="organization_name">Office/Organization Name *</label>
                    <input type="text" id="organization_name" name="organization_name" maxlength="200" value="<?= reg_old($clean, $post, 'organization_name') ?>">
                    <?php if (!empty($errors['organization_name'])): ?><div class="reg-field-error"><?= Sanitize::html($errors['organization_name']) ?></div><?php endif; ?>
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label for="organization_address">Office Address (optional)</label>
                    <input type="text" id="organization_address" name="organization_address" maxlength="500" value="<?= reg_old($clean, $post, 'organization_address') ?>">
                </div>
            </div>

            <div id="workingLocationBlock" class="reg-grid" style="margin-top:16px;" hidden>
                <div class="form-group">
                    <label for="working_district_id">Working District *</label>
                    <select id="working_district_id" name="working_district_id"></select>
                    <?php if (!empty($errors['working_district_id'])): ?><div class="reg-field-error"><?= Sanitize::html($errors['working_district_id']) ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="working_taluk_id">Working Taluk *</label>
                    <select id="working_taluk_id" name="working_taluk_id"></select>
                    <?php if (!empty($errors['working_taluk_id'])): ?><div class="reg-field-error"><?= Sanitize::html($errors['working_taluk_id']) ?></div><?php endif; ?>
                </div>
                <div class="form-group" id="workingGpField">
                    <label for="working_gp_id">Gram Panchayati (optional)</label>
                    <select id="working_gp_id" name="working_gp_id"><option value="">-- Select --</option></select>
                    <?php if (!empty($errors['working_gp_id'])): ?><div class="reg-field-error"><?= Sanitize::html($errors['working_gp_id']) ?></div><?php endif; ?>
                </div>
            </div>

            <div id="membershipManualBlock" class="reg-grid" style="margin-top:16px;" hidden>
                <div class="form-group">
                    <label for="membership_district_id">Membership District *</label>
                    <select id="membership_district_id" name="membership_district_id"></select>
                    <?php if (!empty($errors['membership_district_id'])): ?><div class="reg-field-error"><?= Sanitize::html($errors['membership_district_id']) ?></div><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="membership_taluk_id">Membership Taluk *</label>
                    <select id="membership_taluk_id" name="membership_taluk_id"></select>
                    <?php if (!empty($errors['membership_taluk_id'])): ?><div class="reg-field-error"><?= Sanitize::html($errors['membership_taluk_id']) ?></div><?php endif; ?>
                </div>
            </div>

            <div id="membershipLockedBlock" style="margin-top:16px;" hidden>
                <div class="reg-locked-box">
                    Membership District/Taluk<span id="membershipLockedGpText"></span> will be automatically
                    assigned from your Working location above and cannot be changed manually.
                </div>
            </div>
        </div>

        <div style="display:flex; gap:12px;">
            <button type="submit" class="btn btn-teal">Continue to Review &amp; Payment</button>
        </div>
    </form>

<?php endif; ?>

<script id="geoData" type="application/json"><?= json_encode($geoData, JSON_UNESCAPED_UNICODE) ?></script>
<script>
(function () {
    var geo = JSON.parse(document.getElementById('geoData').textContent);

    function optionsHtml(list, selectedId) {
        var html = '<option value="">-- Select --</option>';
        list.forEach(function (item) {
            var sel = (String(item.id) === String(selectedId)) ? ' selected' : '';
            html += '<option value="' + item.id + '"' + sel + '>' + item.name.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</option>';
        });
        return html;
    }

    function fillDistricts(selectEl, selectedId) {
        selectEl.innerHTML = optionsHtml(geo.districts, selectedId);
    }

    function fillTaluksForDistrict(selectEl, districtId, selectedId) {
        var list = geo.taluks.filter(function (t) { return String(t.district_id) === String(districtId); });
        selectEl.innerHTML = optionsHtml(list, selectedId);
    }

    function fillGpsForTaluk(selectEl, talukId, selectedId) {
        var list = geo.gps.filter(function (g) { return String(g.taluk_id) === String(talukId); });
        selectEl.innerHTML = '<option value="">-- Select (optional) --</option>' + optionsHtml(list, selectedId).replace('<option value="">-- Select --</option>', '');
    }

    var form = document.getElementById('regForm');
    if (!form) { return; } // review/success step -- no cascading UI needed

    var wDistrict = document.getElementById('working_district_id');
    var wTaluk    = document.getElementById('working_taluk_id');
    var wGp       = document.getElementById('working_gp_id');
    var mDistrict = document.getElementById('membership_district_id');
    var mTaluk    = document.getElementById('membership_taluk_id');

    var oldWorkingDistrict    = "<?= reg_old($clean, $post, 'working_district_id') ?>";
    var oldWorkingTaluk       = "<?= reg_old($clean, $post, 'working_taluk_id') ?>";
    var oldWorkingGp          = "<?= reg_old($clean, $post, 'working_gp_id') ?>";
    var oldMembershipDistrict = "<?= reg_old($clean, $post, 'membership_district_id') ?>";
    var oldMembershipTaluk    = "<?= reg_old($clean, $post, 'membership_taluk_id') ?>";

    fillDistricts(wDistrict, oldWorkingDistrict);
    fillDistricts(mDistrict, oldMembershipDistrict);
    if (oldWorkingDistrict) { fillTaluksForDistrict(wTaluk, oldWorkingDistrict, oldWorkingTaluk); }
    if (oldMembershipDistrict) { fillTaluksForDistrict(mTaluk, oldMembershipDistrict, oldMembershipTaluk); }
    if (oldWorkingTaluk) { fillGpsForTaluk(wGp, oldWorkingTaluk, oldWorkingGp); }

    wDistrict.addEventListener('change', function () {
        fillTaluksForDistrict(wTaluk, wDistrict.value, '');
        fillGpsForTaluk(wGp, '', '');
    });
    wTaluk.addEventListener('change', function () {
        fillGpsForTaluk(wGp, wTaluk.value, '');
    });
    mDistrict.addEventListener('change', function () {
        fillTaluksForDistrict(mTaluk, mDistrict.value, '');
    });

    var gpWorkingYes = document.getElementById('gpWorkingYes');
    var gpWorkingNo  = document.getElementById('gpWorkingNo');
    var orgTypeSelect = document.getElementById('organization_type');

    var workingBlock    = document.getElementById('workingLocationBlock');
    var workingGpField  = document.getElementById('workingGpField');
    var orgBlock        = document.getElementById('orgBlock');
    var manualBlock     = document.getElementById('membershipManualBlock');
    var lockedBlock      = document.getElementById('membershipLockedBlock');
    var lockedGpText     = document.getElementById('membershipLockedGpText');

    var lockedOrgTypes = ['zilla_panchayat', 'taluk_panchayat'];

    function refreshVisibility() {
        var gpWorking = gpWorkingYes.checked ? 'yes' : (gpWorkingNo.checked ? 'no' : '');
        var orgType   = orgTypeSelect ? orgTypeSelect.value : '';
        var orgIsLocked = lockedOrgTypes.indexOf(orgType) !== -1;

        if (gpWorking === 'yes') {
            workingBlock.hidden = false;
            workingGpField.hidden = false;
            orgBlock.hidden = true;
            manualBlock.hidden = true;
            lockedBlock.hidden = false;
            lockedGpText.textContent = ' (and Gram Panchayati, if selected)';
        } else if (gpWorking === 'no') {
            orgBlock.hidden = false;
            if (orgIsLocked) {
                workingBlock.hidden = false;
                workingGpField.hidden = true; // GP not collected for this branch
                manualBlock.hidden = true;
                lockedBlock.hidden = false;
                lockedGpText.textContent = '';
            } else {
                workingBlock.hidden = true;
                manualBlock.hidden = false;
                lockedBlock.hidden = true;
            }
        } else {
            workingBlock.hidden = true;
            orgBlock.hidden = true;
            manualBlock.hidden = true;
            lockedBlock.hidden = true;
        }

        // Required-attribute bookkeeping so the browser doesn't block
        // submission on hidden fields.
        wDistrict.required = !workingBlock.hidden;
        wTaluk.required    = !workingBlock.hidden;
        if (orgTypeSelect) { orgTypeSelect.required = !orgBlock.hidden; }
        var orgNameEl = document.getElementById('organization_name');
        if (orgNameEl) { orgNameEl.required = !orgBlock.hidden; }
        mDistrict.required = !manualBlock.hidden;
        mTaluk.required    = !manualBlock.hidden;
    }

    gpWorkingYes.addEventListener('change', refreshVisibility);
    gpWorkingNo.addEventListener('change', refreshVisibility);
    if (orgTypeSelect) { orgTypeSelect.addEventListener('change', refreshVisibility); }

    refreshVisibility();
})();
</script>

<?php require __DIR__ . '/includes/partials/footer.php'; ?>
