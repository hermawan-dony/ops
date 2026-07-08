<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'driver') {
    exit('Unauthorized');
}

$driver_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

$mandatory_photo = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'mandatory_photo'")->fetchColumn();
if ($mandatory_photo === false) {
    $mandatory_photo = '1';
}

function resetPassengerApprovalIfApproved($pdo, $trip_id) {
    $stmt = $pdo->prepare("SELECT passenger_approval FROM trips WHERE id = ?");
    $stmt->execute([$trip_id]);
    $trip = $stmt->fetch();
    
    if ($trip && $trip['passenger_approval'] === 'approved') {
        $now = date('d-M-Y H:i:s');
        $feedback = "MODIFIED: " . $now;
        
        $update = $pdo->prepare("UPDATE trips SET passenger_approval = 'pending', passenger_feedback = ? WHERE id = ?");
        $update->execute([$feedback, $trip_id]);
    }
}

function sendWhatsAppNotification($pdo, $trip_id, $is_modification = false) {
    // Check if WhatsApp notification is enabled
    $wa_notify = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'wa_notify'")->fetchColumn();
    if ($wa_notify != '1') {
        return;
    }
    
    $stmt_trip = $pdo->prepare("SELECT t.*, d.name as dest, p.name as passenger_name, p.wa_no FROM trips t JOIN master_destinations d ON t.destination_id = d.id JOIN master_passengers p ON t.passenger_id = p.id WHERE t.id = ?");
    $stmt_trip->execute([$trip_id]);
    $trip = $stmt_trip->fetch();
    
    if ($trip && $trip['wa_no']) {
        if ($trip['passenger_name'] === '?') {
            return;
        }
        if ($trip['status'] === 'ongoing') {
            return;
        }
        // Generate/retrieve token
        $token = $trip['approval_token'];
        if (empty($token)) {
            $token = bin2hex(random_bytes(16));
            $pdo->prepare("UPDATE trips SET approval_token = ? WHERE id = ?")->execute([$token, $trip_id]);
        }
        
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'];
        $uri = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        $approval_url = $protocol . $host . $uri . "/approve_trip.php?token=" . $token;
        
        $stmt_cost = $pdo->prepare("SELECT SUM(amount) FROM trip_expenses WHERE trip_id = ?");
        $stmt_cost->execute([$trip_id]);
        $total_cost = $stmt_cost->fetchColumn() ?: 0;
        
        $header = $is_modification ? "⚠️ *REQUEST MODIFY TRIP*" : "✅ *TRIP ARRIVED*";
        
        $msg = "{$header}\nPassenger: {$trip['passenger_name']}\nDestination: {$trip['dest']}\nTime: " . date('H:i', strtotime($trip['start_time'])) . " - " . date('H:i', strtotime($trip['end_time'])) . "\nKM: {$trip['km_start']} -> {$trip['km_end']}\nTotal Cost: Rp " . number_format($total_cost, 0, ',', '.') . "\nStatus: " . ($is_modification ? "Modified" : "Arrived") . "\n\n*Passenger Approval Required:*\nPlease click link below to confirm your trip:\n" . $approval_url;
        
        $pdo->prepare("INSERT INTO outbox (wa_no, wa_text) VALUES (?, ?)")->execute([$trip['wa_no'], $msg]);
        
        // Direct FastWA Call
        $phone = str_replace('+', '', $trip['wa_no']);
        $phone = str_replace(' ', '', $phone);
        if (substr($phone, 0, 1) == '0') { 
            $phone = '62' . substr($phone, 1, 30); 
        }
        $fastwa_token = '989C172CB5B6C8F0983391A6945BC436';
        
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => 'https://app.fastwa.com/api/v1/8655C64C0C1B38982A7DA98BEDAB602D/send_text',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => 'api_key='.$fastwa_token.'&phone='.$phone.'&message='.urlencode($msg),
        ));
        curl_exec($ch);
        curl_close($ch);
    }
}

function uploadFile($fileField, $prefix = 'img_') {
    if (isset($_FILES[$fileField])) {
        if ($_FILES[$fileField]['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            
            $ext = 'jpg'; // We compress and save as JPG
            $filename = $prefix . uniqid() . '.' . $ext;
            $destPath = $upload_dir . $filename;
            $thumbFilename = 'thumb_' . $filename;
            $thumbDestPath = $upload_dir . $thumbFilename;
            
            require_once 'image_helper.php';
            $success = compressAndResizeImage($_FILES[$fileField]['tmp_name'], $destPath, $thumbDestPath);
            if ($success) {
                return $filename;
            } else {
                $_SESSION['flash_error'] = "Failed to process and compress the uploaded image.";
            }
        } else {
            $error_code = $_FILES[$fileField]['error'];
            if ($error_code !== UPLOAD_ERR_NO_FILE) {
                switch ($error_code) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $_SESSION['flash_error'] = "Image size is too large. Please select a smaller photo or lower your camera resolution.";
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $_SESSION['flash_error'] = "The file upload was interrupted. Please try again.";
                        break;
                    default:
                        $_SESSION['flash_error'] = "Image upload failed. Error code: " . $error_code;
                        break;
                }
            }
        }
    } else {
        $_SESSION['flash_error'] = "Missing upload field: " . $fileField;
    }
    return null;
}

try {
    if ($action === 'start_trip') {
        // Get active shift
        $stmt = $pdo->prepare("SELECT id FROM shifts WHERE driver_id = ? AND status = 'active'");
        $stmt->execute([$driver_id]);
        $shift = $stmt->fetch();
        
        if ($shift) {
            $dest_id = $_POST['destination_id'] ?? '';
            $passenger_id = $_POST['passenger_id'] ?? '';

            // Handle empty destination: find or create '?'
            if (empty($dest_id)) {
                $stmt_q = $pdo->prepare("SELECT id FROM master_destinations WHERE name = '?'");
                $stmt_q->execute();
                $dest_id = $stmt_q->fetchColumn();
                if (!$dest_id) {
                    $pdo->exec("INSERT INTO master_destinations (name) VALUES ('?')");
                    $dest_id = $pdo->lastInsertId();
                }
            } else if ($dest_id === 'NEW' && !empty($_POST['new_destination'])) {
                $stmt_new = $pdo->prepare("INSERT INTO master_destinations (name) VALUES (?)");
                $stmt_new->execute([$_POST['new_destination']]);
                $dest_id = $pdo->lastInsertId();
            }

            // Handle empty passenger: find or create '?'
            if (empty($passenger_id)) {
                $stmt_q = $pdo->prepare("SELECT id FROM master_passengers WHERE name = '?'");
                $stmt_q->execute();
                $passenger_id = $stmt_q->fetchColumn();
                if (!$passenger_id) {
                    $pdo->exec("INSERT INTO master_passengers (name) VALUES ('?')");
                    $passenger_id = $pdo->lastInsertId();
                }
            }

            // Verify dest_id exists
            $stmt_check = $pdo->prepare("SELECT id FROM master_destinations WHERE id = ?");
            $stmt_check->execute([$dest_id]);
            if (!$stmt_check->fetch()) {
                throw new Exception("Tujuan yang dipilih tidak terdaftar di sistem. Silakan pilih dari daftar.");
            }

            // Verify passenger_id exists
            $stmt_check = $pdo->prepare("SELECT id FROM master_passengers WHERE id = ?");
            $stmt_check->execute([$passenger_id]);
            if (!$stmt_check->fetch()) {
                throw new Exception("Penumpang yang dipilih tidak terdaftar di sistem. Silakan pilih dari daftar.");
            }

            $photo = '';
            if ($mandatory_photo === '1') {
                $photo = uploadFile('km_start_photo', 'km_start_');
            }
            if ($mandatory_photo !== '1' || $photo) {
                $stmt = $pdo->prepare("INSERT INTO trips (shift_id, destination_id, passenger_id, car_id, km_start, km_start_photo, start_lat, start_lng, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ongoing')");
                $stmt->execute([
                    $shift['id'], 
                    $dest_id, 
                    $passenger_id, 
                    $_POST['car_id'], 
                    $_POST['km_start'], 
                    $photo,
                    $_POST['start_lat'] ?? null,
                    $_POST['start_lng'] ?? null
                ]);
                $_SESSION['flash_success'] = ($_SESSION['lang'] ?? 'en') === 'id' ? "Perjalanan berhasil dimulai." : "Trip started successfully.";
            } else {
                if (!isset($_SESSION['flash_error'])) {
                    $_SESSION['flash_error'] = ($_SESSION['lang'] ?? 'en') === 'id' ? "Foto KM Awal wajib diunggah." : "KM Start Photo is required to start a trip.";
                }
            }
        } else {
            $_SESSION['flash_error'] = ($_SESSION['lang'] ?? 'en') === 'id' ? "Shift aktif tidak ditemukan. Silakan Clock In terlebih dahulu." : "No active shift found. Please Clock In first.";
        }
    } elseif ($action === 'edit_trip') {
        $trip_id = $_POST['trip_id'];
        $dest_id = $_POST['destination_id'] ?? '';
        $passenger_id = $_POST['passenger_id'] ?? '';

        if (empty($dest_id)) {
            throw new Exception("Tujuan perjalanan tidak boleh kosong. Silakan ketik dan pilih tujuan dari daftar.");
        }
        if (empty($passenger_id)) {
            throw new Exception("Penumpang tidak boleh kosong. Silakan ketik dan pilih penumpang dari daftar.");
        }

        if ($dest_id === 'NEW' && !empty($_POST['new_destination'])) {
            $stmt_new = $pdo->prepare("INSERT INTO master_destinations (name) VALUES (?)");
            $stmt_new->execute([$_POST['new_destination']]);
            $dest_id = $pdo->lastInsertId();
        }

        // Verify dest_id exists
        $stmt_check = $pdo->prepare("SELECT id FROM master_destinations WHERE id = ?");
        $stmt_check->execute([$dest_id]);
        if (!$stmt_check->fetch()) {
            throw new Exception("Tujuan yang dipilih tidak terdaftar di sistem. Silakan pilih dari daftar.");
        }

        // Verify passenger_id exists
        $stmt_check = $pdo->prepare("SELECT id FROM master_passengers WHERE id = ?");
        $stmt_check->execute([$passenger_id]);
        if (!$stmt_check->fetch()) {
            throw new Exception("Penumpang yang dipilih tidak terdaftar di sistem. Silakan pilih dari daftar.");
        }

        $photo = null;
        if ($mandatory_photo === '1' && isset($_FILES['km_start_photo']) && $_FILES['km_start_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $photo = uploadFile('km_start_photo', 'km_start_');
        }

        $km_end = isset($_POST['km_end']) && $_POST['km_end'] !== '' ? $_POST['km_end'] : null;
        if ($km_end !== null && $km_end < $_POST['km_start']) {
            throw new Exception("KM End tidak boleh lebih kecil dari KM Start.");
        }

        $stmt_current = $pdo->prepare("SELECT status, end_time FROM trips WHERE id = ?");
        $stmt_current->execute([$trip_id]);
        $current_trip = $stmt_current->fetch();
        
        $status_update = $current_trip['status'];
        $end_time_update = $current_trip['end_time'];
        
        if ($km_end !== null && $current_trip['status'] === 'ongoing') {
            $status_update = 'completed';
            $end_time_update = date('Y-m-d H:i:s');
        }

        if ($photo) {
            $stmt = $pdo->prepare("UPDATE trips SET destination_id = ?, passenger_id = ?, car_id = ?, km_start = ?, km_end = ?, km_start_photo = ?, status = ?, end_time = ? WHERE id = ?");
            $stmt->execute([
                $dest_id,
                $passenger_id,
                $_POST['car_id'],
                $_POST['km_start'],
                $km_end,
                $photo,
                $status_update,
                $end_time_update,
                $trip_id
            ]);
        } else {
            $stmt = $pdo->prepare("UPDATE trips SET destination_id = ?, passenger_id = ?, car_id = ?, km_start = ?, km_end = ?, status = ?, end_time = ? WHERE id = ?");
            $stmt->execute([
                $dest_id,
                $passenger_id,
                $_POST['car_id'],
                $_POST['km_start'],
                $km_end,
                $status_update,
                $end_time_update,
                $trip_id
            ]);
        }
        resetPassengerApprovalIfApproved($pdo, $trip_id);
        $_SESSION['flash_success'] = ($_SESSION['lang'] ?? 'en') === 'id' ? "Detail perjalanan berhasil diperbarui." : "Trip details updated successfully.";
    } elseif ($action === 'add_expense') {
        $trip_id = $_POST['trip_id'];
        $type = $_POST['expense_type'];
        $amount = $_POST['amount'];
        $litre = $_POST['litre'] ?? null;
        $photo = '';
        if ($mandatory_photo === '1') {
            $photo = uploadFile('photo', 'expense_');
        }
        
        if ($mandatory_photo !== '1' || $photo) {
            $stmt = $pdo->prepare("INSERT INTO trip_expenses (trip_id, expense_type, amount, litre, photo) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$trip_id, $type, $amount, $litre, $photo]);
            
            resetPassengerApprovalIfApproved($pdo, $trip_id);
            $_SESSION['flash_success'] = ($_SESSION['lang'] ?? 'en') === 'id' ? "Biaya berhasil disimpan." : "Expense added successfully.";
        } else {
            if (!isset($_SESSION['flash_error'])) {
                $_SESSION['flash_error'] = ($_SESSION['lang'] ?? 'en') === 'id' ? "Foto bukti pengeluaran wajib diunggah." : "Expense photo/receipt is required.";
            }
        }
    } elseif ($action === 'delete_expense') {
        $expense_id = $_POST['expense_id'];
        $trip_id = $_POST['trip_id'];
        
        // Delete expense image file
        $stmt_file = $pdo->prepare("SELECT photo FROM trip_expenses WHERE id = ? AND trip_id = ?");
        $stmt_file->execute([$expense_id, $trip_id]);
        $exp = $stmt_file->fetch();
        if ($exp && $exp['photo']) {
            @unlink('uploads/' . $exp['photo']);
            @unlink('uploads/thumb_' . $exp['photo']);
        }
        
        $stmt = $pdo->prepare("DELETE FROM trip_expenses WHERE id = ? AND trip_id = ?");
        $stmt->execute([$expense_id, $trip_id]);
        
        resetPassengerApprovalIfApproved($pdo, $trip_id);
        $_SESSION['flash_success'] = ($_SESSION['lang'] ?? 'en') === 'id' ? "Biaya berhasil dihapus." : "Expense deleted successfully.";
    } elseif ($action === 'cancel_trip') {
        $trip_id = $_POST['trip_id'];
        
        // Check if there are any expenses recorded for this trip
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM trip_expenses WHERE trip_id = ?");
        $stmt_check->execute([$trip_id]);
        $has_expenses = $stmt_check->fetchColumn() > 0;
        
        if ($has_expenses) {
            throw new Exception("Perjalanan tidak dapat dibatalkan karena sudah ada biaya yang dicatat.");
        }
        
        // Fetch trip info to delete start odometer photo
        $stmt_trip = $pdo->prepare("SELECT km_start_photo FROM trips WHERE id = ? AND status = 'ongoing'");
        $stmt_trip->execute([$trip_id]);
        $trip = $stmt_trip->fetch();
        
        if ($trip) {
            if ($trip['km_start_photo']) {
                @unlink('uploads/' . $trip['km_start_photo']);
                @unlink('uploads/thumb_' . $trip['km_start_photo']);
            }
            
            // Delete trip
            $stmt_delete = $pdo->prepare("DELETE FROM trips WHERE id = ?");
            $stmt_delete->execute([$trip_id]);
            $_SESSION['flash_success'] = ($_SESSION['lang'] ?? 'en') === 'id' ? "Perjalanan berhasil dibatalkan dan dihapus." : "Trip successfully cancelled and deleted.";
        } else {
            throw new Exception(($_SESSION['lang'] ?? 'en') === 'id' ? "Perjalanan tidak ditemukan atau sudah selesai." : "Trip not found or already completed.");
        }
    } elseif ($action === 'end_trip') {
        $trip_id = $_POST['trip_id'];
        
        // Validation: KM end must be >= KM start
        $stmt_check = $pdo->prepare("SELECT km_start FROM trips WHERE id = ?");
        $stmt_check->execute([$trip_id]);
        $trip_data = $stmt_check->fetch();
        if ($trip_data && $_POST['km_end'] < $trip_data['km_start']) {
            $_SESSION['flash_error'] = "KM End (" . $_POST['km_end'] . ") cannot be less than KM Start (" . $trip_data['km_start'] . ").";
            if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $_SESSION['flash_error']]);
                unset($_SESSION['flash_error']);
                exit;
            }
            header('Location: index.php');
            exit;
        }

        $photo = '';
        if ($mandatory_photo === '1') {
            $photo = uploadFile('km_end_photo', 'km_end_');
        }
        if ($mandatory_photo !== '1' || $photo) {
            $stmt = $pdo->prepare("UPDATE trips SET km_end = ?, km_end_photo = ?, end_lat = ?, end_lng = ?, end_time = CURRENT_TIMESTAMP, status = 'completed' WHERE id = ?");
            $stmt->execute([
                $_POST['km_end'], 
                $photo, 
                $_POST['end_lat'] ?? null,
                $_POST['end_lng'] ?? null,
                $trip_id
            ]);

            // Generate token
            $token = bin2hex(random_bytes(16));
            $pdo->prepare("UPDATE trips SET approval_token = ? WHERE id = ?")->execute([$token, $trip_id]);
            $msg = ($_SESSION['lang'] ?? 'en') === 'id' 
                ? "Perjalanan berhasil diselesaikan. Menunggu konfirmasi penumpang." 
                : "Trip finished successfully. Waiting for passenger confirmation.";
            $_SESSION['flash_success'] = $msg;

            if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $_SESSION['flash_success']]);
                unset($_SESSION['flash_success']);
                exit;
            }
            // Redirect to dashboard (instead of show_qr.php)
            header("Location: index.php");
            exit;
        } else {
            if (!isset($_SESSION['flash_error'])) {
                $_SESSION['flash_error'] = ($_SESSION['lang'] ?? 'en') === 'id' ? "Foto KM Akhir wajib diunggah untuk menyelesaikan perjalanan." : "KM End Photo is required to end the trip.";
            }
        }
    } elseif ($action === 'send_wa_request') {
        $trip_id = $_POST['trip_id'];
        sendWhatsAppNotification($pdo, $trip_id, false);
        $_SESSION['flash_success'] = ($_SESSION['lang'] ?? 'en') === 'id' 
            ? "Request persetujuan telah dikirim ke WhatsApp penumpang." 
            : "Approval request has been sent to the passenger's WhatsApp.";
    } elseif ($action === 'delete_trip') {
        $trip_id = $_POST['trip_id'];
        
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM trip_expenses WHERE trip_id = ?");
        $stmt_check->execute([$trip_id]);
        if ($stmt_check->fetchColumn() > 0) {
            throw new Exception("Transaksi tidak dapat dihapus karena memiliki rincian biaya.");
        }
        
        $stmt_trip = $pdo->prepare("SELECT t.id, t.km_start_photo, t.km_end_photo, d.name as dest_name 
                                    FROM trips t 
                                    LEFT JOIN master_destinations d ON t.destination_id = d.id 
                                    WHERE t.id = ? AND t.passenger_approval = 'pending'");
        $stmt_trip->execute([$trip_id]);
        $trip = $stmt_trip->fetch();
        
        if ($trip) {
            if ($trip['dest_name'] !== '?' && !empty($trip['dest_name'])) {
                throw new Exception("Hanya transaksi dengan tujuan kosong ('?') yang dapat dihapus.");
            }
            
            if ($trip['km_start_photo']) {
                @unlink('uploads/' . $trip['km_start_photo']);
                @unlink('uploads/thumb_' . $trip['km_start_photo']);
            }
            if ($trip['km_end_photo']) {
                @unlink('uploads/' . $trip['km_end_photo']);
                @unlink('uploads/thumb_' . $trip['km_end_photo']);
            }
            
            $stmt_delete = $pdo->prepare("DELETE FROM trips WHERE id = ?");
            $stmt_delete->execute([$trip_id]);
            
            $_SESSION['flash_success'] = ($_SESSION['lang'] ?? 'en') === 'id' 
                ? "Transaksi berhasil dihapus secara permanen." 
                : "Transaction successfully deleted permanently.";
        } else {
            throw new Exception("Transaksi tidak ditemukan atau tidak memenuhi syarat untuk dihapus.");
        }
    }
} catch (Exception $e) {
    $_SESSION['flash_error'] = "Error: " . $e->getMessage();
}

if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    header('Content-Type: application/json');
    if (isset($_SESSION['flash_error'])) {
        $err = $_SESSION['flash_error'];
        unset($_SESSION['flash_error']);
        echo json_encode(['success' => false, 'error' => $err]);
    } else {
        $msg = $_SESSION['flash_success'] ?? 'Success';
        unset($_SESSION['flash_success']);
        echo json_encode(['success' => true, 'message' => $msg]);
    }
    exit;
}

header('Location: index.php');
exit;
?>
