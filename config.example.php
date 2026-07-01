<?php
// Set session lifetime to 30 days (30 * 24 * 60 * 60 seconds)
ini_set('session.cookie_lifetime', 2592000);
ini_set('session.gc_maxlifetime', 2592000);
session_start();

// Set timezone to Asia/Jakarta
date_default_timezone_set('Asia/Jakarta');

// Language handling
if (isset($_GET['lang'])) {
    $lang_choice = $_GET['lang'] === 'id' ? 'id' : 'en';
    $_SESSION['lang'] = $lang_choice;
    setcookie('lang', $lang_choice, time() + (365 * 24 * 60 * 60), "/"); // Persist for 1 year
}
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $_COOKIE['lang'] ?? 'en';
}

// Theme handling
if (isset($_GET['theme'])) {
    $_SESSION['theme'] = $_GET['theme'] === 'dark' ? 'dark' : 'light';
}
if (!isset($_SESSION['theme'])) $_SESSION['theme'] = 'light';

require_once 'lang.php';

// Detect Environment and Set DB Credentials
$http_host = $_SERVER['HTTP_HOST'] ?? '';
if (strpos($http_host, 'ops.framas.co.id') !== false) {
    // Production Hosting
    $host = 'localhost';
    $db   = 'framas_ops';
    $user = 'framas_root';
    $pass = 'YOUR_PROD_PASSWORD_HERE'; 
} else {
    // Local Environment
    $host = '192.168.170.102';
    $db   = 'transport_db';
    $user = 'beacukai';
    $pass = 'YOUR_LOCAL_PASSWORD_HERE'; 
}
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $pdo->exec("SET time_zone = '+07:00'");
    
    // Auto-end shifts that exceed 24 hours
    $stmt_old_shifts = $pdo->query("SELECT id, shift_date, start_time FROM shifts WHERE status = 'active'");
    $old_shifts = $stmt_old_shifts->fetchAll();
    foreach ($old_shifts as $s) {
        $start_ts = strtotime($s['shift_date'] . ' ' . $s['start_time']);
        if ($start_ts && (time() - $start_ts > 24 * 3600)) {
            // Update shift status
            $stmt_end = $pdo->prepare("UPDATE shifts 
                                       SET end_time = '00:00:00', status = 'completed', approval_status = 'pending', overtime_early = 0, overtime_late = 0 
                                       WHERE id = ?");
            $stmt_end->execute([$s['id']]);
            
            // Auto-complete ongoing trips under this shift
            $stmt_end_trips = $pdo->prepare("UPDATE trips 
                                             SET status = 'completed', end_time = start_time, km_end = km_start, passenger_approval = 'rejected', passenger_feedback = 'Auto-closed due to shift timeout (>24h)' 
                                             WHERE shift_id = ? AND status = 'ongoing'");
            $stmt_end_trips->execute([$s['id']]);
        }
    }
    // Ensure mandatory_photo setting exists
    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'mandatory_photo'");
    $stmt_check->execute();
    if ($stmt_check->fetchColumn() == 0) {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('mandatory_photo', '1')")->execute();
    }
    
    // Ensure destination_id and passenger_id allow NULL
    try {
        $pdo->exec("ALTER TABLE trips MODIFY COLUMN destination_id INT NULL");
        $pdo->exec("ALTER TABLE trips MODIFY COLUMN passenger_id INT NULL");
    } catch (\Exception $ex) {}

    // Ensure reset_token and reset_expires columns exist in master_passengers (production auto-migration)
    try {
        $pdo->exec("ALTER TABLE master_passengers ADD COLUMN reset_token VARCHAR(255) NULL");
    } catch (\Exception $ex) {}
    try {
        $pdo->exec("ALTER TABLE master_passengers ADD COLUMN reset_expires DATETIME NULL");
    } catch (\Exception $ex) {}
} catch (\PDOException $e) {
    // Fail silently in UI or show a friendly message, log the actual error
    die('Database connection failed. Please check config.php');
} catch (\Exception $e) {
    // Other errors fail silently
}
?>
