<?php
require_once 'config.php';

function formatDecimalHoursPHP($hoursDecimal) {
    $totalMinutes = (int)round(floatval($hoursDecimal ?? 0) * 60);
    $hrs = str_pad(floor($totalMinutes / 60), 2, '0', STR_PAD_LEFT);
    $mins = str_pad($totalMinutes % 60, 2, '0', STR_PAD_LEFT);
    return "$hrs:$mins";
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'driver') {
    header('Location: login.php');
    exit;
}

// Get Mandatory Photo setting
$mandatory_photo = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'mandatory_photo'")->fetchColumn();
if ($mandatory_photo === false) {
    $mandatory_photo = '1';
}

if (isset($_GET['action']) && $_GET['action'] === 'fetch_hris_proxy') {
    header('Content-Type: application/json');
    $nik = $_GET['emp_cd'] ?? '';
    $start = $_GET['start_date'] ?? '';
    $end = $_GET['end_date'] ?? '';

    $target_url = "https://api.framas.web.id/ops-ot/api.php?emp_cd=" . urlencode($nik) . "&start_date=" . urlencode($start) . "&end_date=" . urlencode($end);

    $ch = curl_init($target_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && !empty($response)) {
        $json = json_decode($response, true);
        if ($json) {
            echo json_encode($json);
            exit;
        }
    }

    echo json_encode([
        'status' => 'error',
        'message' => 'Server API HRIS (api.framas.web.id) sedang tidak dapat dijangkau (HTTP ' . $httpCode . ' / Offline).'
    ]);
    exit;
}

$driver_id = $_SESSION['user_id'];
$today = date('Y-m-d');

// 1. Get User Data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$driver_id]);
$driver_data = $stmt->fetch();

// 2. Get Active Shift
$stmt = $pdo->prepare("SELECT * FROM shifts WHERE driver_id = ? AND status = 'active' LIMIT 1");
$stmt->execute([$driver_id]);
$active_shift = $stmt->fetch();

// 3. Get Active Trip
$active_trip = null;
if ($active_shift) {
    $stmt = $pdo->prepare("SELECT t.*, d.name as dest_name, p.name as pass_name, c.car_no 
                           FROM trips t 
                           JOIN master_destinations d ON t.destination_id = d.id 
                           JOIN master_passengers p ON t.passenger_id = p.id 
                           JOIN master_cars c ON t.car_id = c.id
                           WHERE t.shift_id = ? AND t.status = 'ongoing' LIMIT 1");
    $stmt->execute([$active_shift['id']]);
    $active_trip = $stmt->fetch();
    
    $has_expenses = false;
    $active_trip_expenses = [];
    if ($active_trip) {
        $stmt_check_exp = $pdo->prepare("SELECT COUNT(*) FROM trip_expenses WHERE trip_id = ?");
        $stmt_check_exp->execute([$active_trip['id']]);
        $has_expenses = $stmt_check_exp->fetchColumn() > 0;

        $st_exp = $pdo->prepare("SELECT * FROM trip_expenses WHERE trip_id = ?");
        $st_exp->execute([$active_trip['id']]);
        $active_trip_expenses = $st_exp->fetchAll();
    }
}

$incomplete_trips_count = 0;
if ($active_shift) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM trips t 
                           LEFT JOIN master_destinations d ON t.destination_id = d.id 
                           LEFT JOIN master_passengers p ON t.passenger_id = p.id 
                           WHERE t.shift_id = ? AND (d.name = '?' OR p.name = '?' OR d.name IS NULL OR p.name IS NULL)");
    $stmt->execute([$active_shift['id']]);
    $incomplete_trips_count = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM trips WHERE shift_id = ? AND status = 'ongoing'");
    $stmt->execute([$active_shift['id']]);
    $ongoing_trips_count = (int)$stmt->fetchColumn();
}

// 4. Get Master Data
$destinations = $pdo->query("SELECT id, name FROM master_destinations ORDER BY name ASC")->fetchAll();
$passengers = $pdo->query("SELECT id, name FROM master_passengers ORDER BY name ASC")->fetchAll();
$cars = $pdo->query("SELECT * FROM master_cars ORDER BY car_no ASC")->fetchAll();

$car_last_km = [];
foreach ($cars as $c) {
    $stmt = $pdo->prepare("SELECT km_end FROM trips WHERE car_id = ? AND km_end IS NOT NULL AND status = 'completed' ORDER BY end_time DESC LIMIT 1");
    $stmt->execute([$c['id']]);
    $last_km = $stmt->fetchColumn();
    $car_last_km[$c['id']] = $last_km ? (int)$last_km : '';
}

$pref_car_no = '';
$pref_car_km = '';
if (!empty($driver_data['preferred_car_id'])) {
    foreach ($cars as $c) {
        if ($c['id'] == $driver_data['preferred_car_id']) {
            $pref_car_no = $c['car_no'];
            $pref_car_km = $car_last_km[$c['id']] ?? '';
            break;
        }
    }
}
if (empty($pref_car_no) && count($cars) > 0) {
    $pref_car_no = $cars[0]['car_no'];
    $pref_car_km = $car_last_km[$cars[0]['id']] ?? '';
}

// 5. Get History with Date Range (1 week back up to TODAY)
$default_hist_start = date('Y-m-d', strtotime('-6 days'));
$default_hist_end = date('Y-m-d');

$hist_start = $_GET['hist_start'] ?? $default_hist_start;
$hist_end = $_GET['hist_end'] ?? $default_hist_end;

$stmt = $pdo->prepare("SELECT t.*, d.name as dest_name, p.name as pass_name, c.car_no, s.approval_status 
                       FROM trips t 
                       LEFT JOIN master_destinations d ON t.destination_id = d.id 
                       LEFT JOIN master_passengers p ON t.passenger_id = p.id 
                       JOIN master_cars c ON t.car_id = c.id
                       JOIN shifts s ON t.shift_id = s.id
                       WHERE s.driver_id = ? AND DATE(t.start_time) BETWEEN ? AND ? 
                       ORDER BY t.start_time DESC");
$stmt->execute([$driver_id, $hist_start, $hist_end]);
$history_trips = $stmt->fetchAll();

foreach ($history_trips as &$ht) {
    $st_exp = $pdo->prepare("SELECT * FROM trip_expenses WHERE trip_id = ?");
    $st_exp->execute([$ht['id']]);
    $ht['expenses'] = $st_exp->fetchAll();
}
unset($ht);

$today_day = intval(date('j'));
if ($today_day > 20) {
    $default_att_start = date('Y-m-21');
    $default_att_end = date('Y-m-20', strtotime('+1 month'));
} else {
    $default_att_start = date('Y-m-21', strtotime('-1 month'));
    $default_att_end = date('Y-m-20');
}

$att_start = $_GET['att_start'] ?? $default_att_start;
$att_end = $_GET['att_end'] ?? $default_att_end;

// Payroll Periods Generator (Framas Cut-off: 21st of previous month to 20th of current month)
$months_lang = [
    'id' => [
        'full' => [1=>'Januari', 2=>'Februari', 3=>'Maret', 4=>'April', 5=>'Mei', 6=>'Juni', 7=>'Juli', 8=>'Agustus', 9=>'September', 10=>'Oktober', 11=>'November', 12=>'Desember'],
        'short' => [1=>'Jan', 2=>'Feb', 3=>'Mar', 4=>'Apr', 5=>'Mei', 6=>'Jun', 7=>'Jul', 8=>'Agu', 9=>'Sep', 10=>'Okt', 11=>'Nov', 12=>'Des']
    ],
    'en' => [
        'full' => [1=>'January', 2=>'February', 3=>'March', 4=>'April', 5=>'May', 6=>'June', 7=>'July', 8=>'August', 9=>'September', 10=>'October', 11=>'November', 12=>'December'],
        'short' => [1=>'Jan', 2=>'Feb', 3=>'Mar', 4=>'Apr', 5=>'May', 6=>'Jun', 7=>'Jul', 8=>'Aug', 9=>'Sep', 10=>'Oct', 11=>'Nov', 12=>'Dec']
    ]
];
$active_lang = $_SESSION['lang'] ?? 'id';
$months_full = $months_lang[$active_lang]['full'] ?? $months_lang['id']['full'];
$months_short = $months_lang[$active_lang]['short'] ?? $months_lang['id']['short'];

$max_period_offset = ($today_day > 20) ? 1 : 0;
$payroll_periods = [];
$current_month_time = strtotime(date('Y-m-01'));
for ($i = $max_period_offset; $i >= -12; $i--) {
    $target_time = strtotime("$i month", $current_month_time);
    $y = intval(date('Y', $target_time));
    $m = intval(date('n', $target_time));

    $prev_time = strtotime("-1 month", strtotime(sprintf('%04d-%02d-01', $y, $m)));
    $prev_y = intval(date('Y', $prev_time));
    $prev_m = intval(date('n', $prev_time));

    $p_start = sprintf('%04d-%02d-21', $prev_y, $prev_m);
    $p_end = sprintf('%04d-%02d-20', $y, $m);

    $label = $months_full[$m] . ' ' . $y . ' (21 ' . $months_short[$prev_m] . ' - 20 ' . $months_short[$m] . ')';
    $is_selected = ($p_start === $att_start && $p_end === $att_end);

    $payroll_periods[] = [
        'label' => $label,
        'start_date' => $p_start,
        'end_date' => $p_end,
        'val' => $p_start . '|' . $p_end,
        'selected' => $is_selected
    ];
}

$stmt_ot = $pdo->prepare("SELECT 
                            shift_date, 
                            MIN(start_time) as start_time, 
                            MAX(end_time) as end_time, 
                            SUM(overtime_early) as overtime_early, 
                            SUM(overtime_late) as overtime_late, 
                            MAX(ot_type) as ot_type,
                            SUM(real_ot) as real_ot,
                            SUM(conv_ot) as conv_ot,
                            IF(SUM(approval_status = 'pending') > 0, 'pending', 'approved') as approval_status
                          FROM shifts 
                          WHERE driver_id = ? AND shift_date BETWEEN ? AND ? 
                          GROUP BY shift_date 
                          ORDER BY shift_date ASC");
$stmt_ot->execute([$driver_id, $att_start, $att_end]);
$attendance_records = $stmt_ot->fetchAll();

// 7. Check if there are any completed trips still pending passenger approval
$stmt_pending_trips = $pdo->prepare("SELECT COUNT(*) 
                                     FROM trips t 
                                     JOIN shifts s ON t.shift_id = s.id 
                                     WHERE s.driver_id = ? AND t.status = 'completed' AND t.passenger_approval = 'pending'");
$stmt_pending_trips->execute([$driver_id]);
$pending_passenger_trips_count = (int)$stmt_pending_trips->fetchColumn();
?>
<!DOCTYPE html>
<html lang="<?= $_SESSION['lang'] ?>" class="notranslate">
<head>
    <meta charset="UTF-8">
    <meta name="google" content="notranslate">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('app_name') ?></title>
    
    <!-- PWA Setup -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#118DFF">
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

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?= time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <style>
        .searchable-select { position: relative; width: 100%; }
        .search-results {
            position: absolute; top: 100%; left: 0; right: 0;
            background: var(--glass-bg); border: 1px solid var(--glass-border);
            border-radius: 8px; max-height: 200px; overflow-y: auto;
            z-index: 100; display: none; box-shadow: var(--glass-shadow);
        }
        .search-option { padding: 12px; cursor: pointer; border-bottom: 1px solid var(--glass-border); font-size: 0.9rem; }
        .search-option:hover, .search-option.highlighted { background: rgba(37, 99, 235, 0.1); color: var(--accent-color); }

        /* Smooth collapsible forms */
        .collapsible-form {
            max-height: 0;
            overflow: hidden;
            opacity: 0;
            transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease-out, padding 0.3s ease-out, margin 0.3s ease-out;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
            margin-top: 0 !important;
            margin-bottom: 0 !important;
            border-top-width: 0 !important;
            border-bottom-width: 0 !important;
        }
        .collapsible-form.show {
            max-height: 800px;
            opacity: 1;
            padding-top: 16px !important;
            padding-bottom: 16px !important;
            margin-top: 12px !important;
            margin-bottom: 12px !important;
            border-top-width: 1px !important;
            border-bottom-width: 1px !important;
        }
        #expense_form.show {
            margin-bottom: 20px !important;
            margin-top: 12px !important;
        }
        #end_trip_form.show {
            margin-top: 20px !important;
            margin-bottom: 12px !important;
        }
        #end_trip_toggle_btn:hover {
            background: #ff2626 !important;
            box-shadow: 0 4px 12px rgba(255, 38, 38, 0.3) !important;
        }
        .arrow-indicator {
            display: inline-block;
            font-size: 0.75rem;
            margin-left: 6px;
            transition: transform 0.2s ease;
        }
    </style>
</head>
<body class="<?= $_SESSION['theme'] === 'dark' ? 'dark-mode' : '' ?>">
    <?php if (password_verify('123', $driver_data['password'])): ?>
    <div id="security-alert-banner" style="background: #fee2e2; color: #991b1b; padding: 12px 32px 12px 12px; text-align: center; font-size: 0.85rem; border-bottom: 2px solid #ef4444; position: fixed; top: 0; left:0; right:0; z-index: 9999; font-weight: 700; box-shadow: 0 4px 6px rgba(0,0,0,0.1); display: flex; align-items: center; justify-content: center;">
        <span><?= htmlspecialchars(__('security_alert_123')) ?></span>
        <button onclick="document.getElementById('security-alert-banner').style.display='none'; document.getElementById('security-alert-spacer').style.display='none';" style="position: absolute; right: 15px; background: none; border: none; color: #991b1b; font-size: 1.4rem; cursor: pointer; font-weight: bold; line-height: 1; padding: 0;">&times;</button>
    </div>
    <div id="security-alert-spacer" style="height: 45px;"></div>
    <?php endif; ?>

    <div class="glass-container" style="max-width: 500px; padding: 6px 12px 12px 12px; min-height: 80vh;">
        
        <div class="header" style="margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; gap: 8px;">
            <h2 style="margin: 0; font-size: 1.15rem; color: #1e293b; font-weight: 800; line-height: 1.2; flex: 1; min-width: 0; word-break: break-word;"><?= htmlspecialchars($driver_data['full_name']) ?></h2>
            <span id="server-clock" style="font-size: 0.7rem; color: var(--text-primary); font-weight: 700; background: var(--card-bg); padding: 4px 8px; border-radius: 8px; font-family: monospace; letter-spacing: 0.02em; border: 1px solid var(--glass-border); box-shadow: 0 2px 4px rgba(0,0,0,0.02); display: inline-flex; flex-direction: column; align-items: center; gap: 1px; white-space: nowrap; flex-shrink: 0;" data-timestamp="<?= time() ?>"><div><?= date('d M Y') ?></div><div style="font-size: 0.8rem; color: var(--accent-color);"><?= date('H:i:s') ?></div></span>
        </div>



        <?php if (isset($_SESSION['flash_success'])): ?>
            <?php if (in_array($_SESSION['flash_success'], ["Perjalanan berhasil dimulai.", "Trip started successfully."])): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', () => {
                        const Toast = Swal.mixin({
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 3000,
                            timerProgressBar: true
                        });
                        Toast.fire({
                            icon: 'success',
                            title: <?= json_encode($_SESSION['flash_success']) ?>
                        });
                    });
                </script>
            <?php else: ?>
                <div class="alert alert-success" style="background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; padding: 8px 10px; border-radius: 12px; margin-bottom: 12px; font-size: 0.85rem; display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                    <span style="flex: 1;"><?= htmlspecialchars($_SESSION['flash_success']) ?></span>
                    <button onclick="this.closest('.alert').style.display='none'" style="background:none; border:none; color:#15803d; font-size:1.3rem; cursor:pointer; font-weight:bold; padding:0; line-height:1; margin-left:4px;">&times;</button>
                </div>
            <?php endif; ?>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="alert alert-danger" style="background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; padding: 8px 10px; border-radius: 12px; margin-bottom: 12px; font-size: 0.85rem; display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                <span style="flex: 1;"><?= htmlspecialchars($_SESSION['flash_error']) ?></span>
                <button onclick="this.closest('.alert').style.display='none'" style="background:none; border:none; color:#b91c1c; font-size:1.3rem; cursor:pointer; font-weight:bold; padding:0; line-height:1; margin-left:4px;">&times;</button>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <style>
            .search-results {
                background: var(--card-bg) !important;
                border: 1px solid var(--glass-border);
                border-radius: 12px;
                box-shadow: 0 10px 25px rgba(0,0,0,0.1);
                margin-top: 5px;
                max-height: 200px;
                overflow-y: auto;
                position: absolute;
                width: 100%;
                z-index: 100;
                display: none;
            }
            .search-option {
                padding: 12px 16px;
                cursor: pointer;
                border-bottom: 1px solid var(--glass-border);
                font-size: 0.9rem;
                color: var(--text-primary);
                background: var(--card-bg);
            }
            .search-option:last-child { border-bottom: none; }
            .search-option:hover { background: rgba(0,0,0,0.02); color: var(--accent-color); }
        </style>

        <!-- TAB 1: HOME -->
        <div id="shift" class="tab-content active">
            <?php if (!$active_shift): ?>
                <div style="text-align: center; padding: 60px 20px;">
                    <div style="font-size: 4rem; margin-bottom: 20px;">🏠</div>
                    <p style="color: var(--text-secondary); margin-bottom: 24px;">Anda sedang tidak bertugas.</p>
                    <form action="manage_shift.php" method="POST">
                        <input type="hidden" name="action" value="clock_in">
                        <button type="submit" class="btn btn-success" style="padding: 16px;"><?= __('clock_in') ?></button>
                    </form>
                </div>
            <?php else: ?>
                <div style="background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%); color: white; padding: 10px 14px; border-radius: 12px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);">
                    <div>
                        <span style="font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.9;"><?= htmlspecialchars(strtoupper(__('clock_in_time'))) ?></span>
                        <h3 style="margin: 2px 0 0 0; font-size: 1.4rem;"><?= substr($active_shift['start_time'], 0, 5) ?></h3>
                    </div>
                    <form action="manage_shift.php" id="end_shift_form" method="POST">
                        <input type="hidden" name="action" value="clock_out">
                        <button type="button" onclick="confirmEndShift()" style="background: rgba(255,255,255,0.2); border: none; color: white; padding: 8px 16px; border-radius: 10px; font-weight: 700; font-size: 0.8rem; cursor: pointer; backdrop-filter: blur(10px);"><?= __('end_shift') ?></button>
                    </form>
                </div>

                <?php if (!$active_trip): ?>
                    <div class="report-card" style="padding: 16px;">
                        <form action="manage_trip.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="start_trip">
                            <h4 style="margin-bottom: 20px; font-size: 1.1rem; display: flex; align-items: center; gap: 8px;"><span>👤</span> <?= __('start_trip') ?></h4>
                            
                            <!-- 1. Nomor Mobil & Odometer Awal -->
                            <div class="form-grid-2">
                                <div class="form-group searchable-select">
                                    <label><?= __('car_no') ?></label>
                                    <input type="text" name="car_no" id="car_search" placeholder="Ketik atau pilih Nomor Mobil..." autocomplete="off" required value="<?= htmlspecialchars($pref_car_no) ?>" data-last-km="<?= $pref_car_km ?>">
                                    <input type="hidden" name="car_id" id="car_id_hidden" value="<?= htmlspecialchars($driver_data['preferred_car_id'] ?? '') ?>">
                                    <div id="car_results" class="search-results"></div>
                                </div>
                                <div class="form-group">
                                    <label><?= __('km_start') ?></label>
                                    <input type="number" name="km_start" id="km_start_input" placeholder="0" required>
                                </div>
                            </div>

                            <!-- 2. Penumpang -->
                            <div class="form-group searchable-select">
                                <label><?= __('passenger') ?></label>
                                <input type="text" name="passenger_name" id="pass_search" placeholder="Cari User..." autocomplete="off">
                                <input type="hidden" name="passenger_id" id="pass_id_hidden">
                                <div id="pass_results" class="search-results"></div>
                            </div>

                            <!-- 3. Tujuan -->
                            <div class="form-group searchable-select">
                                <label><?= __('destination') ?></label>
                                <input type="text" name="destination_name" id="dest_search" placeholder="Ketik atau Pilih Tujuan..." autocomplete="off">
                                <input type="hidden" name="destination_id" id="dest_id_hidden">
                                <div id="dest_results" class="search-results"></div>
                            </div>
                            <?php if ($mandatory_photo === '1'): ?>
                            <div class="form-group">
                                <label><?= __('photo_proof') ?> (KM Start)</label>
                                <input type="file" name="km_start_photo" accept="image/*" style="font-size: 0.8rem;">
                            </div>
                            <?php endif; ?>
                            <input type="hidden" name="start_lat" id="start_lat">
                            <input type="hidden" name="start_lng" id="start_lng">
                            
                            <button type="submit" class="btn" onclick="captureGPS(event)"><?= __('start_trip') ?></button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="trip-card" style="padding: 16px;">
                        <span style="color: var(--accent-color); font-weight: 600; font-size: 0.8rem; text-transform: uppercase;"><?= __('ongoing_trip') ?></span>
                        <h2 style="margin: 8px 0; text-align: left;"><?= htmlspecialchars($active_trip['dest_name']) ?></h2>
                        <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 4px;">👤 <strong><?= htmlspecialchars($active_trip['pass_name']) ?></strong></p>
                        <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 4px;">🚗 <strong><?= htmlspecialchars($active_trip['car_no']) ?></strong></p>
                        <p style="color: var(--text-secondary); font-size: 0.85rem; font-weight: 500; display: flex; align-items: center; gap: 4px;">⏱️ <?= $_SESSION['lang'] === 'id' ? 'Mulai' : 'Started' ?>: <strong><?= date('d M Y H:i', strtotime($active_trip['start_time'])) ?></strong></p>

                        <?php if (count($active_trip_expenses) > 0): ?>
                            <div style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25); padding: 10px 12px; border-radius: 12px; margin-top: 12px; box-shadow: inset 0 0 8px rgba(245, 158, 11, 0.03);">
                                <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #d97706; display: block; margin-bottom: 8px; letter-spacing: 0.02em;"><?= $_SESSION['lang'] === 'id' ? 'Biaya Tercatat' : 'Cost Breakdown' ?>:</span>
                                <?php 
                                $total_active_cost = 0;
                                foreach ($active_trip_expenses as $exp): 
                                    $total_active_cost += $exp['amount'];
                                    $expThumb = $exp['photo'] ? "uploads/" . $exp['photo'] : '';
                                ?>
                                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; margin-bottom: 8px;">
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <?php if ($expThumb): ?>
                                                <img src="<?= $expThumb ?>" style="width: 32px; height: 32px; object-fit: cover; border-radius: 6px; cursor: pointer;" onclick="openImageViewer('<?= $expThumb ?>')">
                                            <?php endif; ?>
                                            <span style="color: var(--text-primary); font-weight: 500;"><?= htmlspecialchars(__($exp['expense_type'])) ?></span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span style="font-weight: 700; color: var(--text-primary);">Rp <?= number_format($exp['amount'], 0, ',', '.') ?></span>
                                            <form action="manage_trip.php" id="delete_expense_form_<?= $exp['id'] ?>" method="POST" style="display: inline-flex;">
                                                <input type="hidden" name="action" value="delete_expense">
                                                <input type="hidden" name="expense_id" value="<?= $exp['id'] ?>">
                                                <input type="hidden" name="trip_id" value="<?= $active_trip['id'] ?>">
                                                <button type="button" onclick="confirmDeleteExpense(<?= $exp['id'] ?>)" style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 0.85rem; padding: 0;" title="Hapus Biaya">🗑</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <div style="display: flex; justify-content: space-between; font-size: 0.95rem; margin-top: 12px; border-top: 1px dashed rgba(245, 158, 11, 0.35); padding-top: 12px; font-weight: 800; color: #d97706;">
                                    <span>TOTAL BIAYA</span>
                                    <span>Rp <?= number_format($total_active_cost, 0, ',', '.') ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top: 24px;">

                            <button onclick="toggleForm('expense_form', this)" class="btn btn-success" style="background: rgba(16, 185, 129, 0.1); color: var(--success-color); border: 1px solid var(--success-color); margin-bottom: 12px;">💵 <?= __('add_expense') ?> <span class="arrow-indicator">▼</span></button>
                            
                            <div id="expense_form" class="collapsible-form" style="background: rgba(16, 185, 129, 0.05); border-radius: 12px; border: 1px solid rgba(16, 185, 129, 0.1);">
                                <form action="manage_trip.php" method="POST" enctype="multipart/form-data" onsubmit="submitFormAjax(event)">
                                    <input type="hidden" name="action" value="add_expense">
                                    <input type="hidden" name="trip_id" value="<?= $active_trip['id'] ?>">
                                    <div class="form-group">
                                        <label><?= __('type') ?></label>
                                        <select name="expense_type" onchange="this.value=='gasoline'?document.getElementById('litre_div').style.display='block':document.getElementById('litre_div').style.display='none'">
                                            <option value="gasoline"><?= __('gasoline') ?></option>
                                            <option value="toll"><?= __('toll') ?></option>
                                            <option value="parking"><?= __('parking') ?></option>
                                            <option value="lunch"><?= __('lunch') ?></option>
                                            <option value="others"><?= __('others') ?></option>
                                        </select>
                                    </div>
                                    <div id="litre_div" class="form-group">
                                        <label>Litre</label>
                                        <input type="number" step="0.01" name="litre">
                                    </div>
                                    <div class="form-group">
                                        <label><?= __('amount') ?></label>
                                        <input type="text" name="amount" class="formatted-amount-input" oninput="formatAmountInput(this)" required style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary); font-size: 1rem;">
                                    </div>
                                    <?php if ($mandatory_photo === '1'): ?>
                                    <div class="form-group">
                                        <label><?= __('photo_proof') ?></label>
                                        <input type="file" name="photo" accept="image/*" required>
                                    </div>
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-success" style="width: 100%;"><?= __('save_expense') ?></button>
                                </form>
                            </div>
                            
                            <button id="end_trip_toggle_btn" onclick="toggleForm('end_trip_form', this)" class="btn btn-danger" style="background: #ff4d4d; color: white; border: 1px solid #ff3333; box-shadow: 0 4px 12px rgba(255, 77, 77, 0.2); font-weight: 700;">🏁 <?= __('end_trip') ?> <span class="arrow-indicator">▼</span></button>
                            <div id="end_trip_form" class="collapsible-form" style="background: rgba(239, 68, 68, 0.05); border-radius: 12px;">
                                <form action="manage_trip.php" method="POST" enctype="multipart/form-data" onsubmit="captureGPSEnd(event)">
                                    <input type="hidden" name="action" value="end_trip">
                                    <input type="hidden" name="trip_id" value="<?= $active_trip['id'] ?>">
                                    <input type="hidden" name="end_lat" id="end_lat">
                                    <input type="hidden" name="end_lng" id="end_lng">
                                    <div class="form-group">
                                        <label style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                                            <span><?= __('km_end') ?></span>
                                            <span style="font-size: 0.8rem; font-weight: normal; color: var(--text-secondary);">Odometer Awal: <strong><?= number_format($active_trip['km_start'], 0, ',', '.') ?></strong></span>
                                        </label>
                                        <input type="number" name="km_end" required>
                                    </div>
                                    <?php if ($mandatory_photo === '1'): ?>
                                    <div class="form-group"><label><?= __('photo_proof') ?> (KM End)</label><input type="file" name="km_end_photo" accept="image/*" required></div>
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-danger"><?= __('save_odometer_end') ?></button>
                                </form>
                            </div>

                            <?php if (!$has_expenses): ?>
                                <form action="manage_trip.php" id="cancel_trip_form" method="POST" style="margin-top: 12px;">
                                    <input type="hidden" name="action" value="cancel_trip">
                                    <input type="hidden" name="trip_id" value="<?= $active_trip['id'] ?>">
                                    <button type="button" onclick="confirmCancelTrip()" class="btn" style="background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid #ef4444; width: 100%; margin-bottom: 0;">❌ <?= $_SESSION['lang'] === 'id' ? 'Batalkan Perjalanan' : 'Cancel Trip' ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- TAB 2 & 3: HISTORY & SETTINGS (Keep same as before but wrapped in tab-content) -->
        <div id="history" class="tab-content"><?php include 'history_tab.php'; ?></div>
        <div id="attendance" class="tab-content">
            <div style="background: var(--card-bg); padding: 16px; border-radius: 12px; border: 1px solid var(--glass-border); margin-bottom: 24px;">
                <form action="index.php" method="GET" id="attendance_filter_form">
                    <div style="margin-bottom: 12px;">
                        <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                            📅 <span><?= __('period') ?? 'Periode' ?></span>
                        </label>
                        <select id="att_period_select" onchange="onPeriodChange(this, 'att_start_input', 'att_end_input', 'submit_att')" style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border-radius: 8px; background: var(--bg-color); color: var(--text-primary); border: 1px solid var(--glass-border); font-weight: 600; cursor: pointer; outline: none;">
                            <?php 
                            $has_matched_att = false;
                            foreach ($payroll_periods as $p): 
                                if ($p['selected']) $has_matched_att = true;
                            ?>
                                <option value="<?= $p['val'] ?>" <?= $p['selected'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['label']) ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="custom" <?= !$has_matched_att ? 'selected' : '' ?> disabled style="display: <?= !$has_matched_att ? 'block' : 'none' ?>;">-- <?= __('custom_period') ?? 'Kustom Tanggal' ?> --</option>
                        </select>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr auto auto; gap: 8px; align-items: flex-end;">
                        <div class="form-group" style="margin: 0;">
                            <label style="font-size: 0.7rem; color: var(--text-secondary);"><?= __('start_date') ?? 'Start' ?></label>
                            <input type="date" id="att_start_input" name="att_start" value="<?= $att_start ?>" onchange="checkCustomPeriod('att_period_select', this.value, document.getElementById('att_end_input').value)" style="padding: 8px; font-size: 0.85rem; border-radius: 8px; background: var(--bg-color); color: var(--text-primary); border: 1px solid var(--glass-border); width: 100%;">
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <label style="font-size: 0.7rem; color: var(--text-secondary);"><?= __('end_date') ?? 'End' ?></label>
                            <input type="date" id="att_end_input" name="att_end" value="<?= $att_end ?>" onchange="checkCustomPeriod('att_period_select', document.getElementById('att_start_input').value, this.value)" style="padding: 8px; font-size: 0.85rem; border-radius: 8px; background: var(--bg-color); color: var(--text-primary); border: 1px solid var(--glass-border); width: 100%;">
                        </div>
                        <button type="submit" class="btn" title="Cari / Filter" style="padding: 10px 12px; border-radius: 8px; margin-bottom: 0;">🔍</button>
                        <a href="index.php" class="btn" title="Reset" style="padding: 10px 12px; border-radius: 8px; margin-bottom: 0; background: rgba(239,68,68,0.1); color: #ef4444; border: 1px solid #ef4444; display: flex; align-items: center; justify-content: center; text-decoration: none;">❌</a>
                    </div>
                </form>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                <h3 style="margin: 0; font-size: 1.1rem;"><?= __('attendance_report') ?></h3>
                <div style="display: flex; gap: 6px; align-items: center;">
                    <button type="button" id="compareToggleBtn" onclick="toggleHrisCompare()" style="background: rgba(17, 141, 255, 0.1); color: var(--pbi-blue); border: 1px solid var(--pbi-blue); border-radius: 8px; padding: 5px 10px; font-size: 0.75rem; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 4px; transition: all 0.2s;">
                        🔍 Compare HRIS
                    </button>
                    <button type="button" onclick="recalculateDriverOt()" style="background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid #10b981; border-radius: 8px; padding: 5px 10px; font-size: 0.75rem; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 4px; transition: all 0.2s;">
                        🔄 <?= $_SESSION['lang'] === 'id' ? 'Hitung Ulang' : 'Recalculate' ?>
                    </button>
                    <button type="button" onclick="exportDriverOtPdf()" style="background: #e11d48; color: #ffffff; border: none; border-radius: 8px; padding: 5px 10px; font-size: 0.75rem; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 4px; box-shadow: 0 2px 6px rgba(225,29,72,0.3);">
                        📄 Export PDF
                    </button>
                </div>
            </div>

            <?php if (count($attendance_records) === 0): ?>
                <div style="text-align: center; color: var(--text-secondary); padding: 40px; background: var(--card-bg); border-radius: 12px; border: 1px dashed var(--glass-border);">
                    <p>No overtime records found for this date range.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="pbi-table" style="width: 100%; border-collapse: collapse; font-size: 0.7rem; background: var(--card-bg); border: 1px solid var(--glass-border); border-radius: 8px; white-space: nowrap;">
                        <thead>
                            <tr style="background: rgba(0,0,0,0.02);">
                                <th style="padding: 6px 4px; border-bottom: 2px solid var(--glass-border); text-align: left; color: var(--text-secondary);">Tgl</th>
                                <th style="padding: 6px 4px; border-bottom: 2px solid var(--glass-border); text-align: center; color: var(--text-secondary);">Jam</th>
                                <th style="padding: 6px 4px; border-bottom: 2px solid var(--glass-border); text-align: center; color: var(--text-secondary);">Awal</th>
                                <th style="padding: 6px 4px; border-bottom: 2px solid var(--glass-border); text-align: center; color: var(--text-secondary);">Akhir</th>
                                <th style="padding: 6px 4px; border-bottom: 2px solid var(--glass-border); text-align: center; color: var(--text-secondary);">Tipe</th>
                                <th style="padding: 6px 4px; border-bottom: 2px solid var(--glass-border); text-align: center; color: var(--text-secondary);">OT</th>
                                <th class="col-conv-ot" style="padding: 6px 4px; border-bottom: 2px solid var(--glass-border); text-align: center; color: var(--text-secondary);">Conv</th>
                                <th class="col-hris-compare" style="padding: 6px 4px; border-bottom: 2px solid var(--glass-border); text-align: center; color: #107c10; display: none;">HRIS</th>
                                <th class="col-hris-compare" style="padding: 6px 4px; border-bottom: 2px solid var(--glass-border); text-align: center; color: #ea580c; display: none;">Break</th>
                                <th class="col-status-ot" style="padding: 6px 4px; border-bottom: 2px solid var(--glass-border); text-align: center; color: var(--text-secondary);">Sts</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_real_ot = 0;
                            $total_conv_ot = 0;
                            $total_ot_days = 0;
                            foreach ($attendance_records as $ar): 
                                $real_val = (float)($ar['real_ot'] ?? 0);
                                $conv_val = (float)($ar['conv_ot'] ?? 0);
                                $total_real_ot += $real_val;
                                $total_conv_ot += $conv_val;
                                if ($real_val > 0 || (float)($ar['overtime_early'] ?? 0) > 0 || (float)($ar['overtime_late'] ?? 0) > 0) {
                                    $total_ot_days++;
                                }
                                $duration = '-';
                                if ($ar['end_time']) {
                                    if ($ar['end_time'] === '00:00:00') {
                                        $duration = 'Timeout';
                                    } else {
                                        $start = new DateTime($ar['shift_date'] . ' ' . $ar['start_time']);
                                        $end = new DateTime($ar['shift_date'] . ' ' . $ar['end_time']);
                                        $diff = $start->diff($end);
                                        $duration = $diff->format('%hh %im');
                                    }
                                }
                                $is_holiday = (($ar['ot_type'] ?? 'R') === 'H');
                                $has_ot = $real_val > 0;
                                
                                $row_style = '';
                                if ($is_holiday) {
                                    $row_style = 'background-color: rgba(220, 38, 38, 0.1);';
                                } elseif (!$has_ot) {
                                    $row_style = 'background-color: rgba(0, 0, 0, 0.04); opacity: 0.6; filter: grayscale(100%);';
                                }
                            ?>
                                <tr style="border-bottom: 1px solid var(--glass-border); <?= $row_style ?>">
                                    <td style="padding: 6px 4px;">
                                        <?php 
                                            $m_id = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agt', 'Sep', 'Okt', 'Nov', 'Des'];
                                            $m_idx = (int)date('n', strtotime($ar['shift_date'])) - 1;
                                            $m_str = $_SESSION['lang'] === 'id' ? $m_id[$m_idx] : date('M', strtotime($ar['shift_date']));
                                            
                                            $d_id = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
                                            $d_idx = (int)date('w', strtotime($ar['shift_date']));
                                            $d_str = $_SESSION['lang'] === 'id' ? $d_id[$d_idx] : date('D', strtotime($ar['shift_date']));
                                        ?>
                                        <strong><?= date('d', strtotime($ar['shift_date'])) . ' ' . $m_str ?></strong>
                                        <div style="font-size: 0.65rem; color: var(--text-secondary); margin-top: 1px;"><?= $d_str ?></div>
                                    </td>
                                    <td style="padding: 6px 4px; text-align: center;">
                                        <span style="font-size: 0.65rem;"><?= substr($ar['start_time'], 0, 5) ?></span><br>
                                        <strong><?= $ar['end_time'] ? ($ar['end_time'] === '00:00:00' ? '00:00' : substr($ar['end_time'], 0, 5)) : '-' ?></strong>
                                    </td>
                                    <td style="padding: 6px 4px; text-align: center; color: <?= $ar['overtime_early'] > 0 ? 'var(--pbi-blue)' : 'var(--text-secondary)' ?>;">
                                        <?= (float)($ar['overtime_early'] ?? 0) > 0 ? formatDecimalHoursPHP($ar['overtime_early']) : '-' ?>
                                    </td>
                                    <td style="padding: 6px 4px; text-align: center; color: <?= $ar['overtime_late'] > 0 ? 'var(--pbi-blue)' : 'var(--text-secondary)' ?>;">
                                        <?= (float)($ar['overtime_late'] ?? 0) > 0 ? formatDecimalHoursPHP($ar['overtime_late']) : '-' ?>
                                    </td>
                                    <td style="padding: 6px 4px; text-align: center; font-weight: bold; color: <?= ($ar['ot_type'] ?? 'R') === 'H' ? '#dc2626' : '#475569' ?>;">
                                        <?= $ar['ot_type'] ?? '-' ?>
                                    </td>
                                    <td style="padding: 6px 4px; text-align: center; font-weight: <?= ($ar['real_ot'] ?? 0) > 0 ? 'bold' : 'normal' ?>; color: <?= ($ar['real_ot'] ?? 0) > 0 ? 'var(--pbi-blue)' : 'var(--text-secondary)' ?>;">
                                        <?= ($ar['real_ot'] ?? 0) > 0 ? (float)$ar['real_ot'] : '-' ?>
                                    </td>
                                    <td class="col-conv-ot" style="padding: 6px 4px; text-align: center; font-weight: <?= ($ar['conv_ot'] ?? 0) > 0 ? 'bold' : 'normal' ?>; color: <?= ($ar['conv_ot'] ?? 0) > 0 ? '#107c10' : 'var(--text-secondary)' ?>;">
                                        <?= ($ar['conv_ot'] ?? 0) > 0 ? (float)$ar['conv_ot'] : '-' ?>
                                    </td>
                                    <td class="col-hris-compare hris-compare-cell" data-date="<?= $ar['shift_date'] ?>" data-web-ot="<?= (float)($ar['real_ot'] ?? 0) ?>" style="padding: 6px 4px; text-align: center; font-size: 0.72rem; font-weight: bold; color: var(--text-secondary); display: none;">-</td>
                                    <td class="col-hris-compare hris-break-cell" data-date="<?= $ar['shift_date'] ?>" style="padding: 6px 4px; text-align: center; font-size: 0.72rem; font-weight: bold; color: #ea580c; display: none;">-</td>
                                    <td class="col-status-ot" style="padding: 6px 4px; text-align: center; font-size: 0.65rem;">
                                        <?php if ($ar['approval_status'] == 'approved'): ?>
                                            <span style="color: #166534; font-weight: 600;">✔</span>
                                        <?php else: ?>
                                            <span style="color: #b91c1c; font-weight: 600;">⏳</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot style="border-top: 2px solid var(--glass-border); background: rgba(0,0,0,0.02); font-weight: bold;">
                            <tr style="border-bottom: 1px dashed var(--glass-border);">
                                <td colspan="5" style="padding: 6px 4px; text-align: right; color: var(--text-secondary); font-size: 0.7rem;">HARI LEMBUR:</td>
                                <td style="padding: 6px 4px; text-align: center; color: #107c10; font-weight: bold;"><?= $total_ot_days ?> Hari</td>
                                <td class="col-conv-ot" style="padding: 6px 4px;"></td>
                                <td class="col-hris-compare" id="hrisTotalDaysCell" style="padding: 6px 4px; text-align: center; color: #107c10; font-weight: bold; display: none;">-</td>
                                <td class="col-hris-compare" style="padding: 6px 4px; display: none;"></td>
                                <td class="col-status-ot" style="padding: 6px 4px;"></td>
                            </tr>
                            <tr>
                                <td colspan="5" style="padding: 8px 4px; text-align: right; color: var(--text-primary);">TOTAL JAM:</td>
                                <td style="padding: 8px 4px; text-align: center; color: var(--pbi-blue); font-weight: bold;"><?= $total_real_ot > 0 ? $total_real_ot : '-' ?></td>
                                <td class="col-conv-ot" style="padding: 8px 4px; text-align: center; color: #107c10; font-weight: bold;"><?= $total_conv_ot > 0 ? $total_conv_ot : '-' ?></td>
                                <td class="col-hris-compare" id="hrisTotalOtCell" style="padding: 8px 4px; text-align: center; color: #107c10; font-weight: bold; display: none;">-</td>
                                <td class="col-hris-compare" id="hrisTotalBreakCell" style="padding: 8px 4px; text-align: center; color: #ea580c; font-weight: bold; display: none;">-</td>
                                <td class="col-status-ot" style="padding: 8px 4px;"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div style="margin-top: 14px; background: var(--card-bg); border: 1px solid var(--glass-border); border-radius: 12px; padding: 14px;">
                    <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-primary); margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                        ℹ️ <span>Rumus & Penjelasan Lembur (Payroll & HRIS)</span>
                    </div>
                    <div style="font-size: 0.72rem; color: var(--text-secondary); line-height: 1.6;">
                        <div style="margin-bottom: 10px; padding: 10px; background: rgba(234, 88, 12, 0.08); border-left: 4px solid #ea580c; border-radius: 6px; color: var(--text-primary);">
                            <strong style="color: #c2410c; font-size: 0.8rem;">💡 Penjelasan Komparasi HRIS & Potongan Break Time:</strong><br>
                            Jam lembur di HRIS Payroll adalah <strong>Jam Netto (setelah dipotong waktu istirahat / Break Time)</strong>.<br>
                            Data dianggap <strong>Sesuai (OK)</strong> jika: <code>Jam HRIS + Jam Break = Jam Web OT</code>.<br>
                            <span style="color: #b91c1c; font-weight: 600;">⚠️ Tanda Merah hanya muncul apabila total (Jam HRIS + Break) masih lebih kecil dari Jam Web OT.</span>
                        </div>
                        <div style="margin-bottom: 10px; padding: 10px; background: rgba(16, 124, 16, 0.08); border-left: 4px solid #107c10; border-radius: 6px; color: var(--text-primary);">
                            <strong style="color: #107c10; font-size: 0.8rem;">💰 Rumus Nominal Uang Lembur:</strong><br>
                            <strong>Total Uang Lembur = Total Conv OT × (Gaji Pokok ÷ 173)</strong>
                        </div>
                        <div style="margin-bottom: 8px; padding: 8px; background: rgba(17,141,255,0.05); border-left: 3px solid var(--pbi-blue); border-radius: 6px;">
                            <strong style="color: var(--pbi-blue);">1. Hari Kerja Biasa (Tipe R):</strong><br>
                            • <strong>Jam ke-1:</strong> Real OT × 1.5<br>
                            • <strong>Jam ke-2 & seterusnya:</strong> 1.5 + ((Real OT - 1) × 2.0)
                        </div>
                        <div style="padding: 8px; background: rgba(220,38,38,0.05); border-left: 3px solid #dc2626; border-radius: 6px;">
                            <strong style="color: #dc2626;">2. Hari Libur / Akhir Pekan (Tipe H):</strong><br>
                            • <strong>Jam ke-1 s/d 7:</strong> Real OT × 2.0<br>
                            • <strong>Jam ke-8:</strong> 14.0 + ((Real OT - 7) × 3.0)<br>
                            • <strong>Jam ke-9 & seterusnya:</strong> 17.0 + ((Real OT - 8) × 4.0)
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <div id="hris" class="tab-content">
            <div style="background: var(--card-bg); padding: 16px; border-radius: 12px; border: 1px solid var(--glass-border); margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                    <div style="font-weight: 700; font-size: 1rem; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
                        🏢 <span>Data Aktual HRIS (Payroll)</span>
                    </div>
                    <span style="font-size: 0.75rem; color: #107c10; font-weight: 600; background: rgba(16,124,16,0.1); padding: 3px 8px; border-radius: 4px;">NIK: <?= htmlspecialchars($driver_data['nik'] ?? '-') ?></span>
                </div>
                
                <div style="margin-bottom: 12px;">
                    <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                        📅 <span><?= __('period') ?? 'Periode' ?></span>
                    </label>
                    <select id="hris_period_select" onchange="onPeriodChange(this, 'hris_start_date', 'hris_end_date', 'load_hris')" style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border-radius: 8px; background: var(--bg-color); color: var(--text-primary); border: 1px solid var(--glass-border); font-weight: 600; cursor: pointer; outline: none;">
                        <?php 
                        $has_matched_hris = false;
                        foreach ($payroll_periods as $p): 
                            if ($p['selected']) $has_matched_hris = true;
                        ?>
                            <option value="<?= $p['val'] ?>" <?= $p['selected'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['label']) ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="custom" <?= !$has_matched_hris ? 'selected' : '' ?> disabled style="display: <?= !$has_matched_hris ? 'block' : 'none' ?>;">-- <?= __('custom_period') ?? 'Kustom Tanggal' ?> --</option>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 8px; align-items: flex-end;">
                    <div class="form-group" style="margin: 0;">
                        <label style="font-size: 0.7rem; color: var(--text-secondary);">Start Date</label>
                        <input type="date" id="hris_start_date" value="<?= $att_start ?>" onchange="checkCustomPeriod('hris_period_select', this.value, document.getElementById('hris_end_date').value)" style="padding: 8px; font-size: 0.85rem; border-radius: 8px; background: var(--bg-color); color: var(--text-primary); border: 1px solid var(--glass-border); width: 100%;">
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <label style="font-size: 0.7rem; color: var(--text-secondary);">End Date</label>
                        <input type="date" id="hris_end_date" value="<?= $att_end ?>" onchange="checkCustomPeriod('hris_period_select', document.getElementById('hris_start_date').value, this.value)" style="padding: 8px; font-size: 0.85rem; border-radius: 8px; background: var(--bg-color); color: var(--text-primary); border: 1px solid var(--glass-border); width: 100%;">
                    </div>
                    <button type="button" onclick="loadHrisData(true)" class="btn" style="padding: 10px 14px; border-radius: 8px; margin: 0; background: var(--pbi-blue); font-weight: 600;">🔍 Load</button>
                </div>
            </div>

            <div id="hrisContentBox">
                <div style="text-align: center; color: var(--text-secondary); padding: 40px; background: var(--card-bg); border-radius: 12px; border: 1px dashed var(--glass-border);">
                    <p>Klik tombol 🔍 <strong>Load</strong> untuk memuat data aktual dari HRIS.</p>
                </div>
            </div>
        </div>
        <div id="settings" class="tab-content"><?php include 'settings_tab.php'; ?></div>
        <div style="height: 90px;"></div>
    </div>

    <!-- BOTTOM NAV -->
    <div class="bottom-nav">
        <div class="nav-item active" onclick="showTab('shift', this)"><span class="nav-icon">🚗</span><span><?= __('home') ?></span></div>
        <div class="nav-item" onclick="showTab('history', this)"><span class="nav-icon">🕒</span><span><?= __('history') ?></span></div>
        <div class="nav-item" onclick="showTab('attendance', this)"><span class="nav-icon">⏰</span><span><?= __('attendance') ?></span></div>
        <div class="nav-item" onclick="showTab('hris', this); loadHrisData();"><span class="nav-icon">🏢</span><span>HRIS</span></div>
        <div class="nav-item" onclick="showTab('settings', this)"><span class="nav-icon">⚙️</span><span><?= __('settings') ?></span></div>
    </div>

    <script>
    // Global window.alert override with SweetAlert2
    window.alert = function(msg) {
        Swal.fire({
            text: msg,
            icon: 'warning',
            confirmButtonColor: '#3085d6',
            confirmButtonText: 'OK'
        });
    };

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        return String(text)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
    window.escapeHtml = escapeHtml;

    let destinations = <?= json_encode($destinations) ?>;
    let passengers = <?= json_encode($passengers) ?>;

    async function deleteDestOption(id, name, inputId, hiddenId, resultsId) {
        const isIndo = "<?= $_SESSION['lang'] ?? 'id' ?>" === 'id';
        const confirmRes = await Swal.fire({
            title: isIndo ? 'Hapus Destinasi?' : 'Delete Destination?',
            html: isIndo 
                ? `Apakah Anda yakin ingin menghapus <strong>"${escapeHtml(name)}"</strong> dari daftar saran riwayat tujuan?` 
                : `Are you sure you want to remove <strong>"${escapeHtml(name)}"</strong> from destination suggestions?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: isIndo ? 'Ya, Hapus' : 'Yes, Delete',
            cancelButtonText: isIndo ? 'Batal' : 'Cancel'
        });

        if (!confirmRes.isConfirmed) return;

        try {
            const fd = new FormData();
            fd.append('action', 'delete_destination');
            fd.append('destination_id', id);
            fd.append('ajax', '1');

            const res = await fetch('manage_trip.php', {
                method: 'POST',
                body: fd
            });
            const json = await res.json();

            if (json.success) {
                // Remove from in-memory destinations array
                if (typeof destinations !== 'undefined') {
                    const idx = destinations.findIndex(d => d.id == id);
                    if (idx !== -1) {
                        destinations.splice(idx, 1);
                    }
                }

                // If input currently has this destination typed, clear it
                const input = document.getElementById(inputId);
                const hidden = document.getElementById(hiddenId);
                if (input && input.value.trim().toLowerCase() === name.trim().toLowerCase()) {
                    input.value = '';
                    if (hidden) hidden.value = '';
                }

                // Re-trigger input to refresh the dropdown immediately
                if (input) {
                    input.dispatchEvent(new Event('input'));
                }

                Swal.fire({
                    icon: 'success',
                    title: isIndo ? 'Berhasil Dihapus' : 'Deleted Successfully',
                    text: json.message || (isIndo ? 'Tujuan berhasil dihapus dari daftar saran.' : 'Destination removed.'),
                    timer: 1600,
                    showConfirmButton: false
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: isIndo ? 'Gagal Menghapus' : 'Cannot Delete',
                    text: json.error || (isIndo ? 'Destinasi tidak dapat dihapus.' : 'Failed to delete destination.')
                });
            }
        } catch (err) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: err.message
            });
        }
    }

    function focusNextField(currentInputId) {
        if (currentInputId === 'car_search') {
            const next = document.getElementById('km_start_input');
            if (next) next.focus();
        } else if (currentInputId === 'km_start_input') {
            const next = document.getElementById('pass_search');
            if (next) next.focus();
        } else if (currentInputId === 'pass_search') {
            const next = document.getElementById('dest_search');
            if (next) next.focus();
        } else if (currentInputId === 'dest_search') {
            const photo = document.querySelector('input[name="km_start_photo"]');
            if (photo) {
                photo.focus();
            } else {
                const btn = document.querySelector('form button[type="submit"]');
                if (btn) btn.focus();
            }
        } else if (currentInputId === 'modal_dest_search') {
            const next = document.getElementById('modal_pass_search');
            if (next) next.focus();
        } else if (currentInputId === 'modal_pass_search') {
            const next = document.querySelector('#editTripModal input[name="car_no"]');
            if (next) next.focus();
        } else if (currentInputId.startsWith('edit_dest_search_')) {
            const tripId = currentInputId.replace('edit_dest_search_', '');
            const next = document.getElementById(`edit_pass_search_${tripId}`);
            if (next) next.focus();
        } else if (currentInputId.startsWith('edit_pass_search_')) {
            const tripId = currentInputId.replace('edit_pass_search_', '');
            const next = document.querySelector(`#history_edit_trip_form_${tripId} input[name="car_no"]`);
            if (next) next.focus();
        }
    }

    function initSearchable(inputId, hiddenId, resultsId, data, isDest = false) {
        const input = document.getElementById(inputId);
        const hidden = document.getElementById(hiddenId);
        const results = document.getElementById(resultsId);
        let activeIndex = -1;

        if(!input) return;

        input.addEventListener('focus', () => filter(input.value));
        input.addEventListener('input', (e) => {
            hidden.value = ''; // Reset ID if user types custom text
            filter(e.target.value);
        });

        input.addEventListener('keydown', (e) => {
            const options = results.querySelectorAll('.search-option');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (results.style.display === 'none') {
                    filter(input.value);
                    return;
                }
                if (options.length > 0) {
                    activeIndex = (activeIndex + 1) % options.length;
                    updateHighlight(options);
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (options.length > 0) {
                    activeIndex = (activeIndex - 1 + options.length) % options.length;
                    updateHighlight(options);
                }
            } else if (e.key === 'Enter') {
                e.preventDefault(); // Mencegah submit langsung saat Enter
                if (results.style.display !== 'none' && activeIndex >= 0 && options[activeIndex]) {
                    options[activeIndex].click();
                } else {
                    results.style.display = 'none';
                    focusNextField(inputId);
                }
            }
        });

        function updateHighlight(options) {
            options.forEach((opt, idx) => {
                if (idx === activeIndex) {
                    opt.classList.add('highlighted');
                    opt.scrollIntoView({ block: 'nearest' });
                } else {
                    opt.classList.remove('highlighted');
                }
            });
        }
        
        function filter(query) {
            activeIndex = -1;
            const q = (query || '').toLowerCase().trim();
            let filtered = data.filter(item => (item.name || '').toLowerCase().includes(q));
            if (filtered.length === 0) {
                results.style.display = 'none';
                results.innerHTML = '';
                return;
            }

            const isIndo = "<?= $_SESSION['lang'] ?? 'id' ?>" === 'id';
            let html = filtered.map((item, idx) => {
                const isDeletable = isDest && item.name !== '?';
                return `
                    <div class="search-option" data-idx="${idx}" style="${isDeletable ? 'display: flex; justify-content: space-between; align-items: center; padding: 10px 14px;' : 'padding: 10px 14px;'}">
                        <span style="flex: 1; text-align: left; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-right: 8px;">${escapeHtml(item.name)}</span>
                        ${isDeletable ? `
                        <button type="button" class="btn-dest-del" data-idx="${idx}" title="${isIndo ? 'Hapus dari riwayat tujuan' : 'Remove from suggestions'}" style="background: rgba(239, 68, 68, 0.08); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 6px; padding: 4px 8px; font-size: 0.85rem; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; line-height: 1; transition: all 0.2s;" onmouseover="this.style.background='rgba(239, 68, 68, 0.2)'" onmouseout="this.style.background='rgba(239, 68, 68, 0.08)'">
                            🗑️
                        </button>
                        ` : ''}
                    </div>
                `;
            }).join('');

            results.innerHTML = html;
            results.style.display = 'block';

            // Bind click handlers directly to DOM elements avoiding any string escaping issues
            results.querySelectorAll('.search-option').forEach(optEl => {
                const idx = parseInt(optEl.getAttribute('data-idx'), 10);
                const item = filtered[idx];
                if (!item) return;

                optEl.addEventListener('click', (e) => {
                    if (e.target.closest('.btn-dest-del')) return;
                    selectItem(inputId, hiddenId, resultsId, item.id, item.name);
                });

                const delBtn = optEl.querySelector('.btn-dest-del');
                if (delBtn) {
                    delBtn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        deleteDestOption(item.id, item.name, inputId, hiddenId, resultsId);
                    });
                }
            });
        }
    }

    function selectItem(inputId, hiddenId, resultsId, id, name) {
        const input = document.getElementById(inputId);
        const hidden = document.getElementById(hiddenId);
        const results = document.getElementById(resultsId);

        if (input) input.value = name;
        if (hidden) hidden.value = id;
        if (results) results.style.display = 'none';

        focusNextField(inputId);
    }

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.searchable-select')) {
            document.querySelectorAll('.search-results').forEach(r => r.style.display = 'none');
        }
    });

    initSearchable('dest_search', 'dest_id_hidden', 'dest_results', destinations, true);
    initSearchable('pass_search', 'pass_id_hidden', 'pass_results', passengers, false);

    const carsData = <?= json_encode(array_map(function($c) use ($car_last_km) {
        return [
            'id' => $c['id'],
            'car_no' => $c['car_no'],
            'last_km' => $car_last_km[$c['id']] ?? ''
        ];
    }, $cars)) ?>;

    function initSearchableCar(inputId, hiddenId, resultsId, kmInputId) {
        const input = document.getElementById(inputId);
        const hidden = document.getElementById(hiddenId);
        const results = document.getElementById(resultsId);
        const kmInput = document.getElementById(kmInputId);
        let activeIndex = -1;

        if (!input) return;

        input.addEventListener('focus', () => filterCar(input.value));
        input.addEventListener('input', (e) => {
            if (hidden) hidden.value = '';
            filterCar(e.target.value);
            updateKmForTypedCar(e.target.value);
        });

        input.addEventListener('keydown', (e) => {
            const options = results.querySelectorAll('.search-option');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (results.style.display === 'none') {
                    filterCar(input.value);
                    return;
                }
                if (options.length > 0) {
                    activeIndex = (activeIndex + 1) % options.length;
                    updateHighlight(options);
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (options.length > 0) {
                    activeIndex = (activeIndex - 1 + options.length) % options.length;
                    updateHighlight(options);
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (results.style.display !== 'none' && activeIndex >= 0 && options[activeIndex]) {
                    options[activeIndex].click();
                } else {
                    results.style.display = 'none';
                    if (kmInput) kmInput.focus();
                }
            }
        });

        function updateHighlight(options) {
            options.forEach((opt, idx) => {
                if (idx === activeIndex) {
                    opt.classList.add('highlighted');
                    opt.scrollIntoView({ block: 'nearest' });
                } else {
                    opt.classList.remove('highlighted');
                }
            });
        }

        function updateKmForTypedCar(val) {
            if (!kmInput || kmInput.getAttribute('data-user-modified')) return;
            const matched = carsData.find(c => c.car_no.trim().toLowerCase() === val.trim().toLowerCase());
            if (matched && matched.last_km !== '') {
                kmInput.value = matched.last_km;
            }
        }

        function filterCar(query) {
            activeIndex = -1;
            const q = (query || '').toLowerCase().trim();
            let filtered = carsData.filter(item => (item.car_no || '').toLowerCase().includes(q));
            if (filtered.length === 0) {
                results.style.display = 'none';
                results.innerHTML = '';
                return;
            }
            let html = filtered.map((item, idx) => `
                <div class="search-option" data-idx="${idx}" style="padding: 10px 14px;">
                    <span>🚗 <strong>${escapeHtml(item.car_no)}</strong></span>
                    ${item.last_km ? `<small style="color: var(--text-secondary); float: right;">KM: ${escapeHtml(String(item.last_km))}</small>` : ''}
                </div>
            `).join('');

            results.innerHTML = html;
            results.style.display = 'block';

            results.querySelectorAll('.search-option').forEach(optEl => {
                const idx = parseInt(optEl.getAttribute('data-idx'), 10);
                const item = filtered[idx];
                if (!item) return;

                optEl.addEventListener('click', () => {
                    selectCarItem(inputId, hiddenId, resultsId, kmInputId, item.id, item.car_no, item.last_km);
                });
            });
        }

        if (kmInput) {
            kmInput.addEventListener('input', () => {
                kmInput.setAttribute('data-user-modified', 'true');
            });
            if (input.value && !kmInput.value) {
                updateKmForTypedCar(input.value);
            }
        }
    }

    function selectCarItem(inputId, hiddenId, resultsId, kmInputId, id, carNo, lastKm) {
        const input = document.getElementById(inputId);
        const hidden = document.getElementById(hiddenId);
        const results = document.getElementById(resultsId);
        const kmInput = document.getElementById(kmInputId);

        if (input) input.value = carNo;
        if (hidden) hidden.value = id;
        if (results) results.style.display = 'none';

        if (kmInput && lastKm !== '' && !kmInput.getAttribute('data-user-modified')) {
            kmInput.value = lastKm;
        }
        if (kmInput) kmInput.focus();
    }

    initSearchableCar('car_search', 'car_id_hidden', 'car_results', 'km_start_input');

    const kmStartInputEl = document.getElementById('km_start_input');
    if (kmStartInputEl) {
        kmStartInputEl.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                focusNextField('km_start_input');
            }
        });
    }

    // Swipe gestures to switch tabs
    let touchstartX = 0;
    let touchstartY = 0;
    let touchendX = 0;
    let touchendY = 0;
    
    const tabsOrder = ['shift', 'history', 'attendance', 'hris', 'settings'];

    function handleGesture() {
        const diffX = touchendX - touchstartX;
        const diffY = touchendY - touchstartY;
        
        // Ensure horizontal swipe is dominant and exceeds threshold
        if (Math.abs(diffX) > Math.abs(diffY) && Math.abs(diffX) > 70) {
            const activeElement = document.activeElement;
            if (activeElement && (activeElement.tagName === 'INPUT' || activeElement.tagName === 'SELECT' || activeElement.tagName === 'TEXTAREA')) {
                return; // Prevent swipe tabs while typing
            }
            
            const currentTab = localStorage.getItem('driverActiveTab') || 'shift';
            const currentIndex = tabsOrder.indexOf(currentTab);
            
            if (diffX < 0) {
                // Swipe Left -> Next Tab
                if (currentIndex < tabsOrder.length - 1) {
                    showTab(tabsOrder[currentIndex + 1]);
                }
            } else {
                // Swipe Right -> Previous Tab
                if (currentIndex > 0) {
                    showTab(tabsOrder[currentIndex - 1]);
                }
            }
        }
    }

    document.addEventListener('touchstart', e => {
        if (e.target.closest('.search-results') || e.target.closest('input') || e.target.closest('select') || e.target.closest('textarea')) {
            return;
        }
        if (e.target.closest('table') || e.target.closest('.pbi-table') || e.target.closest('div[style*="overflow-x"]')) {
            return;
        }
        touchstartX = e.changedTouches[0].screenX;
        touchstartY = e.changedTouches[0].screenY;
    }, { passive: true });

    document.addEventListener('touchend', e => {
        if (e.target.closest('.search-results') || e.target.closest('input') || e.target.closest('select') || e.target.closest('textarea')) {
            return;
        }
        if (e.target.closest('table') || e.target.closest('.pbi-table') || e.target.closest('div[style*="overflow-x"]')) {
            return;
        }
        touchendX = e.changedTouches[0].screenX;
        touchendY = e.changedTouches[0].screenY;
        handleGesture();
    }, { passive: true });

    function showTab(tabId, el) {
        const tabsOrderList = ['shift', 'history', 'attendance', 'hris', 'settings'];
        const currentActiveTab = document.querySelector('.tab-content.active');
        let direction = '';
        if (currentActiveTab) {
            const oldId = currentActiveTab.id;
            const oldIndex = tabsOrderList.indexOf(oldId);
            const newIndex = tabsOrderList.indexOf(tabId);
            if (oldIndex !== -1 && newIndex !== -1 && oldIndex !== newIndex) {
                direction = newIndex > oldIndex ? 'left' : 'right';
            }
        }

        localStorage.setItem('driverActiveTab', tabId);
        document.querySelectorAll('.tab-content').forEach(t => {
            t.classList.remove('active', 'slide-in-left', 'slide-in-right');
        });
        document.querySelectorAll('.nav-item').forEach(t => t.classList.remove('active'));
        
        const targetTab = document.getElementById(tabId);
        if (targetTab) {
            targetTab.classList.add('active');
            if (direction === 'left') {
                targetTab.classList.add('slide-in-left');
            } else if (direction === 'right') {
                targetTab.classList.add('slide-in-right');
            }
        }
        
        if (el) {
            el.classList.add('active');
        } else {
            // Fallback to find navigation item
            const navItems = document.querySelectorAll('.nav-item');
            navItems.forEach(item => {
                if (item.getAttribute('onclick') && item.getAttribute('onclick').includes(tabId)) {
                    item.classList.add('active');
                }
            });
        }

        if (tabId === 'hris' && typeof loadHrisData === 'function') {
            loadHrisData(false);
        }
    }
    
    // Auto restore active tab on DOMContentLoaded
    document.addEventListener('DOMContentLoaded', () => {
        <?php if (isset($_SESSION['just_logged_in'])): ?>
            localStorage.removeItem('driverActiveTab');
            <?php unset($_SESSION['just_logged_in']); ?>
        <?php endif; ?>
        <?php if (isset($_GET['att_start']) || isset($_GET['att_end'])): ?>
            localStorage.setItem('driverActiveTab', 'attendance');
        <?php endif; ?>
        const savedTab = localStorage.getItem('driverActiveTab') || 'shift';
        showTab(savedTab);

        // Hide bottom nav when virtual keyboard is shown on mobile devices
        const initialHeight = window.innerHeight;
        window.addEventListener('resize', () => {
            const bottomNav = document.querySelector('.bottom-nav');
            if (bottomNav) {
                if (window.innerHeight < initialHeight - 100) {
                    bottomNav.style.setProperty('display', 'none', 'important');
                } else {
                    bottomNav.style.setProperty('display', 'flex', 'important');
                }
            }
        });
    });

    function toggleForm(id, btn) {
        const f = document.getElementById(id); 
        if (!f) return;

        const isShowing = f.classList.contains('show');

        if (id === 'end_trip_form' && !isShowing) {
            const expForm = document.getElementById('expense_form');
            if (expForm && expForm.classList.contains('show')) {
                const amountInput = expForm.querySelector('input[name="amount"]');
                const photoInput = expForm.querySelector('input[name="photo"]');
                if ((amountInput && amountInput.value.trim() !== '') || (photoInput && photoInput.files && photoInput.files.length > 0)) {
                    const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
                    Swal.fire({
                        title: lang === 'id' ? 'Form Belum Disimpan' : 'Form Not Saved',
                        text: lang === 'id' ? 'Anda sedang mengisi form Tambah Biaya tetapi belum menyimpannya. Apakah Anda ingin mengabaikan biaya tersebut dan melanjutkan Selesai Perjalanan?' : 'You are currently filling in the Expense form but have not saved it. Do you want to ignore this expense and proceed to End Trip?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: '#3085d6',
                        cancelButtonColor: '#d33',
                        confirmButtonText: lang === 'id' ? 'Ya, Lanjutkan' : 'Yes, Proceed',
                        cancelButtonText: lang === 'id' ? 'Batal' : 'Cancel'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            openForm(f);
                            if (btn) {
                                const arrow = btn.querySelector('.arrow-indicator');
                                if (arrow) arrow.textContent = '▲';
                            }
                        }
                    });
                    return;
                }
            }
        }

        if (isShowing) {
            closeForm(f);
            if (btn) {
                const arrow = btn.querySelector('.arrow-indicator');
                if (arrow) arrow.textContent = '▼';
            }
            const trParent = f.closest('tr.table-form-row');
            if (trParent) {
                setTimeout(() => {
                    const siblingForms = trParent.querySelectorAll('.collapsible-form.show');
                    if (siblingForms.length === 0) {
                        trParent.style.display = 'none';
                    }
                }, 250); // wait for collapse animation
            }
        } else {
            const trParent = f.closest('tr.table-form-row');
            if (trParent) {
                trParent.style.display = 'table-row';
            }
            openForm(f);
            if (btn) {
                const arrow = btn.querySelector('.arrow-indicator');
                if (arrow) arrow.textContent = '▲';
            }
        }
    }

    function bounceScrollTo(targetY) {
        const startY = window.pageYOffset || document.documentElement.scrollTop;
        const distance = targetY - startY;
        const duration = 750; // Animating in 750ms for snappy bounce
        let startTime = null;

        // Easing function: easeOutBack (overshoots and returns)
        function easeOutBack(t, b, c, d, s) {
            if (s === undefined) s = 2.0; // Bounce factor (s > 1.70158 creates more overshoot)
            return c * ((t = t / d - 1) * t * ((s + 1) * t + s) + 1) + b;
        }

        function animate(currentTime) {
            if (startTime === null) startTime = currentTime;
            const timeElapsed = currentTime - startTime;
            
            const run = easeOutBack(timeElapsed, startY, distance, duration);
            window.scrollTo(0, run);

            if (timeElapsed < duration) {
                requestAnimationFrame(animate);
            } else {
                window.scrollTo(0, targetY); // Hard snap to exact destination at the end
            }
        }
        requestAnimationFrame(animate);
    }

    function openForm(formEl) {
        formEl.classList.add('show');
        
        // Wait for class addition (longer timeout for collapsible forms to partially layout first)
        const delay = (formEl.id === 'expense_form' || formEl.id === 'end_trip_form') ? 220 : 80;
        setTimeout(() => {
            if (formEl.id === 'expense_form' || formEl.id === 'end_trip_form') {
                const targetY = document.documentElement.scrollHeight - window.innerHeight;
                const safeTargetY = Math.max(0, targetY);
                bounceScrollTo(safeTargetY);
            } else {
                const rect = formEl.getBoundingClientRect();
                const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
                const formTop = rect.top + scrollTop;
                const safeTargetY = Math.max(0, formTop - 100);
                bounceScrollTo(safeTargetY);
            }
            
            // Focus input element
            const input = formEl.querySelector('input:not([type="hidden"]), select, textarea');
            if (input) {
                setTimeout(() => {
                    input.focus();
                }, 300);
            }
        }, delay);
    }

    function closeForm(formEl) {
        formEl.classList.remove('show');
    }

    function confirmEndShift() {
        const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
        const incompleteCount = <?= $incomplete_trips_count ?>;
        const ongoingCount = <?= $ongoing_trips_count ?? 0 ?>;
        
        if (ongoingCount > 0) {
            Swal.fire({
                title: lang === 'id' ? '⚠️ Peringatan!' : '⚠️ Warning!',
                text: lang === 'id' 
                    ? `Ada ${ongoingCount} perjalanan yang belum selesai (ONGOING). Silakan selesaikan perjalanan (isi KM Akhir) terlebih dahulu.` 
                    : `There are ${ongoingCount} ongoing trips. Please finish the trips (fill End KM) first.`,
                icon: 'warning',
                confirmButtonColor: '#3085d6',
                confirmButtonText: 'OK'
            });
            return;
        }

        if (incompleteCount > 0) {
            Swal.fire({
                title: lang === 'id' ? '⚠️ Peringatan!' : '⚠️ Warning!',
                text: lang === 'id' 
                    ? `Ada ${incompleteCount} transaksi yang belum diset penumpang & tujuannya (masih '?'). Silakan perbarui/edit terlebih dahulu di tab Riwayat.` 
                    : `There are ${incompleteCount} transactions with unset passengers & destinations (still '?'). Please update/edit them first in the History tab.`,
                icon: 'warning',
                confirmButtonColor: '#3085d6',
                confirmButtonText: 'OK'
            });
            return;
        }
        Swal.fire({
            title: lang === 'id' ? 'Akhiri Shift?' : 'End Shift?',
            text: lang === 'id' ? 'Apakah Anda yakin ingin mengakhiri tugas/shift saat ini?' : 'Are you sure you want to end your shift?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#3085d6',
            confirmButtonText: lang === 'id' ? 'Ya, Akhiri Shift' : 'Yes, End Shift',
            cancelButtonText: lang === 'id' ? 'Batal' : 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('end_shift_form').submit();
            }
        });
    }

    function confirmDeleteExpense(id) {
        const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
        Swal.fire({
            title: lang === 'id' ? 'Hapus Biaya?' : 'Delete Expense?',
            text: lang === 'id' ? 'Apakah Anda yakin ingin menghapus catatan biaya ini?' : 'Are you sure you want to delete this expense?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#3085d6',
            confirmButtonText: lang === 'id' ? 'Ya, Hapus' : 'Yes, Delete',
            cancelButtonText: lang === 'id' ? 'Batal' : 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                submitFormElementAjax(document.getElementById('delete_expense_form_' + id));
            }
        });
    }

    function confirmCancelTrip() {
        const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
        Swal.fire({
            title: lang === 'id' ? 'Batalkan Perjalanan?' : 'Cancel Trip?',
            text: lang === 'id' ? 'Apakah Anda yakin ingin membatalkan perjalanan ini? Seluruh transaksi dan data perjalanan ini akan dihapus permanen dari sistem.' : 'Are you sure you want to cancel this trip? All recorded expenses and trip data will be deleted permanently.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#3085d6',
            confirmButtonText: lang === 'id' ? 'Ya, Batalkan' : 'Yes, Cancel Trip',
            cancelButtonText: lang === 'id' ? 'Batal' : 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('cancel_trip_form').submit();
            }
        });
    }

    function formatAmountInput(input) {
        let value = input.value.replace(/[^0-9]/g, '');
        if (value) {
            input.value = Number(value).toLocaleString('id-ID');
        } else {
            input.value = '';
        }
    }

    function cleanAmountOnSubmit(form) {
        const amountInput = form.querySelector('.formatted-amount-input');
        if (amountInput) {
            amountInput.value = amountInput.value.replace(/[^0-9]/g, '');
        }
        return true;
    }

    function validateStartTrip(form) {
        return true;
    }

    function validateEditTrip(form) {
        return true;
    }

    function proceedWithGPS(form, btn) {
        const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
        const gpsBypass = localStorage.getItem('gps_bypass') === 'true';
        if (gpsBypass) {
            document.getElementById('start_lat').value = '';
            document.getElementById('start_lng').value = '';
            form.submit();
            return;
        }
        if (navigator.geolocation) {
            const originalText = btn.innerText;
            btn.innerText = lang === 'id' ? 'Mengambil Lokasi...' : 'Capturing Location...';
            btn.disabled = true;

            navigator.geolocation.getCurrentPosition((pos) => {
                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;
                if (!lat || !lng) {
                    btn.innerText = originalText;
                    btn.disabled = false;
                    Swal.fire({
                        icon: 'error',
                        title: lang === 'id' ? 'Gagal Mendapatkan GPS' : 'GPS Capture Failed',
                        text: lang === 'id' ? 'Lokasi GPS kosong atau tidak valid. Pastikan GPS Anda aktif dan cari sinyal terbuka.' : 'GPS location is empty or invalid. Please ensure your GPS is active and look for open skies.'
                    });
                    return;
                }
                document.getElementById('start_lat').value = lat;
                document.getElementById('start_lng').value = lng;
                form.submit();
            }, (err) => {
                console.error(err);
                btn.innerText = originalText;
                btn.disabled = false;
                
                let titleMsg = lang === 'id' ? 'Akses Lokasi Ditolak' : 'Location Access Denied';
                let textMsg = lang === 'id' 
                    ? 'Gagal mengambil lokasi GPS karena akses diblokir. Pastikan izin lokasi HP Anda diaktifkan untuk aplikasi ini.' 
                    : 'Failed to retrieve GPS location because access is blocked. Please ensure Location permission is enabled for this app.';
                
                if (err.code === 3) { // TIMEOUT
                    titleMsg = lang === 'id' ? 'Sinyal GPS Lemah' : 'Weak GPS Signal';
                    textMsg = lang === 'id' 
                        ? 'Waktu pencarian GPS habis (Timeout). Pastikan Anda tidak berada di dalam ruang tertutup/gedung beton tebal dan coba lagi.' 
                        : 'GPS search timed out. Please make sure you are not inside a thick concrete building or basement, and try again.';
                } else if (err.code === 2) { // POSITION_UNAVAILABLE
                    titleMsg = lang === 'id' ? 'GPS HP Nonaktif' : 'GPS Location Disabled';
                    textMsg = lang === 'id' 
                        ? 'Akses GPS tidak tersedia. Silakan aktifkan layanan Lokasi (GPS) di menu bar atas HP Anda.' 
                        : 'GPS location is unavailable. Please turn on Location services in your Android settings/status bar.';
                }
                
                Swal.fire({
                    icon: 'error',
                    title: titleMsg,
                    text: textMsg
                });
            }, { timeout: 15000, enableHighAccuracy: true });
        } else {
            Swal.fire({
                icon: 'error',
                title: lang === 'id' ? 'Browser Tidak Mendukung GPS' : 'GPS Not Supported',
                text: lang === 'id' ? 'Browser Anda tidak mendukung fitur lokasi GPS.' : 'Your browser does not support GPS location features.'
            });
        }
    }

    function captureGPS(e) {
        const form = e.target.closest('form');
        const kmStartInput = form.querySelector('input[name="km_start"]');
        if (!kmStartInput || kmStartInput.value.trim() === '') {
            e.preventDefault();
            const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
            Swal.fire({
                icon: 'warning',
                title: lang === 'id' ? '⚠️ Perhatian' : '⚠️ Warning',
                text: lang === 'id' ? 'Silakan isi Odometer Awal terlebih dahulu!' : 'Please fill in the Starting Odometer first!',
                confirmButtonColor: '#3085d6',
                confirmButtonText: 'OK',
                returnFocus: false
            }).then(() => {
                setTimeout(() => {
                    if (kmStartInput) kmStartInput.focus();
                }, 100);
            });
            return;
        }

        const photoInput = form.querySelector('input[name="km_start_photo"]');
        if (photoInput && (!photoInput.files || photoInput.files.length === 0)) {
            e.preventDefault();
            const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
            Swal.fire({
                icon: 'warning',
                title: lang === 'id' ? '⚠️ Perhatian' : '⚠️ Warning',
                text: lang === 'id' ? 'Silakan unggah foto bukti Odometer Awal!' : 'Please upload the Starting Odometer photo proof!',
                confirmButtonColor: '#3085d6',
                confirmButtonText: 'OK',
                returnFocus: false
            }).then(() => {
                setTimeout(() => {
                    if (photoInput) photoInput.focus();
                }, 100);
            });
            return;
        }

        if (!form.reportValidity()) {
            e.preventDefault();
            return;
        }
        if (!validateStartTrip(form)) {
            e.preventDefault();
            return;
        }

        const destSearch = form.querySelector('#dest_search').value.trim();
        const passSearch = form.querySelector('#pass_search').value.trim();

        if (destSearch === '' || passSearch === '') {
            e.preventDefault();
            const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
            Swal.fire({
                title: lang === 'id' ? 'Konfirmasi' : 'Confirmation',
                text: lang === 'id' ? 'Tujuan dan Penumpang masih Kosong.. Lanjutkan?' : 'Destination and Passenger are still empty.. Proceed?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: lang === 'id' ? 'Ya, Lanjutkan' : 'Yes, Proceed',
                cancelButtonText: lang === 'id' ? 'Batal' : 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    proceedWithGPS(form, e.target);
                }
            });
        } else {
            e.preventDefault();
            proceedWithGPS(form, e.target);
        }
    }

    function showEndTripLoader() {
        const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
        Swal.fire({
            title: lang === 'id' ? 'Memproses...' : 'Processing...',
            text: lang === 'id' ? 'Sedang menyelesaikan perjalanan' : 'Concluding trip',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
    }

    let bypassUnsavedExpenseCheck = false;

    function captureGPSEnd(e) {
        const form = e.target;
        const expForm = document.getElementById('expense_form');
        if (!bypassUnsavedExpenseCheck && expForm && expForm.classList.contains('show')) {
            const amountInput = expForm.querySelector('input[name="amount"]');
            const photoInput = expForm.querySelector('input[name="photo"]');
            if ((amountInput && amountInput.value.trim() !== '') || (photoInput && photoInput.files && photoInput.files.length > 0)) {
                e.preventDefault();
                const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
                Swal.fire({
                    title: lang === 'id' ? 'Form Belum Disimpan' : 'Form Not Saved',
                    text: lang === 'id' 
                        ? 'Anda sedang mengisi form Tambah Biaya tetapi belum menyimpannya. Apakah Anda ingin mengabaikan biaya tersebut dan menyelesaikan perjalanan?' 
                        : 'You are currently filling in the Expense form but have not saved it. Do you want to ignore this expense and end the trip?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#ef4444',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: lang === 'id' ? 'Ya, Abaikan & Selesai' : 'Yes, Ignore & End',
                    cancelButtonText: lang === 'id' ? 'Batal' : 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        bypassUnsavedExpenseCheck = true;
                        // Find the submit button and click it to re-trigger captureGPSEnd with bypass set to true
                        const submitBtn = form.querySelector('button[type="submit"]');
                        if (submitBtn) {
                            submitBtn.click();
                        } else {
                            form.submit();
                        }
                    }
                });
                return;
            }
        }
        
        // Reset bypass flag for any subsequent calls
        bypassUnsavedExpenseCheck = false;

        const kmEndInput = document.querySelector('input[name="km_end"]');
        if (kmEndInput) {
            const kmEnd = parseInt(kmEndInput.value, 10);
            const kmStart = <?= $active_trip ? (int)$active_trip['km_start'] : 0 ?>;
            if (kmEnd < kmStart) {
                const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
                const msg = (lang === 'id') 
                    ? `Odometer Akhir (${kmEnd}) tidak boleh kurang dari Odometer Awal (${kmStart}).`
                    : `End Odometer (${kmEnd}) cannot be less than Start Odometer (${kmStart}).`;
                alert(msg);
                e.preventDefault();
                return;
            }
        }
        
        const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
        const gpsBypass = localStorage.getItem('gps_bypass') === 'true';
        if (gpsBypass) {
            document.getElementById('end_lat').value = '';
            document.getElementById('end_lng').value = '';
            submitGPSEndTripAjax(form);
            return;
        }
        if (navigator.geolocation) {
            e.preventDefault();
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn ? submitBtn.innerText : '';
            if (submitBtn) {
                submitBtn.innerText = lang === 'id' ? 'Mengambil Lokasi...' : 'Capturing Location...';
                submitBtn.disabled = true;
            }
            
            navigator.geolocation.getCurrentPosition((pos) => {
                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;
                if (!lat || !lng) {
                    if (submitBtn) {
                        submitBtn.innerText = originalText;
                        submitBtn.disabled = false;
                    }
                    Swal.fire({
                        icon: 'error',
                        title: lang === 'id' ? 'Gagal Mendapatkan GPS' : 'GPS Capture Failed',
                        text: lang === 'id' ? 'Lokasi GPS kosong atau tidak valid. Pastikan GPS Anda aktif dan cari sinyal terbuka.' : 'GPS location is empty or invalid. Please ensure your GPS is active and look for open skies.'
                    });
                    return;
                }
                document.getElementById('end_lat').value = lat;
                document.getElementById('end_lng').value = lng;
                submitGPSEndTripAjax(form);
            }, (err) => {
                console.error(err);
                if (submitBtn) {
                    submitBtn.innerText = originalText;
                    submitBtn.disabled = false;
                }
                
                let titleMsg = lang === 'id' ? 'Akses Lokasi Ditolak' : 'Location Access Denied';
                let textMsg = lang === 'id' 
                    ? 'Gagal mengambil lokasi GPS karena akses diblokir. Pastikan izin lokasi HP Anda diaktifkan untuk aplikasi ini.' 
                    : 'Failed to retrieve GPS location because access is blocked. Please ensure Location permission is enabled for this app.';
                
                if (err.code === 3) { // TIMEOUT
                    titleMsg = lang === 'id' ? 'Sinyal GPS Lemah' : 'Weak GPS Signal';
                    textMsg = lang === 'id' 
                        ? 'Waktu pencarian GPS habis (Timeout). Pastikan Anda tidak berada di dalam ruang tertutup/gedung beton tebal dan coba lagi.' 
                        : 'GPS search timed out. Please make sure you are not inside a thick concrete building or basement, and try again.';
                } else if (err.code === 2) { // POSITION_UNAVAILABLE
                    titleMsg = lang === 'id' ? 'GPS HP Nonaktif' : 'GPS Location Disabled';
                    textMsg = lang === 'id' 
                        ? 'Akses GPS tidak tersedia. Silakan aktifkan layanan Lokasi (GPS) di menu bar atas HP Anda.' 
                        : 'GPS location is unavailable. Please turn on Location services in your Android settings/status bar.';
                }
                
                Swal.fire({
                    icon: 'error',
                    title: titleMsg,
                    text: textMsg
                });
            }, { timeout: 15000, enableHighAccuracy: true });
        } else {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: lang === 'id' ? 'Browser Tidak Mendukung GPS' : 'GPS Not Supported',
                text: lang === 'id' ? 'Browser Anda tidak mendukung fitur lokasi GPS.' : 'Your browser does not support GPS location features.'
            });
        }
    }

    async function submitFormElementAjax(form) {
        const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
        Swal.fire({
            title: lang === 'id' ? 'Memproses...' : 'Processing...',
            text: lang === 'id' ? 'Mohon tunggu sebentar' : 'Please wait a moment',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        const amountInput = form.querySelector('.formatted-amount-input');
        let originalAmountVal = '';
        if (amountInput) {
            originalAmountVal = amountInput.value;
            amountInput.value = amountInput.value.replace(/[^0-9]/g, '');
        }

        const formData = new FormData(form);
        formData.append('ajax', '1');

        if (amountInput) {
            amountInput.value = originalAmountVal;
        }

        try {
            const actionUrl = form.getAttribute('action') || 'manage_trip.php';
            const response = await fetch(actionUrl, {
                method: 'POST',
                body: formData
            });
            
            const rawText = await response.text();
            if (rawText.trim() === 'Unauthorized') {
                Swal.fire({
                    icon: 'warning',
                    title: lang === 'id' ? 'Sesi Berakhir' : 'Session Expired',
                    text: lang === 'id' ? 'Sesi Anda telah berakhir. Silakan login kembali.' : 'Your session has expired. Please log in again.',
                    confirmButtonColor: '#3085d6',
                    confirmButtonText: 'OK',
                    allowOutsideClick: false
                }).then(() => {
                    window.location.href = 'login.php';
                });
                return;
            }

            // Detect WAF / Bot verification challenge or HTML interception
            if (rawText.includes('One moment, please') || rawText.includes('wsidchk') || rawText.includes('failedChecks') || rawText.includes('<!DOCTYPE html>') || rawText.includes('<html')) {
                Swal.fire({
                    icon: 'info',
                    title: lang === 'id' ? 'Memperbarui Sesi...' : 'Refreshing Session...',
                    text: lang === 'id' 
                        ? 'Koneksi keamanan server sedang diperbarui. Halaman akan dimuat ulang.' 
                        : 'Security session is refreshing. Reloading page...',
                    timer: 2000,
                    showConfirmButton: false,
                    allowOutsideClick: false
                }).then(() => {
                    window.location.reload();
                });
                return;
            }

            let result;
            try {
                result = JSON.parse(rawText);
            } catch (jsonErr) {
                console.error("Server raw response:", rawText);
                Swal.fire({
                    icon: 'error',
                    title: "System Error",
                    text: "Gagal memproses respon server. Respon mentah: " + rawText.substring(0, 300)
                });
                return;
            }
            
            if (result.success) {
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 2000,
                    timerProgressBar: true
                });
                
                Toast.fire({
                    icon: 'success',
                    title: result.message || (lang === 'id' ? 'Sukses' : 'Success')
                });

                const actionInput = form.querySelector('input[name="action"]');
                const actionVal = actionInput ? actionInput.value : '';
                if (actionVal === 'add_expense') {
                    form.reset();
                    if (amountInput) amountInput.value = '';
                    const collapsible = form.closest('.collapsible-form');
                    if (collapsible) {
                        closeForm(collapsible);
                        const btn = document.querySelector(`[onclick*="${collapsible.id}"]`);
                        if (btn) {
                            const arrow = btn.querySelector('.arrow-indicator');
                            if (arrow) arrow.textContent = '▼';
                        }
                    }
                }

                setTimeout(() => {
                    location.reload();
                }, 1500);

            } else {
                Swal.fire({
                    icon: 'error',
                    title: lang === 'id' ? 'Gagal' : 'Error',
                    text: result.error || (lang === 'id' ? 'Terjadi kesalahan.' : 'Something went wrong.')
                });
            }
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: lang === 'id' ? 'Koneksi Buruk' : 'Connection Error',
                text: lang === 'id' ? 'Gagal mengirim data. Silakan periksa koneksi internet Anda.' : 'Failed to send data. Please check your internet connection.'
            });
        }
    }
    window.submitFormElementAjax = submitFormElementAjax;

    async function submitFormAjax(event) {
        event.preventDefault();
        await submitFormElementAjax(event.target);
    }

    async function submitGPSEndTripAjax(form) {
        const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
        showEndTripLoader();
        const formData = new FormData(form);
        formData.append('ajax', '1');

        try {
            const actionUrl = form.getAttribute('action') || 'manage_trip.php';
            const response = await fetch(actionUrl, {
                method: 'POST',
                body: formData
            });
            
            const rawText = await response.text();
            if (rawText.trim() === 'Unauthorized') {
                Swal.fire({
                    icon: 'warning',
                    title: lang === 'id' ? 'Sesi Berakhir' : 'Session Expired',
                    text: lang === 'id' ? 'Sesi Anda telah berakhir. Silakan login kembali.' : 'Your session has expired. Please log in again.',
                    confirmButtonColor: '#3085d6',
                    confirmButtonText: 'OK',
                    allowOutsideClick: false
                }).then(() => {
                    window.location.href = 'login.php';
                });
                return;
            }

            // Detect WAF / Bot verification challenge or HTML interception
            if (rawText.includes('One moment, please') || rawText.includes('wsidchk') || rawText.includes('failedChecks') || rawText.includes('<!DOCTYPE html>') || rawText.includes('<html')) {
                hideEndTripLoader();
                Swal.fire({
                    icon: 'info',
                    title: lang === 'id' ? 'Memperbarui Sesi...' : 'Refreshing Session...',
                    text: lang === 'id' 
                        ? 'Koneksi keamanan server sedang diperbarui. Halaman akan dimuat ulang.' 
                        : 'Security session is refreshing. Reloading page...',
                    timer: 2000,
                    showConfirmButton: false,
                    allowOutsideClick: false
                }).then(() => {
                    window.location.reload();
                });
                return;
            }

            let result;
            try {
                result = JSON.parse(rawText);
            } catch (jsonErr) {
                console.error("Server raw response:", rawText);
                Swal.fire({
                    icon: 'error',
                    title: "System Error",
                    text: "Gagal memproses respon server. Respon mentah: " + rawText.substring(0, 300)
                });
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.innerText = lang === 'id' ? 'Simpan Odometer dan Selesai' : 'Save Odometer and Finish';
                    submitBtn.disabled = false;
                }
                return;
            }
            
            if (result.success) {
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 2000,
                    timerProgressBar: true
                });
                Toast.fire({
                    icon: 'success',
                    title: result.message || (lang === 'id' ? 'Perjalanan berhasil diakhiri.' : 'Trip ended successfully.')
                });
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                Swal.fire({
                    icon: 'error',
                    title: lang === 'id' ? 'Gagal' : 'Error',
                    text: result.error || (lang === 'id' ? 'Terjadi kesalahan.' : 'Something went wrong.')
                });
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.innerText = lang === 'id' ? 'Simpan Odometer dan Selesai' : 'Save Odometer and Finish';
                    submitBtn.disabled = false;
                }
            }
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: lang === 'id' ? 'Koneksi Buruk' : 'Connection Error',
                text: lang === 'id' ? 'Gagal mengirim data. Silakan periksa koneksi internet Anda.' : 'Failed to send data. Please check your internet connection.'
            });
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.innerText = lang === 'id' ? 'Simpan Odometer dan Selesai' : 'Save Odometer and Finish';
                submitBtn.disabled = false;
            }
        }
    }

    function openImageViewer(src) {
        document.getElementById('fullImageView').src = src;
        document.getElementById('imageViewerModal').style.display = 'flex';
    }
    function closeImageViewer() {
        document.getElementById('imageViewerModal').style.display = 'none';
        document.getElementById('fullImageView').src = '';
    }

    // Close on outside click
    window.addEventListener('click', (e) => {
        const modal = document.getElementById('imageViewerModal');
        if (e.target === modal) {
            closeImageViewer();
        }
    });

    // Close on Escape key press
    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeImageViewer();
        }
    });

    // Real-time server ticking clock
    const clockEl = document.getElementById('server-clock');
    if (clockEl) {
        let serverTimestamp = parseInt(clockEl.getAttribute('data-timestamp'), 10) * 1000;
        
        setInterval(() => {
            serverTimestamp += 1000;
            const d = new Date(serverTimestamp);
            
            const day = String(d.getDate()).padStart(2, '0');
            const monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
            const month = monthNames[d.getMonth()];
            const year = d.getFullYear();
            
            const hours = String(d.getHours()).padStart(2, '0');
            const minutes = String(d.getMinutes()).padStart(2, '0');
            const seconds = String(d.getSeconds()).padStart(2, '0');
            
            clockEl.innerHTML = `<div>${day} ${month} ${year}</div><div style="font-size: 0.95rem; color: var(--accent-color);">${hours}:${minutes}:${seconds}</div>`;
        }, 1000);
    }

    function onPeriodChange(selectEl, startId, endId, mode) {
        if (!selectEl.value || selectEl.value === 'custom') return;
        const parts = selectEl.value.split('|');
        if (parts.length !== 2) return;
        const startDate = parts[0];
        const endDate = parts[1];

        const startInput = document.getElementById(startId);
        const endInput = document.getElementById(endId);
        if (startInput) startInput.value = startDate;
        if (endInput) endInput.value = endDate;

        if (mode === 'submit_att') {
            const form = document.getElementById('attendance_filter_form');
            if (form) form.submit();
        } else if (mode === 'load_hris') {
            if (typeof loadHrisData === 'function') {
                loadHrisData(true);
            }
        }
    }

    function checkCustomPeriod(selectId, startVal, endVal) {
        const select = document.getElementById(selectId);
        if (!select) return;
        const pair = (startVal || '') + '|' + (endVal || '');
        let matched = false;
        for (let i = 0; i < select.options.length; i++) {
            if (select.options[i].value === pair) {
                select.selectedIndex = i;
                matched = true;
                break;
            }
        }
        if (!matched) {
            let customOpt = select.querySelector('option[value="custom"]');
            if (customOpt) {
                customOpt.style.display = 'block';
                select.value = 'custom';
            }
        }
    }

    let isHrisLoaded = false;

    async function loadHrisData(force = false) {
        if (isHrisLoaded && !force) return;
        const nik = "<?= htmlspecialchars($driver_data['nik'] ?? '') ?>";
        const startDate = document.getElementById('hris_start_date').value;
        const endDate = document.getElementById('hris_end_date').value;
        const box = document.getElementById('hrisContentBox');

        if (!nik || nik.trim() === '' || nik === '-') {
            box.innerHTML = `<div style="text-align:center; color:#ef4444; padding:30px; background:var(--card-bg); border-radius:12px; border:1px solid rgba(239,68,68,0.3);">⚠️ NIK Anda belum terdaftar di sistem. Silakan hubungi Admin untuk penginputan NIK.</div>`;
            return;
        }

        box.innerHTML = `<div style="text-align:center; color:var(--pbi-blue); padding:30px; background:var(--card-bg); border-radius:12px;">Loading Data...</div>`;

        try {
            const apiUrl = `index.php?action=fetch_hris_proxy&emp_cd=${encodeURIComponent(nik)}&start_date=${startDate}&end_date=${endDate}`;
            const res = await fetch(apiUrl);
            const json = await res.json();

            if (json.status === 'error') {
                box.innerHTML = `<div style="text-align:center; color:#ef4444; padding:30px; background:var(--card-bg); border-radius:12px; border:1px solid rgba(239,68,68,0.3);">⚠️ ${escapeHtml(json.message)}</div>`;
                return;
            }

            if (json.status !== 'success' || !json.data || json.data.length === 0) {
                box.innerHTML = `<div style="text-align:center; color:#fbbf24; padding:30px; background:var(--card-bg); border-radius:12px; border:1px dashed var(--glass-border);">⚠️ Tidak ada data lembur di HRIS untuk NIK ${nik} pada periode ini.</div>`;
                return;
            }

            isHrisLoaded = true;

            let totalOt = 0;
            let totalConvOt = 0;
            let totalTransport = 0;
            let totalOtDays = 0;

            const mIdList = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agt', 'Sep', 'Okt', 'Nov', 'Des'];
            const mEnList = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const dIdList = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
            const dEnList = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            const isIndo = "<?= $_SESSION['lang'] ?? 'id' ?>" === 'id';

            let rowsHtml = json.data.map(r => {
                const otVal = parseFloat(r.time_total || 0);
                const convVal = parseFloat(r.total_hour || 0);
                totalOt += otVal;
                totalConvOt += convVal;
                totalTransport += parseFloat(r.transport_amt || 0);
                if (otVal > 0) {
                    totalOtDays++;
                }

                const dParts = r.trn_date.split('-');
                const dateObj = new Date(parseInt(dParts[0]), parseInt(dParts[1]) - 1, parseInt(dParts[2]));
                const dayNum = dParts[2];
                const monthStr = isIndo ? mIdList[dateObj.getMonth()] : mEnList[dateObj.getMonth()];
                const dayName = isIndo ? dIdList[dateObj.getDay()] : dEnList[dateObj.getDay()];
                const dateCellHtml = `<strong>${dayNum} ${monthStr}</strong><div style="font-size: 0.65rem; color: var(--text-secondary); margin-top: 1px;">${dayName}</div>`;

                const befFr = r.bef_time_fr ? r.bef_time_fr.substring(0, 5) : '-';
                const befTo = r.bef_time_to ? r.bef_time_to.substring(0, 5) : '-';
                const earlyStr = (befFr !== '-' && befTo !== '-' && befFr !== befTo) 
                    ? `<strong>${befFr}</strong><div style="font-size: 0.65rem; color: var(--text-secondary); opacity: 0.6; margin-top: 1px;">${befTo}</div>` 
                    : '-';

                const timeFr = r.time_fr ? r.time_fr.substring(0, 5) : '-';
                const timeTo = r.time_to ? r.time_to.substring(0, 5) : '-';
                const lateStr = (timeFr !== '-' && timeTo !== '-' && timeFr !== timeTo) 
                    ? `<div style="font-size: 0.65rem; color: var(--text-secondary); opacity: 0.6; margin-bottom: 1px;">${timeFr}</div><strong>${timeTo}</strong>` 
                    : '-';

                const breakStr = r.break_time > 0 ? `<span style="color:#ea580c; font-weight:bold;">${r.break_time}</span>` : '-';
                const totalStr = r.time_total > 0 ? `<span style="color:var(--pbi-blue); font-weight:bold;">${r.time_total}</span>` : '-';
                const convStr = r.total_hour > 0 ? `<span style="color:#107c10; font-weight:bold;">${r.total_hour}</span>` : '-';

                return `
                    <tr style="border-bottom: 1px solid var(--glass-border);">
                        <td style="padding: 8px 6px;">${dateCellHtml}</td>
                        <td style="padding: 8px 6px; text-align: center; color: var(--text-secondary);">${earlyStr}</td>
                        <td style="padding: 8px 6px; text-align: center;">${lateStr}</td>
                        <td style="padding: 8px 6px; text-align: center;">${breakStr}</td>
                        <td style="padding: 8px 6px; text-align: center;">${totalStr}</td>
                        <td style="padding: 8px 6px; text-align: center;">${convStr}</td>
                        <td style="padding: 8px 6px; text-align: right; color:#107c10; font-weight:bold;">${(r.transport_amt || 0).toLocaleString('id-ID')}</td>
                    </tr>
                `;
            }).join('');

            box.innerHTML = `
                <div style="overflow-x: auto; background: var(--card-bg); border-radius: 12px; border: 1px solid var(--glass-border);">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.72rem; white-space: nowrap;">
                        <thead>
                            <tr style="background: rgba(0,0,0,0.03); border-bottom: 2px solid var(--glass-border); color: var(--text-secondary);">
                                <th style="padding: 8px 6px; text-align: left;">Tanggal</th>
                                <th style="padding: 8px 6px; text-align: center;">Awal</th>
                                <th style="padding: 8px 6px; text-align: center;">Akhir</th>
                                <th style="padding: 8px 6px; text-align: center;">Break</th>
                                <th style="padding: 8px 6px; text-align: center;">OT</th>
                                <th style="padding: 8px 6px; text-align: center;">Conv</th>
                                <th style="padding: 8px 6px; text-align: right;">Transport</th>
                            </tr>
                        </thead>
                        <tbody>${rowsHtml}</tbody>
                        <tfoot style="border-top: 2px solid var(--glass-border); font-weight: bold; background: rgba(0,0,0,0.02);">
                            <tr style="border-bottom: 1px dashed var(--glass-border);">
                                <td colspan="4" style="padding: 6px 4px; text-align: right; color: var(--text-secondary); font-size: 0.7rem;">HARI LEMBUR:</td>
                                <td style="padding: 6px 4px; text-align: center; color: #107c10; font-weight: bold;">${totalOtDays} Hari</td>
                                <td style="padding: 6px 4px;"></td>
                                <td style="padding: 6px 4px;"></td>
                            </tr>
                            <tr>
                                <td colspan="4" style="padding: 8px 4px; text-align: right; color: var(--text-primary);">TOTAL HRIS:</td>
                                <td style="padding: 8px 4px; text-align: center; color: var(--pbi-blue);">${totalOt.toFixed(2)}</td>
                                <td style="padding: 8px 4px; text-align: center; color: #107c10;">${totalConvOt.toFixed(2)}</td>
                                <td style="padding: 8px 4px; text-align: right; color: #107c10;">${totalTransport.toLocaleString('id-ID')}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div style="margin-top: 16px; background: rgba(234, 88, 12, 0.08); border: 1px solid rgba(234, 88, 12, 0.3); border-radius: 12px; padding: 14px;">
                    <h5 style="margin: 0 0 6px 0; color: #c2410c; font-size: 0.85rem; display: flex; align-items: center; gap: 6px; font-weight: 700;">
                        ⚠️ PENTING: Batas Waktu Koreksi Data
                    </h5>
                    <p style="margin: 0; font-size: 0.75rem; color: var(--text-primary); line-height: 1.5;">
                        Segera laporkan jika terdapat <strong>ketidaksesuaian data</strong> sebelum <strong>tanggal 23</strong>.<br>
                        <span style="color: #b91c1c; font-weight: 600;">Keterlambatan pelaporan beresiko menyebabkan data tidak terproses ke gaji bulan ini.</span>
                    </p>
                </div>
            `;
        } catch (err) {
            box.innerHTML = `<div style="text-align:center; color:#ef4444; padding:30px; background:var(--card-bg); border-radius:12px; border:1px solid rgba(239,68,68,0.3);">⚠️ Gagal terhubung ke API HRIS: ${err.message}</div>`;
        }
    }

    // HRIS Compare Logic for Overtime Tab
    let isCompareEnabled = false;
    let hrisCompareData = null;

    async function recalculateDriverOt() {
        const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
        const confirmMsg = lang === 'id' 
            ? 'Apakah Anda ingin menghitung ulang lembur untuk semua shift pada periode ini?' 
            : 'Do you want to recalculate overtime for all shifts in this period?';
        
        if (!confirm(confirmMsg)) return;

        const startDateInput = document.querySelector('input[name="att_start"]');
        const endDateInput = document.querySelector('input[name="att_end"]');
        const startDate = startDateInput ? startDateInput.value : '';
        const endDate = endDateInput ? endDateInput.value : '';

        const fd = new FormData();
        fd.append('action', 'recalculate_ot');
        fd.append('start_date', startDate);
        fd.append('end_date', endDate);
        fd.append('is_ajax', '1');

        try {
            const res = await fetch('manage_shift.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await res.json();
            if (data.success) {
                alert(data.msg || (lang === 'id' ? 'Lembur berhasil dihitung ulang!' : 'Overtime recalculated successfully!'));
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Failed to recalculate'));
            }
        } catch(err) {
            alert('JS Error: ' + err.message);
        }
    }

    async function toggleHrisCompare() {
        isCompareEnabled = !isCompareEnabled;
        const btn = document.getElementById('compareToggleBtn');
        const hrisCols = document.querySelectorAll('.col-hris-compare');
        const convCols = document.querySelectorAll('.col-conv-ot');
        const stsCols = document.querySelectorAll('.col-status-ot');
        
        if (isCompareEnabled) {
            btn.style.background = '#118DFF';
            btn.style.color = '#ffffff';
            btn.innerHTML = '✔ Compare Active';
            hrisCols.forEach(c => c.style.display = 'table-cell');
            convCols.forEach(c => c.style.display = 'none');
            stsCols.forEach(c => c.style.display = 'none');
            
            if (!hrisCompareData) {
                await fetchHrisForCompare();
            } else {
                renderHrisCompareCells();
            }
        } else {
            btn.style.background = 'rgba(17, 141, 255, 0.1)';
            btn.style.color = 'var(--pbi-blue)';
            btn.innerHTML = '🔍 Compare HRIS';
            hrisCols.forEach(c => c.style.display = 'none');
            convCols.forEach(c => c.style.display = 'table-cell');
            stsCols.forEach(c => c.style.display = 'table-cell');
        }
    }

    async function fetchHrisForCompare() {
        const nik = "<?= htmlspecialchars($driver_data['nik'] ?? '') ?>";
        if (!nik) return;
        
        const startDateInput = document.querySelector('input[name="att_start"]');
        const endDateInput = document.querySelector('input[name="att_end"]');
        const startDate = startDateInput ? startDateInput.value : "<?= $att_start ?>";
        const endDate = endDateInput ? endDateInput.value : "<?= $att_end ?>";
        
        try {
            const apiUrl = `index.php?action=fetch_hris_proxy&emp_cd=${encodeURIComponent(nik)}&start_date=${startDate}&end_date=${endDate}`;
            const res = await fetch(apiUrl);
            const json = await res.json();
            
            if (json.status === 'success' && json.data) {
                hrisCompareData = json.data;
                renderHrisCompareCells();
            }
        } catch(e) {
            console.error("Failed to load HRIS compare data:", e);
        }
    }

    function renderHrisCompareCells() {
        if (!hrisCompareData) return;
        
        let totalHrisOt = 0;
        let totalHrisBreak = 0;
        let totalHrisDays = 0;
        
        document.querySelectorAll('.hris-compare-cell').forEach(cell => {
            const dateStr = cell.dataset.date;
            const webOt = parseFloat(cell.dataset.webOt || 0);
            
            const tr = cell.closest('tr');
            const breakCell = tr ? tr.querySelector('.hris-break-cell') : null;
            
            const hrisRow = hrisCompareData.find(r => r.trn_date === dateStr);
            
            if (hrisRow) {
                const hrisOt = parseFloat(hrisRow.time_total || 0);
                const breakTime = parseFloat(hrisRow.break_time || 0);
                const totalHrisNet = hrisOt + breakTime;

                if (hrisOt > 0 || breakTime > 0) totalHrisDays++;
                totalHrisOt += hrisOt;
                totalHrisBreak += breakTime;

                if (breakCell) {
                    breakCell.innerHTML = breakTime > 0 ? `<span style="color:#ea580c; font-weight:bold;">${breakTime}</span>` : '-';
                }
                
                if (totalHrisNet < (webOt - 0.01)) {
                    // RED HIGHLIGHT ONLY IF (HRIS OT + Break) is LESS than WEB OT!
                    cell.innerHTML = `<span style="color: #dc2626; font-weight: 800; background: rgba(220,38,38,0.12); padding: 2px 6px; border-radius: 4px; border: 1px solid rgba(220,38,38,0.3); display: inline-block;" title="HRIS (${hrisOt}) + Break (${breakTime}) = ${totalHrisNet.toFixed(2)} < Web (${webOt})">${hrisOt > 0 ? hrisOt : '0'} ⚠️</span>`;
                } else {
                    cell.innerHTML = `<span style="color: #166534; font-weight: bold;">${hrisOt > 0 ? hrisOt : '-'}</span>`;
                }
            } else {
                if (breakCell) breakCell.innerHTML = '-';

                if (webOt > 0) {
                    // Red warning if Web has OT but HRIS has NO entry (0)
                    cell.innerHTML = `<span style="color: #dc2626; font-weight: 800; background: rgba(220,38,38,0.12); padding: 2px 6px; border-radius: 4px; border: 1px solid rgba(220,38,38,0.3); display: inline-block;" title="Tidak ada di HRIS!">0 ⚠️</span>`;
                } else {
                    cell.innerHTML = `<span style="color: var(--text-secondary);">-</span>`;
                }
            }
        });
        
        const daysCell = document.getElementById('hrisTotalDaysCell');
        const otCell = document.getElementById('hrisTotalOtCell');
        const breakTotalCell = document.getElementById('hrisTotalBreakCell');

        if (daysCell) daysCell.innerHTML = `${totalHrisDays} Hari`;
        if (otCell) otCell.innerHTML = totalHrisOt > 0 ? totalHrisOt.toFixed(2) : '-';
        if (breakTotalCell) breakTotalCell.innerHTML = totalHrisBreak > 0 ? `<span style="color:#ea580c;">${totalHrisBreak.toFixed(2)}</span>` : '-';
    }

    async function exportDriverOtPdf() {
        if (!window.jspdf) {
            alert('Library PDF sedang dimuat, mohon tunggu sebentar...');
            return;
        }

        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
        
        const driverName = "<?= htmlspecialchars($driver_data['full_name'] ?? 'Driver') ?>";
        const nik = "<?= htmlspecialchars($driver_data['nik'] ?? '-') ?>";
        const startDateInput = document.querySelector('input[name="att_start"]');
        const endDateInput = document.querySelector('input[name="att_end"]');
        const startDate = startDateInput ? startDateInput.value : "<?= $att_start ?>";
        const endDate = endDateInput ? endDateInput.value : "<?= $att_end ?>";
        
        // Fetch HRIS data if not fetched yet
        if (!hrisCompareData && nik !== '-') {
            try {
                const apiUrl = `index.php?action=fetch_hris_proxy&emp_cd=${encodeURIComponent(nik)}&start_date=${startDate}&end_date=${endDate}`;
                const res = await fetch(apiUrl);
                const json = await res.json();
                if (json.status === 'success' && json.data) {
                    hrisCompareData = json.data;
                }
            } catch(e){}
        }

        // Title Header
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(14);
        doc.setTextColor(15, 23, 42);
        doc.text('LAPORAN KOMPARASI LEMBUR DRIVER (CROSCEK HRIS)', 14, 15);
        
        doc.setFontSize(9);
        doc.setFont('helvetica', 'normal');
        doc.setTextColor(71, 85, 105);
        doc.text(`Nama Driver  : ${driverName}`, 14, 21);
        doc.text(`NIK Driver   : ${nik}`, 14, 26);
        doc.text(`Periode      : ${startDate} s/d ${endDate}`, 140, 21);
        doc.text(`Tgl Cetak    : ${new Date().toLocaleString('id-ID')}`, 140, 26);

        // Extract table rows from DOM & HRIS cache
        const tableRows = [];
        const domRows = document.querySelectorAll('.pbi-table tbody tr');
        
        let totalWebOt = 0;
        let totalHrisOt = 0;
        let totalTransport = 0;
        let webOtDays = 0;
        let hrisOtDays = 0;
        let mismatchCount = 0;

        domRows.forEach(tr => {
            const tds = tr.querySelectorAll('td');
            if (tds.length < 6) return;

            const tglText = tds[0].innerText.replace(/\s+/g, ' ').trim();
            const jamText = tds[1].innerText.replace(/\n/g, ' / ').trim();
            const awalText = tds[2].innerText.trim();
            const akhirText = tds[3].innerText.trim();
            const tipeText = tds[4].innerText.trim();
            const webOtText = tds[5].innerText.trim();
            
            const dateCell = tr.querySelector('.hris-compare-cell');
            const dateStr = dateCell ? dateCell.dataset.date : '';
            const webOtVal = parseFloat(webOtText) || 0;
            totalWebOt += webOtVal;
            if (webOtVal > 0 || awalText !== '-' || akhirText !== '-') {
                webOtDays++;
            }

            let hrisOtVal = 0;
            let breakVal = 0;
            let transportVal = 0;
            let statusText = 'OK (Sesuai)';

            if (hrisCompareData && dateStr) {
                const hrisRow = hrisCompareData.find(r => r.trn_date === dateStr);
                if (hrisRow) {
                    hrisOtVal = parseFloat(hrisRow.time_total || 0);
                    breakVal = parseFloat(hrisRow.break_time || 0);
                    transportVal = parseFloat(hrisRow.transport_amt || 0);

                    const totalHrisNet = hrisOtVal + breakVal;

                    if (totalHrisNet < (webOtVal - 0.01)) {
                        statusText = '[!] HRIS < WEB';
                        mismatchCount++;
                    }
                } else if (webOtVal > 0) {
                    statusText = '[!] Tidak Ada di HRIS';
                    mismatchCount++;
                }
            }
            if (hrisOtVal > 0) {
                hrisOtDays++;
            }
            totalHrisOt += hrisOtVal;
            totalTransport += transportVal;

            tableRows.push([
                tglText,
                jamText,
                awalText,
                akhirText,
                tipeText,
                webOtVal > 0 ? webOtVal.toString() : '-',
                breakVal > 0 ? breakVal.toString() : '-',
                hrisOtVal > 0 ? hrisOtVal.toString() : '-',
                transportVal > 0 ? transportVal.toLocaleString('id-ID') : '-',
                statusText
            ]);
        });

        doc.autoTable({
            startY: 32,
            head: [['Tanggal', 'Jam Shift', 'Awal', 'Akhir', 'Tipe', 'Web OT', 'Break', 'HRIS OT', 'Transport', 'Status Croscek']],
            body: tableRows,
            theme: 'grid',
            headStyles: { fillColor: [17, 141, 255], textColor: 255, fontStyle: 'bold', fontSize: 8 },
            bodyStyles: { fontSize: 8, cellPadding: 2 },
            columnStyles: {
                0: { cellWidth: 24 },
                1: { cellWidth: 30, halign: 'center' },
                2: { cellWidth: 16, halign: 'center' },
                3: { cellWidth: 16, halign: 'center' },
                4: { cellWidth: 14, halign: 'center' },
                5: { cellWidth: 20, halign: 'center', fontStyle: 'bold' },
                6: { cellWidth: 16, halign: 'center' },
                7: { cellWidth: 20, halign: 'center', fontStyle: 'bold' },
                8: { cellWidth: 24, halign: 'right', fontStyle: 'bold' },
                9: { cellWidth: 70 }
            },
            didParseCell: function(data) {
                if (data.section === 'body') {
                    const rowData = tableRows[data.row.index];
                    if (rowData && rowData[9].includes('[!]')) {
                        data.cell.styles.fillColor = [254, 226, 226];
                        data.cell.styles.textColor = [185, 28, 28];
                        data.cell.styles.fontStyle = 'bold';
                    }
                }
            }
        });

        const finalY = doc.lastAutoTable.finalY + 8;
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(9);
        doc.setTextColor(15, 23, 42);
        doc.text('REKAPITULASI SUMMARY:', 14, finalY);
        
        doc.setFont('helvetica', 'normal');
        doc.text(`Total Web OT: ${totalWebOt.toFixed(2)} Jam (${webOtDays} Hari)   |   Total HRIS OT: ${totalHrisOt.toFixed(2)} Jam (${hrisOtDays} Hari)   |   Total Transport HRIS: ${totalTransport.toLocaleString('id-ID')}`, 14, finalY + 5);
        
        if (mismatchCount > 0) {
            doc.setTextColor(185, 28, 28);
            doc.setFont('helvetica', 'bold');
            const noteText = `[!] Catatan: Ditemukan ${mismatchCount} hari ketidaksesuaian data (HRIS < Web). Perbedaan nilai sebagian besar disebabkan oleh potongan jam istirahat (Break Time) pada HRIS. Mohon Admin melakukan croscek.`;
            const splitNote = doc.splitTextToSize(noteText, 265);
            doc.text(splitNote, 14, finalY + 11);
        } else {
            doc.setTextColor(22, 101, 52);
            const noteText = `[OK] Catatan: Seluruh data lembur sesuai antara Web dan HRIS.`;
            const splitNote = doc.splitTextToSize(noteText, 265);
            doc.text(splitNote, 14, finalY + 11);
        }

        const safeDriverName = driverName.replace(/[^a-zA-Z0-9_]/g, '_');
        doc.save(`Croscek_Lembur_${safeDriverName}_${startDate}.pdf`);
    }

    // Keep-alive heartbeat: maintains session & WAF clearance cookie automatically
    setInterval(() => {
        fetch('ping.php').catch(() => {});
    }, 10 * 60 * 1000); // Ping every 10 minutes

    // Auto-refresh security token when tab becomes visible after phone sleep
    let lastTabActiveTime = Date.now();
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            const idleTime = Date.now() - lastTabActiveTime;
            lastTabActiveTime = Date.now();
            if (idleTime > 25 * 60 * 1000) { // Idle for > 25 mins
                fetch('ping.php')
                    .then(r => r.text())
                    .then(t => {
                        if (t.includes('<!DOCTYPE html>') || t.includes('One moment, please') || t.includes('wsidchk')) {
                            window.location.reload();
                        }
                    })
                    .catch(() => {});
            }
        } else {
            lastTabActiveTime = Date.now();
        }
    });
    </script>

    <!-- Image Viewer Modal (Full Size) -->
    <div id="imageViewerModal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); backdrop-filter: blur(5px); align-items: center; justify-content: center;">
        <div style="background: rgba(0,0,0,0.9); margin: auto; padding: 12px; border-radius: 12px; width: auto; max-width: 95%; text-align: center; position: relative;">
            <button onclick="closeImageViewer()" style="position: absolute; right: 15px; top: 15px; background: rgba(255,255,255,0.2); border: none; border-radius: 50%; color: white; cursor: pointer; font-size: 1.5rem; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; z-index: 10;">×</button>
            <img id="fullImageView" src="" style="max-height: 80vh; max-width: 100%; border-radius: 4px; object-fit: contain; margin-top: 40px; display: block; margin-left: auto; margin-right: auto;">
        </div>
    </div>
</body>
</html>
