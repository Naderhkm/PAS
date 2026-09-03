<?php
require_once __DIR__ . '/../config.php';

// Single query for all stats
$stats = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM documents) AS total_docs,
        (SELECT COALESCE(SUM(weight_kg), 0) FROM documents) AS total_weight,
        (SELECT COALESCE(SUM(profit_rials), 0) FROM documents) AS total_profit,
        (SELECT COALESCE(SUM(sale_rials), 0) FROM documents WHERE status = 'در انتظار') AS buyer_balance,
        (SELECT COALESCE(SUM(amount), 0) FROM partners) AS partner_capital,
        (SELECT COALESCE(SUM(amount), 0) FROM partner_transactions WHERE type = 'تسویه سود') AS paid_profit,
        (SELECT COALESCE(SUM(amount), 0) FROM partner_transactions WHERE type = 'برداشت') AS withdrawals,
        (SELECT COUNT(*) FROM partners) AS partner_count
")->fetch(PDO::FETCH_ASSOC);

$fund_balance = ($stats['partner_capital'] - $stats['buyer_balance'])
              + ($stats['total_profit'] - $stats['paid_profit'])
              - $stats['withdrawals'];
?>

<div class="page-header">
    <h1>داشبورد</h1>
    <form method="POST" action="backup.php">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-primary">پشتیبان</button>
    </form>
</div>

<div class="stats">
    <div class="stat-card">
        <h3>اسناد</h3>
        <div class="value"><?= $stats['total_docs'] ?></div>
    </div>
    <div class="stat-card">
        <h3>وزن (kg)</h3>
        <div class="value"><?= number_format($stats['total_weight'], 0, '', ',') ?></div>
    </div>
    <div class="stat-card">
        <h3>سود کل</h3>
        <div class="value"><?= format_rial($stats['total_profit']) ?></div>
    </div>
    <div class="stat-card">
        <h3>مانده صندوق</h3>
        <div class="value"><?= format_rial($fund_balance) ?></div>
    </div>
    <div class="stat-card">
        <h3>شرکا</h3>
        <div class="value"><?= $stats['partner_count'] ?> نفر</div>
    </div>
    <div class="stat-card">
        <h3>آورده شرکا</h3>
        <div class="value"><?= format_rial($stats['partner_capital']) ?></div>
    </div>
</div>

<div class="card">
    <div class="page-header" style="margin-bottom:12px;">
        <h2 style="margin-bottom:0;">آخرین اسناد</h2>
        <a href="?page=documents" class="btn btn-secondary">مشاهده همه</a>
    </div>
    <div class="table-responsive">
    <table>
        <thead>
            <tr>
                <th>ردیف</th>
                <th>شماره</th>
                <th>فروشنده</th>
                <th>خریدار</th>
                <th>وزن</th>
                <th>سود</th>
                <th>وضعیت</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $stmt = $pdo->query("SELECT * FROM documents ORDER BY id DESC LIMIT 5");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)):
            ?>
            <tr>
                <td><?= e($row['row_num']) ?></td>
                <td><?= e($row['doc_number']) ?></td>
                <td><?= e($row['seller']) ?></td>
                <td><?= e($row['buyer']) ?></td>
                <td style="font-family:var(--mono)"><?= format_rial($row['weight_kg']) ?></td>
                <td style="font-family:var(--mono)"><?= format_rial($row['profit_rials']) ?></td>
                <td>
                    <span class="badge <?= $row['status'] === 'تسویه شده' ? 'badge-success' : ($row['status'] === 'لغو شده' ? 'badge-muted' : 'badge-warning') ?> <?= $row['status'] === 'تسویه شده' ? 'status-paid' : ($row['status'] === 'لغو شده' ? 'status-cancelled' : 'status-unpaid') ?>">
                        <?= e($row['status']) ?>
                    </span>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    </div>
</div>
