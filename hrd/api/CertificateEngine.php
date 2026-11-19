<?php
// imis/otrs/hrd/api/CertificateEngine.php
// Centralized Certificate Engine — static class for all CCS cert types.
// Include AFTER: tcpdf.php, fpdi/src/autoload.php, phpqrcode/qrlib.php.
// Guard prevents re-declaration if included from multiple endpoints.

if (defined('CCS_ENGINE_LOADED')) return;
define('CCS_ENGINE_LOADED', true);

class CertificateEngine
{
    // Static color arrays — used as spread args to SetTextColor()
    private static array $red  = [236, 28, 45];
    private static array $navy = [42, 51, 143];

    // Path helpers (class constants cannot use __DIR__ portably)
    // Engine lives at imis/otrs/hrd/api/ — 4 levels up reaches public_html/
    private static function fontsDir(): string
    {
        return __DIR__ . '/../../../../assets/fonts/';
    }
    private static function rootImgDir(): string
    {
        return __DIR__ . '/../../../../assets/img/';
    }
    private static function qrDir(): string
    {
        return __DIR__ . '/../../assets/uploads/qrcodes/';
    }
    private static function certDir(): string
    {
        return __DIR__ . '/../../assets/uploads/certificates/';
    }
    private static function hrdImgDir(): string
    {
        return __DIR__ . '/../assets/img/';
    }
    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Generate a unique certificate number with an advisory DB lock.
     * Must be called inside an already-open transaction.
     */
    public static function generateCertNumber(PDO $db, string $year): string
    {
        $prefix   = "CERT-{$year}-";
        $lockName = "ccs_cert_seq_{$year}";
        $locked   = false;

        try {
            $lock = $db->query("SELECT GET_LOCK('{$lockName}', 10)")->fetchColumn();
            if (!$lock) throw new RuntimeException('Could not acquire certificate number lock.');
            $locked = true;

            // Union MAX across all three certificate stores
            $stmt = $db->prepare("
                SELECT MAX(CAST(SUBSTRING_INDEX(certificate_number, '-', -1) AS UNSIGNED)) AS max_seq
                FROM (
                    SELECT certificate_number FROM otrs_certificates
                     WHERE certificate_number LIKE :p1
                    UNION ALL
                    SELECT certificate_number FROM otrs_agency_cert_records
                     WHERE certificate_number LIKE :p2
                    UNION ALL
                    SELECT certificate_number FROM otrs_ccs_certificates
                     WHERE certificate_number LIKE :p3
                ) AS combined
            ");
            $stmt->execute([':p1' => $prefix . '%', ':p2' => $prefix . '%', ':p3' => $prefix . '%']);
            $row    = $stmt->fetch(PDO::FETCH_ASSOC);
            $newSeq = (int)($row['max_seq'] ?? 0) + 1;
            return $prefix . str_pad($newSeq, 6, '0', STR_PAD_LEFT);
        } finally {
            if ($locked) $db->query("SELECT RELEASE_LOCK('{$lockName}')");
        }
    }

    /**
     * Generate a QR PNG composited with the CSC logo.
     * Returns just the filename (stored in qrDir()); false on failure.
     */
    public static function generateQRCode(string $certNumber, string $url): string|false
    {
        $qrDir = self::qrDir();
        if (!is_dir($qrDir)) @mkdir($qrDir, 0755, true);

        $safe    = preg_replace('/[^A-Za-z0-9_-]/', '_', $certNumber);
        $tmpFile = $qrDir . 'tmp_' . $safe . '_' . time() . '.png';
        $outFile = 'qr_' . $safe . '_' . time() . '.png';
        $outPath = $qrDir . $outFile;

        try {
            QRcode::png($url, $tmpFile, QR_ECLEVEL_M, 6, 2);
            if (!file_exists($tmpFile)) return false;

            $qr   = imagecreatefrompng($tmpFile);
            $size = imagesx($qr);

            $logoPath = self::hrdImgDir() . 'csclogo.png';
            if (file_exists($logoPath)) {
                $logo     = imagecreatefrompng($logoPath);
                $lSize    = (int)($size * 0.22);
                $lOffset  = (int)(($size - $lSize) / 2);
                imagecopyresampled($qr, $logo, $lOffset, $lOffset, 0, 0,
                    $lSize, $lSize, imagesx($logo), imagesy($logo));
                imagedestroy($logo);
            }

            imagepng($qr, $outPath);
            imagedestroy($qr);
            @unlink($tmpFile);
            return $outFile;
        } catch (Throwable) {
            @unlink($tmpFile);
            return false;
        }
    }

    /**
     * Generate a certificate PDF.
     *
     * $details required keys (all cert types):
     *   category         — completion|certification|appearance|merged
     *   participant_name — display name string
     *   cert_number      — CERT-YYYY-NNNNNN
     *   qr_file          — PNG filename inside qrDir()
     *   training_title   — activity/training full title
     *   display_date     — human-readable date (e.g. "June 15–19, 2026")
     *   start_date       — ISO date YYYY-MM-DD (for folder naming + "Given this")
     *   venue            — location string
     *   source           — public_offered|agency|internal|appearance
     *   index            — int (for unique filename)
     *
     * Additional for A4 certs:
     *   training_mode    — face-to-face|online|hybrid|blended (affects e-sig + venue text)
     *   no_hrs           — int; 0 omits the credit hours line
     *   training_type    — Technical|Foundation|Leadership and Management
     *   training_level   — Regular|Technical|etc.
     *
     * Additional for appearance only:
     *   reference_no     — optional reference number printed bottom-right
     *
     * Additional for certification only:
     *   position_title   — participant's position/designation (optional)
     *   agency_name      — participant's agency/office (optional)
     *
     * Returns relative path under certDir() (e.g. "Agency-Certificates/…/cert.pdf"),
     * or false on failure.
     */
    public static function generatePDF(array $details): string|false
    {
        $category = strtolower($details['category'] ?? 'completion');
        return match ($category) {
            'appearance'           => self::renderAppearance($details),
            'certification'        => self::renderCertification($details),
            'merged'               => self::renderMergedLandscape($details),
            'merged_certification' => self::renderMergedCertificationLandscape($details),
            default                => self::renderA4($details),
        };
    }

    /**
     * Insert a row into otrs_ccs_certificates and write an 'issued' log entry.
     * Returns the new row id.
     */
    public static function storeRecord(
        PDO    $db,
        array  $data,
        string $actor   = 'system',
        ?int   $actorId = null,
        string $ip      = ''
    ): int {
        $stmt = $db->prepare("
            INSERT INTO otrs_ccs_certificates
                (certificate_number, source, category,
                 participant_id, participant_name,
                 training_id, training_title, venue, start_date, end_date,
                 no_hrs, training_type, training_mode, training_level,
                 position, agency,
                 batch_id, certificate_file, qr_code, qr_code_data,
                 verification_token, status, issued_by, generated_at, generated_by)
            VALUES
                (:cn, :src, :cat,
                 :pid, :pname,
                 :tid, :ttitle, :venue, :sd, :ed,
                 :nhrs, :ttype, :tmode, :tlevel,
                 :pos, :agc,
                 :bid, :cf, :qr, :qd,
                 :vt, :status, :issuedby, NOW(), :gby)
        ");
        $stmt->execute([
            ':cn'      => $data['certificate_number'],
            ':src'     => $data['source'],
            ':cat'     => $data['category'],
            ':pid'     => $data['participant_id']   ?? null,
            ':pname'   => $data['participant_name'] ?? '',
            ':tid'     => $data['training_id']      ?? null,
            ':ttitle'  => $data['training_title']   ?? '',
            ':venue'   => $data['venue']             ?? '',
            ':sd'      => $data['start_date']        ?? null,
            ':ed'      => $data['end_date']          ?? null,
            ':nhrs'    => $data['no_hrs']            ?? null,
            ':ttype'   => $data['training_type']     ?? null,
            ':tmode'   => $data['training_mode']     ?? null,
            ':tlevel'  => $data['training_level']    ?? null,
            ':pos'     => ($data['position'] ?? '') ?: null,
            ':agc'     => ($data['agency']   ?? '') ?: null,
            ':bid'     => $data['batch_id']          ?? null,
            ':cf'      => $data['certificate_file'],
            ':qr'      => $data['qr_code'],
            ':qd'      => $data['qr_code_data']      ?? '',
            ':vt'      => $data['verification_token'] ?? null,
            ':status'  => $data['status']            ?? 'active',
            ':issuedby'=> $data['issued_by']         ?? 'OTRS System',
            ':gby'     => $data['generated_by']      ?? null,
        ]);
        $id = (int)$db->lastInsertId();
        self::writeLog($db, $id, 'issued', null, $data['certificate_file'], $actor, $actorId, $ip);
        return $id;
    }

    /**
     * Re-issue a CCS certificate — same cert number, new QR + PDF.
     * Returns ['cert_id', 'cert_number', 'cert_file'] or throws RuntimeException.
     */
    public static function reissue(PDO $db, int $ccsId, int $actorId, string $ip): array
    {
        $stmt = $db->prepare('SELECT * FROM otrs_ccs_certificates WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $ccsId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException("CCS certificate #{$ccsId} not found.");
        if ($row['status'] === 'voided') throw new RuntimeException('Cannot re-issue a voided certificate.');

        $oldFile = $row['certificate_file'];
        $certNo  = $row['certificate_number'];

        // Appearance certs carry no certificate number, QR, or verification
        // token — skip QR/token generation entirely for that category.
        if ($row['category'] === 'appearance') {
            $vtoken    = null;
            $verifyUrl = '';
            $qrFile    = '';
        } else {
            $vtoken    = bin2hex(random_bytes(32));
            $verifyUrl = self::makeVerificationUrl($vtoken);
            $qrFile    = self::generateQRCode((string)$certNo, $verifyUrl);
            if (!$qrFile) throw new RuntimeException('QR generation failed during re-issue.');
        }

        $details = [
            'category'         => $row['category'],
            'participant_name' => $row['participant_name'],
            'cert_number'      => $certNo ?? '',
            'qr_file'          => $qrFile,
            'training_title'   => $row['training_title'],
            'display_date'     => self::buildDisplayDate($row['start_date'] ?? '', $row['end_date'] ?? ''),
            'start_date'       => $row['start_date'] ?? date('Y-m-d'),
            'end_date'         => $row['end_date']   ?? date('Y-m-d'),
            'venue'            => $row['venue'],
            'training_mode'    => $row['training_mode']  ?? '',
            'no_hrs'           => (int)($row['no_hrs']   ?? 0),
            'training_type'    => $row['training_type']  ?? '',
            'training_level'   => $row['training_level'] ?? '',
            'source'           => $row['source'],
            'reference_no'     => '',
            'index'            => 0,
            'position_title'   => $row['position'] ?? '',
            'agency_name'      => $row['agency']   ?? '',
        ];

        $newFile = self::generatePDF($details);
        if (!$newFile) throw new RuntimeException('PDF generation failed during re-issue.');

        $upd = $db->prepare("
            UPDATE otrs_ccs_certificates
               SET certificate_file    = :cf,
                   qr_code             = :qr,
                   qr_code_data        = :qd,
                   verification_token  = :vt,
                   status              = 'active',
                   generated_at        = NOW(),
                   generated_by        = :gby
             WHERE id = :id
        ");
        $upd->execute([
            ':cf'  => $newFile,
            ':qr'  => $qrFile,
            ':qd'  => $verifyUrl,
            ':vt'  => $vtoken,
            ':gby' => $actorId ?: null,
            ':id'  => $ccsId,
        ]);

        // Delete old PDF (non-fatal)
        if ($oldFile) {
            $abs = self::certDir() . ltrim($oldFile, '/');
            if (file_exists($abs)) @unlink($abs);
        }

        self::writeLog($db, $ccsId, 'reissued', $oldFile, $newFile, (string)$actorId, $actorId, $ip);
        return ['cert_id' => $ccsId, 'cert_number' => $certNo, 'cert_file' => $newFile];
    }

    // ── Utility ───────────────────────────────────────────────────────────────

    /**
     * Normalize a training mode string to the hyphenated form used throughout
     * this engine (face-to-face|online|hybrid|blended). otrs_trainings.mode
     * stores space-separated title case ("Face to Face"), while the CCS/agency
     * flows already use the hyphenated form — this makes every mode comparison
     * in this class work regardless of which source it came from.
     */
    public static function normalizeMode(?string $mode): string
    {
        return strtolower(trim(str_replace(' ', '-', $mode ?? '')));
    }

    public static function buildDisplayDate(string $start, string $end): string
    {
        if (!$start) return '';
        $ts = strtotime($start);
        $te = strtotime($end);
        $ds = (int)date('j', $ts);
        $de = (int)date('j', $te);
        $m  = date('F', $ts);
        $y  = date('Y', $ts);
        if (!$end || $start === $end) return "{$ds} {$m} {$y}";
        if (date('Y-m', $ts) === date('Y-m', $te)) return "{$ds}–{$de} {$m} {$y}";
        return date('j F', $ts) . '–' . date('j F Y', $te);
    }

    public static function numberToWords(int $n): string
    {
        if ($n < 0)  return 'negative ' . self::numberToWords(-$n);
        if ($n === 0) return 'zero';
        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven',
                 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen',
                 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty',
                 'sixty', 'seventy', 'eighty', 'ninety'];
        if ($n < 20)   return ucfirst($ones[$n]);
        if ($n < 100)  return ucfirst($tens[(int)($n / 10)] . ($n % 10 ? '-' . $ones[$n % 10] : ''));
        if ($n < 1000) return ucfirst($ones[(int)($n / 100)] . ' hundred'
            . ($n % 100 ? ' and ' . self::numberToWords($n % 100) : ''));
        return (string)$n;
    }

    public static function makeVerificationUrl(string $token): string
    {
        $host    = $_SERVER['HTTP_HOST'] ?? '';
        $isLocal = in_array($host, ['localhost', '127.0.0.1'], true);
        $base    = $isLocal ? 'http://localhost/ongbak/otrs/' : 'https://otrs.cscro8.com/';
        return $base . 'verify-certificate.php?token=' . $token;
    }

    // ── Private renderers ─────────────────────────────────────────────────────

    /**
     * A4 portrait — all training certificate categories.
     * Layout matches the original cert_issue_ajax_v1.php exactly.
     */
    private static function renderA4(array $d, ?string $outputPathOverride = null, bool $hideSignature = false): string|false
    {
        $paxName   = strtoupper(trim($d['participant_name'] ?? ''));
        $certNo    = $d['cert_number'] ?? '';
        $qrFile    = $d['qr_file'] ?? '';
        $title     = $d['training_title'] ?? '';
        $date      = $d['display_date'] ?? '';
        $startDate = $d['start_date'] ?? date('Y-m-d');
        $venue     = $d['venue'] ?? '';
        $mode      = CertificateEngine::normalizeMode($d['training_mode'] ?? '');
        $noHrs     = (int)($d['no_hrs'] ?? 0);
        $ttype     = trim($d['training_type'] ?? '');
        $tlevel    = trim($d['training_level'] ?? '');
        $source    = $d['source'] ?? 'agency';
        $idx       = (int)($d['index'] ?? 0);

        // Internal trainings and public_offered face-to-face trainings are signed
        // in person by the Director — suppress the e-signature image.
        if (!$hideSignature && ($source === 'internal' || ($source === 'public_offered' && $mode === 'face-to-face'))) {
            $hideSignature = true;
        }
        // Manual override (e.g. agency bulk-cert "Include Signature" checkbox) —
        // suppress-only, never forces a signature to appear when the rule above
        // already hides it.
        if (!empty($d['hide_signature'])) {
            $hideSignature = true;
        }

        // Category-driven text
        $titleText  = 'CERTIFICATE OF COMPLETION';
        $titleColor = self::$red;
        $preamble   = 'is given to';
        $actionText = 'for completing';
        $showHrs    = ($noHrs > 0 && ($ttype !== '' || $tlevel !== ''));

        self::ensureFontsCopied();
        $fCalibri       = self::$registeredFonts['calibri'];
        $fCalibriItalic = self::$registeredFonts['calibriitalic'];
        $fRobotoBold    = self::$registeredFonts['robotobold'];
        $fMirante       = self::$registeredFonts['mirante'];

        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('OTRS CCS');
        $pdf->SetAuthor('Civil Service Commission Regional Office VIII');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage();

        // Full-page certificate background
        $bg = self::hrdImgDir() . 'certificate_bg.jpg';
        if (file_exists($bg)) {
            $pdf->Image($bg, 0, 0, 210, 297, 'JPG', '', 'T', false, 300, '', false, false, 0);
        }

        // Shift the whole "This" → signature block UP, without moving the QR
        // code (anchored independently via the fixed $bottomY further below).
        // The ceiling here is the CSC logo baked into certificate_bg.jpg,
        // which spans roughly y=16mm-49mm — empirically, 20mm already pushes
        // "This" into it, so this is kept well below that for a comfortable
        // breathing gap. This ceiling is independent of certificate content
        // (title length, category, credit-hours line, etc.), unlike a
        // downward shift, which is bounded by the signature block colliding
        // with the "Lingkod Bayani" logo near the bottom on tall content.
        $yShift = 6;

        // "This"
        $pdf->SetXY(0, 65 - $yShift);
        $pdf->SetFont($fCalibri, '', 14);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(210, 6, 'This', 0, 1, 'C');

        $pdf->Ln(4);

        // Certificate title
        $pdf->SetXY(0, 74 - $yShift);
        $pdf->SetFont($fRobotoBold, '', 24);
        $pdf->SetTextColor(...$titleColor);
        $pdf->Cell(210, 10, $titleText, 0, 1, 'C');

        // Preamble
        $pdf->SetFont($fCalibri, '', 14);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(4);
        $pdf->Cell(210, 6, $preamble, 0, 1, 'C');

        // Participant name (auto-shrink 26 → 14 pt)
        $pdf->Ln(6);
        $pdf->SetFont($fMirante, '', 26);
        $pdf->SetTextColor(...self::$navy);
        $nameW   = 170;
        $nameX   = (210 - $nameW) / 2;
        $namePts = 26;
        $pdf->SetX($nameX);
        while ($namePts > 18 && $pdf->GetStringWidth($paxName) > $nameW) {
            $namePts -= 0.5;
            $pdf->SetFontSize($namePts);
        }
        $pdf->Cell($nameW, 10, $paxName, 0, 1, 'C');
        $pdf->Ln(2);
        $nameLineY = $pdf->GetY();
        $pdf->SetLineWidth(0.5);
        $pdf->SetDrawColor(...self::$navy);
        $pdf->Line(50, $nameLineY, 160, $nameLineY);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.2);

        // Action text — merged with credit hours when applicable
        $pdf->SetFont($fCalibri, '', 14);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(4);
        if ($showHrs) {
            $level    = $ttype ?: $tlevel;
            $hrsWords = self::numberToWords($noHrs);
            $pdf->Cell(210, 6, "for having successfully completed the", 0, 1, 'C');
        } else {
            $pdf->Cell(210, 6, $actionText, 0, 1, 'C');
        }

        // Training / activity title — auto-shrinks 24 → 16pt if it would wrap
        // to more than 2 lines, so a long title doesn't push the signature
        // block further than the layout has room for.
        $pdf->Ln(1);
        $ttW    = 170;
        $ttPts  = 24;
        $pdf->SetFont($fMirante, '', $ttPts);
        while ($ttPts > 20 && $pdf->getNumLines($title, $ttW) > 2) {
            $ttPts -= 0.5;
            $pdf->SetFontSize($ttPts);
        }
        $pdf->SetTextColor(...self::$navy);
        $pdf->MultiCell($ttW, 9, $title, 0, 'C', false, 1, (210 - $ttW) / 2);

        // Credit hours — shown directly below the training name, in italics
        if ($showHrs) {
            $pdf->Ln(2);
            $pdf->SetFont($fCalibriItalic, '', 14);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(210, 6, "(Credit of {$hrsWords} [{$noHrs}] {$level} Training Hours)", 0, 1, 'C');
        }

        // Venue / date block
        $pdf->SetFont($fCalibri, '', 14);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);

        if ($mode === 'online') {
            $pdf->Cell(210, 6, 'conducted online by the', 0, 1, 'C');
            $pdf->Ln(1);

            $pdf->Cell(210, 6, 'Civil Service Commission Regional Office VIII on', 0, 1, 'C');
            $pdf->Ln(1);

            $pdf->Cell(210, 6, "{$date}.", 0, 1, 'C');
        } else {
            if ($venue) {

                $pdf->SetX(30);
                $pdf->MultiCell(150, 6, "held on {$date} at the", 0, 'C');

                $pdf->Ln(2); // Small extra gap (adjust 0.5, 1, 1.5, 2, etc.)

                // Venue auto-shrinks 14 → 11pt if it would wrap past 2 lines.
                $venuePts = 14;
                $pdf->SetFont($fCalibri, '', $venuePts);
                while ($venuePts > 11 && $pdf->getNumLines("{$venue}.", 150) > 2) {
                    $venuePts -= 0.5;
                    $pdf->SetFontSize($venuePts);
                }
                $pdf->SetX(30);
                $pdf->MultiCell(150, 6, "{$venue}.", 0, 'C');

            } else {

                $pdf->SetX(30);
                $pdf->MultiCell(
                    150,
                    6,
                    "held on {$date}.",
                    0,
                    'C'
                );

            }
        }

        // "Given this Nth of Month Year."
        $givenDateRaw = (!empty($d['end_date']) && $d['end_date'] !== '0000-00-00')
            ? $d['end_date'] : $startDate;
        $pdf->Ln(6);
        $givenD  = (int)date('j', strtotime($givenDateRaw));
        $mod10   = $givenD % 10;
        $mod100  = $givenD % 100;
        $sfx     = ($mod10 === 1 && $mod100 !== 11) ? 'st'
                 : (($mod10 === 2 && $mod100 !== 12) ? 'nd'
                 : (($mod10 === 3 && $mod100 !== 13) ? 'rd' : 'th'));
        $givenMY = date('F Y', strtotime($givenDateRaw));
        $pdf->SetX(0);
        $pdf->writeHTML(
            '<p style="text-align:center;font-family:' . $fCalibri . ';font-size:14pt;">'
            . "Given this {$givenD}<sup>{$sfx}</sup> day of {$givenMY}.</p>",
            true, false, true, false, ''
        );

        $pdf->Ln(4);

        // ── Signature block — anchored below "Given this", no upper cap ──────
        $contentEndY = $pdf->GetY();
        // Hard cap at 236 — keeps a ~5mm buffer above the "Lingkod Bayani"
        // logo (starts ~y=245mm) on tall content (long titles, online mode,
        // credit-hours line) without undercutting the preceding text, which
        // never reaches that far down even in the tallest tested cases.
        $sig1NameY = min(max(200, $contentEndY + 25), 236);
        $bottomY   = 245;

        // e-sig 1 (Taldo) — size bumped 20% (45×18 → 54×21.6); top shifted up by
        // the added height so the bottom edge stays anchored at the same spot
        // just above the name, instead of pushing further down into it.
        if (!$hideSignature) {
            $sig1Path = self::hrdImgDir() . 'eSign_METaldo.png';
            if (file_exists($sig1Path)) {
                $eW = 54; $eH = 21.6;
                $pdf->Image($sig1Path, (210 - $eW) / 2, $sig1NameY - 23.6, $eW, $eH, 'PNG');
            }
        }

        // Fonts bumped 20% over the original (16pt → 19pt name, 14pt → 17pt title).
        $pdf->SetFont($fMirante, '', 19);
        $pdf->SetTextColor(...self::$navy);
        $pdf->SetXY(0, $sig1NameY - 9); // move up by 10mm, plus the extra ~1mm the larger font needs
        $pdf->Cell(210, 10, 'ATTY. MARILYN E. TALDO', 0, 1, 'C');

        $pdf->Ln(-1);

        $pdf->SetFont($fCalibri, '', 17);
        $pdf->SetTextColor(...self::$navy);
        $pdf->Cell(210, 6, 'Director IV', 0, 1, 'C');
        // $pdf->Cell(210, 5, 'Civil Service Commission Regional Office VIII', 0, 1, 'C')

        // QR code (bottom-left, moved up from $bottomY)
        $qrPath = self::qrDir() . $qrFile;
        if ($qrFile && file_exists($qrPath)) {
            $pdf->Image($qrPath, 20, $bottomY - 10, 35, 35, 'PNG');
            $pdf->SetFont($fCalibri, '', 8);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->SetXY(10, $bottomY + 25);
            $pdf->Cell(55, 4, "Cert No: {$certNo}", 0, 0, 'C');
        }

        if ($outputPathOverride !== null) {
            $pdf->Output($outputPathOverride, 'F');
            return file_exists($outputPathOverride) ? $outputPathOverride : false;
        }

        return self::savePdf($pdf, $source, $title, $startDate, $paxName, $idx);
    }

    /**
     * A4 portrait — Certification. A formal letter-style attestation rather
     * than the card-style layout used by Completion;
     * names the participant's position/agency and is signed by HRD, not the
     * Director.
     */
    private static function renderCertification(array $d, ?string $outputPathOverride = null, bool $hideSignature = false): string|false
    {
        $paxName   = strtoupper(trim($d['participant_name'] ?? ''));
        $certNo    = $d['cert_number'] ?? '';
        $qrFile    = $d['qr_file'] ?? '';
        $title     = $d['training_title'] ?? '';
        $date      = $d['display_date'] ?? '';
        $startDate = $d['start_date'] ?? date('Y-m-d');
        $venue     = $d['venue'] ?? '';
        $mode      = CertificateEngine::normalizeMode($d['training_mode'] ?? '');
        $noHrs     = (int)($d['no_hrs'] ?? 0);
        $ttype     = trim($d['training_type'] ?? '');
        $tlevel    = trim($d['training_level'] ?? '');
        $position  = trim($d['position_title'] ?? '');
        $agency    = trim($d['agency_name'] ?? '');
        $source    = $d['source'] ?? 'agency';
        $idx       = (int)($d['index'] ?? 0);

        // Internal trainings and public_offered face-to-face trainings are signed
        // in person by the HRS Chief — suppress the e-signature image.
        if (!$hideSignature && ($source === 'internal' || ($source === 'public_offered' && $mode === 'face-to-face'))) {
            $hideSignature = true;
        }
        // Manual override (e.g. agency bulk-cert "Include Signature" checkbox) —
        // suppress-only, never forces a signature to appear when the rule above
        // already hides it.
        if (!empty($d['hide_signature'])) {
            $hideSignature = true;
        }

        self::ensureFontsCopied();
        $fRoboto     = self::$registeredFonts['roboto'];
        $fRobotoBold = self::$registeredFonts['robotobold'];
        $fMirante    = self::$registeredFonts['mirante'];
        $navyHex      = sprintf('#%02x%02x%02x', ...self::$navy);

        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('OTRS CCS');
        $pdf->SetAuthor('Civil Service Commission Regional Office VIII');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage();

        // Full-page certificate background
        $bg = self::hrdImgDir() . 'certificate_bg.jpg';
        if (file_exists($bg)) {
            $pdf->Image($bg, 0, 0, 210, 297, 'JPG', '', 'T', false, 300, '', false, false, 0);
        }

        // Title
        $pdf->SetXY(0, 70);
        $pdf->SetFont($fRobotoBold, '', 26);
        $pdf->SetTextColor(...self::$red);
        $pdf->Cell(210, 10, 'CERTIFICATION', 0, 1, 'C');

        // ── Body paragraph ──────────────────────────────────────────────
        $posAgencyClause = '';
        if ($position !== '' && $agency !== '') {
            $posAgencyClause = ", {$position} of the {$agency},";
        } elseif ($position !== '') {
            $posAgencyClause = ", {$position},";
        } elseif ($agency !== '') {
            $posAgencyClause = " of the {$agency},";
        }

        $modeClause = match (true) {
            $mode === 'online'  => 'via Zoom application',
            $mode === 'hybrid'  => 'via a hybrid (in-person and online) setup',
            $mode === 'blended' => 'via a blended learning setup',
            $venue !== ''       => "held at {$venue}",
            default             => '',
        };

        $showHrs   = ($noHrs > 0 && ($ttype !== '' || $tlevel !== ''));
        $hrsClause = '';
        if ($showHrs) {
            $level    = $ttype ?: $tlevel;
            $hrsWords = self::numberToWords($noHrs);
            $hrsClause = " with {$hrsWords} ({$noHrs}) {$level} Training Hours";
        }

        $bodyHtml = 'This is to certify that '
            . '<span style="font-family:' . $fRobotoBold . ';color:' . $navyHex . ';">'
            . htmlspecialchars($paxName) . '</span>'
            . htmlspecialchars($posAgencyClause)
            . ' attended the '
            . '<span style="font-family:' . $fRobotoBold . ';color:' . $navyHex . ';">'
            . htmlspecialchars($title) . '</span>'
            . ($modeClause !== '' ? ' ' . htmlspecialchars($modeClause) : '')
            . ' on ' . htmlspecialchars($date)
            . htmlspecialchars($hrsClause) . '.';

        // Symmetric left/right margins so the paragraph wraps with an even
        // gutter on both sides instead of running out to the page edge.
        // SetXY's X must match the margin — a mismatch is what previously
        // made justify ('J') drop inter-word spaces on wrapped lines.
        $pdf->SetLeftMargin(28);
        $pdf->SetRightMargin(28);
        $pdf->SetFont($fRoboto, '', 13);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY(28, 96);
        $pdf->writeHTML(
            '<p style="
                text-align:justify;
                text-indent:30px;
                font-family:' . $fRoboto . ';
                font-size:13pt;
                line-height:1.6;
            ">'
            . $bodyHtml .
            '</p>',
            true, false, true, false, 'J'
        );
        $bodyEndY = $pdf->GetY();
        $pdf->SetLeftMargin(0);
        $pdf->SetRightMargin(0);

        // "Issued this Nth of Month Year for whatever purpose it may serve."
        $givenDateRaw = (!empty($d['end_date']) && $d['end_date'] !== '0000-00-00')
            ? $d['end_date'] : $startDate;
        $givenD  = (int)date('j', strtotime($givenDateRaw));
        $mod10   = $givenD % 10;
        $mod100  = $givenD % 100;
        $sfx     = ($mod10 === 1 && $mod100 !== 11) ? 'st'
                 : (($mod10 === 2 && $mod100 !== 12) ? 'nd'
                 : (($mod10 === 3 && $mod100 !== 13) ? 'rd' : 'th'));
        $givenMY = date('F Y', strtotime($givenDateRaw));

        // Same left/right margins + text-indent as the body paragraph above,
        // so this line's text starts flush with the body's indented first line.
        $pdf->SetLeftMargin(28);
        $pdf->SetRightMargin(28);
        // $pdf->SetXY(28, max(130, $bodyEndY + 10));
        // Issued statement
        $pdf->SetXY(28, $bodyEndY + 4);
        $pdf->writeHTML(
            '<p style="text-align:left;text-indent:30px;font-family:' . $fRoboto . ';font-size:13pt;">'
            . "Issued this {$givenD}<sup>{$sfx}</sup> of {$givenMY} for whatever purpose it may serve.</p>",
            true, false, true, false, ''
        );
        $pdf->SetLeftMargin(0);
        $pdf->SetRightMargin(0);

        // ── Signature block ────────────────────────────────────────────
        // Buffer of 28mm keeps the e-sig image top (sigNameY-20) at least 8mm
        // below contentEndY. Cap at 236mm matches renderA4's ceiling so the
        // block never drifts into the bottom "Lingkod Bayani" logo (~245mm).
        $contentEndY = $pdf->GetY();
        $sigNameY    = min(max(160, $contentEndY + 28), 236);

        // e-sig image only when not suppressed (agency certs, non-f2f public_offered)
        if (!$hideSignature) {
            $sigPath = self::hrdImgDir() . 'eSign_JMMerelos.png';
            if (file_exists($sigPath)) {
                $eW = 40; $eH = 24;
                $pdf->Image($sigPath, (212.5 - $eW) / 2, $sigNameY - 20, $eW, $eH, 'PNG');
            }
        }

        // Signatory name and title always printed (in-person certs leave a blank line for wet ink)
        $pdf->SetFont($fMirante, '', 16);
        $pdf->SetTextColor(...self::$navy);
        $pdf->SetXY(0, $sigNameY - 8);
        $pdf->Cell(210, 8, 'JAY M. MERELOS', 0, 1, 'C');

        $pdf->SetFont($fRoboto, '', 12);
        $pdf->SetTextColor(...self::$navy);
        $pdf->Cell(210, 5, 'Chief Personnel Specialist (Chief HRS)', 0, 1, 'C');
        $pdf->Cell(210, 5, 'Human Resource Division', 0, 1, 'C');

        // QR code (bottom-left)
        $bottomY = 245;
        $qrPath  = self::qrDir() . $qrFile;
        if ($qrFile && file_exists($qrPath)) {
            $pdf->Image($qrPath, 20, $bottomY - 10, 35, 35, 'PNG');
            $pdf->SetFont($fRoboto, '', 7);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->SetXY(10, $bottomY + 25);
            $pdf->Cell(55, 4, "Cert No: {$certNo}", 0, 0, 'C');
        }

        if ($outputPathOverride !== null) {
            $pdf->Output($outputPathOverride, 'F');
            return file_exists($outputPathOverride) ? $outputPathOverride : false;
        }

        return self::savePdf($pdf, $source, $title, $startDate, $paxName, $idx);
    }

    /**
     * A4 portrait — Certificate of Appearance. The single canonical Appearance
     * design: Completion's background/fonts, indented letter-style body, bold
     * disclaimer paragraph, centered signature (Jay M. Merelos). No QR code —
     * Appearance certs are not independently verification-token based. Used
     * both standalone (appearance_certificates.php batch wizard) and as the
     * right half of the Merged (Completion + Appearance) landscape format.
     */
    public static function renderAppearance(array $d, ?string $outputPathOverride = null, bool $hideSignature = false): string|false
    {
        $paxName   = strtoupper(trim($d['participant_name'] ?? ''));
        $title     = $d['training_title'] ?? '';
        $date      = $d['display_date'] ?? '';
        $startDate = $d['start_date'] ?? date('Y-m-d');
        $venue     = trim($d['venue'] ?? '');
        $mode      = CertificateEngine::normalizeMode($d['training_mode'] ?? '');
        $source    = $d['source'] ?? 'appearance';
        $idx       = (int)($d['index'] ?? 0);

        // Same rule as renderA4()/renderCertification(): Internal trainings and
        // public_offered Face-to-Face trainings are signed in person by the
        // Director — suppress the e-signature image. Only applies when called
        // from the Merged renderers (source is 'internal'/'public_offered' there);
        // standalone Appearance issuance uses source='appearance' and is unaffected.
        if (!$hideSignature && ($source === 'internal' || ($source === 'public_offered' && $mode === 'face-to-face'))) {
            $hideSignature = true;
        }
        // Manual override (e.g. agency bulk-cert "Include Signature" checkbox) —
        // suppress-only, never forces a signature to appear when the rule above
        // already hides it.
        if (!empty($d['hide_signature'])) {
            $hideSignature = true;
        }

        self::ensureFontsCopied();
        $fCalibri     = self::$registeredFonts['calibri'];
        $fCalibriBold = self::$registeredFonts['calibribold'];
        $fRobotoBold  = self::$registeredFonts['robotobold'];
        $fMirante     = self::$registeredFonts['mirante'];
        $navyHex      = sprintf('#%02x%02x%02x', ...self::$navy);

        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('OTRS CCS');
        $pdf->SetAuthor('Civil Service Commission Regional Office VIII');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage();

        // Same full-page background as the Completion certificate.
        $bg = self::hrdImgDir() . 'certificate_bg.jpg';
        if (file_exists($bg)) {
            $pdf->Image($bg, 0, 0, 210, 297, 'JPG', '', 'T', false, 300, '', false, false, 0);
        }

        // Title
        $pdf->SetXY(0, 68);
        $pdf->SetFont($fRobotoBold, '', 28);
        $pdf->SetTextColor(...self::$red);
        $pdf->Cell(210, 12, 'CERTIFICATE OF APPEARANCE', 0, 1, 'C');

        $heldClause = $mode === 'online'
            ? 'held via Zoom Workplace'
            : ($venue !== '' ? "held at {$venue}" : '');

        $para1 = 'This is to certify that '
            . '<span style="font-family:' . $fCalibriBold . ';color:' . $navyHex . ';text-decoration:underline;">'
            . htmlspecialchars($paxName) . '</span>'
            . ' attended the '
            . '<span style="font-family:' . $fCalibriBold . ';color:' . $navyHex . ';">'
            . htmlspecialchars($title) . '</span>'
            . ' on ' . htmlspecialchars($date)
            . ($heldClause !== '' ? ', ' . htmlspecialchars($heldClause) : '') . '.';

        $para2 = '<span style="font-family:' . $fCalibriBold . ';">'
            . 'This further certifies that this Certificate of Appearance shall not substitute '
            . 'for the Certificate of Training which shall only be issued upon full payment of the registration fee.'
            . '</span>';

        // "Issued ... this {last day of training}{st/th}, {location}." — location
        // is the RO8 office address for online trainings, the training venue
        // for face-to-face ones.
        $givenDateRaw = (!empty($d['end_date']) && $d['end_date'] !== '0000-00-00')
            ? $d['end_date'] : $startDate;
        $givenD  = (int)date('j', strtotime($givenDateRaw));
        $mod10   = $givenD % 10;
        $mod100  = $givenD % 100;
        $sfx     = ($mod10 === 1 && $mod100 !== 11) ? 'st'
                 : (($mod10 === 2 && $mod100 !== 12) ? 'nd'
                 : (($mod10 === 3 && $mod100 !== 13) ? 'rd' : 'th'));
        $givenMY = date('F Y', strtotime($givenDateRaw));

        $location = $mode === 'online'
            ? 'Civil Service Commission Regional Office VIII, Government Center, Palo, Leyte'
            : ($venue !== '' ? $venue : 'Civil Service Commission Regional Office VIII, Government Center, Palo, Leyte');

        $para3 = "Issued this {$givenD}<sup>{$sfx}</sup> day of {$givenMY} at the "
            . htmlspecialchars($location)
            . " for whatever purpose it may serve.";

        // Plain-text equivalents (tags stripped) purely for getNumLines() — the
        // actual rendering below still uses the HTML-formatted $para1/2/3.
        $plain1 = "This is to certify that {$paxName} attended the {$title} on {$date}"
            . ($heldClause !== '' ? ", {$heldClause}" : '') . '.';
        $plain2 = 'This further certifies that this Certificate of Appearance shall not substitute '
            . 'for the Certificate of Training which shall only be issued upon full payment of the registration fee.';
        $plain3 = "Issued this {$givenD}{$sfx} of {$givenMY} at the {$location} for whatever purpose it may serve.";

        // Indented, justified body — same margins/indent convention as renderCertification().
        // Body font auto-shrinks 15 → 11pt when the combined name/title/venue
        // text would wrap past ~9 lines total, so a long participant name,
        // training title, or venue doesn't push the signature block too far down.
        $pdf->SetLeftMargin(26);
        $pdf->SetRightMargin(26);
        $bodyWidth = 210 - 26 - 26;
        $bodyPt    = 15;
        $pdf->SetFont($fCalibri, '', $bodyPt);
        while ($bodyPt > 11 && (
                $pdf->getNumLines($plain1, $bodyWidth)
              + $pdf->getNumLines($plain2, $bodyWidth)
              + $pdf->getNumLines($plain3, $bodyWidth)
            ) > 9) {
            $bodyPt -= 0.5;
            $pdf->SetFontSize($bodyPt);
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY(26, 98);
        $pdf->writeHTML(
            '<p style="text-align:justify;text-indent:32px;font-family:' . $fCalibri . ';font-size:' . $bodyPt . 'pt;line-height:1.55;">'
            . $para1 . '</p>'
            . '<p style="text-align:justify;text-indent:32px;font-family:' . $fCalibriBold . ';font-size:' . $bodyPt . 'pt;line-height:1.55;">'
            . $para2 . '</p>'
            . '<p style="text-align:justify;text-indent:32px;font-family:' . $fCalibri . ';font-size:' . $bodyPt . 'pt;line-height:1.55;">'
            . $para3 . '</p>',
            true, false, true, false, 'J'
        );
        $bodyEndY = $pdf->GetY();
        $pdf->SetLeftMargin(0);
        $pdf->SetRightMargin(0);

        // ── Signature block — centered, no QR ───────────────────────────
        // Nudged a little further down the page than the original placement,
        // but capped at 236mm — same ceiling as Completion's signature block —
        // so it can never drift into the bottom logo even on long content.
        $sigNameY = min(max(208, $bodyEndY + 40), 236);

        // e-signature image (same file used in renderCertification, same offset
        // convention: top at sigNameY-20, bottom overlapping slightly into the name)
        if (!$hideSignature) {
            $sigPath = self::hrdImgDir() . 'eSign_JMMerelos.png';
            if (file_exists($sigPath)) {
                $eW = 40; $eH = 24;
                $pdf->Image($sigPath, (210 - $eW) / 2, $sigNameY - $eH + 4, $eW, $eH, 'PNG');
            }
        }

        $pdf->SetFont($fMirante, '', 18);
        $pdf->SetTextColor(...self::$navy);
        $pdf->SetXY(0, $sigNameY - 8); // move up 5mm
        $pdf->Cell(210, 9, 'JAY M. MERELOS', 0, 1, 'C');

        $pdf->SetFont($fCalibri, '', 15);
        $pdf->SetTextColor(...self::$navy);
        $pdf->Cell(210, 6, 'Chief Personnel Specialist (Chief HRS)', 0, 1, 'C');
        $pdf->Cell(210, 6, 'Human Resource Division', 0, 1, 'C');

        if ($outputPathOverride !== null) {
            $pdf->Output($outputPathOverride, 'F');
            return file_exists($outputPathOverride) ? $outputPathOverride : false;
        }

        return self::savePdf($pdf, $source, $title, $startDate, $paxName, $idx);
    }

    /**
     * A4 landscape — Merged Completion + Appearance. Renders both halves to
     * scratch files via the existing renderA4()/renderAppearance() renderers
     * (unmodified content/fonts/signatures), then imports each as an FPDI
     * template scaled into the left/right half of one landscape page. Only
     * the Completion half carries a QR code, per renderAppearance()'s no-QR
     * design — one shared certificate number covers the whole document.
     */
    public static function renderMergedLandscape(array $d): string|false
    {
        $source    = $d['source'] ?? 'agency';
        $title     = $d['training_title'] ?? '';
        $startDate = $d['start_date'] ?? date('Y-m-d');
        $paxName   = strtoupper(trim($d['participant_name'] ?? ''));
        $idx       = (int)($d['index'] ?? 0);

        $scratchCompletion = sys_get_temp_dir() . '/ccs_merged_completion_' . bin2hex(random_bytes(6)) . '.pdf';
        $scratchAppearance = sys_get_temp_dir() . '/ccs_merged_appearance_' . bin2hex(random_bytes(6)) . '.pdf';

        $cleanup = function () use ($scratchCompletion, $scratchAppearance) {
            if (file_exists($scratchCompletion)) @unlink($scratchCompletion);
            if (file_exists($scratchAppearance)) @unlink($scratchAppearance);
        };

        $completionOk = self::renderA4($d, $scratchCompletion, false);
        $appearanceOk = self::renderAppearance($d, $scratchAppearance, false);
        if (!$completionOk || !$appearanceOk) {
            $cleanup();
            return false;
        }

        $pageW = 297.0;
        $pageH = 210.0;
        $halfW = $pageW / 2;

        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('OTRS CCS');
        $pdf->SetAuthor('Civil Service Commission Regional Office VIII');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage();

        // Left half — Completion
        $pdf->setSourceFile($scratchCompletion);
        $tplL = $pdf->importPage(1);
        $sizeL = $pdf->getTemplateSize($tplL);
        $scaleL = min($halfW / $sizeL['width'], $pageH / $sizeL['height']);
        $wL = $sizeL['width'] * $scaleL;
        $hL = $sizeL['height'] * $scaleL;
        $pdf->useTemplate($tplL, ($halfW - $wL) / 2, ($pageH - $hL) / 2, $wL, $hL);

        // Right half — Appearance
        $pdf->setSourceFile($scratchAppearance);
        $tplR = $pdf->importPage(1);
        $sizeR = $pdf->getTemplateSize($tplR);
        $scaleR = min($halfW / $sizeR['width'], $pageH / $sizeR['height']);
        $wR = $sizeR['width'] * $scaleR;
        $hR = $sizeR['height'] * $scaleR;
        $pdf->useTemplate($tplR, $halfW + ($halfW - $wR) / 2, ($pageH - $hR) / 2, $wR, $hR);

        // Thin divider so the two halves read as distinct documents
        $pdf->SetLineWidth(0.15);
        $pdf->SetDrawColor(190, 190, 190);
        $pdf->Line($halfW, 4, $halfW, $pageH - 4);

        $relPath = self::savePdf($pdf, $source, $title, $startDate, $paxName, $idx);
        $cleanup();
        return $relPath;
    }

    /**
     * A4 landscape — Merged Certification + Appearance. Same layout as
     * renderMergedLandscape but left half uses renderCertification instead of
     * renderA4 (Completion). Right half is Appearance, identical to the existing
     * merged format.
     */
    public static function renderMergedCertificationLandscape(array $d): string|false
    {
        $source    = $d['source'] ?? 'agency';
        $title     = $d['training_title'] ?? '';
        $startDate = $d['start_date'] ?? date('Y-m-d');
        $paxName   = strtoupper(trim($d['participant_name'] ?? ''));
        $idx       = (int)($d['index'] ?? 0);

        $scratchCertification = sys_get_temp_dir() . '/ccs_merged_cert_' . bin2hex(random_bytes(6)) . '.pdf';
        $scratchAppearance    = sys_get_temp_dir() . '/ccs_merged_app_'  . bin2hex(random_bytes(6)) . '.pdf';

        $cleanup = function () use ($scratchCertification, $scratchAppearance) {
            if (file_exists($scratchCertification)) @unlink($scratchCertification);
            if (file_exists($scratchAppearance))    @unlink($scratchAppearance);
        };

        $certificationOk = self::renderCertification($d, $scratchCertification, false);
        $appearanceOk    = self::renderAppearance($d, $scratchAppearance, false);
        if (!$certificationOk || !$appearanceOk) {
            $cleanup();
            return false;
        }

        $pageW = 297.0;
        $pageH = 210.0;
        $halfW = $pageW / 2;

        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('OTRS CCS');
        $pdf->SetAuthor('Civil Service Commission Regional Office VIII');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage();

        // Left half — Certification
        $pdf->setSourceFile($scratchCertification);
        $tplL = $pdf->importPage(1);
        $sizeL = $pdf->getTemplateSize($tplL);
        $scaleL = min($halfW / $sizeL['width'], $pageH / $sizeL['height']);
        $wL = $sizeL['width'] * $scaleL;
        $hL = $sizeL['height'] * $scaleL;
        $pdf->useTemplate($tplL, ($halfW - $wL) / 2, ($pageH - $hL) / 2, $wL, $hL);

        // Right half — Appearance
        $pdf->setSourceFile($scratchAppearance);
        $tplR = $pdf->importPage(1);
        $sizeR = $pdf->getTemplateSize($tplR);
        $scaleR = min($halfW / $sizeR['width'], $pageH / $sizeR['height']);
        $wR = $sizeR['width'] * $scaleR;
        $hR = $sizeR['height'] * $scaleR;
        $pdf->useTemplate($tplR, $halfW + ($halfW - $wR) / 2, ($pageH - $hR) / 2, $wR, $hR);

        // Divider line between the two halves
        $pdf->SetLineWidth(0.15);
        $pdf->SetDrawColor(190, 190, 190);
        $pdf->Line($halfW, 4, $halfW, $pageH - 4);

        $relPath = self::savePdf($pdf, $source, $title, $startDate, $paxName, $idx);
        $cleanup();
        return $relPath;
    }

    // ── Shared private helpers ────────────────────────────────────────────────

    private static function savePdf(
        \setasign\Fpdi\Tcpdf\Fpdi $pdf,
        string $source,
        string $title,
        string $startDate,
        string $paxName,
        int    $idx
    ): string|false {
        $safe = fn(string $s, int $max): string =>
            substr(trim(preg_replace('/\s+/', '_', preg_replace('/[^A-Za-z0-9\-_ ]/', '', $s))), 0, $max);

        $folder = match ($source) {
            'public_offered' => 'OTRS-Free-Trainings',
            'agency'         => 'Agency-Certificates',
            'internal'       => 'Internal-Trainings',
            'appearance'     => 'Appearance-Certificates',
            default          => 'CCS-Certificates',
        };

        $year    = date('Y', strtotime($startDate));
        $dateStr = date('Y-m-d', strtotime($startDate));
        $subDir  = "{$folder}/{$year}/{$safe($title, 60)}/{$dateStr}/{$safe($paxName, 50)}/";
        $absDir  = self::certDir() . $subDir;
        if (!is_dir($absDir)) @mkdir($absDir, 0755, true);

        $filename = "cert_{$year}_" . time() . "_{$idx}.pdf";
        $abspath  = $absDir . $filename;
        $relpath  = $subDir . $filename;

        $pdf->Output($abspath, 'F');
        return file_exists($abspath) ? $relpath : false;
    }

    /** Cached font names returned by TCPDF_FONTS::addTTFfont() after first conversion. */
    private static array $registeredFonts = [];

    /**
     * Convert + register the three custom TTF fonts into TCPDF's font directory.
     * TCPDF_FONTS::addTTFfont() writes .php + .z files on first call and is
     * fast on subsequent calls (reads existing files). Results are cached in
     * self::$registeredFonts so conversion only happens once per process.
     */
    private static function ensureFontsCopied(): void
    {
        if (!empty(self::$registeredFonts)) return;
        $dir = self::fontsDir();
        foreach ([
            'calibri'       => 'Calibri.ttf',
            'calibribold'   => 'Calibri-Bold.ttf',
            'calibriitalic' => 'Calibri-Italic.ttf',
            'roboto'        => 'Roboto.ttf',
            'robotobold'    => 'Roboto-Bold.ttf',
            'robotoitalic'  => 'Roboto-Italic.ttf',
            'mirante'       => 'Mirante-Bold.ttf',
        ] as $key => $file) {
            $path = $dir . $file;
            $name = file_exists($path)
                ? TCPDF_FONTS::addTTFfont($path, 'TrueTypeUnicode', '', 32)
                : false;
            self::$registeredFonts[$key] = $name ?: 'helvetica';
        }
    }

    private static function writeLog(
        PDO     $db,
        int     $certId,
        string  $action,
        ?string $oldFile,
        ?string $newFile,
        string  $actor,
        ?int    $actorId,
        string  $ip
    ): void {
        try {
            $db->prepare("
                INSERT INTO otrs_ccs_issuance_log
                    (certificate_id, action, old_file, new_file, actor, actor_id, ip_address)
                VALUES (:cid, :act, :old, :new, :actor, :aid, :ip)
            ")->execute([
                ':cid'   => $certId,
                ':act'   => $action,
                ':old'   => $oldFile,
                ':new'   => $newFile,
                ':actor' => $actor,
                ':aid'   => $actorId,
                ':ip'    => $ip,
            ]);
        } catch (Throwable) {
            // audit log failure must never abort the main request
        }
    }
}
