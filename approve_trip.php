<?php
require_once 'config.php';

$token = $_GET['token'] ?? '';
$trip = null;
$expired = false;
$pin_required = false;
$pin_error = '';
$setup_pin = false;
$success = false;
$success_message = '';

$lang = $_SESSION['lang'] ?? 'en';

$texts = [
    'en' => [
        'invalid_token_title' => 'Invalid Token',
        'invalid_token_desc' => 'The approval link is invalid or expired.',
        'expired_title' => 'QR Code Expired',
        'expired_desc' => 'This QR code has expired. Please ask the driver to generate a new one, or approve this trip from your Passenger Dashboard.',
        'thank_you_title' => 'All Confirmed!',
        'thank_you_desc' => 'Thank you! All your pending trips have been successfully verified and saved.',
        'close_btn' => 'Close Window',
        'already_responded_title' => 'Trip Confirmed',
        'already_responded_desc' => 'You have already confirmed this trip previously.',
        'confirm_title' => 'Confirm Trip(s)',
        'confirm_desc' => 'Hello <strong>%s</strong>, please review and confirm your completed trips.',
        'driver' => 'Driver',
        'destination' => 'Destination',
        'time' => 'Time / Duration',
        'create_pin' => 'Create a 6-digit PIN for Security',
        'enter_pin' => 'Enter your 6-digit PIN',
        'pin_placeholder' => '••••••',
        'feedback_label' => 'Notes / Feedback (Optional)',
        'feedback_placeholder' => 'Share your experience...',
        'reject_btn' => 'Reject Selected',
        'approve_btn' => 'Approve Selected',
        'approve_all_btn' => 'Approve All (%d)',
        'pin_length_err' => 'PIN must be 6 digits.',
        'pin_invalid_err' => 'Invalid PIN.',
        
        // Detailed labels
        'trip_details' => 'Trip Details',
        'other_pending' => 'Other Pending Trips',
        'no_other_pending' => 'No other pending trips.',
        'route' => 'Route',
        'odometer' => 'Odometer Reading',
        'distance' => 'Distance Traveled',
        'expenses' => 'Additional Expenses',
        'total_cost' => 'Total Expenses',
        'view_photo' => 'View Photo',
        'view_map' => 'Open Google Maps Route',
        'approve_this' => 'Approve This',
        'reject_this' => 'Reject This',
        'remaining_pending' => 'You have %d other pending trip(s) requiring your confirmation.',
        'success_msg' => 'Selected trip(s) processed successfully.',
        
        // Expense types
        'gasoline' => 'Fuel / BBM',
        'toll' => 'Toll',
        'parking' => 'Parking',
        'lunch' => 'Allowance',
        'others' => 'Miscellaneous',
        
        // Bilingual inline descriptors
        'driver_lbl' => 'Driver / Pengemudi',
        'dest_lbl' => 'Destination / Tujuan',
        'car_lbl' => 'Car / Mobil',
        'time_lbl' => 'Time / Waktu',
        'odo_lbl' => 'Odometer / Odometer',
        'dist_lbl' => 'Distance / Jarak',
        'exp_lbl' => 'Expenses / Biaya',
        'active_badge' => 'Current / Sekarang',
        'pending_badge' => 'Pending / Tertunda',
    ],
    'id' => [
        'invalid_token_title' => 'Token Tidak Valid',
        'invalid_token_desc' => 'Link persetujuan tidak valid atau sudah kedaluwarsa.',
        'expired_title' => 'QR Code Kedaluwarsa',
        'expired_desc' => 'QR code ini telah kedaluwarsa. Silakan minta pengemudi membuat yang baru, atau setujui perjalanan dari Dashboard Penumpang Anda.',
        'thank_you_title' => 'Semua Selesai!',
        'thank_you_desc' => 'Terima kasih! Semua perjalanan tertunda Anda telah berhasil dikonfirmasi.',
        'close_btn' => 'Tutup Jendela',
        'already_responded_title' => 'Sudah Dikonfirmasi',
        'already_responded_desc' => 'Anda telah mengonfirmasi perjalanan ini sebelumnya.',
        'confirm_title' => 'Konfirmasi Perjalanan',
        'confirm_desc' => 'Halo <strong>%s</strong>, silakan periksa dan konfirmasi perjalanan Anda.',
        'driver' => 'Driver',
        'destination' => 'Tujuan',
        'time' => 'Waktu / Durasi',
        'create_pin' => 'Buat 6-digit PIN untuk Keamanan',
        'enter_pin' => 'Masukkan 6-digit PIN Anda',
        'pin_placeholder' => '••••••',
        'feedback_label' => 'Catatan / Umpan Balik (Opsional)',
        'feedback_placeholder' => 'Tuliskan pengalaman Anda...',
        'reject_btn' => 'Tolak Terpilih',
        'approve_btn' => 'Setujui Terpilih',
        'approve_all_btn' => 'Setujui Semua (%d)',
        'pin_length_err' => 'PIN harus 6 digit.',
        'pin_invalid_err' => 'PIN tidak valid.',
        
        // Detailed labels
        'trip_details' => 'Detail Perjalanan',
        'other_pending' => 'Perjalanan Tertunda Lainnya',
        'no_other_pending' => 'Tidak ada perjalanan tertunda lainnya.',
        'route' => 'Rute',
        'odometer' => 'Odometer',
        'distance' => 'Jarak',
        'expenses' => 'Biaya Tambahan',
        'total_cost' => 'Total Biaya',
        'view_photo' => 'Lihat Foto',
        'view_map' => 'Buka Google Maps Rute',
        'approve_this' => 'Setujui Ini',
        'reject_this' => 'Tolak Ini',
        'remaining_pending' => 'Anda memiliki %d perjalanan tertunda lainnya yang memerlukan konfirmasi Anda.',
        'success_msg' => 'Perjalanan terpilih berhasil diproses.',
        
        // Expense types
        'gasoline' => 'BBM / Fuel',
        'toll' => 'Tol / Toll',
        'parking' => 'Parkir / Parking',
        'lunch' => 'Uang Makan / Allowance',
        'others' => 'Lain-lain / Miscellaneous',
        
        // Bilingual inline descriptors
        'driver_lbl' => 'Driver / Pengemudi',
        'dest_lbl' => 'Destination / Tujuan',
        'car_lbl' => 'Car / Mobil',
        'time_lbl' => 'Time / Waktu',
        'odo_lbl' => 'Odometer / Odometer',
        'dist_lbl' => 'Distance / Jarak',
        'exp_lbl' => 'Expenses / Biaya',
        'active_badge' => 'Current / Sekarang',
        'pending_badge' => 'Pending / Tertunda',
    ],
];

$t = $texts[$lang];

if ($token) {
    $stmt = $pdo->prepare("SELECT t.*, d.name as dest_name, u.full_name as driver_name, p.name as pass_name, p.pin as pass_pin,
                                  car.car_no, car.model as car_model
                          FROM trips t 
                          JOIN master_destinations d ON t.destination_id = d.id 
                          JOIN shifts s ON t.shift_id = s.id 
                          JOIN users u ON s.driver_id = u.id 
                          JOIN master_passengers p ON t.passenger_id = p.id
                          LEFT JOIN master_cars car ON t.car_id = car.id
                          WHERE t.approval_token = ?");
    $stmt->execute([$token]);
    $trip = $stmt->fetch();
    
    if ($trip) {
        $passenger_id = $trip['passenger_id'];
        if ($trip['passenger_approval'] === 'pending') {
            $pin_required = true;
            if (empty($trip['pass_pin'])) {
                $setup_pin = true;
            }
        }
        
        // Fetch all pending completed trips for this passenger
        $stmt_pending = $pdo->prepare("SELECT t.*, d.name as dest_name, u.full_name as driver_name,
                                              car.car_no, car.model as car_model
                                       FROM trips t 
                                       JOIN master_destinations d ON t.destination_id = d.id 
                                       JOIN shifts s ON t.shift_id = s.id 
                                       JOIN users u ON s.driver_id = u.id 
                                       LEFT JOIN master_cars car ON t.car_id = car.id
                                       WHERE t.passenger_id = ? AND t.passenger_approval = 'pending' AND t.status = 'completed'
                                       ORDER BY t.end_time DESC");
        $stmt_pending->execute([$passenger_id]);
        $pending_trips = $stmt_pending->fetchAll();
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $trip && !$expired) {
    $input_pin = $_POST['pin'] ?? '';
    $feedback = $_POST['feedback'] ?? '';
    $action = $_POST['action'] ?? '';
    
    // Validate PIN
    $pin_valid = false;
    if ($setup_pin) {
        if (strlen($input_pin) === 6 && is_numeric($input_pin)) {
            $hashed_pin = password_hash($input_pin, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE master_passengers SET pin = ? WHERE id = ?")->execute([$hashed_pin, $trip['passenger_id']]);
            $pin_valid = true;
            $trip['pass_pin'] = $hashed_pin;
            $setup_pin = false;
        } else {
            $pin_error = $t['pin_length_err'];
        }
    } else {
        if (password_verify($input_pin, $trip['pass_pin'] ?? '')) {
            $pin_valid = true;
        } else {
            $pin_error = $t['pin_invalid_err'];
        }
    }

    if ($pin_valid) {
        $target_status = (strpos($action, 'approve') !== false) ? 'approved' : 'rejected';
        $action_success = false;
        
        if ($action === 'approve_all' || $action === 'reject_all') {
            $stmt = $pdo->prepare("UPDATE trips SET passenger_approval = ?, passenger_feedback = ? WHERE passenger_id = ? AND passenger_approval = 'pending' AND status = 'completed'");
            $stmt->execute([$target_status, $feedback, $trip['passenger_id']]);
            $action_success = true;
        } elseif ($action === 'approve_selected' || $action === 'reject_selected') {
            $selected_ids = $_POST['trip_ids'] ?? [];
            if (!empty($selected_ids) && is_array($selected_ids)) {
                $in_clause = implode(',', array_fill(0, count($selected_ids), '?'));
                $params = array_merge([$target_status, $feedback, $trip['passenger_id']], $selected_ids);
                $stmt = $pdo->prepare("UPDATE trips SET passenger_approval = ?, passenger_feedback = ? WHERE passenger_id = ? AND passenger_approval = 'pending' AND status = 'completed' AND id IN ($in_clause)");
                $stmt->execute($params);
                $action_success = true;
            } else {
                $pin_error = $lang === 'en' ? 'No trips selected.' : 'Tidak ada perjalanan yang terpilih.';
            }
        } elseif ($action === 'single_approve' || $action === 'single_reject') {
            $single_id = $_POST['single_trip_id'] ?? 0;
            if ($single_id > 0) {
                $stmt = $pdo->prepare("UPDATE trips SET passenger_approval = ?, passenger_feedback = ? WHERE id = ? AND passenger_id = ? AND passenger_approval = 'pending' AND status = 'completed'");
                $stmt->execute([$target_status, $feedback, $single_id, $trip['passenger_id']]);
                $action_success = true;
            }
        }
        
        if ($action_success) {
            // Re-fetch pending trips
            $stmt_pending = $pdo->prepare("SELECT t.*, d.name as dest_name, u.full_name as driver_name,
                                                  car.car_no, car.model as car_model
                                           FROM trips t 
                                           JOIN master_destinations d ON t.destination_id = d.id 
                                           JOIN shifts s ON t.shift_id = s.id 
                                           JOIN users u ON s.driver_id = u.id 
                                           LEFT JOIN master_cars car ON t.car_id = car.id
                                           WHERE t.passenger_id = ? AND t.passenger_approval = 'pending' AND t.status = 'completed'
                                           ORDER BY t.end_time DESC");
            $stmt_pending->execute([$trip['passenger_id']]);
            $pending_trips = $stmt_pending->fetchAll();
            
            if (empty($pending_trips)) {
                $success = true;
                $pin_required = false;
            } else {
                $success_message = $t['success_msg'];
                // Update active trip state so we don't show the approved one
                $active_still_pending = false;
                foreach ($pending_trips as $pt) {
                    if ($pt['id'] == $trip['id']) {
                        $active_still_pending = true;
                        $trip = array_merge($trip, $pt);
                        break;
                    }
                }
                if (!$active_still_pending) {
                    $trip = array_merge($trip, $pending_trips[0]);
                }
            }
        }
    }
}

// Fetch expenses for pending trips
$expenses_map = [];
if (!empty($pending_trips)) {
    $pending_ids = array_column($pending_trips, 'id');
    $in_clause = implode(',', array_fill(0, count($pending_ids), '?'));
    $stmt_expenses = $pdo->prepare("SELECT * FROM trip_expenses WHERE trip_id IN ($in_clause) ORDER BY id ASC");
    $stmt_expenses->execute($pending_ids);
    $all_expenses = $stmt_expenses->fetchAll();
    foreach ($all_expenses as $exp) {
        $expenses_map[$exp['trip_id']][] = $exp;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trip Approval - <?= htmlspecialchars(__('app_name')) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-light: #eff6ff;
            --success: #16a34a;
            --success-hover: #15803d;
            --success-light: #f0fdf4;
            --danger: #dc2626;
            --danger-hover: #b91c1c;
            --danger-light: #fef2f2;
            --background: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: var(--background);
            color: var(--text-main);
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 16px;
        }

        .container {
            width: 100%;
            max-width: 500px;
            background: var(--card-bg);
            border-radius: 20px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .header {
            padding: 24px 24px 16px;
            position: relative;
            border-bottom: 1px solid var(--border);
        }

        .lang-switch {
            position: absolute;
            top: 24px;
            right: 24px;
            display: flex;
            gap: 8px;
        }

        .lang-btn {
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 6px;
            transition: all 0.2s;
            border: 1px solid transparent;
        }

        .lang-btn.active {
            background-color: var(--primary-light);
            color: var(--primary);
            border-color: rgba(37, 99, 235, 0.2);
        }

        .lang-btn:not(.active) {
            color: var(--text-muted);
        }

        .lang-btn:not(.active):hover {
            background-color: var(--background);
        }

        .avatar-circle {
            width: 60px;
            height: 60px;
            background: var(--primary-light);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 16px;
        }

        .title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 6px;
        }

        .subtitle {
            font-size: 0.9rem;
            color: var(--text-muted);
            line-height: 1.4;
        }

        .content {
            padding: 24px;
        }

        .alert-banner {
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 16px;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }

        .alert-banner.success {
            background-color: var(--success-light);
            color: var(--success);
            border: 1px solid rgba(22, 163, 74, 0.1);
        }

        .alert-banner.error {
            background-color: var(--danger-light);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, 0.1);
        }

        /* Compact Trip Card Style */
        .trip-list-title {
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 12px;
        }

        .trip-card {
            border: 1px solid var(--border);
            border-radius: 12px;
            margin-bottom: 12px;
            overflow: hidden;
            transition: all 0.2s ease;
            background: #fff;
        }

        .trip-card.active-token {
            border-color: var(--primary);
            box-shadow: 0 0 0 1px var(--primary);
        }

        .trip-card-header {
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--background);
            cursor: pointer;
            user-select: none;
        }

        .trip-card-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }

        .trip-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary);
        }

        .trip-summary-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .trip-summary-dest {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-main);
        }

        .trip-summary-meta {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .badge {
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-active {
            background-color: var(--primary-light);
            color: var(--primary);
        }

        .badge-pending {
            background-color: #f1f5f9;
            color: #475569;
        }

        .chevron-icon {
            font-size: 0.8rem;
            color: var(--text-muted);
            transition: transform 0.2s ease;
        }

        .trip-card.expanded .chevron-icon {
            transform: rotate(180deg);
        }

        .trip-card-body {
            display: none;
            padding: 16px;
            border-top: 1px solid var(--border);
            background: #fff;
            font-size: 0.85rem;
        }

        .trip-card.expanded .trip-card-body {
            display: block;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--text-muted);
            font-weight: 500;
        }

        .detail-value {
            font-weight: 600;
            color: var(--text-main);
            text-align: right;
        }

        /* Cost Breakdown Table */
        .cost-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            background: var(--background);
            border-radius: 8px;
            overflow: hidden;
        }

        .cost-table th, .cost-table td {
            padding: 8px 12px;
            font-size: 0.8rem;
            text-align: left;
        }

        .cost-table th {
            background: #edf2f7;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
        }

        .cost-table tr {
            border-bottom: 1px solid var(--border);
        }

        .cost-table tr:last-child {
            border-bottom: none;
        }

        .photo-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .photo-link:hover {
            text-decoration: underline;
        }

        .btn-inline-action {
            display: flex;
            gap: 8px;
            margin-top: 16px;
            padding-top: 12px;
            border-top: 1px solid var(--border);
        }

        .btn-inline {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }

        .btn-inline-approve {
            background: var(--success);
            color: white;
            border-color: var(--success);
        }

        .btn-inline-approve:hover {
            background: var(--success-hover);
        }

        .btn-inline-reject {
            background: var(--danger-light);
            color: var(--danger);
            border-color: rgba(220, 38, 38, 0.1);
        }

        .btn-inline-reject:hover {
            background: var(--danger);
            color: white;
        }

        /* Footer PIN & Unified Form Section */
        .footer-form {
            background: #fff;
            border-top: 1px solid var(--border);
            padding: 24px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
        }

        .pin-input {
            text-align: center;
            letter-spacing: 0.5em;
            font-weight: 700;
        }

        .btn-group {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }

        @media (min-width: 400px) {
            .btn-group {
                grid-template-columns: 1fr 1fr;
            }
            .btn-full {
                grid-column: span 2;
            }
        }

        .btn-action {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }

        .btn-approve {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .btn-approve:hover {
            background-color: var(--primary-hover);
        }

        .btn-approve-all {
            background-color: var(--success);
            color: white;
            border-color: var(--success);
        }

        .btn-approve-all:hover {
            background-color: var(--success-hover);
        }

        .btn-reject {
            background-color: var(--danger-light);
            color: var(--danger);
            border-color: rgba(220, 38, 38, 0.1);
        }

        .btn-reject:hover {
            background-color: var(--danger);
            color: white;
        }

        /* Lightbox Modal */
        #lightbox-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(4px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 24px;
        }

        .modal-content {
            background: #fff;
            border-radius: 16px;
            width: 100%;
            max-width: 450px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: relative;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        .modal-header {
            padding: 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--text-main);
        }

        .close-btn {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            border: none;
            background: none;
            line-height: 1;
        }

        .modal-body {
            padding: 16px;
            background: #000;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 250px;
        }

        .modal-body img {
            max-width: 100%;
            max-height: 60vh;
            object-fit: contain;
            border-radius: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Language Switcher & General Info -->
        <div class="header">
            <div class="lang-switch">
                <a href="?token=<?= urlencode($token) ?>&lang=en" class="lang-btn <?= $lang === 'en' ? 'active' : '' ?>">EN</a>
                <a href="?token=<?= urlencode($token) ?>&lang=id" class="lang-btn <?= $lang === 'id' ? 'active' : '' ?>">ID</a>
            </div>

            <div class="avatar-circle">🚗</div>

            <?php if (!$trip): ?>
                <h3 class="title"><?= htmlspecialchars($t['invalid_token_title']) ?></h3>
                <p class="subtitle"><?= htmlspecialchars($t['invalid_token_desc']) ?></p>
            <?php elseif ($expired): ?>
                <h3 class="title"><?= htmlspecialchars($t['expired_title']) ?></h3>
                <p class="subtitle"><?= htmlspecialchars($t['expired_desc']) ?></p>
            <?php elseif ($success): ?>
                <div class="avatar-circle" style="background: var(--success-light); color: var(--success)">✅</div>
                <h3 class="title"><?= htmlspecialchars($t['thank_you_title']) ?></h3>
                <p class="subtitle"><?= htmlspecialchars($t['thank_you_desc']) ?></p>
                <div style="margin-top: 24px;">
                    <button onclick="window.close()" class="btn-action btn-approve" style="width: 100%;"><?= htmlspecialchars($t['close_btn']) ?></button>
                </div>
            <?php else: ?>
                <h3 class="title"><?= htmlspecialchars($t['confirm_title']) ?></h3>
                <p class="subtitle"><?= sprintf($t['confirm_desc'], '<strong>' . htmlspecialchars($trip['pass_name']) . '</strong>') ?></p>
            <?php endif; ?>
        </div>

        <?php if ($trip && !$expired && !$success): ?>
            <div class="content">
                <?php if ($success_message): ?>
                    <div class="alert-banner success">
                        <span>✅</span> <?= htmlspecialchars($success_message) ?>
                    </div>
                <?php endif; ?>

                <?php if ($pin_error): ?>
                    <div class="alert-banner error">
                        <span>⚠️</span> <?= htmlspecialchars($pin_error) ?>
                    </div>
                <?php endif; ?>

                <div class="trip-list-title">
                    <?= htmlspecialchars($t['trip_details']) ?> (<?= count($pending_trips) ?>)
                </div>

                <form id="approval-form" method="POST">
                    <!-- Target action parameters -->
                    <input type="hidden" name="action" id="form-action" value="approve_selected">
                    <input type="hidden" name="single_trip_id" id="form-single-trip-id" value="">

                    <?php foreach ($pending_trips as $index => $pt): 
                        $is_current_token = ($pt['id'] == $trip['id']);
                        $pt_expenses = $expenses_map[$pt['id']] ?? [];
                        $total_expenses = array_sum(array_column($pt_expenses, 'amount'));
                        $distance = ($pt['km_end'] !== null && $pt['km_start'] !== null) ? ($pt['km_end'] - $pt['km_start']) : null;
                    ?>
                        <div class="trip-card <?= $is_current_token ? 'active-token' : '' ?>" id="trip-card-<?= $pt['id'] ?>">
                            <!-- Card Header (Toggle Panel) -->
                            <div class="trip-card-header" onclick="toggleCard(<?= $pt['id'] ?>)">
                                <div class="trip-card-header-left">
                                    <input type="checkbox" name="trip_ids[]" value="<?= $pt['id'] ?>" class="trip-checkbox" checked onclick="event.stopPropagation(); updateSelectedCount();">
                                    <div class="trip-summary-info">
                                        <div class="trip-summary-dest"><?= htmlspecialchars($pt['dest_name']) ?></div>
                                        <div class="trip-summary-meta">
                                            <?= date('d M Y • H:i', strtotime($pt['end_time'] ?? $pt['start_time'])) ?>
                                        </div>
                                    </div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <?php if ($is_current_token): ?>
                                        <span class="badge badge-active"><?= htmlspecialchars($t['active_badge']) ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-pending"><?= htmlspecialchars($t['pending_badge']) ?></span>
                                    <?php endif; ?>
                                    <span class="chevron-icon">▼</span>
                                </div>
                            </div>

                            <!-- Expanded Card Body -->
                            <div class="trip-card-body">
                                <div class="detail-row">
                                    <span class="detail-label"><?= htmlspecialchars($t['driver_lbl']) ?></span>
                                    <span class="detail-value"><?= htmlspecialchars($pt['driver_name']) ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label"><?= htmlspecialchars($t['dest_lbl']) ?></span>
                                    <span class="detail-value"><?= htmlspecialchars($pt['dest_name']) ?></span>
                                </div>
                                <?php if (!empty($pt['car_model'])): ?>
                                    <div class="detail-row">
                                        <span class="detail-label"><?= htmlspecialchars($t['car_lbl']) ?></span>
                                        <span class="detail-value"><?= htmlspecialchars($pt['car_model']) ?> (<?= htmlspecialchars($pt['car_no']) ?>)</span>
                                    </div>
                                <?php endif; ?>
                                <div class="detail-row">
                                    <span class="detail-label"><?= htmlspecialchars($t['time_lbl']) ?></span>
                                    <span class="detail-value">
                                        <?= date('H:i', strtotime($pt['start_time'])) ?> - <?= date('H:i', strtotime($pt['end_time'])) ?>
                                    </span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label"><?= htmlspecialchars($t['odo_lbl']) ?></span>
                                    <span class="detail-value">
                                        <?= number_format($pt['km_start']) ?> - <?= number_format($pt['km_end']) ?> KM
                                        <br>
                                        <?php if (!empty($pt['km_start_photo'])): ?>
                                            <a href="javascript:void(0)" class="photo-link" onclick="openPhoto('uploads/<?= htmlspecialchars($pt['km_start_photo']) ?>', 'Odometer Start')">📸 Start</a>
                                        <?php endif; ?>
                                        <?php if (!empty($pt['km_end_photo'])): ?>
                                             &bull; <a href="javascript:void(0)" class="photo-link" onclick="openPhoto('uploads/<?= htmlspecialchars($pt['km_end_photo']) ?>', 'Odometer End')">📸 End</a>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php if ($distance !== null): ?>
                                    <div class="detail-row">
                                        <span class="detail-label"><?= htmlspecialchars($t['dist_lbl']) ?></span>
                                        <span class="detail-value"><?= number_format($distance, 1) ?> KM</span>
                                    </div>
                                <?php endif; ?>

                                <!-- Google Maps link -->
                                <?php if (!empty($pt['start_lat']) && !empty($pt['end_lat'])): ?>
                                    <div class="detail-row">
                                        <span class="detail-label">Google Maps Route</span>
                                        <span class="detail-value">
                                            <a href="https://www.google.com/maps/dir/?api=1&origin=<?= $pt['start_lat'] ?>,<?= $pt['start_lng'] ?>&destination=<?= $pt['end_lat'] ?>,<?= $pt['end_lng'] ?>" target="_blank" class="photo-link" style="color: var(--success);">🗺️ <?= htmlspecialchars($t['view_map']) ?></a>
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <!-- Cost Breakdown / Expenses -->
                                <?php if (!empty($pt_expenses)): ?>
                                    <div style="margin-top: 12px;">
                                        <span class="detail-label" style="display:block; margin-bottom: 6px; font-weight: 600;"><?= htmlspecialchars($t['exp_lbl']) ?></span>
                                        <table class="cost-table">
                                            <thead>
                                                <tr>
                                                    <th>Type / Tipe</th>
                                                    <th>Amount / Nominal</th>
                                                    <th>Receipt / Foto</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($pt_expenses as $exp): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($t[$exp['expense_type']] ?? $exp['expense_type']) ?></td>
                                                        <td>Rp <?= number_format($exp['amount'], 0, ',', '.') ?></td>
                                                        <td>
                                                            <?php if (!empty($exp['photo'])): ?>
                                                                <a href="javascript:void(0)" class="photo-link" onclick="openPhoto('uploads/<?= htmlspecialchars($exp['photo']) ?>', '<?= htmlspecialchars($t[$exp['expense_type']] ?? $exp['expense_type']) ?> Receipt')">📸 View</a>
                                                            <?php else: ?>
                                                                -
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <tr style="font-weight: 700; background: #edf2f7;">
                                                    <td>TOTAL</td>
                                                    <td colspan="2">Rp <?= number_format($total_expenses, 0, ',', '.') ?></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>

                                <!-- Inline Individual action buttons -->
                                <div class="btn-inline-action">
                                    <button type="button" class="btn-inline btn-inline-reject" onclick="submitSingleTrip(<?= $pt['id'] ?>, 'single_reject')">
                                        <?= htmlspecialchars($t['reject_this']) ?>
                                    </button>
                                    <button type="button" class="btn-inline btn-inline-approve" onclick="submitSingleTrip(<?= $pt['id'] ?>, 'single_approve')">
                                        <?= htmlspecialchars($t['approve_this']) ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- PIN & Feedback Form Wrapper -->
                    <div class="footer-form">
                        <div class="form-group">
                            <label><?= htmlspecialchars($setup_pin ? $t['create_pin'] : $t['enter_pin']) ?></label>
                            <input type="password" name="pin" id="pin-field" class="form-control pin-input" placeholder="<?= htmlspecialchars($t['pin_placeholder']) ?>" pattern="\d{6}" maxlength="6" inputmode="numeric" required autocomplete="off">
                        </div>

                        <div class="form-group">
                            <label><?= htmlspecialchars($t['feedback_label']) ?></label>
                            <textarea name="feedback" class="form-control" placeholder="<?= htmlspecialchars($t['feedback_placeholder']) ?>" style="height: 60px; resize: none;"></textarea>
                        </div>

                        <div class="btn-group">
                            <button type="button" class="btn-action btn-reject" onclick="submitFormAction('reject_selected')">
                                <?= htmlspecialchars($t['reject_btn']) ?>
                            </button>
                            <button type="button" class="btn-action btn-approve" id="btn-approve-selected" onclick="submitFormAction('approve_selected')">
                                <?= htmlspecialchars($t['approve_btn']) ?>
                            </button>
                            <button type="button" class="btn-action btn-approve-all btn-full" onclick="submitFormAction('approve_all')">
                                <?= sprintf($t['approve_all_btn'], count($pending_trips)) ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>
        <div style="text-align: center; padding: 16px; border-top: 1px solid var(--border); background: var(--background); font-size: 0.8rem; color: var(--text-muted);">
            Need full access? Click here for the <a href="https://ops.framas.co.id/passenger_dashboard.php" target="_blank" style="color: var(--primary); font-weight: 700; text-decoration: underline;">Passenger Dashboard</a>.
        </div>
    </div>

    <!-- Lightbox Modal for Photo Previews -->
    <div id="lightbox-modal">
        <div class="modal-content">
            <div class="modal-header">
                <span id="modal-title" class="modal-title">Photo Preview</span>
                <button type="button" class="close-btn" onclick="closePhoto()">&times;</button>
            </div>
            <div class="modal-body">
                <img id="modal-img" src="" alt="Receipt or Odometer Proof">
            </div>
        </div>
    </div>

    <script>
        function toggleCard(id) {
            const card = document.getElementById('trip-card-' + id);
            card.classList.toggle('expanded');
        }

        // Keep active trip expanded on load
        window.addEventListener('DOMContentLoaded', () => {
            const activeCard = document.querySelector('.trip-card.active-token');
            if (activeCard) {
                activeCard.classList.add('expanded');
            }
            updateSelectedCount();
        });

        // Photo Lightbox modal controller
        function openPhoto(src, title) {
            document.getElementById('modal-img').src = src;
            document.getElementById('modal-title').textContent = title;
            document.getElementById('lightbox-modal').style.display = 'flex';
        }

        function closePhoto() {
            document.getElementById('lightbox-modal').style.display = 'none';
        }

        // Update selected trips count in the button labels dynamically
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.trip-checkbox:checked');
            const count = checkboxes.length;
            const approveBtn = document.getElementById('btn-approve-selected');
            if (approveBtn) {
                approveBtn.disabled = (count === 0);
            }
        }

        // Form submission helpers
        function submitFormAction(action) {
            const pinField = document.getElementById('pin-field');
            if (!pinField.value || pinField.value.length !== 6) {
                pinField.focus();
                alert("<?= htmlspecialchars($t['pin_length_err']) ?>");
                return;
            }
            
            document.getElementById('form-action').value = action;
            document.getElementById('approval-form').submit();
        }

        function submitSingleTrip(tripId, action) {
            const pinField = document.getElementById('pin-field');
            if (!pinField.value || pinField.value.length !== 6) {
                pinField.focus();
                alert("<?= htmlspecialchars($t['pin_length_err']) ?>");
                return;
            }

            document.getElementById('form-action').value = action;
            document.getElementById('form-single-trip-id').value = tripId;
            document.getElementById('approval-form').submit();
        }
    </script>
</body>
</html>

