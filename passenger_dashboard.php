<?php
require_once 'config.php';

if (!isset($_SESSION['passenger_id'])) {
    header('Location: passenger_login.php');
    exit;
}

if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'en';
}

$passenger_id = $_SESSION['passenger_id'];
$passenger_name = $_SESSION['passenger_name'];

// Check if passenger is a supervisor
$stmt_supervisor = $pdo->prepare("SELECT COUNT(*) FROM users WHERE supervisor_id = ?");
$stmt_supervisor->execute([$passenger_id]);
$is_supervisor = $stmt_supervisor->fetchColumn() > 0;

// Fetch full passenger details
$stmt_pass = $pdo->prepare("SELECT * FROM master_passengers WHERE id = ?");
$stmt_pass->execute([$passenger_id]);
$passenger_info = $stmt_pass->fetch();

$total_conv_ot = 0;
$approved_conv_ot = 0;
$pending_conv_ot = 0;

// POST Action Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'approve_ot') {
        header('Content-Type: application/json');
        try {
            $shift_id = $_POST['shift_id'];
            $note = trim($_POST['supervisor_note'] ?? '');
            $stmt = $pdo->prepare("UPDATE shifts SET approval_status = 'approved', approved_by_name = ?, supervisor_note = ?, approved_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute(['Spv: ' . $passenger_name, $note, $shift_id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    if ($_POST['action'] === 'approve_all_ot') {
        $start = $_POST['start_date'];
        $end = $_POST['end_date'];
        try {
            $stmt = $pdo->prepare("
                UPDATE shifts s
                JOIN users u ON s.driver_id = u.id
                SET s.approval_status = 'approved', s.approved_by_name = ?, s.approved_at = CURRENT_TIMESTAMP
                WHERE u.supervisor_id = ? AND s.status = 'completed' AND s.approval_status = 'pending'
                  AND s.shift_date BETWEEN ? AND ?
            ");
            $stmt->execute(['Spv: ' . $passenger_name, $passenger_id, $start, $end]);
            header("Location: passenger_dashboard.php?start_date={$start}&end_date={$end}&msg=" . urlencode("Successfully approved all pending overtime shifts in range"));
        } catch (Exception $e) {
            header("Location: passenger_dashboard.php?start_date={$start}&end_date={$end}&err=" . urlencode("Failed to approve: " . $e->getMessage()));
        }
        exit;
    }
    if ($_POST['action'] === 'update_language') {
        $_SESSION['lang'] = $_POST['lang'];
        header("Location: passenger_dashboard.php");
        exit;
    }
    if ($_POST['action'] === 'update_profile') {
        $email = trim($_POST['email'] ?? '');
        $stmt = $pdo->prepare("UPDATE master_passengers SET email = ? WHERE id = ?");
        $stmt->execute([$email, $passenger_id]);
        header("Location: passenger_dashboard.php?tab=settings&msg=" . urlencode($lang === 'id' ? "Profil berhasil diperbarui" : "Profile updated successfully"));
        exit;
    }
    if ($_POST['action'] === 'change_pin') {
        $current_pin = $_POST['current_pin'] ?? '';
        $new_pin = $_POST['new_pin'] ?? '';
        $confirm_pin = $_POST['confirm_pin'] ?? '';
        
        // Fetch passenger record
        $stmt = $pdo->prepare("SELECT pin FROM master_passengers WHERE id = ?");
        $stmt->execute([$passenger_id]);
        $stored_hash = $stmt->fetchColumn();
        
        if (!password_verify($current_pin, $stored_hash)) {
            header("Location: passenger_dashboard.php?tab=settings&err=" . urlencode($lang === 'id' ? "PIN saat ini salah!" : "Current PIN is incorrect!"));
            exit;
        }
        if (strlen($new_pin) < 4 || strlen($new_pin) > 6) {
            header("Location: passenger_dashboard.php?tab=settings&err=" . urlencode($lang === 'id' ? "PIN baru harus 4-6 digit!" : "New PIN must be 4-6 digits!"));
            exit;
        }
        if ($new_pin !== $confirm_pin) {
            header("Location: passenger_dashboard.php?tab=settings&err=" . urlencode($lang === 'id' ? "Konfirmasi PIN tidak cocok!" : "Confirm PIN does not match!"));
            exit;
        }
        
        $new_hash = password_hash($new_pin, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE master_passengers SET pin = ? WHERE id = ?")->execute([$new_hash, $passenger_id]);
        header("Location: passenger_dashboard.php?tab=settings&msg=" . urlencode($lang === 'id' ? "PIN berhasil diubah" : "PIN updated successfully"));
        exit;
    }
}

// 1. Calculate Date Range (Weekly: Monday to Sunday of the active week)
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('monday this week'));
$end_date = $_GET['end_date'] ?? date('Y-m-d', strtotime('sunday this week'));

// 2. Fetch Passenger's Own Trip History (Read-only)
$stmt_my_trips = $pdo->prepare("SELECT t.*, d.name as dest_name, u.full_name as driver_name, c.car_no, s.shift_date,
                                (SELECT SUM(amount) FROM trip_expenses WHERE trip_id = t.id AND expense_type = 'gasoline') as gas_amt,
                                (SELECT SUM(amount) FROM trip_expenses WHERE trip_id = t.id AND expense_type = 'toll') as toll_amt,
                                (SELECT SUM(amount) FROM trip_expenses WHERE trip_id = t.id AND expense_type = 'lunch') as lunch_amt,
                                (SELECT SUM(amount) FROM trip_expenses WHERE trip_id = t.id AND expense_type = 'parking') as park_amt,
                                (SELECT SUM(amount) FROM trip_expenses WHERE trip_id = t.id AND expense_type = 'others') as others_amt
                                FROM trips t 
                                JOIN master_destinations d ON t.destination_id = d.id 
                                JOIN shifts s ON t.shift_id = s.id
                                JOIN users u ON s.driver_id = u.id
                                JOIN master_cars c ON t.car_id = c.id
                                WHERE t.passenger_id = ? AND t.status = 'completed'
                                  AND s.shift_date BETWEEN ? AND ?
                                ORDER BY s.shift_date DESC, t.start_time DESC");
$stmt_my_trips->execute([$passenger_id, $start_date, $end_date]);
$my_trips = $stmt_my_trips->fetchAll();

// 3. Supervisor Driver Overtime and Expense Monitoring
$supervisor_shifts = [];
$supervisor_expenses = [];
if ($is_supervisor) {
    // Overtime Shifts
    $stmt_sup_shifts = $pdo->prepare("SELECT s.*, u.full_name as driver_name 
                                      FROM shifts s 
                                      JOIN users u ON s.driver_id = u.id 
                                      WHERE u.supervisor_id = ? AND s.status = 'completed' AND s.real_ot > 0
                                        AND s.shift_date BETWEEN ? AND ?
                                      ORDER BY s.shift_date ASC, s.start_time ASC");
    $stmt_sup_shifts->execute([$passenger_id, $start_date, $end_date]);
    $supervisor_shifts = $stmt_sup_shifts->fetchAll();

    foreach ($supervisor_shifts as $s) {
        $val = floatval($s['conv_ot'] ?: 0);
        $total_conv_ot += $val;
        if ($s['approval_status'] === 'approved') {
            $approved_conv_ot += $val;
        } elseif ($s['approval_status'] === 'pending') {
            $pending_conv_ot += $val;
        }
    }

    // Expenses mapped like report.php
    $stmt_sup_expenses = $pdo->prepare("
        SELECT t.*, u.full_name as driver_name, c.car_no, d.name as dest_name, p.name as pass_name, s.shift_date,
               (SELECT SUM(amount) FROM trip_expenses WHERE trip_id = t.id AND expense_type = 'gasoline') as gas_amt,
               (SELECT SUM(litre) FROM trip_expenses WHERE trip_id = t.id AND expense_type = 'gasoline') as gas_litre,
               (SELECT SUM(amount) FROM trip_expenses WHERE trip_id = t.id AND expense_type = 'toll') as toll_amt,
               (SELECT SUM(amount) FROM trip_expenses WHERE trip_id = t.id AND expense_type = 'lunch') as lunch_amt,
               (SELECT SUM(amount) FROM trip_expenses WHERE trip_id = t.id AND expense_type = 'others') as others_amt,
               (SELECT SUM(amount) FROM trip_expenses WHERE trip_id = t.id AND expense_type = 'parking') as parking_amt
        FROM trips t
        JOIN shifts s ON t.shift_id = s.id
        JOIN users u ON s.driver_id = u.id
        JOIN master_cars c ON t.car_id = c.id
        JOIN master_destinations d ON t.destination_id = d.id
        JOIN master_passengers p ON t.passenger_id = p.id
        WHERE u.supervisor_id = ? AND t.status = 'completed'
          AND s.shift_date BETWEEN ? AND ?
        ORDER BY s.shift_date DESC, t.start_time DESC
    ");
    $stmt_sup_expenses->execute([$passenger_id, $start_date, $end_date]);
    $supervisor_expenses = $stmt_sup_expenses->fetchAll();
    
    // Add receipt details for supervisor expenses view
    foreach ($supervisor_expenses as &$row) {
        $stmt_exp = $pdo->prepare("SELECT id, expense_type, amount, photo, supervisor_note, approval_status, created_at FROM trip_expenses WHERE trip_id = ?");
        $stmt_exp->execute([$row['id']]);
        $row['expense_details'] = $stmt_exp->fetchAll();
    }
}

// Fetch mandatory_photo setting
$mandatory_photo = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'mandatory_photo'")->fetchColumn() ?: '1';

// Dynamic Greeting based on time
$hour = (int)date('H');
$lang = $_SESSION['lang'];
if ($hour >= 4 && $hour < 12) {
    $greeting = ($lang === 'id') ? 'Selamat Pagi' : 'Good Morning';
} elseif ($hour >= 12 && $hour < 17) {
    $greeting = ($lang === 'id') ? 'Selamat Siang' : 'Good Afternoon';
} elseif ($hour >= 17 && $hour < 22) {
    $greeting = ($lang === 'id') ? 'Selamat Malam' : 'Good Evening';
} else {
    $greeting = ($lang === 'id') ? 'Selamat Malam' : 'Good Night';
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" class="notranslate">
<head>
    <meta charset="UTF-8">
    <meta name="google" content="notranslate">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="icon.png">
    <title>Passenger Dashboard - framas</title>
    
    <!-- PWA Setup -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#2563eb">
    <link rel="apple-touch-icon" href="icon-192.png">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('sw.js')
                    .then(reg => console.log('PWA Service Worker registered!', reg))
                    .catch(err => console.log('PWA Service Worker registration failed!', err));
            });
        }
    </script>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Export Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    
    <style>
        :root {
            --primary: #0f172a;
            --primary-accent: #2563eb;
            --primary-light: #eff6ff;
            --bg-page: #f8fafc;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --text-main: #334155;
            --text-muted: #64748b;
            --success: #166534;
            --success-bg: #dcfce7;
            --warning: #b45309;
            --warning-bg: #fef3c7;
            --danger: #ef4444;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            --header-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
            background: var(--bg-page);
            margin: 0;
            color: var(--text-main);
            -webkit-font-smoothing: antialiased;
        }

        /* Premium Gradient Header Banner with Image overlay */
        .header-banner {
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.9) 0%, rgba(30, 41, 59, 0.95) 50%, rgba(37, 99, 235, 0.8) 100%), url('corporate_banner.png');
            background-size: cover;
            background-position: center;
            padding: 32px 24px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 4px solid var(--primary-accent);
            box-shadow: var(--header-shadow);
            border-radius: 0 0 16px 16px;
        }

        .header-title-box {
            display: flex;
            flex-direction: column;
        }

        .header-title {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .header-subtitle {
            margin: 6px 0 0 0;
            font-size: 0.9rem;
            color: #94a3b8;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .btn-logout {
            color: white;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 700;
            padding: 10px 20px;
            border-radius: 10px;
            background: rgba(239, 68, 68, 0.9);
            border: 1px solid rgba(239, 68, 68, 0.5);
            transition: all 0.2s ease;
            box-shadow: 0 4px 6px rgba(239, 68, 68, 0.2);
        }

        .btn-logout:hover {
            background: #ef4444;
            transform: translateY(-1px);
        }

        .container {
            max-width: 98%;
            margin: 0 auto;
            padding: 24px 16px;
        }

        /* Filter Row & Controls */
        .filter-section {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: var(--card-shadow);
        }

        .filter-form {
            display: flex;
            gap: 16px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
            min-width: 180px;
        }

        .filter-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .filter-input {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.85rem;
            font-family: inherit;
            color: var(--text-main);
            background: var(--bg-page);
            box-sizing: border-box;
            width: 100%;
        }

        .btn-submit {
            padding: 11px 24px;
            background: var(--primary-accent);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 6px rgba(37, 99, 235, 0.15);
        }

        .btn-submit:hover {
            opacity: 0.95;
            transform: translateY(-1px);
        }

        /* Navigation Tabs */
        .tab-nav {
            display: flex;
            background: var(--card-bg);
            border-radius: 12px;
            margin-bottom: 24px;
            border: 1px solid var(--border-color);
            padding: 6px;
            overflow-x: auto;
            gap: 6px;
            box-shadow: var(--card-shadow);
        }

        .tab-btn {
            flex: 1;
            min-width: 140px;
            padding: 12px;
            border: none;
            background: transparent;
            font-weight: 700;
            cursor: pointer;
            color: var(--text-muted);
            font-size: 0.85rem;
            border-radius: 8px;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .tab-btn.active {
            background: var(--primary-light);
            color: var(--primary-accent);
        }

        /* Dashboard Summary Cards */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .dashboard-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            padding: 20px;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            display: flex;
            flex-direction: column;
            gap: 6px;
            transition: transform 0.2s;
        }

        .dashboard-card:hover {
            transform: translateY(-2px);
        }

        .dashboard-card-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .dashboard-card-value {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--primary);
            font-family: 'Outfit', sans-serif;
        }

        /* Card Container (Responsive items) */
        .card-container {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .data-card {
            background: var(--card-bg);
            border-radius: 14px;
            border: 1px solid var(--border-color);
            padding: 20px;
            box-shadow: var(--card-shadow);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .data-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        .trip-row {
            display: flex;
            margin-bottom: 10px;
            font-size: 0.85rem;
            align-items: center;
        }

        .trip-label {
            width: 120px;
            color: var(--text-muted);
            font-weight: 700;
        }

        .trip-value {
            flex: 1;
            font-weight: 700;
            color: var(--primary);
        }

        /* Table Design */
        .data-table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow-x: auto;
            box-shadow: var(--card-shadow);
        }

        .corporate-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            text-align: left;
        }

        .corporate-table th {
            background: #f8fafc;
            padding: 14px 16px;
            font-weight: 700;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }

        .corporate-table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
            white-space: nowrap;
        }

        .badge {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 6px;
            text-transform: uppercase;
        }

        .badge-success { background: var(--success-bg); color: var(--success); }
        .badge-pending { background: var(--warning-bg); color: var(--warning); }

        /* Form classes */
        .form-group {
            margin-bottom: 16px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            text-align: left;
        }
        .form-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.85rem;
            font-family: inherit;
            color: var(--text-main);
            background: var(--bg-page);
            box-sizing: border-box;
            display: block;
        }
        .form-input:focus {
            outline: none;
            border-color: var(--primary-accent);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            background: #fff;
        }

        /* Modal classes */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(5px); }
        .modal-content { background: var(--card-bg); margin: 10% auto; padding: 24px; border-radius: 12px; width: 450px; max-width: 90%; border: 1px solid var(--border-color); }

        /* Mobile Responsive Adjustments */
        @media (max-width: 768px) {
            .header-banner {
                flex-direction: column;
                gap: 16px;
                text-align: center;
                padding: 24px 16px;
            }
            .filter-form {
                flex-direction: column;
                align-items: stretch;
            }
            .filter-group {
                width: 100%;
            }
            .btn-submit {
                width: 100%;
            }
            .corporate-table th, .corporate-table td {
                padding: 10px 12px;
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>

    <!-- PWA Install Banner -->
    <div id="pwa-install-banner" style="display: none; background: var(--primary-light); border-bottom: 1px solid rgba(37,99,235,0.2); padding: 14px 24px; justify-content: space-between; align-items: center; gap: 12px; font-size: 0.85rem; color: var(--primary-accent); font-weight: 700; box-shadow: var(--card-shadow);">
        <span>📲 <?= $lang === 'id' ? 'Pasang aplikasi web ini di layar utama Anda untuk akses cepat dan aman ke data perjalanan Anda.' : 'Install this web app on your home screen for quick, secure access to your trip data.' ?></span>
        <div style="display: flex; gap: 12px; align-items: center; flex-shrink: 0;">
            <button onclick="triggerPWAInstall()" class="btn-submit" style="padding: 6px 16px; font-size: 0.8rem; border-radius: 6px; box-shadow: none;"><?= $lang === 'id' ? 'Pasang' : 'Install' ?></button>
            <button onclick="document.getElementById('pwa-install-banner').style.display='none'" style="background: none; border: none; color: var(--primary-accent); font-size: 1.5rem; font-weight: bold; cursor: pointer; line-height: 1; padding: 0;">&times;</button>
        </div>
    </div>

    <!-- Premium Header Banner -->
    <div class="header-banner">
        <div class="header-title-box">
            <h1 class="header-title"><?= $greeting ?>, <?= htmlspecialchars($passenger_name) ?></h1>
            <p class="header-subtitle"><?= $is_supervisor ? ($lang === 'id' ? 'Portal Verifikasi & Operasional Driver' : 'Driver Operations & Approvals Console') : ($lang === 'id' ? 'Dashboard Penumpang' : 'Passenger Dashboard') ?></p>
        </div>
        <div>
            <a href="logout.php?type=passenger" class="btn-logout"><?= $lang === 'id' ? 'Keluar' : 'Logout' ?></a>
        </div>
    </div>

    <div class="container">

        <!-- Banner messages -->
        <?php if (isset($_GET['msg'])): ?>
            <div style="background: var(--success-bg); color: var(--success); border: 1px solid #bbf7d0; padding: 14px; border-radius: 10px; margin-bottom: 20px; font-size: 0.85rem; font-weight: 600;">
                ✅ <?= htmlspecialchars($_GET['msg']) ?>
            </div>
        <?php endif; ?>

        <!-- Date Range Filter Area -->
        <div class="filter-section">
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <span class="filter-label"><?= $lang === 'id' ? 'Tanggal Mulai (Senin)' : 'Start Date (Monday)' ?></span>
                    <input type="date" name="start_date" class="filter-input" value="<?= $start_date ?>">
                </div>
                <div class="filter-group">
                    <span class="filter-label"><?= $lang === 'id' ? 'Tanggal Selesai (Minggu)' : 'End Date (Sunday)' ?></span>
                    <input type="date" name="end_date" class="filter-input" value="<?= $end_date ?>">
                </div>
                <button type="submit" class="btn-submit"><?= $lang === 'id' ? 'Terapkan' : 'Apply Range' ?></button>
            </form>
        </div>

        <!-- Navigation Tabs -->
        <div class="tab-nav">
            <?php if ($is_supervisor): ?>
                <button class="tab-btn active" id="tab-btn-overtime" onclick="switchTab('overtime')"><?= $lang === 'id' ? 'Lembur Driver Saya' : 'My Driver Overtime' ?></button>
                <button class="tab-btn" id="tab-btn-expenses" onclick="switchTab('expenses')"><?= $lang === 'id' ? 'Biaya Driver Saya' : 'My Driver Expense' ?></button>
            <?php endif; ?>
            <button class="tab-btn <?= !$is_supervisor ? 'active' : '' ?>" id="tab-btn-myhistory" onclick="switchTab('myhistory')"><?= $lang === 'id' ? 'Riwayat Saya' : 'My Trip History' ?></button>
            <button class="tab-btn" id="tab-btn-settings" onclick="switchTab('settings')"><?= $lang === 'id' ? 'Pengaturan' : 'Settings' ?></button>
        </div>

        <!-- ==================== TAB 1: DRIVER OVERTIME (SUPERVISOR ONLY) ==================== -->
        <?php if ($is_supervisor): ?>
        <div id="tab-content-overtime" class="tab-pane">
            
            <!-- Overtime Cost Estimator Menu -->
            <div style="background: rgba(37, 99, 235, 0.05); border: 1px solid rgba(37, 99, 235, 0.15); border-radius: 12px; padding: 16px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; box-shadow: var(--card-shadow);">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span style="font-size: 1.5rem;">💸</span>
                    <div>
                        <div style="font-weight: 700; color: var(--primary); font-size: 0.9rem;"><?= $lang === 'id' ? 'Estimator Biaya Lembur (Gaji Pokok)' : 'Overtime Cost Estimator (Basic Salary)' ?></div>
                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= $lang === 'id' ? 'Rumus standar: (Gaji Pokok / 173) × Jam Lembur Konversi' : 'Standard formula: (Basic Salary / 173) × Converted OT Hours' ?></div>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-weight: 700; font-size: 0.85rem; color: var(--text-main);">Rp</span>
                    <input type="number" id="base-salary-input" value="5000000" oninput="recalculateOtCost()" class="filter-input" style="width: 140px; padding: 8px 12px; font-weight: 700; font-size: 0.9rem;">
                </div>
            </div>

            <!-- Converted OT Dashboard -->
            <div class="dashboard-grid">
                <div class="dashboard-card" style="border-left: 4px solid var(--primary-accent);">
                    <span class="dashboard-card-label"><?= $lang === 'id' ? 'Total Konversi OT' : 'Total Converted OT' ?></span>
                    <strong class="dashboard-card-value"><?= (float)$total_conv_ot ?> Hrs</strong>
                    <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); margin-top: 4px;" id="total-ot-cost">Est: Rp 0</div>
                </div>
                <div class="dashboard-card" style="border-left: 4px solid var(--success);">
                    <span class="dashboard-card-label" style="color: var(--success);"><?= $lang === 'id' ? 'OT Disetujui' : 'OT Approved' ?></span>
                    <strong class="dashboard-card-value" style="color: var(--success);"><?= (float)$approved_conv_ot ?> Hrs</strong>
                    <div style="font-size: 0.75rem; font-weight: 700; color: #166534; margin-top: 4px;" id="approved-ot-cost">Est: Rp 0</div>
                </div>
                <div class="dashboard-card" style="border-left: 4px solid var(--warning);">
                    <span class="dashboard-card-label" style="color: var(--warning);"><?= $lang === 'id' ? 'OT Pending' : 'OT Pending' ?></span>
                    <strong class="dashboard-card-value" style="color: var(--warning);"><?= (float)$pending_conv_ot ?> Hrs</strong>
                    <div style="font-size: 0.75rem; font-weight: 700; color: #b45309; margin-top: 4px;" id="pending-ot-cost">Est: Rp 0</div>
                </div>
            </div>

            <!-- Table controls (Sort) -->
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <span class="filter-label" style="margin: 0;"><?= $lang === 'id' ? 'Urutan:' : 'Sort By:' ?></span>
                    <select id="sort-ot" onchange="sortOtTable()" class="filter-input" style="padding: 6px 10px; font-size: 0.8rem; width: auto; min-width: 140px; margin-right: 8px;">
                        <option value="date_desc">Date: Newest First</option>
                        <option value="date_asc">Date: Oldest First</option>
                        <option value="ot_desc">OT: High to Low</option>
                        <option value="ot_asc">OT: Low to High</option>
                    </select>
                    <button type="button" onclick="exportOtToExcel()" class="btn-submit" style="background: #166534; padding: 8px 12px; font-size: 0.8rem; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px; border: none; box-shadow: none; cursor: pointer;"><?= $lang === 'id' ? 'Ekspor Excel 📊' : 'Export Excel 📊' ?></button>
                    <button type="button" onclick="exportOtToPdf()" class="btn-submit" style="background: #991b1b; padding: 8px 12px; font-size: 0.8rem; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px; border: none; box-shadow: none; cursor: pointer;"><?= $lang === 'id' ? 'Ekspor PDF 📄' : 'Export PDF 📄' ?></button>
                </div>
                
                <?php 
                $pending_ot_shifts = array_filter($supervisor_shifts, function($s) { return $s['approval_status'] === 'pending'; });
                if (count($pending_ot_shifts) > 0): 
                ?>
                    <form action="" method="POST" onsubmit="return confirm('<?= $lang === 'id' ? 'Setujui semua lembur yang pending untuk minggu ini?' : 'Approve all pending overtime shifts for this week?' ?>')">
                        <input type="hidden" name="action" value="approve_all_ot">
                        <input type="hidden" name="start_date" value="<?= $start_date ?>">
                        <input type="hidden" name="end_date" value="<?= $end_date ?>">
                        <button type="submit" class="btn-submit" style="background: var(--success); padding: 8px 16px;"><?= $lang === 'id' ? '✓ Setujui Semua' : '✓ Approve All Pending' ?></button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Overtime Table -->
            <div class="data-table-container">
                <table class="corporate-table" id="otTable">
                    <thead>
                        <tr>
                            <th>Driver</th>
                            <th>Date</th>
                            <th>Time (In/Out)</th>
                            <th>Real OT</th>
                            <th>Conv. OT</th>
                            <th><?= $lang === 'id' ? 'Estimasi Biaya' : 'Est. Cost' ?></th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="ot-tbody">
                        <?php if (empty($supervisor_shifts)): ?>
                            <tr class="empty-row">
                                <td colspan="8" align="center" style="padding: 24px; color: var(--text-muted);">
                                    <?= $lang === 'id' ? 'Tidak ada data lembur driver pada periode ini.' : 'No driver overtime shifts found in this period.' ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($supervisor_shifts as $s): ?>
                                <tr class="ot-tr" data-date="<?= $s['shift_date'] ?>" data-ot="<?= floatval($s['conv_ot']) ?>">
                                    <td><strong><?= htmlspecialchars($s['driver_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($s['shift_date']) ?></td>
                                    <td><?= substr($s['start_time'], 0, 5) ?> - <?= $s['end_time'] ? substr($s['end_time'], 0, 5) : '-' ?></td>
                                    <td><?= (float)$s['real_ot'] ?></td>
                                    <td><strong><?= (float)$s['conv_ot'] ?></strong></td>
                                    <td class="ot-cost-cell" data-hours="<?= floatval($s['conv_ot']) ?>">Rp 0</td>
                                    <td>
                                        <span class="badge badge-<?= $s['approval_status'] === 'approved' ? 'success' : 'pending' ?>">
                                            <?= $s['approval_status'] === 'approved' ? ($lang === 'id' ? 'Disetujui' : 'Approved') : ($lang === 'id' ? 'Pending' : 'Pending') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($s['approval_status'] === 'pending'): ?>
                                            <button onclick="openApproveOtModal(<?= $s['id'] ?>, '<?= htmlspecialchars($s['driver_name']) ?>', '<?= $s['shift_date'] ?>')" class="btn-submit" style="padding: 4px 8px; font-size: 0.75rem; background: var(--success);"><?= $lang === 'id' ? 'Setujui' : 'Approve' ?></button>
                                        <?php else: ?>
                                            <span style="font-size:0.75rem; color:var(--text-muted); font-style:italic;">By Spv</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ==================== TAB 2: DRIVER EXPENSE (SUPERVISOR ONLY) ==================== -->
        <div id="tab-content-expenses" class="tab-pane" style="display:none;">
            <?php
            // Calculate total expenses for Supervisor's Driver Expense tab
            $gasTotal = 0; $tollTotal = 0; $lunchTotal = 0; $othersTotal = 0;
            foreach ($supervisor_expenses as $row) {
                $gasTotal += floatval($row['gas_amt'] ?: 0);
                $tollTotal += floatval($row['toll_amt'] ?: 0);
                $lunchTotal += floatval($row['lunch_amt'] ?: 0);
                $othersTotal += floatval($row['others_amt'] ?: 0) + floatval($row['parking_amt'] ?: 0);
            }
            $expGrandTotal = $gasTotal + $tollTotal + $lunchTotal + $othersTotal;
            ?>
            
            <!-- Expenses Mini Dashboard -->
            <div class="dashboard-grid">
                <div class="dashboard-card">
                    <span class="dashboard-card-label"><?= $lang === 'id' ? 'Total Pengeluaran' : 'Total Expenses' ?></span>
                    <strong class="dashboard-card-value" style="color: var(--primary-accent);">Rp <?= number_format($expGrandTotal) ?></strong>
                </div>
                <div class="dashboard-card">
                    <span class="dashboard-card-label">Gasoline (BBM)</span>
                    <strong class="dashboard-card-value">Rp <?= number_format($gasTotal) ?></strong>
                </div>
                <div class="dashboard-card">
                    <span class="dashboard-card-label">Toll</span>
                    <strong class="dashboard-card-value">Rp <?= number_format($tollTotal) ?></strong>
                </div>
                <div class="dashboard-card">
                    <span class="dashboard-card-label">Lunch & Others</span>
                    <strong class="dashboard-card-value">Rp <?= number_format($lunchTotal + $othersTotal) ?></strong>
                </div>
            </div>

            <!-- Sorting & Search Control -->
            <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; align-items: center;">
                <span class="filter-label"><?= $lang === 'id' ? 'Urutan:' : 'Sort By:' ?></span>
                <select id="sort-expenses" onchange="sortExpensesTable()" class="filter-input" style="padding: 6px 10px; font-size: 0.8rem; width: auto; min-width: 140px;">
                    <option value="date_desc">Date: Newest First</option>
                    <option value="date_asc">Date: Oldest First</option>
                    <option value="cost_desc">Cost: High to Low</option>
                    <option value="cost_asc">Cost: Low to High</option>
                </select>

                <span class="filter-label" style="margin-left: 12px;"><?= $lang === 'id' ? 'Status Cek Admin:' : 'Admin Checked Status:' ?></span>
                <select id="filter-expense-checked" onchange="filterExpensesTableByChecked()" class="filter-input" style="padding: 6px 10px; font-size: 0.8rem; width: auto; min-width: 160px;">
                    <option value="all"><?= $lang === 'id' ? 'Semua Status' : 'All Status' ?></option>
                    <option value="unchecked"><?= $lang === 'id' ? 'Belum Dicek Admin' : 'Unchecked by Admin' ?></option>
                    <option value="checked"><?= $lang === 'id' ? 'Sudah Dicek Admin' : 'Checked by Admin' ?></option>
                </select>
                
                <button type="button" onclick="exportExpensesToExcel()" class="btn-submit" style="background: #166534; padding: 8px 12px; font-size: 0.8rem; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px; border: none; box-shadow: none; cursor: pointer; margin-left: 12px;"><?= $lang === 'id' ? 'Ekspor Excel 📊' : 'Export Excel 📊' ?></button>
                <button type="button" onclick="exportExpensesToPdf()" class="btn-submit" style="background: #991b1b; padding: 8px 12px; font-size: 0.8rem; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px; border: none; box-shadow: none; cursor: pointer;"><?= $lang === 'id' ? 'Ekspor PDF 📄' : 'Export PDF 📄' ?></button>
            </div>

            <!-- Driver Expense Table -->
            <div class="data-table-container">
                <table class="corporate-table" id="expenseTable">
                    <thead>
                        <tr>
                            <th>TX ID</th>
                            <th>Driver</th>
                            <th>Date</th>
                            <th>Passenger</th>
                            <th>Destination</th>
                            <th>Gasoline</th>
                            <th>Toll</th>
                            <th>Others</th>
                            <th>Lunch</th>
                            <th align="center">Checked</th>
                        </tr>
                    </thead>
                    <tbody id="expenses-tbody">
                        <?php if (empty($supervisor_expenses)): ?>
                            <tr class="empty-row">
                                <td colspan="10" align="center" style="padding: 24px; color: var(--text-muted);">
                                    <?= $lang === 'id' ? 'Tidak ada rincian biaya driver periode ini.' : 'No driver expenses found in this period.' ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($supervisor_expenses as $r): ?>
                                <?php
                                // Check if all expenses are approved by Admin
                                $allApproved = true;
                                if (isset($r['expense_details']) && count($r['expense_details']) > 0) {
                                    foreach ($r['expense_details'] as $e) {
                                        if ($e['approval_status'] !== 'approved') {
                                            $allApproved = false;
                                            break;
                                        }
                                    }
                                } else {
                                    $allApproved = true; // No expenses to approve
                                }
                                $checkedHtml = $allApproved 
                                    ? '<span style="color: var(--success); font-weight:700; font-size:1.1rem;">✔</span>' 
                                    : '<span style="color: var(--text-muted);">-</span>';
                                $rowTotalCost = floatval($r['gas_amt']) + floatval($r['toll_amt']) + floatval($r['lunch_amt']) + floatval($r['others_amt']) + floatval($r['parking_amt']);
                                ?>
                                <tr class="expense-tr" data-date="<?= $r['shift_date'] ?>" data-cost="<?= $rowTotalCost ?>" data-checked="<?= $allApproved ? '1' : '0' ?>">
                                    <td><a href="#" onclick="showTripDetailById(event, <?= $r['id'] ?>)" style="color: var(--primary-accent); text-decoration: underline; font-weight: 700; cursor: pointer;">TX-<?= $r['id'] ?></a></td>
                                    <td><?= htmlspecialchars($r['driver_name']) ?></td>
                                    <td><?= htmlspecialchars($r['shift_date']) ?></td>
                                    <td><?= htmlspecialchars($r['pass_name']) ?></td>
                                    <td><strong><?= htmlspecialchars($r['dest_name']) ?></strong></td>
                                    <td><?= $r['gas_amt'] ? 'Rp '.number_format($r['gas_amt']) : '-' ?></td>
                                    <td><?= $r['toll_amt'] ? 'Rp '.number_format($r['toll_amt']) : '-' ?></td>
                                    <td><?= ($r['others_amt'] || $r['parking_amt']) ? 'Rp '.number_format(($r['others_amt'] ?: 0) + ($r['parking_amt'] ?: 0)) : '-' ?></td>
                                    <td><?= $r['lunch_amt'] ? 'Rp '.number_format($r['lunch_amt']) : '-' ?></td>
                                    <td align="center"><?= $checkedHtml ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ==================== TAB 3: MY TRIP HISTORY ==================== -->
        <div id="tab-content-myhistory" class="tab-pane" style="display: <?= !$is_supervisor ? 'block' : 'none' ?>;">
            <?php
            // Calculate passenger's own trips costs
            $myGas = 0; $myToll = 0; $myLunch = 0; $myOthers = 0; $myKM = 0;
            foreach ($my_trips as $mt) {
                $myGas += floatval($mt['gas_amt'] ?: 0);
                $myToll += floatval($mt['toll_amt'] ?: 0);
                $myLunch += floatval($mt['lunch_amt'] ?: 0);
                $myOthers += floatval($mt['park_amt'] ?: 0) + floatval($mt['others_amt'] ?: 0);
                if (floatval($mt['km_end']) > 0) {
                    $myKM += max(0, floatval($mt['km_end']) - floatval($mt['km_start']));
                }
            }
            $myGrandTotal = $myGas + $myToll + $myLunch + $myOthers;
            ?>

            <!-- My History Dashboard -->
            <div class="dashboard-grid">
                <div class="dashboard-card">
                    <span class="dashboard-card-label"><?= $lang === 'id' ? 'Total Pengeluaran Saya' : 'My Expenses' ?></span>
                    <strong class="dashboard-card-value" style="color: var(--primary-accent);">Rp <?= number_format($myGrandTotal) ?></strong>
                </div>
                <div class="dashboard-card">
                    <span class="dashboard-card-label"><?= $lang === 'id' ? 'Total Jarak' : 'Total Distance' ?></span>
                    <strong class="dashboard-card-value" style="color: #10b981;"><?= number_format($myKM, 1) ?> KM</strong>
                </div>
                <div class="dashboard-card">
                    <span class="dashboard-card-label">Gasoline</span>
                    <strong class="dashboard-card-value">Rp <?= number_format($myGas) ?></strong>
                </div>
                <div class="dashboard-card">
                    <span class="dashboard-card-label">Toll</span>
                    <strong class="dashboard-card-value">Rp <?= number_format($myToll) ?></strong>
                </div>
                <div class="dashboard-card">
                    <span class="dashboard-card-label">Lunch & Others</span>
                    <strong class="dashboard-card-value">Rp <?= number_format($myLunch + $myOthers) ?></strong>
                </div>
            </div>

            <!-- Sorting Controls for Cards -->
            <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; align-items: center;">
                <span class="filter-label"><?= $lang === 'id' ? 'Urutan:' : 'Sort By:' ?></span>
                <select id="sort-my-trips" onchange="sortMyTripsList()" class="filter-input" style="padding: 6px 10px; font-size: 0.8rem; width: auto; min-width: 140px;">
                    <option value="date_desc">Date: Newest First</option>
                    <option value="date_asc">Date: Oldest First</option>
                    <option value="cost_desc">Cost: High to Low</option>
                    <option value="cost_asc">Cost: Low to High</option>
                </select>
            </div>

            <!-- My History List -->
            <div class="card-container" id="my-trips-container">
                <?php if (empty($my_trips)): ?>
                    <div class="empty-state">
                        <div style="font-size: 3rem; margin-bottom: 15px; color: var(--text-muted);">🚗</div>
                        <h3 style="margin: 0 0 10px 0; color: #334155;"><?= $lang === 'id' ? 'Belum Ada Perjalanan' : 'No Trips Registered' ?></h3>
                        <p style="margin: 0; font-size: 0.95rem;"><?= $lang === 'id' ? 'Tidak ada riwayat perjalanan Anda dalam range tanggal terpilih.' : 'No trip records found for you in the selected date range.' ?></p>
                    </div>
                <?php else: ?>
                    <?php foreach ($my_trips as $t): ?>
                        <?php
                        $rowTotal = floatval($t['gas_amt'])+floatval($t['toll_amt'])+floatval($t['lunch_amt'])+floatval($t['park_amt'])+floatval($t['others_amt']);
                        ?>
                        <div class="data-card my-trip-card-item" data-date="<?= $t['shift_date'] ?>" data-cost="<?= $rowTotal ?>">
                            <div style="display:flex; justify-content:space-between; margin-bottom:12px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">
                                <span style="font-weight:700; color:var(--primary-accent);">TX-<?= $t['id'] ?></span>
                                <span style="font-size:0.8rem; color:var(--text-muted); font-weight:600;"><?= htmlspecialchars($t['shift_date']) ?></span>
                            </div>
                            <div class="trip-row"><span class="trip-label">Driver:</span><span class="trip-value"><?= htmlspecialchars($t['driver_name']) ?> (<?= htmlspecialchars($t['car_no']) ?>)</span></div>
                            <div class="trip-row"><span class="trip-label">Destination:</span><span class="trip-value"><?= htmlspecialchars($t['dest_name']) ?></span></div>
                            <div class="trip-row"><span class="trip-label">Odometer:</span><span class="trip-value"><?= $t['km_start'] ?> &rarr; <?= $t['km_end'] ?: '-' ?> KM</span></div>
                            <div class="trip-row"><span class="trip-label">Total Cost:</span><span class="trip-value" style="color:#166534; font-weight: 700;">Rp <?= number_format($rowTotal) ?></span></div>
                            <?php 
                            // Determine Google Maps Direction Link
                            if (!empty($t['start_lat']) && !empty($t['start_lng']) && !empty($t['end_lat']) && !empty($t['end_lng'])) {
                                $gmaps_link = "https://www.google.com/maps/dir/?api=1&origin=" . urlencode($t['start_lat'] . ',' . $t['start_lng']) . "&destination=" . urlencode($t['end_lat'] . ',' . $t['end_lng']);
                            } else {
                                $gmaps_link = "https://www.google.com/maps/search/?api=1&query=" . urlencode($t['dest_name']);
                            }
                            ?>
                            <div class="trip-row">
                                <span class="trip-label">GPS / Route:</span>
                                <span class="trip-value">
                                    <a href="<?= $gmaps_link ?>" target="_blank" style="color: var(--primary-accent); text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                        🗺️ <?= $lang === 'id' ? 'Cek Rute Google Maps' : 'Check Google Maps Route' ?>
                                    </a>
                                </span>
                            </div>
                            
                            <!-- Expense breakdown list -->
                            <?php 
                            $breakdown = [];
                            if (floatval($t['gas_amt']) > 0) $breakdown[] = '⛽ ' . ($lang === 'id' ? 'BBM' : 'Gasoline') . ': <strong>Rp ' . number_format($t['gas_amt']) . '</strong>';
                            if (floatval($t['toll_amt']) > 0) $breakdown[] = '🛣️ Toll: <strong>Rp ' . number_format($t['toll_amt']) . '</strong>';
                            if (floatval($t['lunch_amt']) > 0) $breakdown[] = '🍔 ' . ($lang === 'id' ? 'Makan' : 'Lunch') . ': <strong>Rp ' . number_format($t['lunch_amt']) . '</strong>';
                            if (floatval($t['park_amt']) > 0 || floatval($t['others_amt']) > 0) {
                                $oth = floatval($t['park_amt']) + floatval($t['others_amt']);
                                $breakdown[] = '🅿️ ' . ($lang === 'id' ? 'Parkir/Lainnya' : 'Parking/Others') . ': <strong>Rp ' . number_format($oth) . '</strong>';
                            }
                            if (!empty($breakdown)):
                            ?>
                                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px dashed var(--border-color); display: flex; flex-wrap: wrap; gap: 12px; font-size: 0.75rem; color: var(--text-muted);">
                                    <?= implode(' &nbsp;•&nbsp; ', $breakdown) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ==================== TAB 4: SETTINGS ==================== -->
        <div id="tab-content-settings" class="tab-pane" style="display:none;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; margin-top: 8px;">
                
                <!-- Left Card: Profile & Language Settings -->
                <div class="settings-card" style="background:var(--card-bg); border-radius:12px; border:1px solid var(--border-color); padding:24px; box-shadow:var(--card-shadow); text-align: left;">
                    <h3 class="settings-title" style="margin-top:0; font-family:'Outfit',sans-serif; color: var(--primary); font-weight: 700; border-bottom: 2px solid var(--primary-accent); padding-bottom: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
                        👤 <?= $lang === 'id' ? 'Profil & Bahasa' : 'Profile & Language' ?>
                    </h3>
                    
                    <!-- Language Switcher Form -->
                    <form action="" method="POST" style="margin-bottom: 20px; border-bottom: 1px dashed var(--border-color); padding-bottom: 16px;">
                        <input type="hidden" name="action" value="update_language">
                        <div class="form-group">
                            <label class="form-label" style="font-weight: 700; font-size: 0.75rem; color: var(--text-muted);"><?= $lang === 'id' ? 'Bahasa Aplikasi' : 'App Language' ?></label>
                            <select name="lang" class="form-input" onchange="this.form.submit()" style="font-weight: 600;">
                                <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>English</option>
                                <option value="id" <?= $lang === 'id' ? 'selected' : '' ?>>Bahasa Indonesia</option>
                            </select>
                        </div>
                    </form>

                    <!-- Profile Form -->
                    <form action="" method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="form-group">
                            <label class="form-label" style="font-weight: 700; font-size: 0.75rem; color: var(--text-muted);"><?= $lang === 'id' ? 'Nama Lengkap' : 'Full Name' ?></label>
                            <input type="text" class="form-input" value="<?= htmlspecialchars($passenger_info['name'] ?? '') ?>" disabled style="background: #f1f5f9; cursor: not-allowed; font-weight: 600;">
                        </div>
                        <div class="form-group">
                            <label class="form-label" style="font-weight: 700; font-size: 0.75rem; color: var(--text-muted);"><?= $lang === 'id' ? 'Nomor WhatsApp' : 'WhatsApp Number' ?></label>
                            <input type="text" class="form-input" value="<?= htmlspecialchars($passenger_info['wa_no'] ?? '') ?>" disabled style="background: #f1f5f9; cursor: not-allowed; font-weight: 600;">
                        </div>
                        <div class="form-group">
                            <label class="form-label" style="font-weight: 700; font-size: 0.75rem; color: var(--text-muted);"><?= $lang === 'id' ? 'Alamat Email' : 'Email Address' ?></label>
                            <input type="email" name="email" class="form-input" value="<?= htmlspecialchars($passenger_info['email'] ?? '') ?>" placeholder="e.g. user@framas.co.id" style="font-weight: 600;" required>
                        </div>
                        <button type="submit" class="btn-submit" style="width: 100%; border: none; padding: 12px; border-radius: 8px; font-weight: 700; cursor: pointer;"><?= $lang === 'id' ? 'Simpan Profil' : 'Save Profile' ?></button>
                    </form>
                </div>

                <!-- Right Card: Change PIN -->
                <div class="settings-card" style="background:var(--card-bg); border-radius:12px; border:1px solid var(--border-color); padding:24px; box-shadow:var(--card-shadow); text-align: left;">
                    <h3 class="settings-title" style="margin-top:0; font-family:'Outfit',sans-serif; color: var(--primary); font-weight: 700; border-bottom: 2px solid var(--primary-accent); padding-bottom: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
                        🔑 <?= $lang === 'id' ? 'Ganti PIN Pengaman' : 'Change Security PIN' ?>
                    </h3>
                    
                    <form action="" method="POST">
                        <input type="hidden" name="action" value="change_pin">
                        <div class="form-group">
                            <label class="form-label" style="font-weight: 700; font-size: 0.75rem; color: var(--text-muted);"><?= $lang === 'id' ? 'PIN Saat Ini' : 'Current PIN' ?></label>
                            <input type="password" name="current_pin" class="form-input" placeholder="••••" required maxlength="6" inputmode="numeric" pattern="\d{4,6}" style="font-weight: 600; letter-spacing: 2px;">
                        </div>
                        <div class="form-group">
                            <label class="form-label" style="font-weight: 700; font-size: 0.75rem; color: var(--text-muted);"><?= $lang === 'id' ? 'PIN Baru (4-6 Digit)' : 'New PIN (4-6 Digits)' ?></label>
                            <input type="password" name="new_pin" class="form-input" placeholder="••••" required maxlength="6" inputmode="numeric" pattern="\d{4,6}" style="font-weight: 600; letter-spacing: 2px;">
                        </div>
                        <div class="form-group">
                            <label class="form-label" style="font-weight: 700; font-size: 0.75rem; color: var(--text-muted);"><?= $lang === 'id' ? 'Konfirmasi PIN Baru' : 'Confirm New PIN' ?></label>
                            <input type="password" name="confirm_pin" class="form-input" placeholder="••••" required maxlength="6" inputmode="numeric" pattern="\d{4,6}" style="font-weight: 600; letter-spacing: 2px;">
                        </div>
                        <button type="submit" class="btn-submit" style="width: 100%; border: none; padding: 12px; border-radius: 8px; font-weight: 700; cursor: pointer; background: var(--primary-accent);"><?= $lang === 'id' ? 'Update PIN Pengaman' : 'Update Security PIN' ?></button>
                    </form>
                </div>

            </div>
        </div>

    </div>

    <!-- Supervisor OT Approval Modal -->
    <div id="approveOtModal" class="modal">
        <div class="modal-content">
            <h3 style="margin-top:0;" id="spvOtTitle">Approve Overtime</h3>
            <form id="approveOtForm" onsubmit="submitApproveOt(event)">
                <input type="hidden" name="action" value="approve_ot">
                <input type="hidden" name="shift_id" id="spvShiftId">
                <div class="form-group">
                    <label class="form-label"><?= $lang === 'id' ? 'Catatan Supervisor (Opsional)' : 'Supervisor Note (Optional)' ?></label>
                    <input type="text" name="supervisor_note" id="spvNote" class="form-input" placeholder="e.g. Approved overtime">
                </div>
                <div style="display:flex; gap:12px; margin-top:20px;">
                    <button type="button" onclick="closeApproveOtModal()" class="btn-submit" style="background:#64748b; flex:1;"><?= $lang === 'id' ? 'Batal' : 'Cancel' ?></button>
                    <button type="submit" class="btn-submit" style="background:var(--success); flex:1;"><?= $lang === 'id' ? 'Setujui' : 'Approve' ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const supervisorExpensesData = <?= json_encode($supervisor_expenses) ?>;

        // Switch between tabs
        function switchTab(tabId) {
            document.querySelectorAll('.tab-pane').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            
            const targetPane = document.getElementById('tab-content-' + tabId);
            if (targetPane) targetPane.style.display = 'block';
            
            const targetBtn = document.getElementById('tab-btn-' + tabId);
            if (targetBtn) targetBtn.classList.add('active');
        }

        // Open Overtime Approval Modal
        function openApproveOtModal(shiftId, driverName, date) {
            document.getElementById('spvShiftId').value = shiftId;
            document.getElementById('spvNote').value = '';
            document.getElementById('spvOtTitle').innerText = `Approve Overtime: ${driverName} (${date})`;
            document.getElementById('approveOtModal').style.display = 'block';
        }

        function closeApproveOtModal() {
            document.getElementById('approveOtModal').style.display = 'none';
        }

        // Submit Overtime Approval via Ajax
        function submitApproveOt(e) {
            e.preventDefault();
            const form = document.getElementById('approveOtForm');
            const data = new FormData(form);
            
            fetch('passenger_dashboard.php', {
                method: 'POST',
                body: data
            })
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Approved!',
                        text: 'Driver overtime shift approved successfully.',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: res.error || 'Failed to approve.'
                    });
                }
            })
            .catch(err => {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Network error occurred.'
                });
            });
        }

        // Client-side Sorting Functions
        function sortOtTable() {
            const criteria = document.getElementById('sort-ot').value;
            const tbody = document.getElementById('ot-tbody');
            if (!tbody) return;
            const rows = Array.from(tbody.querySelectorAll('tr.ot-tr'));
            if (rows.length === 0) return;

            rows.sort((a, b) => {
                if (criteria === 'date_desc') {
                    return b.getAttribute('data-date').localeCompare(a.getAttribute('data-date'));
                } else if (criteria === 'date_asc') {
                    return a.getAttribute('data-date').localeCompare(b.getAttribute('data-date'));
                } else if (criteria === 'ot_desc') {
                    return parseFloat(b.getAttribute('data-ot')) - parseFloat(a.getAttribute('data-ot'));
                } else if (criteria === 'ot_asc') {
                    return parseFloat(a.getAttribute('data-ot')) - parseFloat(b.getAttribute('data-ot'));
                }
                return 0;
            });

            rows.forEach(r => tbody.appendChild(r));
        }

        function sortExpensesTable() {
            const criteria = document.getElementById('sort-expenses').value;
            const tbody = document.getElementById('expenses-tbody');
            if (!tbody) return;
            const rows = Array.from(tbody.querySelectorAll('tr.expense-tr'));
            if (rows.length === 0) return;

            rows.sort((a, b) => {
                if (criteria === 'date_desc') {
                    return b.getAttribute('data-date').localeCompare(a.getAttribute('data-date'));
                } else if (criteria === 'date_asc') {
                    return a.getAttribute('data-date').localeCompare(b.getAttribute('data-date'));
                } else if (criteria === 'cost_desc') {
                    return parseFloat(b.getAttribute('data-cost')) - parseFloat(a.getAttribute('data-cost'));
                } else if (criteria === 'cost_asc') {
                    return parseFloat(a.getAttribute('data-cost')) - parseFloat(b.getAttribute('data-cost'));
                }
                return 0;
            });

            rows.forEach(r => tbody.appendChild(r));
        }

        function sortMyTripsList() {
            const criteria = document.getElementById('sort-my-trips').value;
            const container = document.getElementById('my-trips-container');
            if (!container) return;
            const cards = Array.from(container.querySelectorAll('.my-trip-card-item'));
            if (cards.length === 0) return;

            cards.sort((a, b) => {
                if (criteria === 'date_desc') {
                    return b.getAttribute('data-date').localeCompare(a.getAttribute('data-date'));
                } else if (criteria === 'date_asc') {
                    return a.getAttribute('data-date').localeCompare(b.getAttribute('data-date'));
                } else if (criteria === 'cost_desc') {
                    return parseFloat(b.getAttribute('data-cost')) - parseFloat(a.getAttribute('data-cost'));
                } else if (criteria === 'cost_asc') {
                    return parseFloat(a.getAttribute('data-cost')) - parseFloat(b.getAttribute('data-cost'));
                }
                return 0;
            });

            cards.forEach(c => container.appendChild(c));
        }

        function filterExpensesTableByChecked() {
            const status = document.getElementById('filter-expense-checked').value;
            const tbody = document.getElementById('expenses-tbody');
            if (!tbody) return;
            const rows = Array.from(tbody.querySelectorAll('tr.expense-tr'));
            
            rows.forEach(row => {
                const isChecked = row.getAttribute('data-checked') === '1';
                if (status === 'all') {
                    row.style.display = '';
                } else if (status === 'unchecked') {
                    row.style.display = !isChecked ? '' : 'none';
                } else if (status === 'checked') {
                    row.style.display = isChecked ? '' : 'none';
                }
            });
        }

        function recalculateOtCost() {
            const salaryInput = document.getElementById('base-salary-input');
            if (!salaryInput) return;
            const salary = parseFloat(salaryInput.value) || 0;
            const hourlyRate = salary / 173;
            
            // Update table cells
            document.querySelectorAll('.ot-cost-cell').forEach(cell => {
                const hours = parseFloat(cell.getAttribute('data-hours')) || 0;
                const cost = hours * hourlyRate;
                cell.innerHTML = '<strong>Rp ' + Math.round(cost).toLocaleString() + '</strong>';
            });

            // Update dashboard card estimations
            const totalHours = <?= (float)$total_conv_ot ?>;
            const approvedHours = <?= (float)$approved_conv_ot ?>;
            const pendingHours = <?= (float)$pending_conv_ot ?>;

            const totalEl = document.getElementById('total-ot-cost');
            const approvedEl = document.getElementById('approved-ot-cost');
            const pendingEl = document.getElementById('pending-ot-cost');

            if (totalEl) totalEl.innerText = 'Est: Rp ' + Math.round(totalHours * hourlyRate).toLocaleString();
            if (approvedEl) approvedEl.innerText = 'Est: Rp ' + Math.round(approvedHours * hourlyRate).toLocaleString();
            if (pendingEl) pendingEl.innerText = 'Est: Rp ' + Math.round(pendingHours * hourlyRate).toLocaleString();
        }

        // Run calculation on load
        window.addEventListener('DOMContentLoaded', recalculateOtCost);

        // Export Functions
        function exportOtToExcel() {
            const table = document.getElementById('otTable');
            if (!table) return;
            // Create a clone to remove the last action column from Excel export for a cleaner output
            const clonedTable = table.cloneNode(true);
            clonedTable.querySelectorAll('tr').forEach(tr => {
                const lastCell = tr.cells[tr.cells.length - 1];
                if (lastCell) tr.deleteCell(tr.cells.length - 1);
            });
            const wb = XLSX.utils.table_to_book(clonedTable, {sheet: "Overtime"});
            XLSX.writeFile(wb, "Driver_Overtime_Report_" + new Date().toISOString().slice(0,10) + ".xlsx");
        }

        function exportOtToPdf() {
            const element = document.getElementById('otTable');
            if (!element) return;
            // Clone table and remove action column
            const clonedTable = element.cloneNode(true);
            clonedTable.querySelectorAll('tr').forEach(tr => {
                const lastCell = tr.cells[tr.cells.length - 1];
                if (lastCell) tr.deleteCell(tr.cells.length - 1);
            });
            const opt = {
                margin:       10,
                filename:     "Driver_Overtime_Report_" + new Date().toISOString().slice(0,10) + ".pdf",
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2 },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'landscape' }
            };
            html2pdf().set(opt).from(clonedTable).save();
        }

        function exportExpensesToExcel() {
            const table = document.getElementById('expenseTable');
            if (!table) return;
            const wb = XLSX.utils.table_to_book(table, {sheet: "Expenses"});
            XLSX.writeFile(wb, "Driver_Expenses_Report_" + new Date().toISOString().slice(0,10) + ".xlsx");
        }

        function exportExpensesToPdf() {
            const element = document.getElementById('expenseTable');
            if (!element) return;
            const opt = {
                margin:       10,
                filename:     "Driver_Expenses_Report_" + new Date().toISOString().slice(0,10) + ".pdf",
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2 },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'landscape' }
            };
            html2pdf().set(opt).from(element).save();
        }

        // Detailed Trip view popup
        function showTripDetailById(event, id) {
            event.preventDefault();
            const t = supervisorExpensesData.find(item => item.id == id);
            if (!t) return;
            
            // Format Currency
            const fmt = (val) => val && parseFloat(val) > 0 ? 'Rp ' + Math.round(parseFloat(val)).toLocaleString() : '-';
            
            // Calculate total odometer distance
            const dist = t.km_end && t.km_start ? (parseFloat(t.km_end) - parseFloat(t.km_start)) + ' KM' : '-';
            
            // Determine Google Maps Direction Link
            let gmaps_link = '';
            if (t.start_lat && t.start_lng && t.end_lat && t.end_lng) {
                gmaps_link = `<a href="https://www.google.com/maps/dir/?api=1&origin=${t.start_lat},${t.start_lng}&destination=${t.end_lat},${t.end_lng}" target="_blank" style="color: #2563eb; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">🗺️ View Google Maps Route</a>`;
            } else {
                gmaps_link = `<a href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(t.dest_name)}" target="_blank" style="color: #2563eb; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">🗺️ Search Destination</a>`;
            }

            // Build expense breakdown details
            let expenseRows = '';
            if (t.expense_details && t.expense_details.length > 0) {
                t.expense_details.forEach(exp => {
                    const statusBadge = exp.approval_status === 'approved' 
                        ? '<span style="color: #166534; font-weight: 700;">✔ Approved</span>' 
                        : '<span style="color: #b45309; font-weight: 700;">⏳ Pending</span>';
                    
                    expenseRows += `
                        <tr style="border-bottom: 1px solid #e2e8f0;">
                            <td style="padding: 10px; text-transform: capitalize;">${exp.expense_type === 'gasoline' ? 'Fuel (BBM)' : exp.expense_type}</td>
                            <td style="padding: 10px; font-weight: 700;">${fmt(exp.amount)}</td>
                            <td style="padding: 10px;">${statusBadge}</td>
                            <td style="padding: 10px; font-style: italic; font-size: 0.8rem; color: #64748b;">${exp.supervisor_note || '-'}</td>
                        </tr>
                    `;
                });
            } else {
                expenseRows = `<tr><td colspan="4" align="center" style="padding: 16px; color: #64748b; font-style: italic;">No expenses recorded for this trip.</td></tr>`;
            }

            // Construct modal HTML
            const modalHtml = `
                <div style="text-align: left; font-size: 0.85rem; font-family: 'Plus Jakarta Sans', sans-serif; color: #334155; line-height: 1.6;">
                    
                    <!-- Grid 1: Basic Info -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; background: #f8fafc; padding: 16px; border-radius: 12px; margin-bottom: 20px; border: 1px solid #e2e8f0;">
                        <div>
                            <div style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Driver</div>
                            <div style="font-weight: 700; font-size: 0.95rem; color: #0f172a; margin-bottom: 8px;">${t.driver_name}</div>
                            
                            <div style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Car Plate No.</div>
                            <div style="font-weight: 600; color: #334155; margin-bottom: 8px;">${t.car_no}</div>

                            <div style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Passenger</div>
                            <div style="font-weight: 600; color: #334155;">${t.pass_name}</div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Date</div>
                            <div style="font-weight: 700; font-size: 0.95rem; color: #0f172a; margin-bottom: 8px;">${t.shift_date}</div>

                            <div style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Odometer</div>
                            <div style="font-weight: 600; color: #334155; margin-bottom: 8px;">${t.km_start} &rarr; ${t.km_end || '-'} KM (${dist})</div>

                            <div style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">GPS Route</div>
                            <div>${gmaps_link}</div>
                        </div>
                    </div>

                    <!-- Destination Info -->
                    <div style="background: #eff6ff; border: 1px solid rgba(37,99,235,0.15); border-radius: 12px; padding: 12px 16px; margin-bottom: 20px;">
                        <div style="font-size: 0.75rem; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px;">Destination</div>
                        <div style="font-weight: 700; font-size: 1rem; color: #1e3b8b; margin-top: 2px;">📍 ${t.dest_name}</div>
                    </div>

                    <!-- Itemized Expenses Header -->
                    <div style="font-weight: 800; font-size: 0.85rem; color: #0f172a; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">Trip Expenses Breakdown</div>
                    <div style="overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px;">
                        <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.8rem;">
                            <thead>
                                <tr style="background: #f1f5f9; border-bottom: 1px solid #e2e8f0;">
                                    <th style="padding: 10px; font-weight: 700;">Type</th>
                                    <th style="padding: 10px; font-weight: 700;">Amount</th>
                                    <th style="padding: 10px; font-weight: 700;">Status</th>
                                    <th style="padding: 10px; font-weight: 700;">Supervisor Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${expenseRows}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;

            Swal.fire({
                title: 'Trip Details (TX-' + t.id + ')',
                html: modalHtml,
                width: '650px',
                confirmButtonColor: '#2563eb',
                confirmButtonText: 'Close',
                customClass: {
                    title: 'app-title'
                }
            });
        }

        // PWA Installation handling
        let deferredPrompt;
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            const banner = document.getElementById('pwa-install-banner');
            if (banner) {
                banner.style.display = 'flex';
            }
        });

        function triggerPWAInstall() {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then((choiceResult) => {
                    if (choiceResult.outcome === 'accepted') {
                        console.log('User accepted the PWA install prompt');
                    } else {
                        console.log('User dismissed the PWA install prompt');
                    }
                    deferredPrompt = null;
                    const banner = document.getElementById('pwa-install-banner');
                    if (banner) banner.style.display = 'none';
                });
            }
        }

        // Handle URL Tab Activation & Alert Alerts
        window.addEventListener('DOMContentLoaded', () => {
            const params = new URLSearchParams(window.location.search);
            const activeTab = params.get('tab');
            if (activeTab) {
                switchTab(activeTab);
            }

            const msg = params.get('msg');
            const err = params.get('err');
            if (msg) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success',
                    text: msg,
                    timer: 3000,
                    showConfirmButton: false
                });
            }
            if (err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: err
                });
            }
        });
    </script>
</body>
</html>
