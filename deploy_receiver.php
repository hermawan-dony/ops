<?php
/**
 * Automated Deploy Receiver for ops.framas.co.id
 *
 * Receives files from local deploy script via HTTPS POST with secret token authentication.
 * Automatically backs up overwritten files into .deploy_backups/<timestamp>/
 */

// Production Deploy Secret Key
define('DEPLOY_SECRET', 'framas_ops_deploy_9f8e7d6c5b4a321');

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

// Determine provided token
$token = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? $_POST['token'] ?? $_GET['token'] ?? $_GET['key'] ?? null;

// Read JSON input if sent as application/json
$rawInput = file_get_contents('php://input');
$jsonData = null;
if (!empty($rawInput)) {
    $jsonData = json_decode($rawInput, true);
    if (is_array($jsonData) && !empty($jsonData['token'])) {
        $token = $jsonData['token'];
    }
}

// Authentication Check
if (!$token || !hash_equals(DEPLOY_SECRET, (string)$token)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized: Invalid or missing deploy token'
    ], JSON_PRETTY_PRINT);
    exit;
}

// Health Check / Ping (GET request)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'status' => 'ok',
        'message' => 'Deploy receiver is active and ready.',
        'server_time' => date('Y-m-d H:i:s'),
        'php_version' => PHP_VERSION,
        'root_dir' => __DIR__,
        'max_upload' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size')
    ], JSON_PRETTY_PRINT);
    exit;
}

// Only POST allowed for deployment
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'message' => 'Method not allowed. Use POST to deploy.'
    ], JSON_PRETTY_PRINT);
    exit;
}

// Validate payload
$files = $jsonData['files'] ?? null;
if (!is_array($files) || empty($files)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'No files payload received. Expected JSON: { "files": [ { "path": "...", "content": "base64..." } ] }'
    ], JSON_PRETTY_PRINT);
    exit;
}

// Prepare Backup Directory
$sessionId = date('Y-m-d_His');
$baseDir = realpath(__DIR__);
$backupBaseDir = $baseDir . DIRECTORY_SEPARATOR . '.deploy_backups';
$sessionBackupDir = $backupBaseDir . DIRECTORY_SEPARATOR . $sessionId;

if (!is_dir($backupBaseDir)) {
    @mkdir($backupBaseDir, 0755, true);
    // Protect backup directory with .htaccess
    $htaccess = $backupBaseDir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
}

$results = [];
$errors = [];

foreach ($files as $fileItem) {
    $relPath = $fileItem['path'] ?? '';
    $contentBase64 = $fileItem['content'] ?? null;

    if (empty($relPath) || $contentBase64 === null) {
        $errors[] = "Missing path or content for file item.";
        continue;
    }

    // Normalize path separators
    $relPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($relPath, "/\\ "));

    // Security check: Traversal prevention
    if (strpos($relPath, '..') !== false || strpos($relPath, ':') !== false) {
        $errors[] = "Path traversal detected in path: $relPath";
        continue;
    }

    // Protected folders
    if (strpos($relPath, '.git') === 0 || strpos($relPath, '.deploy_backups') === 0) {
        $errors[] = "Cannot write to protected path: $relPath";
        continue;
    }

    $targetPath = $baseDir . DIRECTORY_SEPARATOR . $relPath;

    // Decode content
    $decodedContent = base64_decode($contentBase64, true);
    if ($decodedContent === false) {
        $errors[] = "Failed to base64-decode content for: $relPath";
        continue;
    }

    // Automatic backup of existing file
    $backedUp = false;
    if (file_exists($targetPath)) {
        $backupPath = $sessionBackupDir . DIRECTORY_SEPARATOR . $relPath;
        $backupDir = dirname($backupPath);
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }
        if (@copy($targetPath, $backupPath)) {
            $backedUp = true;
        }
    }

    // Ensure target folder exists
    $targetDir = dirname($targetPath);
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0755, true);
    }

    // Write target file
    $bytes = @file_put_contents($targetPath, $decodedContent);
    if ($bytes === false) {
        $errors[] = "Failed to write to file: $relPath (check permissions)";
        continue;
    }

    $results[] = [
        'path' => str_replace(DIRECTORY_SEPARATOR, '/', $relPath),
        'size' => $bytes,
        'backed_up' => $backedUp
    ];
}

if (!empty($errors) && empty($results)) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Deployment failed',
        'errors' => $errors
    ], JSON_PRETTY_PRINT);
    exit;
}

echo json_encode([
    'status' => empty($errors) ? 'success' : 'partial_success',
    'session' => $sessionId,
    'total_files' => count($results),
    'files' => $results,
    'errors' => $errors,
    'backup_session' => $sessionBackupDir
], JSON_PRETTY_PRINT);
