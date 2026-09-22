<?php
/**
 * Direct Deployment Tool for ops.framas.co.id
 *
 * Usage:
 *   go                   -> Deploy all git modified / added files
 *   go index.php         -> Deploy specific file(s)
 *   go --all             -> Deploy entire project (excluding ignores)
 *   go --check           -> Test connection to deploy receiver
 */

// Configuration
$config = [
    'endpoint' => 'https://ops.framas.co.id/deploy_receiver.php',
    'token'    => 'framas_ops_deploy_9f8e7d6c5b4a321',
    'ignored_extensions' => ['sql', 'srd', 'xlsx', 'tmp', 'log', 'bak'],
    'ignored_files' => [
        'deploy.php',
        'go.bat',
        'deploy_receiver.php', // Keep receiver separate unless explicitly deployed
        'challenge_solver.js',
        '.deploy_cookie',
        '.deploy_state.json',
        '.teams_notif.log',
        '.gitignore',
        '.gitattributes',
        'tes.srd',
        'd_pay_ops_import.srd',
        'pay_overtimed.xlsx'
    ],
    'ignored_dirs' => [
        '.git',
        '.deploy_backups',
        'node_modules',
        'vendor'
    ]
];

$baseDir = realpath(__DIR__);
$userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

// Helper print functions
function info($msg) { echo "\033[36m[INFO]\033[0m $msg\n"; }
function ok($msg) { echo "\033[32m[OK]\033[0m $msg\n"; }
function warn($msg) { echo "\033[33m[WARN]\033[0m $msg\n"; }
function err($msg) { echo "\033[31m[ERROR]\033[0m $msg\n"; }

echo "====================================================\n";
echo "       FRAMAS TRANSPORT APP - DIRECT DEPLOYER       \n";
echo "       Target: https://ops.framas.co.id             \n";
echo "====================================================\n\n";

// Parse CLI arguments
$args = array_slice($argv, 1);
$isCheck = in_array('--check', $args) || in_array('-c', $args) || in_array('--status', $args);
$isAll = in_array('--all', $args) || in_array('-a', $args);
$isForce = in_array('--force', $args) || in_array('-f', $args) || in_array('--git', $args);
$specificFiles = array_filter($args, fn($a) => !str_starts_with($a, '-'));

$stateFile = $baseDir . DIRECTORY_SEPARATOR . '.deploy_state.json';
$state = file_exists($stateFile) ? (json_decode(file_get_contents($stateFile), true) ?: []) : [];
$deployedTimestamps = $state['files'] ?? [];

function getClearanceCookie($forceRefresh = false) {
    global $baseDir;
    $cookieFile = $baseDir . DIRECTORY_SEPARATOR . '.deploy_cookie';
    if (!$forceRefresh && file_exists($cookieFile) && (time() - filemtime($cookieFile) < 3000)) {
        return trim(file_get_contents($cookieFile));
    }
    
    $solverScript = $baseDir . DIRECTORY_SEPARATOR . 'challenge_solver.js';
    if (file_exists($solverScript)) {
        exec('node ' . escapeshellarg($solverScript) . ' 2>&1', $out, $code);
        if ($code === 0 && file_exists($cookieFile)) {
            return trim(file_get_contents($cookieFile));
        }
    }
    return '';
}

// Test connection with automatic WAF bypass
function checkConnection($config) {
    global $userAgent;
    info("Testing connection to hosting...");
    $url = $config['endpoint'] . '?key=' . urlencode($config['token']);
    
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $cookie = getClearanceCookie($attempt > 1);
        $headers = [
            'User-Agent: ' . $userAgent
        ];
        if (!empty($cookie)) {
            $headers[] = 'Cookie: ' . $cookie;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        
        if ($curlErr) {
            err("Connection failed: $curlErr");
            return false;
        }

        if (strpos($resp, 'One moment, please') !== false || strpos($resp, 'wsidchk') !== false) {
            if ($attempt === 1) {
                info("WAF Anti-Bot challenge detected. Solving automatically...");
                continue;
            }
            err("WAF Anti-Bot challenge could not be resolved.");
            return false;
        }

        if ($httpCode === 404) {
            err("Receiver not found on hosting (HTTP 404)!");
            echo "\n> PERHATIAN: File deploy_receiver.php belum di-upload ke hosting.\n";
            echo "> Silakan upload file 'deploy_receiver.php' ke hosting ops.framas.co.id (folder root / public_html) SATU KALI saja.\n\n";
            return false;
        }
        
        if ($httpCode === 403) {
            err("Authentication failed (HTTP 403 Forbidden). Invalid secret token.");
            return false;
        }
        
        $json = json_decode($resp, true);
        if ($httpCode === 200 && ($json['status'] ?? '') === 'ok') {
            ok("Receiver is active on hosting! Server time: " . ($json['server_time'] ?? 'N/A') . " (PHP " . ($json['php_version'] ?? 'N/A') . ")");
            return true;
        }
        
        err("Unexpected response (HTTP $httpCode): " . substr($resp, 0, 200));
        return false;
    }
    return false;
}

if ($isCheck) {
    checkConnection($config);
    exit;
}

// First check connectivity
if (!checkConnection($config)) {
    exit(1);
}

// Gather files to deploy
$filesToDeploy = [];

if (!empty($specificFiles)) {
    // Specific files requested
    info("Deploying specific files...");
    foreach ($specificFiles as $f) {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $f);
        $fullPath = $baseDir . DIRECTORY_SEPARATOR . $path;
        if (!file_exists($fullPath)) {
            warn("File not found, skipping: $f");
            continue;
        }
        $rel = trim(str_replace($baseDir, '', $fullPath), DIRECTORY_SEPARATOR);
        $filesToDeploy[] = str_replace('\\', '/', $rel);
    }
} elseif ($isAll) {
    // Deploy all files
    info("Scanning all project files (--all mode)...");
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $item) {
        if ($item->isDir()) continue;
        $rel = trim(str_replace($baseDir, '', $item->getPathname()), DIRECTORY_SEPARATOR);
        $relNormalized = str_replace('\\', '/', $rel);
        
        // Check ignores
        $parts = explode('/', $relNormalized);
        $skip = false;
        foreach ($parts as $p) {
            if (in_array($p, $config['ignored_dirs'])) { $skip = true; break; }
        }
        if ($skip) continue;
        
        $ext = strtolower(pathinfo($relNormalized, PATHINFO_EXTENSION));
        if (in_array($ext, $config['ignored_extensions'])) continue;
        if (in_array(basename($relNormalized), $config['ignored_files'])) continue;
        
        $filesToDeploy[] = $relNormalized;
    }
} else {
    // Default: Deploy git modified and untracked code files
    info("Detecting modified files via git status...");
    exec('git status --porcelain', $output, $code);
    
    if ($code !== 0 || empty($output)) {
        info("No git changes detected, or git is not available.");
        exit(0);
    }
    
    foreach ($output as $line) {
        $status = substr($line, 0, 2);
        $file = trim(substr($line, 2));
        $file = str_replace(['"', "'"], '', $file);
        $fileNormalized = str_replace('\\', '/', $file);
        
        // Skip deleted files for now
        if (strpos($status, 'D') !== false) {
            continue;
        }
        
        // Handle renames R  orig -> new
        if (strpos($fileNormalized, ' -> ') !== false) {
            $parts = explode(' -> ', $fileNormalized);
            $fileNormalized = trim($parts[1]);
        }
        
        $ext = strtolower(pathinfo($fileNormalized, PATHINFO_EXTENSION));
        if (in_array($ext, $config['ignored_extensions'])) continue;
        if (in_array(basename($fileNormalized), $config['ignored_files'])) continue;
        
        $baseName = basename($fileNormalized);
        if (strpos($baseName, 'backup') !== false) continue;
        if (strpos($baseName, 'tes') === 0) continue;
        
        $parts = explode('/', $fileNormalized);
        $skip = false;
        foreach ($parts as $p) {
            if (in_array($p, $config['ignored_dirs'])) { $skip = true; break; }
        }
        if ($skip) continue;
        
        $fullPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fileNormalized);
        if (file_exists($fullPath)) {
            $mtime = filemtime($fullPath);
            $prevMtime = $deployedTimestamps[$fileNormalized] ?? 0;
            if ($isForce || ($mtime > $prevMtime)) {
                $filesToDeploy[] = $fileNormalized;
            }
        }
    }
}

$filesToDeploy = array_values(array_unique($filesToDeploy));

if (empty($filesToDeploy)) {
    ok("Tidak ada file yang baru diubah sejak deploy terakhir. Semua file di hosting sudah up to date!");
    echo "  (Gunakan 'go --force' jika ingin upload ulang seluruh file yang ada di git status)\n\n";
    exit(0);
}

echo "\nFiles to be uploaded (" . count($filesToDeploy) . " files):\n";
foreach ($filesToDeploy as $idx => $f) {
    $size = filesize($baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $f));
    echo "  [" . ($idx + 1) . "] $f (" . number_format($size) . " bytes)\n";
}
echo "\n";

// Package files into JSON payload
info("Packaging files...");
$payloadFiles = [];
foreach ($filesToDeploy as $f) {
    $fullPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $f);
    $payloadFiles[] = [
        'path' => $f,
        'content' => base64_encode(file_get_contents($fullPath))
    ];
}

$payload = [
    'token' => $config['token'],
    'files' => $payloadFiles
];

$jsonPayload = json_encode($payload);
info("Payload size: " . number_format(strlen($jsonPayload)) . " bytes. Uploading to production hosting...");

$cookie = getClearanceCookie();
$postHeaders = [
    'Content-Type: application/json',
    'X-Deploy-Token: ' . $config['token'],
    'User-Agent: ' . $userAgent
];
if (!empty($cookie)) {
    $postHeaders[] = 'Cookie: ' . $cookie;
}

// Send POST to receiver
$ch = curl_init($config['endpoint']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, $postHeaders);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

$startTime = microtime(true);
$response = curl_exec($ch);
$duration = round(microtime(true) - $startTime, 2);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    err("Upload failed: $curlErr");
    exit(1);
}

$resData = json_decode($response, true);

if ($httpCode === 200 && ($resData['status'] ?? '') === 'success') {
    echo "\n";
    ok("DEPLOY SUCCESSFUL! (" . $duration . "s)");
    ok("Total files updated: " . $resData['total_files']);
    if (!empty($resData['backup_session'])) {
        info("Previous version backed up on server: " . $resData['backup_session']);
    }
    echo "====================================================\n";
    echo "  Website https://ops.framas.co.id is updated!   \n";
    echo "====================================================\n";

    // Record deployed file timestamps
    foreach ($filesToDeploy as $df) {
        $dfPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $df);
        if (file_exists($dfPath)) {
            $deployedTimestamps[$df] = filemtime($dfPath);
        }
    }
    $state['last_deploy'] = time();
    $state['last_deploy_date'] = date('Y-m-d H:i:s');
    $state['files'] = $deployedTimestamps;
    @file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
} elseif ($httpCode === 200 && ($resData['status'] ?? '') === 'partial_success') {
    warn("DEPLOY FINISHED WITH WARNINGS (" . $duration . "s)");
    warn("Files updated: " . ($resData['total_files'] ?? 0));
    if (!empty($resData['errors'])) {
        foreach ($resData['errors'] as $e) {
            err(" - $e");
        }
    }
} else {
    err("DEPLOY FAILED (HTTP $httpCode)");
    if (!empty($resData['message'])) {
        err("Server message: " . $resData['message']);
    }
    if (!empty($resData['errors'])) {
        foreach ($resData['errors'] as $e) {
            err(" - $e");
        }
    }
    if (!$resData) {
        echo "Raw response: " . substr($response, 0, 500) . "\n";
    }
    exit(1);
}
