<?php
// models/Participant.php
class Participant {
    private $conn;
    private $table = 'otrs_participants';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getById($id) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT * FROM otrs_participants WHERE id = :id LIMIT 1"
            );
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: false;
        } catch (PDOException $e) {
            error_log('Participant::getById Error: ' . $e->getMessage());
            return false;
        }
    }

    public function getByIdWithRegistration($id, $trainingId) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT p.*,
                        r.id              AS reg_id,
                        r.payment_status,
                        r.registration_status,
                        r.training_status,
                        r.or_no,
                        r.or_date,
                        r.collecting_officer,
                        r.remarks,
                        r.proof_file,
                        r.radio_charge,
                        r.radio_cert,
                        r.registered_at
                 FROM otrs_participants p
                 JOIN otrs_registrations r ON r.participant_id = p.id
                 WHERE p.id = :id
                   AND r.training_id = :training_id
                 LIMIT 1"
            );
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->bindParam(':training_id', $trainingId, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: false;
        } catch (PDOException $e) {
            error_log('Participant::getByIdWithRegistration Error: ' . $e->getMessage());
            return false;
        }
    }

    public function update($data) {
        try {
            $this->conn->beginTransaction();

            // Capture old payment status and participant profile before the update.
            $oldStmt = $this->conn->prepare(
                "SELECT payment_status FROM otrs_registrations
                 WHERE id = :reg_id AND participant_id = :pid LIMIT 1"
            );
            $oldStmt->execute([':reg_id' => $data['reg_id'], ':pid' => $data['id']]);
            $oldPaymentStatus = $oldStmt->fetchColumn() ?: null;

            $oldProfile = $this->getById($data['id']);

            $pStmt = $this->conn->prepare(
                "UPDATE otrs_participants
                 SET fname             = :fname,
                     mname             = :mname,
                     lname             = :lname,
                     suffix            = :suffix,
                     gender            = :gender,
                     pwd               = :pwd,
                     civil_status      = :civil_status,
                     position_title    = :position_title,
                     salary_grade      = :salary_grade,
                     employment_status = :employment_status,
                     position_level    = :position_level,
                     agency_name       = :agency_name,
                     agency_address    = :agency_address,
                     home_address      = :home_address,
                     food_restrictions = :food_restrictions,
                     email             = :email,
                     mobile_no         = :mobile_no,
                     location          = :location
                 WHERE id = :id"
            );

            $pStmt->bindParam(':id',                $data['id'],               PDO::PARAM_INT);
            $pStmt->bindParam(':fname',              $data['fname']);
            $pStmt->bindParam(':mname',              $data['mname']);
            $pStmt->bindParam(':lname',              $data['lname']);
            $suffixVal = $data['suffix'] ?? '';
            $pStmt->bindValue(':suffix', $suffixVal !== '' ? $suffixVal : null, $suffixVal !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $pStmt->bindParam(':gender',             $data['gender']);
            $pStmt->bindParam(':pwd',                $data['pwd']);
            $pStmt->bindParam(':civil_status',       $data['civil_status']);
            $pStmt->bindParam(':position_title',     $data['position_title']);
            $pStmt->bindParam(':salary_grade',       $data['salary_grade']);
            $pStmt->bindParam(':employment_status',  $data['employment_status']);
            $pStmt->bindParam(':position_level',     $data['position_level']);
            $pStmt->bindParam(':agency_name',        $data['agency_name']);
            $pStmt->bindParam(':agency_address',     $data['agency_address']);
            $pStmt->bindParam(':home_address',       $data['home_address']);
            $pStmt->bindParam(':food_restrictions',  $data['food_restrictions']);
            $pStmt->bindParam(':email',              $data['email']);
            $pStmt->bindParam(':mobile_no',          $data['mobile_no']);
            $pStmt->bindParam(':location',           $data['location']);
            $pStmt->execute();

            $rStmt = $this->conn->prepare(
                "UPDATE otrs_registrations
                 SET payment_status      = :payment_status,
                     registration_status = :registration_status,
                     training_status     = :training_status,
                     or_no               = :or_no,
                     or_date             = :or_date,
                     collecting_officer  = :collecting_officer,
                     remarks             = :remarks,
                     prime_hrm_discount  = :prime_hrm_discount
                 WHERE id = :reg_id
                   AND participant_id = :participant_id"
            );

            $rStmt->bindParam(':reg_id',              $data['reg_id'],              PDO::PARAM_INT);
            $rStmt->bindParam(':participant_id',       $data['id'],                  PDO::PARAM_INT);
            $rStmt->bindParam(':payment_status',       $data['payment_status']);
            $rStmt->bindParam(':registration_status',  $data['registration_status']);
            $rStmt->bindParam(':training_status',      $data['training_status']);
            $rStmt->bindParam(':or_no',                $data['or_no']);

            if (empty($data['or_date'])) {
                $rStmt->bindValue(':or_date', null, PDO::PARAM_NULL);
            } else {
                $rStmt->bindParam(':or_date', $data['or_date']);
            }

            $rStmt->bindParam(':collecting_officer', $data['collecting_officer']);
            $rStmt->bindParam(':remarks',            $data['remarks']);
            $rStmt->bindParam(':prime_hrm_discount', $data['prime_hrm_discount'], PDO::PARAM_INT);
            $rStmt->execute();

            $updatedBy = $data['updated_by'] ?? 'HRD';

            // Log payment status change only when it actually changed.
            if ($oldPaymentStatus !== null && $oldPaymentStatus !== $data['payment_status']) {
                $action = ($data['payment_status'] === 'cancelled')
                    ? 'Registration Cancelled'
                    : 'Payment Updated';
                $this->conn->prepare("
                    INSERT INTO otrs_payment_logs
                        (registration_id, action, old_status, new_status, updated_by)
                    VALUES
                        (:reg_id, :action, :old_status, :new_status, :updated_by)
                ")->execute([
                    ':reg_id'     => $data['reg_id'],
                    ':action'     => $action,
                    ':old_status' => $oldPaymentStatus,
                    ':new_status' => $data['payment_status'],
                    ':updated_by' => $updatedBy,
                ]);
            }

            // When HRD cancels a participant: record in otrs_cancellation_requests for audit.
            // HRD is admin — no approval required; insert/update as auto-approved.
            if ($oldPaymentStatus !== null
                && strtolower($oldPaymentStatus) !== 'cancelled'
                && $data['payment_status'] === 'cancelled'
            ) {
                $tStmt = $this->conn->prepare(
                    "SELECT training_id FROM otrs_registrations WHERE id = :rid LIMIT 1"
                );
                $tStmt->execute([':rid' => $data['reg_id']]);
                $trainingId = (int) $tStmt->fetchColumn();

                $remarks = !empty($data['remarks'])
                    ? $data['remarks']
                    : 'Cancelled by HRD administrator.';

                // If a pending FO request exists, approve it; otherwise create a new HRD-initiated record.
                $pendingStmt = $this->conn->prepare("
                    SELECT id FROM otrs_cancellation_requests
                    WHERE  enrollment_id = :eid AND status = 'pending'
                    LIMIT  1
                ");
                $pendingStmt->execute([':eid' => $data['reg_id']]);
                $pendingId = $pendingStmt->fetchColumn();

                if ($pendingId) {
                    $this->conn->prepare("
                        UPDATE otrs_cancellation_requests
                        SET    status         = 'approved',
                               reviewed_by    = :by,
                               reviewed_at    = NOW(),
                               review_remarks = 'Approved — cancelled directly by HRD administrator.'
                        WHERE  id = :id
                    ")->execute([':by' => $updatedBy, ':id' => $pendingId]);

                    $this->conn->prepare("
                        INSERT INTO otrs_cancellation_logs
                            (request_id, action, actor, actor_location, remarks)
                        VALUES (:rid, 'approved', :actor, 'hrd', 'Approved and processed by HRD administrator.')
                    ")->execute([':rid' => $pendingId, ':actor' => $updatedBy]);
                } else {
                    $this->conn->prepare("
                        INSERT INTO otrs_cancellation_requests
                            (enrollment_id, participant_id, training_id, remarks, status,
                             requested_by, requested_by_location, requested_at,
                             reviewed_by, reviewed_at, review_remarks)
                        VALUES
                            (:eid, :pid, :tid, :remarks, 'approved',
                             :by_req, 'hrd', NOW(),
                             :by_rev, NOW(), 'Auto-approved — cancelled directly by HRD administrator.')
                    ")->execute([
                        ':eid'     => $data['reg_id'],
                        ':pid'     => $data['id'],
                        ':tid'     => $trainingId,
                        ':remarks' => $remarks,
                        ':by_req'  => $updatedBy,
                        ':by_rev'  => $updatedBy,
                    ]);
                    $requestId = (int) $this->conn->lastInsertId();

                    $this->conn->prepare("
                        INSERT INTO otrs_cancellation_logs
                            (request_id, action, actor, actor_location, remarks)
                        VALUES (:rid, 'approved', :actor, 'hrd', :remarks)
                    ")->execute([
                        ':rid'     => $requestId,
                        ':actor'   => $updatedBy,
                        ':remarks' => 'Cancelled directly by HRD administrator.',
                    ]);
                }
            }

            // Log profile information changes.
            // 'upper' fields are stored via strtoupper() in update_participant.php,
            // so normalize old values the same way before comparing.
            if ($oldProfile) {
                $profileFields = [
                    'fname'             => ['label' => 'First Name',        'upper' => true],
                    'mname'             => ['label' => 'Middle Name',       'upper' => true],
                    'lname'             => ['label' => 'Last Name',         'upper' => true],
                    'suffix'            => ['label' => 'Suffix',            'upper' => true],
                    'gender'            => ['label' => 'Gender',            'upper' => false],
                    'civil_status'      => ['label' => 'Civil Status',      'upper' => true],
                    'position_title'    => ['label' => 'Position Title',    'upper' => true],
                    'salary_grade'      => ['label' => 'Salary Grade',      'upper' => false],
                    'employment_status' => ['label' => 'Employment Status', 'upper' => false],
                    'position_level'    => ['label' => 'Position Level',    'upper' => true],
                    'agency_name'       => ['label' => 'Agency',            'upper' => true],
                    'agency_address'    => ['label' => 'Agency Address',    'upper' => true],
                    'home_address'      => ['label' => 'Home Address',      'upper' => true],
                    'food_restrictions' => ['label' => 'Food Restrictions', 'upper' => true],
                    'email'             => ['label' => 'Email',             'upper' => false],
                    'mobile_no'         => ['label' => 'Mobile No.',        'upper' => false],
                    'location'          => ['label' => 'Office',            'upper' => false],
                ];
                $changed = [];
                foreach ($profileFields as $col => $field) {
                    $oldVal = trim((string)($oldProfile[$col] ?? ''));
                    if ($field['upper']) $oldVal = strtoupper($oldVal);
                    $newVal = trim((string)($data[$col] ?? ''));
                    if ($oldVal !== $newVal) {
                        $changed[] = $field['label'];
                    }
                }
                if (!empty($changed)) {
                    $this->conn->prepare("
                        INSERT INTO otrs_payment_logs
                            (registration_id, action, old_status, new_status, notes, updated_by)
                        VALUES
                            (:reg_id, 'Participant Info Updated', NULL, 'profile_updated', :notes, :updated_by)
                    ")->execute([
                        ':reg_id'     => $data['reg_id'],
                        ':notes'      => 'Updated: ' . implode(', ', $changed),
                        ':updated_by' => $updatedBy,
                    ]);
                }
            }

            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log('Participant::update Error: ' . $e->getMessage());
            throw new Exception('Database error: ' . $e->getMessage());
        }
    }

    public function delete($id) {
        try {
            $this->conn->beginTransaction();

            // Fetch registrations to know which slots to restore
            $regStmt = $this->conn->prepare(
                "SELECT training_id, registration_status
                 FROM otrs_registrations
                 WHERE participant_id = :id"
            );
            $regStmt->bindParam(':id', $id, PDO::PARAM_INT);
            $regStmt->execute();
            $registrations = $regStmt->fetchAll(PDO::FETCH_ASSOC);

            // Restore a slot for each active (non-cancelled) registration
            $slotStmt = $this->conn->prepare(
                "UPDATE otrs_trainings SET slots = slots + 1 WHERE id = :training_id"
            );
            foreach ($registrations as $reg) {
                if (strtolower($reg['registration_status']) !== 'cancelled') {
                    $slotStmt->execute([':training_id' => $reg['training_id']]);
                }
            }

            // Remove registration rows
            $delReg = $this->conn->prepare(
                "DELETE FROM otrs_registrations WHERE participant_id = :id"
            );
            $delReg->bindParam(':id', $id, PDO::PARAM_INT);
            $delReg->execute();

            // Remove participant
            $delPart = $this->conn->prepare(
                "DELETE FROM otrs_participants WHERE id = :id"
            );
            $delPart->bindParam(':id', $id, PDO::PARAM_INT);
            $delPart->execute();

            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log('Participant::delete Error: ' . $e->getMessage());
            return false;
        }
    }

    public function updateTrainingStatus($ids, $newStatus) {
        try {
            if (empty($ids)) {
                return false;
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->conn->prepare(
                "UPDATE otrs_registrations
                 SET training_status = ?
                 WHERE id IN ($placeholders)"
            );
            return $stmt->execute(array_merge([$newStatus], array_values($ids)));
        } catch (PDOException $e) {
            error_log('Participant::updateTrainingStatus Error: ' . $e->getMessage());
            return false;
        }
    }

    public function transferToTraining($regIds, $newTrainingId) {
        try {
            if (empty($regIds)) {
                return false;
            }

            $this->conn->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($regIds), '?'));

            // Count active (non-cancelled) registrations per source training
            $srcStmt = $this->conn->prepare(
                "SELECT training_id, COUNT(*) AS cnt
                 FROM otrs_registrations
                 WHERE id IN ($placeholders)
                   AND registration_status NOT IN ('cancelled')
                 GROUP BY training_id"
            );
            $srcStmt->execute(array_values($regIds));
            $sources = $srcStmt->fetchAll(PDO::FETCH_ASSOC);

            $transferCount = (int) array_sum(array_column($sources, 'cnt'));

            // Verify destination has enough slots
            $dStmt = $this->conn->prepare(
                "SELECT slots FROM otrs_trainings WHERE id = ? LIMIT 1"
            );
            $dStmt->execute([$newTrainingId]);
            $destSlots = (int) $dStmt->fetchColumn();

            if ($destSlots < $transferCount) {
                $this->conn->rollBack();
                throw new \RuntimeException('Not enough slots in the destination training.');
            }

            // Restore slots to source trainings
            $restoreStmt = $this->conn->prepare(
                "UPDATE otrs_trainings SET slots = slots + :cnt WHERE id = :id"
            );
            foreach ($sources as $src) {
                $restoreStmt->execute([':cnt' => (int) $src['cnt'], ':id' => (int) $src['training_id']]);
            }

            // Consume slots from destination
            $this->conn->prepare(
                "UPDATE otrs_trainings SET slots = slots - :cnt WHERE id = :id"
            )->execute([':cnt' => $transferCount, ':id' => $newTrainingId]);

            // Move registrations
            $this->conn->prepare(
                "UPDATE otrs_registrations SET training_id = ? WHERE id IN ($placeholders)"
            )->execute(array_merge([$newTrainingId], array_values($regIds)));

            $this->conn->commit();
            return true;
        } catch (\RuntimeException $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            throw $e;
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            error_log('Participant::transferToTraining Error: ' . $e->getMessage());
            return false;
        }
    }

    public function updateRescheduleDate($regIds, $newDate) {
        // No longer applicable in normalized schema; training dates live on otrs_trainings.
        return true;
    }
}
?>
