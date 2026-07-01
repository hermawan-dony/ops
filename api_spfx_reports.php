<?php
// Set CORS headers to allow SharePoint Online to fetch data
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';

$action = $_GET['action'] ?? 'detail';

if ($action === 'detail') {
    $driver_id = $_POST['driver_id'] ?? $_GET['driver_id'] ?? 'ALL';
    $start_date = $_POST['start_date'] ?? $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_POST['end_date'] ?? $_GET['end_date'] ?? date('Y-m-d');

    $sql = "SELECT t.*, s.shift_date, u.full_name as driver_name, c.car_no, d.name as dest_name, p.name as pass_name, s.approval_status as admin_approval,
            (SELECT SUM(amount) FROM trip_expenses WHERE trip_id = t.id AND expense_type = 'gasoline') as gas_amt,
            (SELECT SUM(litre) FROM trip_expenses WHERE trip_id = t.id AND expense_type = 'gasoline') as gas_litre,
            (SELECT SUM(amount) FROM trip_expenses WHERE trip_id = t.id AND expense_type = 'toll') as toll_amt,
            (SELECT SUM(amount) FROM trip_expenses WHERE trip_id = t.id AND expense_type = 'lunch') as lunch_amt,
            (SELECT SUM(amount) FROM trip_expenses WHERE trip_id = t.id AND expense_type = 'others') as others_amt,
            (SELECT SUM(amount) FROM trip_expenses WHERE trip_id = t.id AND expense_type = 'parking') as parking_amt
            FROM trips t
            JOIN shifts s ON t.shift_id = s.id
            JOIN users u ON s.driver_id = u.id
            JOIN master_cars c ON t.car_id = c.id
            JOIN master_destinations d ON t.destination_id = d.id
            JOIN master_passengers p ON t.passenger_id = p.id
            WHERE s.shift_date BETWEEN ? AND ?";

    $params = [$start_date, $end_date];

    if ($driver_id !== 'ALL') {
        $sql .= " AND u.id = ?";
        $params[] = $driver_id;
    }

    $sql .= " ORDER BY s.shift_date ASC, t.start_time ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    // Add expense details (photos) for each trip
    foreach ($data as &$row) {
        $stmt_exp = $pdo->prepare("SELECT expense_type, amount, photo, created_at FROM trip_expenses WHERE trip_id = ?");
        $stmt_exp->execute([$row['id']]);
        $row['expense_details'] = $stmt_exp->fetchAll();
    }

    header('Content-Type: application/json');
    echo json_encode($data);
    exit;

} elseif ($action === 'annual_summary') {
    $driver_id = $_POST['driver_id'] ?? $_GET['driver_id'] ?? 'ALL';
    $year = intval($_POST['year'] ?? $_GET['year'] ?? date('Y'));

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

} elseif ($action === 'drivers') {
    // Return list of drivers for the dropdown
    $drivers = $pdo->query("SELECT id, full_name FROM users WHERE role = 'driver' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($drivers);
    exit;
}

echo json_encode(['error' => 'invalid action']);
