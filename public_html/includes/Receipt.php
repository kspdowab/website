<?php
/**
 * KSPDOWA — Payment Receipt Generation
 * ============================================================
 * Phase 3 unit (approved build order: Payment History -> Receipts ->
 * Donations; approved format: PREFIX-YYYY-NNNNN + PDF, defaulting to
 * KSPDOWA-RCP-YYYY-NNNNN). The prefix, year format, and zero-pad
 * length are admin-configurable (system_settings: receipt_no_prefix /
 * receipt_no_year_format / receipt_no_pad_length -- migration 019,
 * editable via admin/settings.php), not fixed PHP constants -- per
 * explicit request. RECEIPT_PREFIX (config/app.php) is kept only as
 * the fallback default if a setting row is ever missing.
 *
 * Deliberately lazy / on-demand: a receipt is generated the first
 * time it is requested (member/receipt.php), not automatically the
 * moment a payment completes. This keeps PaymentGateway::confirmPayment()
 * -- already validated end-to-end against live Razorpay test-mode
 * data this session -- completely untouched. Idempotent: payment_id
 * has a UNIQUE key on payment_receipts (migration 004), so a second
 * call for the same payment just returns the existing row.
 *
 * PDF rendering uses the vendored FPDF library (includes/lib/fpdf.php,
 * v1.9, MIT licensed, single self-contained file, no Composer/vendor
 * tree needed) -- consistent with this project's existing "no external
 * dependency manager" convention (RazorpayClient.php is hand-built
 * raw cURL for the same reason).
 *
 * Membership No. shown on the receipt is members.member_no itself --
 * NOT reformatted here. As of migration 020, member_no is assigned as
 * a real district-coded value (KSPDOWA-{DISTRICT_SHORT_CODE}-{4-digit
 * per-district serial}, e.g. KSPDOWA-BGK-0001) by MembershipNumber.php,
 * called from Auth::activateMemberPortalAccess() at the moment a member
 * is first activated -- the only place a receipt can exist implies
 * activation already happened, so member_no is guaranteed to already
 * be the final value by the time renderPdf() runs. Do not reintroduce
 * a cosmetic reformatting helper here (a former formatMemberNo() did
 * this and was removed -- it assumed a purely-numeric placeholder and
 * would silently corrupt real district-coded values).
 * ============================================================
 */

declare(strict_types=1);

class Receipt
{
    /**
     * Return the existing receipt row for a completed payment, or
     * generate one now if none exists yet.
     *
     * @param int $paymentId membership_payments.id (caller must already
     *                       have verified ownership + status='completed')
     * @return array|false payment_receipts row, or false on failure
     */
    public static function forPayment(int $paymentId): array|false
    {
        $existing = Database::fetchOne(
            'SELECT * FROM payment_receipts WHERE payment_id = ?',
            [$paymentId]
        );
        if ($existing !== false) {
            return $existing;
        }

        return self::generate($paymentId);
    }

    /**
     * Generate a new receipt (number + PDF file) for a completed payment.
     * Never regenerates for a payment_id that already has one -- callers
     * should use forPayment() rather than calling this directly.
     */
    private static function generate(int $paymentId): array|false
    {
        $payment = Database::fetchOne(
            "SELECT mp.*, my.financial_year, m.member_no, m.name AS member_name,
                    m.designation, pr.personal_email,
                    d.name AS district_name, t.name AS taluk_name
             FROM membership_payments mp
             JOIN membership_years my ON my.id = mp.membership_year_id
             JOIN members m           ON m.id  = mp.member_id
             LEFT JOIN member_profiles pr ON pr.member_id = mp.member_id
             LEFT JOIN districts d        ON d.id = m.district_id
             LEFT JOIN taluks t           ON t.id = m.taluk_id
             WHERE mp.id = ? AND mp.status = 'completed'",
            [$paymentId]
        );

        if ($payment === false) {
            // Not completed (or does not exist) -- nothing to issue a receipt for.
            return false;
        }

        // Format is admin-configurable (admin/settings.php -- migration
        // 019); these three Settings keys default to reproducing the
        // originally hard-coded RECEIPT_PREFIX-YYYY-NNNNN scheme exactly.
        $prefix     = trim(Settings::get('receipt_no_prefix', RECEIPT_PREFIX));
        $yearFormat = trim(Settings::get('receipt_no_year_format', 'Y'));
        $padLength  = (int) Settings::get('receipt_no_pad_length', '5');
        if ($prefix === '') {
            $prefix = RECEIPT_PREFIX;
        }
        if ($yearFormat === '') {
            $yearFormat = 'Y';
        }
        if ($padLength < 1 || $padLength > 10) {
            $padLength = 5;
        }
        $yearStr = date($yearFormat);

        $receiptNo   = null;
        $maxAttempts = 5;

        try {
            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                $seq = self::nextSequenceForYear($prefix, $yearStr);
                $candidate = sprintf('%s-%s-%0' . $padLength . 'd', $prefix, $yearStr, $seq);

                $clash = Database::fetchOne(
                    'SELECT id FROM payment_receipts WHERE receipt_no = ?',
                    [$candidate]
                );
                if ($clash === false) {
                    $receiptNo = $candidate;
                    break;
                }
                // Extremely unlikely (concurrent generation in the same
                // second) -- loop and try the next sequence number.
            }

            if ($receiptNo === null) {
                error_log('[KSPDOWA][Receipt] Could not allocate a unique receipt number for payment ' . $paymentId);
                return false;
            }

            $relativePath = 'receipts/' . $receiptNo . '.pdf';
            $absolutePath = UPLOADS_DIR . '/' . $relativePath;

            if (!is_dir(dirname($absolutePath))) {
                mkdir(dirname($absolutePath), 0755, true);
            }

            self::renderPdf($absolutePath, $receiptNo, $payment);

            return Database::transaction(function () use ($paymentId, $receiptNo, $relativePath) {
                Database::execute(
                    'INSERT INTO payment_receipts (payment_id, receipt_no, file_path, generated_at)
                     VALUES (?, ?, ?, NOW())',
                    [$paymentId, $receiptNo, $relativePath]
                );
                $id = (int) Database::lastInsertId();

                AuditLogger::log('CREATE', 'payment_receipts', $id, null, [
                    'payment_id' => $paymentId,
                    'receipt_no' => $receiptNo,
                ]);

                return Database::fetchOne('SELECT * FROM payment_receipts WHERE id = ?', [$id]);
            });
        } catch (Throwable $e) {
            error_log('[KSPDOWA][Receipt] generate() failed for payment ' . $paymentId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Next sequence number for a given prefix + formatted year, based on
     * how many receipts already carry that prefix/year in their
     * receipt_no. The UNIQUE key on receipt_no (plus the caller's
     * clash-check/retry loop) is the actual correctness guarantee under
     * concurrency -- this count is just where the next attempt starts
     * from. Both inputs are admin-configured (Settings), not user input.
     */
    private static function nextSequenceForYear(string $prefix, string $yearStr): int
    {
        $likePrefix = $prefix . '-' . $yearStr . '-';
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM payment_receipts WHERE receipt_no LIKE ?",
            [$likePrefix . '%']
        );
        $count = $row !== false ? (int) $row['c'] : 0;
        return $count + 1;
    }

    /**
     * Render the receipt PDF to disk using the vendored FPDF library.
     *
     * Letterhead layout (logos + association name/address either side
     * of center) mirrors the association's official letterhead image
     * provided by the user. The contact line (email/phone/website) is
     * pulled from `system_settings` (Settings::get()) -- editable via
     * admin/settings.php -- rather than hard-coded here, per explicit
     * request. FPDF's core fonts only cover Latin-1, so the heading is
     * rendered in English (the association's own site_name/site_address
     * setting values), not the Kannada script from the reference image.
     */
    private static function renderPdf(string $absolutePath, string $receiptNo, array $payment): void
    {
        require_once INCLUDES_DIR . '/lib/fpdf.php';

        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetTitle('Receipt ' . $receiptNo);
        $pdf->SetMargins(20, 15, 20);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        self::renderLetterhead($pdf);

        $printableWidth = $pdf->GetPageWidth() - 20 - 20; // matches the 20mm SetMargins() above

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell($printableWidth / 2, 7, self::latin1('Receipt No: ' . $receiptNo), 0, 0, 'L');
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell($printableWidth / 2, 7, 'Date: ' . date('d M Y', strtotime((string) ($payment['paid_at'] ?? 'now'))), 0, 1, 'R');
        $pdf->Ln(3);

        $pdf->SetFont('Arial', 'B', 13);
        $boxLabel = 'Payment Receipt';
        $boxWidth = $pdf->GetStringWidth($boxLabel) + 16;
        $pdf->SetX(($pdf->GetPageWidth() - $boxWidth) / 2);
        $pdf->Cell($boxWidth, 10, $boxLabel, 1, 1, 'C');
        $pdf->Ln(8);

        // Membership No. shown prominently on its own, separate from the
        // regular details table below -- per explicit request. This is
        // the real value now stored in members.member_no (district-coded
        // KSPDOWA-{CODE}-{4-digit serial}, assigned once at activation --
        // see MembershipNumber.php); displayed exactly as stored, with no
        // cosmetic reformatting.
        $pdf->SetFont('Arial', 'B', 13);
        $pdf->Cell(0, 8, self::latin1('Membership No.: ' . (string) $payment['member_no']), 0, 1);
        $pdf->Ln(4);

        $rows = [
            ['Member Name',        (string) $payment['member_name']],
            ['Designation',        (string) $payment['designation']],
            ['Email',              (string) ($payment['personal_email'] ?? '')],
            ['Membership District', (string) ($payment['district_name'] ?? '') !== '' ? (string) $payment['district_name'] : 'N/A'],
            ['Membership Taluk',   (string) ($payment['taluk_name'] ?? '') !== '' ? (string) $payment['taluk_name'] : 'N/A'],
            ['Membership Year',    (string) $payment['financial_year']],
            ['Amount Received',    'Rs. ' . number_format((float) $payment['amount'], 2)],
            ['Payment Reference',  (string) ($payment['gateway_payment_id'] ?? '')],
        ];

        $pdf->SetFont('Arial', '', 11);
        foreach ($rows as [$label, $value]) {
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell(58, 8, self::latin1($label), 0, 0);
            $pdf->SetFont('Arial', '', 11);
            $pdf->Cell(0, 8, self::latin1($value), 0, 1);
        }

        $pdf->Ln(10);
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->MultiCell(0, 5, self::latin1(
            'This is a system-generated receipt for the annual membership fee paid via Razorpay. ' .
            'Convenience charges collected by Razorpay, if any, are not part of the membership fee ' .
            'shown above and are retained by Razorpay.'
        ));

        $pdf->Output('F', $absolutePath);
    }

    /**
     * Logo-left / heading-center / logo-right letterhead block, plus a
     * thin rule and a contact line, all sourced from Settings (except
     * the two logo image files, which are static assets an admin
     * replaces by uploading a new file rather than through a form).
     *
     * Public (not private) so DonationReceipt.php can reuse the exact
     * same letterhead for donation receipts without duplicating this
     * FPDF code -- the only other caller besides renderPdf() below.
     */
    public static function renderLetterhead(FPDF $pdf): void
    {
        // Kept in sync with the SetMargins(20, 15, 20) call in renderPdf() --
        // FPDF has no public getter for the current margins, so the left/
        // right values used to lay out this block are tracked here as
        // plain constants rather than queried from the object.
        $leftMargin  = 20;
        $rightMargin = 20;

        $leftLogo  = self::resolveLogoPath('receipt_logo_left',  'assets/images/receipt-logo-left.png');
        $rightLogo = self::resolveLogoPath('receipt_logo_right', 'assets/images/receipt-logo-right.png');

        $pageWidth  = $pdf->GetPageWidth();
        $logoSize   = 22; // mm
        $topY       = $pdf->GetY();

        if (is_file($leftLogo)) {
            $pdf->Image($leftLogo, $leftMargin, $topY, $logoSize);
        }
        if (is_file($rightLogo)) {
            $pdf->Image($rightLogo, $pageWidth - $rightMargin - $logoSize, $topY, $logoSize);
        }

        $centerX     = $leftMargin + $logoSize + 2;
        $centerWidth = $pageWidth - $leftMargin - $rightMargin - 2 * ($logoSize + 2);

        $siteName    = Settings::get('site_name', APP_NAME);
        $siteTagline = Settings::get('site_tagline', '');
        $siteAddress = Settings::get('site_address', '');
        $siteEmail   = Settings::get('site_email', '');
        $sitePhone   = Settings::get('site_phone', '');
        $siteWebsite = Settings::get('site_website', '');

        $pdf->SetXY($centerX, $topY);
        $pdf->SetFont('Arial', 'B', 13);
        $pdf->SetTextColor(200, 0, 0);
        $pdf->MultiCell($centerWidth, 6, self::latin1($siteName), 0, 'C');
        $pdf->SetTextColor(0, 0, 0);

        if ($siteTagline !== '') {
            $pdf->SetX($centerX);
            $pdf->SetFont('Arial', 'I', 9);
            $pdf->MultiCell($centerWidth, 5, self::latin1($siteTagline), 0, 'C');
        }

        if ($siteAddress !== '') {
            $pdf->SetX($centerX);
            $pdf->SetFont('Arial', '', 9);
            $pdf->MultiCell($centerWidth, 5, self::latin1($siteAddress), 0, 'C');
        }

        // Ensure the cursor clears both logos before continuing.
        $afterTextY = $pdf->GetY();
        $pdf->SetY(max($afterTextY, $topY + $logoSize + 2));

        $contactParts = [];
        if ($siteEmail !== '') {
            $contactParts[] = 'Email: ' . $siteEmail;
        }
        if ($sitePhone !== '') {
            $contactParts[] = 'Phone: ' . $sitePhone;
        }
        if ($siteWebsite !== '') {
            $contactParts[] = 'Website: ' . preg_replace('#^https?://#i', '', $siteWebsite);
        }

        $pdf->Ln(1);
        $pdf->SetDrawColor(26, 58, 107);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($leftMargin, $pdf->GetY(), $pageWidth - $rightMargin, $pdf->GetY());
        $pdf->Ln(2);

        if (!empty($contactParts)) {
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(0, 5, self::latin1(implode('   |   ', $contactParts)), 0, 1, 'C');
        }

        $pdf->Ln(1);
        $pdf->SetDrawColor(20, 20, 20);
        $pdf->SetLineWidth(0.6);
        $pdf->Line($leftMargin, $pdf->GetY(), $pageWidth - $rightMargin, $pdf->GetY());
        $pdf->Ln(2);
    }

    /**
     * Resolve a letterhead logo to an absolute filesystem path: an
     * admin-uploaded file (system_settings, set via admin/settings.php)
     * if one is on record and still present on disk, otherwise the
     * bundled default asset cropped from the association's letterhead
     * image. Never trusts the setting value as an absolute path -- it
     * is always resolved relative to PUBLIC_HTML, same as any other
     * asset URL on this site.
     */
    private static function resolveLogoPath(string $settingKey, string $defaultRelative): string
    {
        $configured = trim(Settings::get($settingKey, ''));
        if ($configured !== '') {
            $candidate = PUBLIC_HTML . '/' . ltrim($configured, '/');
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return PUBLIC_HTML . '/' . $defaultRelative;
    }

    /**
     * FPDF's core fonts only support the Latin-1 (cp1252) character set,
     * not UTF-8. All our own text values are ASCII, but member-entered
     * data (name, designation, email) may contain characters outside
     * Latin-1; convert what we can and drop the rest rather than
     * emitting mojibake or letting FPDF throw.
     *
     * Public so DonationReceipt.php can reuse it for donor-entered text.
     */
    public static function latin1(string $text): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
        return $converted !== false ? $converted : $text;
    }
}
