<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    exit('Unauthorized');
}

$filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

try {
    // 1. Get all tables
    $tables = [];
    $result = $pdo->query("SHOW TABLES");
    while ($row = $result->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    $sql = "-- Database Backup: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Database: " . $db . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    echo $sql;
    flush();

    // 2. Loop through tables
    foreach ($tables as $table) {
        // Table structure
        $createTableStmt = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        echo "DROP TABLE IF EXISTS `$table`;\n";
        echo $createTableStmt[1] . ";\n\n";
        flush();

        // Table data
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 0) {
            foreach ($rows as $row) {
                $columns = array_keys($row);
                $escapedColumns = array_map(function($col) { return "`$col`"; }, $columns);
                
                $values = array_values($row);
                $escapedValues = array_map(function($val) use ($pdo) {
                    if ($val === null) {
                        return "NULL";
                    }
                    return $pdo->quote($val);
                }, $values);

                echo "INSERT INTO `$table` (" . implode(', ', $escapedColumns) . ") VALUES (" . implode(', ', $escapedValues) . ");\n";
            }
            echo "\n";
            flush();
        }
    }

    echo "\nSET FOREIGN_KEY_CHECKS=1;\n";

} catch (Exception $e) {
    echo "\n-- ERROR PERFORMING BACKUP: " . $e->getMessage() . "\n";
}
