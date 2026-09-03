<?php
require_once 'config.php';
$page = $_GET['page'] ?? 'dashboard';
$allowed_pages = ['dashboard', 'documents', 'partners', 'reports', 'fund', 'settlement', 'change_password'];
if (!in_array($page, $allowed_pages)) {
    $page = 'dashboard';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>جاری شرکا</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <nav class="sidebar">
        <div class="logo"><span class="logo-mark">ج</span> جاری شرکا</div>
        <ul>
            <li><a href="?page=dashboard" class="<?= $page === 'dashboard' ? 'active' : '' ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>داشبورد</a></li>
            <li><a href="?page=documents" class="<?= $page === 'documents' ? 'active' : '' ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/></svg>اسناد</a></li>
            <li><a href="?page=partners" class="<?= $page === 'partners' ? 'active' : '' ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>شرکا</a></li>
            <li><a href="?page=reports" class="<?= $page === 'reports' ? 'active' : '' ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>گزارشات</a></li>
            <li><a href="?page=fund" class="<?= $page === 'fund' ? 'active' : '' ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="14" rx="2"/><circle cx="12" cy="13" r="3"/><path d="M6 6V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2"/></svg>صندوق</a></li>
            <li><a href="?page=settlement" class="<?= $page === 'settlement' ? 'active' : '' ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>تسویه سود</a></li>
            <li><a href="?page=change_password" class="<?= $page === 'change_password' ? 'active' : '' ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>تغییر رمز</a></li>
        </ul>
        <div class="sidebar-footer">
            <div class="user-info"><?= e($_SESSION['fullname'] ?? $_SESSION['username'] ?? '') ?></div>
            <div class="flex-row">
                <button onclick="toggleDarkMode()" class="btn btn-sm btn-secondary flex-1">
                    <span id="theme-icon">☀</span> <span id="theme-text">روشن</span>
                </button>
                <a href="logout.php" class="btn btn-sm btn-danger flex-1">خروج</a>
            </div>
        </div>
    </nav>

    <main class="content">
        <?php
        $file = "pages/{$page}.php";
        if (file_exists($file)) {
            require $file;
        } else {
            echo '<div class="card"><h2>صفحه یافت نشد</h2></div>';
        }
        ?>
    </main>

    <div id="toast-container"></div>

    <script>
    function showToast(msg, type) {
        type = type || 'success';
        var c = document.getElementById('toast-container');
        var t = document.createElement('div');
        t.className = 'toast toast-' + type;
        var span = document.createElement('span');
        span.textContent = msg;
        var btn = document.createElement('button');
        btn.textContent = '×';
        btn.onclick = function() { t.remove(); };
        t.appendChild(span);
        t.appendChild(btn);
        c.appendChild(t);
        requestAnimationFrame(function() { t.classList.add('show'); });
        setTimeout(function() {
            t.classList.remove('show');
            setTimeout(function() { t.remove(); }, 300);
        }, 4000);
    }

    document.addEventListener('DOMContentLoaded', function() {
        var params = new URLSearchParams(window.location.search);
        if (params.get('success')) {
            var msgs = {
                'partner_added': 'شریک اضافه شد',
                'partner_deleted': 'شریک حذف شد',
                'transaction_added': 'تراکنش ثبت شد',
                'transaction_deleted': 'تراکنش حذف شد',
                'settlement_done': 'تسویه انجام شد',
                'settlement_cancelled': 'تسویه لغو شد',
                'saved': 'ذخیره شد',
                'deleted': 'حذف شد'
            };
            var allowedSuccess = Object.keys(msgs);
            var successVal = params.get('success');
            showToast(allowedSuccess.indexOf(successVal) !== -1 ? msgs[successVal] : 'انجام شد');
            params.delete('success');
            history.replaceState({}, '', '?' + params.toString());
        }
        if (params.get('error')) {
            var allowedErrors = { 'duplicate': 'تسویه تکراری', 'noprofit': 'سودی وجود ندارد', 'invalid': 'داده نامعتبر', 'failed': 'عملیات ناموفق' };
            var errorVal = params.get('error');
            showToast(allowedErrors[errorVal] || 'خطا', 'error');
            params.delete('error');
            history.replaceState({}, '', '?' + params.toString());
        }

        function fmt(n) {
            if (!n) return '';
            return n.toString().replace(/[^\d]/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
        function unfmt(n) { return n ? n.replace(/,/g, '') : ''; }

        document.querySelectorAll('input.num-format').forEach(function(el) {
            if (el.value) el.value = fmt(el.value);
            el.addEventListener('input', function() {
                var p = this.selectionStart, o = this.value.length;
                this.value = fmt(unfmt(this.value));
                this.setSelectionRange(p + this.value.length - o, p + this.value.length - o);
            });
            el.addEventListener('focus', function() {
                var p = this.selectionStart;
                this.value = unfmt(this.value);
                this.setSelectionRange(p, p);
            });
            el.addEventListener('blur', function() { if (this.value) this.value = fmt(this.value); });
        });

        document.querySelectorAll('form').forEach(function(f) {
            f.addEventListener('submit', function() {
                f.querySelectorAll('input.num-format').forEach(function(el) { el.value = unfmt(el.value); });
            });
        });

        if (localStorage.getItem('darkMode') === 'true') {
            document.body.classList.add('dark');
            updateThemeUI(true);
        }
    });

    function toggleDarkMode() {
        document.body.classList.toggle('dark');
        var d = document.body.classList.contains('dark');
        localStorage.setItem('darkMode', d);
        updateThemeUI(d);
    }

    function updateThemeUI(d) {
        document.getElementById('theme-icon').textContent = d ? '☾' : '☀';
        document.getElementById('theme-text').textContent = d ? 'تاریک' : 'روشن';
    }
    </script>
</body>
</html>
