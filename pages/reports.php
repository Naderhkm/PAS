<?php
require_once __DIR__ . '/../config.php';

$partner_names = $pdo->query("SELECT DISTINCT account_name FROM partners ORDER BY account_name")->fetchAll(PDO::FETCH_COLUMN);
$monthly_profit = get_monthly_profits($pdo);
$partners = $pdo->query("SELECT id, account_name FROM partners ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// Calculate daily-weighted profit distribution
$settlement_results = calculate_settlement_profits($pdo, $partners, $monthly_profit);

// Build report_data structure for table display
$report_data = [];
foreach ($partner_names as $name) {
    $report_data[$name] = ['months' => [], 'total' => 0];
}

$totals = [];
foreach ($settlement_results as $month_key => $sr) {
    $totals[$month_key] = $sr['total_share'];
    foreach ($sr['partner_shares'] as $ps) {
        $name = $ps['name'];
        if (isset($report_data[$name])) {
            $report_data[$name]['months'][$month_key] = [
                'value' => $ps['share'],
                'percentage' => $ps['pct'],
                'effective_balance' => $ps['effective_balance'] ?? 0,
            ];
            $report_data[$name]['total'] += $ps['share'];
        }
    }
}

$grand_total = array_sum($totals);
$total_profit_all = array_sum(array_column($monthly_profit, 'profit'));
$partner_pcts = $pdo->query("SELECT account_name, percentage FROM partners")->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<h1>گزارشات سود شرکا</h1>

<div class="stats">
    <div class="stat-card">
        <h3>جمع کل سود</h3>
        <div class="value"><?= format_rial($total_profit_all) ?> ریال</div>
    </div>
    <div class="stat-card">
        <h3>تعداد شرکا</h3>
        <div class="value"><?= count($partner_names) ?> نفر</div>
    </div>
</div>

<!-- خلاصه سود هر شریک -->
<div class="card">
    <div class="page-header">
        <h2 style="margin-bottom:0;">خلاصه سود هر شریک</h2>
        <div class="flex-row">
            <a href="pages/reports_print.php" target="_blank" class="btn btn-primary btn-sm">PDF</a>
            <button onclick="window.print()" class="btn btn-secondary btn-sm">پرینت</button>
        </div>
    </div>
    <table>
        <thead>
            <tr>
                <th>شریک</th>
                <th>درصد سهم</th>
                <th>جمع سود</th>
                <th>درصد از کل</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($report_data as $name => $data): ?>
            <tr>
                <td class="text-bold"><?= e($name) ?></td>
                <td><?= round(($partner_pcts[$name] ?? 0) * 100, 2) ?>%</td>
                <td class="text-bold text-profit" style="font-family:var(--mono)"><?= format_rial($data['total']) ?></td>
                <td><?= $grand_total > 0 ? round($data['total'] / $grand_total * 100, 2) : 0 ?>%</td>
            </tr>
            <?php endforeach; ?>
            <tr class="text-bold bg-subtle">
                <td>جمع کل</td>
                <td>100%</td>
                <td style="font-family:var(--mono)"><?= format_rial($grand_total) ?></td>
                <td>100%</td>
            </tr>
        </tbody>
    </table>
</div>

<!-- جزئیات ماهانه با تب -->
<?php if (!empty($settlement_results)): ?>
<div class="card">
    <h2>جزئیات ماهانه</h2>
    
    <div class="tabs" id="report-tabs">
        <?php $first_tab = true; ?>
        <?php foreach ($settlement_results as $mk => $sr): ?>
        <a href="#" class="tab <?= $first_tab ? 'active' : '' ?>" onclick="showReportTab('rtab-<?= $mk ?>', this); return false;">
            <?= e($months_fa_num[$sr['month']] . ' ' . $sr['year']) ?>
        </a>
        <?php $first_tab = false; ?>
        <?php endforeach; ?>
    </div>
    
    <?php $first_tab = true; ?>
    <?php foreach ($settlement_results as $mk => $sr): ?>
    <div id="rtab-<?= $mk ?>" class="report-tab" style="<?= $first_tab ? '' : 'display:none;' ?>">
        <div style="margin-bottom:16px;">
            <div class="flex-row" style="justify-content:space-between;">
                <div class="flex-row">
                    <strong style="font-size:1rem;"><?= e($months_fa_num[$sr['month']] . ' ' . $sr['year']) ?></strong>
                    <span class="text-muted">— سود: <?= format_rial($sr['profit']) ?> ریال</span>
                </div>
            </div>
        </div>
        
        <!-- جدول سهم شرکا -->
        <table style="width:100%;font-size:0.85rem;margin-bottom:16px;">
            <thead>
                <tr>
                    <th>شریک</th>
                    <th>سرمایه مؤثر</th>
                    <th>درصد</th>
                    <th>سهم سود</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sr['partner_shares'] as $ps): ?>
                <tr>
                    <td class="text-bold"><?= e($ps['name']) ?></td>
                    <td style="font-family:var(--mono)"><?= format_rial($ps['effective_balance'] ?? 0) ?></td>
                    <td><?= round($ps['pct'] * 100, 2) ?>%</td>
                    <td style="font-family:var(--mono)" class="text-bold"><?= format_rial($ps['share']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- جزئیات روزانه -->
        <?php if (!empty($sr['daily_details'])): ?>
        <details>
            <summary style="cursor:pointer;color:var(--accent);font-size:0.82rem;font-weight:600;">جزئیات مانده روزانه</summary>
            <div style="margin-top:12px;padding:12px;background:var(--bg);border-radius:6px;">
                <?php foreach ($sr['daily_details'] as $pname => $daily): ?>
                <div style="margin-bottom:12px;">
                    <strong class="text-bold" style="color:var(--ink-2);"><?= e($pname) ?></strong>
                    <table style="width:100%;font-size:0.8rem;margin-top:6px;">
                        <thead>
                            <tr>
                                <th>روز</th>
                                <th>مانده ابتدا</th>
                                <th>تغییرات</th>
                                <th>مانده انتها</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($daily as $day_data): ?>
                            <tr>
                                <td><?= $day_data['day'] ?? to_jalali($day_data['balance_date'] ?? '', 'Y/m/d') ?></td>
                                <td style="font-family:var(--mono)"><?= format_rial($day_data['opening'] ?? $day_data['opening_balance'] ?? 0) ?></td>
                                <td style="font-family:var(--mono)" class="<?= ($day_data['change'] ?? $day_data['daily_change'] ?? 0) > 0 ? 'text-profit' : (($day_data['change'] ?? $day_data['daily_change'] ?? 0) < 0 ? 'text-danger' : '') ?>">
                                    <?php $change = $day_data['change'] ?? $day_data['daily_change'] ?? 0; ?>
                                    <?= $change != 0 ? ($change > 0 ? '+' : '') . format_rial($change) : '-' ?>
                                </td>
                                <td style="font-family:var(--mono)" class="text-bold"><?= format_rial($day_data['closing'] ?? $day_data['closing_balance'] ?? 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>
            </div>
        </details>
        <?php endif; ?>
    </div>
    <?php $first_tab = false; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
function showReportTab(tabId, clickedTab) {
    document.querySelectorAll('.report-tab').forEach(function(el) {
        el.style.display = 'none';
    });
    document.querySelectorAll('#report-tabs .tab').forEach(function(el) {
        el.classList.remove('active');
    });
    document.getElementById(tabId).style.display = '';
    clickedTab.classList.add('active');
}
</script>
