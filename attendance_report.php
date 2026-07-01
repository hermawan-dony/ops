<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php'); exit;
}

// AJAX handler to get report data
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'get_data') {
        $driver_id = $_POST['driver_id'] ?? 'ALL';
        $start_date = $_POST['start_date'] ?? date('Y-m-21', strtotime('-1 month', strtotime(date('Y-m-01'))));
        $end_date = $_POST['end_date'] ?? date('Y-m-20');
        
        $sql = "SELECT 
                    MAX(s.id) as shift_id,
                    s.driver_id,
                    s.shift_date,
                    MIN(s.start_time) as start_time,
                    MAX(s.end_time) as end_time,
                    u.full_name as driver_name,
                    u.nik,
                    SUM(s.overtime_early) as overtime_early,
                    SUM(s.overtime_late) as overtime_late,
                    SUM(s.real_ot) as real_ot,
                    SUM(s.conv_ot) as conv_ot,
                    MAX(s.ot_type) as ot_type,
                    IF(SUM(s.status = 'active') > 0, 'active', 'completed') as status,
                    IF(SUM(s.approval_status = 'pending') > 0, 'pending', 'approved') as approval_status,
                    MAX(a.full_name) as approver_name,
                    (SELECT GROUP_CONCAT(t.id ORDER BY t.id ASC) 
                     FROM trips t 
                     JOIN shifts s2 ON t.shift_id = s2.id 
                     WHERE s2.driver_id = s.driver_id AND s2.shift_date = s.shift_date
                    ) as tx_ids
                FROM shifts s 
                JOIN users u ON s.driver_id = u.id 
                LEFT JOIN users a ON s.approved_by = a.id
                WHERE s.shift_date BETWEEN ? AND ?";
        $params = [$start_date, $end_date];
        
        if ($driver_id !== 'ALL') {
            $sql .= " AND u.id = ?";
            $params[] = $driver_id;
        }
        
        $sql .= " GROUP BY s.driver_id, s.shift_date";
        $sql .= " ORDER BY s.shift_date ASC, u.full_name ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    } elseif ($_GET['action'] === 'get_trip_details') {
        $trip_id = $_GET['trip_id'] ?? 0;
        $stmt = $pdo->prepare("SELECT t.*, s.shift_date, s.start_time as shift_start, s.end_time as shift_end, s.approval_status as admin_approval, 
                                     u.full_name as driver_name, c.car_no, c.model as car_model, d.name as dest_name, p.name as pass_name
                              FROM trips t
                              JOIN shifts s ON t.shift_id = s.id
                              JOIN users u ON s.driver_id = u.id
                              JOIN master_cars c ON t.car_id = c.id
                              JOIN master_destinations d ON t.destination_id = d.id
                              JOIN master_passengers p ON t.passenger_id = p.id
                              WHERE t.id = ?");
        $stmt->execute([$trip_id]);
        $trip = $stmt->fetch();
        
        if ($trip) {
            $stmt_exp = $pdo->prepare("SELECT expense_type, amount, litre, photo, created_at FROM trip_expenses WHERE trip_id = ?");
            $stmt_exp->execute([$trip_id]);
            $trip['expense_details'] = $stmt_exp->fetchAll();
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'data' => $trip]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Trip not found']);
        }
        exit;
    } elseif ($_GET['action'] === 'update_nik') {
        $driver_id = $_POST['driver_id'] ?? 0;
        $nik = trim($_POST['nik'] ?? '');
        if ($driver_id && $nik !== '') {
            $stmt = $pdo->prepare("UPDATE users SET nik = ? WHERE id = ?");
            $stmt->execute([$nik, $driver_id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid NIK or Driver ID']);
        }
        exit;
    } elseif ($_GET['action'] === 'update_shift') {
        $shift_id = $_POST['shift_id'] ?? 0;
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $ot_early = $_POST['overtime_early'] ?? 0.00;
        $ot_late = $_POST['overtime_late'] ?? 0.00;
        
        if ($shift_id) {
            $stmt = $pdo->prepare("UPDATE shifts 
                                   SET start_time = ?, end_time = ?, overtime_early = ?, overtime_late = ?, is_edited = 1, approval_status = 'approved' 
                                   WHERE id = ?");
            $stmt->execute([$start_time, $end_time ? $end_time : null, $ot_early, $ot_late, $shift_id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid Shift ID']);
        }
        exit;
    } elseif ($_GET['action'] === 'approve_shift') {
        $shift_id = $_POST['shift_id'] ?? $_GET['shift_id'] ?? 0;
        if ($shift_id) {
            $admin_id = $_SESSION['user_id'];
            $stmt = $pdo->prepare("UPDATE shifts SET approval_status = 'approved', approved_at = CURRENT_TIMESTAMP, approved_by = ? WHERE id = ?");
            $stmt->execute([$admin_id, $shift_id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid Shift ID']);
        }
        exit;
    } elseif ($_GET['action'] === 'recalculate') {
        try {
            $driver_id = $_POST['driver_id'] ?? 'ALL';
            $start_date = $_POST['start_date'] ?? date('Y-m-01');
            $end_date = $_POST['end_date'] ?? date('Y-m-d');
            
            $sql = "SELECT driver_id, shift_date, MIN(start_time) as start_time, MAX(end_time) as end_time, GROUP_CONCAT(id ORDER BY id DESC) as shift_ids FROM shifts WHERE is_edited = 0 AND shift_date BETWEEN ? AND ?";
            $params = [$start_date, $end_date];
            if ($driver_id !== 'ALL') {
                $sql .= " AND driver_id = ?";
                $params[] = $driver_id;
            }
            $sql .= " GROUP BY driver_id, shift_date";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $daily_shifts = $stmt->fetchAll();
            
            $updated_count = 0;
            foreach ($daily_shifts as $s) {
                $day_of_week = date('N', strtotime($s['shift_date']));
                $stmt_hol = $pdo->prepare("SELECT id FROM master_holidays WHERE holiday_date = ?");
                $stmt_hol->execute([$s['shift_date']]);
                $is_holiday = $stmt_hol->fetch() ? true : false;
                
                $ot_type = ($day_of_week >= 6 || $is_holiday) ? 'H' : 'R';

                $ot_early = 0.00;
                $ot_late = 0.00;
                
                if ($ot_type === 'H') {
                    $total_ot = 0.00;
                    if ($s['start_time'] && $s['end_time']) {
                        $start_dt = new DateTime($s['shift_date'] . ' ' . $s['start_time']);
                        $end_dt = new DateTime($s['shift_date'] . ' ' . $s['end_time']);
                        if ($end_dt < $start_dt) {
                            $end_dt->modify('+1 day');
                        }
                        $diff = $end_dt->getTimestamp() - $start_dt->getTimestamp();
                        $total_ot = round(max(0, $diff / 3600), 2);
                    }
                } else {
                    if ($s['start_time'] && $s['start_time'] <= '06:00:00') {
                        $start_dt = new DateTime($s['shift_date'] . ' ' . $s['start_time']);
                        $limit_dt = new DateTime($s['shift_date'] . ' 07:00:00');
                        $diff = $limit_dt->getTimestamp() - $start_dt->getTimestamp();
                        $ot_early = round(max(0, $diff / 3600), 2);
                    }
                    
                    if ($s['end_time']) {
                        $start_dt = new DateTime($s['shift_date'] . ' ' . $s['start_time']);
                        $end_dt = new DateTime($s['shift_date'] . ' ' . $s['end_time']);
                        if ($end_dt < $start_dt) {
                            $end_dt->modify('+1 day');
                        }
                        
                        $limit_dt = new DateTime($s['shift_date'] . ' 15:30:00');
                        if ($end_dt->getTimestamp() >= ($limit_dt->getTimestamp() + 3600)) {
                            $diff = $end_dt->getTimestamp() - $limit_dt->getTimestamp();
                            $ot_late = round(max(0, $diff / 3600), 2);
                        }
                    }
                    $total_ot = $ot_early + $ot_late;
                }
                if ($total_ot > 12) {
                    $real_ot = 12.0;
                } else {
                    $hours = floor($total_ot);
                    $mins = round(($total_ot - $hours) * 60);
                    if ($mins <= 15) {
                        $mins_rounded = 0;
                    } elseif ($mins <= 45) {
                        $mins_rounded = 30;
                    } else {
                        $mins_rounded = 60;
                    }
                    $real_ot = $hours + ($mins_rounded / 60);
                }
                
                $conv_ot = 0;
                if ($real_ot > 0) {
                    $stmt_conv = $pdo->prepare("SELECT conv_ot FROM twotcon WHERE tipe = ? AND real_ot = ?");
                    $stmt_conv->execute([$ot_type, $real_ot]);
                    $row_conv = $stmt_conv->fetch();
                    if ($row_conv && $row_conv['conv_ot'] !== null) {
                        $conv_ot = (float)$row_conv['conv_ot'];
                    }
                }
                    
                $shift_ids = explode(',', $s['shift_ids']);
                $primary_shift_id = $shift_ids[0];
                
                $up_stmt = $pdo->prepare("UPDATE shifts SET overtime_early = ?, overtime_late = ?, real_ot = ?, ot_type = ?, conv_ot = ?, approval_status = 'pending', approved_at = NULL WHERE id = ?");
                $up_stmt->execute([$ot_early, $ot_late, $real_ot, $ot_type, $conv_ot, $primary_shift_id]);
                
                for ($i = 1; $i < count($shift_ids); $i++) {
                    $up_zero = $pdo->prepare("UPDATE shifts SET overtime_early = 0, overtime_late = 0, real_ot = 0, conv_ot = 0, ot_type = ? WHERE id = ?");
                    $up_zero->execute([$ot_type, $shift_ids[$i]]);
                }
                
                $updated_count++;
            }
            echo json_encode(['success' => true, 'updated' => $updated_count]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    } elseif ($_GET['action'] === 'bulk_approval') {
        $shift_ids = $_POST['shift_ids'] ?? [];
        $status = $_POST['status'] ?? 'approved'; // 'approved' or 'pending'
        
        if (!empty($shift_ids)) {
            $admin_id = $_SESSION['user_id'];
            $in_query = implode(',', array_fill(0, count($shift_ids), '?'));
            $sql = "UPDATE shifts SET approval_status = ?, approved_at = CURRENT_TIMESTAMP, approved_by = ? WHERE id IN ($in_query)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$status, $admin_id], $shift_ids));
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No shifts selected']);
        }
        exit;
    }
}

$drivers = $pdo->query("SELECT * FROM users WHERE role = 'driver' ORDER BY full_name ASC")->fetchAll();
$is_collapsed = isset($_SESSION['sidebar_collapsed']) && $_SESSION['sidebar_collapsed'];
$theme = $_SESSION['theme'] ?? 'light';
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang']; ?>" class="<?php echo $theme === 'dark' ? 'dark-mode' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo __('attendance_report'); ?> - framas Transport App</title>
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
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
            #reportForm { flex-direction: column; align-items: stretch !important; }
        }

        .main-content { margin-left: var(--sidebar-w); flex: 1; padding: 16px; transition: margin-left 0.3s; }
        body.collapsed .main-content { margin-left: var(--sidebar-collapsed); }

        .report-filter-card { background: var(--card-bg); padding: 16px; border-radius: 8px; box-shadow: var(--card-shadow); border: 1px solid var(--glass-border); }
        .pbi-form-group { flex: 1; min-width: 200px; }
        .pbi-label { display: block; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 8px; }
        .pbi-input { width: 100%; padding: 8px; border: 1px solid var(--glass-border); border-radius: 4px; font-family: inherit; font-size: 0.9rem; background: var(--bg-color); color: var(--text-primary); box-sizing: border-box; }
        
        .btn-generate { background: var(--pbi-blue); color: #fff; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: 700; height: 38px; white-space: nowrap; }
        .btn-export { border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 0.85rem; color: #fff; }
        
        .results-box { background: var(--card-bg); padding: 16px; border-radius: 8px; box-shadow: var(--card-shadow); display: none; border: 1px solid var(--glass-border); }
        .pbi-table { width: 100%; border-collapse: collapse; font-size: 0.75rem; }
        .pbi-table th { text-align: center; padding: 6px; border: 1px solid var(--glass-border); background: rgba(0,0,0,0.02); color: var(--text-secondary); }
        .pbi-table td { padding: 6px; border: 1px solid var(--glass-border); color: var(--text-primary); }

        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); }
        .modal-content { background: var(--card-bg); margin: 5% auto; padding: 32px; border-radius: 16px; width: 90%; max-width: 600px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); border: 1px solid var(--glass-border); box-sizing: border-box; }
        .btn-action { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.75rem; font-weight: 600; text-decoration: none; display: inline-block; }
        .btn-delete { background: #fff1f1; color: #d83b01; }
        .btn-edit { background: #f0f9ff; color: #118DFF; margin-right: 5px; }
        .btn-add { background: var(--pbi-blue); color: #fff; padding: 10px 20px; border-radius: 6px; border: none; cursor: pointer; font-weight: 700; box-shadow: 0 4px 12px rgba(17,141,255,0.2); }

        .lang-theme-footer { position: absolute; bottom: 0; width: 100%; padding: 20px; border-top: 1px solid var(--glass-border); background: var(--card-bg); }
    </style>
</head>
<body class="<?php echo $is_collapsed ? 'collapsed' : ''; ?>">

    <div class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand">Transport Overview</div>
            <div class="toggle-btn" onclick="toggleSidebar()">☰</div>
        </div>
        <nav style="padding: 10px 0;">
            <a href="admin.php" class="nav-item"><div class="nav-icon">📊</div><span>Dashboard</span></a>
            <a href="master_data.php" class="nav-item"><div class="nav-icon">📁</div><span><?php echo __('master_data'); ?></span></a>
            <a href="report.php" class="nav-item"><div class="nav-icon">📝</div><span><?php echo __('reports'); ?></span></a>
            <a href="attendance_report.php" class="nav-item active"><div class="nav-icon">⏰</div><span><?php echo __('attendance'); ?></span></a>
        </nav>
        
        <div class="lang-theme-footer">
            <div style="font-size: 0.7rem; color: #999; margin-bottom: 10px; font-weight: 700;">PREFERENCES</div>
            <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                <a href="?lang=en" style="text-decoration:none; color: <?php echo $_SESSION['lang']=='en'?'var(--pbi-blue)':'#666'; ?>; font-weight: 700;">EN</a>
                <a href="?lang=id" style="text-decoration:none; color: <?php echo $_SESSION['lang']=='id'?'var(--pbi-blue)':'#666'; ?>; font-weight: 700;">ID</a>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="?theme=light" style="text-decoration:none; font-size: 0.75rem; color: <?php echo $theme=='light'?'var(--pbi-blue)':'#666'; ?>; font-weight: 700;">LIGHT</a>
                <a href="?theme=dark" style="text-decoration:none; font-size: 0.75rem; color: <?php echo $theme=='dark'?'var(--pbi-blue)':'#666'; ?>; font-weight: 700;">DARK</a>
            </div>
            
            <div style="margin-top: 20px; border-top: 1px dashed var(--glass-border); padding-top: 15px;">
                <a href="admin_password.php" style="display:block; color: var(--pbi-blue); text-decoration: none; font-size: 0.75rem; font-weight: 700; margin-bottom: 10px;">🔑 Ganti Password</a>
                <a href="login.php" style="display:block; color: var(--text-secondary); text-decoration: none; font-size: 0.85rem; font-weight: 700;">Logout</a>
            </div>
        </div>
    </div>

    <div class="main-content">
        <h2 style="margin-bottom: 16px; font-size: 1.5rem;"><?php echo __('attendance_report'); ?></h2>

        <div style="display: flex; flex-direction: column; gap: 16px;">
            <div class="report-filter-card">
                <form id="reportForm" style="display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap;">
                    <div class="pbi-form-group">
                        <label class="pbi-label">Select Driver</label>
                        <select id="driver_id" class="pbi-input">
                            <option value="ALL">[ ALL DRIVERS ]</option>
                            <?php foreach($drivers as $d): ?>
                                <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pbi-form-group">
                        <label class="pbi-label"><?php echo $_SESSION['lang'] == 'id' ? 'Tanggal Mulai' : 'Start Date'; ?></label>
                        <input type="date" id="start_date" class="pbi-input" value="<?php echo date('Y-m-21', strtotime('-1 month', strtotime(date('Y-m-01')))); ?>">
                    </div>
                    <div class="pbi-form-group">
                        <label class="pbi-label"><?php echo $_SESSION['lang'] == 'id' ? 'Tanggal Akhir' : 'End Date'; ?></label>
                        <input type="date" id="end_date" class="pbi-input" value="<?php echo date('Y-m-20'); ?>">
                    </div>
                    <button type="button" onclick="generateReport()" class="btn-generate"><?php echo $_SESSION['lang'] == 'id' ? 'Buat Laporan' : 'Generate Report'; ?></button>
                    <button type="button" onclick="recalculateOvertime()" class="btn-generate" style="background: #854d0e; margin-left: 8px;"><?php echo $_SESSION['lang'] == 'id' ? 'Hitung Ulang' : 'Recalculate'; ?></button>
                </form>
            </div>

            <div id="resultsBox" class="results-box">
                <div style="display: flex; justify-content: flex-end; align-items: center; margin-bottom: 20px;">
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button onclick="bulkApprove('approved')" class="btn-export" style="background: #166534; margin-bottom: 5px;"><?php echo $_SESSION['lang'] == 'id' ? 'Setujui Terpilih' : 'Approve Selected'; ?></button>
                        <button onclick="bulkApprove('pending')" class="btn-export" style="background: #b91c1c; margin-bottom: 5px;"><?php echo $_SESSION['lang'] == 'id' ? 'Batalkan Setuju' : 'Unapprove Selected'; ?></button>
                        <button onclick="exportToPDF()" class="btn-export" style="background: #e11d48; margin-bottom: 5px;">Export PDF</button>
                        <button onclick="exportToExcelSeparate()" class="btn-export" style="background: #107c10; margin-bottom: 5px;">Export Excel (Per Driver)</button>
                        <button onclick="exportToExcelCombined()" class="btn-export" style="background: #0078d4; margin-bottom: 5px;">Export Excel (All Drivers)</button>
                    </div>
                </div>

                <!-- Search & Filter Controls -->
                <div style="display: flex; gap: 16px; margin-bottom: 16px; align-items: center; flex-wrap: nowrap; background: rgba(0,0,0,0.02); padding: 12px; border-radius: 6px; border: 1px solid var(--glass-border);">
                    <div style="flex: 2; display: flex; gap: 8px; align-items: center;">
                        <span style="font-size: 0.8rem; font-weight: bold; color: var(--text-secondary); white-space: nowrap;"><?php echo $_SESSION['lang'] == 'id' ? 'CARI:' : 'SEARCH:'; ?></span>
                        <input type="text" id="tableSearch" oninput="applyFiltersAndSort()" placeholder="<?php echo $_SESSION['lang'] == 'id' ? 'Ketik NIK, Nama Driver, atau Status...' : 'Type NIK, Driver Name, or Status...'; ?>" style="width: 100%; padding: 6px 10px; border: 1px solid var(--glass-border); border-radius: 4px; font-size: 0.8rem; background: var(--bg-color); color: var(--text-primary);">
                    </div>
                    <div style="flex: 3; display: flex; gap: 12px; align-items: center; justify-content: flex-end;">
                        <span style="font-size: 0.8rem; font-weight: bold; color: var(--text-secondary); white-space: nowrap;"><?php echo $_SESSION['lang'] == 'id' ? 'FILTER:' : 'FILTER:'; ?></span>
                        <select id="filterApproval" onchange="applyFiltersAndSort()" style="padding: 6px; border: 1px solid var(--glass-border); border-radius: 4px; font-size: 0.8rem; background: var(--bg-color); color: var(--text-primary); width: 170px;">
                            <option value="ALL"><?php echo $_SESSION['lang'] == 'id' ? '[ Semua OT Approval ]' : '[ All OT Approval ]'; ?></option>
                            <option value="APPROVED">APPROVED</option>
                            <option value="PENDING">PENDING</option>
                        </select>
                        <select id="filterOvertime" onchange="applyFiltersAndSort()" style="padding: 6px; border: 1px solid var(--glass-border); border-radius: 4px; font-size: 0.8rem; background: var(--bg-color); color: var(--text-primary); width: 170px;">
                            <option value="ALL"><?php echo $_SESSION['lang'] == 'id' ? '[ Semua Lembur ]' : '[ All Overtime ]'; ?></option>
                            <option value="HAS_OT"><?php echo $_SESSION['lang'] == 'id' ? 'Ada Lembur' : 'Has Overtime'; ?></option>
                            <option value="NO_OT"><?php echo $_SESSION['lang'] == 'id' ? 'Tidak Ada Lembur' : 'No Overtime'; ?></option>
                        </select>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table class="pbi-table" id="reportTable">
                        <thead>
                            <tr>
                                <th onclick="sortColumn('nik')" style="cursor: pointer;">NIK <span id="sort-nik">⇅</span></th>
                                <th onclick="sortColumn('driver_name')" style="cursor: pointer;">Driver <span id="sort-driver_name">⇅</span></th>
                                <th><?php echo $_SESSION['lang'] == 'id' ? 'Jumlah Trip' : 'Trips'; ?></th>
                                <th onclick="sortColumn('shift_date')" style="cursor: pointer;"><?php echo $_SESSION['lang'] == 'id' ? 'Tanggal' : 'Date'; ?> <span id="sort-shift_date">⇅</span></th>
                                <th onclick="sortColumn('start_time')" style="cursor: pointer;"><?php echo __('clock_in_time'); ?> <span id="sort-start_time">⇅</span></th>
                                <th onclick="sortColumn('end_time')" style="cursor: pointer;"><?php echo __('clock_out_time'); ?> <span id="sort-end_time">⇅</span></th>
                                <th onclick="sortColumn('duration')" style="cursor: pointer;"><?php echo __('work_duration'); ?> <span id="sort-duration">⇅</span></th>
                                <th onclick="sortColumn('overtime_early')" style="cursor: pointer;"><?php echo __('early_overtime'); ?> <span id="sort-overtime_early">⇅</span></th>
                                <th onclick="sortColumn('overtime_late')" style="cursor: pointer;"><?php echo __('late_overtime'); ?> <span id="sort-overtime_late">⇅</span></th>
                                <th onclick="sortColumn('ot_type')" style="cursor: pointer;">Tipe OT <span id="sort-ot_type">⇅</span></th>
                                <th onclick="sortColumn('real_ot')" style="cursor: pointer;">Real OT <span id="sort-real_ot">⇅</span></th>
                                <th onclick="sortColumn('conv_ot')" style="cursor: pointer;">Conv OT <span id="sort-conv_ot">⇅</span></th>
                                <th onclick="sortColumn('status')" style="cursor: pointer;">Status <span id="sort-status">⇅</span></th>
                                <th style="width: 30px;"><input type="checkbox" id="checkAll" onchange="toggleSelectAll(this)"></th>
                                <th onclick="sortColumn('approval_status')" style="cursor: pointer;"><?php echo __('approval_status'); ?> <span id="sort-approval_status">⇅</span></th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="reportContent"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        const lang_early_overtime = "<?php echo __('early_overtime'); ?>";
        const lang_late_overtime = "<?php echo __('late_overtime'); ?>";
        const lang_approval_status = "<?php echo __('approval_status'); ?>";

        function toggleSidebar() {
            document.body.classList.toggle('collapsed');
            fetch('manage_admin_action.php?action=toggle_sidebar');
        }

        function toggleSelectAll(masterCb) {
            const checkboxes = document.querySelectorAll('.shift-checkbox');
            checkboxes.forEach(cb => cb.checked = masterCb.checked);
        }

        function formatDecimalHours(hoursDecimal) {
            const totalMinutes = Math.round(parseFloat(hoursDecimal || 0) * 60);
            if (totalMinutes === 0) return '';
            const hrs = String(Math.floor(totalMinutes / 60)).padStart(2, '0');
            const mins = String(totalMinutes % 60).padStart(2, '0');
            return `${hrs}:${mins}`;
        }

        let sortKey = '';
        let sortDirection = 'asc';

        function sortColumn(key) {
            if (sortKey === key) {
                sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                sortKey = key;
                sortDirection = 'asc';
            }
            applyFiltersAndSort();
        }

        function applyFiltersAndSort() {
            const searchTerm = document.getElementById('tableSearch').value.toLowerCase();
            const approvalFilter = document.getElementById('filterApproval').value;
            const overtimeFilter = document.getElementById('filterOvertime').value;

            // 1. Filter
            let filtered = currentData.filter(r => {
                const matchSearch = 
                    (r.nik || '').toLowerCase().includes(searchTerm) ||
                    (r.driver_name || '').toLowerCase().includes(searchTerm) ||
                    (r.tx_ids || '').toLowerCase().includes(searchTerm) ||
                    (r.shift_date || '').toLowerCase().includes(searchTerm) ||
                    (r.status || '').toLowerCase().includes(searchTerm) ||
                    (r.approval_status || '').toLowerCase().includes(searchTerm);

                const matchApproval = approvalFilter === 'ALL' || r.approval_status.toUpperCase() === approvalFilter;

                const hasOt = (parseFloat(r.real_ot) > 0);
                const matchOvertime = overtimeFilter === 'ALL' || 
                                      (overtimeFilter === 'HAS_OT' && hasOt) || 
                                      (overtimeFilter === 'NO_OT' && !hasOt);

                return matchSearch && matchApproval && matchOvertime;
            });

            // 2. Sort
            if (sortKey) {
                filtered.sort((a, b) => {
                    let valA = a[sortKey];
                    let valB = b[sortKey];

                    if (sortKey === 'duration') {
                        const durationMs = (row) => {
                            if (!row.end_time) return 0;
                            const start = new Date(`${row.shift_date} ${row.start_time}`);
                            const end = new Date(`${row.shift_date} ${row.end_time}`);
                            let diff = end - start;
                            if (diff < 0) diff += 24 * 60 * 60 * 1000;
                            return diff;
                        };
                        valA = durationMs(a);
                        valB = durationMs(b);
                    } else {
                        if (!isNaN(valA) && !isNaN(valB) && valA !== '' && valB !== '') {
                            valA = parseFloat(valA || 0);
                            valB = parseFloat(valB || 0);
                        } else {
                            valA = String(valA || '').toLowerCase();
                            valB = String(valB || '').toLowerCase();
                        }
                    }

                    if (valA < valB) return sortDirection === 'asc' ? -1 : 1;
                    if (valA > valB) return sortDirection === 'asc' ? 1 : -1;
                    return 0;
                });
            }

            // Update Sort Indicators in Header
            const columns = ['nik', 'driver_name', 'shift_date', 'start_time', 'end_time', 'duration', 'overtime_early', 'overtime_late', 'ot_type', 'real_ot', 'conv_ot', 'status', 'approval_status'];
            columns.forEach(col => {
                const indicatorEl = document.getElementById(`sort-${col}`);
                if (indicatorEl) {
                    if (sortKey === col) {
                        indicatorEl.innerHTML = sortDirection === 'asc' ? '▲' : '▼';
                        indicatorEl.style.color = 'var(--pbi-blue)';
                    } else {
                        indicatorEl.innerHTML = '⇅';
                        indicatorEl.style.color = 'inherit';
                    }
                }
            });

            renderTableBody(filtered);
        }

        function renderTableBody(data) {
            const tbody = document.getElementById('reportContent');
            tbody.innerHTML = data.map((r) => {
                let duration = '-';
                if (r.end_time) {
                    if (r.end_time === '00:00:00') {
                        duration = '<span style="color:#b91c1c; font-weight:600;">Exceeded 16h</span>';
                    } else {
                        const start = new Date(`${r.shift_date} ${r.start_time}`);
                        const end = new Date(`${r.shift_date} ${r.end_time}`);
                        let diffMs = end - start;
                        if (diffMs < 0) diffMs += 24 * 60 * 60 * 1000; // handle overnight shifts
                        const diffHrs = String(Math.floor(diffMs / (1000 * 60 * 60))).padStart(2, '0');
                        const diffMins = String(Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60))).padStart(2, '0');
                        duration = `${diffHrs}:${diffMins}`;
                        if (duration === '00:00') duration = '';
                    }
                }
                const driverNameSafe = (r.driver_name || '').replace(/'/g, "\\'");
                const nikVal = (r.nik && String(r.nik).trim() !== '') ? r.nik : `<button onclick="editNik(${r.driver_id}, '${driverNameSafe}')" class="btn" style="padding: 2px 6px; font-size: 0.7rem; background: var(--pbi-blue); color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: 600;">+ Entry NIK</button>`;
                
                const otEarlyVal = parseFloat(r.overtime_early || 0).toFixed(2);
                const otLateVal = parseFloat(r.overtime_late || 0).toFixed(2);
                const realOtVal = parseFloat(r.real_ot || 0);
                const convOtVal = parseFloat(r.conv_ot || 0);
                
                const isApproved = r.approval_status === 'approved';
                let rowStyle = isApproved ? 'style="background-color: rgba(21, 128, 61, 0.06);"' : '';
                
                if (r.ot_type === 'H') {
                    rowStyle = 'style="background-color: rgba(220, 38, 38, 0.1);"';
                }

                const hasOt = (realOtVal > 0);
                let actionBtns = `<button onclick="openEditShiftModal(${r.shift_id}, '${driverNameSafe}', '${r.shift_date}', '${r.start_time || ''}', '${r.end_time || ''}', ${otEarlyVal}, ${otLateVal})" class="btn-action btn-edit">Edit</button>`;
                
                if (hasOt && r.approval_status === 'pending') {
                    actionBtns += ` <button onclick="approveShift(${r.shift_id})" class="btn-action" style="background:#dcfce7; color:#166534; margin-left:4px; border:none; border-radius:4px; padding:6px 12px; font-size:0.75rem; font-weight:600; cursor:pointer;">Approve</button>`;
                }
                
                let txHtml = '-';
                if (r.tx_ids) {
                    const ids = r.tx_ids.split(',');
                    const count = ids.length;
                    const suffix = count > 1 ? " <?php echo $_SESSION['lang'] == 'id' ? 'Trip' : 'Trips'; ?>" : " Trip";
                    txHtml = `<a href="#" onclick="showShiftTripsList('${r.driver_name.replace(/'/g, "\\'")}', '${r.shift_date}', '${r.tx_ids}'); return false;" style="color:var(--pbi-blue); font-weight:bold; text-decoration:underline;">${count}${suffix}</a>`;
                }

                return `
                    <tr ${rowStyle}>
                        <td align="center">${nikVal}</td>
                        <td><strong>${r.driver_name || ''}</strong></td>
                        <td align="center">${txHtml}</td>
                        <td align="center">${r.shift_date || ''}</td>
                        <td align="center">${r.start_time ? r.start_time.substring(0, 5) : '-'}</td>
                        <td align="center">${r.end_time ? (r.end_time === '00:00:00' ? '<span style="color:#b91c1c; font-weight:bold;">00:00 (Timeout)</span>' : r.end_time.substring(0, 5)) : '-'}</td>
                        <td align="center">${duration}</td>
                        <td align="center" style="color: #64748b;">${formatDecimalHours(otEarlyVal)}</td>
                        <td align="center" style="color: #64748b;">${formatDecimalHours(otLateVal)}</td>
                        <td align="center" style="font-weight: bold; color: ${r.ot_type === 'H' ? '#dc2626' : '#475569'};">${r.ot_type || '-'}</td>
                        <td align="center" style="font-weight: ${realOtVal > 0 ? 'bold' : 'normal'}; color: ${realOtVal > 0 ? 'var(--pbi-blue)' : 'inherit'};">${realOtVal > 0 ? realOtVal : '-'}</td>
                        <td align="center" style="font-weight: ${convOtVal > 0 ? 'bold' : 'normal'}; color: ${convOtVal > 0 ? '#107c10' : 'inherit'};">${convOtVal > 0 ? convOtVal : '-'}</td>
                        <td align="center">${r.status ? r.status.toUpperCase() : ''}</td>
                        <td align="center"><input type="checkbox" class="shift-checkbox" value="${r.shift_id}"></td>
                        <td align="center">${r.approval_status === 'approved' ? '<span style="color: #15803d; font-size: 1.15rem; font-weight: bold;">✔</span><br><span style="font-size: 0.65rem; color: ' + (r.approver_name ? '#0284c7' : '#94a3b8') + '; font-weight: bold;">' + (r.approver_name || 'System') + '</span>' : ''}</td>
                        <td align="center">${actionBtns}</td>
                    </tr>
                `;
            }).join('');
        }

        let currentData = [];
        async function generateReport() {
            const formData = new FormData();
            formData.append('driver_id', document.getElementById('driver_id').value);
            formData.append('start_date', document.getElementById('start_date').value);
            formData.append('end_date', document.getElementById('end_date').value);

            const res = await fetch('attendance_report.php?action=get_data', { method: 'POST', body: formData });
            currentData = await res.json();

            // Reset master checkbox
            document.getElementById('checkAll').checked = false;

            // Reset search & filters
            document.getElementById('tableSearch').value = '';
            document.getElementById('filterApproval').value = 'ALL';
            document.getElementById('filterOvertime').value = 'ALL';
            sortKey = '';

            applyFiltersAndSort();

            document.getElementById('resultsBox').style.display = 'block';
        }

        async function recalculateOvertime() {
            if (!confirm("Recalculate early/late overtime for non-edited shifts in this date range?")) return;
            const formData = new FormData();
            formData.append('driver_id', document.getElementById('driver_id').value);
            formData.append('start_date', document.getElementById('start_date').value);
            formData.append('end_date', document.getElementById('end_date').value);
            
            try {
                const res = await fetch('attendance_report.php?action=recalculate', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    alert(`Successfully recalculated ${data.updated} shifts.`);
                    generateReport();
                } else {
                    alert('Recalculation failed: ' + data.message);
                }
            } catch (err) {
                alert('Recalculation failed due to request or server error: ' + err);
            }
        }

        async function bulkApprove(status) {
            const checkedBoxes = document.querySelectorAll('.shift-checkbox:checked');
            if (checkedBoxes.length === 0) {
                alert('No shifts selected.');
                return;
            }
            if (!confirm(`Are you sure you want to change approval status of selected shifts to ${status.toUpperCase()}?`)) return;
            
            const shiftIds = Array.from(checkedBoxes).map(cb => cb.value);
            const formData = new FormData();
            shiftIds.forEach(id => formData.append('shift_ids[]', id));
            formData.append('status', status);
            
            const res = await fetch('attendance_report.php?action=bulk_approval', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                alert('Bulk update successful.');
                generateReport();
            } else {
                alert('Bulk update failed');
            }
        }

        function editNik(driverId, driverName) {
            document.getElementById('nikDriverId').value = driverId;
            document.getElementById('nikDriverName').value = driverName;
            document.getElementById('nikValue').value = '';
            document.getElementById('nikModal').style.display = 'block';
        }

        function closeNikModal() {
            document.getElementById('nikModal').style.display = 'none';
        }

        function submitNik(event) {
            event.preventDefault();
            const driverId = document.getElementById('nikDriverId').value;
            const nik = document.getElementById('nikValue').value;
            
            if (nik.trim() === '') {
                alert('NIK tidak boleh kosong.');
                return;
            }
            
            const formData = new FormData();
            formData.append('driver_id', driverId);
            formData.append('nik', nik.trim());
            
            fetch('attendance_report.php?action=update_nik', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert('NIK berhasil diperbarui!');
                        closeNikModal();
                        generateReport();
                    } else {
                        alert('Gagal memperbarui NIK: ' + data.message);
                    }
                })
                .catch(err => {
                     alert('Terjadi kesalahan: ' + err);
                });
        }

        function exportToExcelSeparate() {
            const wb = XLSX.utils.book_new();
            const dateObj = new Date(document.getElementById('start_date').value);
            const monthName = dateObj.toLocaleString('default', { month: 'long' });
            const year = dateObj.getFullYear();
            
            const driverNames = [...new Set(currentData.map(d => d.driver_name))];
            driverNames.forEach(name => {
                const driverData = currentData.filter(d => d.driver_name === name);
                const firstRow = driverData[0];
                const rows = [
                    ["DRIVER OVERTIME REPORT"],
                    [],
                    ["Month :", monthName, "", "Driver :", `${name} (NIK: ${firstRow.nik || '-'})`],
                    ["Year :", year],
                    [],
                    ["Date", "Clock In", "Clock Out", "Duration", lang_early_overtime, lang_late_overtime, "Status", lang_approval_status]
                ];
                
                driverData.forEach(r => {
                    let duration = '-';
                    if (r.end_time) {
                        const start = new Date(`${r.shift_date} ${r.start_time}`);
                        const end = new Date(`${r.shift_date} ${r.end_time}`);
                        let diffMs = end - start;
                        if (diffMs < 0) diffMs += 24 * 60 * 60 * 1000;
                        const diffHrs = String(Math.floor(diffMs / (1000 * 60 * 60))).padStart(2, '0');
                        const diffMins = String(Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60))).padStart(2, '0');
                        duration = `${diffHrs}:${diffMins}`;
                    }
                    rows.push([
                        r.shift_date,
                        r.start_time.substring(0, 5),
                        r.end_time ? r.end_time.substring(0, 5) : '-',
                        duration,
                        formatDecimalHours(r.overtime_early),
                        formatDecimalHours(r.overtime_late),
                        r.status.toUpperCase(),
                        r.approval_status.toUpperCase()
                    ]);
                });
                
                const ws = XLSX.utils.aoa_to_sheet(rows);
                ws['!merges'] = [
                    {s:{r:0,c:0}, e:{r:0,c:7}}
                ];
                XLSX.utils.book_append_sheet(wb, ws, name.substring(0, 31));
            });
            XLSX.writeFile(wb, `Overtime_Per_Driver_${new Date().toISOString().split('T')[0]}.xlsx`);
        }

        function exportToExcelCombined() {
            const wb = XLSX.utils.book_new();
            const dateObj = new Date(document.getElementById('start_date').value);
            const monthName = dateObj.toLocaleString('default', { month: 'long' });
            const year = dateObj.getFullYear();
            
            const rows = [
                ["ALL DRIVERS OVERTIME REPORT"],
                [],
                ["Month :", monthName],
                ["Year :", year],
                [],
                ["NIK", "Driver", "Date", "Clock In", "Clock Out", "Duration", lang_early_overtime, lang_late_overtime, "Status", lang_approval_status]
            ];
            
            currentData.forEach(r => {
                let duration = '-';
                if (r.end_time) {
                    const start = new Date(`${r.shift_date} ${r.start_time}`);
                    const end = new Date(`${r.shift_date} ${r.end_time}`);
                    let diffMs = end - start;
                    if (diffMs < 0) diffMs += 24 * 60 * 60 * 1000;
                    const diffHrs = String(Math.floor(diffMs / (1000 * 60 * 60))).padStart(2, '0');
                    const diffMins = String(Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60))).padStart(2, '0');
                    duration = `${diffHrs}:${diffMins}`;
                }
                rows.push([
                    r.nik || '-',
                    r.driver_name,
                    r.shift_date,
                    r.start_time.substring(0, 5),
                    r.end_time ? r.end_time.substring(0, 5) : '-',
                    duration,
                    formatDecimalHours(r.overtime_early),
                    formatDecimalHours(r.overtime_late),
                    r.status.toUpperCase(),
                    r.approval_status.toUpperCase()
                ]);
            });
            
            const ws = XLSX.utils.aoa_to_sheet(rows);
            ws['!merges'] = [
                {s:{r:0,c:0}, e:{r:0,c:9}}
            ];
            XLSX.utils.book_append_sheet(wb, ws, "All Overtime");
            XLSX.writeFile(wb, `Overtime_All_Drivers_${new Date().toISOString().split('T')[0]}.xlsx`);
        }

        function exportToPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('l', 'pt', 'a4');
            const dateObj = new Date(document.getElementById('start_date').value);
            const monthName = dateObj.toLocaleString('default', { month: 'long' });
            const year = dateObj.getFullYear();
            
            const driverNames = [...new Set(currentData.map(d => d.driver_name))];
            driverNames.forEach((name, index) => {
                if (index > 0) doc.addPage();
                const driverData = currentData.filter(d => d.driver_name === name);
                const firstRow = driverData[0];
                
                doc.setFontSize(16);
                doc.text("DRIVER OVERTIME REPORT", 40, 40);
                doc.setFontSize(10);
                doc.text(`Month : ${monthName}`, 40, 65);
                doc.text(`Year  : ${year}`, 40, 80);
                doc.text(`Driver : ${name} (NIK: ${firstRow.nik || '-'})`, 550, 65);
                
                const tableBody = driverData.map(r => {
                    let duration = '-';
                    if (r.end_time) {
                        const start = new Date(`${r.shift_date} ${r.start_time}`);
                        const end = new Date(`${r.shift_date} ${r.end_time}`);
                        let diffMs = end - start;
                        if (diffMs < 0) diffMs += 24 * 60 * 60 * 1000;
                        const diffHrs = String(Math.floor(diffMs / (1000 * 60 * 60))).padStart(2, '0');
                        const diffMins = String(Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60))).padStart(2, '0');
                        duration = `${diffHrs}:${diffMins}`;
                    }
                    return [
                        r.shift_date, r.start_time.substring(0, 5), r.end_time ? r.end_time.substring(0, 5) : '-',
                        duration, formatDecimalHours(r.overtime_early), formatDecimalHours(r.overtime_late),
                        r.status.toUpperCase(), r.approval_status.toUpperCase()
                    ];
                });
                
                doc.autoTable({
                    head: [['Date', 'Clock In', 'Clock Out', 'Duration', lang_early_overtime, lang_late_overtime, 'Status', lang_approval_status]],
                    body: tableBody, startY: 100, theme: 'grid', styles: {fontSize:8, cellPadding:3}, headStyles: {fillColor:[51,51,51], halign:'center'}
                });
            });
            doc.save(`Overtime_Report_${new Date().getTime()}.pdf`);
        }

        function openEditShiftModal(shiftId, driverName, shiftDate, startTime, endTime, otEarly, otLate) {
            document.getElementById('editShiftId').value = shiftId;
            document.getElementById('editShiftDriver').value = driverName;
            document.getElementById('editShiftDate').value = shiftDate;
            document.getElementById('editShiftStartTime').value = startTime ? startTime.substring(0, 5) : '';
            document.getElementById('editShiftEndTime').value = endTime ? endTime.substring(0, 5) : '';
            document.getElementById('editShiftOtEarly').value = parseFloat(otEarly || 0).toFixed(2);
            document.getElementById('editShiftOtLate').value = parseFloat(otLate || 0).toFixed(2);
            document.getElementById('editShiftModal').style.display = 'block';
        }

        function closeEditShiftModal() {
            document.getElementById('editShiftModal').style.display = 'none';
        }

        function submitEditShift(event) {
            event.preventDefault();
            const shiftId = document.getElementById('editShiftId').value;
            const startTime = document.getElementById('editShiftStartTime').value;
            const endTime = document.getElementById('editShiftEndTime').value;
            const otEarly = document.getElementById('editShiftOtEarly').value;
            const otLate = document.getElementById('editShiftOtLate').value;
            
            const formData = new FormData();
            formData.append('shift_id', shiftId);
            formData.append('start_time', startTime);
            formData.append('end_time', endTime);
            formData.append('overtime_early', otEarly);
            formData.append('overtime_late', otLate);
            
            fetch('attendance_report.php?action=update_shift', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert('Data absensi & lembur berhasil diperbarui!');
                        closeEditShiftModal();
                        generateReport();
                    } else {
                        alert('Gagal memperbarui data: ' + data.message);
                    }
                })
                .catch(err => {
                    alert('Terjadi kesalahan: ' + err);
                });
        }

        function approveShift(shiftId) {
            if (!confirm('Setujui lembur untuk absensi ini?')) return;
            
            fetch(`attendance_report.php?action=approve_shift&shift_id=${shiftId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert('Lembur berhasil disetujui!');
                        generateReport();
                    } else {
                        alert('Gagal menyetujui lembur: ' + data.message);
                    }
                })
                .catch(err => {
                    alert('Terjadi kesalahan: ' + err);
                });
        }

        async function viewTripDetails(tripId) {
            try {
                const res = await fetch(`attendance_report.php?action=get_trip_details&trip_id=${tripId}`);
                const json = await res.json();
                if (!json.success) {
                    alert(json.message || 'Gagal mengambil detail.');
                    return;
                }
                
                const t = json.data;
                document.getElementById('tripDetailsTitle').innerText = `Detail Transaksi TX-${t.id}`;
                
                const expenses = t.expense_details || [];
                let expHtml = '<p style="color:var(--text-muted); font-style:italic; margin:0;">Tidak ada biaya</p>';
                if (expenses.length > 0) {
                    expHtml = expenses.map(e => {
                        const typeLabel = e.expense_type.toUpperCase();
                        const amountStr = parseFloat(e.amount).toLocaleString('id-ID');
                        const litreStr = e.litre && parseFloat(e.litre) > 0 ? ` (${parseFloat(e.litre)} L)` : '';
                        const imgHtml = e.photo ? `<div style="margin-top:6px;"><a href="uploads/${e.photo}" target="_blank"><img src="uploads/${e.photo}" style="width:80px; height:80px; object-fit:cover; border-radius:4px; border:1px solid var(--glass-border);" /></a></div>` : '';
                        return `<div style="padding:10px; border:1px solid var(--glass-border); border-radius:6px; margin-bottom:8px; background:rgba(0,0,0,0.01);">
                            <strong>${typeLabel}</strong>: Rp ${amountStr}${litreStr}
                            ${imgHtml}
                        </div>`;
                    }).join('');
                }
                
                const odomStartImg = t.photo_start ? `<div style="margin-top:6px;"><a href="uploads/${t.photo_start}" target="_blank"><img src="uploads/${t.photo_start}" style="width:80px; height:80px; object-fit:cover; border-radius:4px; border:1px solid var(--glass-border);" /></a></div>` : '';
                const odomEndImg = t.photo_end ? `<div style="margin-top:6px;"><a href="uploads/${t.photo_end}" target="_blank"><img src="uploads/${t.photo_end}" style="width:80px; height:80px; object-fit:cover; border-radius:4px; border:1px solid var(--glass-border);" /></a></div>` : '';
                
                const formatTime = (ts) => ts ? ts.substring(0, 16) : '-';
                
                const html = `
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
                        <div>
                            <h4 style="margin:0 0 8px 0; color:var(--pbi-blue); border-bottom:1px solid var(--glass-border); padding-bottom:4px;">Informasi Driver & Kendaraan</h4>
                            <p style="margin:4px 0;"><strong>Driver:</strong> ${t.driver_name || '-'}</p>
                            <p style="margin:4px 0;"><strong>Kendaraan:</strong> ${t.car_no || '-'} ${t.car_model ? '(' + t.car_model + ')' : ''}</p>
                        </div>
                        <div>
                            <h4 style="margin:0 0 8px 0; color:var(--pbi-blue); border-bottom:1px solid var(--glass-border); padding-bottom:4px;">Informasi Penumpang & Tujuan</h4>
                            <p style="margin:4px 0;"><strong>Penumpang:</strong> ${t.pass_name || '-'}</p>
                            <p style="margin:4px 0;"><strong>Tujuan:</strong> ${t.dest_name || '-'}</p>
                        </div>
                    </div>
                    
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
                        <div>
                            <h4 style="margin:0 0 8px 0; color:var(--pbi-blue); border-bottom:1px solid var(--glass-border); padding-bottom:4px;">Jam Kerja (Shift)</h4>
                            <p style="margin:4px 0;"><strong>Tanggal Shift:</strong> ${t.shift_date || '-'}</p>
                            <p style="margin:4px 0;"><strong>Start Shift (Check In):</strong> ${t.shift_start ? t.shift_start.substring(0, 5) : '-'}</p>
                            <p style="margin:4px 0;"><strong>End Shift (Check Out):</strong> ${t.shift_end ? (t.shift_end === '00:00:00' ? '<span style="color:#b91c1c; font-weight:bold;">00:00 (Timeout)</span>' : t.shift_end.substring(0, 5)) : '-'}</p>
                        </div>
                        <div>
                            <h4 style="margin:0 0 8px 0; color:var(--pbi-blue); border-bottom:1px solid var(--glass-border); padding-bottom:4px;">Perjalanan (Trip)</h4>
                            <p style="margin:4px 0;"><strong>Mulai Trip:</strong> ${formatTime(t.start_time)}</p>
                            <p style="margin:4px 0;"><strong>Selesai Trip:</strong> ${formatTime(t.end_time)}</p>
                        </div>
                    </div>
                    
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
                        <div>
                            <h4 style="margin:0 0 8px 0; color:var(--pbi-blue); border-bottom:1px solid var(--glass-border); padding-bottom:4px;">Odometer Awal</h4>
                            <p style="margin:4px 0;"><strong>KM Start:</strong> ${t.km_start ? parseFloat(t.km_start).toLocaleString('id-ID') : '-'} KM</p>
                            ${odomStartImg}
                        </div>
                        <div>
                            <h4 style="margin:0 0 8px 0; color:var(--pbi-blue); border-bottom:1px solid var(--glass-border); padding-bottom:4px;">Odometer Akhir</h4>
                            <p style="margin:4px 0;"><strong>KM End:</strong> ${t.km_end ? parseFloat(t.km_end).toLocaleString('id-ID') : '-'} KM</p>
                            ${odomEndImg}
                        </div>
                    </div>
                    
                    <div style="margin-bottom:20px;">
                        <h4 style="margin:0 0 10px 0; color:var(--pbi-blue); border-bottom:1px solid var(--glass-border); padding-bottom:4px;">Rincian Biaya Tambahan (Expenses)</h4>
                        ${expHtml}
                    </div>
                    
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                        <div>
                            <h4 style="margin:0 0 8px 0; color:var(--pbi-blue); border-bottom:1px solid var(--glass-border); padding-bottom:4px;">Persetujuan Penumpang</h4>
                            <p style="margin:4px 0;"><strong>Status:</strong> <span style="font-weight:bold; color:${t.passenger_approval === 'approved' ? '#15803d' : (t.passenger_approval === 'rejected' ? '#b91c1c' : '#92400e')}">${t.passenger_approval ? t.passenger_approval.toUpperCase() : 'PENDING'}</span></p>
                            <p style="margin:4px 0;"><strong>Catatan:</strong> ${t.passenger_feedback || '-'}</p>
                        </div>
                        <div>
                            <h4 style="margin:0 0 8px 0; color:var(--pbi-blue); border-bottom:1px solid var(--glass-border); padding-bottom:4px;">Persetujuan Lembur (Admin)</h4>
                            <p style="margin:4px 0;"><strong>Status:</strong> <span style="font-weight:bold; color:${t.admin_approval === 'approved' ? '#15803d' : '#92400e'}">${t.admin_approval ? t.admin_approval.toUpperCase() : 'PENDING'}</span></p>
                        </div>
                    </div>
                `;
                
                document.getElementById('tripDetailsBody').innerHTML = html;
                document.getElementById('tripDetailsModal').style.display = 'block';
            } catch (err) {
                alert('Gagal menampilkan detail: ' + err);
            }
        }
        
        function closeTripDetailsModal() {
            document.getElementById('tripDetailsModal').style.display = 'none';
        }

        function showShiftTripsList(driverName, shiftDate, txIds) {
            const ids = txIds.split(',');
            if (ids.length === 1) {
                viewTripDetails(ids[0]);
                return;
            }
            showMultiTripsModal(driverName, shiftDate, ids);
        }

        async function showMultiTripsModal(driverName, shiftDate, ids) {
            document.getElementById('multiTripsTitle').innerText = `${driverName} - ${shiftDate}`;
            const tbody = document.getElementById('multiTripsTableContent');
            tbody.innerHTML = '<tr><td colspan="6" align="center">Loading...</td></tr>';
            document.getElementById('multiTripsModal').style.display = 'block';
            
            try {
                const promises = ids.map(id => fetch(`attendance_report.php?action=get_trip_details&trip_id=${id}`).then(r => r.json()));
                const results = await Promise.all(promises);
                
                tbody.innerHTML = results.map(res => {
                    if (!res.success) return '';
                    const t = res.data;
                    const startTime = t.start_time ? t.start_time.substring(11, 16) : '-';
                    const endTime = t.end_time ? t.end_time.substring(11, 16) : '-';
                    return `<tr>
                        <td><strong>TX-${t.id}</strong></td>
                        <td>${t.pass_name || '-'}</td>
                        <td>${t.dest_name || '-'}</td>
                        <td align="center">${startTime}</td>
                        <td align="center">${endTime}</td>
                        <td align="center">
                            <button onclick="closeMultiTripsModal(); viewTripDetails(${t.id});" class="btn" style="padding:4px 8px; font-size:0.75rem; background:var(--pbi-blue); color:white; border:none; border-radius:4px; cursor:pointer;">View</button>
                        </td>
                    </tr>`;
                }).join('');
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="6" align="center" style="color:#b91c1c;">Error: ${err.message}</td></tr>`;
            }
        }
        
        function closeMultiTripsModal() {
            document.getElementById('multiTripsModal').style.display = 'none';
        }

        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('filter') === 'pending') {
                document.getElementById('start_date').value = '2020-01-01';
                generateReport().then(() => {
                    document.getElementById('filterApproval').value = 'PENDING';
                    applyFiltersAndSort();
                });
            }
        });
    </script>

    <!-- EDIT SHIFT MODAL -->
    <div id="editShiftModal" class="modal">
        <div class="modal-content" style="width: 450px; max-width: 90%; margin: 10% auto;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0; font-size: 1.15rem; color: var(--text-primary);">Edit Jam Kerja & Lembur</h3>
                <button onclick="closeEditShiftModal()" style="background:none; border:none; cursor:pointer; font-size:1.5rem; color:var(--text-muted);">&times;</button>
            </div>
            <form id="editShiftForm" onsubmit="submitEditShift(event)">
                <input type="hidden" id="editShiftId">
                <div style="margin-bottom:15px;">
                    <label class="pbi-label">Driver</label>
                    <input type="text" id="editShiftDriver" readonly class="pbi-input" style="background: rgba(0,0,0,0.05); font-weight: bold;">
                </div>
                <div style="margin-bottom:15px;">
                    <label class="pbi-label">Tanggal</label>
                    <input type="text" id="editShiftDate" readonly class="pbi-input" style="background: rgba(0,0,0,0.05);">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom:15px;">
                    <div>
                        <label class="pbi-label">Jam Masuk (Check-In)</label>
                        <input type="time" id="editShiftStartTime" required class="pbi-input">
                    </div>
                    <div>
                        <label class="pbi-label">Jam Keluar (Check-Out)</label>
                        <input type="time" id="editShiftEndTime" class="pbi-input">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom:20px;">
                    <div>
                        <label class="pbi-label"><?php echo __('early_overtime'); ?> (<?php echo $_SESSION['lang'] == 'id' ? 'Jam' : 'Hours'; ?>)</label>
                        <input type="number" step="0.01" id="editShiftOtEarly" required class="pbi-input">
                    </div>
                    <div>
                        <label class="pbi-label"><?php echo __('late_overtime'); ?> (<?php echo $_SESSION['lang'] == 'id' ? 'Jam' : 'Hours'; ?>)</label>
                        <input type="number" step="0.01" id="editShiftOtLate" required class="pbi-input">
                    </div>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="button" onclick="closeEditShiftModal()" class="btn-action btn-delete" style="flex:1; padding:12px;">Cancel</button>
                    <button type="submit" class="btn-add" style="flex:2; box-shadow:none; font-weight:700;">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ENTRY NIK MODAL -->
    <div id="nikModal" class="modal">
        <div class="modal-content" style="width: 400px; max-width: 90%; margin: 15% auto;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0; font-size: 1.15rem; color: var(--text-primary);">Entry NIK</h3>
                <button onclick="closeNikModal()" style="background:none; border:none; cursor:pointer; font-size:1.5rem; color:var(--text-muted);">&times;</button>
            </div>
            <form id="nikForm" onsubmit="submitNik(event)">
                <input type="hidden" id="nikDriverId">
                <div style="margin-bottom:15px;">
                    <label class="pbi-label">Driver</label>
                    <input type="text" id="nikDriverName" readonly class="pbi-input" style="background: rgba(0,0,0,0.05); font-weight: bold;">
                </div>
                <div style="margin-bottom:20px;">
                    <label class="pbi-label">NIK</label>
                    <input type="text" id="nikValue" required class="pbi-input" placeholder="Masukkan NIK">
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="button" onclick="closeNikModal()" class="btn-action btn-delete" style="flex:1; padding:12px;">Cancel</button>
                    <button type="submit" class="btn-add" style="flex:2; box-shadow:none; font-weight:700;">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- TRIP DETAILS MODAL -->
    <div id="tripDetailsModal" class="modal" style="overflow-y: auto;">
        <div class="modal-content" style="width: 650px; max-width: 95%; margin: 3% auto;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid var(--glass-border); padding-bottom:12px;">
                <h3 id="tripDetailsTitle" style="margin:0; font-size: 1.25rem; color: var(--pbi-blue);">Detail Transaksi</h3>
                <button onclick="closeTripDetailsModal()" style="background:none; border:none; cursor:pointer; font-size:1.5rem; color:var(--text-muted);">&times;</button>
            </div>
            <div id="tripDetailsBody" style="max-height: 70vh; overflow-y: auto; font-size: 0.9rem; line-height: 1.5;">
                <!-- Content injected via JS -->
            </div>
        </div>
    </div>

    <!-- MULTI TRIPS LIST MODAL -->
    <div id="multiTripsModal" class="modal" style="overflow-y: auto;">
        <div class="modal-content" style="width: 600px; max-width: 95%; margin: 5% auto;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid var(--glass-border); padding-bottom:12px;">
                <h3 id="multiTripsTitle" style="margin:0; font-size: 1.15rem; color: var(--pbi-blue);">Daftar Perjalanan</h3>
                <button onclick="closeMultiTripsModal()" style="background:none; border:none; cursor:pointer; font-size:1.5rem; color:var(--text-muted);">&times;</button>
            </div>
            <div id="multiTripsBody" style="max-height: 60vh; overflow-y: auto;">
                <table class="pbi-table" style="font-size:0.85rem;">
                    <thead>
                        <tr>
                            <th>TX ID</th>
                            <th>Penumpang</th>
                            <th>Tujuan</th>
                            <th>Mulai</th>
                            <th>Selesai</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="multiTripsTableContent"></tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
