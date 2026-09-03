<?php
session_start();

$host = 'localhost';
$db   = 'jari_shoka';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("خطا در اتصال به پایگاه داده: " . $e->getMessage());
}

// Authentication check (skip for login.php and setup scripts)
$current_file = basename($_SERVER['SCRIPT_NAME'] ?? '');
if (!in_array($current_file, ['login.php', 'setup.php'])) {
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

// ─── Shared Month Arrays ──────────────────────────────────────────
$months_fa_num = [
    1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
    4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
    7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
    10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
];

$months_fa_key = [
    'farvardin' => 'فروردین', 'ordibehesht' => 'اردیبهشت', 'khordad' => 'خرداد',
    'tir' => 'تیر', 'mordad' => 'مرداد', 'shahrivar' => 'شهریور',
    'mehr' => 'مهر', 'aban' => 'آبان', 'azar' => 'آذر',
    'dey' => 'دی', 'bahman' => 'بهمن', 'esfand' => 'اسفند',
];

$jalali_month_map = [
    1 => 'farvardin', 2 => 'ordibehesht', 3 => 'khordad',
    4 => 'tir', 5 => 'mordad', 6 => 'shahrivar',
    7 => 'mehr', 8 => 'aban', 9 => 'azar',
    10 => 'dey', 11 => 'bahman', 12 => 'esfand',
];

$valid_statuses = ['تسویه شده', 'در انتظار', 'لغو شده'];

// ─── Formatting Helpers ───────────────────────────────────────────
function format_rial($amount) {
    return number_format((float)($amount ?? 0), 0, '', ',');
}

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// ─── Pagination ───────────────────────────────────────────────────
function paginate($pdo, $query, $params = [], $per_page = 20) {
    $page = max(1, (int)($_GET['p'] ?? 1));
    $offset = ($page - 1) * $per_page;

    // Count total rows using a subquery to avoid regex manipulation
    $count_query = "SELECT COUNT(*) as cnt FROM ($query) AS _cnt";
    $stmt = $pdo->prepare($count_query);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $total_pages = max(1, ceil($total / $per_page));
    $page = min($page, $total_pages);

    $offset = (int) $offset;
    $per_page = (int) $per_page;
    $stmt = $pdo->prepare($query . " LIMIT $per_page OFFSET $offset");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return ['data' => $data, 'page' => $page, 'total' => $total, 'total_pages' => $total_pages];
}

function render_pagination($page, $total_pages, $base_url = '?') {
    if ($total_pages <= 1) return;

    $sep = strpos($base_url, '?') !== false ? '&' : '?';
    echo '<div class="pagination">';

    if ($page > 1) {
        echo "<a href=\"{$base_url}{$sep}p=" . ($page - 1) . "\">« قبلی</a>";
    }

    for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++) {
        if ($i == $page) {
            echo "<span class=\"current\">$i</span>";
        } else {
            echo "<a href=\"{$base_url}{$sep}p=$i\">$i</a>";
        }
    }

    if ($page < $total_pages) {
        echo "<a href=\"{$base_url}{$sep}p=" . ($page + 1) . "\">بعدی »</a>";
    }

    echo '</div>';
}

// ─── Validation ───────────────────────────────────────────────────
function validate_jalali_date($date) {
    if (empty($date)) return true;
    $parts = explode('/', $date);
    if (count($parts) !== 3) return false;
    $y = (int)$parts[0];
    $m = (int)$parts[1];
    $d = (int)$parts[2];
    if ($y < 1300 || $y > 1500) return false;
    if ($m < 1 || $m > 12) return false;
    if ($d < 1 || $d > 31) return false;
    return true;
}

function validate_number($value, $min = 0, $max = PHP_INT_MAX) {
    if ($value === '' || $value === null) return true;
    $num = (float)$value;
    return $num >= $min && $num <= $max;
}

function validate_required($value) {
    return trim($value ?? '') !== '';
}

// ─── Jalali Date Conversion ──────────────────────────────────────
function gregorian_to_jalali($gy, $gm, $gd) {
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $days = 355666 + (365 * $gy) + (int)(($gy + 3) / 4) - (int)(($gy + 99) / 100) + (int)(($gy + 399) / 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * (int)($days / 12053));
    $days %= 12053;
    $jy += 4 * (int)($days / 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + (int)($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

function to_jalali($date, $format = 'Y/m/d') {
    if (empty($date) || $date === '0000-00-00') return '';
    $parts = explode('-', $date);
    if (count($parts) !== 3) return $date;
    list($jy, $jm, $jd) = gregorian_to_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);
    $result = str_replace(['Y', 'm', 'd'], [$jy, str_pad($jm, 2, '0', STR_PAD_LEFT), str_pad($jd, 2, '0', STR_PAD_LEFT)], $format);
    return $result;
}

function to_jalali_full($date) {
    global $months_fa_num;
    if (empty($date) || $date === '0000-00-00') return '';
    list($jy, $jm, $jd) = gregorian_to_jalali(
        (int)substr($date, 0, 4),
        (int)substr($date, 5, 2),
        (int)substr($date, 8, 2)
    );
    return $jd . ' ' . ($months_fa_num[$jm] ?? '') . ' ' . $jy;
}

function auto_month_from_date($jalali_date) {
    global $months_fa_num;
    if (empty($jalali_date)) return '';
    $parts = explode('/', $jalali_date);
    if (count($parts) !== 3) return '';
    $month_num = (int)$parts[1];
    return $months_fa_num[$month_num] ?? '';
}

function jalali_to_gregorian($jy, $jm, $jd) {
    $jy += 1595;
    $days = -355667 + (365 * $jy) + ((int)($jy / 33) * 8) + (int)(($jy % 33 + 1) / 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30 + 186));
    $gy = 400 * (int)($days / 146097);
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * (int)(($days - 1) / 36524);
        $days = ($days - 1) % 36524;
        if ($days >= 365) $days++;
    }
    $gy += 4 * (int)($days / 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $g_d_m = [0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $gm = 1;
    while ($gm < 13 && $gd > $g_d_m[$gm]) {
        $gd -= $g_d_m[$gm];
        $gm++;
    }
    if ($gm == 3 && (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0))) {
        $gd--;
    }
    return [$gy, $gm, $gd];
}

function to_gregorian($jalali_date) {
    if (empty($jalali_date)) return null;
    $parts = explode('/', $jalali_date);
    if (count($parts) !== 3) return null;
    list($gy, $gm, $gd) = jalali_to_gregorian((int)$parts[0], (int)$parts[1], (int)$parts[2]);
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

// ─── CSRF Protection ─────────────────────────────────────────────
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function verify_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            die('خطای امنیتی: توکن CSRF نامعتبر است');
        }
    }
}

// ─── Shared Business Logic ───────────────────────────────────────

/**
 * Calculate monthly profit from documents, grouped by Jalali month.
 * Returns: [ '1405/03' => ['year'=>1405, 'month'=>3, 'profit'=>9435000, 'jalali_key'=>'khordad'], ... ]
 */
function get_monthly_profits($pdo) {
    global $jalali_month_map;
    $docs = $pdo->query("SELECT profit_rials, doc_date FROM documents WHERE profit_rials > 0 AND doc_date IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    $monthly = [];
    foreach ($docs as $doc) {
        $parts = explode('-', $doc['doc_date']);
        if (count($parts) === 3) {
            list($jy, $jm) = gregorian_to_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);
            $key = $jy . '/' . str_pad($jm, 2, '0', STR_PAD_LEFT);
            if (!isset($monthly[$key])) {
                $monthly[$key] = ['year' => $jy, 'month' => $jm, 'profit' => 0, 'jalali_key' => $jalali_month_map[$jm] ?? ''];
            }
            $monthly[$key]['profit'] += $doc['profit_rials'];
        }
    }
    ksort($monthly);
    return $monthly;
}

/**
 * دریافت سود روزانه از اسناد بر اساس تاریخ واقعی.
 * خروجی: [day_number => profit_amount, ...]
 * 
 * @param PDO $pdo
 * @param int $year سال شمسی
 * @param int $month ماه شمسی
 * @return array [day => profit, ...]
 */
function get_daily_profits_from_documents($pdo, $year, $month) {
    $days = get_days_in_jalali_month($year, $month);
    
    // تبدیل بازه ماه به تاریخ میلادی
    list($gy_start, $gm_start, $gd_start) = jalali_to_gregorian($year, $month, 1);
    list($gy_end, $gm_end, $gd_end) = jalali_to_gregorian($year, $month, $days);
    $g_start = sprintf('%04d-%02d-%02d', $gy_start, $gm_start, $gd_start);
    $g_end = sprintf('%04d-%02d-%02d', $gy_end, $gm_end, $gd_end);
    
    // دریافت تمام اسناد این ماه
    $stmt = $pdo->prepare("SELECT profit_rials, doc_date FROM documents 
        WHERE profit_rials > 0 AND doc_date IS NOT NULL 
        AND doc_date BETWEEN ? AND ?");
    $stmt->execute([$g_start, $g_end]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // گروه‌بندی بر اساس روز
    $daily_profits = array_fill(1, $days, 0);
    
    foreach ($docs as $doc) {
        $parts = explode('-', $doc['doc_date']);
        if (count($parts) === 3) {
            list($jy, $jm, $jd) = gregorian_to_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);
            if ($jy == $year && $jm == $month && $jd >= 1 && $jd <= $days) {
                $daily_profits[$jd] += $doc['profit_rials'];
            }
        }
    }
    
    return $daily_profits;
}

/**
 * Build a map of Jalali date keys => transactions, sorted chronologically.
 * Excludes 'تسویه سود' if $exclude_settlements is true.
 */
function get_transactions_by_jalali_date($pdo, $exclude_settlements = false) {
    $sql = "SELECT partner_name, transaction_date, amount, type FROM partner_transactions";
    if ($exclude_settlements) {
        $sql .= " WHERE type != 'تسویه سود'";
    }
    $sql .= " ORDER BY transaction_date ASC, id ASC";
    $all_txns = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $txn_by_date = [];
    foreach ($all_txns as $txn) {
        if ($txn['transaction_date']) {
            $parts = explode('-', $txn['transaction_date']);
            if (count($parts) === 3) {
                list($jy, $jm, $jd) = gregorian_to_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);
                $date_key = $jy . '-' . str_pad($jm, 2, '0', STR_PAD_LEFT) . '-' . str_pad($jd, 2, '0', STR_PAD_LEFT);
                $txn_by_date[$date_key][] = $txn;
            }
        }
    }
    ksort($txn_by_date);
    return $txn_by_date;
}

/**
 * Recalculate all partner percentages based on their current amounts.
 */
function recalculate_percentages($pdo) {
    $total = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM partners")->fetchColumn();
    if ($total > 0) {
        $stmt = $pdo->query("SELECT id, amount FROM partners");
        while ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pct = $p['amount'] / $total;
            $pdo->prepare("UPDATE partners SET percentage = ? WHERE id = ?")->execute([$pct, $p['id']]);
        }
    }
}

/**
 * Get number of days in a Jalali month.
 */
function get_days_in_jalali_month($year, $month) {
    if ($month <= 6) return 31;
    if ($month <= 11) return 30;
    $residues = [1, 5, 9, 13, 17, 22, 26, 30];
    return in_array($year % 33, $residues) ? 30 : 29;
}

/**
 * Fetch all non-settlement transactions sorted by date.
 */
function get_all_balance_transactions($pdo) {
    return $pdo->query("SELECT partner_name, transaction_date, amount, type FROM partner_transactions WHERE type != 'تسویه سود' ORDER BY transaction_date ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * محاسبه مانده ابتدای روز برای یک شریک در یک تاریخ مشخص.
 * این تابع تمام تراکنش‌های قبل از آن تاریخ را جمع می‌زند.
 */
function get_opening_balance_for_date($pdo, $partner_name, $gregorian_date) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(
        CASE
            WHEN type IN ('واریز', 'آورده') THEN amount
            WHEN type = 'برداشت' THEN -amount
            ELSE 0
        END
    ), 0) FROM partner_transactions
    WHERE partner_name = ? AND transaction_date < ?");
    $stmt->execute([$partner_name, $gregorian_date]);
    return (int)$stmt->fetchColumn();
}

/**
 * محاسبه مانده روزانه یک شریک در یک ماه شمسی مشخص.
 * برای هر روز ماه، opening و closing balance را محاسبه می‌کند.
 * 
 * @param string $partner_name نام شریک
 * @param int $year سال شمسی
 * @param int $month ماه شمسی
 * @return array ['days' => [[day, gregorian_date, opening, closing, change], ...], 'effective' => int, 'closing' => int]
 */
function calculate_partner_monthly_daily_balances($pdo, $partner_name, $year, $month) {
    $days = get_days_in_jalali_month($year, $month);

    // تبدیل اولین روز ماه به میلادی
    list($gy_start, $gm_start, $gd_start) = jalali_to_gregorian($year, $month, 1);
    $g_start = sprintf('%04d-%02d-%02d', $gy_start, $gm_start, $gd_start);

    // دریافت تراکنش‌های این شریک در این ماه
    list($gy_end, $gm_end, $gd_end) = jalali_to_gregorian($year, $month, $days);
    $g_end = sprintf('%04d-%02d-%02d', $gy_end, $gm_end, $gd_end);

    $stmt = $pdo->prepare("SELECT transaction_date, amount, type FROM partner_transactions
        WHERE partner_name = ? AND type != 'تسویه سود'
        AND transaction_date BETWEEN ? AND ?
        ORDER BY transaction_date ASC, id ASC");
    $stmt->execute([$partner_name, $g_start, $g_end]);
    $month_txns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // محاسبه مانده ابتدای ماه
    $opening_balance = get_opening_balance_for_date($pdo, $partner_name, $g_start);

    // ساخت آرایه تراکنش‌ها بر اساس تاریخ میلادی
    $txns_by_date = [];
    foreach ($month_txns as $txn) {
        $txns_by_date[$txn['transaction_date']][] = $txn;
    }

    // محاسبه روزانه
    $balance = $opening_balance;
    $daily = [];
    $total_weighted = 0;
    $current_date = $g_start;

    for ($day = 1; $day <= $days; $day++) {
        // اعمال تراکنش‌های این روز
        $change = 0;
        if (isset($txns_by_date[$current_date])) {
            foreach ($txns_by_date[$current_date] as $txn) {
                if ($txn['type'] === 'واریز' || $txn['type'] === 'آورده') {
                    $change += $txn['amount'];
                } elseif ($txn['type'] === 'برداشت') {
                    $change -= $txn['amount'];
                }
            }
        }

        $opening = $balance;
        $balance += $change;
        $closing = $balance;

        $daily[] = [
            'day' => $day,
            'jalali_date' => $year . '/' . str_pad($month, 2, '0', STR_PAD_LEFT) . '/' . str_pad($day, 2, '0', STR_PAD_LEFT),
            'gregorian_date' => $current_date,
            'opening' => $opening,
            'closing' => $closing,
            'change' => $change,
        ];

        $total_weighted += $closing;
        $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
    }

    $effective = $days > 0 ? (int)round($total_weighted / $days) : $opening_balance;

    return [
        'days' => $daily,
        'effective' => $effective,
        'closing' => $balance,
        'total_weighted' => $total_weighted,
        'days_count' => $days,
    ];
}

/**
 * ذخیره مانده روزانه شرکا در جدول partner_daily_balances.
 * این تابع باید بعد از ثبت هر تراکنش فراخوانی شود.
 * 
 * @param int $partner_id
 * @param string $partner_name
 * @param string $transaction_date تاریخ میلادی تراکنش (Y-m-d)
 * @param PDO $pdo
 */
function update_daily_balance_from_transaction($pdo, $partner_id, $partner_name, $transaction_date) {
    // محاسبه مانده ابتدای روز
    $opening = get_opening_balance_for_date($pdo, $partner_name, $transaction_date);

    // محاسبه تغییرات این روز
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(
        CASE
            WHEN type IN ('واریز', 'آورده') THEN amount
            WHEN type = 'برداشت' THEN -amount
            ELSE 0
        END
    ), 0) FROM partner_transactions
    WHERE partner_name = ? AND transaction_date = ?");
    $stmt->execute([$partner_name, $transaction_date]);
    $daily_change = (int)$stmt->fetchColumn();

    $closing = $opening + $daily_change;

    // ذخیره یا بروزرسانی
    $stmt = $pdo->prepare("INSERT INTO partner_daily_balances
        (partner_id, partner_name, balance_date, opening_balance, closing_balance, daily_change)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            opening_balance = VALUES(opening_balance),
            closing_balance = VALUES(closing_balance),
            daily_change = VALUES(daily_change)");
    $stmt->execute([$partner_id, $partner_name, $transaction_date, $opening, $closing, $daily_change]);

    // بروزرسانی روزهای بعدی (چون closing balance تغییر کرده)
    $stmt_next = $pdo->prepare("SELECT DISTINCT balance_date FROM partner_daily_balances
        WHERE partner_id = ? AND balance_date > ? ORDER BY balance_date ASC");
    $stmt_next->execute([$partner_id, $transaction_date]);
    $next_dates = $stmt_next->fetchAll(PDO::FETCH_COLUMN);

    $prev_closing = $closing;
    foreach ($next_dates as $next_date) {
        $stmt_upd = $pdo->prepare("UPDATE partner_daily_balances
            SET opening_balance = ?, closing_balance = ? + daily_change
            WHERE partner_id = ? AND balance_date = ?");
        $stmt_upd->execute([$prev_closing, $prev_closing, $partner_id, $next_date]);

        // دریافت closing جدید
        $stmt_get = $pdo->prepare("SELECT closing_balance FROM partner_daily_balances
            WHERE partner_id = ? AND balance_date = ?");
        $stmt_get->execute([$partner_id, $next_date]);
        $prev_closing = (int)$stmt_get->fetchColumn();
    }
}

/**
 * محاسبه مانده روزانه برای تمام شرکا در یک ماه شمسی.
 * این تابع از جدول partner_daily_balances می‌خواند (سریع‌تر).
 * 
 * @return array ['partner_name' => ['daily' => [...], 'effective' => int, 'closing' => int], ...]
 */
function get_all_partners_monthly_balances($pdo, $partners, $year, $month) {
    $days = get_days_in_jalali_month($year, $month);
    list($gy_start, $gm_start, $gd_start) = jalali_to_gregorian($year, $month, 1);
    list($gy_end, $gm_end, $gd_end) = jalali_to_gregorian($year, $month, $days);
    $g_start = sprintf('%04d-%02d-%02d', $gy_start, $gm_start, $gd_start);
    $g_end = sprintf('%04d-%02d-%02d', $gy_end, $gm_end, $gd_end);

    $results = [];

    // بررسی وجود جدول cache
    $cache_available = true;
    try {
        $pdo->query("SELECT 1 FROM partner_daily_balances LIMIT 1");
    } catch (PDOException $e) {
        $cache_available = false;
    }

    foreach ($partners as $p) {
        $name = $p['account_name'];
        $cached = [];

        // اگر جدول cache وجود داره، از اون بخوان
        if ($cache_available) {
            $stmt = $pdo->prepare("SELECT balance_date, opening_balance, closing_balance, daily_change
                FROM partner_daily_balances
                WHERE partner_id = ? AND balance_date BETWEEN ? AND ?
                ORDER BY balance_date ASC");
            $stmt->execute([$p['id'], $g_start, $g_end]);
            $cached = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (count($cached) === $days) {
            // داده کامل cached است
            $total_weighted = 0;
            foreach ($cached as $row) {
                $total_weighted += $row['closing_balance'];
            }
            $effective = (int)round($total_weighted / $days);
            $results[$name] = [
                'daily' => $cached,
                'effective' => $effective,
                'closing' => end($cached)['closing_balance'],
            ];
        } else {
            // محاسبه از نو
            $calc = calculate_partner_monthly_daily_balances($pdo, $name, $year, $month);
            $results[$name] = [
                'daily' => $calc['days'],
                'effective' => $calc['effective'],
                'closing' => $calc['closing'],
            ];

            // ذخیره در cache برای دفعات بعدی (اگر جدول وجود داشته باشه)
            if ($cache_available) {
                foreach ($calc['days'] as $day_data) {
                    $stmt_ins = $pdo->prepare("INSERT INTO partner_daily_balances
                        (partner_id, partner_name, balance_date, opening_balance, closing_balance, daily_change)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            opening_balance = VALUES(opening_balance),
                            closing_balance = VALUES(closing_balance),
                            daily_change = VALUES(daily_change)");
                    $stmt_ins->execute([$p['id'], $name, $day_data['gregorian_date'], $day_data['opening'], $day_data['closing'], $day_data['change']]);
                }
            }
        }
    }

    return $results;
}

/**
 * محاسبه توزیع سود برای یک ماه شمسی مشخص.
 * 
 * الگوریتم: تقسیم سود به بازه‌های زمانی
 * هر بازه شامل روزهایی است که ترکیب شرکا یکسان باشد.
 * سود هر بازه بر اساس تعداد روزها و سرمایه شرکای فعال آن بازه تقسیم می‌شود.
 * 
 * @return array [
 *   'profit' => int,
 *   'periods' => [['start_day' => int, 'end_day' => int, 'days' => int, 'period_profit' => int, 'partners' => [['name', 'capital', 'ratio', 'share'], ...]], ...],
 *   'partner_shares' => [['name' => str, 'share' => int, 'pct' => float], ...],
 *   'total_share' => int,
 * ]
 */
function calculate_month_settlement($pdo, $partners, $year, $month, $monthly_profit) {
    $all_balances = get_all_partners_monthly_balances($pdo, $partners, $year, $month);
    $days = get_days_in_jalali_month($year, $month);
    
    // دریافت سود واقعی هر روز از اسناد
    $daily_profits = get_daily_profits_from_documents($pdo, $year, $month);

    // ساخت آرایه مانده روزانه
    $daily_balances = [];
    foreach ($partners as $p) {
        $name = $p['account_name'];
        $bal = $all_balances[$name] ?? ['daily' => []];
        $daily_balances[$name] = [];
        foreach ($bal['daily'] as $day_data) {
            if (isset($day_data['day'])) {
                $day_num = $day_data['day'];
            } elseif (isset($day_data['balance_date'])) {
                $parts = explode('-', $day_data['balance_date']);
                if (count($parts) === 3) {
                    list($jy_d, $jm_d, $jd_d) = gregorian_to_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);
                    $day_num = $jd_d;
                } else { $day_num = 0; }
            } else { $day_num = 0; }
            $closing = $day_data['closing'] ?? $day_data['closing_balance'] ?? 0;
            $daily_balances[$name][$day_num] = $closing;
        }
    }

    // شناسایی بازه‌ها: هر بازه روزهایی است که ترکیب شرکای فعال (با مانده > 0) یکسان باشد
    $periods = [];
    $current_active = null;
    $period_start = 1;

    for ($day = 1; $day <= $days; $day++) {
        // لیست شرکای فعال در این روز (با مانده > 0)
        $active_today = [];
        foreach ($partners as $p) {
            $name = $p['account_name'];
            $balance = $daily_balances[$name][$day] ?? 0;
            if ($balance > 0) {
                $active_today[$name] = $balance;
            }
        }
        ksort($active_today);
        $active_key = md5(serialize(array_keys($active_today)));

        if ($active_key !== $current_active) {
            // بازه قبلی تمام شد
            if ($current_active !== null && $day - 1 >= $period_start) {
                $periods[] = buildPeriod($period_start, $day - 1, $daily_profits, $daily_balances, $partners);
            }
            $period_start = $day;
            $current_active = $active_key;
        }
    }
    // بازه آخر
    if ($current_active !== null && $days >= $period_start) {
        $periods[] = buildPeriod($period_start, $days, $daily_profits, $daily_balances, $partners);
    }

    // جمع‌زدن سهم نهایی هر شریک از تمام بازه‌ها
    $partner_shares = [];
    $total_share = 0;
    foreach ($partners as $p) {
        $name = $p['account_name'];
        $total_share_for_partner = 0;
        foreach ($periods as $period) {
            foreach ($period['partners'] as $pp) {
                if ($pp['name'] === $name) {
                    $total_share_for_partner += $pp['share'];
                }
            }
        }
        $pct = $monthly_profit > 0 ? $total_share_for_partner / $monthly_profit : 0;
        $effective = $all_balances[$name]['effective'] ?? 0;
        $partner_shares[] = [
            'name' => $name,
            'share' => $total_share_for_partner,
            'pct' => $pct,
            'effective_balance' => $effective,
        ];
        $total_share += $total_share_for_partner;
    }

    // ساخت daily_details از all_balances
    $daily_details = [];
    foreach ($partners as $p) {
        $name = $p['account_name'];
        $bal = $all_balances[$name] ?? ['daily' => []];
        if (!empty($bal['daily'])) {
            $daily_details[$name] = $bal['daily'];
        }
    }

    return [
        'profit' => $monthly_profit,
        'periods' => $periods,
        'partner_shares' => $partner_shares,
        'total_share' => $total_share,
        'daily_profits' => $daily_profits,
        'daily_details' => $daily_details,
    ];
}

/**
 * ساخت اطلاعات یک بازه زمانی
 */
function buildPeriod($start_day, $end_day, $daily_profits, $daily_balances, $partners) {
    $num_days = $end_day - $start_day + 1;
    
    // محاسبه سود واقعی این بازه (جمع سود روزهای این بازه)
    $period_profit = 0;
    for ($d = $start_day; $d <= $end_day; $d++) {
        $period_profit += $daily_profits[$d] ?? 0;
    }
    $period_profit = (int)$period_profit;

    // محاسبه سرمایه و نسبت هر شریک فعال
    $total_capital = 0;
    $partner_data = [];
    foreach ($partners as $p) {
        $name = $p['account_name'];
        $balance = $daily_balances[$name][$start_day] ?? 0;
        if ($balance > 0) {
            $partner_data[$name] = ['name' => $name, 'capital' => $balance];
            $total_capital += $balance;
        }
    }

    // محاسبه نسبت و سهم
    foreach ($partner_data as &$pp) {
        $pp['ratio'] = $total_capital > 0 ? $pp['capital'] / $total_capital : 0;
        $pp['share'] = (int)round($period_profit * $pp['ratio']);
    }
    unset($pp);

    return [
        'start_day' => $start_day,
        'end_day' => $end_day,
        'days' => $num_days,
        'period_profit' => $period_profit,
        'total_capital' => $total_capital,
        'partners' => array_values($partner_data),
    ];
}


/**
 * محاسبه توزیع سود برای تمام ماه‌ها (برای گزارش و پیش‌نمایش).
 * از cache جدول partner_daily_balances استفاده می‌کند.
 */
function calculate_settlement_profits($pdo, $partners, $monthly_profits) {
    $results = [];
    ksort($monthly_profits);

    foreach ($monthly_profits as $month_key => $month_data) {
        $jy = $month_data['year'];
        $jm = $month_data['month'];
        $profit = $month_data['profit'];

        $settlement = calculate_month_settlement($pdo, $partners, $jy, $jm, $profit);

        $results[$month_key] = [
            'year' => $jy,
            'month' => $jm,
            'profit' => $profit,
            'periods' => $settlement['periods'],
            'partner_shares' => $settlement['partner_shares'],
            'total_share' => $settlement['total_share'],
            'daily_details' => $settlement['daily_details'],
        ];
    }

    return $results;
}

/**
 * دریافت مانده فعلی شریک بر اساس تراکنش‌ها (برای نمایش در صفحه شرکا).
 */
function get_partner_current_balance($pdo, $partner_name) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(
        CASE
            WHEN type IN ('واریز', 'آورده') THEN amount
            WHEN type = 'برداشت' THEN -amount
            ELSE 0
        END
    ), 0) FROM partner_transactions WHERE partner_name = ?");
    $stmt->execute([$partner_name]);
    return (int)$stmt->fetchColumn();
}

/**
 * Escape LIKE special characters for safe pattern matching.
 */
function escape_like($value) {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}
