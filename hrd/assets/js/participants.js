// participants.js - Enhanced Modular JavaScript for Participant Management

const ParticipantManager = (function() {
  'use strict';

  // Configuration
  const config = {
    endpoints: {
      fetch: 'api/fetch_participant.php',
      update: 'api/update_participant.php',
      delete: 'api/delete_participant.php',
      updateStatus: 'api/update_participants.php',
      transfer: 'api/transfer_participant.php',
      updateDate: 'api/update_training_date.php',
      sendAck: 'send_email-ack.php',
      sendZoom: 'send_email-link.php',
      sendAdvisory: 'send_email-adv.php'
    }
  };

  // Store DataTable instance
  let listParticipants = null;

  // Utility Functions
  const utils = {
    showLoader: (message = 'Loading...') => {
      Swal.fire({
        title: message,
        allowOutsideClick: false,
        allowEscapeKey: false,
        allowEnterKey: false,
        didOpen: () => Swal.showLoading()
      });
    },

    hideLoader: () => Swal.close(),

    showToast: (icon, title, timer = 3000) => {
      const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: timer,
        timerProgressBar: true,
        didOpen: (toast) => {
          toast.addEventListener('mouseenter', Swal.stopTimer);
          toast.addEventListener('mouseleave', Swal.resumeTimer);
        }
      });

      return Toast.fire({
        icon: icon,
        title: title
      });
    },

    showSuccess: (title, text) => {
      return Swal.fire({
        icon: 'success',
        title: title,
        text: text,
        confirmButtonText: 'OK'
      });
    },

    showError: (title, text) => {
      return Swal.fire({
        icon: 'error',
        title: title,
        text: text
      });
    },

    showConfirm: (title, text, confirmText = 'Yes', cancelText = 'Cancel') => {
      return Swal.fire({
        title: title,
        html: text,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33'
      });
    },

    getSelectedParticipants: () => {
      const selected = [];
      document.querySelectorAll('.participant-checkbox:checked').forEach(cb => {
        selected.push({
          id: cb.dataset.participantId,
          status: cb.dataset.status,
          name: cb.dataset.name
        });
      });
      return selected;
    },

    ajaxRequest: (url, data, method = 'POST') => {
      const body = data instanceof URLSearchParams || data instanceof FormData
        ? data
        : new URLSearchParams(data);
      const token = window.csrfToken ?? '';
      const init = { method };
      if (body instanceof FormData) {
        init.headers = { 'X-CSRF-Token': token };
        init.body = body;
      } else {
        init.headers = { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': token };
        init.body = body;
      }
      return fetch(url, init).then(res => {
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
      });
    },

    reloadPage: () => {
      window.location.reload();
    }
  };

  // Participant Operations
  const operations = {
    fetchParticipant: async (participantId) => {
      utils.showLoader('Loading participant details...');
      try {
        const response = await utils.ajaxRequest(
          config.endpoints.fetch,
          { participantId: participantId }
        );

        utils.hideLoader();
        
        if (response.status === 'success') {
          return response.participant;
        } else {
          throw new Error(response.message || 'Failed to fetch participant');
        }
      } catch (error) {
        utils.hideLoader();
        utils.showToast('error', error.message);
        throw error;
      }
    },

    updateParticipant: async (formData) => {
      // Validate required fields
      if (formData.status === 'paid' && !formData.orno.trim()) {
        utils.showToast('error', 'Please enter OR No. for paid participants', 4000);
        return false;
      }

      // Show confirmation for cancelled status
      if (formData.status === 'cancelled') {
        const result = await utils.showConfirm(
          'Are you sure?',
          'This action will cancel the participant\'s registration.',
          'Yes, cancel registration',
          'No, keep registration'
        );

        if (!result.isConfirmed) {
          return false;
        }
      }

      utils.showLoader('Updating participant...');
      try {
        const response = await utils.ajaxRequest(
          config.endpoints.update,
          formData
        );

        utils.hideLoader();
        
        if (response.status === 'success') {
          // Close modal first
          $('#updateModal').modal('hide');
          
          // Show success message
          await utils.showSuccess('Success', response.message || 'Participant updated successfully');
          
          // Reload after user acknowledges
          utils.reloadPage();
          return true;
        } else {
          throw new Error(response.message || 'Failed to update participant');
        }
      } catch (error) {
        utils.hideLoader();
        utils.showToast('error', error.message);
        return false;
      }
    },

    deleteParticipant: async (participantId) => {
      const result = await utils.showConfirm(
        'Confirmation',
        'Are you sure you want to delete this participant?',
        'Delete'
      );

      if (!result.isConfirmed) return;

      utils.showLoader('Deleting participant...');
      try {
        const response = await utils.ajaxRequest(
          config.endpoints.delete,
          { id: participantId }
        );

        utils.hideLoader();
        
        if (response.success) {
          utils.showToast('success', 'Participant deleted successfully');
          setTimeout(() => utils.reloadPage(), 1500);
        } else {
          throw new Error(response.error || 'Failed to delete participant');
        }
      } catch (error) {
        utils.hideLoader();
        utils.showToast('error', error.message);
      }
    },

    updateSelectedStatus: async (newStatus) => {
      const selected = utils.getSelectedParticipants();

      if (selected.length === 0) {
        utils.showToast('error', 'Please select at least one participant', 3500);
        return;
      }

      const result = await utils.showConfirm(
        'Update Participants',
        `Are you sure you want to update ${selected.length} participant(s)?`,
        'Update'
      );

      if (!result.isConfirmed) return;

      utils.showLoader('Updating participants...');
      try {
        const participantIds = selected.map(p => p.id);
        const response = await utils.ajaxRequest(
          config.endpoints.updateStatus,
          {
            participantIds: JSON.stringify(participantIds),
            newStatus: newStatus
          }
        );

        utils.hideLoader();
        
        if (response.status === 'success') {
          utils.showToast('success', `${selected.length} participant(s) updated successfully`);
          setTimeout(() => utils.reloadPage(), 1500);
        } else {
          throw new Error(response.message || 'Failed to update participants');
        }
      } catch (error) {
        utils.hideLoader();
        utils.showToast('error', error.message);
      }
    },

    transferParticipants: async (newTrainingId) => {
      const selected = utils.getSelectedParticipants();

      if (selected.length === 0) {
        utils.showToast('error', 'Please select participants to transfer', 3500);
        return;
      }

      const participantIds = selected.map(p => p.id);

      utils.showLoader('Transferring participants...');
      try {
        const response = await utils.ajaxRequest(
          config.endpoints.transfer,
          {
            participantIds: participantIds.join(','),
            newTrainingId: newTrainingId
          }
        );

        utils.hideLoader();
        
        if (response.success) {
          utils.showToast('success', `${selected.length} participant(s) transferred successfully`);
          setTimeout(() => utils.reloadPage(), 1500);
        } else {
          throw new Error(response.message || 'Failed to transfer participants');
        }
      } catch (error) {
        utils.hideLoader();
        utils.showToast('error', error.message);
      }
    },

    rescheduleParticipants: async (newDate) => {
      const selected = utils.getSelectedParticipants();

      if (selected.length === 0) {
        utils.showToast('error', 'Please select at least one participant', 3500);
        return;
      }

      if (!newDate) {
        utils.showToast('error', 'Please select a new training date', 3500);
        return;
      }

      const result = await utils.showConfirm(
        'Are you sure?',
        `Do you want to reschedule ${selected.length} participant(s)?`,
        'Yes, reschedule it!'
      );

      if (!result.isConfirmed) return;

      utils.showLoader('Rescheduling participants...');
      try {
        const participantIds = selected.map(p => p.id);
        const response = await utils.ajaxRequest(
          config.endpoints.updateDate,
          {
            participantIds: participantIds,
            reschedule_date: newDate
          }
        );

        utils.hideLoader();
        
        if (response.status === 'success') {
          utils.showToast('success', response.message || 'Participants rescheduled successfully');
          setTimeout(() => utils.reloadPage(), 1500);
        } else {
          throw new Error(response.message || 'Failed to reschedule');
        }
      } catch (error) {
        utils.hideLoader();
        utils.showToast('error', error.message);
      }
    }
  };

  // Email Operations
  const emailOperations = {
    sendAcknowledgment: async (trainingId) => {
      const selected = utils.getSelectedParticipants();

      if (selected.length === 0) {
        utils.showToast('error', 'Please select at least one participant', 3500);
        return;
      }

      const result = await utils.showConfirm(
        'Send Acknowledgment Letter',
        `Send acknowledgment letter to <b>${selected.length}</b> participant(s)?`,
        'Send'
      );

      if (!result.isConfirmed) return;

      try {
        utils.showLoader('Sending acknowledgment emails...');
        const participantIds = selected.map(p => p.id);
        
        const response = await utils.ajaxRequest(
          config.endpoints.sendAck + '?id=' + trainingId,
          { participantIds: JSON.stringify(participantIds) }
        );

        utils.hideLoader();
        if (response.failed > 0) {
          utils.showToast('warning', `Sent: ${response.sent}, Failed: ${response.failed}`, 6000);
        } else {
          utils.showToast('success', `Acknowledgment sent to ${response.sent} participant(s)`, 4000);
        }

        setTimeout(() => {
          $('#checkAll').prop('checked', false);
          $('.participant-checkbox').prop('checked', false);
        }, 1000);
      } catch (error) {
        utils.hideLoader();
        utils.showToast('error', error.message);
      }
    },

    sendZoomLink: async (trainingId, zoomLink, pdfFile, bgFile) => {
      const selected = utils.getSelectedParticipants();

      if (selected.length === 0) {
        utils.showToast('error', 'Please select at least one paid participant', 3500);
        return;
      }

      // Check for unpaid participants
      const unpaidParticipants = selected.filter(p => 
        p.status !== 'paid' && p.status !== 'promissory note'
      );

      if (unpaidParticipants.length > 0) {
        const unpaidNames = unpaidParticipants.map((p, i) => 
          `<b>${i + 1}: </b>${p.name}`
        ).join('<br>');

        utils.showError(
          'Unpaid Participants',
          `Some selected participants are not <span style="color: red"><b>"paid"</b></span> or <span style="color: red"><b>"promissory note"</b></span>.<br><br>` +
          `Unpaid participants: <b>${unpaidParticipants.length}</b><br><br>${unpaidNames}`
        );
        return;
      }

      const result = await utils.showConfirm(
        'Send Zoom Link',
        `Send Zoom link to <b>${selected.length}</b> participant(s)?`,
        'Send'
      );

      if (!result.isConfirmed) return;

      try {
        utils.showLoader('Sending Zoom link emails...');
        const participantIds = selected.map(p => p.id);
        
        const formData = new FormData();
        formData.append('participantIds', JSON.stringify(participantIds));
        formData.append('zoomLinkInput', zoomLink);
        if (pdfFile) formData.append('zoomPDF', pdfFile);
        if (bgFile) formData.append('zoomBg', bgFile);

        const res = await fetch(config.endpoints.sendZoom + '?id=' + trainingId, {
          method: 'POST',
          headers: { 'X-CSRF-Token': window.csrfToken ?? '' },
          body: formData
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const response = await res.json();

        utils.hideLoader();
        if (response.failed > 0) {
          utils.showToast('warning', `Sent: ${response.sent}, Failed: ${response.failed}`, 6000);
        } else {
          utils.showToast('success', `Zoom link sent to ${response.sent} participant(s)`, 4000);
        }

        setTimeout(() => {
          $('#checkAll').prop('checked', false);
          $('.participant-checkbox').prop('checked', false);
        }, 1000);
      } catch (error) {
        utils.hideLoader();
        utils.showToast('error', 'An error occurred while sending the Zoom link');
      }
    },

    sendAdvisory: async (trainingId) => {
      const selected = utils.getSelectedParticipants();
      const pendingParticipants = selected.filter(p => p.status === 'pending');

      if (pendingParticipants.length === 0) {
        utils.showToast('error', 'Please select at least one pending participant', 3500);
        return;
      }

      const result = await utils.showConfirm(
        'Send Training Advisory',
        `Send training advisory to <b>${pendingParticipants.length}</b> participant(s)?`,
        'Send'
      );

      if (!result.isConfirmed) return;

      try {
        utils.showLoader('Sending training advisory emails...');
        
        const pendingIds = pendingParticipants.map(p => p.id);
        const response = await utils.ajaxRequest(
          config.endpoints.sendAdvisory + '?id=' + trainingId,
          { participantIds: JSON.stringify(pendingIds) }
        );

        utils.hideLoader();
        if (response.failed > 0) {
          utils.showToast('warning', `Sent: ${response.sent}, Failed: ${response.failed}`, 6000);
        } else {
          utils.showToast('success', `Advisory sent to ${response.sent} participant(s)`, 4000);
        }

        setTimeout(() => {
          $('#checkAll').prop('checked', false);
          $('.participant-checkbox').prop('checked', false);
        }, 1000);
      } catch (error) {
        utils.hideLoader();
        utils.showToast('error', error.message);
      }
    }
  };

  // Modal Management
  const modals = {
    showUpdateModal: async (participantId) => {
      try {
        const participant = await operations.fetchParticipant(participantId);

        // Populate form fields
        $('#participantId').val(participant.id);
        $('#fname').val(participant.fname);
        $('#mname').val(participant.mname);
        $('#lname').val(participant.lname);
        $('#gender').val(participant.gender);
        $('#age').val(participant.age);
        $('#civilstatus').val(participant.civilstatus);
        $('#positiontitle').val(participant.positiontitle);
        $('#agency_name').val(participant.agency_name);
        $('#agency_address').val(participant.agency_address);
        $('#email').val(participant.email);
        $('#contactno').val(participant.mobileno);
        $('#location').val(participant.location);
        $('#status').val(participant.status);
        $('#status_training').val(participant.status_training);
        $('#orno').val(participant.orno);
        $('#ordate').val(participant.ordate);
        $('#remarks').val(participant.remarks);

        const fullName = `${participant.fname} ${participant.mname} ${participant.lname}`;
        $('#fullName').text(fullName);

        // Load collecting officers if status is Paid
        if (participant.status === 'paid') {
          await forms.fetchCollectingOfficers(participant.location);
          $('#collecting_officer').val(participant.collecting_officer);
        }

        // Show the modal
        $('#updateModal').modal('show');
      } catch (error) {
        console.error('Error loading participant:', error);
        utils.showToast('error', 'Failed to load participant details');
      }
    },

    submitUpdateForm: async () => {
      const formData = {
        participantId: $('#participantId').val(),
        fname: $('#fname').val(),
        mname: $('#mname').val(),
        lname: $('#lname').val(),
        gender: $('#gender').val(),
        age: $('#age').val(),
        civilstatus: $('#civilstatus').val(),
        positiontitle: $('#positiontitle').val(),
        agency_name: $('#agency_name').val(),
        agency_address: $('#agency_address').val(),
        email: $('#email').val(),
        contactno: $('#contactno').val(),
        location: $('#location').val(),
        status: $('#status').val(),
        status_training: $('#status_training').val(),
        orno: $('#orno').val(),
        ordate: $('#ordate').val(),
        collecting_officer: $('#collecting_officer').val(),
        remarks: $('#remarks').val()
      };

      const success = await operations.updateParticipant(formData);
      if (success) {
        $('#updateModal').modal('hide');
      }
    }
  };

  // Form Management
  const forms = {
    toggleCollectingOfficerFields: () => {
      const status = $('#status').val();
      const collectingOfficer = $('#collecting_officer');
      const orno = $('#orno');
      const ordate = $('#ordate');
      const statusTraining = $('#status_training');

      if (status === 'paid') {
        collectingOfficer.prop('disabled', false);
        orno.prop('disabled', false).prop('required', true);
        ordate.prop('disabled', false);
        statusTraining.prop('disabled', false);
        
        const currentValue = collectingOfficer.val();
        
        forms.fetchCollectingOfficers($('#location').val()).then(() => {
          if (currentValue) {
            collectingOfficer.val(currentValue);
          }
        });
      } else {
        collectingOfficer.val('').prop('disabled', true);
        orno.val('').prop('disabled', true).prop('required', false);
        ordate.val('').prop('disabled', true);
        statusTraining.prop('disabled', true);
      }
    },

    fetchCollectingOfficers: async (location) => {
      try {
        const res = await fetch('fetch_co_dropdown.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ location })
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const response = await res.json();

        const select = $('#collecting_officer');
        const currentValue = select.val();
        select.empty();

        response.forEach(officer => {
          select.append($('<option>', {
            value: officer.id,
            text: officer.name
          }));
        });
        
        if (currentValue) {
          select.val(currentValue);
        }
        
        return response;
      } catch (error) {
        console.error('Error fetching collecting officers:', error);
        return [];
      }
    }
  };

  // Filter Management
  const filters = {
    apply: (filterValue) => {
      const urlParams = new URLSearchParams(window.location.search);
      
      if (filterValue === 'All') {
        urlParams.delete('filter');
      } else {
        urlParams.set('filter', filterValue);
      }
      
      window.location.search = urlParams.toString();
    },

    toggleButtons: (filterValue) => {
      const sendEmailAck = $('#sendEmailAck');
      const sendEmailZoom = $('#sendEmailZoom');
      const sendEmailAdvisory = $('#sendEmailAdvisory');

      sendEmailAck.hide();
      sendEmailZoom.hide();
      sendEmailAdvisory.hide();

      switch(filterValue) {
        case 'pending':
          sendEmailAck.show();
          sendEmailAdvisory.show();
          break;
        case 'paid':
          sendEmailZoom.show();
          break;
        case 'promissory note':
          sendEmailZoom.show();
          sendEmailAdvisory.show();
          break;
        case 'cancelled':
          break;
        default:
          sendEmailAck.show();
          sendEmailZoom.show();
          sendEmailAdvisory.show();
      }
    }
  };

  // DataTable Initialization
  const dataTable = {
    init: () => {
      const isMobileView = () => $(window).width() <= 767;

      listParticipants = $('#participantsList').DataTable({
        scrollX: true,
        fixedHeader: {
          headerOffset: 50
        },
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        fixedColumns: isMobileView() ? false : {
          left: 4
        },
        buttons: [{
          extend: 'excel',
          text: 'Export to Excel',
          filename: dataTable.getExportFilename(),
          exportOptions: {
            columns: ':gt(1)'
          }
        }],
        language: {
          search: '<i class="bi bi-search"></i>',
          searchPlaceholder: 'Search',
          lengthMenu: 'Show _MENU_ entries',
          paginate: {
            next: '<i class="bi bi-chevron-right"></i>',
            previous: '<i class="bi bi-chevron-left"></i>'
          }
        },
        lengthMenu: [[10, 50, 100, -1], [10, 50, 100, "All"]]
      });

      $('#exportButton').on('click', () => {
        listParticipants.button('.buttons-excel').trigger();
      });

      let currentIsMobile = isMobileView();
      $(window).on('resize', () => {
        const nowIsMobile = isMobileView();
        if (currentIsMobile !== nowIsMobile) {
          currentIsMobile = nowIsMobile;
          listParticipants.destroy();
          dataTable.init();
        }
      });

      return listParticipants;
    },

    getExportFilename: () => {
      const urlParams = new URLSearchParams(window.location.search);
      const location = urlParams.get('location') || '';
      const currentDate = new Date();
      const dateStr = `${String(currentDate.getMonth() + 1).padStart(2, '0')}-${String(currentDate.getDate()).padStart(2, '0')}-${currentDate.getFullYear()}`;
      
      return `${window.trainingName}_${location}_${dateStr}`;
    }
  };

  // Event Handlers
  const events = {
    init: () => {
      $('#checkAll').on('change', function() {
        $('.participant-checkbox').prop('checked', this.checked);
      });

      $('#updateModal').on('show.bs.modal', () => {
        forms.toggleCollectingOfficerFields();
      });

      $('#status').on('change', () => {
        forms.toggleCollectingOfficerFields();
      });

      $('#location').on('change', () => {
        const status = $('#status').val();
        if (status === 'paid') {
          forms.fetchCollectingOfficers($('#location').val());
        }
      });

      $('#updateForm').on('submit', (e) => {
        e.preventDefault();
        modals.submitUpdateForm();
      });

      $('#transferTraining').on('click', () => {
        const selected = utils.getSelectedParticipants();
        
        if (selected.length === 0) {
          utils.showToast('error', 'Please select participants to transfer', 3500);
          return;
        }

        utils.showConfirm(
          'Transfer Participants',
          `Transfer <b>${selected.length}</b> participant(s) to another training?`,
          'Yes, Transfer'
        ).then(result => {
          if (result.isConfirmed) {
            const participantIds = selected.map(p => p.id);
            $('#transferTrainingModal #participantIds').val(participantIds.join(','));
            $('#transferTrainingModal').modal('show');
          }
        });
      });

      $('#confirmTransfer').on('click', () => {
        const newTrainingId = $('#newTraining').val();
        if (!newTrainingId) {
          utils.showToast('error', 'Please select a training to transfer to', 3500);
          return;
        }
        operations.transferParticipants(newTrainingId);
        $('#transferTrainingModal').modal('hide');
      });

      $('#rescheduleDate').on('click', () => {
        const selected = utils.getSelectedParticipants();
        
        if (selected.length === 0) {
          utils.showToast('error', 'Please select at least one participant', 3500);
          return;
        }

        const participantIds = selected.map(p => p.id);
        $('#rescheduleParticipantsModal #participantIds').val(participantIds.join(','));
        $('#rescheduleParticipantsModal').modal('show');
      });

      $('#updateReschedule').on('click', () => {
        const newDate = $('#reschedule_date').val();
        operations.rescheduleParticipants(newDate);
      });

      $('#updateStatusModal').on('shown.bs.modal', () => {
        $('#newStatusSelect').focus();
      });

      $('#zoomLinkModal').on('shown.bs.modal', () => {
        $('#zoomLinkInput').focus();
      });
    }
  };

  // Public API
  return {
    init: () => {
      dataTable.init();
      events.init();
      
      const urlParams = new URLSearchParams(window.location.search);
      const currentFilter = urlParams.get('filter') || 'All';
      filters.toggleButtons(currentFilter);
    },
    
    showUpdateModal: modals.showUpdateModal,
    updateParticipant: modals.submitUpdateForm,
    deleteParticipant: operations.deleteParticipant,
    updateSelectedParticipants: () => {
      const newStatus = $('#newStatusSelect').val();
      operations.updateSelectedStatus(newStatus);
    },
    sendAcknowledgmentLetter: () => {
      const trainingId = new URLSearchParams(window.location.search).get('id');
      emailOperations.sendAcknowledgment(trainingId);
    },
    confirmSendEmailZoom: () => {
      const trainingId = new URLSearchParams(window.location.search).get('id');
      const zoomLink = $('#zoomLinkInput').val().trim();
      const pdfFile = $('#zoomPDF')[0].files[0];
      const bgFile = $('#zoomBg')[0].files[0];
      
      emailOperations.sendZoomLink(trainingId, zoomLink, pdfFile, bgFile);
      $('#zoomLinkModal').modal('hide');
    },
    sendEmailAdvisory: () => {
      const trainingId = new URLSearchParams(window.location.search).get('id');
      emailOperations.sendAdvisory(trainingId);
    },
    applyFilter: filters.apply
  };
})();

$(document).ready(() => {
  ParticipantManager.init();
});

// Global functions for inline event handlers
function showUpdateModal(participantId) {
  ParticipantManager.showUpdateModal(participantId);
}

function updateParticipant() {
  ParticipantManager.updateParticipant();
}

function deleteParticipant(participantId) {
  ParticipantManager.deleteParticipant(participantId);
}

function updateSelectedParticipants() {
  ParticipantManager.updateSelectedParticipants();
}

function sendAcknowledgmentLetter() {
  ParticipantManager.sendAcknowledgmentLetter();
}

function confirmSendEmailZoom() {
  ParticipantManager.confirmSendEmailZoom();
}

function sendEmailAdvisory() {
  ParticipantManager.sendEmailAdvisory();
}

function applyFilter(filterValue) {
  ParticipantManager.applyFilter(filterValue);
}