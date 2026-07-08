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

// 5. Get History with Date Range
$default_hist_start = date('Y-m-d', strtotime('-1 day'));
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

// 6. Get Overtime History (aggregated per day using MIN and MAX)
$default_att_start = date('Y-m-21', strtotime('-1 month', strtotime(date('Y-m-01'))));
$default_att_end = date('Y-m-20');

$att_start = $_GET['att_start'] ?? $default_att_start;
$att_end = $_GET['att_end'] ?? $default_att_end;

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
                          ORDER BY shift_date DESC");
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
    <style>
        .searchable-select { position: relative; width: 100%; }
        .search-results {
            position: absolute; top: 100%; left: 0; right: 0;
            background: var(--glass-bg); border: 1px solid var(--glass-border);
            border-radius: 8px; max-height: 200px; overflow-y: auto;
            z-index: 100; display: none; box-shadow: var(--glass-shadow);
        }
        .search-option { padding: 12px; cursor: pointer; border-bottom: 1px solid var(--glass-border); font-size: 0.9rem; }
        .search-option:hover { background: rgba(0,0,0,0.05); }

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

        <?php if ($pending_passenger_trips_count > 0): ?>
            <div class="alert alert-warning" style="background: #fffbeb; color: #b45309; border: 1px solid #fde68a; padding: 8px 10px; border-radius: 12px; margin-bottom: 12px; font-size: 0.85rem; font-weight: 500; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                <span style="flex: 1;">⚠️ <?= $_SESSION['lang'] === 'id' ? "Ada {$pending_passenger_trips_count} perjalanan menunggu persetujuan penumpang." : "There are {$pending_passenger_trips_count} trips waiting for passenger approval." ?></span>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <button onclick="showTab('history')" style="background: #d97706; border: none; color: white; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; cursor: pointer; white-space: nowrap;"><?= $_SESSION['lang'] === 'id' ? 'Cek Riwayat' : 'Check History' ?></button>
                    <button onclick="this.closest('.alert').style.display='none'" style="background:none; border:none; color:#b45309; font-size:1.3rem; cursor:pointer; font-weight:bold; padding:0; line-height:1; margin-left:4px;">&times;</button>
                </div>
            </div>
        <?php endif; ?>

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
                            
                            <!-- Searchable Destination -->
                            <div class="form-group searchable-select">
                                <label><?= __('destination') ?></label>
                                <input type="text" id="dest_search" placeholder="Cari atau Tambah Tujuan..." autocomplete="off">
                                <input type="hidden" name="destination_id" id="dest_id_hidden">
                                <input type="text" name="new_destination" id="new_dest_input" placeholder="Nama Tujuan Baru" style="display:none; margin-top: 10px;">
                                <div id="dest_results" class="search-results"></div>
                            </div>

                            <!-- Searchable Passenger -->
                            <div class="form-group searchable-select">
                                <label><?= __('passenger') ?></label>
                                <input type="text" id="pass_search" placeholder="Cari User..." autocomplete="off">
                                <input type="hidden" name="passenger_id" id="pass_id_hidden">
                                <div id="pass_results" class="search-results"></div>
                            </div>

                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label><?= __('car_no') ?></label>
                                    <select name="car_id" id="car_id_select" required>
                                        <?php foreach ($cars as $c): ?>
                                            <option value="<?= $c['id'] ?>" data-last-km="<?= $car_last_km[$c['id']] ?>" <?= ($driver_data['preferred_car_id'] == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['car_no']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label><?= __('km_start') ?></label>
                                    <input type="number" name="km_start" id="km_start_input" placeholder="0" required>
                                </div>
                            </div>
                            
                            <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                const carSelect = document.getElementById('car_id_select');
                                const kmStartInput = document.getElementById('km_start_input');
                                
                                if (carSelect && kmStartInput) {
                                    function updateKmStart() {
                                        const selectedOption = carSelect.options[carSelect.selectedIndex];
                                        const lastKm = selectedOption.getAttribute('data-last-km');
                                        if (lastKm && !kmStartInput.getAttribute('data-user-modified')) {
                                            kmStartInput.value = lastKm;
                                        }
                                    }
                                    
                                    // Let user override without auto-replacing back on blur
                                    kmStartInput.addEventListener('input', () => {
                                        kmStartInput.setAttribute('data-user-modified', 'true');
                                    });
                                    
                                    carSelect.addEventListener('change', () => {
                                        kmStartInput.removeAttribute('data-user-modified');
                                        updateKmStart();
                                    });
                                    
                                    // Init on load
                                    updateKmStart();
                                }
                            });
                            </script>
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
                <form action="index.php" method="GET" style="display: grid; grid-template-columns: 1fr 1fr auto auto; gap: 8px; align-items: flex-end;">
                    <div class="form-group" style="margin: 0;">
                        <label style="font-size: 0.7rem; color: var(--text-secondary);"><?= __('start_date') ?? 'Start' ?></label>
                        <input type="date" name="att_start" value="<?= $att_start ?>" style="padding: 8px; font-size: 0.85rem; border-radius: 8px;">
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <label style="font-size: 0.7rem; color: var(--text-secondary);"><?= __('end_date') ?? 'End' ?></label>
                        <input type="date" name="att_end" value="<?= $att_end ?>" style="padding: 8px; font-size: 0.85rem; border-radius: 8px;">
                    </div>
                    <button type="submit" class="btn" style="padding: 10px 12px; border-radius: 8px; margin-bottom: 0;">🔍</button>
                    <a href="index.php" class="btn" style="padding: 10px 12px; border-radius: 8px; margin-bottom: 0; background: rgba(239,68,68,0.1); color: #ef4444; border: 1px solid #ef4444; display: flex; align-items: center; justify-content: center; text-decoration: none;">❌</a>
                </form>
            </div>

            <h3 style="text-align: left; margin-bottom: 15px; font-size: 1.1rem;"><?= __('attendance_report') ?></h3>

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
                                <th style="padding: 6px 4px; border-bottom: 2px solid var(--glass-border); text-align: center; color: var(--text-secondary);">Real</th>
                                <th style="padding: 6px 4px; border-bottom: 2px solid var(--glass-border); text-align: center; color: var(--text-secondary);">Conv</th>
                                <th style="padding: 6px 4px; border-bottom: 2px solid var(--glass-border); text-align: center; color: var(--text-secondary);">Sts</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_real_ot = 0;
                            $total_conv_ot = 0;
                            foreach ($attendance_records as $ar): 
                                $total_real_ot += (float)($ar['real_ot'] ?? 0);
                                $total_conv_ot += (float)($ar['conv_ot'] ?? 0);
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
                                $has_ot = (float)($ar['real_ot'] ?? 0) > 0;
                                
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
                                        <?= formatDecimalHoursPHP($ar['overtime_early']) ?>
                                    </td>
                                    <td style="padding: 6px 4px; text-align: center; color: <?= $ar['overtime_late'] > 0 ? 'var(--pbi-blue)' : 'var(--text-secondary)' ?>;">
                                        <?= formatDecimalHoursPHP($ar['overtime_late']) ?>
                                    </td>
                                    <td style="padding: 6px 4px; text-align: center; font-weight: bold; color: <?= ($ar['ot_type'] ?? 'R') === 'H' ? '#dc2626' : '#475569' ?>;">
                                        <?= $ar['ot_type'] ?? '-' ?>
                                    </td>
                                    <td style="padding: 6px 4px; text-align: center; font-weight: <?= ($ar['real_ot'] ?? 0) > 0 ? 'bold' : 'normal' ?>; color: <?= ($ar['real_ot'] ?? 0) > 0 ? 'var(--pbi-blue)' : 'var(--text-secondary)' ?>;">
                                        <?= ($ar['real_ot'] ?? 0) > 0 ? (float)$ar['real_ot'] : '-' ?>
                                    </td>
                                    <td style="padding: 6px 4px; text-align: center; font-weight: <?= ($ar['conv_ot'] ?? 0) > 0 ? 'bold' : 'normal' ?>; color: <?= ($ar['conv_ot'] ?? 0) > 0 ? '#107c10' : 'var(--text-secondary)' ?>;">
                                        <?= ($ar['conv_ot'] ?? 0) > 0 ? (float)$ar['conv_ot'] : '-' ?>
                                    </td>
                                    <td style="padding: 6px 4px; text-align: center; font-size: 0.65rem;">
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
                            <tr>
                                <td colspan="5" style="padding: 8px 4px; text-align: right; color: var(--text-primary);">TOTAL:</td>
                                <td style="padding: 8px 4px; text-align: center; color: var(--text-primary);"><?= $total_real_ot > 0 ? $total_real_ot : '-' ?></td>
                                <td style="padding: 8px 4px; text-align: center; color: #107c10;"><?= $total_conv_ot > 0 ? $total_conv_ot : '-' ?></td>
                                <td style="padding: 8px 4px;"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <div style="margin-top: 16px; background: rgba(16, 124, 16, 0.05); border: 1px dashed rgba(16, 124, 16, 0.3); border-radius: 12px; padding: 14px;">
                    <h5 style="margin: 0 0 8px 0; color: #107c10; font-size: 0.85rem; display: flex; align-items: center; gap: 6px;">💡 Info Perhitungan Uang Lembur</h5>
                    <p style="margin: 0; font-size: 0.75rem; color: var(--text-secondary); line-height: 1.5;">
                        Sistem menghitung <strong>Conv OT (Jam Lembur Konversi)</strong> Anda sesuai pengali aturan pemerintah.<br>
                        Untuk mengetahui estimasi Uang Lembur (Rupiah), Anda dapat menggunakan rumus baku ini:<br>
                        <span style="display: block; margin-top: 6px; padding: 6px 10px; background: rgba(255,255,255,0.7); border-radius: 6px; color: var(--text-primary); font-weight: 600;">Uang Lembur = Total Conv OT × (1 / 173 × Gaji Pokok)</span>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <div id="settings" class="tab-content"><?php include 'settings_tab.php'; ?></div>
        <div style="height: 90px;"></div>
    </div>

    <!-- BOTTOM NAV -->
    <div class="bottom-nav">
        <div class="nav-item active" onclick="showTab('shift', this)"><span class="nav-icon">🚗</span><span><?= __('home') ?></span></div>
        <div class="nav-item" onclick="showTab('history', this)"><span class="nav-icon">🕒</span><span><?= __('history') ?></span></div>
        <div class="nav-item" onclick="showTab('attendance', this)"><span class="nav-icon">⏰</span><span><?= __('attendance') ?></span></div>
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

    const destinations = <?= json_encode($destinations) ?>;
    const passengers = <?= json_encode($passengers) ?>;

    function initSearchable(inputId, hiddenId, resultsId, data, isDest = false) {
        const input = document.getElementById(inputId);
        const hidden = document.getElementById(hiddenId);
        const results = document.getElementById(resultsId);

        if(!input) return;

        input.addEventListener('focus', () => filter(input.value));
        input.addEventListener('input', (e) => {
            hidden.value = ''; // Reset ID if user types custom text
            filter(e.target.value);
        });
        
        function filter(query) {
            let filtered = data.filter(item => item.name.toLowerCase().includes(query.toLowerCase()));
            let html = filtered.map(item => `<div class="search-option" onclick="selectItem('${inputId}', '${hiddenId}', '${resultsId}', '${item.id}', '${item.name}', ${isDest})">${item.name}</div>`).join('');
            
            if (isDest) {
                html += `<div class="search-option" style="color: var(--accent-color); font-weight: bold;" onclick="selectItem('${inputId}', '${hiddenId}', '${resultsId}', 'NEW', '+ Tambah Baru', true)">+ <?= __('add_new_destination') ?></div>`;
            }

            results.innerHTML = html;
            results.style.display = 'block';
        }
    }

    function selectItem(inputId, hiddenId, resultsId, id, name, isDest) {
        const input = document.getElementById(inputId);
        const hidden = document.getElementById(hiddenId);
        const results = document.getElementById(resultsId);
        
        let newDestInput = null;
        if (inputId.startsWith('edit_dest_search_')) {
            const tripId = inputId.replace('edit_dest_search_', '');
            newDestInput = document.getElementById(`edit_new_dest_input_${tripId}`);
        } else if (inputId === 'edit_dest_search') {
            newDestInput = document.getElementById('edit_new_dest_input');
        } else {
            newDestInput = document.getElementById('new_dest_input');
        }

        input.value = (id === 'NEW') ? '+ Tambah Baru' : name;
        hidden.value = id;
        results.style.display = 'none';

        if (isDest && newDestInput) {
            newDestInput.style.display = (id === 'NEW') ? 'block' : 'none';
            if (id === 'NEW') {
                newDestInput.required = true;
                newDestInput.focus();
            } else {
                newDestInput.required = false;
            }
        }
    }

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.searchable-select')) {
            document.querySelectorAll('.search-results').forEach(r => r.style.display = 'none');
        }
    });

    initSearchable('dest_search', 'dest_id_hidden', 'dest_results', destinations, true);
    initSearchable('pass_search', 'pass_id_hidden', 'pass_results', passengers, false);

    // Swipe gestures to switch tabs
    let touchstartX = 0;
    let touchstartY = 0;
    let touchendX = 0;
    let touchendY = 0;
    
    const tabsOrder = ['shift', 'history', 'attendance', 'settings'];

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
        const tabsOrderList = ['shift', 'history', 'attendance', 'settings'];
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
    }
    
    // Auto restore active tab on DOMContentLoaded
    document.addEventListener('DOMContentLoaded', () => {
        <?php if (isset($_SESSION['just_logged_in'])): ?>
            localStorage.removeItem('driverActiveTab');
            <?php unset($_SESSION['just_logged_in']); ?>
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
        const destSearch = form.querySelector('#dest_search').value.trim();
        const destId = form.querySelector('#dest_id_hidden').value;
        const passSearch = form.querySelector('#pass_search').value.trim();
        const passengerId = form.querySelector('#pass_id_hidden').value;

        if (destSearch !== '' && !destId) {
            alert("Silakan pilih Tujuan dari daftar saran yang muncul!");
            form.querySelector('#dest_search').focus();
            return false;
        }
        if (passSearch !== '' && !passengerId) {
            alert("Silakan pilih Penumpang dari daftar saran yang muncul!");
            form.querySelector('#pass_search').focus();
            return false;
        }
        return true;
    }

    function validateEditTrip(form) {
        const destIdInput = form.querySelector('input[name="destination_id"]');
        const passengerIdInput = form.querySelector('input[name="passenger_id"]');
        const destSearchInput = form.querySelector('.dest-search-input');
        const passSearchInput = form.querySelector('.pass-search-input');

        const destId = destIdInput ? destIdInput.value : '';
        const passengerId = passengerIdInput ? passengerIdInput.value : '';

        if (!destId) {
            alert("Silakan pilih Tujuan dari daftar saran yang muncul!");
            if (destSearchInput) destSearchInput.focus();
            return false;
        }
        if (!passengerId) {
            alert("Silakan pilih Penumpang dari daftar saran yang muncul!");
            if (passSearchInput) passSearchInput.focus();
            return false;
        }
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
