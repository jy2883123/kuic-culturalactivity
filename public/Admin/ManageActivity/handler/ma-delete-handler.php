<?php
/**
 * 프로그램 삭제 핸들러
 * 관련된 모든 데이터와 업로드 파일을 영구 삭제
 */

require_once '../../../../config/config_admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /Admin/ManageActivity/ma-index.php');
    exit();
}

// CSRF 토큰 검증
$csrf_token = $_POST['csrf_token'] ?? '';
if (!csrf_validate_token($csrf_token)) {
    $_SESSION['ma_error'] = '보안 토큰이 유효하지 않습니다. 다시 시도해주세요.';
    header('Location: /Admin/ManageActivity/ma-index.php');
    exit();
}

$activity_id = isset($_POST['activity_id']) ? (int)$_POST['activity_id'] : 0;

if ($activity_id <= 0) {
    $_SESSION['ma_error'] = '유효하지 않은 프로그램 ID입니다.';
    header('Location: /Admin/ManageActivity/ma-index.php');
    exit();
}

try {
    $pdo->beginTransaction();

    $activity_stmt = $pdo->prepare("
        SELECT id, program_name, main_image_path
        FROM cultural_activities
        WHERE id = :id AND is_deleted = FALSE
        FOR UPDATE
    ");
    $activity_stmt->execute(['id' => $activity_id]);
    $activity = $activity_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        throw new Exception('프로그램을 찾을 수 없습니다.');
    }

    $image_stmt = $pdo->prepare("
        SELECT image_path
        FROM cultural_activity_images
        WHERE activity_id = :activity_id
    ");
    $image_stmt->execute(['activity_id' => $activity_id]);
    $additional_images = $image_stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $pdo->prepare("DELETE FROM cultural_activity_enrollment_history WHERE activity_id = :id")
        ->execute(['id' => $activity_id]);

    $pdo->prepare("DELETE FROM cultural_activity_enrollments WHERE activity_id = :id")
        ->execute(['id' => $activity_id]);

    $pdo->prepare("DELETE FROM cultural_activity_images WHERE activity_id = :id")
        ->execute(['id' => $activity_id]);

    $pdo->prepare("
        DELETE FROM cultural_activity_bans
        WHERE ban_type = 'specific' AND activity_id = :id
    ")->execute(['id' => $activity_id]);

    $pdo->prepare("DELETE FROM cultural_activities WHERE id = :id")
        ->execute(['id' => $activity_id]);

    $log_stmt = $pdo->prepare("
        INSERT INTO cultural_activity_admin_logs (admin_id, activity_id, action, details, ip_address)
        VALUES (:admin_id, :activity_id, :action, :details, :ip_address)
    ");
    $admin_login_id = $_SESSION['admin_id'] ?? 'unknown';
    $log_stmt->execute([
        'admin_id' => $admin_login_id,
        'activity_id' => $activity_id,
        'action' => 'delete_program',
        'details' => "프로그램 삭제: {$activity['program_name']}",
        'ip_address' => get_client_ip()
    ]);

    $pdo->commit();

    $all_images = array_filter(array_merge(
        [$activity['main_image_path']],
        $additional_images
    ));

    foreach ($all_images as $img_path) {
        $normalized = ltrim($img_path, '/');
        $full_path = ROOT_PATH . 'public/' . $normalized;
        if (is_file($full_path)) {
            @unlink($full_path);
        }
    }

    $_SESSION['ma_success'] = '프로그램 및 관련 데이터가 영구적으로 삭제되었습니다.';
    header('Location: /Admin/ManageActivity/ma-index.php');
    exit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Program delete error: ' . $e->getMessage());
    $_SESSION['ma_error'] = '삭제 처리 중 오류가 발생했습니다: ' . $e->getMessage();
    header('Location: /Admin/ManageActivity/ma-index.php');
    exit();
}
