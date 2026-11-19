<?php
/**
 * imis/otrs/inc/email_template.php
 * Shared HTML email building blocks for CSC RO VIII HRD email modules.
 *
 * Usage:  require_once __DIR__ . '/../../inc/email_template.php';
 *
 * Exposed functions (all guarded with function_exists):
 *   hrdEmailEsc($v)                       – htmlspecialchars wrapper
 *   hrdEmailFormatDate($start, $end)      – smart date-range label
 *   hrdEmailFormatFee($fee)               – "Php 1,500.00" or "Free"
 *   hrdEmailFormatStatus($status)         – DB value → display label
 *   hrdEmailHeader($badge)                – navy CSC RO VIII header row
 *   hrdEmailSignature()                   – Jay M. Merelos signature block
 *   hrdEmailFooter()                      – disclaimer + virus warning row
 *   hrdEmailWrap($rows, $width)           – outer HTML shell
 *   hrdEmailBankBlock()                   – Landbank details + proof-of-payment link
 *   hrdEmailPenaltyNote()                 – 80% penalty paragraph
 *   hrdEmailContact()                     – "(053) 888-1811" contact line
 */

if (!function_exists('hrdEmailEsc')) {
    function hrdEmailEsc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('hrdEmailFormatDate')) {
    function hrdEmailFormatDate(?string $start, ?string $end): string
    {
        if (!$start || $start === '0000-00-00') return '';
        try {
            $sd = new DateTimeImmutable($start);
            $ed = ($end && $end !== '0000-00-00') ? new DateTimeImmutable($end) : null;
            if (!$ed || $sd->format('Y-m-d') === $ed->format('Y-m-d'))
                return $sd->format('F j, Y');
            if ($sd->format('Y-m') === $ed->format('Y-m'))
                return $sd->format('F j') . '&ndash;' . $ed->format('j, Y');
            if ($sd->format('Y') === $ed->format('Y'))
                return $sd->format('F j') . ' &ndash; ' . $ed->format('F j, Y');
            return $sd->format('F j, Y') . ' &ndash; ' . $ed->format('F j, Y');
        } catch (Exception $ex) {
            return hrdEmailEsc($start);
        }
    }
}

if (!function_exists('hrdEmailFormatDatePlain')) {
    function hrdEmailFormatDatePlain(?string $start, ?string $end): string
    {
        if (!$start || $start === '0000-00-00') return '';
        try {
            $sd = new DateTimeImmutable($start);
            $ed = ($end && $end !== '0000-00-00') ? new DateTimeImmutable($end) : null;
            if (!$ed || $sd->format('Y-m-d') === $ed->format('Y-m-d'))
                return $sd->format('F j, Y');
            if ($sd->format('Y-m') === $ed->format('Y-m'))
                return $sd->format('F j') . '-' . $ed->format('j, Y');
            if ($sd->format('Y') === $ed->format('Y'))
                return $sd->format('F j') . ' - ' . $ed->format('F j, Y');
            return $sd->format('F j, Y') . ' - ' . $ed->format('F j, Y');
        } catch (Exception $ex) {
            return strip_tags($start);
        }
    }
}

if (!function_exists('hrdEmailFormatFee')) {
    function hrdEmailFormatFee($fee): string
    {
        return ((float)$fee > 0) ? 'Php ' . number_format((float)$fee, 2) : 'Free';
    }
}

if (!function_exists('hrdEmailFormatStatus')) {
    function hrdEmailFormatStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'paid'             => 'Paid',
            'pending'          => 'Pending',
            'free'             => 'Free',
            'cancelled'        => 'Cancelled',
            'promissory note'  => 'Promissory Note',
            default            => ucwords($status),
        };
    }
}

if (!function_exists('hrdEmailHeader')) {
    function hrdEmailHeader(string $badge = ''): string
    {
        $badgeHtml = $badge !== ''
            ? "<p style='margin:10px 0 0;display:inline-block;background-color:rgba(255,255,255,0.12);"
              . "color:#c8d9ef;font-size:11px;padding:4px 14px;border-radius:20px;letter-spacing:0.5px;'>"
              . hrdEmailEsc($badge) . "</p>"
            : '';
        return "
  <tr>
    <td style='background-color:#012970;padding:28px 36px;text-align:center;'>
      <p style='margin:0;font-size:11px;color:#a0b4d0;letter-spacing:1.5px;text-transform:uppercase;'>Civil Service Commission &ndash; Regional Office VIII</p>
      <h1 style='margin:8px 0 0;font-size:18px;color:#ffffff;font-weight:700;line-height:1.4;'>Human Resource Division</h1>
      {$badgeHtml}
    </td>
  </tr>";
    }
}

if (!function_exists('hrdEmailSignature')) {
    function hrdEmailSignature(): string
    {
        return "
      <p style='margin:24px 0 0;font-size:14px;color:#333;line-height:1.8;'>Thank you.<br><br>Sincerely yours,</p>
      <table cellpadding='0' cellspacing='0' style='margin-top:16px;'>
        <tr>
          <td style='border-left:3px solid #012970;padding-left:14px;'>
            <p style='margin:0;font-size:14px;font-weight:700;color:#012970;'>(Sgd.) JAY M. MERELOS</p>
            <p style='margin:2px 0 0;font-size:12px;color:#555;'>Chief Human Resource Specialist</p>
            <p style='margin:1px 0 0;font-size:12px;color:#555;'>Human Resource Division</p>
            <p style='margin:1px 0 0;font-size:12px;color:#555;'>Civil Service Commission &ndash; Regional Office VIII</p>
          </td>
        </tr>
      </table>";
    }
}

if (!function_exists('hrdEmailFooter')) {
    function hrdEmailFooter(): string
    {
        return "
  <tr>
    <td style='background-color:#f4f6f9;border-top:1px solid #e0e8f0;padding:20px 36px;'>
      <p style='margin:0 0 10px;font-size:10.5px;color:#666;line-height:1.6;'>
        <b>Disclaimer:</b> This email message including attachments, if any, may contain confidential information
        and is only for the use of the individual or entity to whom it is addressed. If you have received this
        email by mistake or are not the named addressee, you are notified that disseminating, distributing, or
        copying of this communication is strictly prohibited.
      </p>
      <p style='margin:0;font-size:10.5px;color:#c0392b;line-height:1.6;'>
        <b>WARNING!</b> Computer viruses can be transmitted via email. The recipient should check this email and
        any attachments for the presence of viruses. The Civil Service Commission accepts no liability for any
        damage caused by any virus transmitted by this email.
      </p>
    </td>
  </tr>";
    }
}

if (!function_exists('hrdEmailWrap')) {
    function hrdEmailWrap(string $rows, int $width = 620): string
    {
        return "<!DOCTYPE html>
<html lang='en'>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width,initial-scale=1.0'>
</head>
<body style='margin:0;padding:0;background-color:#f4f6f9;font-family:Verdana,Geneva,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background-color:#f4f6f9;padding:30px 0;'>
<tr><td align='center'>
<table width='{$width}' cellpadding='0' cellspacing='0' style='background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);'>
{$rows}
</table>
</td></tr>
</table>
</body>
</html>";
    }
}

if (!function_exists('hrdEmailBankBlock')) {
    function hrdEmailBankBlock(): string
    {
        return "
      <table cellpadding='0' cellspacing='0' width='100%' style='margin:0 0 16px;'>
        <tr>
          <td style='background-color:#eaf7f0;border-left:4px solid #28a745;border-radius:4px;padding:14px 18px;'>
            <p style='margin:0 0 8px;font-size:13px;font-weight:700;color:#1a5c32;'>Bank Details for Payment</p>
            <table cellpadding='0' cellspacing='0' style='font-size:13px;color:#1a5c32;margin-bottom:10px;'>
              <tr><td style='padding:2px 0;width:150px;'>Bank:</td><td><b>Landbank of the Philippines</b></td></tr>
              <tr><td style='padding:2px 0;'>Account Name:</td><td><b>Civil Service Commission</b></td></tr>
              <tr><td style='padding:2px 0;'>Account Number:</td><td><b>018-211-0825</b></td></tr>
            </table>
            <p style='margin:6px 0 0;font-size:12px;color:#2d7a4f;'>
              <b>Submit your proof of payment at:</b><br>
              <a href='https://otrs.cscro8.com/proof_payment' style='color:#0d6efd;'>https://otrs.cscro8.com/proof_payment</a>
            </p>
          </td>
        </tr>
      </table>";
    }
}

if (!function_exists('hrdEmailPenaltyNote')) {
    function hrdEmailPenaltyNote(): string
    {
        return "<p style='margin:0 0 14px;font-size:13px;color:#333;line-height:1.7;'>
        Registered participants will be charged the amount equivalent to <b>80% of the registration fee</b>
        if they fail to attend the training and/or fail to cancel their registration within
        <b>seven (7) days</b> before the conduct of the training.
      </p>";
    }
}

if (!function_exists('hrdEmailContact')) {
    function hrdEmailContact(): string
    {
        return "<p style='margin:0 0 14px;font-size:13px;color:#333;line-height:1.7;'>
        For clarification, please call the CSC RO VIII &ndash; HRD at
        <b>(053) 888-1811</b> or any of the CSC field offices.
      </p>";
    }
}
