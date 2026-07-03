<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

$authResult = adminAuthenticateRequest();
if (!$authResult['authenticated']) {
    $data = [
        'status' => $authResult['status'],
        'message' => $authResult['message']
    ];
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode($data);
    exit;
}

if ($requestMethod === 'POST') {
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];
    $userId = $authResult['userId'];
    $userType = $authResult['user_type'];

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!$payload) {
        $data = ['status' => 400, 'message' => 'Invalid JSON payload'];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $class = isset($payload['class']) ? $payload['class'] : null;
    $section = isset($payload['section']) ? $payload['section'] : null;
    $subjectRepeatData = (isset($payload['subjectRepeatData']) && is_array($payload['subjectRepeatData'])) ? $payload['subjectRepeatData'] : [];
    $intent = isset($_GET['intent']) ? strtolower(trim($_GET['intent'])) : 'generate';
    if ($intent !== 'generate' && $intent !== 're-generate' && $intent !== 'new-regenerate') {
        $data = ['status' => 400, 'message' => 'Invalid intent parameter'];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }
    $generateType = 'week';
    $regenDay = null;
    $regenDayNormalized = null;
    if ($intent === 're-generate') {
        if (!isset($_GET['generateType'])) {
            $data = ['status' => 400, 'message' => 'Missing generate type parameter'];
            header("HTTP/1.0 400 Bad Request");
            echo json_encode($data);
            exit;
        }
        $generateType = strtolower(trim($_GET['generateType']));
        if ($generateType !== 'week' && $generateType !== 'day') {
            $data = ['status' => 400, 'message' => 'Invalid generate type parameter'];
            header("HTTP/1.0 400 Bad Request");
            echo json_encode($data);
            exit;
        }
        if ($generateType === 'day') {
            $regenDay = trim(isset($payload['day']) ? $payload['day'] : (isset($payload['data']) ? $payload['data'] : ''));
            $regenDayNormalized = strtolower(trim($regenDay));
            if ($regenDay === '') {
                $data = ['status' => 400, 'message' => 'Missing day parameter for day regeneration'];
                header("HTTP/1.0 400 Bad Request");
                echo json_encode($data);
                exit;
            }
        }
    }
    $fullDays = isset($payload['fullDays']) ? $payload['fullDays'] : [];
    $halfDays = isset($payload['halfDays']) ? $payload['halfDays'] : [];

    if (!$class || !$section) {
        $data = ['status' => 400, 'message' => 'class and section are required'];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    if ($intent === 'generate' || $intent === 'new-regenerate') {
        $fullDaysJson = json_encode(array_values($fullDays));
        $halfDaysJson = json_encode(array_values($halfDays));
        $repeatsJson = json_encode(array_values($subjectRepeatData));

        if ($intent === 'generate') {
            $payloadStmt = $conn->prepare(
                "INSERT INTO time_table_payload (inst_id, `class`, `section`, full_days, half_days, repeats) VALUES (?, ?, ?, ?, ?, ?)"
            );
            if (!$payloadStmt) {
                $data = ['status' => 500, 'message' => 'Failed to prepare payload insert statement'];
                header("HTTP/1.0 500 Internal Server Error");
                echo json_encode($data);
                exit;
            }

            $payloadStmt->bind_param('ssssss', $instituteId, $class, $section, $fullDaysJson, $halfDaysJson, $repeatsJson);
            if (!$payloadStmt->execute()) {
                $data = ['status' => 500, 'message' => 'Failed to save timetable payload'];
                header("HTTP/1.0 500 Internal Server Error");
                echo json_encode($data);
                exit;
            }
        } else {
            $payloadExistsStmt = $conn->prepare(
                "SELECT id FROM time_table_payload WHERE inst_id = ? AND `class` = ? AND `section` = ? LIMIT 1"
            );
            if (!$payloadExistsStmt) {
                $data = ['status' => 500, 'message' => 'Failed to prepare payload existence check'];
                header("HTTP/1.0 500 Internal Server Error");
                echo json_encode($data);
                exit;
            }
            $payloadExistsStmt->bind_param('sss', $instituteId, $class, $section);
            $payloadExistsStmt->execute();
            $resExists = $payloadExistsStmt->get_result();

            if ($resExists && $resExists->num_rows > 0) {
                $payloadUpdateStmt = $conn->prepare(
                    "UPDATE time_table_payload SET full_days = ?, half_days = ?, repeats = ? WHERE inst_id = ? AND `class` = ? AND `section` = ?"
                );
                if (!$payloadUpdateStmt) {
                    $data = ['status' => 500, 'message' => 'Failed to prepare payload update statement'];
                    header("HTTP/1.0 500 Internal Server Error");
                    echo json_encode($data);
                    exit;
                }

                $payloadUpdateStmt->bind_param('ssssss', $fullDaysJson, $halfDaysJson, $repeatsJson, $instituteId, $class, $section);
                if (!$payloadUpdateStmt->execute()) {
                    $data = ['status' => 500, 'message' => 'Failed to update timetable payload'];
                    header("HTTP/1.0 500 Internal Server Error");
                    echo json_encode($data);
                    exit;
                }
            } else {
                $payloadInsertStmt = $conn->prepare(
                    "INSERT INTO time_table_payload (inst_id, `class`, `section`, full_days, half_days, repeats) VALUES (?, ?, ?, ?, ?, ?)"
                );
                if (!$payloadInsertStmt) {
                    $data = ['status' => 500, 'message' => 'Failed to prepare payload insert statement'];
                    header("HTTP/1.0 500 Internal Server Error");
                    echo json_encode($data);
                    exit;
                }
                $payloadInsertStmt->bind_param('ssssss', $instituteId, $class, $section, $fullDaysJson, $halfDaysJson, $repeatsJson);
                if (!$payloadInsertStmt->execute()) {
                    $data = ['status' => 500, 'message' => 'Failed to save timetable payload'];
                    header("HTTP/1.0 500 Internal Server Error");
                    echo json_encode($data);
                    exit;
                }
            }
        }
    }

    // 1) Fetch class_wise_subjects for this inst, class & section
    $stmt = $conn->prepare("SELECT * FROM class_wise_subjects WHERE inst_id = ? AND class = ? AND section = ?");
    $stmt->bind_param('iss', $instituteId, $class, $section);
    $stmt->execute();
    $res = $stmt->get_result();
    $subjects = [];
    while ($row = $res->fetch_assoc()) {
        $subjects[] = $row;
    }
    if (count($subjects) === 0) {
        $data = ['status' => 404, 'message' => 'No subjects found for this class/section'];
        header("HTTP/1.0 404 Not Found");
        echo json_encode($data);
        exit;
    }

    // 2) Check that subject_teacher and co_teachers are assigned (not null/empty)
    foreach ($subjects as $s) {
        if (is_null($s['subject_teacher']) || trim($s['subject_teacher']) === '') {
            $data = ['status' => 422, 'message' => 'Some subjects do not have a primary teacher assigned'];
            header("HTTP/1.0 422 Unprocessable Entity");
            echo json_encode($data);
            exit;
        }
        if (is_null($s['co_teachers']) || trim($s['co_teachers']) === '') {
            // allow empty co_teachers but user requested to check for null; treat empty as okay
            // if you want to enforce co_teachers present, uncomment below
            // $data = ['status' => 422, 'message' => 'Some subjects do not have co-teachers assigned'];
            // header("HTTP/1.0 422 Unprocessable Entity"); echo json_encode($data); exit;
        }
    }

    // 3) Fetch time_slots for this inst
    $stmt = $conn->prepare("SELECT * FROM time_slots WHERE inst_id = ? ORDER BY STR_TO_DATE(start, '%h:%i %p') ASC");
    $stmt->bind_param('i', $instituteId);
    $stmt->execute();
    $res = $stmt->get_result();
    $slots = [];
    while ($row = $res->fetch_assoc()) {
        $slots[] = $row;
    }
    if (count($slots) === 0) {
        $data = ['status' => 404, 'message' => 'No time slots configured for this institute'];
        header("HTTP/1.0 404 Not Found");
        echo json_encode($data);
        exit;
    }

    $slotCount = count($slots);
    // If less than 4 slots configured for the institute, abort generation
    if ($slotCount < 4) {
        $data = ['status' => 422, 'message' => 'Only ' . $slotCount . ' slot(s) configured; please add more slots before generating timetable'];
        header("HTTP/1.0 422 Unprocessable Entity");
        echo json_encode($data);
        exit;
    }
    // Find break position
    $breakIndex = null;
    for ($i = 0; $i < count($slots); $i++) {
        if (strtolower(trim($slots[$i]['name'])) === 'break') {
            $breakIndex = $i;
            break;
        }
    }

    // Build selected days
    $periods = [];
    $selectedDays = [];

    foreach ($fullDays as $d) {
        $selectedDays[$d] = 'full';
    }

    foreach ($halfDays as $d) {
        $selectedDays[$d] = 'half';
    }

    if ($intent === 're-generate' && $generateType === 'day' && count($selectedDays) === 0 && $regenDay !== null) {
        $selectedDays[$regenDay] = 'full';
    }

    // Active slots (excluding break)
    $activeSlots = [];
    foreach ($slots as $slot) {
        if (strtolower(trim($slot['name'])) !== 'break') {
            $activeSlots[] = $slot;
        }
    }

    foreach ($selectedDays as $day => $dtype) {
        if ($dtype === 'full') {
            // Full day = all non-break periods
            foreach ($activeSlots as $slot) {
                $periods[] = [
                    'day' => $day,
                    'slot' => $slot
                ];
            }
        } else {
            if ($breakIndex !== null) {
                // Take all periods before break
                for ($i = 0; $i < $breakIndex; $i++) {
                    if (strtolower(trim($slots[$i]['name'])) === 'break') {
                        continue;
                    }

                    $periods[] = [
                        'day' => $day,
                        'slot' => $slots[$i]
                    ];
                }
            } else {
                $halfCount = floor(count($activeSlots) / 2);

                if ($halfCount < 1) {
                    $halfCount = 1;
                }

                for ($i = 0; $i < $halfCount; $i++) {

                    $periods[] = [
                        'day' => $day,
                        'slot' => $activeSlots[$i]
                    ];
                }
            }
        }
    }

    // Ensure periods are saved in chronological order within each day
    if (count($periods) > 1) {
        $dayOrder = array_keys($selectedDays);
        $dayIndex = array_flip($dayOrder);
        usort($periods, function ($a, $b) use ($dayIndex) {
            $dayA = $a['day'];
            $dayB = $b['day'];
            $dayPosA = $dayIndex[$dayA] ?? 0;
            $dayPosB = $dayIndex[$dayB] ?? 0;
            if ($dayPosA !== $dayPosB) {
                return $dayPosA <=> $dayPosB;
            }
            $timeA = strtotime($a['slot']['start']);
            $timeB = strtotime($b['slot']['start']);
            return $timeA <=> $timeB;
        });
    }

    if (count($periods) === 0) {
        $data = ['status' => 400, 'message' => 'No periods to generate based on provided days/slots'];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $dayPeriods = [];
    if ($intent === 're-generate' && $generateType === 'day') {
        foreach ($periods as $p) {
            if (strcasecmp($p['day'], $regenDay) === 0) {
                $dayPeriods[] = $p;
            }
        }
        if (count($dayPeriods) === 0) {
            $data = ['status' => 400, 'message' => 'Requested day is not included in selected days'];
            header("HTTP/1.0 400 Bad Request");
            echo json_encode($data);
            exit;
        }
    }

    // 4) Prepare subjects with constraints
    $constraints = [];
    foreach ($subjectRepeatData as $c) {
        if (!isset($c['subject'])) continue;
        $constraints[$c['subject']] = [
            'type' => isset($c['type']) ? $c['type'] : null,
            'value' => isset($c['value']) ? (int)$c['value'] : 0
        ];
    }

    $subjectMap = [];
    foreach ($subjects as $s) {
        $co = [];
        if (!empty($s['co_teachers'])) {
            $co = array_map('trim', explode(',', $s['co_teachers']));
        }
        $subName = $s['subject'];
        $subjectMap[$subName] = [
            'subject' => $subName,
            'primary' => $s['subject_teacher'],
            'co' => $co,
            'assigned' => 0,
            'min' => 0,
            'max' => PHP_INT_MAX,
            'exact' => null
        ];
        if (isset($constraints[$subName])) {
            $ct = $constraints[$subName];
            $type = strtolower(trim($ct['type']));
            $val = max(0, (int)$ct['value']);
            if ($type === 'exactly') {
                $subjectMap[$subName]['exact'] = $val;
                $subjectMap[$subName]['min'] = $val;
                $subjectMap[$subName]['max'] = $val;
            } elseif ($type === 'minimum') {
                // minimum at least $val, but cap reasonable upper bound (10)
                if ($val < 0) $val = 0;
                if ($val > 10) $val = 10;
                $subjectMap[$subName]['min'] = $val;
                $subjectMap[$subName]['max'] = 10;
            } elseif ($type === 'maximum') {
                // maximum at most $val, ensure at least 1 occurrence
                if ($val < 1) $val = 1;
                if ($val > 10) $val = 10;
                $subjectMap[$subName]['max'] = $val;
                // assume subject should appear at least once when maximum provided
                $subjectMap[$subName]['min'] = 1;
            }
        }
    }

    $totalPeriods = count($periods);
    // Validate sums
    $sumExact = 0;
    $sumMin = 0;
    foreach ($subjectMap as $m) {
        if (!is_null($m['exact'])) $sumExact += $m['exact'];
        $sumMin += $m['min'];
    }
    if ($sumExact > $totalPeriods) {
        $data = ['status' => 422, 'message' => 'Sum of Exactly constraints exceeds available periods'];
        header("HTTP/1.0 422 Unprocessable Entity");
        echo json_encode($data);
        exit;
    }
    if ($sumMin > $totalPeriods) {
        $data = ['status' => 422, 'message' => 'Sum of Minimum constraints exceeds available periods'];
        header("HTTP/1.0 422 Unprocessable Entity");
        echo json_encode($data);
        exit;
    }

    // Build assignment counts per subject
    $assignCounts = [];
    // allocate exacts
    $remaining = $totalPeriods;
    foreach ($subjectMap as $k => $m) {
        if (!is_null($m['exact'])) {
            $assignCounts[$k] = $m['exact'];
            $remaining -= $m['exact'];
        } else {
            $assignCounts[$k] = 0;
        }
    }
    // allocate mins
    foreach ($subjectMap as $k => $m) {
        if (is_null($m['exact']) && $m['min'] > 0) {
            $take = min($m['min'], $remaining);
            $assignCounts[$k] += $take;
            $remaining -= $take;
        }
    }
    // fill remaining honoring max
    $keys = array_keys($subjectMap);
    $ki = 0;
    while ($remaining > 0) {
        $k = $keys[$ki % count($keys)];
        $cur = $assignCounts[$k];
        $max = $subjectMap[$k]['max'];
        if ($cur < $max) {
            $assignCounts[$k]++;
            $remaining--;
        }
        $ki++;
        if ($ki > 1000000) break;
    }

    // Build pool using round-robin subject distribution to avoid same-subject blocks
    $pool = [];
    $remainingCounts = $assignCounts;
    while (array_sum($remainingCounts) > 0) {
        foreach ($remainingCounts as $sub => &$count) {
            if ($count > 0) {
                $pool[] = $sub;
                $count--;
            }
        }
        unset($count);
    }
    if (count($pool) !== $totalPeriods) {
        // fallback: fill with primary subject names in round-robin
        while (count($pool) < $totalPeriods) {
            foreach ($subjectMap as $k => $v) {
                if (count($pool) < $totalPeriods) $pool[] = $k;
            }
        }
    }

    $prevMap = [];
    $workPeriods = $periods;
    $workPool = $pool;
    if ($intent === 're-generate') {
        if ($generateType === 'week') {
            $prevStmt = $conn->prepare("SELECT day, time, subject FROM time_table WHERE inst_id = ? AND `class` = ? AND `section` = ?");
            $prevStmt->bind_param('iss', $instituteId, $class, $section);
            $prevStmt->execute();
            $resPrev = $prevStmt->get_result();
            while ($r = $resPrev->fetch_assoc()) {
                $key = $r['day'] . '|' . $r['time'];
                $prevMap[$key] = $r['subject'];
            }
        } else {
            $prevStmt = $conn->prepare("SELECT day, time, subject FROM time_table WHERE inst_id = ? AND `class` = ? AND `section` = ? AND TRIM(LOWER(day)) = ?");
            $prevStmt->bind_param('isss', $instituteId, $class, $section, $regenDayNormalized);
            $prevStmt->execute();
            $resPrev = $prevStmt->get_result();
            while ($r = $resPrev->fetch_assoc()) {
                $key = $r['day'] . '|' . $r['time'];
                $prevMap[$key] = $r['subject'];
            }

            $dayPool = [];
            $dayStmt = $conn->prepare("SELECT time, subject FROM time_table WHERE inst_id = ? AND `class` = ? AND `section` = ? AND TRIM(LOWER(day)) = ? ORDER BY STR_TO_DATE(SUBSTRING_INDEX(time, ' - ', 1), '%h:%i %p') ASC");
            $dayStmt->bind_param('isss', $instituteId, $class, $section, $regenDayNormalized);
            $dayStmt->execute();
            $resDay = $dayStmt->get_result();
            while ($r = $resDay->fetch_assoc()) {
                $dayPool[] = $r['subject'];
            }
            if (count($dayPool) === 0) {
                $data = ['status' => 404, 'message' => 'No existing timetable found for selected day'];
                header("HTTP/1.0 404 Not Found");
                echo json_encode($data);
                exit;
            }
            if (count($dayPool) < count($dayPeriods)) {
                $data = ['status' => 422, 'message' => 'Existing day timetable has fewer periods than expected'];
                header("HTTP/1.0 422 Unprocessable Entity");
                echo json_encode($data);
                exit;
            }
            if (count($dayPool) > count($dayPeriods)) {
                $dayPool = array_slice($dayPool, 0, count($dayPeriods));
            }
            $workPeriods = $dayPeriods;
            $workPool = $dayPool;
        }
    }

    if ($intent === 're-generate') {
        if ($generateType === 'week') {
            // build previous sequence in order of periods (may contain nulls)
            $prevSequence = [];
            foreach ($periods as $p) {
                $k = $p['day'] . '|' . ($p['slot']['start'] . ' - ' . $p['slot']['end']);
                $prevSequence[] = isset($prevMap[$k]) ? $prevMap[$k] : null;
            }

            // create pool as flat list according to assignCounts (already created above)
            // try shuffling until different from previous sequence or until attempts exhausted
            $attempts = 0;
            $maxAttempts = 50;
            // ensure pool length matches periods; if not, rebuild simple pool
            if (count($pool) !== $totalPeriods) {
                $pool = [];
                foreach ($assignCounts as $sub => $cnt) {
                    for ($x = 0; $x < $cnt; $x++) $pool[] = $sub;
                }
                while (count($pool) < $totalPeriods) {
                    foreach ($subjectMap as $k => $v) {
                        if (count($pool) < $totalPeriods) $pool[] = $k;
                    }
                }
            }

            do {
                shuffle($pool);
                $attempts++;
                $different = false;
                for ($i = 0; $i < $totalPeriods; $i++) {
                    if ($prevSequence[$i] !== $pool[$i]) {
                        $different = true;
                        break;
                    }
                }
            } while (!$different && $attempts < $maxAttempts);
            $workPool = $pool;
        } else {
            $prevSequence = [];
            foreach ($workPeriods as $p) {
                $k = $p['day'] . '|' . ($p['slot']['start'] . ' - ' . $p['slot']['end']);
                $prevSequence[] = isset($prevMap[$k]) ? $prevMap[$k] : null;
            }

            $attempts = 0;
            $maxAttempts = 50;
            do {
                shuffle($workPool);
                $attempts++;
                $different = false;
                for ($i = 0; $i < count($workPeriods); $i++) {
                    if ($prevSequence[$i] !== $workPool[$i]) {
                        $different = true;
                        break;
                    }
                }
            } while (!$different && $attempts < $maxAttempts);
        }
    }

    $conn->begin_transaction();
    try {
        $insertStmt = $conn->prepare("INSERT INTO time_table (inst_id, `class`, `section`, day, period, time, subject, teacher) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $checkStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM time_table WHERE inst_id = ? AND day = ? AND time = ? AND teacher = ?");

        // If re-generating, delete previous timetable for this class/section
        if ($intent === 're-generate' || $intent === 'new-regenerate') {
            if ($intent === 'new-regenerate') {
                $delStmt = $conn->prepare("DELETE FROM time_table WHERE inst_id = ? AND `class` = ? AND `section` = ?");
                $delStmt->bind_param('iss', $instituteId, $class, $section);
            } elseif ($generateType === 'week') {
                $delStmt = $conn->prepare("DELETE FROM time_table WHERE inst_id = ? AND `class` = ? AND `section` = ?");
                $delStmt->bind_param('iss', $instituteId, $class, $section);
            } else {
                $delStmt = $conn->prepare("DELETE FROM time_table WHERE inst_id = ? AND `class` = ? AND `section` = ? AND TRIM(LOWER(day)) = ?");
                $delStmt->bind_param('isss', $instituteId, $class, $section, $regenDayNormalized);
            }
            $delStmt->execute();
        }

        $generated = [];
        $teacherRotation = [];
        // remember last teacher assigned per subject to prefer repetition when possible
        $lastAssigned = [];
        for ($i = 0; $i < count($workPeriods); $i++) {
            $p = $workPeriods[$i];
            $slot = $p['slot'];
            $day = $p['day'];
            $periodName = $slot['name'];
            $timeRange = $slot['start'] . ' - ' . $slot['end'];
            $subjectName = $workPool[$i];

            // try primary then co-teachers, preferring last assigned teacher when available,
            // otherwise fallback to rotating teachers for repeated subjects
            $teacherAssigned = null;
            $candidates = [];
            $primary = $subjectMap[$subjectName]['primary'];
            if ($primary !== null && trim($primary) !== '') $candidates[] = $primary;
            foreach ($subjectMap[$subjectName]['co'] as $ct) if ($ct !== '') $candidates[] = $ct;

            $candidateCount = count($candidates);
            // 1) try to reassign the last teacher for this subject (so teachers can repeat)
            $last = $lastAssigned[$subjectName] ?? null;
            if ($last !== null) {
                $checkStmt->bind_param('ssss', $instituteId, $day, $timeRange, $last);
                $checkStmt->execute();
                $r = $checkStmt->get_result()->fetch_assoc();
                if ($r['cnt'] == 0) {
                    $teacherAssigned = $last;
                }
            }

            // 2) if last assigned not available, use rotation over candidates
            if (is_null($teacherAssigned) && $candidateCount > 0) {
                $startIndex = $teacherRotation[$subjectName] ?? 0;
                for ($offset = 0; $offset < $candidateCount; $offset++) {
                    $index = ($startIndex + $offset) % $candidateCount;
                    $cand = $candidates[$index];
                    $checkStmt->bind_param('ssss', $instituteId, $day, $timeRange, $cand);
                    $checkStmt->execute();
                    $r = $checkStmt->get_result()->fetch_assoc();
                    if ($r['cnt'] == 0) {
                        $teacherAssigned = $cand;
                        $teacherRotation[$subjectName] = ($index + 1) % $candidateCount;
                        break;
                    }
                }
            }
            if (is_null($teacherAssigned)) {
                $teacherAssigned = 'N/A';
            }

            // record last assigned if a real teacher was assigned
            if ($teacherAssigned !== 'N/A') {
                $lastAssigned[$subjectName] = $teacherAssigned;
            }

            $insertStmt->bind_param('ssssssss', $instituteId, $class, $section, $day, $periodName, $timeRange, $subjectName, $teacherAssigned);
            $insertStmt->execute();

            $generated[] = ['day' => $day, 'period' => $periodName, 'time' => $timeRange, 'subject' => $subjectName, 'teacher' => $teacherAssigned];
        }

        $conn->commit();
        $responseMessage = 'Time table generated';
        if ($intent === 're-generate' || $intent === 'new-regenerate') {
            if ($generateType === 'week') {
                $responseMessage = 'Time table re-generated for the week';
            } else {
                $responseMessage = 'Time table re-generated for ' . $regenDay;
            }
        }

        $data = ['status' => 200, 'message' => $responseMessage];
        echo json_encode($data);
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $data = ['status' => 500, 'message' => 'Error generating timetable: ' . $e->getMessage()];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
        exit;
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
