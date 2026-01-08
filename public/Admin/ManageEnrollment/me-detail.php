<?php
/**
 * 학생 신청 관리 - 프로그램별 신청자 목록 및 관리
 * 관리자가 특정 프로그램의 신청자들을 관리
 */

require_once '../../../config/config_admin.php';

$admin_name = $_SESSION['admin_name'] ?? 'Administrator';
$admin_position = $_SESSION['admin_position'] ?? 'Admin';
$activity_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($activity_id <= 0) {
    header('Location: /Admin/ManageEnrollment/me-index.php');
    exit();
}

// 성공/에러 메시지
$success_message = $_SESSION['me_success'] ?? '';
$error_message = $_SESSION['me_error'] ?? '';
unset($_SESSION['me_success'], $_SESSION['me_error']);
$total_enrollment_count = 0;
$filtered_enrollment_count = 0;
$unchecked_approved_count = 0;
$requires_gown_size = false;
$gown_counts = ['S' => 0, 'M' => 0, 'L' => 0];
$gown_capacities = ['S' => null, 'M' => null, 'L' => null];
require_once '../../../config/config_admin.php';

$admin_name = $_SESSION['admin_name'] ?? 'Administrator';
$admin_position = $_SESSION['admin_position'] ?? 'Admin';
$show_unchecked_only = isset($_GET['unchecked']) && $_GET['unchecked'] === '1';
$activity_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($activity_id <= 0) {
    header('Location: /Admin/ManageEnrollment/me-index.php');
    exit();
}

// 성공/에러 메시지
$success_message = $_SESSION['me_success'] ?? '';
$error_message = $_SESSION['me_error'] ?? '';
unset($_SESSION['me_success'], $_SESSION['me_error']);

try {
    // 프로그램 정보 조회
    $activity_stmt = $pdo->prepare("
        SELECT *
        FROM cultural_activities
        WHERE id = :id AND is_deleted = 0
        LIMIT 1
    ");
    $activity_stmt->execute(['id' => $activity_id]);
    $activity = $activity_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        $_SESSION['me_error'] = '프로그램을 찾을 수 없습니다.';
        header('Location: /Admin/ManageEnrollment/me-index.php');
        exit();
    }
    $requires_gown_size = activity_requires_gown_size($activity);
    if ($requires_gown_size) {
        $gown_capacities = [
            'S' => $activity['gown_capacity_s'] ?? null,
            'M' => $activity['gown_capacity_m'] ?? null,
            'L' => $activity['gown_capacity_l'] ?? null,
        ];
    }

    $enrollments_stmt = $pdo->prepare("
        SELECT
            e.id as enrollment_id,
            e.student_id,
            e.student_name,
            e.gown_size,
            e.status,
            e.enrolled_at,
            e.fee_paid,
            e.checked_in,
            e.check_in_time,
            e.cancelled_by,
            e.admin_reason,
            e.updated_at,
            u.email
        FROM cultural_activity_enrollments e
        LEFT JOIN uwayxlsx_current u ON e.student_id COLLATE utf8mb4_uca1400_ai_ci = u.application_no
        WHERE e.activity_id = :activity_id
        ORDER BY
            CASE e.status
                WHEN 'approved' THEN 1
                WHEN 'pending' THEN 2
                WHEN 'cancelled' THEN 3
                WHEN 'rejected' THEN 4
                ELSE 5
            END,
            e.enrolled_at ASC
    ");
    $enrollments_stmt->execute(['activity_id' => $activity_id]);
    $all_enrollments = $enrollments_stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($show_unchecked_only) {
        $enrollments = array_values(array_filter($all_enrollments, fn($row) => (int)$row['checked_in'] === 0));
    } else {
        $enrollments = $all_enrollments;
    }
    if ($requires_gown_size) {
        foreach ($all_enrollments as $row) {
            $size = normalize_gown_size($row['gown_size'] ?? null);
            if ($size && isset($gown_counts[$size])) {
                $gown_counts[$size] += 1;
            }
        }
    }
    $total_enrollment_count = count($all_enrollments);
    $filtered_enrollment_count = count($enrollments);
    $unchecked_approved_count = count(array_filter(
        $all_enrollments,
        fn($row) => $row['status'] === 'approved' && (int)$row['checked_in'] === 0
    ));

} catch (PDOException $e) {
    error_log('Enrollment detail error: ' . $e->getMessage());
    error_log('Error details: ' . json_encode([
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]));
    $_SESSION['me_error'] = '신청 데이터를 불러오는데 실패했습니다. [오류: ' . $e->getMessage() . ']';
    header('Location: /Admin/ManageEnrollment/me-index.php');
    exit();
}

$seoulTz = new DateTimeZone('Asia/Seoul');
$now = new DateTime('now', $seoulTz);
$activity_date = new DateTime($activity['activity_date'], $seoulTz);
$is_past = $activity_date < $now;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>신청 관리 - <?= htmlspecialchars($activity['program_name'], ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars($PAGE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
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
            max-width: 1600px;
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
            flex: 1;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            transition: background 0.2s ease;
            white-space: nowrap;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .header-title h1 {
            font-size: 1.3rem;
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

        /* Main Content */
        .main-content {
            flex: 1;
            max-width: 1600px;
            width: 100%;
            margin: 0 auto;
            padding: 32px;
        }

        .filter-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 999px;
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: var(--text-main);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .filter-pill.active {
            background: var(--admin-primary);
            color: #ffffff;
            border-color: var(--admin-primary);
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

        /* Activity Info */
        .activity-info {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 16px;
        }

        .activity-title {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--admin-primary);
        }

        .btn-add-enrollment {
            padding: 10px 20px;
            background: var(--success-green);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-add-enrollment:hover {
            background: #15803d;
        }

        .activity-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            font-size: 0.9rem;
        }
        .gown-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }
        .gown-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 12px 14px;
        }
        .gown-card h4 {
            margin-bottom: 6px;
            font-size: 1rem;
            color: var(--admin-primary);
        }
        .gown-card .stat-row {
            display: flex;
            justify-content: space-between;
            font-size: 0.92rem;
            margin: 2px 0;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-muted);
        }

        /* Enrollments Table */
        .enrollments-section {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--admin-primary);
        }

        .section-controls {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .enrollments-count {
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .ban-form {
            margin: 0;
        }

        .btn-ban-unchecked {
            padding: 8px 16px;
            background: #f87171;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .btn-ban-unchecked:hover:not(:disabled) {
            background: #ef4444;
        }

        .btn-ban-unchecked:disabled {
            background: #f3f4f6;
            color: #9ca3af;
            cursor: not-allowed;
        }

        .search-box {
            margin: 16px 0;
            padding: 16px;
            background: #ffffff;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .search-input {
            width: 100%;
            max-width: 400px;
            padding: 10px 16px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.9rem;
            transition: border-color 0.2s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--admin-primary);
        }

        .search-input::placeholder {
            color: var(--text-muted);
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
        }

        th {
            background: var(--bg-soft);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
        }

        td {
            font-size: 0.9rem;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge-approved { background: #dcfce7; color: var(--success-green); }
        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-cancelled { background: #f3f4f6; color: #6b7280; }
        .badge-rejected { background: #fee2e2; color: var(--error-red); }
        .badge-paid { background: #cffafe; color: #0891b2; }
        .badge-unpaid { background: #fef3c7; color: #d97706; }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-small {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-mark-paid {
            background: #cffafe;
            color: #0891b2;
        }

        .btn-mark-paid:hover {
            background: #a5f3fc;
        }

        .btn-cancel {
            background: #fee2e2;
            color: var(--error-red);
        }

        .btn-cancel:hover {
            background: #fecaca;
        }

        .btn-cancel:disabled {
            background: #f3f4f6;
            color: #9ca3af;
            cursor: not-allowed;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: #ffffff;
            border-radius: 16px;
            padding: 32px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-title {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 16px;
            color: var(--admin-primary);
        }

        .modal-body {
            margin-bottom: 24px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--text-main);
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.9rem;
            font-family: inherit;
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-secondary {
            background: #e5e7eb;
            color: var(--text-main);
        }

        .btn-secondary:hover {
            background: #d1d5db;
        }

        .btn-danger {
            background: var(--error-red);
            color: #ffffff;
        }

        .btn-danger:hover {
            background: #b91c1c;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .empty-icon {
            font-size: 3rem;
            margin-bottom: 16px;
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
            .main-content {
                padding: 20px;
            }

            .activity-header {
                flex-direction: column;
                gap: 16px;
            }

            .activity-meta {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 0.85rem;
            }

            th, td {
                padding: 8px;
            }

            .action-buttons {
                flex-direction: column;
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
                    <a href="/Admin/ManageEnrollment/me-index.php" class="back-btn">← 프로그램 목록</a>
                    <div class="header-title">
                        <h1><?= htmlspecialchars($activity['program_name'], ENT_QUOTES, 'UTF-8') ?></h1>
                        <div class="header-subtitle">이 프로그램의 신청 내역을 관리합니다</div>
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
            <?php if ($success_message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <!-- Activity Info -->
            <div class="activity-info">
                <div class="activity-header">
                    <h2 class="activity-title"><?= htmlspecialchars($activity['program_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <button type="button" class="btn-add-enrollment" onclick="showAddEnrollmentModal()">
                        + 신청자 추가
                    </button>
                </div>
                <div class="activity-meta">
                    <div class="meta-item">
                        <span>📅</span>
                        <span><?= $activity_date->format('Y년 n월 j일 (l)') ?></span>
                    </div>
                    <div class="meta-item">
                        <span>🕐</span>
                        <span><?= is_null($activity['activity_time']) ? '시간 미정' : date('H:i', strtotime($activity['activity_time'])) ?></span>
                    </div>
                    <div class="meta-item">
                        <span>📍</span>
                        <span><?= htmlspecialchars($activity['location'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <?php if ($activity['has_fee']): ?>
                        <div class="meta-item">
                            <span>💰</span>
                            <span>₩<?= number_format($activity['fee_amount']) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="meta-item">
                        <span>👥</span>
                        <span>정원: <?= count(array_filter($enrollments, fn($e) => in_array($e['status'], ['approved', 'pending']))) ?> / <?= $activity['capacity'] ?? '∞' ?></span>
                    </div>
                </div>
                <?php if ($requires_gown_size): ?>
                <div class="gown-stats">
                    <?php foreach (['S','M','L'] as $size): ?>
                        <?php
                            $cap = $gown_capacities[$size];
                            $used = $gown_counts[$size] ?? 0;
                            $remaining = is_null($cap) ? '∞' : max(0, $cap - $used);
                        ?>
                        <div class="gown-card">
                            <h4><?= $size ?> 사이즈</h4>
                            <div class="stat-row"><span>신청</span><span><?= $used ?>명</span></div>
                            <div class="stat-row"><span>수량</span><span><?= is_null($cap) ? '제한 없음' : $cap . '벌' ?></span></div>
                            <div class="stat-row"><span>잔여</span><span><?= is_null($cap) ? '제한 없음' : $remaining . '벌' ?></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Enrollments Table -->
            <div class="enrollments-section">
                <div class="section-header">
                    <div>
                        <h3 class="section-title">신청 목록</h3>
                        <div style="margin-top:10px;">
                            <?php $unchecked_url = '/Admin/ManageEnrollment/me-detail.php?id=' . $activity_id . '&unchecked=1'; ?>
                            <?php $all_url = '/Admin/ManageEnrollment/me-detail.php?id=' . $activity_id; ?>
                            <a href="<?= $all_url ?>" class="filter-pill <?= !$show_unchecked_only ? 'active' : '' ?>">전체 보기</a>
                            <a href="<?= $unchecked_url ?>" class="filter-pill <?= $show_unchecked_only ? 'active' : '' ?>">미출석만</a>
                        </div>
                    </div>
                    <div class="section-controls">
                        <span class="enrollments-count">
                            <?= $show_unchecked_only ? '미출석 ' : '전체 ' ?>
                            <span id="filteredCount"><?= $filtered_enrollment_count ?></span> / <?= $total_enrollment_count ?> 명
                        </span>
                        <form class="ban-form" method="POST" action="/Admin/ManageEnrollment/me-ban-unchecked.php" onsubmit="return confirmBanUnchecked(<?= $unchecked_approved_count ?? 0 ?>);">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="activity_id" value="<?= $activity_id ?>" />
                            <button type="submit"
                                    class="btn-ban-unchecked"
                                    <?= ($unchecked_approved_count ?? 0) === 0 ? 'disabled' : '' ?>>
                                미출석자 밴 (<?= $unchecked_approved_count ?? 0 ?>명)
                            </button>
                        </form>
                        <?php if ($is_past): ?>
                        <form class="ban-form" method="POST" action="/Admin/ManageEnrollment/me-cancel-unchecked.php" onsubmit="return confirmCancelUnchecked();">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="activity_id" value="<?= $activity_id ?>" />
                            <button type="submit" class="btn-ban-unchecked" style="background: #b91c1c; border-color: #b91c1c;">
                                지난 이벤트 미체크인 일괄 취소
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($enrollments)): ?>
                    <div class="search-box">
                        <input
                            type="text"
                            id="searchInput"
                            class="search-input"
                            placeholder="학번 또는 이름으로 검색..."
                            onkeyup="filterEnrollments()">
                    </div>
                <?php endif; ?>

                <?php if (empty($enrollments)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📋</div>
                        <p>아직 이 프로그램에 대한 신청이 없습니다.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>학번</th>
                                    <th>이름</th>
                                    <th>이메일</th>
                                    <?php if ($requires_gown_size): ?>
                                        <th>가운 사이즈</th>
                                    <?php endif; ?>
                                    <th>상태</th>
                                    <th>신청일시</th>
                                    <?php if ($activity['has_fee']): ?>
                                        <th>수강료</th>
                                    <?php endif; ?>
                                    <th>출석</th>
                                    <th>관리</th>
                                </tr>
                            </thead>
                            <tbody id="enrollmentsTableBody">
                                <?php foreach ($enrollments as $enrollment): ?>
                                    <tr class="enrollment-row"
                                        data-student-id="<?= htmlspecialchars($enrollment['student_id'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-student-name="<?= htmlspecialchars($enrollment['student_name'], ENT_QUOTES, 'UTF-8') ?>">
                                        <td><?= htmlspecialchars($enrollment['student_id'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <?= htmlspecialchars($enrollment['student_name'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php if ($enrollment['status'] === 'cancelled' && $enrollment['cancelled_by'] === 'admin'): ?>
                                                <br><small style="color: var(--error-red);">관리자 취소: <?= htmlspecialchars($enrollment['admin_reason'], ENT_QUOTES, 'UTF-8') ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($enrollment['email'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                        <?php if ($requires_gown_size): ?>
                                            <td><?= htmlspecialchars($enrollment['gown_size'] ?? '-', ENT_QUOTES, 'UTF-8') ?: '-' ?></td>
                                        <?php endif; ?>
                                        <td>
                                            <?php
                                                $status_kr = ['approved' => '승인', 'pending' => '대기', 'cancelled' => '취소', 'rejected' => '거부'];
                                            ?>
                                            <span class="badge badge-<?= $enrollment['status'] ?>">
                                                <?= $status_kr[$enrollment['status']] ?? $enrollment['status'] ?>
                                            </span>
                                        </td>
                                        <td><?= date('Y-m-d H:i', strtotime($enrollment['enrolled_at'])) ?></td>
                                        <?php if ($activity['has_fee']): ?>
                                            <td>
                                                <span class="badge badge-<?= $enrollment['fee_paid'] ? 'paid' : 'unpaid' ?>">
                                                    <?= $enrollment['fee_paid'] ? '납부완료' : '미납' ?>
                                                </span>
                                            </td>
                                        <?php endif; ?>
                                        <td>
                                            <?php if ($enrollment['checked_in']): ?>
                                                ✓ <?= date('m-d H:i', strtotime($enrollment['check_in_time'])) ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($activity['has_fee'] && !$enrollment['fee_paid'] && $enrollment['status'] === 'approved'): ?>
                                                    <form method="POST" action="/Admin/ManageEnrollment/me-mark-paid.php" style="display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="enrollment_id" value="<?= $enrollment['enrollment_id'] ?>" />
                                                        <button type="submit" class="btn-small btn-mark-paid">납부완료</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php
                                                $is_checked_in = !empty($enrollment['checked_in']) && (int)$enrollment['checked_in'] === 1;
                                                $can_force_cancel = ($enrollment['status'] === 'approved') && !$is_checked_in;
                                                ?>
                                                <?php if ($can_force_cancel): ?>
                                                    <button type="button"
                                                            class="btn-small btn-cancel"
                                                            data-enrollment-id="<?= (int)$enrollment['enrollment_id'] ?>"
                                                            data-student-name="<?= htmlspecialchars($enrollment['student_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                            onclick="showCancelModalFromButton(this)">
                                                        강제취소
                                                    </button>
                                                <?php elseif ($enrollment['status'] === 'approved' && $is_checked_in): ?>
                                                    <button type="button" class="btn-small btn-cancel" disabled>
                                                        체크인 완료
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer -->
        <footer class="footer">
            © DATANEST, KOREA UNIVERSITY – Int'l Summer &amp; Winter Campus
        </footer>
    </div>

    <!-- Force Cancel Modal -->
    <div id="cancelModal" class="modal">
        <div class="modal-content">
            <h3 class="modal-title">신청 강제 취소</h3>
            <div class="modal-body">
                <p style="margin-bottom: 16px;"><strong id="cancelStudentName"></strong> 학생의 신청을 취소하려고 합니다.</p>
                <form id="cancelForm" method="POST" action="/Admin/ManageEnrollment/me-force-cancel.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="enrollment_id" id="cancelEnrollmentId" />
                    <div class="form-group">
                        <label class="form-label" for="admin_reason">취소 사유 *</label>
                        <textarea class="form-control"
                                  id="admin_reason"
                                  name="admin_reason"
                                  required
                                  placeholder="신청을 취소하는 사유를 입력하세요..."></textarea>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="hideCancelModal()">취소</button>
                        <button type="submit" class="btn btn-danger">취소 확인</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Enrollment Modal -->
    <div id="addEnrollmentModal" class="modal">
        <div class="modal-content">
            <h3 class="modal-title">신청자 추가</h3>
            <div class="modal-body">
                <form id="addEnrollmentForm" method="POST" action="/Admin/ManageEnrollment/me-force-enroll.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="activity_id" value="<?= $activity_id ?>" />
                    <div class="form-group">
                        <label class="form-label" for="student_id">학번 *</label>
                        <input type="text"
                               class="form-control"
                               id="student_id"
                               name="student_id"
                               required
                               placeholder="학생 학번을 입력하세요" />
                    </div>
                    <?php if ($requires_gown_size): ?>
                    <div class="form-group">
                        <label class="form-label" for="gown_size">졸업가운 사이즈 *</label>
                        <select class="form-control" id="gown_size" name="gown_size" required>
                            <option value="">사이즈 선택</option>
                            <option value="S">S</option>
                            <option value="M">M</option>
                            <option value="L">L</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="hideAddEnrollmentModal()">취소</button>
                        <button type="submit" class="btn btn-danger" style="background: var(--success-green);">신청자 추가</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function filterEnrollments() {
            const searchInput = document.getElementById('searchInput');
            const filter = searchInput.value.toLowerCase().trim();
            const rows = document.querySelectorAll('.enrollment-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const studentId = row.getAttribute('data-student-id').toLowerCase();
                const studentName = row.getAttribute('data-student-name').toLowerCase();

                if (studentId.includes(filter) || studentName.includes(filter)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            // Update filtered count
            document.getElementById('filteredCount').textContent = visibleCount;
        }

        function showCancelModal(enrollmentId, studentName) {
            document.getElementById('cancelEnrollmentId').value = enrollmentId;
            document.getElementById('cancelStudentName').textContent = studentName;
            document.getElementById('cancelModal').classList.add('active');
        }

        function showCancelModalFromButton(button) {
            if (!button) {
                return;
            }
            const enrollmentId = button.dataset.enrollmentId;
            const studentName = button.dataset.studentName || '';
            showCancelModal(enrollmentId, studentName);
        }

        function hideCancelModal() {
            document.getElementById('cancelModal').classList.remove('active');
            document.getElementById('admin_reason').value = '';
        }

        function showAddEnrollmentModal() {
            document.getElementById('addEnrollmentModal').classList.add('active');
        }

        function hideAddEnrollmentModal() {
            document.getElementById('addEnrollmentModal').classList.remove('active');
            document.getElementById('student_id').value = '';
        }

        function confirmBanUnchecked(count) {
            if (count === 0) {
                alert('미출석 상태인 승인 학생이 없습니다.');
                return false;
            }
            return confirm('미출석 상태인 승인 학생 ' + count + '명을 밴 처리하시겠습니까?');
        }
        function confirmCancelUnchecked() {
            return confirm('지난 이벤트에서 미체크인 상태인 승인 학생을 모두 취소하시겠습니까?');
        }

        // Close modals when clicking outside
        document.getElementById('cancelModal').addEventListener('click', function(e) {
            if (e.target === this) hideCancelModal();
        });

        document.getElementById('addEnrollmentModal').addEventListener('click', function(e) {
            if (e.target === this) hideAddEnrollmentModal();
        });
    </script>
</body>
</html>
