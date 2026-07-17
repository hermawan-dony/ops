<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php'); exit;
}

// Handle POST actions for trip edit/delete by admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'edit_trip_admin') {
        $trip_id = intval($_POST['trip_id']);
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'] ? $_POST['end_time'] : null;
        
        // 1. Update trip times
        $stmt = $pdo->prepare("UPDATE trips SET start_time = ?, end_time = ? WHERE id = ?");
        $stmt->execute([$start_time, $end_time, $trip_id]);
        
        // 2. Process expenses if submitted
        $expense_types = $_POST['expense_type'] ?? [];
        $expense_amounts = $_POST['expense_amount'] ?? [];
        $expense_litres = $_POST['expense_litre'] ?? [];
        $expense_notes = $_POST['expense_note'] ?? [];
        $expense_approved = $_POST['expense_approved'] ?? [];
        
        // Load all expenses for this trip to see if they are all approved
        $stmt_check_exp = $pdo->prepare("SELECT id FROM trip_expenses WHERE trip_id = ?");
        $stmt_check_exp->execute([$trip_id]);
        $all_expenses = $stmt_check_exp->fetchAll(PDO::FETCH_ASSOC);
        
        $total_expenses_count = count($all_expenses);
        $approved_count = 0;
        
        foreach ($all_expenses as $exp) {
            $exp_id = $exp['id'];
            if (isset($expense_types[$exp_id])) {
                $type = $expense_types[$exp_id];
                $amt = floatval($expense_amounts[$exp_id] ?? 0);
                $lit = ($type === 'gasoline') ? floatval($expense_litres[$exp_id] ?? null) : null;
                $note = $expense_notes[$exp_id] ?? '';
                
                $status = isset($expense_approved[$exp_id]) ? 'approved' : 'pending';
                $approved_by = isset($expense_approved[$exp_id]) ? 'Admin' : null;
                $approved_at = isset($expense_approved[$exp_id]) ? date('Y-m-d H:i:s') : null;
                
                if ($status === 'approved') {
                    $approved_count++;
                }
                
                $stmt_up = $pdo->prepare("UPDATE trip_expenses 
                                          SET expense_type = ?, amount = ?, litre = ?, supervisor_note = ?, 
                                              approval_status = ?, approved_by_name = ?, approved_at = ? 
                                          WHERE id = ?");
                $stmt_up->execute([$type, $amt, $lit, $note, $status, $approved_by, $approved_at, $exp_id]);
            }
        }
        
        // 3. Set passenger_approval to 'approved' if all expenses are approved (or if there are no expenses at all)
        $trip_status = ($approved_count === $total_expenses_count) ? 'approved' : 'pending';
        $trip_feedback = ($approved_count === $total_expenses_count) ? 'Approved by Admin' : '';
        
        $stmt_trip_up = $pdo->prepare("UPDATE trips SET passenger_approval = ?, passenger_feedback = ? WHERE id = ?");
        $stmt_trip_up->execute([$trip_status, $trip_feedback, $trip_id]);
        
        header("Location: report.php?msg=" . urlencode("Trip TX-{$trip_id} and expenses updated successfully"));
        exit;
    } elseif ($_POST['action'] === 'edit_expense_ajax') {
        header('Content-Type: application/json');
        try {
            $expense_id   = intval($_POST['expense_id']);
            $expense_type = $_POST['expense_type'];
            $amount       = floatval($_POST['amount']);
            $litre        = isset($_POST['litre']) && $_POST['litre'] !== '' ? floatval($_POST['litre']) : null;
            $note         = $_POST['note'] ?? '';
            $approved     = isset($_POST['approved']) && $_POST['approved'] === '1';
            $status       = $approved ? 'approved' : 'pending';
            $approved_by  = $approved ? 'Admin' : null;
            $approved_at  = $approved ? date('Y-m-d H:i:s') : null;

            $stmt = $pdo->prepare("UPDATE trip_expenses
                SET expense_type=?, amount=?, litre=?, supervisor_note=?,
                    approval_status=?, approved_by_name=?, approved_at=?
                WHERE id=?");
            $stmt->execute([$expense_type, $amount, $litre, $note,
                            $status, $approved_by, $approved_at, $expense_id]);

            // Recalculate trip approval
            $trip_row = $pdo->prepare("SELECT trip_id FROM trip_expenses WHERE id=?");
            $trip_row->execute([$expense_id]);
            $trip_id = $trip_row->fetchColumn();

            $counts = $pdo->prepare("SELECT COUNT(*) as total,
                SUM(CASE WHEN approval_status='approved' THEN 1 ELSE 0 END) as approved_cnt
                FROM trip_expenses WHERE trip_id=?");
            $counts->execute([$trip_id]);
            $c = $counts->fetch();
            $trip_status = ($c['approved_cnt'] >= $c['total']) ? 'approved' : 'pending';
            $pdo->prepare("UPDATE trips SET passenger_approval=? WHERE id=?")->execute([$trip_status, $trip_id]);

            // Return updated expense row totals for the group
            echo json_encode(['success' => true, 'expense_id' => $expense_id,
                              'trip_id' => $trip_id, 'amount' => $amount,
                              'expense_type' => $expense_type, 'litre' => $litre,
                              'approval_status' => $status]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    } elseif ($_POST['action'] === 'get_group_trips') {
        header('Content-Type: application/json');
        try {
            $driver_id  = intval($_POST['driver_id']);
            $shift_date = $_POST['shift_date'];

            $stmt = $pdo->prepare("
                SELECT t.id, t.start_time, t.end_time, t.km_start, t.km_end,
                       t.passenger_approval, d.name as dest_name, p.name as pass_name
                FROM trips t
                JOIN shifts s ON t.shift_id = s.id
                LEFT JOIN master_destinations d ON t.destination_id = d.id
                LEFT JOIN master_passengers p ON t.passenger_id = p.id
                WHERE s.driver_id = ? AND s.shift_date = ? AND t.status = 'completed'
                ORDER BY t.start_time ASC
            ");
            $stmt->execute([$driver_id, $shift_date]);
            $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($trips as &$trip) {
                $exp_stmt = $pdo->prepare("SELECT * FROM trip_expenses WHERE trip_id = ? ORDER BY expense_type ASC");
                $exp_stmt->execute([$trip['id']]);
                $trip['expenses'] = $exp_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode(['success' => true, 'trips' => $trips]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    } elseif ($_POST['action'] === 'edit_expense_admin') {
        $expense_id = intval($_POST['expense_id']);
        $expense_type = $_POST['expense_type'];
        $amount = $_POST['amount'];
        $litre = $_POST['litre'] ? $_POST['litre'] : null;
        
        $stmt = $pdo->prepare("UPDATE trip_expenses SET expense_type = ?, amount = ?, litre = ? WHERE id = ?");
        $stmt->execute([$expense_type, $amount, $litre, $expense_id]);
        
        header("Location: report.php?msg=" . urlencode("Expense ID-{$expense_id} updated"));
        exit;
    } elseif ($_POST['action'] === 'approve_expense_admin') {
        header('Content-Type: application/json');
        try {
            $expense_id = intval($_POST['expense_id']);
            $stmt = $pdo->prepare("UPDATE trip_expenses SET approval_status = 'approved', approved_by_name = ?, approved_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute(['Admin', $expense_id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    } elseif ($_POST['action'] === 'approve_group_admin') {
        header('Content-Type: application/json');
        try {
            $driver_id = intval($_POST['driver_id']);
            $shift_date = $_POST['shift_date'];
            
            // 1. Get all completed trips for this driver on this date
            $stmt = $pdo->prepare("
                SELECT t.id 
                FROM trips t
                JOIN shifts s ON t.shift_id = s.id
                WHERE s.driver_id = ? AND s.shift_date = ? AND t.status = 'completed'
            ");
            $stmt->execute([$driver_id, $shift_date]);
            $trip_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($trip_ids)) {
                $in_clause = implode(',', array_fill(0, count($trip_ids), '?'));
                
                // 2. Approve all expenses for these trips
                $stmt_exp = $pdo->prepare("
                    UPDATE trip_expenses 
                    SET approval_status = 'approved', approved_by_name = 'Admin', approved_at = CURRENT_TIMESTAMP 
                    WHERE trip_id IN ($in_clause)
                ");
                $stmt_exp->execute(array_merge($trip_ids));
                
                // 3. Approve the trips themselves (passenger_approval = 'approved')
                $stmt_trips = $pdo->prepare("
                    UPDATE trips 
                    SET passenger_approval = 'approved', passenger_feedback = 'Approved by Admin' 
                    WHERE id IN ($in_clause)
                ");
                $stmt_trips->execute(array_merge($trip_ids));
            }
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    } elseif ($_POST['action'] === 'delete_trip_admin') {
        $trip_id = intval($_POST['trip_id']);
        
        // Fetch trip info to delete photos
        $stmt_trip = $pdo->prepare("SELECT km_start_photo, km_end_photo FROM trips WHERE id = ?");
        $stmt_trip->execute([$trip_id]);
        $trip = $stmt_trip->fetch();
        
        if ($trip) {
            // Delete trip start photo
            if ($trip['km_start_photo']) {
                @unlink('uploads/' . $trip['km_start_photo']);
                @unlink('uploads/thumb_' . $trip['km_start_photo']);
            }
            // Delete trip end photo
            if ($trip['km_end_photo']) {
                @unlink('uploads/' . $trip['km_end_photo']);
                @unlink('uploads/thumb_' . $trip['km_end_photo']);
            }
            
            // Delete associated expense photos
            $stmt_exp = $pdo->prepare("SELECT photo FROM trip_expenses WHERE trip_id = ?");
            $stmt_exp->execute([$trip_id]);
            $expenses = $stmt_exp->fetchAll();
            foreach ($expenses as $e) {
                if ($e['photo']) {
                    @unlink('uploads/' . $e['photo']);
                    @unlink('uploads/thumb_' . $e['photo']);
                }
            }
            
            // Delete trip expenses records
            $pdo->prepare("DELETE FROM trip_expenses WHERE trip_id = ?")->execute([$trip_id]);
            
            // Delete trip record
            $pdo->prepare("DELETE FROM trips WHERE id = ?")->execute([$trip_id]);
            
            header("Location: report.php?msg=" . urlencode("Trip TX-{$trip_id} successfully deleted from system"));
            exit;
        }
    }
}

$drivers = $pdo->query("SELECT * FROM users WHERE role = 'driver' ORDER BY full_name ASC")->fetchAll();
$is_collapsed = isset($_SESSION['sidebar_collapsed']) && $_SESSION['sidebar_collapsed'];
$theme = $_SESSION['theme'] ?? 'light';

// Fetch pending counts
$pending_trips_count = $pdo->query("SELECT COUNT(*) FROM trips WHERE passenger_approval = 'pending' AND status = 'completed'")->fetchColumn() ?: 0;
$pending_shifts_count = $pdo->query("SELECT COUNT(*) FROM shifts WHERE approval_status = 'pending' AND status = 'completed'")->fetchColumn() ?: 0;
$mandatory_photo = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'mandatory_photo'")->fetchColumn() ?: '1';
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang']; ?>" class="<?php echo $theme === 'dark' ? 'dark-mode' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo __('reports'); ?> - framas Transport App</title>
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .tab-nav { display: flex; gap: 5px; margin-bottom: 16px; background: var(--card-bg); padding: 5px; border-radius: 8px; box-shadow: var(--card-shadow); width: fit-content; border: 1px solid var(--glass-border); }
        .tab-btn { padding: 10px 20px; border: none; background: transparent; cursor: pointer; border-radius: 6px; font-weight: 600; color: var(--text-secondary); font-size: 0.85rem; }
        .tab-btn.active { background: var(--pbi-blue); color: #fff; }

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

        .btn-action { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.75rem; font-weight: 600; text-decoration: none; display: inline-block; }
        .btn-delete { background: #fff1f1; color: #d83b01; }
        .btn-edit { background: #f0f9ff; color: #118DFF; margin-right: 5px; }

        .clickable-data { color: var(--pbi-blue); cursor: pointer; text-decoration: none; font-weight: 600; }
        .clickable-data:hover { color: #005a9e; }

        /* Media Modal */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(5px); overflow-y: auto; }
        .modal-content { background: var(--card-bg); margin: 5% auto; padding: 24px; border-radius: 12px; width: 500px; max-width: 90%; border: 1px solid var(--glass-border); }
        .evidence-img { width: 100%; border-radius: 8px; margin-top: 15px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }

        .lang-theme-footer { position: absolute; bottom: 0; width: 100%; padding: 20px; border-top: 1px solid var(--glass-border); background: var(--card-bg); }
    </style>
</head>
<body class="<?php echo $is_collapsed ? 'collapsed' : ''; ?>">

    <?php include 'sidemenu.php'; ?>

    <div class="main-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 16px;">
            <h2 style="margin: 0; font-size: 1.5rem; white-space: nowrap;"><?php echo __('reports'); ?></h2>
            
            <div style="display: flex; gap: 12px; flex-wrap: wrap; justify-content: flex-end; align-items: center;">
                <?php if ($pending_trips_count > 0): ?>
                    <div onclick="filterPendingTrips()" style="background: #fee2e2; border: 1px solid #fecaca; padding: 8px 16px; border-radius: 8px; color: #991b1b; font-size: 0.8rem; font-weight: 600; display: flex; align-items: center; gap: 6px; cursor: pointer; transition: transform 0.15s; margin: 0;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                        <span>⚠️</span>
                        <span>
                            <?php if ($_SESSION['lang'] === 'id'): ?>
                                <strong><?php echo $pending_trips_count; ?></strong> Trip Pending
                            <?php else: ?>
                                <strong><?php echo $pending_trips_count; ?></strong> Pending Trips
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>
                <?php if ($pending_shifts_count > 0): ?>
                    <a href="attendance_report.php?filter=pending" style="text-decoration: none; display: flex; margin: 0;">
                        <div style="background: #fef3c7; border: 1px solid #fde68a; padding: 8px 16px; border-radius: 8px; color: #92400e; font-size: 0.8rem; font-weight: 600; display: flex; align-items: center; gap: 6px; cursor: pointer; transition: transform 0.15s;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                            <span>⏳</span>
                            <span>
                                <?php if ($_SESSION['lang'] === 'id'): ?>
                                    <strong><?php echo $pending_shifts_count; ?></strong> Shift Pending
                                <?php else: ?>
                                    <strong><?php echo $pending_shifts_count; ?></strong> Pending Shifts
                                <?php endif; ?>
                            </span>
                        </div>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isset($_GET['msg'])): ?>
            <div class="alert alert-success" style="background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem; font-weight: 500;">
                ✅ <?= htmlspecialchars($_GET['msg']) ?>
            </div>
        <?php endif; ?>

        <div class="tab-nav">
            <button class="tab-btn active" data-tab="detail" onclick="switchTab('detail')"><?php echo $_SESSION['lang'] == 'id' ? 'Laporan Detail' : 'Detail Report'; ?></button>
            <button class="tab-btn" data-tab="annual" onclick="switchTab('annual')"><?php echo $_SESSION['lang'] == 'id' ? 'Ringkasan Biaya Tahunan' : 'Annual Cost Summary'; ?></button>
        </div>

        <div style="display: flex; flex-direction: column; gap: 16px;">
            <div class="report-filter-card">
                <form id="reportForm" style="display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap;">
                    <!-- 1. Select Year (Annual tab) -->
                    <div id="groupAnnualYear" class="pbi-form-group" style="display: none;">
                        <label class="pbi-label">Select Year</label>
                        <select id="report_year" class="pbi-input">
                            <?php 
                            $current_year = date('Y');
                            for ($y = $current_year; $y >= $current_year - 5; $y--): ?>
                                <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <!-- 2. Expense Type (Annual tab) -->
                    <div id="groupAnnualExpenseType" class="pbi-form-group" style="display: none;">
                        <label class="pbi-label"><?php echo $_SESSION['lang'] == 'id' ? 'Biaya' : 'Expense Type'; ?></label>
                        <select id="annual_expense_type" class="pbi-input" onchange="if(annualData.length > 0) renderAnnualChart();">
                            <option value="ALL"><?php echo $_SESSION['lang'] == 'id' ? '[ Semua Biaya (Stacked) ]' : '[ All Expenses (Stacked) ]'; ?></option>
                            <option value="gasoline">Gasoline (BBM)</option>
                            <option value="toll">Toll</option>
                            <option value="parking">Parking</option>
                            <option value="lunch">Lunch (Uang Makan)</option>
                            <option value="others">Others (Lain-lain)</option>
                            <option value="total_amount">Total Amount</option>
                        </select>
                    </div>

                    <!-- 3. Select Driver (Both tabs) -->
                    <div id="groupDriver" class="pbi-form-group">
                        <label class="pbi-label">Select Driver</label>
                        <select id="driver_id" class="pbi-input">
                            <option value="ALL">[ ALL DRIVERS ]</option>
                            <?php foreach($drivers as $d): ?>
                                <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 4. Start Date (Detail tab) -->
                    <div id="groupStartDate" class="pbi-form-group">
                        <label class="pbi-label">Start Date</label>
                        <input type="date" id="start_date" class="pbi-input" value="<?php echo date('Y-m-01'); ?>">
                    </div>

                    <!-- 5. End Date (Detail tab) -->
                    <div id="groupEndDate" class="pbi-form-group">
                        <label class="pbi-label">End Date</label>
                        <input type="date" id="end_date" class="pbi-input" value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <button type="button" onclick="handleGenerateReport()" class="btn-generate">Generate Report</button>
                </form>
            </div>

            <div id="resultsBox" class="results-box">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="margin:0; font-size: 1.1rem;">Data Preview</h3>
                    <div style="display: flex; gap: 8px;">
                        <button onclick="exportToPDF()" class="btn-export" style="background: #e11d48;">Export PDF</button>
                        <button onclick="exportToExcel()" class="btn-export" style="background: #107c10;">Export Excel</button>
                    </div>
                </div>

                <!-- Mini Dashboard Cards -->
                <div style="display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 150px; background: var(--bg-color); border: 1px solid var(--glass-border); padding: 15px; border-radius: 10px; box-shadow: var(--card-shadow); display: flex; flex-direction: column; gap: 4px;">
                        <span style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;"><?= $_SESSION['lang'] === 'id' ? 'Total Transaksi' : 'Total Transactions' ?></span>
                        <strong id="dash-total-tx" style="font-size: 1.5rem; color: var(--text-primary);">0</strong>
                    </div>
                    <div id="dash-unchecked-card" onclick="filterUncheckedOnly()" style="flex: 1; min-width: 150px; background: rgba(225, 29, 72, 0.05); border: 1px solid rgba(225, 29, 72, 0.2); padding: 15px; border-radius: 10px; box-shadow: var(--card-shadow); display: flex; flex-direction: column; gap: 4px; cursor: pointer; transition: transform 0.15s;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                        <span style="font-size: 0.75rem; color: #e11d48; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 4px;">⚠️ <?= $_SESSION['lang'] === 'id' ? 'Belum Dicek' : 'Unchecked (Pending)' ?></span>
                        <strong id="dash-unchecked-tx" style="font-size: 1.5rem; color: #e11d48;">0</strong>
                    </div>
                    <div style="flex: 1; min-width: 150px; background: var(--bg-color); border: 1px solid var(--glass-border); padding: 15px; border-radius: 10px; box-shadow: var(--card-shadow); display: flex; flex-direction: column; gap: 4px;">
                        <span style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;"><?= $_SESSION['lang'] === 'id' ? 'Total Biaya' : 'Total Expenses' ?></span>
                        <strong id="dash-total-cost" style="font-size: 1.5rem; color: #107c10;">Rp 0</strong>
                    </div>
                </div>

                <!-- Interactive controls for reports table -->
                <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; background: rgba(0,0,0,0.02); padding: 8px; border-radius: 6px; border: 1px solid var(--glass-border); align-items: center;">
                    <select id="report-view-mode" onchange="renderReportTable()" style="flex: 0.8; min-width: 150px; padding: 6px 8px; font-size: 0.8rem; border-radius: 4px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary); font-weight: bold;">
                        <option value="detail"><?= $_SESSION['lang'] == 'id' ? '🔍 Detail Transaksi' : '🔍 Transaction Details' ?></option>
                        <option value="group"><?= $_SESSION['lang'] == 'id' ? '📦 Grup: Driver & Tanggal' : '📦 Group: Driver & Date' ?></option>
                    </select>

                    <input type="text" id="report-search" placeholder="Search driver, passenger, destination, TX-ID..." style="flex: 1.5; min-width: 160px; padding: 6px 8px; font-size: 0.8rem; border-radius: 4px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                    
                    <select id="report-sort" style="flex: 1; min-width: 120px; padding: 6px 8px; font-size: 0.8rem; border-radius: 4px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                        <option value="date_desc">Date: Newest First</option>
                        <option value="date_asc">Date: Oldest First</option>
                        <option value="dist_desc">Distance: High to Low</option>
                        <option value="dist_asc">Distance: Low to High</option>
                        <option value="cost_desc">Cost: High to Low</option>
                        <option value="cost_asc">Cost: Low to High</option>
                    </select>
                    
                    <select id="report-filter-status" onchange="renderReportTable()" style="flex: 1; min-width: 120px; padding: 6px 8px; font-size: 0.8rem; border-radius: 4px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                        <option value="all"><?= $_SESSION['lang'] == 'id' ? 'Semua Status Cek' : 'All Status' ?></option>
                        <option value="approved"><?= $_SESSION['lang'] == 'id' ? 'Sudah Dicek (Approved)' : 'Checked (Approved)' ?></option>
                        <option value="pending"><?= $_SESSION['lang'] == 'id' ? 'Belum Dicek (Pending)' : 'Unchecked (Pending)' ?></option>
                    </select>
                </div>

                <div style="overflow-x: auto;">
                    <table class="pbi-table" id="reportTable">
                        <thead id="reportHeader">
                            <tr>
                                <th rowspan="2">TX ID</th>
                                <th rowspan="2">Driver</th>
                                <th rowspan="2">Date</th>
                                <th colspan="2">Time</th>
                                <th colspan="3">Km</th>
                                <th rowspan="2">Litre</th>
                                <th colspan="4">Amount</th>
                                <th rowspan="2">Passenger</th>
                                <th rowspan="2">Checked</th>
                                <th rowspan="2">Place</th>
                            </tr>
                            <tr>
                                <th>In</th><th>Out</th>
                                <th>In</th><th>Out</th><th>Total</th>
                                <th>Gasoline</th><th>Toll</th><th>Others</th><th>Lunch</th>
                            </tr>
                        </thead>
                        <tbody id="reportContent"></tbody>
                    </table>
                </div>
                <!-- Pagination container -->
                <div id="report-pagination" style="display:flex; justify-content:space-between; align-items:center; margin-top:16px; flex-wrap:wrap; gap:12px; font-size:0.8rem; color:var(--text-secondary);"></div>
            </div>

            <!-- ANNUAL SUMMARY BOX -->
            <div id="resultsBoxAnnual" class="results-box">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="margin:0; font-size: 1.1rem;"><?php echo $_SESSION['lang'] == 'id' ? 'Ringkasan Biaya Tahunan' : 'Annual Cost Summary'; ?></h3>
                    <div style="display: flex; gap: 8px;">
                        <button onclick="exportAnnualToPDF()" class="btn-export" style="background: #e11d48;">Export PDF</button>
                        <button onclick="exportAnnualToExcel()" class="btn-export" style="background: #107c10;">Export Excel</button>
                    </div>
                </div>
                
                <!-- Chart Card -->
                <div style="background: var(--bg-color); border: 1px solid var(--glass-border); padding: 16px; border-radius: 12px; margin-bottom: 24px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                    <div style="height: 320px; position: relative;">
                        <canvas id="annualChartCanvas"></canvas>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table class="pbi-table" id="annualReportTable">
                        <thead>
                            <tr>
                                <th><?php echo $_SESSION['lang'] == 'id' ? 'Bulan' : 'Month'; ?></th>
                                <th>Gasoline (BBM)</th>
                                <th>Toll</th>
                                <th>Parking</th>
                                <th>Lunch (Uang Makan)</th>
                                <th>Others (Lain-lain)</th>
                                <th>Total</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="annualReportContent"></tbody>
                        <tfoot>
                            <tr style="background: rgba(0,0,0,0.05); font-weight: bold;">
                                <td>TOTAL</td>
                                <td align="right" id="annualTotalGas">Rp 0</td>
                                <td align="right" id="annualTotalToll">Rp 0</td>
                                <td align="right" id="annualTotalParking">Rp 0</td>
                                <td align="right" id="annualTotalLunch">Rp 0</td>
                                <td align="right" id="annualTotalOthers">Rp 0</td>
                                <td align="right" id="annualGrandTotal" style="color: var(--pbi-blue);">Rp 0</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Media Modal -->
    <div id="mediaModal" class="modal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                <h4 id="mediaTitle" style="margin:0;">Evidence Documentation</h4>
                <button onclick="closeMedia()" style="background:none; border:none; cursor:pointer; font-size:1.5rem; color:#666;">×</button>
            </div>
            <div id="mediaContainer" style="max-height: 70vh; overflow-y: auto; padding-right: 8px;"></div>
        </div>
    </div>

    <!-- Image Viewer Modal (Full Size) -->
    <div id="imageViewerModal" class="modal" style="z-index: 2100;">
        <div class="modal-content" style="width: auto; max-width: 95%; margin: 2% auto; padding: 12px; background: rgba(0,0,0,0.9); border: none; text-align: center; position: relative;">
            <button onclick="closeImageViewer()" style="position: absolute; right: 15px; top: 15px; background: rgba(255,255,255,0.2); border: none; border-radius: 50%; color: white; cursor: pointer; font-size: 1.5rem; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; z-index: 10;">×</button>
            <img id="fullImageView" src="" style="max-height: 85vh; max-width: 100%; border-radius: 4px; object-fit: contain; margin-top: 40px;">
        </div>
    </div>

    <!-- Monthly Details Modal -->
    <div id="monthlyDetailsModal" class="modal" style="z-index: 2050;">
        <div class="modal-content" style="width: 900px; max-width: 95%; margin: 5% auto;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h4 id="monthlyDetailsTitle" style="margin:0; font-size: 1.25rem;">Monthly Expense Details</h4>
                <button onclick="closeMonthlyDetails()" style="background:none; border:none; cursor:pointer; font-size:1.5rem; color:#666;">×</button>
            </div>
            <div style="overflow-x: auto; max-height: 60vh;">
                <table class="pbi-table">
                    <thead>
                        <tr>
                            <th>Driver</th>
                            <th>Date & Time</th>
                            <th>Destination</th>
                            <th>Passenger</th>
                            <th>Car No</th>
                            <th>Expense Type</th>
                            <th>Litre</th>
                            <th>Amount</th>
                            <th>Proof</th>
                        </tr>
                    </thead>
                    <tbody id="monthlyDetailsContent"></tbody>
                </table>
            </div>
        </div>
    </div>
    <!-- Edit Trip Modal for Admin -->
    <div id="editTripModal" class="modal" style="z-index: 2000;">
        <div class="modal-content" style="width: 500px; max-width: 90%; margin: 8% auto;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 id="editTripModalTitle" style="margin:0;">Edit Trip Transaction</h3>
                <button onclick="closeEditTripModal()" style="background:none; border:none; cursor:pointer; font-size:1.5rem; color:var(--text-muted);">&times;</button>
            </div>
            <form id="editTripForm" method="POST" action="report.php">
                <input type="hidden" name="action" value="edit_trip_admin">
                <input type="hidden" name="trip_id" id="edit_trip_id">
                
                <div style="margin-bottom: 15px;">
                    <label class="pbi-label">Start Time (Waktu Mulai)</label>
                    <input type="datetime-local" name="start_time" id="edit_start_time" class="pbi-input" required>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label class="pbi-label">End Time (Waktu Selesai)</label>
                    <input type="datetime-local" name="end_time" id="edit_end_time" class="pbi-input">
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label class="pbi-label" style="font-weight: 700; border-bottom: 1px solid var(--glass-border); padding-bottom: 6px; margin-bottom: 12px; display: block; color: var(--text-primary);">Expenses / Biaya Transaksi</label>
                    <div id="modal_expenses_list" style="display: flex; flex-direction: column; gap: 12px; max-height: 280px; overflow-y: auto; padding-right: 4px;">
                        <!-- Populate dynamically in JS -->
                    </div>
                </div>
                
                <div style="display: flex; gap: 8px;">
                    <button type="button" id="adminDeleteTripBtn" onclick="confirmDeleteTripAdmin()" class="btn-action btn-delete" style="padding: 12px; font-weight: bold;">Hapus Transaksi</button>
                    <button type="submit" class="btn-generate" style="flex: 1; height: auto; padding: 12px; font-weight: bold;">Save Changes</button>
                </div>
            </form>
            <form id="deleteTripForm" method="POST" action="report.php" style="display:none;">
                <input type="hidden" name="action" value="delete_trip_admin">
                <input type="hidden" name="trip_id" id="delete_trip_id">
            </form>
        </div>
    </div>

    <script>
        const mandatoryPhoto = "<?= $mandatory_photo ?>";

        function toggleSidebar() {
            document.body.classList.toggle('collapsed');
            fetch('manage_admin_action.php?action=toggle_sidebar');
        }

        let activeTab = 'detail';
        function switchTab(tab) {
            activeTab = tab;
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelector(`.tab-btn[data-tab="${tab}"]`).classList.add('active');

            document.getElementById('resultsBox').style.display = 'none';
            document.getElementById('resultsBoxAnnual').style.display = 'none';

            if (tab === 'detail') {
                document.getElementById('groupStartDate').style.display = 'block';
                document.getElementById('groupEndDate').style.display = 'block';
                document.getElementById('groupDriver').style.display = 'block';
                document.getElementById('groupAnnualYear').style.display = 'none';
                document.getElementById('groupAnnualExpenseType').style.display = 'none';
            } else {
                document.getElementById('groupStartDate').style.display = 'none';
                document.getElementById('groupEndDate').style.display = 'none';
                document.getElementById('groupDriver').style.display = 'block';
                document.getElementById('groupAnnualYear').style.display = 'block';
                document.getElementById('groupAnnualExpenseType').style.display = 'block';
            }
        }

        function handleGenerateReport() {
            if (activeTab === 'detail') {
                generateReport();
            } else {
                generateAnnualReport();
            }
        }

        let currentData = [];
        let currentPage = 1;
        let pageSize = 10;

        async function generateReport() {
            const formData = new FormData();
            formData.append('driver_id', document.getElementById('driver_id').value);
            formData.append('start_date', document.getElementById('start_date').value);
            formData.append('end_date', document.getElementById('end_date').value);

            const res = await fetch('api_get_report.php', { method: 'POST', body: formData });
            currentData = await res.json();

            currentPage = 1; // reset page on load
            renderReportTable();

            document.getElementById('resultsBox').style.display = 'block';
        }

        function renderReportTable() {
            const searchQuery = document.getElementById('report-search').value.toLowerCase().trim();
            const sortValue = document.getElementById('report-sort').value;
            const filterStatus = document.getElementById('report-filter-status').value;
            const viewMode = document.getElementById('report-view-mode').value;
            const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";

            // Update Dashboard Counters based on currentData (all loaded records)
            const totalTx = currentData.length;
            const checkedTx = currentData.filter(r => {
                return (r.expense_details || []).every(e => e.approval_status === 'approved');
            }).length;
            const uncheckedTx = totalTx - checkedTx;
            const totalCost = currentData.reduce((sum, r) => {
                return sum + (parseFloat(r.gas_amt) || 0) + (parseFloat(r.toll_amt) || 0) + (parseFloat(r.others_amt) || 0) + (parseFloat(r.parking_amt) || 0) + (parseFloat(r.lunch_amt) || 0);
            }, 0);

            document.getElementById('dash-total-tx').innerText = totalTx;
            document.getElementById('dash-total-cost').innerText = 'Rp ' + totalCost.toLocaleString();
            
            const uncheckedCard = document.getElementById('dash-unchecked-card');
            if (uncheckedCard) {
                if (uncheckedTx > 0) {
                    // Still has pending items — show red warning
                    document.getElementById('dash-unchecked-tx').innerText = uncheckedTx;
                    uncheckedCard.style.background = 'rgba(225, 29, 72, 0.08)';
                    uncheckedCard.style.borderColor = '#e11d48';
                    uncheckedCard.querySelector('span').style.color = '#e11d48';
                    uncheckedCard.querySelector('strong').style.color = '#e11d48';
                    uncheckedCard.querySelector('span').innerHTML = '⚠️ ' + (lang === 'id' ? 'Belum Dicek' : 'Unchecked (Pending)');
                } else {
                    // All approved — show green with total approved count
                    document.getElementById('dash-unchecked-tx').innerText = checkedTx;
                    uncheckedCard.style.background = 'rgba(16, 185, 129, 0.05)';
                    uncheckedCard.style.borderColor = 'rgba(16, 185, 129, 0.2)';
                    uncheckedCard.querySelector('span').style.color = '#10b981';
                    uncheckedCard.querySelector('strong').style.color = '#10b981';
                    uncheckedCard.querySelector('span').innerHTML = '✔ ' + (lang === 'id' ? 'Semua Sudah Dicek' : 'All Checked ✔');
                }
            }

            // Update Table Header based on View Mode
            const headerEl = document.getElementById('reportHeader');
            if (viewMode === 'group') {
                headerEl.innerHTML = `
                    <tr>
                        <th rowspan="2">${lang === 'id' ? 'Driver' : 'Driver'}</th>
                        <th rowspan="2">${lang === 'id' ? 'Tanggal' : 'Date'}</th>
                        <th rowspan="2">${lang === 'id' ? 'Total Trip' : 'Total Trips'}</th>
                        <th colspan="4">${lang === 'id' ? 'Rincian Pengeluaran' : 'Expenses Breakdown'}</th>
                        <th rowspan="2">${lang === 'id' ? 'Total Biaya' : 'Total Cost'}</th>
                        <th rowspan="2">${lang === 'id' ? 'Checked' : 'Checked'}</th>
                        <th rowspan="2">${lang === 'id' ? 'Tindakan' : 'Actions'}</th>
                    </tr>
                    <tr>
                        <th>Gasoline</th><th>Toll</th><th>Others</th><th>Lunch</th>
                    </tr>
                `;
            } else {
                headerEl.innerHTML = `
                    <tr>
                        <th rowspan="2">TX ID</th>
                        <th rowspan="2">Driver</th>
                        <th rowspan="2">Date</th>
                        <th colspan="2">Time</th>
                        <th colspan="3">Km</th>
                        <th rowspan="2">Litre</th>
                        <th colspan="4">Amount</th>
                        <th rowspan="2">Passenger</th>
                        <th rowspan="2">Checked</th>
                        <th rowspan="2">Place</th>
                    </tr>
                    <tr>
                        <th>In</th><th>Out</th>
                        <th>In</th><th>Out</th><th>Total</th>
                        <th>Gasoline</th><th>Toll</th><th>Others</th><th>Lunch</th>
                    </tr>
                `;
            }

            // 1. Filter
            let filtered = currentData.filter(r => {
                const driverName = (r.driver_name || '').toLowerCase();
                const passName = (r.pass_name || '').toLowerCase();
                const destName = (r.dest_name || '').toLowerCase();
                const txId = `tx-${r.id}`;
                
                const matchesSearch = driverName.includes(searchQuery) || 
                                      passName.includes(searchQuery) || 
                                      destName.includes(searchQuery) || 
                                      txId.includes(searchQuery);

                const allApproved = (r.expense_details || []).every(e => e.approval_status === 'approved');
                const matchesStatus = (filterStatus === 'all') || 
                                      (filterStatus === 'approved' && allApproved) ||
                                      (filterStatus === 'pending' && !allApproved);

                return matchesSearch && matchesStatus;
            });

            // 2. Map and Group if in Group View
            let dataToRender = [];
            if (viewMode === 'group') {
                const groups = {};
                filtered.forEach(r => {
                    const key = `${r.driver_id}_${r.shift_date}`;
                    if (!groups[key]) {
                        groups[key] = {
                            driver_id: r.driver_id,
                            driver_name: r.driver_name,
                            shift_date: r.shift_date,
                            trip_count: 0,
                            gas_amt: 0,
                            toll_amt: 0,
                            others_amt: 0,
                            parking_amt: 0,
                            lunch_amt: 0,
                            trips: [],
                            all_approved: true
                        };
                    }
                    groups[key].trip_count++;
                    groups[key].gas_amt += parseFloat(r.gas_amt) || 0;
                    groups[key].toll_amt += parseFloat(r.toll_amt) || 0;
                    groups[key].others_amt += parseFloat(r.others_amt) || 0;
                    groups[key].parking_amt += parseFloat(r.parking_amt) || 0;
                    groups[key].lunch_amt += parseFloat(r.lunch_amt) || 0;
                    groups[key].trips.push(r);
                    
                    const tripApproved = (r.expense_details || []).every(e => e.approval_status === 'approved');
                    if (!tripApproved) {
                        groups[key].all_approved = false;
                    }
                });
                dataToRender = Object.values(groups);

                // Sort grouped data
                dataToRender.sort((a, b) => {
                    if (sortValue === 'date_desc') {
                        return new Date(b.shift_date) - new Date(a.shift_date);
                    } else if (sortValue === 'date_asc') {
                        return new Date(a.shift_date) - new Date(b.shift_date);
                    } else if (sortValue === 'cost_desc') {
                        const costA = a.gas_amt + a.toll_amt + a.others_amt + a.parking_amt + a.lunch_amt;
                        const costB = b.gas_amt + b.toll_amt + b.others_amt + b.parking_amt + b.lunch_amt;
                        return costB - costA;
                    } else if (sortValue === 'cost_asc') {
                        const costA = a.gas_amt + a.toll_amt + a.others_amt + a.parking_amt + a.lunch_amt;
                        const costB = b.gas_amt + b.toll_amt + b.others_amt + b.parking_amt + b.lunch_amt;
                        return costA - costB;
                    }
                    return new Date(b.shift_date) - new Date(a.shift_date);
                });
            } else {
                dataToRender = filtered;

                // Sort detail data
                dataToRender.sort((a, b) => {
                    const getTripCost = (t) => {
                        return (parseFloat(t.gas_amt) || 0) + (parseFloat(t.toll_amt) || 0) + (parseFloat(t.others_amt) || 0) + (parseFloat(t.parking_amt) || 0) + (parseFloat(t.lunch_amt) || 0);
                    };
                    
                    if (sortValue === 'date_desc') {
                        return new Date(b.start_time) - new Date(a.start_time);
                    } else if (sortValue === 'date_asc') {
                        return new Date(a.start_time) - new Date(b.start_time);
                    } else if (sortValue === 'dist_desc') {
                        const distA = a.km_end ? (a.km_end - a.km_start) : 0;
                        const distB = b.km_end ? (b.km_end - b.km_start) : 0;
                        return distB - distA;
                    } else if (sortValue === 'dist_asc') {
                        const distA = a.km_end ? (a.km_end - a.km_start) : 0;
                        const distB = b.km_end ? (b.km_end - b.km_start) : 0;
                        return distA - distB;
                    } else if (sortValue === 'cost_desc') {
                        return getTripCost(b) - getTripCost(a);
                    } else if (sortValue === 'cost_asc') {
                        return getTripCost(a) - getTripCost(b);
                    }
                    return 0;
                });
            }

            // 3. Paginate
            const totalEntries = dataToRender.length;
            const totalPages = Math.ceil(totalEntries / pageSize) || 1;
            if (currentPage > totalPages) {
                currentPage = totalPages;
            }
            const startIndex = (currentPage - 1) * pageSize;
            const endIndex = Math.min(startIndex + pageSize, totalEntries);
            const pageData = dataToRender.slice(startIndex, endIndex);

            // 4. Render Table Body
            const tbody = document.getElementById('reportContent');
            if (pageData.length === 0) {
                tbody.innerHTML = `<tr><td colspan="${viewMode==='group'?10:15}" align="center" style="padding:20px; color:var(--text-secondary);">No matching records found.</td></tr>`;
                document.getElementById('report-pagination').innerHTML = '';
                return;
            }

            if (viewMode === 'group') {
                tbody.innerHTML = pageData.map((g) => {
                    const totalCost = g.gas_amt + g.toll_amt + g.others_amt + g.parking_amt + g.lunch_amt;
                    const checkedHtml = g.all_approved 
                        ? `<span style="color: #166534; font-weight: 700; font-size: 1.1rem;">✔</span>` 
                        : `<span style="color: #94a3b8; font-weight: 500;">-</span>`;
                    
                    let actionButtons = '';
                    if (!g.all_approved) {
                        actionButtons += `<button onclick="approveGroupAdmin(${g.driver_id}, '${g.shift_date}')" class="btn-action" style="background: #10b981; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 0.75rem; font-weight: bold; margin-right: 4px;">Approve All</button>`;
                    }
                    actionButtons += `<button onclick="openGroupEditModal(${g.driver_id}, '${g.shift_date}')" class="btn-action" style="background: #f59e0b; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 0.75rem; font-weight: bold; margin-right: 4px;">✏️ Edit</button>`;
                    actionButtons += `<button onclick="viewGroupDetails(${g.driver_id}, '${g.shift_date}')" class="btn-action" style="background: #3b82f6; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 0.75rem; font-weight: bold;">Detail</button>`;

                    return `
                    <tr>
                        <td><strong>${g.driver_name}</strong></td>
                        <td align="center">${g.shift_date}</td>
                        <td align="center"><strong>${g.trip_count}</strong></td>
                        <td align="right">${g.gas_amt ? 'Rp '+parseInt(g.gas_amt).toLocaleString() : '-'}</td>
                        <td align="right">${g.toll_amt ? 'Rp '+parseInt(g.toll_amt).toLocaleString() : '-'}</td>
                        <td align="right">${(g.others_amt + g.parking_amt) ? 'Rp '+parseInt(g.others_amt + g.parking_amt).toLocaleString() : '-'}</td>
                        <td align="right">${g.lunch_amt ? 'Rp '+parseInt(g.lunch_amt).toLocaleString() : '-'}</td>
                        <td align="right" style="color: var(--pbi-blue); font-weight: bold;">Rp ${parseInt(totalCost).toLocaleString()}</td>
                        <td align="center">${checkedHtml}</td>
                        <td align="center">${actionButtons}</td>
                    </tr>
                    `;
                }).join('');
            } else {
                tbody.innerHTML = pageData.map((r) => {
                    const allApproved = (r.expense_details || []).every(e => e.approval_status === 'approved');
                    const checkedHtml = allApproved 
                        ? `<span style="color: #166534; font-weight: 700; font-size: 1.1rem;">✔</span>` 
                        : `<span style="color: #94a3b8; font-weight: 500;">-</span>`;
                    return `
                    <tr>
                        <td align="center" class="clickable-data" onclick="openEditTripModal(${r.id})"><strong>TX-${r.id}</strong></td>
                        <td>${r.driver_name}</td>
                        <td>${r.shift_date}</td>
                        <td align="center">${r.start_time.substring(11,16)}</td>
                        <td align="center">${r.end_time ? r.end_time.substring(11,16) : '-'}</td>
                        <td align="right" class="clickable-data" onclick="showMedia(${r.id}, 'km_start')">${r.km_start}</td>
                        <td align="right" class="clickable-data" onclick="showMedia(${r.id}, 'km_end')">${r.km_end || '-'}</td>
                        <td align="right"><strong>${r.km_end ? (r.km_end - r.km_start) : 0}</strong></td>
                        <td align="right">${r.gas_litre || '-'}</td>
                        <td align="right" class="${r.gas_amt?'clickable-data':''}" onclick="showMedia(${r.id}, 'gasoline')">${r.gas_amt ? 'Rp '+parseInt(r.gas_amt).toLocaleString() : '-'}</td>
                        <td align="right" class="${r.toll_amt?'clickable-data':''}" onclick="showMedia(${r.id}, 'toll')">${r.toll_amt ? 'Rp '+parseInt(r.toll_amt).toLocaleString() : '-'}</td>
                        <td align="right" class="${(parseInt(r.others_amt)||0)+(parseInt(r.parking_amt)||0)?'clickable-data':''}" onclick="showMedia(${r.id}, 'others')">${(parseInt(r.others_amt)||0) + (parseInt(r.parking_amt)||0) ? 'Rp '+((parseInt(r.others_amt)||0) + (parseInt(r.parking_amt)||0)).toLocaleString() : '-'}</td>
                        <td align="right" class="${r.lunch_amt?'clickable-data':''}" onclick="showMedia(${r.id}, 'lunch')">${r.lunch_amt ? 'Rp '+parseInt(r.lunch_amt).toLocaleString() : '-'}</td>
                        <td>${r.pass_name || '-'}</td>
                        <td align="center">${checkedHtml}</td>
                        <td class="clickable-data" onclick="showTripMap(${r.id})">${r.dest_name}</td>
                    </tr>
                    `;
                }).join('');
            }

            // 5. Render Pagination Controls
            const infoText = totalEntries > 0 ? `Showing ${startIndex + 1} to ${endIndex} of ${totalEntries} entries` : 'Showing 0 to 0 of 0 entries';
            
            let pageButtons = '';
            pageButtons += `<button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} style="padding: 4px 8px; border: 1px solid var(--glass-border); background: var(--card-bg); border-radius: 4px; cursor: ${currentPage === 1 ? 'not-allowed' : 'pointer'}; color: var(--text-primary); font-size: 0.75rem;">Prev</button>`;
            
            for (let i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
                    pageButtons += `<button onclick="changePage(${i})" style="padding: 4px 8px; border: 1px solid ${i === currentPage ? 'var(--pbi-blue)' : 'var(--glass-border)'}; background: ${i === currentPage ? 'var(--pbi-blue)' : 'var(--card-bg)'}; color: ${i === currentPage ? '#fff' : 'var(--text-primary)'}; border-radius: 4px; cursor: pointer; font-size: 0.75rem; margin: 0 2px;">${i}</button>`;
                } else if (i === currentPage - 3 || i === currentPage + 3) {
                    pageButtons += `<span style="padding: 4px 6px;">...</span>`;
                }
            }

            pageButtons += `<button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} style="padding: 4px 8px; border: 1px solid var(--glass-border); background: var(--card-bg); border-radius: 4px; cursor: ${currentPage === totalPages ? 'not-allowed' : 'pointer'}; color: var(--text-primary); font-size: 0.75rem;">Next</button>`;

            const sizeSelector = `
                <div style="display:flex; align-items:center; gap:6px;">
                    <span>Show</span>
                    <select onchange="changePageSize(this.value)" style="padding: 4px; font-size: 0.75rem; border: 1px solid var(--glass-border); border-radius: 4px; background: var(--card-bg); color: var(--text-primary);">
                        <option value="10" ${pageSize === 10 ? 'selected' : ''}>10</option>
                        <option value="25" ${pageSize === 25 ? 'selected' : ''}>25</option>
                        <option value="50" ${pageSize === 50 ? 'selected' : ''}>50</option>
                        <option value="100" ${pageSize === 100 ? 'selected' : ''}>100</option>
                    </select>
                    <span>entries</span>
                </div>
            `;

            document.getElementById('report-pagination').innerHTML = `
                <div>${infoText}</div>
                <div style="display: flex; gap: 16px; align-items: center; flex-wrap: wrap;">
                    ${sizeSelector}
                    <div style="display: flex; align-items: center;">${pageButtons}</div>
                </div>
            `;
        }

        function changePage(page) {
            currentPage = page;
            renderReportTable();
        }

        function changePageSize(size) {
            pageSize = parseInt(size);
            currentPage = 1;
            renderReportTable();
        }

        let annualChart = null;
        let annualData = [];
        async function generateAnnualReport() {
            const formData = new FormData();
            formData.append('driver_id', document.getElementById('driver_id').value);
            formData.append('year', document.getElementById('report_year').value);

            const res = await fetch('api_get_annual_report.php?action=summary', { method: 'POST', body: formData });
            annualData = await res.json();

            const tbody = document.getElementById('annualReportContent');
            const monthNames = [
                'January', 'February', 'March', 'April', 'May', 'June', 
                'July', 'August', 'September', 'October', 'November', 'December'
            ];
            
            let totalGas = 0, totalToll = 0, totalParking = 0, totalLunch = 0, totalOthers = 0, grandTotal = 0;

            tbody.innerHTML = annualData.map((r) => {
                totalGas += r.gasoline;
                totalToll += r.toll;
                totalParking += r.parking;
                totalLunch += r.lunch;
                totalOthers += r.others;
                grandTotal += r.total_amount;

                const hasData = r.total_amount > 0;
                const detailsBtn = hasData ? `<button onclick="showMonthlyDetails(${r.month_num})" class="btn-action btn-edit" style="margin: 0; padding: 4px 8px; font-size: 0.7rem;">Detail</button>` : '-';

                return `
                    <tr>
                        <td><strong>${monthNames[r.month_num - 1]}</strong></td>
                        <td align="right">Rp ${parseInt(r.gasoline).toLocaleString()}</td>
                        <td align="right">Rp ${parseInt(r.toll).toLocaleString()}</td>
                        <td align="right">Rp ${parseInt(r.parking).toLocaleString()}</td>
                        <td align="right">Rp ${parseInt(r.lunch).toLocaleString()}</td>
                        <td align="right">Rp ${parseInt(r.others).toLocaleString()}</td>
                        <td align="right"><strong>Rp ${parseInt(r.total_amount).toLocaleString()}</strong></td>
                        <td align="center">${detailsBtn}</td>
                    </tr>
                `;
            }).join('');

            document.getElementById('annualTotalGas').innerText = 'Rp ' + totalGas.toLocaleString();
            document.getElementById('annualTotalToll').innerText = 'Rp ' + totalToll.toLocaleString();
            document.getElementById('annualTotalParking').innerText = 'Rp ' + totalParking.toLocaleString();
            document.getElementById('annualTotalLunch').innerText = 'Rp ' + totalLunch.toLocaleString();
            document.getElementById('annualTotalOthers').innerText = 'Rp ' + totalOthers.toLocaleString();
            document.getElementById('annualGrandTotal').innerText = 'Rp ' + grandTotal.toLocaleString();

            renderAnnualChart();

            document.getElementById('resultsBoxAnnual').style.display = 'block';
        }

        function renderAnnualChart() {
            const ctx = document.getElementById('annualChartCanvas').getContext('2d');
            if (annualChart) {
                annualChart.destroy();
            }

            const monthLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const expenseType = document.getElementById('annual_expense_type').value;

            if (expenseType === 'ALL') {
                const gasolineData = annualData.map(r => r.gasoline);
                const tollData = annualData.map(r => r.toll);
                const parkingData = annualData.map(r => r.parking);
                const lunchData = annualData.map(r => r.lunch);
                const othersData = annualData.map(r => r.others);

                annualChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: monthLabels,
                        datasets: [
                            { label: 'Gasoline', data: gasolineData, backgroundColor: '#3b82f6' },
                            { label: 'Toll', data: tollData, backgroundColor: '#10b981' },
                            { label: 'Parking', data: parkingData, backgroundColor: '#f59e0b' },
                            { label: 'Lunch', data: lunchData, backgroundColor: '#ec4899' },
                            { label: 'Others', data: othersData, backgroundColor: '#6b7280' }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { stacked: true },
                            y: { stacked: true, ticks: { callback: (val) => 'Rp ' + val.toLocaleString() } }
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: (context) => `${context.dataset.label}: Rp ${context.raw.toLocaleString()}`
                                }
                            }
                        }
                    }
                });
            } else {
                let datasetLabel = '';
                let datasetColor = '#3b82f6';
                let data = [];

                if (expenseType === 'gasoline') {
                    datasetLabel = 'Gasoline';
                    datasetColor = '#3b82f6';
                    data = annualData.map(r => r.gasoline);
                } else if (expenseType === 'toll') {
                    datasetLabel = 'Toll';
                    datasetColor = '#10b981';
                    data = annualData.map(r => r.toll);
                } else if (expenseType === 'parking') {
                    datasetLabel = 'Parking';
                    datasetColor = '#f59e0b';
                    data = annualData.map(r => r.parking);
                } else if (expenseType === 'lunch') {
                    datasetLabel = 'Lunch';
                    datasetColor = '#ec4899';
                    data = annualData.map(r => r.lunch);
                } else if (expenseType === 'others') {
                    datasetLabel = 'Others';
                    datasetColor = '#6b7280';
                    data = annualData.map(r => r.others);
                } else if (expenseType === 'total_amount') {
                    datasetLabel = 'Total Amount';
                    datasetColor = '#118DFF';
                    data = annualData.map(r => r.total_amount);
                }

                annualChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: monthLabels,
                        datasets: [{
                            label: datasetLabel,
                            data: data,
                            borderColor: datasetColor,
                            backgroundColor: datasetColor + '15', // light transparent fill
                            fill: true,
                            tension: 0.4,
                            borderWidth: 3,
                            pointRadius: 5,
                            pointHoverRadius: 7
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: true },
                            tooltip: {
                                callbacks: {
                                    label: (context) => `${context.dataset.label}: Rp ${context.raw.toLocaleString()}`
                                }
                            }
                        },
                        scales: {
                            x: { grid: { display: false } },
                            y: { 
                                grid: { color: 'rgba(0,0,0,0.05)' },
                                ticks: {
                                    callback: function(value) {
                                        return 'Rp ' + value.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }

        async function showMonthlyDetails(monthNum) {
            const monthNames = [
                'January', 'February', 'March', 'April', 'May', 'June', 
                'July', 'August', 'September', 'October', 'November', 'December'
            ];
            document.getElementById('monthlyDetailsTitle').innerText = `Expense Details - ${monthNames[monthNum - 1]} ${document.getElementById('report_year').value}`;

            const formData = new FormData();
            formData.append('driver_id', document.getElementById('driver_id').value);
            formData.append('year', document.getElementById('report_year').value);
            formData.append('month', monthNum);

            const res = await fetch('api_get_annual_report.php?action=details', { method: 'POST', body: formData });
            const details = await res.json();

            const tbody = document.getElementById('monthlyDetailsContent');
            if (details.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" align="center">No expense details found.</td></tr>';
            } else {
                tbody.innerHTML = details.map(d => {
                    const litreVal = d.litre ? parseFloat(d.litre).toFixed(2) + ' L' : '-';
                    const photoHtml = d.photo ? `<div style="position:relative; cursor:pointer;" onclick="openImageViewer('uploads/${d.photo}')"><img src="uploads/thumb_${d.photo}" onerror="this.src='uploads/${d.photo}'" style="width:50px; height:50px; object-fit:cover; border-radius:4px;"><div style="position: absolute; bottom: 2px; right: 2px; background: rgba(0,0,0,0.5); color: white; padding: 1px 2px; font-size: 0.55rem; border-radius: 2px;">🔍</div></div>` : '-';
                    return `
                        <tr>
                            <td><strong>${d.driver_name}</strong></td>
                            <td>${d.expense_date}</td>
                            <td>${d.dest_name}</td>
                            <td>${d.pass_name}</td>
                            <td align="center">${d.car_no}</td>
                            <td align="center"><span class="badge" style="background: rgba(0,0,0,0.05); font-size: 0.65rem; padding: 2px 6px; text-transform: uppercase;">${d.expense_type}</span></td>
                            <td align="right">${litreVal}</td>
                            <td align="right"><strong>Rp ${parseInt(d.amount).toLocaleString()}</strong></td>
                            <td align="center">${photoHtml}</td>
                        </tr>
                    `;
                }).join('');
            }

            document.getElementById('monthlyDetailsModal').style.display = 'block';
        }

        function closeMonthlyDetails() {
            document.getElementById('monthlyDetailsModal').style.display = 'none';
        }

        function showMedia(tripId, type) {
            const r = currentData.find(item => item.id == tripId);
            const container = document.getElementById('mediaContainer');
            const title = document.getElementById('mediaTitle');
            container.innerHTML = '';
            
            if (type === 'km_start' || type === 'km_end') {
                title.innerText = `Odometer (${type === 'km_start' ? 'Start' : 'End'})`;
                const photo = type === 'km_start' ? r.km_start_photo : r.km_end_photo;
                const time = type === 'km_start' ? r.start_time : r.end_time;
                const photoHtml = (mandatoryPhoto === '1' && photo) ? `
                    <div style="position: relative; cursor: pointer; min-width: 100px;" onclick="openImageViewer('uploads/${photo}')" title="Click to view full size">
                        <img src="uploads/thumb_${photo}" onerror="this.src='uploads/${photo}'" class="evidence-img" style="width: 100px; height: 100px; object-fit: cover; border-radius: 8px; border: 1px solid var(--glass-border); display: block;">
                        <div style="position: absolute; bottom: 4px; right: 4px; background: rgba(0,0,0,0.6); color: white; border-radius: 4px; padding: 2px 4px; font-size: 0.65rem;">🔍</div>
                    </div>
                ` : '';
                container.innerHTML = `
                    <div style="background: var(--bg-color); border: 1px solid var(--glass-border); border-radius: 12px; padding: 16px; margin-bottom: 12px; font-family: inherit;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 0.8rem; border-bottom: 1px dashed var(--glass-border); padding-bottom: 8px; color: var(--text-secondary);">
                            <span>Uploaded At: <strong>${time}</strong></span>
                        </div>
                        <div style="display: flex; gap: 16px; align-items: center;">
                            ${photoHtml}
                            <div style="font-size: 0.85rem; color: var(--text-primary);">
                                <div style="margin-bottom: 4px;"><strong>Odometer (${type === 'km_start' ? 'Start' : 'End'})</strong></div>
                                <div style="color: var(--text-secondary);">Value: <strong>${type === 'km_start' ? r.km_start : (r.km_end || '-')} KM</strong></div>
                            </div>
                        </div>
                    </div>`;
            } else {
                title.innerText = `Expense Receipt: ${type.toUpperCase()}`;
                const expenses = r.expense_details.filter(e => {
                    if (type === 'others') return e.expense_type === 'others' || e.expense_type === 'parking';
                    return e.expense_type === type;
                });
                
                if (expenses.length > 0) {
                    const totalAmt = expenses.reduce((sum, e) => sum + parseInt(e.amount), 0);
                    
                    let html = `
                        <div style="background: rgba(17, 141, 255, 0.05); border: 1px solid rgba(17, 141, 255, 0.15); border-radius: 12px; padding: 12px; margin-bottom: 16px; display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--text-primary);">
                            <span>Total Files: <strong>${expenses.length}</strong></span>
                            <span>Total Value: <strong>Rp ${totalAmt.toLocaleString()}</strong></span>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 12px;">`;
                    
                    expenses.forEach(e => {
                        const photoHtml = (mandatoryPhoto === '1' && e.photo) ? `
                            <div style="position: relative; cursor: pointer; min-width: 80px;" onclick="openImageViewer('uploads/${e.photo}')" title="Click to view full size">
                                <img src="uploads/thumb_${e.photo}" onerror="this.src='uploads/${e.photo}'" class="evidence-img" style="width: 80px; height: 80px; object-fit: cover; border-radius: 8px; border: 1px solid var(--glass-border); display: block;">
                                <div style="position: absolute; bottom: 4px; right: 4px; background: rgba(0,0,0,0.6); color: white; border-radius: 4px; padding: 2px 4px; font-size: 0.65rem;">🔍</div>
                            </div>
                        ` : '';
                        html += `
                            <div style="background: var(--bg-color); border: 1px solid var(--glass-border); border-radius: 12px; padding: 12px; display: flex; gap: 16px; align-items: center; justify-content: space-between;">
                                <div style="display: flex; gap: 16px; align-items: center;">
                                    ${photoHtml}
                                    <div style="font-size: 0.85rem;">
                                        <div style="font-weight: 700; color: var(--text-primary); margin-bottom: 4px;">Rp ${parseInt(e.amount).toLocaleString()}</div>
                                        <div style="font-size: 0.75rem; color: var(--text-secondary);">${e.expense_type.toUpperCase()}</div>
                                        ${e.litre ? `<div style="font-size: 0.75rem; color: var(--text-secondary);">${parseFloat(e.litre).toFixed(2)} L</div>` : ''}
                                        ${e.supervisor_note ? `<div style="font-size: 0.7rem; color: #f59e0b; margin-top: 4px; white-space: pre-wrap;">📝 Note: ${e.supervisor_note}</div>` : ''}
                                    </div>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--text-secondary); text-align: right; min-width: 90px;">
                                    <div>Uploaded At</div>
                                    <div style="font-weight: 600; margin-top: 2px; color: var(--text-primary);">${e.created_at || '-'}</div>
                                    ${e.approval_status === 'approved' ? `<div style="color: #166534; font-weight: bold; margin-top: 4px;">✔ Approved</div><div style="font-size:0.65rem;">${e.approved_by_name || ''}</div>` : `<div style="display:flex; flex-direction:column; gap:4px; margin-top:6px;"><button onclick="approveExpenseAdmin(${e.id})" style="background: #10b981; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 0.7rem;">Approve</button><button onclick="editExpenseAdmin(${e.id}, '${e.expense_type}', ${e.amount}, ${e.litre || 0})" style="background: #3b82f6; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 0.7rem;">Edit</button></div>`}
                                </div>
                            </div>`;
                    });
                    
                    html += `</div>`;
                    container.innerHTML = html;
                } else {
                    container.innerHTML = '<p style="text-align:center; padding:20px; color: var(--text-secondary);">No receipt photo found.</p>';
                }
            }
            
            document.getElementById('mediaModal').style.display = 'block';
        }

        function showTripMap(tripId) {
            const r = currentData.find(item => item.id == tripId);
            const container = document.getElementById('mediaContainer');
            const title = document.getElementById('mediaTitle');
            title.innerText = "Trip GPS Coordinates (Google Maps)";
            
            const distance = (r.km_end && r.km_start) ? (r.km_end - r.km_start) : 0;
            
            let html = `
                <div style="background: var(--bg-color); border: 1px solid var(--glass-border); border-radius: 12px; padding: 16px; margin-bottom: 12px; font-family: inherit;">
                    <div style="margin-bottom: 16px; font-size: 0.95rem; color: var(--text-primary); line-height: 1.5;">
                        <strong>Destination:</strong> ${r.dest_name}<br>
                        <strong>Passenger:</strong> ${r.pass_name}<br>
                        <strong>Driver:</strong> ${r.driver_name}<br>
                        <strong>Odometer:</strong> ${r.km_start} &rarr; ${r.km_end || '-'} KM (Total: <strong>${distance} KM</strong>)
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 12px;">
            `;
            
            if (r.start_lat && r.start_lng) {
                const startUrl = `https://www.google.com/maps/search/?api=1&query=${r.start_lat},${r.start_lng}`;
                html += `
                    <div style="display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.02); padding: 12px; border-radius: 8px; border: 1px solid var(--glass-border);">
                        <div>
                            <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-primary);">📍 Start Location (Mulai Berangkat)</div>
                            <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 2px;">GPS: ${parseFloat(r.start_lat).toFixed(6)}, ${parseFloat(r.start_lng).toFixed(6)}</div>
                        </div>
                        <a href="${startUrl}" target="_blank" class="btn btn-success" style="padding: 6px 12px; font-size: 0.8rem; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">🗺️ View Start Map</a>
                    </div>
                `;
            } else {
                html += `
                    <div style="background: rgba(0,0,0,0.02); padding: 12px; border-radius: 8px; border: 1px dashed var(--glass-border); color: var(--text-secondary); font-size: 0.85rem;">
                        📍 Start GPS coordinates not recorded for this trip.
                    </div>
                `;
            }
            
            if (r.end_lat && r.end_lng) {
                const endUrl = `https://www.google.com/maps/search/?api=1&query=${r.end_lat},${r.end_lng}`;
                html += `
                    <div style="display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.02); padding: 12px; border-radius: 8px; border: 1px solid var(--glass-border);">
                        <div>
                            <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-primary);">🏁 End Location (Sampai)</div>
                            <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 2px;">GPS: ${parseFloat(r.end_lat).toFixed(6)}, ${parseFloat(r.end_lng).toFixed(6)}</div>
                        </div>
                        <a href="${endUrl}" target="_blank" class="btn btn-success" style="padding: 6px 12px; font-size: 0.8rem; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">🗺️ View End Map</a>
                    </div>
                `;
            } else {
                html += `
                    <div style="background: rgba(0,0,0,0.02); padding: 12px; border-radius: 8px; border: 1px dashed var(--glass-border); color: var(--text-secondary); font-size: 0.85rem;">
                        🏁 End GPS coordinates not recorded for this trip.
                    </div>
                `;
            }
            
            if (r.start_lat && r.start_lng && r.end_lat && r.end_lng) {
                const routeUrl = `https://www.google.com/maps/dir/?api=1&origin=${r.start_lat},${r.start_lng}&destination=${r.end_lat},${r.end_lng}&travelmode=driving`;
                html += `
                    <div style="margin-top: 8px; text-align: center; border-top: 1px dashed var(--glass-border); padding-top: 16px;">
                        <a href="${routeUrl}" target="_blank" class="btn" style="background: var(--accent-color); color: white; padding: 10px 16px; font-size: 0.85rem; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; font-weight: 600; width: 100%; justify-content: center; box-shadow: var(--glass-shadow);">
                            🚗 View Route & Actual Distance on Google Maps
                        </a>
                    </div>
                `;
            }
            
            html += `
                    </div>
                </div>
            `;
            
            container.innerHTML = html;
            document.getElementById('mediaModal').style.display = 'block';
        }

        function closeMedia() { document.getElementById('mediaModal').style.display = 'none'; }

        function openImageViewer(src) {
            document.getElementById('fullImageView').src = src;
            document.getElementById('imageViewerModal').style.display = 'block';
        }
        function closeImageViewer() {
            document.getElementById('imageViewerModal').style.display = 'none';
            document.getElementById('fullImageView').src = '';
        }

        // Close on outside click
        window.onclick = function(event) {
            if (event.target == document.getElementById('mediaModal')) {
                closeMedia();
            }
            if (event.target == document.getElementById('imageViewerModal')) {
                closeImageViewer();
            }
            if (event.target == document.getElementById('monthlyDetailsModal')) {
                closeMonthlyDetails();
            }
        }

        // Close on Escape key press
        window.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const imageViewer = document.getElementById('imageViewerModal');
                const mediaModal = document.getElementById('mediaModal');
                const monthlyModal = document.getElementById('monthlyDetailsModal');
                if (imageViewer && imageViewer.style.display === 'block') {
                    closeImageViewer();
                } else if (mediaModal && mediaModal.style.display === 'block') {
                    closeMedia();
                } else if (monthlyModal && monthlyModal.style.display === 'block') {
                    closeMonthlyDetails();
                }
            }
        });

        function exportToExcel() {
            const wb = XLSX.utils.book_new();
            const driverNames = [...new Set(currentData.map(d => d.driver_name))];
            
            driverNames.forEach(name => {
                const driverData = currentData.filter(d => d.driver_name === name);
                const firstRow = driverData[0];
                const dateObj = new Date(document.getElementById('start_date').value);
                const monthName = dateObj.toLocaleString('default', { month: 'long' });
                const year = dateObj.getFullYear();

                const rows = [
                    ["DAILY TRANSPORTATION REPORT"],
                    [],
                    ["Month :", monthName, "", "", "", "", "", "", "", "Driver :", name],
                    ["Year :", year, "", "", "", "", "", "", "", "Car No :", firstRow.car_no],
                    [],
                    ["Date", "Time", "", "Km", "", "", "Litre", "Amount", "", "", "", "Passenger", "Checked", "Place"],
                    ["", "In", "Out", "In", "Out", "Total", "", "Gasoline", "Toll", "Others", "Lunch Outside", "", "", ""]
                ];

                driverData.forEach(r => {
                    const allApproved = (r.expense_details || []).every(e => e.approval_status === 'approved');
                    rows.push([
                        r.shift_date,
                        r.start_time.substring(11,16),
                        r.end_time ? r.end_time.substring(11,16) : '-',
                        r.km_start,
                        r.km_end || '-',
                        r.km_end ? (r.km_end - r.km_start) : 0,
                        r.gas_litre || '-',
                        r.gas_amt || 0,
                        r.toll_amt || 0,
                        (parseInt(r.others_amt)||0) + (parseInt(r.parking_amt)||0),
                        r.lunch_amt || 0,
                        r.pass_name || '-',
                        allApproved ? "✔" : "-",
                        r.dest_name
                    ]);
                });

                const totalGas = driverData.reduce((sum, r) => sum + (parseInt(r.gas_amt)||0), 0);
                const totalToll = driverData.reduce((sum, r) => sum + (parseInt(r.toll_amt)||0), 0);
                const totalOthers = driverData.reduce((sum, r) => sum + (parseInt(r.others_amt)||0) + (parseInt(r.parking_amt)||0), 0);
                const totalLunch = driverData.reduce((sum, r) => sum + (parseInt(r.lunch_amt)||0), 0);
                const grandTotal = totalGas + totalToll + totalOthers + totalLunch;

                rows.push(["", "", "", "", "TOTAL", "", "", totalGas, totalToll, totalOthers, totalLunch, "", "", "Rp " + grandTotal.toLocaleString()]);

                const ws = XLSX.utils.aoa_to_sheet(rows);
                ws['!merges'] = [
                    {s:{r:0,c:0}, e:{r:0,c:13}}, {s:{r:2,c:10}, e:{r:2,c:13}}, {s:{r:3,c:10}, e:{r:3,c:13}},
                    {s:{r:5,c:1}, e:{r:5,c:2}}, {s:{r:5,c:3}, e:{r:5,c:5}}, {s:{r:5,c:7}, e:{r:5,c:10}}, {s:{r:5,c:11}, e:{r:5,c:12}}
                ];
                XLSX.utils.book_append_sheet(wb, ws, name.substring(0, 31));
            });
            XLSX.writeFile(wb, `Transport_Report_${new Date().toISOString().split('T')[0]}.xlsx`);
        }

        function exportToPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('l', 'pt', 'a4');
            const driverNames = [...new Set(currentData.map(d => d.driver_name))];
            driverNames.forEach((name, index) => {
                if (index > 0) doc.addPage();
                const driverData = currentData.filter(d => d.driver_name === name);
                const firstRow = driverData[0];
                const dateObj = new Date(document.getElementById('start_date').value);
                doc.setFontSize(16); doc.text("DAILY TRANSPORTATION REPORT", 40, 40);
                doc.setFontSize(10);
                doc.text(`Month : ${dateObj.toLocaleString('default', { month: 'long' })}`, 40, 65);
                doc.text(`Year  : ${dateObj.getFullYear()}`, 40, 80);
                doc.text(`Driver : ${name}`, 550, 65);
                doc.text(`Car No : ${firstRow.car_no}`, 550, 80);
                 const tableBody = driverData.map(r => {
                     const allApproved = (r.expense_details || []).every(e => e.approval_status === 'approved');
                     return [
                         r.shift_date, r.start_time.substring(11,16), r.end_time ? r.end_time.substring(11,16) : '-',
                         r.km_start, r.km_end || '-', r.km_end ? (r.km_end - r.km_start) : 0, r.gas_litre || '-',
                         r.gas_amt || 0, r.toll_amt || 0, (parseInt(r.others_amt)||0) + (parseInt(r.parking_amt)||0),
                         r.lunch_amt || 0, r.pass_name || '-', allApproved ? '✔' : '-', r.dest_name
                     ];
                 });
                 doc.autoTable({
                     head: [[{content:'Date',rowSpan:2},{content:'Time',colSpan:2},{content:'Km',colSpan:3},{content:'Litre',rowSpan:2},{content:'Amount',colSpan:4},{content:'Passenger',rowSpan:2},{content:'Checked',rowSpan:2},{content:'Place',rowSpan:2}],['In','Out','In','Out','Total','Gasoline','Toll','Others','Lunch']],
                     body: tableBody, startY: 100, theme: 'grid', styles: {fontSize:7, cellPadding:3}, headStyles: {fillColor:[51,51,51], halign:'center'}
                 });
            });
            doc.save(`Transport_Report_${new Date().getTime()}.pdf`);
        }

        function exportAnnualToExcel() {
            const wb = XLSX.utils.book_new();
            const year = document.getElementById('report_year').value;
            const driverId = document.getElementById('driver_id').value;
            let driverName = 'All Drivers';
            if (driverId !== 'ALL') {
                const driverSelect = document.getElementById('driver_id');
                driverName = driverSelect.options[driverSelect.selectedIndex].text;
            }
            
            const rows = [
                ["ANNUAL COST SUMMARY REPORT"],
                [],
                ["Year :", year, "", "Driver :", driverName],
                [],
                ["Month", "Gasoline (BBM)", "Toll", "Parking", "Lunch (Uang Makan)", "Others (Lain-lain)", "Total"]
            ];
            
            const monthNames = [
                'January', 'February', 'March', 'April', 'May', 'June', 
                'July', 'August', 'September', 'October', 'November', 'December'
            ];
            
            let totalGas = 0, totalToll = 0, totalParking = 0, totalLunch = 0, totalOthers = 0, grandTotal = 0;
            
            annualData.forEach(r => {
                totalGas += r.gasoline;
                totalToll += r.toll;
                totalParking += r.parking;
                totalLunch += r.lunch;
                totalOthers += r.others;
                grandTotal += r.total_amount;
                
                rows.push([
                    monthNames[r.month_num - 1],
                    r.gasoline,
                    r.toll,
                    r.parking,
                    r.lunch,
                    r.others,
                    r.total_amount
                ]);
            });
            
            rows.push([
                "TOTAL",
                totalGas,
                totalToll,
                totalParking,
                totalLunch,
                totalOthers,
                grandTotal
            ]);
            
            const ws = XLSX.utils.aoa_to_sheet(rows);
            ws['!merges'] = [
                {s:{r:0,c:0}, e:{r:0,c:6}}
            ];
            XLSX.utils.book_append_sheet(wb, ws, "Annual Summary");
            XLSX.writeFile(wb, `Annual_Cost_Summary_${year}_${new Date().toISOString().split('T')[0]}.xlsx`);
        }

        function exportAnnualToPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('p', 'pt', 'a4');
            const year = document.getElementById('report_year').value;
            const driverId = document.getElementById('driver_id').value;
            let driverName = 'All Drivers';
            if (driverId !== 'ALL') {
                const driverSelect = document.getElementById('driver_id');
                driverName = driverSelect.options[driverSelect.selectedIndex].text;
            }
            
            doc.setFontSize(16);
            doc.text("ANNUAL COST SUMMARY REPORT", 40, 40);
            doc.setFontSize(10);
            doc.text(`Year  : ${year}`, 40, 65);
            doc.text(`Driver : ${driverName}`, 40, 80);
            
            // Get chart image from canvas
            const chartCanvas = document.getElementById('annualChartCanvas');
            let startY = 110;
            if (chartCanvas) {
                try {
                    const chartImage = chartCanvas.toDataURL('image/png');
                    // Draw chart image (width: 515 pt, height: 200 pt)
                    doc.addImage(chartImage, 'PNG', 40, 100, 515, 200);
                    startY = 320; // Shift table below chart
                } catch (e) {
                    console.error("Failed to add chart to PDF: ", e);
                }
            }
            
            const monthNames = [
                'January', 'February', 'March', 'April', 'May', 'June', 
                'July', 'August', 'September', 'October', 'November', 'December'
            ];
            
            let totalGas = 0, totalToll = 0, totalParking = 0, totalLunch = 0, totalOthers = 0, grandTotal = 0;
            
            const tableBody = annualData.map(r => {
                totalGas += r.gasoline;
                totalToll += r.toll;
                totalParking += r.parking;
                totalLunch += r.lunch;
                totalOthers += r.others;
                grandTotal += r.total_amount;
                
                return [
                    monthNames[r.month_num - 1],
                    'Rp ' + parseInt(r.gasoline).toLocaleString(),
                    'Rp ' + parseInt(r.toll).toLocaleString(),
                    'Rp ' + parseInt(r.parking).toLocaleString(),
                    'Rp ' + parseInt(r.lunch).toLocaleString(),
                    'Rp ' + parseInt(r.others).toLocaleString(),
                    'Rp ' + parseInt(r.total_amount).toLocaleString()
                ];
            });
            
            // Add grand total row
            tableBody.push([
                'TOTAL',
                'Rp ' + totalGas.toLocaleString(),
                'Rp ' + totalToll.toLocaleString(),
                'Rp ' + totalParking.toLocaleString(),
                'Rp ' + totalLunch.toLocaleString(),
                'Rp ' + totalOthers.toLocaleString(),
                'Rp ' + grandTotal.toLocaleString()
            ]);
            
            doc.autoTable({
                head: [['Month', 'Gasoline (BBM)', 'Toll', 'Parking', 'Lunch (Uang Makan)', 'Others (Lain-lain)', 'Total']],
                body: tableBody,
                startY: startY,
                theme: 'grid',
                styles: { fontSize: 9, cellPadding: 5 },
                headStyles: { fillColor: [17, 141, 255], halign: 'center' },
                columnStyles: {
                    0: { fontStyle: 'bold' },
                    1: { halign: 'right' },
                    2: { halign: 'right' },
                    3: { halign: 'right' },
                    4: { halign: 'right' },
                    5: { halign: 'right' },
                    6: { halign: 'right', fontStyle: 'bold' }
                },
                didParseCell: function(data) {
                    if (data.row.index === tableBody.length - 1) {
                        data.cell.styles.fontStyle = 'bold';
                        data.cell.styles.fillColor = [240, 240, 240];
                    }
                }
            });
            
            doc.save(`Annual_Cost_Summary_${year}_${new Date().getTime()}.pdf`);
        }

         function openEditTripModal(tripId) {
             try {
                 console.log("openEditTripModal called for TX-" + tripId);
                 const trip = currentData.find(t => t.id == tripId);
                 if (!trip) {
                     alert("Trip data not found in current dataset!");
                     return;
                 }
                 
                 const editTripIdEl = document.getElementById('edit_trip_id');
                 const deleteTripIdEl = document.getElementById('delete_trip_id');
                 const editStartTimeEl = document.getElementById('edit_start_time');
                 const editEndTimeEl = document.getElementById('edit_end_time');
                 
                 if (!editTripIdEl || !deleteTripIdEl || !editStartTimeEl || !editEndTimeEl) {
                     alert("Error: One or more modal elements were not found in the DOM.");
                     return;
                 }
                 
                 editTripIdEl.value = tripId;
                 deleteTripIdEl.value = tripId;
                 
                 const formatForDatetimeLocal = (ts) => {
                     if (!ts) return '';
                     return ts.replace(' ', 'T').substring(0, 16);
                 };
                 
                 editStartTimeEl.value = formatForDatetimeLocal(trip.start_time);
                 editEndTimeEl.value = formatForDatetimeLocal(trip.end_time);
                 
                 // Populate expenses checklist & inputs
                 const expensesListEl = document.getElementById('modal_expenses_list');
                 if (expensesListEl) {
                     expensesListEl.innerHTML = '';
                     const expenses = trip.expense_details || [];
                     if (expenses.length === 0) {
                         expensesListEl.innerHTML = '<p style="color: var(--text-secondary); font-size: 0.8rem; font-style: italic; margin: 0;">Tidak ada pengajuan biaya untuk transaksi ini.</p>';
                     } else {
                         expenses.forEach(e => {
                             const isGas = e.expense_type === 'gasoline';
                             const checked = e.approval_status === 'approved' ? 'checked' : '';
                             
                             const expCard = document.createElement('div');
                             expCard.style.cssText = 'background: var(--bg-color); border: 1px solid var(--glass-border); border-radius: 8px; padding: 10px; font-size: 0.8rem; display: flex; flex-direction: column; gap: 8px; margin-bottom: 6px;';
                             expCard.innerHTML = `
                                 <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed var(--glass-border); padding-bottom: 6px;">
                                     <span style="font-weight: bold; color: var(--text-primary);">Expense ID: ${e.id} (${e.expense_type.toUpperCase()})</span>
                                     <label style="display: flex; align-items: center; gap: 6px; font-weight: 700; cursor: pointer; color: #166534; user-select: none;">
                                         <input type="checkbox" name="expense_approved[${e.id}]" value="approved" ${checked} style="width:16px; height:16px; cursor:pointer; margin: 0 4px 0 0;">
                                         Approve
                                     </label>
                                 </div>
                                 <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                     <div style="flex: 1; min-width: 100px;">
                                         <label style="font-size: 0.7rem; color: var(--text-secondary); display: block; margin-bottom: 2px;">Type</label>
                                         <select name="expense_type[${e.id}]" onchange="toggleLitreInput(${e.id}, this.value)" style="width: 100%; padding: 4px 6px; font-size: 0.75rem; border-radius: 4px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                                             <option value="gasoline" ${e.expense_type==='gasoline'?'selected':''}>Gasoline</option>
                                             <option value="toll" ${e.expense_type==='toll'?'selected':''}>Toll</option>
                                             <option value="parking" ${e.expense_type==='parking'?'selected':''}>Parking</option>
                                             <option value="lunch" ${e.expense_type==='lunch'?'selected':''}>Lunch</option>
                                             <option value="others" ${e.expense_type==='others'?'selected':''}>Others</option>
                                         </select>
                                     </div>
                                     <div style="flex: 1; min-width: 100px;">
                                         <label style="font-size: 0.7rem; color: var(--text-secondary); display: block; margin-bottom: 2px;">Amount (Rp)</label>
                                         <input type="number" name="expense_amount[${e.id}]" value="${parseInt(e.amount)}" style="width: 100%; padding: 4px 6px; font-size: 0.75rem; border-radius: 4px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                                     </div>
                                     <div id="litre_div_${e.id}" style="flex: 1; min-width: 80px; display: ${isGas?'block':'none'};">
                                         <label style="font-size: 0.7rem; color: var(--text-secondary); display: block; margin-bottom: 2px;">Litre</label>
                                         <input type="number" step="any" name="expense_litre[${e.id}]" value="${e.litre || ''}" style="width: 100%; padding: 4px 6px; font-size: 0.75rem; border-radius: 4px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                                     </div>
                                 </div>
                                 <div>
                                     <label style="font-size: 0.7rem; color: var(--text-secondary); display: block; margin-bottom: 2px;">Note / Catatan Admin</label>
                                     <input type="text" name="expense_note[${e.id}]" value="${e.supervisor_note || ''}" placeholder="Tambahkan catatan..." style="width: 100%; padding: 4px 6px; font-size: 0.75rem; border-radius: 4px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                                 </div>
                             `;
                             expensesListEl.appendChild(expCard);
                         });
                     }
                 }
                 
                 const adminDeleteTripBtn = document.getElementById('adminDeleteTripBtn');
                 const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
                 if (adminDeleteTripBtn) {
                     adminDeleteTripBtn.innerText = lang === 'id' ? `Hapus TX-${tripId}` : `Delete TX-${tripId}`;
                 }
                 
                 document.getElementById('editTripModalTitle').innerText = `Edit Trip TX-${tripId} (${trip.driver_name})`;
                 document.getElementById('editTripModal').style.display = 'block';
             } catch (err) {
                 console.error("Error in openEditTripModal:", err);
                 alert("JS Error: " + err.message);
             }
         }
 
         function toggleLitreInput(id, val) {
             const div = document.getElementById('litre_div_' + id);
             if (div) {
                 div.style.display = val === 'gasoline' ? 'block' : 'none';
             }
         }
         
         function filterUncheckedOnly() {
             document.getElementById('report-filter-status').value = 'pending';
             renderReportTable();
         }
        
        function closeEditTripModal() {
            document.getElementById('editTripModal').style.display = 'none';
        }
        
        function confirmDeleteTripAdmin() {
            const tripId = document.getElementById('delete_trip_id').value;
            const targetCode = `TX-${tripId}`;
            const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
            
            const promptMsg = lang === 'id'
                ? `Peringatan: Menghapus ${targetCode} akan menghapus seluruh rincian biaya dan foto struk secara permanen.\n\nUntuk melanjutkan, silakan ketik ulang "${targetCode}" di bawah ini:`
                : `Warning: Deleting ${targetCode} will permanently remove all associated expenses and receipts.\n\nTo proceed, please re-type "${targetCode}" below:`;
            
            const userInput = prompt(promptMsg);
            if (userInput === targetCode) {
                document.getElementById('deleteTripForm').submit();
            } else if (userInput !== null) {
                alert(lang === 'id' 
                    ? `Konfirmasi gagal. Kode yang Anda masukkan salah.` 
                    : `Confirmation failed. The code you entered did not match.`);
            }
        }

        async function filterPendingTrips() {
            switchTab('detail');
            document.getElementById('driver_id').value = 'ALL';
            document.getElementById('start_date').value = '2020-01-01';
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('end_date').value = today;
            await generateReport();
            document.getElementById('report-filter-status').value = 'pending';
            renderReportTable();
        }

        // -------------------------------------------------------
        // FILTER STATE PERSISTENCE via localStorage
        // Keyed per-user so different admins don't share state.
        // Cleared automatically on logout (see logout handler below).
        // -------------------------------------------------------
        const FILTER_KEY = 'report_filters_uid_<?= $_SESSION['user_id'] ?? '0' ?>';

        function saveFilterState() {
            const state = {
                driver_id:    document.getElementById('driver_id').value,
                start_date:   document.getElementById('start_date').value,
                end_date:     document.getElementById('end_date').value,
                report_year:  document.getElementById('report_year').value,
                view_mode:    document.getElementById('report-view-mode').value,
                sort:         document.getElementById('report-sort').value,
                status:       document.getElementById('report-filter-status').value,
                search:       document.getElementById('report-search').value,
                page_size:    pageSize,
                active_tab:   activeTab,
            };
            localStorage.setItem(FILTER_KEY, JSON.stringify(state));
        }

        function restoreFilterState() {
            const raw = localStorage.getItem(FILTER_KEY);
            if (!raw) return false;
            try {
                const state = JSON.parse(raw);
                if (state.driver_id  !== undefined) document.getElementById('driver_id').value           = state.driver_id;
                if (state.start_date !== undefined) document.getElementById('start_date').value          = state.start_date;
                if (state.end_date   !== undefined) document.getElementById('end_date').value            = state.end_date;
                if (state.report_year!== undefined) document.getElementById('report_year').value         = state.report_year;
                if (state.view_mode  !== undefined) document.getElementById('report-view-mode').value    = state.view_mode;
                if (state.sort       !== undefined) document.getElementById('report-sort').value         = state.sort;
                if (state.status     !== undefined) document.getElementById('report-filter-status').value= state.status;
                if (state.search     !== undefined) document.getElementById('report-search').value       = state.search;
                if (state.page_size  !== undefined) pageSize = parseInt(state.page_size);
                if (state.active_tab !== undefined) {
                    activeTab = state.active_tab;
                    // Sync tab UI
                    document.querySelectorAll('.tab-btn').forEach(btn => {
                        btn.classList.toggle('active', btn.dataset.tab === activeTab);
                    });
                    document.querySelectorAll('.tab-content').forEach(sec => {
                        sec.style.display = sec.id === (activeTab === 'detail' ? 'detailTabContent' : 'annualTabContent') ? 'block' : 'none';
                    });
                }
                return true;
            } catch(e) {
                return false;
            }
        }

        // Attach listeners for interactive auto-updating controls and initial generation
        document.addEventListener('DOMContentLoaded', () => {
            // Restore saved filter state (if any)
            restoreFilterState();

            // Auto-trigger AJAX generation when main filters change
            document.getElementById('driver_id').addEventListener('change', () => {
                saveFilterState();
                if (activeTab === 'detail') generateReport(); else generateAnnualReport();
            });
            document.getElementById('start_date').addEventListener('change', () => {
                saveFilterState();
                if (activeTab === 'detail') generateReport();
            });
            document.getElementById('end_date').addEventListener('change', () => {
                saveFilterState();
                if (activeTab === 'detail') generateReport();
            });
            document.getElementById('report_year').addEventListener('change', () => {
                saveFilterState();
                if (activeTab === 'annual') generateAnnualReport();
            });

            // Client-side instant sort/filter/search updates
            document.getElementById('report-search').addEventListener('input', () => {
                currentPage = 1;
                saveFilterState();
                renderReportTable();
            });
            document.getElementById('report-sort').addEventListener('change', () => {
                currentPage = 1;
                saveFilterState();
                renderReportTable();
            });
            document.getElementById('report-filter-status').addEventListener('change', () => {
                currentPage = 1;
                saveFilterState();
                renderReportTable();
            });
            document.getElementById('report-view-mode').addEventListener('change', () => {
                currentPage = 1;
                saveFilterState();
                renderReportTable();
            });

            // Automatically load initial report on page open
            if (activeTab === 'detail') {
                generateReport();
            } else {
                generateAnnualReport();
            }
        });
    </script>
    </form>

    <!-- Modal for Edit Expense -->
    <div id="editExpenseModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h2>Edit Expense</h2>
                <span class="close" onclick="document.getElementById('editExpenseModal').style.display='none'">&times;</span>
            </div>
            <form method="POST" action="report.php">
                <input type="hidden" name="action" value="edit_expense_admin">
                <input type="hidden" name="expense_id" id="edit_expense_id">
                
                <div class="pbi-form-group">
                    <label class="pbi-label">Expense Type</label>
                    <select name="expense_type" id="edit_expense_type" class="pbi-input" required>
                        <option value="gasoline">Gasoline</option>
                        <option value="toll">Toll</option>
                        <option value="parking">Parking</option>
                        <option value="lunch">Lunch</option>
                        <option value="others">Others</option>
                    </select>
                </div>
                
                <div class="pbi-form-group">
                    <label class="pbi-label">Amount (Rp)</label>
                    <input type="number" name="amount" id="edit_expense_amount" class="pbi-input" required>
                </div>
                
                <div class="pbi-form-group">
                    <label class="pbi-label">Litre (for Gasoline)</label>
                    <input type="number" step="0.01" name="litre" id="edit_expense_litre" class="pbi-input">
                </div>
                
                <button type="submit" class="btn-primary" style="width:100%; margin-top:10px;">Save Changes</button>
            </form>
        </div>
    </div>

    <script>
        function approveExpenseAdmin(id) {
            if (confirm("Approve this expense?")) {
                const formData = new FormData();
                formData.append('action', 'approve_expense_admin');
                formData.append('expense_id', id);
                fetch('report.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            alert("Expense Approved!");
                            location.reload();
                        } else {
                            alert("Error: " + data.error);
                        }
                    });
            }
        }

        function editExpenseAdmin(id, type, amount, litre) {
            document.getElementById('edit_expense_id').value = id;
            document.getElementById('edit_expense_type').value = type;
            document.getElementById('edit_expense_amount').value = amount;
            document.getElementById('edit_expense_litre').value = litre || '';
            
            // Close media container first
            document.getElementById('mediaModal').style.display = 'none';
            document.getElementById('editExpenseModal').style.display = 'flex';
        }

        function approveGroupAdmin(driverId, shiftDate) {
            if (confirm(`Approve all expenses for this driver on ${shiftDate}?`)) {
                const formData = new FormData();
                formData.append('action', 'approve_group_admin');
                formData.append('driver_id', driverId);
                formData.append('shift_date', shiftDate);
                fetch('report.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            alert("Group Expenses Approved!");
                            generateReport(); // Refresh data preview
                        } else {
                            alert("Error: " + data.error);
                        }
                    });
            }
        }

        function viewGroupDetails(driverId, shiftDate) {
            // Set view mode to detail to show individual items
            document.getElementById('report-view-mode').value = 'detail';
            
            // Filter by driver and exact shift date to inspect them
            document.getElementById('start_date').value = shiftDate;
            document.getElementById('end_date').value = shiftDate;
            document.getElementById('driver_id').value = driverId;
            
            // Trigger load & render
            generateReport();
        }

        // -------------------------------------------------------
        // GROUP EDIT MODAL — inline expense editing from Group view
        // -------------------------------------------------------
        let _groupEditCtx = { driverId: null, shiftDate: null, rowKey: null };

        async function openGroupEditModal(driverId, shiftDate) {
            _groupEditCtx = { driverId, shiftDate, rowKey: `${driverId}_${shiftDate}` };

            const modal = document.getElementById('groupEditModal');
            const body  = document.getElementById('groupEditBody');
            document.getElementById('groupEditTitle').textContent =
                `Edit Expenses — ${shiftDate}`;
            body.innerHTML = `<div style="text-align:center;padding:24px;color:var(--text-secondary);">Loading trips...</div>`;
            modal.style.display = 'flex';

            const fd = new FormData();
            fd.append('action', 'get_group_trips');
            fd.append('driver_id', driverId);
            fd.append('shift_date', shiftDate);
            const res  = await fetch('report.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (!data.success) {
                body.innerHTML = `<p style="color:red">Error: ${data.error}</p>`;
                return;
            }

            if (!data.trips.length) {
                body.innerHTML = `<p style="color:var(--text-secondary)">No completed trips found for this group.</p>`;
                return;
            }

            body.innerHTML = data.trips.map(trip => {
                const dest     = trip.dest_name || '-';
                const pass     = trip.pass_name || '-';
                const timeIn   = trip.start_time ? trip.start_time.substring(11,16) : '-';
                const timeOut  = trip.end_time   ? trip.end_time.substring(11,16)   : '-';
                const km       = (trip.km_start && trip.km_end) ? (trip.km_end - trip.km_start) : '-';

                const expRows = trip.expenses.length ? trip.expenses.map(e => {
                    const isGas = e.expense_type === 'gasoline';
                    const approved = e.approval_status === 'approved';
                    return `
                    <tr id="exp-row-${e.id}" style="border-bottom:1px solid var(--glass-border);">
                        <td style="padding:6px 8px;font-size:0.78rem;">
                            <select id="etype-${e.id}" onchange="document.getElementById('elitre-wrap-${e.id}').style.display=this.value==='gasoline'?'':'none'"
                                style="padding:3px 6px;font-size:0.75rem;border-radius:4px;border:1px solid var(--glass-border);background:var(--card-bg);color:var(--text-primary);">
                                <option value="gasoline" ${e.expense_type==='gasoline'?'selected':''}>Gasoline</option>
                                <option value="toll"     ${e.expense_type==='toll'?'selected':''}>Toll</option>
                                <option value="parking"  ${e.expense_type==='parking'?'selected':''}>Parking</option>
                                <option value="lunch"    ${e.expense_type==='lunch'?'selected':''}>Lunch</option>
                                <option value="others"   ${e.expense_type==='others'?'selected':''}>Others</option>
                            </select>
                        </td>
                        <td style="padding:6px 8px;">
                            <input id="eamt-${e.id}" type="number" value="${parseInt(e.amount)}"
                                style="width:110px;padding:3px 6px;font-size:0.78rem;border-radius:4px;border:1px solid var(--glass-border);background:var(--card-bg);color:var(--text-primary);">
                        </td>
                        <td id="elitre-wrap-${e.id}" style="padding:6px 8px;display:${isGas?'':'none'};">
                            <input id="elitre-${e.id}" type="number" step="0.01" value="${e.litre||''}"
                                style="width:70px;padding:3px 6px;font-size:0.78rem;border-radius:4px;border:1px solid var(--glass-border);background:var(--card-bg);color:var(--text-primary);">
                        </td>
                        <td style="padding:6px 8px;">
                            <input id="enote-${e.id}" type="text" value="${(e.supervisor_note||'').replace(/"/g,'&quot;')}" placeholder="note..."
                                style="width:120px;padding:3px 6px;font-size:0.75rem;border-radius:4px;border:1px solid var(--glass-border);background:var(--card-bg);color:var(--text-primary);">
                        </td>
                        <td style="padding:6px 8px;text-align:center;">
                            <input id="eapprove-${e.id}" type="checkbox" ${approved?'checked':''} style="width:16px;height:16px;cursor:pointer;">
                        </td>
                        <td style="padding:6px 8px;text-align:center;">
                            <button onclick="saveExpenseAjax(${e.id}, ${trip.id})"
                                style="background:#3b82f6;color:#fff;border:none;padding:4px 10px;border-radius:4px;font-size:0.72rem;font-weight:700;cursor:pointer;">
                                💾 Save
                            </button>
                            <span id="esave-status-${e.id}" style="font-size:0.7rem;margin-left:4px;"></span>
                        </td>
                    </tr>`;
                }).join('') : `<tr><td colspan="6" style="padding:10px;color:var(--text-secondary);font-style:italic;font-size:0.8rem;">No expenses recorded.</td></tr>`;

                return `
                <div style="margin-bottom:18px;background:var(--card-bg);border:1px solid var(--glass-border);border-radius:10px;overflow:hidden;">
                    <div style="background:rgba(17,141,255,0.08);padding:10px 14px;display:flex;justify-content:space-between;align-items:center;">
                        <strong style="font-size:0.85rem;color:var(--pbi-blue);">TX-${trip.id} &nbsp;·&nbsp; ${timeIn}–${timeOut}</strong>
                        <span style="font-size:0.78rem;color:var(--text-secondary);">${dest} &nbsp;|&nbsp; ${pass} &nbsp;|&nbsp; Δkm: ${km}</span>
                    </div>
                    <table style="width:100%;border-collapse:collapse;">
                        <thead>
                            <tr style="background:rgba(0,0,0,0.03);font-size:0.72rem;color:var(--text-secondary);text-transform:uppercase;">
                                <th style="padding:5px 8px;text-align:left;">Type</th>
                                <th style="padding:5px 8px;text-align:left;">Amount (Rp)</th>
                                <th style="padding:5px 8px;text-align:left;">Litre</th>
                                <th style="padding:5px 8px;text-align:left;">Note</th>
                                <th style="padding:5px 8px;text-align:center;">Approve</th>
                                <th style="padding:5px 8px;text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="trip-exp-${trip.id}">${expRows}</tbody>
                    </table>
                </div>`;
            }).join('');
        }

        async function saveExpenseAjax(expId, tripId) {
            const type    = document.getElementById(`etype-${expId}`).value;
            const amount  = document.getElementById(`eamt-${expId}`).value;
            const litreEl = document.getElementById(`elitre-${expId}`);
            const litre   = litreEl ? litreEl.value : '';
            const note    = document.getElementById(`enote-${expId}`).value;
            const approved= document.getElementById(`eapprove-${expId}`).checked ? '1' : '0';
            const statusEl= document.getElementById(`esave-status-${expId}`);

            statusEl.textContent = '⏳';
            statusEl.style.color = '#f59e0b';

            const fd = new FormData();
            fd.append('action',   'edit_expense_ajax');
            fd.append('expense_id', expId);
            fd.append('expense_type', type);
            fd.append('amount',   amount);
            fd.append('litre',    litre);
            fd.append('note',     note);
            fd.append('approved', approved);

            const res  = await fetch('report.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (!data.success) {
                statusEl.textContent = '❌ ' + (data.error || 'Error');
                statusEl.style.color = '#e11d48';
                return;
            }

            statusEl.textContent = '✔ Saved';
            statusEl.style.color = '#16a34a';
            setTimeout(() => statusEl.textContent = '', 2500);

            // ─── Update currentData in-memory so the group row refreshes correctly ───
            currentData.forEach(r => {
                if (!r.expense_details) return;
                r.expense_details.forEach(e => {
                    if (e.id == expId) {
                        e.expense_type     = data.expense_type;
                        e.amount           = data.amount;
                        e.litre            = data.litre;
                        e.approval_status  = data.approval_status;
                    }
                });
                // Recalculate aggregated amounts on the trip record
                if (r.id == tripId) {
                    r.gas_amt    = r.expense_details.filter(e=>e.expense_type==='gasoline').reduce((s,e)=>s+parseFloat(e.amount||0),0)||null;
                    r.gas_litre  = r.expense_details.filter(e=>e.expense_type==='gasoline').reduce((s,e)=>s+parseFloat(e.litre||0),0)||null;
                    r.toll_amt   = r.expense_details.filter(e=>e.expense_type==='toll').reduce((s,e)=>s+parseFloat(e.amount||0),0)||null;
                    r.lunch_amt  = r.expense_details.filter(e=>e.expense_type==='lunch').reduce((s,e)=>s+parseFloat(e.amount||0),0)||null;
                    r.others_amt = r.expense_details.filter(e=>e.expense_type==='others').reduce((s,e)=>s+parseFloat(e.amount||0),0)||null;
                    r.parking_amt= r.expense_details.filter(e=>e.expense_type==='parking').reduce((s,e)=>s+parseFloat(e.amount||0),0)||null;
                    const allApproved = r.expense_details.every(e=>e.approval_status==='approved');
                    r.passenger_approval = allApproved ? 'approved' : 'pending';
                }
            });

            // ─── Find the group row DOM element and update only the affected columns ───
            const viewMode = document.getElementById('report-view-mode').value;
            if (viewMode === 'group') {
                // Recalculate group totals from updated currentData
                const key = _groupEditCtx.rowKey;
                const [gDriverId, gDate] = key.split('_');
                let gas=0, toll=0, others=0, parking=0, lunch=0, allApproved=true;
                currentData.forEach(r => {
                    if (r.driver_id == gDriverId && r.shift_date === gDate) {
                        gas     += parseFloat(r.gas_amt)||0;
                        toll    += parseFloat(r.toll_amt)||0;
                        others  += parseFloat(r.others_amt)||0;
                        parking += parseFloat(r.parking_amt)||0;
                        lunch   += parseFloat(r.lunch_amt)||0;
                        if (!(r.expense_details||[]).every(e=>e.approval_status==='approved')) {
                            allApproved = false;
                        }
                    }
                });
                const total = gas+toll+others+parking+lunch;
                const fmt   = v => v ? 'Rp '+parseInt(v).toLocaleString() : '-';

                // Find the row by searching all tbody tr cells for matching driver+date
                document.querySelectorAll('#reportContent tr').forEach(tr => {
                    const cells = tr.querySelectorAll('td');
                    if (cells.length >= 8) {
                        // cells[0]=driver, cells[1]=date
                        const rowDate = cells[1] ? cells[1].textContent.trim() : '';
                        if (rowDate === gDate) {
                            cells[3].textContent = fmt(gas);
                            cells[4].textContent = fmt(toll);
                            cells[5].textContent = fmt(others+parking);
                            cells[6].textContent = fmt(lunch);
                            cells[7].textContent = 'Rp '+parseInt(total).toLocaleString();
                            cells[8].innerHTML   = allApproved
                                ? '<span style="color:#166534;font-weight:700;font-size:1.1rem;">✔</span>'
                                : '<span style="color:#94a3b8;font-weight:500;">-</span>';
                            // Show/hide Approve All button
                            const actionCell = cells[9];
                            if (actionCell) {
                                let btnHtml = '';
                                if (!allApproved) {
                                    btnHtml += `<button onclick="approveGroupAdmin(${gDriverId}, '${gDate}')" style="background:#10b981;color:#fff;border:none;padding:4px 8px;border-radius:4px;cursor:pointer;font-size:0.75rem;font-weight:bold;margin-right:6px;">Approve All</button>`;
                                }
                                btnHtml += `<button onclick="viewGroupDetails(${gDriverId}, '${gDate}')" style="background:#3b82f6;color:#fff;border:none;padding:4px 8px;border-radius:4px;cursor:pointer;font-size:0.75rem;font-weight:bold;">Detail</button>`;
                                actionCell.innerHTML = btnHtml;
                            }
                        }
                    }
                });
            }
        }

        function closeGroupEditModal() {
            document.getElementById('groupEditModal').style.display = 'none';
        }
    </script>

    <!-- Group Edit Modal -->
    <div id="groupEditModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:flex-start;justify-content:center;padding:24px;overflow-y:auto;">
        <div style="background:var(--card-bg);border-radius:14px;width:100%;max-width:900px;box-shadow:0 20px 60px rgba(0,0,0,0.3);overflow:hidden;">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 22px;background:rgba(17,141,255,0.06);border-bottom:1px solid var(--glass-border);">
                <h2 id="groupEditTitle" style="margin:0;font-size:1.05rem;color:var(--pbi-blue);">✏️ Edit Group Expenses</h2>
                <button onclick="closeGroupEditModal()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:var(--text-secondary);line-height:1;">&times;</button>
            </div>
            <div id="groupEditBody" style="padding:20px;max-height:75vh;overflow-y:auto;">
                <!-- Trips will be rendered here -->
            </div>
            <div style="padding:12px 22px;border-top:1px solid var(--glass-border);text-align:right;">
                <button onclick="closeGroupEditModal()" style="background:#e2e8f0;color:#475569;border:none;padding:8px 18px;border-radius:6px;font-weight:600;cursor:pointer;">Close</button>
            </div>
        </div>
    </div>
</body>
</html>
