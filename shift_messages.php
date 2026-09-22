<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// AJAX handler to get shift comments
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'get_shift_comments') {
        header('Content-Type: application/json');
        $shift_id = intval($_GET['shift_id'] ?? $_POST['shift_id'] ?? 0);
        if ($shift_id) {
            $stmt = $pdo->prepare("SELECT * FROM shift_comments WHERE shift_id = ? ORDER BY created_at ASC");
            $stmt->execute([$shift_id]);
            $comments = $stmt->fetchAll();
            echo json_encode(['success' => true, 'comments' => $comments]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid shift ID']);
        }
        exit;
    } elseif ($_GET['action'] === 'admin_add_shift_comment') {
        header('Content-Type: application/json');
        try {
            $shift_id = intval($_POST['shift_id'] ?? 0);
            $comment = trim($_POST['comment'] ?? '');
            $admin_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
            $admin_id = $_SESSION['user_id'] ?? null;
            
            if (!$shift_id || empty($comment)) {
                echo json_encode(['success' => false, 'error' => 'Comment cannot be empty.']);
                exit;
            }

            $stmt_c = $pdo->prepare("INSERT INTO shift_comments (shift_id, user_type, user_id, user_name, comment) VALUES (?, 'admin', ?, ?, ?)");
            $stmt_c->execute([$shift_id, $admin_id, $admin_name, $comment]);

            $stmt_u = $pdo->prepare("UPDATE shifts SET last_note = ?, note_status = 'replied_admin' WHERE id = ?");
            $stmt_u->execute([$comment, $shift_id]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// Fetch all shift notes / messages
$sql = "SELECT 
            s.id as shift_id,
            s.shift_date,
            s.start_time,
            s.end_time,
            s.note_status,
            s.last_note,
            u.full_name as driver_name,
            u.nik as driver_nik,
            p.name as supervisor_name,
            (SELECT COUNT(*) FROM shift_comments sc WHERE sc.shift_id = s.id) as comment_count,
            (SELECT MAX(created_at) FROM shift_comments sc WHERE sc.shift_id = s.id) as last_comment_at,
            (SELECT user_name FROM shift_comments sc WHERE sc.shift_id = s.id ORDER BY sc.created_at DESC LIMIT 1) as last_user_name,
            (SELECT user_type FROM shift_comments sc WHERE sc.shift_id = s.id ORDER BY sc.created_at DESC LIMIT 1) as last_user_type
        FROM shifts s
        JOIN users u ON s.driver_id = u.id
        LEFT JOIN master_passengers p ON u.supervisor_id = p.id
        WHERE s.note_status IN ('pending_admin', 'replied_admin') 
           OR s.id IN (SELECT DISTINCT shift_id FROM shift_comments)
        ORDER BY (s.note_status = 'pending_admin') DESC, s.shift_date DESC, s.id DESC";

$stmt = $pdo->query($sql);
$messages_list = $stmt->fetchAll();

$total_count = count($messages_list);
$pending_count = 0;
$replied_count = 0;

foreach ($messages_list as $m) {
    if ($m['note_status'] === 'pending_admin') {
        $pending_count++;
    } elseif ($m['note_status'] === 'replied_admin') {
        $replied_count++;
    }
}

$initial_filter = $_GET['filter'] ?? 'all';
$is_collapsed = isset($_SESSION['sidebar_collapsed']) && $_SESSION['sidebar_collapsed'];
$theme = $_SESSION['theme'] ?? 'light';
$is_id = ($_SESSION['lang'] ?? 'en') === 'id';
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>" class="<?php echo $theme === 'dark' ? 'dark-mode' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo $is_id ? 'History Pesan & Catatan' : 'Messages & Notes History'; ?> - <?php echo __('app_name'); ?></title>
    <link rel="icon" type="image/png" href="icon.png">
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --pbi-blue: #118DFF; --pbi-bg: #F3F2F1; --pbi-dark: #333;
            --sidebar-w: 240px; --sidebar-collapsed: 70px;
            --card-shadow: 0 1.6px 3.6px 0 rgba(0,0,0,0.132), 0 0.3px 0.9px 0 rgba(0,0,0,0.108);
        }
        .dark-mode { --pbi-bg: #1e293b; --pbi-dark: #f8fafc; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--pbi-bg); margin: 0; display: flex; transition: all 0.3s; color: var(--pbi-dark); }

        .main-content { margin-left: var(--sidebar-w); flex: 1; padding: 20px; transition: margin-left 0.3s; }
        body.collapsed .main-content { margin-left: var(--sidebar-collapsed); }

        .stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 20px; }
        .stat-card {
            background: var(--card-bg); padding: 16px 20px; border-radius: 8px;
            box-shadow: var(--card-shadow); border-left: 4px solid var(--pbi-blue);
            border: 1px solid var(--glass-border);
        }
        .stat-card.pending { border-left: 4px solid #d97706; }
        .stat-card.replied { border-left: 4px solid #16a34a; }
        .stat-label { font-size: 0.75rem; color: var(--text-secondary); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 1.8rem; font-weight: 800; margin-top: 4px; color: var(--text-primary); }

        .filter-container {
            background: var(--card-bg); padding: 16px; border-radius: 8px;
            box-shadow: var(--card-shadow); border: 1px solid var(--glass-border); margin-bottom: 20px;
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;
        }
        .tab-buttons { display: flex; gap: 6px; background: rgba(0,0,0,0.04); padding: 4px; border-radius: 6px; }
        .dark-mode .tab-buttons { background: rgba(255,255,255,0.05); }
        .tab-btn {
            padding: 8px 16px; border: none; background: transparent; font-size: 0.85rem;
            font-weight: 600; color: var(--text-secondary); border-radius: 4px; cursor: pointer; transition: all 0.2s;
        }
        .tab-btn.active { background: var(--card-bg); color: var(--pbi-blue); box-shadow: 0 2px 4px rgba(0,0,0,0.08); font-weight: 700; }

        .search-input {
            width: 260px; padding: 8px 12px; border: 1px solid var(--glass-border);
            border-radius: 6px; font-size: 0.85rem; background: var(--bg-color); color: var(--text-primary);
        }

        .messages-table-card {
            background: var(--card-bg); padding: 20px; border-radius: 8px;
            box-shadow: var(--card-shadow); border: 1px solid var(--glass-border);
        }
        .pbi-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
        .pbi-table th { text-align: left; padding: 10px 12px; border-bottom: 2px solid var(--glass-border); color: var(--text-secondary); font-weight: 700; }
        .pbi-table td { padding: 12px; border-bottom: 1px solid var(--glass-border); color: var(--text-primary); vertical-align: middle; }
        .pbi-table tr:hover { background: rgba(17, 141, 255, 0.03); }

        .badge-status {
            padding: 4px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;
        }
        .badge-pending { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .badge-replied { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-none { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }

        .btn-reply {
            background: var(--pbi-blue); color: #fff; border: none; padding: 6px 14px;
            border-radius: 6px; cursor: pointer; font-size: 0.78rem; font-weight: 700;
            display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;
        }
        .btn-reply:hover { background: #0078d4; transform: translateY(-1px); }

        /* Modal Styles */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px); }
        .modal-content {
            background: var(--card-bg); margin: 4% auto; padding: 24px; border-radius: 16px;
            width: 600px; max-width: 92%; max-height: 85vh; display: flex; flex-direction: column;
            border: 1px solid var(--glass-border); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
        }
        .comments-container {
            max-height: 380px; overflow-y: auto; padding: 12px; display: flex;
            flex-direction: column; gap: 12px; margin-bottom: 16px; border: 1px solid var(--glass-border);
            border-radius: 10px; background: rgba(0,0,0,0.02);
        }
        .dark-mode .comments-container { background: rgba(0,0,0,0.2); }

        @media (max-width: 768px) {
            .stat-grid { grid-template-columns: 1fr; }
            .filter-container { flex-direction: column; align-items: stretch; }
            .search-input { width: 100%; }
        }
    </style>
</head>
<body class="<?php echo $is_collapsed ? 'collapsed' : ''; ?>">

    <?php include 'sidemenu.php'; ?>

    <div class="main-content">
        <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom: 20px;">
            <div>
                <h2 style="margin:0; font-size: 1.5rem; color: var(--text-primary);">💬 <?php echo $is_id ? 'History Pesan & Catatan' : 'Messages & Notes History'; ?></h2>
                <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 4px;">
                    <?php echo $is_id ? 'Daftar semua pesan dan catatan dari Penumpang / Supervisor' : 'Overview of all passenger notes and admin response threads'; ?>
                </div>
            </div>
        </div>

        <!-- Summary Stats Cards -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-label"><?php echo $is_id ? 'Total Diskusi Pesan' : 'Total Note Threads'; ?></div>
                <div class="stat-value" id="statTotal"><?php echo $total_count; ?></div>
            </div>
            <div class="stat-card pending">
                <div class="stat-label"><?php echo $is_id ? 'Belum Dibalas (Pending)' : 'Pending Admin Reply'; ?></div>
                <div class="stat-value" id="statPending" style="color: #d97706;"><?php echo $pending_count; ?></div>
            </div>
            <div class="stat-card replied">
                <div class="stat-label"><?php echo $is_id ? 'Sudah Dibalas Admin' : 'Replied by Admin'; ?></div>
                <div class="stat-value" id="statReplied" style="color: #16a34a;"><?php echo $replied_count; ?></div>
            </div>
        </div>

        <!-- Filter & Search -->
        <div class="filter-container">
            <div class="tab-buttons">
                <button class="tab-btn <?php echo $initial_filter === 'all' ? 'active' : ''; ?>" onclick="setFilter('all', this)"><?php echo $is_id ? 'Semua Pesan' : 'All Messages'; ?> (<?php echo $total_count; ?>)</button>
                <button class="tab-btn <?php echo $initial_filter === 'pending' ? 'active' : ''; ?>" onclick="setFilter('pending', this)">🔔 <?php echo $is_id ? 'Pending Reply' : 'Pending Reply'; ?> (<?php echo $pending_count; ?>)</button>
                <button class="tab-btn <?php echo $initial_filter === 'replied' ? 'active' : ''; ?>" onclick="setFilter('replied', this)">✅ <?php echo $is_id ? 'Sudah Dibalas' : 'Replied'; ?> (<?php echo $replied_count; ?>)</button>
            </div>
            <input type="text" id="searchInput" class="search-input" placeholder="<?php echo $is_id ? 'Cari driver, supervisor, atau pesan...' : 'Search driver, supervisor, or note...'; ?>" oninput="applyFilters()">
        </div>

        <!-- Messages Table -->
        <div class="messages-table-card">
            <div style="overflow-x: auto;">
                <table class="pbi-table" id="messagesTable">
                    <thead>
                        <tr>
                            <th><?php echo $is_id ? 'Tanggal Shift' : 'Shift Date'; ?></th>
                            <th>Driver</th>
                            <th>Supervisor / Passenger</th>
                            <th><?php echo $is_id ? 'Catatan Terakhir' : 'Latest Note'; ?></th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="messagesTbody">
                        <?php if (empty($messages_list)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 30px; color: var(--text-muted);">
                                    <?php echo $is_id ? 'Belum ada catatan atau pesan dari penumpang.' : 'No passenger notes or message threads recorded.'; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($messages_list as $m): 
                                $status = $m['note_status'];
                                $status_class = $status === 'pending_admin' ? 'badge-pending' : ($status === 'replied_admin' ? 'badge-replied' : 'badge-none');
                                $status_text = $status === 'pending_admin' ? '🔔 Pending Reply' : ($status === 'replied_admin' ? '✅ Replied' : 'No Notes');
                                if ($is_id) {
                                    $status_text = $status === 'pending_admin' ? '🔔 Menunggu Balasan' : ($status === 'replied_admin' ? '✅ Sudah Dibalas' : 'Tidak Ada Catatan');
                                }
                            ?>
                                <tr data-status="<?php echo $status; ?>" data-shift-id="<?php echo $m['shift_id']; ?>">
                                    <td><strong><?php echo date('d M Y', strtotime($m['shift_date'])); ?></strong></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($m['driver_name']); ?></strong>
                                        <?php if ($m['driver_nik']): ?>
                                            <br><small style="color: var(--text-muted); font-size: 0.72rem;">NIK: <?php echo htmlspecialchars($m['driver_nik']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-weight: 600; color: var(--pbi-blue);">
                                            👤 <?php echo htmlspecialchars($m['supervisor_name'] ?: ($m['last_user_name'] ?: 'Passenger')); ?>
                                        </span>
                                    </td>
                                    <td style="max-width: 320px;">
                                        <div style="font-size: 0.82rem; color: var(--text-primary); font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            "<?php echo htmlspecialchars($m['last_note'] ?: '-'); ?>"
                                        </div>
                                        <small style="font-size: 0.7rem; color: var(--text-muted);">
                                            <?php echo $m['last_comment_at'] ? date('d M H:i', strtotime($m['last_comment_at'])) : ''; ?>
                                            <?php if ($m['last_user_name']): ?>
                                                • by <strong><?php echo htmlspecialchars($m['last_user_name']); ?></strong>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button onclick="openCommentsModal(<?php echo $m['shift_id']; ?>, '<?php echo addslashes(htmlspecialchars($m['driver_name'])); ?>', '<?php echo $m['shift_date']; ?>')" class="btn-reply">
                                            💬 <?php echo $is_id ? 'Buka & Reply' : 'View & Reply'; ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- COMMENTS MODAL -->
    <div id="adminShiftCommentsModal" class="modal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h3 id="modalTitle" style="margin:0; font-size:1.15rem; color:var(--text-primary);">💬 Diskusi Pesan Shift</h3>
                <button onclick="closeCommentsModal()" style="background:none; border:none; cursor:pointer; font-size:1.5rem; color:var(--text-muted);">&times;</button>
            </div>
            
            <div id="commentsContainer" class="comments-container">
                <div style="text-align:center; color:var(--text-muted); padding:20px;">Loading messages...</div>
            </div>

            <form id="addCommentForm" onsubmit="submitReply(event)">
                <input type="hidden" name="shift_id" id="commentShiftId">
                <div style="display:flex; gap:10px;">
                    <textarea name="comment" id="commentInputText" placeholder="<?php echo $is_id ? 'Ketik balasan pesan di sini...' : 'Type your reply here...'; ?>" required style="flex:1; height:60px; padding:10px; border-radius:8px; border:1px solid var(--glass-border); background:var(--bg-color); color:var(--text-primary); font-family:inherit; font-size:0.85rem; resize:none;"></textarea>
                    <button type="submit" class="btn-reply" style="padding:0 20px; font-size:0.88rem; font-weight:700;">
                        <?php echo $is_id ? 'Kirim Balasan' : 'Send Reply'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentFilter = '<?php echo $initial_filter; ?>';

        function toggleSidebar() {
            document.body.classList.toggle('collapsed');
            fetch('manage_admin_action.php?action=toggle_sidebar');
        }

        function setFilter(filter, btn) {
            currentFilter = filter;
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            applyFilters();
        }

        function applyFilters() {
            const search = document.getElementById('searchInput').value.toLowerCase().trim();
            const rows = document.querySelectorAll('#messagesTbody tr[data-shift-id]');

            rows.forEach(row => {
                const status = row.getAttribute('data-status');
                const text = row.innerText.toLowerCase();

                let matchesFilter = true;
                if (currentFilter === 'pending') {
                    matchesFilter = (status === 'pending_admin');
                } else if (currentFilter === 'replied') {
                    matchesFilter = (status === 'replied_admin');
                }

                let matchesSearch = text.includes(search);

                if (matchesFilter && matchesSearch) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function openCommentsModal(shiftId, driverName, shiftDate) {
            document.getElementById('commentShiftId').value = shiftId;
            document.getElementById('commentInputText').value = '';
            const container = document.getElementById('commentsContainer');
            container.innerHTML = '<div style="text-align: center; color: var(--text-muted); padding: 20px;">Loading notes...</div>';
            document.getElementById('modalTitle').innerText = `💬 ${driverName} (${shiftDate})`;
            document.getElementById('adminShiftCommentsModal').style.display = 'block';

            fetch(`shift_messages.php?action=get_shift_comments&shift_id=${shiftId}`)
                .then(res => res.json())
                .then(res => {
                    if (res.success) {
                        renderComments(res.comments);
                    } else {
                        container.innerHTML = '<div style="color: #dc2626; padding: 12px;">Failed to load messages.</div>';
                    }
                })
                .catch(err => {
                    container.innerHTML = '<div style="color: #dc2626; padding: 12px;">Network error.</div>';
                });
        }

        function closeCommentsModal() {
            document.getElementById('adminShiftCommentsModal').style.display = 'none';
        }

        function renderComments(comments) {
            const container = document.getElementById('commentsContainer');
            if (!comments || comments.length === 0) {
                container.innerHTML = '<div style="text-align: center; color: var(--text-muted); padding: 20px; font-size: 0.85rem;">No messages recorded for this shift.</div>';
                return;
            }

            container.innerHTML = comments.map(c => {
                const isAdmin = c.user_type === 'admin';
                const bg = isAdmin ? 'rgba(16, 185, 129, 0.08)' : 'rgba(17, 141, 255, 0.08)';
                const border = isAdmin ? 'rgba(16, 185, 129, 0.25)' : 'rgba(17, 141, 255, 0.25)';
                const badgeColor = isAdmin ? '#166534' : '#1e40af';
                const badgeText = isAdmin ? 'Admin' : 'Passenger';
                
                return `
                    <div style="background: ${bg}; border: 1px solid ${border}; border-radius: 10px; padding: 10px 14px; font-size: 0.82rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <span style="font-weight: 700; color: ${badgeColor}; display: inline-flex; align-items: center; gap: 4px;">
                                ${isAdmin ? '🛡️' : '👤'} ${escapeHtml(c.user_name)} <small style="font-weight: 600; opacity: 0.8;">(${badgeText})</small>
                            </span>
                            <span style="font-size: 0.7rem; color: var(--text-muted);">${c.created_at}</span>
                        </div>
                        <div style="color: var(--text-primary); line-height: 1.4; word-break: break-word;">${escapeHtml(c.comment)}</div>
                    </div>
                `;
            }).join('');
            container.scrollTop = container.scrollHeight;
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
        }

        function submitReply(e) {
            e.preventDefault();
            const form = document.getElementById('addCommentForm');
            const data = new FormData(form);
            const submitBtn = form.querySelector('button[type="submit"]');
            const oldText = submitBtn.innerText;
            submitBtn.disabled = true;
            submitBtn.innerText = "Mengirim...";

            fetch('shift_messages.php?action=admin_add_shift_comment', {
                method: 'POST',
                body: data
            })
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    const shiftId = document.getElementById('commentShiftId').value;
                    // Refresh comments
                    fetch(`shift_messages.php?action=get_shift_comments&shift_id=${shiftId}`)
                        .then(r => r.json())
                        .then(r => {
                            if (r.success) renderComments(r.comments);
                        });
                    document.getElementById('commentInputText').value = '';
                    
                    // Update table row status dynamically
                    const row = document.querySelector(`tr[data-shift-id="${shiftId}"]`);
                    if (row) {
                        row.setAttribute('data-status', 'replied_admin');
                        const statusCell = row.querySelector('.badge-status');
                        if (statusCell) {
                            statusCell.className = 'badge-status badge-replied';
                            statusCell.innerText = '<?php echo $is_id ? '✅ Sudah Dibalas' : '✅ Replied'; ?>';
                        }
                    }

                    // Toast success
                    Swal.fire({
                        icon: 'success',
                        title: '<?php echo $is_id ? 'Balasan berhasil dikirim!' : 'Reply sent successfully!'; ?>',
                        timer: 1500,
                        showConfirmButton: false,
                        toast: true,
                        position: 'top-end'
                    });
                } else {
                    alert(res.error || 'Failed to post reply.');
                }
            })
            .catch(err => {
                alert('Network error.');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerText = oldText;
            });
        }

        // Initialize on DOM load
        document.addEventListener('DOMContentLoaded', () => {
            applyFilters();
            
            // Auto open first pending item if filter=pending is passed in URL
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('filter') === 'pending') {
                const firstPendingBtn = document.querySelector('tr[data-status="pending_admin"] .btn-reply');
                if (firstPendingBtn) {
                    setTimeout(() => firstPendingBtn.click(), 300);
                }
            }
        });
    </script>
</body>
</html>
