<?php
require_once '../../../config/config_admin.php';

$seoulTz = new DateTimeZone('Asia/Seoul');
$today = new DateTime('today', $seoulTz);
$default_start = (clone $today)->modify('-30 days');

$start_input = trim($_GET['start'] ?? $default_start->format('Y-m-d'));
$end_input = trim($_GET['end'] ?? $today->format('Y-m-d'));

$start_dt = DateTime::createFromFormat('Y-m-d', $start_input, $seoulTz) ?: clone $default_start;
$end_dt = DateTime::createFromFormat('Y-m-d', $end_input, $seoulTz) ?: clone $today;
if ($end_dt < $start_dt) {
    $tmp = $start_dt;
    $start_dt = $end_dt;
    $end_dt = $tmp;
}
$start_at = $start_dt->format('Y-m-d 00:00:00');
$end_at = $end_dt->format('Y-m-d 23:59:59');
$today_str = $today->format('Y-m-d');

$stats = [
    'activities_total' => 0,
    'activities_active' => 0,
    'activities_upcoming' => 0,
    'enroll_total' => 0,
    'enroll_approved' => 0,
    'enroll_pending' => 0,
    'enroll_cancelled' => 0,
    'checkins_total' => 0,
    'bans_active' => 0,
    'bans_total' => 0
];

$top_activities = [];
$error_message = '';

try {
    $activity_stmt = $pdo->query("
        SELECT
            COUNT(*) AS total_count,
            SUM(is_active = 1) AS active_count
        FROM cultural_activities
        WHERE is_deleted = 0
    ");
    $activity_row = $activity_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats['activities_total'] = (int)($activity_row['total_count'] ?? 0);
    $stats['activities_active'] = (int)($activity_row['active_count'] ?? 0);

    $upcoming_stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM cultural_activities
        WHERE is_deleted = 0
          AND activity_date >= :today
    ");
    $upcoming_stmt->execute(['today' => $today_str]);
    $stats['activities_upcoming'] = (int)$upcoming_stmt->fetchColumn();

    $enroll_stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_count,
            SUM(status = 'approved') AS approved_count,
            SUM(status = 'pending') AS pending_count,
            SUM(status = 'cancelled') AS cancelled_count
        FROM cultural_activity_enrollments
        WHERE enrolled_at BETWEEN :start_at AND :end_at
    ");
    $enroll_stmt->execute([
        'start_at' => $start_at,
        'end_at' => $end_at
    ]);
    $enroll_row = $enroll_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats['enroll_total'] = (int)($enroll_row['total_count'] ?? 0);
    $stats['enroll_approved'] = (int)($enroll_row['approved_count'] ?? 0);
    $stats['enroll_pending'] = (int)($enroll_row['pending_count'] ?? 0);
    $stats['enroll_cancelled'] = (int)($enroll_row['cancelled_count'] ?? 0);

    $checkin_stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM cultural_activity_enrollments
        WHERE checked_in = 1
          AND check_in_time BETWEEN :start_at AND :end_at
    ");
    $checkin_stmt->execute([
        'start_at' => $start_at,
        'end_at' => $end_at
    ]);
    $stats['checkins_total'] = (int)$checkin_stmt->fetchColumn();

    $ban_active_stmt = $pdo->query("SELECT COUNT(*) FROM cultural_activity_bans WHERE is_active = 1");
    $stats['bans_active'] = (int)$ban_active_stmt->fetchColumn();
    $ban_total_stmt = $pdo->query("SELECT COUNT(*) FROM cultural_activity_bans");
    $stats['bans_total'] = (int)$ban_total_stmt->fetchColumn();

    $top_stmt = $pdo->prepare("
        SELECT
            ca.id,
            ca.program_name,
            ca.activity_date,
            ca.activity_time,
            COUNT(e.id) AS total_enrollments,
            SUM(e.status = 'approved') AS approved_count,
            SUM(e.status = 'pending') AS pending_count,
            SUM(e.status = 'cancelled') AS cancelled_count,
            SUM(e.checked_in = 1) AS checked_in_count
        FROM cultural_activities ca
        LEFT JOIN cultural_activity_enrollments e
            ON e.activity_id = ca.id
           AND e.enrolled_at BETWEEN :start_at AND :end_at
        WHERE ca.is_deleted = 0
        GROUP BY ca.id
        ORDER BY total_enrollments DESC, ca.activity_date ASC
        LIMIT 10
    ");
    $top_stmt->execute([
        'start_at' => $start_at,
        'end_at' => $end_at
    ]);
    $top_activities = $top_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Admin report load error: ' . $e->getMessage());
    $error_message = '통계를 불러오는 중 오류가 발생했습니다.';
}

$checkin_rate = $stats['enroll_approved'] > 0
    ? round(($stats['checkins_total'] / $stats['enroll_approved']) * 100, 1)
    : 0.0;
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>리포트 및 통계 | <?= htmlspecialchars($PAGE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            --admin-primary: #1a5490;
            --admin-accent: #2563eb;
            --bg-soft: #f6f8fb;
            --border-color: #d1dce8;
            --text-main: #2f2f2f;
            --text-muted: #6b7280;
            --success-green: #16a34a;
            --warning-orange: #ea580c;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { min-height: 100vh; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif; background: var(--bg-soft); color: var(--text-main); }
        .container { min-height: 100vh; display: flex; flex-direction: column; }
        .header { background: linear-gradient(135deg, var(--admin-primary), var(--admin-accent)); color: #fff; padding: 20px 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .header-content { max-width: 1400px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
        .back-btn { background: rgba(255,255,255,0.2); color: #fff; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 0.9rem; }
        .main-content { flex: 1; max-width: 1400px; margin: 0 auto; width: 100%; padding: 32px; }
        .page-title { font-size: 1.6rem; font-weight: 700; color: var(--admin-primary); margin-bottom: 8px; }
        .page-description { color: var(--text-muted); margin-bottom: 20px; }
        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; background: #fee2e2; color: #dc2626; }
        .filters { background: #fff; border-radius: 12px; padding: 16px; display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .filters label { font-size: 0.85rem; color: var(--text-muted); display: block; margin-bottom: 6px; }
        .filters input[type="date"] { padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border-color); }
        .filters button { padding: 8px 16px; border-radius: 8px; border: none; background: var(--admin-primary); color: #fff; font-weight: 600; cursor: pointer; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: #fff; border-radius: 14px; padding: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .stat-label { font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
        .stat-value { font-size: 1.6rem; font-weight: 700; margin-top: 6px; color: var(--admin-primary); }
        .stat-sub { font-size: 0.9rem; color: var(--text-muted); margin-top: 6px; }
        .table-card { background: #fff; border-radius: 16px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 10px; border-bottom: 1px solid var(--border-color); text-align: left; font-size: 0.9rem; }
        th { font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 0.8rem; font-weight: 600; }
        .badge-approved { background: #dcfce7; color: var(--success-green); }
        .badge-pending { background: #ffedd5; color: var(--warning-orange); }
        .badge-cancelled { background: #fee2e2; color: #dc2626; }
        .footer { background: #fff; border-top: 1px solid var(--border-color); padding: 20px 32px; text-align: center; color: var(--text-muted); font-size: 0.85rem; }
        @media (max-width: 768px) {
            .filters { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="header-content">
                <a href="/Admin/dashboard.php" class="back-btn">← 대시보드</a>
                <div>
                    <div style="font-size:1.2rem; font-weight:700;">리포트 및 통계</div>
                    <div style="font-size:0.85rem; opacity:0.9;">등록/출석/밴 현황 요약</div>
                </div>
            </div>
        </header>

        <main class="main-content">
            <h2 class="page-title">기간별 요약</h2>
            <p class="page-description">선택한 기간의 신청/출석 현황과 주요 지표를 확인합니다.</p>

            <?php if ($error_message): ?>
                <div class="alert"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form class="filters" method="GET">
                <div>
                    <label for="start">시작일</label>
                    <input type="date" id="start" name="start" value="<?= htmlspecialchars($start_dt->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" />
                </div>
                <div>
                    <label for="end">종료일</label>
                    <input type="date" id="end" name="end" value="<?= htmlspecialchars($end_dt->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" />
                </div>
                <button type="submit">필터 적용</button>
            </form>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">전체 활동</div>
                    <div class="stat-value"><?= $stats['activities_total'] ?></div>
                    <div class="stat-sub">활성 <?= $stats['activities_active'] ?> · 예정 <?= $stats['activities_upcoming'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">신청 건수</div>
                    <div class="stat-value"><?= $stats['enroll_total'] ?></div>
                    <div class="stat-sub">승인 <?= $stats['enroll_approved'] ?> · 대기 <?= $stats['enroll_pending'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">취소 건수</div>
                    <div class="stat-value"><?= $stats['enroll_cancelled'] ?></div>
                    <div class="stat-sub">기간 내 취소 합계</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">출석 체크인</div>
                    <div class="stat-value"><?= $stats['checkins_total'] ?></div>
                    <div class="stat-sub">승인 대비 출석률 <?= $checkin_rate ?>%</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">밴 현황</div>
                    <div class="stat-value"><?= $stats['bans_active'] ?></div>
                    <div class="stat-sub">전체 밴 <?= $stats['bans_total'] ?></div>
                </div>
            </div>

            <div class="table-card">
                <h3 style="margin-bottom: 12px; font-size: 1.1rem;">활동별 신청 상위 (Top 10)</h3>
                <?php if (empty($top_activities)): ?>
                    <p style="color: var(--text-muted);">선택한 기간에 신청 데이터가 없습니다.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>활동명</th>
                                <th>일정</th>
                                <th>총 신청</th>
                                <th>승인</th>
                                <th>대기</th>
                                <th>취소</th>
                                <th>출석</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_activities as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['program_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?= htmlspecialchars(date('Y-m-d', strtotime($row['activity_date'])), ENT_QUOTES, 'UTF-8') ?>
                                        <?= $row['activity_time'] ? htmlspecialchars(date('H:i', strtotime($row['activity_time'])), ENT_QUOTES, 'UTF-8') : '' ?>
                                    </td>
                                    <td><?= (int)$row['total_enrollments'] ?></td>
                                    <td><span class="badge badge-approved"><?= (int)$row['approved_count'] ?></span></td>
                                    <td><span class="badge badge-pending"><?= (int)$row['pending_count'] ?></span></td>
                                    <td><span class="badge badge-cancelled"><?= (int)$row['cancelled_count'] ?></span></td>
                                    <td><?= (int)$row['checked_in_count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </main>

        <footer class="footer">
            © DATANEST, KOREA UNIVERSITY – Int'l Summer &amp; Winter Campus
        </footer>
    </div>
</body>
</html>
