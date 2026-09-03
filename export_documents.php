<?php
require_once 'config.php';

// Build query with same filters as documents page
$where = [];
$params = [];

if (!empty($_GET['q'])) {
    $where[] = "(doc_number LIKE ? OR seller LIKE ? OR buyer LIKE ?)";
    $q = '%' . $_GET['q'] . '%';
    $params = array_merge($params, [$q, $q, $q]);
}
if (!empty($_GET['seller'])) {
    $where[] = "seller LIKE ?";
    $params[] = '%' . $_GET['seller'] . '%';
}
if (!empty($_GET['buyer'])) {
    $where[] = "buyer LIKE ?";
    $params[] = '%' . $_GET['buyer'] . '%';
}
if (!empty($_GET['status'])) {
    $where[] = "status = ?";
    $params[] = $_GET['status'];
}
if (!empty($_GET['month_name'])) {
    $where[] = "month_name = ?";
    $params[] = $_GET['month_name'];
}

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $pdo->prepare("SELECT * FROM documents $where_sql ORDER BY id");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Column definitions
$columns = [
    'ردیف' => 'row_num',
    'تاریخ سند' => 'doc_date',
    'شماره سند' => 'doc_number',
    'فروشنده' => 'seller',
    'خریدار' => 'buyer',
    'وزن (kg)' => 'weight_kg',
    'افت (kg)' => 'loss_kg',
    'کسر (ریال/kg)' => 'deduction_rials',
    'پاداش (ریال/kg)' => 'bonus',
    'خرید (ریال)' => 'purchase_rials',
    'فروش (ریال)' => 'sale_rials',
    'سود (ریال)' => 'profit_rials',
    'تاریخ پرداخت' => 'payment_date',
    'وضعیت' => 'status',
    'ماه' => 'month_name',
];

// Generate CSV with UTF-8 BOM for Excel compatibility
$filename = 'documents_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');

$output = fopen('php://output', 'w');

// UTF-8 BOM for Excel
fwrite($output, "\xEF\xBB\xBF");

// Header row
$fheaders = array_keys($columns);
fputcsv($output, $fheaders, ',');

// Data rows
foreach ($rows as $row) {
    $line = [];
    foreach ($columns as $field) {
        $val = $row[$field] ?? '';

        // Convert dates to Jalali
        if ($field === 'doc_date' || $field === 'payment_date') {
            $val = $val ? to_jalali($val, 'Y/m/d') : '';
        }

        $line[] = $val;
    }
    fputcsv($output, $line, ',');
}

fclose($output);
exit;
