<?php
/**
 * BULK APPROVE UTILITY
 * Auto-approves all trip expenses with shift_date BEFORE 2026-07-01.
 * Run this script ONCE via browser (admin only) or via PHP CLI on the server.
 *
 * Access: https://ops.framas.co.id/bulk_approve_before_july.php
 * 
 * For safety:
 *  - A CONFIRM step is shown first before any data is modified.
 *  - A dry-run count is displayed before committing.
 *  - After running, DELETE or RENAME this file to prevent re-execution.
 */

require_once 'config.php';

// --- Safety gate: admin only (browser access) ---
if (PHP_SAPI !== 'cli') {
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        die('<h2 style="font-family:sans-serif;color:red;">403 Forbidden – Admin login required.</h2>');
    }
}

$cutoff_date = '2026-07-01';
$approved_by = 'Admin (Bulk)';
$action = $_POST['action'] ?? 'preview';

// --- Count query (dry-run) ---
try {
    // Count trips (with shift_date < cutoff) that still have ANY non-approved expense
    $stmt_count_exp = $pdo->prepare("
        SELECT COUNT(te.id) as cnt
        FROM trip_expenses te
        JOIN trips t ON te.trip_id = t.id
        JOIN shifts s ON t.shift_id = s.id
        WHERE s.shift_date < :cutoff
          AND te.approval_status != 'approved'
    ");
    $stmt_count_exp->execute([':cutoff' => $cutoff_date]);
    $pending_expenses = $stmt_count_exp->fetchColumn();

    // Count distinct trips affected
    $stmt_count_trips = $pdo->prepare("
        SELECT COUNT(DISTINCT t.id) as cnt
        FROM trip_expenses te
        JOIN trips t ON te.trip_id = t.id
        JOIN shifts s ON t.shift_id = s.id
        WHERE s.shift_date < :cutoff
          AND te.approval_status != 'approved'
    ");
    $stmt_count_trips->execute([':cutoff' => $cutoff_date]);
    $pending_trips = $stmt_count_trips->fetchColumn();

    // Count distinct drivers affected
    $stmt_count_drivers = $pdo->prepare("
        SELECT COUNT(DISTINCT s.driver_id) as cnt
        FROM trip_expenses te
        JOIN trips t ON te.trip_id = t.id
        JOIN shifts s ON t.shift_id = s.id
        WHERE s.shift_date < :cutoff
          AND te.approval_status != 'approved'
    ");
    $stmt_count_drivers->execute([':cutoff' => $cutoff_date]);
    $pending_drivers = $stmt_count_drivers->fetchColumn();

} catch (Exception $e) {
    die('<pre style="color:red;">DB Error: ' . htmlspecialchars($e->getMessage()) . '</pre>');
}

// --- Execute bulk approval ---
$result = null;
if ($action === 'execute' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // 1. Approve all trip_expenses before cutoff
        $stmt_upd_exp = $pdo->prepare("
            UPDATE trip_expenses te
            JOIN trips t ON te.trip_id = t.id
            JOIN shifts s ON t.shift_id = s.id
            SET te.approval_status = 'approved',
                te.approved_by_name = :by,
                te.approved_at = NOW()
            WHERE s.shift_date < :cutoff
              AND te.approval_status != 'approved'
        ");
        $stmt_upd_exp->execute([':cutoff' => $cutoff_date, ':by' => $approved_by]);
        $rows_expenses = $stmt_upd_exp->rowCount();

        // 2. Also mark the trip's passenger_approval = 'approved' for those trips
        $stmt_upd_trips = $pdo->prepare("
            UPDATE trips t
            JOIN shifts s ON t.shift_id = s.id
            SET t.passenger_approval = 'approved',
                t.passenger_feedback = 'Auto-approved (before July 2026)'
            WHERE s.shift_date < :cutoff
              AND t.passenger_approval != 'approved'
        ");
        $stmt_upd_trips->execute([':cutoff' => $cutoff_date]);
        $rows_trips = $stmt_upd_trips->rowCount();

        $pdo->commit();
        $result = [
            'success' => true,
            'expenses_approved' => $rows_expenses,
            'trips_approved'    => $rows_trips,
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        $result = ['success' => false, 'error' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Bulk Approve – Before 1 July 2026</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', sans-serif; background: #f0f4f8; color: #1e293b; margin: 0; padding: 40px 20px; }
  .card { max-width: 640px; margin: 0 auto; background: #fff; border-radius: 14px; box-shadow: 0 4px 24px rgba(0,0,0,0.1); padding: 36px 40px; }
  h1 { margin: 0 0 6px; font-size: 1.5rem; color: #0f172a; }
  .subtitle { color: #64748b; font-size: 0.9rem; margin-bottom: 28px; }
  .badge { display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: 0.78rem; font-weight: 700; }
  .badge-orange { background: #fef3c7; color: #b45309; }
  .badge-green  { background: #dcfce7; color: #15803d; }
  .badge-red    { background: #fee2e2; color: #dc2626; }
  .stats { display: flex; gap: 14px; margin: 20px 0; flex-wrap: wrap; }
  .stat-box { flex: 1; min-width: 130px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; text-align: center; }
  .stat-box .num { font-size: 2rem; font-weight: 800; color: #e11d48; }
  .stat-box .lbl { font-size: 0.72rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 4px; }
  .warning-box { background: #fffbeb; border: 1px solid #f59e0b; border-radius: 8px; padding: 14px 18px; font-size: 0.85rem; color: #92400e; margin: 20px 0; }
  .warning-box strong { display: block; margin-bottom: 6px; }
  .btn { display: inline-block; padding: 11px 24px; border-radius: 8px; font-size: 0.9rem; font-weight: 700; cursor: pointer; border: none; text-decoration: none; }
  .btn-danger { background: #e11d48; color: #fff; }
  .btn-danger:hover { background: #be123c; }
  .btn-gray   { background: #e2e8f0; color: #475569; }
  .btn-gray:hover { background: #cbd5e1; }
  .success-box { background: #f0fdf4; border: 2px solid #16a34a; border-radius: 8px; padding: 20px 24px; }
  .success-box h2 { margin: 0 0 8px; color: #15803d; }
  .error-box { background: #fff1f2; border: 2px solid #e11d48; border-radius: 8px; padding: 20px 24px; }
  hr { border: none; border-top: 1px solid #e2e8f0; margin: 24px 0; }
  .footer-note { font-size: 0.78rem; color: #94a3b8; margin-top: 20px; }
</style>
</head>
<body>
<div class="card">

  <h1>⚡ Bulk Approve Expenses</h1>
  <p class="subtitle">Auto-approve all pending trip expenses with shift date <strong>before 1 July 2026</strong>.</p>

  <?php if ($result): ?>
    <?php if ($result['success']): ?>
      <div class="success-box">
        <h2>✅ Bulk Approval Completed!</h2>
        <p style="margin:0">
          <strong><?= number_format($result['expenses_approved']) ?></strong> expense line items approved.<br>
          <strong><?= number_format($result['trips_approved']) ?></strong> trips marked as approved.
        </p>
      </div>
      <hr>
      <div class="warning-box" style="background:#fef9c3;border-color:#ca8a04;color:#713f12;">
        <strong>⚠️ Action Required:</strong>
        Now that this script has been executed, please <strong>delete or rename</strong> the file
        <code>bulk_approve_before_july.php</code> from the server to prevent accidental re-runs.
      </div>
      <a href="report.php" class="btn btn-gray">← Back to Report</a>
    <?php else: ?>
      <div class="error-box">
        <h2 style="color:#dc2626;margin:0 0 8px;">❌ Error During Approval</h2>
        <p style="margin:0;color:#7f1d1d;"><?= htmlspecialchars($result['error']) ?></p>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <!-- Preview / Confirmation Screen -->

    <?php if ($pending_expenses == 0): ?>
      <div class="success-box">
        <h2>✅ Nothing to Do</h2>
        <p style="margin:0">All expenses before <strong><?= $cutoff_date ?></strong> are already approved!</p>
      </div>
      <hr>
      <a href="report.php" class="btn btn-gray">← Back to Report</a>
    <?php else: ?>

      <div class="stats">
        <div class="stat-box">
          <div class="num"><?= number_format($pending_expenses) ?></div>
          <div class="lbl">Expense Items<br>to Approve</div>
        </div>
        <div class="stat-box">
          <div class="num"><?= number_format($pending_trips) ?></div>
          <div class="lbl">Trips<br>Affected</div>
        </div>
        <div class="stat-box">
          <div class="num"><?= number_format($pending_drivers) ?></div>
          <div class="lbl">Drivers<br>Affected</div>
        </div>
      </div>

      <div class="warning-box">
        <strong>⚠️ Heads up — This action cannot be undone!</strong>
        All <strong><?= number_format($pending_expenses) ?></strong> pending expense items for trips
        with a shift date before <strong><?= $cutoff_date ?></strong> will be marked as
        <span class="badge badge-green">Approved</span> instantly.
      </div>

      <form method="POST" onsubmit="return confirm('Are you absolutely sure? This will approve ALL pending expenses before July 2026.');">
        <input type="hidden" name="action" value="execute">
        <button type="submit" class="btn btn-danger">
          ✅ Confirm – Approve All <?= number_format($pending_expenses) ?> Items
        </button>
        &nbsp;&nbsp;
        <a href="report.php" class="btn btn-gray">Cancel</a>
      </form>

      <p class="footer-note">
        This script sets <code>approval_status = 'approved'</code> on <code>trip_expenses</code>
        and <code>passenger_approval = 'approved'</code> on <code>trips</code> for all records
        where <code>shifts.shift_date &lt; <?= $cutoff_date ?></code>.
      </p>
    <?php endif; ?>
  <?php endif; ?>

</div>
</body>
</html>
