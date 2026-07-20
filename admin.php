<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'get_shift_details') {
        $shift_id = $_GET['id'] ?? 0;
        
        // Get shift details
        $stmt = $pdo->prepare("SELECT s.*, u.full_name FROM shifts s JOIN users u ON s.driver_id = u.id WHERE s.id = ?");
        $stmt->execute([$shift_id]);
        $shift = $stmt->fetch();
        
        if ($shift) {
            // Get trips
            $stmt_trips = $pdo->prepare("SELECT t.*, d.name as dest_name, p.name as passenger_name, p.wa_no as passenger_wa, c.car_no 
                                         FROM trips t 
                                         JOIN master_destinations d ON t.destination_id = d.id 
                                         JOIN master_passengers p ON t.passenger_id = p.id 
                                         JOIN master_cars c ON t.car_id = c.id 
                                         WHERE t.shift_id = ?");
            $stmt_trips->execute([$shift_id]);
            $trips = $stmt_trips->fetchAll();
            
            echo json_encode([
                'success' => true,
                'shift' => $shift,
                'trips' => $trips
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Shift not found']);
        }
        exit;
    } elseif ($_GET['action'] === 'send_passenger_wa') {
        $trip_id = $_GET['trip_id'] ?? 0;
        
        // Get trip details
        $stmt = $pdo->prepare("SELECT t.*, d.name as dest, p.name as passenger_name, p.wa_no 
                               FROM trips t 
                               JOIN master_destinations d ON t.destination_id = d.id 
                               JOIN master_passengers p ON t.passenger_id = p.id 
                               WHERE t.id = ?");
        $stmt->execute([$trip_id]);
        $trip = $stmt->fetch();
        
        if ($trip) {
            $wa_no = $trip['wa_no'];
            if ($wa_no) {
                // Generate token if not exists
                $token = $trip['approval_token'];
                if (empty($token)) {
                    $token = bin2hex(random_bytes(16));
                    $pdo->prepare("UPDATE trips SET approval_token = ?, qr_expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?")->execute([$token, $trip_id]);
                }
                
                // Fetch all pending completed trips for this passenger to construct grouped WA message
                $stmt_pending = $pdo->prepare("SELECT t.*, d.name as dest 
                                               FROM trips t 
                                               JOIN master_destinations d ON t.destination_id = d.id 
                                               WHERE t.passenger_id = ? AND t.passenger_approval = 'pending' AND t.status = 'completed'");
                $stmt_pending->execute([$trip['passenger_id']]);
                $pending_trips = $stmt_pending->fetchAll();
                
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                $host = $_SERVER['HTTP_HOST'];
                $uri = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                $approval_url = $protocol . $host . $uri . "/approve_trip.php?token=" . $token;
                
                if (count($pending_trips) > 1) {
                    $dests = array_column($pending_trips, 'dest');
                    $dest_list = implode(', ', array_unique($dests));
                    $msg = "✅ *KONFIRMASI PERJALANAN MULTIPEL*\n\nHalo {$trip['passenger_name']},\nAda *" . count($pending_trips) . "* perjalanan Anda yang menunggu konfirmasi:\nTujuan: {$dest_list}\n\n*Mohon klik link di bawah untuk menyetujui semua sekaligus:*\n" . $approval_url;
                } else {
                    $stmt_cost = $pdo->prepare("SELECT SUM(amount) FROM trip_expenses WHERE trip_id = ?");
                    $stmt_cost->execute([$trip_id]);
                    $total_cost = $stmt_cost->fetchColumn() ?: 0;

                    $msg = "✅ *TRIP ARRIVED*\nPassenger: {$trip['passenger_name']}\nDestination: {$trip['dest']}\nTime: " . date('H:i', strtotime($trip['start_time'])) . " - " . date('H:i', strtotime($trip['end_time'])) . "\nKM: {$trip['km_start']} -> {$trip['km_end']}\nTotal Cost: Rp " . number_format($total_cost, 0, ',', '.') . "\nStatus: Arrived\n\n*Passenger Approval Required:*\nPlease click link below to confirm your trip:\n" . $approval_url;
                }
                
                // Direct FastWA Call to prevent loopback deadlock
                $phone = str_replace('+', '', $wa_no);
                $phone = str_replace(' ', '', $phone);
                if (substr($phone, 0, 1) == '0') { 
                    $phone = '62' . substr($phone, 1, 30); 
                }
                $fastwa_token = '989C172CB5B6C8F0983391A6945BC436';
                
                $ch = curl_init();
                curl_setopt_array($ch, array(
                    CURLOPT_URL => 'https://app.fastwa.com/api/v1/8655C64C0C1B38982A7DA98BEDAB602D/send_text',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => 'api_key='.$fastwa_token.'&phone='.$phone.'&message='.urlencode($msg),
                ));
                $response = curl_exec($ch);
                curl_close($ch);
                
                echo json_encode(['success' => true, 'response' => $response]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Passenger has no WA number']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Trip not found']);
        }
        exit;
    } elseif ($_GET['action'] === 'send_driver_wa') {
        $phone = $_POST['phone'] ?? $_GET['phone'] ?? '';
        $message = $_POST['message'] ?? $_GET['message'] ?? '';
        
        if ($phone && $message) {
            $phone = str_replace('+', '', $phone);
            $phone = str_replace(' ', '', $phone);
            if (substr($phone, 0, 1) == '0') { 
                $phone = '62' . substr($phone, 1, 30); 
            }
            $fastwa_token = '989C172CB5B6C8F0983391A6945BC436';
            
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL => 'https://app.fastwa.com/api/v1/8655C64C0C1B38982A7DA98BEDAB602D/send_text',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => 'api_key='.$fastwa_token.'&phone='.$phone.'&message='.urlencode($message),
            ));
            $response = curl_exec($ch);
            curl_close($ch);
            
            echo json_encode(['success' => true, 'response' => $response]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Missing phone or message']);
        }
        exit;
    } elseif ($_GET['action'] === 'approve_passenger_trip') {
        $trip_id = $_GET['trip_id'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM trips WHERE id = ?");
        $stmt->execute([$trip_id]);
        $trip = $stmt->fetch();
        
        if ($trip) {
            $stmt_update = $pdo->prepare("UPDATE trips SET passenger_approval = 'approved', passenger_feedback = 'Approved by Admin' WHERE id = ?");
            $stmt_update->execute([$trip_id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Trip not found']);
        }
        exit;
    }
}

// Handle Theme/Lang
if (isset($_GET['theme'])) { $_SESSION['theme'] = $_GET['theme']; header("Location: admin.php"); exit; }
if (isset($_GET['lang'])) { $_SESSION['lang'] = $_GET['lang']; header("Location: admin.php"); exit; }

// Stats & Data Fetching
$stats = [
    'pending' => $pdo->query("SELECT COUNT(*) FROM trips WHERE passenger_approval = 'pending' AND status = 'completed'")->fetchColumn() ?: 0,
    'active_drivers' => $pdo->query("SELECT COUNT(DISTINCT driver_id) FROM shifts WHERE status = 'active'")->fetchColumn(),
    'trips_today' => $pdo->query("SELECT COUNT(*) FROM trips WHERE DATE(start_time) = CURDATE()")->fetchColumn(),
    'total_km_month' => $pdo->query("SELECT SUM(km_end - km_start) FROM trips WHERE MONTH(start_time) = MONTH(CURRENT_DATE())")->fetchColumn() ?: 0,
];

// Detailed Data for Drill-down (Pre-fetched or AJAX ready)
$pending_list = $pdo->query("SELECT s.*, u.full_name, 
                                    GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') as passengers,
                                    GROUP_CONCAT(DISTINCT d.name SEPARATOR ', ') as destinations
                             FROM shifts s 
                             JOIN users u ON s.driver_id = u.id 
                             JOIN trips t ON t.shift_id = s.id
                             LEFT JOIN master_passengers p ON t.passenger_id = p.id
                             LEFT JOIN master_destinations d ON t.destination_id = d.id
                             WHERE t.passenger_approval = 'pending'
                             GROUP BY s.id
                             ORDER BY passengers ASC")->fetchAll();
$active_personnel = $pdo->query("SELECT s.*, u.full_name, u.wa_no as driver_wa, c.car_no, c.model as car_model,
                                        GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') as current_passengers,
                                        GROUP_CONCAT(DISTINCT d.name SEPARATOR ', ') as current_destinations
                                 FROM shifts s 
                                 JOIN users u ON s.driver_id = u.id 
                                 LEFT JOIN trips t ON t.shift_id = s.id AND t.status = 'ongoing'
                                 LEFT JOIN master_passengers p ON t.passenger_id = p.id
                                 LEFT JOIN master_destinations d ON t.destination_id = d.id
                                 LEFT JOIN master_cars c ON (t.car_id = c.id OR u.preferred_car_id = c.id)
                                 WHERE s.status = 'active'
                                 GROUP BY s.id
                                 ORDER BY MIN(CASE WHEN t.status = 'ongoing' THEN 0 ELSE 1 END) ASC, u.full_name ASC")->fetchAll();
$free_drivers = $pdo->query("SELECT u.*, c.car_no as pref_car, c.model as car_model 
                             FROM users u
                             LEFT JOIN master_cars c ON u.preferred_car_id = c.id
                             WHERE u.role = 'driver' 
                               AND u.id NOT IN (SELECT driver_id FROM shifts WHERE status = 'active')
                             ORDER BY u.full_name ASC")->fetchAll();
$free_cars = $pdo->query("SELECT * FROM master_cars 
                          WHERE id NOT IN (SELECT DISTINCT car_id FROM trips WHERE status = 'ongoing')
                            AND id NOT IN (SELECT DISTINCT u.preferred_car_id FROM shifts s JOIN users u ON s.driver_id = u.id WHERE s.status = 'active' AND u.preferred_car_id IS NOT NULL)
                          ORDER BY car_no ASC")->fetchAll();
$trips_today_list = $pdo->query("SELECT t.*, u.full_name as driver, d.name as dest, p.name as passenger, c.car_no as vehicle, DATE_FORMAT(t.start_time, '%d %b %Y') as f_date 
                                 FROM trips t 
                                 JOIN shifts s ON t.shift_id = s.id 
                                 JOIN users u ON s.driver_id = u.id 
                                 JOIN master_destinations d ON t.destination_id = d.id 
                                 JOIN master_passengers p ON t.passenger_id = p.id
                                 JOIN master_cars c ON t.car_id = c.id
                                 WHERE DATE(t.start_time) = CURDATE()
                                 ORDER BY u.full_name ASC, t.start_time DESC")->fetchAll();
$km_month_details = $pdo->query("SELECT t.*, u.full_name as driver, d.name as dest, p.name as passenger, c.car_no as vehicle, DATE_FORMAT(t.start_time, '%d %b %Y') as f_date, (t.km_end - t.km_start) as km
                                 FROM trips t 
                                 JOIN shifts s ON t.shift_id = s.id 
                                 JOIN users u ON s.driver_id = u.id 
                                 JOIN master_destinations d ON t.destination_id = d.id 
                                 JOIN master_passengers p ON t.passenger_id = p.id
                                 JOIN master_cars c ON t.car_id = c.id
                                 WHERE MONTH(t.start_time) = MONTH(CURRENT_DATE()) AND t.status = 'completed'
                                 ORDER BY c.car_no ASC, t.start_time DESC")->fetchAll();

// Handle WA Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'toggle_wa') {
        $new_val = $_POST['wa_status'] == '1' ? '1' : '0';
        $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'wa_notify'")->execute([$new_val]);
        header("Location: admin.php"); exit;
    } elseif ($_POST['action'] === 'toggle_mandatory_photo') {
        $new_val = $_POST['mandatory_photo_status'] == '1' ? '1' : '0';
        $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'mandatory_photo'")->execute([$new_val]);
        header("Location: admin.php"); exit;
    } elseif ($_POST['action'] === 'reset_service') {
        $car_no = $_POST['car_no'];
        $current_km = $_POST['current_km'];
        $stmt = $pdo->prepare("UPDATE master_cars SET last_service_km = ? WHERE car_no = ?");
        $stmt->execute([$current_km, $car_no]);
        header("Location: admin.php?msg=Service+reset+successful"); exit;
    }
}
$wa_notify = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'wa_notify'")->fetchColumn();
$mandatory_photo = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'mandatory_photo'")->fetchColumn();

// Maintenance Alerts
$maintenance_alerts = $pdo->query("SELECT car_no, model, (SELECT MAX(km_end) FROM trips WHERE car_id = master_cars.id) as current_km, last_service_km FROM master_cars")->fetchAll();
$fuel_data = $pdo->query("SELECT c.car_no, SUM(t.km_end - t.km_start) as total_km, SUM(e.litre) as total_litre FROM trips t JOIN master_cars c ON t.car_id = c.id JOIN trip_expenses e ON t.id = e.trip_id WHERE e.expense_type = 'gasoline' AND t.status = 'completed' GROUP BY c.id")->fetchAll();
$chart_res = $pdo->query("SELECT DATE(start_time) as d, COUNT(*) as c FROM trips WHERE start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY d ORDER BY d ASC")->fetchAll();
$chart_labels = json_encode(array_column($chart_res, 'd'));
$chart_values = json_encode(array_column($chart_res, 'c'));
$dest_res = $pdo->query("SELECT d.name, COUNT(*) as c FROM trips t JOIN master_destinations d ON t.destination_id = d.id GROUP BY d.id ORDER BY c DESC LIMIT 5")->fetchAll();
$dest_labels = json_encode(array_column($dest_res, 'name'));
$dest_values = json_encode(array_column($dest_res, 'c'));

$is_collapsed = isset($_SESSION['sidebar_collapsed']) && $_SESSION['sidebar_collapsed'];
$theme = $_SESSION['theme'] ?? 'light';
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang']; ?>" class="<?php echo $theme === 'dark' ? 'dark-mode' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <title>Analisa Data - <?php echo __('app_name'); ?></title>
    <link rel="icon" type="image/png" href="icon.png">
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --pbi-blue: #118DFF; --pbi-bg: #F3F2F1; --pbi-dark: #333;
            --sidebar-w: 240px; --sidebar-collapsed: 70px;
            --card-shadow: 0 1.6px 3.6px 0 rgba(0,0,0,0.132), 0 0.3px 0.9px 0 rgba(0,0,0,0.108);
        }
        .dark-mode { --pbi-bg: #1e293b; --pbi-dark: #f8fafc; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--pbi-bg); margin: 0; display: flex; transition: all 0.3s; color: var(--pbi-dark); }
        
        .sidebar { width: var(--sidebar-w); background: var(--card-bg); height: 100vh; position: fixed; border-right: 1px solid var(--glass-border); transition: width 0.3s; overflow: hidden; z-index: 1001; }
        body.collapsed .sidebar { width: var(--sidebar-collapsed); }
        .sidebar-header { padding: 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--glass-border); }
        .sidebar-brand { font-weight: 700; color: var(--pbi-blue); white-space: nowrap; }
        body.collapsed .sidebar-brand { display: none; }
        .toggle-btn { cursor: pointer; padding: 5px; border: 1px solid var(--glass-border); background: var(--card-bg); border-radius: 4px; color: var(--text-primary); }
        
        .nav-item { display: flex; align-items: center; padding: 12px 16px; margin: 4px 16px; border-radius: 8px; text-decoration: none; color: var(--text-secondary); font-size: 0.95rem; font-weight: 500; transition: all 0.2s ease; }
        .nav-item:hover { background: rgba(17, 141, 255, 0.05); color: var(--pbi-blue); }
        .nav-item.active { background: linear-gradient(90deg, rgba(17,141,255,0.1) 0%, rgba(17,141,255,0.02) 100%); color: var(--pbi-blue); border-left: 4px solid var(--pbi-blue); margin-left: 12px; font-weight: 700; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
        .nav-icon { min-width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; margin-right: 12px; font-size: 1.1rem; background: var(--card-bg); border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border: 1px solid var(--glass-border); transition: all 0.2s; }
        .nav-item.active .nav-icon { background: var(--pbi-blue); border: none; box-shadow: 0 4px 8px rgba(17,141,255,0.3); }
        
        body.collapsed .nav-item { margin: 4px 10px; justify-content: center; padding: 12px; }
        body.collapsed .nav-item.active { margin-left: 10px; border-left: none; }
        body.collapsed .nav-icon { margin-right: 0; }
        body.collapsed .nav-item span { display: none; }

        @media (max-width: 768px) {
            .sidebar { width: var(--sidebar-collapsed); }
            .sidebar-brand { display: none; }
            .nav-item span { display: none; }
            .nav-item { margin: 4px 10px; justify-content: center; padding: 12px; }
            .nav-item.active { margin-left: 10px; border-left: none; }
            .nav-icon { margin-right: 0; }
            .main-content { margin-left: var(--sidebar-collapsed); padding: 10px; }
            .lang-theme-footer { padding: 10px; }
            .lang-theme-footer a { font-size: 0.65rem !important; }
            .pbi-grid { grid-template-columns: 1fr 1fr; }
            .visual-box { overflow-x: auto; }
            div[style*="grid-template-columns"] { grid-template-columns: 1fr !important; }
        }

        .main-content { margin-left: var(--sidebar-w); flex: 1; padding: 16px; transition: margin-left 0.3s; }
        body.collapsed .main-content { margin-left: var(--sidebar-collapsed); }

        .pbi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px; }
        .pbi-card { 
            background: var(--card-bg); padding: 12px 16px; border-radius: 4px; 
            box-shadow: var(--card-shadow); border-top: 4px solid var(--pbi-blue); 
            border-left: 1px solid var(--glass-border); border-right: 1px solid var(--glass-border); 
            border-bottom: 1px solid var(--glass-border); cursor: pointer; transition: transform 0.2s;
        }
        .pbi-card:hover { transform: translateY(-2px); }
        .label { font-size: 0.75rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase; }
        .value { font-size: 1.8rem; font-weight: 700; margin-top: 5px; color: var(--text-primary); }

        .visual-box { background: var(--card-bg); padding: 16px; border-radius: 4px; box-shadow: var(--card-shadow); margin-bottom: 16px; border: 1px solid var(--glass-border); }
        .visual-title { font-size: 0.9rem; font-weight: 600; margin-bottom: 12px; color: var(--text-primary); }
        .pbi-table { width: 100%; border-collapse: collapse; font-size: 0.75rem; }
        .pbi-table th { text-align: left; padding: 6px 8px; border-bottom: 2px solid var(--glass-border); color: var(--text-secondary); }
        .pbi-table td { padding: 6px 8px; border-bottom: 1px solid var(--glass-border); color: var(--text-primary); }
        
        .modal-sortable { cursor: pointer; user-select: none; position: relative; }
        .modal-sortable:hover { background: rgba(17, 141, 255, 0.08) !important; }
        .modal-sortable::after { content: ' ⇅'; opacity: 0.4; font-size: 0.7rem; margin-left: 4px; }
        .modal-sortable.asc::after { content: ' ▲'; opacity: 0.9; color: var(--pbi-blue); }
        .modal-sortable.desc::after { content: ' ▼'; opacity: 0.9; color: var(--pbi-blue); }
        
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px); transition: all 0.3s ease; }
        .modal-content { 
            background: var(--card-bg); 
            margin: 3% auto; 
            padding: 32px; 
            border-radius: 16px; 
            width: 1050px; 
            max-width: 95%; 
            max-height: 85vh; 
            overflow-y: auto; 
            border: 1px solid var(--glass-border);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
            animation: modalFadeIn 0.3s ease-out;
        }
        @keyframes modalFadeIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .lang-theme-footer { position: absolute; bottom: 0; width: 100%; padding: 20px; border-top: 1px solid var(--glass-border); background: var(--card-bg); }
    </style>
</head>
<body class="<?php echo $is_collapsed ? 'collapsed' : ''; ?>">

    <?php include 'sidemenu.php'; ?>

    <div class="main-content">
        <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom: 16px;">
            <h2 style="margin:0; font-size: 1.5rem; color: var(--text-primary);"><?php echo __('executive_overview'); ?></h2>
            <div style="display: flex; align-items: center; gap: 15px;">
                <div style="background: var(--card-bg); padding: 4px 12px; border-radius: 50px; border: 1px solid var(--glass-border); display: flex; align-items: center; gap: 10px; font-size: 0.75rem;">
                    <span style="font-weight: 600;">WA:</span>
                    <span style="color: <?php echo $wa_notify == '1' ? '#107c10' : '#d83b01'; ?>; font-weight: 700;"><?php echo $wa_notify == '1' ? 'ON' : 'OFF'; ?></span>
                    <form action="" method="POST" style="margin:0;"><input type="hidden" name="action" value="toggle_wa"><input type="hidden" name="wa_status" value="<?php echo $wa_notify == '1' ? '0' : '1'; ?>"><button type="submit" class="btn" style="padding: 2px 8px; font-size: 0.7rem; background: <?php echo $wa_notify == '1' ? '#d83b01' : '#107c10'; ?>; color:#fff; border:none; border-radius:4px; cursor:pointer;"><?php echo $wa_notify == '1' ? 'Disable' : 'Enable'; ?></button></form>
                </div>
                <div style="background: var(--card-bg); padding: 4px 12px; border-radius: 50px; border: 1px solid var(--glass-border); display: flex; align-items: center; gap: 10px; font-size: 0.75rem;">
                    <span style="font-weight: 600;">Upload Foto:</span>
                    <span style="color: <?php echo $mandatory_photo == '1' ? '#107c10' : '#d83b01'; ?>; font-weight: 700;"><?php echo $mandatory_photo == '1' ? 'YES' : 'NO'; ?></span>
                    <form action="" method="POST" style="margin:0;"><input type="hidden" name="action" value="toggle_mandatory_photo"><input type="hidden" name="mandatory_photo_status" value="<?php echo $mandatory_photo == '1' ? '0' : '1'; ?>"><button type="submit" class="btn" style="padding: 2px 8px; font-size: 0.7rem; background: <?php echo $mandatory_photo == '1' ? '#d83b01' : '#107c10'; ?>; color:#fff; border:none; border-radius:4px; cursor:pointer;"><?php echo $mandatory_photo == '1' ? 'Disable' : 'Enable'; ?></button></form>
                </div>
                <div style="font-size: 0.8rem; color: var(--text-secondary); font-weight: 500;"><?php echo date('l, d F Y'); ?></div>
            </div>
        </div>

        <!-- KPI Grid with Drill-down -->
        <div class="pbi-grid">
            <div class="pbi-card" style="border-top-color: #ffb900;" onclick="showDrillDown('pending')"><div class="label"><?php echo __('pending_approval'); ?></div><div class="value"><?php echo $stats['pending']; ?></div></div>
            <div class="pbi-card" style="border-top-color: #107c10;" onclick="showDrillDown('active')"><div class="label"><?php echo __('active_drivers'); ?></div><div class="value"><?php echo $stats['active_drivers']; ?></div></div>
            <div class="pbi-card" onclick="showDrillDown('trips')"><div class="label"><?php echo __('trips_today'); ?></div><div class="value"><?php echo $stats['trips_today']; ?></div></div>
            <div class="pbi-card" style="border-top-color: #0078d4;" onclick="showDrillDown('km')"><div class="label">Total KM (Month)</div><div class="value"><?php echo number_format($stats['total_km_month']); ?></div></div>
        </div>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px;">
            <div class="visual-box"><div class="visual-title">Trip Distribution History</div><div style="height: 220px;"><canvas id="trendChart"></canvas></div></div>
            <div class="visual-box"><div class="visual-title">Destination Popularity</div><div style="height: 220px;"><canvas id="destChart"></canvas></div></div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="visual-box">
                <div class="visual-title"><?php echo __('maintenance_alerts'); ?></div>
                <table class="pbi-table">
                    <thead><tr><th>Vehicle</th><th>Current KM</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach($maintenance_alerts as $ma): 
                            $curr_km = $ma['current_km'] ?? 0;
                            $warn = ($curr_km - $ma['last_service_km']) >= 5000; ?>
                        <tr>
                            <td><strong><?php echo $ma['car_no']; ?></strong></td>
                            <td><?php echo number_format($curr_km); ?></td>
                            <td><span style="color: <?php echo $warn?'#d83b01':'#107c10'; ?>; font-weight:700;"><?php echo $warn?'SERVIS!':'OK'; ?></span></td>
                            <td>
                                <?php if ($warn): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Reset service odometer for this vehicle to current KM?')">
                                        <input type="hidden" name="action" value="reset_service">
                                        <input type="hidden" name="car_no" value="<?php echo htmlspecialchars($ma['car_no']); ?>">
                                        <input type="hidden" name="current_km" value="<?php echo $curr_km; ?>">
                                        <button type="submit" class="btn" style="padding:2px 6px; font-size:0.7rem; background:#107c10; color:#fff; border:none; border-radius:4px; cursor:pointer;">Reset</button>
                                    </form>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="visual-box">
                <div class="visual-title"><?php echo __('fuel_efficiency'); ?> (KM/L)</div>
                <table class="pbi-table">
                    <thead><tr><th>Vehicle</th><th>Mileage</th><th>Efficiency</th></tr></thead>
                    <tbody>
                        <?php foreach($fuel_data as $f): $eff = round($f['total_km']/($f['total_litre']?:1), 2); ?>
                        <tr><td><strong><?php echo $f['car_no']; ?></strong></td><td><?php echo number_format($f['total_km']); ?> KM</td><td style="font-weight:700; color:var(--pbi-blue);"><?php echo $eff; ?> KM/L</td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- DRILL DOWN MODAL -->
    <div id="drillModal" class="modal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 id="drillTitle" style="margin:0;">Detail Breakdown</h3>
                <button onclick="closeDrill()" style="background:none; border:none; cursor:pointer; font-size:1.5rem; color:var(--text-muted);">×</button>
            </div>
            <div id="drillContent" style="overflow-x:auto;"></div>
        </div>
    </div>

    <!-- WA CUSTOM MESSAGE MODAL -->
    <div id="waModal" class="modal">
        <div class="modal-content" style="width: 450px; max-width: 90%; margin: 10% auto;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0; font-size: 1.15rem; color: var(--text-primary);">Kirim WhatsApp</h3>
                <button onclick="closeWAModal()" style="background:none; border:none; cursor:pointer; font-size:1.5rem; color:var(--text-muted);">&times;</button>
            </div>
            <form id="waForm" onsubmit="submitDriverCustomWA(event)">
                <input type="hidden" id="waDriverPhone">
                <input type="hidden" id="waDriverName">
                <div style="margin-bottom:15px; font-size:0.85rem; color: var(--text-secondary);">
                    Kirim pesan WhatsApp ke Driver: <strong id="waDriverNameText" style="color: var(--text-primary);"></strong> (<span id="waDriverPhoneText"></span>)
                </div>
                <div style="margin-bottom:15px;">
                    <label class="pbi-label" style="margin-bottom:8px;">Ketik pesan Anda di bawah ini:</label>
                    <textarea id="waMessage" required placeholder="Tulis pesan..." style="width: 100%; height: 100px; padding: 12px; border: 1px solid var(--glass-border); border-radius: 8px; background: var(--bg-color); color: var(--text-primary); box-sizing: border-box; resize: vertical; font-family: inherit; font-size: 0.9rem;"></textarea>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="button" onclick="closeWAModal()" class="btn-action btn-delete" style="flex:1; padding:12px;">Cancel</button>
                    <button type="submit" class="btn-add" style="flex:2; box-shadow:none; background:#107c10; font-weight:700;">Kirim Pesan</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            document.body.classList.toggle('collapsed');
            fetch('manage_admin_action.php?action=toggle_sidebar');
        }

        const drillData = {
            pending: <?php echo json_encode($pending_list); ?>,
            active: <?php echo json_encode($active_personnel); ?>,
            free: <?php echo json_encode($free_drivers); ?>,
            freeCars: <?php echo json_encode($free_cars); ?>,
            trips: <?php echo json_encode($trips_today_list); ?>,
            kmDetails: <?php echo json_encode($km_month_details); ?>
        };

        let modalSortDirection = {};

        function filterModalTable() {
            const query = document.getElementById('modalSearch').value.toLowerCase().trim();
            const rows = document.querySelectorAll('#modalTableBody tr');
            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        }

        function sortModalTable(colIndex) {
            const tbody = document.getElementById('modalTableBody');
            if (!tbody) return;
            const rows = Array.from(tbody.querySelectorAll('tr'));
            const isAsc = !modalSortDirection[colIndex];
            modalSortDirection = {};
            modalSortDirection[colIndex] = isAsc;
            
            const headers = document.querySelectorAll('.modal-sortable');
            headers.forEach((th, idx) => {
                th.classList.remove('asc', 'desc');
                if (idx === colIndex) {
                    th.classList.add(isAsc ? 'asc' : 'desc');
                }
            });

            rows.sort((a, b) => {
                let valA = a.children[colIndex].innerText.trim();
                let valB = b.children[colIndex].innerText.trim();
                
                const numA = parseFloat(valA.replace(/,/g, ''));
                const numB = parseFloat(valB.replace(/,/g, ''));
                if (!isNaN(numA) && !isNaN(numB)) {
                    return isAsc ? numA - numB : numB - numA;
                }
                
                return isAsc ? valA.localeCompare(valB) : valB.localeCompare(valA);
            });
            
            rows.forEach(row => tbody.appendChild(row));
        }

        function showSubTab(subTabName) {
            document.querySelectorAll('.subtab-content').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.tab-btn').forEach(btn => {
                if (btn.id && btn.id.startsWith('btn-')) btn.classList.remove('active');
            });
            
            document.getElementById('subtab-' + subTabName).style.display = 'block';
            document.getElementById('btn-' + subTabName).classList.add('active');
            
            const onDutyBody = document.querySelector('#subtab-on-duty tbody');
            const freeBody = document.querySelector('#subtab-free tbody');
            const freeCarsBody = document.querySelector('#subtab-free-cars tbody');
            
            // Assign primary search id dynamically
            if (onDutyBody) onDutyBody.id = 'modalTableBodyOnDuty';
            if (freeBody) freeBody.id = 'modalTableBodyFree';
            if (freeCarsBody) freeCarsBody.id = 'modalTableBodyFreeCars';
            
            if (subTabName === 'on-duty') {
                if (onDutyBody) onDutyBody.id = 'modalTableBody';
            } else if (subTabName === 'free') {
                if (freeBody) freeBody.id = 'modalTableBody';
            } else if (subTabName === 'free-cars') {
                if (freeCarsBody) freeCarsBody.id = 'modalTableBody';
            }
            
            filterModalTable();
        }

        function sendDriverCustomWA(driverName, phone) {
            if (!phone || phone.trim() === '' || phone === 'null') {
                alert('Driver ini belum memiliki nomor WhatsApp terdaftar. Silakan lengkapi di data master.');
                return;
            }
            
            document.getElementById('waDriverPhone').value = phone;
            document.getElementById('waDriverName').value = driverName;
            document.getElementById('waDriverNameText').innerText = driverName;
            document.getElementById('waDriverPhoneText').innerText = phone;
            document.getElementById('waMessage').value = '';
            document.getElementById('waModal').style.display = 'block';
        }

        function closeWAModal() {
            document.getElementById('waModal').style.display = 'none';
        }

        function submitDriverCustomWA(event) {
            event.preventDefault();
            const phone = document.getElementById('waDriverPhone').value;
            const driverName = document.getElementById('waDriverName').value;
            const msg = document.getElementById('waMessage').value;
            const submitBtn = event.target.querySelector('button[type="submit"]');
            const oldText = submitBtn.innerText;
            
            if (!msg || msg.trim() === '') {
                alert('Pesan tidak boleh kosong.');
                return;
            }
            
            submitBtn.innerText = "Mengirim...";
            submitBtn.disabled = true;
            
            fetch(`admin.php?action=send_driver_wa&phone=${encodeURIComponent(phone)}&message=${encodeURIComponent(msg)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert(`Pesan WhatsApp berhasil dikirim ke ${driverName}!`);
                        closeWAModal();
                    } else {
                        alert(`Gagal mengirim pesan WA: ${data.message}`);
                    }
                })
                .catch(err => {
                    alert(`Gagal mengirim pesan: ${err}`);
                })
                .finally(() => {
                    submitBtn.innerText = oldText;
                    submitBtn.disabled = false;
                });
        }

        function showDrillDown(type) {
            const content = document.getElementById('drillContent');
            const title = document.getElementById('drillTitle');
            modalSortDirection = {};
            
            let filterHtml = `
                <div style="display:flex; gap:10px; margin-bottom:12px; align-items:center; flex-wrap:wrap;">
                    <input type="text" id="modalSearch" placeholder="Search in table..." oninput="filterModalTable()" class="pbi-input" style="margin-bottom:0; width: 250px; padding: 8px 12px; font-size: 0.8rem;">
                </div>
            `;
            
            let html = '';
            
            if (type === 'pending') {
                title.innerText = 'Pending Verification';
                html = filterHtml + '<table class="pbi-table"><thead><tr>';
                html += '<th class="modal-sortable" onclick="sortModalTable(0)">Date</th><th class="modal-sortable" onclick="sortModalTable(1)">Driver</th><th class="modal-sortable" onclick="sortModalTable(2)">Passenger</th><th class="modal-sortable" onclick="sortModalTable(3)">Tujuan</th><th>Actions</th></tr></thead><tbody id="modalTableBody">';
                html += drillData.pending.map(d => `<tr><td>${d.shift_date}</td><td>${d.full_name}</td><td>${d.passengers || '-'}</td><td>${d.destinations || '-'}</td><td><button onclick="viewShiftDetails(${d.id}); return false;" class="btn" style="padding:4px 8px; font-size:0.75rem; background:var(--pbi-blue); color:white; border:none; border-radius:4px; cursor:pointer;">View</button></td></tr>`).join('');
                html += '</tbody></table>';
            } else if (type === 'active') {
                title.innerText = 'Field Personnel & Assets Overview';
                filterHtml = `
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; gap:10px; flex-wrap:wrap;">
                        <div class="tab-nav" style="margin-bottom:0; box-shadow:none; padding:3px; background:rgba(0,0,0,0.05); border-radius:6px; border:none; display:flex; gap:3px;">
                            <button class="tab-btn active" id="btn-on-duty" onclick="showSubTab('on-duty')" style="padding: 6px 12px; font-size: 0.75rem; border-radius: 4px; border:none; cursor:pointer; font-weight:600;"><?php echo __('on_duty_personnel'); ?> (${drillData.active.length})</button>
                            <button class="tab-btn" id="btn-free" onclick="showSubTab('free')" style="padding: 6px 12px; font-size: 0.75rem; border-radius: 4px; border:none; cursor:pointer; font-weight:600;"><?php echo __('drivers_off'); ?> (${drillData.free.length})</button>
                            <button class="tab-btn" id="btn-free-cars" onclick="showSubTab('free-cars')" style="padding: 6px 12px; font-size: 0.75rem; border-radius: 4px; border:none; cursor:pointer; font-weight:600;"><?php echo __('vehicles_off'); ?> (${drillData.freeCars.length})</button>
                        </div>
                        <input type="text" id="modalSearch" placeholder="Search..." oninput="filterModalTable()" class="pbi-input" style="margin-bottom:0; width: 200px; padding: 6px 12px; font-size: 0.8rem;">
                    </div>
                `;
                
                let onDutyTable = `
                    <div id="subtab-on-duty" class="subtab-content">
                        <table class="pbi-table">
                            <thead>
                                <tr>
                                    <th class="modal-sortable" onclick="sortModalTable(0)">Driver</th>
                                    <th class="modal-sortable" onclick="sortModalTable(1)">Vehicle</th>
                                    <th class="modal-sortable" onclick="sortModalTable(2)">Shift Start</th>
                                    <th class="modal-sortable" onclick="sortModalTable(3)">Current Destination</th>
                                    <th class="modal-sortable" onclick="sortModalTable(4)">Current Passenger</th>
                                    <th class="modal-sortable" onclick="sortModalTable(5)">Trip Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="modalTableBody">
                                ${drillData.active.map(d => {
                                    const hasTrip = d.current_passengers || d.current_destinations;
                                    const tripStatus = hasTrip ? '<span style="color:#d83b01; font-weight:700;">ON TRIP</span>' : '<span style="color:#107c10; font-weight:700;">STANDBY / IDLE</span>';
                                    const actionBtn = d.driver_wa ? `<button onclick="sendDriverCustomWA('${d.full_name.replace(/'/g, "\\'")}', '${d.driver_wa}')" class="btn" style="padding:4px 8px; font-size:0.7rem; background:#107c10; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:600;">Send WA</button>` : '<span style="color:#94a3b8; font-size:0.7rem; font-style:italic;">No WA</span>';
                                    return `<tr>
                                        <td><strong>${d.full_name}</strong></td>
                                        <td>${d.car_no} ${d.car_model ? '(' + d.car_model + ')' : ''}</td>
                                        <td>${d.start_time}</td>
                                        <td>${d.current_destinations || '-'}</td>
                                        <td>${d.current_passengers || '-'}</td>
                                        <td>${tripStatus}</td>
                                        <td>${actionBtn}</td>
                                    </tr>`;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                `;

                let freeTable = `
                    <div id="subtab-free" class="subtab-content" style="display:none;">
                        <table class="pbi-table">
                            <thead>
                                <tr>
                                    <th class="modal-sortable" onclick="sortModalTable(0)">Driver Name</th>
                                    <th class="modal-sortable" onclick="sortModalTable(1)">Username</th>
                                    <th class="modal-sortable" onclick="sortModalTable(2)">Preferred Vehicle</th>
                                    <th class="modal-sortable" onclick="sortModalTable(3)">Last Service KM</th>
                                    <th class="modal-sortable" onclick="sortModalTable(4)">Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="modalTableBodyFree">
                                ${drillData.free.map(d => {
                                    const actionBtn = d.wa_no ? `<button onclick="sendDriverCustomWA('${d.full_name.replace(/'/g, "\\'")}', '${d.wa_no}')" class="btn" style="padding:4px 8px; font-size:0.7rem; background:#107c10; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:600;">Send WA</button>` : '<span style="color:#94a3b8; font-size:0.7rem; font-style:italic;">No WA</span>';
                                    return `<tr>
                                        <td><strong>${d.full_name}</strong></td>
                                        <td>${d.username}</td>
                                        <td>${d.pref_car ? d.pref_car + (d.car_model ? ' (' + d.car_model + ')' : '') : '-'}</td>
                                        <td>${d.last_service_km ? parseFloat(d.last_service_km).toLocaleString() : '-'} KM</td>
                                        <td><span style="color:#2563eb; font-weight:700;"><?php echo __('status_off'); ?></span></td>
                                        <td>${actionBtn}</td>
                                    </tr>`;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                `;

                let freeCarsTable = `
                    <div id="subtab-free-cars" class="subtab-content" style="display:none;">
                        <table class="pbi-table">
                            <thead>
                                <tr>
                                    <th class="modal-sortable" onclick="sortModalTable(0)">Vehicle Number</th>
                                    <th class="modal-sortable" onclick="sortModalTable(1)">Model / Type</th>
                                    <th class="modal-sortable" onclick="sortModalTable(2)">Odometer (Last Service)</th>
                                    <th class="modal-sortable" onclick="sortModalTable(3)">Status</th>
                                </tr>
                            </thead>
                            <tbody id="modalTableBodyFreeCars">
                                ${drillData.freeCars.map(c => `<tr>
                                    <td><strong>${c.car_no}</strong></td>
                                    <td>${c.model || '-'}</td>
                                    <td>${c.last_service_km ? parseFloat(c.last_service_km).toLocaleString() : '-'} KM</td>
                                    <td><span style="color:#107c10; font-weight:700;"><?php echo __('status_off'); ?></span></td>
                                </tr>`).join('')}
                            </tbody>
                        </table>
                    </div>
                `;

                html = filterHtml + onDutyTable + freeTable + freeCarsTable;
            } else if (type === 'trips') {
                title.innerText = 'Daily Transactions (Today)';
                html = filterHtml + '<table class="pbi-table"><thead><tr>';
                html += '<th class="modal-sortable" onclick="sortModalTable(0)">Date</th><th class="modal-sortable" onclick="sortModalTable(1)">Driver</th><th class="modal-sortable" onclick="sortModalTable(2)">Vehicle</th><th class="modal-sortable" onclick="sortModalTable(3)">Passenger</th><th class="modal-sortable" onclick="sortModalTable(4)">Destination</th></tr></thead><tbody id="modalTableBody">';
                html += drillData.trips.map(d => `<tr><td>${d.f_date}</td><td>${d.driver}</td><td><strong>${d.vehicle}</strong></td><td>${d.passenger}</td><td>${d.dest}</td></tr>`).join('');
                html += '</tbody></table>';
            } else if (type === 'km') {
                title.innerText = 'KM Month Detailed Breakdown';
                html = filterHtml + '<table class="pbi-table"><thead><tr>';
                html += '<th class="modal-sortable" onclick="sortModalTable(0)">Date</th><th class="modal-sortable" onclick="sortModalTable(1)">Vehicle</th><th class="modal-sortable" onclick="sortModalTable(2)">Driver</th><th class="modal-sortable" onclick="sortModalTable(3)">Destination</th><th class="modal-sortable" onclick="sortModalTable(4)">KM</th></tr></thead><tbody id="modalTableBody">';
                html += drillData.kmDetails.map(d => `<tr><td>${d.f_date}</td><td><strong>${d.vehicle}</strong></td><td>${d.driver}</td><td>${d.dest}</td><td>${d.km} KM</td></tr>`).join('');
                html += '</tbody></table>';
            }
            
            content.innerHTML = html;
            document.getElementById('drillModal').style.display = 'block';
        }

        function viewShiftDetails(shiftId) {
            const content = document.getElementById('drillContent');
            const title = document.getElementById('drillTitle');
            
            title.innerText = "Shift Details Loading...";
            content.innerHTML = "<p>Loading...</p>";
            
            fetch(`admin.php?action=get_shift_details&id=${shiftId}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        content.innerHTML = `<p style="color:red;">Error: ${data.message}</p>`;
                        return;
                    }
                    
                    const shift = data.shift;
                    const trips = data.trips;
                    
                    title.innerText = `Shift Detail: ${shift.full_name} (${shift.shift_date})`;
                    
                    let tripsHtml = '';
                    if (trips.length === 0) {
                        tripsHtml = '<p style="color:var(--text-secondary); margin: 15px 0;">No trips registered during this shift.</p>';
                    } else {
                        const renderedSendButtons = [];
                        const tripsRows = trips.map(t => {
                            let approvalBadge = `<span style="font-weight:700; color:#d83b01;">Pending</span>`;
                            if (t.passenger_approval === 'approved') {
                                approvalBadge = `<span style="font-weight:700; color:#107c10;">Approved</span>`;
                            } else if (t.passenger_approval === 'rejected') {
                                approvalBadge = `<span style="font-weight:700; color:#a80000;">Rejected</span>`;
                            }
                            
                            let actionBtn = '';
                            if (t.passenger_approval === 'pending') {
                                actionBtn = `<button onclick="approveTripAsAdmin(${t.id}, this, ${shift.id})" class="btn" style="padding:4px 8px; font-size:0.7rem; background:#0078d4; color:white; border:none; border-radius:4px; cursor:pointer;">Approve as Admin</button>`;
                            }
                            
                            return `
                                <tr>
                                    <td><strong>${t.dest_name}</strong></td>
                                    <td>${t.passenger_name} (${t.passenger_wa || '-'})</td>
                                    <td>${t.km_start} -> ${t.km_end || 'Ongoing'}</td>
                                    <td>${approvalBadge}</td>
                                    <td><span style="font-size:0.75rem; color:#475569;">${t.passenger_feedback || '-'}</span></td>
                                    <td>${actionBtn}</td>
                                </tr>
                            `;
                        }).join('');

                        tripsHtml = `
                            <h4 style="margin: 15px 0 8px 0; color: var(--text-primary);">Trips Detail:</h4>
                            <table class="pbi-table" style="margin-bottom: 20px;">
                                <thead>
                                    <tr>
                                        <th>Destination</th>
                                        <th>Passenger</th>
                                        <th>KM Start -> End</th>
                                        <th>Approval Status</th>
                                        <th>Notes/Keterangan</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${tripsRows}
                                </tbody>
                            </table>
                        `;
                    }
                    
                    let actionFormHtml = '';
                    if (shift.approval_status === 'pending') {
                        actionFormHtml = `
                            <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end; border-top: 1px solid var(--glass-border); padding-top: 15px; align-items: center;">
                                <form action="approve_shift.php" method="POST" style="margin:0;">
                                    <input type="hidden" name="shift_id" value="${shift.id}">
                                    <input type="hidden" name="action" value="reject">
                                    <button type="submit" class="btn" style="background: #d83b01; color: white; padding: 8px 16px; border:none; border-radius:4px; cursor:pointer; font-weight:600;">Reject Shift</button>
                                </form>
                                <form action="approve_shift.php" method="POST" style="margin:0; display:flex; gap: 10px; align-items: center;">
                                    <input type="hidden" name="shift_id" value="${shift.id}">
                                    <input type="hidden" name="action" value="approve">
                                    <div style="display:flex; align-items:center; gap:5px;">
                                        <label style="font-size:0.8rem; font-weight:600;">Tipe OT:</label>
                                        <select name="ot_type" class="pbi-input" style="width: 120px; margin:0; padding: 6px;" required>
                                            <option value="R" ${shift.ot_type === 'R' ? 'selected' : ''}>Regular (R)</option>
                                            <option value="H" ${shift.ot_type === 'H' ? 'selected' : ''}>Holiday (H)</option>
                                        </select>
                                    </div>
                                    <div style="display:flex; align-items:center; gap:5px;">
                                        <label style="font-size:0.8rem; font-weight:600;">Real OT:</label>
                                        <input type="number" step="0.5" name="real_ot" class="pbi-input" style="width: 70px; margin:0; padding: 6px;" value="${shift.real_ot || 0}" required>
                                    </div>
                                    <button type="submit" class="btn" style="background: #107c10; color: white; padding: 8px 16px; border:none; border-radius:4px; cursor:pointer; font-weight:600;">Approve Shift</button>
                                </form>
                            </div>
                        `;
                    }
                    
                    content.innerHTML = `
                        <div style="background:var(--pbi-bg); padding:12px; border-radius:6px; margin-bottom:15px; font-size:0.85rem; border:1px solid var(--glass-border);">
                            <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
                                <span><strong>Driver:</strong> ${shift.full_name}</span>
                                <span><strong>Date:</strong> ${shift.shift_date}</span>
                            </div>
                            <div style="display:flex; justify-content:space-between;">
                                <span><strong>Start:</strong> ${shift.start_time}</span>
                                <span><strong>End:</strong> ${shift.end_time || 'Active'}</span>
                            </div>
                        </div>
                        ${tripsHtml}
                        ${actionFormHtml}
                    `;
                })
                .catch(err => {
                    content.innerHTML = `<p style="color:red;">Failed to fetch details: ${err}</p>`;
                });
        }
        
        function sendPassengerWA(tripId, btn) {
            const oldText = btn.innerText;
            btn.innerText = "Sending...";
            btn.disabled = true;
            btn.style.opacity = 0.6;
            
            fetch(`admin.php?action=send_passenger_wa&trip_id=${tripId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        btn.innerText = "Sent!";
                        btn.style.background = "#555";
                    } else {
                        alert("Error sending WA: " + data.message);
                        btn.innerText = oldText;
                        btn.disabled = false;
                        btn.style.opacity = 1;
                    }
                })
                .catch(err => {
                    alert("Network error: " + err);
                    btn.innerText = oldText;
                    btn.disabled = false;
                    btn.style.opacity = 1;
                });
        }

        function approveTripAsAdmin(tripId, btn, shiftId) {
            if (!confirm("Are you sure you want to approve this trip as Admin?")) return;
            const oldText = btn.innerText;
            btn.innerText = "Approving...";
            btn.disabled = true;
            btn.style.opacity = 0.6;
            
            fetch(`admin.php?action=approve_passenger_trip&trip_id=${tripId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        btn.innerText = "Approved!";
                        btn.style.background = "#555";
                        viewShiftDetails(shiftId); // Refresh details modal content
                    } else {
                        alert("Error approving trip: " + data.message);
                        btn.innerText = oldText;
                        btn.disabled = false;
                        btn.style.opacity = 1;
                    }
                })
                .catch(err => {
                    alert("Network error: " + err);
                    btn.innerText = oldText;
                    btn.disabled = false;
                    btn.style.opacity = 1;
                });
        }

        function closeDrill() { document.getElementById('drillModal').style.display = 'none'; }

        new Chart(document.getElementById('trendChart'), { type: 'line', data: { labels: <?php echo $chart_labels; ?>, datasets: [{ data: <?php echo $chart_values; ?>, borderColor: '#118DFF', backgroundColor: 'rgba(17, 141, 255, 0.1)', fill: true, tension: 0.4, pointRadius: 0 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { display: false }, y: { display: false } } } });
        new Chart(document.getElementById('destChart'), { type: 'doughnut', data: { labels: <?php echo $dest_labels; ?>, datasets: [{ data: <?php echo $dest_values; ?>, backgroundColor: ['#118DFF', '#12239E', '#E66C37', '#6B007B', '#E044A7'], borderWidth: 0 }] }, options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'right', labels: { boxWidth: 8, font: { size: 9 }, color: '<?php echo $theme=='dark'?'#f8fafc':'#666'; ?>' } } } } });
    </script>
</body>
</html>
