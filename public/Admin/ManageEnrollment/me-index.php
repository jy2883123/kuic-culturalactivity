<?php
/**
 * 학생 신청 관리 - 프로그램 목록
 * 관리자가 각 프로그램별 신청 현황을 확인하고 관리
 */

require_once '../../../config/config_admin.php';

$admin_name = $_SESSION['admin_name'] ?? 'Administrator';
$admin_position = $_SESSION['admin_position'] ?? 'Admin';

// 성공/에러 메시지
$success_message = $_SESSION['me_success'] ?? '';
$error_message = $_SESSION['me_error'] ?? '';
unset($_SESSION['me_success'], $_SESSION['me_error']);

// 프로그램 목록 조회 (신청자가 있는 프로그램만)
try {
    $programs_stmt = $pdo->prepare("
        SELECT
            ca.id,
            ca.program_name,
            ca.program_description,
            ca.activity_date,
            ca.activity_time,
            ca.location,
            ca.capacity,
            ca.current_enrollment,
            ca.has_fee,
            ca.fee_amount,
            ca.main_image_path,
            ca.is_active,
            COUNT(DISTINCT CASE WHEN e.status = 'approved' THEN e.id END) as approved_count,
            COUNT(DISTINCT CASE WHEN e.status = 'pending' THEN e.id END) as pending_count,
            COUNT(DISTINCT CASE WHEN e.status = 'cancelled' THEN e.id END) as cancelled_count,
            COUNT(DISTINCT CASE WHEN e.status = 'rejected' THEN e.id END) as rejected_count,
            COUNT(DISTINCT CASE WHEN e.fee_paid = 1 THEN e.id END) as paid_count,
            COUNT(DISTINCT CASE WHEN e.checked_in = 1 THEN e.id END) as checked_in_count
        FROM cultural_activities ca
        LEFT JOIN cultural_activity_enrollments e ON ca.id = e.activity_id
        WHERE ca.is_deleted = 0
        GROUP BY ca.id
        ORDER BY ca.activity_date DESC, ca.created_at DESC
    ");
    $programs_stmt->execute();
    $programs = $programs_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Manage Enrollment error: ' . $e->getMessage());
    $error_message = '프로그램 목록을 불러오는데 실패했습니다. 다시 시도해주세요.';
    $programs = [];
}

$seoulTz = new DateTimeZone('Asia/Seoul');
$now = new DateTime('now', $seoulTz);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>학생 신청 관리 | <?= htmlspecialchars($PAGE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            --admin-primary: #1e40af;
            --admin-primary-dark: #1e3a8a;
            --admin-accent: #3b82f6;
            --bg-soft: #f6f8fb;
            --border-color: #d1dce8;
            --text-main: #2f2f2f;
            --text-muted: #777777;
            --success-green: #16a34a;
            --warning-orange: #ea580c;
            --error-red: #dc2626;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
            background: var(--bg-soft);
            color: var(--text-main);
        }

        .container {
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

        .back-btn {
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            transition: background 0.2s ease;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
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

        .page-header {
            margin-bottom: 24px;
        }

        .page-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--admin-primary);
            margin-bottom: 8px;
        }

        .page-description {
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        /* Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 24px;
            font-size: 0.95rem;
        }

        .alert-success {
            background: #dcfce7;
            color: var(--success-green);
            border: 1px solid var(--success-green);
        }

        .alert-error {
            background: #fee2e2;
            color: var(--error-red);
            border: 1px solid var(--error-red);
        }

        /* Program Cards */
        .programs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 24px;
        }

        .program-card {
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.2s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .program-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        }

        .card-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }

        .card-body {
            padding: 20px;
        }

        .card-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--admin-primary);
            margin-bottom: 8px;
        }

        .card-meta {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 16px;
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--border-color);
        }

        .stat-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 1.1rem;
            font-weight: 700;
        }

        .stat-value.approved { color: var(--success-green); }
        .stat-value.pending { color: var(--warning-orange); }
        .stat-value.cancelled { color: var(--text-muted); }
        .stat-value.paid { color: #0891b2; }

        /* Empty State */
        .empty-state {
            background: #ffffff;
            border-radius: 16px;
            padding: 60px 32px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .empty-icon {
            font-size: 4rem;
            margin-bottom: 16px;
        }

        .empty-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 8px;
        }

        .empty-description {
            color: var(--text-muted);
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

        /* Responsive */
        @media (max-width: 768px) {
            .programs-grid {
                grid-template-columns: 1fr;
            }

            .main-content {
                padding: 20px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-left">
                    <a href="/Admin/dashboard.php" class="back-btn">← 대시보드</a>
                    <div class="header-title">
                        <h1>학생 신청 관리</h1>
                        <div class="header-subtitle">문화체험 프로그램의 학생 신청 내역을 관리합니다</div>
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
            <div class="page-header">
                <h2 class="page-title">신청자가 있는 프로그램 목록</h2>
                <p class="page-description">프로그램을 선택하여 신청 내역을 관리하세요</p>
            </div>

            <?php if ($success_message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if (empty($programs)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📋</div>
                    <h3 class="empty-title">신청 내역 없음</h3>
                    <p class="empty-description">현재 관리할 학생 신청 내역이 없습니다.</p>
                </div>
            <?php else: ?>
                <div class="programs-grid">
                    <?php foreach ($programs as $program): ?>
                        <?php
                            $activity_date = new DateTime($program['activity_date'], $seoulTz);
                            $formatted_date = $activity_date->format('F j, Y (l)');
                            $time_display = is_null($program['activity_time']) ? 'Time TBD' : date('g:i A', strtotime($program['activity_time']));
                            $is_past = $activity_date < $now;
                            $total_enrollments = $program['approved_count'] + $program['pending_count'];
                        ?>
                        <a href="/Admin/ManageEnrollment/me-detail.php?id=<?= $program['id'] ?>" class="program-card">
                            <img src="<?= htmlspecialchars($program['main_image_path'], ENT_QUOTES, 'UTF-8') ?>"
                                 alt="<?= htmlspecialchars($program['program_name'], ENT_QUOTES, 'UTF-8') ?>"
                                 class="card-image" />
                            <div class="card-body">
                                <h3 class="card-title"><?= htmlspecialchars($program['program_name'], ENT_QUOTES, 'UTF-8') ?></h3>

                                <div class="card-meta">
                                    <div class="meta-item">
                                        <span>📅</span>
                                        <span><?= htmlspecialchars($formatted_date, ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <span>🕐</span>
                                        <span><?= htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <span>📍</span>
                                        <span><?= htmlspecialchars($program['location'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <?php if ($program['has_fee']): ?>
                                        <div class="meta-item">
                                            <span>💰</span>
                                            <span>₩<?= number_format($program['fee_amount']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="stats-grid">
                                    <div class="stat-item">
                                        <div class="stat-label">승인</div>
                                        <div class="stat-value approved"><?= $program['approved_count'] ?></div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-label">대기</div>
                                        <div class="stat-value pending"><?= $program['pending_count'] ?></div>
                                    </div>
                                    <?php if ($program['has_fee']): ?>
                                        <div class="stat-item">
                                            <div class="stat-label">납부완료</div>
                                            <div class="stat-value paid"><?= $program['paid_count'] ?> / <?= $total_enrollments ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <div class="stat-item">
                                        <div class="stat-label">출석</div>
                                        <div class="stat-value"><?= $program['checked_in_count'] ?></div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-label">취소</div>
                                        <div class="stat-value cancelled"><?= $program['cancelled_count'] ?></div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-label">정원</div>
                                        <div class="stat-value"><?= $total_enrollments ?> / <?= $program['capacity'] ?? '∞' ?></div>
                                    </div>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>

        <!-- Footer -->
        <footer class="footer">
            © DATANEST, KOREA UNIVERSITY – Int'l Summer &amp; Winter Campus
        </footer>
    </div>
</body>
</html>
