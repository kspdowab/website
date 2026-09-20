<?php
/**
 * KSPDOWA — Transactional Email Templates
 * ============================================================
 * Generates styled, responsive HTML emails adhering to the official
 * KSPDOWA visual guidelines (Navy: #173F67, Blue: #1769AA, Slate: #F5F7FA).
 * Ensures compatibility across webmail and desktop clients (Outlook,
 * Gmail, Apple Mail) using inline tables and styles.
 * ============================================================
 */

declare(strict_types=1);

class EmailTemplates
{
    /**
     * Wrap content inside the official KSPDOWA email shell.
     */
    public static function wrap(string $title, string $htmlContent, ?string $actionUrl = null, ?string $actionLabel = null): string
    {
        $siteName  = class_exists('Settings') ? Settings::get('site_name', APP_FULL_NAME) : APP_FULL_NAME;
        $shortName = class_exists('Settings') ? Settings::get('site_short_name', APP_SHORT_NAME) : APP_SHORT_NAME;
        $siteUrl   = defined('APP_URL') ? APP_URL : 'https://kspdowa.org';

        $actionButton = '';
        if ($actionUrl !== null && $actionLabel !== null) {
            $actionButton = '
            <table border="0" cellpadding="0" cellspacing="0" style="margin: 24px 0 16px;">
                <tr>
                    <td align="center" style="border-radius: 6px; background-color: #1769AA;">
                        <a href="' . htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank"
                           style="display: inline-block; padding: 12px 28px; font-family: Arial, sans-serif; font-size: 14px; font-weight: bold; color: #ffffff; text-decoration: none; border-radius: 6px;">
                            ' . htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8') . '
                        </a>
                    </td>
                </tr>
            </table>';
        }

        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>
</head>
<body style="margin: 0; padding: 0; background-color: #F0F4F8; font-family: Arial, Helvetica, sans-serif; color: #1e293b; line-height: 1.6;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #F0F4F8; padding: 24px 12px;">
        <tr>
            <td align="center">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.06);">
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #173F67; padding: 24px 30px; text-align: center;">
                            <h1 style="margin: 0 0 6px; color: #ffffff; font-size: 20px; font-weight: bold; letter-spacing: 0.5px;">
                                ' . htmlspecialchars($shortName, ENT_QUOTES, 'UTF-8') . '
                            </h1>
                            <p style="margin: 0; color: #93c5fd; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">
                                ' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Sub-header Title -->
                    <tr>
                        <td style="background-color: #1e4b7a; padding: 12px 30px; text-align: center; border-bottom: 2px solid #3b82f6;">
                            <span style="color: #ffffff; font-size: 14px; font-weight: 600;">
                                ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '
                            </span>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 30px; font-size: 14px; color: #334155;">
                            ' . $htmlContent . '
                            ' . $actionButton . '
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8fafc; padding: 20px 30px; border-top: 1px solid #e2e8f0; text-align: center; font-size: 12px; color: #64748b;">
                            <p style="margin: 0 0 6px;">
                                This is an official transactional notification sent to you by <strong>' . htmlspecialchars($shortName, ENT_QUOTES, 'UTF-8') . '</strong>.
                            </p>
                            <p style="margin: 0 0 10px;">
                                Designed &amp; Developed by : KHUBAASING JADAV
                            </p>
                            <p style="margin: 0; font-size: 11px; color: #94a3b8;">
                                Please do not reply directly to this automated email. Visit <a href="' . htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') . '" style="color: #1769AA; text-decoration: underline;">' . htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') . '</a> for inquiries.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }

    /**
     * Key-Value summary box for data tables in emails.
     */
    public static function renderSummaryTable(array $rows): string
    {
        $html = '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 18px 0; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; background-color: #fcfcfc;">';
        foreach ($rows as $label => $val) {
            $html .= '<tr>
                <td style="padding: 10px 14px; border-bottom: 1px solid #f1f5f9; font-weight: bold; color: #475569; width: 40%; font-size: 13px; background-color: #f8fafc;">
                    ' . htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') . '
                </td>
                <td style="padding: 10px 14px; border-bottom: 1px solid #f1f5f9; color: #1e293b; font-size: 13px;">
                    ' . htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8') . '
                </td>
            </tr>';
        }
        $html .= '</table>';
        return $html;
    }
}
