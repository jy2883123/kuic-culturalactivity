<?php
/**
 * 문화체험 프로그램 수정 핸들러
 * 프로그램 정보 업데이트, 이미지 업데이트 처리
 */

// 관리자 전용 설정 파일 로드 (세션 검증 포함)
require_once '../../../../config/config_admin.php';

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /Admin/ManageActivity/ma-index.php');
    exit();
}

// CSRF 토큰 검증
$csrf_token = $_POST['csrf_token'] ?? '';
if (!csrf_validate_token($csrf_token)) {
    $_SESSION['error_message'] = '보안 토큰이 유효하지 않습니다. 다시 시도해주세요.';
    header('Location: /Admin/ManageActivity/ma-index.php');
    exit();
}

try {
    // 1. 프로그램 ID 확인
    $program_id = isset($_POST['program_id']) ? (int)$_POST['program_id'] : 0;

    if ($program_id <= 0) {
        throw new Exception('유효하지 않은 프로그램 ID입니다.');
    }

    // 프로그램 존재 확인
    $check_stmt = $pdo->prepare("SELECT id FROM cultural_activities WHERE id = :id AND is_deleted = FALSE LIMIT 1");
    $check_stmt->execute(['id' => $program_id]);
    if (!$check_stmt->fetch()) {
        throw new Exception('프로그램을 찾을 수 없습니다.');
    }

    // 2. 입력값 검증
    $program_name = trim($_POST['program_name'] ?? '');
    $program_description = trim($_POST['program_description'] ?? '');
    $activity_date = $_POST['activity_date'] ?? '';
    $time_tbd = isset($_POST['time_tbd']);
    $activity_time = $time_tbd ? null : ($_POST['activity_time'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $unlimited_capacity = isset($_POST['unlimited_capacity']);
    $capacity = $unlimited_capacity ? null : (int)($_POST['capacity'] ?? 0);
    $has_fee = isset($_POST['has_fee']);
    $fee_amount = $has_fee ? (float)($_POST['fee_amount'] ?? 0) : null;
    $registration_start_date = $_POST['registration_start_date'] ?? '';
    $registration_end_date = $_POST['registration_end_date'] ?? '';
    $cancellation_deadline = !empty($_POST['cancellation_deadline']) ? $_POST['cancellation_deadline'] : null;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $requires_gown_size = isset($_POST['requires_gown_size']);
    $gown_capacity_s = isset($_POST['gown_capacity_s']) && $_POST['gown_capacity_s'] !== '' ? (int)$_POST['gown_capacity_s'] : null;
    $gown_capacity_m = isset($_POST['gown_capacity_m']) && $_POST['gown_capacity_m'] !== '' ? (int)$_POST['gown_capacity_m'] : null;
    $gown_capacity_l = isset($_POST['gown_capacity_l']) && $_POST['gown_capacity_l'] !== '' ? (int)$_POST['gown_capacity_l'] : null;

    // 필수 입력값 확인
    if (empty($program_name) || empty($program_description) || empty($activity_date) ||
        empty($location) || empty($registration_start_date) || empty($registration_end_date)) {
        throw new Exception('모든 필수 항목을 입력해주세요.');
    }

    // 시간 미정이 아닌 경우 시간 필수
    if (!$time_tbd && empty($activity_time)) {
        throw new Exception('활동 시간을 입력하거나 "시간 미정"을 선택해주세요.');
    }

    // 정원 검증
    if (!$unlimited_capacity && $capacity < 1) {
        throw new Exception('정원은 1명 이상이어야 합니다.');
    }

    // 참가비 검증
    if ($has_fee && $fee_amount < 0) {
        throw new Exception('참가비는 0원 이상이어야 합니다.');
    }

    // 3. 이미지 업로드 처리
    $upload_base_dir = ROOT_PATH . 'public/uploads/programs/';
    $web_upload_base = '/uploads/programs/';

    // 업로드 디렉토리 확인
    if (!is_dir($upload_base_dir)) {
        if (!mkdir($upload_base_dir, 0755, true)) {
            throw new Exception('업로드 디렉토리 생성 실패: ' . $upload_base_dir);
        }
    }

    $pdo->beginTransaction();

    // 대표 이미지가 업로드된 경우에만 처리
    $main_image_path = null;
    if (!empty($_FILES['main_image']['name'])) {
        // 기존 대표 이미지 삭제
        $old_image_stmt = $pdo->prepare("SELECT main_image_path FROM cultural_activities WHERE id = :id");
        $old_image_stmt->execute(['id' => $program_id]);
        $old_image = $old_image_stmt->fetchColumn();

        if ($old_image && file_exists($upload_base_dir . basename($old_image))) {
            unlink($upload_base_dir . basename($old_image));
        }

        // 새 이미지 업로드
        $main_image_path = uploadImage($_FILES['main_image'], $upload_base_dir, $web_upload_base);
    }

    // 4. DB 업데이트
    $update_fields = [
        'program_name' => $program_name,
        'program_description' => $program_description,
        'activity_date' => $activity_date,
        'activity_time' => $activity_time,
        'location' => $location,
        'requires_gown_size' => $requires_gown_size ? 1 : 0,
        'gown_capacity_s' => $requires_gown_size ? $gown_capacity_s : null,
        'gown_capacity_m' => $requires_gown_size ? $gown_capacity_m : null,
        'gown_capacity_l' => $requires_gown_size ? $gown_capacity_l : null,
        'capacity' => $capacity,
        'has_fee' => $has_fee ? 1 : 0,
        'fee_amount' => $fee_amount,
        'registration_start_date' => $registration_start_date,
        'registration_end_date' => $registration_end_date,
        'cancellation_deadline' => $cancellation_deadline,
        'is_active' => $is_active,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    // 대표 이미지가 업로드된 경우에만 추가
    if ($main_image_path) {
        $update_fields['main_image_path'] = $main_image_path;
    }

    $sql = "UPDATE cultural_activities SET ";
    $sql_parts = [];
    foreach ($update_fields as $key => $value) {
        $sql_parts[] = "$key = :$key";
    }
    $sql .= implode(', ', $sql_parts);
    $sql .= " WHERE id = :id";

    $stmt = $pdo->prepare($sql);
    $update_fields['id'] = $program_id;
    $stmt->execute($update_fields);

    // 5. 추가 이미지 삭제 처리
    if (!empty($_POST['images_to_delete'])) {
        $images_to_delete = explode(',', $_POST['images_to_delete']);

        foreach ($images_to_delete as $image_id) {
            $image_id = (int)$image_id;
            if ($image_id <= 0) continue;

            // 이미지 경로 조회
            $img_path_stmt = $pdo->prepare("SELECT image_path FROM cultural_activity_images WHERE id = :id AND activity_id = :activity_id");
            $img_path_stmt->execute(['id' => $image_id, 'activity_id' => $program_id]);
            $img_path = $img_path_stmt->fetchColumn();

            if ($img_path && file_exists($upload_base_dir . basename($img_path))) {
                unlink($upload_base_dir . basename($img_path));
            }

            // DB에서 삭제
            $delete_img_stmt = $pdo->prepare("DELETE FROM cultural_activity_images WHERE id = :id AND activity_id = :activity_id");
            $delete_img_stmt->execute(['id' => $image_id, 'activity_id' => $program_id]);
        }
    }

    // 6. 새 추가 이미지 업로드
    if (!empty($_FILES['additional_images']['name'][0])) {
        $additional_images = $_FILES['additional_images'];
        $max_additional_images = 5;

        // 현재 추가 이미지 개수 확인
        $current_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM cultural_activity_images WHERE activity_id = :activity_id");
        $current_count_stmt->execute(['activity_id' => $program_id]);
        $current_count = $current_count_stmt->fetchColumn();

        $available_slots = $max_additional_images - $current_count;

        for ($i = 0; $i < min(count($additional_images['name']), $available_slots); $i++) {
            if (empty($additional_images['name'][$i])) {
                continue;
            }

            $file = [
                'name' => $additional_images['name'][$i],
                'type' => $additional_images['type'][$i],
                'tmp_name' => $additional_images['tmp_name'][$i],
                'error' => $additional_images['error'][$i],
                'size' => $additional_images['size'][$i]
            ];

            $additional_image_path = uploadImage($file, $upload_base_dir, $web_upload_base);

            // 최대 display_order 조회
            $max_order_stmt = $pdo->prepare("SELECT COALESCE(MAX(display_order), -1) + 1 FROM cultural_activity_images WHERE activity_id = :activity_id");
            $max_order_stmt->execute(['activity_id' => $program_id]);
            $display_order = $max_order_stmt->fetchColumn();

            // DB에 추가 이미지 저장
            $img_stmt = $pdo->prepare("
                INSERT INTO cultural_activity_images (activity_id, image_path, display_order)
                VALUES (:activity_id, :image_path, :display_order)
            ");
            $img_stmt->execute([
                'activity_id' => $program_id,
                'image_path' => $additional_image_path,
                'display_order' => $display_order
            ]);
        }
    }

    // 7. 관리자 로그 기록
    $admin_login_id = $_SESSION['admin_id'] ?? null;

    $log_stmt = $pdo->prepare("
        INSERT INTO cultural_activity_admin_logs (admin_id, activity_id, action, details, ip_address)
        VALUES (:admin_id, :activity_id, :action, :details, :ip_address)
    ");
    $log_stmt->execute([
        'admin_id' => $admin_login_id,
        'activity_id' => $program_id,
        'action' => 'update_program',
        'details' => "프로그램 수정: {$program_name}",
        'ip_address' => get_client_ip()
    ]);

    $pdo->commit();

    // 성공 메시지와 함께 리다이렉트
    $_SESSION['mp_edit_success'] = '프로그램이 성공적으로 수정되었습니다.';
    header('Location: /Admin/ManageActivity/ma-edit.php?id=' . $program_id);
    exit();

} catch (Exception $e) {
    // 트랜잭션 롤백
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // 업로드된 이미지 삭제 (실패 시 정리)
    if (isset($main_image_path) && isset($upload_base_dir) && file_exists($upload_base_dir . basename($main_image_path))) {
        unlink($upload_base_dir . basename($main_image_path));
    }

    // 상세 에러 정보 수집
    $error_details = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];

    error_log('Program edit error: ' . $e->getMessage());
    error_log('Error details: ' . json_encode($error_details));

    // 세션에 에러 정보 저장
    $_SESSION['mp_edit_error'] = $e->getMessage();
    $_SESSION['mp_edit_error_details'] = $error_details;

    // 프로그램 ID가 있으면 수정 페이지로, 없으면 목록으로
    if (isset($program_id) && $program_id > 0) {
        header('Location: /Admin/ManageActivity/ma-edit.php?id=' . $program_id);
    } else {
        header('Location: /Admin/ManageActivity/ma-index.php');
    }
    exit();
}

/**
 * 이미지 업로드 함수
 *
 * @param array $file $_FILES 배열의 단일 파일
 * @param string $upload_dir 업로드 디렉토리 (절대 경로)
 * @param string $web_path 웹 경로
 * @return string 업로드된 이미지의 웹 경로
 * @throws Exception 업로드 실패 시
 */
function uploadImage($file, $upload_dir, $web_path) {
    // 파일 에러 확인
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'php.ini의 upload_max_filesize 초과',
            UPLOAD_ERR_FORM_SIZE => 'HTML 폼의 MAX_FILE_SIZE 초과',
            UPLOAD_ERR_PARTIAL => '파일이 부분적으로만 업로드됨',
            UPLOAD_ERR_NO_FILE => '파일이 업로드되지 않음',
            UPLOAD_ERR_NO_TMP_DIR => '임시 디렉토리 없음',
            UPLOAD_ERR_CANT_WRITE => '디스크 쓰기 실패',
            UPLOAD_ERR_EXTENSION => 'PHP 확장에 의해 업로드 중단',
        ];
        $error_msg = $error_messages[$file['error']] ?? '알 수 없는 오류';
        throw new Exception('이미지 업로드 중 오류가 발생했습니다: ' . $error_msg . ' (코드: ' . $file['error'] . ')');
    }

    // 파일 크기 확인 (최대 5MB)
    $max_size = 5 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        throw new Exception('이미지 파일 크기는 5MB를 초과할 수 없습니다. (현재: ' . round($file['size'] / 1024 / 1024, 2) . 'MB)');
    }

    // tmp_name 확인
    if (!file_exists($file['tmp_name'])) {
        throw new Exception('임시 파일이 존재하지 않습니다: ' . $file['tmp_name']);
    }

    // 파일 타입 확인
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime_type, $allowed_types)) {
        throw new Exception('허용되지 않은 이미지 형식입니다. (JPEG, PNG, GIF, WebP만 가능) - 감지된 타입: ' . $mime_type);
    }

    // 파일명 생성 (충돌 방지를 위해 UUID + 타임스탬프 사용)
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('program_', true) . '_' . time() . '.' . $extension;

    $upload_path = $upload_dir . $filename;

    // 파일 이동
    if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
        $last_error = error_get_last();
        throw new Exception('이미지 저장에 실패했습니다. 경로: ' . $upload_path . ' / 마지막 오류: ' . ($last_error['message'] ?? 'N/A'));
    }

    // 파일 권한 설정
    chmod($upload_path, 0644);

    return $web_path . $filename;
}
