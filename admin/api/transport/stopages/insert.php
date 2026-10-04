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

function calculateStopageDistanceMeters(float $oldLatitude, float $oldLongitude, float $newLatitude, float $newLongitude): float
{
	$earthRadius = 6371000;
	$latitudeDifference = deg2rad($newLatitude - $oldLatitude);
	$longitudeDifference = deg2rad($newLongitude - $oldLongitude);
	$a = sin($latitudeDifference / 2) ** 2
		+ cos(deg2rad($oldLatitude)) * cos(deg2rad($newLatitude))
		* sin($longitudeDifference / 2) ** 2;
	$a = min(1, max(0, $a));

	return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

$name = normalizeStopageValue($inputData['name'] ?? '');
$state = normalizeStopageValue($inputData['state'] ?? '');
$city = normalizeStopageValue($inputData['city'] ?? '');
$location = normalizeStopageValue($inputData['location'] ?? '');
$latitude = normalizeStopageValue($inputData['latitude'] ?? '');
$longitude = normalizeStopageValue($inputData['longitude'] ?? '');
$distance = normalizeStopageValue($inputData['distance'] ?? '');
$status = filter_var($inputData['status'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

if ($name === '' || $location === '' || $latitude === '' || $longitude === '') {
	sendStopageError(400, 'Required fields: name, location, latitude, longitude');
}

if (!is_numeric($latitude) || !is_numeric($longitude) || (float) $latitude < -90 || (float) $latitude > 90 || (float) $longitude < -180 || (float) $longitude > 180) {
	sendStopageError(400, 'Valid latitude and longitude are required.');
}

if ($intent === 'add' && ($state === '' || $city === '' || $distance === '')) {
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
		'message' => 'Stopage added successfully.'
	]);
	exit;
}

$id = normalizeStopageValue($inputData['id'] ?? '');
if ($id === '') {
	sendStopageError(400, 'id is required for update intent.');
}

$idEsc = mysqli_real_escape_string($conn, $id);
$existsSql = "SELECT `id`, `latitude`, `longitude` FROM `transport_stopages` WHERE `inst_id` = '$instIdEsc' AND `id` = '$idEsc' LIMIT 1";
$existsResult = mysqli_query($conn, $existsSql);

if (!$existsResult) {
	sendStopageError(500, 'Internal Server Error: ' . mysqli_error($conn));
}

if (mysqli_num_rows($existsResult) === 0) {
	sendStopageError(404, 'Stopage not found.');
}

$existingStopage = mysqli_fetch_assoc($existsResult);

$duplicateSql = "SELECT `id` FROM `transport_stopages`
	WHERE `inst_id` = '$instIdEsc'
		AND LOWER(TRIM(`name`)) = LOWER(TRIM('$nameEsc'))
		AND `id` != '$idEsc'
	LIMIT 1";
$duplicateResult = mysqli_query($conn, $duplicateSql);

if (!$duplicateResult) {
	sendStopageError(500, 'Internal Server Error: ' . mysqli_error($conn));
}

if (mysqli_num_rows($duplicateResult) > 0) {
	sendStopageError(400, 'This stopage already exists for this institute.');
}

$passengerSql = "SELECT `id` FROM `transport_passengers` WHERE `inst_id` = '$instIdEsc' AND `stopage` = '$idEsc' LIMIT 1";
$passengerResult = mysqli_query($conn, $passengerSql);

if (!$passengerResult) {
	sendStopageError(500, 'Internal Server Error: ' . mysqli_error($conn));
}

if (mysqli_num_rows($passengerResult) > 0) {
	$oldLatitude = $existingStopage['latitude'] ?? null;
	$oldLongitude = $existingStopage['longitude'] ?? null;

	if (!is_numeric($oldLatitude) || !is_numeric($oldLongitude)) {
		sendStopageError(500, 'The existing stopage coordinates are invalid.');
	}

	$distanceInMeters = calculateStopageDistanceMeters(
		(float) $oldLatitude,
		(float) $oldLongitude,
		(float) $latitude,
		(float) $longitude
	);

	if ($distanceInMeters >= 100) {
		sendStopageError(400, 'This stopage is assigned to passengers and cannot be moved by 100 metres or more.');
	}
}

$updateSql = "UPDATE `transport_stopages`
	SET `name` = '$nameEsc',
		`location` = '$locationEsc',
		`latitude` = '$latitudeEsc',
		`longitude` = '$longitudeEsc'
	WHERE `inst_id` = '$instIdEsc' AND `id` = '$idEsc'";
$updateResult = mysqli_query($conn, $updateSql);

if (!$updateResult) {
	sendStopageError(500, 'Database error: ' . mysqli_error($conn));
}

header("HTTP/1.0 200 OK");
echo json_encode([
	'status' => 200,
	'message' => 'Stopage updated successfully.'
]);
