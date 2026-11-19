<div class="modal fade" id="updateStatusModal" tabindex="-1" aria-labelledby="updateStatusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-title" id="updateStatusModalLabel">Update Training Status</span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Select the new status:</p>
                <select class="form-select" id="newStatusSelect">
                    <option value="completed">Completed</option>
                    <option value="not completed">Not Completed</option>
                    <option value="For Compliance">For Compliance</option>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Close</button>
                <button type="button" class="btn btn-primary" onclick="updateSelectedParticipants()"><i class="bi bi-check-circle"></i> Update</button>
            </div>
        </div>
    </div>
</div>