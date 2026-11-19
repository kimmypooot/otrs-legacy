<?php
// agency_cert_helpers.php
// Shared helper functions for agency certificate endpoints.
// Include this AFTER loading vendor libs (TCPDF / FPDI / phpqrcode).

if (defined('AGENCY_CERT_HELPERS_LOADED')) return;
define('AGENCY_CERT_HELPERS_LOADED', true);

use setasign\Fpdi\Tcpdf\Fpdi;

// CertificateEngine must already be loaded by the calling endpoint (after vendor libs).
// Guard against double-inclusion in case this file is included from a context that
// hasn't loaded vendor libs yet.
if (!defined('CCS_ENGINE_LOADED') && file_exists(__DIR__ . '/CertificateEngine.php')) {
    require_once __DIR__ . '/CertificateEngine.php';
}

// ── Date formatting ───────────────────────────────────────────────────────────

function agencyBuildDisplayDate(string $s, string $e): string
{
    if ($s === '' || $s === '0000-00-00') return '—';
    $start = new DateTime($s);
    $end   = ($e !== '' && $e !== '0000-00-00') ? new DateTime($e) : clone $start;
    if ($start == $end)                                     return $start->format('j F Y');
    if ($start->format('Y') !== $end->format('Y'))          return $start->format('j F Y') . ' – ' . $end->format('j F Y');
    if ($start->format('m') !== $end->format('m'))          return $start->format('j F') . ' – ' . $end->format('j F Y');
    return $start->format('j') . '–' . $end->format('j F Y');
}

// ── Number-to-words ───────────────────────────────────────────────────────────

function agencyConvertNumberToWords(int $number): string
{
    $ones = [0=>'',1=>'One',2=>'Two',3=>'Three',4=>'Four',5=>'Five',6=>'Six',
             7=>'Seven',8=>'Eight',9=>'Nine',10=>'Ten',11=>'Eleven',12=>'Twelve',
             13=>'Thirteen',14=>'Fourteen',15=>'Fifteen',16=>'Sixteen',
             17=>'Seventeen',18=>'Eighteen',19=>'Nineteen'];
    $tens = [0=>'',2=>'Twenty',3=>'Thirty',4=>'Forty',5=>'Fifty',
             6=>'Sixty',7=>'Seventy',8=>'Eighty',9=>'Ninety'];
    if ($number === 0) return 'Zero';
    $words = '';
    if ($number >= 100) {
        $words .= $ones[intdiv($number, 100)] . ' Hundred';
        $number %= 100;
        if ($number > 0) $words .= ' ';
    }
    if ($number < 20) {
        $words .= $ones[$number];
    } else {
        $words .= $tens[intdiv($number, 10)];
        if ($number % 10 > 0) $words .= '-' . $ones[$number % 10];
    }
    return trim($words);
}

// ── Certificate number generation ────────────────────────────────────────────
// Delegates to CertificateEngine which checks all 3 cert tables with GET_LOCK.

function agencyGenerateCertNumber(PDO $db, string $year): string
{
    return CertificateEngine::generateCertNumber($db, $year);
}

// ── QR code generation ────────────────────────────────────────────────────────

function agencyGenerateQRCode(string $certNumber, string $verificationUrl): string|false
{
    return CertificateEngine::generateQRCode($certNumber, $verificationUrl);
}

// ── PDF certificate generation ────────────────────────────────────────────────
//
// $details keys:
//   participant_name, training_title, venue, display_date,
//   start_date, end_date, cert_type (Completion|Certification),
//   no_hrs (int|null), training_type (string|null), index (int),
//   position (string|null), agency (string|null) — certification only
//   hide_signature (bool|null) — when true, suppresses the e-signature image
//
// Returns a relative path under assets/uploads/certificates/ on success, false on failure.

function agencyGenerateCertificateFile(array $details, string $certNumber, string $qrFile): string|false
{
    // Map legacy agency cert shape → CertificateEngine::generatePDF() shape.
    // cert_type 'Completion'/'Certification' → category 'completion'/'certification'.
    $category = strtolower(trim($details['cert_type'] ?? 'completion'));
    if (!in_array($category, ['completion', 'certification'], true)) {
        $category = 'completion';
    }

    return CertificateEngine::generatePDF([
        'category'         => $category,
        'participant_name' => $details['participant_name'],
        'cert_number'      => $certNumber,
        'qr_file'          => $qrFile,
        'training_title'   => $details['training_title'],
        'display_date'     => $details['display_date'],
        'start_date'       => $details['start_date'],
        'end_date'         => $details['end_date'],
        'venue'            => $details['venue'],
        'training_mode'    => '',  // agency certs have no mode; empty = engine shows e-sig
        'no_hrs'           => (int)($details['no_hrs'] ?? 0),
        'training_type'    => trim($details['training_type'] ?? ''),
        'training_level'   => '',
        'source'           => 'agency',
        'reference_no'     => '',
        'index'            => (int)($details['index'] ?? 0),
        'position_title'   => trim($details['position'] ?? ''),
        'agency_name'      => trim($details['agency'] ?? ''),
        'hide_signature'   => (bool)($details['hide_signature'] ?? false),
    ]);
}
