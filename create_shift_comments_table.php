<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

echo "<h2>Database Migration Script</h2><pre>";

try {
    // 1. Create shift_comments table
    $sql_comments = "CREATE TABLE IF NOT EXISTS shift_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shift_id INT NOT NULL,
        user_type ENUM('passenger', 'admin') NOT NULL,
        user_id INT NULL,
        user_name VARCHAR(100) NOT NULL,
        comment TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_shift_id (shift_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $pdo->exec($sql_comments);
    echo "✅ Table 'shift_comments' created/verified successfully.\n";

    // 2. Add note_status and last_note columns to shifts table if not exists
    $columns = $pdo->query("SHOW COLUMNS FROM shifts")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('note_status', $columns)) {
        $pdo->exec("ALTER TABLE shifts ADD COLUMN note_status ENUM('none', 'pending_admin', 'replied_admin') DEFAULT 'none'");
        echo "✅ Column 'note_status' added to 'shifts' table.\n";
    } else {
        echo "ℹ️ Column 'note_status' already exists.\n";
    }

    if (!in_array('last_note', $columns)) {
        $pdo->exec("ALTER TABLE shifts ADD COLUMN last_note TEXT NULL");
        echo "✅ Column 'last_note' added to 'shifts' table.\n";
    } else {
        echo "ℹ️ Column 'last_note' already exists.\n";
    }

    echo "\n🎉 Database migration completed successfully!</pre>";
} catch (Exception $e) {
    echo "❌ Migration Error: " . htmlspecialchars($e->getMessage()) . "</pre>";
}
