<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

$authResult = adminAuthenticateRequest();
if (!$authResult['authenticated']) {
	header("HTTP/1.0 " . $authResult['status']);
	echo json_encode([
		'status' => $authResult['status'],
		'message' => $authResult['message']
	]);
	exit;
}

if ($requestMethod === 'GET') {
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
				throw new RuntimeException('Could not prepare class details query.');
			}
			try {
				if (!$stmt->bind_param($types, ...$params) || !$stmt->execute()) {
					throw new RuntimeException('Could not execute class details query.');
				}
				$result = $stmt->get_result();
				if (!$result) {
					throw new RuntimeException('Could not fetch class details.');
				}
				return $result->fetch_all(MYSQLI_ASSOC);
			} finally {
				$stmt->close();
			}
		};

		$rows = $fetchRows(
			"SELECT `id`, `classroom_id`, `class`, `section`, `day`, `period`, `time`, `subject`, `teacher`
			 FROM `time_table` WHERE `id` = ? AND `inst_id` = ? LIMIT 1",
			'ss',
			$id,
			$instituteId
		);
		if (!$rows) {
			http_response_code(404);
			echo json_encode(['status' => 404, 'message' => 'Class not found.']);
			exit;
		}
		$classDetails = $rows[0];
		$days = [
			'mon' => 'Monday',
			'tue' => 'Tuesday',
			'wed' => 'Wednesday',
			'thu' => 'Thursday',
			'fri' => 'Friday',
			'sat' => 'Saturday',
			'sun' => 'Sunday'
		];
		$dayKey = strtolower(substr(trim((string) $classDetails['day']), 0, 3));
		$classDetails['day'] = $days[$dayKey] ?? $classDetails['day'];

		$joinName = static function ($parts) {
			$name = [];
			foreach (['First Name', 'Middle Name', 'Last Name'] as $field) {
				$value = trim((string) ($parts[$field] ?? ''));
				if ($value !== '') {
					$name[] = $value;
				}
			}
			return implode(' ', $name);
		};
		$teacherRows = $fetchRows(
			"SELECT `field_name`, `value` FROM `staff_field_values`
			 WHERE `inst_id` = ? AND `staff_id` = ? AND `staff_type` = 'teaching'
			 AND `field_name` IN ('First Name', 'Middle Name', 'Last Name') ORDER BY `id` ASC",
			'ss',
			$instituteId,
			$classDetails['teacher']
		);
		$teacherParts = [];
		foreach ($teacherRows as $row) {
			$teacherParts[$row['field_name']] = $row['value'];
		}
		$classDetails['teacher'] = $joinName($teacherParts);

		$studentRows = $fetchRows(
			"SELECT s.`id` AS `student_id`, s.`enrollment_id`, n.`field_name`, n.`value`
			 FROM `students` s
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
				'enrollment_id' => $row['enrollment_id']
			];
			$nameParts[$studentId][$row['field_name'] ?? ''] = $row['value'];
		}
		foreach ($students as $studentId => &$student) {
			$student['name'] = $joinName($nameParts[$studentId]);
		}
		unset($student);
		$classDetails['students'] = array_values($students);

		http_response_code(200);
		echo json_encode([
			'status' => 200,
			'message' => 'Class details fetched successfully.',
			'data' => $classDetails
		]);
	} catch (Throwable $e) {
		error_log('Class details API: ' . $e->getMessage());
		http_response_code(500);
		echo json_encode(['status' => 500, 'message' => 'Unable to fetch class details.']);
	}
	exit;
} else {
	$data = [
		'status' => 405,
		'message' => $requestMethod . ' Method Not Allowed',
	];
	header("HTTP/1.0 405 Method Not Allowed");
	echo json_encode($data);
}
