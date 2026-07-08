<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

$user_id = $_SESSION['user_id'];
if ($_SESSION['role'] === 'admin' && isset($_POST['user_id_override'])) {
    $user_id = $_POST['user_id_override'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['profile_photo'])) {
        if ($_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            
            $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png'];
            
            if (in_array($ext, $allowed)) {
                $filename = 'profile_' . $user_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $upload_dir . $filename)) {
                    $stmt = $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
                    $stmt->execute([$filename, $user_id]);
                }
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'update_wa') {
        $wa_no = trim($_POST['wa_no'] ?? '');
        if (preg_match('/^628[0-9]{8,15}$/', $wa_no)) {
            $stmt = $pdo->prepare("UPDATE users SET wa_no = ? WHERE id = ?");
            $stmt->execute([$wa_no, $user_id]);
            $_SESSION['flash_success'] = 'Berhasil memperbarui nomor WhatsApp.';
        } else {
            $_SESSION['flash_error'] = 'Format nomor WA harus sesuai contoh : 6285678910112';
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'update_supervisor') {
        $supervisor_id = !empty($_POST['supervisor_id']) ? (int)$_POST['supervisor_id'] : null;
        $stmt = $pdo->prepare("UPDATE users SET supervisor_id = ? WHERE id = ?");
        $stmt->execute([$supervisor_id, $user_id]);
        $_SESSION['flash_success'] = $_SESSION['lang'] === 'id' ? 'Berhasil memperbarui Atasan (Supervisor).' : 'Supervisor updated successfully.';
    }
}

header('Location: ' . ($_SESSION['role'] === 'admin' ? 'master_data.php' : 'index.php'));
exit;
?>
