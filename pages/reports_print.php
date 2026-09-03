<?php
require_once 'config.php';

$partner_names = $pdo->query("SELECT account_name FROM partners ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$monthly_profit = get_monthly_profits($pdo);
$partners = $pdo->query("SELECT id, account_name FROM partners ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// Calculate daily-weighted profit distribution
$settlement_results = calculate_settlement_profits($pdo, $partners, $monthly_profit);

// Build report_data for display
$report_data = [];
foreach ($partner_names as $name) {
    $report_data[$name] = ['months' => [], 'total' => 0];
}

foreach ($settlement_results as $month_key => $sr) {
    foreach ($sr['partner_shares'] as $ps) {
        $name = $ps['name'];
        if (isset($report_data[$name])) {
            $report_data[$name]['months'][$month_key] = $ps['share'];
            $report_data[$name]['total'] += $ps['share'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>گزارش سود شرکا</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css">
    <style>
        body { font-family: 'Vazirmatn', Tahoma, sans-serif; padding: 40px; font-size: 12px; }
        h1 { text-align: center; margin-bottom: 30px; font-size: 18px; }
        h2 { font-size: 14px; margin: 20px 0 10px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #333; padding: 8px; text-align: right; font-size: 11px; }
        th { background: #333; color: #fff; }
        .total { font-weight: bold; background: #eee; }
        .footer { text-align: center; margin-top: 40px; font-size: 10px; color: #666; }
        @media print { body { padding: 20px; } }
    </style>
</head>
<body>
    <h1>گزارش توزیع سود شرکا</h1>
    <p style="text-align:center;color:#666;">تاریخ چاپ: <?= to_jalali(date('Y-m-d'), 'Y/m/d') ?></p>

    <h2>جدول تقسیم سود ماهانه</h2>
    <table>
        <thead>
            <tr>
                <th>شریک</th>
                <?php foreach ($monthly_profit as $mk => $mp): ?>
                <th><?= $months_fa_key[$mp['jalali_key']] ?></th>
                <?php endforeach; ?>
                <th>جمع</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($report_data as $name => $data): ?>
            <tr>
                <td><?= e($name) ?></td>
                <?php foreach ($monthly_profit as $mk => $mp): ?>
                <td><?= format_rial($data['months'][$mk] ?? 0) ?></td>
                <?php endforeach; ?>
                <td class="total"><?= format_rial($data['total']) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total">
                <td>جمع کل</td>
                <?php foreach ($monthly_profit as $mp): ?>
                <td><?= format_rial($mp['profit']) ?></td>
                <?php endforeach; ?>
                <td><?= format_rial(array_sum(array_column($monthly_profit, 'profit'))) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <p>جاری شرکا - سامانه مدیریت مالی</p>
    </div>

    <script>
    window.onload = function() { window.print(); }
    </script>
</body>
</html>
