<?php
/**
 * hrd/inc/audit_log.php
 * Thin helper for writing to otrs_audit_log.
 * Silently swallows DB errors so a log failure never breaks the main flow.
 */

function otrsAuditLog(
    PDO    $conn,
    string $action,
    string $entity,
    ?int   $entityId       = null,
    ?int   $trainingId     = null,
    ?string $participantName = null,
    ?string $details        = null,
    ?string $performedBy    = null,
    ?string $ip             = null
): void {
    try {
        $stmt = $conn->prepare("
            INSERT INTO otrs_audit_log
                (action, entity, entity_id, training_id, participant_name, details, performed_by, ip_address)
            VALUES
                (:action, :entity, :entity_id, :training_id, :participant_name, :details, :performed_by, :ip)
        ");
        $stmt->execute([
            ':action'           => mb_substr($action, 0, 60),
            ':entity'           => mb_substr($entity, 0, 50),
            ':entity_id'        => $entityId,
            ':training_id'      => $trainingId,
            ':participant_name' => $participantName ? mb_substr($participantName, 0, 255) : null,
            ':details'          => $details,
            ':performed_by'     => $performedBy ? mb_substr($performedBy, 0, 100) : null,
            ':ip'               => $ip ? mb_substr($ip, 0, 45) : null,
        ]);
    } catch (Throwable $e) {
        error_log('[otrsAuditLog] ' . $e->getMessage());
    }
}
