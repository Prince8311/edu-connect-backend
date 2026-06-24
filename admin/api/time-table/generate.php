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
    // Read payload
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
    $fullDays = isset($payload['fullDays']) ? $payload['fullDays'] : [];
    $halfDays = isset($payload['halfDays']) ? $payload['halfDays'] : [];

    if (!$class || !$section) {
        $data = ['status' => 400, 'message' => 'class and section are required'];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
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
        if (is_null($s['subject_teacher']) || trim($s['subject_teacher']) === '' ) {
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
    $stmt = $conn->prepare("SELECT * FROM time_slots WHERE inst_id = ? ORDER BY start ASC");
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
    $breakIndex = null;
    for ($i = 0; $i < $slotCount; $i++) {
        if (strtolower(trim($slots[$i]['name'])) === 'break') { $breakIndex = $i; break; }
    }

    // Build list of (day, slot) to generate based on fullDays & halfDays
    $periods = [];
    $uniqueDays = [];
    foreach ($fullDays as $d) { $uniqueDays[$d] = 'full'; }
    foreach ($halfDays as $d) {
        if (isset($uniqueDays[$d]) && $uniqueDays[$d] === 'full') continue;
        $uniqueDays[$d] = 'half';
    }

    foreach ($uniqueDays as $day => $dtype) {
        if ($slotCount > 4) {
            if ($dtype === 'full') {
                // all slots except Break
                foreach ($slots as $slot) {
                    if (strtolower(trim($slot['name'])) === 'break') continue;
                    $periods[] = ['day' => $day, 'slot' => $slot];
                }
            } else { // half
                if (!is_null($breakIndex)) {
                    for ($i = 0; $i < $breakIndex; $i++) {
                        $periods[] = ['day' => $day, 'slot' => $slots[$i]];
                    }
                } else {
                    // use first half of slots (rounded down)
                    $half = (int) floor($slotCount / 2);
                    for ($i = 0; $i < $half; $i++) { $periods[] = ['day' => $day, 'slot' => $slots[$i]]; }
                }
            }
        } else {
            // 4 or less -> use all slots (except break) for both full and half
            foreach ($slots as $slot) {
                if (strtolower(trim($slot['name'])) === 'break') continue;
                $periods[] = ['day' => $day, 'slot' => $slot];
            }
        }
    }

    if (count($periods) === 0) {
        $data = ['status' => 400, 'message' => 'No periods to generate based on provided days/slots'];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
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
    $sumExact = 0; $sumMin = 0;
    foreach ($subjectMap as $m) {
        if (!is_null($m['exact'])) $sumExact += $m['exact'];
        $sumMin += $m['min'];
    }
    if ($sumExact > $totalPeriods) {
        $data = ['status' => 422, 'message' => 'Sum of Exactly constraints exceeds available periods'];
        header("HTTP/1.0 422 Unprocessable Entity"); echo json_encode($data); exit;
    }
    if ($sumMin > $totalPeriods) {
        $data = ['status' => 422, 'message' => 'Sum of Minimum constraints exceeds available periods'];
        header("HTTP/1.0 422 Unprocessable Entity"); echo json_encode($data); exit;
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
        if ($cur < $max) { $assignCounts[$k]++; $remaining--; }
        $ki++;
        if ($ki > 1000000) break;
    }

    // Build pool
    $pool = [];
    foreach ($assignCounts as $sub => $count) {
        for ($i = 0; $i < $count; $i++) $pool[] = $sub;
    }
    if (count($pool) !== $totalPeriods) {
        // fallback: fill with primary subject names in round-robin
        while (count($pool) < $totalPeriods) {
            foreach ($subjectMap as $k => $v) {
                if (count($pool) < $totalPeriods) $pool[] = $k;
            }
        }
    }

    // 5) Assign pool to periods and check teacher availability, then insert into time_table
    $conn->begin_transaction();
    try {
        $insertStmt = $conn->prepare("INSERT INTO time_table (inst_id, day, period, time, subject, teacher) VALUES (?, ?, ?, ?, ?, ?)");
        $checkStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM time_table WHERE inst_id = ? AND time = ? AND teacher = ?");

        $generated = [];
        for ($i = 0; $i < $totalPeriods; $i++) {
            $p = $periods[$i];
            $slot = $p['slot'];
            $day = $p['day'];
            $periodName = $slot['name'];
            $timeRange = $slot['start'] . ' - ' . $slot['end'];
            $subjectName = $pool[$i];

            // try primary then co-teachers
            $teacherAssigned = null;
            $candidates = [];
            $primary = $subjectMap[$subjectName]['primary'];
            if ($primary !== null && trim($primary) !== '') $candidates[] = $primary;
            foreach ($subjectMap[$subjectName]['co'] as $ct) if ($ct !== '') $candidates[] = $ct;

            foreach ($candidates as $cand) {
                $checkStmt->bind_param('iss', $instituteId, $timeRange, $cand);
                $checkStmt->execute();
                $r = $checkStmt->get_result()->fetch_assoc();
                if ($r['cnt'] == 0) { $teacherAssigned = $cand; break; }
            }
            if (is_null($teacherAssigned)) {
                $conn->rollback();
                $data = ['status' => 422, 'message' => 'No available teacher for subject '.$subjectName.' at '.$day.' '.$timeRange];
                header("HTTP/1.0 422 Unprocessable Entity");
                echo json_encode($data);
                exit;
            }

            $insertStmt->bind_param('issssi', $instituteId, $day, $periodName, $timeRange, $subjectName, $teacherAssigned);
            $insertStmt->execute();

            $generated[] = ['day' => $day, 'period' => $periodName, 'time' => $timeRange, 'subject' => $subjectName, 'teacher' => $teacherAssigned];
        }

        $conn->commit();
        $data = ['status' => 200, 'message' => 'Time table generated', 'data' => $generated];
        echo json_encode($data);
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $data = ['status' => 500, 'message' => 'Error generating timetable: '.$e->getMessage()];
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
