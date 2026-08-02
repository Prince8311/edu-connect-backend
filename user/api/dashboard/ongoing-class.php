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
	require __DIR__ . "/../../../_db-connect.php";
	global $conn;

	$instituteId = mysqli_real_escape_string($conn, (string) ($authResult['inst_id'] ?? ''));
	$userId = mysqli_real_escape_string($conn, (string) ($authResult['userId'] ?? ''));
	$userType = strtolower(trim((string) ($authResult['user_type'] ?? '')));

	if ($instituteId === '') {
		header("HTTP/1.0 400 Bad Request");
		echo json_encode([
			'success' => false,
			'status' => 400,
			'message' => 'Institute information is missing for the authenticated user.'
		]);
		exit;
	}

	$todayDayName = date('l');
	$dayNameToShort = [
		'Sunday' => 'Sun',
		'Monday' => 'Mon',
		'Tuesday' => 'Tue',
		'Wednesday' => 'Wed',
		'Thursday' => 'Thu',
		'Friday' => 'Fri',
		'Saturday' => 'Sat',
	];
	$todayShortDay = $dayNameToShort[$todayDayName] ?? substr($todayDayName, 0, 3);

	$scheduledClasses = [];
	$studentClass = null;
	$studentSection = null;
	$teacherClassRoom = null;

	$normalizeTimeRange = static function ($value) {
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}

		$parts = preg_split('/\s*-\s*/', $value, 2);
		if (is_array($parts) && count($parts) === 2) {
			return strtolower(trim((string) $parts[0]) . ' - ' . trim((string) $parts[1]));
		}

		return strtolower(preg_replace('/\s+/', ' ', $value));
	};

	$parseTimeToTimestamp = static function ($timeValue) {
		$timeValue = trim((string) $timeValue);
		if ($timeValue === '') {
			return null;
		}

		$formats = ['g:i A', 'h:i A', 'g:i a', 'h:i a'];
		foreach ($formats as $format) {
			$dt = DateTime::createFromFormat($format, $timeValue);
			if ($dt instanceof DateTime) {
				$hour = (int) $dt->format('H');
				$minute = (int) $dt->format('i');
				return ($hour * 3600) + ($minute * 60);
			}
		}

		return null;
	};

	$isCurrentTimeInRange = static function ($rangeValue) use ($parseTimeToTimestamp) {
		$rangeValue = trim((string) $rangeValue);
		if ($rangeValue === '') {
			return false;
		}

		$parts = preg_split('/\s*-\s*/', $rangeValue, 2);
		if (!is_array($parts) || count($parts) !== 2) {
			return false;
		}

		$startSeconds = $parseTimeToTimestamp($parts[0]);
		$endSeconds = $parseTimeToTimestamp($parts[1]);
		if ($startSeconds === null || $endSeconds === null) {
			return false;
		}

		$currentSeconds = ((int) date('H') * 3600) + ((int) date('i') * 60) + (int) date('s');

		if ($endSeconds < $startSeconds) {
			return ($currentSeconds >= $startSeconds) || ($currentSeconds <= $endSeconds);
		}

		return $currentSeconds >= $startSeconds && $currentSeconds <= $endSeconds;
	};

	if ($userType === 'teacher') {
		$teacherStmt = $conn->prepare("SELECT `id`, `class_teacher` FROM `teachers` WHERE `inst_id` = ? AND `user_id` = ? LIMIT 1");
		if (!$teacherStmt) {
			header("HTTP/1.0 500 Internal Server Error");
			echo json_encode([
				'success' => false,
				'status' => 500,
				'message' => 'Failed to prepare teacher lookup query.'
			]);
			exit;
		}

		$teacherStmt->bind_param('ss', $instituteId, $userId);
		$teacherStmt->execute();
		$teacherResult = $teacherStmt->get_result();
		$teacherRow = $teacherResult ? $teacherResult->fetch_assoc() : null;
		$teacherStmt->close();

		if (!$teacherRow) {
			header("HTTP/1.0 404 Not Found");
			echo json_encode([
				'success' => false,
				'status' => 404,
				'message' => 'Teacher profile not found for this user.'
			]);
			exit;
		}

		$teacherId = (string) $teacherRow['id'];
		$classTeacherRaw = isset($teacherRow['class_teacher']) ? trim((string) $teacherRow['class_teacher']) : '';
		if ($classTeacherRaw !== '') {
			$classTeacherParts = preg_split('/\s*-\s*/', $classTeacherRaw, 2);
			if (is_array($classTeacherParts) && count($classTeacherParts) === 2) {
				$teacherClassRoom = trim((string) $classTeacherParts[0]) . ' - ' . trim((string) $classTeacherParts[1]);
			} else {
				$teacherClassRoom = $classTeacherRaw;
			}
		}

		$timeTableSql = "SELECT `id`, `inst_id`, `class`, `section`, `day`, `period`, `time`, `subject`, `teacher`, `status`
			FROM `time_table`
			WHERE `inst_id` = ? AND `teacher` = ? AND `status` = 1 AND TRIM(LOWER(`day`)) = ?
			ORDER BY STR_TO_DATE(SUBSTRING_INDEX(`time`, ' - ', 1), '%h:%i %p') ASC";

		$timeTableStmt = $conn->prepare($timeTableSql);
		if (!$timeTableStmt) {
			header("HTTP/1.0 500 Internal Server Error");
			echo json_encode([
				'success' => false,
				'status' => 500,
				'message' => 'Failed to prepare timetable query for teacher.'
			]);
			exit;
		}

		$todayDayKey = strtolower($todayShortDay);
		$timeTableStmt->bind_param('sss', $instituteId, $teacherId, $todayDayKey);
		$timeTableStmt->execute();
		$timeTableResult = $timeTableStmt->get_result();

		while ($timeTableResult && $row = $timeTableResult->fetch_assoc()) {
			$scheduledClasses[] = $row;
		}
		$timeTableStmt->close();
	} elseif ($userType === 'student' || $userType === 'guardian') {
		$studentId = null;

		if ($userType === 'student') {
			$studentStmt = $conn->prepare("SELECT `id` FROM `students` WHERE `inst_id` = ? AND `user_id` = ? LIMIT 1");
			if (!$studentStmt) {
				header("HTTP/1.0 500 Internal Server Error");
				echo json_encode([
					'success' => false,
					'status' => 500,
					'message' => 'Failed to prepare student lookup query.'
				]);
				exit;
			}

			$studentStmt->bind_param('ss', $instituteId, $userId);
			$studentStmt->execute();
			$studentResult = $studentStmt->get_result();
			$studentRow = $studentResult ? $studentResult->fetch_assoc() : null;
			$studentStmt->close();

			if (!$studentRow) {
				header("HTTP/1.0 404 Not Found");
				echo json_encode([
					'success' => false,
					'status' => 404,
					'message' => 'Student profile not found for this user.'
				]);
				exit;
			}

			$studentId = (string) $studentRow['id'];
		} else {
			$authStudentId = isset($authResult['student_id']) ? (string) $authResult['student_id'] : '';
			if ($authStudentId === '') {
				header("HTTP/1.0 400 Bad Request");
				echo json_encode([
					'success' => false,
					'status' => 400,
					'message' => 'Guardian student context is missing. Please select a student and login again.'
				]);
				exit;
			}
			$studentId = mysqli_real_escape_string($conn, $authStudentId);
		}

		if ($userType === 'guardian') {
			$fieldSql = "SELECT `field_name`, `value` FROM `student_field_values` WHERE `inst_id` = ? AND `student_id` = ? AND `field_name` IN ('Class / Standard', 'Section')";
		} else {
			$fieldSql = "SELECT `field_name`, `value` FROM `student_field_values` WHERE `inst_id` = ? AND `student_id` = ? AND `section_id` = '1' AND `field_name` IN ('Class / Standard', 'Section')";
		}

		$fieldStmt = $conn->prepare($fieldSql);
		if (!$fieldStmt) {
			header("HTTP/1.0 500 Internal Server Error");
			echo json_encode([
				'success' => false,
				'status' => 500,
				'message' => 'Failed to prepare student field lookup query.'
			]);
			exit;
		}

		$fieldStmt->bind_param('ss', $instituteId, $studentId);
		$fieldStmt->execute();
		$fieldResult = $fieldStmt->get_result();

		$classStandard = '';
		$section = '';
		while ($fieldResult && $fieldRow = $fieldResult->fetch_assoc()) {
			if ($fieldRow['field_name'] === 'Class / Standard') {
				$classStandard = trim((string) $fieldRow['value']);
			}
			if ($fieldRow['field_name'] === 'Section') {
				$section = trim((string) $fieldRow['value']);
			}
		}
		$fieldStmt->close();

		if ($classStandard === '' || $section === '') {
			header("HTTP/1.0 404 Not Found");
			echo json_encode([
				'success' => false,
				'status' => 404,
				'message' => 'Class or section is not configured for this student.'
			]);
			exit;
		}

		$studentClass = $classStandard;
		$studentSection = $section;

		$timeTableSql = "SELECT `id`, `inst_id`, `class`, `section`, `day`, `period`, `time`, `subject`, `teacher`, `status`
			FROM `time_table`
			WHERE `inst_id` = ? AND `class` = ? AND `section` = ? AND `status` = 1 AND TRIM(LOWER(`day`)) = ?
			ORDER BY STR_TO_DATE(SUBSTRING_INDEX(`time`, ' - ', 1), '%h:%i %p') ASC";

		$timeTableStmt = $conn->prepare($timeTableSql);
		if (!$timeTableStmt) {
			header("HTTP/1.0 500 Internal Server Error");
			echo json_encode([
				'success' => false,
				'status' => 500,
				'message' => 'Failed to prepare timetable query for student.'
			]);
			exit;
		}

		$todayDayKey = strtolower($todayShortDay);
		$timeTableStmt->bind_param('ssss', $instituteId, $classStandard, $section, $todayDayKey);
		$timeTableStmt->execute();
		$timeTableResult = $timeTableStmt->get_result();

		while ($timeTableResult && $row = $timeTableResult->fetch_assoc()) {
			$scheduledClasses[] = $row;
		}
		$timeTableStmt->close();
	} else {
		header("HTTP/1.0 403 Forbidden");
		echo json_encode([
			'success' => false,
			'status' => 403,
			'message' => 'This user role is not allowed to access ongoing class.'
		]);
		exit;
	}

	$toOrdinalLabel = static function ($number) {
		$number = (int) $number;
		$mod100 = $number % 100;
		if ($mod100 >= 11 && $mod100 <= 13) {
			return $number . 'th';
		}

		$mod10 = $number % 10;
		if ($mod10 === 1) {
			return $number . 'st';
		}
		if ($mod10 === 2) {
			return $number . 'nd';
		}
		if ($mod10 === 3) {
			return $number . 'rd';
		}

		return $number . 'th';
	};

	$timeSlotOrdinalByRange = [];
	if ($userType === 'student' || $userType === 'guardian') {
		$timeSlotSql = "SELECT `name`, `start`, `end` FROM `time_slots` WHERE `inst_id` = ? ORDER BY STR_TO_DATE(`start`, '%h:%i %p') ASC";
		$timeSlotStmt = $conn->prepare($timeSlotSql);
		if ($timeSlotStmt) {
			$timeSlotStmt->bind_param('s', $instituteId);
			$timeSlotStmt->execute();
			$timeSlotResult = $timeSlotStmt->get_result();

			$slotPosition = 1;
			while ($timeSlotResult && $timeSlotRow = $timeSlotResult->fetch_assoc()) {
				$slotName = isset($timeSlotRow['name']) ? strtolower(trim((string) $timeSlotRow['name'])) : '';
				if ($slotName === 'break') {
					continue;
				}

				$slotStart = isset($timeSlotRow['start']) ? trim((string) $timeSlotRow['start']) : '';
				$slotEnd = isset($timeSlotRow['end']) ? trim((string) $timeSlotRow['end']) : '';
				if ($slotStart === '' || $slotEnd === '') {
					continue;
				}

				$slotRange = $normalizeTimeRange($slotStart . ' - ' . $slotEnd);
				if ($slotRange !== '' && !isset($timeSlotOrdinalByRange[$slotRange])) {
					$timeSlotOrdinalByRange[$slotRange] = $toOrdinalLabel($slotPosition);
					$slotPosition++;
				}
			}

			$timeSlotStmt->close();
		}
	}

	$studentCountByClassSection = [];
	if ($userType === 'teacher') {
		foreach ($scheduledClasses as $row) {
			$classKey = (string) $row['class'] . '|' . (string) $row['section'];
			if (!isset($studentCountByClassSection[$classKey])) {
				$countSql = "SELECT COUNT(DISTINCT student_id) as count
					FROM student_field_values
					WHERE inst_id = ?
					  AND field_name = 'Class / Standard'
					  AND value = ?
					  AND student_id IN (
						SELECT student_id FROM student_field_values
						WHERE inst_id = ?
						  AND field_name = 'Section'
						  AND value = ?
					  )";
				$countStmt = $conn->prepare($countSql);
				if ($countStmt) {
					$countStmt->bind_param('ssss', $instituteId, $row['class'], $instituteId, $row['section']);
					$countStmt->execute();
					$countResult = $countStmt->get_result();
					$countRow = $countResult ? $countResult->fetch_assoc() : null;
					$studentCountByClassSection[$classKey] = $countRow ? (int) $countRow['count'] : 0;
					$countStmt->close();
				} else {
					$studentCountByClassSection[$classKey] = 0;
				}
			}
		}
	}

	$teacherNameByTeacherId = [];
	if ($userType === 'student' || $userType === 'guardian') {
		$teacherIds = [];
		foreach ($scheduledClasses as $row) {
			$teacherIdRaw = isset($row['teacher']) ? trim((string) $row['teacher']) : '';
			if ($teacherIdRaw !== '' && strtolower($teacherIdRaw) !== 'n/a') {
				$teacherIds[$teacherIdRaw] = true;
			}
		}

		if (!empty($teacherIds)) {
			$escapedTeacherIds = [];
			foreach (array_keys($teacherIds) as $teacherId) {
				$escapedTeacherIds[] = "'" . mysqli_real_escape_string($conn, $teacherId) . "'";
			}

			$teacherLookupSql = "SELECT t.`id` AS `teacher_id`, u.`name` AS `teacher_name`
				FROM `teachers` t
				LEFT JOIN `users` u ON u.`id` = t.`user_id`
				WHERE t.`inst_id` = '$instituteId' AND t.`id` IN (" . implode(',', $escapedTeacherIds) . ")";
			$teacherLookupResult = mysqli_query($conn, $teacherLookupSql);

			if ($teacherLookupResult) {
				while ($teacherRow = mysqli_fetch_assoc($teacherLookupResult)) {
					$teacherKey = (string) $teacherRow['teacher_id'];
					$teacherNameByTeacherId[$teacherKey] = isset($teacherRow['teacher_name'])
						? trim((string) $teacherRow['teacher_name'])
						: '';
				}
			}
		}
	}

	$ongoingRow = null;
	foreach ($scheduledClasses as $row) {
		$classTime = isset($row['time']) ? (string) $row['time'] : '';
		if ($isCurrentTimeInRange($classTime)) {
			$ongoingRow = $row;
			break;
		}
	}

	$ongoingClass = null;
	if ($ongoingRow !== null) {
		if ($userType === 'teacher') {
			$classKey = (string) $ongoingRow['class'] . '|' . (string) $ongoingRow['section'];
			$ongoingClass = [
				'id' => $ongoingRow['id'],
				'period' => $ongoingRow['period'],
				'time' => $ongoingRow['time'],
				'subject' => $ongoingRow['subject'],
				'class' => $ongoingRow['class'],
				'section' => $ongoingRow['section'],
				'student_no' => $studentCountByClassSection[$classKey] ?? 0,
			];
		}

		if ($userType === 'student' || $userType === 'guardian') {
			$teacherIdRaw = isset($ongoingRow['teacher']) ? trim((string) $ongoingRow['teacher']) : '';
			$normalizedClassTime = $normalizeTimeRange(isset($ongoingRow['time']) ? $ongoingRow['time'] : '');
			$periodLabel = $ongoingRow['period'];
			if ($normalizedClassTime !== '' && isset($timeSlotOrdinalByRange[$normalizedClassTime])) {
				$periodLabel = $timeSlotOrdinalByRange[$normalizedClassTime];
			}

			$ongoingClass = [
				'id' => $ongoingRow['id'],
				'period' => $periodLabel,
				'time' => $ongoingRow['time'],
				'subject' => $ongoingRow['subject'],
				'teacher' => $teacherNameByTeacherId[$teacherIdRaw] ?? null,
			];
		}
	}

	$responseData = [
		'success' => true,
		'status' => 200,
		'message' => 'Ongoing class fetched successfully.',
		'data' => [
			'ongoing_class' => $ongoingClass,
			'today_day_name' => $todayDayName,
		]
	];

	if ($userType === 'student' || $userType === 'guardian') {
		$responseData['data']['class_room'] = trim((string) $studentClass) . ' - ' . trim((string) $studentSection);
	}

	if ($userType === 'teacher') {
		$responseData['data']['class_teacher'] = $teacherClassRoom;
	}

	header("HTTP/1.0 200 OK");
	echo json_encode($responseData);
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
