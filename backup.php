<?php
require_once 'config.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?page=dashboard');
    exit;
}

// Verify CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die('خطای امنیتی');
}

// Get all tables
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

$backup = "-- پشتیبان دیتابیس جاری شرکا\n";
$backup .= "-- تاریخ: " . date('Y-m-d H:i:s') . "\n";
$backup .= "-- دیتابیس: $db\n\n";
$backup .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

foreach ($tables as $table) {
    // Get CREATE TABLE statement
    $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
    $backup .= "DROP TABLE IF EXISTS `$table`;\n";
    $backup .= $create['Create Table'] . ";\n\n";

    // Get data
    $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rows)) {
        $columns = array_keys($rows[0]);
        $col_str = '`' . implode('`, `', $columns) . '`';

        foreach ($rows as $row) {
            $values = array_map(function($v) use ($pdo) {
                if ($v === null) return 'NULL';
                return $pdo->quote($v);
            }, $row);
            $backup .= "INSERT INTO `$table` ($col_str) VALUES (" . implode(', ', $values) . ");\n";
        }
        $backup .= "\n";
    }
}

$backup .= "SET FOREIGN_KEY_CHECKS = 1;\n";

// Send file for download
$filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($backup));
header('Cache-Control: no-cache, must-revalidate');

echo $backup;
exit;
