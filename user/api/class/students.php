<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

$authResult = userAuthenticateRequest();
if (!$authResult['authenticated']) {
	header("HTTP/1.0 " . $authResult['status']);
	echo json_encode(['status' => $authResult['status'], 'message' => $authResult['message']]);
	exit;
}

if ($requestMethod !== 'GET') {
	header("HTTP/1.0 405 Method Not Allowed");
	echo json_encode(['status' => 405, 'message' => $requestMethod . ' Method Not Allowed']);
	exit;
}

$id = $_GET['id'] ?? null;
if (!is_string($id) || !preg_match('/^[1-9][0-9]*$/', $id)) {
	http_response_code(400);
	echo json_encode(['status' => 400, 'message' => 'A valid positive id is required.']);
	exit;
}

require __DIR__ . "/../../../_db-connect.php";
global $conn;
$instituteId = $authResult['inst_id'];

try {
	$fetchRows = static function ($sql, $types, ...$params) use ($conn) {
		$stmt = $conn->prepare($sql);
		if (!$stmt) {
			throw new RuntimeException('Could not prepare class students query.');
		}
		try {
			if (!$stmt->bind_param($types, ...$params) || !$stmt->execute()) {
				throw new RuntimeException('Could not execute class students query.');
			}
			$result = $stmt->get_result();
			if (!$result) {
				throw new RuntimeException('Could not fetch class students.');
			}
			return $result->fetch_all(MYSQLI_ASSOC);
		} finally {
			$stmt->close();
		}
	};

	$classRows = $fetchRows(
		"SELECT `class`, `section`, `period`, `time`, `subject`
		 FROM `time_table` WHERE `id` = ? AND `inst_id` = ? LIMIT 1",
		'ss',
		$id,
		$instituteId
	);
	if (!$classRows) {
		http_response_code(404);
		echo json_encode(['status' => 404, 'message' => 'Class not found.']);
		exit;
	}
	$classDetails = $classRows[0];
	$attendanceRows = $fetchRows(
		"SELECT `attendance_type` FROM `institution_attendance_settings`
		 WHERE `inst_id` = ? AND FIND_IN_SET(?, REPLACE(`classes`, ' ', '')) > 0
		 ORDER BY `id` ASC LIMIT 1",
		'ss',
		$instituteId,
		trim((string) $classDetails['class'])
	);
	$attendanceType = $attendanceRows[0]['attendance_type'] ?? null;

	$studentRows = $fetchRows(
		"SELECT s.`id` AS `student_id`, s.`enrollment_id`, u.`profile_image`, n.`field_name`, n.`value`
		 FROM `students` s
		 LEFT JOIN `users` u ON u.`id` = s.`user_id` AND u.`inst_id` = s.`inst_id`
		 LEFT JOIN `student_field_values` n ON n.`inst_id` = s.`inst_id`
		 AND n.`student_id` = s.`id`
		 AND n.`field_name` IN ('First Name', 'Middle Name', 'Last Name')
		 WHERE s.`inst_id` = ?
		 AND EXISTS (SELECT 1 FROM `student_field_values` c
		     WHERE c.`inst_id` = s.`inst_id` AND c.`student_id` = s.`id`
		     AND c.`field_name` = 'Class / Standard' AND c.`value` = ?)
		 AND EXISTS (SELECT 1 FROM `student_field_values` sec
		     WHERE sec.`inst_id` = s.`inst_id` AND sec.`student_id` = s.`id`
		     AND sec.`field_name` = 'Section' AND sec.`value` = ?)
		 ORDER BY s.`id` ASC, n.`id` ASC",
		'sss',
		$instituteId,
		$classDetails['class'],
		$classDetails['section']
	);

	$students = [];
	$nameParts = [];
	foreach ($studentRows as $row) {
		$studentId = $row['student_id'];
		$students[$studentId] = [
			'student_id' => $studentId,
			'name' => '',
			'enrollment_id' => $row['enrollment_id'],
			'profile_image' => $row['profile_image'],
			'attendance_status' => 'not marked'
		];
		$nameParts[$studentId][$row['field_name'] ?? ''] = $row['value'];
	}

	$attendanceRow = [];
	$today = date('j F, Y');
	$classSection = trim((string) $classDetails['class']) . trim((string) $classDetails['section']);
	if ($attendanceType === 'date_wise') {
		$attendanceRow = $fetchRows(
			"SELECT `present`, `absent` FROM `date_wise_attendance`
			 WHERE `inst_id` = ? AND `date` = ? AND `class_section` = ? ORDER BY `id` DESC LIMIT 1",
			'sss', $instituteId, $today, $classSection
		);
	} elseif ($attendanceType === 'period_wise') {
		$attendanceRow = $fetchRows(
			"SELECT `present`, `absent` FROM `period_wise_attendance`
			 WHERE `inst_id` = ? AND `date` = ? AND `class_section` = ?
			 AND `period` = ? AND `time_slot` = ? AND `subject` = ? ORDER BY `id` DESC LIMIT 1",
			'ssssss', $instituteId, $today, $classSection, $classDetails['period'], $classDetails['time'], $classDetails['subject']
		);
	}
	if ($attendanceRow) {
		$presentStudentIds = array_filter(array_map('trim', explode(',', (string) $attendanceRow[0]['present'])), 'strlen');
		$absentStudentIds = array_filter(array_map('trim', explode(',', (string) $attendanceRow[0]['absent'])), 'strlen');
		foreach ($students as $studentId => &$student) {
			if (in_array((string) $studentId, $presentStudentIds, true)) {
				$student['attendance_status'] = 'present';
			} elseif (in_array((string) $studentId, $absentStudentIds, true)) {
				$student['attendance_status'] = 'absent';
			}
		}
		unset($student);
	}

	foreach ($students as $studentId => &$student) {
		$name = [];
		foreach (['First Name', 'Middle Name', 'Last Name'] as $field) {
			$value = trim((string) ($nameParts[$studentId][$field] ?? ''));
			if ($value !== '') {
				$name[] = $value;
			}
		}
		$student['name'] = implode(' ', $name);
	}
	unset($student);

	http_response_code(200);
	echo json_encode([
		'status' => 200,
		'message' => 'Class students fetched successfully.',
		'data' => array_values($students)
	]);
} catch (Throwable $e) {
	error_log('Class students API: ' . $e->getMessage());
	http_response_code(500);
	echo json_encode(['status' => 500, 'message' => 'Unable to fetch class students.']);
}
