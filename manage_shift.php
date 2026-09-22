<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

$driver_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

function calculateConvOT($ot_type, $real_ot) {
    if ($real_ot <= 0) return 0.00;
    
    if ($ot_type === 'H') {
        if ($real_ot <= 7.0) {
            return round($real_ot * 2.0, 2);
        } elseif ($real_ot <= 8.0) {
            return round(14.0 + (($real_ot - 7.0) * 3.0), 2);
        } else {
            return round(17.0 + (($real_ot - 8.0) * 4.0), 2);
        }
    } else {
        if ($real_ot <= 1.0) {
            return round($real_ot * 1.5, 2);
        } else {
            return round(1.5 + (($real_ot - 1.0) * 2.0), 2);
        }
    }
}

function calculateOvertime($pdo, $shift_date, $start_time, $end_date, $end_time) {
    $early = 0.00;
    $late = 0.00;
    
    $day_of_week = date('N', strtotime($shift_date));
    $stmt = $pdo->prepare("SELECT id FROM master_holidays WHERE holiday_date = ?");
    $stmt->execute([$shift_date]);
    $is_holiday = $stmt->fetch() ? true : false;
    
    $ot_type = ($day_of_week >= 6 || $is_holiday) ? 'H' : 'R';

    if ($ot_type === 'H') {
        $total_ot = 0.00;
        if ($start_time && $end_time) {
            $start_dt = new DateTime($shift_date . ' ' . $start_time);
            $end_dt = new DateTime($end_date . ' ' . $end_time);
            if ($end_dt < $start_dt) {
                $end_dt->modify('+1 day');
            }
            $diff = $end_dt->getTimestamp() - $start_dt->getTimestamp();
            $total_ot = max(0, $diff / 3600);
        }
    } else {
        if ($start_time) {
            $start_dt = new DateTime($shift_date . ' ' . $start_time);
            $limit_start = new DateTime($shift_date . ' 07:00:00');
            if ($start_dt < $limit_start) {
                $diff = $limit_start->getTimestamp() - $start_dt->getTimestamp();
                $early = max(0, $diff / 3600);
            }
        }
        
        if ($end_time) {
            $start_dt = new DateTime($shift_date . ' ' . $start_time);
            $end_dt = new DateTime($end_date . ' ' . $end_time);
            if ($end_dt < $start_dt) {
                $end_dt->modify('+1 day');
            }
            
            $limit_end = new DateTime($shift_date . ' 16:00:00');
            if ($end_dt->getTimestamp() > $limit_end->getTimestamp()) {
                $diff = $end_dt->getTimestamp() - $limit_end->getTimestamp();
                $late = max(0, $diff / 3600);
            }
        }
        $total_ot = $early + $late;
    }
    
    // Deduct break time:
    // - If end_time >= 23:00 or 00:00 -> 1.0 hr (60 mins)
    // - Else if total_ot >= 3.5 hrs -> 0.5 hr (30 mins)
    $break_hours = 0.0;
    $end_hour = $end_time ? intval(substr($end_time, 0, 2)) : 0;
    if ($end_hour >= 23 || substr($end_time, 0, 5) === '00:00') {
        if ($total_ot >= 3.5) {
            $break_hours = 1.0;
        }
    } elseif ($total_ot >= 3.5) {
        $break_hours = 0.5;
    }

    $net_ot = max(0, $total_ot - $break_hours);

    if ($early > 23 || $late > 23 || $total_ot > 23) {
        $real_ot = 0.00;
    } else {
        $real_ot = round($net_ot, 2);
    }
    
    return [$real_ot, $ot_type, round($early, 2), round($late, 2)];
}

if ($action === 'clock_in') {
    // Check if already has an active shift
    $stmt = $pdo->prepare("SELECT id FROM shifts WHERE driver_id = ? AND status = 'active'");
    $stmt->execute([$driver_id]);
    if (!$stmt->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO shifts (driver_id, shift_date, start_time, status) VALUES (?, CURDATE(), CURTIME(), 'active')");
        $stmt->execute([$driver_id]);
    }
} elseif ($action === 'clock_out') {
    // Get active shift start_time and shift_date
    $stmt = $pdo->prepare("SELECT id, shift_date, start_time FROM shifts WHERE driver_id = ? AND status = 'active'");
    $stmt->execute([$driver_id]);
    $active = $stmt->fetch();
    
    if ($active) {
        $end_date = date('Y-m-d');
        $end_time = date('H:i:s');
        list($real_ot, $ot_type, $early, $late) = calculateOvertime($pdo, $active['shift_date'], $active['start_time'], $end_date, $end_time);
        
        $has_ot = ($real_ot > 0);
        $approval_status = $has_ot ? 'pending' : 'approved';
        $approved_at = $has_ot ? null : date('Y-m-d H:i:s');
        
        $conv_ot = calculateConvOT($ot_type, $real_ot);
        
        $stmt = $pdo->prepare("UPDATE shifts 
                               SET end_time = ?, status = 'completed', approval_status = ?, approved_at = ?, overtime_early = ?, overtime_late = ?, real_ot = ?, ot_type = ?, conv_ot = ? 
                               WHERE id = ?");
        $stmt->execute([$end_time, $approval_status, $approved_at, $early, $late, $real_ot, $ot_type, $conv_ot, $active['id']]);
    }
} elseif ($action === 'recalculate_ot') {
    $target_driver_id = $driver_id;
    if (isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'supervisor')) {
        $target_driver_id = !empty($_POST['driver_id']) ? intval($_POST['driver_id']) : 0;
    }

    $start_date = $_POST['start_date'] ?? date('Y-m-01');
    $end_date   = $_POST['end_date'] ?? date('Y-m-d');

    $query = "SELECT id, shift_date, start_time, end_time, approval_status, approved_by, approved_at FROM shifts WHERE status = 'completed' AND shift_date BETWEEN ? AND ?";
    $params = [$start_date, $end_date];

    if ($target_driver_id > 0) {
        $query .= " AND driver_id = ?";
        $params[] = $target_driver_id;
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $shifts_to_recalc = $stmt->fetchAll();

    $updated_count = 0;
    foreach ($shifts_to_recalc as $s) {
        if (!$s['start_time'] || !$s['end_time'] || $s['end_time'] === '00:00:00') {
            continue;
        }
        $s_date = $s['shift_date'];
        $e_date = $s_date;
        if (strtotime($s['end_time']) < strtotime($s['start_time'])) {
            $e_date = date('Y-m-d', strtotime($s_date . ' +1 day'));
        }

        list($real_ot, $ot_type, $early, $late) = calculateOvertime($pdo, $s_date, $s['start_time'], $e_date, $s['end_time']);
        $conv_ot = calculateConvOT($ot_type, $real_ot);

        $has_ot = ($real_ot > 0);
        $app_status = $s['approval_status'];
        $app_at = $s['approved_at'];

        if ($has_ot) {
            // If shift now has OT (>0) and was not manually approved by a supervisor, reset to pending
            if (empty($s['approved_by'])) {
                $app_status = 'pending';
                $app_at = null;
            }
        } else {
            // If shift has 0 OT, auto-approve
            $app_status = 'approved';
            if (!$app_at) $app_at = date('Y-m-d H:i:s');
        }

        $up_stmt = $pdo->prepare("UPDATE shifts SET overtime_early = ?, overtime_late = ?, real_ot = ?, ot_type = ?, conv_ot = ?, approval_status = ?, approved_at = ? WHERE id = ?");
        $up_stmt->execute([$early, $late, $real_ot, $ot_type, $conv_ot, $app_status, $app_at, $s['id']]);
        $updated_count++;
    }

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest' || (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'count' => $updated_count, 'msg' => "{$updated_count} shift berhasil dihitung ulang"]);
        exit;
    }

    header('Location: index.php?tab=attendance');
    exit;
}

header('Location: index.php');
exit;
?>
