<?php
/**
 * cron/auto_complete_trainings.php
 *
 * Marks overdue trainings as Completed.
 * Run daily via Hostinger hPanel Cron Jobs:
 *   php /home/u390694310/domains/cscro8.com/public_html/imis/otrs/cron/auto_complete_trainings.php
 *
 * CLI-only: web requests are rejected for security.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../../includes/connect.php'; // provides $conn

try {
    $stmt = $conn->prepare(
        "UPDATE otrs_trainings
         SET training_status = 'completed'
         WHERE end_date IS NOT NULL
           AND end_date <> '0000-00-00'
           AND end_date < CURDATE()
           AND LOWER(training_status) NOT IN ('completed', 'cancelled', 'rescheduled')"
    );
    $stmt->execute();
    $affected = $stmt->rowCount();

    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] auto_complete_trainings: {$affected} training(s) marked Completed.\n";

    // Reset the web-side lock so the next page load can run a fresh check immediately
    $lock = sys_get_temp_dir() . '/otrs_auto_complete.lock';
    if (file_exists($lock)) {
        @unlink($lock);
    }
} catch (PDOException $e) {
    $ts = date('Y-m-d H:i:s');
    fwrite(STDERR, "[{$ts}] auto_complete_trainings ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
