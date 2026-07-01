<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shift_id = $_POST['shift_id'] ?? null;
    $action = $_POST['action'] ?? null;
    $admin_id = $_SESSION['user_id'];

    if ($shift_id && in_array($action, ['approve', 'reject'])) {
        $status = ($action === 'approve') ? 'approved' : 'rejected';

        try {
            if ($action === 'approve') {
                $ot_type = $_POST['ot_type'] ?? 'R';
                $real_ot = floatval($_POST['real_ot'] ?? 0);
                
                $conv_ot = 0;
                if ($real_ot > 0) {
                    $stmt_ot = $pdo->prepare("SELECT conv_ot FROM twotcon WHERE tipe = ? AND real_ot = ?");
                    $stmt_ot->execute([$ot_type, $real_ot]);
                    $res_ot = $stmt_ot->fetchColumn();
                    if ($res_ot !== false) {
                        $conv_ot = $res_ot;
                    }
                }
                
                $stmt = $pdo->prepare("UPDATE shifts SET approval_status = ?, approved_by = ?, approved_at = NOW(), ot_type = ?, real_ot = ?, conv_ot = ? WHERE id = ?");
                $stmt->execute([$status, $admin_id, $ot_type, $real_ot, $conv_ot, $shift_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE shifts SET approval_status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
                $stmt->execute([$status, $admin_id, $shift_id]);
            }
            
            header("Location: admin.php?msg=Shift " . ucfirst($action) . " Successfully.");
            exit;
        } catch (\PDOException $e) {
            header('Location: admin.php?msg=Database error: ' . urlencode($e->getMessage()));
            exit;
        }
    }
}

header('Location: admin.php');
exit;
?>
