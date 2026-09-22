<?php
require_once 'config.php';

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

// Secure API bypass for PowerBuilder direct HRIS export
if (isset($_GET['action']) && $_GET['action'] === 'get_hris_tsv') {
    $secret = $_GET['secret'] ?? '';
    if ($secret !== 'framas_pay_key_2026') {
        header('HTTP/1.1 403 Forbidden');
        echo "Unauthorized access.";
        exit;
    }
} else {
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        header('Location: login.php'); exit;
    }
}

$today_day = intval(date('j'));
if ($today_day > 20) {
    $default_start_date = date('Y-m-21');
    $default_end_date = date('Y-m-20', strtotime('+1 month'));
} else {
    $default_start_date = date('Y-m-21', strtotime('-1 month'));
    $default_end_date = date('Y-m-20');
}

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

    $label = $months_full[$m] . ' ' . $y;
    $is_selected = ($p_start === $default_start_date && $p_end === $default_end_date);

    $payroll_periods[] = [
        'label' => $label,
        'start_date' => $p_start,
        'end_date' => $p_end,
        'val' => $p_start . '|' . $p_end,
        'selected' => $is_selected
    ];
}

// AJAX handler to get report data
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'get_data') {
        $driver_id = $_POST['driver_id'] ?? 'ALL';
        $supervisor_id = $_POST['supervisor_id'] ?? 'ALL';
        $start_date = $_POST['start_date'] ?? $default_start_date;
        $end_date = $_POST['end_date'] ?? $default_end_date;
        
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
                    MAX(s.note_status) as note_status,
                    MAX(s.last_note) as last_note,
                    MAX(s.hris_note) as hris_note,
                    MAX(s.hris_note_by) as hris_note_by,
                    MAX(s.hris_note_at) as hris_note_at,
                    MAX(a.full_name) as approver_name,
                    (SELECT GROUP_CONCAT(t.id ORDER BY t.id ASC) 
                     FROM trips t 
                     JOIN shifts s2 ON t.shift_id = s2.id 
                     WHERE s2.driver_id = s.driver_id AND s2.shift_date = s.shift_date
                    ) as tx_ids,
                    p.name as supervisor_name,
                    p.email as supervisor_email,
                    p.id as supervisor_id
                FROM shifts s 
                JOIN users u ON s.driver_id = u.id 
                LEFT JOIN users a ON s.approved_by = a.id
                LEFT JOIN master_passengers p ON u.supervisor_id = p.id
                WHERE s.shift_date BETWEEN ? AND ?";
        $params = [$start_date, $end_date];
        
        if ($driver_id !== 'ALL') {
            $sql .= " AND u.id = ?";
            $params[] = $driver_id;
        }
        
        if ($supervisor_id !== 'ALL') {
            $sql .= " AND u.supervisor_id = ?";
            $params[] = $supervisor_id;
        }
        
        $sql .= " GROUP BY s.driver_id, s.shift_date";
        $sql .= " ORDER BY s.shift_date ASC, u.full_name ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    } elseif ($_GET['action'] === 'get_shift_comments') {
        header('Content-Type: application/json');
        $shift_id = intval($_GET['shift_id'] ?? $_POST['shift_id'] ?? 0);
        if ($shift_id) {
            $stmt = $pdo->prepare("SELECT * FROM shift_comments WHERE shift_id = ? ORDER BY created_at ASC");
            $stmt->execute([$shift_id]);
            $comments = $stmt->fetchAll();
            echo json_encode(['success' => true, 'comments' => $comments]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid shift ID']);
        }
        exit;
    } elseif ($_GET['action'] === 'admin_add_shift_comment') {
        header('Content-Type: application/json');
        try {
            $shift_id = intval($_POST['shift_id'] ?? 0);
            $comment = trim($_POST['comment'] ?? '');
            $admin_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
            $admin_id = $_SESSION['user_id'] ?? null;
            if (!$shift_id || empty($comment)) {
                echo json_encode(['success' => false, 'error' => 'Comment cannot be empty.']);
                exit;
            }

            $stmt_c = $pdo->prepare("INSERT INTO shift_comments (shift_id, user_type, user_id, user_name, comment) VALUES (?, 'admin', ?, ?, ?)");
            $stmt_c->execute([$shift_id, $admin_id, $admin_name, $comment]);

            $stmt_u = $pdo->prepare("UPDATE shifts SET last_note = ?, note_status = 'replied_admin' WHERE id = ?");
            $stmt_u->execute([$comment, $shift_id]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    } elseif ($_GET['action'] === 'save_hris_note') {
        header('Content-Type: application/json');
        try {
            $shift_id = intval($_POST['shift_id'] ?? 0);
            $driver_id = intval($_POST['driver_id'] ?? 0);
            $shift_date = trim($_POST['shift_date'] ?? '');
            $note = trim($_POST['note'] ?? '');
            $admin_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
            $admin_id = $_SESSION['user_id'] ?? null;

            if (!$shift_date) {
                echo json_encode(['success' => false, 'error' => 'Shift date is required.']);
                exit;
            }

            if ($driver_id > 0) {
                $stmt = $pdo->prepare("UPDATE shifts SET hris_note = ?, hris_note_by = ?, hris_note_at = IF(? = '', NULL, NOW()) WHERE driver_id = ? AND shift_date = ?");
                $stmt->execute([$note !== '' ? $note : null, $note !== '' ? $admin_name : null, $note, $driver_id, $shift_date]);
            } else if ($shift_id > 0) {
                $stmt = $pdo->prepare("UPDATE shifts SET hris_note = ?, hris_note_by = ?, hris_note_at = IF(? = '', NULL, NOW()) WHERE id = ?");
                $stmt->execute([$note !== '' ? $note : null, $note !== '' ? $admin_name : null, $note, $shift_id]);
            }

            // Also log in shift_comments if note is not empty
            if (!empty($note) && $shift_id > 0) {
                $stmt_c = $pdo->prepare("INSERT INTO shift_comments (shift_id, user_type, user_id, user_name, comment) VALUES (?, 'admin', ?, ?, ?)");
                $stmt_c->execute([$shift_id, $admin_id, $admin_name, "[Konfirmasi HRIS] " . $note]);
            }

            echo json_encode([
                'success' => true,
                'note' => $note,
                'note_by' => $admin_name,
                'note_at' => date('Y-m-d H:i')
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
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
        $ot_early = floatval($_POST['overtime_early'] ?? 0.00);
        $ot_late = floatval($_POST['overtime_late'] ?? 0.00);
        
        $gross_ot = $ot_early + $ot_late;
        
        // Break deduction rule:
        // - if end_time >= 23:00 or 00:00 -> 1.0 hour (60 mins)
        // - else if gross_ot >= 3.5 hours -> 0.5 hour (30 mins)
        $break_hours = 0.0;
        $end_hour = $end_time ? intval(substr($end_time, 0, 2)) : 0;
        if ($end_hour >= 23 || substr($end_time, 0, 5) === '00:00') {
            if ($gross_ot >= 3.5) {
                $break_hours = 1.0;
            }
        } elseif ($gross_ot >= 3.5) {
            $break_hours = 0.5;
        }
        
        $real_ot = round(max(0, $gross_ot - $break_hours), 2);
        
        // Fetch ot_type for conv_ot calculation
        $stmt_shift = $pdo->prepare("SELECT ot_type FROM shifts WHERE id = ?");
        $stmt_shift->execute([$shift_id]);
        $ot_type = $stmt_shift->fetchColumn() ?: 'R';
        
        $conv_ot = calculateConvOT($ot_type, $real_ot);
        
        if ($shift_id) {
            $stmt = $pdo->prepare("UPDATE shifts 
                                   SET start_time = ?, end_time = ?, overtime_early = ?, overtime_late = ?, real_ot = ?, conv_ot = ?, is_edited = 1, approval_status = 'approved' 
                                   WHERE id = ?");
            $stmt->execute([$start_time, $end_time ? $end_time : null, $ot_early, $ot_late, $real_ot, $conv_ot, $shift_id]);
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
            
            $sql = "SELECT driver_id, shift_date, MIN(start_time) as start_time, MAX(end_time) as end_time, GROUP_CONCAT(id ORDER BY id DESC) as shift_ids FROM shifts WHERE shift_date BETWEEN ? AND ?";
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
                    if ($s['start_time'] && $s['start_time'] < '07:00:00') {
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
                        
                        $limit_dt = new DateTime($s['shift_date'] . ' 16:00:00');
                        if ($end_dt->getTimestamp() > $limit_dt->getTimestamp()) {
                            $diff = $end_dt->getTimestamp() - $limit_dt->getTimestamp();
                            $ot_late = round(max(0, $diff / 3600), 2);
                        }
                    }
                    $total_ot = $ot_early + $ot_late;
                }
                // Deduct break time:
                // - If end_time >= 23:00 or 00:00 -> 1.0 hr (60 mins)
                // - Else if total_ot >= 3.5 hrs -> 0.5 hr (30 mins)
                $break_hours = 0.0;
                $end_hour = $s['end_time'] ? intval(substr($s['end_time'], 0, 2)) : 0;
                if ($end_hour >= 23 || substr($s['end_time'], 0, 5) === '00:00') {
                    if ($total_ot >= 3.5) {
                        $break_hours = 1.0;
                    }
                } elseif ($total_ot >= 3.5) {
                    $break_hours = 0.5;
                }

                $net_ot = max(0, $total_ot - $break_hours);

                if ($ot_early > 23 || $ot_late > 23 || $total_ot > 23) {
                    $real_ot = 0.00;
                } else {
                    $real_ot = round($net_ot, 2);
                }
                
                $conv_ot = calculateConvOT($ot_type, $real_ot);
                    
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
    } elseif ($_GET['action'] === 'send_teams_notification') {
        $supervisor_id = intval($_POST['supervisor_id'] ?? 0);
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        
        if ($supervisor_id && $start_date && $end_date) {
            // Find supervisor info
            $stmt = $pdo->prepare("SELECT * FROM master_passengers WHERE id = ?");
            $stmt->execute([$supervisor_id]);
            $supervisor = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($supervisor) {
                if (empty($supervisor['email'])) {
                    echo json_encode(['success' => false, 'message' => 'Supervisor does not have an email address configured.']);
                    exit;
                }
                
                // Get all pending shifts for this supervisor's drivers in the date range
                $sql = "SELECT s.*, u.full_name as driver_name 
                        FROM shifts s 
                        JOIN users u ON s.driver_id = u.id 
                        WHERE u.supervisor_id = ? AND s.shift_date BETWEEN ? AND ? AND s.approval_status = 'pending' AND s.real_ot > 0 
                        ORDER BY s.shift_date ASC, u.full_name ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$supervisor_id, $start_date, $end_date]);
                $pending_shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($pending_shifts)) {
                    echo json_encode(['success' => false, 'message' => 'No pending overtime shifts found for this supervisor in this period.']);
                    exit;
                }
                
                // Group shifts by driver and calculate summary
                $driver_summary = [];
                foreach ($pending_shifts as $s) {
                    $dname = $s['driver_name'];
                    if (!isset($driver_summary[$dname])) {
                        $driver_summary[$dname] = ['hours' => 0.00, 'shifts_count' => 0];
                    }
                    $driver_summary[$dname]['hours'] += floatval($s['real_ot']);
                    $driver_summary[$dname]['shifts_count']++;
                }
                
                $secret_key = 'framas_shift_secret_2026';
                $token = md5($supervisor_id . $start_date . $end_date . $secret_key);
                $approval_url = "https://ops.framas.co.id/passenger_dashboard.php?supervisor_id=" . $supervisor_id . "&start_date=" . urlencode($start_date) . "&end_date=" . urlencode($end_date) . "&token=" . $token;
                $start_fmt = date('d M Y', strtotime($start_date));
                $end_fmt = date('d M Y', strtotime($end_date));
                $total_hours = 0.00;
                foreach ($driver_summary as $info) {
                    $total_hours += $info['hours'];
                }

                $msg = "Dear <b>" . htmlspecialchars($supervisor['name']) . "</b>,<br><br>";
                $msg .= "Please review and approve the driver overtime claims for the period (<b>" . $start_fmt . " to " . $end_fmt . "</b>):<br><br>";
                foreach ($driver_summary as $dname => $info) {
                    $day_label = $info['shifts_count'] > 1 ? "days" : "day";
                    $msg .= "• <b>" . htmlspecialchars($dname) . "</b>: " . number_format($info['hours'], 2) . " Hours (" . $info['shifts_count'] . " " . $day_label . ")<br>";
                }
                $shift_label = count($pending_shifts) > 1 ? "pending shifts" : "pending shift";
                $msg .= "<br>Total: <b>" . number_format($total_hours, 2) . " Hours</b> (" . count($pending_shifts) . " " . $shift_label . ").<br><br>";
                $msg .= "👉 Please click the link below to review and approve:<br>";
                $msg .= "<a href=\"" . $approval_url . "\"><b>Open Overtime Approval Dashboard</b></a><br><br>";
                $msg .= "<small><i>Automated notification from Framas Transport System.</i></small>";
                
                $teams_api_url = "https://api.framas.web.id/framas-api/teams/send.php?to=" . urlencode($supervisor['email']) . "&msg=" . urlencode($msg);
                
                // Call teams sending service via GET
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
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Teams API returned error status code: ' . $http_code]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Supervisor not found.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
        }
        exit;
    } elseif ($_GET['action'] === 'get_hris_tsv') {
        $start_date = $_GET['start_date'] ?? '';
        $end_date = $_GET['end_date'] ?? '';
        $emp_cd = $_GET['emp_cd'] ?? ''; // NIK filter
        
        if (!$start_date || !$end_date) {
            header('HTTP/1.1 400 Bad Request');
            echo "Missing start_date or end_date";
            exit;
        }
        
        // Fetch approved overtime shifts in the date range
        $sql = "SELECT 
                    s.id as shift_id,
                    s.driver_id,
                    s.shift_date,
                    s.start_time,
                    s.end_time,
                    u.full_name as driver_name,
                    u.nik,
                    s.overtime_early,
                    s.overtime_late,
                    s.real_ot,
                    s.conv_ot,
                    s.ot_type,
                    p.name as supervisor_name
                FROM shifts s 
                JOIN users u ON s.driver_id = u.id 
                LEFT JOIN master_passengers p ON u.supervisor_id = p.id
                WHERE s.shift_date BETWEEN ? AND ? AND s.approval_status = 'approved' AND s.real_ot > 0";
                
        $params = [$start_date, $end_date];
        if ($emp_cd !== '') {
            $sql .= " AND u.nik = ?";
            $params[] = $emp_cd;
        }
        
        $sql .= " ORDER BY s.shift_date ASC, u.full_name ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: text/plain; charset=utf-8');
        
        // Output headers in TSV format for 13 columns
        echo implode("\t", ["trn_date", "srl_no", "emp_cd", "emp_nm", "sec_cd", "bef_time_fr", "bef_time_to", "time_fr", "time_to", "break_time", "time_total", "transport_amt", "remarks"]) . "\r\n";
        
        foreach ($data as $idx => $r) {
            $trn_date = $r['shift_date'];
            $srl_no = $idx + 1;
            $emp_cd = $r['nik'] ? $r['nik'] : '-';
            $emp_nm = $r['driver_name'];
            $sec_cd = "2009";
            
            $earlyHours = floatval($r['overtime_early']);
            $lateHours = floatval($r['overtime_late']);
            $real_ot = floatval($r['real_ot']);
            $is_holiday = ($r['ot_type'] === 'H');
            
            // Early OT Range: Use actual Jam Masuk (start_time) and 07:00
            $bef_time_fr = '';
            $bef_time_to = '';
            if ($earlyHours > 0) {
                $bef_time_fr = $r['start_time'] ? substr($r['start_time'], 0, 5) : '05:00';
                $bef_time_to = '07:00';
                if ($bef_time_fr === '00:00') $bef_time_fr = '00:01';
            }
            
            // Late OT Range: Use 16:00 and actual Jam Pulang (end_time)
            $time_fr = '';
            $time_to = '';
            if ($lateHours > 0 || $is_holiday) {
                if ($is_holiday) {
                    $time_fr = $r['start_time'] ? substr($r['start_time'], 0, 5) : '';
                    $time_to = $r['end_time'] ? substr($r['end_time'], 0, 5) : '';
                } else {
                    $time_fr = '16:00';
                    $time_to = $r['end_time'] ? substr($r['end_time'], 0, 5) : '16:00';
                }
                
                // User directive: If midnight 00:00, change to 00:01
                if ($time_to === '00:00') {
                    $time_to = '00:01';
                }
            }
            
            // Break Time Rule:
            // - if OT end time >= 23:00 or 00:01 -> 60 mins break
            // - if OT >= 3.5 hours -> 30 mins break
            $end_hour = 0;
            if ($time_to !== '') {
                $end_hour = intval(substr($time_to, 0, 2));
            }
            
            $break_time = '0.00';
            if ($end_hour >= 23 || $time_to === '00:01' || ($r['end_time'] && intval(substr($r['end_time'], 0, 2)) >= 23)) {
                $break_time = '60.00';
            } elseif ($real_ot >= 3.5) {
                $break_time = '30.00';
            } else {
                $break_time = '0.00';
            }
            
            $time_total = number_format($earlyHours + $lateHours, 2, '.', '');
            
            // Transport Amount Rule:
            // - Early OT < 07:00 -> 20,000
            // - Late OT > 16:00 -> 20,000
            // - Both Early & Late OT -> 40,000
            // - Holiday Shift -> 40,000
            $transport_amt = '0.00';
            if ($is_holiday) {
                $transport_amt = '40000.00';
            } else {
                if ($earlyHours > 0 && $lateHours > 0) {
                    $transport_amt = '40000.00';
                } elseif ($earlyHours > 0 || $lateHours > 0) {
                    $transport_amt = '20000.00';
                }
            }
            
            $remarks = $r['supervisor_name'] ? $r['supervisor_name'] : '';
            
            echo implode("\t", [
                $trn_date, $srl_no, $emp_cd, $emp_nm, $sec_cd,
                $bef_time_fr, $bef_time_to, $time_fr, $time_to,
                $break_time, $time_total, $transport_amt, $remarks
            ]) . "\r\n";
        }
        exit;
    }
}

$drivers = $pdo->query("SELECT * FROM users WHERE role = 'driver' ORDER BY full_name ASC")->fetchAll();
$supervisors = $pdo->query("SELECT DISTINCT p.id, p.name FROM master_passengers p JOIN users u ON u.supervisor_id = p.id ORDER BY p.name ASC")->fetchAll(PDO::FETCH_ASSOC);
$pending_notes_count = $pdo->query("SELECT COUNT(*) FROM shifts WHERE note_status = 'pending_admin'")->fetchColumn() ?: 0;
$show_note_popup = false;
if ($pending_notes_count > 0 && empty($_SESSION['note_popup_shown'])) {
    $show_note_popup = true;
    $_SESSION['note_popup_shown'] = true;
}
$is_collapsed = isset($_SESSION['sidebar_collapsed']) && $_SESSION['sidebar_collapsed'];
$theme = $_SESSION['theme'] ?? 'light';
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang']; ?>" class="<?php echo $theme === 'dark' ? 'dark-mode' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo __('attendance_report'); ?> - framas Transport App</title>
    <link rel="icon" type="image/png" href="icon.png">
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
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

    <?php include 'sidemenu.php'; ?>

    <div class="main-content">
        <h2 style="margin-bottom: 16px; font-size: 1.5rem;"><?php echo __('attendance_report'); ?></h2>

        <div style="display: flex; flex-direction: column; gap: 16px;">
            <div class="report-filter-card">
                <form id="reportForm" style="display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap;">
                    <div class="pbi-form-group">
                        <label class="pbi-label">Select Supervisor</label>
                        <select id="supervisor_id" class="pbi-input">
                            <option value="ALL">[ ALL SUPERVISORS ]</option>
                            <?php foreach($supervisors as $sv): ?>
                                <option value="<?php echo $sv['id']; ?>"><?php echo htmlspecialchars($sv['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pbi-form-group">
                        <label class="pbi-label">Select Driver</label>
                        <select id="driver_id" class="pbi-input" onchange="updateMiniDashboard()">
                            <option value="ALL">[ ALL DRIVERS ]</option>
                            <?php foreach($drivers as $d): ?>
                                <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pbi-form-group" style="min-width: 170px;">
                        <label class="pbi-label">📅 <?php echo $_SESSION['lang'] == 'id' ? 'Periode' : 'Period'; ?></label>
                        <select id="period_select" class="pbi-input" onchange="onPeriodSelectChange(this)" style="font-weight: 600;">
                            <?php 
                            $has_matched_period = false;
                            foreach ($payroll_periods as $p): 
                                if ($p['selected']) $has_matched_period = true;
                            ?>
                                <option value="<?php echo $p['val']; ?>" <?php echo $p['selected'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['label']); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="custom" <?php echo !$has_matched_period ? 'selected' : ''; ?> disabled style="display: <?php echo !$has_matched_period ? 'block' : 'none'; ?>;">-- <?php echo $_SESSION['lang'] == 'id' ? 'Kustom Tanggal' : 'Custom Date'; ?> --</option>
                        </select>
                    </div>
                    <div class="pbi-form-group" style="max-width: 140px;">
                        <label class="pbi-label"><?php echo $_SESSION['lang'] == 'id' ? 'Tanggal Mulai' : 'Start Date'; ?></label>
                        <input type="date" id="start_date" class="pbi-input" value="<?php echo $default_start_date; ?>" onchange="checkCustomPeriod()">
                    </div>
                    <div class="pbi-form-group" style="max-width: 140px;">
                        <label class="pbi-label"><?php echo $_SESSION['lang'] == 'id' ? 'Tanggal Akhir' : 'End Date'; ?></label>
                        <input type="date" id="end_date" class="pbi-input" value="<?php echo $default_end_date; ?>" onchange="checkCustomPeriod()">
                    </div>
                    <button type="button" onclick="generateReport()" class="btn-generate"><?php echo $_SESSION['lang'] == 'id' ? 'Buat Laporan' : 'Generate Report'; ?></button>
                    <button type="button" onclick="recalculateOvertime()" class="btn-generate" style="background: #854d0e; margin-left: 8px;"><?php echo $_SESSION['lang'] == 'id' ? 'Hitung Ulang' : 'Recalculate'; ?></button>
                </form>
            </div>

            <div id="resultsBox" class="results-box">
                <!-- Rekap Mini Dashboard Driver (Tampil jika 1 Driver dipilih) -->
                <div id="driverMiniDashboard" style="display: none; margin-bottom: 20px; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #fff; padding: 18px 24px; border-radius: 12px; box-shadow: 0 8px 20px rgba(0,0,0,0.15); border: 1px solid rgba(255,255,255,0.1);">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                        <div style="display: flex; align-items: center; gap: 14px;">
                            <div style="width: 46px; height: 46px; border-radius: 50%; background: var(--pbi-blue); display: flex; align-items: center; justify-content: center; font-size: 1.4rem; font-weight: bold; color: #fff; box-shadow: 0 4px 10px rgba(17,141,255,0.3);">
                                👤
                            </div>
                            <div>
                                <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.8px; color: #94a3b8; font-weight: 600;"><?php echo $_SESSION['lang'] == 'id' ? 'REKAP LEMBUR DRIVER' : 'DRIVER OVERTIME SUMMARY'; ?></div>
                                <div id="dashDriverName" style="font-size: 1.25rem; font-weight: 700; color: #f8fafc;">-</div>
                            </div>
                        </div>

                        <div style="display: flex; gap: 16px; flex-wrap: wrap; align-items: center;">
                            <!-- Stat 1: Hari Lembur -->
                            <div style="background: rgba(255,255,255,0.06); padding: 10px 20px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.1); text-align: center; min-width: 120px;">
                                <div style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; margin-bottom: 4px;"><?php echo $_SESSION['lang'] == 'id' ? 'Hari Lembur' : 'OT Days'; ?></div>
                                <div id="dashOtDays" style="font-size: 1.4rem; font-weight: 800; color: #38bdf8;">0 <span style="font-size: 0.85rem; font-weight: 500;"><?php echo $_SESSION['lang'] == 'id' ? 'Hari' : 'Days'; ?></span></div>
                            </div>

                            <!-- Stat 2: Total OT (Jam) -->
                            <div style="background: rgba(255,255,255,0.06); padding: 10px 20px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.1); text-align: center; min-width: 140px;">
                                <div style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; margin-bottom: 4px;"><?php echo $_SESSION['lang'] == 'id' ? 'Total OT (Jam)' : 'Total OT (Hrs)'; ?></div>
                                <div id="dashTotalOt" style="font-size: 1.4rem; font-weight: 800; color: #4ade80;">0.00 <span style="font-size: 0.85rem; font-weight: 500;">Jam</span></div>
                            </div>

                            <!-- Stat 3: Belum di-Approve -->
                            <div id="dashPendingCard" style="background: rgba(255,255,255,0.06); padding: 10px 20px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.1); text-align: center; min-width: 140px;">
                                <div style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; margin-bottom: 4px;"><?php echo $_SESSION['lang'] == 'id' ? 'Belum Approved' : 'Pending Approval'; ?></div>
                                <div id="dashPendingDays" style="font-size: 1.4rem; font-weight: 800; color: #fbbf24;">0 <span style="font-size: 0.85rem; font-weight: 500;"><?php echo $_SESSION['lang'] == 'id' ? 'Hari' : 'Days'; ?></span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; align-items: center; margin-bottom: 20px;">
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button onclick="bulkApprove('approved')" class="btn-export" style="background: #166534; margin-bottom: 5px;"><?php echo $_SESSION['lang'] == 'id' ? 'Setujui Terpilih' : 'Approve Selected'; ?></button>
                        <button onclick="bulkApprove('pending')" class="btn-export" style="background: #b91c1c; margin-bottom: 5px;"><?php echo $_SESSION['lang'] == 'id' ? 'Batalkan Setuju' : 'Unapprove Selected'; ?></button>
                        <button onclick="bulkNotifyTeams()" class="btn-export" style="background: #0284c7; margin-bottom: 5px;"><?php echo $_SESSION['lang'] == 'id' ? 'Kirim Teams Terpilih' : 'Notify Selected via Teams'; ?></button>
                        <button onclick="exportToPDF()" class="btn-export" style="background: #e11d48; margin-bottom: 5px;">Export PDF</button>
                        <button onclick="exportToExcelSeparate()" class="btn-export" style="background: #107c10; margin-bottom: 5px;">Export Excel (Per Driver)</button>
                        <button onclick="exportToExcelCombined()" class="btn-export" style="background: #0078d4; margin-bottom: 5px;">Export Excel (All Drivers)</button>
                        <button onclick="exportToHRIS()" class="btn-export" style="background: #ea580c; margin-bottom: 5px;">Export HRIS</button>
                        <button type="button" id="btnAdminCompareHris" onclick="toggleAdminHrisCompare()" class="btn-export" style="background: #0284c7; margin-bottom: 5px; font-weight: 700;">🔍 Compare HRIS</button>
                        <span id="hrisDiffCountBadge" onclick="quickFilterHrisDiff()" style="display: none; cursor: pointer; border-radius: 4px; padding: 6px 12px; font-size: 0.8rem; font-weight: 700; margin-bottom: 5px; align-items: center; gap: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: all 0.2s;" title="<?php echo $_SESSION['lang'] == 'id' ? 'Klik untuk filter hanya baris selisih' : 'Click to filter discrepancy rows only'; ?>"></span>
                    </div>
                </div>

                <!-- DANGER ALERT BANNER: Tampil jika ditemukan ada shift dengan HRIS = 0 padahal ada lembur -->
                <div id="hrisDangerAlertBanner" style="display: none; background: #fef2f2; border: 2px solid #ef4444; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; color: #991b1b; box-shadow: 0 4px 12px rgba(239,68,68,0.12);">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <span style="font-size: 1.8rem; line-height: 1;">🚨</span>
                            <div>
                                <strong style="font-size: 0.95rem; color: #b91c1c;"><?php echo $_SESSION['lang'] == 'id' ? 'PERINGATAN BAHAYA: Ditemukan <span id="dangerAlertCount">0</span> shift di HRIS = 0 (Kosong) padahal ada lembur!' : 'CRITICAL ALERT: Found <span id="dangerAlertCount">0</span> shift(s) with HRIS = 0 despite having overtime!'; ?></strong>
                                <div style="font-size: 0.8rem; color: #7f1d1d; margin-top: 2px;"><?php echo $_SESSION['lang'] == 'id' ? 'Lembur driver belum diinput ke HRIS dan berisiko TIDAK TERBAYAR saat payroll. Segera cek dan laporkan!' : 'Driver overtime has not been entered into HRIS and risks being unpaid during payroll!'; ?></div>
                            </div>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" onclick="filterDangerOnly()" style="background: #dc2626; color: #fff; border: none; border-radius: 6px; padding: 8px 14px; font-weight: 700; cursor: pointer; font-size: 0.82rem; box-shadow: 0 2px 5px rgba(220,38,38,0.3);">
                                🚨 <?php echo $_SESSION['lang'] == 'id' ? 'Filter HRIS = 0' : 'Filter HRIS = 0'; ?>
                            </button>
                            <button type="button" onclick="document.getElementById('hrisDangerAlertBanner').style.display='none'" style="background: rgba(0,0,0,0.06); color: #7f1d1d; border: 1px solid rgba(0,0,0,0.1); border-radius: 6px; padding: 8px 12px; font-weight: 600; cursor: pointer; font-size: 0.82rem;">
                                ✕ <?php echo $_SESSION['lang'] == 'id' ? 'Tutup' : 'Dismiss'; ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Search & Filter Controls -->
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; background: rgba(0,0,0,0.02); padding: 10px 14px; border-radius: 8px; border: 1px solid var(--glass-border);">
                    <div style="display: flex; gap: 8px; align-items: center; flex: 1; min-width: 220px; max-width: 320px;">
                        <span style="font-size: 0.8rem; font-weight: bold; color: var(--text-secondary); white-space: nowrap;">🔍 <?php echo $_SESSION['lang'] == 'id' ? 'CARI:' : 'SEARCH:'; ?></span>
                        <input type="text" id="tableSearch" oninput="applyFiltersAndSort()" placeholder="<?php echo $_SESSION['lang'] == 'id' ? 'Ketik NIK, Nama Driver, atau Status...' : 'Type NIK, Driver Name, or Status...'; ?>" style="width: 100%; padding: 6px 10px; border: 1px solid var(--glass-border); border-radius: 6px; font-size: 0.8rem; background: var(--bg-color); color: var(--text-primary); box-sizing: border-box;">
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: nowrap; overflow-x: auto;">
                        <span style="font-size: 0.8rem; font-weight: bold; color: var(--text-secondary); white-space: nowrap;">⚡ <?php echo $_SESSION['lang'] == 'id' ? 'FILTER:' : 'FILTER:'; ?></span>
                        <select id="filterApproval" onchange="applyFiltersAndSort()" style="padding: 6px 8px; border: 1px solid var(--glass-border); border-radius: 6px; font-size: 0.8rem; background: var(--bg-color); color: var(--text-primary); width: 145px; box-sizing: border-box;">
                            <option value="ALL"><?php echo $_SESSION['lang'] == 'id' ? '[ OT Approval ]' : '[ OT Approval ]'; ?></option>
                            <option value="APPROVED">APPROVED</option>
                            <option value="PENDING">PENDING</option>
                        </select>
                        <select id="filterOvertime" onchange="applyFiltersAndSort()" style="padding: 6px 8px; border: 1px solid var(--glass-border); border-radius: 6px; font-size: 0.8rem; background: var(--bg-color); color: var(--text-primary); width: 135px; box-sizing: border-box;">
                            <option value="ALL"><?php echo $_SESSION['lang'] == 'id' ? '[ Status Lembur ]' : '[ All Overtime ]'; ?></option>
                            <option value="HAS_OT"><?php echo $_SESSION['lang'] == 'id' ? 'Ada Lembur' : 'Has Overtime'; ?></option>
                            <option value="NO_OT"><?php echo $_SESSION['lang'] == 'id' ? 'Tidak Ada' : 'No Overtime'; ?></option>
                        </select>
                        <select id="filterHris" onchange="onHrisFilterChange()" style="padding: 6px 8px; border: 1px solid var(--glass-border); border-radius: 6px; font-size: 0.8rem; background: var(--bg-color); color: var(--text-primary); width: 200px; box-sizing: border-box;">
                            <option value="ALL"><?php echo $_SESSION['lang'] == 'id' ? '[ Status HRIS ]' : '[ HRIS Status ]'; ?></option>
                            <option value="DIFF_UNCONFIRMED" style="color: #dc2626; font-weight: bold;"><?php echo $_SESSION['lang'] == 'id' ? '⚠️ Perlu Cek (Belum Note)' : '⚠️ Check (Unconfirmed)'; ?></option>
                            <option value="CONFIRMED" style="color: #0284c7; font-weight: bold;"><?php echo $_SESSION['lang'] == 'id' ? '📝 Confirmed (Ada Note)' : '📝 Confirmed (Has Note)'; ?></option>
                            <option value="DIFF" style="color: #ea580c;"><?php echo $_SESSION['lang'] == 'id' ? '⚡ Semua Selisih (>0.5j & 0)' : '⚡ All Diff (>0.5h & 0)'; ?></option>
                            <option value="DANGER" style="color: #b91c1c; font-weight: 800;"><?php echo $_SESSION['lang'] == 'id' ? '🚨 BAHAYA (HRIS = 0)' : '🚨 DANGER (HRIS = 0)'; ?></option>
                            <option value="MAJOR" style="color: #d97706;"><?php echo $_SESSION['lang'] == 'id' ? '⚠️ Selisih > 0.5 Jam' : '⚠️ Diff > 0.5 Hours'; ?></option>
                            <option value="MATCH" style="color: #166534;"><?php echo $_SESSION['lang'] == 'id' ? '✔ Sesuai (Toleransi OK)' : '✔ Match (Tolerance OK)'; ?></option>
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
                                <th>Break</th>
                                <th onclick="sortColumn('ot_type')" style="cursor: pointer;">Tipe OT <span id="sort-ot_type">⇅</span></th>
                                <th onclick="sortColumn('real_ot')" style="cursor: pointer;">Real OT <span id="sort-real_ot">⇅</span></th>
                                <th class="col-admin-hris-compare" style="display: none; background: rgba(16,124,16,0.1); color: #107c10;">HRIS OT</th>
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
            const hrisFilter = document.getElementById('filterHris') ? document.getElementById('filterHris').value : 'ALL';

            // 1. Filter
            let filtered = currentData.filter(r => {
                const matchSearch = 
                    (r.nik || '').toLowerCase().includes(searchTerm) ||
                    (r.driver_name || '').toLowerCase().includes(searchTerm) ||
                    (r.tx_ids || '').toLowerCase().includes(searchTerm) ||
                    (r.shift_date || '').toLowerCase().includes(searchTerm) ||
                    (r.status || '').toLowerCase().includes(searchTerm) ||
                    (r.approval_status || '').toLowerCase().includes(searchTerm) ||
                    (r.hris_note || '').toLowerCase().includes(searchTerm);

                const matchApproval = approvalFilter === 'ALL' || r.approval_status.toUpperCase() === approvalFilter;

                const hasOt = (parseFloat(r.real_ot) > 0);
                const matchOvertime = overtimeFilter === 'ALL' || 
                                      (overtimeFilter === 'HAS_OT' && hasOt) || 
                                      (overtimeFilter === 'NO_OT' && !hasOt);

                let matchHris = true;
                if (isAdminCompareActive && hrisFilter !== 'ALL') {
                    const hasNote = Boolean(r.hris_note && r.hris_note.trim() !== '');
                    const diff = checkHrisDiff(r.nik, r.shift_date, r.real_ot, hasNote);
                    if (hrisFilter === 'DIFF_UNCONFIRMED') {
                        matchHris = diff.isUnconfirmedDiff;
                    } else if (hrisFilter === 'CONFIRMED') {
                        matchHris = diff.isConfirmed;
                    } else if (hrisFilter === 'DIFF') {
                        matchHris = diff.isDiff;
                    } else if (hrisFilter === 'DANGER') {
                        matchHris = diff.isDanger;
                    } else if (hrisFilter === 'MAJOR') {
                        matchHris = diff.isMajorDiff && !diff.isDanger;
                    } else if (hrisFilter === 'MATCH') {
                        matchHris = !diff.isDiff || diff.isConfirmed;
                    }
                }

                return matchSearch && matchApproval && matchOvertime && matchHris;
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
                    if (r.supervisor_email) {
                        actionBtns += ` <button onclick="notifySupervisor(${r.shift_id})" class="btn-action" style="background:#118DFF; color:#ffffff; margin-left:4px; border:none; border-radius:4px; padding:6px 12px; font-size:0.75rem; font-weight:600; cursor:pointer;" title="Notify Supervisor via Teams">Teams</button>`;
                    }
                }

                if (r.note_status === 'pending_admin') {
                    actionBtns += ` <button onclick="openAdminShiftCommentsModal(${r.shift_id}, '${driverNameSafe}', '${r.shift_date}')" class="btn-action" style="background:#fef3c7; color:#d97706; margin-left:4px; border:1px solid #fde68a; border-radius:4px; padding:6px 10px; font-size:0.75rem; font-weight:600; cursor:pointer;" title="Passenger Note (Awaiting Admin Reply)">💬 Note</button>`;
                } else if (r.note_status === 'replied_admin') {
                    actionBtns += ` <button onclick="openAdminShiftCommentsModal(${r.shift_id}, '${driverNameSafe}', '${r.shift_date}')" class="btn-action" style="background:#ecfdf5; color:#059669; margin-left:4px; border:1px solid #a7f3d0; border-radius:4px; padding:6px 10px; font-size:0.75rem; font-weight:600; cursor:pointer;" title="Note (Replied by Admin)">💬 Note</button>`;
                } else if (r.last_note) {
                    actionBtns += ` <button onclick="openAdminShiftCommentsModal(${r.shift_id}, '${driverNameSafe}', '${r.shift_date}')" class="btn-action" style="background:#f1f5f9; color:#475569; margin-left:4px; border:1px solid #cbd5e1; border-radius:4px; padding:6px 10px; font-size:0.75rem; font-weight:600; cursor:pointer;" title="Note Thread">💬 Note</button>`;
                }
                
                let txHtml = '-';
                if (r.tx_ids) {
                    const ids = r.tx_ids.split(',');
                    const count = ids.length;
                    const suffix = count > 1 ? " <?php echo $_SESSION['lang'] == 'id' ? 'Trip' : 'Trips'; ?>" : " Trip";
                    txHtml = `<a href="#" onclick="showShiftTripsList('${r.driver_name.replace(/'/g, "\\'")}', '${r.shift_date}', '${r.tx_ids}'); return false;" style="color:var(--pbi-blue); font-weight:bold; text-decoration:underline;">${count}${suffix}</a>`;
                }

                // Break Calculation
                let breakMins = 0;
                const endHour = r.end_time ? parseInt(r.end_time.substring(0, 2)) : 0;
                const grossOt = parseFloat(otEarlyVal) + parseFloat(otLateVal);
                if (endHour >= 23 || (r.end_time && r.end_time.substring(0, 5) === '00:00')) {
                    if (grossOt >= 3.5 || realOtVal >= 3.5) breakMins = 60;
                } else if (grossOt >= 3.5 || realOtVal >= 3.5) {
                    breakMins = 30;
                }
                const breakHtml = breakMins > 0 ? `<span style="color: #ea580c; font-weight: bold;">${breakMins}m</span>` : '<span style="color: #94a3b8;">-</span>';

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
                        <td align="center">${breakHtml}</td>
                        <td align="center" style="font-weight: bold; color: ${r.ot_type === 'H' ? '#dc2626' : '#475569'};">${r.ot_type || '-'}</td>
                        <td align="center" style="font-weight: ${realOtVal > 0 ? 'bold' : 'normal'}; color: ${realOtVal > 0 ? 'var(--pbi-blue)' : 'inherit'};">${realOtVal > 0 ? realOtVal : '-'}</td>
                        <td class="col-admin-hris-compare admin-hris-cell" data-nik="${r.nik || ''}" data-date="${r.shift_date}" data-web-ot="${realOtVal}" data-shift-id="${r.shift_id}" data-driver-id="${r.driver_id}" data-driver-name="${driverNameSafe}" align="center" style="${isAdminCompareActive ? '' : 'display: none;'} font-weight: bold;">-</td>
                        <td align="center" style="font-weight: ${convOtVal > 0 ? 'bold' : 'normal'}; color: ${convOtVal > 0 ? '#107c10' : 'inherit'};">${convOtVal > 0 ? convOtVal : '-'}</td>
                        <td align="center">${r.status ? r.status.toUpperCase() : ''}</td>
                        <td align="center"><input type="checkbox" class="shift-checkbox" value="${r.shift_id}"></td>
                        <td align="center">${r.approval_status === 'approved' ? '<span style="color: #15803d; font-size: 1.15rem; font-weight: bold;">✔</span><br><span style="font-size: 0.65rem; color: ' + (r.approver_name ? '#0284c7' : '#94a3b8') + '; font-weight: bold;">' + (r.approver_name || 'System') + '</span>' : ''}</td>
                        <td align="center">${actionBtns}</td>
                    </tr>
                `;
            }).join('');

            if (isAdminCompareActive) {
                renderAdminHrisCompareCells();
            }
        }

        // HRIS Compare Logic for Admin Attendance Report
        let isAdminCompareActive = false;
        let adminHrisCache = {};

        function checkHrisDiff(nik, shiftDate, webOt, hasNote = false) {
            webOt = parseFloat(webOt || 0);
            const driverHrisData = (nik && adminHrisCache[nik]) ? adminHrisCache[nik] : [];
            const hrisRow = driverHrisData.find(r => r.trn_date === shiftDate);

            let isDanger = false;
            let isMajorDiff = false;
            let isDiff = false;
            let diffHours = 0;
            let diffSign = '';
            let hrisOt = 0;
            let breakHours = 0;
            let totalHrisNet = 0;
            let hasData = false;

            if (hrisRow) {
                hrisOt = parseFloat(hrisRow.time_total || 0);
                const breakTime = parseFloat(hrisRow.break_time || 0);
                breakHours = breakTime > 5 ? (breakTime / 60.0) : breakTime;
                totalHrisNet = hrisOt + breakHours;
                hasData = true;

                // BAHAYA: Di HRIS = 0 (atau kosong) padahal di attendance_report ADA lembur!
                isDanger = (webOt > 0 && hrisOt <= 0);

                if (!isDanger) {
                    // Komparasi DUA ARAH (Selisih > 0.5 jam):
                    // 1. HRIS Kurang dari Web (setelah toleransi break deduction di HRIS)
                    const hrisEffective = Math.max(hrisOt, totalHrisNet);
                    const diffKurang = webOt - hrisEffective;

                    // 2. HRIS Lebih Besar dari Web (seperti Andrian 5.08 vs 4.09)
                    const diffLebih = hrisOt - webOt;

                    if (diffKurang > 0.501) {
                        isMajorDiff = true;
                        diffHours = diffKurang;
                        diffSign = '-';
                    } else if (diffLebih > 0.501) {
                        isMajorDiff = true;
                        diffHours = diffLebih;
                        diffSign = '+';
                    } else {
                        isMajorDiff = false;
                        diffHours = 0;
                        diffSign = '';
                    }
                }

                isDiff = isDanger || isMajorDiff;
            } else {
                isDanger = (webOt > 0);
                isMajorDiff = false;
                isDiff = isDanger;
                diffHours = webOt;
                diffSign = '-';
                hrisOt = 0;
                breakHours = 0;
                totalHrisNet = 0;
                hasData = false;
            }

            // Jika ada NOTE maka shift selisih ini dianggap CONFIRMED!
            const isConfirmed = Boolean(hasNote && (isDiff || webOt > 0));
            const isUnconfirmedDanger = isDanger && !hasNote;
            const isUnconfirmedMajorDiff = isMajorDiff && !hasNote;
            const isUnconfirmedDiff = isDiff && !hasNote;

            return {
                isDanger: isDanger,
                isMajorDiff: isMajorDiff,
                isDiff: isDiff,
                isConfirmed: isConfirmed,
                isUnconfirmedDanger: isUnconfirmedDanger,
                isUnconfirmedMajorDiff: isUnconfirmedMajorDiff,
                isUnconfirmedDiff: isUnconfirmedDiff,
                diffHours: diffHours,
                diffSign: diffSign,
                hrisOt: hrisOt,
                breakTime: breakHours,
                totalHrisNet: totalHrisNet,
                hasData: hasData
            };
        }

        function updateHrisDiffSummary() {
            const badge = document.getElementById('hrisDiffCountBadge');
            const alertBanner = document.getElementById('hrisDangerAlertBanner');
            const alertCountEl = document.getElementById('dangerAlertCount');

            if (!isAdminCompareActive) {
                if (badge) badge.style.display = 'none';
                if (alertBanner) alertBanner.style.display = 'none';
                return;
            }

            let unconfirmedDangerCount = 0;
            let unconfirmedMajorDiffCount = 0;
            let confirmedCount = 0;

            currentData.forEach(r => {
                const hasNote = Boolean(r.hris_note && r.hris_note.trim() !== '');
                const diff = checkHrisDiff(r.nik, r.shift_date, r.real_ot, hasNote);
                if (diff.isUnconfirmedDanger) {
                    unconfirmedDangerCount++;
                } else if (diff.isUnconfirmedMajorDiff) {
                    unconfirmedMajorDiffCount++;
                }
                if (diff.isConfirmed) {
                    confirmedCount++;
                }
            });

            // 1. DANGER ALERT BANNER (Hanya muncul jika ada BAHAYA yang BELUM dikonfirmasi dengan catatan)
            if (alertBanner && alertCountEl) {
                if (unconfirmedDangerCount > 0) {
                    alertCountEl.innerText = unconfirmedDangerCount;
                    alertBanner.style.display = 'block';
                } else {
                    alertBanner.style.display = 'none';
                }
            }

            // 2. BADGE RINGKASAN DI TOOLBAR
            if (badge) {
                badge.style.display = 'inline-flex';
                if (unconfirmedDangerCount > 0 && unconfirmedMajorDiffCount > 0) {
                    badge.style.background = '#fef2f2';
                    badge.style.color = '#dc2626';
                    badge.style.border = '1px solid #ef4444';
                    badge.innerHTML = `🚨 ${unconfirmedDangerCount} BAHAYA + ⚠️ ${unconfirmedMajorDiffCount} Selisih` + (confirmedCount > 0 ? ` | 📝 ${confirmedCount} Confirmed` : '');
                    badge.title = '<?php echo $_SESSION['lang'] == 'id' ? 'Klik untuk filter selisih yang belum ada note' : 'Click to filter unconfirmed'; ?>';
                } else if (unconfirmedDangerCount > 0) {
                    badge.style.background = '#fef2f2';
                    badge.style.color = '#dc2626';
                    badge.style.border = '1px solid #ef4444';
                    badge.innerHTML = `🚨 ${unconfirmedDangerCount} BAHAYA (HRIS = 0)` + (confirmedCount > 0 ? ` | 📝 ${confirmedCount} Confirmed` : '');
                    badge.title = '<?php echo $_SESSION['lang'] == 'id' ? 'Klik untuk filter shift HRIS = 0' : 'Click to filter HRIS = 0'; ?>';
                } else if (unconfirmedMajorDiffCount > 0) {
                    badge.style.background = '#fffbeb';
                    badge.style.color = '#b45309';
                    badge.style.border = '1px solid #f59e0b';
                    badge.innerHTML = `⚠️ ${unconfirmedMajorDiffCount} Selisih > 0.5 Jam` + (confirmedCount > 0 ? ` | 📝 ${confirmedCount} Confirmed` : '');
                    badge.title = '<?php echo $_SESSION['lang'] == 'id' ? 'Klik untuk filter selisih > 0.5 jam' : 'Click to filter diff > 0.5h'; ?>';
                } else if (confirmedCount > 0) {
                    badge.style.background = '#e0f2fe';
                    badge.style.color = '#0369a1';
                    badge.style.border = '1px solid #7dd3fc';
                    badge.innerHTML = `✔ Selesai (📝 ${confirmedCount} Confirmed)`;
                    badge.title = 'Semua selisih telah dikonfirmasi dengan catatan';
                } else {
                    badge.style.background = '#dcfce7';
                    badge.style.color = '#166534';
                    badge.style.border = '1px solid #86efac';
                    badge.innerHTML = `✔ <?php echo $_SESSION['lang'] == 'id' ? 'Semua Cocok (Toleransi OK)' : 'All Match (Tolerance OK)'; ?>`;
                    badge.title = '';
                }
            }
        }

        async function filterDangerOnly() {
            const filterEl = document.getElementById('filterHris');
            if (filterEl) {
                filterEl.value = 'DANGER';
            }
            await onHrisFilterChange();
        }

        async function quickFilterHrisDiff() {
            const filterEl = document.getElementById('filterHris');
            if (!filterEl) return;
            if (filterEl.value === 'DIFF_UNCONFIRMED' || filterEl.value === 'DIFF' || filterEl.value === 'DANGER' || filterEl.value === 'MAJOR') {
                filterEl.value = 'ALL';
            } else {
                filterEl.value = 'DIFF_UNCONFIRMED';
            }
            await onHrisFilterChange();
        }

        async function onHrisFilterChange() {
            const filterEl = document.getElementById('filterHris');
            const val = filterEl ? filterEl.value : 'ALL';

            if (filterEl) {
                if (val === 'DANGER' || val === 'DIFF_UNCONFIRMED') {
                    filterEl.style.borderColor = '#dc2626';
                    filterEl.style.color = '#dc2626';
                    filterEl.style.fontWeight = 'bold';
                } else if (val === 'CONFIRMED') {
                    filterEl.style.borderColor = '#0284c7';
                    filterEl.style.color = '#0284c7';
                    filterEl.style.fontWeight = 'bold';
                } else if (val === 'MAJOR') {
                    filterEl.style.borderColor = '#d97706';
                    filterEl.style.color = '#d97706';
                    filterEl.style.fontWeight = 'bold';
                } else if (val === 'DIFF') {
                    filterEl.style.borderColor = '#ea580c';
                    filterEl.style.color = '#ea580c';
                    filterEl.style.fontWeight = 'bold';
                } else if (val === 'MATCH') {
                    filterEl.style.borderColor = '#166534';
                    filterEl.style.color = '#166534';
                    filterEl.style.fontWeight = 'bold';
                } else {
                    filterEl.style.borderColor = 'var(--glass-border)';
                    filterEl.style.color = 'var(--text-primary)';
                    filterEl.style.fontWeight = 'normal';
                }
            }

            if (val !== 'ALL') {
                if (!isAdminCompareActive) {
                    isAdminCompareActive = true;
                    const btn = document.getElementById('btnAdminCompareHris');
                    if (btn) {
                        btn.style.background = '#0369a1';
                        btn.innerHTML = '✔ Compare HRIS (Active)';
                    }
                    const cols = document.querySelectorAll('.col-admin-hris-compare');
                    cols.forEach(c => c.style.display = '');
                }
                await loadAndRenderAdminHrisCompare();
            }
            applyFiltersAndSort();
        }

        async function toggleAdminHrisCompare() {
            isAdminCompareActive = !isAdminCompareActive;
            const btn = document.getElementById('btnAdminCompareHris');
            const cols = document.querySelectorAll('.col-admin-hris-compare');

            if (isAdminCompareActive) {
                btn.style.background = '#0369a1';
                btn.innerHTML = '✔ Compare HRIS (Active)';
                cols.forEach(c => c.style.display = '');
                await loadAndRenderAdminHrisCompare();
            } else {
                btn.style.background = '#0284c7';
                btn.innerHTML = '🔍 Compare HRIS';
                cols.forEach(c => c.style.display = 'none');

                const filterHrisEl = document.getElementById('filterHris');
                if (filterHrisEl && filterHrisEl.value !== 'ALL') {
                    filterHrisEl.value = 'ALL';
                    filterHrisEl.style.borderColor = 'var(--glass-border)';
                    filterHrisEl.style.color = 'var(--text-primary)';
                    filterHrisEl.style.fontWeight = 'normal';
                }
                const badge = document.getElementById('hrisDiffCountBadge');
                if (badge) badge.style.display = 'none';
                const alertBanner = document.getElementById('hrisDangerAlertBanner');
                if (alertBanner) alertBanner.style.display = 'none';

                applyFiltersAndSort();
            }
        }

        async function loadAndRenderAdminHrisCompare() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            
            const uniqueNiks = [...new Set(currentData.map(r => r.nik).filter(n => n && String(n).trim() !== ''))];
            if (uniqueNiks.length === 0) {
                updateHrisDiffSummary();
                return;
            }

            const badge = document.getElementById('hrisDiffCountBadge');
            if (badge) {
                badge.style.display = 'inline-flex';
                badge.style.background = '#f1f5f9';
                badge.style.color = '#64748b';
                badge.style.border = '1px solid #cbd5e1';
                badge.innerHTML = `⏳ <?php echo $_SESSION['lang'] == 'id' ? 'Memuat HRIS...' : 'Loading HRIS...'; ?>`;
            }

            document.querySelectorAll('.admin-hris-cell').forEach(c => c.innerHTML = '<span style="color:var(--pbi-blue); font-size:0.65rem;">...</span>');

            for (const nik of uniqueNiks) {
                if (!adminHrisCache[nik]) {
                    try {
                        const apiUrl = `https://api.framas.web.id/ops-ot/api.php?emp_cd=${encodeURIComponent(nik)}&start_date=${startDate}&end_date=${endDate}`;
                        const res = await fetch(apiUrl);
                        const json = await res.json();
                        if (json.status === 'success' && json.data) {
                            adminHrisCache[nik] = json.data;
                        } else {
                            adminHrisCache[nik] = [];
                        }
                    } catch(e) {
                        adminHrisCache[nik] = [];
                    }
                }
            }

            renderAdminHrisCompareCells();
            updateHrisDiffSummary();
        }

        function renderAdminHrisCompareCells() {
            document.querySelectorAll('.admin-hris-cell').forEach(cell => {
                if (isAdminCompareActive) {
                    cell.style.display = '';
                }
                const nik = cell.dataset.nik;
                const dateStr = cell.dataset.date;
                const shiftId = cell.dataset.shiftId;
                const driverId = cell.dataset.driverId;
                const driverName = cell.dataset.driverName;
                const webOt = parseFloat(cell.dataset.webOt || 0);

                const row = currentData.find(r => r.shift_id == shiftId || (r.nik == nik && r.shift_date == dateStr));
                const hrisNote = row && row.hris_note ? row.hris_note : '';
                const hasNote = Boolean(hrisNote && hrisNote.trim() !== '');

                const diff = checkHrisDiff(nik, dateStr, webOt, hasNote);

                const driverNameSafe = (driverName || (row ? row.driver_name : '') || '').replace(/'/g, "\\'");
                const sId = shiftId || (row ? row.shift_id : 0);
                const dId = driverId || (row ? row.driver_id : 0);
                const openModalCall = `openHrisNoteModal(${sId}, ${dId}, '${dateStr}', '${driverNameSafe}', ${webOt}, ${diff.hrisOt})`;

                if (diff.isConfirmed) {
                    // SUDAH CONFIRMED: Ada selisih, tapi admin sudah menulis catatan konfirmasi
                    cell.innerHTML = `
                        <div style="display:inline-flex; align-items:center; justify-content:center; gap:4px; flex-wrap:nowrap;">
                            <span style="color:#0369a1; font-weight:bold;">${diff.hrisOt > 0 ? diff.hrisOt : '0'}</span>
                            <button type="button" onclick="${openModalCall}" style="background:#e0f2fe; color:#0369a1; border:1px solid #7dd3fc; border-radius:4px; padding:2px 6px; font-size:0.75rem; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:2px;" title="Catatan Admin: ${escapeHtml(hrisNote)} (Klik untuk edit/hapus)">
                                📝 Confirmed
                            </button>
                        </div>
                    `;
                } else if (diff.isDanger) {
                    // BAHAYA & BELUM CONFIRMED: Ada lembur di Web tapi di HRIS = 0 (Kosong)
                    cell.innerHTML = `
                        <div style="display:inline-flex; align-items:center; justify-content:center; gap:4px; flex-wrap:nowrap;">
                            <span style="color:#fff; background:#dc2626; font-weight:800; padding:2px 6px; border-radius:4px; font-size:0.8rem; box-shadow:0 1px 3px rgba(220,38,38,0.4);" title="BAHAYA: Ada lembur di Web (${webOt} jam) tapi di HRIS = 0 (Kosong)!">🚨 0</span>
                            <button type="button" onclick="${openModalCall}" style="background:#fee2e2; color:#b91c1c; border:1px solid #f87171; border-radius:4px; padding:2px 6px; font-size:0.75rem; font-weight:700; cursor:pointer;" title="Tulis catatan konfirmasi selisih">+ Note</button>
                        </div>
                    `;
                } else if (diff.isMajorDiff) {
                    // Selisih > 0.5 jam & BELUM CONFIRMED (baik HRIS kurang maupun HRIS lebih)
                    const sign = diff.diffSign || '';
                    const diffFormatted = sign + diff.diffHours.toFixed(2);
                    cell.innerHTML = `
                        <div style="display:inline-flex; align-items:center; justify-content:center; gap:4px; flex-wrap:nowrap;">
                            <span style="color:#b45309; font-weight:800; background:#fef3c7; padding:2px 6px; border-radius:4px; border:1px solid #fde68a; font-size:0.8rem;" title="HRIS: ${diff.hrisOt} jam vs Web: ${webOt} jam (Selisih ${diffFormatted} jam > 0.5 jam)">${diff.hrisOt > 0 ? diff.hrisOt : '0'} ⚠️ (${diffFormatted}j)</span>
                            <button type="button" onclick="${openModalCall}" style="background:#fef3c7; color:#92400e; border:1px solid #fcd34d; border-radius:4px; padding:2px 6px; font-size:0.75rem; font-weight:700; cursor:pointer;" title="Tulis catatan konfirmasi selisih">+ Note</button>
                        </div>
                    `;
                } else {
                    // Sesuai / Toleransi OK (selisih <= 0.5 jam diabaikan karena potongan break HRIS)
                    cell.innerHTML = `
                        <div style="display:inline-flex; align-items:center; justify-content:center; gap:4px; flex-wrap:nowrap;">
                            <span style="color:#166534; font-weight:bold;" title="HRIS: ${diff.hrisOt} jam (Sesuai / Toleransi break OK)">${diff.hrisOt > 0 ? diff.hrisOt : (diff.hasData ? '0' : '-')}</span>
                            ${hasNote ? `<button type="button" onclick="${openModalCall}" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; border-radius:4px; padding:2px 5px; font-size:0.72rem; cursor:pointer;" title="Catatan: ${escapeHtml(hrisNote)}">📝</button>` : ''}
                        </div>
                    `;
                }
            });
            updateHrisDiffSummary();
        }

        function openHrisNoteModal(shiftId, driverId, shiftDate, driverName, webOt, hrisOt) {
            const row = currentData.find(r => r.shift_id == shiftId || (r.driver_id == driverId && r.shift_date == shiftDate));
            const existingNote = row && row.hris_note ? row.hris_note : '';
            const existingBy = row && row.hris_note_by ? row.hris_note_by : '';
            const existingAt = row && row.hris_note_at ? row.hris_note_at : '';

            const rawDiff = (parseFloat(hrisOt || 0) - parseFloat(webOt || 0));
            const diffPrefix = rawDiff > 0 ? '+' : '';
            const diffHours = diffPrefix + rawDiff.toFixed(2);

            Swal.fire({
                title: '📝 Catatan Selisih HRIS',
                html: `
                    <div style="text-align: left; font-size: 0.88rem; color: var(--text-primary);">
                        <div style="background: rgba(0,0,0,0.03); border: 1px solid var(--glass-border); border-radius: 8px; padding: 10px 14px; margin-bottom: 14px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                <strong style="color:var(--text-secondary);">Driver:</strong> <span style="font-weight:700;">${driverName}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                <strong style="color:var(--text-secondary);">Tanggal:</strong> <span>${shiftDate}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; border-top: 1px dashed #cbd5e1; padding-top: 6px; margin-top: 6px; font-size: 0.84rem;">
                                <span>Web OT: <strong>${webOt} jam</strong></span>
                                <span>HRIS: <strong>${hrisOt} jam</strong></span>
                                <span style="color: #dc2626; font-weight:700;">Selisih: ${diffHours} jam</span>
                            </div>
                        </div>

                        <label style="display: block; font-weight: bold; margin-bottom: 6px; font-size: 0.85rem;">
                            Tulis Alasan / Catatan Selisih:
                        </label>
                        <textarea id="swalHrisNoteText" class="pbi-input" rows="3" placeholder="Contoh: Sudah konfirmasi SPV, driver ada lembur jemput manual / potongan istirahat..." style="width: 100%; box-sizing: border-box; padding: 8px 10px; font-size: 0.85rem; border-radius: 6px; border: 1px solid #cbd5e1; font-family: inherit;">${escapeHtml(existingNote)}</textarea>

                        ${existingBy ? `
                            <div style="font-size: 0.75rem; color: #64748b; margin-top: 6px;">
                                Terakhir dikonfirmasi oleh: <strong>${escapeHtml(existingBy)}</strong> ${existingAt ? `(${existingAt})` : ''}
                            </div>
                        ` : ''}

                        <div style="margin-top: 12px; font-size: 0.78rem; color: #0369a1; background: #e0f2fe; padding: 8px 10px; border-radius: 6px; border: 1px solid #bae6fd; line-height: 1.4;">
                            💡 <em>Shift yang memiliki catatan otomatis dianggap <strong>CONFIRMED</strong> (Terkonfirmasi & Selesai).</em>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                showDenyButton: Boolean(existingNote),
                confirmButtonText: '💾 Simpan & Konfirmasi',
                denyButtonText: '🗑️ Hapus Note (Unconfirm)',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#0284c7',
                denyButtonColor: '#dc2626',
                cancelButtonColor: '#64748b',
                focusConfirm: false,
                preConfirm: () => {
                    const text = document.getElementById('swalHrisNoteText').value.trim();
                    if (!text) {
                        Swal.showValidationMessage('Catatan tidak boleh kosong untuk konfirmasi. Jika ingin membatalkan, klik Hapus Note.');
                        return false;
                    }
                    return text;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    saveHrisNoteAjax(shiftId, driverId, shiftDate, result.value);
                } else if (result.isDenied) {
                    saveHrisNoteAjax(shiftId, driverId, shiftDate, '');
                }
            });
        }

        function saveHrisNoteAjax(shiftId, driverId, shiftDate, note) {
            const formData = new FormData();
            formData.append('shift_id', shiftId);
            formData.append('driver_id', driverId);
            formData.append('shift_date', shiftDate);
            formData.append('note', note);

            fetch('attendance_report.php?action=save_hris_note', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    // Update currentData in memory
                    currentData.forEach(r => {
                        if (r.shift_id == shiftId || (r.driver_id == driverId && r.shift_date == shiftDate)) {
                            r.hris_note = res.note;
                            r.hris_note_by = res.note_by;
                            r.hris_note_at = res.note_at;
                        }
                    });

                    // Re-render
                    renderAdminHrisCompareCells();
                    applyFiltersAndSort();
                    updateHrisDiffSummary();

                    const isSaved = Boolean(note);
                    Swal.fire({
                        icon: 'success',
                        title: isSaved ? 'Shift Dikonfirmasi!' : 'Catatan Dihapus',
                        text: isSaved ? 'Catatan berhasil disimpan dan status shift kini CONFIRMED.' : 'Status konfirmasi shift telah dibatalkan.',
                        timer: 1800,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire('Error', res.error || 'Gagal menyimpan catatan.', 'error');
                }
            })
            .catch(err => {
                Swal.fire('Error', 'Terjadi kesalahan jaringan saat menyimpan catatan.', 'error');
            });
        }

        let currentData = [];

        function updateMiniDashboard() {
            const driverSelect = document.getElementById('driver_id');
            const selectedDriverId = driverSelect.value;
            const miniDash = document.getElementById('driverMiniDashboard');

            if (selectedDriverId !== 'ALL' && currentData.length > 0) {
                const firstRow = currentData[0];
                let driverName = firstRow && firstRow.driver_name ? firstRow.driver_name : driverSelect.options[driverSelect.selectedIndex].text;
                if (firstRow && firstRow.nik) {
                    driverName += ` (NIK: ${firstRow.nik})`;
                }

                // Filter shifts where real_ot > 0
                const otRows = currentData.filter(r => parseFloat(r.real_ot || 0) > 0);
                const otDaysCount = otRows.length;
                const totalRealOt = currentData.reduce((sum, r) => sum + (parseFloat(r.real_ot) || 0), 0);
                const pendingOtCount = otRows.filter(r => r.approval_status === 'pending').length;

                document.getElementById('dashDriverName').innerText = driverName;
                document.getElementById('dashOtDays').innerHTML = `${otDaysCount} <span style="font-size: 0.85rem; font-weight: 500;"><?php echo $_SESSION['lang'] == 'id' ? 'Hari' : 'Days'; ?></span>`;
                document.getElementById('dashTotalOt').innerHTML = `${totalRealOt.toFixed(2)} <span style="font-size: 0.85rem; font-weight: 500;">Jam</span>`;

                const pendingEl = document.getElementById('dashPendingDays');
                if (pendingOtCount > 0) {
                    pendingEl.innerHTML = `${pendingOtCount} <span style="font-size: 0.85rem; font-weight: 500;"><?php echo $_SESSION['lang'] == 'id' ? 'Hari' : 'Days'; ?></span>`;
                    pendingEl.style.color = '#fbbf24';
                } else {
                    pendingEl.innerHTML = `0 <span style="font-size: 0.85rem; font-weight: 500;"><?php echo $_SESSION['lang'] == 'id' ? '(Semua Approved)' : '(All Approved)'; ?></span>`;
                    pendingEl.style.color = '#4ade80';
                }

                miniDash.style.display = 'block';
            } else {
                miniDash.style.display = 'none';
            }
        }

        function onPeriodSelectChange(selectEl) {
            if (selectEl.value === 'custom') return;
            const parts = selectEl.value.split('|');
            if (parts.length === 2) {
                document.getElementById('start_date').value = parts[0];
                document.getElementById('end_date').value = parts[1];
                generateReport();
            }
        }

        function checkCustomPeriod() {
            const s = document.getElementById('start_date').value;
            const e = document.getElementById('end_date').value;
            const selectEl = document.getElementById('period_select');
            if (!selectEl) return;
            const target = `${s}|${e}`;
            let found = false;
            for (let opt of selectEl.options) {
                if (opt.value === target) {
                    selectEl.value = target;
                    found = true;
                    break;
                }
            }
            if (!found) {
                selectEl.value = 'custom';
            }
        }

        async function generateReport() {
            checkCustomPeriod();
            const formData = new FormData();
            formData.append('driver_id', document.getElementById('driver_id').value);
            formData.append('supervisor_id', document.getElementById('supervisor_id').value);
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
            const filterHrisEl = document.getElementById('filterHris');
            if (filterHrisEl) {
                filterHrisEl.value = 'ALL';
                filterHrisEl.style.borderColor = 'var(--glass-border)';
                filterHrisEl.style.fontWeight = 'normal';
            }
            sortKey = '';

            // Reset HRIS cache for fresh report run
            adminHrisCache = {};

            applyFiltersAndSort();
            updateMiniDashboard();

            if (isAdminCompareActive) {
                await loadAndRenderAdminHrisCompare();
            }

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

        async function exportToHRIS() {
            const lines = [];
            
            // Header columns
            lines.push(["trn_date", "srl_no", "emp_cd", "emp_nm", "sec_cd", "bef_time_fr", "bef_time_to", "time_fr", "time_to", "break_time", "time_total", "transport_amt", "remarks"].join('\t'));
            
            currentData.forEach((r, idx) => {
                const trn_date = r.shift_date;
                const srl_no = idx + 1;
                const emp_cd = r.nik || '-';
                const emp_nm = r.driver_name;
                const sec_cd = "2009";
                
                const earlyHours = parseFloat(r.overtime_early || 0);
                const lateHours = parseFloat(r.overtime_late || 0);
                const real_ot = parseFloat(r.real_ot || 0);
                const is_holiday = (r.ot_type === 'H');
                
                // Early OT Range
                let bef_time_fr = '';
                let bef_time_to = '';
                if (earlyHours > 0) {
                    bef_time_fr = r.start_time ? r.start_time.substring(0, 5) : '05:00';
                    bef_time_to = '07:00';
                    if (bef_time_fr === '00:00') bef_time_fr = '00:01';
                }
                
                // Late OT Range
                let time_fr = '';
                let time_to = '';
                if (lateHours > 0 || is_holiday) {
                    if (is_holiday) {
                        time_fr = r.start_time ? r.start_time.substring(0, 5) : '';
                        time_to = r.end_time ? r.end_time.substring(0, 5) : '';
                    } else {
                        time_fr = '16:00';
                        time_to = r.end_time ? r.end_time.substring(0, 5) : '16:00';
                    }
                    if (time_fr === '00:00') time_fr = '00:01';
                    if (time_to === '00:00') time_to = '00:01';
                }
                
                let break_time = '0.00';
                let end_hour = 0;
                if (time_to !== '') {
                    end_hour = parseInt(time_to.substring(0, 2));
                }
                if (end_hour >= 23 || time_to === '00:01' || (r.end_time && parseInt(r.end_time.substring(0, 2)) >= 23)) {
                    break_time = '60.00';
                } else if (real_ot >= 3.5) {
                    break_time = '30.00';
                }
                
                const time_total = (earlyHours + lateHours).toFixed(2);
                
                let transport_amt = '0.00';
                if (is_holiday) {
                    transport_amt = '40000.00';
                } else {
                    if (earlyHours > 0 && lateHours > 0) {
                        transport_amt = '40000.00';
                    } else if (earlyHours > 0 || lateHours > 0) {
                        transport_amt = '20000.00';
                    }
                }
                
                const remarks = r.supervisor_name || '';
                
                lines.push([
                    trn_date, srl_no, emp_cd, emp_nm, sec_cd,
                    bef_time_fr, bef_time_to, time_fr, time_to,
                    break_time, time_total, transport_amt, remarks
                ].join('\t'));
            });
            
            const tsvText = lines.join('\n');
            
            try {
                await navigator.clipboard.writeText(tsvText);
                alert("Berhasil disalin! " + currentData.length + " baris data kolom HRIS telah disalin ke clipboard.\n\nSilakan paste (Ctrl+V) langsung ke Excel atau kolom input aplikasi HRIS Anda.");
            } catch (err) {
                // Fallback for older browsers or HTTP contexts
                const textArea = document.createElement("textarea");
                textArea.value = tsvText;
                textArea.style.position = "fixed";
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                try {
                    document.execCommand('copy');
                    alert("Berhasil disalin! " + currentData.length + " baris data kolom HRIS telah disalin ke clipboard.\n\nSilakan paste (Ctrl+V) langsung ke Excel atau kolom input aplikasi HRIS Anda.");
                } catch (e) {
                    alert("Gagal menyalin ke clipboard: " + e);
                }
                document.body.removeChild(textArea);
            }
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
            
            // Map Start Time selects
            if (startTime) {
                const parts = startTime.split(':');
                document.getElementById('editShiftStartHour').value = parts[0];
                document.getElementById('editShiftStartMin').value = parts[1];
            } else {
                document.getElementById('editShiftStartHour').value = '08';
                document.getElementById('editShiftStartMin').value = '00';
            }
            
            // Map End Time selects
            if (endTime && endTime !== '00:00:00') {
                const parts = endTime.split(':');
                document.getElementById('editShiftEndHour').value = parts[0];
                document.getElementById('editShiftEndMin').value = parts[1];
            } else {
                document.getElementById('editShiftEndHour').value = '';
                document.getElementById('editShiftEndMin').value = '';
            }

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
            
            const startHour = document.getElementById('editShiftStartHour').value;
            const startMin = document.getElementById('editShiftStartMin').value;
            const startTime = `${startHour}:${startMin}`;
            
            const endHour = document.getElementById('editShiftEndHour').value;
            const endMin = document.getElementById('editShiftEndMin').value;
            const endTime = (endHour && endMin) ? `${endHour}:${endMin}` : '';
            
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

        async function recalculateOvertime() {
            const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
            const confirmMsg = lang === 'id'
                ? 'Apakah Anda ingin menghitung ulang lembur untuk semua shift pada rentang tanggal dan driver yang dipilih?'
                : 'Do you want to recalculate overtime for all shifts in the selected date range and driver filter?';

            if (!confirm(confirmMsg)) return;

            const driverId = document.getElementById('driver_id').value;
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;

            const fd = new FormData();
            fd.append('action', 'recalculate_ot');
            fd.append('driver_id', driverId === 'ALL' ? '0' : driverId);
            fd.append('start_date', startDate);
            fd.append('end_date', endDate);
            fd.append('is_ajax', '1');

            try {
                const res = await fetch('manage_shift.php', {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json();
                if (data.success) {
                    alert(data.msg || (lang === 'id' ? 'Lembur berhasil dihitung ulang!' : 'Overtime recalculated successfully!'));
                    generateReport();
                } else {
                    alert('Error: ' + (data.error || 'Gagal menghitung ulang lembur'));
                }
            } catch(err) {
                alert('JS Error: ' + err.message);
            }
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

        async function notifySupervisor(supervisorId, isSilent = false) {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;

            if (!isSilent && !confirm("Send a Microsoft Teams notification to the supervisor for their drivers' overtime in this period?")) return { success: false, message: 'Cancelled by user' };
            
            const formData = new FormData();
            formData.append('supervisor_id', supervisorId);
            formData.append('start_date', startDate);
            formData.append('end_date', endDate);
            
            try {
                const res = await fetch('attendance_report.php?action=send_teams_notification', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    if (!isSilent) alert('Teams notification successfully sent to the supervisor!');
                    return { success: true };
                } else {
                    if (!isSilent) alert('Failed to send Teams notification: ' + data.message);
                    return { success: false, message: data.message };
                }
            } catch (err) {
                if (!isSilent) alert('An error occurred while sending notification: ' + err);
                return { success: false, message: err.message || err };
            }
        }

        async function bulkNotifyTeams() {
            const checkedBoxes = document.querySelectorAll('.shift-checkbox:checked');
            let selectedShifts = [];
            
            if (checkedBoxes.length === 0) {
                // Get all pending shifts with OT (>0) from currentData
                selectedShifts = currentData.filter(r => r.approval_status === 'pending' && parseFloat(r.real_ot || 0) > 0);
                if (selectedShifts.length === 0) {
                    alert('No pending overtime shifts found in the current table to notify.');
                    return;
                }
                
                const confirmMsg = `No specific rows checked. Do you want to send Teams notifications to supervisors for ALL pending overtime shifts in this period?`;
                if (!confirm(confirmMsg)) return;
            } else {
                const shiftIds = Array.from(checkedBoxes).map(cb => parseInt(cb.value));
                selectedShifts = currentData.filter(r => shiftIds.includes(parseInt(r.shift_id)));
            }
            
            // Get unique supervisors from selected shifts
            const supervisorIds = [...new Set(selectedShifts.map(r => r.supervisor_id).filter(id => id !== null && id !== ''))];
            if (supervisorIds.length === 0) {
                alert('No supervisors found for the selected shifts.');
                return;
            }
            
            let successCount = 0;
            let failureMessages = [];
            
            for (let i = 0; i < supervisorIds.length; i++) {
                const res = await notifySupervisor(supervisorIds[i], true);
                if (res.success) {
                    successCount++;
                } else {
                    const row = selectedShifts.find(r => r.supervisor_id == supervisorIds[i]);
                    const svName = row ? row.supervisor_name : 'ID ' + supervisorIds[i];
                    failureMessages.push(`- ${svName}: ${res.message}`);
                }
            }
            
            let finalAlert = `Teams notification process completed.\n\nNotified: ${successCount} supervisors\nFailed: ${failureMessages.length}`;
            if (failureMessages.length > 0) {
                finalAlert += `\n\nFailure Details:\n` + failureMessages.join('\n');
            }
            alert(finalAlert);
            generateReport();
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

        function filterPendingNotesOnly() {
            generateReport().then(() => {
                const pendingRows = currentData.filter(r => r.note_status === 'pending_admin');
                renderTable(pendingRows);
                const isId = "<?php echo $_SESSION['lang'] ?? 'en'; ?>" === 'id';
                Swal.fire({
                    icon: 'info',
                    title: isId ? 'Catatan Penumpang (User)' : 'Passenger Notes Filtered',
                    text: isId ? `Menampilkan ${pendingRows.length} shift dengan catatan penumpang yang belum dibalas.` : `Showing ${pendingRows.length} shift(s) with unreplied passenger notes.`
                });
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('filter') === 'pending') {
                generateReport().then(() => {
                    document.getElementById('filterApproval').value = 'PENDING';
                    applyFiltersAndSort();
                });
            } else {
                const pendingNotesCount = <?php echo intval($pending_notes_count); ?>;
                const shouldShowPopup = <?php echo $show_note_popup ? 'true' : 'false'; ?>;
                const isId = "<?php echo $_SESSION['lang'] ?? 'en'; ?>" === 'id';

                if (shouldShowPopup && pendingNotesCount > 0) {
                    setTimeout(() => {
                        Swal.fire({
                            title: isId ? '🔔 Catatan Penumpang (User) Pending!' : '🔔 Pending Passenger Notes!',
                            html: isId ? 
                                `Terdapat <strong style="color: #d97706; font-size: 1.1rem;">${pendingNotesCount} shift</strong> dengan catatan dari penumpang yang belum dibalas oleh Admin.` : 
                                `You have <strong style="color: #d97706; font-size: 1.1rem;">${pendingNotesCount} shift(s)</strong> with unreplied notes from passengers.`,
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonColor: '#118DFF',
                            confirmButtonText: isId ? '🔍 Lihat Pesan Pending' : '🔍 View Pending Notes',
                            cancelButtonText: isId ? 'Nanti Saja' : 'Later'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                window.location.href = 'shift_messages.php?filter=pending';
                            }
                        });
                    }, 500);
                }
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
                        <div style="display: flex; gap: 4px; align-items: center;">
                            <select id="editShiftStartHour" class="pbi-input" style="flex: 1; padding: 6px;">
                                <?php for ($h = 0; $h < 24; $h++): $h_str = str_pad($h, 2, '0', STR_PAD_LEFT); ?>
                                    <option value="<?php echo $h_str; ?>"><?php echo $h_str; ?></option>
                                <?php endfor; ?>
                            </select>
                            <span>:</span>
                            <select id="editShiftStartMin" class="pbi-input" style="flex: 1; padding: 6px;">
                                <?php for ($m = 0; $m < 60; $m++): $m_str = str_pad($m, 2, '0', STR_PAD_LEFT); ?>
                                    <option value="<?php echo $m_str; ?>"><?php echo $m_str; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="pbi-label">Jam Keluar (Check-Out)</label>
                        <div style="display: flex; gap: 4px; align-items: center;">
                            <select id="editShiftEndHour" class="pbi-input" style="flex: 1; padding: 6px;">
                                <option value="">--</option>
                                <?php for ($h = 0; $h < 24; $h++): $h_str = str_pad($h, 2, '0', STR_PAD_LEFT); ?>
                                    <option value="<?php echo $h_str; ?>"><?php echo $h_str; ?></option>
                                <?php endfor; ?>
                            </select>
                            <span>:</span>
                            <select id="editShiftEndMin" class="pbi-input" style="flex: 1; padding: 6px;">
                                <option value="">--</option>
                                <?php for ($m = 0; $m < 60; $m++): $m_str = str_pad($m, 2, '0', STR_PAD_LEFT); ?>
                                    <option value="<?php echo $m_str; ?>"><?php echo $m_str; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
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

    <!-- ADMIN SHIFT DISCUSSION MODAL -->
    <div id="adminShiftCommentsModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 520px; border-radius: 16px; padding: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--glass-border); padding-bottom: 12px; margin-bottom: 16px;">
                <h3 style="margin: 0; font-size: 1.1rem; color: var(--pbi-blue);" id="adminCommentsModalTitle">
                    💬 Shift Notes & Discussion
                </h3>
                <button type="button" onclick="closeAdminShiftCommentsModal()" style="background: none; border: none; font-size: 1.5rem; font-weight: bold; cursor: pointer; color: var(--text-muted);">&times;</button>
            </div>

            <div id="adminCommentsContainer" style="max-height: 300px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; margin-bottom: 16px; padding-right: 4px;">
                <!-- Rendered dynamically -->
            </div>

            <form id="adminAddCommentForm" onsubmit="submitAdminShiftComment(event)">
                <input type="hidden" name="shift_id" id="adminCommentShiftId">
                <div style="display: flex; gap: 8px;">
                    <input type="text" name="comment" id="adminCommentInputText" class="pbi-input" placeholder="Type an Admin reply / note..." required style="flex: 1; padding: 10px 12px; font-size: 0.85rem;">
                    <button type="submit" class="btn-add" style="padding: 10px 16px; font-size: 0.85rem; border-radius: 6px; font-weight: 700;">
                        Send Reply
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAdminShiftCommentsModal(shiftId, driverName, shiftDate) {
            document.getElementById('adminCommentShiftId').value = shiftId;
            document.getElementById('adminCommentInputText').value = '';
            const container = document.getElementById('adminCommentsContainer');
            container.innerHTML = '<div style="text-align: center; color: var(--text-muted); padding: 16px;">Loading notes...</div>';
            document.getElementById('adminShiftCommentsModal').style.display = 'block';

            const formData = new FormData();
            formData.append('action', 'get_shift_comments');
            formData.append('shift_id', shiftId);

            fetch(`attendance_report.php?action=get_shift_comments&shift_id=${shiftId}`)
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    if (driverName) {
                        document.getElementById('adminCommentsModalTitle').innerText = `💬 ${driverName} (${shiftDate})`;
                    }
                    renderAdminComments(res.comments);
                } else {
                    container.innerHTML = '<div style="color: #dc2626; padding: 12px;">Failed to load notes.</div>';
                }
            })
            .catch(err => {
                container.innerHTML = '<div style="color: #dc2626; padding: 12px;">Network error.</div>';
            });
        }

        function closeAdminShiftCommentsModal() {
            document.getElementById('adminShiftCommentsModal').style.display = 'none';
        }

        function renderAdminComments(comments) {
            const container = document.getElementById('adminCommentsContainer');
            if (!comments || comments.length === 0) {
                container.innerHTML = '<div style="text-align: center; color: var(--text-muted); padding: 20px; font-size: 0.85rem;">No notes recorded for this shift.</div>';
                return;
            }

            container.innerHTML = comments.map(c => {
                const isAdmin = c.user_type === 'admin';
                const bg = isAdmin ? '#f0fdf4' : '#eff6ff';
                const border = isAdmin ? '#bbf7d0' : '#bfdbfe';
                const badgeColor = isAdmin ? '#166534' : '#1e40af';
                const badgeText = isAdmin ? 'Admin' : 'Passenger';
                
                return `
                    <div style="background: ${bg}; border: 1px solid ${border}; border-radius: 10px; padding: 10px 12px; font-size: 0.82rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <span style="font-weight: 700; color: ${badgeColor}; display: inline-flex; align-items: center; gap: 4px;">
                                ${isAdmin ? '🛡️' : '👤'} ${escapeHtml(c.user_name)} <small style="font-weight: 600; opacity: 0.8;">(${badgeText})</small>
                            </span>
                            <span style="font-size: 0.7rem; color: var(--text-muted);">${c.created_at}</span>
                        </div>
                        <div style="color: var(--text-primary); line-height: 1.4; word-break: break-word;">${escapeHtml(c.comment)}</div>
                    </div>
                `;
            }).join('');
            container.scrollTop = container.scrollHeight;
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
        }

        function submitAdminShiftComment(e) {
            e.preventDefault();
            const form = document.getElementById('adminAddCommentForm');
            const data = new FormData(form);

            fetch('attendance_report.php?action=admin_add_shift_comment', {
                method: 'POST',
                body: data
            })
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    const shiftId = document.getElementById('adminCommentShiftId').value;
                    const driverName = document.getElementById('adminCommentsModalTitle').innerText;
                    openAdminShiftCommentsModal(shiftId, driverName, '');
                    fetchData(); // refresh table
                } else {
                    alert(res.error || 'Failed to post reply.');
                }
            })
            .catch(err => {
                alert('Network error.');
            });
        }
    </script>
</body>
</html>
