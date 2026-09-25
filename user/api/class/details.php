<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

$authResult = userAuthenticateRequest();
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
		$attendanceRows = $fetchRows(
			"SELECT `attendance_type` FROM `institution_attendance_settings`
			 WHERE `inst_id` = ? AND FIND_IN_SET(?, REPLACE(`classes`, ' ', '')) > 0
			 ORDER BY `id` ASC LIMIT 1",
			'ss',
			$instituteId,
			trim((string) $classDetails['class'])
		);
		$classDetails['attendance_type'] = $attendanceRows[0]['attendance_type'] ?? null;

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
	$response = [
        'success' => false,
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($response);
}
