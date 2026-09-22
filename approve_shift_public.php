<?php
require_once 'config.php';

$shift_id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$secret_key = 'framas_shift_secret_2026';

$valid = false;
if ($shift_id && $token) {
    $expected_token = md5($shift_id . $secret_key);
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
            <p>This approval link is invalid or has expired. Please check the URL or ask the system administrator to send a new request.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Fetch shift details and supervisor info
$stmt = $pdo->prepare("SELECT s.*, u.full_name as driver_name, u.nik, p.name as supervisor_name 
                      FROM shifts s 
                      JOIN users u ON s.driver_id = u.id 
                      JOIN master_passengers p ON u.supervisor_id = p.id 
                      WHERE s.id = ?");
$stmt->execute([$shift_id]);
$shift = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shift) {
    echo "Shift record not found.";
    exit;
}

$success = false;
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $note = trim($_POST['supervisor_note'] ?? '');
    
    if ($shift['approval_status'] !== 'pending') {
        $msg = "This shift has already been processed previously.";
    } else {
        if ($action === 'approve') {
            $stmt = $pdo->prepare("UPDATE shifts 
                                  SET approval_status = 'approved', approved_at = CURRENT_TIMESTAMP, approved_by_name = ?, supervisor_note = ? 
                                  WHERE id = ?");
            $stmt->execute([$shift['supervisor_name'], $note, $shift_id]);
            $success = true;
            $msg = "Overtime claim has been successfully approved! Thank you.";
            // Reload updated state
            $shift['approval_status'] = 'approved';
            $shift['approved_by_name'] = $shift['supervisor_name'];
            $shift['supervisor_note'] = $note;
        } elseif ($action === 'reject') {
            $stmt = $pdo->prepare("UPDATE shifts 
                                  SET approval_status = 'rejected', supervisor_note = ? 
                                  WHERE id = ?");
            $stmt->execute([$note, $shift_id]);
            $success = true;
            $msg = "Overtime claim has been rejected.";
            // Reload updated state
            $shift['approval_status'] = 'rejected';
            $shift['supervisor_note'] = $note;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Review Overtime - framas Transport App</title>
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
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); margin: 0; padding: 20px; color: var(--text); display: flex; justify-content: center; align-items: center; min-height: 100vh; box-sizing: border-box; }
        .container { background: #ffffff; border-radius: 16px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.02); border: 1px solid #e2e8f0; width: 100%; max-width: 500px; padding: 24px; box-sizing: border-box; }
        .header { text-align: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 16px; margin-bottom: 20px; }
        .logo { font-size: 1.3rem; font-weight: bold; color: var(--primary); margin-bottom: 4px; }
        .sub-header { font-size: 0.85rem; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; }
        
        .alert { padding: 16px; border-radius: 8px; font-weight: 600; font-size: 0.95rem; text-align: center; margin-bottom: 20px; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-info { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        
        .details-grid { display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px; }
        .detail-row { display: flex; justify-content: space-between; border-bottom: 1px dashed #e2e8f0; padding-bottom: 8px; }
        .detail-label { font-size: 0.85rem; color: #64748b; }
        .detail-value { font-size: 0.9rem; font-weight: 600; text-align: right; }
        
        .note-area { width: 100%; min-height: 80px; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: inherit; font-size: 0.9rem; margin-bottom: 20px; box-sizing: border-box; resize: vertical; }
        .note-area:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(17,141,255,0.2); }
        
        .btn-group { display: flex; gap: 12px; }
        .btn { flex: 1; border: none; padding: 14px; border-radius: 8px; color: #ffffff; font-weight: bold; cursor: pointer; font-size: 1rem; transition: transform 0.1s, opacity 0.1s; }
        .btn:active { transform: scale(0.98); }
        .btn-approve { background: var(--success); }
        .btn-approve:hover { opacity: 0.9; }
        .btn-reject { background: var(--danger); }
        .btn-reject:hover { opacity: 0.9; }
        
        .badge { font-size: 0.75rem; font-weight: bold; padding: 4px 8px; border-radius: 4px; text-transform: uppercase; display: inline-block; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-approved { background: #dcfce7; color: #166534; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">framas Transport App</div>
            <div class="sub-header">Overtime Approval Portal</div>
        </div>

        <?php if ($msg): ?>
            <div class="alert <?php echo $success ? 'alert-success' : 'alert-info'; ?>">
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <div class="details-grid">
            <div class="detail-row">
                <span class="detail-label">Supervisor</span>
                <span class="detail-value"><?php echo htmlspecialchars($shift['supervisor_name']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Driver Name</span>
                <span class="detail-value"><?php echo htmlspecialchars($shift['driver_name']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Shift Date</span>
                <span class="detail-value"><?php echo date('d F Y', strtotime($shift['shift_date'])); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Clock In / Out</span>
                <span class="detail-value"><?php echo htmlspecialchars($shift['start_time']) . ' - ' . ($shift['end_time'] ? htmlspecialchars($shift['end_time']) : '-'); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">OT Duration (Hours)</span>
                <span class="detail-value" style="color: var(--primary); font-size: 1.1rem; font-weight: bold;"><?php echo number_format($shift['real_ot'], 2); ?> Hours</span>
            </div>
            <div class="detail-row" style="border:none;">
                <span class="detail-label">Status</span>
                <span class="detail-value">
                    <span class="badge badge-<?php echo $shift['approval_status']; ?>">
                        <?php echo htmlspecialchars($shift['approval_status']); ?>
                    </span>
                </span>
            </div>
        </div>

        <?php if ($shift['approval_status'] === 'pending'): ?>
            <form method="POST">
                <input type="hidden" name="id" value="<?php echo $shift_id; ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                
                <label for="supervisor_note" style="font-size: 0.8rem; font-weight: bold; color: #64748b; display: block; margin-bottom: 6px;">Notes / Feedback (Optional)</label>
                <textarea name="supervisor_note" id="supervisor_note" class="note-area" placeholder="Enter notes or comments here..."></textarea>
                
                <div class="btn-group">
                    <button type="submit" name="action" value="reject" class="btn btn-reject">Reject Claim</button>
                    <button type="submit" name="action" value="approve" class="btn btn-approve">Approve Claim</button>
                </div>
            </form>
        <?php else: ?>
            <?php if (!empty($shift['supervisor_note'])): ?>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-size: 0.85rem; margin-top: 10px;">
                    <strong style="display:block; margin-bottom: 4px; color: #64748b;">Supervisor Notes:</strong>
                    <div style="color: #475569; white-space: pre-wrap;"><?php echo htmlspecialchars($shift['supervisor_note']); ?></div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
