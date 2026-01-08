<?php
require_once '../../../config/config_admin.php';

$show_all = isset($_GET['all']) && $_GET['all'] === '1';
$search_query = trim($_GET['q'] ?? '');

$success_message = $_SESSION['mb_success'] ?? '';
$error_message = $_SESSION['mb_error'] ?? '';
unset($_SESSION['mb_success'], $_SESSION['mb_error']);

try {
    $summary_active_stmt = $pdo->query("SELECT COUNT(*) FROM cultural_activity_bans WHERE is_active = 1");
    $active_count = (int)$summary_active_stmt->fetchColumn();

    $summary_total_stmt = $pdo->query("SELECT COUNT(*) FROM cultural_activity_bans");
    $total_count = (int)$summary_total_stmt->fetchColumn();

    $params = [];
    $conditions = [];

    if (!$show_all) {
        $conditions[] = 'b.is_active = 1';
    }

    if ($search_query !== '') {
        $conditions[] = '(b.student_id LIKE :search OR u.applicant_name LIKE :search OR b.ban_reason LIKE :search)';
        $params['search'] = '%' . $search_query . '%';
    }

    $sql = "
        SELECT
            b.id,
            b.student_id,
            b.ban_type,
            b.activity_id,
            b.ban_reason,
            b.is_active,
            b.banned_at,
            b.banned_by,
            ca.program_name,
            adm.name AS admin_name,
            adm.login_id AS admin_login,
            u.applicant_name
        FROM cultural_activity_bans b
        LEFT JOIN cultural_activities ca ON b.activity_id = ca.id
        LEFT JOIN admins adm ON b.banned_by = adm.id
        LEFT JOIN uwayxlsx_current u ON b.student_id COLLATE utf8mb4_uca1400_ai_ci = u.application_no
    ";

    if (!empty($conditions)) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY b.is_active DESC, b.banned_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $bans = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('ManageBan load error: ' . $e->getMessage());
    $bans = [];
    $active_count = 0;
    $total_count = 0;
    $error_message = '밴 목록을 불러오는 중 오류가 발생했습니다: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>밴 관리 | <?= htmlspecialchars($PAGE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            --admin-primary: #1e40af;
            --admin-accent: #3b82f6;
            --bg-soft: #f6f8fb;
            --border-color: #d1dce8;
            --text-main: #1f2933;
            --text-muted: #6b7280;
            --success-green: #16a34a;
            --error-red: #dc2626;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { min-height: 100vh; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif; background: var(--bg-soft); color: var(--text-main); }
        .container { min-height: 100vh; display: flex; flex-direction: column; }
        .header {
            background: linear-gradient(135deg, var(--admin-primary), var(--admin-accent));
            color: #fff;
            padding: 20px 32px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: #fff;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.2s ease;
        }
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        .header-title h1 { font-size: 1.4rem; margin-bottom: 4px; }
        .header-subtitle { font-size: 0.9rem; opacity: 0.9; }
        .header-right { display: flex; gap: 12px; align-items: center; }
        .admin-badge {
            padding: 6px 14px;
            background: rgba(255,255,255,0.25);
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .main-content {
            flex: 1;
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
            padding: 32px;
        }
        .alert {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
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
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .summary-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .summary-label { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 6px; }
        .summary-value { font-size: 1.6rem; font-weight: 700; color: var(--admin-primary); }
        .section-card {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .filters {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .filter-pill {
            padding: 6px 14px;
            border-radius: 999px;
            border: 1px solid var(--border-color);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-main);
        }
        .filter-pill.active {
            background: var(--admin-primary);
            color: #fff;
            border-color: var(--admin-primary);
        }
        .search-form {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .search-input {
            flex: 1;
            min-width: 200px;
            padding: 10px 14px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        .search-button {
            padding: 10px 16px;
            border-radius: 8px;
            border: none;
            background: var(--admin-primary);
            color: #fff;
            font-weight: 600;
            cursor: pointer;
        }
        .table-wrapper { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.9rem;
        }
        th {
            text-transform: uppercase;
            font-size: 0.8rem;
            color: var(--text-muted);
            letter-spacing: 0.5px;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-active { background: #fee2e2; color: #b91c1c; }
        .badge-inactive { background: #dcfce7; color: #166534; }
        .btn-unban {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            background: #22c55e;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-unban:hover { background: #16a34a; }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }
        @media (max-width: 640px) {
            .section-header { flex-direction: column; }
            .search-form { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="header-content">
                <div class="header-left">
                    <a href="/Admin/dashboard.php" class="back-btn">← 대시보드</a>
                    <div class="header-title">
                        <h1>밴 관리</h1>
                        <div class="header-subtitle">학생 차단 현황을 확인하고 해제할 수 있습니다.</div>
                    </div>
                </div>
                <div class="header-right">
                    <span class="admin-badge"><?= htmlspecialchars($admin_position, ENT_QUOTES, 'UTF-8') ?></span>
                    <a href="/Auth/admin_logout.php" class="back-btn" style="background: rgba(255,255,255,0.85); color: var(--admin-primary);">로그아웃</a>
                </div>
            </div>
        </header>

        <main class="main-content">
            <?php if ($success_message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-label">활성 밴</div>
                    <div class="summary-value"><?= number_format($active_count) ?></div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">전체 밴</div>
                    <div class="summary-value"><?= number_format($total_count) ?></div>
                </div>
            </div>

            <div class="section-card">
                <div class="section-header">
                    <div class="filters">
                        <?php $active_url = '/Admin/ManageBan/mb-index.php'; ?>
                        <?php $all_url = '/Admin/ManageBan/mb-index.php?all=1'; ?>
                        <a href="<?= $active_url ?>" class="filter-pill <?= !$show_all ? 'active' : '' ?>">활성 밴만</a>
                        <a href="<?= $all_url ?>" class="filter-pill <?= $show_all ? 'active' : '' ?>">전체 보기</a>
                    </div>
                    <form class="search-form" method="GET" action="/Admin/ManageBan/mb-index.php">
                        <?php if ($show_all): ?>
                            <input type="hidden" name="all" value="1" />
                        <?php endif; ?>
                        <input type="text"
                               name="q"
                               class="search-input"
                               placeholder="학번, 이름 또는 사유 검색"
                               value="<?= htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8') ?>" />
                        <button type="submit" class="search-button">검색</button>
                    </form>
                </div>

                <div class="table-wrapper">
                    <?php if (empty($bans)): ?>
                        <div class="empty-state">표시할 밴 데이터가 없습니다.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>학생</th>
                                    <th>유형</th>
                                    <th>프로그램</th>
                                    <th>사유</th>
                                    <th>상태</th>
                                    <th>등록일</th>
                                    <th>처리자</th>
                                    <th>관리</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bans as $ban): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($ban['student_id'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                            <small><?= htmlspecialchars($ban['applicant_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></small>
                                        </td>
                                        <td><?= $ban['ban_type'] === 'all' ? '전체 차단' : '프로그램 차단' ?></td>
                                        <td>
                                            <?php if ($ban['ban_type'] === 'all'): ?>
                                                전체 활동
                                            <?php else: ?>
                                                <?= htmlspecialchars($ban['program_name'] ?? '삭제된 프로그램', ENT_QUOTES, 'UTF-8') ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= nl2br(htmlspecialchars($ban['ban_reason'], ENT_QUOTES, 'UTF-8')) ?></td>
                                        <td>
                                            <?php if ((int)$ban['is_active'] === 1): ?>
                                                <span class="badge badge-active">활성</span>
                                            <?php else: ?>
                                                <span class="badge badge-inactive">해제</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($ban['banned_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <?= htmlspecialchars($ban['admin_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?><br>
                                            <small><?= htmlspecialchars($ban['admin_login'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                                        </td>
                                        <td>
                                            <?php if ((int)$ban['is_active'] === 1): ?>
                                                <form method="POST" action="/Admin/ManageBan/mb-unban.php" onsubmit="return confirm('이 학생의 밴을 해제하시겠습니까?');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="ban_id" value="<?= (int)$ban['id'] ?>" />
                                                    <button type="submit" class="btn-unban">밴 해제</button>
                                                </form>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <footer class="footer" style="background:#fff;border-top:1px solid var(--border-color);padding:20px 32px;text-align:center;color:var(--text-muted);font-size:0.85rem;">
            © DATANEST, KOREA UNIVERSITY – Int'l Summer &amp; Winter Campus
        </footer>
    </div>
</body>
</html>
