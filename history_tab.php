<div style="background: var(--card-bg); padding: 12px; border-radius: 12px; border: 1px solid var(--glass-border); margin-bottom: 20px; font-size: 0.8rem;">
    <!-- Date range search (via PHP reload) -->
    <form action="index.php" method="GET" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 12px;">
        <input type="hidden" name="tab" value="history">
        <input type="date" name="hist_start" value="<?= $hist_start ?>" style="flex: 1; min-width: 110px; padding: 6px 8px; font-size: 0.8rem; border-radius: 6px; background: var(--card-bg); color: var(--text-primary); border: 1px solid var(--glass-border);">
        <span style="color: var(--text-secondary); font-size: 0.8rem;">&rarr;</span>
        <input type="date" name="hist_end" value="<?= $hist_end ?>" style="flex: 1; min-width: 110px; padding: 6px 8px; font-size: 0.8rem; border-radius: 6px; background: var(--card-bg); color: var(--text-primary); border: 1px solid var(--glass-border);">
        <button type="submit" class="btn" style="padding: 6px 12px; border-radius: 6px; margin: 0; font-size: 0.8rem; height: 32px;">🔍</button>
    </form>

    <!-- Client-side Interactive Search, Filter, Sort -->
    <div style="display: flex; gap: 8px; flex-wrap: wrap; border-top: 1px dashed var(--glass-border); padding-top: 12px; align-items: center;">
        <input type="text" id="history-search" placeholder="<?= htmlspecialchars(__('search_placeholder')) ?>" style="flex: 1.5; min-width: 160px; padding: 6px 8px; font-size: 0.8rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary); height: 32px;">
        
        <select id="history-sort" style="flex: 1; min-width: 110px; padding: 6px 8px; font-size: 0.8rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary); height: 32px;">
            <option value="newest"><?= htmlspecialchars(__('newest_first')) ?></option>
            <option value="oldest"><?= htmlspecialchars(__('oldest_first')) ?></option>
            <option value="cost_desc"><?= htmlspecialchars(__('cost_high_low')) ?></option>
            <option value="cost_asc"><?= htmlspecialchars(__('cost_low_high')) ?></option>
            <option value="dest_asc"><?= htmlspecialchars(__('dest_a_z')) ?></option>
        </select>
        
        <select id="history-filter-user" style="flex: 1; min-width: 90px; padding: 6px 8px; font-size: 0.8rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary); height: 32px;">
            <option value="all"><?= htmlspecialchars(__('all_status')) ?></option>
            <option value="approved"><?= htmlspecialchars(__('approved')) ?></option>
            <option value="pending"><?= htmlspecialchars(__('pending')) ?></option>
            <option value="rejected"><?= htmlspecialchars(__('rejected')) ?></option>
        </select>

        <!-- Segmented Control View Toggle -->
        <div style="display: flex; gap: 2px; background: rgba(0, 0, 0, 0.05); padding: 2px; border-radius: 6px; border: 1px solid var(--glass-border); height: 32px; align-items: center; box-sizing: border-box;">
            <button type="button" id="btn-view-card" style="border: none; background: none; color: var(--text-secondary); padding: 4px 10px; font-size: 0.85rem; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; height: 100%; transition: all 0.2s; box-sizing: border-box;" title="<?= $_SESSION['lang'] === 'id' ? 'Tampilan Kartu' : 'Card View' ?>">📇</button>
            <button type="button" id="btn-view-table" style="border: none; background: none; color: var(--text-secondary); padding: 4px 10px; font-size: 0.85rem; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; height: 100%; transition: all 0.2s; box-sizing: border-box;" title="<?= $_SESSION['lang'] === 'id' ? 'Tampilan Tabel' : 'Table View' ?>">📊</button>
        </div>
    </div>
</div>

<h3 style="text-align: left; margin-bottom: 15px; font-size: 1.1rem;"><?= __('history') ?></h3>

<div id="history-trips-container" style="display: flex; flex-direction: column; gap: 16px; padding-bottom: 80px;">
    <!-- Rendered dynamically via JS -->
</div>

<!-- Custom Pop-up Modal Container -->
<div id="history-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box; backdrop-filter: blur(4px);">
    <div style="background: var(--card-bg); border: 1px solid var(--glass-border); border-radius: 16px; padding: 20px; width: 100%; max-width: 450px; box-sizing: border-box; position: relative; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.3);">
        <button onclick="closeHistoryModal()" style="position: absolute; top: 12px; right: 12px; background: none; border: none; font-size: 1.5rem; color: var(--text-secondary); cursor: pointer; line-height: 1;">&times;</button>
        <h3 id="history-modal-title" style="margin-top: 0; margin-bottom: 15px; font-size: 1.1rem; color: var(--text-primary); font-weight: 700;">Title</h3>
        <div id="history-modal-body">
            <!-- Dynamic form content loaded here -->
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const rawHistoryData = <?= json_encode($history_trips) ?>;
    const destinations = <?= json_encode($destinations) ?>;
    const passengers = <?= json_encode($passengers) ?>;
    const cars = <?= json_encode($cars) ?>;
    const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
    const mandatoryPhoto = "<?= $mandatory_photo ?>";
    
    const searchInput = document.getElementById('history-search');
    const sortSelect = document.getElementById('history-sort');
    const userFilter = document.getElementById('history-filter-user');
    const container = document.getElementById('history-trips-container');

    const btnViewCard = document.getElementById('btn-view-card');
    const btnViewTable = document.getElementById('btn-view-table');
    let currentViewMode = localStorage.getItem('history_view_mode') || 'card';

    function updateViewModeButtons() {
        if (currentViewMode === 'card') {
            btnViewCard.style.background = 'var(--accent-color)';
            btnViewCard.style.color = '#ffffff';
            btnViewCard.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
            
            btnViewTable.style.background = 'none';
            btnViewTable.style.color = 'var(--text-secondary)';
            btnViewTable.style.boxShadow = 'none';
        } else {
            btnViewTable.style.background = 'var(--accent-color)';
            btnViewTable.style.color = '#ffffff';
            btnViewTable.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
            
            btnViewCard.style.background = 'none';
            btnViewCard.style.color = 'var(--text-secondary)';
            btnViewCard.style.boxShadow = 'none';
        }
    }

    btnViewCard.addEventListener('click', () => {
        currentViewMode = 'card';
        localStorage.setItem('history_view_mode', 'card');
        updateViewModeButtons();
        renderTrips();
    });

    btnViewTable.addEventListener('click', () => {
        currentViewMode = 'table';
        localStorage.setItem('history_view_mode', 'table');
        updateViewModeButtons();
        renderTrips();
    });

    updateViewModeButtons();

    // Export modal functions to global window scope
    window.closeHistoryModal = function() {
        document.getElementById('history-modal').style.display = 'none';
        document.getElementById('history-modal-body').innerHTML = '';
    };

    window.openEditTripModal = function(tripId) {
        const t = rawHistoryData.find(item => item.id == tripId);
        if (!t) return;
        
        document.getElementById('history-modal-title').textContent = lang === 'id' ? `Edit Perjalanan TX-${t.id}` : `Edit Trip TX-${t.id}`;
        
        const body = document.getElementById('history-modal-body');
        body.innerHTML = `
            <form action="manage_trip.php" method="POST" enctype="multipart/form-data" onsubmit="if(validateEditTrip(this)){ submitFormAjaxModal(event); } else { event.preventDefault(); }">
                <input type="hidden" name="action" value="edit_trip">
                <input type="hidden" name="trip_id" value="${t.id}">
                
                <div class="form-group searchable-select" style="margin-bottom: 12px; position: relative;">
                    <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">${lang === 'id' ? 'Tujuan' : 'Destination'}</label>
                    <input type="text" id="modal_dest_search" class="dest-search-input" value="${t.dest_name || ''}" placeholder="${lang === 'id' ? 'Cari/Tambah Tujuan...' : 'Search/Add Destination...'}" autocomplete="off" style="width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                    <input type="hidden" name="destination_id" id="modal_dest_id_hidden" value="${t.destination_id || ''}">
                    <input type="text" name="new_destination" id="modal_new_dest_input" placeholder="${lang === 'id' ? 'Nama Tujuan Baru' : 'New Destination Name'}" style="display:none; margin-top: 10px; width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                    <div id="modal_dest_results" class="search-results"></div>
                </div>

                <div class="form-group searchable-select" style="margin-bottom: 12px; position: relative;">
                    <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">${lang === 'id' ? 'Penumpang' : 'Passenger'}</label>
                    <input type="text" id="modal_pass_search" class="pass-search-input" value="${t.pass_name || ''}" placeholder="${lang === 'id' ? 'Cari/Tambah Penumpang...' : 'Search/Add Passenger...'}" autocomplete="off" style="width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                    <input type="hidden" name="passenger_id" id="modal_pass_id_hidden" value="${t.passenger_id || ''}">
                    <input type="text" name="new_passenger" id="modal_new_pass_input" placeholder="${lang === 'id' ? 'Nama Penumpang Baru' : 'New Passenger Name'}" style="display:none; margin-top: 10px; width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                    <div id="modal_pass_results" class="search-results"></div>
                </div>

                <div class="form-group" style="margin-bottom: 12px;">
                    <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">${lang === 'id' ? 'Mobil' : 'Car'}</label>
                    <select name="car_id" style="width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                        ${cars.map(c => `<option value="${c.id}" ${c.id == t.car_id ? 'selected' : ''}>${c.car_no}</option>`).join('')}
                    </select>
                </div>

                <div class="form-grid-2" style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px;">
                    <div class="form-group">
                        <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">KM Start</label>
                        <input type="number" name="km_start" value="${t.km_start}" required style="width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                    </div>
                    <div class="form-group">
                        <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">KM End</label>
                        <input type="number" name="km_end" value="${t.km_end || ''}" style="width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                    </div>
                </div>

                ${mandatoryPhoto === '1' ? `
                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">${lang === 'id' ? 'Foto KM Start (Opsional)' : 'KM Start Photo (Optional)'}</label>
                    <input type="file" name="km_start_photo" accept="image/*" style="font-size: 0.8rem; color: var(--text-primary);">
                </div>
                ` : ''}

                <button type="submit" class="btn" style="background: var(--success-color); color: white; width: 100%; padding: 10px; font-size: 0.85rem; border-radius: 6px; border: none; font-weight: 600;">${lang === 'id' ? 'Simpan Perubahan' : 'Save Changes'}</button>
            </form>
        `;
        
        // Show modal
        document.getElementById('history-modal').style.display = 'flex';
        
        // Initialize Autocomplete
        initSearchable('modal_dest_search', 'modal_dest_id_hidden', 'modal_dest_results', destinations, true);
        initSearchable('modal_pass_search', 'modal_pass_id_hidden', 'modal_pass_results', passengers, false);
        
        // Prevent auto-focus and hide dropdowns
        setTimeout(() => {
            if(document.activeElement) document.activeElement.blur();
            document.getElementById('modal_dest_results').style.display = 'none';
            document.getElementById('modal_pass_results').style.display = 'none';
        }, 10);
    };

    window.openAddExpenseModal = function(tripId) {
        const t = rawHistoryData.find(item => item.id == tripId);
        if (!t) return;
        
        document.getElementById('history-modal-title').textContent = lang === 'id' ? `Tambah Biaya TX-${t.id}` : `Add Expense TX-${t.id}`;
        
        const litreDivId = `modal_litre_div_${t.id}`;
        
        const body = document.getElementById('history-modal-body');
        body.innerHTML = `
            <form action="manage_trip.php" method="POST" enctype="multipart/form-data" onsubmit="submitFormAjaxModal(event)">
                <input type="hidden" name="action" value="add_expense">
                <input type="hidden" name="trip_id" value="${t.id}">
                <div class="form-group" style="margin-bottom: 12px;">
                    <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">${lang === 'id' ? 'Jenis' : 'Type'}</label>
                    <select name="expense_type" onchange="this.value=='gasoline'?document.getElementById('${litreDivId}').style.display='block':document.getElementById('${litreDivId}').style.display='none'" style="width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                        <option value="gasoline">${lang === 'id' ? 'BBM' : 'Fuel'}</option>
                        <option value="toll">${lang === 'id' ? 'Tol' : 'Toll'}</option>
                        <option value="parking">${lang === 'id' ? 'Parkir' : 'Parking'}</option>
                        <option value="lunch">${lang === 'id' ? 'Uang Makan' : 'Allowance'}</option>
                        <option value="others">${lang === 'id' ? 'Lain-lain' : 'Others'}</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 12px;">
                    <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">${lang === 'id' ? 'Jumlah' : 'Amount'}</label>
                    <input type="text" name="amount" class="formatted-amount-input" oninput="formatAmountInput(this)" required style="width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                </div>
                <div class="form-group" id="${litreDivId}" style="margin-bottom: 12px;">
                    <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Litre</label>
                    <input type="number" step="0.01" name="litre" placeholder="10.5" style="width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                </div>
                ${mandatoryPhoto === '1' ? `
                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">${lang === 'id' ? 'Foto Bukti' : 'Photo Proof'}</label>
                    <input type="file" name="photo" accept="image/*" required style="font-size: 0.8rem; color: var(--text-primary);">
                </div>
                ` : ''}
                <button type="submit" class="btn" style="width: 100%; padding: 10px; font-size: 0.85rem; border-radius: 6px; background: #d97706; color: white; border: none; font-weight: 600;">${lang === 'id' ? 'Tambah Biaya' : 'Add Expense'}</button>
            </form>
        `;
        
        // Show modal
        document.getElementById('history-modal').style.display = 'flex';
    };

    // AJAX form submission wrapper that also closes the modal on success
    window.submitFormAjaxModal = async function(event) {
        event.preventDefault();
        const form = event.target;
        await submitFormElementAjax(form);
        // On success, closeHistoryModal will be implicit since page will reload, 
        // but close it immediately for visual feedback
        closeHistoryModal();
    };

    // Close on outside click
    window.addEventListener('click', (e) => {
        const modal = document.getElementById('history-modal');
        if (e.target === modal) {
            closeHistoryModal();
        }
    });

    // Close on Escape key press
    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeHistoryModal();
        }
    });

    function formatIDR(amount) {
        return 'Rp ' + Number(amount).toLocaleString('id-ID');
    }

    function formatDate(dateStr) {
        const d = new Date(dateStr);
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return `${String(d.getDate()).padStart(2, '0')} ${months[d.getMonth()]} ${d.getFullYear()}`;
    }

    function formatTime(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr);
        return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
    }

    function getTripTotalCost(trip) {
        return (trip.expenses || []).reduce((sum, exp) => sum + parseFloat(exp.amount), 0);
    }

    function renderTrips() {
        const query = searchInput.value.toLowerCase().trim();
        const sortVal = sortSelect.value;
        const filterVal = userFilter.value;

        // 1. Filter
        let filtered = rawHistoryData.filter(t => {
            const destName = (t.dest_name || '').toLowerCase();
            const passName = (t.pass_name || '').toLowerCase();
            const carNo = (t.car_no || '').toLowerCase();
            const txId = `tx-${t.id}`;
            const searchMatch = destName.includes(query) || passName.includes(query) || carNo.includes(query) || txId.includes(query);
            const filterMatch = (filterVal === 'all') || (t.passenger_approval === filterVal);
            return searchMatch && filterMatch;
        });

        // 2. Sort
        filtered.sort((a, b) => {
            if (sortVal === 'newest') {
                return new Date(b.start_time) - new Date(a.start_time);
            } else if (sortVal === 'oldest') {
                return new Date(a.start_time) - new Date(b.start_time);
            } else if (sortVal === 'cost_desc') {
                return getTripTotalCost(b) - getTripTotalCost(a);
            } else if (sortVal === 'cost_asc') {
                return getTripTotalCost(a) - getTripTotalCost(b);
            } else if (sortVal === 'dest_asc') {
                return (a.dest_name || '').localeCompare(b.dest_name || '');
            }
            return 0;
        });

        // 3. Render HTML
        if (filtered.length === 0) {
            container.innerHTML = `
                <div style="text-align: center; color: var(--text-secondary); padding: 40px; background: var(--card-bg); border-radius: 12px; border: 1px dashed var(--glass-border);">
                    <p>Belum ada riwayat perjalanan yang cocok.</p>
                </div>`;
            return;
        }

        if (currentViewMode === 'card') {
            container.style.display = 'flex';
            container.innerHTML = filtered.map(t => {
                const distance = (t.km_end && t.km_start) ? t.km_end - t.km_start : 0;
                const totalCost = getTripTotalCost(t);

                const passBg = t.passenger_approval === 'approved' ? '#dcfce7' : (t.passenger_approval === 'rejected' ? '#fee2e2' : '#f1f5f9');
                const passColor = t.passenger_approval === 'approved' ? '#166534' : (t.passenger_approval === 'rejected' ? '#991b1b' : '#64748b');
                
                let passText = '';
                if (t.passenger_approval === 'approved') {
                    passText = lang === 'id' ? 'DISETUJUI' : 'APPROVED';
                } else if (t.passenger_approval === 'rejected') {
                    passText = lang === 'id' ? 'DITOLAK' : 'REJECTED';
                } else {
                    passText = lang === 'id' ? 'MENUNGGU' : 'PENDING';
                }

                // EXPENSES BREAKDOWN
                let expensesHtml = '';
                if (t.expenses && t.expenses.length > 0) {
                    expensesHtml = `
                        <div style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25); padding: 10px 12px; border-radius: 12px; margin-top: 10px; box-shadow: inset 0 0 8px rgba(245, 158, 11, 0.03);">
                            <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #d97706; display: block; margin-bottom: 8px; letter-spacing: 0.02em;">${lang === 'id' ? 'Rincian Biaya:' : 'Cost Breakdown:'}</span>
                            ${t.expenses.map(exp => {
                                const expThumb = exp.photo ? `uploads/${exp.photo}` : '';
                                const thumbImg = expThumb ? `<img src="${expThumb}" style="width: 32px; height: 32px; object-fit: cover; border-radius: 6px; cursor: pointer;" onclick="openImageViewer('${expThumb}')">` : '';
                                const expenseTranslations = {
                                    'id': { 'gasoline': 'BBM', 'toll': 'Tol', 'parking': 'Parkir', 'lunch': 'Uang Makan', 'others': 'Lain-lain' },
                                    'en': { 'gasoline': 'Fuel', 'toll': 'Toll', 'parking': 'Parking', 'lunch': 'Allowance', 'others': 'Miscellaneous' }
                                };
                                const expName = (expenseTranslations[lang] && expenseTranslations[lang][exp.expense_type]) ? expenseTranslations[lang][exp.expense_type] : exp.expense_type;
                                return `
                                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; margin-bottom: 8px;">
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            ${thumbImg}
                                            <span style="color: var(--text-primary); font-weight: 500;">${expName}</span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span style="font-weight: 700; color: var(--text-primary);">${formatIDR(exp.amount)}</span>
                                            <form action="manage_trip.php" id="history_delete_expense_form_${exp.id}" method="POST" style="display: inline-flex;">
                                                <input type="hidden" name="action" value="delete_expense">
                                                <input type="hidden" name="expense_id" value="${exp.id}">
                                                <input type="hidden" name="trip_id" value="${t.id}">
                                                <button type="button" onclick="confirmDeleteExpenseHistory(${exp.id})" style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 0.85rem; padding: 0;" title="Hapus Biaya">🗑</button>
                                            </form>
                                        </div>
                                    </div>
                                `;
                            }).join('')}
                            <div style="display: flex; justify-content: space-between; font-size: 0.9rem; margin-top: 10px; border-top: 1px solid var(--glass-border); padding-top: 10px; font-weight: 800; color: var(--accent-color);">
                                <span>${lang === 'id' ? 'TOTAL BIAYA' : 'TOTAL COST'}</span>
                                <span>${formatIDR(totalCost)}</span>
                            </div>
                        </div>
                    `;
                }

                // KM PHOTOS
                let startPhotoHtml = '';
                if (t.km_start_photo) {
                    startPhotoHtml = `<img src="uploads/${t.km_start_photo}" style="width: 50px; height: 50px; object-fit: cover; border-radius: 6px; cursor: pointer; border: 1px solid var(--glass-border);" onclick="openImageViewer('uploads/${t.km_start_photo}')">`;
                }
                let endPhotoHtml = '';
                if (t.km_end_photo) {
                    endPhotoHtml = `<img src="uploads/${t.km_end_photo}" style="width: 50px; height: 50px; object-fit: cover; border-radius: 6px; cursor: pointer; border: 1px solid var(--glass-border);" onclick="openImageViewer('uploads/${t.km_end_photo}')">`;
                }

                const formId = `history_expense_form_${t.id}`;
                const litreDivId = `history_litre_div_${t.id}`;

                return `
                    <div class="report-card" style="padding: 12px; background: var(--card-bg); border: 1px solid var(--glass-border); border-radius: 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                            <div>
                                <span style="font-size: 0.7rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase;">
                                    ${formatDate(t.start_time)} | <strong style="font-weight: 800; color: var(--text-primary);">TX-${t.id}</strong>
                                </span>
                                <strong style="font-size: 1.1rem; display: block; margin-top: 2px; color: var(--text-primary);">${t.dest_name}</strong>
                                <span style="font-size: 0.8rem; color: var(--text-secondary);">
                                    ${formatTime(t.start_time)} - ${formatTime(t.end_time)}
                                </span>
                            </div>
                            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 4px;">
                                <div style="background: var(--accent-color); color: white; padding: 4px 10px; border-radius: 8px; font-size: 0.75rem; font-weight: 700;">
                                    ${distance} KM
                                </div>
                                <div style="display: flex; gap: 4px;">
                                    <span class="badge" style="font-size: 0.65rem; padding: 3px 6px; background: ${passBg}; color: ${passColor}; font-weight: 700; letter-spacing: 0.02em;">
                                        ${passText}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 8px; display: flex; flex-direction: column; gap: 4px; background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.1); padding: 8px 10px; border-radius: 8px;">
                            <div style="display:flex; align-items:center; gap:8px;"><span>👤</span> ${t.pass_name}</div>
                            <div style="display:flex; align-items:center; gap:8px;"><span>🚗</span> ${t.car_no}</div>
                            <div style="display:flex; align-items:center; gap:8px;"><span>📍</span> KM: ${t.km_start} &rarr; ${t.km_end}</div>
                            <div style="display:flex; align-items:center; gap:6px; font-size: 0.8rem; white-space: nowrap; overflow-x: auto;">
                                <span>🗺️</span> Map: 
                                ${t.start_lat && t.start_lng ? `<a href="https://www.google.com/maps/search/?api=1&query=${t.start_lat},${t.start_lng}" target="_blank" style="color: var(--accent-color); font-weight: 600; text-decoration: underline;">Start</a>` : '<span style="color: var(--text-secondary);">Start N/A</span>'}
                                <span>-</span>
                                ${t.end_lat && t.end_lng ? `<a href="https://www.google.com/maps/search/?api=1&query=${t.end_lat},${t.end_lng}" target="_blank" style="color: var(--accent-color); font-weight: 600; text-decoration: underline;">End</a>` : '<span style="color: var(--text-secondary);">End N/A</span>'}
                                ${t.start_lat && t.start_lng && t.end_lat && t.end_lng ? `<span>|</span><a href="https://www.google.com/maps/dir/?api=1&origin=${t.start_lat},${t.start_lng}&destination=${t.end_lat},${t.end_lng}&travelmode=driving" target="_blank" style="color: var(--success-color); font-weight: 700; text-decoration: underline;">🚗 ${lang === 'id' ? 'Cek Map' : 'Check Map'}</a>` : ''}
                            </div>
                            <div style="display: flex; gap: 8px; margin-top: 6px;">
                                ${startPhotoHtml}
                                ${endPhotoHtml}
                            </div>
                        </div>

                        ${expensesHtml}

                        <div style="border-top: 1px dashed var(--glass-border); padding-top: 8px; margin-top: 8px; display: flex; flex-direction: column;">
                            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                <button onclick="toggleForm('history_edit_trip_form_${t.id}', this)" class="btn" style="background: rgba(16, 185, 129, 0.1); color: var(--success-color); border: 1px solid var(--success-color); padding: 6px 12px; font-size: 0.8rem; border-radius: 8px; margin-bottom: 0; flex: 1;">✏️ <?= __('edit_trip') ?> <span class="arrow-indicator">▼</span></button>
                                <button onclick="toggleForm('${formId}', this)" class="btn" style="background: rgba(245, 158, 11, 0.1); color: #d97706; border: 1px solid #d97706; padding: 6px 12px; font-size: 0.8rem; border-radius: 8px; margin-bottom: 0; flex: 1;">➕ <?= __('add_expense') ?> <span class="arrow-indicator">▼</span></button>
                            </div>
                            ${(t.passenger_approval === 'pending' && t.pass_name !== '?' && t.pass_name) ? `
                            <form action="manage_trip.php" method="POST" onsubmit="showSendWALoader()" style="margin-top: 8px; margin-bottom: 0; display: block; width: 100%;">
                                <input type="hidden" name="action" value="send_wa_request">
                                <input type="hidden" name="trip_id" value="${t.id}">
                                <button type="submit" class="btn" style="background: #2563eb; color: white; padding: 10px; font-size: 0.85rem; border-radius: 8px; width: 100%; display: flex; align-items: center; justify-content: center; gap: 6px; font-weight: 700; margin-bottom: 0;">
                                    💬 ${lang === 'id' ? 'Kirim Request Persetujuan' : 'Send Approval Request'}
                                </button>
                            </form>
                            ` : ''}

                            ${(t.passenger_approval === 'pending' && (!t.expenses || t.expenses.length === 0) && (t.dest_name === '?' || !t.dest_name)) ? `
                            <form action="manage_trip.php" id="history_delete_trip_form_${t.id}" method="POST" style="margin-top: 8px; margin-bottom: 0; display: block; width: 100%;">
                                <input type="hidden" name="action" value="delete_trip">
                                <input type="hidden" name="trip_id" value="${t.id}">
                                <button type="button" onclick="confirmDeleteTripHistory(${t.id})" class="btn" style="background: #ef4444; color: white; padding: 10px; font-size: 0.85rem; border-radius: 8px; width: 100%; display: flex; align-items: center; justify-content: center; gap: 6px; font-weight: 700; margin-bottom: 0;">
                                    🗑️ ${lang === 'id' ? `Hapus TX-${t.id}` : `Delete TX-${t.id}`}
                                </button>
                            </form>
                            ` : ''}
                            
                            <div id="${formId}" class="collapsible-form" style="background: rgba(245, 158, 11, 0.05); padding: 12px; border-radius: 8px; border: 1px solid rgba(245, 158, 11, 0.1); width: 100%;">
                                <form action="manage_trip.php" method="POST" enctype="multipart/form-data" onsubmit="submitFormAjax(event)">
                                    <input type="hidden" name="action" value="add_expense">
                                    <input type="hidden" name="trip_id" value="${t.id}">
                                    <div class="form-group" style="margin-bottom: 8px;">
                                        <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);"><?= __('type') ?></label>
                                        <select name="expense_type" onchange="this.value=='gasoline'?document.getElementById('${litreDivId}').style.display='block':document.getElementById('${litreDivId}').style.display='none'" style="width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                                            <option value="gasoline"><?= __('gasoline') ?></option>
                                            <option value="toll"><?= __('toll') ?></option>
                                            <option value="parking"><?= __('parking') ?></option>
                                            <option value="lunch"><?= __('lunch') ?></option>
                                            <option value="others"><?= __('others') ?></option>
                                        </select>
                                    </div>
                                    <div class="form-group" style="margin-bottom: 8px;">
                                        <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);"><?= __('amount') ?></label>
                                        <input type="text" name="amount" class="formatted-amount-input" oninput="formatAmountInput(this)" required style="width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                                    </div>
                                    <div class="form-group" id="${litreDivId}" style="margin-bottom: 8px;">
                                        <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Litre</label>
                                        <input type="number" step="0.01" name="litre" placeholder="10.5" style="width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                                    </div>
                                    <?php if ($mandatory_photo === '1'): ?>
                                    <div class="form-group" style="margin-bottom: 12px;">
                                        <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);"><?= __('photo_proof') ?></label>
                                        <input type="file" name="photo" accept="image/*" required style="font-size: 0.8rem; color: var(--text-primary);">
                                    </div>
                                    <?php endif; ?>
                                    <button type="submit" class="btn" style="width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; background: #d97706; color: white; border: none; font-weight: 600;"><?= htmlspecialchars(__('add_expense')) ?></button>
                                </form>
                            </div>

                            <div id="history_edit_trip_form_${t.id}" class="collapsible-form" style="background: rgba(16, 185, 129, 0.05); padding: 12px; border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.1); width: 100%;">
                                <form action="manage_trip.php" method="POST" enctype="multipart/form-data" onsubmit="if(validateEditTrip(this)){ submitFormAjax(event); } else { event.preventDefault(); }">
                                    <input type="hidden" name="action" value="edit_trip">
                                    <input type="hidden" name="trip_id" value="${t.id}">
                                    
                                    <input type="text" style="position:absolute; left:-9999px; width:1px; height:1px;" tabindex="-1">
                                    <div class="form-group searchable-select" style="margin-bottom: 8px; position: relative;">
                                        <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Tujuan</label>
                                        <input type="text" id="edit_dest_search_${t.id}" class="dest-search-input" value="${t.dest_name || ''}" placeholder="Cari atau Tambah Tujuan..." autocomplete="off" style="width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                                        <input type="hidden" name="destination_id" id="edit_dest_id_hidden_${t.id}" value="${t.destination_id || ''}">
                                        <input type="text" name="new_destination" id="edit_new_dest_input_${t.id}" placeholder="Nama Tujuan Baru" style="display:none; margin-top: 10px; width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                                        <div id="edit_dest_results_${t.id}" class="search-results"></div>
                                    </div>

                                    <div class="form-group searchable-select" style="margin-bottom: 8px; position: relative;">
                                        <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Penumpang</label>
                                        <input type="text" id="edit_pass_search_${t.id}" class="pass-search-input" value="${t.pass_name || ''}" placeholder="Cari User..." autocomplete="off" style="width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                                        <input type="hidden" name="passenger_id" id="edit_pass_id_hidden_${t.id}" value="${t.passenger_id || ''}">
                                        <div id="edit_pass_results_${t.id}" class="search-results"></div>
                                    </div>

                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                        <div class="form-group" style="margin-bottom: 8px; grid-column: span 2;">
                                            <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Mobil</label>
                                            <select name="car_id" required style="width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                                                ${cars.map(c => '<option value="' + c.id + '"' + (t.car_id == c.id ? ' selected' : '') + '>' + c.car_no + '</option>').join('')}
                                            </select>
                                        </div>
                                        <div class="form-group" style="margin-bottom: 8px;">
                                            <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">KM Awal</label>
                                            <input type="number" name="km_start" value="${t.km_start}" required style="width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                                        </div>
                                        <div class="form-group" style="margin-bottom: 8px;">
                                            <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">KM Akhir</label>
                                            <input type="number" name="km_end" value="${t.km_end || ''}" style="width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--card-bg); color: var(--text-primary);">
                                        </div>
                                    </div>

                                    <?php if ($mandatory_photo === '1'): ?>
                                    <div class="form-group" style="margin-bottom: 12px;">
                                        <label style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Foto KM Start (Opsional)</label>
                                        <input type="file" name="km_start_photo" accept="image/*" style="font-size: 0.8rem; color: var(--text-primary);">
                                    </div>
                                    <?php endif; ?>

                                     <button type="submit" class="btn" style="background: var(--success-color); color: white; width: 100%; padding: 8px; font-size: 0.85rem; border-radius: 6px; border: none; font-weight: 600;">Simpan Perubahan</button>
                                </form>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        } else {
            // Render table view
            container.style.display = 'block';
            container.innerHTML = `
                <div style="width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 12px; border: 1px solid var(--glass-border); background: var(--card-bg);">
                    <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.82rem; color: var(--text-primary); min-width: 650px;">
                        <thead>
                            <tr style="border-bottom: 2px solid var(--glass-border); background: rgba(0, 0, 0, 0.02);">
                                <th style="padding: 10px 8px; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary);">${lang === 'id' ? 'KODE TX / TANGGAL' : 'TX ID / DATE'}</th>
                                <th style="padding: 10px 8px; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary);">${lang === 'id' ? 'DETAIL' : 'DETAILS'}</th>
                                <th style="padding: 10px 8px; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary);">${lang === 'id' ? 'ODO & MAP' : 'ODO & MAP'}</th>
                                <th style="padding: 10px 8px; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary);">${lang === 'id' ? 'BIAYA' : 'COST'}</th>
                                <th style="padding: 10px 8px; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary);">${lang === 'id' ? 'STATUS' : 'STATUS'}</th>
                                <th style="padding: 10px 8px; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary);">${lang === 'id' ? 'AKSI' : 'ACTIONS'}</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${filtered.map(t => {
                                const distance = (t.km_end && t.km_start) ? t.km_end - t.km_start : 0;
                                const totalCost = getTripTotalCost(t);

                                const passBg = t.passenger_approval === 'approved' ? '#dcfce7' : (t.passenger_approval === 'rejected' ? '#fee2e2' : '#f1f5f9');
                                const passColor = t.passenger_approval === 'approved' ? '#166534' : (t.passenger_approval === 'rejected' ? '#991b1b' : '#64748b');
                                
                                let passText = '';
                                if (t.passenger_approval === 'approved') {
                                    passText = lang === 'id' ? 'DISETUJUI' : 'APPROVED';
                                } else if (t.passenger_approval === 'rejected') {
                                    passText = lang === 'id' ? 'DITOLAK' : 'REJECTED';
                                } else {
                                    passText = lang === 'id' ? 'MENUNGGU' : 'PENDING';
                                }

                                // EXPENSES BREAKDOWN for table
                                let expensesHtml = '';
                                if (t.expenses && t.expenses.length > 0) {
                                    expensesHtml = `
                                        <div style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25); padding: 8px 10px; border-radius: 12px; margin-top: 4px; box-shadow: inset 0 0 8px rgba(245, 158, 11, 0.03);">
                                            ${t.expenses.map(exp => {
                                                const expThumb = exp.photo ? `uploads/${exp.photo}` : '';
                                                const thumbImg = expThumb ? `<img src="${expThumb}" style="width: 24px; height: 24px; object-fit: cover; border-radius: 4px; cursor: pointer; vertical-align: middle; margin-right: 4px;" onclick="openImageViewer('${expThumb}')">` : '';
                                                const expenseTranslations = {
                                                    'id': { 'gasoline': 'BBM', 'toll': 'Tol', 'parking': 'Parkir', 'lunch': 'Uang Makan', 'others': 'Lain-lain' },
                                                    'en': { 'gasoline': 'Fuel', 'toll': 'Toll', 'parking': 'Parking', 'lunch': 'Allowance', 'others': 'Miscellaneous' }
                                                };
                                                const expName = (expenseTranslations[lang] && expenseTranslations[lang][exp.expense_type]) ? expenseTranslations[lang][exp.expense_type] : exp.expense_type;
                                                return `
                                                    <div style="font-size: 0.75rem; margin-bottom: 4px; display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                                                        <span>${thumbImg}${expName}</span>
                                                        <span style="font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                                            ${formatIDR(exp.amount)}
                                                            <form action="manage_trip.php" id="history_delete_expense_form_${exp.id}" method="POST" style="display: inline-flex;">
                                                                <input type="hidden" name="action" value="delete_expense">
                                                                <input type="hidden" name="expense_id" value="${exp.id}">
                                                                <input type="hidden" name="trip_id" value="${t.id}">
                                                                <button type="button" onclick="confirmDeleteExpenseHistory(${exp.id})" style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 0.75rem; padding: 0;" title="Hapus Biaya">🗑</button>
                                                            </form>
                                                        </span>
                                                    </div>
                                                `;
                                            }).join('')}
                                        </div>
                                    `;
                                }

                                // KM PHOTOS for table
                                let startPhotoHtml = '';
                                if (t.km_start_photo) {
                                    startPhotoHtml = `<img src="uploads/${t.km_start_photo}" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px; cursor: pointer; border: 1px solid var(--glass-border);" onclick="openImageViewer('uploads/${t.km_start_photo}')">`;
                                }
                                let endPhotoHtml = '';
                                if (t.km_end_photo) {
                                    endPhotoHtml = `<img src="uploads/${t.km_end_photo}" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px; cursor: pointer; border: 1px solid var(--glass-border);" onclick="openImageViewer('uploads/${t.km_end_photo}')">`;
                                }

                                return `
                                    <tr style="border-bottom: 1px solid var(--glass-border);">
                                        <td style="padding: 10px 8px; vertical-align: top;">
                                            <span style="font-size: 0.7rem; color: var(--text-secondary); display: block;">${formatDate(t.start_time)}</span>
                                            <strong style="font-weight: 800; color: var(--text-primary); font-size: 0.8rem;">TX-${t.id}</strong>
                                        </td>
                                        <td style="padding: 10px 8px; vertical-align: top;">
                                            <strong style="font-size: 0.85rem; display: block; color: var(--text-primary);">${t.dest_name}</strong>
                                            <span style="font-size: 0.75rem; color: var(--text-secondary); display: block; margin-bottom: 2px;">👤 ${t.pass_name}</span>
                                            <span style="font-size: 0.75rem; color: var(--text-secondary); display: block;">⏱️ ${formatTime(t.start_time)} - ${formatTime(t.end_time)}</span>
                                        </td>
                                        <td style="padding: 10px 8px; vertical-align: top;">
                                            <span style="font-size: 0.8rem; color: var(--text-primary); display: block; margin-bottom: 2px;">🚗 ${t.car_no}</span>
                                            <span style="font-size: 0.8rem; color: var(--text-secondary); display: block; margin-bottom: 4px;">📍 ${t.km_start} &rarr; ${t.km_end} (${distance} KM)</span>
                                            <div style="display: flex; gap: 4px; align-items: center; margin-bottom: 4px;">
                                                ${startPhotoHtml}
                                                ${endPhotoHtml}
                                            </div>
                                            <div style="font-size: 0.75rem; white-space: nowrap;">
                                                🗺️ 
                                                ${t.start_lat && t.start_lng ? `<a href="https://www.google.com/maps/search/?api=1&query=${t.start_lat},${t.start_lng}" target="_blank" style="color: var(--accent-color); font-weight: 600; text-decoration: underline;">Start</a>` : '<span style="color: var(--text-secondary);">Start N/A</span>'}
                                                <span>-</span>
                                                ${t.end_lat && t.end_lng ? `<a href="https://www.google.com/maps/search/?api=1&query=${t.end_lat},${t.end_lng}" target="_blank" style="color: var(--accent-color); font-weight: 600; text-decoration: underline;">End</a>` : '<span style="color: var(--text-secondary);">End N/A</span>'}
                                                ${t.start_lat && t.start_lng && t.end_lat && t.end_lng ? `| <a href="https://www.google.com/maps/dir/?api=1&origin=${t.start_lat},${t.start_lng}&destination=${t.end_lat},${t.end_lng}&travelmode=driving" target="_blank" style="color: var(--success-color); font-weight: 700; text-decoration: underline;">Map</a>` : ''}
                                            </div>
                                        </td>
                                        <td style="padding: 10px 8px; vertical-align: top;">
                                            <strong style="color: var(--accent-color); font-size: 0.85rem; display: block; margin-bottom: 4px;">${formatIDR(totalCost)}</strong>
                                            ${expensesHtml}
                                        </td>
                                        <td style="padding: 10px 8px; vertical-align: top;">
                                            <span class="badge" style="font-size: 0.65rem; padding: 3px 6px; background: ${passBg}; color: ${passColor}; font-weight: 700; letter-spacing: 0.02em; display: inline-block;">
                                                ${passText}
                                            </span>
                                        </td>
                                        <td style="padding: 10px 8px; vertical-align: top;">
                                            <div style="display: flex; flex-direction: column; gap: 4px; width: 80px;">
                                                <button onclick="openEditTripModal(${t.id})" class="btn" style="background: rgba(16, 185, 129, 0.1); color: var(--success-color); border: 1px solid var(--success-color); padding: 4px 8px; font-size: 0.75rem; border-radius: 6px; margin: 0; white-space: nowrap; width: 100%;">✏️ Edit</button>
                                                <button onclick="openAddExpenseModal(${t.id})" class="btn" style="background: rgba(245, 158, 11, 0.1); color: #d97706; border: 1px solid #d97706; padding: 4px 8px; font-size: 0.75rem; border-radius: 6px; margin: 0; white-space: nowrap; width: 100%;">➕ ${lang === 'id' ? 'Biaya' : 'Cost'}</button>
                                                
                                                ${(t.passenger_approval === 'pending' && t.pass_name !== '?' && t.pass_name) ? `
                                                <form action="manage_trip.php" method="POST" onsubmit="showSendWALoader()" style="margin: 0; display: block; width: 100%;">
                                                    <input type="hidden" name="action" value="send_wa_request">
                                                    <input type="hidden" name="trip_id" value="${t.id}">
                                                    <button type="submit" class="btn" style="background: #2563eb; color: white; padding: 4px 8px; font-size: 0.75rem; border-radius: 6px; margin: 0; white-space: nowrap; width: 100%;">💬 WA</button>
                                                </form>
                                                ` : ''}

                                                ${(t.passenger_approval === 'pending' && (!t.expenses || t.expenses.length === 0) && (t.dest_name === '?' || !t.dest_name)) ? `
                                                <form action="manage_trip.php" id="history_delete_trip_form_${t.id}" method="POST" style="margin: 0; display: block; width: 100%;">
                                                    <input type="hidden" name="action" value="delete_trip">
                                                    <input type="hidden" name="trip_id" value="${t.id}">
                                                    <button type="button" onclick="confirmDeleteTripHistory(${t.id})" class="btn" style="background: #ef4444; color: white; padding: 4px 8px; font-size: 0.75rem; border-radius: 6px; margin: 0; white-space: nowrap; width: 100%;">🗑️ ${lang === 'id' ? 'Hapus' : 'Delete'}</button>
                                                </form>
                                                ` : ''}
                                            </div>
                                        </td>
                                    </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        }

        // Initialize searchable autocomplete inputs for dynamic edit forms (only applicable in Card view where inline forms exist)
        if (currentViewMode === 'card') {
            filtered.forEach(t => {
                initSearchable(`edit_dest_search_${t.id}`, `edit_dest_id_hidden_${t.id}`, `edit_dest_results_${t.id}`, destinations, true);
                initSearchable(`edit_pass_search_${t.id}`, `edit_pass_id_hidden_${t.id}`, `edit_pass_results_${t.id}`, passengers, false);
            });
        }
    }

    window.showSendWALoader = function() {
        const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
        Swal.fire({
            title: lang === 'id' ? 'Mengirim...' : 'Sending...',
            text: lang === 'id' ? 'Sedang mengirim request ke WhatsApp penumpang' : 'Sending request to passenger\'s WhatsApp',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
    };

    window.confirmDeleteTripHistory = function(id) {
        const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
        Swal.fire({
            title: lang === 'id' ? 'Hapus Transaksi?' : 'Delete Transaction?',
            text: lang === 'id' ? `Yakin akan hapus TX-${id} secara permanen?` : `Are you sure you want to delete TX-${id} permanently?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#3085d6',
            confirmButtonText: lang === 'id' ? 'Ya, Hapus' : 'Yes, Delete',
            cancelButtonText: lang === 'id' ? 'Batal' : 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('history_delete_trip_form_' + id).submit();
            }
        });
    };

    window.confirmDeleteExpenseHistory = function(id) {
        const lang = "<?= $_SESSION['lang'] ?? 'en' ?>";
        Swal.fire({
            title: lang === 'id' ? 'Hapus Biaya?' : 'Delete Expense?',
            text: lang === 'id' ? 'Apakah Anda yakin ingin menghapus catatan biaya ini?' : 'Are you sure you want to delete this expense?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#3085d6',
            confirmButtonText: lang === 'id' ? 'Ya, Hapus' : 'Yes, Delete',
            cancelButtonText: lang === 'id' ? 'Batal' : 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                submitFormElementAjax(document.getElementById('history_delete_expense_form_' + id));
            }
        });
    };

    // Attach Event Listeners
    searchInput.addEventListener('input', renderTrips);
    sortSelect.addEventListener('change', renderTrips);
    userFilter.addEventListener('change', renderTrips);

    // Initial render
    renderTrips();
});
</script>
