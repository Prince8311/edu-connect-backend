<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

$authResult = userAuthenticateRequest();
if (!$authResult['authenticated']) {
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode(['success' => false, 'status' => $authResult['status'], 'message' => $authResult['message']]);
    exit;
}

$respond = static function ($status, $message, $extra = []) {
    http_response_code($status);
    echo json_encode(array_merge([
        'success' => $status >= 200 && $status < 300,
        'status' => $status,
        'message' => $message
    ], $extra));
    exit;
};

if ($requestMethod !== 'POST') {
    $respond(405, $requestMethod . ' Method Not Allowed');
}

require __DIR__ . "/../../../_db-connect.php";
global $conn;
$instituteId = (string) ($authResult['inst_id'] ?? '');
$userId = (string) ($authResult['userId'] ?? '');

$intent = $_GET['intent'] ?? null;
if (!is_string($intent) || !in_array(strtolower(trim($intent)), ['add', 'update'], true)) {
    $respond(400, 'Invalid intent. Allowed values: add, update.');
}
$intent = strtolower(trim($intent));

if (!isset($_POST['inputs'])) {
    $respond(400, 'Empty request data');
}
$inputData = json_decode((string) $_POST['inputs'], true);
if (!is_array($inputData)) {
    $respond(400, 'Invalid inputs payload');
}

$attendanceType = $inputData['attendance_type'] ?? null;
if (!is_string($attendanceType) || !in_array(strtolower(trim($attendanceType)), ['date_wise', 'period_wise'], true)) {
    $respond(400, 'attendance_type must be date_wise or period_wise.');
}
$attendanceType = strtolower(trim($attendanceType));
$table = $attendanceType === 'date_wise' ? 'date_wise_attendance' : 'period_wise_attendance';

$normalizeStudentIds = static function ($value) {
    if (is_string($value)) {
        $value = $value === '' ? [] : explode(',', $value);
    }
    if (!is_array($value)) {
        return null;
    }
    $ids = [];
    foreach ($value as $studentId) {
        if ((!is_string($studentId) && !is_int($studentId))
            || !preg_match('/^[1-9][0-9]*$/', trim((string) $studentId))) {
            return null;
        }
        $ids[] = trim((string) $studentId);
    }
    return implode(',', array_values(array_unique($ids)));
};

$commonFields = ['class', 'section', 'date', 'present', 'absent'];
$periodFields = ['period', 'time_slot', 'subject'];
$requiredFields = array_merge($commonFields, $attendanceType === 'period_wise' ? $periodFields : []);
if ($intent === 'add') {
    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $inputData)) {
            $respond(400, implode(', ', $requiredFields) . ' are required.');
        }
    }
}

$values = [];
foreach (['date', 'period', 'time_slot', 'subject'] as $field) {
    if (!array_key_exists($field, $inputData)) {
        continue;
    }
    if (!is_string($inputData[$field]) && !is_int($inputData[$field])) {
        $respond(400, 'Invalid ' . $field . '.');
    }
    $value = trim((string) $inputData[$field]);
    if ($value === '') {
        $respond(400, $field . ' must not be empty.');
    }
    $values[$field] = $value;
}

$hasClass = array_key_exists('class', $inputData);
$hasSection = array_key_exists('section', $inputData);
if ($hasClass !== $hasSection) {
    $respond(400, 'class and section must be provided together.');
}
if ($hasClass) {
    if ((!is_string($inputData['class']) && !is_int($inputData['class']))
        || (!is_string($inputData['section']) && !is_int($inputData['section']))) {
        $respond(400, 'Invalid class or section.');
    }
    $class = trim((string) $inputData['class']);
    $section = trim((string) $inputData['section']);
    if ($class === '' || $section === '') {
        $respond(400, 'class and section must not be empty.');
    }
    $values['class_section'] = $class . '-' . $section;
}

foreach (['present', 'absent'] as $field) {
    if (!array_key_exists($field, $inputData)) {
        continue;
    }
    $normalized = $normalizeStudentIds($inputData[$field]);
    if ($normalized === null) {
        $respond(400, $field . ' must contain valid positive student IDs.');
    }
    $values[$field] = $normalized;
}
if (isset($values['present'], $values['absent'])) {
    $presentIds = $values['present'] === '' ? [] : explode(',', $values['present']);
    $absentIds = $values['absent'] === '' ? [] : explode(',', $values['absent']);
    if (array_intersect($presentIds, $absentIds)) {
        $respond(400, 'A student cannot be both present and absent.');
    }
}
if ($attendanceType === 'date_wise') {
    foreach ($periodFields as $field) {
        unset($values[$field]);
    }
}

try {
    if ($intent === 'add') {
        $values = array_merge([
            'inst_id' => $instituteId,
            'date' => $values['date'],
            'class_section' => $values['class_section']
        ], $attendanceType === 'period_wise' ? [
            'period' => $values['period'],
            'time_slot' => $values['time_slot'],
            'subject' => $values['subject']
        ] : [], [
            'present' => $values['present'],
            'absent' => $values['absent'],
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        $columns = array_keys($values);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Could not prepare attendance insert.');
        }
        $params = array_values($values);
        $types = str_repeat('s', count($params));
        if (!$stmt->bind_param($types, ...$params) || !$stmt->execute()) {
            throw new RuntimeException($stmt->error ?: 'Could not insert attendance.');
        }
        $attendanceId = $stmt->insert_id;
        $stmt->close();
        $respond(200, 'Attendance added successfully.', ['id' => $attendanceId]);
    }

    $id = $inputData['id'] ?? null;
    if ((!is_string($id) && !is_int($id)) || !preg_match('/^[1-9][0-9]*$/', trim((string) $id))) {
        $respond(400, 'A valid id is required for update intent.');
    }
    if (!$values) {
        $respond(400, 'No attendance fields were provided to update.');
    }
    $assignments = implode(', ', array_map(static function ($column) {
        return '`' . $column . '` = ?';
    }, array_keys($values)));
    $sql = 'UPDATE `' . $table . '` SET ' . $assignments . ' WHERE `id` = ? AND `inst_id` = ? LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Could not prepare attendance update.');
    }
    $params = array_merge(array_values($values), [(string) $id, $instituteId]);
    $types = str_repeat('s', count($params));
    if (!$stmt->bind_param($types, ...$params) || !$stmt->execute()) {
        throw new RuntimeException($stmt->error ?: 'Could not update attendance.');
    }
    if ($stmt->affected_rows === 0) {
        $check = $conn->prepare('SELECT `id` FROM `' . $table . '` WHERE `id` = ? AND `inst_id` = ? LIMIT 1');
        if (!$check || !$check->bind_param('ss', $id, $instituteId) || !$check->execute()) {
            throw new RuntimeException('Could not verify attendance row.');
        }
        $exists = $check->get_result()->num_rows > 0;
        $check->close();
        if (!$exists) {
            $stmt->close();
            $respond(404, 'Attendance record not found.');
        }
    }
    $stmt->close();
    $respond(200, 'Attendance updated successfully.', ['id' => (int) $id]);
} catch (Throwable $error) {
    error_log('Attendance save failed: ' . $error->getMessage());
    $respond(500, 'Failed to save attendance.');
}

