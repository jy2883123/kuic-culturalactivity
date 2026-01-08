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
        SELECT *
        FROM cultural_activities
        WHERE id = :id AND is_deleted = FALSE
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
        SELECT id, image_path, display_order
        FROM cultural_activity_images
        WHERE activity_id = :activity_id
        ORDER BY display_order ASC
    ");
    $img_stmt->execute(['activity_id' => $program_id]);
    $additional_images = $img_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Program fetch error: ' . $e->getMessage());
    $_SESSION['mp_list_error'] = '프로그램 정보를 불러오는 중 오류가 발생했습니다.';
    header('Location: /Admin/ManageActivity/ma-index.php');
    exit();
}

// 성공/에러 메시지 처리
$success_message = $_SESSION['mp_edit_success'] ?? '';
$error_message = $_SESSION['mp_edit_error'] ?? '';
$error_details = $_SESSION['mp_edit_error_details'] ?? null;
$debug_info = $_SESSION['mp_edit_debug_info'] ?? null;
unset($_SESSION['mp_edit_success'], $_SESSION['mp_edit_error'], $_SESSION['mp_edit_error_details'], $_SESSION['mp_edit_debug_info']);
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>프로그램 수정 | <?= htmlspecialchars($PAGE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
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
            color: #dc2626;
            border: 1px solid #dc2626;
        }

        .form-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .form-section {
            margin-bottom: 32px;
            padding-bottom: 32px;
            border-bottom: 1px solid var(--border-color);
        }

        .form-section:last-child {
            border-bottom: none;
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--admin-primary);
            margin-bottom: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 16px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        label {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 8px;
        }

        .required {
            color: #dc2626;
        }

        .optional {
            color: var(--text-muted);
            font-weight: 400;
        }

        input[type="text"],
        input[type="number"],
        input[type="date"],
        input[type="time"],
        input[type="datetime-local"],
        textarea {
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.2s ease;
        }

        input:focus,
        textarea:focus {
            outline: none;
            border-color: var(--admin-accent);
        }
        #gown_capacity_group input[disabled] {
            background: #f1f5f9;
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .help-text {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 6px;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 32px;
        }

        .btn {
            padding: 12px 28px;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--admin-primary), var(--admin-accent));
            color: #ffffff;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(26, 84, 144, 0.3);
        }

        .btn-secondary {
            background: #e5e7eb;
            color: var(--text-main);
        }

        .btn-secondary:hover {
            background: #d1d5db;
        }

        input[type="file"] {
            display: none;
        }

        .file-upload-area {
            border: 2px dashed var(--border-color);
            border-radius: 10px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-bottom: 12px;
        }

        .file-upload-area:hover {
            border-color: var(--admin-accent);
            background: rgba(37, 99, 235, 0.02);
        }

        .upload-icon {
            font-size: 3rem;
            margin-bottom: 12px;
        }

        .upload-text {
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .preview-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }

        .preview-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
        }

        .preview-image {
            width: 100%;
            height: 120px;
            object-fit: cover;
        }

        .current-images {
            margin-bottom: 16px;
        }

        .current-image-item {
            position: relative;
            display: inline-block;
            margin: 8px;
        }

        .current-image {
            width: 150px;
            height: 100px;
            object-fit: cover;
            border-radius: 8px;
        }

        .remove-image-btn {
            position: absolute;
            top: 4px;
            right: 4px;
            background: #dc2626;
            color: #ffffff;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            cursor: pointer;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

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

            .form-card {
                padding: 24px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
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
                        <h1>프로그램 수정</h1>
                        <div class="header-subtitle">문화체험 프로그램 정보 수정</div>
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
                <div class="alert alert-error">
                    <strong>에러:</strong> <?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?>

                    <?php if ($error_details): ?>
                        <details style="margin-top: 12px; padding: 12px; background: rgba(0,0,0,0.05); border-radius: 6px;">
                            <summary style="cursor: pointer; font-weight: 600; margin-bottom: 8px;">상세 에러 정보 보기</summary>
                            <div style="font-family: monospace; font-size: 0.85rem; white-space: pre-wrap;">
                                <strong>파일:</strong> <?= htmlspecialchars($error_details['file'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                <br><strong>라인:</strong> <?= htmlspecialchars($error_details['line'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                <br><br><strong>스택 트레이스:</strong>
                                <br><?= htmlspecialchars($error_details['trace'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </details>
                    <?php endif; ?>

                    <?php if ($debug_info): ?>
                        <details style="margin-top: 12px; padding: 12px; background: rgba(0,0,0,0.05); border-radius: 6px;">
                            <summary style="cursor: pointer; font-weight: 600; margin-bottom: 8px;">디버그 정보 보기</summary>
                            <div style="font-family: monospace; font-size: 0.85rem;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <?php foreach ($debug_info as $key => $value): ?>
                                        <tr style="border-bottom: 1px solid rgba(0,0,0,0.1);">
                                            <td style="padding: 4px 8px; font-weight: 600; width: 40%;"><?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?></td>
                                            <td style="padding: 4px 8px;"><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form action="/Admin/ManageActivity/handler/ma-edit-handler.php" method="POST" enctype="multipart/form-data" class="form-card">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="program_id" value="<?= $program['id'] ?>" />

                <!-- 프로그램 기본 정보 -->
                <div class="form-section">
                    <h2 class="section-title">프로그램 기본 정보</h2>

                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="program_name">
                                프로그램명 (영문) <span class="required">*</span>
                            </label>
                            <input type="text" id="program_name" name="program_name" required
                                   value="<?= htmlspecialchars($program['program_name'], ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="e.g., Traditional Korean Tea Ceremony Experience" />
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="program_description">
                                프로그램 설명 (영문) <span class="required">*</span>
                            </label>
                            <textarea id="program_description" name="program_description" required
                                      placeholder="Describe the cultural activity in detail..."><?= htmlspecialchars($program['program_description'], ENT_QUOTES, 'UTF-8') ?></textarea>
                            <span class="help-text">학생들에게 보여질 프로그램 설명을 작성하세요.</span>
                        </div>
                    </div>
                </div>

                <!-- 일시 및 장소 -->
                <div class="form-section">
                    <h2 class="section-title">일시 및 장소</h2>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="activity_date">
                                활동 날짜 <span class="required">*</span>
                            </label>
                            <input type="date" id="activity_date" name="activity_date" required
                                   value="<?= htmlspecialchars($program['activity_date'], ENT_QUOTES, 'UTF-8') ?>" />
                        </div>

                        <div class="form-group">
                            <label for="activity_time">
                                활동 시간 <span class="required">*</span>
                            </label>
                            <div class="checkbox-group" style="margin-bottom: 8px;">
                                <input type="checkbox" id="time_tbd" name="time_tbd" <?= is_null($program['activity_time']) ? 'checked' : '' ?> />
                                <label for="time_tbd" style="margin: 0;">시간 미정 (TBD)</label>
                            </div>
                            <input type="time" id="activity_time" name="activity_time"
                                   value="<?= is_null($program['activity_time']) ? '' : date('H:i', strtotime($program['activity_time'])) ?>"
                                   <?= is_null($program['activity_time']) ? 'disabled' : 'required' ?> />
                            <span class="help-text" id="time_tbd_note" style="<?= is_null($program['activity_time']) ? '' : 'display: none;' ?> color: var(--warning-orange);">시간 미정으로 표시됩니다.</span>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="location">
                                활동 장소 <span class="required">*</span>
                            </label>
                            <input type="text" id="location" name="location" required
                                   value="<?= htmlspecialchars($program['location'], ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="e.g., Cultural Hall, 3rd Floor, Main Building" />
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="requires_gown_size" name="requires_gown_size" <?= !empty($program['requires_gown_size']) ? 'checked' : '' ?> />
                                <label for="requires_gown_size" style="margin: 0;">졸업가운 사이즈 수집</label>
                            </div>
                            <span class="help-text">졸업식 등 가운 착용 활동이면 체크하여 S/M/L 선택을 받습니다.</span>
                        </div>
                    </div>
                    <div class="form-row" id="gown_capacity_group" style="<?= !empty($program['requires_gown_size']) ? '' : 'display: none;' ?>">
                        <div class="form-group">
                            <label for="gown_capacity_s">가운 수량 - S</label>
                            <input type="number" id="gown_capacity_s" name="gown_capacity_s" min="0"
                                   value="<?= htmlspecialchars($program['gown_capacity_s'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="예: 20" />
                        </div>
                        <div class="form-group">
                            <label for="gown_capacity_m">가운 수량 - M</label>
                            <input type="number" id="gown_capacity_m" name="gown_capacity_m" min="0"
                                   value="<?= htmlspecialchars($program['gown_capacity_m'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="예: 40" />
                        </div>
                        <div class="form-group">
                            <label for="gown_capacity_l">가운 수량 - L</label>
                            <input type="number" id="gown_capacity_l" name="gown_capacity_l" min="0"
                                   value="<?= htmlspecialchars($program['gown_capacity_l'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="예: 30" />
                        </div>
                        <div class="form-group full-width">
                            <span class="help-text">비워두면 해당 사이즈는 수량 제한 없이 신청 가능합니다.</span>
                        </div>
                    </div>
                </div>

                <!-- 정원 설정 -->
                <div class="form-section">
                    <h2 class="section-title">정원 설정</h2>

                    <div class="form-row">
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="unlimited_capacity" name="unlimited_capacity" <?= is_null($program['capacity']) ? 'checked' : '' ?> />
                                <label for="unlimited_capacity" style="margin: 0;">무제한 정원</label>
                            </div>
                            <span class="help-text">체크 시 정원 제한 없이 누구나 신청 가능합니다.</span>
                        </div>

                        <div class="form-group" id="capacity_input_group" style="<?= is_null($program['capacity']) ? 'display: none;' : '' ?>">
                            <label for="capacity">
                                최대 정원 <span class="required">*</span>
                            </label>
                            <input type="number" id="capacity" name="capacity" min="1"
                                   value="<?= is_null($program['capacity']) ? '' : $program['capacity'] ?>"
                                   placeholder="30" />
                            <span class="help-text">프로그램 최대 수용 인원을 입력하세요.</span>
                        </div>
                    </div>
                </div>

                <!-- 참가비 설정 -->
                <div class="form-section">
                    <h2 class="section-title">참가비 설정</h2>

                    <div class="form-row">
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="has_fee" name="has_fee" <?= $program['has_fee'] ? 'checked' : '' ?> />
                                <label for="has_fee" style="margin: 0;">참가비 있음</label>
                            </div>
                            <span class="help-text">참가비가 있는 경우 체크하세요.</span>
                        </div>

                        <div class="form-group" id="fee_amount_group" style="<?= $program['has_fee'] ? '' : 'display: none;' ?>">
                            <label for="fee_amount">
                                참가비 금액 (₩)
                            </label>
                            <input type="number" id="fee_amount" name="fee_amount" min="0" step="1000"
                                   value="<?= $program['has_fee'] ? $program['fee_amount'] : '' ?>"
                                   placeholder="10000" />
                            <span class="help-text">참가비는 사무실에서 현금으로만 납부 가능합니다.</span>
                        </div>
                    </div>
                </div>

                <!-- 신청 기간 -->
                <div class="form-section">
                    <h2 class="section-title">신청 기간</h2>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="registration_start_date">
                                신청 시작일시 <span class="required">*</span>
                            </label>
                            <input type="datetime-local" id="registration_start_date" name="registration_start_date" required
                                   value="<?= date('Y-m-d\TH:i', strtotime($program['registration_start_date'])) ?>" />
                        </div>

                        <div class="form-group">
                            <label for="registration_end_date">
                                신청 마감일시 <span class="required">*</span>
                            </label>
                            <input type="datetime-local" id="registration_end_date" name="registration_end_date" required
                                   value="<?= date('Y-m-d\TH:i', strtotime($program['registration_end_date'])) ?>" />
                        </div>

                        <div class="form-group">
                            <label for="cancellation_deadline">
                                취소 가능 기한
                            </label>
                            <input type="datetime-local" id="cancellation_deadline" name="cancellation_deadline"
                                   value="<?php echo (!empty($program['cancellation_deadline']) && $program['cancellation_deadline'] !== null) ? date('Y-m-d\TH:i', strtotime($program['cancellation_deadline'])) : ''; ?>" />
                            <span class="help-text">학생이 신청을 취소할 수 있는 마감 시간입니다. 미설정시 활동 시작 전까지 취소 가능합니다.</span>
                        </div>
                    </div>
                </div>

                <!-- 이미지 업로드 -->
                <div class="form-section">
                    <h2 class="section-title">프로그램 이미지</h2>

                    <div class="form-group">
                        <label for="main_image">
                            대표 이미지 <span class="optional">(변경하지 않으려면 비워두세요)</span>
                        </label>

                        <!-- 현재 대표 이미지 -->
                        <div class="current-images">
                            <div class="current-image-item">
                                <img src="<?= htmlspecialchars($program['main_image_path'], ENT_QUOTES, 'UTF-8') ?>"
                                     alt="현재 대표 이미지"
                                     class="current-image" />
                                <div style="margin-top: 4px; font-size: 0.8rem; color: var(--text-muted);">현재 대표 이미지</div>
                            </div>
                        </div>

                        <div class="file-upload-area" id="main_image_upload_area">
                            <div class="upload-icon">📷</div>
                            <div class="upload-text">
                                클릭하거나 이미지를 드래그하여 업로드 (선택사항)
                            </div>
                        </div>
                        <input type="file" id="main_image" name="main_image" accept="image/*" />
                        <div id="main_image_preview" class="preview-container"></div>
                        <span class="help-text">새 이미지를 업로드하면 기존 이미지를 대체합니다.</span>
                    </div>

                    <div class="form-group" style="margin-top: 24px;">
                        <label for="additional_images">
                            추가 이미지 <span class="optional">(선택사항)</span>
                        </label>

                        <!-- 현재 추가 이미지들 -->
                        <?php if (!empty($additional_images)): ?>
                            <div class="current-images" id="current_additional_images">
                                <?php foreach ($additional_images as $img): ?>
                                    <div class="current-image-item" data-image-id="<?= $img['id'] ?>">
                                        <img src="<?= htmlspecialchars($img['image_path'], ENT_QUOTES, 'UTF-8') ?>"
                                             alt="추가 이미지"
                                             class="current-image" />
                                        <button type="button" class="remove-image-btn" onclick="removeAdditionalImage(<?= $img['id'] ?>)">✕</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="images_to_delete" id="images_to_delete" value="" />
                        <?php endif; ?>

                        <div class="file-upload-area" id="additional_images_upload_area">
                            <div class="upload-icon">🖼️</div>
                            <div class="upload-text">
                                여러 이미지를 선택하여 업로드 (최대 5개)
                            </div>
                        </div>
                        <input type="file" id="additional_images" name="additional_images[]" accept="image/*" multiple />
                        <div id="additional_images_preview" class="preview-container"></div>
                        <span class="help-text">새 이미지를 추가하거나 기존 이미지를 삭제할 수 있습니다.</span>
                    </div>
                </div>

                <!-- 활성화 상태 -->
                <div class="form-section">
                    <h2 class="section-title">프로그램 활성화</h2>

                    <div class="form-row">
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="is_active" name="is_active" <?= $program['is_active'] ? 'checked' : '' ?> />
                                <label for="is_active" style="margin: 0;">프로그램 활성화</label>
                            </div>
                            <span class="help-text">비활성화 시 학생들에게 표시되지 않습니다.</span>
                        </div>
                    </div>
                </div>

                <!-- 제출 버튼 -->
                <div class="form-actions">
                    <a href="/Admin/ManageActivity/ma-index.php" class="btn btn-secondary">취소</a>
                    <button type="submit" class="btn btn-primary">프로그램 수정</button>
                </div>
            </form>
        </main>

        <!-- Footer -->
        <footer class="footer">
            © DATANEST, KOREA UNIVERSITY – Int'l Summer &amp; Winter Campus
        </footer>
    </div>

    <script>
        // 삭제할 이미지 ID 목록
        let imagesToDelete = [];

        // 추가 이미지 삭제
        function removeAdditionalImage(imageId) {
            if (confirm('이 이미지를 삭제하시겠습니까?')) {
                imagesToDelete.push(imageId);
                document.getElementById('images_to_delete').value = imagesToDelete.join(',');

                // UI에서 제거
                const imageItem = document.querySelector(`[data-image-id="${imageId}"]`);
                if (imageItem) {
                    imageItem.remove();
                }
            }
        }

        // 시간 미정 체크박스 토글
        const timeTbdCheckbox = document.getElementById('time_tbd');
        const activityTimeInput = document.getElementById('activity_time');
        const timeTbdNote = document.getElementById('time_tbd_note');

        timeTbdCheckbox.addEventListener('change', function() {
            if (this.checked) {
                activityTimeInput.disabled = true;
                activityTimeInput.removeAttribute('required');
                activityTimeInput.value = '';
                timeTbdNote.style.display = 'block';
            } else {
                activityTimeInput.disabled = false;
                activityTimeInput.setAttribute('required', 'required');
                timeTbdNote.style.display = 'none';
            }
        });

        // 무제한 정원 체크박스 토글
        const unlimitedCheckbox = document.getElementById('unlimited_capacity');
        const capacityInputGroup = document.getElementById('capacity_input_group');
        const capacityInput = document.getElementById('capacity');

        unlimitedCheckbox.addEventListener('change', function() {
            if (this.checked) {
                capacityInputGroup.style.display = 'none';
                capacityInput.removeAttribute('required');
                capacityInput.value = '';
            } else {
                capacityInputGroup.style.display = 'block';
                capacityInput.setAttribute('required', 'required');
            }
        });

        // 참가비 체크박스 토글
        const hasFeeCheckbox = document.getElementById('has_fee');
        const feeAmountGroup = document.getElementById('fee_amount_group');

        hasFeeCheckbox.addEventListener('change', function() {
            if (this.checked) {
                feeAmountGroup.style.display = 'block';
            } else {
                feeAmountGroup.style.display = 'none';
            }
        });

        // 가운 사이즈 수집 토글
        const gownCheckbox = document.getElementById('requires_gown_size');
        const gownCapacityGroup = document.getElementById('gown_capacity_group');
        const gownInputs = [
            document.getElementById('gown_capacity_s'),
            document.getElementById('gown_capacity_m'),
            document.getElementById('gown_capacity_l')
        ];

        function toggleGownCapacity() {
            const enabled = gownCheckbox.checked;
            gownCapacityGroup.style.display = enabled ? 'grid' : 'none';
            gownInputs.forEach(input => {
                if (!input) return;
                input.disabled = !enabled;
                if (!enabled) {
                    input.value = '';
                }
            });
        }

        gownCheckbox.addEventListener('change', toggleGownCapacity);
        toggleGownCapacity();

        // 대표 이미지 업로드 영역 클릭
        document.getElementById('main_image_upload_area').addEventListener('click', function() {
            document.getElementById('main_image').click();
        });

        // 추가 이미지 업로드 영역 클릭
        document.getElementById('additional_images_upload_area').addEventListener('click', function() {
            document.getElementById('additional_images').click();
        });

        // 대표 이미지 미리보기
        document.getElementById('main_image').addEventListener('change', function(e) {
            const previewContainer = document.getElementById('main_image_preview');
            previewContainer.innerHTML = '';

            if (e.target.files.length > 0) {
                const file = e.target.files[0];
                const reader = new FileReader();
                reader.onload = function(event) {
                    const div = document.createElement('div');
                    div.className = 'preview-item';
                    div.innerHTML = `<img src="${event.target.result}" class="preview-image" alt="Preview" />`;
                    previewContainer.appendChild(div);
                };
                reader.readAsDataURL(file);
            }
        });

        // 추가 이미지 미리보기
        document.getElementById('additional_images').addEventListener('change', function(e) {
            const previewContainer = document.getElementById('additional_images_preview');
            previewContainer.innerHTML = '';

            Array.from(e.target.files).slice(0, 5).forEach(file => {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const div = document.createElement('div');
                    div.className = 'preview-item';
                    div.innerHTML = `<img src="${event.target.result}" class="preview-image" alt="Preview" />`;
                    previewContainer.appendChild(div);
                };
                reader.readAsDataURL(file);
            });
        });
    </script>
</body>
</html>
