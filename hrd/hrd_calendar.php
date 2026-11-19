<?php
/**
 * hrd_calendar.php  —  Redesigned HRD Calendar of Activities
 * -----------------------------------------------------------
 * Displays:
 *   • Live trainings from `otrs_trainings` table (read-only, color-coded by status)
 *   • Custom events from `otrs_hrd_calendar` (fully editable)
 *
 * Design mirrors participants.php: toolbar system, section labels,
 * blue strip, btn-f flat-button grid, etc.
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    header('Location: ../index?location=' . urlencode(strtolower($_SESSION['type'] ?? '')));
    exit();
}

// ── Quick stats for the summary strip ────────────────────────────────────────
$statsTrainings    = 0;
$statsThisMonth    = 0;
$statsCustomEvents = 0;
$statsOngoing      = 0;

try {
    // Total active trainings this year
    $r = $conn->query("
        SELECT COUNT(*) FROM otrs_trainings
        WHERE  status != 'deleted'
          AND  start_date != '0000-00-00'
          AND  start_date IS NOT NULL
          AND  YEAR(start_date) = YEAR(CURDATE())
    ");
    $statsTrainings = (int)$r->fetchColumn();

    // Ongoing trainings
    $r = $conn->query("
        SELECT COUNT(*) FROM otrs_trainings
        WHERE  status != 'deleted'
          AND  LOWER(training_status) = 'ongoing'
    ");
    $statsOngoing = (int)$r->fetchColumn();

    // This month (trainings)
    $r = $conn->query("
        SELECT COUNT(*) FROM otrs_trainings
        WHERE  status != 'deleted'
          AND  start_date != '0000-00-00'
          AND  start_date IS NOT NULL
          AND  MONTH(start_date) = MONTH(CURDATE())
          AND  YEAR(start_date)  = YEAR(CURDATE())
    ");
    $statsThisMonth = (int)$r->fetchColumn();

    // Custom events from otrs_hrd_calendar
    $r = $conn->query("
        SELECT COUNT(*) FROM otrs_hrd_calendar
        WHERE  status = 'active'
          AND  is_synced_training = 0
          AND  YEAR(`start`) = YEAR(CURDATE())
    ");
    $statsCustomEvents = (int)$r->fetchColumn();

} catch (PDOException $e) {
    // non-fatal — stats will show 0
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>CSC RO VIII — HRD Calendar of Activities</title>

    <!-- Favicons -->
    <link href="../assets/img/favicon.png"        rel="icon">
    <link href="../assets/img/apple-touch-icon.png" rel="apple-touch-icon">

    <!-- Fonts -->
    <link href="https://fonts.gstatic.com" rel="preconnect">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;600;700&family=Nunito:wght@300;400;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Vendor CSS -->
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css"     rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/vendor/boxicons/css/boxicons.min.css"        rel="stylesheet">
    <link href="../assets/vendor/remixicon/remixicon.css"              rel="stylesheet">

    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

    <!-- App CSS -->
    <link href="../../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/modules/otrs.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/modules/otrs.css'); ?>" rel="stylesheet">
    <link href="../../assets/css/imis-design-system.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/imis-design-system.css'); ?>" rel="stylesheet">

    <style>
        /* ── Layout ──────────────────────────────────────────────── */
        label, input, select, .form-control { font-size: 14px; }

        /* ── Back button ─────────────────────────────────────────── */
        .btn-back-page {
            background: #dc3545; border: none; color: #fff;
            font-size: .78rem; font-weight: 600;
            padding: .32rem .78rem; border-radius: 5px;
            display: inline-flex; align-items: center; gap: .3rem;
            text-decoration: none;
            transition: filter .13s ease, transform .12s ease;
            white-space: nowrap; align-self: flex-start;
        }
        .btn-back-page:hover  { filter: brightness(.86); transform: translateY(-1px); color:#fff; }
        .btn-back-page:active { filter: brightness(.78); transform: translateY(0);    color:#fff; }

        /* ── Section label ───────────────────────────────────────── */
        .section-label {
            font-size: .68rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 1.5px;
            color: #899bbd; border-bottom: 1px solid #ebeef4;
            padding-bottom: .4rem; margin-bottom: .75rem;
        }

        /* ── Toolbar ─────────────────────────────────────────────── */
        .toolbar {
            display: flex; flex-wrap: wrap; align-items: center;
            gap: .38rem; padding: .6rem .9rem;
            background: #f6f9ff; border: 1px solid #ebeef4;
            border-radius: 6px;
        }
        .toolbar + .toolbar { margin-top: .45rem; }
        .toolbar-divider     { width:1px; height:20px; background:#d1d8e8; flex-shrink:0; margin:0 .05rem; }
        .toolbar-spacer      { flex: 1 1 auto; }

        /* ── Flat button system ──────────────────────────────────── */
        .btn-f {
            border: none; color: #fff;
            font-size: .78rem; font-weight: 600;
            padding: .3rem .75rem; border-radius: 4px;
            display: inline-flex; align-items: center; gap: .3rem;
            cursor: pointer; text-decoration: none; white-space: nowrap;
            transition: filter .13s ease, transform .12s ease;
            line-height: 1.45;
        }
        .btn-f:hover  { filter: brightness(.86); transform: translateY(-1px); color:#fff; }
        .btn-f:active { filter: brightness(.78); transform: translateY(0);    color:#fff; }
        .btn-f:focus  { outline: none; }

        .btn-f-primary   { background: #0077b6; }
        .btn-f-success   { background: #198754; }
        .btn-f-danger    { background: #dc3545; }
        .btn-f-secondary { background: #6c757d; }
        .btn-f-warning   { background: #e07b00; }
        .btn-f-dark      { background: #343a40; }

        /* Filter select */
        .filter-select {
            font-size: .77rem !important; font-weight: 600;
            border: 1px solid #d1d8e8; border-radius: 4px;
            padding: .28rem .55rem; color: #012970; background: #fff;
            height: auto; cursor: pointer;
        }
        .filter-select:focus { border-color:#0077b6; box-shadow:0 0 0 .15rem rgba(0,119,182,.15); outline:none; }

        /* ── Stats cards ─────────────────────────────────────────── */
        .stat-card {
            background: #fff; border: 1px solid #ebeef4;
            border-radius: 8px; padding: 1rem 1.2rem;
            display: flex; align-items: center; gap: .9rem;
            box-shadow: 0 1px 4px rgba(1,41,112,.06);
            flex: 1 1 180px; min-width: 160px;
        }
        .stat-icon {
            width: 44px; height: 44px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; font-size: 1.2rem;
        }
        .stat-label { font-size: .68rem; text-transform: uppercase; letter-spacing: .8px; color: #899bbd; margin-bottom: .1rem; }
        .stat-value { font-size: 1.55rem; font-weight: 800; color: #012970; line-height: 1; }

        /* ── Legend ──────────────────────────────────────────────── */
        .legend-strip {
            background: #f6f9ff; border: 1px solid #ebeef4;
            border-radius: 6px; padding: .55rem 1rem;
            display: flex; flex-wrap: wrap; align-items: center;
            gap: .5rem 1.2rem; margin-bottom: .85rem;
        }
        .legend-item {
            display: inline-flex; align-items: center; gap: .38rem;
            font-size: .76rem; font-weight: 600; color: #344767;
        }
        .legend-dot {
            width: 12px; height: 12px; border-radius: 50%;
            display: inline-block; flex-shrink: 0;
        }
        .legend-bar {
            width: 28px; height: 12px; border-radius: 3px;
            display: inline-block; flex-shrink: 0;
        }

        /* ── Calendar wrapper ─────────────────────────────────────── */
        #calendarWrap {
            background: #fff; border: 1px solid #ebeef4;
            border-radius: 8px; padding: 1rem;
        }

        /* ── FullCalendar overrides ───────────────────────────────── */
        .fc .fc-toolbar-title             { font-size: 1.05rem; font-weight: 700; color: #012970; }
        .fc .fc-button-primary            { background: #0077b6 !important; border-color: #0077b6 !important; font-size: .78rem !important; }
        .fc .fc-button-primary:hover      { background: #005f94 !important; }
        .fc .fc-button-primary.fc-button-active { background: #004a75 !important; }
        .fc .fc-daygrid-event             { font-size: .72rem; padding: 1px 4px; border-radius: 3px; }
        .fc .fc-event-title               { font-weight: 600; }
        .fc .fc-list-event-title a        { font-weight: 600; color: inherit; }
        .fc .fc-col-header-cell-cushion   { font-size: .78rem; font-weight: 700; color: #344767; }
        .fc .fc-daygrid-day-number        { font-size: .8rem; color: #344767; }
        .fc .fc-day-today                 { background: rgba(0,119,182,.06) !important; }
        .fc .fc-day-today .fc-daygrid-day-number { color: #0077b6; font-weight: 800; }
        .fc-event-training                { cursor: default !important; }
        .fc-event-custom                  { cursor: pointer !important; }

        /* ── Tooltip popup ───────────────────────────────────────── */
        .fc-event-popover {
            position: absolute; z-index: 9999;
            background: #fff; border: 1px solid #dee2e6;
            border-radius: 8px; padding: .75rem 1rem;
            box-shadow: 0 8px 24px rgba(0,0,0,.12);
            min-width: 220px; max-width: 300px;
            pointer-events: none;
        }
        .fc-event-popover .pop-title   { font-weight: 700; font-size: .82rem; color: #012970; margin-bottom: .35rem; }
        .fc-event-popover .pop-row     { font-size: .76rem; color: #344767; display: flex; align-items: flex-start; gap: .35rem; margin-bottom: .18rem; }
        .fc-event-popover .pop-row i   { flex-shrink: 0; margin-top: 2px; }

        /* ── Training readonly badge ─────────────────────────────── */
        .badge-readonly {
            font-size: .6rem; font-weight: 700; letter-spacing: .5px;
            padding: .12rem .4rem; border-radius: 3px;
            background: rgba(255,255,255,.25); color: #fff;
            vertical-align: middle;
        }

        /* ── Modal: view training ────────────────────────────────── */
        #viewTrainingModal .modal-header { background: #2E7D32; }
        #viewTrainingModal .training-status-badge {
            display: inline-flex; align-items: center; gap: .3rem;
            font-size: .8rem; font-weight: 700; padding: .25rem .65rem;
            border-radius: 20px; border: 2px solid rgba(255,255,255,.4);
            color: #fff; background: rgba(255,255,255,.15);
        }
        #viewTrainingModal .detail-row {
            display: flex; align-items: flex-start; gap: .75rem;
            padding: .55rem 0; border-bottom: 1px solid #f1f4f8;
            font-size: .85rem;
        }
        #viewTrainingModal .detail-row:last-child { border-bottom: none; }
        #viewTrainingModal .detail-icon {
            width: 28px; height: 28px; border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; font-size: .85rem;
        }
        #viewTrainingModal .detail-label { font-size: .68rem; text-transform: uppercase; letter-spacing: .8px; color: #899bbd; line-height: 1; margin-bottom: .15rem; }
        #viewTrainingModal .detail-value { font-weight: 600; color: #012970; }

        /* ── Modal: add / edit event ─────────────────────────────── */
        #addEventModal .modal-header,
        #editEventModal .modal-header {
            background: #0077b6;
        }
        .type-btn-group { display: flex; flex-wrap: wrap; gap: .45rem; margin-top: .35rem; }
        .type-btn {
            display: inline-flex; align-items: center; gap: .3rem;
            padding: .3rem .75rem; border-radius: 20px; border: 2px solid transparent;
            font-size: .76rem; font-weight: 700; cursor: pointer;
            transition: all .15s ease; text-transform: uppercase; letter-spacing: .5px;
        }
        .type-btn.active { border-color: #fff; box-shadow: 0 0 0 3px rgba(0,0,0,.12); }

        /* ── Clock ───────────────────────────────────────────────── */
        #clock { font-variant-numeric: tabular-nums; }
    </style>
</head>

<body onload="startTime()">

    <?php imis_include('header_js') ?>
    <?php include 'inc/sidebar.php' ?>

    <main id="main" class="main">

        <div class="pagetitle otrs-ph">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="otrs-ph-icon icon-purple"><i class="bi bi-calendar2-week-fill"></i></div>
                    <div>
                        <h1>HRD Calendar of Activities</h1>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="index">Home</a></li>
                                <li class="breadcrumb-item active">HRD Calendar</li>
                            </ol>
                        </nav>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <div class="text-end" style="font-size:.78rem;color:#64748b;">
                        <div style="font-weight:600;color:#012970;"><?php echo date('l, F j, Y'); ?></div>
                        <div id="clock" style="font-size:.75rem;color:#0077b6;font-weight:700;"></div>
                    </div>
                    <a href="index" class="otrs-btn-back">
                        <i class="bi bi-arrow-left-circle"></i> Dashboard
                    </a>
                </div>
            </div>
        </div>
        <!-- ── END PAGE TITLE ──────────────────────────────────────── -->

        <section class="section">

            <!-- ── STAT CARDS ──────────────────────────────────────── -->
            <div class="d-flex flex-wrap gap-3 mb-4">

                <div class="stat-card">
                    <div class="stat-icon" style="background:#e8f0fe;">
                        <i class="bi bi-mortarboard-fill" style="color:#0077b6;"></i>
                    </div>
                    <div>
                        <div class="stat-label">Trainings (<?php echo date('Y'); ?>)</div>
                        <div class="stat-value"><?php echo $statsTrainings; ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="background:#e8f5e9;">
                        <i class="bi bi-arrow-repeat" style="color:#198754;"></i>
                    </div>
                    <div>
                        <div class="stat-label">Ongoing</div>
                        <div class="stat-value"><?php echo $statsOngoing; ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="background:#fff3e0;">
                        <i class="bi bi-calendar-week" style="color:#e07b00;"></i>
                    </div>
                    <div>
                        <div class="stat-label">This Month</div>
                        <div class="stat-value"><?php echo $statsThisMonth; ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="background:#f3e5f5;">
                        <i class="bi bi-stars" style="color:#7b1fa2;"></i>
                    </div>
                    <div>
                        <div class="stat-label">Custom Events (<?php echo date('Y'); ?>)</div>
                        <div class="stat-value"><?php echo $statsCustomEvents; ?></div>
                    </div>
                </div>

            </div><!-- /.stat cards -->

            <!-- ── CALENDAR CARD ────────────────────────────────────── -->
            <div class="card">
                <div class="card-body p-4">

                    <p class="section-label"><i class="bi bi-sliders me-1"></i> Actions &amp; Filters</p>

                    <!-- ── TOOLBAR ───────────────────────────────────── -->
                    <div class="toolbar mb-2">
                        <!-- Add Event -->
                        <button class="btn-f btn-f-primary"
                                onclick="openAddModal()">
                            <i class="bi bi-plus-circle-fill"></i> Add Event
                        </button>

                        <div class="toolbar-divider"></div>

                        <!-- Filter toggles -->
                        <span style="font-size:.77rem;font-weight:600;color:#012970;">
                            <i class="bi bi-funnel me-1 text-primary"></i>Show:
                        </span>
                        <button class="btn-f btn-f-success  filter-toggle active"
                                data-type="training"  title="Toggle Training events">
                            <i class="bi bi-mortarboard-fill"></i> Trainings
                        </button>
                        <button class="btn-f filter-toggle active"
                                data-type="event"
                                style="background:#2196F3;" title="Toggle Activities">
                            <i class="bi bi-star-fill"></i> Activities
                        </button>
                        <button class="btn-f filter-toggle active"
                                data-type="leave"
                                style="background:#FF9800;" title="Toggle Leave">
                            <i class="bi bi-person-x-fill"></i> Leave
                        </button>
                        <button class="btn-f filter-toggle active"
                                data-type="holiday"
                                style="background:#E53935;" title="Toggle Holidays">
                            <i class="bi bi-flag-fill"></i> Holidays
                        </button>
                        <button class="btn-f filter-toggle active"
                                data-type="other"
                                style="background:#7b1fa2;" title="Toggle Other">
                            <i class="bi bi-three-dots"></i> Other
                        </button>

                        <div class="toolbar-spacer"></div>

                        <!-- View buttons -->
                        <button class="btn-f btn-f-dark" id="btnPrint" title="Print calendar">
                            <i class="bi bi-printer-fill"></i> Print
                        </button>
                        <button class="btn-f btn-f-secondary" id="btnToday">
                            <i class="bi bi-calendar-check"></i> Today
                        </button>
                    </div>

                    <!-- ── LEGEND ─────────────────────────────────────── -->
                    <div class="legend-strip">
                        <span style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#899bbd;">
                            Legend:
                        </span>
                        <!-- Training statuses -->
                        <span class="legend-item">
                            <span class="legend-bar" style="background:#2E7D32;"></span> Open / Upcoming Training
                        </span>
                        <span class="legend-item">
                            <span class="legend-bar" style="background:#1565C0;"></span> Ongoing Training
                        </span>
                        <span class="legend-item">
                            <span class="legend-bar" style="background:#546E7A;"></span> Completed Training
                        </span>
                        <span class="legend-item">
                            <span class="legend-bar" style="background:#E53935;"></span> Cancelled Training
                        </span>
                        <span class="legend-item" style="border-left:1px solid #d1d8e8; padding-left:.8rem;">
                            <span class="legend-dot" style="background:#2196F3;"></span> Event / Activity
                        </span>
                        <span class="legend-item">
                            <span class="legend-dot" style="background:#FF9800;"></span> Leave
                        </span>
                        <span class="legend-item">
                            <span class="legend-dot" style="background:#E53935;"></span> Holiday
                        </span>
                        <span class="legend-item">
                            <span class="legend-dot" style="background:#7b1fa2;"></span> Other
                        </span>
                    </div>

                    <!-- ── FULLCALENDAR ───────────────────────────────── -->
                    <p class="section-label"><i class="bi bi-calendar3 me-1"></i> Calendar View</p>
                    <div id="calendarWrap">
                        <div id="calendar"></div>
                    </div>

                </div><!-- /.card-body -->
            </div><!-- /.card -->

        </section>
    </main><!-- /#main -->


    <!-- ══════════════════════════════════════════════════════════════
         MODAL: Add Custom Event
    ══════════════════════════════════════════════════════════════ -->
    <div id="addEventModal" class="modal fade" tabindex="-1" aria-labelledby="addEventModalLabel">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-white fw-bold" id="addEventModalLabel">
                        <i class="bi bi-plus-circle-fill me-2"></i>Add Activity / Event
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="addEventForm" autocomplete="off">

                        <!-- Activity Type -->
                        <div class="mb-3">
                            <label class="form-label fw-bold mb-1">Type of Activity <span class="text-danger">*</span></label>
                            <div class="type-btn-group" id="addTypeGroup">
                                <button type="button" class="type-btn active"
                                        data-type="event" data-color="#2196F3"
                                        style="background:#2196F3;color:#fff;">
                                    <i class="bi bi-star-fill"></i> Activity/Event
                                </button>
                                <button type="button" class="type-btn"
                                        data-type="leave" data-color="#FF9800"
                                        style="background:#eee;color:#555;">
                                    <i class="bi bi-person-x-fill"></i> Leave
                                </button>
                                <button type="button" class="type-btn"
                                        data-type="holiday" data-color="#E53935"
                                        style="background:#eee;color:#555;">
                                    <i class="bi bi-flag-fill"></i> Holiday
                                </button>
                                <button type="button" class="type-btn"
                                        data-type="other" data-color="#7b1fa2"
                                        style="background:#eee;color:#555;">
                                    <i class="bi bi-three-dots"></i> Other
                                </button>
                            </div>
                            <input type="hidden" id="addActivityType" value="event">
                            <input type="hidden" id="addColor"        value="#2196F3">
                        </div>

                        <!-- Title -->
                        <div class="mb-3">
                            <label for="addTitle" class="form-label fw-bold">
                                Name of Activity / Title <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="addTitle"
                                   placeholder="e.g. Monthly Staff Meeting" required>
                        </div>

                        <!-- Description -->
                        <div class="mb-3">
                            <label for="addDescription" class="form-label fw-bold">Description / Notes</label>
                            <textarea class="form-control" id="addDescription" rows="2"
                                      placeholder="Optional details…"></textarea>
                        </div>

                        <!-- Dates -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="addStart" class="form-label fw-bold">
                                    Start Date &amp; Time <span class="text-danger">*</span>
                                </label>
                                <input type="datetime-local" class="form-control" id="addStart" required>
                            </div>
                            <div class="col-md-6">
                                <label for="addEnd" class="form-label fw-bold">
                                    End Date &amp; Time <span class="text-danger">*</span>
                                </label>
                                <input type="datetime-local" class="form-control" id="addEnd" required>
                            </div>
                        </div>

                        <!-- All day -->
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="addAllDay">
                            <label class="form-check-label fw-bold" for="addAllDay">
                                All-day event
                            </label>
                        </div>

                        <!-- Recurrence -->
                        <div class="mb-2">
                            <label for="addRecurrence" class="form-label fw-bold">Recurrence</label>
                            <select class="form-select" id="addRecurrence">
                                <option value="none">None (one-time)</option>
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly (every 3 months)</option>
                                <option value="annually">Annually (yearly)</option>
                            </select>
                        </div>

                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger"
                            data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Close
                    </button>
                    <button type="button" class="btn btn-success"
                            id="btnSaveEvent">
                        <i class="bi bi-save"></i> Save Event
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- ══════════════════════════════════════════════════════════════
         MODAL: View Training (read-only)
    ══════════════════════════════════════════════════════════════ -->
    <div id="viewTrainingModal" class="modal fade" tabindex="-1">
        <div class="modal-dialog modal-md">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="flex-grow-1">
                        <h5 class="modal-title text-white fw-bold mb-1" id="vtModalTitle">—</h5>
                        <span class="training-status-badge" id="vtModalBadge">—</span>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">

                    <div class="detail-row">
                        <div class="detail-icon" style="background:#e8f0fe;">
                            <i class="bi bi-calendar3 text-primary"></i>
                        </div>
                        <div>
                            <div class="detail-label">Training Date</div>
                            <div class="detail-value" id="vtDate">—</div>
                        </div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-icon" style="background:#e8f5e9;">
                            <i class="bi bi-geo-alt-fill" style="color:#198754;"></i>
                        </div>
                        <div>
                            <div class="detail-label">Location</div>
                            <div class="detail-value" id="vtLocation">—</div>
                        </div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-icon" style="background:#fff3e0;">
                            <i class="bi bi-display" style="color:#e07b00;"></i>
                        </div>
                        <div>
                            <div class="detail-label">Mode of Delivery</div>
                            <div class="detail-value" id="vtMode">—</div>
                        </div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-icon" style="background:#e3f2fd;">
                            <i class="bi bi-people-fill" style="color:#1565C0;"></i>
                        </div>
                        <div>
                            <div class="detail-label">Slots / Enrolled</div>
                            <div class="detail-value" id="vtSlots">—</div>
                        </div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-icon" style="background:#f3e5f5;">
                            <i class="bi bi-cash-coin" style="color:#7b1fa2;"></i>
                        </div>
                        <div>
                            <div class="detail-label">Registration Fee</div>
                            <div class="detail-value" id="vtFee">—</div>
                        </div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-icon" style="background:#e0f7fa;">
                            <i class="bi bi-clock-fill" style="color:#00838f;"></i>
                        </div>
                        <div>
                            <div class="detail-label">Training Hours</div>
                            <div class="detail-value" id="vtHours">—</div>
                        </div>
                    </div>

                    <!-- Info note -->
                    <div class="alert alert-info mt-3 mb-0 py-2 px-3" style="font-size:.8rem;">
                        <i class="bi bi-info-circle me-1"></i>
                        This training is managed in the <strong>Training List</strong>.
                        Click below to view participants.
                    </div>

                </div>
                <div class="modal-footer d-flex justify-content-between align-items-center">
                    <div>
                        <span class="badge bg-secondary" id="vtTypeBadge" style="font-size:.72rem;">—</span>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> Close
                        </button>
                        <a href="#" id="vtParticipantsLink" class="btn btn-primary">
                            <i class="bi bi-people-fill"></i> View Participants
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- ══════════════════════════════════════════════════════════════
         MODAL: Edit Custom Event
    ══════════════════════════════════════════════════════════════ -->
    <div id="editEventModal" class="modal fade" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-white fw-bold">
                        <i class="bi bi-pencil-square me-2"></i>Edit Event
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="editEventForm" autocomplete="off">
                        <input type="hidden" id="editEventId">
                        <input type="hidden" id="editOccurrenceDate">

                        <!-- Activity Type -->
                        <div class="mb-3">
                            <label class="form-label fw-bold mb-1">Type of Activity</label>
                            <div class="type-btn-group" id="editTypeGroup">
                                <button type="button" class="type-btn"
                                        data-type="event" data-color="#2196F3"
                                        style="background:#eee;color:#555;">
                                    <i class="bi bi-star-fill"></i> Activity/Event
                                </button>
                                <button type="button" class="type-btn"
                                        data-type="leave" data-color="#FF9800"
                                        style="background:#eee;color:#555;">
                                    <i class="bi bi-person-x-fill"></i> Leave
                                </button>
                                <button type="button" class="type-btn"
                                        data-type="holiday" data-color="#E53935"
                                        style="background:#eee;color:#555;">
                                    <i class="bi bi-flag-fill"></i> Holiday
                                </button>
                                <button type="button" class="type-btn"
                                        data-type="other" data-color="#7b1fa2"
                                        style="background:#eee;color:#555;">
                                    <i class="bi bi-three-dots"></i> Other
                                </button>
                            </div>
                            <input type="hidden" id="editActivityType" value="event">
                            <input type="hidden" id="editColor"        value="#2196F3">
                        </div>

                        <!-- Title -->
                        <div class="mb-3">
                            <label for="editTitle" class="form-label fw-bold">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="editTitle" required>
                        </div>

                        <!-- Description -->
                        <div class="mb-3">
                            <label for="editDescription" class="form-label fw-bold">Description / Notes</label>
                            <textarea class="form-control" id="editDescription" rows="2"></textarea>
                        </div>

                        <!-- Dates -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="editStart" class="form-label fw-bold">Start <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control" id="editStart" required>
                            </div>
                            <div class="col-md-6">
                                <label for="editEnd" class="form-label fw-bold">End <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control" id="editEnd" required>
                            </div>
                        </div>

                        <!-- All day -->
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="editAllDay">
                            <label class="form-check-label fw-bold" for="editAllDay">All-day event</label>
                        </div>

                        <!-- Recurrence -->
                        <div class="mb-2">
                            <label for="editRecurrence" class="form-label fw-bold">Recurrence</label>
                            <select class="form-select" id="editRecurrence">
                                <option value="none">None (one-time)</option>
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="annually">Annually</option>
                            </select>
                        </div>

                    </form>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-danger" id="btnDeleteEvent">
                        <i class="bi bi-trash-fill"></i> Delete
                    </button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> Close
                        </button>
                        <button type="button" class="btn btn-primary" id="btnUpdateEvent">
                            <i class="bi bi-save"></i> Update
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- Hover tooltip for calendar events -->
    <div id="calEventTooltip" class="fc-event-popover d-none"></div>


    <?php imis_include('footer') ?>
    <a href="#" class="back-to-top d-flex align-items-center justify-content-center">
        <i class="bi bi-arrow-up-short"></i>
    </a>

    <!-- ── JS dependencies ─────────────────────────────────────────── -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.14/index.global.min.js"></script>
        
    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/main.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/main.js'); ?>"></script>

    <script>
    /* ================================================================
       HRD CALENDAR — main script
       ================================================================ */
    (function () {
        'use strict';

        // ── Helpers ──────────────────────────────────────────────────
        const toast = (icon, title) => Swal.fire({
            icon, title,
            toast: true, position: 'top-end',
            showConfirmButton: false, timer: 3000,
            timerProgressBar: true
        });

        const confirm = (title, html, confirmText = 'Yes', icon = 'warning') =>
            Swal.fire({ title, html, icon, showCancelButton: true, confirmButtonText: confirmText });

        function fmtDatetimeLocal(date) {
            if (!date) return '';
            const pad = n => String(n).padStart(2, '0');
            return date.getFullYear() + '-' +
                   pad(date.getMonth() + 1) + '-' +
                   pad(date.getDate())      + 'T' +
                   pad(date.getHours())     + ':' +
                   pad(date.getMinutes());
        }

        // Default datetime: today 08:00
        function defaultDT(hour = 8) {
            const d = new Date();
            d.setHours(hour, 0, 0, 0);
            return fmtDatetimeLocal(d);
        }

        // ── Type-button selector ──────────────────────────────────────
        function initTypeButtons(groupId, hiddenTypeId, hiddenColorId) {
            const $group = $('#' + groupId);
            $group.on('click', '.type-btn', function () {
                const $btn  = $(this);
                const type  = $btn.data('type');
                const color = $btn.data('color');

                // Reset all
                $group.find('.type-btn').css({
                    background: '#eee', color: '#555', borderColor: 'transparent'
                }).removeClass('active');

                // Activate clicked
                $btn.css({ background: color, color: '#fff' }).addClass('active');

                $('#' + hiddenTypeId).val(type);
                $('#' + hiddenColorId).val(color);
            });
        }

        initTypeButtons('addTypeGroup',  'addActivityType',  'addColor');
        initTypeButtons('editTypeGroup', 'editActivityType', 'editColor');

        function setActiveTypeBtn(groupId, hiddenTypeId, hiddenColorId, type) {
            const $group = $('#' + groupId);
            $group.find('.type-btn').each(function () {
                const $b = $(this);
                if ($b.data('type') === type) {
                    $b.css({ background: $b.data('color'), color: '#fff' }).addClass('active');
                    $('#' + hiddenTypeId).val(type);
                    $('#' + hiddenColorId).val($b.data('color'));
                } else {
                    $b.css({ background: '#eee', color: '#555' }).removeClass('active');
                }
            });
        }

        // ── Active-filter set ─────────────────────────────────────────
        const activeFilters = new Set(['training', 'event', 'leave', 'holiday', 'other']);

        $('.filter-toggle').on('click', function () {
            const type = $(this).data('type');
            if (activeFilters.has(type)) {
                activeFilters.delete(type);
                $(this).css('opacity', '0.45').removeClass('active');
            } else {
                activeFilters.add(type);
                $(this).css('opacity', '1').addClass('active');
            }
            calendar.refetchEvents();
        });

        // ── Tooltip ──────────────────────────────────────────────────
        const $tip = $('#calEventTooltip');
        let   tipTimeout;

        function showTip(jsEvent, content) {
            clearTimeout(tipTimeout);
            $tip.html(content).removeClass('d-none');
            positionTip(jsEvent);
        }

        function hideTip() {
            tipTimeout = setTimeout(() => $tip.addClass('d-none'), 120);
        }

        function positionTip(jsEvent) {
            const x = jsEvent.pageX + 12;
            const y = jsEvent.pageY - 10;
            const vw = $(window).width();
            const pw = $tip.outerWidth() || 260;
            $tip.css({
                top:  y + 'px',
                left: (x + pw > vw ? x - pw - 24 : x) + 'px'
            });
        }

        $(document).on('mousemove', '#calEventTooltip', function (e) {
            clearTimeout(tipTimeout);
            positionTip(e);
        });
        $(document).on('mouseleave', '#calEventTooltip', hideTip);

        // ── FullCalendar init ─────────────────────────────────────────
        let calendar;

        document.addEventListener('DOMContentLoaded', function () {

            const calEl = document.getElementById('calendar');
            calendar = new FullCalendar.Calendar(calEl, {

                headerToolbar: {
                    left:   'prev,next today',
                    center: 'title',
                    right:  'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
                },
                initialView:  'dayGridMonth',
                height:       'auto',
                navLinks:     true,
                selectable:   true,
                dayMaxEvents: 4,

                // ── Event source ──────────────────────────────────────
                events: function (fetchInfo, successCb, failureCb) {
                    $.ajax({
                        url: 'api/fetch_hrd_calendar.php',
                        data: {
                            start: fetchInfo.startStr,
                            end:   fetchInfo.endStr
                        },
                        success: function (data) {
                            // Filter by activeFilters
                            const filtered = data.filter(ev => {
                                // Fallback to 'source' if 'activity_type' is missing
                                const type = ev.extendedProps 
                                    ? (ev.extendedProps.activity_type || ev.extendedProps.source || 'event') 
                                    : 'event';
                                return activeFilters.has(type);
                            });
                            successCb(filtered);
                        },
                        error: function () {
                            toast('error', 'Failed to load calendar events.');
                            failureCb();
                        }
                    });
                },

                // ── Date click → pre-fill Add modal ──────────────────
                dateClick: function (info) {
                    openAddModal(info.dateStr);
                },

                // ── Event click ───────────────────────────────────────
                eventClick: function (info) {
                    const props  = info.event.extendedProps || {};
                    const source = props.source || 'custom';

                    if (source === 'training') {
                        openViewTraining(info.event, props);
                    } else {
                        openEditModal(info.event, props);
                    }
                },

                // ── Hover tooltip ─────────────────────────────────────
                eventMouseEnter: function (info) {
                    const props  = info.event.extendedProps || {};
                    const source = props.source || 'custom';
                    let   html   = `<div class="pop-title">${escHtml(info.event.title)}</div>`;

                    if (source === 'training') {
                        html += row('bi bi-calendar3',     props.display_date || '—');
                        html += row('bi bi-geo-alt',       props.location     || '—');
                        html += row('bi bi-display',       props.mode         || '—');
                        html += row('bi bi-people',        props.slots        || '—');
                        html += row('bi bi-cash',          props.fee          || '—');
                        html += row('bi bi-clock',         (props.no_hrs || 0) + ' hrs');
                        html += `<div class="pop-row mt-2">
                                    <span class="badge" style="background:${info.event.backgroundColor};">
                                        ${escHtml(props.status_trng || 'Training')}
                                    </span>
                                 </div>`;
                    } else {
                        if (props.description) html += row('bi bi-card-text', props.description);
                        html += row('bi bi-arrow-repeat', capitalise(props.recurrence_type || 'none'));
                        html += `<div class="pop-row mt-2 text-muted" style="font-size:.7rem;">
                                    Click to edit
                                 </div>`;
                    }

                    showTip(info.jsEvent, html);
                },
                eventMouseLeave: hideTip,

                // ── Style events ──────────────────────────────────────
                eventDidMount: function (info) {
                    const src = (info.event.extendedProps || {}).source || 'custom';
                    if (src === 'training') {
                        info.el.classList.add('fc-event-training');
                        info.el.setAttribute('title', ''); // prevent browser tooltip
                    } else {
                        info.el.classList.add('fc-event-custom');
                    }
                },
            });

            calendar.render();

            // ── Today + Print buttons ─────────────────────────────────
            $('#btnToday').on('click', () => calendar.today());
            $('#btnPrint').on('click', () => window.print());
        });

        // ── Tooltip helpers ───────────────────────────────────────────
        function row(icon, text) {
            return `<div class="pop-row"><i class="bi ${icon}"></i> ${escHtml(String(text))}</div>`;
        }
        function escHtml(s) {
            return String(s || '')
                .replace(/&/g,'&amp;').replace(/</g,'&lt;')
                .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
        function capitalise(s) {
            return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
        }

        // ================================================================
        // OPEN ADD MODAL
        // ================================================================
        window.openAddModal = function (dateStr) {
            // Reset form
            $('#addEventForm')[0].reset();
            $('#addActivityType').val('event');
            $('#addColor').val('#2196F3');

            // Restore type buttons
            $('#addTypeGroup .type-btn').each(function () {
                const $b = $(this);
                if ($b.data('type') === 'event') {
                    $b.css({ background: $b.data('color'), color: '#fff' }).addClass('active');
                } else {
                    $b.css({ background: '#eee', color: '#555' }).removeClass('active');
                }
            });

            // Pre-fill date
            if (dateStr) {
                const base = dateStr.length === 10 ? dateStr + 'T08:00' : dateStr;
                $('#addStart').val(base);
                const endDt = new Date(base);
                endDt.setHours(endDt.getHours() + 1);
                $('#addEnd').val(fmtDatetimeLocal(endDt));
            } else {
                $('#addStart').val(defaultDT(8));
                $('#addEnd').val(defaultDT(9));
            }

            new bootstrap.Modal(document.getElementById('addEventModal')).show();
        };

        // ── Save new event ────────────────────────────────────────────
        $('#btnSaveEvent').on('click', function () {
            const title = $('#addTitle').val().trim();
            const start = $('#addStart').val();
            const end   = $('#addEnd').val();

            if (!title) { toast('warning', 'Please enter a title.'); return; }
            if (!start) { toast('warning', 'Please select a start date.'); return; }
            if (!end)   { toast('warning', 'Please select an end date.'); return; }
            if (start > end) { toast('warning', 'End must be after Start.'); return; }

            const payload = {
                title:           title,
                description:     $('#addDescription').val().trim(),
                start:           start,
                end:             end,
                all_day:         $('#addAllDay').is(':checked') ? 1 : 0,
                color:           $('#addColor').val(),
                text_color:      '#ffffff',
                activity_type:   $('#addActivityType').val(),
                recurrence_type: $('#addRecurrence').val(),
            };

            $.ajax({ url: 'api/add_hrd_calendar.php', type: 'POST', dataType: 'json',
                data: { ...payload, _csrf: window.csrfToken ?? '' },
                headers: { 'X-CSRF-Token': window.csrfToken ?? '' } })
                .done(function (res) {
                    if (res.success) {
                        bootstrap.Modal.getInstance(
                            document.getElementById('addEventModal')
                        ).hide();
                        calendar.refetchEvents();
                        toast('success', 'Event saved!');
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                })
                .fail(() => Swal.fire('Error', 'Request failed. Try again.', 'error'));
        });

        // ================================================================
        // OPEN VIEW-TRAINING MODAL (read-only)
        // ================================================================
        function openViewTraining(event, props) {
            $('#vtModalTitle').text(event.title);
            $('#vtDate').text(props.display_date || '—');
            $('#vtLocation').text(props.location  || '—');
            $('#vtMode').text(props.mode           || '—');
            $('#vtSlots').text(props.slots         || '—');
            $('#vtFee').text(props.fee             || '—');
            $('#vtHours').text((props.no_hrs || 0) + ' training hour(s)');
            $('#vtTypeBadge').text(capitalise(props.training_type || 'Training'));

            // Status badge
            const statusColors = {
                'ongoing':   '#1565C0',
                'completed': '#546E7A',
                'cancelled': '#E53935',
                'open':      '#2E7D32',
            };
            const sc = statusColors[(props.status_trng || '').toLowerCase()] || '#2E7D32';
            $('#vtModalBadge')
                .text(props.status_trng || 'Open')
                .css('background', sc + '44');

            // Header colour = event colour
            document.querySelector('#viewTrainingModal .modal-header')
                    .style.background = event.backgroundColor || '#2E7D32';

            // Link to participants
            const tid = props.training_id;
            if (tid) {
                $('#vtParticipantsLink')
                    .attr('href', 'participants?id=' + tid)
                    .show();
            } else {
                $('#vtParticipantsLink').hide();
            }

            new bootstrap.Modal(document.getElementById('viewTrainingModal')).show();
        }

        // ================================================================
        // OPEN EDIT MODAL
        // ================================================================
        function openEditModal(event, props) {
            const dbId = props.db_id;
            if (!dbId) { toast('error', 'Cannot identify event ID.'); return; }

            $('#editEventId').val(dbId);
            $('#editOccurrenceDate').val(props.occurrence_date || '');
            $('#editTitle').val(event.title || '');
            $('#editDescription').val(props.description || '');
            $('#editAllDay').prop('checked', event.allDay || false);
            $('#editRecurrence').val(props.recurrence_type || 'none');

            // Dates
            const startDt = event.start ? fmtDatetimeLocal(event.start) : '';
            const endDt   = event.end   ? fmtDatetimeLocal(event.end)   : startDt;
            $('#editStart').val(startDt);
            $('#editEnd').val(endDt);

            // Activate correct type button
            const type = props.activity_type || 'event';
            setActiveTypeBtn('editTypeGroup', 'editActivityType', 'editColor', type);
            // Also update hidden color from current event
            if (event.backgroundColor) $('#editColor').val(event.backgroundColor);

            new bootstrap.Modal(document.getElementById('editEventModal')).show();
        }

        // ── Update event ──────────────────────────────────────────────
        $('#btnUpdateEvent').on('click', function () {
            const id    = $('#editEventId').val();
            const title = $('#editTitle').val().trim();
            const start = $('#editStart').val();
            const end   = $('#editEnd').val();

            if (!title) { toast('warning', 'Title is required.'); return; }
            if (!start) { toast('warning', 'Start date is required.'); return; }
            if (!end)   { toast('warning', 'End date is required.'); return; }
            if (start > end) { toast('warning', 'End must be after Start.'); return; }

            const payload = {
                id,
                title,
                description:     $('#editDescription').val().trim(),
                start,
                end,
                all_day:         $('#editAllDay').is(':checked') ? 1 : 0,
                color:           $('#editColor').val(),
                text_color:      '#ffffff',
                activity_type:   $('#editActivityType').val(),
                recurrence_type: $('#editRecurrence').val(),
            };

            $.ajax({ url: 'api/update_hrd_calendar.php', type: 'POST', dataType: 'json',
                data: { ...payload, _csrf: window.csrfToken ?? '' },
                headers: { 'X-CSRF-Token': window.csrfToken ?? '' } })
                .done(function (res) {
                    if (res.success) {
                        bootstrap.Modal.getInstance(
                            document.getElementById('editEventModal')
                        ).hide();
                        calendar.refetchEvents();
                        toast('success', 'Event updated!');
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                })
                .fail(() => Swal.fire('Error', 'Request failed.', 'error'));
        });

        // ── Delete event ──────────────────────────────────────────────
        $('#btnDeleteEvent').on('click', function () {
            const id             = $('#editEventId').val();
            const recurrenceType = $('#editRecurrence').val();

            if (recurrenceType !== 'none') {
                // Offer single vs all future
                Swal.fire({
                    title: 'Delete Recurring Event',
                    html:  'Do you want to delete <b>this occurrence only</b> or the <b>entire series</b>?',
                    icon:  'warning',
                    showDenyButton:    true,
                    showCancelButton:  true,
                    confirmButtonText: 'Delete This Only',
                    denyButtonText:    'Delete Entire Series',
                    cancelButtonText:  'Cancel',
                }).then(result => {
                    if (result.isConfirmed) {
                        doDelete(id, 'single');
                    } else if (result.isDenied) {
                        doDelete(id, 'future');
                    }
                });
            } else {
                confirm('Delete Event?',
                    'This action <b>cannot be undone</b>.',
                    '<i class="bi bi-trash-fill"></i> Delete')
                .then(res => {
                    if (res.isConfirmed) doDelete(id, 'single');
                });
            }
        });

        function doDelete(id, deleteType) {
            const occurrenceDate = $('#editOccurrenceDate').val();
            $.ajax({ url: 'api/delete_hrd_calendar.php', type: 'POST', dataType: 'json',
                data: { id, deleteType, occurrence_date: occurrenceDate, _csrf: window.csrfToken ?? '' },
                headers: { 'X-CSRF-Token': window.csrfToken ?? '' } })
                .done(function (res) {
                    if (res.success) {
                        bootstrap.Modal.getInstance(
                            document.getElementById('editEventModal')
                        ).hide();
                        calendar.refetchEvents();
                        toast('success', res.message || 'Event deleted.');
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                })
                .fail(() => Swal.fire('Error', 'Request failed.', 'error'));
        }

    }()); // IIFE end

    // ── Digital clock ─────────────────────────────────────────────────
    function startTime() {
        const now  = new Date();
        let hr     = now.getHours();
        const min  = String(now.getMinutes()).padStart(2, '0');
        const sec  = String(now.getSeconds()).padStart(2, '0');
        const ap   = hr < 12 ? 'AM' : 'PM';
        hr = hr === 0 ? 12 : hr > 12 ? hr - 12 : hr;
        document.getElementById('clock').innerHTML =
            `${String(hr).padStart(2,'0')}:${min}:${sec} <span>${ap}</span>`;
        setTimeout(startTime, 500);
    }
    </script>

</body>
</html>