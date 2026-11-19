<?php
/**
 * fetch_co_dropdown.php
 *
 * MIGRATION NOTES:
 *   The `collecting_officer` table is not part of the new otrs_* schema dump
 *   but is still referenced by otrs_enrollments.collecting_officer (varchar 64).
 *   This endpoint remains structurally unchanged; name resolution continues to
 *   happen in update_participant.php before persisting to otrs_enrollments.
 *
 * POST param: location (e.g. HRD, LFOI, LFOII …)
 */

include_once 'connect.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_POST['location'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request: missing location']);
    exit;
}

try {
    $conn = new PDO(
        'mysql:host=' . $servername . ';dbname=' . $dbname . ';charset=utf8mb4',
        $username,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE,        PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $location = trim($_POST['location']);

    $stmt = $conn->prepare("
        SELECT id, name
        FROM collecting_officer
        WHERE location = :location
        ORDER BY name ASC
    ");
    $stmt->bindValue(':location', $location);
    $stmt->execute();
    $officers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($officers, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('[fetch_co_dropdown] PDOException: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch collecting officers']);
}