<?php
echo '<form id="participantForm">';
echo '<table id="participantsList" class="table table-bordered table-hover table-striped table-responsive" style="font-size: 14px; width: 100%">';
echo '<thead><tr>
        <th style="text-align: center; vertical-align: middle;"><input style="transform: scale(1.8);" type="checkbox" id="checkAll"></th>
        <th style="text-align: center; vertical-align: middle;">Action</th>
        <th style="text-align: center; vertical-align: middle;">#</th>
        <th style="text-align: center; vertical-align: middle;">Name of Participant</th>
        <th style="text-align: center; vertical-align: middle;">Gender</th>
        <th style="text-align: center; vertical-align: middle;">Age</th>
        <th style="text-align: center; vertical-align: middle;">Civil Status</th>
        <th style="text-align: center; vertical-align: middle;">Position Title</th>
        <th style="text-align: center; vertical-align: middle;">Salary Grade</th>
        <th style="text-align: center; vertical-align: middle;">Home Address</th>
        <th style="text-align: center; vertical-align: middle;">Agency/Office</th>
        <th style="text-align: center; vertical-align: middle;">Agency Address</th>
        <th style="text-align: center; vertical-align: middle;">Email Address</th>
        <th style="text-align: center; vertical-align: middle;">Contact No.</th>
        <th style="text-align: center; vertical-align: middle;">FO</th>
        <th style="text-align: center; vertical-align: middle;">Status</th>
        <th style="text-align: center; vertical-align: middle;">OR No.</th>
        <th style="text-align: center; vertical-align: middle;">Date of OR</th>
        <th style="text-align: center; vertical-align: middle;">Collecting Officer</th>';

if ($training['mode'] == "Face to Face") {
    echo '<th style="text-align: center; vertical-align: middle;">Food Restrictions</th>';
}

echo '<th style="text-align: center; vertical-align: middle;">Remarks</th>
      <th style="text-align: center; vertical-align: middle;">Training Status</th>
      <th style="text-align: center; vertical-align: middle;">Reg Date &amp; Time</th>
    </tr></thead><tbody>';

$count = 0;
foreach ($participants as $participant) {
    $count++;
    $participantId = $participant['id'];
    $sfx      = !empty(trim($participant['suffix'] ?? '')) ? ', ' . trim($participant['suffix']) : '';
    $name     = htmlspecialchars($participant['fname'] . ' ' . $participant['mname'] . ' ' . $participant['lname'] . $sfx);
    $fullName = htmlspecialchars($participant['lname'] . ', ' . $participant['fname'] . ' ' . $participant['mname'] . $sfx);
    
    echo '<tr>';
    echo '<td style="text-align: center">';
    echo '<input style="transform: scale(1.8);" type="checkbox" name="participants[]" value="' . $participantId . '" 
          class="participant-checkbox" data-participant-id="' . $participantId . '" 
          data-status="' . htmlspecialchars($participant['status']) . '" 
          data-name="' . $fullName . '">';
    echo '</td>';
    
    echo '<td style="text-align: center">';
    if (strtolower(trim($participant['status'])) === 'cancelled') {
        echo '<a type="button" class="btn btn-danger btn-sm" href="#" onclick="deleteParticipant(' . $participantId . ')" 
              data-bs-toggle="tooltip" data-bs-placement="right" title="Delete Participant"><i class="bi bi-trash"></i></a>';
    } else {
        echo '<a type="button" class="btn btn-primary btn-sm" href="#" onclick="showUpdateModal(' . $participantId . ')" 
              data-bs-toggle="tooltip" data-bs-placement="left" title="Update Participant"><i class="bi bi-pencil-square"></i></a> ';
        echo '<a type="button" class="btn btn-danger btn-sm" href="#" onclick="deleteParticipant(' . $participantId . ')" 
              data-bs-toggle="tooltip" data-bs-placement="right" title="Delete Participant"><i class="bi bi-trash"></i></a>';
    }
    echo '</td>';
    
    echo '<td>' . $count . '</td>';
    echo '<td>' . $name . '</td>';
    echo '<td>' . htmlspecialchars($participant['gender']) . '</td>';
    echo '<td>' . htmlspecialchars($participant['age']) . '</td>';
    echo '<td>' . htmlspecialchars($participant['civilstatus']) . '</td>';
    echo '<td>' . htmlspecialchars($participant['positiontitle']) . '</td>';
    echo '<td>' . htmlspecialchars($participant['salary_grade']) . '</td>';
    echo '<td>' . htmlspecialchars($participant['home_address']) . '</td>';
    echo '<td>' . htmlspecialchars($participant['agency_name']) . '</td>';
    echo '<td>' . htmlspecialchars($participant['agency_address']) . '</td>';
    echo '<td>' . htmlspecialchars($participant['email']) . '</td>';
    echo '<td>' . htmlspecialchars($participant['mobileno']) . '</td>';
    echo '<td>' . htmlspecialchars($participant['location']) . '</td>';
    
    // Status badge
    $statusBadges = [
        'pending'         => 'bg-primary',
        'cancelled'       => 'bg-danger',
        'not qualified'   => 'bg-danger',
        'paid'            => 'bg-success',
        'promissory note' => 'bg-warning',
        'free'            => 'bg-info',
    ];
    $statusKey   = strtolower(trim($participant['status']));
    $statusBadge = $statusBadges[$statusKey] ?? 'bg-secondary';

    echo '<td><span class="badge ' . $statusBadge . '">' . htmlspecialchars(ucwords($statusKey)) . '</span></td>';
    echo '<td>' . htmlspecialchars($participant['orno']) . '</td>';
    echo '<td>' . htmlspecialchars($participant['ordate']) . '</td>';
    echo '<td>' . htmlspecialchars($participant['collecting_officer']) . '</td>';
    
    if ($training['mode'] == "Face to Face") {
        echo '<td>' . htmlspecialchars($participant['food_restrictions']) . '</td>';
    }
    
    echo '<td>' . htmlspecialchars($participant['remarks']) . '</td>';
    
    // Training status badge
    $trainingStatusBadges = [
        'completed'      => 'bg-success',
        'not completed'  => 'bg-primary',
        'for compliance' => 'bg-danger',
    ];
    $trainingKey         = strtolower(trim($participant['status_training']));
    $trainingStatusBadge = $trainingStatusBadges[$trainingKey] ?? 'bg-secondary';

    echo '<td><span class="badge ' . $trainingStatusBadge . '">' . htmlspecialchars(ucwords($trainingKey)) . '</span></td>';
    echo '<td>' . htmlspecialchars($participant['timereg']) . '</td>';
    echo '</tr>';
}

echo '</tbody></table></form>';
?>