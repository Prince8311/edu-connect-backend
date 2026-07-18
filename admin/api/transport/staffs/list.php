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

	$isForm = isset($_GET['isForm']) && strtolower(trim($_GET['isForm'])) === 'true';

	if ($isForm) {
		$instIdEsc = mysqli_real_escape_string($conn, $instituteId);
		$sql = "SELECT `id`, `name`, `role` FROM `transport_staffs` WHERE `inst_id`='$instIdEsc' AND `status`='1' ORDER BY `id` DESC";
		$result = mysqli_query($conn, $sql);

		if ($result) {
			$grouped = [];
			while ($row = mysqli_fetch_assoc($result)) {
				$role = $row['role'];
				if (!isset($grouped[$role])) {
					$grouped[$role] = [];
				}
				$grouped[$role][] = ['id' => $row['id'], 'name' => $row['name']];
			}

			$staffs = [];
			foreach ($grouped as $role => $members) {
				$staffs[] = ['role' => $role, 'staffs' => $members];
			}

			header("HTTP/1.0 200 OK");
			echo json_encode([
				'status' => 200,
				'message' => 'Transport staffs list fetched successfully.',
				'list' => $staffs
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

	$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 10;
	$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0
		? (int)$_GET['page']
		: 1;
	$offset = ($page - 1) * $limit;

	$countSql = "SELECT COUNT(*) as total FROM `transport_staffs` WHERE `inst_id`='$instituteId'";
	$countResult = mysqli_query($conn, $countSql);
	$totalRow = mysqli_fetch_assoc($countResult);
	$totalStaffs = (int)$totalRow['total'];

	$sql = "SELECT `id`, `name`, `role`, `contact_no`, `email`, `license_file`, `status` FROM `transport_staffs` WHERE `inst_id`='$instituteId' ORDER BY `id` DESC LIMIT $limit OFFSET $offset";
	$result = mysqli_query($conn, $sql);

	if ($result) {
		$staffs = [];
		while ($row = mysqli_fetch_assoc($result)) {
			$row['status'] = ((int)$row['status'] === 1);
			$staffs[] = $row;
		}

		$data = [
			'status' => 200,
			'message' => 'Transport staffs list fetched successfully.',
			'totalCount' => $totalStaffs,
			'currentPage' => $page,
			'staffs' => $staffs
		];
		header("HTTP/1.0 200 OK");
		echo json_encode($data);
	} else {
		$data = [
			'status' => 500,
			'message' => 'Database error: ' . mysqli_error($conn)
		];
		header("HTTP/1.0 500 Internal Server Error");
		echo json_encode($data);
	}
} else {
	$data = [
		'status' => 405,
		'message' => $requestMethod . ' Method Not Allowed',
	];
	header("HTTP/1.0 405 Method Not Allowed");
	echo json_encode($data);
}
