<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    exit('Unauthorized');
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'toggle_sidebar') {
    $_SESSION['sidebar_collapsed'] = !($_SESSION['sidebar_collapsed'] ?? false);
    exit;
}

try {
    if ($action === 'delete_expense') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("SELECT photo FROM trip_expenses WHERE id = ?");
        $stmt->execute([$id]);
        $exp = $stmt->fetch();
        if ($exp && $exp['photo']) {
            $file_path = 'uploads/' . $exp['photo'];
            if (file_exists($file_path)) unlink($file_path);
        }
        $stmt = $pdo->prepare("DELETE FROM trip_expenses WHERE id = ?");
        $stmt->execute([$id]);
    } elseif ($action === 'approve_shift') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("UPDATE shifts SET approval_status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$_SESSION['user_id'], $id]);
    } elseif ($action === 'reject_shift') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("UPDATE shifts SET approval_status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$_SESSION['user_id'], $id]);
    }
} catch (Exception $e) {
    // Log error
}

header('Location: admin.php');
exit;
?>
