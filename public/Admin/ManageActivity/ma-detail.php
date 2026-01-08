<?php
// 관리자 전용 설정 파일 로드 (세션 검증 포함)
require_once '../../../config/config_admin.php';

// ID 확인
$program_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($program_id <= 0) {
    $_SESSION['mp_list_error'] = '유효하지 않은 프로그램 ID입니다.';
    header('Location: /Admin/ManageActivity/ma-index.php');
    exit();
}

// 프로그램 정보 조회
try {
    $stmt = $pdo->prepare("
        SELECT
            ca.*,
            a.name as creator_name,
            a.login_id as creator_login_id
        FROM cultural_activities ca
        LEFT JOIN admins a ON ca.created_by = a.id
        WHERE ca.id = :id AND ca.is_deleted = FALSE
        LIMIT 1
    ");
    $stmt->execute(['id' => $program_id]);
    $program = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$program) {
        $_SESSION['mp_list_error'] = '프로그램을 찾을 수 없습니다.';
        header('Location: /Admin/ManageActivity/ma-index.php');
        exit();
    }

    // 추가 이미지 조회
    $img_stmt = $pdo->prepare("
        SELECT image_path, display_order
        FROM cultural_activity_images
        WHERE activity_id = :activity_id
        ORDER BY display_order ASC
    ");
    $img_stmt->execute(['activity_id' => $program_id]);
    $additional_images = $img_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 현재 등록 인원 조회
    $enrollment_stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM cultural_activity_enrollments
        WHERE activity_id = :activity_id AND status = 'confirmed'
    ");
    $enrollment_stmt->execute(['activity_id' => $program_id]);
    $enrollment_count = $enrollment_stmt->fetchColumn();

} catch (PDOException $e) {
    error_log('Program detail fetch error: ' . $e->getMessage());
    $_SESSION['mp_list_error'] = '프로그램 정보를 불러오는 중 오류가 발생했습니다.';
    header('Location: /Admin/ManageActivity/ma-index.php');
    exit();
}

// 시간 표시
$time_display = is_null($program['activity_time']) ? 'TBD (시간 미정)' : date('H:i', strtotime($program['activity_time']));
$capacity_display = is_null($program['capacity']) ? '무제한' : number_format($program['capacity']) . '명';
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>프로그램 상세 | <?= htmlspecialchars($PAGE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
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
            max-width: 1200px;
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
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
            padding: 32px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .page-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--admin-primary);
        }

        .action-buttons {
            display: flex;
            gap: 12px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            border: none;
            transition: all 0.2s ease;
        }

        .btn-edit {
            background: linear-gradient(135deg, var(--admin-primary), var(--admin-accent));
            color: #ffffff;
        }

        .btn-edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(26, 84, 144, 0.3);
        }

        .btn-secondary {
            background: #e5e7eb;
            color: var(--text-main);
        }

        .btn-secondary:hover {
            background: #d1d5db;
        }

        /* Detail Card */
        .detail-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 24px;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .status-active {
            background: #dcfce7;
            color: var(--success-green);
        }

        .status-inactive {
            background: #fee2e2;
            color: #dc2626;
        }

        .main-image {
            width: 100%;
            max-width: 600px;
            height: auto;
            border-radius: 12px;
            margin-bottom: 24px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .detail-item {
            padding: 16px;
            background: var(--bg-soft);
            border-radius: 10px;
        }

        .detail-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            font-weight: 600;
        }

        .detail-value {
            font-size: 1.1rem;
            color: var(--admin-primary);
            font-weight: 600;
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--border-color);
        }

        .description-text {
            line-height: 1.7;
            color: var(--text-main);
            white-space: pre-wrap;
        }

        .additional-images {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }

        .additional-image {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 10px;
        }

        .qr-code-display {
            font-family: monospace;
            font-size: 1.2rem;
            color: var(--admin-primary);
            background: #f3f4f6;
            padding: 12px 16px;
            border-radius: 8px;
            display: inline-block;
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

            .detail-grid {
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
                    <a href="/Admin/ManageActivity/ma-index.php" class="back-btn">← 목록으로</a>
                    <div class="header-title">
                        <h1>프로그램 상세</h1>
                        <div class="header-subtitle">문화체험 프로그램 상세 정보</div>
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
                <h2 class="page-title"><?= htmlspecialchars($program['program_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="action-buttons">
                    <a href="/Admin/ManageActivity/ma-edit.php?id=<?= $program['id'] ?>" class="btn btn-edit">수정</a>
                    <a href="/Admin/ManageActivity/ma-index.php" class="btn btn-secondary">목록</a>
                </div>
            </div>

            <div class="detail-card">
                <span class="status-badge <?= $program['is_active'] ? 'status-active' : 'status-inactive' ?>">
                    <?= $program['is_active'] ? '활성' : '비활성' ?>
                </span>

                <!-- 대표 이미지 -->
                <img src="<?= htmlspecialchars($program['main_image_path'], ENT_QUOTES, 'UTF-8') ?>"
                     alt="<?= htmlspecialchars($program['program_name'], ENT_QUOTES, 'UTF-8') ?>"
                     class="main-image" />

                <!-- 프로그램 기본 정보 -->
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-label">활동 날짜</div>
                        <div class="detail-value"><?= date('Y-m-d', strtotime($program['activity_date'])) ?></div>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">활동 시간</div>
                        <div class="detail-value"><?= htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">활동 장소</div>
                        <div class="detail-value"><?= htmlspecialchars($program['location'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">정원</div>
                        <div class="detail-value"><?= htmlspecialchars($capacity_display, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">현재 신청 인원</div>
                        <div class="detail-value"><?= number_format($enrollment_count) ?>명</div>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">참가비</div>
                        <div class="detail-value">
                            <?= $program['has_fee'] ? '₩' . number_format($program['fee_amount']) : '무료' ?>
                        </div>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">신청 시작일</div>
                        <div class="detail-value"><?= date('Y-m-d', strtotime($program['registration_start_date'])) ?></div>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">신청 마감일</div>
                        <div class="detail-value"><?= date('Y-m-d', strtotime($program['registration_end_date'])) ?></div>
                    </div>
                </div>

                <!-- 프로그램 설명 -->
                <div style="margin-top: 32px;">
                    <h3 class="section-title">프로그램 설명</h3>
                    <p class="description-text"><?= nl2br(htmlspecialchars($program['program_description'], ENT_QUOTES, 'UTF-8')) ?></p>
                </div>

                <!-- QR 코드 -->
                <div style="margin-top: 32px;">
                    <h3 class="section-title">QR 코드</h3>
                    <div class="qr-code-display"><?= htmlspecialchars($program['qr_code'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>

                <!-- 추가 이미지 -->
                <?php if (!empty($additional_images)): ?>
                    <div style="margin-top: 32px;">
                        <h3 class="section-title">추가 이미지</h3>
                        <div class="additional-images">
                            <?php foreach ($additional_images as $img): ?>
                                <img src="<?= htmlspecialchars($img['image_path'], ENT_QUOTES, 'UTF-8') ?>"
                                     alt="추가 이미지"
                                     class="additional-image" />
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- 생성 정보 -->
                <div style="margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--border-color);">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">생성자</div>
                            <div class="detail-value" style="font-size: 0.95rem;">
                                <?= htmlspecialchars($program['creator_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                (<?= htmlspecialchars($program['creator_login_id'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>)
                            </div>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">생성일시</div>
                            <div class="detail-value" style="font-size: 0.95rem;">
                                <?= date('Y-m-d H:i:s', strtotime($program['created_at'])) ?>
                            </div>
                        </div>

                        <?php if ($program['updated_at']): ?>
                            <div class="detail-item">
                                <div class="detail-label">수정일시</div>
                                <div class="detail-value" style="font-size: 0.95rem;">
                                    <?= date('Y-m-d H:i:s', strtotime($program['updated_at'])) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="footer">
            © DATANEST, KOREA UNIVERSITY – Int'l Summer &amp; Winter Campus
        </footer>
    </div>
</body>
</html>
