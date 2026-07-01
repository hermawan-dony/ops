<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['new_password'])) {
    $user_id = $_SESSION['user_id'];
    $hashed_pass = password_hash($_POST['new_password'], PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashed_pass, $user_id]);
        
        header('Location: index.php?msg=Password updated successfully');
        exit;
    } catch (PDOException $e) {
        header('Location: index.php?msg=Error updating password');
        exit;
    }
}

header('Location: index.php');
exit;
?>
