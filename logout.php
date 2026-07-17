<?php
session_start();
$is_passenger = isset($_GET['type']) && $_GET['type'] === 'passenger';
$uid = $_SESSION['user_id'] ?? '0';
session_destroy();
if ($is_passenger) {
    header('Location: passenger_login.php?clear_filters=' . $uid);
} else {
    header('Location: login.php?clear_filters=' . $uid);
}
exit;
?>
