<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'driver') {
    header('Location: login.php');
    exit;
}

$trip_id = $_GET['trip_id'] ?? null;
if (!$trip_id) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT t.*, d.name as dest_name, p.name as pass_name FROM trips t JOIN master_destinations d ON t.destination_id = d.id JOIN master_passengers p ON t.passenger_id = p.id WHERE t.id = ? AND t.status = 'completed'");
$stmt->execute([$trip_id]);
$trip = $stmt->fetch();

if (!$trip || !$trip['approval_token']) {
    header('Location: index.php');
    exit;
}

$qr_url = "http://" . $_SERVER['HTTP_HOST'] . "/transport/approve_trip.php?token=" . $trip['approval_token'];
$expires_at = strtotime($trip['qr_expires_at']);
$now = time();
$remaining = max(0, $expires_at - $now);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trip Completed - QR Approval</title>
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #118DFF; margin: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; color: #333; }
        .card { background: #fff; padding: 30px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); width: 100%; max-width: 350px; text-align: center; }
        .title { margin-top: 0; font-size: 1.4rem; color: #118DFF; margin-bottom: 5px; }
        .subtitle { font-size: 0.9rem; color: #666; margin-bottom: 20px; }
        .qr-container { background: #f8fafc; padding: 20px; border-radius: 12px; display: inline-block; margin-bottom: 20px; }
        .timer { font-size: 1.5rem; font-weight: 700; color: #d83b01; margin-bottom: 20px; }
        .trip-info { background: #f0f9ff; padding: 15px; border-radius: 8px; font-size: 0.85rem; text-align: left; margin-bottom: 20px; }
        .trip-info strong { display: block; color: #118DFF; margin-bottom: 2px; }
        .btn-home { display: block; width: 100%; padding: 14px; background: #eee; color: #333; text-decoration: none; border-radius: 8px; font-weight: 700; transition: background 0.2s; box-sizing: border-box; }
        .btn-home:hover { background: #e0e0e0; }
    </style>
</head>
<body>
    <div class="card">
        <h2 class="title">Trip Completed!</h2>
        <p class="subtitle">Please ask <strong><?= htmlspecialchars($trip['pass_name']) ?></strong> to scan this QR code to approve the trip.</p>
        
        <div class="qr-container" id="qrcode"></div>
        
        <div class="trip-info">
            <strong>Destination:</strong> <?= htmlspecialchars($trip['dest_name']) ?>
            <strong style="margin-top: 10px;">Distance:</strong> <?= ($trip['km_end'] - $trip['km_start']) ?> KM
        </div>
        
        <a href="index.php" class="btn-home">Back to Dashboard</a>
    </div>

    <script>
        var qrData = "<?= $qr_url ?>";
        new QRCode(document.getElementById("qrcode"), { text: qrData, width: 200, height: 200, colorDark : "#000000", colorLight : "#ffffff", correctLevel : QRCode.CorrectLevel.H });
    </script>
</body>
</html>
