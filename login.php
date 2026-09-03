<?php
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$login_attempts_key = 'login_attempts';
$login_lockout_key = 'login_lockout_until';

// Rate limiting: max 5 attempts per 15 minutes
function is_login_locked() {
    $lockout = $_SESSION['login_lockout_until'] ?? 0;
    return $lockout > time();
}

function record_failed_login() {
    $attempts = ($_SESSION['login_attempts'] ?? 0) + 1;
    $_SESSION['login_attempts'] = $attempts;

    if ($attempts >= 5) {
        $_SESSION['login_lockout_until'] = time() + 900; // 15 min lockout
    }
}

function reset_login_attempts() {
    unset($_SESSION['login_attempts'], $_SESSION['login_lockout_until']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF verification
    verify_csrf();

    // Rate limit check
    if (is_login_locked()) {
        $remaining = ($_SESSION['login_lockout_until'] ?? 0) - time();
        $error = 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. ' . ceil($remaining / 60) . ' دقیقه صبر کنید.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'نام کاربری و رمز عبور را وارد کنید.';
        } else {
            $stmt = $pdo->prepare("SELECT id, username, password, fullname FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                reset_login_attempts();
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['fullname'] = $user['fullname'];
                header('Location: index.php');
                exit;
            } else {
                record_failed_login();
                $error = 'نام کاربری یا رمز عبور اشتباه است.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود — جاری شرکا</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
    <div class="login-box">
        <div class="brand-mark">ج</div>
        <h1>جاری شرکا</h1>
        <p>برای ادامه وارد شوید</p>
        <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>نام کاربری</label>
                <input type="text" name="username" required autofocus placeholder="نام کاربری">
            </div>
            <div class="form-group">
                <label>رمز عبور</label>
                <input type="password" name="password" required placeholder="رمز عبور">
            </div>
            <button type="submit" class="btn btn-primary">ورود</button>
        </form>
    </div>
</body>
</html>
