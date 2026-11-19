<div id="updateModal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="updateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-title" id="updateModalLabel">Update Participant - <b><span id="fullName"></span></b></span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="updateForm" style="font-size: 14px">
                    <input type="hidden" id="participantId" name="participantId" value="">
                    <div class="row">
                        <div class="col-md-4">
                            <label for="fname" class="form-label"><b>First Name:</b></label>
                            <input type="text" class="form-control" id="fname" name="fname">
                        </div>
                        <div class="col-md-2">
                            <label for="mname" class="form-label"><b>MI:</b></label>
                            <input type="text" class="form-control" id="mname" name="mname" maxlength="2">
                        </div>
                        <div class="col-md-4">
                            <label for="lname" class="form-label"><b>Last Name:</b></label>
                            <input type="text" class="form-control" id="lname" name="lname">
                        </div>
                        <div class="col-md-2">
                            <label for="age" class="form-label"><b>Age:</b></label>
                            <input type="text" class="form-control" id="age" name="age" maxlength="2">
                        </div>            
                    </div><br>
                    <div class="row">
                        <div class="col-md-6">
                            <label for="email" class="form-label"><b>Email Address:</b></label>
                            <input type="text" class="form-control" id="email" name="email">
                        </div>
                        <div class="col-md-3">
                            <label for="gender" class="form-label"><b>Gender:</b></label>
                            <select class="form-select" id="gender" name="gender">
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="civilstatus" class="form-label"><b>Civil Status:</b></label>
                            <select class="form-select" id="civilstatus" name="civilstatus">
                                <option value="Single">Single</option>
                                <option value="Married">Married</option>
                                <option value="Widowed/Widower">Widowed/Widower</option>
                                <option value="Separated">Separated</option>
                                <option value="Others">Others</option>
                            </select>
                        </div>
                    </div><br>
                    <div class="row">
                        <div class="col-md-5">
                            <label for="contactno" class="form-label"><b>Mobile No.:</b></label>
                            <input type="text" class="form-control" id="contactno" name="contactno" maxlength="11">
                        </div>
                        <div class="col-md-7">
                            <label for="positiontitle" class="form-label"><b>Position Title:</b></label>
                            <input type="text" class="form-control" id="positiontitle" name="positiontitle">
                        </div>
                    </div><br>
                    <div class="row">
                        <div class="col-md-6">
                            <label for="agency_name" class="form-label"><b>Name of Agency/Office:</b></label>
                            <input type="text" class="form-control" id="agency_name" name="agency_name">
                        </div>
                        <div class="col-md-6">
                            <label for="agency_address" class="form-label"><b>Agency Address:</b></label>
                            <input type="text" class="form-control" id="agency_address" name="agency_address">
                        </div>
                    </div><br>
                    <div class="row">
                        <div class="col-md-2">
                            <label for="location" class="form-label"><b>FO:</b></label>
                            <select class="form-select" id="location" name="location">
                                <option value="HRD">HRD</option>  
                                <option value="LFOI">LFOI</option>
                                <option value="LFOII">LFOII</option>
                                <option value="BFO">BFO</option>
                                <option value="WLSO">WLSO</option>
                                <option value="SLFO">SLFO</option>
                                <option value="SFO">SFO</option>
                                <option value="ESFO">ESFO</option>
                                <option value="NSFO">NSFO</option>
                                <option value="RO6">Region VI</option>
                                <option value="RO7">Region VII</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="status" class="form-label"><b>Status:</b></label>
                            <select class="form-select" id="status" name="status">
                                <option value="pending">Pending</option>
                                <option value="paid">Paid</option>
                                <option value="cancelled">Cancelled</option>
                                <option value="promissory note">Promissory Note</option>
                                <option value="not qualified">Not Qualified</option>
                            </select>
                        </div>
                        <div class="col-md-7">
                            <label for="collecting_officer" class="form-label"><b>Collecting Officer:</b></label>
                            <select class="form-select" id="collecting_officer" name="collecting_officer">
                            </select>
                        </div>
                    </div><br>
                    <div class="row">
                        <div class="col-md-2">
                            <label for="orno" class="form-label"><b>OR No.:</b></label>
                            <input type="text" class="form-control" id="orno" name="orno" maxlength="12" required>
                        </div>
                        <div class="col-md-3">
                            <label for="ordate" class="form-label"><b>Date of OR:</b></label>
                            <input type="date" class="form-control" id="ordate" name="ordate">
                        </div>
                        <div class="col-md-3">
                            <label for="status_training" class="form-label"><b>Training Status</b></label>
                            <select class="form-select" id="status_training" name="status_training">
                                <option value="completed">Completed</option>
                                <option value="not completed">Not Completed</option>
                                <option value="For Compliance">For Compliance</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="remarks" class="form-label"><b>Remarks:</b></label>
                            <textarea class="form-control" id="remarks" name="remarks" style="font-size: 14px;"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Cancel</button>
                <button type="button" class="btn btn-success" onclick="updateParticipant()"><i class="bi bi-save2"></i> Update</button>
            </div>
        </div>
    </div>
</div>