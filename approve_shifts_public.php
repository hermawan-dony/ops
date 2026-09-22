<?php
require_once 'config.php';

$supervisor_id = intval($_GET['supervisor_id'] ?? $_POST['supervisor_id'] ?? 0);
$start_date = $_GET['start_date'] ?? $_POST['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? $_POST['end_date'] ?? '';
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$secret_key = 'framas_shift_secret_2026';

$valid = false;
if ($supervisor_id && $start_date && $end_date && $token) {
    $expected_token = md5($supervisor_id . $start_date . $end_date . $secret_key);
    if (hash_equals($expected_token, $token)) {
        $valid = true;
    }
}

if (!$valid) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Invalid Link - framas Transport App</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            body { font-family: 'Segoe UI', sans-serif; background: #f8fafc; margin:0; display:flex; align-items:center; justify-content:center; min-height:100vh; color: #1e293b; }
            .card { background: white; padding: 32px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; max-width: 400px; text-align: center; }
            h2 { color: #ef4444; margin-top:0; }
            p { color: #64748b; font-size: 0.95rem; line-height: 1.5; }
        </style>
    </head>
    <body>
        <div class="card">
            <h2>⚠️ Invalid Link</h2>
            <p>This approval link is invalid or has expired. Please ask the administrator to generate a new link.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Fetch supervisor details
$stmt = $pdo->prepare("SELECT * FROM master_passengers WHERE id = ?");
$stmt->execute([$supervisor_id]);
$supervisor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$supervisor) {
    echo "Supervisor not found.";
    exit;
}

$msg = '';
$success = false;

// Handle Actions (Approve / Reject Selected)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $selected_shifts = $_POST['shifts'] ?? [];
    $note = trim($_POST['supervisor_note'] ?? '');
    
    if (empty($selected_shifts)) {
        $msg = "No shifts were selected for processing.";
    } else {
        $in_placeholder = implode(',', array_fill(0, count($selected_shifts), '?'));
        
        if ($action === 'approve') {
            $sql = "UPDATE shifts SET approval_status = 'approved', approved_at = CURRENT_TIMESTAMP, approved_by_name = ?, supervisor_note = ? WHERE id IN ($in_placeholder) AND approval_status = 'pending'";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$supervisor['name'], $note], $selected_shifts));
            $success = true;
            $msg = "Successfully APPROVED selected overtime claims!";
        } elseif ($action === 'reject') {
            $sql = "UPDATE shifts SET approval_status = 'rejected', supervisor_note = ? WHERE id IN ($in_placeholder) AND approval_status = 'pending'";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$note], $selected_shifts));
            $success = true;
            $msg = "Successfully REJECTED selected overtime claims.";
        }
    }
}

// Fetch all pending overtime shifts for this supervisor's drivers in the date range
$sql = "SELECT s.*, u.full_name as driver_name, u.nik 
        FROM shifts s 
        JOIN users u ON s.driver_id = u.id 
        WHERE u.supervisor_id = ? AND s.shift_date BETWEEN ? AND ? AND s.approval_status = 'pending' AND s.real_ot > 0 
        ORDER BY s.shift_date ASC, u.full_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$supervisor_id, $start_date, $end_date]);
$pending_shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Review Overtime Claims - framas Transport App</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #118DFF;
            --success: #10b981;
            --danger: #ef4444;
            --bg: #f1f5f9;
            --text: #1e293b;
        }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); margin: 0; padding: 16px; color: var(--text); display: flex; justify-content: center; align-items: flex-start; min-height: 100vh; box-sizing: border-box; }
        .container { background: #ffffff; border-radius: 16px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.02); border: 1px solid #e2e8f0; width: 100%; max-width: 600px; padding: 24px; box-sizing: border-box; margin: 20px 0; }
        .header { text-align: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 16px; margin-bottom: 20px; }
        .logo { font-size: 1.3rem; font-weight: bold; color: var(--primary); margin-bottom: 4px; }
        .sub-header { font-size: 0.85rem; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; }
        
        .alert { padding: 14px; border-radius: 8px; font-weight: 600; font-size: 0.9rem; text-align: center; margin-bottom: 20px; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-info { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }

        .period-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 20px; font-size: 0.9rem; }
        .period-row { display: flex; justify-content: space-between; margin-bottom: 4px; }
        .period-label { color: #64748b; }
        .period-val { font-weight: 600; }

        .shift-card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 12px; position: relative; transition: border-color 0.2s; display: flex; align-items: flex-start; gap: 12px; }
        .shift-card:hover { border-color: var(--primary); }
        .shift-checkbox { transform: scale(1.2); margin-top: 4px; cursor: pointer; }
        .shift-info { flex: 1; }
        .driver-name { font-weight: 700; font-size: 0.95rem; color: #0f172a; margin-bottom: 4px; }
        .shift-meta { font-size: 0.8rem; color: #64748b; display: flex; flex-wrap: wrap; gap: 12px; margin-top: 6px; }
        .ot-badge { font-weight: 700; color: var(--primary); font-size: 0.9rem; margin-top: 2px; }

        .note-area { width: 100%; min-height: 70px; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: inherit; font-size: 0.9rem; margin-bottom: 20px; box-sizing: border-box; resize: vertical; }
        .note-area:focus { outline: none; border-color: var(--primary); }
        
        .btn-group { display: flex; gap: 12px; }
        .btn { flex: 1; border: none; padding: 14px; border-radius: 8px; color: #ffffff; font-weight: bold; cursor: pointer; font-size: 0.95rem; transition: opacity 0.1s; }
        .btn:hover { opacity: 0.9; }
        .btn-approve { background: var(--success); }
        .btn-reject { background: var(--danger); }

        .select-all-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; font-size: 0.85rem; color: #64748b; font-weight: 600; padding: 0 4px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">framas Transport App</div>
            <div class="sub-header">Supervisor Overtime Approval</div>
        </div>

        <?php if ($msg): ?>
            <div class="alert <?php echo $success ? 'alert-success' : 'alert-info'; ?>">
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <div class="period-box">
            <div class="period-row">
                <span class="period-label">Supervisor:</span>
                <span class="period-val"><?php echo htmlspecialchars($supervisor['name']); ?></span>
            </div>
            <div class="period-row" style="margin: 0;">
                <span class="period-label">Period:</span>
                <span class="period-val"><?php echo date('d F Y', strtotime($start_date)) . ' - ' . date('d F Y', strtotime($end_date)); ?></span>
            </div>
        </div>

        <?php if (empty($pending_shifts)): ?>
            <div style="text-align: center; padding: 30px; color: #64748b;">
                <h3 style="margin-top: 0;">🎉 All caught up!</h3>
                <p style="margin: 0;">There are no pending overtime claims for your drivers in this period.</p>
            </div>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="supervisor_id" value="<?php echo $supervisor_id; ?>">
                <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                <div class="select-all-bar">
                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                        <input type="checkbox" checked onchange="toggleSelectAll(this)" style="transform:scale(1.1); cursor:pointer;"> Select / Deselect All
                    </label>
                    <span><?php echo count($pending_shifts); ?> Claims Pending</span>
                </div>

                <?php foreach ($pending_shifts as $s): ?>
                    <div class="shift-card">
                        <input type="checkbox" name="shifts[]" value="<?php echo $s['id']; ?>" class="shift-checkbox" checked>
                        <div class="shift-info">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                <span class="driver-name"><?php echo htmlspecialchars($s['driver_name']); ?></span>
                                <span class="ot-badge"><?php echo number_format($s['real_ot'], 2); ?> Hrs</span>
                            </div>
                            <div class="shift-meta">
                                <span><strong>Date:</strong> <?php echo date('d F Y', strtotime($s['shift_date'])); ?></span>
                                <span><strong>Time:</strong> <?php echo htmlspecialchars($s['start_time']) . ' - ' . ($s['end_time'] ? htmlspecialchars($s['end_time']) : '-'); ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <label for="supervisor_note" style="font-size: 0.8rem; font-weight: bold; color: #64748b; display: block; margin-bottom: 6px; margin-top: 16px;">Notes / Feedback (Optional)</label>
                <textarea name="supervisor_note" id="supervisor_note" class="note-area" placeholder="Enter comments here..."></textarea>
                
                <div class="btn-group">
                    <button type="submit" name="action" value="reject" class="btn btn-reject">Reject Selected</button>
                    <button type="submit" name="action" value="approve" class="btn btn-approve">Approve Selected</button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script>
        function toggleSelectAll(masterCb) {
            const checkboxes = document.querySelectorAll('.shift-checkbox');
            checkboxes.forEach(cb => cb.checked = masterCb.checked);
        }
    </script>
</body>
</html>
