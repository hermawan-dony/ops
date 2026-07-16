<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    exit(json_encode([]));
}

$driver_id = $_POST['driver_id'] ?? 'ALL';
$start_date = $_POST['start_date'] ?? date('Y-m-01');
$end_date = $_POST['end_date'] ?? date('Y-m-d');

$sql = "SELECT t.*, s.shift_date, s.driver_id, u.full_name as driver_name, c.car_no, d.name as dest_name, p.name as pass_name, s.approval_status as admin_approval,
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
    $stmt_exp = $pdo->prepare("SELECT id, expense_type, amount, photo, supervisor_note, approval_status, created_at FROM trip_expenses WHERE trip_id = ?");
    $stmt_exp->execute([$row['id']]);
    $row['expense_details'] = $stmt_exp->fetchAll();
}

header('Content-Type: application/json');
echo json_encode($data);
?>
