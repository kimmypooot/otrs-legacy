<?php
// models/Training.php
class Training {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getById($id) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT ot.*,
                        ot.type AS training_type,
                        ot.type AS training_type_category
                 FROM otrs_trainings ot
                 WHERE ot.id = :id
                 LIMIT 1"
            );
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: false;
        } catch (PDOException $e) {
            error_log('Training::getById Error: ' . $e->getMessage());
            return false;
        }
    }

    public function getActiveTrainings($excludeId = null) {
        try {
            $query = "SELECT ot.id, ot.name, ot.start_date, ot.end_date
                      FROM otrs_trainings ot
                      WHERE ot.training_status NOT IN ('completed', 'cancelled')";

            if ($excludeId !== null) {
                $query .= " AND ot.id != :excludeId";
            }

            $query .= " ORDER BY ot.name";

            $stmt = $this->conn->prepare($query);

            if ($excludeId !== null) {
                $stmt->bindParam(':excludeId', $excludeId, PDO::PARAM_INT);
            }

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Training::getActiveTrainings Error: ' . $e->getMessage());
            return [];
        }
    }
}
?>
