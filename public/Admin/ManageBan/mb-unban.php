<?php
require_once '../../../config/config_admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /Admin/ManageBan/mb-index.php');
    exit();
}

// CSRF 토큰 검증
$csrf_token = $_POST['csrf_token'] ?? '';
if (!csrf_validate_token($csrf_token)) {
    $_SESSION['mb_error'] = '보안 토큰이 유효하지 않습니다. 다시 시도해주세요.';
    header('Location: /Admin/ManageBan/mb-index.php');
    exit();
}

$ban_id = isset($_POST['ban_id']) ? (int)$_POST['ban_id'] : 0;

if ($ban_id <= 0) {
    $_SESSION['mb_error'] = '잘못된 요청입니다.';
    header('Location: /Admin/ManageBan/mb-index.php');
    exit();
}

try {
    $pdo->beginTransaction();

    $ban_stmt = $pdo->prepare("
        SELECT id, student_id, ban_type, activity_id, is_active
        FROM cultural_activity_bans
        WHERE id = :id
        FOR UPDATE
    ");
    $ban_stmt->execute(['id' => $ban_id]);
    $ban = $ban_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ban) {
        throw new Exception('밴 정보를 찾을 수 없습니다.');
    }

    if ((int)$ban['is_active'] === 0) {
        throw new Exception('이미 해제된 상태입니다.');
    }

    $update_stmt = $pdo->prepare("
        UPDATE cultural_activity_bans
        SET is_active = 0
        WHERE id = :id
    ");
    $update_stmt->execute(['id' => $ban_id]);

    $admin_login_id = $_SESSION['admin_id'] ?? 'unknown';
    $log_stmt = $pdo->prepare("
        INSERT INTO cultural_activity_admin_logs (admin_id, activity_id, action, details, ip_address)
        VALUES (:admin_id, :activity_id, :action, :details, :ip_address)
    ");
    $log_details = json_encode([
        'ban_id' => $ban_id,
        'student_id' => $ban['student_id'],
        'ban_type' => $ban['ban_type']
    ], JSON_UNESCAPED_UNICODE);
    $log_stmt->execute([
        'admin_id' => $admin_login_id,
        'activity_id' => $ban['activity_id'],
        'action' => 'unban_student',
        'details' => $log_details,
        'ip_address' => get_client_ip()
    ]);

    $pdo->commit();
    $_SESSION['mb_success'] = '밴이 해제되었습니다.';
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['mb_error'] = $e->getMessage();
}

header('Location: /Admin/ManageBan/mb-index.php');
exit();
