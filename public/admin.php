<?php
session_start();
require_once '../config/config.php';
require_once '../handler/admin_login_handler.php';

// 로그인 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_admin_login($pdo);
}

// 이미 관리자로 로그인한 경우 대시보드로 리다이렉트
if (!empty($_SESSION['admin_logged_in'])) {
    echo "<script>alert('이미 로그인되어 있습니다. 대시보드로 이동합니다...'); window.location.href = 'Admin/dashboard.php';</script>";
    exit;
}

// 로그인 에러 / 성공 메시지 세션에서 가져오기
$login_error = $_SESSION['admin_login_error'] ?? '';
unset($_SESSION['admin_login_error']);

$login_success = (isset($_GET['login']) && $_GET['login'] === 'success');
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>관리자 로그인 | KUISC/IWC 문화활동</title>
    <style>
        :root {
            --admin-primary: #1a5490;
            --admin-primary-dark: #123d6b;
            --admin-accent: #2563eb;
            --bg-soft: #f6f8fb;
            --border-color: #d1dce8;
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
            background: radial-gradient(circle at top left, #e8f0fc 0, #f4f6fb 35%, #f7fafc 70%, #ffffff 100%);
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

        .admin-badge {
            display: inline-block;
            padding: 4px 12px;
            background: linear-gradient(135deg, var(--admin-primary), var(--admin-accent));
            color: #ffffff;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
            text-transform: uppercase;
        }

        .title-main {
            font-size: 1.7rem;
            font-weight: 700;
            color: var(--admin-primary);
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
            border-color: var(--admin-primary);
            box-shadow: 0 0 0 1px rgba(26, 84, 144, 0.08);
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
            background: linear-gradient(135deg, var(--admin-primary), var(--admin-accent));
            color: #ffffff;
            font-size: 0.98rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(26, 84, 144, 0.2);
        }

        .btn-primary:active {
            transform: translateY(1px);
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

        .btn-secondary:hover {
            background-color: #faf7fb;
            border-color: #c8bac4;
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

        .card-footer {
            margin-top: 22px;
            text-align: center;
            font-size: 0.75rem;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .card-footer a {
            color: var(--admin-primary);
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
        <main class="card" aria-label="KU ISC / IWC 문화활동 포털 관리자 로그인">
            <header class="card-header">
                <img src="/Auth/hoi.png" alt="KU HOI" />
                <div style="display: flex; align-items: center; justify-content: center; gap: 12px; margin: 12px 0;">
                    <div class="admin-badge">관리자</div>
                </div>
                <h1 class="title-main">KU ISC / IWC</h1>
                <h1 class="title-main">문화활동 포털</h1>
                <p class="subtitle">관리자 전용 페이지입니다.</p>
            </header>

            <section class="card-body">
                <?php if ($login_error): ?>
                    <div class="alert alert-error">
                        <?= htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php elseif ($login_success): ?>
                    <div class="alert alert-success">
                        로그인 성공. 관리자 대시보드로 이동합니다...
                    </div>
                <?php endif; ?>
                <form action="" method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-group">
                        <label for="login-id">로그인 ID</label>
                        <input
                            type="text"
                            id="login-id"
                            name="login_id"
                            placeholder="로그인 ID를 입력하세요"
                            autocomplete="username"
                        />
                    </div>

                    <div class="form-group">
                        <label for="password">비밀번호</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="비밀번호를 입력하세요"
                            autocomplete="current-password"
                        />
                    </div>

                    <button type="submit" class="btn-primary">관리자 로그인</button>
                </form>

                <div class="divider"><span>또는</span></div>

                <p class="muted-text">학생이신가요?</p>

                <a href="index.php" class="btn-secondary">학생 로그인</a>
            </section>

            <footer class="card-footer">
                <p class="copyright">
                    © DATANEST, KOREA UNIVERSITY – Int'l Summer &amp; Winter Campus
                </p>
            </footer>
        </main>
    </div>
</body>
</html>
