<?php
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../includes/bootstrap.php';

if (strtolower($_SESSION['type'] ?? '') !== 'hrd') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$csrfToken = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$trainingId = isset($_POST['training_id']) ? (int)$_POST['training_id'] : 0;
$filterType = isset($_POST['filter_type']) ? trim($_POST['filter_type']) : '';
$label      = isset($_POST['label'])       ? trim(strip_tags($_POST['label'])) : 'Participants';

if ($trainingId < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid training ID.']);
    exit;
}

/* Graduate base — mirrors get_training_analytics.php $baseWhere exactly */
$baseGraduate = "r.training_id = :tid
    AND r.registration_status != 'cancelled'
    AND r.payment_status IN ('paid', 'promissory note', 'free')";

$params      = [':tid' => $trainingId];
$extraWhere  = [];

/* ── Gender helper ─────────────────────────────────────────── */
function applyGender(string $gender, array &$extraWhere, array &$params): void
{
    if ($gender === 'Male' || $gender === 'Female') {
        $extraWhere[]       = 'p.gender = :gender';
        $params[':gender']  = $gender;
    } elseif ($gender === 'Others') {
        $extraWhere[] = "p.gender NOT IN ('Male', 'Female')";
    }
    /* 'all' → no clause */
}

try {
    switch ($filterType) {

        /* ── B. Pax Breakdown (Section B) ──────────────────── */
        case 'graduate_pax': {
            $gender = trim($_POST['gender']          ?? 'all');
            $cat    = trim($_POST['participant_cat'] ?? 'all');

            applyGender($gender, $extraWhere, $params);

            $validCats = ['cs', 'nc_gov', 'nc_other', 'pwd_cs', 'all'];
            if (!in_array($cat, $validCats, true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid participant category.']);
                exit;
            }

            if ($cat === 'cs') {
                $extraWhere[] = "p.civil_servant = 'CS'";
            } elseif ($cat === 'nc_gov') {
                $extraWhere[] = "p.civil_servant = 'Non-CS'";
                $extraWhere[] = "p.sector IN ('NGA','GOCC','SUC','LWD','LGU')";
            } elseif ($cat === 'nc_other') {
                $extraWhere[] = "p.civil_servant = 'Non-CS'";
                $extraWhere[] = "p.sector NOT IN ('NGA','GOCC','SUC','LWD','LGU')";
            } elseif ($cat === 'pwd_cs') {
                $extraWhere[] = "p.civil_servant = 'CS'";
                $extraWhere[] = "p.pwd = 'Yes'";
            }
            /* 'all' → no additional clause */
            break;
        }

        /* ── C. Position Level (Section C) ─────────────────── */
        case 'graduate_position': {
            $posLevel = trim($_POST['position_level'] ?? 'all');
            $gender   = trim($_POST['gender']         ?? 'all');

            $extraWhere[] = "p.civil_servant = 'CS'";

            $validLevels = [
                'First Level',
                'Second Level (Rank-and-File)',
                'Second Level (Non-Supervisory)',
                'Second Level (Supervisory)',
                'Second Level (Executive/Managerial)',
                'Third Level',
            ];
            if ($posLevel !== 'all') {
                if (!in_array($posLevel, $validLevels, true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid position level.']);
                    exit;
                }
                $extraWhere[]              = 'p.position_level = :position_level';
                $params[':position_level'] = $posLevel;
            }

            applyGender($gender, $extraWhere, $params);
            break;
        }

        /* ── D. Sector Breakdown (Section D) ───────────────── */
        case 'graduate_sector': {
            $sector = trim($_POST['sector'] ?? 'all');
            $gender = trim($_POST['gender'] ?? 'all');

            $extraWhere[] = "p.civil_servant = 'CS'";

            $validSectors = ['NGA', 'GOCC', 'SUC', 'LWD', 'LGU'];
            if ($sector !== 'all') {
                if (!in_array($sector, $validSectors, true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid sector.']);
                    exit;
                }
                $extraWhere[]       = 'p.sector = :sector';
                $params[':sector']  = $sector;
            } else {
                /* all gov sectors */
                $extraWhere[] = "p.sector IN ('NGA','GOCC','SUC','LWD','LGU')";
            }

            applyGender($gender, $extraWhere, $params);
            break;
        }

        /* ── E. Collection Summary (Section E) ─────────────── */
        case 'collection': {
            $statusFilter = trim(strtolower($_POST['status_filter'] ?? ''));
            $validStatuses = ['paid', 'pending', 'promissory note', 'free', 'cancelled', 'not qualified'];

            if (!in_array($statusFilter, $validStatuses, true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid status filter.']);
                exit;
            }

            /* Collection uses all registrations, not just graduates */
            $baseGraduate = 'r.training_id = :tid';

            if ($statusFilter === 'cancelled') {
                $extraWhere[] = "r.registration_status = 'cancelled'";
            } elseif ($statusFilter === 'not qualified') {
                $extraWhere[] = "r.registration_status = 'not qualified'";
            } else {
                $extraWhere[] = "r.payment_status = :payment_status";
                $extraWhere[] = "r.registration_status NOT IN ('cancelled', 'not qualified')";
                $params[':payment_status'] = $statusFilter;
            }
            break;
        }

        default: {
            echo json_encode(['success' => false, 'message' => 'Invalid filter type.']);
            exit;
        }
    }

    $whereSQL = $baseGraduate;
    if (!empty($extraWhere)) {
        $whereSQL .= ' AND ' . implode(' AND ', $extraWhere);
    }

    $stmt = $conn->prepare("
        SELECT
            p.id,
            CONCAT(
                p.lname, ', ', p.fname,
                CASE WHEN p.mname  IS NOT NULL AND p.mname  != '' THEN CONCAT(' ', p.mname)  ELSE '' END,
                CASE WHEN p.suffix IS NOT NULL AND p.suffix != '' THEN CONCAT(' ', p.suffix) ELSE '' END
            ) AS full_name,
            p.gender,
            p.position_title  AS position,
            p.salary_grade,
            p.position_level,
            p.agency_name     AS agency,
            p.sector,
            p.location,
            r.payment_status,
            r.registration_status
        FROM otrs_registrations r
        JOIN otrs_participants  p ON r.participant_id = p.id
        WHERE {$whereSQL}
        ORDER BY p.lname, p.fname
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $participants = array_map(fn($p) => [
        'id'                  => (int)$p['id'],
        'full_name'           => htmlspecialchars($p['full_name']           ?? '', ENT_QUOTES),
        'gender'              => htmlspecialchars($p['gender']              ?? '', ENT_QUOTES),
        'position'            => htmlspecialchars($p['position']            ?? '', ENT_QUOTES),
        'salary_grade'        => htmlspecialchars($p['salary_grade']        ?? '', ENT_QUOTES),
        'position_level'      => htmlspecialchars($p['position_level']      ?? '', ENT_QUOTES),
        'agency'              => htmlspecialchars($p['agency']              ?? '', ENT_QUOTES),
        'sector'              => htmlspecialchars($p['sector']              ?? '', ENT_QUOTES),
        'location'            => htmlspecialchars($p['location']            ?? '', ENT_QUOTES),
        'payment_status'      => htmlspecialchars($p['payment_status']      ?? '', ENT_QUOTES),
        'registration_status' => htmlspecialchars($p['registration_status'] ?? '', ENT_QUOTES),
    ], $rows);

    echo json_encode([
        'success'      => true,
        'label'        => $label,
        'filter_type'  => $filterType,
        'count'        => count($participants),
        'participants' => $participants,
    ]);

} catch (PDOException $e) {
    error_log('get_count_participants.php PDO error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred.']);
}
