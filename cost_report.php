<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$theme = $_SESSION['theme'] ?? 'light';
$is_collapsed = isset($_SESSION['sidebar_collapsed']) && $_SESSION['sidebar_collapsed'];

// Fetch drivers list
$stmt = $pdo->query("SELECT id, full_name FROM users WHERE role = 'driver' ORDER BY full_name ASC");
$drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch pending counts for badge display
$pending_trips_count = $pdo->query("SELECT COUNT(*) FROM trips WHERE passenger_approval = 'pending'")->fetchColumn() ?: 0;
$pending_shifts_count = $pdo->query("SELECT COUNT(*) FROM shifts WHERE approval_status = 'pending' AND status = 'completed'")->fetchColumn() ?: 0;
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang']; ?>" class="<?php echo $theme === 'dark' ? 'dark-mode' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo __('cost_reports'); ?> - framas Transport App</title>
    <link rel="icon" type="image/png" href="icon.png">
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- SheetJS (Excel) -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <!-- jsPDF & AutoTable -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>

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
        }

        .main-content { margin-left: var(--sidebar-w); flex: 1; padding: 16px; transition: margin-left 0.3s; }
        body.collapsed .main-content { margin-left: var(--sidebar-collapsed); }

        .report-filter-card { background: var(--card-bg); padding: 16px; border-radius: 8px; box-shadow: var(--card-shadow); border: 1px solid var(--glass-border); }
        .pbi-form-group { flex: 1; min-width: 200px; }
        .pbi-label { display: block; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 8px; }
        .pbi-input { width: 100%; padding: 8px; border: 1px solid var(--glass-border); border-radius: 4px; font-family: inherit; font-size: 0.9rem; background: var(--bg-color); color: var(--text-primary); box-sizing: border-box; }
        
        .btn-generate { background: var(--pbi-blue); color: #fff; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: 700; height: 38px; white-space: nowrap; }
        .btn-export { border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 0.85rem; color: #fff; }
        
        .pbi-table { width: 100%; border-collapse: collapse; font-size: 0.75rem; }
        .pbi-table th { text-align: center; padding: 6px; border: 1px solid var(--glass-border); background: rgba(0,0,0,0.02); color: var(--text-secondary); }
        .pbi-table td { padding: 6px; border: 1px solid var(--glass-border); color: var(--text-primary); }

        .btn-action { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.75rem; font-weight: 600; text-decoration: none; display: inline-block; }
        .btn-edit { background: #f0f9ff; color: #118DFF; margin-right: 5px; }

        .lang-theme-footer { position: absolute; bottom: 0; width: 100%; padding: 20px; border-top: 1px solid var(--glass-border); background: var(--card-bg); }

        .kpi-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .kpi-card {
            background: var(--card-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.06);
        }
        .kpi-title {
            font-size: 0.75rem;
            color: var(--text-secondary);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .kpi-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }
        .kpi-badge {
            align-self: flex-start;
            font-size: 0.65rem;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 4px;
            text-transform: uppercase;
        }
        .charts-row {
            display: flex;
            gap: 20px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .chart-box-left {
            flex: 1.8;
            min-width: 320px;
            background: var(--card-bg);
            border: 1px solid var(--glass-border);
            padding: 20px;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
        }
        .chart-box-right {
            flex: 1;
            min-width: 280px;
            background: var(--card-bg);
            border: 1px solid var(--glass-border);
            padding: 20px;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            display: flex;
            flex-direction: column;
        }
        .insights-section-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0 0 16px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .tables-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 24px;
        }
        @media (max-width: 900px) {
            .tables-grid {
                grid-template-columns: 1fr;
            }
        }
        .secondary-card {
            background: var(--card-bg);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 20px;
            box-shadow: var(--card-shadow);
        }
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(5px); overflow-y: auto; align-items: center; justify-content: center; }
        .modal-content { background: var(--card-bg); margin: auto; padding: 24px; border-radius: 12px; width: 500px; max-width: 90%; border: 1px solid var(--glass-border); }
    </style>
</head>
<body class="<?php echo $is_collapsed ? 'collapsed' : ''; ?>">

    <?php include 'sidemenu.php'; ?>

    <div class="main-content">
        <!-- HEADER -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
            <h2 style="margin: 0; font-size: 1.5rem; white-space: nowrap;"><?php echo __('cost_reports'); ?></h2>
            
            <div style="display: flex; gap: 12px; align-items: center;">
                <button onclick="exportAll()" class="btn-export" style="background: #107c10; font-weight: 600; padding: 8px 16px;">Export Full Analysis (Excel)</button>
            </div>
        </div>

        <!-- FILTER BAR -->
        <div class="report-filter-card" style="margin-bottom: 24px;">
            <form id="reportForm" style="display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap;">
                <!-- Select Year -->
                <div class="pbi-form-group">
                    <label class="pbi-label">Select Year</label>
                    <select id="report_year" class="pbi-input">
                        <?php 
                        $current_year = date('Y');
                        for ($y = $current_year; $y >= $current_year - 5; $y--): ?>
                            <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <!-- Select Driver -->
                <div class="pbi-form-group">
                    <label class="pbi-label">Select Driver</label>
                    <select id="driver_id" class="pbi-input">
                        <option value="ALL">[ ALL DRIVERS ]</option>
                        <?php foreach($drivers as $d): ?>
                            <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Expense Type -->
                <div class="pbi-form-group">
                    <label class="pbi-label"><?php echo $_SESSION['lang'] == 'id' ? 'Biaya' : 'Expense Type'; ?></label>
                    <select id="annual_expense_type" class="pbi-input" onchange="renderCharts()">
                        <option value="ALL"><?php echo $_SESSION['lang'] == 'id' ? '[ Semua Biaya (Spline) ]' : '[ All Expenses (Spline) ]'; ?></option>
                        <option value="gasoline">Gasoline (BBM)</option>
                        <option value="toll">Toll</option>
                        <option value="parking">Parking</option>
                        <option value="lunch">Lunch (Uang Makan)</option>
                        <option value="others">Others (Lain-lain)</option>
                        <option value="total_amount">Total Amount</option>
                    </select>
                </div>

                <!-- Generate button -->
                <button type="button" onclick="loadDashboardData()" class="btn-generate" style="height: 38px; padding: 0 20px; font-weight: bold;">Generate Report</button>
            </form>
        </div>

        <!-- KPI SUMMARY CARDS -->
        <div class="kpi-container">
            <!-- 1. Total Annual Spending -->
            <div class="kpi-card">
                <span class="kpi-title"><?php echo __('annual_spending'); ?></span>
                <span class="kpi-value" id="kpi-total-spend">Rp 0</span>
                <span class="kpi-badge" style="background: rgba(17,141,255,0.1); color: #118DFF;">Budget Spent</span>
            </div>
            <!-- 2. Avg Monthly Cost -->
            <div class="kpi-card">
                <span class="kpi-title"><?php echo __('avg_monthly_spend'); ?></span>
                <span class="kpi-value" id="kpi-avg-spend">Rp 0</span>
                <span class="kpi-badge" style="background: rgba(16,185,129,0.1); color: #10b981;">Monthly Mean</span>
            </div>
            <!-- 3. Peak Month -->
            <div class="kpi-card">
                <span class="kpi-title"><?php echo __('peak_month'); ?></span>
                <span class="kpi-value" id="kpi-peak-month">-</span>
                <span class="kpi-badge" style="background: rgba(225,29,72,0.1); color: #e11d48;" id="kpi-peak-val">Rp 0</span>
            </div>
            <!-- 4. Top Category -->
            <div class="kpi-card">
                <span class="kpi-title"><?php echo __('top_category'); ?></span>
                <span class="kpi-value" id="kpi-top-category">-</span>
                <span class="kpi-badge" style="background: rgba(245,158,11,0.1); color: #f59e0b;" id="kpi-top-cat-val">0% Share</span>
            </div>
        </div>

        <!-- CHARTS SECTION -->
        <div class="charts-row">
            <!-- Spline Trend Chart -->
            <div class="chart-box-left">
                <div class="insights-section-title">
                    <span>📈 Monthly Cost Trend (Spline)</span>
                </div>
                <div style="height: 280px; position: relative;">
                    <canvas id="splineChartCanvas"></canvas>
                </div>
            </div>

            <!-- Doughnut Category Chart -->
            <div class="chart-box-right">
                <div class="insights-section-title">
                    <span>🍩 Cost Category Breakdown</span>
                </div>
                <div style="height: 220px; position: relative; margin: auto;">
                    <canvas id="categoryDoughnutCanvas"></canvas>
                </div>
            </div>
        </div>

        <!-- ANNUAL SUMMARY TABLE -->
        <div class="secondary-card" style="margin-bottom: 24px;">
            <div class="insights-section-title">
                <span>📅 Monthly Expense Breakdowns</span>
                <div style="display: flex; gap: 8px;">
                    <button onclick="exportAnnualToPDF()" class="btn-export" style="background: #e11d48; font-size: 0.75rem; padding: 6px 12px;">Export Summary PDF</button>
                    <button onclick="exportAnnualToExcel()" class="btn-export" style="background: #107c10; font-size: 0.75rem; padding: 6px 12px;">Excel</button>
                </div>
            </div>
            <div style="overflow-x: auto;">
                <table class="pbi-table" id="annualReportTable">
                    <thead>
                        <tr>
                            <th>Month</th>
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

        <!-- TABLES GRID (Driver Rank & Vehicle Efficiency) -->
        <div class="tables-grid">
            <!-- Left: Driver Rank -->
            <div class="secondary-card">
                <div class="insights-section-title">
                    <span>👥 <?php echo __('driver_share'); ?></span>
                </div>
                <div style="overflow-x: auto; max-height: 350px;">
                    <table class="pbi-table">
                        <thead>
                            <tr>
                                <th>Driver Name</th>
                                <th style="text-align: right;">Total Amount</th>
                                <th style="text-align: right;">Budget Contribution</th>
                            </tr>
                        </thead>
                        <tbody id="driverShareContent">
                            <tr><td colspan="3" align="center" style="color:var(--text-secondary);">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right: Vehicle Cost Summary -->
            <div class="secondary-card">
                <div class="insights-section-title">
                    <span>🚗 <?php echo __('vehicle_efficiency'); ?></span>
                </div>
                <div style="overflow-x: auto; max-height: 350px;">
                    <table class="pbi-table">
                        <thead>
                            <tr>
                                <th>Car Plate No</th>
                                <th>Model</th>
                                <th style="text-align: right;">Fuel (Gasoline)</th>
                                <th style="text-align: right;">Toll</th>
                                <th style="text-align: right;">Total Spent</th>
                            </tr>
                        </thead>
                        <tbody id="vehicleEfficiencyContent">
                            <tr><td colspan="5" align="center" style="color:var(--text-secondary);">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <!-- Monthly Read-Only Details Modal -->
    <div id="monthlyDetailsModal" class="modal" style="z-index: 2050;">
        <div class="modal-content" style="width: 1000px; max-width: 95%; margin: 5% auto;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h4 id="monthlyDetailsTitle" style="margin:0; font-size: 1.25rem; font-weight: bold; color: var(--text-primary);">Monthly Expense Details</h4>
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

    <!-- Image Viewer Modal (Full Size) -->
    <div id="imageViewerModal" class="modal" style="z-index: 2100;">
        <div class="modal-content" style="width: auto; max-width: 95%; margin: 2% auto; padding: 12px; background: rgba(0,0,0,0.9); border: none; text-align: center; position: relative;">
            <button onclick="closeImageViewer()" style="position: absolute; right: 15px; top: 15px; background: rgba(255,255,255,0.2); border: none; border-radius: 50%; color: white; cursor: pointer; font-size: 1.5rem; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; z-index: 10;">×</button>
            <img id="fullImageView" src="" style="max-height: 85vh; max-width: 100%; border-radius: 4px; object-fit: contain; margin-top: 40px;">
        </div>
    </div>

    <script>
        let splineChart = null;
        let doughnutChart = null;
        let annualData = [];
        let driverShareData = [];
        let vehicleEfficiencyData = [];

        async function loadDashboardData() {
            try {
                const formData = new FormData();
                const driverId = document.getElementById('driver_id').value;
                const year = document.getElementById('report_year').value;
                formData.append('driver_id', driverId);
                formData.append('year', year);

                // Fetch annual summary
                const res = await fetch('api_get_annual_report.php?action=summary', { method: 'POST', body: formData });
                if (!res.ok) throw new Error("HTTP error " + res.status);
                annualData = await res.json();

                // Fetch driver share
                const resDriver = await fetch('api_get_annual_report.php?action=driver_share', { method: 'POST', body: formData });
                if (!resDriver.ok) throw new Error("HTTP error " + resDriver.status);
                driverShareData = await resDriver.json();

                // Fetch vehicle efficiency
                const resVehicle = await fetch('api_get_annual_report.php?action=vehicle_efficiency', { method: 'POST', body: formData });
                if (!resVehicle.ok) throw new Error("HTTP error " + resVehicle.status);
                vehicleEfficiencyData = await resVehicle.json();

                updateKPICards();
                renderCharts();
                renderSummaryTable();
                renderDriverShareTable();
                renderVehicleEfficiencyTable();
            } catch (err) {
                console.error("Dashboard Load Error: ", err);
                alert("Failed to load cost report data. Please make sure that 'api_get_annual_report.php' is fully uploaded to your cPanel hosting root.\n\nError details: " + err.message);
            }
        }

        function updateKPICards() {
            let totalSpend = 0;
            let activeMonths = 0;
            let peakMonthIndex = -1;
            let peakMonthAmount = 0;

            let totalGas = 0, totalToll = 0, totalParking = 0, totalLunch = 0, totalOthers = 0;
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

            annualData.forEach(r => {
                totalSpend += r.total_amount;
                if (r.total_amount > 0) {
                    activeMonths++;
                }
                if (r.total_amount > peakMonthAmount) {
                    peakMonthAmount = r.total_amount;
                    peakMonthIndex = r.month_num - 1;
                }
                totalGas += r.gasoline;
                totalToll += r.toll;
                totalParking += r.parking;
                totalLunch += r.lunch;
                totalOthers += r.others;
            });

            const avgMonthly = activeMonths > 0 ? (totalSpend / activeMonths) : 0;

            // Find top category
            const categories = [
                { name: 'Gasoline', value: totalGas },
                { name: 'Toll', value: totalToll },
                { name: 'Parking', value: totalParking },
                { name: 'Lunch', value: totalLunch },
                { name: 'Others', value: totalOthers }
            ];
            categories.sort((a,b) => b.value - a.value);
            const topCategory = categories[0];
            const topCatPercent = totalSpend > 0 ? ((topCategory.value / totalSpend) * 100).toFixed(1) : 0;

            // Set DOM values
            document.getElementById('kpi-total-spend').innerText = 'Rp ' + totalSpend.toLocaleString();
            document.getElementById('kpi-avg-spend').innerText = 'Rp ' + Math.round(avgMonthly).toLocaleString();
            document.getElementById('kpi-peak-month').innerText = peakMonthIndex >= 0 ? monthNames[peakMonthIndex].substring(0,3) + ' ' + document.getElementById('report_year').value : '-';
            document.getElementById('kpi-peak-val').innerText = 'Rp ' + peakMonthAmount.toLocaleString();
            document.getElementById('kpi-top-category').innerText = topCategory.value > 0 ? topCategory.name : '-';
            document.getElementById('kpi-top-cat-val').innerText = topCategory.value > 0 ? topCatPercent + '% Share' : '0% Share';
        }

        function renderCharts() {
            const splineCtx = document.getElementById('splineChartCanvas').getContext('2d');
            const doughnutCtx = document.getElementById('categoryDoughnutCanvas').getContext('2d');

            if (splineChart) splineChart.destroy();
            if (doughnutChart) doughnutChart.destroy();

            const monthLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const expenseType = document.getElementById('annual_expense_type').value;

            if (expenseType === 'ALL') {
                const gasolineData = annualData.map(r => r.gasoline);
                const tollData = annualData.map(r => r.toll);
                const parkingData = annualData.map(r => r.parking);
                const lunchData = annualData.map(r => r.lunch);
                const othersData = annualData.map(r => r.others);

                splineChart = new Chart(splineCtx, {
                    type: 'line',
                    data: {
                        labels: monthLabels,
                        datasets: [
                            { label: 'Gasoline', data: gasolineData, borderColor: '#3b82f6', backgroundColor: '#3b82f615', tension: 0.4, borderWidth: 3, pointRadius: 4, fill: false },
                            { label: 'Toll', data: tollData, borderColor: '#10b981', backgroundColor: '#10b98115', tension: 0.4, borderWidth: 3, pointRadius: 4, fill: false },
                            { label: 'Parking', data: parkingData, borderColor: '#f59e0b', backgroundColor: '#f59e0b15', tension: 0.4, borderWidth: 3, pointRadius: 4, fill: false },
                            { label: 'Lunch', data: lunchData, borderColor: '#ec4899', backgroundColor: '#ec489915', tension: 0.4, borderWidth: 3, pointRadius: 4, fill: false },
                            { label: 'Others', data: othersData, borderColor: '#6b7280', backgroundColor: '#6b728015', tension: 0.4, borderWidth: 3, pointRadius: 4, fill: false }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { grid: { display: false } },
                            y: { grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { callback: (val) => 'Rp ' + val.toLocaleString() } }
                        },
                        plugins: {
                            tooltip: { callbacks: { label: (context) => `${context.dataset.label}: Rp ${context.raw.toLocaleString()}` } }
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

                splineChart = new Chart(splineCtx, {
                    type: 'line',
                    data: {
                        labels: monthLabels,
                        datasets: [{
                            label: datasetLabel,
                            data: data,
                            borderColor: datasetColor,
                            backgroundColor: datasetColor + '15',
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
                            tooltip: { callbacks: { label: (context) => `${context.dataset.label}: Rp ${context.raw.toLocaleString()}` } }
                        },
                        scales: {
                            x: { grid: { display: false } },
                            y: { grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { callback: (val) => 'Rp ' + val.toLocaleString() } }
                        }
                    }
                });
            }

            // Sum totals for doughnut chart
            let totalGas = 0, totalToll = 0, totalParking = 0, totalLunch = 0, totalOthers = 0;
            annualData.forEach(r => {
                totalGas += r.gasoline;
                totalToll += r.toll;
                totalParking += r.parking;
                totalLunch += r.lunch;
                totalOthers += r.others;
            });

            doughnutChart = new Chart(doughnutCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Gasoline', 'Toll', 'Parking', 'Lunch', 'Others'],
                    datasets: [{
                        data: [totalGas, totalToll, totalParking, totalLunch, totalOthers],
                        backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ec4899', '#6b7280'],
                        borderWidth: 2,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } },
                        tooltip: { callbacks: { label: (context) => `${context.label}: Rp ${context.raw.toLocaleString()}` } }
                    }
                }
            });
        }

        function renderSummaryTable() {
            const tbody = document.getElementById('annualReportContent');
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            let totalGas = 0, totalToll = 0, totalParking = 0, totalLunch = 0, totalOthers = 0, grandTotal = 0;

            tbody.innerHTML = annualData.map(r => {
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
        }

        function renderDriverShareTable() {
            const tbody = document.getElementById('driverShareContent');
            if (driverShareData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="3" align="center" style="color:var(--text-secondary);">No driver cost data.</td></tr>';
                return;
            }
            
            const grandTotal = driverShareData.reduce((sum, d) => sum + parseFloat(d.total_amount), 0);
            tbody.innerHTML = driverShareData.map(d => {
                const percent = grandTotal > 0 ? ((parseFloat(d.total_amount) / grandTotal) * 100).toFixed(1) : 0;
                return `
                    <tr>
                        <td><strong>${d.driver_name}</strong></td>
                        <td align="right">Rp ${parseInt(d.total_amount).toLocaleString()}</td>
                        <td align="right"><span class="badge" style="background: rgba(17,141,255,0.1); color:#118DFF; font-weight:bold;">${percent}%</span></td>
                    </tr>
                `;
            }).join('');
        }

        function renderVehicleEfficiencyTable() {
            const tbody = document.getElementById('vehicleEfficiencyContent');
            if (vehicleEfficiencyData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" align="center" style="color:var(--text-secondary);">No vehicle cost data.</td></tr>';
                return;
            }

            tbody.innerHTML = vehicleEfficiencyData.map(v => {
                return `
                    <tr>
                        <td><strong>${v.car_no}</strong></td>
                        <td>${v.car_model || '-'}</td>
                        <td align="right">Rp ${parseInt(v.gasoline).toLocaleString()}</td>
                        <td align="right">Rp ${parseInt(v.toll).toLocaleString()}</td>
                        <td align="right"><strong>Rp ${parseInt(v.total_amount).toLocaleString()}</strong></td>
                    </tr>
                `;
            }).join('');
        }

        async function showMonthlyDetails(monthNum) {
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
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
                    const photoHtml = d.photo ? `<div style="position:relative; cursor:pointer; display:inline-block;" onclick="openImageViewer('uploads/${d.photo}')"><img src="uploads/thumb_${d.photo}" onerror="this.src='uploads/${d.photo}'" style="width:50px; height:50px; object-fit:cover; border-radius:4px;"><div style="position: absolute; bottom: 2px; right: 2px; background: rgba(0,0,0,0.5); color: white; padding: 1px 2px; font-size: 0.55rem; border-radius: 2px;">🔍</div></div>` : '-';
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

        function openImageViewer(src) {
            document.getElementById('fullImageView').src = src;
            document.getElementById('imageViewerModal').style.display = 'block';
        }

        function closeImageViewer() {
            document.getElementById('imageViewerModal').style.display = 'none';
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
            
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
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
            ws['!merges'] = [{s:{r:0,c:0}, e:{r:0,c:6}}];
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
            
            const splineCanvas = document.getElementById('splineChartCanvas');
            let startY = 110;
            if (splineCanvas) {
                try {
                    const chartImage = splineCanvas.toDataURL('image/png');
                    doc.addImage(chartImage, 'PNG', 40, 100, 515, 200);
                    startY = 320;
                } catch (e) {
                    console.error("Failed to add chart to PDF: ", e);
                }
            }
            
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
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

        function exportAll() {
            // Exports all 3 summary tables to separate sheets in a single Excel file
            const wb = XLSX.utils.book_new();
            const year = document.getElementById('report_year').value;
            const driverId = document.getElementById('driver_id').value;
            let driverName = 'All Drivers';
            if (driverId !== 'ALL') {
                const driverSelect = document.getElementById('driver_id');
                driverName = driverSelect.options[driverSelect.selectedIndex].text;
            }

            // Sheet 1: Monthly Cost Breakdowns
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            let summaryRows = [
                ["ANNUAL COST SUMMARY REPORT"],
                ["Year:", year, "Driver:", driverName],
                [],
                ["Month", "Gasoline (BBM)", "Toll", "Parking", "Lunch (Uang Makan)", "Others (Lain-lain)", "Total"]
            ];
            let totalGas = 0, totalToll = 0, totalParking = 0, totalLunch = 0, totalOthers = 0, grandTotal = 0;
            annualData.forEach(r => {
                totalGas += r.gasoline; totalToll += r.toll; totalParking += r.parking; totalLunch += r.lunch; totalOthers += r.others; grandTotal += r.total_amount;
                summaryRows.push([monthNames[r.month_num - 1], r.gasoline, r.toll, r.parking, r.lunch, r.others, r.total_amount]);
            });
            summaryRows.push(["TOTAL", totalGas, totalToll, totalParking, totalLunch, totalOthers, grandTotal]);
            const wsSummary = XLSX.utils.aoa_to_sheet(summaryRows);
            XLSX.utils.book_append_sheet(wb, wsSummary, "Monthly Breakdown");

            // Sheet 2: Driver Share Ranking
            let driverRows = [
                ["DRIVER COST CONTRIBUTION RANKING"],
                ["Year:", year],
                [],
                ["Driver Name", "Total Expenses (Rp)"]
            ];
            driverShareData.forEach(d => {
                driverRows.push([d.driver_name, parseFloat(d.total_amount)]);
            });
            const wsDriver = XLSX.utils.aoa_to_sheet(driverRows);
            XLSX.utils.book_append_sheet(wb, wsDriver, "Driver Ranking");

            // Sheet 3: Vehicle Efficiency
            let vehicleRows = [
                ["VEHICLE OPERATING EXPENSES SUMMARY"],
                ["Year:", year],
                [],
                ["Car Plate No", "Model", "Fuel / Gasoline (Rp)", "Toll (Rp)", "Total Spend (Rp)"]
            ];
            vehicleEfficiencyData.forEach(v => {
                vehicleRows.push([v.car_no, v.car_model || '-', parseFloat(v.gasoline), parseFloat(v.toll), parseFloat(v.total_amount)]);
            });
            const wsVehicle = XLSX.utils.aoa_to_sheet(vehicleRows);
            XLSX.utils.book_append_sheet(wb, wsVehicle, "Vehicle Efficiency");

            XLSX.writeFile(wb, `Financial_Cost_Analysis_${year}_${new Date().toISOString().split('T')[0]}.xlsx`);
        }

        // Initialize dashboard
        document.addEventListener('DOMContentLoaded', () => {
            loadDashboardData();
        });
    </script>
</body>
</html>
