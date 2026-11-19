<div class="modal fade" id="rescheduleParticipantsModal" tabindex="-1" role="dialog" aria-labelledby="rescheduleParticipantsModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-title" id="rescheduleParticipantsModalLabel">Reschedule Participant's Training</span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="rescheduleParticipantForm">
                    <input type="hidden" id="participantIds" name="participantIds">
                    <div class="mb-3">
                        <p>Enter New Reschedule Date:</p>
                        <input type="text" class="form-control" name="reschedule_date" id="reschedule_date" placeholder="ex. 14-15 January 2025">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Close</button>
                <button type="button" class="btn btn-primary" id="updateReschedule"><i class="bi bi-calendar2-week"></i> Reschedule</button>
            </div>
        </div>
    </div>
</div>