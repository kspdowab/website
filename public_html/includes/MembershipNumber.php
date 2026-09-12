<?php
/**
 * KSPDOWA — District-Coded Membership Number Assignment
 * ============================================================
 * Approved format: KSPDOWA-{DISTRICT_SHORT_CODE}-{4-digit serial},
 * e.g. KSPDOWA-BGK-0001, KSPDOWA-BGK-0002, KSPDOWA-VJP-0001. Short
 * codes are usually 3 letters but districts.short_code is CHAR(4)
 * (migration 021) because BENGALURU / BENGALURU RURAL / BENGALURU
 * SOUTH need a 4th letter to stay distinct (BLRU/BLRR/BLRS) -- this
 * code never assumes a fixed width, it just uses whatever is stored.
 * The serial is sequential and never reused WITHIN each district
 * (districts.last_member_serial, migration 020) -- it is not a
 * global sequence.
 *
 * Assigned into the EXISTING members.member_no column, not a new
 * column, and ONLY when that column still holds the auto-generated
 * "REG-<member id>" placeholder that Registration::register() writes
 * at self-registration time. An admin-typed member_no
 * (admin/members.php's free-text field) or an already-assigned real
 * Membership Number is never touched -- "Existing Membership Numbers
 * must not be changed automatically" per the approved spec.
 *
 * Called from exactly one place: Auth::activateMemberPortalAccess(),
 * the project's single existing membership-activation choke point
 * (shared by the Razorpay payment-confirmation flow and the
 * member-login "I already paid" resume flow) -- never from
 * registration, never from client-supplied input.
 *
 * IMPORTANT: assignIfPlaceholder() does NOT open its own transaction
 * -- this Database class does not support nested transactions
 * (documented in PaymentGateway.php / Auth.php). It must only be
 * called from inside a transaction the caller already started, and
 * it takes row locks (SELECT ... FOR UPDATE) on both the member row
 * and the district row within that transaction, so two concurrent
 * activations for the same district can never receive the same
 * serial, and a concurrent activation for the same member can never
 * assign a number twice.
 * ============================================================
 */

declare(strict_types=1);

class MembershipNumber
{
    private const PREFIX = 'KSPDOWA';

    /** Matches only the auto-generated placeholder from Registration::register() -- never an admin-typed value. */
    private const PLACEHOLDER_PATTERN = '/^REG-\d+$/';

    public static function isPlaceholder(string $memberNo): bool
    {
        return preg_match(self::PLACEHOLDER_PATTERN, $memberNo) === 1;
    }

    /**
     * Assign a real district-coded Membership Number, replacing the
     * current member_no, but ONLY if it is still the REG-NNNNNN
     * placeholder. Returns the new number on success, or null if
     * nothing was assigned (already a real number, admin-typed value,
     * missing district, missing district short_code, or any error --
     * every failure mode is logged and treated as non-fatal so it
     * never blocks the surrounding login-activation flow).
     *
     * Must be called from inside an existing transaction. Never
     * throws -- caught internally so a numbering problem can never
     * turn into an activation failure (a member should still get
     * portal access even if, for some data reason, a real Membership
     * Number could not yet be minted).
     */
    public static function assignIfPlaceholder(int $memberId): ?string
    {
        try {
            $member = Database::fetchOne(
                'SELECT member_no, district_id FROM members WHERE id = ? FOR UPDATE',
                [$memberId]
            );

            if ($member === false) {
                return null;
            }

            if (!self::isPlaceholder((string) $member['member_no'])) {
                // Already a real number, or an admin-typed free-text
                // value -- never touch either.
                return null;
            }

            if ($member['district_id'] === null) {
                error_log('[KSPDOWA][MembershipNumber] member_id=' . $memberId . ' has no district_id -- cannot assign a Membership Number.');
                return null;
            }

            $districtId = (int) $member['district_id'];

            $district = Database::fetchOne(
                'SELECT short_code, last_member_serial FROM districts WHERE id = ? FOR UPDATE',
                [$districtId]
            );

            if ($district === false || trim((string) $district['short_code']) === '') {
                error_log('[KSPDOWA][MembershipNumber] district_id=' . $districtId . ' has no short_code -- cannot assign a Membership Number.');
                return null;
            }

            $nextSerial   = (int) $district['last_member_serial'] + 1;
            $membershipNo = sprintf('%s-%s-%04d', self::PREFIX, $district['short_code'], $nextSerial);

            Database::execute(
                'UPDATE districts SET last_member_serial = ? WHERE id = ?',
                [$nextSerial, $districtId]
            );
            Database::execute(
                'UPDATE members SET member_no = ? WHERE id = ?',
                [$membershipNo, $memberId]
            );

            AuditLogger::log('MEMBERSHIP_NUMBER_ASSIGNED', 'members', $memberId, [
                'member_no' => $member['member_no'],
            ], [
                'member_no'   => $membershipNo,
                'district_id' => $districtId,
                'serial'      => $nextSerial,
            ]);

            return $membershipNo;
        } catch (Throwable $e) {
            error_log('[KSPDOWA][MembershipNumber] assignIfPlaceholder() failed for member_id=' . $memberId . ': ' . $e->getMessage());
            return null;
        }
    }
}
