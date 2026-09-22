<?php
require_once 'config.php';

if (isset($_GET['spfx_token']) && $_GET['spfx_token'] === 'framas_admin_123') {
    $_SESSION['user_id'] = 999;
    $_SESSION['role'] = 'admin';
    $_SESSION['lang'] = 'en';
    $_SESSION['is_iframe'] = true;
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php'); exit;
}

// Handle Theme/Lang
if (isset($_GET['theme'])) { $_SESSION['theme'] = $_GET['theme']; header("Location: master_data.php"); exit; }
if (isset($_GET['lang'])) { $_SESSION['lang'] = $_GET['lang']; header("Location: master_data.php"); exit; }

// Handle Teams Reminder Action for Passengers
if (isset($_GET['action']) && $_GET['action'] === 'send_reminder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $passenger_id = intval($_POST['passenger_id'] ?? 0);
    
    if (!$passenger_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid passenger ID.']);
        exit;
    }
    
    // Fetch passenger info
    $stmt = $pdo->prepare("SELECT * FROM master_passengers WHERE id = ?");
    $stmt->execute([$passenger_id]);
    $passenger = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$passenger || empty($passenger['email'])) {
        echo json_encode(['success' => false, 'message' => 'Passenger email address is missing.']);
        exit;
    }
    
    // Calculate Monday to Sunday date range for Last Week
    $today_time = time();
    $day_of_week = intval(date('N', $today_time)); // 1 = Mon, 7 = Sun
    $this_mon = strtotime("-" . ($day_of_week - 1) . " days", strtotime(date('Y-m-d', $today_time)));
    $last_mon = strtotime("-7 days", $this_mon);
    $last_sun = strtotime("+6 days", $last_mon);

    $start_date = date('Y-m-d', $last_mon);
    $end_date = date('Y-m-d', $last_sun);

    // Fetch pending shifts for drivers under this supervisor for last week
    $sql = "SELECT s.*, u.full_name as driver_name 
            FROM shifts s 
            JOIN users u ON s.driver_id = u.id 
            WHERE u.supervisor_id = ? 
              AND s.shift_date BETWEEN ? AND ? 
              AND s.status = 'completed'
              AND s.approval_status = 'pending' 
              AND s.real_ot > 0 
            ORDER BY s.shift_date ASC, u.full_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$passenger_id, $start_date, $end_date]);
    $pending_shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $driver_summary = [];
    $total_hours = 0.00;
    foreach ($pending_shifts as $s) {
        $dname = $s['driver_name'];
        if (!isset($driver_summary[$dname])) {
            $driver_summary[$dname] = ['hours' => 0.00, 'shifts_count' => 0];
        }
        $ot = floatval($s['real_ot'] ?: 0);
        $driver_summary[$dname]['hours'] += $ot;
        $driver_summary[$dname]['shifts_count']++;
        $total_hours += $ot;
    }

    $secret_key = 'framas_shift_secret_2026';
    $token = md5($passenger_id . $start_date . $end_date . $secret_key);
    $approval_url = "https://ops.framas.co.id/passenger_dashboard.php?supervisor_id=" . $passenger_id . "&start_date=" . urlencode($start_date) . "&end_date=" . urlencode($end_date) . "&token=" . $token;

    $start_fmt = date('d M Y', strtotime($start_date));
    $end_fmt = date('d M Y', strtotime($end_date));

    $msg = "Dear <b>" . htmlspecialchars($passenger['name']) . "</b>,<br><br>";
    if (!empty($driver_summary)) {
        $msg .= "Please review and approve the driver overtime claims for last week's period (<b>" . $start_fmt . " to " . $end_fmt . "</b>):<br><br>";
        foreach ($driver_summary as $dname => $info) {
            $day_label = $info['shifts_count'] > 1 ? "days" : "day";
            $msg .= "• <b>" . htmlspecialchars($dname) . "</b>: " . number_format($info['hours'], 2) . " Hours (" . $info['shifts_count'] . " " . $day_label . ")<br>";
        }
        $shift_label = count($pending_shifts) > 1 ? "pending shifts" : "pending shift";
        $msg .= "<br>Total: <b>" . number_format($total_hours, 2) . " Hours</b> (" . count($pending_shifts) . " " . $shift_label . ").<br><br>";
        $msg .= "👉 Please click the link below to review and approve:<br>";
        $msg .= "<a href=\"" . $approval_url . "\"><b>Open Overtime Approval Dashboard</b></a><br><br>";
    } else {
        $msg .= "Here is your direct access link for the Driver Operations Dashboard for last week's period (<b>" . $start_fmt . " to " . $end_fmt . "</b>):<br><br>";
        $msg .= "👉 Please click the link below to review your driver's shifts and overtime:<br>";
        $msg .= "<a href=\"" . $approval_url . "\"><b>Open Driver Dashboard</b></a><br><br>";
    }
    $msg .= "<small><i>Automated notification from Framas Transport System.</i></small>";

    $teams_api_url = "https://api.framas.web.id/framas-api/teams/send.php?to=" . urlencode($passenger['email']) . "&msg=" . urlencode($msg);

    // Send Teams notification via cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $teams_api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200) {
        echo json_encode(['success' => true, 'message' => 'Reminder link sent successfully to ' . $passenger['name']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Teams API returned code: ' . $http_code]);
    }
    exit;
}

// Data Fetching
$drivers = $pdo->query("SELECT u.*, c.car_no as pref_car, p.name as supervisor_name FROM users u LEFT JOIN master_cars c ON u.preferred_car_id = c.id LEFT JOIN master_passengers p ON u.supervisor_id = p.id ORDER BY u.role ASC, u.full_name ASC")->fetchAll();
$cars = $pdo->query("SELECT * FROM master_cars ORDER BY car_no ASC")->fetchAll();
$destinations = $pdo->query("SELECT * FROM master_destinations ORDER BY name ASC")->fetchAll();
$passengers = $pdo->query("SELECT * FROM master_passengers ORDER BY name ASC")->fetchAll();
$holidays = $pdo->query("SELECT * FROM master_holidays ORDER BY holiday_date DESC")->fetchAll();

$is_collapsed = isset($_SESSION['sidebar_collapsed']) && $_SESSION['sidebar_collapsed'];
$theme = $_SESSION['theme'] ?? 'light';
$is_iframe = isset($_GET['spfx_token']) || isset($_SESSION['is_iframe']);
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang']; ?>" class="<?php echo $theme === 'dark' ? 'dark-mode' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo __('master_data'); ?> - <?php echo __('app_name'); ?></title>
    <link rel="icon" type="image/png" href="icon.png">
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        .nav-item.active .nav-icon { background: var(--pbi-blue); border: none; box-shadow: 0 4px 8px rgba(17,141,255,0.3); color: #fff; }
        
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
            .tab-nav { flex-wrap: wrap; }
            .data-card { overflow-x: auto; }
        }

        .main-content { margin-left: var(--sidebar-w); flex: 1; padding: 16px; transition: margin-left 0.3s; }
        body.collapsed .main-content { margin-left: var(--sidebar-collapsed); }

        .tab-nav { display: flex; gap: 5px; margin-bottom: 16px; background: var(--card-bg); padding: 5px; border-radius: 8px; box-shadow: var(--card-shadow); width: fit-content; border: 1px solid var(--glass-border); }
        .tab-btn { padding: 10px 20px; border: none; background: transparent; cursor: pointer; border-radius: 6px; font-weight: 600; color: var(--text-secondary); font-size: 0.85rem; }
        .tab-btn.active { background: var(--pbi-blue); color: #fff; }

        .data-card { background: var(--card-bg); padding: 16px; border-radius: 8px; box-shadow: var(--card-shadow); border: 1px solid var(--glass-border); }
        .pbi-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
        .pbi-table th { text-align: left; padding: 8px; border-bottom: 2px solid var(--glass-border); color: var(--text-secondary); }
        .pbi-table td { padding: 8px; border-bottom: 1px solid var(--glass-border); color: var(--text-primary); }
        
        .btn-action { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.75rem; font-weight: 600; text-decoration: none; display: inline-block; }
        .btn-delete { background: #fff1f1; color: #d83b01; }
        .btn-edit { background: #f0f9ff; color: #118DFF; margin-right: 5px; }
        .btn-add { background: var(--pbi-blue); color: #fff; padding: 10px 20px; border-radius: 6px; border: none; cursor: pointer; font-weight: 700; box-shadow: 0 4px 12px rgba(17,141,255,0.2); }
        
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); }
        .modal-content { background: var(--card-bg); margin: 5% auto; padding: 32px; border-radius: 16px; width: 90%; max-width: 600px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); border: 1px solid var(--glass-border); box-sizing: border-box; }
        .pbi-input { width: 100%; padding: 12px; margin-bottom: 15px; border: 1px solid var(--glass-border); border-radius: 8px; background: var(--bg-color); color: var(--text-primary); box-sizing: border-box; }
        .pbi-label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 6px; }
        #formFields.grid-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; }
        #formFields.grid-2col .pbi-input { margin-bottom: 0; }
        #formFields.grid-2col .pbi-label { margin-bottom: 4px; }

        .sortable-header { cursor: pointer; user-select: none; position: relative; }
        .sortable-header:hover { background: rgba(17, 141, 255, 0.08) !important; }
        .sortable-header::after { content: ' ⇅'; opacity: 0.4; font-size: 0.7rem; margin-left: 4px; }
        .sortable-header.asc::after { content: ' ▲'; opacity: 0.9; color: var(--pbi-blue); }
        .sortable-header.desc::after { content: ' ▼'; opacity: 0.9; color: var(--pbi-blue); }
        .control-panel { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; gap: 10px; flex-wrap: wrap; }

        .lang-theme-footer { position: absolute; bottom: 0; width: 100%; padding: 20px; border-top: 1px solid var(--glass-border); background: var(--card-bg); }
        
        body.is-iframe .sidebar { display: none !important; }
        body.is-iframe .main-content { margin-left: 0 !important; width: 100% !important; padding: 10px; }
    </style>
</head>
<body class="<?php echo $is_collapsed ? 'collapsed' : ''; ?> <?php echo $is_iframe ? 'is-iframe' : ''; ?>">

    <?php include 'sidemenu.php'; ?>

    <div class="main-content">
        <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom: 16px;">
            <h2 style="margin:0; font-size: 1.5rem;"><?php echo __('master_data'); ?></h2>
            <button class="btn-add" id="masterAddBtn" onclick="openAddModal()"><?php echo __('add_new_record'); ?></button>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div style="background-color: #fde8e8; border: 1px solid #f8b4b4; color: #9b1c1c; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 0.85rem; display: flex; justify-content: space-between; align-items: center; font-weight: 600;">
                <span>⚠️ <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></span>
                <span style="cursor:pointer; font-size: 1.2rem; font-weight: bold; line-height: 1;" onclick="this.parentElement.style.display='none'">&times;</span>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div style="background-color: #def7ec; border: 1px solid #bcf0da; color: #03543f; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 0.85rem; display: flex; justify-content: space-between; align-items: center; font-weight: 600;">
                <span>✅ <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></span>
                <span style="cursor:pointer; font-size: 1.2rem; font-weight: bold; line-height: 1;" onclick="this.parentElement.style.display='none'">&times;</span>
            </div>
        <?php endif; ?>

        <div class="tab-nav" id="masterTabNav">
            <button class="tab-btn active" data-type="driver" onclick="showTab('drivers', this)"><?php echo __('drivers'); ?></button>
            <button class="tab-btn" data-type="car" onclick="showTab('cars', this)"><?php echo __('vehicles'); ?></button>
            <button class="tab-btn" data-type="destination" onclick="showTab('destinations', this)"><?php echo __('destinations'); ?></button>
            <button class="tab-btn" data-type="passenger" onclick="showTab('passengers', this)"><?php echo __('passengers'); ?></button>
            <button class="tab-btn" data-type="holiday" onclick="showTab('holidays', this)"><?php echo __('holidays'); ?></button>
        </div>

        <!-- DRIVERS TAB -->
        <div id="tab-drivers" class="data-tab data-card">
            <div class="control-panel">
                <input type="text" placeholder="<?php echo __('search_drivers'); ?>" oninput="searchTable('drivers', this.value)" class="pbi-input" style="margin-bottom:0; width: 250px; padding: 8px 12px; font-size: 0.85rem;">
                <button class="btn-action btn-delete" style="display:none; padding: 8px 12px;" id="bulk-delete-drivers" onclick="bulkDelete('driver', 'drivers')"><?php echo __('delete_selected'); ?></button>
            </div>
            <table class="pbi-table">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" onclick="toggleSelectAll('drivers', this)"></th>
                        <th class="sortable-header" onclick="sortTable('drivers', 1)"><?php echo __('col_name'); ?></th>
                        <th class="sortable-header" onclick="sortTable('drivers', 2)"><?php echo __('col_username'); ?></th>
                        <th class="sortable-header" onclick="sortTable('drivers', 3)"><?php echo __('col_nik'); ?></th>
                        <th class="sortable-header" onclick="sortTable('drivers', 4)"><?php echo __('col_wa_no'); ?></th>
                        <th class="sortable-header" onclick="sortTable('drivers', 5)"><?php echo __('col_default_vehicle'); ?></th>
                        <th class="sortable-header" onclick="sortTable('drivers', 6)"><?php echo __('col_supervisor'); ?></th>
                        <th><?php echo __('col_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($drivers as $d): ?>
                    <tr>
                        <td><input type="checkbox" class="select-row-drivers" value="<?php echo $d['id']; ?>" onchange="updateActionButtons('drivers')"></td>
                        <td><strong><?php echo htmlspecialchars($d['full_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($d['username']); ?></td>
                        <td><?php echo htmlspecialchars($d['nik'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($d['wa_no'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($d['pref_car'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($d['supervisor_name'] ?? '-'); ?></td>
                        <td>
                            <button class="btn-action btn-edit" onclick="openEditModal('driver', <?php echo htmlspecialchars(json_encode($d)); ?>)"><?php echo __('btn_edit'); ?></button>
                            <button type="button" class="btn-action btn-delete" onclick="confirmDeleteRecord('driver', <?php echo $d['id']; ?>, <?php echo htmlspecialchars(json_encode($d['full_name'])); ?>)"><?php echo __('btn_delete'); ?></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- VEHICLES TAB -->
        <div id="tab-cars" class="data-tab data-card" style="display:none;">
            <div class="control-panel">
                <input type="text" placeholder="<?php echo __('search_vehicles'); ?>" oninput="searchTable('cars', this.value)" class="pbi-input" style="margin-bottom:0; width: 250px; padding: 8px 12px; font-size: 0.85rem;">
                <button class="btn-action btn-delete" style="display:none; padding: 8px 12px;" id="bulk-delete-cars" onclick="bulkDelete('car', 'cars')"><?php echo __('delete_selected'); ?></button>
            </div>
            <table class="pbi-table">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" onclick="toggleSelectAll('cars', this)"></th>
                        <th class="sortable-header" onclick="sortTable('cars', 1)"><?php echo __('col_car_no'); ?></th>
                        <th class="sortable-header" onclick="sortTable('cars', 2)"><?php echo __('col_model'); ?></th>
                        <th class="sortable-header" onclick="sortTable('cars', 3)"><?php echo __('col_service_km'); ?></th>
                        <th><?php echo __('col_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($cars as $c): ?>
                    <tr>
                        <td><input type="checkbox" class="select-row-cars" value="<?php echo $c['id']; ?>" onchange="updateActionButtons('cars')"></td>
                        <td><strong><?php echo htmlspecialchars($c['car_no']); ?></strong></td>
                        <td><?php echo htmlspecialchars($c['model']); ?></td>
                        <td><?php echo number_format($c['last_service_km']); ?></td>
                        <td>
                            <button class="btn-action btn-edit" onclick="openEditModal('car', <?php echo htmlspecialchars(json_encode($c)); ?>)"><?php echo __('btn_edit'); ?></button>
                            <button type="button" class="btn-action btn-delete" onclick="confirmDeleteRecord('car', <?php echo $c['id']; ?>, <?php echo htmlspecialchars(json_encode($c['car_no'])); ?>)"><?php echo __('btn_delete'); ?></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- DESTINATIONS TAB -->
        <div id="tab-destinations" class="data-tab data-card" style="display:none;">
            <div class="control-panel">
                <input type="text" placeholder="<?php echo __('search_destinations'); ?>" oninput="searchTable('destinations', this.value)" class="pbi-input" style="margin-bottom:0; width: 250px; padding: 8px 12px; font-size: 0.85rem;">
                <div>
                    <button class="btn-action btn-edit" style="display:none; padding: 8px 12px; margin-right: 5px;" id="combine-destinations" onclick="combineSelected('destination', 'destinations')"><?php echo __('combine_selected'); ?></button>
                    <button class="btn-action btn-delete" style="display:none; padding: 8px 12px;" id="bulk-delete-destinations" onclick="bulkDelete('destination', 'destinations')"><?php echo __('delete_selected'); ?></button>
                </div>
            </div>
            <table class="pbi-table">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" onclick="toggleSelectAll('destinations', this)"></th>
                        <th class="sortable-header" onclick="sortTable('destinations', 1)"><?php echo __('col_location_name'); ?></th>
                        <th><?php echo __('col_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($destinations as $dest): ?>
                    <tr>
                        <td><input type="checkbox" class="select-row-destinations" value="<?php echo $dest['id']; ?>" onchange="updateActionButtons('destinations')"></td>
                        <td><strong><?php echo htmlspecialchars($dest['name']); ?></strong></td>
                        <td>
                            <button class="btn-action btn-edit" onclick="openEditModal('destination', <?php echo htmlspecialchars(json_encode($dest)); ?>)"><?php echo __('btn_edit'); ?></button>
                            <button type="button" class="btn-action btn-delete" onclick="confirmDeleteRecord('destination', <?php echo $dest['id']; ?>, <?php echo htmlspecialchars(json_encode($dest['name'])); ?>)"><?php echo __('btn_delete'); ?></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- PASSENGERS TAB -->
        <div id="tab-passengers" class="data-tab data-card" style="display:none;">
            <div class="control-panel">
                <input type="text" placeholder="<?php echo __('search_passengers'); ?>" oninput="searchTable('passengers', this.value)" class="pbi-input" style="margin-bottom:0; width: 250px; padding: 8px 12px; font-size: 0.85rem;">
                <div>
                    <button type="button" class="btn-action" style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 8px 12px; margin-right: 5px; cursor: pointer; font-weight: 600;" onclick="triggerAutoNotifTeams()"><?php echo __('test_run_auto_notif'); ?></button>
                    <button class="btn-action btn-edit" style="display:none; padding: 8px 12px; margin-right: 5px;" id="combine-passengers" onclick="combineSelected('passenger', 'passengers')"><?php echo __('combine_selected'); ?></button>
                    <button class="btn-action btn-delete" style="display:none; padding: 8px 12px;" id="bulk-delete-passengers" onclick="bulkDelete('passenger', 'passengers')"><?php echo __('delete_selected'); ?></button>
                </div>
            </div>
            <table class="pbi-table">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" onclick="toggleSelectAll('passengers', this)"></th>
                        <th class="sortable-header" onclick="sortTable('passengers', 1)"><?php echo __('col_name'); ?></th>
                        <th class="sortable-header" onclick="sortTable('passengers', 2)"><?php echo __('col_wa_no'); ?></th>
                        <th class="sortable-header" onclick="sortTable('passengers', 3)"><?php echo __('email'); ?></th>
                        <th class="sortable-header" onclick="sortTable('passengers', 4)"><?php echo __('col_security_pin'); ?></th>
                        <th class="sortable-header" onclick="sortTable('passengers', 5)"><?php echo __('col_oto_notif'); ?></th>
                        <th><?php echo __('col_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($passengers as $p): ?>
                    <tr>
                        <td><input type="checkbox" class="select-row-passengers" value="<?php echo $p['id']; ?>" onchange="updateActionButtons('passengers')"></td>
                        <td><strong><?php echo htmlspecialchars($p['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($p['wa_no'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($p['email'] ?? '-'); ?></td>
                        <td>
                            <?php if (!empty($p['pin'])): ?>
                                <span style="background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 0.75rem;">🔒 PIN Set</span>
                            <?php else: ?>
                                <span style="color: var(--text-muted); font-size: 0.75rem;"><?php echo __('not_set'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span onclick="toggleOtoNotif(<?php echo $p['id']; ?>, '<?php echo ($p['oto_notif'] ?? 'N') === 'Y' ? 'N' : 'Y'; ?>', this)" 
                                  style="cursor: pointer; display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 0.75rem; <?php echo ($p['oto_notif'] ?? 'N') === 'Y' ? 'background: #dcfce7; color: #15803d; border: 1px solid #86efac;' : 'background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1;'; ?>" 
                                  title="<?php echo ($_SESSION['lang'] ?? 'en') === 'id' ? 'Klik untuk ubah Oto_Notif' : 'Click to toggle Auto Notif'; ?>">
                                <?php echo ($p['oto_notif'] ?? 'N') === 'Y' ? '🔔 Y' : '🔕 N'; ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn-action btn-edit" onclick="openEditModal('passenger', <?php echo htmlspecialchars(json_encode($p)); ?>)"><?php echo __('btn_edit'); ?></button>
                            <button type="button" class="btn-action btn-delete" onclick="confirmDeleteRecord('passenger', <?php echo $p['id']; ?>, <?php echo htmlspecialchars(json_encode($p['name'])); ?>)"><?php echo __('btn_delete'); ?></button>
                            <button type="button" class="btn-action" style="background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; margin-left: 5px; cursor: pointer;" onclick="sendPassengerReminder(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($p['email'] ?? '', ENT_QUOTES); ?>')"><?php echo __('send_reminder'); ?></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- HOLIDAYS TAB -->
        <div id="tab-holidays" class="data-tab data-card" style="display:none;">
            <div class="control-panel">
                <input type="text" placeholder="<?php echo __('search_holidays'); ?>" oninput="searchTable('holidays', this.value)" class="pbi-input" style="margin-bottom:0; width: 250px; padding: 8px 12px; font-size: 0.85rem;">
                <button class="btn-action btn-delete" style="display:none; padding: 8px 12px;" id="bulk-delete-holidays" onclick="bulkDelete('holiday', 'holidays')"><?php echo __('delete_selected'); ?></button>
            </div>
            <table class="pbi-table">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" onclick="toggleSelectAll('holidays', this)"></th>
                        <th class="sortable-header" onclick="sortTable('holidays', 1)"><?php echo __('col_date'); ?></th>
                        <th class="sortable-header" onclick="sortTable('holidays', 2)"><?php echo __('col_description'); ?></th>
                        <th><?php echo __('col_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($holidays as $h): ?>
                    <tr>
                        <td><input type="checkbox" class="select-row-holidays" value="<?php echo $h['id']; ?>" onchange="updateActionButtons('holidays')"></td>
                        <td><strong><?php echo date('d M Y', strtotime($h['holiday_date'])); ?></strong></td>
                        <td><?php echo htmlspecialchars($h['description']); ?></td>
                        <td>
                            <button class="btn-action btn-edit" onclick="openEditModal('holiday', <?php echo htmlspecialchars(json_encode($h)); ?>)"><?php echo __('btn_edit'); ?></button>
                            <button type="button" class="btn-action btn-delete" onclick="confirmDeleteRecord('holiday', <?php echo $h['id']; ?>, <?php echo htmlspecialchars(json_encode($h['holiday_date'])); ?>)"><?php echo __('btn_delete'); ?></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL -->
    <div id="masterModal" class="modal">
        <div class="modal-content">
            <h3 id="modalTitle" style="margin-top:0; margin-bottom:24px;"><?php echo __('add_new_record'); ?></h3>
            <form id="masterForm" action="manage_master.php" method="POST">
                <input type="hidden" name="type" id="formType">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="formId">
                
                <div id="formFields"></div>
                
                <div style="display: flex; gap: 10px; margin-top: 10px;">
                    <button type="button" class="btn-action btn-delete" onclick="closeModal()" style="flex:1; padding:12px;"><?php echo __('btn_cancel'); ?></button>
                    <button type="submit" class="btn-add" style="flex:2; box-shadow:none;"><?php echo __('btn_save'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentType = 'driver';
        const isId = "<?php echo $_SESSION['lang'] ?? 'en'; ?>" === 'id';

        function sendPassengerReminder(id, name, email) {
            if (!email || email.trim() === '' || email === '-') {
                Swal.fire({
                    icon: 'warning',
                    title: isId ? 'Email Belum Diatur' : 'Email Not Configured',
                    text: isId 
                        ? `Alamat email Teams untuk ${name} belum diisi. Silakan edit penumpang untuk memasukkan email Teams terlebih dahulu.`
                        : `Teams email address for ${name} is missing. Please edit the passenger to set their email first.`
                });
                return;
            }

            Swal.fire({
                title: isId ? `Kirim Reminder ke ${name}?` : `Send Reminder to ${name}?`,
                text: isId 
                    ? `Sistem akan mengirimkan link login langsung (akses minggu kemarin) ke Microsoft Teams (${email}).`
                    : `The system will send a direct access link (for last week's period) to Microsoft Teams (${email}).`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#166534',
                confirmButtonText: isId ? 'Ya, Kirim Reminder!' : 'Yes, Send Reminder!',
                cancelButtonText: isId ? 'Batal' : 'Cancel',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    const formData = new FormData();
                    formData.append('passenger_id', id);
                    return fetch('master_data.php?action=send_reminder', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (!response.ok) throw new Error(response.statusText);
                        return response.json();
                    })
                    .then(data => {
                        if (!data.success) throw new Error(data.message || (isId ? 'Gagal mengirim reminder' : 'Failed to send reminder'));
                        return data;
                    })
                    .catch(error => {
                        Swal.showValidationMessage(`Request failed: ${error}`);
                    });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed && result.value && result.value.success) {
                    Swal.fire({
                        icon: 'success',
                        title: isId ? 'Berhasil Dikirim!' : 'Sent Successfully!',
                        text: result.value.message || (isId ? 'Reminder berhasil terkirim ke Teams.' : 'Reminder sent successfully to Teams.'),
                        timer: 2000,
                        showConfirmButton: false
                    });
                }
            });
        }

        function toggleOtoNotif(id, newVal, el) {
            const fd = new FormData();
            fd.append('type', 'passenger');
            fd.append('action', 'toggle_oto_notif');
            fd.append('id', id);
            fd.append('val', newVal);
            fd.append('ajax', '1');
            
            fetch('manage_master.php', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const isY = data.oto_notif === 'Y';
                    el.style.background = isY ? '#dcfce7' : '#f1f5f9';
                    el.style.color = isY ? '#15803d' : '#64748b';
                    el.style.border = isY ? '1px solid #86efac' : '1px solid #cbd5e1';
                    el.innerHTML = isY ? '🔔 Y' : '🔕 N';
                    el.setAttribute('onclick', `toggleOtoNotif(${id}, '${isY ? 'N' : 'Y'}', this)`);
                    
                    const Toast = Swal.mixin({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 1500
                    });
                    const activeText = isId ? 'Aktif (Y)' : 'Active (Y)';
                    const inactiveText = isId ? 'Nonaktif (N)' : 'Inactive (N)';
                    Toast.fire({
                        icon: 'success',
                        title: `${isId ? 'Oto_Notif' : 'Auto Notif'}: ${data.oto_notif === 'Y' ? activeText : inactiveText}`
                    });
                }
            })
            .catch(err => {
                Swal.fire('Error', (isId ? 'Gagal mengubah Oto_Notif: ' : 'Failed to update Auto Notif: ') + err, 'error');
            });
        }

        function triggerAutoNotifTeams() {
            Swal.fire({
                title: isId ? 'Jalankan Auto Notif Teams Sekarang?' : 'Run Teams Auto Notification Now?',
                text: isId 
                    ? 'Sistem akan mengecek seluruh atasan dengan Oto_Notif = "Y" yang masih memiliki shift pending minggu kemarin, lalu mengirim link approval ke Microsoft Teams mereka.'
                    : 'The system will check all supervisors with Auto Notif = "Y" who have pending overtime shifts from last week, then send approval links to their Microsoft Teams.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#1d4ed8',
                confirmButtonText: isId ? 'Ya, Jalankan!' : 'Yes, Run Now!',
                cancelButtonText: isId ? 'Batal' : 'Cancel',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    return fetch(`cron_teams_reminder.php?action=run&key=framas_shift_secret_2026&lang=${isId ? 'id' : 'en'}`)
                        .then(r => r.json())
                        .catch(e => {
                            throw new Error((isId ? 'Gagal menghubungi cron_teams_reminder.php: ' : 'Failed to reach cron_teams_reminder.php: ') + e);
                        });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    const res = result.value;
                    let summaryHtml = `<div style="text-align:left; font-size:0.85rem; max-height:300px; overflow-y:auto;">`;
                    summaryHtml += `<p><strong>${isId ? 'Waktu Eksekusi:' : 'Execution Time:'}</strong> ${res.timestamp || '-'}</p>`;
                    
                    const recipientUnit = isId ? 'orang' : (res.total_sent === 1 ? 'recipient' : 'recipients');
                    summaryHtml += `<p><strong>${isId ? 'Total Penerima Notif:' : 'Total Recipients:'}</strong> ${res.total_sent || 0} ${recipientUnit}</p>`;
                    
                    if (res.details && res.details.length > 0) {
                        summaryHtml += `<ul style="padding-left:20px;">`;
                        res.details.forEach(d => {
                            const shiftWord = isId 
                                ? 'shift pending' 
                                : (d.pending_shifts_count === 1 ? 'pending shift' : 'pending shifts');
                            const hoursWord = isId ? 'Jam' : 'Hours';
                            summaryHtml += `<li><strong>${d.name}</strong> (${d.email}): ${d.status} - ${d.pending_shifts_count} ${shiftWord} (${d.total_hours} ${hoursWord})</li>`;
                        });
                        summaryHtml += `</ul>`;
                    } else {
                        summaryHtml += `<p style="color:#64748b;"><em>${isId ? 'Tidak ada atasan dengan status pending atau Oto_Notif = Y.' : 'No supervisors found with pending overtime or Auto Notif = Y.'}</em></p>`;
                    }
                    summaryHtml += `</div>`;
                    
                    Swal.fire({
                        title: isId ? 'Hasil Eksekusi Notifikasi' : 'Notification Execution Result',
                        html: summaryHtml,
                        icon: 'info',
                        confirmButtonText: isId ? 'Tutup' : 'Close'
                    });
                }
            });
        }

        function toggleSidebar() {
            document.body.classList.toggle('collapsed');
            fetch('manage_admin_action.php?action=toggle_sidebar');
        }

        function showTab(tab, btn) {
            document.querySelectorAll('.data-tab').forEach(t => t.style.display = 'none');
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById('tab-' + tab).style.display = 'block';
            btn.classList.add('active');
            currentType = btn.getAttribute('data-type');
            localStorage.setItem('master_active_tab', tab);
        }

        window.addEventListener('DOMContentLoaded', () => {
            const activeTab = localStorage.getItem('master_active_tab') || 'drivers';
            const btn = document.querySelector(`.tab-btn[onclick*="'${activeTab}'"]`);
            if (btn) {
                showTab(activeTab, btn);
            }
        });

        function searchTable(tab, query) {
            const rows = document.querySelectorAll(`#tab-${tab} tbody tr`);
            const q = query.toLowerCase().trim();
            rows.forEach(row => {
                let text = row.innerText.toLowerCase();
                if (text.includes(q)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function sortTable(tab, colIndex) {
            const table = document.querySelector(`#tab-${tab} table`);
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            const header = table.querySelectorAll('thead th')[colIndex];
            const isAsc = !header.classList.contains('asc');
            
            table.querySelectorAll('thead th').forEach(th => {
                th.classList.remove('asc', 'desc');
            });
            
            header.classList.add(isAsc ? 'asc' : 'desc');
            
            rows.sort((a, b) => {
                let valA = a.children[colIndex].innerText.trim();
                let valB = b.children[colIndex].innerText.trim();
                
                let numA = parseFloat(valA.replace(/[^0-9.-]/g, ''));
                let numB = parseFloat(valB.replace(/[^0-9.-]/g, ''));
                
                if (!isNaN(numA) && !isNaN(numB) && !valA.includes('@') && !valA.includes('+')) {
                    return isAsc ? numA - numB : numB - numA;
                }
                
                return isAsc ? valA.localeCompare(valB) : valB.localeCompare(valA);
            });
            
            rows.forEach(row => tbody.appendChild(row));
        }

        function toggleSelectAll(tab, masterCb) {
            const checkboxes = document.querySelectorAll(`.select-row-${tab}`);
            checkboxes.forEach(cb => cb.checked = masterCb.checked);
            updateActionButtons(tab);
        }

        function updateActionButtons(tab) {
            const checkedBoxes = document.querySelectorAll(`.select-row-${tab}:checked`);
            const deleteBtn = document.getElementById(`bulk-delete-${tab}`);
            const combineBtn = document.getElementById(`combine-${tab}`);
            
            const delLabel = isId ? 'Hapus Terpilih' : 'Delete Selected';
            const combLabel = isId ? 'Gabungkan Terpilih' : 'Combine Selected';

            if (checkedBoxes.length > 0) {
                if (deleteBtn) {
                    deleteBtn.style.display = 'inline-block';
                    deleteBtn.innerText = `${delLabel} (${checkedBoxes.length})`;
                }
                if (combineBtn && checkedBoxes.length > 1) {
                    combineBtn.style.display = 'inline-block';
                    combineBtn.innerText = `${combLabel} (${checkedBoxes.length})`;
                } else if (combineBtn) {
                    combineBtn.style.display = 'none';
                }
            } else {
                if (deleteBtn) deleteBtn.style.display = 'none';
                if (combineBtn) combineBtn.style.display = 'none';
            }
        }

        function combineSelected(type, tab) {
            const checkedBoxes = document.querySelectorAll(`.select-row-${tab}:checked`);
            if (checkedBoxes.length < 2) return;
            
            const promptText = isId 
                ? `Gabungkan ${checkedBoxes.length} data terpilih.\n\nMasukkan Nama yang Seharusnya (nama tujuan/penumpang yang benar):`
                : `Combine ${checkedBoxes.length} selected records.\n\nEnter the target name (the correct replacement name):`;
            const targetName = prompt(promptText);
            if (!targetName || targetName.trim() === '') {
                alert(isId ? 'Tindakan dibatalkan. Nama tujuan/penumpang tidak boleh kosong.' : 'Action cancelled. Name cannot be empty.');
                return;
            }
            
            const ids = Array.from(checkedBoxes).map(cb => cb.value).join(',');
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'manage_master.php';
            
            const typeInput = document.createElement('input');
            typeInput.type = 'hidden';
            typeInput.name = 'type';
            typeInput.value = type;
            form.appendChild(typeInput);
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'combine';
            form.appendChild(actionInput);
            
            const targetNameInput = document.createElement('input');
            targetNameInput.type = 'hidden';
            targetNameInput.name = 'target_name';
            targetNameInput.value = targetName.trim();
            form.appendChild(targetNameInput);
            
            const idsInput = document.createElement('input');
            idsInput.type = 'hidden';
            idsInput.name = 'ids';
            idsInput.value = ids;
            form.appendChild(idsInput);
            
            document.body.appendChild(form);
            form.submit();
        }

        function bulkDelete(type, tab) {
            const checkedBoxes = document.querySelectorAll(`.select-row-${tab}:checked`);
            if (checkedBoxes.length === 0) return;
            
            const count = checkedBoxes.length;
            const confirmWord = isId ? 'OKE' : 'OK';
            const promptText = isId 
                ? `Anda akan menghapus secara massal ${count} data terpilih secara permanen.\n\nKetik "${confirmWord}" (huruf kapital) untuk melanjutkan:`
                : `You are about to permanently delete ${count} selected records.\n\nType "${confirmWord}" (capital letters) to proceed:`;
            const confirmation = prompt(promptText);
            if (confirmation !== confirmWord) {
                alert(isId ? 'Tindakan dibatalkan. Kode konfirmasi salah atau kosong.' : 'Action cancelled. Confirmation code incorrect or empty.');
                return;
            }
            
            const ids = Array.from(checkedBoxes).map(cb => cb.value).join(',');
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'manage_master.php';
            
            const typeInput = document.createElement('input');
            typeInput.type = 'hidden';
            typeInput.name = 'type';
            typeInput.value = type;
            form.appendChild(typeInput);
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'bulk_delete';
            form.appendChild(actionInput);
            
            const idsInput = document.createElement('input');
            idsInput.type = 'hidden';
            idsInput.name = 'ids';
            idsInput.value = ids;
            form.appendChild(idsInput);
            
            document.body.appendChild(form);
            form.submit();
        }

        function confirmDeleteRecord(type, id, name) {
            const typeLabels = isId ? {
                driver: 'driver',
                car: 'kendaraan',
                destination: 'tujuan',
                passenger: 'penumpang',
                holiday: 'libur tanggal'
            } : {
                driver: 'driver',
                car: 'vehicle',
                destination: 'destination',
                passenger: 'passenger',
                holiday: 'holiday'
            };
            const label = typeLabels[type] || type;
            const confirmText = isId 
                ? `Apakah Anda yakin ingin menghapus ${label}: ${name}?`
                : `Are you sure you want to delete this ${label}: ${name}?`;
            if (confirm(confirmText)) {
                window.location.href = `manage_master.php?type=${type}&action=delete&id=${id}`;
            }
        }

        function closeModal() { document.getElementById('masterModal').style.display = 'none'; }

        function openAddModal() {
            const typeNames = isId ? {
                driver: 'Pengemudi',
                car: 'Kendaraan',
                destination: 'Tujuan',
                passenger: 'Penumpang',
                holiday: 'Hari Libur'
            } : {
                driver: 'Driver',
                car: 'Vehicle',
                destination: 'Destination',
                passenger: 'Passenger',
                holiday: 'Holiday'
            };
            const label = typeNames[currentType] || currentType.toUpperCase();
            document.getElementById('modalTitle').innerText = (isId ? 'Tambah ' : 'Add ') + label;
            document.getElementById('formType').value = currentType;
            document.getElementById('formAction').value = 'add';
            document.getElementById('formId').value = '';
            renderFields(currentType);
            document.getElementById('masterModal').style.display = 'block';
        }

        function openEditModal(type, data) {
            const typeNames = isId ? {
                driver: 'Pengemudi',
                car: 'Kendaraan',
                destination: 'Tujuan',
                passenger: 'Penumpang',
                holiday: 'Hari Libur'
            } : {
                driver: 'Driver',
                car: 'Vehicle',
                destination: 'Destination',
                passenger: 'Passenger',
                holiday: 'Holiday'
            };
            const label = typeNames[type] || type.toUpperCase();
            document.getElementById('modalTitle').innerText = (isId ? 'Edit ' : 'Edit ') + label;
            document.getElementById('formType').value = type;
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formId').value = data.id;
            renderFields(type, data);
            document.getElementById('masterModal').style.display = 'block';
        }

        function renderFields(type, data = null) {
            let html = '';
            const formFields = document.getElementById('formFields');
            if (type === 'driver') {
                formFields.classList.add('grid-2col');
                html = `
                    <div>
                        <label class="pbi-label">${isId ? 'Nama Lengkap' : 'Full Name'}</label>
                        <input type="text" name="full_name" class="pbi-input" required value="${data?data.full_name:''}">
                    </div>
                    <div>
                        <label class="pbi-label">Username</label>
                        <input type="text" name="username" class="pbi-input" required value="${data?data.username:''}">
                    </div>
                    <div>
                        <label class="pbi-label">Password ${data ? (isId ? '(Kosongkan jika tidak diubah)' : '(Leave blank to keep)') : ''}</label>
                        <input type="password" name="password" class="pbi-input" ${data?'':'required'}>
                    </div>
                    <div>
                        <label class="pbi-label">NIK</label>
                        <input type="text" name="nik" class="pbi-input" maxlength="20" placeholder="${isId ? 'Masukkan NIK' : 'Enter NIK'}" value="${data && data.nik ? data.nik : ''}">
                    </div>
                    <div>
                        <label class="pbi-label">${isId ? 'Nomor WA (contoh: 628...)' : 'WA Number (e.g. 628...)'}</label>
                        <input type="text" name="wa_no" class="pbi-input" placeholder="${isId ? 'Masukkan Nomor WA' : 'Enter WA Number'}" value="${data && data.wa_no ? data.wa_no : ''}">
                    </div>
                    <div>
                        <label class="pbi-label">${isId ? 'Kendaraan Default' : 'Default Vehicle'}</label>
                        <select name="preferred_car_id" class="pbi-input">
                            <option value="">-- ${isId ? 'Tanpa Mobil' : 'No Car'} --</option>
                            <?php foreach($cars as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo $c['car_no']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="pbi-label">${isId ? 'Atasan (Supervisor)' : 'Supervisor'}</label>
                        <select name="supervisor_id" class="pbi-input">
                            <option value="">-- ${isId ? 'Tanpa Atasan' : 'No Supervisor'} --</option>
                            <?php foreach($passengers as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                `;
            } else {
                formFields.classList.remove('grid-2col');
                if (type === 'car') {
                    html = `
                        <label class="pbi-label">${isId ? 'Nomor Mobil' : 'Car Number'}</label><input type="text" name="car_no" class="pbi-input" required value="${data?data.car_no:''}">
                        <label class="pbi-label">Model</label><input type="text" name="model" class="pbi-input" required value="${data?data.model:''}">
                        <label class="pbi-label">${isId ? 'Servis Terakhir (KM)' : 'Last Service (KM)'}</label><input type="number" name="last_service_km" class="pbi-input" required value="${data?data.last_service_km:0}">
                    `;
                } else if (type === 'destination') {
                    html = `<label class="pbi-label">${isId ? 'Nama Lokasi / Tujuan' : 'Location Name'}</label><input type="text" name="name" class="pbi-input" required value="${data?data.name:''}">`;
                } else if (type === 'passenger') {
                    html = `
                        <label class="pbi-label">${isId ? 'Nama Penumpang' : 'Passenger Name'}</label><input type="text" name="name" class="pbi-input" required value="${data?data.name:''}">
                        <label class="pbi-label">${isId ? 'Nomor WA (contoh: 628...)' : 'WA Number (e.g. 628...)'}</label><input type="text" name="wa_no" class="pbi-input" value="${data && data.wa_no ? data.wa_no : ''}">
                        <label class="pbi-label">Email (Teams)</label><input type="email" name="email" class="pbi-input" value="${data && data.email ? data.email : ''}">
                        <label class="pbi-label">${isId ? 'Setel PIN Baru (4 Digit)' : 'Set New PIN (4 Digits)'}</label><input type="password" name="pin" class="pbi-input" maxlength="4" pattern="\\d{4}" placeholder="${data && data.pin ? (isId ? 'Kosongkan jika tidak diubah' : 'Leave blank to keep current PIN') : 'e.g. 1234'}">
                        <label class="pbi-label">${isId ? 'Oto_Notif (Notif Otomatis Teams)' : 'Auto Notif (Teams Auto Notif)'}</label>
                        <select name="oto_notif" class="pbi-input">
                            <option value="N" ${data && data.oto_notif === 'Y' ? '' : 'selected'}>N - ${isId ? 'Tidak Aktif (Tidak Terima Notif Otomatis)' : 'Inactive (Do not receive auto notifications)'}</option>
                            <option value="Y" ${data && data.oto_notif === 'Y' ? 'selected' : ''}>Y - ${isId ? 'Aktif (Kirim Notif Otomatis ke Teams)' : 'Active (Send auto notification to Teams)'}</option>
                        </select>
                    `;
                } else if (type === 'holiday') {
                    html = `
                        <label class="pbi-label">${isId ? 'Tanggal Libur' : 'Holiday Date'}</label><input type="date" name="holiday_date" class="pbi-input" required value="${data?data.holiday_date:''}">
                        <label class="pbi-label">${isId ? 'Keterangan' : 'Description'}</label><input type="text" name="description" class="pbi-input" required value="${data?data.description:''}">
                    `;
                }
            }
            formFields.innerHTML = html;
            
            // Set select values if editing
            if (data && type === 'driver') {
                if (data.preferred_car_id) {
                    document.querySelector('select[name="preferred_car_id"]').value = data.preferred_car_id;
                }
                if (data.supervisor_id) {
                    document.querySelector('select[name="supervisor_id"]').value = data.supervisor_id;
                }
            }
        }
    </script>
</body>
</html>
