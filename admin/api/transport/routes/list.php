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

    $limit = filter_var($_GET['limit'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 10;
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
    $offset = ($page - 1) * $limit;
    $instIdEsc = mysqli_real_escape_string($conn, (string) $instituteId);
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
        $countResult = $query("SELECT COUNT(*) AS total FROM `transport_routes` WHERE `inst_id`='$instIdEsc'");
        $totalCount = (int) mysqli_fetch_assoc($countResult)['total'];
        $result = $query("SELECT r.`id`, r.`name`, r.`staffs`, r.`stopages`,
                v.`name` AS vehicle_name, v.`number` AS vehicle_number
            FROM `transport_routes` r
            LEFT JOIN `transport_vehicles` v
                ON v.`id`=r.`assigned_vehicle_id` AND v.`inst_id`=r.`inst_id`
            WHERE r.`inst_id`='$instIdEsc'
            ORDER BY r.`id` DESC LIMIT $limit OFFSET $offset");

        $routes = [];
        $staffIds = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $row['staffs'] = $parseIds($row['staffs']);
            foreach ($row['staffs'] as $id) {
                $staffIds[$id] = true;
            }
            $row['stopageCount'] = count($parseIds($row['stopages']));
            unset($row['stopages']);
            $routes[] = $row;
        }

        $staffNames = [];
        if ($staffIds) {
            $quotedIds = array_map(function ($id) use ($conn) {
                return "'" . mysqli_real_escape_string($conn, (string) $id) . "'";
            }, array_keys($staffIds));
            $staffResult = $query("SELECT `id`, `name` FROM `transport_staffs`
                WHERE `inst_id`='$instIdEsc' AND `id` IN (" . implode(',', $quotedIds) . ')');
            while ($staff = mysqli_fetch_assoc($staffResult)) {
                $staffNames[$staff['id']] = $staff['name'];
            }
        }
        foreach ($routes as &$route) {
            $names = [];
            foreach ($route['staffs'] as $id) {
                if (array_key_exists($id, $staffNames)) {
                    $names[] = $staffNames[$id];
                }
            }
            $route['staffs'] = $names;
        }
        unset($route);

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Transport routes list fetched successfully.',
            'totalCount' => $totalCount,
            'currentPage' => $page,
            'routes' => $routes
        ]);
    } catch (Throwable $error) {
        http_response_code(500);
        echo json_encode(['status' => 500, 'message' => 'Unable to fetch routes due to a database error.']);
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}

?>
