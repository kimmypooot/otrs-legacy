<?php
/**
 * otrs/hrd/inc/training_dates.php
 *
 * Single source of truth for "what are this training's actual days".
 *
 * If otrs_training_dates has rows for a training, those explicit dates are
 * authoritative (custom / non-consecutive schedule). If it has no rows, the
 * training's days are derived from start_date..end_date (legacy range mode) —
 * exactly the behavior every call site had before this file existed.
 *
 * In both cases, any date present in otrs_training_suspensions for that
 * training is removed, and day_number is re-ranked 1..N over the remaining
 * dates in ascending order.
 */

if (!function_exists('getTrainingDates')) {
    function getTrainingDates(PDO $conn, int $trainingId): array
    {
        // 1. Base date set: custom dates if present, else the legacy range.
        $stmtCustom = $conn->prepare(
            "SELECT training_date FROM otrs_training_dates
             WHERE training_id = :tid ORDER BY training_date ASC"
        );
        $stmtCustom->execute([':tid' => $trainingId]);
        $customDates = array_column($stmtCustom->fetchAll(PDO::FETCH_ASSOC), 'training_date');

        if (!empty($customDates)) {
            $baseDates = $customDates;
        } else {
            $stmtT = $conn->prepare(
                "SELECT start_date, end_date FROM otrs_trainings WHERE id = :tid LIMIT 1"
            );
            $stmtT->execute([':tid' => $trainingId]);
            $training = $stmtT->fetch(PDO::FETCH_ASSOC);

            $baseDates = [];
            if ($training && !empty($training['start_date']) && $training['start_date'] !== '0000-00-00') {
                $endRaw = (!empty($training['end_date']) && $training['end_date'] !== '0000-00-00')
                    ? $training['end_date'] : $training['start_date'];
                $cursor = new DateTime($training['start_date']);
                $last   = new DateTime($endRaw);
                while ($cursor <= $last) {
                    $baseDates[] = $cursor->format('Y-m-d');
                    $cursor->modify('+1 day');
                }
            }
        }

        if (empty($baseDates)) {
            return [];
        }

        // 2. Remove suspended dates.
        $stmtS = $conn->prepare(
            "SELECT suspended_date FROM otrs_training_suspensions WHERE training_id = :tid"
        );
        $stmtS->execute([':tid' => $trainingId]);
        $suspended = array_map('strval', array_column($stmtS->fetchAll(PDO::FETCH_ASSOC), 'suspended_date'));

        $remaining = array_values(array_diff($baseDates, $suspended));
        sort($remaining);

        // 3. Re-rank day_number 1..N.
        $result = [];
        foreach ($remaining as $i => $date) {
            $result[] = ['date' => $date, 'day_number' => $i + 1];
        }
        return $result;
    }
}

if (!function_exists('getTrainingDateStrings')) {
    function getTrainingDateStrings(PDO $conn, int $trainingId): array
    {
        return array_column(getTrainingDates($conn, $trainingId), 'date');
    }
}
