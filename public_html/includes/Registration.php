<?php
/**
 * KSPDOWA — Member Self-Registration (Validation + DB Flow)
 * ============================================================
 * Encapsulates the approved "MEMBER REGISTRATION + VALIDATION +
 * PAYMENT + MEMBERSHIP LOCATION RULES" spec so register.php stays a
 * thin controller. Two responsibilities, kept separate:
 *
 *   validate() -- pure input validation/normalisation. Never touches
 *                 the `members`/`member_profiles` tables (only reads
 *                 districts/taluks/gram_panchayatis to check FK
 *                 validity, and member_profiles to give a friendly
 *                 duplicate-email/KGID error instead of a raw
 *                 constraint violation). Safe to call twice (once to
 *                 render the Review & Payment step, once more on the
 *                 final Confirm submission -- hidden re-posted values
 *                 are NEVER trusted without being re-validated here).
 *
 *   register()  -- the actual DB write, wrapped in one transaction.
 *                  Creates the member + profile + a PENDING current-
 *                  year payment record. Never marks a payment
 *                  'completed' and never creates a `users` login row
 *                  -- that only ever happens after server-verified
 *                  payment, via the already-built member-login.php /
 *                  Auth::generateTemporaryPassword() machinery. This
 *                  class does not duplicate that flow.
 *
 * MEMBERSHIP LOCATION RULES (approved spec):
 *   - gp_working = yes            -> Working District/Taluk mandatory,
 *                                     GP optional; Membership location
 *                                     = Working location, auto-assigned
 *                                     and locked (never trust a client-
 *                                     submitted membership_* value in
 *                                     this branch -- always overwritten
 *                                     server-side with the working
 *                                     location).
 *   - gp_working = no,
 *     organization_type in (zilla_panchayat, taluk_panchayat)
 *                                  -> same lock: Working District/Taluk
 *                                     mandatory, Membership = Working,
 *                                     auto-assigned and locked.
 *   - gp_working = no, other org types
 *                                  -> Working District/Taluk not
 *                                     collected; Membership District/
 *                                     Taluk manually selected instead.
 *
 * PASSWORD/CONFIRM PASSWORD CONFLICT (spec item 8) -- resolution:
 * The approved, already-built-and-tested login architecture generates
 * a system random temporary password ONLY after verified current-year
 * payment (Auth::generateTemporaryPassword(), member-login.php), and
 * forces a change on first use. Honouring a member-chosen password
 * collected at registration time -- before payment exists at all --
 * would mean either (a) storing a real credential that could log
 * someone in before their payment is verified ("Login must remain
 * disabled" / "Do not activate login from client-side payment success
 * alone" -- both violated), or (b) collecting a password and silently
 * discarding it, which is confusing and dishonest to the member. This
 * class therefore does NOT accept or store a registration-time
 * password at all -- register.php explains in the UI that the login
 * password is system-generated and emailed after payment is verified,
 * which is the existing approved mechanism working exactly as already
 * built and tested. No new credential path is introduced.
 * ============================================================
 */

declare(strict_types=1);

class Registration
{
    /** Office/Organization Type options (registration spec section 1). */
    public const ORG_TYPES = [
        'secretariat'     => 'Secretariat',
        'rdpr'            => 'RDPR',
        'commissionerate' => 'Commissionerate',
        'zilla_panchayat' => 'Zilla Panchayati',
        'taluk_panchayat' => 'Taluk Panchayati',
        'mp_mla_mlc_pa'   => 'MP/MLA/MLC PA',
        'other'           => 'Other',
    ];

    /**
     * Organization types for which Working District/Taluk become
     * mandatory and Membership location is auto-assigned + locked to
     * them (same treatment as gp_working = yes).
     */
    private const LOCKED_ORG_TYPES = ['zilla_panchayat', 'taluk_panchayat'];

    public static function isLockedOrgType(string $orgType): bool
    {
        return in_array($orgType, self::LOCKED_ORG_TYPES, true);
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * @return array{errors: array<string,string>, clean: array<string,mixed>}
     */
    public static function validate(array $post): array
    {
        $errors = [];
        $clean  = [];

        // --- Personal details ---
        $fullName = Sanitize::string($post['full_name'] ?? '', 200);
        if ($fullName === '') {
            $errors['full_name'] = 'Full Name is required.';
        }
        $clean['full_name'] = $fullName;

        $fatherSpouse = Sanitize::string($post['father_spouse_name'] ?? '', 200);
        if ($fatherSpouse === '') {
            $errors['father_spouse_name'] = 'Father / Husband Name is required.';
        }
        $clean['father_spouse_name'] = $fatherSpouse;

        $gender = Sanitize::inArray($post['gender'] ?? '', ['male', 'female']);
        if ($gender === false) {
            $errors['gender'] = 'Please select Gender.';
        }
        $clean['gender'] = $gender ?: null;

        $phone = Sanitize::mobileStrict($post['phone'] ?? '');
        if ($phone === false) {
            $errors['phone'] = 'Enter a valid 10-digit Indian mobile number (starts 6-9, digits only -- no +91, spaces or letters).';
        }
        $clean['phone'] = $phone ?: '';

        $email = Sanitize::email($post['email'] ?? '');
        if ($email === false) {
            $errors['email'] = 'Enter a valid email address.';
        }
        $clean['email'] = $email ?: '';

        $kgid = Sanitize::string($post['kgid_no'] ?? '', 50);
        if ($kgid === '') {
            $errors['kgid_no'] = 'KGID No. is required.';
        }
        $clean['kgid_no'] = $kgid;

        $dob = Sanitize::date($post['dob'] ?? '');
        if ($dob === false) {
            $errors['dob'] = 'Enter a valid Date of Birth.';
        } elseif ($dob >= date('Y-m-d')) {
            $errors['dob'] = 'Date of Birth must be in the past.';
        }
        $clean['dob'] = $dob ?: '';

        // --- Current working details ---
        $gpWorking = Sanitize::inArray($post['gp_working'] ?? '', ['yes', 'no']);
        if ($gpWorking === false) {
            $errors['gp_working'] = 'Please answer whether you are currently working in a Gram Panchayati.';
        }
        $clean['gp_working'] = $gpWorking ?: null;

        $clean['organization_type']      = null;
        $clean['organization_name']      = null;
        $clean['organization_address']   = null;
        $clean['working_district_id']    = null;
        $clean['working_taluk_id']       = null;
        $clean['working_gp_id']          = null;
        $clean['membership_district_id'] = null;
        $clean['membership_taluk_id']    = null;
        $clean['membership_gp_id']       = null;

        if ($gpWorking === 'yes') {
            [$wDistrict, $wTaluk, $wGp, $locErrors] = self::validateWorkingLocation($post, true);
            $errors += $locErrors;
            $clean['working_district_id']    = $wDistrict;
            $clean['working_taluk_id']       = $wTaluk;
            $clean['working_gp_id']          = $wGp;
            // Membership = Working, always -- auto-assigned and locked.
            // Never derived from anything the client posted directly.
            $clean['membership_district_id'] = $wDistrict;
            $clean['membership_taluk_id']    = $wTaluk;
            $clean['membership_gp_id']       = $wGp;
        } elseif ($gpWorking === 'no') {
            $orgType = Sanitize::inArray($post['organization_type'] ?? '', array_keys(self::ORG_TYPES));
            if ($orgType === false) {
                $errors['organization_type'] = 'Please select Office/Organization Type.';
            }
            $clean['organization_type'] = $orgType ?: null;

            $orgName = Sanitize::string($post['organization_name'] ?? '', 200);
            if ($orgName === '') {
                $errors['organization_name'] = 'Office/Organization Name is required.';
            }
            $clean['organization_name'] = $orgName;

            $clean['organization_address'] = Sanitize::string($post['organization_address'] ?? '', 2000);
            if ($clean['organization_address'] === '') {
                $clean['organization_address'] = null; // optional field
            }

            if ($orgType !== false && self::isLockedOrgType($orgType)) {
                [$wDistrict, $wTaluk, , $locErrors] = self::validateWorkingLocation($post, false);
                $errors += $locErrors;
                $clean['working_district_id']    = $wDistrict;
                $clean['working_taluk_id']       = $wTaluk;
                $clean['working_gp_id']          = null; // not collected for this branch
                $clean['membership_district_id'] = $wDistrict;
                $clean['membership_taluk_id']    = $wTaluk;
                $clean['membership_gp_id']       = null;
            } else {
                // Manually selectable Membership location.
                $mDistrict = Sanitize::positiveInt($post['membership_district_id'] ?? '');
                $mTaluk    = Sanitize::positiveInt($post['membership_taluk_id'] ?? '');

                if ($mDistrict === false || Database::fetchOne('SELECT id FROM districts WHERE id = ?', [$mDistrict]) === false) {
                    $errors['membership_district_id'] = 'Please select a valid Membership District.';
                    $mDistrict = null;
                }
                if ($mTaluk === false) {
                    $errors['membership_taluk_id'] = 'Please select a valid Membership Taluk.';
                    $mTaluk = null;
                } elseif ($mDistrict !== null) {
                    $row = Database::fetchOne('SELECT id FROM taluks WHERE id = ? AND district_id = ?', [$mTaluk, $mDistrict]);
                    if ($row === false) {
                        $errors['membership_taluk_id'] = 'Selected Taluk does not belong to the selected District.';
                        $mTaluk = null;
                    }
                }

                $clean['membership_district_id'] = $mDistrict;
                $clean['membership_taluk_id']    = $mTaluk;
                $clean['membership_gp_id']       = null;
            }
        }

        // Duplicate checks last, so a member gets every other validation
        // error at once rather than one field at a time.
        if ($email !== false && Database::fetchOne('SELECT id FROM member_profiles WHERE personal_email = ?', [$email]) !== false
            && !self::emailBelongsToRetryableMember($email, $kgid)) {
            $errors['email'] = 'This email address is already registered.';
        }
        if ($kgid !== '' && Database::fetchOne('SELECT id FROM member_profiles WHERE kgid_no = ?', [$kgid]) !== false
            && !self::kgidBelongsToRetryableMember($kgid, $email !== false ? $email : '')) {
            $errors['kgid_no'] = 'This KGID No. is already registered.';
        }

        return ['errors' => $errors, 'clean' => $clean];
    }

    /**
     * @return array{0: ?int, 1: ?int, 2: ?int, 3: array<string,string>}
     */
    private static function validateWorkingLocation(array $post, bool $gpOptional): array
    {
        $errors = [];
        $district = Sanitize::positiveInt($post['working_district_id'] ?? '');
        $taluk    = Sanitize::positiveInt($post['working_taluk_id'] ?? '');
        $gp       = Sanitize::positiveInt($post['working_gp_id'] ?? '');

        if ($district === false || Database::fetchOne('SELECT id FROM districts WHERE id = ?', [$district]) === false) {
            $errors['working_district_id'] = 'Please select a valid Working District.';
            $district = null;
        }
        if ($taluk === false) {
            $errors['working_taluk_id'] = 'Please select a valid Working Taluk.';
            $taluk = null;
        } elseif ($district !== null) {
            $row = Database::fetchOne('SELECT id FROM taluks WHERE id = ? AND district_id = ?', [$taluk, $district]);
            if ($row === false) {
                $errors['working_taluk_id'] = 'Selected Taluk does not belong to the selected District.';
                $taluk = null;
            }
        }

        if ($gpOptional && $gp !== false && $taluk !== null) {
            $row = Database::fetchOne('SELECT id FROM gram_panchayatis WHERE id = ? AND taluk_id = ?', [$gp, $taluk]);
            if ($row === false) {
                $errors['working_gp_id'] = 'Selected Gram Panchayati does not belong to the selected Taluk.';
                $gp = null;
            }
        } else {
            $gp = null;
        }

        return [$district, $taluk, $gp, $errors];
    }

    /**
     * A pre-existing registration under this email is treated as a safe
     * "retry", not a duplicate, only when it belongs to the SAME person
     * (matching KGID No.) and is not yet current-year eligible. Anything
     * else (different KGID, or already paid/eligible) is a real conflict.
     */
    private static function emailBelongsToRetryableMember(string $email, string $kgid): bool
    {
        $member = self::findRetryableMemberByEmail($email);
        if ($member === null) {
            return false;
        }
        return $kgid !== '' && $member['kgid_no'] === $kgid;
    }

    private static function kgidBelongsToRetryableMember(string $kgid, string $email): bool
    {
        $row = Database::fetchOne(
            'SELECT mp.member_id, mp.personal_email FROM member_profiles mp WHERE mp.kgid_no = ?',
            [$kgid]
        );
        if ($row === false) {
            return false;
        }
        $year = Membership::getCurrentYear();
        if ($year === null || Membership::isEligibleForYear((int) $row['member_id'], (int) $year['id'])) {
            return false;
        }
        return $email !== '' && $row['personal_email'] === $email;
    }

    private static function findRetryableMemberByEmail(string $email): ?array
    {
        $row = Database::fetchOne(
            'SELECT mp.member_id, mp.kgid_no FROM member_profiles mp WHERE mp.personal_email = ?',
            [$email]
        );
        if ($row === false) {
            return null;
        }
        $year = Membership::getCurrentYear();
        if ($year === null || Membership::isEligibleForYear((int) $row['member_id'], (int) $year['id'])) {
            return null; // already active -- not a safe retry, a real conflict
        }
        return $row;
    }

    // ------------------------------------------------------------------
    // DB flow
    // ------------------------------------------------------------------

    /**
     * @return array{success: bool, member_id?: int, payment_id?: int, amount?: float, financial_year?: string, error?: string}
     */
    public static function register(array $clean): array
    {
        $year = Membership::getCurrentYear();
        if ($year === null) {
            return [
                'success' => false,
                'error'   => 'Registration is temporarily unavailable: the current annual membership fee has not been configured yet. Please contact the Association.',
            ];
        }

        $existing = self::findRetryableMemberByEmail($clean['email']);
        if ($existing !== null && $existing['kgid_no'] === $clean['kgid_no']) {
            return self::retryExistingRegistration((int) $existing['member_id'], $year);
        }

        // Not a safe retry -- re-check both uniqueness constraints one
        // more time immediately before insert (defense in depth against
        // a race between validate() and here; the DB unique keys are the
        // final backstop either way).
        if (Database::fetchOne('SELECT id FROM member_profiles WHERE personal_email = ?', [$clean['email']]) !== false) {
            return ['success' => false, 'error' => 'This email address is already registered.'];
        }
        if (Database::fetchOne('SELECT id FROM member_profiles WHERE kgid_no = ?', [$clean['kgid_no']]) !== false) {
            return ['success' => false, 'error' => 'This KGID No. is already registered.'];
        }

        try {
            return Database::transaction(function () use ($clean, $year) {
                Database::execute(
                    'INSERT INTO members
                        (member_no, name, designation, gp_id, taluk_id, district_id,
                         gp_working, organization_type, organization_name, organization_address,
                         working_district_id, working_taluk_id, working_gp_id,
                         joining_date, membership_status)
                     VALUES (?, ?, \'PDO\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), \'active\')',
                    [
                        'PENDING-' . bin2hex(random_bytes(6)),
                        $clean['full_name'],
                        $clean['membership_gp_id'], $clean['membership_taluk_id'], $clean['membership_district_id'],
                        $clean['gp_working'],
                        $clean['organization_type'], $clean['organization_name'], $clean['organization_address'],
                        $clean['working_district_id'], $clean['working_taluk_id'], $clean['working_gp_id'],
                    ]
                );
                $memberId = (int) Database::lastInsertId();

                // Assign the permanent member number now that the ID is
                // known. No numbering scheme is documented for self-
                // registration (docs are silent; admin-created members use
                // a free-text member_no per admin/members.php) -- this is
                // a provisional, guaranteed-unique placeholder an admin can
                // rename later via the existing Members screen, not an
                // invented business rule.
                $memberNo = 'REG-' . str_pad((string) $memberId, 6, '0', STR_PAD_LEFT);
                Database::execute('UPDATE members SET member_no = ? WHERE id = ?', [$memberNo, $memberId]);

                Database::execute(
                    'INSERT INTO member_profiles
                        (member_id, date_of_birth, gender, father_spouse_name, personal_email, personal_mobile, kgid_no)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$memberId, $clean['dob'], $clean['gender'], $clean['father_spouse_name'], $clean['email'], $clean['phone'], $clean['kgid_no']]
                );

                $idempotencyKey = self::newIdempotencyKey();
                Database::execute(
                    "INSERT INTO membership_payments (member_id, membership_year_id, amount, status, idempotency_key)
                     VALUES (?, ?, ?, 'pending', ?)",
                    [$memberId, $year['id'], $year['fee_amount'], $idempotencyKey]
                );
                $paymentId = (int) Database::lastInsertId();

                AuditLogger::log('CREATE', 'members', $memberId, null, [
                    'member_no' => $memberNo, 'name' => $clean['full_name'], 'source' => 'self_registration',
                ]);
                AuditLogger::log('CREATE', 'membership_payments', $paymentId, null, [
                    'member_id' => $memberId, 'membership_year_id' => $year['id'],
                    'amount' => $year['fee_amount'], 'status' => 'pending',
                ]);

                return [
                    'success'        => true,
                    'member_id'      => $memberId,
                    'payment_id'     => $paymentId,
                    'amount'         => (float) $year['fee_amount'],
                    'financial_year' => $year['financial_year'],
                ];
            });
        } catch (Throwable $e) {
            error_log('[KSPDOWA][Registration] register() failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Registration could not be completed due to a system error. Please try again.'];
        }
    }

    /**
     * Lightweight resume for a registered-but-unpaid member reached from
     * member-login.php's "not eligible" screen -- the SAME two-factor
     * (email + KGID No.) match already used by register()'s safe retry
     * path above, just without re-entering the whole registration form.
     * Never creates a new `members` row -- only ever reuses/creates a
     * 'pending' membership_payments row for the CURRENT financial year
     * against an EXISTING member found by an exact email+KGID pair.
     *
     * Returns null (never a distinguishing error) when the email/KGID
     * pair does not identify a member -- caller (member-login.php) must
     * show the identical "not eligible" message for that as for a fully
     * unknown email, so this cannot be used to discover which
     * emails/KGID numbers belong to registered members.
     *
     * @return array{member_id:int, payment_id:int}|null
     */
    public static function resumePendingPayment(string $email, string $kgid): ?array
    {
        if ($email === '' || $kgid === '') {
            return null;
        }

        $row = Database::fetchOne(
            'SELECT mp.member_id FROM member_profiles mp WHERE mp.personal_email = ? AND mp.kgid_no = ?',
            [$email, $kgid]
        );
        if ($row === false) {
            return null;
        }
        $memberId = (int) $row['member_id'];

        $year = Membership::getCurrentYear();
        if ($year === null) {
            return null;
        }

        try {
            return Database::transaction(function () use ($memberId, $year) {
                $pending = Database::fetchOne(
                    "SELECT id FROM membership_payments
                     WHERE member_id = ? AND membership_year_id = ? AND status = 'pending'
                     ORDER BY id DESC LIMIT 1",
                    [$memberId, $year['id']]
                );

                if ($pending !== false) {
                    $paymentId = (int) $pending['id'];
                } else {
                    $idempotencyKey = self::newIdempotencyKey();
                    Database::execute(
                        "INSERT INTO membership_payments (member_id, membership_year_id, amount, status, idempotency_key)
                         VALUES (?, ?, ?, 'pending', ?)",
                        [$memberId, $year['id'], $year['fee_amount'], $idempotencyKey]
                    );
                    $paymentId = (int) Database::lastInsertId();
                    AuditLogger::log('CREATE', 'membership_payments', $paymentId, null, [
                        'member_id' => $memberId, 'membership_year_id' => $year['id'],
                        'amount' => $year['fee_amount'], 'status' => 'pending', 'source' => 'member_login_resume',
                    ]);
                }

                return ['member_id' => $memberId, 'payment_id' => $paymentId];
            });
        } catch (Throwable $e) {
            error_log('[KSPDOWA][Registration] resumePendingPayment() failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Safe retry for a member who already exists (same email + KGID) but
     * is not yet current-year eligible: never creates a second `members`
     * row. Reuses an existing untouched 'pending' payment row for the
     * current year if one exists; otherwise creates a fresh pending row,
     * deliberately leaving any 'failed'/'refunded' rows exactly as they
     * are so the failed attempt stays in history rather than being
     * overwritten.
     */
    private static function retryExistingRegistration(int $memberId, array $year): array
    {
        try {
            return Database::transaction(function () use ($memberId, $year) {
                $pending = Database::fetchOne(
                    "SELECT id FROM membership_payments
                     WHERE member_id = ? AND membership_year_id = ? AND status = 'pending'
                     ORDER BY id DESC LIMIT 1",
                    [$memberId, $year['id']]
                );

                if ($pending !== false) {
                    $paymentId = (int) $pending['id'];
                } else {
                    $idempotencyKey = self::newIdempotencyKey();
                    Database::execute(
                        "INSERT INTO membership_payments (member_id, membership_year_id, amount, status, idempotency_key)
                         VALUES (?, ?, ?, 'pending', ?)",
                        [$memberId, $year['id'], $year['fee_amount'], $idempotencyKey]
                    );
                    $paymentId = (int) Database::lastInsertId();
                    AuditLogger::log('CREATE', 'membership_payments', $paymentId, null, [
                        'member_id' => $memberId, 'membership_year_id' => $year['id'],
                        'amount' => $year['fee_amount'], 'status' => 'pending', 'source' => 'registration_retry',
                    ]);
                }

                return [
                    'success'        => true,
                    'member_id'      => $memberId,
                    'payment_id'     => $paymentId,
                    'amount'         => (float) $year['fee_amount'],
                    'financial_year' => $year['financial_year'],
                    'retry'          => true,
                ];
            });
        } catch (Throwable $e) {
            error_log('[KSPDOWA][Registration] retryExistingRegistration() failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Registration could not be completed due to a system error. Please try again.'];
        }
    }

    // ------------------------------------------------------------------
    // Payment attempt lifecycle (Razorpay Standard Checkout + Idempotency)
    // ------------------------------------------------------------------

    /**
     * Generate a cryptographically secure UUID v4, used as the
     * idempotency_key for exactly one payment ATTEMPT (one
     * membership_payments row) -- approved spec: "Generate
     * cryptographically secure UUID v4 idempotency_key for every NEW
     * payment attempt. Store it with UNIQUE constraint."
     */
    private static function newIdempotencyKey(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant 10xx

        $hex = bin2hex($data);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * Mark a still-pending payment attempt as failed (a declined card,
     * a cancelled/dismissed Standard Checkout modal, or an explicit
     * failure callback from Razorpay). No-op if the row is not
     * currently 'pending' -- a payment that is already 'completed'
     * must never be downgraded by a late/duplicate failure signal, and
     * an already-'failed' row does not need marking again.
     *
     * The row itself is never deleted -- approved spec: "Preserve
     * previous failed attempts."
     */
    public static function markPaymentFailed(int $paymentId, string $reason = ''): void
    {
        try {
            $row = Database::fetchOne('SELECT id, status FROM membership_payments WHERE id = ?', [$paymentId]);
            if ($row === false || $row['status'] !== 'pending') {
                return;
            }

            Database::execute(
                "UPDATE membership_payments SET status = 'failed' WHERE id = ? AND status = 'pending'",
                [$paymentId]
            );

            AuditLogger::log('UPDATE', 'membership_payments', $paymentId, ['status' => 'pending'], [
                'status' => 'failed',
                'reason' => mb_substr($reason, 0, 500, 'UTF-8'),
            ]);
        } catch (Throwable $e) {
            error_log('[KSPDOWA][Registration] markPaymentFailed() failed: ' . $e->getMessage());
        }
    }

    /**
     * Start a fresh payment attempt for a member -- approved spec:
     * "Failed/cancelled retry creates a NEW payment attempt, NEW key
     * and NEW Razorpay Order. Preserve previous failed attempts."
     *
     * Reuses an existing 'pending' row for the current year if one
     * already exists (nothing to retry yet -- e.g. the member simply
     * reloaded the payment page without ever reaching Razorpay), so
     * this is also safe to call unconditionally from a "Pay Now" /
     * "Try Again" action without first checking payment state.
     * Otherwise always inserts a brand-new row with a brand-new
     * idempotency key, leaving every 'failed'/'refunded' row exactly
     * as it is.
     *
     * @return array{success: bool, payment_id?: int, amount?: float, financial_year?: string, error?: string}
     */
    public static function startNewPaymentAttempt(int $memberId): array
    {
        $year = Membership::getCurrentYear();
        if ($year === null) {
            return [
                'success' => false,
                'error'   => 'The current annual membership fee has not been configured yet. Please contact the Association.',
            ];
        }

        try {
            return Database::transaction(function () use ($memberId, $year) {
                $pending = Database::fetchOne(
                    "SELECT id, amount FROM membership_payments
                     WHERE member_id = ? AND membership_year_id = ? AND status = 'pending'
                     ORDER BY id DESC LIMIT 1",
                    [$memberId, $year['id']]
                );

                if ($pending !== false) {
                    return [
                        'success'        => true,
                        'payment_id'     => (int) $pending['id'],
                        'amount'         => (float) $pending['amount'],
                        'financial_year' => $year['financial_year'],
                    ];
                }

                $idempotencyKey = self::newIdempotencyKey();
                Database::execute(
                    "INSERT INTO membership_payments (member_id, membership_year_id, amount, status, idempotency_key)
                     VALUES (?, ?, ?, 'pending', ?)",
                    [$memberId, $year['id'], $year['fee_amount'], $idempotencyKey]
                );
                $paymentId = (int) Database::lastInsertId();

                AuditLogger::log('CREATE', 'membership_payments', $paymentId, null, [
                    'member_id' => $memberId, 'membership_year_id' => $year['id'],
                    'amount' => $year['fee_amount'], 'status' => 'pending', 'source' => 'payment_retry_new_attempt',
                ]);

                return [
                    'success'        => true,
                    'payment_id'     => $paymentId,
                    'amount'         => (float) $year['fee_amount'],
                    'financial_year' => $year['financial_year'],
                ];
            });
        } catch (Throwable $e) {
            error_log('[KSPDOWA][Registration] startNewPaymentAttempt() failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Could not start a new payment attempt due to a system error. Please try again.'];
        }
    }
}
