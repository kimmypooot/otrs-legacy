<?php
// imis/otrs/hrd/api/agency_parse_csv.php
// Parses an uploaded CSV and returns a list of participant names as JSON.
// Handles comma, semicolon, and tab delimiters automatically.
// Handles UTF-8 BOM, CRLF/LF line endings, quoted fields, and extra columns.

ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../../../includes/bootstrap.php';

// Ensure stray output never corrupts the JSON response.
register_shutdown_function(function () {
    $buf = ob_get_clean();
    if (headers_sent()) return;
    header('Content-Type: application/json; charset=utf-8');
    if (empty($buf) || (isset($buf[0]) && $buf[0] === '{')) {
        echo $buf;
    } else {
        $fatal = error_get_last();
        $msg   = ($fatal && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR], true))
               ? $fatal['message']
               : 'A server error occurred while parsing the CSV.';
        echo json_encode(['success' => false, 'error' => $msg]);
    }
});

try {
    if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        exit(json_encode(['success' => false, 'error' => 'Access denied.']));
    }
    // Sample CSV download (GET ?sample=1) — HRD session already verified above.
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['sample'])) {
        $type = preg_replace('/[^a-z0-9_]/', '', strtolower($_GET['type'] ?? 'agency'));
        $filename = 'sample_' . ($type ?: 'agency') . '_participants.csv';
        ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel compatibility
        echo "Participant Name,Position,Agency\r\n"
           . "Juan Dela Cruz,Administrative Officer II,Department of Education\r\n"
           . "Maria Santos,HR Assistant,Bureau of Internal Revenue\r\n"
           . "Pedro Reyes,Clerk III,Department of Health\r\n";
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(405);
        exit(json_encode(['success' => false, 'error' => 'Method not allowed.']));
    }

    $csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
    if (!validateCsrfToken($csrfToken)) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        exit(json_encode(['success' => false, 'error' => 'Invalid CSRF token.']));
    }

    // ── File upload check ─────────────────────────────────────────────────────
    $uploadError = $_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    if (empty($_FILES['csv_file']) || $uploadError !== UPLOAD_ERR_OK) {
        $msgs = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        ];
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['success' => false,
            'error' => $msgs[$uploadError] ?? 'Upload failed (code ' . $uploadError . ').']));
    }

    $tmpPath = $_FILES['csv_file']['tmp_name'];

    // ── MIME guard (permissive — CSV can report many MIME types) ─────────────
    if (function_exists('mime_content_type')) {
        $mime    = mime_content_type($tmpPath);
        $blocked = ['image/', 'audio/', 'video/', 'application/pdf',
                    'application/zip', 'application/x-zip'];
        foreach ($blocked as $prefix) {
            if (stripos($mime, $prefix) === 0) {
                ob_end_clean();
                header('Content-Type: application/json; charset=utf-8');
                exit(json_encode(['success' => false,
                    'error' => 'Invalid file type (' . $mime . '). Please upload a CSV file.']));
            }
        }
    }

    // ── Read raw content, strip BOM, normalise line endings ───────────────────
    $raw = file_get_contents($tmpPath);
    if ($raw === false) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['success' => false, 'error' => 'Could not read uploaded file.']));
    }

    // Strip UTF-8 BOM (\xEF\xBB\xBF) if present
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    // Strip UTF-16 LE BOM (\xFF\xFE) — Excel sometimes saves this
    if (str_starts_with($raw, "\xFF\xFE")) {
        $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
    }

    // Normalise line endings to \n
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $raw = trim($raw);

    if ($raw === '') {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['success' => false, 'error' => 'The uploaded file is empty.']));
    }

    // ── Auto-detect delimiter ─────────────────────────────────────────────────
    // Count occurrences of each candidate on the first non-empty line.
    $firstLine = strtok($raw, "\n");
    strtok('', ''); // reset
    $candidates  = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];
    foreach ($candidates as $d => $_) {
        // Count unquoted occurrences (rough but sufficient for header line)
        $candidates[$d] = substr_count($firstLine, $d);
    }
    arsort($candidates);
    $delimiter = array_key_first($candidates);
    // Fall back to comma if nothing detected
    if ($candidates[$delimiter] === 0) {
        $delimiter = ',';
    }

    // ── Parse via fgetcsv using detected delimiter ────────────────────────────
    // Write normalised content to a temp stream so fgetcsv handles quoting.
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $raw);
    rewind($stream);

    $participants   = [];
    $errors         = [];
    $rowNum         = 0;
    $nameColIndex   = null;
    $positionColIndex = null;
    $agencyColIndex   = null;

    while (($row = fgetcsv($stream, 0, $delimiter)) !== false) {
        $rowNum++;

        // Skip completely blank rows
        if (count($row) === 1 && trim($row[0]) === '') {
            continue;
        }

        if ($rowNum === 1) {
            // ── Header detection ──────────────────────────────────────────────
            // 1. Exact / well-known header names
            $knownHeaders = [
                'name', 'participant name', 'participant_name',
                'full name', 'fullname', 'full_name',
                'participant', 'participants',
            ];
            foreach ($row as $i => $cell) {
                $h = strtolower(trim($cell));
                if (in_array($h, $knownHeaders, true)) {
                    $nameColIndex = $i;
                    break;
                }
            }
            // 2. Fuzzy: any header containing the word "name"
            if ($nameColIndex === null) {
                foreach ($row as $i => $cell) {
                    if (stripos($cell, 'name') !== false) {
                        $nameColIndex = $i;
                        break;
                    }
                }
            }
            // 3. No header found — treat first row as data
            if ($nameColIndex === null) {
                $nameColIndex = 0;
                $firstVal     = trim($row[0] ?? '');
                // Only add if it doesn't look like a typical header keyword
                if ($firstVal !== '' && !preg_match('/^(id|no\.?|sl\.?\s*no\.?|num\.?|number|#|sr\.?|sn\.?)$/i', $firstVal)) {
                    $participants[] = ['name' => $firstVal, 'position' => '', 'agency' => ''];
                }
            }

            // ── Optional Position / Agency column detection ────────────────────
            foreach ($row as $i => $cell) {
                if ($i === $nameColIndex) continue;
                $h = strtolower(trim($cell));
                if ($positionColIndex === null && (in_array($h, ['position', 'designation', 'rank'], true) || stripos($h, 'position') !== false || stripos($h, 'designation') !== false)) {
                    $positionColIndex = $i;
                    continue;
                }
                if ($agencyColIndex === null && (in_array($h, ['agency', 'office', 'department'], true) || stripos($h, 'agency') !== false || stripos($h, 'office') !== false)) {
                    $agencyColIndex = $i;
                }
            }
            continue; // always skip / already processed row 1
        }

        // ── Data rows ─────────────────────────────────────────────────────────
        $name = trim($row[$nameColIndex] ?? '');
        if ($name === '') {
            $errors[] = "Row {$rowNum}: empty name, skipped.";
            continue;
        }
        if (mb_strlen($name) > 255) {
            $errors[] = "Row {$rowNum}: name too long (max 255 chars), skipped.";
            continue;
        }
        $position = $positionColIndex !== null ? trim($row[$positionColIndex] ?? '') : '';
        $agency   = $agencyColIndex   !== null ? trim($row[$agencyColIndex] ?? '')   : '';
        $participants[] = [
            'name'     => $name,
            'position' => mb_substr($position, 0, 255),
            'agency'   => mb_substr($agency, 0, 255),
        ];
    }
    fclose($stream);

    if (empty($participants)) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode([
            'success' => false,
            'error'   => 'No valid participant names found. '
                       . 'Make sure the file has a "Participant Name" column header '
                       . 'and at least one name below it.',
            'errors'  => $errors,
        ]));
    }

    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'      => true,
        'participants' => $participants,
        'count'        => count($participants),
        'delimiter'    => $delimiter === "\t" ? 'tab' : $delimiter,
        'errors'       => $errors,
    ]);

} catch (Throwable $e) {
    error_log('[agency_parse_csv] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
