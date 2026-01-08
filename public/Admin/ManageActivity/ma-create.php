<?php
// 관리자 전용 설정 파일 로드 (세션 검증 포함)
require_once '../../../config/config_admin.php';

// 성공/에러 메시지 처리
$success_message = $_SESSION['mp_create_success'] ?? '';
$error_message = $_SESSION['mp_create_error'] ?? '';
$error_details = $_SESSION['mp_create_error_details'] ?? null;
$debug_info = $_SESSION['mp_create_debug_info'] ?? null;
unset($_SESSION['mp_create_success'], $_SESSION['mp_create_error'], $_SESSION['mp_create_error_details'], $_SESSION['mp_create_debug_info']);
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>프로그램 생성 | <?= htmlspecialchars($PAGE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
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
        }

        /* Main Content */
        .main-content {
            flex: 1;
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
            padding: 32px;
        }

        .form-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 24px;
            font-size: 0.9rem;
        }

        .alert-success {
            background: #dcfce7;
            color: #16a34a;
            border: 1px solid #86efac;
        }

        .alert-error {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fca5a5;
        }

        .form-section {
            margin-bottom: 32px;
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--admin-primary);
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--border-color);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        label {
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 0.9rem;
            color: var(--text-main);
        }

        label .required {
            color: #dc2626;
        }

        label .optional {
            color: var(--text-muted);
            font-weight: 400;
            font-size: 0.85rem;
        }

        input[type="text"],
        input[type="date"],
        input[type="time"],
        input[type="datetime-local"],
        input[type="number"],
        textarea,
        select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: border-color 0.2s ease;
        }

        input:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: var(--admin-primary);
        }
        #gown_capacity_group input[disabled] {
            background: #f1f5f9;
        }

        textarea {
            min-height: 120px;
            resize: vertical;
            font-family: inherit;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
        }

        input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .file-upload-area {
            border: 2px dashed var(--border-color);
            border-radius: 8px;
            padding: 24px;
            text-align: center;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .file-upload-area:hover {
            border-color: var(--admin-primary);
            background: #f8fafc;
        }

        .file-upload-area.drag-over {
            border-color: var(--admin-accent);
            background: #eff6ff;
        }

        .upload-icon {
            font-size: 2.5rem;
            margin-bottom: 8px;
        }

        .upload-text {
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        input[type="file"] {
            display: none;
        }

        .preview-container {
            margin-top: 16px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 12px;
        }

        .preview-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            border: 2px solid var(--border-color);
        }

        .preview-image {
            width: 100%;
            height: 120px;
            object-fit: cover;
        }

        .preview-remove {
            position: absolute;
            top: 4px;
            right: 4px;
            background: rgba(220, 38, 38, 0.9);
            color: #ffffff;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            cursor: pointer;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .help-text {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid var(--border-color);
        }

        .btn {
            padding: 12px 32px;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-block;
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
                padding: 24px 16px;
            }

            .form-row {
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
                <a href="/Admin/ManageActivity/ma-index.php" class="back-btn">← 활동 목록</a>
                <div class="header-title">
                    <h1>새 문화체험 프로그램 생성</h1>
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

            <form action="/Admin/ManageActivity/handler/ma-create-handler.php" method="POST" enctype="multipart/form-data" class="form-card">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8') ?>">
                <!-- 프로그램 기본 정보 -->
                <div class="form-section">
                    <h2 class="section-title">프로그램 기본 정보</h2>

                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="program_name">
                                프로그램명 (영문) <span class="required">*</span>
                            </label>
                            <input type="text" id="program_name" name="program_name" required
                                   placeholder="e.g., Traditional Korean Tea Ceremony Experience" />
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="program_description">
                                프로그램 설명 (영문) <span class="required">*</span>
                            </label>
                            <textarea id="program_description" name="program_description" required
                                      placeholder="Describe the cultural activity in detail..."></textarea>
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
                            <input type="date" id="activity_date" name="activity_date" required />
                        </div>

                        <div class="form-group">
                            <label for="activity_time">
                                활동 시간 <span class="required">*</span>
                            </label>
                            <div class="checkbox-group" style="margin-bottom: 8px;">
                                <input type="checkbox" id="time_tbd" name="time_tbd" />
                                <label for="time_tbd" style="margin: 0;">시간 미정 (TBD)</label>
                            </div>
                            <input type="time" id="activity_time" name="activity_time" required />
                            <span class="help-text" id="time_tbd_note" style="display: none; color: var(--warning-orange);">시간 미정으로 표시됩니다.</span>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="location">
                                활동 장소 <span class="required">*</span>
                            </label>
                            <input type="text" id="location" name="location" required
                                   placeholder="e.g., Cultural Hall, 3rd Floor, Main Building" />
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="requires_gown_size" name="requires_gown_size" />
                                <label for="requires_gown_size" style="margin: 0;">졸업가운 사이즈 수집</label>
                            </div>
                            <span class="help-text">졸업식 등 가운 착용 활동이면 체크하여 S/M/L 선택을 받습니다.</span>
                        </div>
                    </div>
                    <div class="form-row" id="gown_capacity_group" style="display: none;">
                        <div class="form-group">
                            <label for="gown_capacity_s">가운 수량 - S</label>
                            <input type="number" id="gown_capacity_s" name="gown_capacity_s" min="0" placeholder="예: 20" />
                        </div>
                        <div class="form-group">
                            <label for="gown_capacity_m">가운 수량 - M</label>
                            <input type="number" id="gown_capacity_m" name="gown_capacity_m" min="0" placeholder="예: 40" />
                        </div>
                        <div class="form-group">
                            <label for="gown_capacity_l">가운 수량 - L</label>
                            <input type="number" id="gown_capacity_l" name="gown_capacity_l" min="0" placeholder="예: 30" />
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
                                <input type="checkbox" id="unlimited_capacity" name="unlimited_capacity" />
                                <label for="unlimited_capacity" style="margin: 0;">무제한 정원</label>
                            </div>
                            <span class="help-text">체크 시 정원 제한 없이 누구나 신청 가능합니다.</span>
                        </div>

                        <div class="form-group" id="capacity_input_group">
                            <label for="capacity">
                                최대 정원 <span class="required">*</span>
                            </label>
                            <input type="number" id="capacity" name="capacity" min="1" placeholder="30" />
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
                                <input type="checkbox" id="has_fee" name="has_fee" />
                                <label for="has_fee" style="margin: 0;">참가비 있음</label>
                            </div>
                            <span class="help-text">참가비가 있는 경우 체크하세요.</span>
                        </div>

                        <div class="form-group" id="fee_amount_group" style="display: none;">
                            <label for="fee_amount">
                                참가비 금액 (₩)
                            </label>
                            <input type="number" id="fee_amount" name="fee_amount" min="0" step="1000" placeholder="10000" />
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
                            <input type="datetime-local" id="registration_start_date" name="registration_start_date" required />
                        </div>

                        <div class="form-group">
                            <label for="registration_end_date">
                                신청 마감일시 <span class="required">*</span>
                            </label>
                            <input type="datetime-local" id="registration_end_date" name="registration_end_date" required />
                        </div>

                        <div class="form-group">
                            <label for="cancellation_deadline">
                                취소 가능 기한
                            </label>
                            <input type="datetime-local" id="cancellation_deadline" name="cancellation_deadline" />
                            <span class="help-text">학생이 신청을 취소할 수 있는 마감 시간입니다. 미설정시 활동 시작 전까지 취소 가능합니다.</span>
                        </div>
                    </div>
                </div>

                <!-- 이미지 업로드 -->
                <div class="form-section">
                    <h2 class="section-title">프로그램 이미지</h2>

                    <div class="form-group">
                        <label for="main_image">
                            대표 이미지 <span class="required">*</span>
                        </label>
                        <div class="file-upload-area" id="main_image_upload_area">
                            <div class="upload-icon">📷</div>
                            <div class="upload-text">
                                클릭하거나 이미지를 드래그하여 업로드
                            </div>
                        </div>
                        <input type="file" id="main_image" name="main_image" accept="image/*" required />
                        <div id="main_image_preview" class="preview-container"></div>
                        <span class="help-text">프로그램 목록에 표시될 대표 이미지입니다. (권장: 1200x800px)</span>
                    </div>

                    <div class="form-group" style="margin-top: 24px;">
                        <label for="additional_images">
                            추가 이미지 <span class="optional">(선택사항)</span>
                        </label>
                        <div class="file-upload-area" id="additional_images_upload_area">
                            <div class="upload-icon">🖼️</div>
                            <div class="upload-text">
                                여러 이미지를 선택하여 업로드 (최대 5개)
                            </div>
                        </div>
                        <input type="file" id="additional_images" name="additional_images[]" accept="image/*" multiple />
                        <div id="additional_images_preview" class="preview-container"></div>
                        <span class="help-text">프로그램 상세 페이지에 표시될 추가 이미지입니다.</span>
                    </div>
                </div>

                <!-- 제출 버튼 -->
                <div class="form-actions">
                    <a href="/Admin/ManageActivity/ma-index.php" class="btn btn-secondary">취소</a>
                    <button type="submit" class="btn btn-primary">프로그램 생성</button>
                </div>
            </form>
        </main>

        <!-- Footer -->
        <footer class="footer">
            © DATANEST, KOREA UNIVERSITY – Int'l Summer &amp; Winter Campus
        </footer>
    </div>

    <script>
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
        const feeAmountInput = document.getElementById('fee_amount');

        hasFeeCheckbox.addEventListener('change', function() {
            if (this.checked) {
                feeAmountGroup.style.display = 'block';
                feeAmountInput.setAttribute('required', 'required');
            } else {
                feeAmountGroup.style.display = 'none';
                feeAmountInput.removeAttribute('required');
                feeAmountInput.value = '';
            }
        });

        // 파일 업로드 핸들러 (대표 이미지)
        const mainImageInput = document.getElementById('main_image');
        const mainImageUploadArea = document.getElementById('main_image_upload_area');
        const mainImagePreview = document.getElementById('main_image_preview');

        mainImageUploadArea.addEventListener('click', () => mainImageInput.click());

        mainImageInput.addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                displayImagePreview(file, mainImagePreview, true);
            }
        });

        // 드래그 앤 드롭
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            mainImageUploadArea.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            mainImageUploadArea.addEventListener(eventName, () => {
                mainImageUploadArea.classList.add('drag-over');
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            mainImageUploadArea.addEventListener(eventName, () => {
                mainImageUploadArea.classList.remove('drag-over');
            });
        });

        mainImageUploadArea.addEventListener('drop', function(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            if (files.length > 0) {
                mainImageInput.files = files;
                displayImagePreview(files[0], mainImagePreview, true);
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

        // 파일 업로드 핸들러 (추가 이미지)
        const additionalImagesInput = document.getElementById('additional_images');
        const additionalImagesUploadArea = document.getElementById('additional_images_upload_area');
        const additionalImagesPreview = document.getElementById('additional_images_preview');

        additionalImagesUploadArea.addEventListener('click', () => additionalImagesInput.click());

        additionalImagesInput.addEventListener('change', function() {
            additionalImagesPreview.innerHTML = '';
            Array.from(this.files).slice(0, 5).forEach((file, index) => {
                displayImagePreview(file, additionalImagesPreview, false, index);
            });
        });

        // 이미지 미리보기 표시 함수
        function displayImagePreview(file, container, isSingle, index = 0) {
            const reader = new FileReader();
            reader.onload = function(e) {
                if (isSingle) {
                    container.innerHTML = '';
                }

                const previewItem = document.createElement('div');
                previewItem.className = 'preview-item';

                const img = document.createElement('img');
                img.src = e.target.result;
                img.className = 'preview-image';

                const removeBtn = document.createElement('button');
                removeBtn.className = 'preview-remove';
                removeBtn.innerHTML = '×';
                removeBtn.type = 'button';
                removeBtn.onclick = function() {
                    if (isSingle) {
                        container.innerHTML = '';
                        mainImageInput.value = '';
                    } else {
                        // 추가 이미지 제거 로직은 복잡하므로 전체 초기화
                        container.innerHTML = '';
                        additionalImagesInput.value = '';
                    }
                };

                previewItem.appendChild(img);
                previewItem.appendChild(removeBtn);
                container.appendChild(previewItem);
            };
            reader.readAsDataURL(file);
        }

        // 폼 제출 전 유효성 검사
        document.querySelector('form').addEventListener('submit', function(e) {
            const startDate = new Date(document.getElementById('registration_start_date').value);
            const endDate = new Date(document.getElementById('registration_end_date').value);
            const activityDate = new Date(document.getElementById('activity_date').value);

            if (endDate <= startDate) {
                e.preventDefault();
                alert('신청 마감일시는 신청 시작일시보다 이후여야 합니다.');
                return false;
            }

            if (activityDate <= endDate) {
                if (!confirm('활동 날짜가 신청 마감일 이전입니다. 계속하시겠습니까?')) {
                    e.preventDefault();
                    return false;
                }
            }
        });
    </script>
</body>
</html>
