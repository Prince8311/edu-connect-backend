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

function sendErrorResponse(int $status, string $message)
{
	header("HTTP/1.0 " . $status);
	echo json_encode([
		'status' => $status,
		'message' => $message
	]);
	exit;
}

function normalizeTemplateStatus($statusValue)
{
	if ($statusValue === null || $statusValue === '') {
		return null;
	}

	if ((string) $statusValue === '0') {
		return 'pending';
	}

	if ((string) $statusValue === '1') {
		return 'active';
	}

	return false;
}

function hasNonEmptyValue($value)
{
	return !($value === null || trim((string) $value) === '');
}

$rawIntent = $_GET['intent'] ?? null;
if ($rawIntent === null || trim((string) $rawIntent) === '') {
	sendErrorResponse(400, "intent is required.");
}

$intent = strtolower(trim((string) $rawIntent));
if (!in_array($intent, ['add', 'update'], true)) {
	sendErrorResponse(400, "Invalid 'intent'. Allowed values are add or update");
}

$inputData = json_decode(file_get_contents('php://input'), true);
if (!is_array($inputData) || empty($inputData)) {
	sendErrorResponse(400, 'Empty request data');
}

$userId = (string) ($authResult['userId'] ?? '');
$userType = $authResult['user_type'] ?? '';

if (!in_array($userType, ['super_admin', 'inst_admin'], true)) {
	sendErrorResponse(403, "You don't have the permission");
}

$rowId = null;
$existingTemplate = null;

if ($intent === 'update') {
	$rowId = $inputData['id'] ?? null;
	if ($rowId === null || $rowId === '' || !is_numeric($rowId)) {
		sendErrorResponse(400, 'For update, id is required and must be numeric');
	}

	$rowIdEsc = mysqli_real_escape_string($conn, (string) $rowId);
	$existingSql = "SELECT * FROM `communication_msg_templates` WHERE `id` = '$rowIdEsc' LIMIT 1";
	$existingResult = mysqli_query($conn, $existingSql);

	if (!$existingResult || mysqli_num_rows($existingResult) === 0) {
		sendErrorResponse(404, 'Template not found');
	}

	$existingTemplate = mysqli_fetch_assoc($existingResult);
	if (($existingTemplate['status'] ?? '') === 'active') {
		sendErrorResponse(400, "This template is already approved it can't be edited");
	}
}

if ($userType === 'inst_admin') {
	$templateTitle = trim((string) ($inputData['template_title'] ?? ''));
	$templateBody = trim((string) ($inputData['template_body'] ?? ''));

	if ($templateTitle === '' || $templateBody === '') {
		sendErrorResponse(400, 'template_title and template_body are required');
	}

	$hasTemplateId = array_key_exists('template_id', $inputData);
	$hasStatus = array_key_exists('status', $inputData);
	$statusValue = $inputData['status'] ?? null;

	if ($hasTemplateId) {
		sendErrorResponse(403, "you don't have the authority for approve message");
	}

	if ($hasStatus) {
		if ((string) $statusValue !== '0') {
			sendErrorResponse(403, "you don't have the authority for approve message");
		}
	}

	$titleEsc = mysqli_real_escape_string($conn, $templateTitle);
	$bodyEsc = mysqli_real_escape_string($conn, $templateBody);
	$userIdEsc = mysqli_real_escape_string($conn, $userId);

	if ($intent === 'add') {
		$insertSql = "INSERT INTO `communication_msg_templates`(`template_title`, `template_body`, `status`, `created_by`) VALUES ('$titleEsc', '$bodyEsc', 'pending', '$userIdEsc')";

		if (!mysqli_query($conn, $insertSql)) {
			sendErrorResponse(500, 'Database error: ' . mysqli_error($conn));
		}

		echo json_encode([
			'status' => 200,
			'message' => 'Template created successfully'
		]);
		exit;
	}

	$rowIdEsc = mysqli_real_escape_string($conn, (string) $rowId);
	$updateSql = "UPDATE `communication_msg_templates` SET `template_title` = '$titleEsc', `template_body` = '$bodyEsc', `status` = 'pending', `created_by` = '$userIdEsc' WHERE `id` = '$rowIdEsc'";

	if (!mysqli_query($conn, $updateSql)) {
		sendErrorResponse(500, 'Database error: ' . mysqli_error($conn));
	}

	echo json_encode([
		'status' => 200,
		'message' => 'Template updated successfully',
		'user_type' => $userType
	]);
	exit;
}

if ($userType === 'super_admin') {
	$mappedStatus = normalizeTemplateStatus($inputData['status'] ?? null);
	if ($mappedStatus === false) {
		sendErrorResponse(400, "Invalid status. Allowed values are 0 or 1");
	}

	$approvedByValue = $mappedStatus === 'active' ? "'" . mysqli_real_escape_string($conn, $userId) . "'" : 'NULL';
	$createdByEsc = mysqli_real_escape_string($conn, $userId);

	if ($intent === 'add') {
		$templateTitle = trim((string) ($inputData['template_title'] ?? ''));
		$templateBody = trim((string) ($inputData['template_body'] ?? ''));

		if ($templateTitle === '' || $templateBody === '') {
			sendErrorResponse(400, 'template_title and template_body are required');
		}

		$rawTemplateId = $inputData['template_id'] ?? null;
		$rawBalance = $inputData['balance'] ?? null;
		$hasTemplateId = hasNonEmptyValue($rawTemplateId);
		$hasBalance = hasNonEmptyValue($rawBalance);

		if ($mappedStatus === 'active' && (!$hasTemplateId || !$hasBalance)) {
			sendErrorResponse(400, 'For status 1, template_id and balance are required');
		}

		$templateIdValue = $hasTemplateId
			? "'" . mysqli_real_escape_string($conn, (string) $rawTemplateId) . "'"
			: 'NULL';

		$balanceValue = $hasBalance
			? "'" . mysqli_real_escape_string($conn, (string) $rawBalance) . "'"
			: 'NULL';

		$titleEsc = mysqli_real_escape_string($conn, $templateTitle);
		$bodyEsc = mysqli_real_escape_string($conn, $templateBody);
		$statusForInsert = $mappedStatus ?? 'pending';
		$statusEsc = mysqli_real_escape_string($conn, $statusForInsert);

		$insertSql = "INSERT INTO `communication_msg_templates`
			(`template_id`, `template_title`, `template_body`, `balance`, `status`, `approved_by`, `created_by`)
			VALUES
			($templateIdValue, '$titleEsc', '$bodyEsc', $balanceValue, '$statusEsc', $approvedByValue, '$createdByEsc')";

		if (!mysqli_query($conn, $insertSql)) {
			sendErrorResponse(500, 'Database error: ' . mysqli_error($conn));
		}

		echo json_encode([
			'status' => 200,
			'message' => 'Template created successfully'
		]);
		exit;
	}

	$finalTemplateId = array_key_exists('template_id', $inputData)
		? $inputData['template_id']
		: ($existingTemplate['template_id'] ?? null);

	$finalTemplateTitle = array_key_exists('template_title', $inputData)
		? trim((string) $inputData['template_title'])
		: (string) ($existingTemplate['template_title'] ?? '');

	$finalTemplateBody = array_key_exists('template_body', $inputData)
		? trim((string) $inputData['template_body'])
		: (string) ($existingTemplate['template_body'] ?? '');

	$finalBalance = array_key_exists('balance', $inputData)
		? $inputData['balance']
		: ($existingTemplate['balance'] ?? null);

	$finalStatus = $mappedStatus !== null
		? $mappedStatus
		: (string) ($existingTemplate['status'] ?? 'pending');

	if ($finalStatus === 'active' && (!hasNonEmptyValue($finalTemplateId) || !hasNonEmptyValue($finalBalance))) {
		sendErrorResponse(400, 'For status active, Template id and Balance are required');
	}

	if ($finalTemplateTitle === '' || $finalTemplateBody === '') {
		sendErrorResponse(400, 'Template Title and Template Body are required');
	}

	$templateIdValue = ($finalTemplateId === null || $finalTemplateId === '')
		? 'NULL'
		: "'" . mysqli_real_escape_string($conn, (string) $finalTemplateId) . "'";

	$balanceValue = ($finalBalance === null || $finalBalance === '')
		? 'NULL'
		: "'" . mysqli_real_escape_string($conn, (string) $finalBalance) . "'";

	$titleEsc = mysqli_real_escape_string($conn, $finalTemplateTitle);
	$bodyEsc = mysqli_real_escape_string($conn, $finalTemplateBody);
	$statusEsc = mysqli_real_escape_string($conn, $finalStatus);
	$approvedByValue = $finalStatus === 'active'
		? "'" . mysqli_real_escape_string($conn, $userId) . "'"
		: 'NULL';
	$rowIdEsc = mysqli_real_escape_string($conn, (string) $rowId);

	$updateSql = "UPDATE `communication_msg_templates`
		SET `template_id` = $templateIdValue,
			`template_title` = '$titleEsc',
			`template_body` = '$bodyEsc',
			`balance` = $balanceValue,
			`status` = '$statusEsc',
			`approved_by` = $approvedByValue
		WHERE `id` = '$rowIdEsc'";

	if (!mysqli_query($conn, $updateSql)) {
		sendErrorResponse(500, 'Database error: ' . mysqli_error($conn));
	}

	echo json_encode([
		'status' => 200,
		'message' => 'Template updated successfully',
		'user_type' => $userType
	]);
	exit;
}

sendErrorResponse(403, "You don't have the permission");
