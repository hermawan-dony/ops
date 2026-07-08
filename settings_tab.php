<style>
    .profile-section { text-align: center; margin-bottom: 40px; padding: 20px; background: rgba(255,255,255,0.05); border-radius: 20px; border: 1px solid var(--glass-border); }
    .avatar-wrapper { 
        cursor: pointer; position: relative; display: inline-block; 
        width: 110px; height: 110px; border-radius: 50%; 
        padding: 5px; background: linear-gradient(45deg, var(--accent-color), #60a5fa);
        box-shadow: 0 10px 20px rgba(0,0,0,0.1);
    }
    .profile-avatar-circle { 
        width: 100%; height: 100%; border-radius: 50%; 
        object-fit: cover; display: flex; align-items: center; justify-content: center; 
        background: #fff; color: var(--accent-color); font-weight: 700; font-size: 2.8rem;
        border: 3px solid #fff; box-sizing: border-box;
    }
    .camera-badge { 
        position: absolute; bottom: 5px; right: 5px; 
        background: #fff; border-radius: 50%; padding: 8px; 
        box-shadow: 0 4px 10px rgba(0,0,0,0.15); font-size: 0.9rem;
        display: flex; align-items: center; justify-content: center;
        border: 1px solid #eee;
    }
    .settings-group { background: var(--card-bg); border-radius: 20px; border: 1px solid var(--glass-border); overflow: hidden; margin-bottom: 24px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
    .settings-link { 
        display: flex; align-items: center; justify-content: space-between; 
        padding: 18px 20px; border-bottom: 1px solid var(--glass-border); 
        cursor: pointer; transition: all 0.2s;
    }
    .settings-link:last-child { border-bottom: none; }
    .settings-link:active { background: rgba(0,0,0,0.02); }
    .settings-info { display: flex; align-items: center; gap: 15px; }
    .settings-icon-box { 
        width: 40px; height: 40px; border-radius: 12px; 
        background: rgba(17, 141, 255, 0.1); color: var(--accent-color); 
        display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
    }
    .settings-text-primary { font-size: 0.95rem; font-weight: 600; color: var(--text-primary); }
    .settings-text-secondary { font-size: 0.8rem; color: var(--text-secondary); margin-top: 2px; }
    .chevron-right { opacity: 0.3; font-size: 1.2rem; }
</style>

<script>
window.toggleForm = function(id) {
    const f = document.getElementById(id);
    if (!f) return;
    if (f.classList.contains('show') || f.style.display === 'block') {
        f.classList.remove('show');
        f.style.display = 'none';
    } else {
        f.classList.add('show');
        f.style.display = 'block';
        
        // auto scroll to form
        setTimeout(() => {
            f.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 100);
    }
};
</script>


<div class="profile-section">
    <label for="profile_upload" class="avatar-wrapper">
        <?php if ($driver_data['profile_photo']): ?>
            <img src="uploads/<?= htmlspecialchars($driver_data['profile_photo']) ?>" class="profile-avatar-circle" alt="Profile">
        <?php else: ?>
            <div class="profile-avatar-circle">
                <?= strtoupper(substr($driver_data['full_name'], 0, 1)) ?>
            </div>
        <?php endif; ?>
        <div class="camera-badge">📷</div>
    </label>
    <form action="update_profile.php" method="POST" enctype="multipart/form-data" id="profile_form" style="display:none;">
        <input type="file" name="profile_photo" id="profile_upload" onchange="document.getElementById('profile_form').submit()">
    </form>
    <h3 style="margin: 16px 0 4px 0; font-size: 1.4rem; color: var(--text-primary);"><?= htmlspecialchars($driver_data['full_name']) ?></h3>
    <div style="font-size: 0.85rem; color: var(--accent-color); font-weight: 700; background: rgba(17, 141, 255, 0.1); display: inline-block; padding: 4px 12px; border-radius: 50px;">
        @<?= htmlspecialchars($driver_data['username']) ?>
    </div>
</div>

<div class="settings-group">
    <!-- Language -->
    <div class="settings-link" onclick="window.location.href='?lang=<?= $_SESSION['lang'] == 'id' ? 'en' : 'id' ?>'">
        <div class="settings-info">
            <div class="settings-icon-box">🌐</div>
            <div>
                <div class="settings-text-primary"><?= __('language_setting') ?></div>
                <div class="settings-text-secondary"><?= $_SESSION['lang'] == 'id' ? 'Bahasa Indonesia' : 'English' ?></div>
            </div>
        </div>
        <div class="chevron-right">&rsaquo;</div>
    </div>

    <!-- User Manual -->
    <div class="settings-link" onclick="window.location.href='docs.php'">
        <div class="settings-info">
            <div class="settings-icon-box" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6;">📖</div>
            <div>
                <div class="settings-text-primary"><?= $_SESSION['lang'] == 'id' ? 'Buku Panduan' : 'User Manual' ?></div>
                <div class="settings-text-secondary"><?= $_SESSION['lang'] == 'id' ? 'Baca panduan & unduh PDF' : 'Read guide & download PDF' ?></div>
            </div>
        </div>
        <div class="chevron-right">&rsaquo;</div>
    </div>

    <!-- Theme -->
    <div class="settings-link" onclick="window.location.href='?theme=<?= $_SESSION['theme'] == 'dark' ? 'light' : 'dark' ?>'">
        <div class="settings-info">
            <div class="settings-icon-box" style="background: rgba(255, 185, 0, 0.1); color: #ffb900;">🌓</div>
            <div>
                <div class="settings-text-primary"><?= __('theme_setting') ?></div>
                <div class="settings-text-secondary"><?= ucfirst($_SESSION['theme']) ?> Mode</div>
            </div>
        </div>
        <div class="chevron-right">&rsaquo;</div>
    </div>

    <!-- WhatsApp Number -->
    <div class="settings-link" onclick="openSettingsPopup('wa')">
        <div class="settings-info">
            <div class="settings-icon-box" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">💬</div>
            <div>
                <div class="settings-text-primary"><?= __('whatsapp_setting') ?></div>
                <div class="settings-text-secondary"><?= htmlspecialchars($driver_data['wa_no'] ?? __('not_set')) ?></div>
            </div>
        </div>
        <div class="chevron-right">&rsaquo;</div>
    </div>

    <!-- Password -->
    <div class="settings-link" onclick="openSettingsPopup('password')">
        <div class="settings-info">
            <div class="settings-icon-box" style="background: rgba(16, 124, 16, 0.1); color: #107c10;">🔑</div>
            <div>
                <div class="settings-text-primary"><?= __('change_password_setting') ?></div>
                <div class="settings-text-secondary"><?= __('update_credentials_subtext') ?></div>
            </div>
        </div>
        <div class="chevron-right">&rsaquo;</div>
    </div>

    <!-- Supervisor (Atasan) -->
    <div class="settings-link" onclick="openSettingsPopup('supervisor')">
        <div class="settings-info">
            <div class="settings-icon-box" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6;">👥</div>

            <div>
                <div class="settings-text-primary"><?= $_SESSION['lang'] == 'id' ? 'Atasan (Supervisor)' : 'Supervisor' ?></div>
                <div class="settings-text-secondary">
                    <?php 
                    $current_spv = null;
                    if (!empty($driver_data['supervisor_id'])) {
                        foreach ($passengers as $p) {
                            if ($p['id'] == $driver_data['supervisor_id']) {
                                $current_spv = $p['name'];
                                break;
                            }
                        }
                    }
                    echo htmlspecialchars($current_spv ?? __('not_set'));
                    ?>
                </div>
            </div>
        </div>
        <div class="chevron-right">&rsaquo;</div>
    </div>

    <!-- GPS Bypass Toggle -->
    <div class="settings-link" onclick="toggleGpsBypass()">
        <div class="settings-info">
            <div class="settings-icon-box" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;">📍</div>
            <div>
                <div class="settings-text-primary"><?= $_SESSION['lang'] == 'id' ? 'HP Tidak Support GPS' : 'Device Without GPS' ?></div>
                <div class="settings-text-secondary" id="gps-bypass-status"><?= $_SESSION['lang'] == 'id' ? 'Nonaktif' : 'Disabled' ?></div>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <input type="checkbox" id="gps-bypass-checkbox" style="width: 22px; height: 22px; cursor: pointer; border-radius: 6px; accent-color: var(--accent-color);" onclick="event.stopPropagation(); handleGpsBypassChange(this.checked)">
        </div>
    </div>
</div>

<script>
function updateGpsBypassUI() {
    const isBypassed = localStorage.getItem('gps_bypass') === 'true';
    const checkbox = document.getElementById('gps-bypass-checkbox');
    const statusText = document.getElementById('gps-bypass-status');
    const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
    
    if (checkbox) checkbox.checked = isBypassed;
    if (statusText) {
        statusText.innerText = isBypassed 
            ? (lang === 'id' ? 'Aktif (Abaikan GPS)' : 'Enabled (Bypass GPS)')
            : (lang === 'id' ? 'Nonaktif (Wajib GPS)' : 'Disabled (Require GPS)');
    }
}

function handleGpsBypassChange(checked) {
    localStorage.setItem('gps_bypass', checked ? 'true' : 'false');
    updateGpsBypassUI();
}

function toggleGpsBypass() {
    const checkbox = document.getElementById('gps-bypass-checkbox');
    if (checkbox) {
        checkbox.checked = !checkbox.checked;
        handleGpsBypassChange(checkbox.checked);
    }
}

// Run on load
document.addEventListener('DOMContentLoaded', () => {
    updateGpsBypassUI();
});
</script>

<!-- Settings Popup Modal -->
<div id="settings-popup-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box; backdrop-filter: blur(4px);">
    <div style="background: var(--card-bg); border: 1px solid var(--glass-border); border-radius: 24px; padding: 24px; width: 100%; max-width: 420px; box-sizing: border-box; position: relative; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.3); transition: all 0.3s ease;">
        <button onclick="closeSettingsPopup()" style="position: absolute; top: 18px; right: 18px; background: rgba(0,0,0,0.05); border: none; font-size: 1.2rem; color: var(--text-secondary); cursor: pointer; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; line-height: 1;">&times;</button>
        <h3 id="settings-popup-title" style="margin-top: 0; margin-bottom: 20px; font-size: 1.2rem; color: var(--text-primary); font-weight: 700;">Edit Setting</h3>
        
        <!-- Form WA -->
        <div id="modal_wa_form" class="modal-form-content" style="display: none;">
            <form action="update_profile.php" method="POST">
                <input type="hidden" name="action" value="update_wa">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 8px;"><?= htmlspecialchars(__('whatsapp_setting')) ?></label>
                    <input type="text" name="wa_no" placeholder="<?= htmlspecialchars(__('wa_placeholder')) ?>" required 
                           pattern="628[0-9]{8,15}" title="<?= htmlspecialchars(__('wa_example_title')) ?>"
                           value="<?= htmlspecialchars($driver_data['wa_no'] ?? '') ?>"
                           style="width: 100%; padding: 12px; border-radius: 12px; border: 1px solid var(--glass-border); background: var(--bg-color); color: var(--text-primary); font-size: 0.95rem; box-sizing: border-box;">
                </div>
                <button type="submit" class="btn" style="width: 100%; border-radius: 12px; padding: 12px; font-weight: 700;"><?= htmlspecialchars(__('save_wa_no')) ?></button>
            </form>
        </div>

        <!-- Form Password -->
        <div id="modal_password_form" class="modal-form-content" style="display: none;">
            <form action="change_password.php" method="POST">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 8px;"><?= htmlspecialchars(__('change_password_setting')) ?></label>
                    <input type="password" name="new_password" placeholder="<?= htmlspecialchars(__('new_password_placeholder')) ?>" required 
                           style="width: 100%; padding: 12px; border-radius: 12px; border: 1px solid var(--glass-border); background: var(--bg-color); color: var(--text-primary); font-size: 0.95rem; box-sizing: border-box;">
                </div>
                <button type="submit" class="btn" style="width: 100%; border-radius: 12px; padding: 12px; font-weight: 700;"><?= htmlspecialchars(__('update_password_btn')) ?></button>
            </form>
        </div>

        <!-- Form Supervisor -->
        <div id="modal_supervisor_form" class="modal-form-content" style="display: none;">
            <form action="update_profile.php" method="POST">
                <input type="hidden" name="action" value="update_supervisor">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 8px;">
                        <?= $_SESSION['lang'] == 'id' ? 'Pilih Atasan (Supervisor)' : 'Choose Supervisor' ?>
                    </label>
                    <select name="supervisor_id" required style="width: 100%; padding: 12px; border-radius: 12px; border: 1px solid var(--glass-border); background: var(--bg-color); color: var(--text-primary); font-size: 0.95rem; box-sizing: border-box; -webkit-appearance: none; appearance: none;">
                        <option value=""><?= $_SESSION['lang'] == 'id' ? '-- Pilih Atasan --' : '-- Choose Supervisor --' ?></option>
                        <?php foreach ($passengers as $p): ?>
                            <?php if ($p['name'] === '?') continue; ?>
                            <option value="<?= $p['id'] ?>" <?= ($p['id'] == ($driver_data['supervisor_id'] ?? '')) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn" style="width: 100%; border-radius: 12px; padding: 12px; font-weight: 700;">
                    <?= $_SESSION['lang'] == 'id' ? 'Simpan Atasan' : 'Save Supervisor' ?>
                </button>
            </form>
        </div>
    </div>
</div>

<script>
window.openSettingsPopup = function(type) {
    const modal = document.getElementById('settings-popup-modal');
    const titleEl = document.getElementById('settings-popup-title');
    const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
    
    // Hide all forms first
    document.querySelectorAll('.modal-form-content').forEach(el => el.style.display = 'none');
    
    if (type === 'wa') {
        titleEl.textContent = lang === 'id' ? 'Ubah Nomor WhatsApp' : 'Change WhatsApp Number';
        document.getElementById('modal_wa_form').style.display = 'block';
    } else if (type === 'password') {
        titleEl.textContent = lang === 'id' ? 'Ubah Kata Sandi' : 'Change Password';
        document.getElementById('modal_password_form').style.display = 'block';
    } else if (type === 'supervisor') {
        titleEl.textContent = lang === 'id' ? 'Pilih Atasan (Supervisor)' : 'Choose Supervisor';
        document.getElementById('modal_supervisor_form').style.display = 'block';
    }
    
    modal.style.display = 'flex';
};

window.closeSettingsPopup = function() {
    const modal = document.getElementById('settings-popup-modal');
    if (modal) modal.style.display = 'none';
};

// Close modal when clicking outside of the content container
document.getElementById('settings-popup-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeSettingsPopup();
    }
});
</script>

<div class="settings-group" style="border-color: rgba(239, 68, 68, 0.1);">
    <div class="settings-link" onclick="if(confirm('<?= addslashes(__('confirm_logout')) ?>')) window.location.href='logout.php'">
        <div class="settings-info">
            <div class="settings-icon-box" style="background: rgba(239, 68, 68, 0.1); color: var(--danger-color);">🚪</div>
            <div class="settings-text-primary" style="color: var(--danger-color);"><?= __('logout_setting') ?></div>
        </div>
        <div class="chevron-right" style="color: var(--danger-color); opacity: 0.5;">&rsaquo;</div>
    </div>
</div>

<div style="text-align: center; margin-top: 40px; font-size: 0.75rem; color: var(--text-secondary); opacity: 0.6; font-weight: 600; letter-spacing: 0.5px;">
    VERSION 2.0 &bull; &copy; <?= date('Y') ?> FRAMAS INDONESIA
</div>

