<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['passenger_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$passenger_id = $_SESSION['passenger_id'];
$old_pin = $_POST['old_pin'] ?? '';
$new_pin = $_POST['new_pin'] ?? '';

if (strlen($new_pin) !== 6 || !is_numeric($new_pin)) {
    echo json_encode(['success' => false, 'error' => 'New PIN must be exactly 6 digits.']);
    exit;
}

$stmt = $pdo->prepare("SELECT pin FROM master_passengers WHERE id = ?");
$stmt->execute([$passenger_id]);
$passenger = $stmt->fetch();

if (!$passenger || !password_verify($old_pin, $passenger['pin'])) {
    echo json_encode(['success' => false, 'error' => 'Current PIN is incorrect.']);
    exit;
}

$hashed_new_pin = password_hash($new_pin, PASSWORD_DEFAULT);
$update = $pdo->prepare("UPDATE master_passengers SET pin = ? WHERE id = ?");
$success = $update->execute([$hashed_new_pin, $passenger_id]);

echo json_encode(['success' => $success]);
