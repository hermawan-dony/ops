<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'driver') {
    exit('Unauthorized');
}

$driver_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

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
            $early_threshold = new DateTime($shift_date . ' 06:00:00');
            if ($start_dt <= $early_threshold) {
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
            
            $limit_end = new DateTime($shift_date . ' 15:30:00');
            $late_threshold = new DateTime($shift_date . ' 16:30:00');
            if ($end_dt->getTimestamp() >= $late_threshold->getTimestamp()) {
                $diff = $end_dt->getTimestamp() - $limit_end->getTimestamp();
                $late = max(0, $diff / 3600);
            }
        }
        $total_ot = $early + $late;
    }
    
    if ($early > 12 || $late > 12 || $total_ot > 12) {
        $real_ot = 0;
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
    
    return [$real_ot, $ot_type];
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
        list($real_ot, $ot_type) = calculateOvertime($pdo, $active['shift_date'], $active['start_time'], $end_date, $end_time);
        
        $has_ot = ($real_ot > 0);
        $approval_status = $has_ot ? 'pending' : 'approved';
        $approved_at = $has_ot ? null : date('Y-m-d H:i:s');
        
        $conv_ot = 0;
        if ($real_ot > 0) {
            $stmt_conv = $pdo->prepare("SELECT conv_ot FROM twotcon WHERE tipe = ? AND real_ot = ?");
            $stmt_conv->execute([$ot_type, $real_ot]);
            $row_conv = $stmt_conv->fetch();
            if ($row_conv && $row_conv['conv_ot'] !== null) {
                $conv_ot = (float)$row_conv['conv_ot'];
            }
        }
        
        $stmt = $pdo->prepare("UPDATE shifts 
                               SET end_time = ?, status = 'completed', approval_status = ?, approved_at = ?, real_ot = ?, ot_type = ?, conv_ot = ? 
                               WHERE id = ?");
        $stmt->execute([$end_time, $approval_status, $approved_at, $real_ot, $ot_type, $conv_ot, $active['id']]);
    }
}

header('Location: index.php');
exit;
?>
