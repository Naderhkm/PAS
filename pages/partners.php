<?php
require_once __DIR__ . '/../config.php';

$error = '';
$success = '';

// Handle delete partner (and its transactions)
if (isset($_POST['delete_partner'])) {
    verify_csrf();
    $pid = $_POST['delete_partner'];
    $pdo->beginTransaction();
    try {
        // حذف مانده‌های روزانه شریک
        $pdo->prepare("DELETE FROM partner_daily_balances WHERE partner_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM settlement_daily_balances WHERE partner_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM partner_transactions WHERE partner_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM partners WHERE id = ?")->execute([$pid]);
        recalculate_percentages($pdo);
        $pdo->commit();
        header('Location: ?page=partners&success=partner_deleted');
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = 'خطا در حذف شریک: ' . $e->getMessage();
    }
}

// Handle delete transaction
if (isset($_POST['delete_transaction'])) {
    verify_csrf();
    $txn_id = $_POST['delete_transaction'];

    $stmt_txn = $pdo->prepare("SELECT partner_id, partner_name, transaction_date, type, amount FROM partner_transactions WHERE id = ?");
    $stmt_txn->execute([$txn_id]);
    $txn = $stmt_txn->fetch(PDO::FETCH_ASSOC);

    if ($txn) {
        $txn_date = $txn['transaction_date'];
        $txn_partner_name = $txn['partner_name'];
        $pdo->beginTransaction();
        try {
            // Reverse the transaction effect on partner's amount
            if ($txn['type'] === 'واریز' || $txn['type'] === 'آورده') {
                $pdo->prepare("UPDATE partners SET amount = amount - ? WHERE id = ?")->execute([$txn['amount'], $txn['partner_id']]);
            } elseif ($txn['type'] === 'برداشت') {
                $pdo->prepare("UPDATE partners SET amount = amount + ? WHERE id = ?")->execute([$txn['amount'], $txn['partner_id']]);
            }

            $pdo->prepare("DELETE FROM partner_transactions WHERE id = ?")->execute([$txn_id]);
            recalculate_percentages($pdo);
            $pdo->commit();

            // بروزرسانی مانده روزانه بعد از حذف تراکنش
            // برای تمام تاریخ‌هایی که تراکنش داشته‌ایم
            $stmt_daily = $pdo->prepare("SELECT DISTINCT transaction_date FROM partner_transactions
                WHERE partner_id = ? AND transaction_date >= ? ORDER BY transaction_date ASC");
            $stmt_daily->execute([$txn['partner_id'], $txn_date]);
            $remaining_dates = $stmt_daily->fetchAll(PDO::FETCH_COLUMN);
            foreach ($remaining_dates as $rd) {
                update_daily_balance_from_transaction($pdo, $txn['partner_id'], $txn_partner_name, $rd);
            }

            header('Location: ?page=partners&success=transaction_deleted');
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'خطا در حذف تراکنش: ' . $e->getMessage();
        }
    }
}

// Handle add partner
if (isset($_POST['save_partner'])) {
    verify_csrf();
    $account_name = trim($_POST['account_name']);
    if ($account_name === '') {
        $error = 'نام طرف حساب را وارد کنید.';
    } else {
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM partners WHERE account_name = ?");
        $stmt_check->execute([$account_name]);
        if ($stmt_check->fetchColumn() > 0) {
            $error = 'طرف حساب با این نام قبلاً ثبت شده است.';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO partners (account_name, amount, percentage) VALUES (?, 0, 0)")->execute([$account_name]);
                recalculate_percentages($pdo);
                $pdo->commit();
                header('Location: ?page=partners&success=partner_added');
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'خطا در افزودن شریک: ' . $e->getMessage();
            }
        }
    }
}

// Handle add transaction
if (isset($_POST['save_transaction'])) {
    verify_csrf();
    $partner_id = $_POST['partner_id'];
    $type = $_POST['type'];
    $amount = (int)str_replace(',', '', $_POST['amount'] ?: 0);
    $description = trim($_POST['description']);
    $transaction_date = to_gregorian($_POST['transaction_date']) ?: date('Y-m-d');
    $partner_name = $_POST['partner_name'];

    if (!$partner_id) {
        $error = 'لطفاً طرف حساب را انتخاب کنید.';
    } elseif (!validate_jalali_date($_POST['transaction_date'])) {
        $error = 'تاریخ تراکنش فرمت صحیح ندارد (مثال: 1405/04/23)';
    } elseif (!validate_number($amount, 1)) {
        $error = 'مبلغ باید بیشتر از صفر باشد.';
    }

    if (!$error && $partner_id && $amount > 0) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO partner_transactions (partner_id, partner_name, transaction_date, description, amount, type) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$partner_id, $partner_name, $transaction_date, $description, $amount, $type]);

            if ($type === 'واریز') {
                $pdo->prepare("UPDATE partners SET amount = amount + ? WHERE id = ?")->execute([$amount, $partner_id]);
            } elseif ($type === 'برداشت') {
                $stmt_bal = $pdo->prepare("SELECT amount FROM partners WHERE id = ?");
                $stmt_bal->execute([$partner_id]);
                $current = $stmt_bal->fetchColumn();
                if ($current < $amount) {
                    $pdo->rollBack();
                    $error = 'مبلغ برداشت از موجودی شریک بیشتر است.';
                } else {
                    $pdo->prepare("UPDATE partners SET amount = amount - ? WHERE id = ?")->execute([$amount, $partner_id]);
                }
            }

            if (!$error) {
                recalculate_percentages($pdo);
                $pdo->commit();

                // ثبت خودکار مانده روزانه برای این شریک
                update_daily_balance_from_transaction($pdo, $partner_id, $partner_name, $transaction_date);

                header('Location: ?page=partners&success=transaction_added');
                exit;
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'خطا در ثبت تراکنش: ' . $e->getMessage();
        }
    }
}

// Get data
$partners = $pdo->query("SELECT * FROM partners ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$total_amount = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM partners")->fetchColumn();
$transactions = $pdo->query("SELECT * FROM partner_transactions ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<h1>شرکا</h1>

<?php if ($error): ?>
<div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<div class="stats">
    <div class="stat-card">
        <h3>جمع آورده شرکا</h3>
        <div class="value"><?= format_rial($total_amount) ?> ریال</div>
    </div>
    <div class="stat-card">
        <h3>تعداد شرکا</h3>
        <div class="value"><?= count($partners) ?> نفر</div>
    </div>
</div>

<div class="card">
    <h2>افزودن شریک</h2>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="save_partner" value="1">
        <div class="form-grid" style="grid-template-columns: 1fr auto;">
            <div class="form-group">
                <label>نام طرف حساب</label>
                <input type="text" name="account_name" required placeholder="نام شریک" autofocus>
            </div>
            <div class="form-group" style="justify-content:flex-end;">
                <button type="submit" class="btn btn-primary">افزودن شریک</button>
            </div>
        </div>
    </form>
</div>

<div class="card">
    <h2>ثبت تراکنش</h2>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="save_transaction" value="1">
        <div class="form-grid">
            <div class="form-group">
                <label>طرف حساب</label>
                <select name="partner_id" id="partner_select" required onchange="document.getElementById('partner_name').value = this.options[this.selectedIndex].getAttribute('data-name');">
                    <option value="">انتخاب کنید...</option>
                    <?php foreach ($partners as $p): ?>
                    <option value="<?= e($p['id']) ?>" data-name="<?= e($p['account_name']) ?>"><?= e($p['account_name']) ?> (<?= format_rial($p['amount']) ?> ریال)</option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="partner_name" id="partner_name">
            </div>
            <div class="form-group">
                <label>نوع تراکنش</label>
                <select name="type" required>
                    <option value="واریز">واریز (افزایش سرمایه)</option>
                    <option value="برداشت">برداشت (کاهش سرمایه)</option>
                </select>
            </div>
            <div class="form-group">
                <label>مبلغ (ریال)</label>
                <input type="text" class="num-format" name="amount" required>
            </div>
            <div class="form-group">
                <label>تاریخ (شمسی)</label>
                <input type="text" name="transaction_date" placeholder="1405/03/25" required>
            </div>
            <div class="form-group">
                <label>شرح</label>
                <input type="text" name="description" placeholder="شرح تراکنش">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">ثبت تراکنش</button>
    </form>
</div>

<div class="card">
    <h2>لیست شرکا</h2>
    <table>
        <thead>
            <tr>
                <th>طرف حساب</th>
                <th>جمع آورده (ریال)</th>
                <th>مانده فعلی (ریال)</th>
                <th>درصد سهم</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($partners as $row): ?>
            <?php
            // محاسبه مانده فعلی از تراکنش‌ها
            $current_balance = get_partner_current_balance($pdo, $row['account_name']);
            ?>
            <tr>
                <td class="text-bold"><?= e($row['account_name']) ?></td>
                <td><?= format_rial($row['amount']) ?></td>
                <td class="text-bold <?= $current_balance >= 0 ? 'text-profit' : 'text-danger' ?>">
                    <?= format_rial($current_balance) ?>
                </td>
                <td><?= round($row['percentage'] * 100, 2) ?>%</td>
                <td>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('با حذف شریک، تراکنش‌هایش هم حذف می‌شوند. ادامه می‌دهید؟')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="delete_partner" value="<?= e($row['id']) ?>">
                        <button type="submit" class="btn btn-danger btn-sm">حذف</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if ($partners): ?>
            <tr class="text-bold bg-subtle border-bottom-bold">
                <td>جمع کل</td>
                <td><?= format_rial($total_amount) ?></td>
                <td></td>
                <td>100%</td>
                <td></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>تراکنش‌ها</h2>
    <div class="table-responsive">
    <table>
        <thead>
            <tr>
                <th>طرف حساب</th>
                <th>تاریخ</th>
                <th>نوع</th>
                <th>مبلغ (ریال)</th>
                <th>شرح</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($transactions as $row): ?>
            <tr>
                <td><?= e($row['partner_name']) ?></td>
                <td><?= to_jalali($row['transaction_date']) ?></td>
                <td>
                    <?php if ($row['type'] === 'واریز'): ?>
                        <span class="badge badge-success status-paid">واریز</span>
                    <?php elseif ($row['type'] === 'تسویه سود'): ?>
                        <span class="badge badge-purple text-purple">تسویه سود</span>
                    <?php else: ?>
                        <span class="badge badge-warning status-unpaid">برداشت</span>
                    <?php endif; ?>
                </td>
                <td><?= format_rial($row['amount']) ?></td>
                <td><?= e($row['description']) ?></td>
                <td>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('حذف شود؟')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="delete_transaction" value="<?= e($row['id']) ?>">
                        <button type="submit" class="btn btn-danger btn-sm">حذف</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$transactions): ?>
            <tr><td colspan="6" class="text-muted text-center">تراکنشی ثبت نشده</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
