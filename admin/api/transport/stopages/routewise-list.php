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
    
    if (empty($instituteId)) {
        http_response_code(422);
        echo json_encode(['status' => 422, 'message' => 'Institute ID is missing from authentication.']);
        exit;
    }

    $routeId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$routeId) {
        http_response_code(400);
        echo json_encode(['status' => 400, 'message' => 'Route ID is required and must be a valid integer.']);
        exit;
    }

    $instIdEsc = mysqli_real_escape_string($conn, (string) $instituteId);
    $routeIdEsc = mysqli_real_escape_string($conn, (string) $routeId);
    $search = trim((string) ($_GET['search'] ?? ''));
    $searchCondition = '';
    if ($search !== '') {
        $searchEsc = mysqli_real_escape_string($conn, $search);
        $searchCondition = " AND `name` LIKE '%$searchEsc%'";
    }
    
    $query = function ($sql) use ($conn) {
        $result = mysqli_query($conn, $sql);
        if ($result === false) {
            throw new RuntimeException('Database query failed.');
        }
        return $result;
    };
    
    $parseIds = function ($value) {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), function ($id) {
            return $id !== '';
        }));
    };

    try {
        // Get route and extract stopages
        $routeResult = $query("SELECT `stopages` FROM `transport_routes` 
            WHERE `id`='$routeIdEsc' AND `inst_id`='$instIdEsc'");
        
        if (mysqli_num_rows($routeResult) === 0) {
            http_response_code(404);
            echo json_encode(['status' => 404, 'message' => 'Route not found.']);
            exit;
        }
        
        $routeRow = mysqli_fetch_assoc($routeResult);
        $stopageIds = $parseIds($routeRow['stopages']);
        
        $stopages = [];
        
        if (!empty($stopageIds)) {
            // Build comma-separated list of quoted IDs
            $quotedIds = array_map(function ($id) use ($conn) {
                return "'" . mysqli_real_escape_string($conn, (string) $id) . "'";
            }, $stopageIds);
            
            // Fetch stopages with status = 1
            $stopagesResult = $query("SELECT `id`, `name` FROM `transport_stopages`
                WHERE `inst_id`='$instIdEsc' AND `id` IN (" . implode(',', $quotedIds) . ") AND `status`=1$searchCondition
                ORDER BY FIELD(`id`, " . implode(',', $quotedIds) . ")");
            
            while ($stopageRow = mysqli_fetch_assoc($stopagesResult)) {
                $stopages[] = $stopageRow;
            }
        }

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Route stopages fetched successfully.',
            'stopages' => $stopages
        ]);
    } catch (Throwable $error) {
        http_response_code(500);
        echo json_encode(['status' => 500, 'message' => 'Unable to fetch stopages due to a database error.']);
    }
} else {
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode([
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed'
    ]);
}
