<!-- imis/otrs/hrd/inc/sidebar.php -->
<aside id="sidebar" class="sidebar">
    <!-- Sidebar content here -->
    <ul class="sidebar-nav" id="sidebar-nav">
<li class="nav-item">
        <a class="nav-link collapsed" data-bs-target="#components-nav" href="index">
        <i class="bi bi-people-fill"></i><span>List of Trainings and Registered Participants</span>
        </a>
    </li>
        <!--<ul id="components-nav" class="nav-content" data-bs-parent="#sidebar-nav">
          <li>
            <a href="index?location=hrd">
              <i class="bi bi-circle"></i><span>CSC RO VIII - HRD</span>
            </a>
          </li>
          <li>
            <a href="index?location=lfoi">
              <i class="bi bi-circle"></i><span>CSC FO - Leyte I</span> <span>Manage Participants</span>
            </a>
          </li>
          <li>
            <a href="index?location=lfoii">
              <i class="bi bi-circle"></i><span>CSC FO - Leyte II</span>
            </a>
          </li>
          <li>
              <a href="index?location=bfo">
                <i class="bi bi-circle"></i><span>CSC FO - Biliran</span>
              </a>
          </li>
          <li>
              <a href="index?location=wlso">
                <i class="bi bi-circle"></i><span>CSC SO - Western Leyte</span>
              </a>
          </li>
          <li>
              <a href="index?location=slfo">
                <i class="bi bi-circle"></i><span>CSC FO - Southern Leyte</span>
              </a>
          </li>
          <li>
              <a href="index?location=sfo">
                <i class="bi bi-circle"></i><span>CSC FO - Samar</span>
              </a>
          </li>
          <li>
              <a href="index?location=esfo">
                <i class="bi bi-circle"></i><span>CSC FO - Eastern Samar</span>
              </a>
          </li>
          <li>
              <a href="index?location=nsfo">
                <i class="bi bi-circle"></i><span>CSC FO - Northern Samar</span>
              </a>
          </li>
          <li>
              <a href="index?location=ro6">
                <i class="bi bi-circle"></i><span>CSC Regional Office VI</span>
              </a>
          </li>
          <li>
              <a href="index?location=ro7">
                <i class="bi bi-circle"></i><span>CSC Regional Office VII</span>
              </a>
          </li>
        </ul>-->
        </li>

        <li class="nav-item">
        <a class="nav-link collapsed" href="hrd_calendar">
          <i class="bi bi-calendar2-week-fill"></i>
          <span>HRD Calendar of Activities</span>
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link collapsed" href="index_manage_trainings">
          <i class="bi bi-clipboard-data"></i>
          <span>Manage Trainings</span>
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link collapsed" href="index_participants">
          <i class="bi bi-person-lines-fill"></i>
          <span>Manage Participants</span>
        </a>
      </li>

      <!-- ── Certificates group ── -->
      <li class="nav-item">
        <a class="nav-link collapsed" data-bs-toggle="collapse" data-bs-target="#certs-nav"
           href="#" aria-expanded="false">
          <i class="bi bi-award-fill" style="color:#7b00cc;"></i>
          <span>Certificates</span>
          <i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="certs-nav" class="nav-content collapse ps-3" data-bs-parent="#sidebar-nav">
          <li>
            <a class="nav-link collapsed" href="ccs_dashboard">
              <i class="bi bi-collection-fill me-1 text-primary"></i>
              <span>Certificate Repository</span>
            </a>
          </li>
          <li>
            <a class="nav-link collapsed" href="index_trainings">
              <i class="bi bi-patch-check-fill me-1" style="color:#7b00cc;"></i>
              <span>Generate Certificates</span>
            </a>
          </li>
          <li>
            <a class="nav-link collapsed" href="agency_certificates">
              <i class="bi bi-building-fill-check me-1" style="color:#7b00cc;"></i>
              <span>Agency-Requested</span>
            </a>
          </li>
          <li>
            <a class="nav-link collapsed" href="appearance_certificates">
              <i class="bi bi-person-badge-fill me-1" style="color:#0d9488;"></i>
              <span>Certificate of Appearance</span>
            </a>
          </li>
        </ul>
      </li>

    <!-- <li class="nav-item">
        <a class="nav-link collapsed" href="index-co" disabled>
          <i class="bi bi-person-check"></i>
          <span>Manage Feedback</span>
        </a>
      </li> -->
      
      <li class="nav-item">
        <a class="nav-link collapsed" href="index_co">
          <i class="bi bi-person-check"></i>
          <span>Manage Collecting Officer</span>
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link collapsed" href="pending_payments">
          <i class="bi bi-receipt" style="color:#dc2626;"></i>
          <span>Pending Payments</span>
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link collapsed" href="payment_logs">
          <i class="bi bi-clock-history" style="color:#92400e;"></i>
          <span>Payment Audit Log</span>
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link collapsed" href="rescheduled_trainings">
          <i class="bi bi-calendar-event" style="color:#f59e0b;"></i>
          <span>Rescheduled Trainings</span>
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link collapsed" href="cancellation_requests">
          <i class="bi bi-clipboard-x-fill" style="color:#fd7e14;"></i>
          <span>Cancellation Requests</span>
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link collapsed" href="replacement_requests">
          <i class="bi bi-arrow-left-right" style="color:#0d6efd;"></i>
          <span>Replacement Requests</span>
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link collapsed" href="submission_review">
          <i class="bi bi-card-checklist" style="color:#0369a1;"></i>
          <span>Submission Review</span>
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link collapsed" href="evaluation_results">
          <i class="bi bi-bar-chart-line-fill" style="color:#7c3aed;"></i>
          <span>Evaluation Results</span>
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link collapsed" href="sme_management">
          <i class="bi bi-person-video3" style="color:#0891b2;"></i>
          <span>SME Management</span>
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link collapsed" href="cert_verification_log">
          <i class="bi bi-shield-check" style="color:#198754;"></i>
          <span>Certificate Verification Log</span>
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link collapsed" href="email_management">
          <i class="bi bi-envelope-paper-fill" style="color:#0891b2;"></i>
          <span>Email Templates</span>
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link collapsed" href="index_bkup">
          <i class="bi bi-database"></i>
          <span>Backup Database</span>
        </a>
      </li>
    </ul>
</aside>