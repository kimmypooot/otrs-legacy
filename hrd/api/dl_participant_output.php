<?php
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    exit('Access denied.');
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    exit('Invalid request.');
}

$regId = (int)$_GET['id'];

try {
    // Fetch day submissions for this registration
    $stmtSub = $conn->prepare("
        SELECT ts.file_path, ts.file_original_name, ts.day_number
        FROM otrs_training_submissions ts
        WHERE ts.registration_id = :reg_id
        ORDER BY ts.day_number ASC
    ");
    $stmtSub->bindParam(':reg_id', $regId, PDO::PARAM_INT);
    $stmtSub->execute();
    $submissions = $stmtSub->fetchAll(PDO::FETCH_ASSOC);

    // Fetch certificate file if available
    $stmtCert = $conn->prepare("
        SELECT c.certificate_file
        FROM otrs_certificates c
        JOIN otrs_registrations r ON c.participant_id = r.participant_id
            AND c.training_id = r.training_id
        WHERE r.id = :reg_id
        LIMIT 1
    ");
    $stmtCert->bindParam(':reg_id', $regId, PDO::PARAM_INT);
    $stmtCert->execute();
    $certRow = $stmtCert->fetch(PDO::FETCH_ASSOC);

    $zip = new ZipArchive();
    $zipFilename = tempnam(sys_get_temp_dir(), 'reg_' . $regId . '_') . '.zip';

    if ($zip->open($zipFilename, ZipArchive::CREATE) !== true) {
        exit("Unable to create ZIP file.\n");
    }

    foreach ($submissions as $sub) {
        if (!empty($sub['file_path']) && file_exists($sub['file_path'])) {
            $entryName = 'day' . $sub['day_number'] . '_' . basename($sub['file_path']);
            $zip->addFile($sub['file_path'], $entryName);
        }
    }

    if ($certRow && !empty($certRow['certificate_file'])) {
        $certBasePath = __DIR__ . '/../../../assets/uploads/certificates/';
        $certFullPath = $certBasePath . ltrim(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $certRow['certificate_file']),
            DIRECTORY_SEPARATOR
        );
        if (file_exists($certFullPath)) {
            $zip->addFile($certFullPath, 'certificate_' . basename($certFullPath));
        }
    }

    $zip->close();

    $downloadName = 'registration_' . $regId . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($zipFilename));
    readfile($zipFilename);
    unlink($zipFilename);
    exit;

} catch (PDOException $e) {
    echo 'Connection failed: ' . $e->getMessage();
}
?>
