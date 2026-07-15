<?php
require_once 'config.php';

// AJAX Forgot PIN Handler
if (isset($_POST['action']) && $_POST['action'] === 'forgot_pin') {
    // Disable error display to prevent warnings/notices from corrupting JSON
    ini_set('display_errors', 0);
    
    // Clear any previous output buffers to guarantee a clean JSON string
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();

    header('Content-Type: application/json');
    
    try {
        $passenger_id = $_POST['passenger_id'] ?? null;
        if (!$passenger_id) {
            echo json_encode(['success' => false, 'error' => 'Passenger ID is required.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM master_passengers WHERE id = ?");
        $stmt->execute([$passenger_id]);
        $passenger = $stmt->fetch();

        if (!$passenger) {
            echo json_encode(['success' => false, 'error' => 'Passenger not found.']);
            exit;
        }

        $wa = trim($passenger['wa_no'] ?? '');
        if (empty($wa)) {
            echo json_encode(['success' => false, 'error' => 'Nomor WhatsApp Anda belum terdaftar di sistem. Silakan hubungi Admin untuk memperbarui data.']);
            exit;
        }

        // Clean phone number format
        $wa = str_replace(['+', ' ', '-'], '', $wa);
        if (substr($wa, 0, 1) === '0') {
            $wa = '62' . substr($wa, 1);
        }

        // Generate secure token
        $raw_token = bin2hex(random_bytes(16));
        $hashed_token = hash('sha256', $raw_token);
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry

        // Save token to database
        $stmt_update = $pdo->prepare("UPDATE master_passengers SET reset_token = ?, reset_expires = ? WHERE id = ?");
        $stmt_update->execute([$hashed_token, $expires, $passenger_id]);

        // Build Reset URL
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $domainName = $_SERVER['HTTP_HOST'];
        $dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        $reset_url = $protocol . $domainName . $dir . "/passenger_login.php?reset_token=" . $raw_token;

        // Send via WhatsApp API
        $token = '989C172CB5B6C8F0983391A6945BC436';
        $message = "Hello *" . $passenger['name'] . "*,\n\nHere is the link to reset your security PIN:\n" . $reset_url . "\n\nThis link is only valid for *1 hour*. Do not share this link with anyone.";
        
        $curl = curl_init();
        curl_setopt_array($curl, array(
          CURLOPT_URL => 'https://app.fastwa.com/api/v1/8655C64C0C1B38982A7DA98BEDAB602D/send_text',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 15,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_POSTFIELDS => 'api_key=' . $token . '&phone=' . $wa . '&message=' . urlencode($message),
          CURLOPT_SSL_VERIFYPEER => false, // Bypass SSL validation checks for outdated curl setups
          CURLOPT_SSL_VERIFYHOST => false,
        ));
        $response = curl_exec($curl);
        curl_close($curl);

        echo json_encode(['success' => true]);
        exit;
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

$reset_passenger = null;
$reset_token_param = $_GET['reset_token'] ?? null;
$error = '';

if ($reset_token_param) {
    $hashed_param = hash('sha256', $reset_token_param);
    $stmt_reset = $pdo->prepare("SELECT * FROM master_passengers WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1");
    $stmt_reset->execute([$hashed_param]);
    $reset_passenger = $stmt_reset->fetch();
    if (!$reset_passenger) {
        $error = "Link reset PIN tidak valid atau telah kadaluarsa.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'reset_pin') {
        $passenger_id = $_POST['passenger_id'] ?? null;
        $pin = $_POST['pin'] ?? null;
        $token = $_POST['token'] ?? null;

        if ($passenger_id && $pin && $token) {
            $hashed_token = hash('sha256', $token);
            $stmt = $pdo->prepare("SELECT * FROM master_passengers WHERE id = ? AND reset_token = ? AND reset_expires > NOW()");
            $stmt->execute([$passenger_id, $hashed_token]);
            $passenger = $stmt->fetch();

            if ($passenger) {
                // Update PIN
                $hashed_pin = password_hash($pin, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE master_passengers SET pin = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?")
                    ->execute([$hashed_pin, $passenger_id]);
                
                // Set Session and Login
                $_SESSION['passenger_id'] = $passenger['id'];
                $_SESSION['passenger_name'] = $passenger['name'];
                header('Location: passenger_dashboard.php');
                exit;
            } else {
                $error = "Gagal meriset PIN. Token kadaluarsa atau tidak valid.";
            }
        }
    } else {
        // Standard Setup/Login
        $passenger_id = $_POST['passenger_id'] ?? null;
        $pin = $_POST['pin'] ?? null;

        if ($passenger_id && $pin) {
            $stmt = $pdo->prepare("SELECT * FROM master_passengers WHERE id = ?");
            $stmt->execute([$passenger_id]);
            $passenger = $stmt->fetch();

            if ($passenger) {
                if ($action === 'setup') {
                    if (empty($passenger['pin'])) {
                        $hashed_pin = password_hash($pin, PASSWORD_DEFAULT);
                        $pdo->prepare("UPDATE master_passengers SET pin = ? WHERE id = ?")->execute([$hashed_pin, $passenger_id]);
                        $_SESSION['passenger_id'] = $passenger['id'];
                        $_SESSION['passenger_name'] = $passenger['name'];
                        header('Location: passenger_dashboard.php');
                        exit;
                    }
                } else if ($action === 'login') {
                    if (password_verify($pin, $passenger['pin'])) {
                        $_SESSION['passenger_id'] = $passenger['id'];
                        $_SESSION['passenger_name'] = $passenger['name'];
                        header('Location: passenger_dashboard.php');
                        exit;
                    } else {
                        $error = "PIN yang dimasukkan salah!";
                    }
                }
            }
        }
    }
}

$passengers = $pdo->query("SELECT id, name, CASE WHEN pin IS NOT NULL AND pin != '' THEN 1 ELSE 0 END as has_pin FROM master_passengers ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="id" class="notranslate">
<head>
    <meta charset="UTF-8">
    <meta name="google" content="notranslate">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="icon.png">
    <title>Passenger Portal - <?= htmlspecialchars(__('app_name')) ?></title>
    
    <!-- PWA Setup -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#118DFF">
    <link rel="apple-touch-icon" href="icon-192.png">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('sw.js')
                    .then(reg => console.log('PWA Service Worker registered!', reg))
                    .catch(err => console.log('PWA Service Worker registration failed!', err));
            });
        }
    </script>

    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --login-bg: linear-gradient(135deg, rgba(15, 23, 42, 0.95) 0%, rgba(30, 41, 59, 0.98) 100%), url('corporate_banner.png');
            --card-bg: rgba(255, 255, 255, 0.94);
            --accent-color: #2563eb;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
        }
        body {
            background: var(--login-bg);
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
            margin: 0; padding: 20px;
            box-sizing: border-box;
        }
        .login-card {
            max-width: 420px; width: 100%;
            background: var(--card-bg);
            border-radius: 20px;
            padding: 44px 36px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            text-align: center;
            animation: fadeInUp 0.5s ease-out;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .logo-box {
            width: 80px; height: 80px;
            margin: 0 auto 24px auto;
            background: rgba(37, 99, 235, 0.08);
            border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            border: 1px solid rgba(37, 99, 235, 0.15);
        }
        .logo-icon { font-size: 2.2rem; }
        .app-title { font-size: 1.6rem; font-weight: 800; color: var(--text-main); margin-bottom: 6px; letter-spacing: -0.5px; font-family: 'Outfit', sans-serif; }
        .app-subtitle { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 36px; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 700; }
        
        .form-group { margin-bottom: 22px; text-align: left; position: relative; }
        .form-label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em; }
        .form-input {
            width: 100%; padding: 12px 16px;
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            color: var(--text-main);
            font-size: 0.95rem;
            box-sizing: border-box;
            transition: all 0.2s ease;
            font-family: inherit;
        }
        .form-input:focus { outline: none; border-color: var(--accent-color); box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12); background: #fff; }
        
        .login-btn {
            width: 100%; padding: 14px;
            background: var(--accent-color);
            color: #fff; border: none; border-radius: 10px;
            font-size: 0.95rem; font-weight: 700; cursor: pointer;
            box-shadow: 0 4px 6px rgba(37, 99, 235, 0.15);
            transition: all 0.2s;
            margin-top: 10px;
            font-family: inherit;
        }
        .login-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 12px rgba(37, 99, 235, 0.25); }
        .login-btn:active { transform: translateY(0); }
        
        /* Searchable Autocomplete List */
        .searchable-select { position: relative; }
        .dropdown-list {
            position: absolute; top: 100%; left: 0; right: 0;
            background: #fff; border-radius: 12px; margin-top: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            max-height: 200px; overflow-y: auto; z-index: 1000;
            display: none; border: 1px solid var(--border-color);
            padding: 6px 0;
        }
        .dropdown-item {
            padding: 12px 18px; cursor: pointer; color: var(--text-main); font-size: 0.92rem; text-align: left;
            transition: background 0.15s;
        }
        .dropdown-item:hover { background: rgba(37, 99, 235, 0.05); color: var(--accent-color); }
        
        .pin-container { display: none; margin-top: 24px; animation: fadeIn 0.4s ease; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
 
        .error-banner { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 12px 16px; border-radius: 10px; font-size: 0.85rem; margin-bottom: 24px; font-weight: 600; text-align: left; }
        
        .forgot-link {
            display: inline-block;
            margin-top: 12px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            transition: color 0.15s;
        }
        .forgot-link:hover { color: var(--accent-color); text-decoration: underline; }
 
        .footer-note { font-size: 0.8rem; color: var(--text-muted); margin-top: 32px; font-weight: 600; }
        .back-link { display: inline-block; color: var(--accent-color); text-decoration: none; font-weight: 700; transition: color 0.15s; }
        .back-link:hover { opacity: 0.85; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo-box">
            <span class="logo-icon">👤</span>
        </div>
        
        <?php if ($reset_passenger): ?>
            <div class="app-title">Reset Your PIN</div>
            <div class="app-subtitle">Set your new security PIN</div>
            
            <?php if ($error): ?>
                <div class="error-banner">⚠️ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="passenger_id" value="<?= $reset_passenger['id'] ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($reset_token_param) ?>">
                <input type="hidden" name="action" value="reset_pin">

                <div class="form-group">
                    <label class="form-label">Enter New PIN (4-digit number)</label>
                    <input type="password" name="pin" class="form-input" placeholder="****" pattern="\d{4}" maxlength="4" inputmode="numeric" required autofocus>
                </div>
                <button type="submit" class="login-btn">Save PIN & Login</button>
            </form>
            <div class="footer-note">
                <a href="passenger_login.php" class="back-link">&larr; Back to Login</a>
            </div>
        <?php else: ?>
            <div class="app-title">Passenger Portal</div>
            <div class="app-subtitle">Log in to view trips</div>

            <?php if ($error): ?>
                <div class="error-banner">⚠️ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" id="loginForm">
                <input type="hidden" name="passenger_id" id="passenger_id">
                <input type="hidden" name="action" id="form_action" value="login">

                <div class="form-group searchable-select">
                    <label class="form-label">Passenger Name</label>
                    <input type="text" class="form-input" id="searchInput" placeholder="Search your name..." autocomplete="off">
                    <div class="dropdown-list" id="dropdownList">
                        <?php foreach ($passengers as $p): ?>
                            <div class="dropdown-item" data-id="<?= $p['id'] ?>" data-haspin="<?= $p['has_pin'] ?>"><?= htmlspecialchars($p['name']) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="pin-container" id="pinContainer">
                    <div class="form-group">
                        <label class="form-label" id="pinLabel">Security PIN</label>
                        <input type="password" name="pin" id="pinInput" class="form-input" placeholder="****" pattern="\d{4}" maxlength="4" inputmode="numeric">
                        <div style="text-align: right;" id="forgotPinBox">
                            <a href="#" class="forgot-link" onclick="handleForgotPin(event)">Forgot PIN?</a>
                        </div>
                    </div>
                    <button type="submit" class="login-btn" id="submitBtn">Login</button>
                </div>
            </form>

            <div class="footer-note">
                Contact Admin / Driver if your name is not listed.<br><br>
                <a href="login.php" class="back-link">Admin / Driver Login &rarr;</a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const searchInput = document.getElementById('searchInput');
        const dropdownList = document.getElementById('dropdownList');
        if (dropdownList) {
            const items = dropdownList.querySelectorAll('.dropdown-item');
            const passIdInput = document.getElementById('passenger_id');
            const pinContainer = document.getElementById('pinContainer');
            const pinLabel = document.getElementById('pinLabel');
            const pinInput = document.getElementById('pinInput');
            const submitBtn = document.getElementById('submitBtn');
            const formAction = document.getElementById('form_action');
            const forgotPinBox = document.getElementById('forgotPinBox');

            searchInput.addEventListener('focus', () => {
                dropdownList.style.display = 'block';
            });

            searchInput.addEventListener('input', function() {
                const val = this.value.toLowerCase();
                let hasVisible = false;
                items.forEach(item => {
                    if (item.innerText.toLowerCase().includes(val)) {
                        item.style.display = 'block';
                        hasVisible = true;
                    } else {
                        item.style.display = 'none';
                    }
                });
                dropdownList.style.display = hasVisible ? 'block' : 'none';
            });

            document.addEventListener('click', (e) => {
                if (!searchInput.contains(e.target) && !dropdownList.contains(e.target)) {
                    dropdownList.style.display = 'none';
                }
            });

            items.forEach(item => {
                item.addEventListener('click', function() {
                    searchInput.value = this.innerText;
                    passIdInput.value = this.getAttribute('data-id');
                    const hasPin = this.getAttribute('data-haspin') === '1';
                    
                    dropdownList.style.display = 'none';
                    pinContainer.style.display = 'block';
                    pinInput.value = '';
                    pinInput.focus();

                    if (hasPin) {
                        pinLabel.innerText = "Enter your PIN";
                        pinInput.placeholder = "••••";
                        pinInput.maxLength = 6;
                        pinInput.pattern = "\\d{4,6}";
                        submitBtn.innerText = "Login";
                        formAction.value = "login";
                        forgotPinBox.style.display = 'block';
                    } else {
                        pinLabel.innerText = "Create New 4-digit Security PIN";
                        pinInput.placeholder = "****";
                        pinInput.maxLength = 4;
                        pinInput.pattern = "\\d{4}";
                        submitBtn.innerText = "Save PIN & Login";
                        formAction.value = "setup";
                        forgotPinBox.style.display = 'none';
                    }
                });
            });
        }

        async function handleForgotPin(e) {
            e.preventDefault();
            const passId = document.getElementById('passenger_id').value;
            if (!passId) return;

            Swal.fire({
                title: 'Send PIN Reset Link?',
                text: 'We will send a PIN reset link to your WhatsApp number.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#118DFF',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, Send',
                cancelButtonText: 'Cancel',
                showLoaderOnConfirm: true,
                preConfirm: async () => {
                    const formData = new FormData();
                    formData.append('action', 'forgot_pin');
                    formData.append('passenger_id', passId);

                    try {
                        const response = await fetch('passenger_login.php', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await response.json();
                        if (!data.success) {
                            throw new Error(data.error || 'Failed to send reset link.');
                        }
                        return data;
                    } catch (error) {
                        Swal.showValidationMessage(`Failed: ${error.message}`);
                    }
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Sent!',
                        text: 'The PIN reset link has been successfully sent to your WhatsApp number. Please check your inbox.',
                        icon: 'success',
                        confirmButtonColor: '#118DFF'
                    });
                }
            });
        }
    </script>
</body>
</html>
