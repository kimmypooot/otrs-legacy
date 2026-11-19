<div class="modal fade" id="zoomLinkModal" tabindex="-1" aria-labelledby="zoomLinkModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-title" id="zoomLinkModalLabel">Enter Zoom Link to <span style="color: red"><b>"paid" and "promissory note"</b></span> Participants</span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label for="zoomLinkInput">Enter Zoom Link:</label>
                <input id="zoomLinkInput" class="form-control" type="text" placeholder="Paste the Zoom link here..." autofocus>
                <label for="" class="mt-3">Upload Training Advisory PDF file:</label>
                <input type="file" id="zoomPDF" class="form-control" accept="application/pdf">
                <label for="" class="mt-3">Upload Zoom Background:</label>
                <input type="file" id="zoomBg" class="form-control" accept="image/*">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmSendEmailZoom()"><i class="ri ri-mail-send-line"></i> Send</button>
            </div>
        </div>
    </div>
</div>