<?php
$current_page = basename($_SERVER['PHP_SELF']);
$theme = $_SESSION['theme'] ?? 'light';
?>
<style>
/* CSS overrides for collapsed sidebar footer */
body.collapsed .lang-theme-footer {
    padding: 10px !important;
    text-align: center !important;
}
body.collapsed .lang-theme-footer span {
    display: none !important;
}
body.collapsed .lang-theme-footer .pref-title {
    font-size: 1.2rem !important;
    margin-bottom: 12px !important;
    text-align: center !important;
}
body.collapsed .lang-theme-footer .pref-row {
    flex-direction: column !important;
    align-items: center !important;
    gap: 12px !important;
    margin-bottom: 12px !important;
    display: flex !important;
}
body.collapsed .lang-theme-footer .pref-row a {
    font-size: 1.2rem !important;
}
body.collapsed .lang-theme-footer .pref-actions {
    margin-top: 10px !important;
    padding-top: 10px !important;
}
body.collapsed .lang-theme-footer .pref-actions a {
    text-align: center !important;
    margin-bottom: 15px !important;
    font-size: 1.3rem !important;
    display: block !important;
}

@media (max-width: 768px) {
    .sidebar .lang-theme-footer {
        padding: 10px !important;
        text-align: center !important;
    }
    .sidebar .lang-theme-footer span {
        display: none !important;
    }
    .sidebar .lang-theme-footer .pref-title {
        font-size: 1.2rem !important;
        margin-bottom: 12px !important;
        text-align: center !important;
    }
    .sidebar .lang-theme-footer .pref-row {
        flex-direction: column !important;
        align-items: center !important;
        gap: 12px !important;
        margin-bottom: 12px !important;
        display: flex !important;
    }
    .sidebar .lang-theme-footer .pref-row a {
        font-size: 1.2rem !important;
    }
    .sidebar .lang-theme-footer .pref-actions {
        margin-top: 10px !important;
        padding-top: 10px !important;
    }
    .sidebar .lang-theme-footer .pref-actions a {
        text-align: center !important;
        margin-bottom: 15px !important;
        font-size: 1.3rem !important;
        display: block !important;
    }
}
</style>

<div class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-brand">Transport Overview</div>
        <div class="toggle-btn" onclick="toggleSidebar()">☰</div>
    </div>
    <nav style="padding: 10px 0;">
        <a href="admin.php" class="nav-item <?= $current_page === 'admin.php' ? 'active' : '' ?>"><div class="nav-icon">📊</div><span>Dashboard</span></a>
        <a href="master_data.php" class="nav-item <?= $current_page === 'master_data.php' ? 'active' : '' ?>"><div class="nav-icon">📁</div><span><?php echo __('master_data'); ?></span></a>
        <a href="report.php" class="nav-item <?= $current_page === 'report.php' ? 'active' : '' ?>"><div class="nav-icon">📝</div><span><?php echo __('reports'); ?></span></a>
        <a href="attendance_report.php" class="nav-item <?= $current_page === 'attendance_report.php' ? 'active' : '' ?>"><div class="nav-icon">⏰</div><span><?php echo __('attendance'); ?></span></a>
    </nav>

    <div class="lang-theme-footer">
        <div class="pref-title" style="font-size: 0.7rem; color: #999; margin-bottom: 10px; font-weight: 700;">⚙️ <span>PREFERENCES</span></div>
        <div class="pref-row" style="display: flex; gap: 10px; margin-bottom: 15px;">
            <a href="?lang=en" style="text-decoration:none; color: <?php echo $_SESSION['lang']=='en'?'var(--pbi-blue)':'#666'; ?>; font-weight: 700;">🇬🇧 <span>EN</span></a>
            <a href="?lang=id" style="text-decoration:none; color: <?php echo $_SESSION['lang']=='id'?'var(--pbi-blue)':'#666'; ?>; font-weight: 700;">🇮🇩 <span>ID</span></a>
        </div>
        <div class="pref-row" style="display: flex; gap: 10px; margin-bottom: 15px;">
            <a href="?theme=light" style="text-decoration:none; font-size: 0.75rem; color: <?php echo $theme=='light'?'var(--pbi-blue)':'#666'; ?>; font-weight: 700;">☀️ <span>LIGHT</span></a>
            <a href="?theme=dark" style="text-decoration:none; font-size: 0.75rem; color: <?php echo $theme=='dark'?'var(--pbi-blue)':'#666'; ?>; font-weight: 700;">🌙 <span>DARK</span></a>
        </div>
        
        <div class="pref-actions" style="margin-top: 20px; border-top: 1px dashed var(--glass-border); padding-top: 15px;">
            <a href="docs.php" target="_blank" style="display:block; color: #8b5cf6; text-decoration: none; font-size: 0.75rem; font-weight: 700; margin-bottom: 10px;">📖 <span>Manual Penggunaan</span></a>
            <a href="admin_password.php" style="display:block; color: var(--pbi-blue); text-decoration: none; font-size: 0.75rem; font-weight: 700; margin-bottom: 10px;">🔑 <span>Ganti Password</span></a>
            <a href="backup_db.php" style="display:block; color: #15803d; text-decoration: none; font-size: 0.75rem; font-weight: 700; margin-bottom: 10px;">📦 <span>Backup Database</span></a>
            <?php if ($current_page === 'admin.php'): ?>
                <a href="reset_data.php" style="display:block; color: #b91c1c; text-decoration: none; font-size: 0.75rem; font-weight: 700; margin-bottom: 10px;">⚠️ <span>Factory Reset Data</span></a>
            <?php endif; ?>
            <a href="logout.php" style="display:block; color: var(--text-secondary); text-decoration: none; font-size: 0.85rem; font-weight: 700;">🚪 <span>Logout</span></a>
        </div>
    </div>
</div>
