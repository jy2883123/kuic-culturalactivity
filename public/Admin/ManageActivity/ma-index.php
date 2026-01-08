<?php
// 관리자 전용 설정 파일 로드 (세션 검증 포함)
require_once '../../../config/config_admin.php';

$success_message = $_SESSION['ma_success'] ?? '';
$error_message = $_SESSION['ma_error'] ?? '';
unset($_SESSION['ma_success'], $_SESSION['ma_error']);

// 프로그램 목록 조회
try {
    $stmt = $pdo->prepare("
        SELECT
            id,
            program_name,
            activity_date,
            activity_time,
            location,
            capacity,
            current_enrollment,
            has_fee,
            fee_amount,
            registration_start_date,
            registration_end_date,
            main_image_path,
            is_active
        FROM cultural_activities
        WHERE is_deleted = FALSE
        ORDER BY activity_date DESC, created_at DESC
    ");
    $stmt->execute();
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Program list fetch error: ' . $e->getMessage());
    $programs = [];
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>활동 관리 | <?= htmlspecialchars($PAGE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
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

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }

        .page-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--admin-primary);
        }

        .btn-create {
            padding: 12px 24px;
            background: linear-gradient(135deg, var(--admin-primary), var(--admin-accent));
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .btn-create:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(26, 84, 144, 0.3);
        }

        /* Programs Table */
        .programs-table {
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: linear-gradient(135deg, var(--admin-primary), var(--admin-accent));
            color: #ffffff;
        }

        th {
            padding: 16px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
        }

        td {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.9rem;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tbody tr {
            transition: background 0.15s ease;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .program-image {
            width: 80px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-active {
            background: #dcfce7;
            color: var(--success-green);
        }

        .status-inactive {
            background: #fee2e2;
            color: #dc2626;
        }

        .capacity-info {
            font-weight: 600;
        }

        .capacity-full {
            color: var(--warning-orange);
        }

        .capacity-available {
            color: var(--success-green);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-small {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s ease;
        }

        .btn-edit {
            background: #dbeafe;
            color: #1e40af;
        }

        .btn-edit:hover {
            background: #bfdbfe;
        }

        .btn-view {
            background: #f3e8ff;
            color: #6b21a8;
        }

        .btn-view:hover {
            background: #e9d5ff;
        }

        .btn-delete {
            background: #fee2e2;
            color: #b91c1c;
        }

        .btn-delete:hover {
            background: #fecaca;
        }

        .empty-state {
            text-align: center;
            padding: 64px 32px;
            color: var(--text-muted);
        }

        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 16px;
        }

        .empty-state-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-main);
        }

        .empty-state-text {
            font-size: 0.95rem;
            margin-bottom: 24px;
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
            .main-content {
                padding: 20px;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }

            .programs-table {
                overflow-x: auto;
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
                        <h1>활동 관리</h1>
                        <div class="header-subtitle">문화체험 프로그램 생성 및 관리</div>
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
                <h2 class="page-title">프로그램 목록</h2>
                <a href="/Admin/ManageActivity/ma-create.php" class="btn-create">
                    ➕ 새 프로그램 생성
                </a>
            </div>

            <?php if ($success_message): ?>
                <div style="margin-bottom: 20px; padding: 12px 16px; border-radius: 10px; background: #dcfce7; color: #166534;">
                    <?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div style="margin-bottom: 20px; padding: 12px 16px; border-radius: 10px; background: #fee2e2; color: #b91c1c;">
                    <?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if (empty($programs)): ?>
                <div class="programs-table">
                    <div class="empty-state">
                        <div class="empty-state-icon">📋</div>
                        <h3 class="empty-state-title">등록된 프로그램이 없습니다</h3>
                        <p class="empty-state-text">새 문화체험 프로그램을 생성하여 학생들에게 제공하세요.</p>
                        <a href="/Admin/ManageActivity/ma-create.php" class="btn-create">
                            ➕ 첫 프로그램 생성하기
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="programs-table">
                    <table>
                        <thead>
                            <tr>
                                <th>이미지</th>
                                <th>프로그램명</th>
                                <th>일시</th>
                                <th>장소</th>
                                <th>정원</th>
                                <th>신청기간</th>
                                <th>상태</th>
                                <th>작업</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($programs as $program): ?>
                                <?php
                                $capacity_text = is_null($program['capacity'])
                                    ? '무제한'
                                    : $program['current_enrollment'] . '/' . $program['capacity'];

                                $is_full = !is_null($program['capacity']) && $program['current_enrollment'] >= $program['capacity'];
                                $capacity_class = $is_full ? 'capacity-full' : 'capacity-available';

                                // 시간 미정 처리
                                $time_display = is_null($program['activity_time']) ? 'TBD' : substr($program['activity_time'], 0, 5);
                                $activity_datetime = $program['activity_date'] . ' ' . $time_display;
                                ?>
                                <tr>
                                    <td>
                                        <img src="<?= htmlspecialchars($program['main_image_path'], ENT_QUOTES, 'UTF-8') ?>"
                                             alt="<?= htmlspecialchars($program['program_name'], ENT_QUOTES, 'UTF-8') ?>"
                                             class="program-image" />
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($program['program_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <?php if ($program['has_fee']): ?>
                                            <br><small style="color: var(--warning-orange);">참가비: ₩<?= number_format($program['fee_amount']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($activity_datetime, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($program['location'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="capacity-info <?= $capacity_class ?>">
                                            <?= htmlspecialchars($capacity_text, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small>
                                            <?= date('Y-m-d', strtotime($program['registration_start_date'])) ?><br>
                                            ~ <?= date('Y-m-d', strtotime($program['registration_end_date'])) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $program['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                            <?= $program['is_active'] ? '활성' : '비활성' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="/Admin/ManageActivity/ma-detail.php?id=<?= $program['id'] ?>" class="btn-small btn-view">상세</a>
                                            <a href="/Admin/ManageActivity/ma-edit.php?id=<?= $program['id'] ?>" class="btn-small btn-edit">수정</a>
                                            <a href="/Admin/ManageActivity/ma-notice.php?id=<?= $program['id'] ?>" class="btn-small" style="background:#fef3c7; color:#92400e;">공지</a>
                                            <form action="/Admin/ManageActivity/handler/ma-delete-handler.php" method="POST" onsubmit="return confirmDelete(this);" style="margin:0;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="activity_id" value="<?= $program['id'] ?>" />
                                                <button type="submit" class="btn-small btn-delete">삭제</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </main>

        <!-- Footer -->
        <footer class="footer">
            © DATANEST, KOREA UNIVERSITY – Int'l Summer &amp; Winter Campus
        </footer>
    </div>
    <script>
        function confirmDelete(form) {
            if (!confirm('프로그램을 삭제하면 복구할 수 없습니다. 계속하시겠습니까?')) {
                return false;
            }
            return confirm('관련된 모든 데이터와 신청 기록이 영구적으로 삭제됩니다. 정말 삭제하시겠습니까?');
        }
    </script>
</body>
</html>
