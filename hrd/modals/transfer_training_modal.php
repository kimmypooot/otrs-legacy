<div class="modal fade" id="transferTrainingModal" tabindex="-1" role="dialog" aria-labelledby="transferTrainingModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-title" id="transferTrainingModalLabel">Transfer Participant's Training</span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="transferForm">
                    <input type="hidden" id="participantIds" name="participantIds">
                    <div class="mb-3">
                        <p>Select New Training to Transfer:</p>
                        <select class="form-select" id="newTraining" name="newTraining">
                            <?php
                            try {
                                $db = Database::getConnection();
                                $trainingModel = new Training($db);
                                $trainings = $trainingModel->getActiveTrainings($trainingId);

                                foreach ($trainings as $trainingOption) {
                                    $dateLabel = !empty($trainingOption['start_date']) && $trainingOption['start_date'] !== '0000-00-00'
                                        ? date('M j, Y', strtotime($trainingOption['start_date']))
                                        : '';
                                    echo '<option value="' . (int)$trainingOption['id'] . '">' .
                                         htmlspecialchars($trainingOption['name']) .
                                         ($dateLabel ? ' - ' . $dateLabel : '') .
                                         '</option>';
                                }
                            } catch (Exception $e) {
                                echo '<option value="">Error loading trainings</option>';
                            }
                            ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Close</button>
                <button type="button" class="btn btn-primary" id="confirmTransfer"><i class="bi bi-check-circle"></i> Transfer</button>
            </div>
        </div>
    </div>
</div>