<?php
require_once __DIR__ . '/../config.php';

// Calculate fund values
$partner_capital = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM partners")->fetchColumn();
$buyer_balance = $pdo->query("SELECT COALESCE(SUM(sale_rials), 0) FROM documents WHERE status = 'در انتظار'")->fetchColumn();
$total_profit = $pdo->query("SELECT COALESCE(SUM(profit_rials), 0) FROM documents")->fetchColumn();
$paid_profit = $pdo->query("SELECT COALESCE(SUM(total_profit), 0) FROM settlements")->fetchColumn();
$withdrawals = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM partner_transactions WHERE type = 'برداشت'")->fetchColumn();
$fund_balance = ($partner_capital - $buyer_balance) + ($total_profit - $paid_profit) - $withdrawals;

// Get fund movement log
$deposits = $pdo->query("SELECT partner_name, transaction_date, amount, type, description FROM partner_transactions WHERE type IN ('واریز', 'آورده') ORDER BY transaction_date ASC")->fetchAll(PDO::FETCH_ASSOC);
$settlement_txns = $pdo->query("SELECT partner_name, transaction_date, amount, type, description FROM partner_transactions WHERE type = 'تسویه سود' ORDER BY transaction_date ASC")->fetchAll(PDO::FETCH_ASSOC);
$withdrawal_txns = $pdo->query("SELECT partner_name, transaction_date, amount, type, description FROM partner_transactions WHERE type = 'برداشت' ORDER BY transaction_date ASC")->fetchAll(PDO::FETCH_ASSOC);

$fund_movements = [];
$running = 0;

foreach ($deposits as $d) {
    $running += $d['amount'];
    $fund_movements[] = [
        'date' => $d['transaction_date'],
        'description' => $d['description'] ?: 'واریز ' . $d['partner_name'],
        'deposit' => $d['amount'],
        'withdrawal' => 0,
        'balance' => $running,
    ];
}

foreach ($settlement_txns as $s) {
    $running -= $s['amount'];
    $fund_movements[] = [
        'date' => $s['transaction_date'],
        'description' => $s['description'] ?: 'تسویه سود ' . $s['partner_name'],
        'deposit' => 0,
        'withdrawal' => $s['amount'],
        'balance' => $running,
    ];
}

foreach ($withdrawal_txns as $w) {
    $running -= $w['amount'];
    $fund_movements[] = [
        'date' => $w['transaction_date'],
        'description' => $w['description'] ?: 'برداشت ' . $w['partner_name'],
        'deposit' => 0,
        'withdrawal' => $w['amount'],
        'balance' => $running,
    ];
}

usort($fund_movements, function($a, $b) {
    return $a['date'] <=> $b['date'];
});
?>

<h1>صندوق</h1>

<div class="card">
    <h2>خلاصه صندوق</h2>
    <table>
        <thead>
            <tr>
                <th>آیتم</th>
                <th>مبلغ (ریال)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>آورده شرکا</td>
                <td style="font-family:var(--mono)"><?= format_rial($partner_capital) ?></td>
            </tr>
            <tr>
                <td>مانده حساب خریدار</td>
                <td style="font-family:var(--mono)"><?= format_rial($buyer_balance) ?></td>
            </tr>
            <tr>
                <td>سود شناسایی شده</td>
                <td class="text-profit" style="font-family:var(--mono)"><?= format_rial($total_profit) ?></td>
            </tr>
            <tr>
                <td>سود پرداخت شده</td>
                <td class="text-danger" style="font-family:var(--mono)"><?= format_rial($paid_profit) ?></td>
            </tr>
            <tr>
                <td>برداشت شرکا</td>
                <td class="text-danger" style="font-family:var(--mono)"><?= format_rial($withdrawals) ?></td>
            </tr>
            <tr class="text-bold bg-subtle">
                <td>مانده صندوق</td>
                <td class="text-lg" style="font-family:var(--mono)"><?= format_rial($fund_balance) ?></td>
            </tr>
        </tbody>
    </table>
</div>

<?php if ($fund_movements): ?>
<div class="card">
    <h2>گردش صندوق</h2>
    <div class="table-responsive">
    <table>
        <thead>
            <tr>
                <th>تاریخ</th>
                <th>شرح</th>
                <th>واریز</th>
                <th>برداشت</th>
                <th>مانده</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($fund_movements as $m): ?>
            <tr>
                <td><?= to_jalali($m['date']) ?></td>
                <td><?= e($m['description']) ?></td>
                <td class="text-profit" style="font-family:var(--mono)"><?= $m['deposit'] > 0 ? '+ ' . format_rial($m['deposit']) : '-' ?></td>
                <td class="text-danger" style="font-family:var(--mono)"><?= $m['withdrawal'] > 0 ? '- ' . format_rial($m['withdrawal']) : '-' ?></td>
                <td class="text-bold" style="font-family:var(--mono)"><?= format_rial($m['balance']) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="text-bold bg-subtle">
                <td colspan="2">مانده نهایی</td>
                <td></td>
                <td></td>
                <td class="text-lg" style="font-family:var(--mono)"><?= format_rial($fund_balance) ?></td>
            </tr>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>
