<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['passenger_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$passenger_id = $_SESSION['passenger_id'];
$trip_id = $_POST['trip_id'] ?? null;
$status = $_POST['status'] ?? null;
$feedback = $_POST['feedback'] ?? '';

if (!$trip_id || !in_array($status, ['approved', 'rejected', 'pending'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Verify the trip belongs to this passenger and state transitions are valid
if ($status === 'pending') {
    // Reverting to pending (unapprove) - current status must be approved or rejected
    $stmt = $pdo->prepare("SELECT id FROM trips WHERE id = ? AND passenger_id = ? AND passenger_approval IN ('approved', 'rejected')");
} else {
    // Normal approval/rejection - current status must be pending
    $stmt = $pdo->prepare("SELECT id FROM trips WHERE id = ? AND passenger_id = ? AND passenger_approval = 'pending'");
}
$stmt->execute([$trip_id, $passenger_id]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Trip not found or state transition invalid']);
    exit;
}

$update = $pdo->prepare("UPDATE trips SET passenger_approval = ?, passenger_feedback = ? WHERE id = ?");
$success = $update->execute([$status, $feedback, $trip_id]);

echo json_encode(['success' => $success]);
