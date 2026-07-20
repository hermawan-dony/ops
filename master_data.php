<?php
require_once 'config.php';

if (isset($_GET['spfx_token']) && $_GET['spfx_token'] === 'framas_admin_123') {
    $_SESSION['user_id'] = 999;
    $_SESSION['role'] = 'admin';
    $_SESSION['lang'] = 'en';
    $_SESSION['is_iframe'] = true;
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php'); exit;
}

// Handle Theme/Lang
if (isset($_GET['theme'])) { $_SESSION['theme'] = $_GET['theme']; header("Location: master_data.php"); exit; }
if (isset($_GET['lang'])) { $_SESSION['lang'] = $_GET['lang']; header("Location: master_data.php"); exit; }

// Data Fetching
$drivers = $pdo->query("SELECT u.*, c.car_no as pref_car, p.name as supervisor_name FROM users u LEFT JOIN master_cars c ON u.preferred_car_id = c.id LEFT JOIN master_passengers p ON u.supervisor_id = p.id ORDER BY u.role ASC, u.full_name ASC")->fetchAll();
$cars = $pdo->query("SELECT * FROM master_cars ORDER BY car_no ASC")->fetchAll();
$destinations = $pdo->query("SELECT * FROM master_destinations ORDER BY name ASC")->fetchAll();
$passengers = $pdo->query("SELECT * FROM master_passengers ORDER BY name ASC")->fetchAll();
$holidays = $pdo->query("SELECT * FROM master_holidays ORDER BY holiday_date DESC")->fetchAll();

$is_collapsed = isset($_SESSION['sidebar_collapsed']) && $_SESSION['sidebar_collapsed'];
$theme = $_SESSION['theme'] ?? 'light';
$is_iframe = isset($_GET['spfx_token']) || isset($_SESSION['is_iframe']);
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang']; ?>" class="<?php echo $theme === 'dark' ? 'dark-mode' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo __('master_data'); ?> - <?php echo __('app_name'); ?></title>
    <link rel="icon" type="image/png" href="icon.png">
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
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
            .tab-nav { flex-wrap: wrap; }
            .data-card { overflow-x: auto; }
        }

        .main-content { margin-left: var(--sidebar-w); flex: 1; padding: 16px; transition: margin-left 0.3s; }
        body.collapsed .main-content { margin-left: var(--sidebar-collapsed); }

        .tab-nav { display: flex; gap: 5px; margin-bottom: 16px; background: var(--card-bg); padding: 5px; border-radius: 8px; box-shadow: var(--card-shadow); width: fit-content; border: 1px solid var(--glass-border); }
        .tab-btn { padding: 10px 20px; border: none; background: transparent; cursor: pointer; border-radius: 6px; font-weight: 600; color: var(--text-secondary); font-size: 0.85rem; }
        .tab-btn.active { background: var(--pbi-blue); color: #fff; }

        .data-card { background: var(--card-bg); padding: 16px; border-radius: 8px; box-shadow: var(--card-shadow); border: 1px solid var(--glass-border); }
        .pbi-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
        .pbi-table th { text-align: left; padding: 8px; border-bottom: 2px solid var(--glass-border); color: var(--text-secondary); }
        .pbi-table td { padding: 8px; border-bottom: 1px solid var(--glass-border); color: var(--text-primary); }
        
        .btn-action { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.75rem; font-weight: 600; text-decoration: none; display: inline-block; }
        .btn-delete { background: #fff1f1; color: #d83b01; }
        .btn-edit { background: #f0f9ff; color: #118DFF; margin-right: 5px; }
        .btn-add { background: var(--pbi-blue); color: #fff; padding: 10px 20px; border-radius: 6px; border: none; cursor: pointer; font-weight: 700; box-shadow: 0 4px 12px rgba(17,141,255,0.2); }
        
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); }
        .modal-content { background: var(--card-bg); margin: 5% auto; padding: 32px; border-radius: 16px; width: 90%; max-width: 600px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); border: 1px solid var(--glass-border); box-sizing: border-box; }
        .pbi-input { width: 100%; padding: 12px; margin-bottom: 15px; border: 1px solid var(--glass-border); border-radius: 8px; background: var(--bg-color); color: var(--text-primary); box-sizing: border-box; }
        .pbi-label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 6px; }
        #formFields.grid-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; }
        #formFields.grid-2col .pbi-input { margin-bottom: 0; }
        #formFields.grid-2col .pbi-label { margin-bottom: 4px; }

        .sortable-header { cursor: pointer; user-select: none; position: relative; }
        .sortable-header:hover { background: rgba(17, 141, 255, 0.08) !important; }
        .sortable-header::after { content: ' ⇅'; opacity: 0.4; font-size: 0.7rem; margin-left: 4px; }
        .sortable-header.asc::after { content: ' ▲'; opacity: 0.9; color: var(--pbi-blue); }
        .sortable-header.desc::after { content: ' ▼'; opacity: 0.9; color: var(--pbi-blue); }
        .control-panel { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; gap: 10px; flex-wrap: wrap; }

        .lang-theme-footer { position: absolute; bottom: 0; width: 100%; padding: 20px; border-top: 1px solid var(--glass-border); background: var(--card-bg); }
        
        body.is-iframe .sidebar { display: none !important; }
        body.is-iframe .main-content { margin-left: 0 !important; width: 100% !important; padding: 10px; }
    </style>
</head>
<body class="<?php echo $is_collapsed ? 'collapsed' : ''; ?> <?php echo $is_iframe ? 'is-iframe' : ''; ?>">

    <?php include 'sidemenu.php'; ?>

    <div class="main-content">
        <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom: 16px;">
            <h2 style="margin:0; font-size: 1.5rem;"><?php echo __('master_data'); ?></h2>
            <button class="btn-add" id="masterAddBtn" onclick="openAddModal()">+ Add New Record</button>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div style="background-color: #fde8e8; border: 1px solid #f8b4b4; color: #9b1c1c; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 0.85rem; display: flex; justify-content: space-between; align-items: center; font-weight: 600;">
                <span>⚠️ <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></span>
                <span style="cursor:pointer; font-size: 1.2rem; font-weight: bold; line-height: 1;" onclick="this.parentElement.style.display='none'">&times;</span>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div style="background-color: #def7ec; border: 1px solid #bcf0da; color: #03543f; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 0.85rem; display: flex; justify-content: space-between; align-items: center; font-weight: 600;">
                <span>✅ <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></span>
                <span style="cursor:pointer; font-size: 1.2rem; font-weight: bold; line-height: 1;" onclick="this.parentElement.style.display='none'">&times;</span>
            </div>
        <?php endif; ?>

        <div class="tab-nav" id="masterTabNav">
            <button class="tab-btn active" data-type="driver" onclick="showTab('drivers', this)">Drivers</button>
            <button class="tab-btn" data-type="car" onclick="showTab('cars', this)">Vehicles</button>
            <button class="tab-btn" data-type="destination" onclick="showTab('destinations', this)">Destinations</button>
            <button class="tab-btn" data-type="passenger" onclick="showTab('passengers', this)">Passengers</button>
            <button class="tab-btn" data-type="holiday" onclick="showTab('holidays', this)">Holidays</button>
        </div>

        <!-- DRIVERS TAB -->
        <div id="tab-drivers" class="data-tab data-card">
            <div class="control-panel">
                <input type="text" placeholder="Search drivers..." oninput="searchTable('drivers', this.value)" class="pbi-input" style="margin-bottom:0; width: 250px; padding: 8px 12px; font-size: 0.85rem;">
                <button class="btn-action btn-delete" style="display:none; padding: 8px 12px;" id="bulk-delete-drivers" onclick="bulkDelete('driver', 'drivers')">Delete Selected</button>
            </div>
            <table class="pbi-table">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" onclick="toggleSelectAll('drivers', this)"></th>
                        <th class="sortable-header" onclick="sortTable('drivers', 1)">Name</th>
                        <th class="sortable-header" onclick="sortTable('drivers', 2)">Username</th>
                        <th class="sortable-header" onclick="sortTable('drivers', 3)">NIK</th>
                        <th class="sortable-header" onclick="sortTable('drivers', 4)">WA Number</th>
                        <th class="sortable-header" onclick="sortTable('drivers', 5)">Default Vehicle</th>
                        <th class="sortable-header" onclick="sortTable('drivers', 6)">Supervisor</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($drivers as $d): ?>
                    <tr>
                        <td><input type="checkbox" class="select-row-drivers" value="<?php echo $d['id']; ?>" onchange="updateActionButtons('drivers')"></td>
                        <td><strong><?php echo htmlspecialchars($d['full_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($d['username']); ?></td>
                        <td><?php echo htmlspecialchars($d['nik'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($d['wa_no'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($d['pref_car'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($d['supervisor_name'] ?? '-'); ?></td>
                        <td>
                            <button class="btn-action btn-edit" onclick="openEditModal('driver', <?php echo htmlspecialchars(json_encode($d)); ?>)">Edit</button>
                            <a href="manage_master.php?type=driver&action=delete&id=<?php echo $d['id']; ?>" class="btn-action btn-delete" onclick="return confirm('Apakah Anda yakin ingin menghapus driver: <?php echo htmlspecialchars($d['full_name'], ENT_QUOTES); ?>?')">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- VEHICLES TAB -->
        <div id="tab-cars" class="data-tab data-card" style="display:none;">
            <div class="control-panel">
                <input type="text" placeholder="Search vehicles..." oninput="searchTable('cars', this.value)" class="pbi-input" style="margin-bottom:0; width: 250px; padding: 8px 12px; font-size: 0.85rem;">
                <button class="btn-action btn-delete" style="display:none; padding: 8px 12px;" id="bulk-delete-cars" onclick="bulkDelete('car', 'cars')">Delete Selected</button>
            </div>
            <table class="pbi-table">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" onclick="toggleSelectAll('cars', this)"></th>
                        <th class="sortable-header" onclick="sortTable('cars', 1)">Car No</th>
                        <th class="sortable-header" onclick="sortTable('cars', 2)">Model</th>
                        <th class="sortable-header" onclick="sortTable('cars', 3)">Service KM</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($cars as $c): ?>
                    <tr>
                        <td><input type="checkbox" class="select-row-cars" value="<?php echo $c['id']; ?>" onchange="updateActionButtons('cars')"></td>
                        <td><strong><?php echo htmlspecialchars($c['car_no']); ?></strong></td>
                        <td><?php echo htmlspecialchars($c['model']); ?></td>
                        <td><?php echo number_format($c['last_service_km']); ?></td>
                        <td>
                            <button class="btn-action btn-edit" onclick="openEditModal('car', <?php echo htmlspecialchars(json_encode($c)); ?>)">Edit</button>
                            <a href="manage_master.php?type=car&action=delete&id=<?php echo $c['id']; ?>" class="btn-action btn-delete" onclick="return confirm('Apakah Anda yakin ingin menghapus kendaraan: <?php echo htmlspecialchars($c['car_no'], ENT_QUOTES); ?>?')">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- DESTINATIONS TAB -->
        <div id="tab-destinations" class="data-tab data-card" style="display:none;">
            <div class="control-panel">
                <input type="text" placeholder="Search destinations..." oninput="searchTable('destinations', this.value)" class="pbi-input" style="margin-bottom:0; width: 250px; padding: 8px 12px; font-size: 0.85rem;">
                <div>
                    <button class="btn-action btn-edit" style="display:none; padding: 8px 12px; margin-right: 5px;" id="combine-destinations" onclick="combineSelected('destination', 'destinations')">Combine Selected</button>
                    <button class="btn-action btn-delete" style="display:none; padding: 8px 12px;" id="bulk-delete-destinations" onclick="bulkDelete('destination', 'destinations')">Delete Selected</button>
                </div>
            </div>
            <table class="pbi-table">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" onclick="toggleSelectAll('destinations', this)"></th>
                        <th class="sortable-header" onclick="sortTable('destinations', 1)">Location Name</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($destinations as $dest): ?>
                    <tr>
                        <td><input type="checkbox" class="select-row-destinations" value="<?php echo $dest['id']; ?>" onchange="updateActionButtons('destinations')"></td>
                        <td><strong><?php echo htmlspecialchars($dest['name']); ?></strong></td>
                        <td>
                            <button class="btn-action btn-edit" onclick="openEditModal('destination', <?php echo htmlspecialchars(json_encode($dest)); ?>)">Edit</button>
                            <a href="manage_master.php?type=destination&action=delete&id=<?php echo $dest['id']; ?>" class="btn-action btn-delete" onclick="return confirm('Apakah Anda yakin ingin menghapus tujuan: <?php echo htmlspecialchars($dest['name'], ENT_QUOTES); ?>?')">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- PASSENGERS TAB -->
        <div id="tab-passengers" class="data-tab data-card" style="display:none;">
            <div class="control-panel">
                <input type="text" placeholder="Search passengers..." oninput="searchTable('passengers', this.value)" class="pbi-input" style="margin-bottom:0; width: 250px; padding: 8px 12px; font-size: 0.85rem;">
                <div>
                    <button class="btn-action btn-edit" style="display:none; padding: 8px 12px; margin-right: 5px;" id="combine-passengers" onclick="combineSelected('passenger', 'passengers')">Combine Selected</button>
                    <button class="btn-action btn-delete" style="display:none; padding: 8px 12px;" id="bulk-delete-passengers" onclick="bulkDelete('passenger', 'passengers')">Delete Selected</button>
                </div>
            </div>
            <table class="pbi-table">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" onclick="toggleSelectAll('passengers', this)"></th>
                        <th class="sortable-header" onclick="sortTable('passengers', 1)">Name</th>
                        <th class="sortable-header" onclick="sortTable('passengers', 2)">WA Number</th>
                        <th class="sortable-header" onclick="sortTable('passengers', 3)">Email</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($passengers as $p): ?>
                    <tr>
                        <td><input type="checkbox" class="select-row-passengers" value="<?php echo $p['id']; ?>" onchange="updateActionButtons('passengers')"></td>
                        <td><strong><?php echo htmlspecialchars($p['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($p['wa_no'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($p['email'] ?? '-'); ?></td>
                        <td>
                            <button class="btn-action btn-edit" onclick="openEditModal('passenger', <?php echo htmlspecialchars(json_encode($p)); ?>)">Edit</button>
                            <a href="manage_master.php?type=passenger&action=delete&id=<?php echo $p['id']; ?>" class="btn-action btn-delete" onclick="return confirm('Apakah Anda yakin ingin menghapus penumpang: <?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>?')">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- HOLIDAYS TAB -->
        <div id="tab-holidays" class="data-tab data-card" style="display:none;">
            <div class="control-panel">
                <input type="text" placeholder="Search holidays..." oninput="searchTable('holidays', this.value)" class="pbi-input" style="margin-bottom:0; width: 250px; padding: 8px 12px; font-size: 0.85rem;">
                <button class="btn-action btn-delete" style="display:none; padding: 8px 12px;" id="bulk-delete-holidays" onclick="bulkDelete('holiday', 'holidays')">Delete Selected</button>
            </div>
            <table class="pbi-table">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" onclick="toggleSelectAll('holidays', this)"></th>
                        <th class="sortable-header" onclick="sortTable('holidays', 1)">Date</th>
                        <th class="sortable-header" onclick="sortTable('holidays', 2)">Description</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($holidays as $h): ?>
                    <tr>
                        <td><input type="checkbox" class="select-row-holidays" value="<?php echo $h['id']; ?>" onchange="updateActionButtons('holidays')"></td>
                        <td><strong><?php echo date('d M Y', strtotime($h['holiday_date'])); ?></strong></td>
                        <td><?php echo htmlspecialchars($h['description']); ?></td>
                        <td>
                            <button class="btn-action btn-edit" onclick="openEditModal('holiday', <?php echo htmlspecialchars(json_encode($h)); ?>)">Edit</button>
                            <a href="manage_master.php?type=holiday&action=delete&id=<?php echo $h['id']; ?>" class="btn-action btn-delete" onclick="return confirm('Apakah Anda yakin ingin menghapus libur tanggal: <?php echo htmlspecialchars($h['holiday_date'], ENT_QUOTES); ?>?')">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL -->
    <div id="masterModal" class="modal">
        <div class="modal-content">
            <h3 id="modalTitle" style="margin-top:0; margin-bottom:24px;">Add New Record</h3>
            <form id="masterForm" action="manage_master.php" method="POST">
                <input type="hidden" name="type" id="formType">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="formId">
                
                <div id="formFields"></div>
                
                <div style="display: flex; gap: 10px; margin-top: 10px;">
                    <button type="button" class="btn-action btn-delete" onclick="closeModal()" style="flex:1; padding:12px;">Cancel</button>
                    <button type="submit" class="btn-add" style="flex:2; box-shadow:none;">Save Record</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentType = 'driver';

        function toggleSidebar() {
            document.body.classList.toggle('collapsed');
            fetch('manage_admin_action.php?action=toggle_sidebar');
        }

        function showTab(tab, btn) {
            document.querySelectorAll('.data-tab').forEach(t => t.style.display = 'none');
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById('tab-' + tab).style.display = 'block';
            btn.classList.add('active');
            currentType = btn.getAttribute('data-type');
            localStorage.setItem('master_active_tab', tab);
        }

        window.addEventListener('DOMContentLoaded', () => {
            const activeTab = localStorage.getItem('master_active_tab') || 'drivers';
            const btn = document.querySelector(`.tab-btn[onclick*="'${activeTab}'"]`);
            if (btn) {
                showTab(activeTab, btn);
            }
        });

        function searchTable(tab, query) {
            const rows = document.querySelectorAll(`#tab-${tab} tbody tr`);
            const q = query.toLowerCase().trim();
            rows.forEach(row => {
                let text = row.innerText.toLowerCase();
                if (text.includes(q)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function sortTable(tab, colIndex) {
            const table = document.querySelector(`#tab-${tab} table`);
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            const header = table.querySelectorAll('thead th')[colIndex];
            const isAsc = !header.classList.contains('asc');
            
            table.querySelectorAll('thead th').forEach(th => {
                th.classList.remove('asc', 'desc');
            });
            
            header.classList.add(isAsc ? 'asc' : 'desc');
            
            rows.sort((a, b) => {
                let valA = a.children[colIndex].innerText.trim();
                let valB = b.children[colIndex].innerText.trim();
                
                const numA = parseFloat(valA.replace(/,/g, ''));
                const numB = parseFloat(valB.replace(/,/g, ''));
                if (!isNaN(numA) && !isNaN(numB)) {
                    return isAsc ? numA - numB : numB - numA;
                }
                
                return isAsc ? valA.localeCompare(valB) : valB.localeCompare(valA);
            });
            
            rows.forEach(row => tbody.appendChild(row));
        }

        function toggleSelectAll(tab, masterCheckbox) {
            const checkboxes = document.querySelectorAll(`.select-row-${tab}`);
            checkboxes.forEach(cb => {
                const row = cb.closest('tr');
                if (row.style.display !== 'none') {
                    cb.checked = masterCheckbox.checked;
                }
            });
            updateActionButtons(tab);
        }

        function updateActionButtons(tab) {
            const checkedBoxes = document.querySelectorAll(`.select-row-${tab}:checked`);
            const deleteBtn = document.getElementById(`bulk-delete-${tab}`);
            const combineBtn = document.getElementById(`combine-${tab}`);
            
            if (checkedBoxes.length > 0) {
                if (deleteBtn) {
                    deleteBtn.style.display = 'inline-block';
                    deleteBtn.innerText = `Delete Selected (${checkedBoxes.length})`;
                }
                if (combineBtn && checkedBoxes.length > 1) {
                    combineBtn.style.display = 'inline-block';
                    combineBtn.innerText = `Combine Selected (${checkedBoxes.length})`;
                } else if (combineBtn) {
                    combineBtn.style.display = 'none';
                }
            } else {
                if (deleteBtn) deleteBtn.style.display = 'none';
                if (combineBtn) combineBtn.style.display = 'none';
            }
        }

        function combineSelected(type, tab) {
            const checkedBoxes = document.querySelectorAll(`.select-row-${tab}:checked`);
            if (checkedBoxes.length < 2) return;
            
            const targetName = prompt(`Gabungkan ${checkedBoxes.length} data terpilih.\n\nMasukkan Nama yang Seharusnya (nama tujuan/penumpang yang benar):`);
            if (!targetName || targetName.trim() === '') {
                alert('Tindakan dibatalkan. Nama tujuan/penumpang tidak boleh kosong.');
                return;
            }
            
            const ids = Array.from(checkedBoxes).map(cb => cb.value).join(',');
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'manage_master.php';
            
            const typeInput = document.createElement('input');
            typeInput.type = 'hidden';
            typeInput.name = 'type';
            typeInput.value = type;
            form.appendChild(typeInput);
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'combine';
            form.appendChild(actionInput);
            
            const targetNameInput = document.createElement('input');
            targetNameInput.type = 'hidden';
            targetNameInput.name = 'target_name';
            targetNameInput.value = targetName.trim();
            form.appendChild(targetNameInput);
            
            const idsInput = document.createElement('input');
            idsInput.type = 'hidden';
            idsInput.name = 'ids';
            idsInput.value = ids;
            form.appendChild(idsInput);
            
            document.body.appendChild(form);
            form.submit();
        }

        function bulkDelete(type, tab) {
            const checkedBoxes = document.querySelectorAll(`.select-row-${tab}:checked`);
            if (checkedBoxes.length === 0) return;
            
            const count = checkedBoxes.length;
            const confirmation = prompt(`Anda akan menghapus secara massal ${count} data terpilih secara permanen.\n\nKetik "OKE" (huruf kapital) untuk melanjutkan:`);
            if (confirmation !== 'OKE') {
                alert('Tindakan dibatalkan. Kode konfirmasi salah atau kosong.');
                return;
            }
            
            const ids = Array.from(checkedBoxes).map(cb => cb.value).join(',');
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'manage_master.php';
            
            const typeInput = document.createElement('input');
            typeInput.type = 'hidden';
            typeInput.name = 'type';
            typeInput.value = type;
            form.appendChild(typeInput);
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'bulk_delete';
            form.appendChild(actionInput);
            
            const idsInput = document.createElement('input');
            idsInput.type = 'hidden';
            idsInput.name = 'ids';
            idsInput.value = ids;
            form.appendChild(idsInput);
            
            document.body.appendChild(form);
            form.submit();
        }

        function closeModal() { document.getElementById('masterModal').style.display = 'none'; }

        function openAddModal() {
            document.getElementById('modalTitle').innerText = 'Add ' + currentType.toUpperCase();
            document.getElementById('formType').value = currentType;
            document.getElementById('formAction').value = 'add';
            document.getElementById('formId').value = '';
            renderFields(currentType);
            document.getElementById('masterModal').style.display = 'block';
        }

        function openEditModal(type, data) {
            document.getElementById('modalTitle').innerText = 'Edit ' + type.toUpperCase();
            document.getElementById('formType').value = type;
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formId').value = data.id;
            renderFields(type, data);
            document.getElementById('masterModal').style.display = 'block';
        }

        function renderFields(type, data = null) {
            let html = '';
            const formFields = document.getElementById('formFields');
            if (type === 'driver') {
                formFields.classList.add('grid-2col');
                html = `
                    <div>
                        <label class="pbi-label">Full Name</label>
                        <input type="text" name="full_name" class="pbi-input" required value="${data?data.full_name:''}">
                    </div>
                    <div>
                        <label class="pbi-label">Username</label>
                        <input type="text" name="username" class="pbi-input" required value="${data?data.username:''}">
                    </div>
                    <div>
                        <label class="pbi-label">Password ${data?'(Leave blank to keep)':''}</label>
                        <input type="password" name="password" class="pbi-input" ${data?'':'required'}>
                    </div>
                    <div>
                        <label class="pbi-label">NIK</label>
                        <input type="text" name="nik" class="pbi-input" maxlength="20" placeholder="Masukkan NIK" value="${data && data.nik ? data.nik : ''}">
                    </div>
                    <div>
                        <label class="pbi-label">WA Number (e.g. 628...)</label>
                        <input type="text" name="wa_no" class="pbi-input" placeholder="Masukkan Nomor WA" value="${data && data.wa_no ? data.wa_no : ''}">
                    </div>
                    <div>
                        <label class="pbi-label">Default Vehicle</label>
                        <select name="preferred_car_id" class="pbi-input">
                            <option value="">-- No Car --</option>
                            <?php foreach($cars as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo $c['car_no']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="pbi-label">Supervisor (Atasan)</label>
                        <select name="supervisor_id" class="pbi-input">
                            <option value="">-- No Supervisor --</option>
                            <?php foreach($passengers as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                `;
            } else {
                formFields.classList.remove('grid-2col');
                if (type === 'car') {
                    html = `
                        <label class="pbi-label">Car Number</label><input type="text" name="car_no" class="pbi-input" required value="${data?data.car_no:''}">
                        <label class="pbi-label">Model</label><input type="text" name="model" class="pbi-input" required value="${data?data.model:''}">
                        <label class="pbi-label">Last Service (KM)</label><input type="number" name="last_service_km" class="pbi-input" required value="${data?data.last_service_km:0}">
                    `;
                } else if (type === 'destination') {
                    html = `<label class="pbi-label">Location Name</label><input type="text" name="name" class="pbi-input" required value="${data?data.name:''}">`;
                } else if (type === 'passenger') {
                    html = `
                        <label class="pbi-label">Passenger Name</label><input type="text" name="name" class="pbi-input" required value="${data?data.name:''}">
                        <label class="pbi-label">WA Number (e.g. 628...)</label><input type="text" name="wa_no" class="pbi-input" required value="${data?data.wa_no:''}">
                        <label class="pbi-label">Email</label><input type="email" name="email" class="pbi-input" value="${data && data.email ? data.email : ''}">
                    `;
                } else if (type === 'holiday') {
                    html = `
                        <label class="pbi-label">Holiday Date</label><input type="date" name="holiday_date" class="pbi-input" required value="${data?data.holiday_date:''}">
                        <label class="pbi-label">Description</label><input type="text" name="description" class="pbi-input" required value="${data?data.description:''}">
                    `;
                }
            }
            formFields.innerHTML = html;
            
            // Set select values if editing
            if (data && type === 'driver') {
                if (data.preferred_car_id) {
                    document.querySelector('select[name="preferred_car_id"]').value = data.preferred_car_id;
                }
                if (data.supervisor_id) {
                    document.querySelector('select[name="supervisor_id"]').value = data.supervisor_id;
                }
            }
        }
    </script>
</body>
</html>
