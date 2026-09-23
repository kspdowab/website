<?php
/**
 * KSPDOWA — Donation Receipt Generation
 * ============================================================
 * Donation equivalent of Receipt.php (membership payments). The
 * approved donations schema (docs/02_DATABASE_SCHEMA.md) already puts
 * receipt_no directly on the donations row rather than in a separate
 * table -- migration 023 adds receipt_file_path alongside it the same
 * way, rather than introducing a second payment_receipts-style table.
 *
 * Reuses Receipt::renderLetterhead() and Receipt::latin1() (made
 * public for this purpose) so the donation receipt shares the exact
 * same letterhead (logos, association name/address, contact line) as
 * a membership receipt, without duplicating that FPDF code.
 *
 * Donation receipts get their own numbering sequence, admin-
 * configurable via system_settings (donation_receipt_no_prefix /
 * donation_receipt_no_year_format / donation_receipt_no_pad_length --
 * migration 023), defaulting to KSPDOWA-DON-YYYY-NNNNN -- kept
 * distinct from membership receipts' KSPDOWA-RCP-YYYY-NNNNN so the two
 * sequences never share numbers.
 *
 * Generated eagerly right after DonationGateway::confirmPayment()
 * succeeds (donate-verify.php), not lazily on first view like
 * membership receipts -- a donor has no login/portal to come back to
 * later, so the download link only ever needs to work from the
 * same-session thank-you page.
 * ============================================================
 */

declare(strict_types=1);

class DonationReceipt
{
    /**
     * Return the existing receipt for a completed donation, or
     * generate one now if none exists yet.
     *
     * @param int $donationId donations.id (caller must already have
     *                        verified this donation is 'completed')
     * @return array|false donations row (with receipt_no/receipt_file_path
     *                      populated), or false on failure
     */
    public static function forDonation(int $donationId): array|false
    {
        $donation = Database::fetchOne(
            "SELECT * FROM donations WHERE id = ? AND status = 'completed'",
            [$donationId]
        );
        if ($donation === false) {
            return false;
        }

        if ($donation['receipt_no'] !== null && $donation['receipt_file_path'] !== null) {
            return $donation;
        }

        return self::generate($donation);
    }

    /**
     * Generate a new receipt (number + PDF file) for a completed
     * donation. Never regenerates for a donation that already has
     * one -- callers should use forDonation() rather than calling
     * this directly.
     */
    private static function generate(array $donation): array|false
    {
        $donationId = (int) $donation['id'];

        $prefix     = trim(Settings::get('donation_receipt_no_prefix', 'KSPDOWA-DON'));
        $yearFormat = trim(Settings::get('donation_receipt_no_year_format', 'Y'));
        $padLength  = (int) Settings::get('donation_receipt_no_pad_length', '5');
        if ($prefix === '') {
            $prefix = 'KSPDOWA-DON';
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

                $clash = Database::fetchOne('SELECT id FROM donations WHERE receipt_no = ?', [$candidate]);
                if ($clash === false) {
                    $receiptNo = $candidate;
                    break;
                }
            }

            if ($receiptNo === null) {
                error_log('[KSPDOWA][DonationReceipt] Could not allocate a unique receipt number for donation ' . $donationId);
                return false;
            }

            $relativePath = 'receipts/' . $receiptNo . '.pdf';
            $absolutePath = UPLOADS_DIR . '/' . $relativePath;

            if (!is_dir(dirname($absolutePath))) {
                mkdir(dirname($absolutePath), 0755, true);
            }

            self::renderPdf($absolutePath, $receiptNo, $donation);

            return Database::transaction(function () use ($donationId, $receiptNo, $relativePath) {
                Database::execute(
                    'UPDATE donations SET receipt_no = ?, receipt_file_path = ? WHERE id = ?',
                    [$receiptNo, $relativePath, $donationId]
                );

                AuditLogger::log('CREATE', 'donations', $donationId, null, [
                    'receipt_no' => $receiptNo,
                ]);

                return Database::fetchOne('SELECT * FROM donations WHERE id = ?', [$donationId]);
            });
        } catch (Throwable $e) {
            error_log('[KSPDOWA][DonationReceipt] generate() failed for donation ' . $donationId . ': ' . $e->getMessage());
            return false;
        }
    }

    private static function nextSequenceForYear(string $prefix, string $yearStr): int
    {
        $likePrefix = $prefix . '-' . $yearStr . '-';
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM donations WHERE receipt_no LIKE ?",
            [$likePrefix . '%']
        );
        $count = $row !== false ? (int) $row['c'] : 0;
        return $count + 1;
    }

    /**
     * Render the donation receipt PDF. Layout mirrors Receipt::renderPdf()
     * (letterhead, Receipt No/Date row, boxed heading, details table,
     * disclaimer) but with donation-specific fields (donor name,
     * purpose, amount, payment reference) instead of membership fields.
     */
    private static function renderPdf(string $absolutePath, string $receiptNo, array $donation): void
    {
        require_once INCLUDES_DIR . '/lib/fpdf.php';

        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetTitle('Donation Receipt ' . $receiptNo);
        $pdf->SetMargins(20, 15, 20);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        Receipt::renderLetterhead($pdf);

        $printableWidth = $pdf->GetPageWidth() - 20 - 20;

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell($printableWidth / 2, 7, Receipt::latin1('Receipt No: ' . $receiptNo), 0, 0, 'L');
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell($printableWidth / 2, 7, 'Date: ' . date('d M Y', strtotime((string) ($donation['paid_at'] ?? 'now'))), 0, 1, 'R');
        $pdf->Ln(3);

        $pdf->SetFont('Arial', 'B', 13);
        $boxLabel = 'Donation Receipt';
        $boxWidth = $pdf->GetStringWidth($boxLabel) + 16;
        $pdf->SetX(($pdf->GetPageWidth() - $boxWidth) / 2);
        $pdf->Cell($boxWidth, 10, $boxLabel, 1, 1, 'C');
        $pdf->Ln(8);

        $purpose = trim((string) ($donation['purpose'] ?? ''));

        $rows = [
            ['Donor Name',        (string) $donation['donor_name']],
        ];

        if (!empty($donation['donor_mobile'])) {
            $rows[] = ['Mobile Number', (string) $donation['donor_mobile']];
        }

        if (!empty($donation['donor_address'])) {
            $rows[] = ['Address / Place', (string) $donation['donor_address']];
        }

        $rows[] = ['Purpose',           $purpose !== '' ? $purpose : 'General Donation'];
        $rows[] = ['Amount Received',   'Rs. ' . number_format((float) $donation['amount'], 2)];
        $rows[] = ['Payment Reference', (string) ($donation['gateway_payment_id'] ?? '')];

        $pdf->SetFont('Arial', '', 11);
        foreach ($rows as [$label, $value]) {
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell(58, 8, Receipt::latin1($label), 0, 0);
            $pdf->SetFont('Arial', '', 11);
            $pdf->Cell(0, 8, Receipt::latin1($value), 0, 1);
        }

        $pdf->Ln(10);
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->MultiCell(0, 5, Receipt::latin1(
            'This is a system-generated receipt for a voluntary donation made to the Association via Razorpay. ' .
            'This donation is separate from, and is not, an annual membership fee payment. Convenience charges ' .
            'collected by Razorpay, if any, are not part of the donation amount shown above and are retained by Razorpay.'
        ));

        $pdf->Output('F', $absolutePath);
    }
}
