<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    exit(json_encode([]));
}

$action = $_GET['action'] ?? 'summary';
$driver_id = $_POST['driver_id'] ?? 'ALL';
$year = intval($_POST['year'] ?? date('Y'));

if ($action === 'summary') {
    $sql = "SELECT 
                MONTH(t.start_time) as month_num,
                SUM(CASE WHEN e.expense_type = 'gasoline' THEN e.amount ELSE 0 END) as gasoline,
                SUM(CASE WHEN e.expense_type = 'toll' THEN e.amount ELSE 0 END) as toll,
                SUM(CASE WHEN e.expense_type = 'parking' THEN e.amount ELSE 0 END) as parking,
                SUM(CASE WHEN e.expense_type = 'lunch' THEN e.amount ELSE 0 END) as lunch,
                SUM(CASE WHEN e.expense_type = 'others' THEN e.amount ELSE 0 END) as others,
                SUM(e.amount) as total_amount
            FROM trip_expenses e
            JOIN trips t ON e.trip_id = t.id
            JOIN shifts s ON t.shift_id = s.id
            JOIN users u ON s.driver_id = u.id
            WHERE YEAR(t.start_time) = ? AND t.passenger_approval = 'approved'";
    
    $params = [$year];
    if ($driver_id !== 'ALL') {
        $sql .= " AND u.id = ?";
        $params[] = $driver_id;
    }
    
    $sql .= " GROUP BY MONTH(t.start_time) ORDER BY month_num ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Make sure all 12 months are present in results for chart/table consistency
    $monthly_data = array_fill(1, 12, [
        'month_num' => 0,
        'gasoline' => 0,
        'toll' => 0,
        'parking' => 0,
        'lunch' => 0,
        'others' => 0,
        'total_amount' => 0
    ]);
    
    foreach ($monthly_data as $m => &$val) {
        $val['month_num'] = $m;
    }
    unset($val);
    
    foreach ($data as $row) {
        $m = intval($row['month_num']);
        if ($m >= 1 && $m <= 12) {
            $monthly_data[$m] = [
                'month_num' => $m,
                'gasoline' => floatval($row['gasoline']),
                'toll' => floatval($row['toll']),
                'parking' => floatval($row['parking']),
                'lunch' => floatval($row['lunch']),
                'others' => floatval($row['others']),
                'total_amount' => floatval($row['total_amount'])
            ];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode(array_values($monthly_data));
    exit;
} elseif ($action === 'details') {
    $month = intval($_POST['month'] ?? date('n'));
    
    $sql = "SELECT 
                e.id as expense_id,
                e.expense_type,
                e.amount,
                e.litre,
                e.photo,
                e.created_at as expense_date,
                t.start_time,
                u.full_name as driver_name,
                d.name as dest_name,
                p.name as pass_name,
                c.car_no
            FROM trip_expenses e
            JOIN trips t ON e.trip_id = t.id
            JOIN master_destinations d ON t.destination_id = d.id
            JOIN master_passengers p ON t.passenger_id = p.id
            JOIN master_cars c ON t.car_id = c.id
            JOIN shifts s ON t.shift_id = s.id
            JOIN users u ON s.driver_id = u.id
            WHERE YEAR(t.start_time) = ? AND MONTH(t.start_time) = ? AND t.passenger_approval = 'approved'";
            
    $params = [$year, $month];
    if ($driver_id !== 'ALL') {
        $sql .= " AND u.id = ?";
        $params[] = $driver_id;
    }
    
    $sql .= " ORDER BY t.start_time DESC, e.id DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
} elseif ($action === 'driver_share') {
    $sql = "SELECT 
                u.id as driver_id,
                u.full_name as driver_name,
                SUM(e.amount) as total_amount
            FROM trip_expenses e
            JOIN trips t ON e.trip_id = t.id
            JOIN shifts s ON t.shift_id = s.id
            JOIN users u ON s.driver_id = u.id
            WHERE YEAR(t.start_time) = ? AND t.passenger_approval = 'approved'";
    $params = [$year];
    if ($driver_id !== 'ALL') {
        $sql .= " AND u.id = ?";
        $params[] = $driver_id;
    }
    $sql .= " GROUP BY u.id ORDER BY total_amount DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
} elseif ($action === 'vehicle_efficiency') {
    $sql = "SELECT 
                c.car_no,
                c.model as car_model,
                SUM(CASE WHEN e.expense_type = 'gasoline' THEN e.amount ELSE 0 END) as gasoline,
                SUM(CASE WHEN e.expense_type = 'toll' THEN e.amount ELSE 0 END) as toll,
                SUM(CASE WHEN e.expense_type = 'parking' THEN e.amount ELSE 0 END) as parking,
                SUM(e.amount) as total_amount
            FROM trip_expenses e
            JOIN trips t ON e.trip_id = t.id
            JOIN master_cars c ON t.car_id = c.id
            JOIN shifts s ON t.shift_id = s.id
            JOIN users u ON s.driver_id = u.id
            WHERE YEAR(t.start_time) = ? AND t.passenger_approval = 'approved'";
    $params = [$year];
    if ($driver_id !== 'ALL') {
        $sql .= " AND u.id = ?";
        $params[] = $driver_id;
    }
    $sql .= " GROUP BY c.id ORDER BY total_amount DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}
?>
