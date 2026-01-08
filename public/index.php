<?php
require_once '../config/config.php';
require_once '../handler/login_handler.php';

// 로그인 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_login($pdo_uwaysync, $pdo);
}

// If already logged in, redirect to dashboard
if (!empty($_SESSION['logged_in'])) {
    echo "<script>alert('You are already logged in. Redirecting to the dashboard...'); window.location.href = 'Student/dashboard.php';</script>";
    exit;
}

// 로그인 에러 / 성공 메시지 세션에서 가져오기
$login_error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

$login_success = (isset($_GET['login']) && $_GET['login'] === 'success');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Login | KUISC/IWC Cultural Activity</title>
    <style>
        :root {
            --ku-crimson: #862633;
            --ku-crimson-dark: #6b1d28;
            --bg-soft: #fdf6f9;
            --border-color: #e4dde2;
            --text-main: #2f2f2f;
            --text-muted: #777777;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
            background: radial-gradient(circle at top left, #ffeef3 0, #f9f4ff 35%, #f7fafc 70%, #ffffff 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-main);
        }

        .login-wrapper {
            width: 100%;
            padding: 40px 16px;
        }

        .card {
            max-width: 540px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 28px;
            box-shadow: 0 18px 45px rgba(0, 0, 0, 0.08);
            padding: 40px 40px 32px;
        }

        .card-header {
            text-align: center;
            margin-bottom: 28px;
        }

        .card-header img {
            width: 100px;
            height: auto;
            margin-bottom: 12px;
        }

        .title-main {
            font-size: 1.7rem;
            font-weight: 700;
            color: var(--ku-crimson);
            margin-bottom: 4px;
        }

        .subtitle {
            font-size: 0.95rem;
            color: var(--text-muted);
            line-height: 1.4;
        }

        .form-group {
            margin-bottom: 16px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.9rem;
            font-weight: 600;
            color: #444444;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            padding: 11px 12px;
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            background-color: #fcfbfd;
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            border-color: var(--ku-crimson);
            box-shadow: 0 0 0 1px rgba(134, 38, 51, 0.08);
            background-color: #ffffff;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            margin-top: 8px;
            padding: 11px 16px;
            border-radius: 10px;
            border: none;
            background-color: var(--ku-crimson);
            color: #ffffff;
            font-size: 0.98rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.15s ease, transform 0.05s ease;
        }

        .btn-primary:hover {
            background-color: var(--ku-crimson-dark);
        }

        .btn-primary:active {
            transform: translateY(1px);
        }

        .link-sm {
            margin: 12px 0 4px;
            text-align: center;
            font-size: 0.88rem;
            color: var(--ku-crimson);
            cursor: pointer;
        }

        .link-sm a {
            color: var(--ku-crimson);
            text-decoration: none;
        }

        .link-sm a:hover {
            text-decoration: underline;
        }

        .divider {
            display: flex;
            align-items: center;
            margin: 16px 0 12px;
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .divider::before,
        .divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background-color: #e8e3e7;
        }

        .divider span {
            padding: 0 10px;
        }

        .muted-text {
            text-align: center;
            font-size: 0.86rem;
            color: var(--text-muted);
            margin-bottom: 10px;
        }

        .btn-secondary {
            width: 100%;
            padding: 10px 16px;
            border-radius: 10px;
            border: 1px solid #d6cfd4;
            background-color: #ffffff;
            color: #4b4b4b;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.12s ease, border-color 0.12s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-secondary + .btn-secondary {
            margin-top: 8px;
        }

        .btn-secondary:hover {
            background-color: #faf7fb;
            border-color: #c8bac4;
        }

        .card-footer {
            margin-top: 22px;
            text-align: center;
            font-size: 0.75rem;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .card-footer a {
            color: var(--ku-crimson);
            text-decoration: none;
            font-weight: 500;
        }

        .card-footer a:hover {
            text-decoration: underline;
        }

        .copyright {
            margin-top: 8px;
            font-size: 0.7rem;
            color: #aaaaaa;
        }

        .alert {
            margin-bottom: 16px;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 0.86rem;
            line-height: 1.4;
        }

        .alert-error {
            background-color: #fff1f1;
            border: 1px solid #f2b3b3;
            color: #a02222;
        }

        .alert-success {
            background-color: #f1fff4;
            border: 1px solid #9ed3a5;
            color: #1f6b33;
        }

        @media (max-width: 480px) {
            .card {
                padding: 28px 22px 24px;
                border-radius: 22px;
            }

            .title-main {
                font-size: 1.45rem;
            }

            .card-header img {
                width: 100px;
            }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <main class="card" aria-label="KU ISC / IWC Cultural Activity Portal Login">
            <header class="card-header">
                <img src="/Auth/hoi.png" alt="KU HOI" />
                <h1 class="title-main">KU ISC / IWC</h1>
                <h1 class="title-main">Cultural Activity Portal</h1>
                <p class="subtitle">Only students enrolled in the program are granted access.</p>
            </header>

            <section class="card-body">
                <?php if ($login_error): ?>
                    <div class="alert alert-error">
                        <?= htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php elseif ($login_success): ?>
                    <div class="alert alert-success">
                        Login successful. Redirecting to the portal...
                    </div>
                <?php endif; ?>
                <form action="" method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-group">
                        <label for="student-number">Student Number</label>
                        <input
                            type="text"
                            id="student-number"
                            name="student_number"
                            placeholder="2025XXXXXX"
                            autocomplete="username"
                        />
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                        />
                    </div>

                    <button type="submit" class="btn-primary">Login</button>
                </form>

                <p class="link-sm">
                    <a href="#" onclick="alert('Password changes can only be made on the UWAY APPLICATION PAGE. It may take at least one hour for the change to be reflected.'); return false;">
                        Forgotten password?
                    </a>
                </p>

                <div class="divider"><span>or</span></div>

                <p class="muted-text">For KU ISC/IWC staff,</p>

                <a href="admin.php" class="btn-secondary">Administrator Login</a>
            </section>

            <footer class="card-footer">
                <p>
                    By logging into the portal, you agree to the
                    <a href="Legal/terms-of-service.html" onclick="window.open(this.href, 'Terms of Service', 'width=800,height=600,scrollbars=yes,resizable=yes'); return false;">Terms of Service</a> and
                    <a href="Legal/privacy-policy.html" onclick="window.open(this.href, 'Privacy Policy', 'width=800,height=600,scrollbars=yes,resizable=yes'); return false;">Privacy Policy</a>.
                </p>
                <p class="copyright">
                    © DATANEST, KOREA UNIVERSITY – Inernational Summer &amp; Winter Campus
                </p>
            </footer>
        </main>
    </div>

    <script>
        // 학생 번호 입력 필드에 숫자만 입력 가능하도록 제한
        const studentNumberInput = document.getElementById('student-number');

        studentNumberInput.addEventListener('input', function(e) {
            const value = e.target.value;
            const numbersOnly = value.replace(/[^0-9]/g, '');

            if (value !== numbersOnly) {
                e.target.value = numbersOnly;

                // 경고 메시지 표시 (중복 방지를 위해 기존 메시지 확인)
                if (!document.querySelector('.number-only-alert')) {
                    const alert = document.createElement('div');
                    alert.className = 'alert alert-error number-only-alert';
                    alert.style.marginTop = '10px';
                    alert.textContent = 'Student number must contain only numbers.';

                    const formGroup = e.target.parentElement;
                    formGroup.appendChild(alert);

                    // 2초 후 메시지 제거
                    setTimeout(() => {
                        alert.remove();
                    }, 2000);
                }
            }
        });

        // 붙여넣기 시에도 검증
        studentNumberInput.addEventListener('paste', function(e) {
            setTimeout(() => {
                const value = e.target.value;
                const numbersOnly = value.replace(/[^0-9]/g, '');

                if (value !== numbersOnly) {
                    e.target.value = numbersOnly;
                    alert('Student number must contain only numbers.');
                }
            }, 10);
        });
    </script>
</body>
</html>
