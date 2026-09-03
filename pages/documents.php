<?php
require_once __DIR__ . '/../config.php';

$error = '';

// Handle status toggle
if (isset($_POST['toggle_status'])) {
    verify_csrf();
    $doc_id = (int)$_POST['toggle_status'];
    $new_status = $_POST['new_status'] ?? 'در انتظار';
    if (in_array($new_status, $valid_statuses)) {
        $pdo->prepare("UPDATE documents SET status = ? WHERE id = ?")->execute([$new_status, $doc_id]);
    }
    header('Location: ?page=documents');
    exit;
}

// Handle delete
if (isset($_POST['delete'])) {
    verify_csrf();
    $stmt = $pdo->prepare("DELETE FROM documents WHERE id = ?");
    $stmt->execute([$_POST['delete']]);
    header('Location: ?page=documents');
    exit;
}

// Handle add/edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete']) && !isset($_POST['toggle_status'])) {
    verify_csrf();
    $errors = [];

    if (!validate_jalali_date($_POST['doc_date'])) {
        $errors[] = 'تاریخ سند فرمت صحیح ندارد (مثال: 1405/04/23)';
    }
    if (!validate_required($_POST['doc_number'])) {
        $errors[] = 'شماره سند الزامی است.';
    }
    if (!validate_required($_POST['seller'])) {
        $errors[] = 'نام فروشنده الزامی است.';
    }
    if (!validate_required($_POST['buyer'])) {
        $errors[] = 'نام خریدار الزامی است.';
    }
    if (!validate_number($_POST['weight_kg'], 0, 1000000)) {
        $errors[] = 'وزن معتبر نیست.';
    }
    if (!validate_number($_POST['purchase_rials'], 0)) {
        $errors[] = 'مبلغ خرید معتبر نیست.';
    }
    if (!validate_jalali_date($_POST['payment_date'])) {
        $errors[] = 'تاریخ پرداخت فرمت صحیح ندارد.';
    }

    if (!empty($errors)) {
        $error = implode('<br>', $errors);
    } else {
        // Calculate derived fields
        $weight = (int)str_replace(',', '', $_POST['weight_kg'] ?: 0);
        $loss = (int)str_replace(',', '', $_POST['loss_kg'] ?: 0);
        $purchase = (int)str_replace(',', '', $_POST['purchase_rials'] ?: 0);
        $deductionRate = (int)str_replace(',', '', $_POST['deduction_rials'] ?: 0);
        $bonusRate = (int)str_replace(',', '', $_POST['bonus'] ?: 0);

        $netWeight = $weight - $loss;
        $deductionAmount = $netWeight * $deductionRate;
        $bonusAmount = $netWeight * $bonusRate;
        $sale = $purchase + $deductionAmount + $bonusAmount;
        $profit = $deductionAmount + $bonusAmount;

        // Auto row_num: next number
        $max_row = $pdo->query("SELECT COALESCE(MAX(row_num), 0) FROM documents")->fetchColumn();
        $row_num = $max_row + 1;

        // Auto month from date
        $month_name = auto_month_from_date($_POST['doc_date']);

        $data = [
            'row_num' => $row_num,
            'doc_date' => to_gregorian($_POST['doc_date']) ?: null,
            'doc_number' => $_POST['doc_number'],
            'seller' => $_POST['seller'],
            'buyer' => $_POST['buyer'],
            'weight_kg' => $weight,
            'loss_kg' => $loss,
            'purchase_rials' => $purchase,
            'sale_rials' => $sale,
            'profit_rials' => $profit,
            'deduction_rials' => $deductionRate,
            'bonus' => $bonusRate,
            'payment_date' => to_gregorian($_POST['payment_date']) ?: null,
            'status' => 'در انتظار',
            'month_name' => $month_name,
        ];

        if (!empty($_POST['id'])) {
            // Edit: keep original row_num
            $sql = "UPDATE documents SET doc_date=:doc_date, doc_number=:doc_number,
                    seller=:seller, buyer=:buyer, weight_kg=:weight_kg, loss_kg=:loss_kg,
                    purchase_rials=:purchase_rials, sale_rials=:sale_rials, profit_rials=:profit_rials,
                    deduction_rials=:deduction_rials, bonus=:bonus, payment_date=:payment_date,
                    month_name=:month_name WHERE id=:id";
            $data['id'] = $_POST['id'];
            unset($data['row_num'], $data['status']);
        } else {
            $sql = "INSERT INTO documents (row_num, doc_date, doc_number, seller, buyer, weight_kg, loss_kg,
                    purchase_rials, sale_rials, profit_rials, deduction_rials, bonus, payment_date, status, month_name)
                    VALUES (:row_num, :doc_date, :doc_number, :seller, :buyer, :weight_kg, :loss_kg,
                    :purchase_rials, :sale_rials, :profit_rials, :deduction_rials, :bonus, :payment_date, :status, :month_name)";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);
        header('Location: ?page=documents');
        exit;
    }
}

// Fetch for edit
$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<h1>اسناد</h1>

<?php if (!empty($error)): ?>
<div class="alert alert-error"><?= $error ?></div>
<?php endif; ?>

<div class="card">
    <h2><?= $edit ? 'ویرایش سند' : 'سند جدید' ?></h2>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
        <div class="form-grid">
            <div class="form-group">
                <label>تاریخ سند</label>
                <input type="text" id="doc_date" name="doc_date" placeholder="1405/03/25" value="<?= $edit ? to_jalali($edit['doc_date'], 'Y/m/d') : to_jalali(date('Y-m-d'), 'Y/m/d') ?>">
            </div>
            <div class="form-group">
                <label>شماره سند</label>
                <input type="text" name="doc_number" value="<?= e($edit['doc_number'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>فروشنده</label>
                <input type="text" name="seller" value="<?= e($edit['seller'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>خریدار</label>
                <input type="text" name="buyer" value="<?= e($edit['buyer'] ?? 'نوآوران سبز احیا') ?>">
            </div>
            <div class="form-group">
                <label>وزن (کیلوگرم)</label>
                <input type="text" class="num-format" name="weight_kg" value="<?= e($edit['weight_kg'] ?? '') ?>" oninput="autoCalc()">
            </div>
            <div class="form-group">
                <label>افت (کیلوگرم)</label>
                <input type="text" class="num-format" name="loss_kg" value="<?= e($edit['loss_kg'] ?? '') ?>" oninput="autoCalc()">
            </div>
            <div class="form-group">
                <label>کسر (ریال/kg)</label>
                <input type="text" class="num-format" id="deduction_rials" name="deduction_rials" value="<?= e($edit['deduction_rials'] ?? '') ?>" oninput="autoCalc()">
            </div>
            <div class="form-group">
                <label>پاداش (ریال/kg)</label>
                <input type="text" class="num-format" id="bonus" name="bonus" value="<?= e($edit['bonus'] ?? '') ?>" oninput="autoCalc()">
            </div>
            <div class="form-group">
                <label>خرید (ریال)</label>
                <input type="text" class="num-format" id="purchase_rials" name="purchase_rials" value="<?= e($edit['purchase_rials'] ?? '') ?>" oninput="autoCalc()">
            </div>
            <div class="form-group">
                <label>فروش (ریال)</label>
                <input type="text" class="num-format" id="sale_rials" name="sale_rials" value="<?= e($edit['sale_rials'] ?? '') ?>" readonly style="background:var(--bg);color:var(--muted);">
            </div>
            <div class="form-group">
                <label>سود (ریال)</label>
                <input type="text" class="num-format" id="profit_rials" name="profit_rials" value="<?= e($edit['profit_rials'] ?? '') ?>" readonly style="background:var(--bg);color:var(--muted);">
            </div>
            <div class="form-group">
                <label>تاریخ پرداخت</label>
                <input type="text" name="payment_date" placeholder="1405/03/25" value="<?= $edit ? to_jalali($edit['payment_date'], 'Y/m/d') : '' ?>">
            </div>
        </div>
        <div class="flex-row">
            <button type="submit" class="btn btn-primary"><?= $edit ? 'بروزرسانی' : 'افزودن' ?></button>
            <?php if ($edit): ?>
            <a href="?page=documents" class="btn btn-secondary">انصراف</a>
            <?php endif; ?>
        </div>
    </form>

    <script>
    function fmt(n) {
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
    function unfmt(s) {
        return s.replace(/,/g, '');
    }

    function autoCalc() {
        const weight = parseInt(unfmt(document.querySelector('[name="weight_kg"]').value)) || 0;
        const loss = parseInt(unfmt(document.querySelector('[name="loss_kg"]').value)) || 0;
        const purchase = parseInt(unfmt(document.getElementById('purchase_rials').value)) || 0;
        const deductionRate = parseInt(unfmt(document.getElementById('deduction_rials').value)) || 0;
        const bonusRate = parseInt(unfmt(document.getElementById('bonus').value)) || 0;

        const netWeight = weight - loss;
        const deductionAmount = netWeight * deductionRate;
        const bonusAmount = netWeight * bonusRate;
        const sale = purchase + deductionAmount + bonusAmount;
        const profit = deductionAmount + bonusAmount;

        document.getElementById('sale_rials').value = fmt(sale);
        document.getElementById('profit_rials').value = fmt(profit);
    }

    document.addEventListener('DOMContentLoaded', function() {
        autoCalc();
    });
    </script>
</div>

<div class="card">
    <div class="page-header" style="margin-bottom:16px;">
        <h2 style="margin-bottom:0;">لیست اسناد</h2>
        <a href="export_documents.php?<?= e(http_build_query($_GET)) ?>" class="btn btn-secondary btn-sm">خروجی اکسل</a>
    </div>

    <form method="GET" style="margin-bottom:20px;">
        <input type="hidden" name="page" value="documents">
        <div class="form-grid" style="grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));">
            <div class="form-group">
                <label>جستجو</label>
                <input type="text" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="شماره سند، فروشنده...">
            </div>
            <div class="form-group">
                <label>فروشنده</label>
                <input type="text" name="seller" value="<?= e($_GET['seller'] ?? '') ?>" placeholder="نام فروشنده">
            </div>
            <div class="form-group">
                <label>خریدار</label>
                <input type="text" name="buyer" value="<?= e($_GET['buyer'] ?? '') ?>" placeholder="نام خریدار">
            </div>
            <div class="form-group">
                <label>وضعیت</label>
                <select name="status">
                    <option value="">همه</option>
                    <?php foreach ($valid_statuses as $s): ?>
                    <option value="<?= $s ?>" <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>ماه</label>
                <select name="month_name">
                    <option value="">همه</option>
                    <?php foreach ($months_fa_num as $m): ?>
                    <option value="<?= $m ?>" <?= ($_GET['month_name'] ?? '') === $m ? 'selected' : '' ?>><?= $m ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="justify-content:flex-end;">
                <button type="submit" class="btn btn-primary">فیلتر</button>
            </div>
        </div>
    </form>

    <div class="table-responsive">
    <table>
        <thead>
            <tr>
                <th>ردیف</th>
                <th>تاریخ</th>
                <th>شماره</th>
                <th>فروشنده</th>
                <th>خریدار</th>
                <th>وزن</th>
                <th>خرید</th>
                <th>فروش</th>
                <th>سود</th>
                <th>وضعیت</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php
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
            $result = paginate($pdo, "SELECT * FROM documents $where_sql ORDER BY id DESC", $params, 15);
            foreach ($result['data'] as $row):
            ?>
            <tr>
                <td><?= e($row['row_num']) ?></td>
                <td><?= to_jalali($row['doc_date']) ?></td>
                <td><?= e($row['doc_number']) ?></td>
                <td><?= e($row['seller']) ?></td>
                <td><?= e($row['buyer']) ?></td>
                <td style="font-family:var(--mono)"><?= format_rial($row['weight_kg']) ?></td>
                <td><?= format_rial($row['purchase_rials']) ?></td>
                <td><?= format_rial($row['sale_rials']) ?></td>
                <td><?= format_rial($row['profit_rials']) ?></td>
                <td>
                    <?php if ($row['status'] === 'تسویه شده'): ?>
                        <span class="badge badge-success status-paid">تسویه شده</span>
                    <?php elseif ($row['status'] === 'لغو شده'): ?>
                        <span class="badge badge-muted status-cancelled">لغو شده</span>
                    <?php else: ?>
                        <span class="badge badge-warning status-unpaid">در انتظار</span>
                    <?php endif; ?>
                </td>
                <td class="actions">
                    <?php if ($row['status'] === 'تسویه شده'): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('برگشت به در انتظار؟')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="toggle_status" value="<?= e($row['id']) ?>">
                        <input type="hidden" name="new_status" value="در انتظار">
                        <button type="submit" class="btn btn-sm btn-secondary">برگشت</button>
                    </form>
                    <?php elseif ($row['status'] !== 'لغو شده'): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('تغییر وضعیت به تسویه شده؟')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="toggle_status" value="<?= e($row['id']) ?>">
                        <input type="hidden" name="new_status" value="تسویه شده">
                        <button type="submit" class="btn btn-sm btn-primary">تسویه</button>
                    </form>
                    <?php endif; ?>
                    <a href="?page=documents&edit=<?= $row['id'] ?>" class="btn btn-secondary btn-sm">ویرایش</a>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('حذف شود؟')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="delete" value="<?= e($row['id']) ?>">
                        <button type="submit" class="btn btn-danger btn-sm">حذف</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php
    $filter_params = $_GET;
    unset($filter_params['p']);
    $filter_url = '?' . http_build_query($filter_params) . '&page=documents';
    render_pagination($result['page'], $result['total_pages'], $filter_url);
    ?>
</div>
