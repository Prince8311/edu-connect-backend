<?php

require __DIR__ . "/../../../../utils/headers.php";
require __DIR__ . "/../../../../utils/middleware.php";

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
	require __DIR__ . "/../../../../_db-connect.php";
	global $conn;

	$instituteId = $authResult['inst_id'];
	$instIdEsc = mysqli_real_escape_string($conn, (string) $instituteId);
	$isForm = isset($_GET['isForm']) && strtolower(trim((string) $_GET['isForm'])) === 'true';

	if ($isForm) {
		$formSql = "SELECT ts.`id`, ts.`name`, ts.`latitude`, ts.`longitude`, ts.`distance`,
				(SELECT COUNT(*) FROM `transport_passengers` tp
					WHERE tp.`inst_id` = ts.`inst_id` AND tp.`stopage` = ts.`id`) AS `passengers`
			FROM `transport_stopages` ts
			WHERE ts.`inst_id`='$instIdEsc' AND ts.`status`='1'
			ORDER BY ts.`id` DESC";

		$formResult = mysqli_query($conn, $formSql);

		if ($formResult) {
			$stopages = [];
			while ($row = mysqli_fetch_assoc($formResult)) {
				$row['passengers'] = (int) $row['passengers'];
				$stopages[] = $row;
			}

			header("HTTP/1.0 200 OK");
			echo json_encode([
				'status' => 200,
				'message' => 'Stopage list fetched successfully.',
				'stopages' => $stopages
			]);
		} else {
			header("HTTP/1.0 500 Internal Server Error");
			echo json_encode([
				'status' => 500,
				'message' => 'Database error: ' . mysqli_error($conn)
			]);
		}
		exit;
	}

	$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 10;
	$page = isset($_GET['page']) && is_numeric($_GET['page']) && (int) $_GET['page'] > 0
		? (int) $_GET['page']
		: 1;
	$offset = ($page - 1) * $limit;

	$countSql = "SELECT COUNT(*) AS total FROM `transport_stopages` WHERE `inst_id`='$instIdEsc'";
	$countResult = mysqli_query($conn, $countSql);

	if (!$countResult) {
		header("HTTP/1.0 500 Internal Server Error");
		echo json_encode([
			'status' => 500,
			'message' => 'Database error: ' . mysqli_error($conn)
		]);
		exit;
	}

	$totalRow = mysqli_fetch_assoc($countResult);
	$totalStopages = (int) ($totalRow['total'] ?? 0);

	$sql = "SELECT ts.`id`, ts.`name`, ts.`state`, ts.`city`, ts.`location`, ts.`latitude`, ts.`longitude`, ts.`distance`, ts.`status`,
			(SELECT COUNT(*) FROM `transport_passengers` tp
				WHERE tp.`inst_id` = ts.`inst_id` AND tp.`stopage` = ts.`id`) AS `passengers`
		FROM `transport_stopages` ts
		WHERE ts.`inst_id`='$instIdEsc'
		ORDER BY ts.`id` DESC
		LIMIT $limit OFFSET $offset";

	$result = mysqli_query($conn, $sql);

	if ($result) {
		$stopages = [];

		while ($row = mysqli_fetch_assoc($result)) {
			$row['status'] = ((int) $row['status'] === 1);
			$row['passengers'] = (int) $row['passengers'];
			$stopages[] = $row;
		}

		header("HTTP/1.0 200 OK");
		echo json_encode([
			'status' => 200,
			'message' => 'Stopage list fetched successfully.',
			'totalCount' => $totalStopages,
			'currentPage' => $page,
			'stopages' => $stopages
		]);
	} else {
		header("HTTP/1.0 500 Internal Server Error");
		echo json_encode([
			'status' => 500,
			'message' => 'Database error: ' . mysqli_error($conn)
		]);
	}
} else {
	header("HTTP/1.0 405 Method Not Allowed");
	echo json_encode([
		'status' => 405,
		'message' => $requestMethod . ' Method Not Allowed'
	]);
}
