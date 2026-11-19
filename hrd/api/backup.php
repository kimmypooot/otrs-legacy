<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';

header('Content-Type: text/plain');
ini_set('display_errors', 0);

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Invalid request method.';
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo 'Invalid CSRF token.';
    exit;
}

require_once __DIR__ . '/../../../phpmailer/PHPMailer.php';
require_once __DIR__ . '/../../../phpmailer/SMTP.php';
require_once __DIR__ . '/../../../phpmailer/Exception.php';
require_once __DIR__ . '/../../inc/mail_config.php';

try {
    $tables = ['otrs_trainings', 'otrs_registrations', 'otrs_certificates', 'otrs_participants', 'users_cscro8'];

    $backupContent = '';
    foreach ($tables as $table) {
        $stmt = $conn->query("SELECT * FROM `$table`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $backupContent .= "DROP TABLE IF EXISTS `$table`;\n";
        $createRow = $conn->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $backupContent .= $createRow['Create Table'] . ";\n\n";

        foreach ($rows as $row) {
            $cols   = implode(', ', array_map(fn($c) => "`$c`", array_keys($row)));
            $vals   = implode(', ', array_map([$conn, 'quote'], $row));
            $backupContent .= "INSERT INTO `$table` ($cols) VALUES ($vals);\n";
        }
        $backupContent .= "\n";
    }

    // Store backups OUTSIDE the webroot (VAPT 2026-07-04). dirname(__DIR__,5) =
    // the domain root (…/cscro8.com), above public_html — not web-servable.
    $backupDir      = dirname(__DIR__, 5) . '/private_db_backups/otrs-hrd/';
    $backupFilename = $backupDir . 'OTRS-backup_db-' . date('m-d-Y') . '.sql';

    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    file_put_contents($backupFilename, $backupContent);

    $mail = new PHPMailer\PHPMailer\PHPMailer();
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = 'tls';
    $mail->Port       = SMTP_PORT;
    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $mail->addAddress('kimbenedick.banoyo@gmail.com', 'kmbanoyo');
    $mail->addAttachment($backupFilename);
    $mail->Subject = 'OTRS Database Backup as of ' . date('m-d-Y');
    $mail->Body    = 'Attached is the backup file of the Online Training Registration System database.';

    if ($mail->send()) {
        echo 'success';
    } else {
        echo 'error: mail not sent';
    }
} catch (PDOException $e) {
    error_log('[backup.php] DB error: ' . $e->getMessage());
    echo 'Database backup error.';
} catch (Exception $e) {
    error_log('[backup.php] Error: ' . $e->getMessage());
    echo 'Backup error: ' . $e->getMessage();
}
