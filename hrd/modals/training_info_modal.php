<div class="modal fade" id="infoTrainingModal" tabindex="-1" aria-labelledby="infoTrainingModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-title" id="trainingModalLabel">Training Details for <b><?php echo htmlspecialchars($training['name']); ?></b></span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <table id="trainingDetailsTable" class="table table-bordered" style="font-size: 14px">
                    <tbody>
                        <tr>
                            <td><b>Name of Training:</b></td>
                            <td colspan="3"><?php echo htmlspecialchars($training['name']); ?></td>
                        </tr>
                        <tr>
                            <td><b>Date of Training:</b></td>
                            <td colspan="3"><?php echo htmlspecialchars($training['date']); ?></td>
                        </tr>
                        <tr>
                            <td><b>Place of Training:</b></td>
                            <td><?php echo htmlspecialchars($training['location']); ?></td>
                            <td><b>Mode of Training:</b></td>
                            <td><?php echo htmlspecialchars($training['mode']); ?></td>
                        </tr>
                        <tr>
                            <td><b>Number of Slots Remaining:</b></td>
                            <td colspan="3"><?php echo htmlspecialchars($training['slots']); ?></td>
                        </tr>
                        <tr>
                            <td><b>Registration Fee:</b></td>
                            <td colspan="3">&#8369; <?php echo htmlspecialchars($training['fee']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Close</button>
            </div>
        </div>
    </div>
</div>