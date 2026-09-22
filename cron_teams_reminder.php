<?php
/**
 * Automated Teams Overtime Approval Reminder
 *
 * Can be triggered via:
 * 1. Google Apps Script (UrlFetchApp): https://ops.framas.co.id/cron_teams_reminder.php?key=framas_shift_secret_2026
 * 2. Web Admin (master_data.php)
 * 3. CLI: php cron_teams_reminder.php [--dry-run]
 */

require_once 'config.php';

// Authentication Check
$is_cli = (php_sapi_name() === 'cli');
$valid_keys = ['framas_shift_secret_2026', 'framas_ops_deploy_9f8e7d6c5b4a321'];
$provided_key = $_GET['key'] ?? $_POST['key'] ?? '';
$is_admin = isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin';

if (!$is_cli && !$is_admin && !in_array($provided_key, $valid_keys, true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Invalid key.']);
    exit;
}

$is_dry_run = false;
if ($is_cli) {
    global $argv;
    $is_dry_run = in_array('--dry-run', $argv ?? []);
} else {
    $is_dry_run = isset($_GET['dry_run']) && $_GET['dry_run'] == '1';
}

$lang = ($_GET['lang'] ?? $_SESSION['lang'] ?? 'en') === 'id' ? 'id' : 'en';

// Calculate date range for "Minggu Kemarin" (Last week Monday to Sunday)
if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) {
    $period_start = $_GET['start_date'];
    $period_end = $_GET['end_date'];
} else {
    $today_time = time();
    $day_of_week = intval(date('N', $today_time)); // 1 (Mon) .. 7 (Sun)
    $this_mon = strtotime("-" . ($day_of_week - 1) . " days", strtotime(date('Y-m-d', $today_time)));
    $last_mon = strtotime("-7 days", $this_mon);
    $last_sun = strtotime("+6 days", $last_mon);

    $period_start = date('Y-m-d', $last_mon);
    $period_end = date('Y-m-d', $last_sun);
}

// 1. Fetch all passengers/supervisors with oto_notif = 'Y' and valid email
$stmt_supervisors = $pdo->prepare("
    SELECT id, name, email, wa_no, oto_notif 
    FROM master_passengers 
    WHERE oto_notif = 'Y' 
      AND email IS NOT NULL 
      AND email != '' 
      AND email != '-'
    ORDER BY name ASC
");
$stmt_supervisors->execute();
$supervisors = $stmt_supervisors->fetchAll(PDO::FETCH_ASSOC);

$total_eligible = count($supervisors);
$total_sent = 0;
$total_skipped = 0;
$details = [];
$secret_key = 'framas_shift_secret_2026';

foreach ($supervisors as $spv) {
    $spv_id = intval($spv['id']);
    $spv_name = $spv['name'];
    $spv_email = trim($spv['email']);

    // 2. Check pending shifts for drivers under this supervisor for LAST WEEK (Senin - Minggu)
    $stmt_shifts = $pdo->prepare("
        SELECT s.id, s.shift_date, s.real_ot, s.status, s.approval_status, u.full_name as driver_name 
        FROM shifts s 
        JOIN users u ON s.driver_id = u.id 
        WHERE u.supervisor_id = ? 
          AND s.shift_date BETWEEN ? AND ?
          AND s.status = 'completed' 
          AND s.approval_status = 'pending' 
          AND s.real_ot > 0 
        ORDER BY s.shift_date ASC, u.full_name ASC
    ");
    $stmt_shifts->execute([$spv_id, $period_start, $period_end]);
    $pending_shifts = $stmt_shifts->fetchAll(PDO::FETCH_ASSOC);

    // If no pending shifts: Skip sending!
    if (empty($pending_shifts)) {
        $total_skipped++;
        $status_text = ($lang === 'id') 
            ? 'Dilewati (Tidak ada lembur pending minggu kemarin)' 
            : 'Skipped (No pending overtime for last week)';
        $details[] = [
            'id' => $spv_id,
            'name' => $spv_name,
            'email' => $spv_email,
            'status' => $status_text,
            'period' => $period_start . ' to ' . $period_end,
            'pending_shifts_count' => 0,
            'total_hours' => 0
        ];
        continue;
    }

    // 3. Summarize by driver
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

    $start_date = $period_start;
    $end_date = $period_end;

    // 4. Generate token and direct approval link
    $token = md5($spv_id . $start_date . $end_date . $secret_key);
    $approval_url = "https://ops.framas.co.id/passenger_dashboard.php?supervisor_id=" . $spv_id . "&start_date=" . urlencode($start_date) . "&end_date=" . urlencode($end_date) . "&token=" . $token;

    // 5. Compose Teams message IN ENGLISH
    $start_fmt = date('d M Y', strtotime($start_date));
    $end_fmt = date('d M Y', strtotime($end_date));

    $msg = "Dear <b>" . htmlspecialchars($spv_name) . "</b>,<br><br>";
    $msg .= "Please review and approve the driver overtime claims for last week's period (<b>" . $start_fmt . " to " . $end_fmt . "</b>):<br><br>";
    foreach ($driver_summary as $dname => $info) {
        $day_label = $info['shifts_count'] > 1 ? "days" : "day";
        $msg .= "• <b>" . htmlspecialchars($dname) . "</b>: " . number_format($info['hours'], 2) . " Hours (" . $info['shifts_count'] . " " . $day_label . ")<br>";
    }
    $shift_label = count($pending_shifts) > 1 ? "pending shifts" : "pending shift";
    $msg .= "<br>Total: <b>" . number_format($total_hours, 2) . " Hours</b> (" . count($pending_shifts) . " " . $shift_label . ").<br><br>";
    $msg .= "👉 Please click the link below to review and approve:<br>";
    $msg .= "<a href=\"" . $approval_url . "\"><b>Open Overtime Approval Dashboard</b></a><br><br>";
    $msg .= "<small><i>Automated notification from Framas Transport System.</i></small>";

    // 6. Send to Teams API (unless dry-run)
    $http_code = 0;
    $api_resp = '';
    if (!$is_dry_run) {
        $teams_api_url = "https://api.framas.web.id/framas-api/teams/send.php?to=" . urlencode($spv_email) . "&msg=" . urlencode($msg);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $teams_api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $api_resp = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $http_code = 200;
        $api_resp = 'DRY_RUN';
    }

    $is_success = ($http_code == 200);
    if ($is_success) {
        $total_sent++;
    }

    $status_sent = $is_dry_run 
        ? ($lang === 'id' ? 'Simulasi OK (Dry Run)' : 'Dry Run OK') 
        : ($is_success 
            ? ($lang === 'id' ? 'Terkirim' : 'Sent') 
            : ($lang === 'id' ? 'Gagal (HTTP ' . $http_code . ')' : 'Failed (HTTP ' . $http_code . ')'));

    $details[] = [
        'id' => $spv_id,
        'name' => $spv_name,
        'email' => $spv_email,
        'status' => $status_sent,
        'pending_shifts_count' => count($pending_shifts),
        'total_hours' => number_format($total_hours, 2),
        'approval_url' => $approval_url
    ];

    // Log to .teams_notif.log
    $log_line = sprintf(
        "[%s] Recipient: %s (%s) | Pending: %d shifts (%.2f hrs) | Status: %s | Resp: %s\n",
        date('Y-m-d H:i:s'),
        $spv_name,
        $spv_email,
        count($pending_shifts),
        $total_hours,
        $is_success ? 'SUCCESS' : 'FAILED_' . $http_code,
        substr(trim($api_resp), 0, 100)
    );
    @file_put_contents(__DIR__ . '/.teams_notif.log', $log_line, FILE_APPEND);
}

// 7. Output Result
$result = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'is_dry_run' => $is_dry_run,
    'total_eligible_supervisors' => $total_eligible,
    'total_sent' => $total_sent,
    'total_skipped' => $total_skipped,
    'details' => $details
];

if ($is_cli) {
    echo "====================================================\n";
    echo "    FRAMAS TEAMS OVERTIME REMINDER - RUN REPORT     \n";
    echo "====================================================\n";
    echo "Timestamp: " . $result['timestamp'] . ($is_dry_run ? ' [DRY RUN]' : '') . "\n";
    echo "Total Supervisors (Oto_Notif=Y): " . $total_eligible . "\n";
    echo "Sent: " . $total_sent . " | Skipped (No Pending): " . $total_skipped . "\n\n";
    foreach ($details as $d) {
        echo sprintf(" - [%s] %s (%s): %d pending (%.2f hrs)\n", $d['status'], $d['name'], $d['email'], $d['pending_shifts_count'], $d['total_hours']);
    }
    echo "====================================================\n";
} else {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($result, JSON_PRETTY_PRINT);
}
