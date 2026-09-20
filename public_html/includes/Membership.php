<?php
/**
 * KSPDOWA — Membership Eligibility Helper
 * ============================================================
 * Centralizes the "current financial year" and "current-year paid
 * member" queries so every part of the system agrees on exactly one
 * definition. Do not duplicate these queries elsewhere -- call these
 * methods instead (admin/membership-setup.php, login.php, and
 * member-login.php all use this class).
 *
 * "Current year"  = the single membership_years row with
 *                    status = 'active' (migrations/004_finance.sql).
 *                    admin/membership-setup.php enforces at most one
 *                    such row at a time.
 * "Eligible"      = a membership_payments row for that member and
 *                    that year with status = 'completed' -- i.e. a
 *                    server-verified payment, never a client-supplied
 *                    signal. Migration 012 prevents more than one such
 *                    row existing per member/year.
 *
 * Architecture rule (approved spec): eligibility is checked against
 * the CURRENT year only. A member who paid a previous year but not
 * the current one is not eligible, and historical rows are never
 * deleted or altered by this class.
 * ============================================================
 */

declare(strict_types=1);

class Membership
{
    /**
     * Existing Razorpay hosted payment page for the current annual fee
     * cycle. Single source of truth -- member-login.php and register.php
     * both link out to this same URL rather than each declaring their own
     * constant. Approved spec: "Use the existing Razorpay flow initially.
     * Do not replace it with a new checkout without approval."
     */
    public const RAZORPAY_ANNUAL_FEE_URL = 'https://pages.razorpay.com/KSPDOWAFEE2026';

    /**
     * The single active (current) financial year, or null if none is
     * marked active yet.
     */
    public static function getCurrentYear(): ?array
    {
        $row = Database::fetchOne(
            "SELECT * FROM membership_years WHERE status = 'active' ORDER BY start_date DESC LIMIT 1"
        );
        return $row !== false ? $row : null;
    }

    /**
     * Whether the given member has a server-verified completed payment
     * for the given membership year.
     */
    public static function isEligibleForYear(int $memberId, int $membershipYearId): bool
    {
        $row = Database::fetchOne(
            "SELECT id FROM membership_payments
             WHERE member_id = ? AND membership_year_id = ? AND status = 'completed'
             LIMIT 1",
            [$memberId, $membershipYearId]
        );
        return $row !== false;
    }

    /**
     * Whether the given member is eligible for the CURRENT year
     * specifically. False (not an error) when no year is marked
     * current yet.
     */
    public static function isCurrentYearEligible(int $memberId): bool
    {
        $year = self::getCurrentYear();
        return $year !== null && self::isEligibleForYear($memberId, (int) $year['id']);
    }

    /**
     * Find the member whose registered personal email matches, AND who
     * is current-year eligible. Returns null both when the email
     * matches no member and when it matches a member who has not paid
     * the current year -- callers must not distinguish these two cases
     * in any response shown to the person entering the email (doing so
     * would reveal which email addresses belong to registered members).
     */
    public static function findEligibleMemberByEmail(string $email): ?array
    {
        $year = self::getCurrentYear();
        if ($year === null) {
            return null;
        }

        $member = Database::fetchOne(
            'SELECT m.* FROM members m
             JOIN member_profiles mp ON mp.member_id = m.id
             WHERE mp.personal_email = ?
             LIMIT 1',
            [$email]
        );

        if ($member === false) {
            return null;
        }

        if (!self::isEligibleForYear((int) $member['id'], (int) $year['id'])) {
            return null;
        }

        return $member;
    }

    /**
     * Fetch a membership year by its ID, or null if not found.
     */
    public static function getYearById(int $yearId): ?array
    {
        $row = Database::fetchOne('SELECT * FROM membership_years WHERE id = ?', [$yearId]);
        return $row !== false ? $row : null;
    }
}
