<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    
    if (strlen($new_password) >= 4) {
        try {
            $hashed_pass = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_pass, $_SESSION['user_id']]);
            $message = "Password administrator berhasil diperbarui!";
        } catch (Exception $e) {
            $error = "Gagal mengubah password.";
        }
    } else {
        $error = "Password terlalu pendek (minimal 4 karakter).";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Ganti Password Admin</title>
    <link rel="icon" type="image/png" href="icon.png">
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f8fafc; margin: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; color: #1e293b; }
        .card { background: #fff; padding: 40px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); width: 100%; max-width: 400px; text-align: center; border: 1px solid #e2e8f0; }
        .title { color: #0f172a; font-size: 1.5rem; margin-top: 0; margin-bottom: 20px; }
        .alert-success { background: #dcfce7; color: #15803d; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: left; font-size: 0.9rem; }
        .alert-error { background: #fee2e2; color: #b91c1c; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: left; font-size: 0.9rem; }
        .form-group { text-align: left; margin-bottom: 20px; }
        .form-input { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; font-family: inherit; }
        .btn-primary { width: 100%; padding: 12px; background: #118DFF; color: #fff; border: none; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 1rem; transition: background 0.2s; }
        .btn-primary:hover { background: #0078d4; }
        .btn-back { display: block; margin-top: 20px; color: #64748b; text-decoration: none; font-size: 0.9rem; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <h2 class="title">🔑 Ganti Password Admin</h2>
        
        <?php if ($message): ?>
            <div class="alert-success">✅ <?= $message ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert-error">❌ <?= $error ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label style="display:block; margin-bottom:8px; font-weight:600; font-size:0.85rem;">Password Baru:</label>
                <input type="password" name="new_password" class="form-input" required placeholder="Masukkan password baru">
            </div>
            <button type="submit" class="btn-primary">Simpan Password</button>
        </form>
        
        <a href="admin.php" class="btn-back">&larr; Kembali ke Dashboard</a>
    </div>
</body>
</html>
