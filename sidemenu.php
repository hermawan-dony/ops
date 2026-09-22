<?php
$current_page = basename($_SERVER['PHP_SELF']);
$theme = $_SESSION['theme'] ?? 'light';
$lang = $_SESSION['lang'] ?? 'en';

$pending_notes_count = 0;
if (isset($pdo)) {
    try {
        $pending_notes_count = $pdo->query("SELECT COUNT(*) FROM shifts WHERE note_status = 'pending_admin'")->fetchColumn() ?: 0;
    } catch (\Exception $e) {}
}
?>
<style>
:root {
    --pbi-blue: #118DFF;
    --pbi-bg: #F3F2F1;
    --pbi-dark: #333;
    --sidebar-w: 240px;
    --sidebar-collapsed: 70px;
    --card-shadow: 0 1.6px 3.6px 0 rgba(0,0,0,0.132), 0 0.3px 0.9px 0 rgba(0,0,0,0.108);
}
.dark-mode {
    --pbi-bg: #1e293b;
    --pbi-dark: #f8fafc;
}

.sidebar { 
    width: var(--sidebar-w); 
    background: var(--card-bg, #ffffff); 
    height: 100vh; 
    position: fixed; 
    left: 0;
    top: 0;
    border-right: 1px solid var(--glass-border, rgba(226, 232, 240, 0.8)); 
    transition: width 0.3s ease; 
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    z-index: 1001; 
    user-select: none;
}
body.collapsed .sidebar { width: var(--sidebar-collapsed); }

.sidebar-header { 
    padding: 18px 20px; 
    display: flex; 
    align-items: center; 
    justify-content: space-between; 
    border-bottom: 1px solid var(--glass-border, rgba(226, 232, 240, 0.8)); 
}
.sidebar-brand { font-weight: 700; color: var(--pbi-blue); white-space: nowrap; font-size: 1rem; }
body.collapsed .sidebar-brand { display: none; }
.toggle-btn { cursor: pointer; padding: 4px 8px; border: 1px solid var(--glass-border, rgba(226, 232, 240, 0.8)); background: var(--card-bg, #ffffff); border-radius: 6px; color: var(--text-primary, #0f172a); transition: background 0.2s; }
.toggle-btn:hover { background: rgba(0,0,0,0.04); }

.sidebar-body {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 10px 0;
}
/* Scrollbar styling for sidebar nav */
.sidebar-body::-webkit-scrollbar { width: 4px; }
.sidebar-body::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1); border-radius: 4px; }

.nav-item { 
    display: flex; 
    align-items: center; 
    padding: 10px 14px; 
    margin: 4px 14px; 
    border-radius: 8px; 
    text-decoration: none; 
    color: var(--text-secondary, #64748b); 
    font-size: 0.9rem; 
    font-weight: 500; 
    transition: all 0.2s ease; 
}
.nav-item:hover { background: rgba(17, 141, 255, 0.06); color: var(--pbi-blue); }
.nav-item.active { 
    background: linear-gradient(90deg, rgba(17,141,255,0.12) 0%, rgba(17,141,255,0.02) 100%); 
    color: var(--pbi-blue); 
    border-left: 4px solid var(--pbi-blue); 
    margin-left: 10px; 
    font-weight: 700; 
    box-shadow: 0 2px 5px rgba(0,0,0,0.02); 
}
.nav-icon { 
    min-width: 30px; 
    height: 30px; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    margin-right: 12px; 
    font-size: 1rem; 
    background: var(--card-bg, #ffffff); 
    border-radius: 8px; 
    box-shadow: 0 1px 3px rgba(0,0,0,0.05); 
    border: 1px solid var(--glass-border, rgba(226, 232, 240, 0.8)); 
    transition: all 0.2s; 
}
.nav-item.active .nav-icon { background: var(--pbi-blue); border: none; box-shadow: 0 4px 8px rgba(17,141,255,0.3); color: #ffffff; }

body.collapsed .nav-item { margin: 4px 8px; justify-content: center; padding: 10px; }
body.collapsed .nav-item.active { margin-left: 8px; border-left: none; }
body.collapsed .nav-icon { margin-right: 0; }
body.collapsed .nav-item span { display: none; }
body.collapsed .badge-pending-count { display: none !important; }

/* Preferences Footer Component */
.lang-theme-footer {
    padding: 14px 16px;
    border-top: 1px solid var(--glass-border, rgba(226, 232, 240, 0.8));
    background: var(--card-bg, #ffffff);
    flex-shrink: 0;
}
.pref-title {
    font-size: 0.65rem;
    font-weight: 800;
    color: var(--text-secondary, #94a3b8);
    letter-spacing: 0.8px;
    text-transform: uppercase;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.pref-segmented {
    display: flex;
    background: rgba(0, 0, 0, 0.04);
    padding: 3px;
    border-radius: 8px;
    border: 1px solid var(--glass-border, rgba(226, 232, 240, 0.8));
    margin-bottom: 8px;
}
.dark-mode .pref-segmented {
    background: rgba(255, 255, 255, 0.05);
}
.pref-pill {
    flex: 1;
    text-align: center;
    padding: 5px 8px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--text-secondary, #64748b);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    transition: all 0.2s ease;
}
.pref-pill.active {
    background: var(--card-bg, #ffffff);
    color: var(--pbi-blue, #118DFF);
    font-weight: 700;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.pref-actions-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px dashed var(--glass-border, rgba(226, 232, 240, 0.8));
}
.pref-action-item {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    font-size: 0.78rem;
    font-weight: 600;
    padding: 5px 8px;
    border-radius: 6px;
    transition: background 0.2s ease;
}
.pref-action-item:hover {
    background: rgba(0, 0, 0, 0.04);
}
.dark-mode .pref-action-item:hover {
    background: rgba(255, 255, 255, 0.05);
}

/* Collapsed Sidebar Styles for Footer */
body.collapsed .pref-title { display: none; }
body.collapsed .pref-segmented { flex-direction: column; padding: 2px; }
body.collapsed .pref-pill span { display: none; }
body.collapsed .pref-action-item span { display: none; }
body.collapsed .pref-action-item { justify-content: center; padding: 8px 0; }
body.collapsed .lang-theme-footer { padding: 10px 8px; }

@media (max-width: 768px) {
    .sidebar { width: var(--sidebar-collapsed); }
    .sidebar-brand { display: none; }
    .nav-item span { display: none; }
    .nav-item { margin: 4px 8px; justify-content: center; padding: 10px; }
    .nav-item.active { margin-left: 8px; border-left: none; }
    .nav-icon { margin-right: 0; }
    .pref-title { display: none; }
    .pref-segmented { flex-direction: column; padding: 2px; }
    .pref-pill span { display: none; }
    .pref-action-item span { display: none; }
    .pref-action-item { justify-content: center; padding: 8px 0; }
    .lang-theme-footer { padding: 10px 8px; }
}
</style>

<div class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-brand">Transport Overview</div>
        <div class="toggle-btn" onclick="toggleSidebar()">☰</div>
    </div>

    <div class="sidebar-body">
        <nav>
            <a href="admin.php" class="nav-item <?= $current_page === 'admin.php' ? 'active' : '' ?>"><div class="nav-icon">📊</div><span>Dashboard</span></a>
            <a href="master_data.php" class="nav-item <?= $current_page === 'master_data.php' ? 'active' : '' ?>"><div class="nav-icon">📁</div><span><?php echo __('master_data'); ?></span></a>
            <a href="report.php" class="nav-item <?= $current_page === 'report.php' ? 'active' : '' ?>"><div class="nav-icon">📝</div><span><?php echo __('verify_claims'); ?></span></a>
            <a href="cost_report.php" class="nav-item <?= $current_page === 'cost_report.php' ? 'active' : '' ?>"><div class="nav-icon">📊</div><span><?php echo __('cost_reports'); ?></span></a>
            <a href="attendance_report.php" class="nav-item <?= $current_page === 'attendance_report.php' ? 'active' : '' ?>"><div class="nav-icon">⏰</div><span><?php echo __('attendance'); ?></span></a>
            <a href="shift_messages.php" class="nav-item <?= $current_page === 'shift_messages.php' ? 'active' : '' ?>">
                <div class="nav-icon">💬</div>
                <span><?php echo __('messages'); ?></span>
                <?php if ($pending_notes_count > 0): ?>
                    <span class="badge-pending-count" style="background: #d97706; color: #fff; border-radius: 10px; padding: 2px 7px; font-size: 0.7rem; font-weight: 700; margin-left: auto;"><?php echo $pending_notes_count; ?></span>
                <?php endif; ?>
            </a>
        </nav>
    </div>

    <div class="lang-theme-footer">
        <div class="pref-title">⚙️ <span>PREFERENCES</span></div>
        
        <!-- Language Switcher Pills -->
        <div class="pref-segmented">
            <a href="?lang=en" class="pref-pill <?php echo $lang === 'en' ? 'active' : ''; ?>">
                🇬🇧 <span>EN</span>
            </a>
            <a href="?lang=id" class="pref-pill <?php echo $lang === 'id' ? 'active' : ''; ?>">
                🇮🇩 <span>ID</span>
            </a>
        </div>

        <!-- Theme Switcher Pills -->
        <div class="pref-segmented">
            <a href="?theme=light" class="pref-pill <?php echo $theme === 'light' ? 'active' : ''; ?>">
                ☀️ <span>LIGHT</span>
            </a>
            <a href="?theme=dark" class="pref-pill <?php echo $theme === 'dark' ? 'active' : ''; ?>">
                🌙 <span>DARK</span>
            </a>
        </div>
        
        <!-- Action Links -->
        <div class="pref-actions-list">
            <a href="docs.php" target="_blank" class="pref-action-item" style="color: #7c3aed;">
                <div style="min-width:20px; text-align:center;">📖</div>
                <span><?php echo __('manual_usage'); ?></span>
            </a>
            <a href="admin_password.php" class="pref-action-item" style="color: var(--pbi-blue, #118DFF);">
                <div style="min-width:20px; text-align:center;">🔑</div>
                <span><?php echo __('change_password_setting'); ?></span>
            </a>
            <a href="backup_db.php" class="pref-action-item" style="color: #16a34a;">
                <div style="min-width:20px; text-align:center;">📦</div>
                <span><?php echo __('backup_database'); ?></span>
            </a>
            <?php if ($current_page === 'admin.php'): ?>
                <a href="reset_data.php" class="pref-action-item" style="color: #dc2626;">
                    <div style="min-width:20px; text-align:center;">⚠️</div>
                    <span><?php echo __('factory_reset_data'); ?></span>
                </a>
            <?php endif; ?>
            <a href="logout.php" class="pref-action-item" style="color: #ef4444; font-weight: 700; margin-top: 2px; border-top: 1px solid var(--glass-border, rgba(226, 232, 240, 0.8)); padding-top: 6px;">
                <div style="min-width:20px; text-align:center;">🚪</div>
                <span><?php echo __('logout_setting'); ?></span>
            </a>
        </div>
    </div>
</div>
