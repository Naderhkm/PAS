<?php
require_once __DIR__ . '/../config.php';

// ─── Handle cancel settlement ────────────────────────────────────
if (isset($_POST['cancel_settlement'])) {
    verify_csrf();
    $settlement_id = (int)$_POST['cancel_settlement'];

    $stmt = $pdo->prepare("SELECT * FROM settlements WHERE id = ?");
    $stmt->execute([$settlement_id]);
    $settlement = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($settlement) {
        $pdo->beginTransaction();
        try {
            $details = $pdo->prepare("SELECT partner_id, share_amount FROM settlement_details WHERE settlement_id = ?");
            $details->execute([$settlement_id]);
            $detail_rows = $details->fetchAll(PDO::FETCH_ASSOC);

            foreach ($detail_rows as $d) {
                $pdo->prepare("DELETE FROM partner_transactions WHERE partner_id = ? AND amount = ? AND type = 'تسویه سود' AND description LIKE ?")
                    ->execute([$d['partner_id'], $d['share_amount'], "%{$settlement['month_label']}%"]);
            }

            // حذف تاریخچه مانده روزانه تسویه
            $pdo->prepare("DELETE FROM settlement_daily_balances WHERE settlement_id = ?")
                ->execute([$settlement_id]);

            $pdo->prepare("DELETE FROM settlement_details WHERE settlement_id = ?")
                ->execute([$settlement_id]);
            $pdo->prepare("DELETE FROM settlements WHERE id = ?")
                ->execute([$settlement_id]);
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
        }
    }

    header('Location: ?page=settlement&success=settlement_cancelled');
    exit;
}

// ─── Handle settle ───────────────────────────────────────────────
if (isset($_POST['do_settlement'])) {
    verify_csrf();
    $year = (int)$_POST['year'];
    $month = (int)$_POST['month'];

    if ($month < 1 || $month > 12 || $year < 1300 || $year > 1500) {
        header('Location: ?page=settlement&error=invalid');
        exit;
    }

    $partners = $pdo->query("SELECT id, account_name FROM partners ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();
    try {
        $check = $pdo->prepare("SELECT COUNT(*) FROM settlements WHERE jalali_year = ? AND jalali_month = ?");
        $check->execute([$year, $month]);
        if ($check->fetchColumn() > 0) {
            $pdo->rollBack();
            header('Location: ?page=settlement&error=duplicate');
            exit;
        }

        // دریافت سود ماهانه از اسناد
        $all_monthly = get_monthly_profits($pdo);
        $month_key = $year . '/' . str_pad($month, 2, '0', STR_PAD_LEFT);
        $monthly_profit = $all_monthly[$month_key]['profit'] ?? 0;

        if ($monthly_profit <= 0) {
            $pdo->rollBack();
            header('Location: ?page=settlement&error=noprofit');
            exit;
        }

        // محاسبه مانده وزنی روزانه هر شریک
        $settlement_result = calculate_month_settlement($pdo, $partners, $year, $month, $monthly_profit);
        $month_label = $months_fa_num[$month] . ' ' . $year;

        // ثبت تسویه
        $pdo->prepare("INSERT INTO settlements (jalali_year, jalali_month, month_label, total_profit) VALUES (?, ?, ?, ?)")
            ->execute([$year, $month, $month_label, $monthly_profit]);
        $settlement_id = $pdo->lastInsertId();

        // ثبت سهم هر شریک
        foreach ($settlement_result['partner_shares'] as $ps) {
            $partner = null;
            foreach ($partners as $p) {
                if ($p['account_name'] === $ps['name']) { $partner = $p; break; }
            }
            if (!$partner) continue;

            if ($ps['share'] > 0) {
                $pdo->prepare("INSERT INTO settlement_details (settlement_id, partner_id, partner_name, share_amount, percentage) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$settlement_id, $partner['id'], $ps['name'], $ps['share'], $ps['pct']]);

                $pdo->prepare("INSERT INTO partner_transactions (partner_id, partner_name, transaction_date, description, amount, type) VALUES (?, ?, CURDATE(), ?, ?, 'تسویه سود')")
                    ->execute([$partner['id'], $ps['name'], "تسویه سود {$month_label}", $ps['share']]);
            }
        }

        // ذخیره تاریخچه مانده روزانه برای این تسویه
        if (!empty($settlement_result['daily_details'])) {
            list($gy_start, $gm_start, $gd_start) = jalali_to_gregorian($year, $month, 1);
            $days = get_days_in_jalali_month($year, $month);
            list($gy_end, $gm_end, $gd_end) = jalali_to_gregorian($year, $month, $days);

            $partner_id_map = [];
            foreach ($partners as $p) { $partner_id_map[$p['account_name']] = $p['id']; }

            foreach ($settlement_result['daily_details'] as $partner_name => $daily) {
                $pid = $partner_id_map[$partner_name] ?? 0;
                if (!$pid) continue;

                foreach ($daily as $day_data) {
                    // day_data ممکن است آرایه ساده باشد یا associative
                    if (isset($day_data['gregorian_date'])) {
                        $g_date = $day_data['gregorian_date'];
                        $opening = $day_data['opening'];
                        $closing = $day_data['closing'];
                        $change = $day_data['change'];
                    } else {
                        // اگر از partner_daily_balances خوانده شده باشد
                        $g_date = $day_data['balance_date'];
                        $opening = $day_data['opening_balance'];
                        $closing = $day_data['closing_balance'];
                        $change = $day_data['daily_change'];
                    }

                    $pdo->prepare("INSERT INTO settlement_daily_balances
                        (settlement_id, partner_id, partner_name, balance_date, opening_balance, closing_balance, daily_change)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            opening_balance = VALUES(opening_balance),
                            closing_balance = VALUES(closing_balance),
                            daily_change = VALUES(daily_change)")
                        ->execute([$settlement_id, $pid, $partner_name, $g_date, $opening, $closing, $change]);
                }
            }
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        header('Location: ?page=settlement&error=failed');
        exit;
    }

    header('Location: ?page=settlement&success=settlement_done');
    exit;
}

// ─── Display ─────────────────────────────────────────────────────
$all_settlements = $pdo->query("SELECT * FROM settlements ORDER BY jalali_year DESC, jalali_month DESC")->fetchAll(PDO::FETCH_ASSOC);
$all_monthly = get_monthly_profits($pdo);
$partners = $pdo->query("SELECT id, account_name FROM partners ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$settled_months = [];
foreach ($all_settlements as $s) {
    $settled_months[$s['jalali_year'] . '/' . str_pad($s['jalali_month'], 2, '0', STR_PAD_LEFT)] = $s['id'];
}

// محاسبه توزیع سود ماهانه (برای پیش‌نمایش)
$settlement_results = calculate_settlement_profits($pdo, $partners, $all_monthly);

// دریافت جزئیات تسویه‌های قبلی
$all_details = $pdo->query("SELECT * FROM settlement_details ORDER BY settlement_id, partner_name")->fetchAll(PDO::FETCH_ASSOC);
$details_by_settlement = [];
foreach ($all_details as $d) {
    $details_by_settlement[$d['settlement_id']][] = $d;
}

// دریافت جزئیات مانده روزانه تسویه‌ها
$all_settlement_daily = $pdo->query("SELECT * FROM settlement_daily_balances ORDER BY settlement_id, partner_id, balance_date ASC")->fetchAll(PDO::FETCH_ASSOC);
$settlement_daily_by_id = [];
foreach ($all_settlement_daily as $sd) {
    $settlement_daily_by_id[$sd['settlement_id']][$sd['partner_name']][] = $sd;
}
?>

<h1>تسویه سود شرکا</h1>

<div class="card">
    <h2>ثبت تسویه سود</h2>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="do_settlement" value="1">
        <div class="form-grid" style="grid-template-columns: auto auto;">
            <div class="form-group">
                <label>سال شمسی</label>
                <select name="year" required>
                    <?php for ($y = 1404; $y <= 1410; $y++): ?>
                    <option value="<?= $y ?>"><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group">
                <label>ماه شمسی</label>
                <select name="month" required>
                    <?php foreach ($months_fa_num as $num => $name): ?>
                    <option value="<?= $num ?>"><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn-primary" onclick="return confirm('آیا از تسویه سود این ماه اطمینان دارید؟')">تسویه سود</button>
    </form>
</div>

<?php if (!empty($settlement_results)): ?>
<div class="card">
    <h2>پیش‌نمایش سود ماهانه</h2>
    
    <div class="tabs" id="settlement-tabs">
        <?php $first_tab = true; ?>
        <?php foreach ($settlement_results as $mk => $sr): ?>
        <a href="#" class="tab <?= $first_tab ? 'active' : '' ?>" onclick="showSettlementTab('tab-<?= $mk ?>', this); return false;">
            <?= e($months_fa_num[$sr['month']] . ' ' . $sr['year']) ?>
            <?php if (isset($settled_months[$mk])): ?>
                <span style="color:var(--green);font-size:0.7rem;">✓</span>
            <?php endif; ?>
        </a>
        <?php $first_tab = false; ?>
        <?php endforeach; ?>
    </div>
    
    <?php $first_tab = true; ?>
    <?php foreach ($settlement_results as $mk => $sr): ?>
    <div id="tab-<?= $mk ?>" class="settlement-tab" style="<?= $first_tab ? '' : 'display:none;' ?>">
        <div style="margin-bottom:12px;">
            <div class="flex-row" style="justify-content:space-between;">
                <div class="flex-row">
                    <strong style="font-size:1rem;"><?= e($months_fa_num[$sr['month']] . ' ' . $sr['year']) ?></strong>
                    <span class="text-muted">— سود: <?= format_rial($sr['profit']) ?> ریال</span>
                </div>
                <?php if (isset($settled_months[$mk])): ?>
                    <span class="badge badge-success status-paid">تسویه شده</span>
                <?php else: ?>
                    <span class="badge badge-warning status-unpaid">در انتظار</span>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (!empty($sr['periods'])): ?>
            <table style="width:100%;font-size:0.85rem;">
                <thead>
                    <tr>
                        <th>شریک</th>
                        <th>جمع سهم</th>
                        <th>درصد</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sr['partner_shares'] as $s): ?>
                    <tr>
                        <td class="text-bold"><?= e($s['name']) ?></td>
                        <td style="font-family:var(--mono)"><?= format_rial($s['share']) ?></td>
                        <td><?= round($s['pct'] * 100, 2) ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <details style="margin-top:12px;">
                <summary style="cursor:pointer;color:var(--accent);font-size:0.82rem;font-weight:600;">جزئیات بازه‌ها</summary>
                <div style="margin-top:10px;padding:12px;background:var(--bg);border-radius:6px;">
                    <?php foreach ($sr['periods'] as $pi => $period): ?>
                    <div style="margin-bottom:8px;">
                        <div class="flex-row" style="margin-bottom:4px;">
                            <strong style="color:var(--ink-2);font-size:0.82rem;">بازه <?= $pi + 1 ?>:</strong>
                            <span class="text-muted" style="font-size:0.82rem;">روز <?= $period['start_day'] ?> تا <?= $period['end_day'] ?> (<?= $period['days'] ?> روز) — سود: <?= format_rial($period['period_profit']) ?></span>
                        </div>
                        <table style="width:100%;font-size:0.8rem;">
                            <thead>
                                <tr>
                                    <th>شریک</th>
                                    <th>سرمایه</th>
                                    <th>سهم</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($period['partners'] as $pp): ?>
                                <tr>
                                    <td><?= e($pp['name']) ?></td>
                                    <td style="font-family:var(--mono)"><?= format_rial($pp['capital']) ?></td>
                                    <td style="font-family:var(--mono)"><?= format_rial($pp['share']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($pi < count($sr['periods']) - 1): ?>
                    <hr style="border:none;border-top:1px dashed var(--border);margin:6px 0;">
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php else: ?>
            <p class="text-muted">داده‌ای موجود نیست</p>
        <?php endif; ?>
    </div>
    <?php $first_tab = false; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($all_settlements): ?>
<div class="card">
    <h2>تسویه‌های انجام شده</h2>
    <div class="table-responsive">
    <table>
        <thead>
            <tr>
                <th>ماه</th>
                <th>جمع سود</th>
                <th>تاریخ تسویه</th>
                <th>جزئیات</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($all_settlements as $s): ?>
            <tr>
                <td class="text-bold"><?= e($s['month_label']) ?></td>
                <td style="font-family:var(--mono)"><?= format_rial($s['total_profit']) ?></td>
                <td><?= to_jalali($s['settled_at']) ?></td>
                <td>
                    <?php if (!empty($details_by_settlement[$s['id']])): ?>
                        <?php foreach ($details_by_settlement[$s['id']] as $d): ?>
                            <span class="text-bold"><?= e($d['partner_name']) ?></span>:
                            <span style="font-family:var(--mono)"><?= format_rial($d['share_amount']) ?></span>
                            <small class="text-muted">(<?= round($d['percentage'] * 100, 1) ?>%)</small>
                            <br>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="actions">
                    <?php if (!empty($settlement_daily_by_id[$s['id']])): ?>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleDailyDetails('daily-<?= $s['id'] ?>')">جزئیات</button>
                    <?php endif; ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('آیا از لغو تسویه این ماه اطمینان دارید؟')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="cancel_settlement" value="<?= e($s['id']) ?>">
                        <button type="submit" class="btn btn-danger btn-sm">لغو</button>
                    </form>
                </td>
            </tr>
            <?php if (!empty($settlement_daily_by_id[$s['id']])): ?>
            <tr id="daily-<?= $s['id'] ?>" style="display:none;">
                <td colspan="5" style="padding:12px;">
                    <div style="background:var(--bg);padding:12px;border-radius:6px;">
                        <strong class="text-bold">جزئیات روزانه شرکا در <?= e($s['month_label']) ?></strong>
                        <div class="table-responsive" style="margin-top:10px;">
                            <?php foreach ($settlement_daily_by_id[$s['id']] as $pname => $daily_rows): ?>
                            <div style="margin-bottom:12px;">
                                <strong class="text-bold" style="color:var(--accent);"><?= e($pname) ?></strong>
                                <table style="width:100%;font-size:0.8rem;">
                                    <thead>
                                        <tr>
                                            <th>روز</th>
                                            <th>مانده ابتدا</th>
                                            <th>تغییرات</th>
                                            <th>مانده انتها</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($daily_rows as $dr): ?>
                                        <tr>
                                            <td><?= to_jalali($dr['balance_date'], 'Y/m/d') ?></td>
                                            <td style="font-family:var(--mono)"><?= format_rial($dr['opening_balance']) ?></td>
                                            <td style="font-family:var(--mono)" class="<?= $dr['daily_change'] > 0 ? 'text-profit' : ($dr['daily_change'] < 0 ? 'text-danger' : '') ?>">
                                                <?= $dr['daily_change'] != 0 ? ($dr['daily_change'] > 0 ? '+' : '') . format_rial($dr['daily_change']) : '-' ?>
                                            </td>
                                            <td style="font-family:var(--mono)" class="text-bold"><?= format_rial($dr['closing_balance']) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<script>
function showSettlementTab(tabId, clickedTab) {
    // Hide all tabs
    document.querySelectorAll('.settlement-tab').forEach(function(el) {
        el.style.display = 'none';
    });
    // Remove active from all tab links
    document.querySelectorAll('#settlement-tabs .tab').forEach(function(el) {
        el.classList.remove('active');
    });
    // Show selected tab
    document.getElementById(tabId).style.display = '';
    clickedTab.classList.add('active');
}

function toggleDailyDetails(id) {
    var el = document.getElementById(id);
    if (el) {
        el.style.display = el.style.display === 'none' ? '' : 'none';
    }
}
</script>
