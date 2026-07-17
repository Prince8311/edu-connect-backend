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

if ($requestMethod !== 'POST') {
	header("HTTP/1.0 405 Method Not Allowed");
	echo json_encode([
		'status' => 405,
		'message' => $requestMethod . ' Method Not Allowed'
	]);
	exit;
}

require __DIR__ . "/../../../../_db-connect.php";
global $conn;

$instituteId = $authResult['inst_id'];
$rawIntent = $_GET['intent'] ?? null;

if ($rawIntent === null || trim((string) $rawIntent) === '') {
	header("HTTP/1.0 400 Bad Request");
	echo json_encode([
		'status' => 400,
		'message' => "intent is required."
	]);
	exit;
}

$intent = strtolower(trim((string) $rawIntent));
if (!in_array($intent, ['add', 'update'], true)) {
	header("HTTP/1.0 400 Bad Request");
	echo json_encode([
		'status' => 400,
		'message' => "Invalid 'intent'. Allowed values are add or update"
	]);
	exit;
}

$inputData = json_decode(file_get_contents('php://input'), true);
if (!is_array($inputData) || empty($inputData)) {
	header("HTTP/1.0 400 Bad Request");
	echo json_encode([
		'status' => 400,
		'message' => 'Empty request data'
	]);
	exit;
}

function sendStopageError(int $status, string $message)
{
	header("HTTP/1.0 " . $status);
	echo json_encode([
		'status' => $status,
		'message' => $message
	]);
	exit;
}

function normalizeStopageValue($value)
{
	return trim((string) $value);
}

$name = normalizeStopageValue($inputData['name'] ?? '');
$state = normalizeStopageValue($inputData['state'] ?? '');
$city = normalizeStopageValue($inputData['city'] ?? '');
$location = normalizeStopageValue($inputData['location'] ?? '');
$latitude = normalizeStopageValue($inputData['latitude'] ?? '');
$longitude = normalizeStopageValue($inputData['longitude'] ?? '');
$distance = normalizeStopageValue($inputData['distance'] ?? '');
$status = filter_var($inputData['status'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

if ($name === '' || $state === '' || $city === '' || $location === '' || $latitude === '' || $longitude === '' || $distance === '') {
	sendStopageError(400, 'All fields are required: name, state, city, location, latitude, longitude, distance');
}

$nameEsc = mysqli_real_escape_string($conn, $name);
$stateEsc = mysqli_real_escape_string($conn, $state);
$cityEsc = mysqli_real_escape_string($conn, $city);
$locationEsc = mysqli_real_escape_string($conn, $location);
$latitudeEsc = mysqli_real_escape_string($conn, $latitude);
$longitudeEsc = mysqli_real_escape_string($conn, $longitude);
$distanceEsc = mysqli_real_escape_string($conn, $distance);
$instIdEsc = mysqli_real_escape_string($conn, $instituteId);

if ($intent === 'add') {
	$duplicateSql = "SELECT `id` FROM `transport_stopages`
		WHERE `inst_id` = '$instIdEsc'
			AND LOWER(TRIM(`name`)) = LOWER(TRIM('$nameEsc'))
			AND LOWER(TRIM(`location`)) = LOWER(TRIM('$locationEsc'))
			AND LOWER(TRIM(`latitude`)) = LOWER(TRIM('$latitudeEsc'))
			AND LOWER(TRIM(`longitude`)) = LOWER(TRIM('$longitudeEsc'))
		LIMIT 1";
	$duplicateResult = mysqli_query($conn, $duplicateSql);

	if (!$duplicateResult) {
		sendStopageError(500, 'Internal Server Error: ' . mysqli_error($conn));
	}

	if (mysqli_num_rows($duplicateResult) > 0) {
		sendStopageError(400, 'This stopage already exists for this institute.');
	}

	$insertSql = "INSERT INTO `transport_stopages`
		(`inst_id`, `name`, `state`, `city`, `location`, `latitude`, `longitude`, `distance`, `status`)
		VALUES
		('$instIdEsc', '$nameEsc', '$stateEsc', '$cityEsc', '$locationEsc', '$latitudeEsc', '$longitudeEsc', '$distanceEsc', '$status')";
	$insertResult = mysqli_query($conn, $insertSql);

	if (!$insertResult) {
		sendStopageError(500, 'Database error: ' . mysqli_error($conn));
	}

	header("HTTP/1.0 200 OK");
	echo json_encode([
		'status' => 200,
		'message' => 'Stopage created successfully.'
	]);
	exit;
}

$id = normalizeStopageValue($inputData['id'] ?? '');
if ($id === '') {
	sendStopageError(400, 'id is required for update intent.');
}

$existsSql = "SELECT `id` FROM `transport_stopages` WHERE `inst_id` = '$instIdEsc' AND `id` = '" . mysqli_real_escape_string($conn, $id) . "' LIMIT 1";
$existsResult = mysqli_query($conn, $existsSql);

if (!$existsResult) {
	sendStopageError(500, 'Internal Server Error: ' . mysqli_error($conn));
}

if (mysqli_num_rows($existsResult) === 0) {
	sendStopageError(404, 'Stopage not found.');
}

$duplicateSql = "SELECT `id` FROM `transport_stopages`
	WHERE `inst_id` = '$instIdEsc'
		AND LOWER(TRIM(`name`)) = LOWER(TRIM('$nameEsc'))
		AND LOWER(TRIM(`location`)) = LOWER(TRIM('$locationEsc'))
		AND LOWER(TRIM(`latitude`)) = LOWER(TRIM('$latitudeEsc'))
		AND LOWER(TRIM(`longitude`)) = LOWER(TRIM('$longitudeEsc'))
		AND `id` != '" . mysqli_real_escape_string($conn, $id) . "'
	LIMIT 1";
$duplicateResult = mysqli_query($conn, $duplicateSql);

if (!$duplicateResult) {
	sendStopageError(500, 'Internal Server Error: ' . mysqli_error($conn));
}

if (mysqli_num_rows($duplicateResult) > 0) {
	sendStopageError(400, 'This stopage already exists for this institute.');
}

$updateSql = "UPDATE `transport_stopages`
	SET `name` = '$nameEsc',
		`state` = '$stateEsc',
		`city` = '$cityEsc',
		`location` = '$locationEsc',
		`latitude` = '$latitudeEsc',
		`longitude` = '$longitudeEsc',
			`distance` = '$distanceEsc',
			`status` = '$status'
	WHERE `inst_id` = '$instIdEsc' AND `id` = '" . mysqli_real_escape_string($conn, $id) . "'";
$updateResult = mysqli_query($conn, $updateSql);

if (!$updateResult) {
	sendStopageError(500, 'Database error: ' . mysqli_error($conn));
}

header("HTTP/1.0 200 OK");
echo json_encode([
	'status' => 200,
	'message' => 'Stopage updated successfully.'
]);
