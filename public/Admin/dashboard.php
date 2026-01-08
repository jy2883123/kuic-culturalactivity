<?php
// 관리자 전용 설정 파일 로드 (세션 검증 포함)
require_once '../../config/config_admin.php';
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>관리자 대시보드 | <?= htmlspecialchars($PAGE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            --admin-primary: #1a5490;
            --admin-primary-dark: #123d6b;
            --admin-accent: #2563eb;
            --bg-soft: #f6f8fb;
            --border-color: #d1dce8;
            --text-main: #2f2f2f;
            --text-muted: #777777;
            --success-green: #16a34a;
            --warning-orange: #ea580c;
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
            background: var(--bg-soft);
            color: var(--text-main);
        }

        .dashboard-container {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, var(--admin-primary), var(--admin-accent));
            color: #ffffff;
            padding: 20px 32px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .header-logo {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .header-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .header-subtitle {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .admin-badge {
            padding: 6px 14px;
            background: rgba(255, 255, 255, 0.25);
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        .btn-logout {
            padding: 8px 20px;
            background: rgba(255, 255, 255, 0.95);
            color: var(--admin-primary);
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-logout:hover {
            background: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            max-width: 1400px;
            width: 100%;
            margin: 0 auto;
            padding: 32px;
        }

        .welcome-section {
            background: linear-gradient(135deg, #ffffff, #f8fafc);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border-left: 4px solid var(--admin-primary);
        }

        .welcome-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--admin-primary);
            margin-bottom: 8px;
        }

        .welcome-text {
            font-size: 1rem;
            color: var(--text-muted);
            line-height: 1.6;
        }

        .admin-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            margin-top: 20px;
        }

        .info-card {
            background: rgba(26, 84, 144, 0.05);
            padding: 16px 20px;
            border-radius: 12px;
            border: 1px solid rgba(26, 84, 144, 0.1);
        }

        .info-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
            font-weight: 600;
        }

        .info-value {
            font-size: 1.1rem;
            color: var(--admin-primary);
            font-weight: 600;
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .dashboard-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .dashboard-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .card-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            background: linear-gradient(135deg, var(--admin-primary), var(--admin-accent));
            color: #ffffff;
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-main);
        }

        .card-content {
            color: var(--text-muted);
            font-size: 0.95rem;
            line-height: 1.6;
        }

        .coming-soon {
            display: inline-block;
            margin-top: 12px;
            padding: 4px 12px;
            background: #fef3c7;
            color: #92400e;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        /* Footer */
        .footer {
            background: #ffffff;
            border-top: 1px solid var(--border-color);
            padding: 20px 32px;
            text-align: center;
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .main-content {
                padding: 20px;
            }

            .welcome-section {
                padding: 24px;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-left">
                    <div class="header-logo">🎓</div>
                    <div class="header-title">
                        <h1>관리자 대시보드</h1>
                        <div class="header-subtitle">KU ISC/IWC 문화체험 포털</div>
                    </div>
                </div>
                <div class="header-right">
                    <span class="admin-badge"><?= htmlspecialchars($admin_position, ENT_QUOTES, 'UTF-8') ?></span>
                    <a href="/Auth/admin_logout.php" class="btn-logout">로그아웃</a>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Welcome Section -->
            <section class="welcome-section">
                <h2 class="welcome-title"><?= htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8') ?>님, 환영합니다!</h2>
                <p class="welcome-text">
                    문화체험 포털 관리자 페이지에 로그인하셨습니다.
                    여기에서 문화체험을 관리하고, 학생 신청을 검토하며, 프로그램 운영을 총괄할 수 있습니다.
                </p>

                <div class="admin-info">
                    <div class="info-card">
                        <div class="info-label">관리자 이름</div>
                        <div class="info-value"><?= htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">로그인 ID</div>
                        <div class="info-value"><?= htmlspecialchars($admin_id, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">직책</div>
                        <div class="info-value"><?= htmlspecialchars($admin_position, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            </section>

            <!-- Dashboard Cards -->
            <div class="dashboard-grid">
                <a href="/Admin/ManageActivity/ma-index.php" class="dashboard-card" style="text-decoration: none; color: inherit;">
                    <div class="card-header">
                        <div class="card-icon">📋</div>
                        <h3 class="card-title">활동 관리</h3>
                    </div>
                    <div class="card-content">
                        학생 등록이 가능한 문화체험을 생성, 수정 및 관리합니다.
                    </div>
                </a>

                <a href="/Admin/ManageEnrollment/me-index.php" class="dashboard-card" style="text-decoration: none; color: inherit;">
                    <div class="card-header">
                        <div class="card-icon">👥</div>
                        <h3 class="card-title">학생 신청 관리</h3>
                    </div>
                    <div class="card-content">
                        문화체험에 대한 학생 신청을 검토하고 관리합니다. 수강료 납부 확인, 강제 취소, 수동 등록 기능을 제공합니다.
                    </div>
                </a>

                <a href="/Admin/ManageCheckin/mc-index.php" class="dashboard-card" style="text-decoration: none; color: inherit;">
                    <div class="card-header">
                        <div class="card-icon">✅</div>
                        <h3 class="card-title">체크인 관리</h3>
                    </div>
                    <div class="card-content">
                        관리자가 QR 코드 또는 학번을 사용해 현장 출석을 검증하고 기록합니다.
                    </div>
                </a>

                <a href="/Admin/ManageBan/mb-index.php" class="dashboard-card" style="text-decoration: none; color: inherit;">
                    <div class="card-header">
                        <div class="card-icon">🚫</div>
                        <h3 class="card-title">밴 관리</h3>
                    </div>
                    <div class="card-content">
                        현재 밴된 학생 현황을 확인하고 필요 시 차단을 해제합니다.
                    </div>
                </a>

                <a href="/Admin/Reports/ar-index.php" class="dashboard-card" style="text-decoration: none; color: inherit;">
                    <div class="card-header">
                        <div class="card-icon">📊</div>
                        <h3 class="card-title">리포트 및 통계</h3>
                    </div>
                    <div class="card-content">
                        기간별 신청/출석 통계와 상위 활동 요약을 확인합니다.
                    </div>
                </a>

                <a href="/Admin/SystemSetting/ss-index.php" class="dashboard-card" style="text-decoration: none; color: inherit;">
                    <div class="card-header">
                        <div class="card-icon">⚙️</div>
                        <h3 class="card-title">시스템 설정</h3>
                    </div>
                    <div class="card-content">
                        단기조교용 체크인 토큰 발급 등 시스템 도구를 관리합니다.
                    </div>
                </a>

                <a href="/Admin/FAQs/faq-index.php" class="dashboard-card" style="text-decoration: none; color: inherit;">
                    <div class="card-header">
                        <div class="card-icon">❓</div>
                        <h3 class="card-title">FAQ 관리</h3>
                    </div>
                    <div class="card-content">
                        학생용 FAQ를 작성하고 순서를 조정하며 비활성화하거나 삭제할 수 있습니다.
                    </div>
                </a>
            </div>
        </main>

        <!-- Footer -->
        <footer class="footer">
            © DATANEST, KOREA UNIVERSITY – Int'l Summer &amp; Winter Campus
        </footer>
    </div>
</body>
</html>
