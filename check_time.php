<?php
require_once 'config.php';
header('Content-Type: text/plain');
echo "PHP Default Timezone: " . date_default_timezone_get() . "\n";
echo "PHP Current Time: " . date('Y-m-d H:i:s') . "\n";
echo "PHP Hour: " . date('H') . "\n";
try {
    $stmt = $pdo->query("SELECT NOW() as db_time, @@global.time_zone as g_tz, @@session.time_zone as s_tz");
    $row = $stmt->fetch();
    echo "DB Current Time: " . $row['db_time'] . "\n";
    echo "DB Global Timezone: " . $row['g_tz'] . "\n";
    echo "DB Session Timezone: " . $row['s_tz'] . "\n";
} catch (Exception $e) {
    echo "DB Error: " . $e->getMessage() . "\n";
}
