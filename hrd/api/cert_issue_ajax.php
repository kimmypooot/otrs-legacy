<?php
// imis/otrs/hrd/cert_issue_ajax.php

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', 0);

// ── MUST be first — before session.php, which redirects unauthenticated ──
$_expectedInternalKey = defined('INTERNAL_SECRET')
    ? INTERNAL_SECRET
    : 'c1448c4298704ac53ba2bda87e6b0f5bb1d4fb79f868c4e91cd000b0cc3ba12c';
$isInternalCall = (
    !empty($_SERVER['HTTP_X_INTERNAL_KEY']) &&
    $_SERVER['HTTP_X_INTERNAL_KEY'] === $_expectedInternalKey
);
unset($_expectedInternalKey);

// Skip session.php entirely for internal calls — it would redirect to /login
// and kill the include before any certificate logic runs.
if (!$isInternalCall) {
    require_once __DIR__ . '/../../../includes/bootstrap.php';

    if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
        http_response_code(403);
        exit(json_encode(['success' => false, 'error' => 'Access denied.']));
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit(json_encode(['success' => false, 'error' => 'Method not allowed.']));
    }

    $csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
    if (!validateCsrfToken($csrfToken)) {
        http_response_code(403);
        exit(json_encode(['success' => false, 'error' => 'Invalid CSRF token.']));
    }
}

require_once __DIR__ . '/../inc/training_dates.php';
require_once __DIR__ . '/../../../../vendor/tcpdf/tcpdf.php';
require_once __DIR__ . '/../../../../vendor/fpdi/src/autoload.php';
require_once __DIR__ . '/../../../../vendor/phpqrcode/qrlib.php';
require_once __DIR__ . '/CertificateEngine.php';

use setasign\Fpdi\Tcpdf\Fpdi;

if (!$isInternalCall) {
    header('Content-Type: application/json');
}

// Buffer all output so a TCPDF die() error can't corrupt the JSON response.
// Skipped for internal includes: CertificateService owns the ob_start() there.
if (!$isInternalCall) {
    ob_start();
    register_shutdown_function(function () {
        $buf = ob_get_contents();
        ob_end_clean();
        if (empty($buf)) return;
        if (!headers_sent()) header('Content-Type: application/json');
        echo (isset($buf[0]) && $buf[0] === '{')
            ? $buf
            : json_encode(['success' => false, 'error' => 'PDF generation failed. A server error occurred — check PHP/TCPDF error logs.']);
    });
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

/**
 * Build the training date string for the certificate.
 *
 * With no suspensions → standard range: "October 27-31, 2026"
 * With suspensions    → segments split around holidays:
 *   same month  : "October 1-4 - October 6, 2026"
 *   cross-month : "October 27-31, 2026 - November 3, 2026"
 *   cross-year  : "December 29-31, 2025 - January 2, 2026"
 */
if (!function_exists('buildTrainingDateDisplay')):
function buildTrainingDateDisplay(array $trainingDateStrings, string $fallbackStart, string $fallbackEnd): string
{
    if (empty($trainingDateStrings)) {
        if (empty($fallbackStart) || $fallbackStart === '0000-00-00') return '—';
        $s = new DateTime($fallbackStart);
        $e = new DateTime(!empty($fallbackEnd) && $fallbackEnd !== '0000-00-00' ? $fallbackEnd : $fallbackStart);
        return _formatSegment($s, $e, true);
    }

    $days = array_map(fn($d) => new DateTime($d), $trainingDateStrings);

    // ── Group consecutive days into segments ───────────────────────────────
    $segments  = [];
    $segStart  = $days[0];
    $segEnd    = $days[0];
    for ($i = 1, $n = count($days); $i < $n; $i++) {
        $next = clone $days[$i - 1];
        $next->modify('+1 day');
        if ($next->format('Y-m-d') === $days[$i]->format('Y-m-d')) {
            $segEnd = $days[$i];
        } else {
            $segments[] = [$segStart, $segEnd];
            $segStart = $days[$i];
            $segEnd   = $days[$i];
        }
    }
    $segments[] = [$segStart, $segEnd];

    // Single segment after removing suspensions (shouldn't normally happen)
    if (count($segments) === 1) {
        return _formatSegment($segments[0][0], $segments[0][1], true);
    }

    // ── Determine if all segments fall within the same year ────────────────
    $years = array_unique(array_map(fn($s) => $s[0]->format('Y'), $segments));
    $allSameYear = count($years) === 1;

    // ── Format each segment ────────────────────────────────────────────────
    $parts = [];
    $total = count($segments);
    foreach ($segments as $i => [$s, $e]) {
        // Show year on every segment when crossing years; only on last when same year
        $showYear = !$allSameYear || ($i === $total - 1);
        $parts[]  = _formatSegment($s, $e, $showYear);
    }

    return implode(' - ', $parts);
}

/** Format one date segment as a string. */
function _formatSegment(DateTime $s, DateTime $e, bool $showYear): string
{
    if ($s == $e) {
        // Single day
        return $showYear ? $s->format('j F Y') : $s->format('j F');
    }

    if ($s->format('Y-m') === $e->format('Y-m')) {
        // Same month: "1-4 October 2026" or "1-4 October"
        $tail = $showYear ? $e->format('F Y') : $e->format('F');
        return $s->format('j') . '-' . $e->format('j') . ' ' . $tail;
    }

    if ($s->format('Y') === $e->format('Y')) {
        // Different months, same year: "27 October - 3 November 2026"
        $yearSuffix = $showYear ? ' ' . $e->format('Y') : '';
        return $s->format('j F') . ' - ' . $e->format('j F') . $yearSuffix;
    }

    // Different years: "29 December 2025 - 2 January 2026"
    return $s->format('j F Y') . ' - ' . $e->format('j F Y');
}
endif; // !function_exists('buildTrainingDateDisplay')

// Session user — 0 for internal/automated calls
$sessionUserId = isset($_SESSION['id']) ? (int) $_SESSION['id'] : null;

if (!$sessionUserId) {
    if (!$isInternalCall) {
        echo json_encode(['success' => false, 'error' => 'Unauthenticated. Please log in again.']);
        exit;
    }
    $sessionUserId = 0; // automated system call
}
 
$trainingId = isset($_POST['trainingId'])
    ? filter_var($_POST['trainingId'], FILTER_VALIDATE_INT)
    : false;
if (!$trainingId) {
    echo json_encode(['success' => false, 'error' => 'Missing or invalid trainingId.']);
    exit;
}
 
$rawIds = isset($_POST['participantIds']) ? $_POST['participantIds'] : '';
if (is_array($rawIds)) {
    $participantIds = array_map('intval', $rawIds);
} elseif (is_string($rawIds) && strpos($rawIds, '[') !== false) {
    $decoded = json_decode($rawIds, true);
    $participantIds = is_array($decoded) ? array_map('intval', $decoded) : array();
} else {
    $participantIds = array_map('intval', explode(',', $rawIds));
}
$participantIds = array_values(array_filter($participantIds, function($v) { return $v > 0; }));
 
if (empty($participantIds)) {
    echo json_encode(['success' => false, 'error' => 'No valid participant IDs provided.']);
    exit;
}
 
// ── Verification base URL ───────────────────────────────────────────
$isLocal        = in_array($_SERVER["HTTP_HOST"], array("localhost", "127.0.0.1"));
$currentBaseUrl = $isLocal
    ? "http://localhost/csc-tms/"   // local dev fallback
    : "https://otrs.cscro8.com/";   // production OTRS domain
 
// ── DB connection ──────────────────────────────────────────────────
try {
 
    // Verify training
    $stmtT = $conn->prepare("
        SELECT ot.*, ot.type AS training_type
        FROM otrs_trainings ot
        WHERE ot.id = :id
        LIMIT 1
    ");
    $stmtT->execute(array(':id' => $trainingId));
    $training = $stmtT->fetch(PDO::FETCH_ASSOC);
 
    if (!$training) {
        echo json_encode(['success' => false, 'error' => 'Training not found.']);
        exit;
    }
 
    // Allowed training types guard
    $trainingType = strtolower(isset($training['training_type']) ? $training['training_type'] : '');
    $allowedTypes = array('free', 'public-offered', 'congress-conducted', 'internal');
    $canGenerateCertificates = ((float)$training['fee'] == 0.0) || in_array($trainingType, $allowedTypes);

    if (!$canGenerateCertificates) {
        echo json_encode(array('success' => false, 'error' => 'Certificate generation is currently supported for Free, Public-Offered, Congress-Conducted, and Internal Trainings only.'));
        exit;
    }

    // Certificate category — only relevant for Internal trainings; defaults to 'completion' for all others.
    $certCategoryRaw = trim($_POST['cert_category'] ?? 'completion');
    $allowedCertCats = ['completion', 'certification'];
    $certCategory    = in_array($certCategoryRaw, $allowedCertCats, true) ? $certCategoryRaw : 'completion';

    // ── Multi-format path (Completion / Certification / Merged / Merged-Certification) ──
    // Driven by the single-select format dropdown on generate_certificates.php,
    // which always sends cert_formats[] as a one-element array. When the caller
    // sends cert_formats[], it completely replaces the legacy single-cert_category
    // flow below for this request — the two never run for the same request, so
    // there's no risk of double-writing a cert. Internal callers (public portal
    // claim flow) don't send cert_formats[] and keep using the legacy path below.
    // otrs_trainings.mode stores space-separated title case ("Face to Face"),
    // not the hyphenated form CertificateEngine expects — normalize before compare.
    $isF2F = CertificateEngine::normalizeMode($training['mode'] ?? '') === 'face-to-face';
    $certFormats = [];
    if (isset($_POST['cert_formats'])) {
        $rawFormats     = $_POST['cert_formats'];
        $rawFormatsArr  = is_array($rawFormats) ? $rawFormats : [$rawFormats];
        $allowedFormats = ['completion', 'certification', 'merged', 'merged_certification'];
        foreach ($rawFormatsArr as $f) {
            $f = strtolower(trim((string)$f));
            if (in_array($f, $allowedFormats, true) && !in_array($f, $certFormats, true)) {
                $certFormats[] = $f;
            }
        }
    }
    $useMultiFormat = !empty($certFormats);

    // Exact Display Type to save to the database
    $dbTrainingType = isset($training['training_type']) && $training['training_type'] !== '' ? $training['training_type'] : 'Public-Offered';
 
    // Build display date — segments split around gaps in the training's actual dates
    $displayDate = buildTrainingDateDisplay(
        getTrainingDateStrings($conn, $trainingId),
        $training['start_date'] ?? '',
        $training['end_date']   ?? ''
    );
 
    $conn->beginTransaction();
 
    $generatedCount = 0;
    $errors         = array();
    $results        = array();
    $year           = date('Y');
 
    // Reusable prepared statements
    $stmtCheck = $conn->prepare(
        "SELECT id, certificate_number, certificate_file FROM otrs_certificates
         WHERE participant_id = :pid AND training_id = :tid LIMIT 1"
    );
    $stmtP = $conn->prepare(
        "SELECT id, fname, mname, lname, suffix, email, position_title AS positiontitle, agency_name, location
         FROM otrs_participants WHERE id = :id LIMIT 1"
    );

    $stmtInsert = $conn->prepare("
        INSERT INTO otrs_certificates
            (certificate_number,
            participant_id, training_id, cert_type,
            certificate_file, qr_code, qr_code_data,
            issued_at, issued_by, generated_at, generated_by, status)
        VALUES
            (:cert_no,
            :pid, :tid, 'Completion',
            :cert_file, :qr_code, :qr_data_url,
            NOW(), 'OTRS System', NOW(), :generated_by, 'active')
    ");
    $stmtUpdate = $conn->prepare("
        UPDATE otrs_certificates
        SET certificate_number = :cert_no,
            certificate_file   = :cert_file,
            qr_code            = :qr_code,
            qr_code_data       = :qr_data_url,
            issued_at          = NOW(),
            generated_at       = NOW(),
            generated_by       = :generated_by,
            status             = 'active',
            email_status       = NULL,
            emailed_at         = NULL
        WHERE participant_id = :pid AND training_id = :tid
    ");
 
    if (!$useMultiFormat) {
    foreach ($participantIds as $pid) {
        try {
            $stmtP->execute(array(':id' => $pid));
            $participant = $stmtP->fetch(PDO::FETCH_ASSOC);
            if (!$participant) {
                $errors[] = "Participant ID {$pid} not found.";
                continue;
            }

            $stmtCheck->execute(array(':pid' => $pid, ':tid' => $trainingId));
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
 
            // Retain existing cert number if re-issuing, otherwise generate new
            if ($existing && !empty($existing['certificate_number'])) {
                $certNumber = $existing['certificate_number'];
            } else {
                $certNumber = CertificateEngine::generateCertNumber($conn, $year);
            }
            $verificationToken = bin2hex(random_bytes(32));

            // Resolve old PDF path now but defer deletion until new PDF is confirmed written.
            $oldPdfToDelete = null;
            if ($existing && !empty($existing['certificate_file'])) {
                $resolved = realpath(
                    __DIR__ . '/../../../../assets/uploads/certificates/' .
                    str_replace('/', DIRECTORY_SEPARATOR, $existing['certificate_file'])
                );
                if ($resolved && file_exists($resolved)) {
                    $oldPdfToDelete = $resolved;
                }
            }
            
            $verificationUrl = $currentBaseUrl . 'verify-certificate.php?token=' . $verificationToken;
 
            // Format name
            $fullName = formatName(
                $participant['fname'],
                $participant['mname'],
                $participant['lname'],
                $participant['suffix'] ?? ''
            );
 
            // Generate QR code image
            $qrFile = CertificateEngine::generateQRCode($certNumber, $verificationUrl);
            if (!$qrFile) throw new Exception("QR code generation failed.");

            // Generate PDF via engine
            $source       = ($trainingType === 'internal') ? 'internal' : 'public_offered';
            $trainingLevel = ($training['level'] ?? '') ?: 'Regular';
            $certFilePath = CertificateEngine::generatePDF([
                'category'         => $certCategory,
                'participant_name' => $fullName,
                'cert_number'      => $certNumber,
                'qr_file'          => $qrFile,
                'training_title'   => $training['name'],
                'display_date'     => $displayDate,
                'start_date'       => $training['start_date'] ?? date('Y-m-d'),
                'end_date'         => $training['end_date']   ?? date('Y-m-d'),
                'venue'            => $training['location']   ?? '',
                'training_mode'    => $training['mode']       ?? '',
                'no_hrs'           => (int)($training['no_hrs'] ?? 0),
                'training_type'    => '',
                'training_level'   => $trainingLevel,
                'source'           => $source,
                'reference_no'     => '',
                'index'            => $pid,
                'position_title'   => $participant['positiontitle'] ?? '',
                'agency_name'      => $participant['agency_name']   ?? '',
            ]);
            if (!$certFilePath) throw new Exception("PDF generation failed: file was not written to disk.");

            // New PDF confirmed on disk — now safe to remove the old one.
            if ($oldPdfToDelete && file_exists($oldPdfToDelete)) {
                @unlink($oldPdfToDelete);
                $oldDir = dirname($oldPdfToDelete);
                if (is_dir($oldDir) && count(glob($oldDir . DIRECTORY_SEPARATOR . '*')) === 0) {
                    @rmdir($oldDir);
                }
            }

            $params = array(
                ':cert_no'      => $certNumber,
                ':cert_file'    => $certFilePath,
                ':qr_code'      => $qrFile,
                ':qr_data_url'  => $verificationUrl,
                ':pid'          => $pid,
                ':tid'          => $trainingId,
                ':generated_by' => $sessionUserId,
            );
 
            if ($existing) {
                $stmtUpdate->execute($params);
                $action = 'reissued';
                $certId = $existing['id'];
            } else {
                $stmtInsert->execute($params);
                $action = 'issued';
                $certId = $conn->lastInsertId();
            }
 
            // Dual-write to CCS table for internal trainings (Phase 3)
            $ccsId = null;
            if ($trainingType === 'internal') {
                try {
                    // Matched by certificate_number (the actual UNIQUE column), not
                    // (pid, tid) alone — this picker can dual-write under either
                    // 'completion' or 'certification' depending on what was chosen,
                    // and both share this same retained certificate_number, so a
                    // lookup without it risks missing a same-numbered row created
                    // under the other category and colliding with it on INSERT.
                    $ccsCheck = $conn->prepare("
                        SELECT id FROM otrs_ccs_certificates
                         WHERE certificate_number = :cn LIMIT 1
                    ");
                    $ccsCheck->execute([':cn' => $certNumber]);
                    $ccsExisting = $ccsCheck->fetchColumn();

                    if ($ccsExisting) {
                        $conn->prepare("
                            UPDATE otrs_ccs_certificates
                               SET category            = :cat,
                                   certificate_file     = :cf,
                                   qr_code              = :qr,
                                   qr_code_data         = :qd,
                                   verification_token   = :vt,
                                   status               = 'active',
                                   generated_at         = NOW(),
                                   generated_by         = :gby,
                                   position              = :pos,
                                   agency                = :agc,
                                   no_hrs                = :nhrs
                             WHERE id = :id
                        ")->execute([
                            ':cat' => $certCategory,
                            ':cf'  => $certFilePath,
                            ':qr'  => $qrFile,
                            ':qd'  => $verificationUrl,
                            ':vt'  => $verificationToken,
                            ':gby' => $sessionUserId,
                            ':pos' => ($participant['positiontitle'] ?? '') ?: null,
                            ':agc' => ($participant['agency_name']   ?? '') ?: null,
                            ':nhrs'=> (int)($training['no_hrs'] ?? 0),
                            ':id'  => $ccsExisting,
                        ]);
                        $ccsId = (int)$ccsExisting;
                    } else {
                        $ccsId = CertificateEngine::storeRecord($conn, [
                            'certificate_number' => $certNumber,
                            'source'             => 'internal',
                            'category'           => $certCategory,
                            'participant_id'     => $pid,
                            'participant_name'   => $fullName,
                            'training_id'        => $trainingId,
                            'training_title'     => $training['name'],
                            'venue'              => $training['location'] ?? '',
                            'start_date'         => $training['start_date'] ?? null,
                            'end_date'           => $training['end_date']   ?? null,
                            'training_mode'      => $training['mode']       ?? null,
                            'training_level'     => $training['level']      ?? null,
                            'no_hrs'             => (int)($training['no_hrs'] ?? 0),
                            'certificate_file'   => $certFilePath,
                            'qr_code'            => $qrFile,
                            'qr_code_data'       => $verificationUrl,
                            'verification_token' => $verificationToken,
                            'status'             => 'active',
                            'issued_by'          => 'OTRS System',
                            'generated_by'       => $sessionUserId ?: null,
                            'position'           => $participant['positiontitle'] ?? '',
                            'agency'             => $participant['agency_name']   ?? '',
                        ], 'system', $sessionUserId ?: null, $_SERVER['REMOTE_ADDR'] ?? '');
                    }
                } catch (Throwable $ccsEx) {
                    // CCS dual-write failure must not abort the main cert generation
                    error_log('[cert_issue_ajax] CCS dual-write failed for pid=' . $pid . ': ' . $ccsEx->getMessage());
                }
            }

            $results[] = array(
                'pid'              => $pid,
                'success'          => true,
                'format'           => 'completion',
                'cert_id'          => $certId,
                'cert_number'      => $certNumber,
                'action'           => $action,
                'name'             => $fullName,
                'certificate_file' => $certFilePath,
                'ccs_cert_id'      => $ccsId,
            );
            $generatedCount++;

        } catch (Exception $ex) {
            $errors[] = "Error for participant {$pid}: " . $ex->getMessage();
            $results[] = array('pid' => $pid, 'success' => false, 'format' => 'completion', 'error' => $ex->getMessage());
        }
    }
    } else {
        // ── Multi-format path: Completion / Certification / Merged ───────────
        // Completion still writes to the legacy otrs_certificates table (and,
        // for Internal trainings, dual-writes to CCS) — mirrors the block
        // above exactly, just reusable per-participant inside this loop.
        // Certification/Merged are CCS-only, deduped by (pid, tid, category).
        $issueCompletion = function (array $participant, string $fullName, int $pid) use (
            $conn, $trainingId, $trainingType, $training, $displayDate, $sessionUserId,
            $year, $currentBaseUrl, $stmtCheck, $stmtInsert, $stmtUpdate, $isF2F
        ) {
            $stmtCheck->execute([':pid' => $pid, ':tid' => $trainingId]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            $certNumber = ($existing && !empty($existing['certificate_number']))
                ? $existing['certificate_number']
                : CertificateEngine::generateCertNumber($conn, $year);
            $verificationToken = bin2hex(random_bytes(32));
            $verificationUrl   = $currentBaseUrl . 'verify-certificate.php?token=' . $verificationToken;

            $oldPdfToDelete = null;
            if ($existing && !empty($existing['certificate_file'])) {
                $resolved = realpath(
                    __DIR__ . '/../../../../assets/uploads/certificates/' .
                    str_replace('/', DIRECTORY_SEPARATOR, $existing['certificate_file'])
                );
                if ($resolved && file_exists($resolved)) $oldPdfToDelete = $resolved;
            }

            $qrFile = CertificateEngine::generateQRCode($certNumber, $verificationUrl);
            if (!$qrFile) throw new Exception('QR code generation failed.');

            $source        = ($trainingType === 'internal') ? 'internal' : 'public_offered';
            $trainingLevel = ($training['level'] ?? '') ?: 'Regular';
            $certFilePath  = CertificateEngine::generatePDF([
                'category'         => 'completion',
                'participant_name' => $fullName,
                'cert_number'      => $certNumber,
                'qr_file'          => $qrFile,
                'training_title'   => $training['name'],
                'display_date'     => $displayDate,
                'start_date'       => $training['start_date'] ?? date('Y-m-d'),
                'end_date'         => $training['end_date']   ?? date('Y-m-d'),
                'venue'            => $training['location']   ?? '',
                'training_mode'    => $training['mode']       ?? '',
                'no_hrs'           => (int)($training['no_hrs'] ?? 0),
                'training_type'    => '',
                'training_level'   => $trainingLevel,
                'source'           => $source,
                'reference_no'     => '',
                'index'            => $pid,
                'position_title'   => $participant['positiontitle'] ?? '',
                'agency_name'      => $participant['agency_name']   ?? '',
            ]);
            if (!$certFilePath) throw new Exception('PDF generation failed: file was not written to disk.');

            if ($oldPdfToDelete && file_exists($oldPdfToDelete)) {
                @unlink($oldPdfToDelete);
                $oldDir = dirname($oldPdfToDelete);
                if (is_dir($oldDir) && count(glob($oldDir . DIRECTORY_SEPARATOR . '*')) === 0) @rmdir($oldDir);
            }

            $params = [
                ':cert_no'      => $certNumber,
                ':cert_file'    => $certFilePath,
                ':qr_code'      => $qrFile,
                ':qr_data_url'  => $verificationUrl,
                ':pid'          => $pid,
                ':tid'          => $trainingId,
                ':generated_by' => $sessionUserId,
            ];

            if ($existing) {
                $stmtUpdate->execute($params);
                $action = 'reissued';
                $certId = $existing['id'];
            } else {
                $stmtInsert->execute($params);
                $action = 'issued';
                $certId = $conn->lastInsertId();
            }

            // Dual-write to CCS — Internal trainings always got this; now also
            // applies to any Face-to-Face training (Standard included), per the
            // rule that the CCS copy ("View CCS" button) only ever exists for
            // F2F. $isF2F is guaranteed true here anyway since this closure is
            // only invoked from the F2F-gated multi-format branch, but the
            // check is kept explicit so the rule stays self-documenting even
            // if this closure is ever reused from elsewhere.
            $ccsId = null;
            if ($trainingType === 'internal' || $isF2F) {
                try {
                    // Matched by certificate_number — the column that's actually
                    // UNIQUE — not by (pid, tid, category). The legacy single-format
                    // picker historically dual-wrote under whatever category was
                    // chosen (completion OR certification) while reusing this same
                    // retained certificate_number; filtering by category='completion'
                    // here would miss that older row and then collide on INSERT
                    // (duplicate certificate_number), silently failing the dual-write.
                    $ccsCheck = $conn->prepare("
                        SELECT id FROM otrs_ccs_certificates
                         WHERE certificate_number = :cn LIMIT 1
                    ");
                    $ccsCheck->execute([':cn' => $certNumber]);
                    $ccsExisting = $ccsCheck->fetchColumn();

                    if ($ccsExisting) {
                        $conn->prepare("
                            UPDATE otrs_ccs_certificates
                               SET category            = 'completion',
                                   certificate_file     = :cf,
                                   qr_code              = :qr,
                                   qr_code_data         = :qd,
                                   verification_token   = :vt,
                                   status               = 'active',
                                   generated_at         = NOW(),
                                   generated_by         = :gby,
                                   position              = :pos,
                                   agency                = :agc,
                                   no_hrs                = :nhrs
                             WHERE id = :id
                        ")->execute([
                            ':cf'  => $certFilePath,
                            ':qr'  => $qrFile,
                            ':qd'  => $verificationUrl,
                            ':vt'  => $verificationToken,
                            ':gby' => $sessionUserId,
                            ':pos' => ($participant['positiontitle'] ?? '') ?: null,
                            ':agc' => ($participant['agency_name']   ?? '') ?: null,
                            ':nhrs'=> (int)($training['no_hrs'] ?? 0),
                            ':id'  => $ccsExisting,
                        ]);
                        $ccsId = (int)$ccsExisting;
                    } else {
                        $ccsSource = ($trainingType === 'internal') ? 'internal' : 'public_offered';
                        $ccsId = CertificateEngine::storeRecord($conn, [
                            'certificate_number' => $certNumber,
                            'source'             => $ccsSource,
                            'category'           => 'completion',
                            'participant_id'     => $pid,
                            'participant_name'   => $fullName,
                            'training_id'        => $trainingId,
                            'training_title'     => $training['name'],
                            'venue'              => $training['location'] ?? '',
                            'start_date'         => $training['start_date'] ?? null,
                            'end_date'           => $training['end_date']   ?? null,
                            'training_mode'      => $training['mode']       ?? null,
                            'training_level'     => $training['level']      ?? null,
                            'no_hrs'             => (int)($training['no_hrs'] ?? 0),
                            'certificate_file'   => $certFilePath,
                            'qr_code'            => $qrFile,
                            'qr_code_data'       => $verificationUrl,
                            'verification_token' => $verificationToken,
                            'status'             => 'active',
                            'issued_by'          => 'OTRS System',
                            'generated_by'       => $sessionUserId ?: null,
                            'position'           => $participant['positiontitle'] ?? '',
                            'agency'             => $participant['agency_name']   ?? '',
                        ], 'system', $sessionUserId ?: null, $_SERVER['REMOTE_ADDR'] ?? '');
                    }
                } catch (Throwable $ccsEx) {
                    error_log('[cert_issue_ajax] CCS dual-write failed for pid=' . $pid . ': ' . $ccsEx->getMessage());
                }
            }

            return [
                'cert_id'          => $certId,
                'cert_number'      => $certNumber,
                'action'           => $action,
                'certificate_file' => $certFilePath,
                'ccs_cert_id'      => $ccsId,
            ];
        };

        $issueCcsFormat = function (string $category, array $participant, string $fullName, int $pid) use (
            $conn, $trainingId, $trainingType, $training, $displayDate, $sessionUserId, $year, $currentBaseUrl
        ) {
            $stmt = $conn->prepare("
                SELECT id, certificate_number, certificate_file FROM otrs_ccs_certificates
                 WHERE participant_id = :pid AND training_id = :tid AND category = :cat LIMIT 1
            ");
            $stmt->execute([':pid' => $pid, ':tid' => $trainingId, ':cat' => $category]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            $certNumber = ($existing && !empty($existing['certificate_number']))
                ? $existing['certificate_number']
                : CertificateEngine::generateCertNumber($conn, $year);
            $verificationToken = bin2hex(random_bytes(32));
            $verificationUrl   = $currentBaseUrl . 'verify-certificate.php?token=' . $verificationToken;

            $oldPdfToDelete = null;
            if ($existing && !empty($existing['certificate_file'])) {
                $resolved = realpath(
                    __DIR__ . '/../../../../assets/uploads/certificates/' .
                    str_replace('/', DIRECTORY_SEPARATOR, $existing['certificate_file'])
                );
                if ($resolved && file_exists($resolved)) $oldPdfToDelete = $resolved;
            }

            $qrFile = CertificateEngine::generateQRCode($certNumber, $verificationUrl);
            if (!$qrFile) throw new Exception('QR code generation failed.');

            $source        = ($trainingType === 'internal') ? 'internal' : 'public_offered';
            $trainingLevel = ($training['level'] ?? '') ?: 'Regular';
            $certFilePath  = CertificateEngine::generatePDF([
                'category'         => $category,
                'participant_name' => $fullName,
                'cert_number'      => $certNumber,
                'qr_file'          => $qrFile,
                'training_title'   => $training['name'],
                'display_date'     => $displayDate,
                'start_date'       => $training['start_date'] ?? date('Y-m-d'),
                'end_date'         => $training['end_date']   ?? date('Y-m-d'),
                'venue'            => $training['location']   ?? '',
                'training_mode'    => $training['mode']       ?? '',
                'no_hrs'           => (int)($training['no_hrs'] ?? 0),
                'training_type'    => '',
                'training_level'   => $trainingLevel,
                'source'           => $source,
                'reference_no'     => '',
                'index'            => $pid,
                'position_title'   => $participant['positiontitle'] ?? '',
                'agency_name'      => $participant['agency_name']   ?? '',
            ]);
            if (!$certFilePath) throw new Exception('PDF generation failed: file was not written to disk.');

            if ($oldPdfToDelete && file_exists($oldPdfToDelete)) {
                @unlink($oldPdfToDelete);
                $oldDir = dirname($oldPdfToDelete);
                if (is_dir($oldDir) && count(glob($oldDir . DIRECTORY_SEPARATOR . '*')) === 0) @rmdir($oldDir);
            }

            if ($existing) {
                $conn->prepare("
                    UPDATE otrs_ccs_certificates
                       SET certificate_file   = :cf,
                           qr_code            = :qr,
                           qr_code_data       = :qd,
                           verification_token = :vt,
                           status             = 'active',
                           generated_at       = NOW(),
                           generated_by       = :gby,
                           position           = :pos,
                           agency             = :agc,
                           no_hrs             = :nhrs
                     WHERE id = :id
                ")->execute([
                    ':cf'  => $certFilePath,
                    ':qr'  => $qrFile,
                    ':qd'  => $verificationUrl,
                    ':vt'  => $verificationToken,
                    ':gby' => $sessionUserId,
                    ':pos' => ($participant['positiontitle'] ?? '') ?: null,
                    ':agc' => ($participant['agency_name']   ?? '') ?: null,
                    ':nhrs'=> (int)($training['no_hrs'] ?? 0),
                    ':id'  => $existing['id'],
                ]);
                $action = 'reissued';
                $certId = $existing['id'];
            } else {
                $certId = CertificateEngine::storeRecord($conn, [
                    'certificate_number' => $certNumber,
                    'source'             => $source,
                    'category'           => $category,
                    'participant_id'     => $pid,
                    'participant_name'   => $fullName,
                    'training_id'        => $trainingId,
                    'training_title'     => $training['name'],
                    'venue'              => $training['location'] ?? '',
                    'start_date'         => $training['start_date'] ?? null,
                    'end_date'           => $training['end_date']   ?? null,
                    'training_mode'      => $training['mode']       ?? null,
                    'training_level'     => $trainingLevel,
                    'no_hrs'             => (int)($training['no_hrs'] ?? 0),
                    'certificate_file'   => $certFilePath,
                    'qr_code'            => $qrFile,
                    'qr_code_data'       => $verificationUrl,
                    'verification_token' => $verificationToken,
                    'status'             => 'active',
                    'issued_by'          => 'OTRS System',
                    'generated_by'       => $sessionUserId ?: null,
                    'position'           => $participant['positiontitle'] ?? '',
                    'agency'             => $participant['agency_name']   ?? '',
                ], 'system', $sessionUserId ?: null, $_SERVER['REMOTE_ADDR'] ?? '');
                $action = 'issued';
            }

            return ['cert_id' => $certId, 'cert_number' => $certNumber, 'action' => $action, 'certificate_file' => $certFilePath];
        };

        foreach ($participantIds as $pid) {
            $stmtP->execute([':id' => $pid]);
            $participant = $stmtP->fetch(PDO::FETCH_ASSOC);
            if (!$participant) {
                $errors[] = "Participant ID {$pid} not found.";
                continue;
            }

            $fullName = formatName(
                $participant['fname'], $participant['mname'], $participant['lname'], $participant['suffix'] ?? ''
            );

            foreach ($certFormats as $format) {
                try {
                    $r = ($format === 'completion')
                        ? $issueCompletion($participant, $fullName, $pid)
                        : $issueCcsFormat($format, $participant, $fullName, $pid);

                    $results[] = [
                        'pid'              => $pid,
                        'success'          => true,
                        'format'           => $format,
                        'cert_id'          => $r['cert_id'],
                        'cert_number'      => $r['cert_number'],
                        'action'           => $r['action'],
                        'name'             => $fullName,
                        'certificate_file' => $r['certificate_file'],
                        'ccs_cert_id'      => $r['ccs_cert_id'] ?? null,
                    ];
                    $generatedCount++;
                } catch (Exception $ex) {
                    $errors[] = "Error for participant {$pid} ({$format}): " . $ex->getMessage();
                    $results[] = ['pid' => $pid, 'format' => $format, 'success' => false, 'error' => $ex->getMessage()];
                }
            }
        }
    }

    $conn->commit();
 
    echo json_encode(array(
        'success' => $generatedCount > 0,
        'count'   => $generatedCount,
        'results' => $results,
        'errors'  => $errors,
        'message' => "Successfully generated {$generatedCount} certificate(s).",
    ));
 
} catch (PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('[cert_issue_ajax] DB error: ' . $e->getMessage());
    echo json_encode(array('success' => false, 'error' => 'Database error. Please try again.'));
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(array('success' => false, 'error' => $e->getMessage()));
}

// ══════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ══════════════════════════════════════════════════════════════════

function formatName($fname, $mname, $lname, $suffix = '') {
    $fname  = trim($fname);
    $lname  = trim($lname);
    $suffix = trim($suffix ?? '');
    $mi     = '';

    if (!empty(trim($mname))) {
        $mRaw = trim($mname);
        $mi   = (strlen($mRaw) <= 2)
            ? strtoupper(str_replace('.', '', $mRaw)) . '. '
            : strtoupper(substr($mRaw, 0, 1)) . '. ';
    }
    $full = $fname . ' ' . $mi . $lname;
    if ($suffix !== '') {
        $full .= ', ' . $suffix;
    }
    $full = preg_replace('/\s+/', ' ', $full);
    return trim($full);
}

