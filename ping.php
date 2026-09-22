<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
session_start();
echo json_encode(['status' => 'ok', 'time' => time()]);
