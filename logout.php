<?php
session_start();
$is_passenger = isset($_GET['type']) && $_GET['type'] === 'passenger';
session_destroy();
if ($is_passenger) {
    header('Location: passenger_login.php');
} else {
    header('Location: login.php');
}
exit;
?>
