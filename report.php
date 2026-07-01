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
        $passenger_approval = $_POST['passenger_approval'];
        $passenger_feedback = $_POST['passenger_feedback'];
        
        $stmt = $pdo->prepare("UPDATE trips 
                               SET start_time = ?, end_time = ?, passenger_approval = ?, passenger_feedback = ? 
                               WHERE id = ?");
        $stmt->execute([$start_time, $end_time, $passenger_approval, $passenger_feedback, $trip_id]);
        
        header("Location: report.php?msg=" . urlencode("Trip TX-{$trip_id} updated successfully"));
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

    <div class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand">Transport Overview</div>
            <div class="toggle-btn" onclick="toggleSidebar()">☰</div>
        </div>
        <nav style="padding: 10px 0;">
            <a href="admin.php" class="nav-item"><div class="nav-icon">📊</div><span>Dashboard</span></a>
            <a href="master_data.php" class="nav-item"><div class="nav-icon">📁</div><span><?php echo __('master_data'); ?></span></a>
            <a href="report.php" class="nav-item active"><div class="nav-icon">📝</div><span><?php echo __('reports'); ?></span></a>
            <a href="attendance_report.php" class="nav-item"><div class="nav-icon">⏰</div><span><?php echo __('attendance'); ?></span></a>
            <a href="docs.php" class="nav-item" target="_blank"><div class="nav-icon" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6;">📖</div><span>Manual</span></a>
        </nav>
        
        <div class="lang-theme-footer">
            <div style="font-size: 0.7rem; color: #999; margin-bottom: 10px; font-weight: 700;">PREFERENCES</div>
            <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                <a href="?lang=en" style="text-decoration:none; color: <?php echo $_SESSION['lang']=='en'?'var(--pbi-blue)':'#666'; ?>; font-weight: 700;">EN</a>
                <a href="?lang=id" style="text-decoration:none; color: <?php echo $_SESSION['lang']=='id'?'var(--pbi-blue)':'#666'; ?>; font-weight: 700;">ID</a>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="?theme=light" style="text-decoration:none; font-size: 0.75rem; color: <?php echo $theme=='light'?'var(--pbi-blue)':'#666'; ?>; font-weight: 700;">LIGHT</a>
                <a href="?theme=dark" style="text-decoration:none; font-size: 0.75rem; color: <?php echo $theme=='dark'?'var(--pbi-blue)':'#666'; ?>; font-weight: 700;">DARK</a>
            </div>
            
            <div style="margin-top: 20px; border-top: 1px dashed var(--glass-border); padding-top: 15px;">
                <a href="admin_password.php" style="display:block; color: var(--pbi-blue); text-decoration: none; font-size: 0.75rem; font-weight: 700; margin-bottom: 10px;">🔑 Ganti Password</a>
                <a href="login.php" style="display:block; color: var(--text-secondary); text-decoration: none; font-size: 0.85rem; font-weight: 700;">Logout</a>
            </div>
        </div>
    </div>

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

                <!-- Interactive controls for reports table -->
                <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; background: rgba(0,0,0,0.02); padding: 8px; border-radius: 6px; border: 1px solid var(--glass-border); align-items: center;">
                    <input type="text" id="report-search" placeholder="Search driver, passenger, destination, TX-ID..." style="flex: 1.5; min-width: 160px; padding: 6px 8px; font-size: 0.8rem; border-radius: 4px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                    
                    <select id="report-sort" style="flex: 1; min-width: 120px; padding: 6px 8px; font-size: 0.8rem; border-radius: 4px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                        <option value="date_desc">Date: Newest First</option>
                        <option value="date_asc">Date: Oldest First</option>
                        <option value="dist_desc">Distance: High to Low</option>
                        <option value="dist_asc">Distance: Low to High</option>
                        <option value="cost_desc">Cost: High to Low</option>
                        <option value="cost_asc">Cost: Low to High</option>
                    </select>
                    
                    <select id="report-filter-status" style="flex: 1; min-width: 120px; padding: 6px 8px; font-size: 0.8rem; border-radius: 4px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                        <option value="all">All Approval Status</option>
                        <option value="approved">Approved</option>
                        <option value="pending">Pending</option>
                    </select>
                </div>

                <div style="overflow-x: auto;">
                    <table class="pbi-table" id="reportTable">
                        <thead>
                            <tr>
                                <th rowspan="2">TX ID</th>
                                <th rowspan="2">Driver</th>
                                <th rowspan="2">Date</th>
                                <th colspan="2">Time</th>
                                <th colspan="3">Km</th>
                                <th rowspan="2">Litre</th>
                                <th colspan="4">Amount</th>
                                <th rowspan="2">Approved</th>
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
                
                <div style="margin-bottom: 15px;">
                    <label class="pbi-label">Passenger Approval Status</label>
                    <div style="display: flex; align-items: center; justify-content: space-between; background: var(--bg-color); padding: 8px 12px; border-radius: 8px; border: 1px solid var(--glass-border);">
                        <span id="edit_passenger_approval_text" style="font-weight: bold;">Pending</span>
                        <input type="hidden" name="passenger_approval" id="edit_passenger_approval">
                        <button type="button" id="adminApproveBtn" onclick="setAdminApproved()" class="btn-export" style="background: #166534; font-size: 0.75rem; padding: 6px 12px; margin: 0; display: none;">Approve by Admin</button>
                    </div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label class="pbi-label">Approval Feedback / Note</label>
                    <textarea name="passenger_feedback" id="edit_passenger_feedback" class="pbi-input" style="height: 60px; resize: vertical;"></textarea>
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

                const matchesStatus = (filterStatus === 'all') || 
                                      (filterStatus === 'approved' && r.passenger_approval === 'approved') ||
                                      (filterStatus === 'pending' && r.passenger_approval !== 'approved');

                return matchesSearch && matchesStatus;
            });

            // 2. Sort
            filtered.sort((a, b) => {
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

            // 3. Paginate
            const totalEntries = filtered.length;
            const totalPages = Math.ceil(totalEntries / pageSize) || 1;
            if (currentPage > totalPages) {
                currentPage = totalPages;
            }
            const startIndex = (currentPage - 1) * pageSize;
            const endIndex = Math.min(startIndex + pageSize, totalEntries);
            const pageData = filtered.slice(startIndex, endIndex);

            // 4. Render Table
            const tbody = document.getElementById('reportContent');
            if (pageData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="15" align="center" style="padding:20px; color:var(--text-secondary);">No matching records found.</td></tr>';
                document.getElementById('report-pagination').innerHTML = '';
                return;
            }

            tbody.innerHTML = pageData.map((r) => {
                const appvStatus = r.passenger_approval === 'approved' ? (r.passenger_feedback === 'Approved by Admin' ? 'Approved by Admin' : r.pass_name) : `Belum Appv oleh ${r.pass_name}`;
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
                    <td align="center"><span style="color: ${r.passenger_approval === 'approved' ? 'inherit' : '#e11d48'}; font-weight: ${r.passenger_approval === 'approved' ? 'normal' : '600'};">${appvStatus}</span></td>
                    <td class="clickable-data" onclick="showTripMap(${r.id})">${r.dest_name}</td>
                </tr>
            `;}).join('');

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
                title.innerText = `Odometer Photo (${type === 'km_start' ? 'Start' : 'End'})`;
                const photo = type === 'km_start' ? r.km_start_photo : r.km_end_photo;
                const time = type === 'km_start' ? r.start_time : r.end_time;
                if (photo) {
                    container.innerHTML = `
                        <div style="background: var(--bg-color); border: 1px solid var(--glass-border); border-radius: 12px; padding: 16px; margin-bottom: 12px; font-family: inherit;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 0.8rem; border-bottom: 1px dashed var(--glass-border); padding-bottom: 8px; color: var(--text-secondary);">
                                <span>Total Files: <strong>1</strong></span>
                                <span>Uploaded At: <strong>${time}</strong></span>
                            </div>
                            <div style="display: flex; gap: 16px; align-items: center;">
                                <div style="position: relative; cursor: pointer; min-width: 100px;" onclick="openImageViewer('uploads/${photo}')" title="Click to view full size">
                                    <img src="uploads/thumb_${photo}" onerror="this.src='uploads/${photo}'" class="evidence-img" style="width: 100px; height: 100px; object-fit: cover; border-radius: 8px; border: 1px solid var(--glass-border); display: block;">
                                    <div style="position: absolute; bottom: 4px; right: 4px; background: rgba(0,0,0,0.6); color: white; border-radius: 4px; padding: 2px 4px; font-size: 0.65rem;">🔍</div>
                                </div>
                                <div style="font-size: 0.85rem; color: var(--text-primary);">
                                    <div style="margin-bottom: 4px;"><strong>Odometer (${type === 'km_start' ? 'Start' : 'End'})</strong></div>
                                    <div style="color: var(--text-secondary);">Value: <strong>${type === 'km_start' ? r.km_start : (r.km_end || '-')} KM</strong></div>
                                </div>
                            </div>
                        </div>`;
                } else {
                    container.innerHTML = '<p style="text-align:center; padding:20px; color: var(--text-secondary);">No photo available.</p>';
                }
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
                        html += `
                            <div style="background: var(--bg-color); border: 1px solid var(--glass-border); border-radius: 12px; padding: 12px; display: flex; gap: 16px; align-items: center; justify-content: space-between;">
                                <div style="display: flex; gap: 16px; align-items: center;">
                                    <div style="position: relative; cursor: pointer; min-width: 80px;" onclick="openImageViewer('uploads/${e.photo}')" title="Click to view full size">
                                        <img src="uploads/thumb_${e.photo}" onerror="this.src='uploads/${e.photo}'" class="evidence-img" style="width: 80px; height: 80px; object-fit: cover; border-radius: 8px; border: 1px solid var(--glass-border); display: block;">
                                        <div style="position: absolute; bottom: 4px; right: 4px; background: rgba(0,0,0,0.6); color: white; border-radius: 4px; padding: 2px 4px; font-size: 0.65rem;">🔍</div>
                                    </div>
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
                    ["Date", "Time", "", "Km", "", "", "Litre", "Amount", "", "", "", "Approved by", "", "Place"],
                    ["", "In", "Out", "In", "Out", "Total", "", "Gasoline", "Toll", "Others", "Lunch Outside", "User", "Signature", ""]
                ];

                driverData.forEach(r => {
                    const appvStatus = r.passenger_approval === 'approved' ? (r.passenger_feedback === 'Approved by Admin' ? 'Approved by Admin' : r.pass_name) : `Belum Appv oleh ${r.pass_name}`;
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
                        appvStatus,
                        "",
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
                const tableBody = driverData.map(r => [
                    r.shift_date, r.start_time.substring(11,16), r.end_time ? r.end_time.substring(11,16) : '-',
                    r.km_start, r.km_end || '-', r.km_end ? (r.km_end - r.km_start) : 0, r.gas_litre || '-',
                    r.gas_amt || 0, r.toll_amt || 0, (parseInt(r.others_amt)||0) + (parseInt(r.parking_amt)||0),
                    r.lunch_amt || 0, r.passenger_approval === 'approved' ? (r.passenger_feedback === 'Approved by Admin' ? 'Approved by Admin' : r.pass_name) : `Belum Appv oleh ${r.pass_name}`, r.dest_name
                ]);
                doc.autoTable({
                    head: [[{content:'Date',rowSpan:2},{content:'Time',colSpan:2},{content:'Km',colSpan:3},{content:'Litre',rowSpan:2},{content:'Amount',colSpan:4},{content:'Approved',rowSpan:2},{content:'Place',rowSpan:2}],['In','Out','In','Out','Total','Gasoline','Toll','Others','Lunch']],
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
                const editPassengerApprovalEl = document.getElementById('edit_passenger_approval');
                const editPassengerFeedbackEl = document.getElementById('edit_passenger_feedback');
                
                if (!editTripIdEl || !deleteTripIdEl || !editStartTimeEl || !editEndTimeEl || !editPassengerApprovalEl || !editPassengerFeedbackEl) {
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
                editPassengerApprovalEl.value = trip.passenger_approval || 'pending';
                editPassengerFeedbackEl.value = trip.passenger_feedback || '';
                
                const isApproved = trip.passenger_approval === 'approved';
                const isRejected = trip.passenger_approval === 'rejected';
                const approvalTextEl = document.getElementById('edit_passenger_approval_text');
                const adminApproveBtn = document.getElementById('adminApproveBtn');
                
                if (isApproved) {
                    const approver = trip.passenger_feedback === 'Approved by Admin' ? 'Admin' : 'Passenger';
                    approvalTextEl.innerText = `Approved (by ${approver})`;
                    approvalTextEl.style.color = '#15803d';
                    adminApproveBtn.style.display = 'none';
                } else if (isRejected) {
                    approvalTextEl.innerText = 'Rejected';
                    approvalTextEl.style.color = '#b91c1c';
                    adminApproveBtn.style.display = 'inline-block';
                } else {
                    approvalTextEl.innerText = 'Pending';
                    approvalTextEl.style.color = '#92400e';
                    adminApproveBtn.style.display = 'inline-block';
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

        function setAdminApproved() {
            document.getElementById('edit_passenger_approval').value = 'approved';
            document.getElementById('edit_passenger_feedback').value = 'Approved by Admin';
            
            const textEl = document.getElementById('edit_passenger_approval_text');
            textEl.innerText = 'Approved (by Admin)';
            textEl.style.color = '#15803d';
            
            document.getElementById('adminApproveBtn').style.display = 'none';
        }
        
        function closeEditTripModal() {
            document.getElementById('editTripModal').style.display = 'none';
        }
        
        function confirmDeleteTripAdmin() {
            const tripId = document.getElementById('delete_trip_id').value;
            const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
            const confirmMsg = lang === 'id' 
                ? `Yakin akan hapus TX-${tripId} secara permanen?\nSemua data biaya dan foto bukti akan dihapus.` 
                : `Are you sure you want to delete TX-${tripId} permanently?\nAll recorded expenses and photos will be deleted.`;
            if (confirm(confirmMsg)) {
                document.getElementById('deleteTripForm').submit();
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

        // Attach listeners for interactive auto-updating controls and initial generation
        document.addEventListener('DOMContentLoaded', () => {
            // Auto-trigger AJAX generation when main filters change
            document.getElementById('driver_id').addEventListener('change', () => {
                if (activeTab === 'detail') generateReport(); else generateAnnualReport();
            });
            document.getElementById('start_date').addEventListener('change', () => {
                if (activeTab === 'detail') generateReport();
            });
            document.getElementById('end_date').addEventListener('change', () => {
                if (activeTab === 'detail') generateReport();
            });
            document.getElementById('report_year').addEventListener('change', () => {
                if (activeTab === 'annual') generateAnnualReport();
            });

            // Client-side instant sort/filter/search updates
            document.getElementById('report-search').addEventListener('input', () => {
                currentPage = 1;
                renderReportTable();
            });
            document.getElementById('report-sort').addEventListener('change', () => {
                currentPage = 1;
                renderReportTable();
            });
            document.getElementById('report-filter-status').addEventListener('change', () => {
                currentPage = 1;
                renderReportTable();
            });

            // Automatically load initial report on page open
            generateReport();
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
    </script>
</body>
</html>
