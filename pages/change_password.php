<?php
require_once __DIR__ . '/../config.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Get current user
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!password_verify($current_password, $user['password'])) {
        $error = 'رمز عبور فعلی اشتباه است.';
    } elseif (strlen($new_password) < 6) {
        $error = 'رمز عبور جدید باید حداقل ۶ کاراکتر باشد.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'رمز عبور جدید و تکرار آن مطابقت ندارند.';
    } else {
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $_SESSION['user_id']]);
        $success = 'رمز عبور با موفقیت تغییر کرد.';
    }
}
?>

<h1>تغییر رمز عبور</h1>

<?php if ($error): ?>
<div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<div class="card">
    <form method="POST" style="max-width:400px;">
        <?= csrf_field() ?>
        <div class="form-group">
            <label>رمز عبور فعلی</label>
            <input type="password" name="current_password" required>
        </div>
        <div class="form-group">
            <label>رمز عبور جدید</label>
            <input type="password" name="new_password" required minlength="6">
        </div>
        <div class="form-group">
            <label>تکرار رمز عبور جدید</label>
            <input type="password" name="confirm_password" required>
        </div>
        <button type="submit" class="btn btn-primary">تغییر رمز</button>
    </form>
</div>
