<?php
require_once 'config.php';

if (!isset($_SESSION['passenger_id'])) {
    header('Location: passenger_login.php');
    exit;
}

$passenger_id = $_SESSION['passenger_id'];
$passenger_name = $_SESSION['passenger_name'];

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
    if ($_POST['action'] === 'add_expense_note') {
        header('Content-Type: application/json');
        try {
            $expense_id = $_POST['expense_id'];
            $note = trim($_POST['supervisor_note'] ?? '');
            $stmt = $pdo->prepare("UPDATE trip_expenses SET supervisor_note = ? WHERE id = ?");
            $stmt->execute([$note, $expense_id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// Fetch Pending Trips (Completed by driver, not yet approved)
$stmt_pending = $pdo->prepare("SELECT t.*, d.name as dest_name, u.full_name as driver_name, c.car_no, s.start_time as shift_start_time, s.end_time as shift_end_time 
                               FROM trips t 
                               JOIN master_destinations d ON t.destination_id = d.id 
                               JOIN shifts s ON t.shift_id = s.id
                               JOIN users u ON s.driver_id = u.id
                               JOIN master_cars c ON t.car_id = c.id
                               WHERE t.passenger_id = ? AND t.passenger_approval = 'pending' AND t.status = 'completed'
                               ORDER BY t.end_time DESC");
$stmt_pending->execute([$passenger_id]);
$pending_trips = $stmt_pending->fetchAll();

// Date range filters for History (default yesterday to today)
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-1 day'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Fetch History Trips (Already approved/rejected) within date range
$stmt_history = $pdo->prepare("SELECT t.*, d.name as dest_name, u.full_name as driver_name, c.car_no, s.start_time as shift_start_time, s.end_time as shift_end_time 
                               FROM trips t 
                               JOIN master_destinations d ON t.destination_id = d.id 
                               JOIN shifts s ON t.shift_id = s.id
                               JOIN users u ON s.driver_id = u.id
                               JOIN master_cars c ON t.car_id = c.id
                               WHERE t.passenger_id = ? AND t.passenger_approval != 'pending' AND t.status = 'completed'
                                 AND DATE(t.end_time) BETWEEN ? AND ?
                               ORDER BY t.end_time DESC");
$stmt_history->execute([$passenger_id, $start_date, $end_date]);
$history_trips = $stmt_history->fetchAll();

// Fetch Expenses for all these trips
$all_trip_ids = array_merge(
    array_column($pending_trips, 'id'),
    array_column($history_trips, 'id')
);

$expenses_by_trip = [];
if (!empty($all_trip_ids)) {
    $in = str_repeat('?,', count($all_trip_ids) - 1) . '?';
    $stmt_exp = $pdo->prepare("SELECT * FROM trip_expenses WHERE trip_id IN ($in)");
    $stmt_exp->execute($all_trip_ids);
    $expenses = $stmt_exp->fetchAll();
    foreach ($expenses as $exp) {
        $expenses_by_trip[$exp['trip_id']][] = $exp;
    }
}

// Check if passenger is a supervisor
$stmt_supervisor = $pdo->prepare("SELECT COUNT(*) FROM users WHERE supervisor_id = ?");
$stmt_supervisor->execute([$passenger_id]);
$is_supervisor = $stmt_supervisor->fetchColumn() > 0;

$supervisor_shifts = [];
if ($is_supervisor) {
    $stmt_sup_shifts = $pdo->prepare("SELECT s.*, u.full_name as driver_name 
                                      FROM shifts s 
                                      JOIN users u ON s.driver_id = u.id 
                                      WHERE u.supervisor_id = ? AND s.status = 'completed' AND s.real_ot > 0
                                      ORDER BY s.shift_date DESC LIMIT 50");
    $stmt_sup_shifts->execute([$passenger_id]);
    $supervisor_shifts = $stmt_sup_shifts->fetchAll();
    
    $stmt_sup_expenses = $pdo->prepare("SELECT e.*, u.full_name as driver_name, t.shift_id, d.name as dest_name
                                        FROM trip_expenses e
                                        JOIN trips t ON e.trip_id = t.id
                                        JOIN shifts s ON t.shift_id = s.id
                                        JOIN users u ON s.driver_id = u.id
                                        JOIN master_destinations d ON t.destination_id = d.id
                                        WHERE u.supervisor_id = ?
                                        ORDER BY e.created_at DESC LIMIT 50");
    $stmt_sup_expenses->execute([$passenger_id]);
    $supervisor_expenses = $stmt_sup_expenses->fetchAll();

    $all_sup_shift_ids = array_column($supervisor_shifts, 'id');
    $sup_trips_by_shift = [];
    if (!empty($all_sup_shift_ids)) {
        $in = str_repeat('?,', count($all_sup_shift_ids) - 1) . '?';
        $stmt_sup_trips = $pdo->prepare("SELECT t.*, d.name as dest_name, p.name as pass_name, c.car_no 
                                         FROM trips t 
                                         JOIN master_destinations d ON t.destination_id = d.id 
                                         JOIN master_passengers p ON t.passenger_id = p.id
                                         JOIN master_cars c ON t.car_id = c.id
                                         WHERE t.shift_id IN ($in) ORDER BY t.end_time ASC");
        $stmt_sup_trips->execute($all_sup_shift_ids);
        $sup_trips = $stmt_sup_trips->fetchAll();
        foreach ($sup_trips as $t) {
            $sup_trips_by_shift[$t['shift_id']][] = $t;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" class="notranslate">
<head>
    <meta charset="UTF-8">
    <meta name="google" content="notranslate">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Trips - <?= htmlspecialchars($passenger_name) ?></title>
    
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

    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f8fafc; margin: 0; color: #1e293b; }
        .header { background: #fff; padding: 20px 24px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 100; border-bottom: 1px solid #e2e8f0; }
        .header-title-box { display: flex; flex-direction: column; }
        .header-title { margin: 0; font-size: 1.25rem; font-weight: 700; color: #0f172a; }
        .header-subtitle { margin: 2px 0 0 0; font-size: 0.85rem; color: #64748b; }
        .logout-btn { color: #ef4444; text-decoration: none; font-size: 0.85rem; font-weight: 700; padding: 8px 16px; border-radius: 50px; background: #fef2f2; transition: all 0.2s; }
        .logout-btn:hover { background: #fee2e2; }
        
        .container { max-width: 600px; margin: 0 auto; padding: 24px 16px; }
        
        .tab-nav { display: flex; background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); margin-bottom: 24px; overflow: hidden; border: 1px solid #e2e8f0; padding: 4px; }
        .tab-btn { flex: 1; padding: 12px; border: none; background: transparent; font-weight: 600; cursor: pointer; color: #64748b; font-size: 0.9rem; border-radius: 8px; transition: all 0.2s; }
        .tab-btn.active { background: #eff6ff; color: #3b82f6; }
        
        .trip-card { background: #fff; border-radius: 16px; padding: 20px; margin-bottom: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; position: relative; overflow: hidden; }
        .trip-header { display: flex; justify-content: space-between; margin-bottom: 15px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; }
        .trip-date { font-weight: 700; color: #3b82f6; font-size: 0.95rem; }
        .trip-status { font-size: 0.75rem; font-weight: 700; padding: 4px 10px; border-radius: 50px; text-transform: uppercase; letter-spacing: 0.5px; }
        .status-pending { background: #fef3c7; color: #d97706; }
        .status-approved { background: #dcfce7; color: #15803d; }
        .status-rejected { background: #fee2e2; color: #b91c1c; }
        
        .trip-row { display: flex; margin-bottom: 10px; font-size: 0.9rem; align-items: center; }
        .trip-label { width: 100px; color: #64748b; font-weight: 600; font-size: 0.85rem; }
        .trip-value { flex: 1; font-weight: 700; color: #334155; }
        
        .action-area { margin-top: 15px; padding-top: 15px; border-top: 1px solid #f1f5f9; }
        .feedback-input { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 12px; font-family: inherit; font-size: 0.9rem; box-sizing: border-box; background: #f8fafc; transition: border 0.2s; }
        .feedback-input:focus { outline: none; border-color: #3b82f6; background: #fff; }
        .btn-group { display: flex; gap: 12px; }
        .btn { flex: 1; padding: 12px; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; text-align: center; font-size: 0.95rem; transition: transform 0.1s, opacity 0.2s; }
        .btn:active { transform: scale(0.98); }
        .btn-approve { background: #3b82f6; color: #fff; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25); }
        .btn-reject { background: #fff; color: #ef4444; border: 1px solid #fca5a5; }
        
        .empty-state { text-align: center; padding: 60px 20px; color: #64748b; background: #fff; border-radius: 16px; border: 1px dashed #cbd5e1; }
        
        /* Settings Tab */
        .settings-card { background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #f1f5f9; }
        .settings-title { font-size: 1.1rem; font-weight: 700; color: #0f172a; margin-top: 0; margin-bottom: 20px; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 0.85rem; font-weight: 600; color: #475569; margin-bottom: 8px; }
        .form-input { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 1rem; box-sizing: border-box; font-family: inherit; }
        .btn-primary { width: 100%; padding: 12px; background: #3b82f6; color: #fff; border: none; border-radius: 8px; font-weight: 700; font-size: 1rem; cursor: pointer; }
        .alert { padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem; font-weight: 600; display: none; }
        .alert-success { background: #dcfce7; color: #15803d; }
        .alert-error { background: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-title-box">
            <h1 class="header-title">My Dashboard</h1>
            <p class="header-subtitle"><?= htmlspecialchars($passenger_name) ?></p>
        </div>
        <div style="display: flex; gap: 10px; align-items: center;">
            <a href="docs.php" style="background: #8b5cf6; color: white; padding: 6px 12px; border-radius: 6px; font-weight: bold; font-size: 0.8rem; text-decoration: none;">📖 Manual</a>
            <a href="logout.php?type=passenger" class="logout-btn">Logout</a>
        </div>
    </div>

    <!-- PWA Install Banner -->
    <div id="pwa-install-banner" style="display: none; background: #eff6ff; border-bottom: 1px solid #bfdbfe; padding: 12px 24px; justify-content: space-between; align-items: center; gap: 12px; font-size: 0.85rem; color: #1e3a8a; font-weight: 600;">
        <span>📲 Install this web app on your home screen for quick, secure access to your trips.</span>
        <div style="display: flex; gap: 12px; align-items: center; flex-shrink: 0;">
            <button onclick="triggerPWAInstall()" style="background: #3b82f6; border: none; color: white; padding: 6px 12px; border-radius: 6px; font-size: 0.8rem; font-weight: 700; cursor: pointer; white-space: nowrap;">Install</button>
            <button onclick="document.getElementById('pwa-install-banner').style.display='none'" style="background: none; border: none; color: #1e3a8a; font-size: 1.3rem; font-weight: bold; cursor: pointer; line-height: 1; padding: 0;">&times;</button>
        </div>
    </div>

    <div class="container">
        <div class="tab-nav">
            <button class="tab-btn active" onclick="showTab('pending', this)"><?= __('pending') ?> (<?= count($pending_trips) ?>)</button>
            <button class="tab-btn" onclick="showTab('history', this)"><?= __('history') ?></button>
            <?php if ($is_supervisor): ?>
            <button class="tab-btn" onclick="showTab('overtime', this)">Driver OT</button>
            <button class="tab-btn" onclick="showTab('expenses', this)">Driver Exp</button>
            <?php endif; ?>
            <button class="tab-btn" onclick="showTab('settings', this)"><?= __('settings') ?></button>
        </div>

        <div id="tab-pending" class="tab-content">
            <?php if (empty($pending_trips)): ?>
                <div class="empty-state">
                    <div style="font-size: 3rem; margin-bottom: 15px; color: #3b82f6;">📋</div>
                    <h3 style="margin: 0 0 10px 0; color: #334155;">All clear!</h3>
                    <p style="margin: 0; font-size: 0.95rem;">You have no pending trips to approve.</p>
                </div>
            <?php else: ?>
                <?php foreach ($pending_trips as $t): ?>
                    <div class="trip-card" id="trip-<?= $t['id'] ?>">
                        <div class="trip-header">
                            <span class="trip-date"><?= date('d M Y - H:i', strtotime($t['end_time'])) ?></span>
                            <span class="trip-status status-pending">Needs Approval</span>
                        </div>
                        <div class="trip-row"><div class="trip-label">Driver</div><div class="trip-value"><?= htmlspecialchars($t['driver_name']) ?></div></div>
                        <div class="trip-row">
                            <div class="trip-label">Driver Shift</div>
                            <div class="trip-value">
                                ⏱️ <?= substr($t['shift_start_time'], 0, 5) ?> &rarr; <?= ($t['shift_end_time'] && $t['shift_end_time'] !== '00:00:00') ? substr($t['shift_end_time'], 0, 5) : 'Ongoing' ?>
                            </div>
                        </div>
                        <div class="trip-row"><div class="trip-label">Vehicle</div><div class="trip-value"><?= htmlspecialchars($t['car_no']) ?></div></div>
                        <div class="trip-row"><div class="trip-label">Destination</div><div class="trip-value"><?= htmlspecialchars($t['dest_name']) ?></div></div>
                        <div class="trip-row"><div class="trip-label">Distance</div><div class="trip-value"><?= ($t['km_end'] - $t['km_start']) ?> KM</div></div>
                        
                        <div style="display: flex; gap: 10px; margin: 15px 0;">
                            <?php if($t['km_start_photo']): ?>
                                <div style="flex: 1;">
                                    <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 4px; font-weight: 600;">KM Start Photo</div>
                                    <div style="cursor: pointer;" onclick="openImageViewer('uploads/<?= $t['km_start_photo'] ?>')">
                                        <img src="uploads/<?= $t['km_start_photo'] ?>" style="width: 100%; height: 90px; object-fit: cover; border-radius: 8px; border: 1px solid #e2e8f0;" alt="KM Start">
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if($t['km_end_photo']): ?>
                                <div style="flex: 1;">
                                    <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 4px; font-weight: 600;">KM End Photo</div>
                                    <div style="cursor: pointer;" onclick="openImageViewer('uploads/<?= $t['km_end_photo'] ?>')">
                                        <img src="uploads/<?= $t['km_end_photo'] ?>" style="width: 100%; height: 90px; object-fit: cover; border-radius: 8px; border: 1px solid #e2e8f0;" alt="KM End">
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($expenses_by_trip[$t['id']])): ?>
                            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #e2e8f0;">
                                <div style="font-size: 0.85rem; font-weight: 700; color: #475569; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px;">Trip Expenses</div>
                                <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px;">
                                    <?php 
                                    $total_cost = 0;
                                    foreach ($expenses_by_trip[$t['id']] as $exp): 
                                        $total_cost += $exp['amount'];
                                    ?>
                                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.9rem;">
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <?php if ($exp['photo']): ?>
                                                    <img src="uploads/<?= $exp['photo'] ?>" style="width: 28px; height: 28px; object-fit: cover; border-radius: 6px; cursor: pointer; border: 1px solid #e2e8f0;" onclick="openImageViewer('uploads/<?= $exp['photo'] ?>')">
                                                <?php endif; ?>
                                                <span style="color: #64748b; font-weight: 500;"><?= htmlspecialchars(ucfirst($exp['expense_type'])) ?></span>
                                            </div>
                                            <span style="font-weight: 700; color: #1e293b;">Rp <?= number_format($exp['amount'], 0, ',', '.') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div style="display: flex; justify-content: space-between; font-size: 0.95rem; font-weight: 800; border-top: 1px solid #e2e8f0; padding-top: 10px; color: #3b82f6;">
                                    <span>TOTAL BIAYA / TOTAL COST</span>
                                    <span>Rp <?= number_format($total_cost, 0, ',', '.') ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="action-area">
                            <input type="text" id="feedback-<?= $t['id'] ?>" class="feedback-input" placeholder="Add note or feedback (optional)">
                            <div class="btn-group">
                                <button class="btn btn-reject" onclick="processApproval(<?= $t['id'] ?>, 'rejected')">Reject</button>
                                <button class="btn btn-approve" onclick="processApproval(<?= $t['id'] ?>, 'approved')">Approve Trip</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div id="tab-history" class="tab-content" style="display:none;">
            <!-- Date Filter Form -->
            <div style="background: #fff; padding: 16px; border-radius: 16px; border: 1px solid #e2e8f0; margin-bottom: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                <form action="passenger_dashboard.php" method="GET" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; align-items: flex-end;">
                    <div style="display: flex; flex-direction: column; gap: 6px;">
                        <label style="font-size: 0.75rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Start Date</label>
                        <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" style="padding: 10px; border: 1px solid #cbd5e1; border-radius: 10px; font-family: inherit; font-size: 0.9rem; width: 100%; box-sizing: border-box;">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 6px;">
                        <label style="font-size: 0.75rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">End Date</label>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" style="padding: 10px; border: 1px solid #cbd5e1; border-radius: 10px; font-family: inherit; font-size: 0.9rem; width: 100%; box-sizing: border-box;">
                    </div>
                    <button type="submit" style="background: #3b82f6; color: white; border: none; padding: 12px 20px; border-radius: 10px; font-weight: 700; cursor: pointer; transition: background 0.2s; font-size: 0.9rem; height: 41px;">Filter</button>
                </form>
            </div>

            <?php if (empty($history_trips)): ?>
                <div class="empty-state"><p>No past trips found for the selected date range.</p></div>
            <?php else: ?>
                <?php foreach ($history_trips as $t): ?>
                    <div class="trip-card">
                        <div class="trip-header">
                            <span class="trip-date"><?= date('d M Y - H:i', strtotime($t['end_time'])) ?></span>
                            <span class="trip-status status-<?= $t['passenger_approval'] ?>"><?= ucfirst($t['passenger_approval']) ?></span>
                        </div>
                        <div class="trip-row"><div class="trip-label">Driver</div><div class="trip-value"><?= htmlspecialchars($t['driver_name']) ?></div></div>
                        <div class="trip-row">
                            <div class="trip-label">Driver Shift</div>
                            <div class="trip-value">
                                ⏱️ <?= substr($t['shift_start_time'], 0, 5) ?> &rarr; <?= ($t['shift_end_time'] && $t['shift_end_time'] !== '00:00:00') ? substr($t['shift_end_time'], 0, 5) : 'Ongoing' ?>
                            </div>
                        </div>
                        <div class="trip-row"><div class="trip-label">Destination</div><div class="trip-value"><?= htmlspecialchars($t['dest_name']) ?></div></div>
                        <div class="trip-row"><div class="trip-label">Distance</div><div class="trip-value"><?= ($t['km_end'] - $t['km_start']) ?> KM</div></div>
                        <?php if ($t['passenger_feedback']): ?>
                            <div class="trip-row"><div class="trip-label">Feedback</div><div class="trip-value" style="font-weight:400; font-style:italic;">"<?= htmlspecialchars($t['passenger_feedback']) ?>"</div></div>
                        <?php endif; ?>

                        <div style="display: flex; gap: 10px; margin-top: 15px; padding-top: 15px; border-top: 1px dashed #e2e8f0;">
                            <?php if($t['km_start_photo']): ?>
                                <div style="flex: 1;">
                                    <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 4px; font-weight: 600;">KM Start Photo</div>
                                    <div style="cursor: pointer;" onclick="openImageViewer('uploads/<?= $t['km_start_photo'] ?>')">
                                        <img src="uploads/<?= $t['km_start_photo'] ?>" style="width: 100%; height: 90px; object-fit: cover; border-radius: 8px; border: 1px solid #e2e8f0;" alt="KM Start">
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if($t['km_end_photo']): ?>
                                <div style="flex: 1;">
                                    <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 4px; font-weight: 600;">KM End Photo</div>
                                    <div style="cursor: pointer;" onclick="openImageViewer('uploads/<?= $t['km_end_photo'] ?>')">
                                        <img src="uploads/<?= $t['km_end_photo'] ?>" style="width: 100%; height: 90px; object-fit: cover; border-radius: 8px; border: 1px solid #e2e8f0;" alt="KM End">
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($expenses_by_trip[$t['id']])): ?>
                            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #e2e8f0;">
                                <div style="font-size: 0.85rem; font-weight: 700; color: #475569; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px;">Trip Expenses</div>
                                <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px;">
                                    <?php 
                                    $total_cost = 0;
                                    foreach ($expenses_by_trip[$t['id']] as $exp): 
                                        $total_cost += $exp['amount'];
                                    ?>
                                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.9rem;">
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <?php if ($exp['photo']): ?>
                                                    <img src="uploads/<?= $exp['photo'] ?>" style="width: 28px; height: 28px; object-fit: cover; border-radius: 6px; cursor: pointer; border: 1px solid #e2e8f0;" onclick="openImageViewer('uploads/<?= $exp['photo'] ?>')">
                                                <?php endif; ?>
                                                <span style="color: #64748b; font-weight: 500;"><?= htmlspecialchars(ucfirst($exp['expense_type'])) ?></span>
                                            </div>
                                            <span style="font-weight: 700; color: #1e293b;">Rp <?= number_format($exp['amount'], 0, ',', '.') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div style="display: flex; justify-content: space-between; font-size: 0.95rem; font-weight: 800; border-top: 1px solid #e2e8f0; padding-top: 10px; color: #3b82f6;">
                                    <span>TOTAL BIAYA / TOTAL COST</span>
                                    <span>Rp <?= number_format($total_cost, 0, ',', '.') ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Unapprove button -->
                        <div style="margin-top: 16px; border-top: 1px solid #f1f5f9; padding-top: 16px; text-align: right;">
                            <button style="background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; font-size: 0.8rem; padding: 8px 16px; border-radius: 50px; font-weight: 700; cursor: pointer; transition: all 0.2s;" onclick="confirmUnapprove(<?= $t['id'] ?>)">↩ Cancel Approval</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div id="tab-overtime" class="tab-content" style="display:none;">
            <div style="background: #fff; padding: 16px; border-radius: 16px; border: 1px solid #e2e8f0; margin-bottom: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                <h3 style="margin-top:0; color: #0f172a;">Driver Overtime Monitor</h3>
                <p style="margin-bottom:0; font-size: 0.85rem; color: #64748b;">Memonitor data lembur driver yang Anda bawahi.</p>
            </div>
            
            <?php if (empty($supervisor_shifts)): ?>
                <div class="empty-state"><p>No overtime data found.</p></div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="pbi-table" style="width: 100%; border-collapse: collapse; font-size: 0.8rem; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;">
                        <thead>
                            <tr style="background: rgba(0,0,0,0.02);">
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0; text-align: left; color: #64748b;">Date</th>
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0; text-align: left; color: #64748b;">Driver</th>
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0; text-align: center; color: #64748b;">Time</th>
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0; text-align: center; color: #64748b;">Early</th>
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0; text-align: center; color: #64748b;">Late</th>
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0; text-align: center; color: #64748b;">Tipe</th>
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0; text-align: center; color: #64748b;">Real</th>
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0; text-align: center; color: #64748b;">Conv</th>
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0; text-align: center; color: #64748b;">Status</th>
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0; text-align: center; color: #64748b;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($supervisor_shifts as $s): 
                                $duration = '-';
                                if ($s['end_time']) {
                                    if ($s['end_time'] === '00:00:00') {
                                        $duration = 'Timeout';
                                    } else {
                                        $start = new DateTime($s['shift_date'] . ' ' . $s['start_time']);
                                        $end = new DateTime($s['shift_date'] . ' ' . $s['end_time']);
                                        $diff = $start->diff($end);
                                        $duration = $diff->format('%hh %im');
                                    }
                                }
                                $is_holiday = (($s['ot_type'] ?? 'R') === 'H');
                            ?>
                                <tr style="border-bottom: 1px solid #e2e8f0; <?= $is_holiday ? 'background-color: rgba(220, 38, 38, 0.1);' : '' ?>">
                                    <td style="padding: 10px;">
                                        <?php 
                                            $m_id = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agt', 'Sep', 'Okt', 'Nov', 'Des'];
                                            $m_idx = (int)date('n', strtotime($s['shift_date'])) - 1;
                                            $m_str = $m_id[$m_idx];
                                            
                                            $d_id = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
                                            $d_idx = (int)date('w', strtotime($s['shift_date']));
                                            $d_str = $d_id[$d_idx];
                                        ?>
                                        <strong><?= $d_str ?>, <?= date('d', strtotime($s['shift_date'])) . ' ' . $m_str ?></strong>
                                        <div style="font-size: 0.7rem; color: #64748b; margin-top: 2px;"><?= $duration ?></div>
                                    </td>
                                    <td style="padding: 10px; font-weight: 600; color: #1e293b;">
                                        <?= htmlspecialchars($s['driver_name']) ?>
                                    </td>
                                    <td style="padding: 10px; text-align: center;">
                                        <?= substr($s['start_time'], 0, 5) ?> &rarr;<br>
                                        <strong><?= $s['end_time'] ? ($s['end_time'] === '00:00:00' ? '00:00' : substr($s['end_time'], 0, 5)) : '-' ?></strong>
                                    </td>
                                    <td style="padding: 10px; text-align: center; color: <?= $s['overtime_early'] > 0 ? '#3b82f6' : '#64748b' ?>;">
                                        <?php
                                            $totMinEarly = (int)round(floatval($s['overtime_early']) * 60);
                                            echo $totMinEarly > 0 ? str_pad(floor($totMinEarly/60),2,'0',STR_PAD_LEFT).':'.str_pad($totMinEarly%60,2,'0',STR_PAD_LEFT) : '-';
                                        ?>
                                    </td>
                                    <td style="padding: 10px; text-align: center; color: <?= $s['overtime_late'] > 0 ? '#3b82f6' : '#64748b' ?>;">
                                        <?php
                                            $totMinLate = (int)round(floatval($s['overtime_late']) * 60);
                                            echo $totMinLate > 0 ? str_pad(floor($totMinLate/60),2,'0',STR_PAD_LEFT).':'.str_pad($totMinLate%60,2,'0',STR_PAD_LEFT) : '-';
                                        ?>
                                    </td>
                                    <td style="padding: 10px; text-align: center; font-weight: bold; color: <?= ($s['ot_type'] ?? 'R') === 'H' ? '#dc2626' : '#475569' ?>;">
                                        <?= $s['ot_type'] ?? '-' ?>
                                    </td>
                                    <td style="padding: 10px; text-align: center; font-weight: <?= ($s['real_ot'] ?? 0) > 0 ? 'bold' : 'normal' ?>; color: <?= ($s['real_ot'] ?? 0) > 0 ? '#3b82f6' : '#64748b' ?>;">
                                        <?= ($s['real_ot'] ?? 0) > 0 ? (float)$s['real_ot'] : '-' ?>
                                    </td>
                                    <td style="padding: 10px; text-align: center; font-weight: <?= ($s['conv_ot'] ?? 0) > 0 ? 'bold' : 'normal' ?>; color: <?= ($s['conv_ot'] ?? 0) > 0 ? '#107c10' : '#64748b' ?>;">
                                        <?= ($s['conv_ot'] ?? 0) > 0 ? (float)$s['conv_ot'] : '-' ?>
                                    </td>
                                    <td style="padding: 10px; text-align: center; font-size: 0.75rem;">
                                        <?php if ($s['approval_status'] == 'approved'): ?>
                                            <span style="color: #166534; font-weight: 600;">✔ APRV</span>
                                            <div style="font-size:0.65rem; color:#64748b; margin-top:2px;"><?= htmlspecialchars($s['approved_by_name'] ?? '') ?></div>
                                        <?php else: ?>
                                            <span style="color: #b91c1c; font-weight: 600;">⏳ PEND</span>
                                        <?php endif; ?>
                                        <?php if(!empty($s['supervisor_note'])): ?>
                                            <div style="font-size:0.65rem; color:#f59e0b; margin-top:2px;" title="<?= htmlspecialchars($s['supervisor_note']) ?>">📝 Note</div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 10px; text-align: center;">
                                        <button class="btn-primary" style="padding: 4px 8px; font-size: 0.7rem; margin-bottom:4px;" onclick="viewSupTrips(<?= $s['id'] ?>)">Trips</button>
                                        <?php if ($s['approval_status'] !== 'approved'): ?>
                                            <button class="btn-primary" style="background:#10b981; padding: 4px 8px; font-size: 0.7rem;" onclick="approveOT(<?= $s['id'] ?>)">Approve</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div id="tab-expenses" class="tab-content" style="display:none;">
            <div style="background: #fff; padding: 16px; border-radius: 16px; border: 1px solid #e2e8f0; margin-bottom: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                <h3 style="margin-top:0; color: #0f172a;">Driver Expense Monitor</h3>
                <p style="margin-bottom:0; font-size: 0.85rem; color: #64748b;">Memantau biaya perjalanan dari driver di bawah Anda.</p>
            </div>
            
            <?php if (empty($supervisor_expenses)): ?>
                <div class="empty-state"><p>No expense data found.</p></div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="pbi-table" style="width: 100%; border-collapse: collapse; font-size: 0.8rem; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;">
                        <thead>
                            <tr style="background: rgba(0,0,0,0.02);">
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0; text-align: left; color: #64748b;">Tgl / Tujuan</th>
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0; text-align: left; color: #64748b;">Driver</th>
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0; text-align: center; color: #64748b;">Tipe</th>
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0; text-align: right; color: #64748b;">Nominal</th>
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0; text-align: center; color: #64748b;">Status</th>
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0; text-align: center; color: #64748b;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($supervisor_expenses as $e): ?>
                                <tr style="border-bottom: 1px solid #e2e8f0;">
                                    <td style="padding: 10px;">
                                        <strong><?= date('d/m/Y', strtotime($e['created_at'])) ?></strong>
                                        <div style="font-size: 0.7rem; color: #64748b; margin-top: 2px;"><?= htmlspecialchars($e['dest_name']) ?></div>
                                    </td>
                                    <td style="padding: 10px; font-weight: 600; color: #1e293b;">
                                        <?= htmlspecialchars($e['driver_name']) ?>
                                    </td>
                                    <td style="padding: 10px; text-align: center;">
                                        <span class="badge" style="background: rgba(0,0,0,0.05); font-size: 0.65rem; padding: 2px 6px; text-transform: uppercase;"><?= $e['expense_type'] ?></span>
                                    </td>
                                    <td style="padding: 10px; text-align: right; font-weight: bold; color: #0f172a;">
                                        Rp <?= number_format($e['amount'], 0, ',', '.') ?>
                                    </td>
                                    <td style="padding: 10px; text-align: center; font-size: 0.75rem;">
                                        <?php if ($e['approval_status'] == 'approved'): ?>
                                            <span style="color: #166534; font-weight: 600;">✔ APRV</span>
                                            <div style="font-size:0.65rem; color:#64748b; margin-top:2px;"><?= htmlspecialchars($e['approved_by_name'] ?? '') ?></div>
                                        <?php else: ?>
                                            <span style="color: #b91c1c; font-weight: 600;">⏳ PEND</span>
                                        <?php endif; ?>
                                        <?php if(!empty($e['supervisor_note'])): ?>
                                            <div style="font-size:0.65rem; color:#f59e0b; margin-top:2px;" title="<?= htmlspecialchars($e['supervisor_note']) ?>">📝 Note</div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 10px; text-align: center;">
                                        <button class="btn-primary" style="background:#f59e0b; padding: 4px 8px; font-size: 0.7rem;" onclick="addExpenseNote(<?= $e['id'] ?>)">+ Note</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div id="tab-settings" class="tab-content" style="display:none;">
            <div class="settings-card">
                <h3 class="settings-title"><?= __('language_setting') ?? 'Language' ?></h3>
                <div style="display: flex; gap: 10px; margin-bottom: 24px;">
                    <a href="?lang=en" class="btn-primary" style="text-align: center; text-decoration: none; background: <?= $_SESSION['lang'] === 'en' ? '#3b82f6' : '#e2e8f0' ?>; color: <?= $_SESSION['lang'] === 'en' ? '#fff' : '#475569' ?>;">English</a>
                    <a href="?lang=id" class="btn-primary" style="text-align: center; text-decoration: none; background: <?= $_SESSION['lang'] === 'id' ? '#3b82f6' : '#e2e8f0' ?>; color: <?= $_SESSION['lang'] === 'id' ? '#fff' : '#475569' ?>;">Bahasa Indonesia</a>
                </div>

                <hr style="border: 0; border-top: 1px dashed #e2e8f0; margin-bottom: 24px;">

                <h3 class="settings-title">Change Security PIN</h3>
                <div id="pinAlert" class="alert"></div>
                <form id="changePinForm" onsubmit="changePin(event)">
                    <div class="form-group">
                        <label class="form-label">Current PIN</label>
                        <input type="password" id="oldPin" class="form-input" pattern="\d{6}" maxlength="6" inputmode="numeric" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">New PIN (6 Digits)</label>
                        <input type="password" id="newPin" class="form-input" pattern="\d{6}" maxlength="6" inputmode="numeric" required>
                    </div>
                    <button type="submit" class="btn-primary" id="btnChangePin">Update PIN</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // PWA Install Prompt Logic
        let deferredPrompt;
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            if (!window.matchMedia('(display-mode: standalone)').matches && !window.navigator.standalone) {
                document.getElementById('pwa-install-banner').style.display = 'flex';
            }
        });

        async function triggerPWAInstall() {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            if (outcome === 'accepted') {
                console.log('User accepted the install prompt');
            }
            deferredPrompt = null;
            document.getElementById('pwa-install-banner').style.display = 'none';
        }

        function showTab(tabName, el) {
            localStorage.setItem('passengerActiveTab', tabName);
            document.querySelectorAll('.tab-content').forEach(t => t.style.display = 'none');
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            
            const targetTab = document.getElementById('tab-' + tabName);
            if (targetTab) targetTab.style.display = 'block';
            
            if (el) {
                el.classList.add('active');
            } else {
                // Find matching button on page load
                const buttons = document.querySelectorAll('.tab-btn');
                buttons.forEach(btn => {
                    if (btn.getAttribute('onclick') && btn.getAttribute('onclick').includes(tabName)) {
                        btn.classList.add('active');
                    }
                });
            }
        }

        // Restore tab on reload
        document.addEventListener('DOMContentLoaded', () => {
            const savedTab = localStorage.getItem('passengerActiveTab') || 'pending';
            showTab(savedTab);
        });

        async function processApproval(tripId, status) {
            const feedback = document.getElementById('feedback-' + tripId).value;
            const btnApprove = document.querySelector(`#trip-${tripId} .btn-approve`);
            const btnReject = document.querySelector(`#trip-${tripId} .btn-reject`);
            
            btnApprove.disabled = true;
            btnReject.disabled = true;
            if (status === 'approved') btnApprove.innerText = 'Processing...';

            const formData = new FormData();
            formData.append('trip_id', tripId);
            formData.append('status', status);
            formData.append('feedback', feedback);

            try {
                const res = await fetch('api_passenger_action.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    const card = document.getElementById('trip-' + tripId);
                    card.innerHTML = `<div style="text-align:center; padding: 30px;"><div style="font-size:3rem; margin-bottom:15px;">✅</div><h3 style="margin:0; color:#15803d; font-size:1.5rem;">Success!</h3><p style="color:#64748b; font-size:0.95rem; margin-top:10px;">Trip has been ${status}.</p></div>`;
                    setTimeout(() => { location.reload(); }, 1500);
                } else {
                    alert('Error: ' + data.error);
                    btnApprove.disabled = false; btnReject.disabled = false;
                    btnApprove.innerText = 'Approve Trip';
                }
            } catch (err) {
                alert('Connection error.');
                btnApprove.disabled = false; btnReject.disabled = false;
                btnApprove.innerText = 'Approve Trip';
            }
        }

        async function changePin(e) {
            e.preventDefault();
            const oldPin = document.getElementById('oldPin').value;
            const newPin = document.getElementById('newPin').value;
            const btn = document.getElementById('btnChangePin');
            const alertBox = document.getElementById('pinAlert');

            btn.disabled = true;
            btn.innerText = 'Updating...';
            alertBox.style.display = 'none';

            const formData = new FormData();
            formData.append('old_pin', oldPin);
            formData.append('new_pin', newPin);

            try {
                const res = await fetch('api_passenger_settings.php', { method: 'POST', body: formData });
                const data = await res.json();
                
                alertBox.style.display = 'block';
                if (data.success) {
                    alertBox.className = 'alert alert-success';
                    alertBox.innerText = 'PIN successfully updated!';
                    document.getElementById('changePinForm').reset();
                } else {
                    alertBox.className = 'alert alert-error';
                    alertBox.innerText = data.error;
                }
            } catch (err) {
                alertBox.style.display = 'block';
                alertBox.className = 'alert alert-error';
                alertBox.innerText = 'Connection error.';
            }
            
            btn.disabled = false;
            btn.innerText = 'Update PIN';
        }

        // Unapprove logic
        function confirmUnapprove(tripId) {
            Swal.fire({
                title: 'Cancel Approval?',
                text: 'This will move the trip back to your Pending tab for review.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3b82f6',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, Revert',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    processUnapprove(tripId);
                }
            });
        }

        async function processUnapprove(tripId) {
            Swal.fire({
                title: 'Processing...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            const formData = new FormData();
            formData.append('trip_id', tripId);
            formData.append('status', 'pending');

            try {
                const res = await fetch('api_passenger_action.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    Swal.fire({
                        title: 'Reverted!',
                        text: 'Trip has been reverted to Pending status.',
                        icon: 'success',
                        confirmButtonColor: '#3b82f6'
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.error || 'Failed to revert approval.', 'error');
                }
            } catch (err) {
                Swal.fire('Connection Error', 'Please check your connection.', 'error');
            }
        }

        // Image Viewer Modal functions
        function openImageViewer(src) {
            document.getElementById('fullImageView').src = src;
            document.getElementById('imageViewerModal').style.display = 'flex';
        }
        function closeImageViewer() {
            document.getElementById('imageViewerModal').style.display = 'none';
            document.getElementById('fullImageView').src = '';
        }
        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeImageViewer();
            }
        });
    </script>

    <!-- Image Viewer Modal (Full Size) -->
    <div id="imageViewerModal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); backdrop-filter: blur(5px); align-items: center; justify-content: center;" onclick="closeImageViewer()">
        <div style="background: rgba(0,0,0,0.9); margin: auto; padding: 12px; border-radius: 12px; width: auto; max-width: 95%; text-align: center; position: relative;" onclick="event.stopPropagation()">
            <button onclick="closeImageViewer()" style="position: absolute; right: 15px; top: 15px; background: rgba(255,255,255,0.25); border: none; border-radius: 50%; color: white; cursor: pointer; font-size: 1.5rem; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; z-index: 10;">×</button>
            <img id="fullImageView" src="" style="max-height: 80vh; max-width: 100%; border-radius: 6px; object-fit: contain; margin-top: 40px; display: block; margin-left: auto; margin-right: auto;">
        </div>
        </div>
    </div>

    <?php if ($is_supervisor): ?>
    <script>
        const supTripsByShift = <?= json_encode($sup_trips_by_shift) ?>;

        function viewSupTrips(shiftId) {
            const trips = supTripsByShift[shiftId] || [];
            let html = '';
            if (trips.length === 0) {
                html = '<p>No trips recorded for this shift.</p>';
            } else {
                html = '<div style="display:flex; flex-direction:column; gap:10px; text-align:left;">';
                trips.forEach(t => {
                    const startStr = t.start_time ? t.start_time.substring(11, 16) : '?';
                    const endStr = t.end_time ? t.end_time.substring(11, 16) : '?';
                    html += `
                        <div style="border:1px solid #e2e8f0; border-radius:8px; padding:10px; font-size:0.8rem;">
                            <div style="font-weight:bold; margin-bottom:5px;">Dest: ${t.dest_name}</div>
                            <div style="display:flex; justify-content:space-between; margin-bottom:3px;">
                                <span>Pass: ${t.pass_name}</span>
                                <span>Car: ${t.car_no}</span>
                            </div>
                            <div style="display:flex; justify-content:space-between; margin-bottom:3px; color:#64748b;">
                                <span>Time: ${startStr} - ${endStr}</span>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
            }
            Swal.fire({
                title: 'Trip Details',
                html: html,
                confirmButtonText: 'Close'
            });
        }

        function approveOT(shiftId) {
            Swal.fire({
                title: 'Approve Overtime',
                input: 'textarea',
                inputLabel: 'Add Note (Optional)',
                inputPlaceholder: 'Enter your note here...',
                showCancelButton: true,
                confirmButtonText: 'Approve',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'approve_ot');
                    formData.append('shift_id', shiftId);
                    formData.append('supervisor_note', result.value || '');
                    fetch('passenger_dashboard.php', { method: 'POST', body: formData })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                Swal.fire('Approved!', 'Overtime has been approved.', 'success').then(() => location.reload());
                            } else {
                                Swal.fire('Error', data.error, 'error');
                            }
                        });
                }
            });
        }

        function addExpenseNote(expenseId) {
            Swal.fire({
                title: 'Add Note to Expense',
                input: 'textarea',
                inputLabel: 'Supervisor Note',
                inputPlaceholder: 'Type your note here...',
                showCancelButton: true,
                confirmButtonText: 'Save Note',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'add_expense_note');
                    formData.append('expense_id', expenseId);
                    formData.append('supervisor_note', result.value || '');
                    fetch('passenger_dashboard.php', { method: 'POST', body: formData })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                Swal.fire('Saved!', 'Your note has been saved.', 'success').then(() => location.reload());
                            } else {
                                Swal.fire('Error', data.error, 'error');
                            }
                        });
                }
            });
        }
    </script>
    <?php endif; ?>
</body>
</html>
