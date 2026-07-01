<?php
// Set CORS headers to allow SharePoint Online to fetch data
header("Access-Control-Allow-Origin: https://framas365.sharepoint.com");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';

$action = $_GET['action'] ?? '';

// API Response helper
function sendResponse($success, $data = [], $message = '') {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data));
    exit;
}

// 1. GET DASHBOARD DATA
if ($action === 'get_dashboard_data') {
    try {
        // Fetch stats
        $stats = [
            'pending' => (int)$pdo->query("SELECT COUNT(*) FROM trips WHERE passenger_approval = 'pending' AND status = 'completed'")->fetchColumn(),
            'active_drivers' => (int)$pdo->query("SELECT COUNT(DISTINCT driver_id) FROM shifts WHERE status = 'active'")->fetchColumn(),
            'trips_today' => (int)$pdo->query("SELECT COUNT(*) FROM trips WHERE DATE(start_time) = CURDATE()")->fetchColumn(),
            'total_km_month' => (int)$pdo->query("SELECT SUM(km_end - km_start) FROM trips WHERE MONTH(start_time) = MONTH(CURRENT_DATE()) AND status = 'completed'")->fetchColumn() ?: 0,
        ];

        // Fetch Today's Trips
        $trips_stmt = $pdo->query("SELECT t.id, u.full_name as driver, COALESCE(d.name, '-') as dest, COALESCE(p.name, '-') as passenger, COALESCE(c.car_no, '-') as vehicle, 
                                          DATE_FORMAT(t.start_time, '%H:%i') as start_time, 
                                          DATE_FORMAT(t.end_time, '%H:%i') as end_time, 
                                          t.status, t.passenger_approval
                                   FROM trips t 
                                   JOIN shifts s ON t.shift_id = s.id 
                                   JOIN users u ON s.driver_id = u.id 
                                   LEFT JOIN master_destinations d ON t.destination_id = d.id 
                                   LEFT JOIN master_passengers p ON t.passenger_id = p.id
                                   LEFT JOIN master_cars c ON t.car_id = c.id
                                   WHERE DATE(t.start_time) = CURDATE()
                                   ORDER BY t.start_time DESC");
        $trips_today = $trips_stmt->fetchAll();

        // Fetch ALL Pending Trips (for drill-down)
        $pending_stmt = $pdo->query("SELECT t.id, u.full_name as driver, COALESCE(d.name, '-') as dest, COALESCE(p.name, '-') as passenger, COALESCE(c.car_no, '-') as vehicle, 
                                            DATE_FORMAT(t.start_time, '%d %b %Y %H:%i') as start_time, 
                                            DATE_FORMAT(t.end_time, '%H:%i') as end_time,
                                            t.status, t.passenger_approval
                                     FROM trips t 
                                     JOIN shifts s ON t.shift_id = s.id 
                                     JOIN users u ON s.driver_id = u.id 
                                     LEFT JOIN master_destinations d ON t.destination_id = d.id 
                                     LEFT JOIN master_passengers p ON t.passenger_id = p.id
                                     LEFT JOIN master_cars c ON t.car_id = c.id
                                     WHERE t.passenger_approval = 'pending' AND t.status = 'completed'
                                     ORDER BY t.start_time DESC");
        $pending_trips = $pending_stmt->fetchAll();

        // Fetch Active Shifts (for active drivers drill-down)
        $active_shifts_stmt = $pdo->query("SELECT s.id, u.full_name as driver, COALESCE(c.car_no, '-') as vehicle, COALESCE(c.model, '-') as vehicle_model,
                                                 s.shift_date, DATE_FORMAT(s.start_time, '%H:%i') as start_time, u.wa_no as driver_wa
                                          FROM shifts s 
                                          JOIN users u ON s.driver_id = u.id 
                                          LEFT JOIN master_cars c ON u.preferred_car_id = c.id
                                          WHERE s.status = 'active'
                                          ORDER BY u.full_name ASC");
        $active_shifts = $active_shifts_stmt->fetchAll();

        // Fetch Maintenance Alerts
        $cars = $pdo->query("SELECT car_no, model, last_service_km,
                                    (SELECT MAX(km_end) FROM trips WHERE car_id = master_cars.id) as current_km 
                             FROM master_cars")->fetchAll();
        
        $maintenance_alerts = [];
        foreach ($cars as $car) {
            $curr_km = $car['current_km'] ?: 0;
            $diff = $curr_km - $car['last_service_km'];
            $is_service_needed = $diff >= 5000;
            
            $maintenance_alerts[] = [
                'car_no' => $car['car_no'],
                'model' => $car['model'],
                'current_km' => (int)$curr_km,
                'last_service_km' => (int)$car['last_service_km'],
                'status' => $is_service_needed ? 'SERVIS!' : 'OK'
            ];
        }

        sendResponse(true, [
            'stats' => $stats,
            'trips_today' => $trips_today,
            'pending_trips' => $pending_trips,
            'active_shifts' => $active_shifts,
            'maintenance_alerts' => $maintenance_alerts
        ]);

    } catch (Exception $e) {
        sendResponse(false, [], $e->getMessage());
    }
}

// 2. APPROVE TRIP
if ($action === 'approve_passenger_trip') {
    $trip_id = (int)($_GET['trip_id'] ?? 0);
    if ($trip_id <= 0) {
        sendResponse(false, [], 'Invalid trip ID');
    }

    try {
        $stmt = $pdo->prepare("UPDATE trips SET passenger_approval = 'approved', passenger_feedback = 'Approved by Admin (SPFx)' WHERE id = ?");
        $stmt->execute([$trip_id]);
        sendResponse(true, [], 'Trip approved');
    } catch (Exception $e) {
        sendResponse(false, [], $e->getMessage());
    }
}

// 3. SEND PASSENGER WA NOTIFICATION (DELEGATED TO FASTWA CALL)
if ($action === 'send_passenger_wa') {
    $trip_id = (int)($_GET['trip_id'] ?? 0);
    if ($trip_id <= 0) {
        sendResponse(false, [], 'Invalid trip ID');
    }

    try {
        // Query trip detail to construct WhatsApp message
        $stmt = $pdo->prepare("SELECT t.*, d.name as dest, p.name as passenger_name, p.wa_no 
                               FROM trips t 
                               JOIN master_destinations d ON t.destination_id = d.id 
                               JOIN master_passengers p ON t.passenger_id = p.id 
                               WHERE t.id = ?");
        $stmt->execute([$trip_id]);
        $trip = $stmt->fetch();

        if ($trip && $trip['wa_no']) {
            $token = $trip['approval_token'];
            if (empty($token)) {
                $token = bin2hex(random_bytes(16));
                $pdo->prepare("UPDATE trips SET approval_token = ?, qr_expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?")->execute([$token, $trip_id]);
            }

            // Get total cost
            $stmt_cost = $pdo->prepare("SELECT SUM(amount) FROM trip_expenses WHERE trip_id = ?");
            $stmt_cost->execute([$trip_id]);
            $total_cost = $stmt_cost->fetchColumn() ?: 0;

            // Generate approval link pointing to production domain
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $host = $_SERVER['HTTP_HOST'];
            $uri = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
            $approval_url = $protocol . $host . $uri . "/approve_trip.php?token=" . $token;

            $msg = "✅ *TRIP ARRIVED*\nPassenger: {$trip['passenger_name']}\nDestination: {$trip['dest']}\nTime: " . date('H:i', strtotime($trip['start_time'])) . " - " . date('H:i', strtotime($trip['end_time'])) . "\nKM: {$trip['km_start']} -> {$trip['km_end']}\nTotal Cost: Rp " . number_format($total_cost, 0, ',', '.') . "\nStatus: Arrived\n\n*Passenger Approval Required:*\nPlease click link below to confirm your trip:\n" . $approval_url;

            $phone = str_replace(['+', ' '], '', $trip['wa_no']);
            if (substr($phone, 0, 1) === '0') {
                $phone = '62' . substr($phone, 1);
            }
            $fastwa_token = '989C172CB5B6C8F0983391A6945BC436';

            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL => 'https://app.fastwa.com/api/v1/8655C64C0C1B38982A7DA98BEDAB602D/send_text',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => 'api_key='.$fastwa_token.'&phone='.$phone.'&message='.urlencode($msg),
            ));
            $response = curl_exec($ch);
            curl_close($ch);

            sendResponse(true, ['fastwa_response' => $response]);
        } else {
            sendResponse(false, [], 'Passenger has no WA number or Trip not found');
        }
    } catch (Exception $e) {
        sendResponse(false, [], $e->getMessage());
    }
}

sendResponse(false, [], 'Invalid Action');
