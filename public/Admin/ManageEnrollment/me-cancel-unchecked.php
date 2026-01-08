<?php
/**
 * 지난 활동의 미체크인 승인 신청을 일괄 취소
 */
require_once '../../../config/config_admin.php';

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /Admin/ManageEnrollment/me-index.php');
    exit();
}

// CSRF 토큰 검증
$csrf_token = $_POST['csrf_token'] ?? '';
if (!csrf_validate_token($csrf_token)) {
    $_SESSION['me_error'] = '보안 토큰이 유효하지 않습니다. 다시 시도해주세요.';
    header('Location: /Admin/ManageEnrollment/me-index.php');
    exit();
}

$activity_id = isset($_POST['activity_id']) ? (int)$_POST['activity_id'] : 0;
$reason = 'Auto-cancelled (past event, not checked-in)';

try {
    if ($activity_id <= 0) {
        throw new Exception('Invalid activity ID.');
    }

    $pdo->beginTransaction();

    // 활동 날짜 확인 (지난 이벤트만 허용)
    $activity_stmt = $pdo->prepare("
        SELECT id, program_name, activity_date
        FROM cultural_activities
        WHERE id = :id AND is_deleted = 0
        LIMIT 1
    ");
    $activity_stmt->execute(['id' => $activity_id]);
    $activity = $activity_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$activity) {
        throw new Exception('Program not found.');
    }

    $seoulTz = new DateTimeZone('Asia/Seoul');
    $now = new DateTime('now', $seoulTz);
    $activity_date = new DateTime($activity['activity_date'], $seoulTz);
    if ($activity_date >= $now) {
        throw new Exception('Only past events can be bulk-cancelled.');
    }

    // 대상 신청 조회 (승인 + 미체크인)
    $target_stmt = $pdo->prepare("
        SELECT id, student_id, student_name
        FROM cultural_activity_enrollments
        WHERE activity_id = :activity_id
          AND status = 'approved'
          AND (checked_in IS NULL OR checked_in = 0)
    ");
    $target_stmt->execute(['activity_id' => $activity_id]);
    $targets = $target_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($targets)) {
        $_SESSION['me_success'] = '취소할 미체크인 승인 신청이 없습니다.';
        $pdo->commit();
        header('Location: /Admin/ManageEnrollment/me-detail.php?id=' . $activity_id);
        exit();
    }

    // 일괄 취소
    $cancel_stmt = $pdo->prepare("
        UPDATE cultural_activity_enrollments
        SET status = 'cancelled',
            cancelled_by = 'admin',
            admin_reason = :admin_reason
        WHERE id = :id
    ");
    $history_stmt = $pdo->prepare("
        INSERT INTO cultural_activity_enrollment_history
        (enrollment_id, student_id, student_name, activity_id, action, action_details, ip_address)
        VALUES (:enrollment_id, :student_id, :student_name, :activity_id, 'cancelled', :action_details, :ip_address)
    ");

    $count = 0;
    $action_details = json_encode([
        'cancelled_by' => 'admin',
        'reason' => $reason,
        'bulk' => true
    ], JSON_UNESCAPED_UNICODE);
    $ip_address = get_client_ip();

    foreach ($targets as $row) {
        $cancel_stmt->execute([
            'id' => $row['id'],
            'admin_reason' => $reason
        ]);

        $history_stmt->execute([
            'enrollment_id' => $row['id'],
            'student_id' => $row['student_id'],
            'student_name' => $row['student_name'],
            'activity_id' => $activity_id,
            'action_details' => $action_details,
            'ip_address' => $ip_address
        ]);
        $count++;
    }

    // current_enrollment 감소
    $update_count_stmt = $pdo->prepare("
        UPDATE cultural_activities
        SET current_enrollment = GREATEST(0, current_enrollment - :cnt)
        WHERE id = :activity_id
    ");
    $update_count_stmt->execute([
        'cnt' => $count,
        'activity_id' => $activity_id
    ]);

    // 관리자 로그
    $log_stmt = $pdo->prepare("
        INSERT INTO cultural_activity_admin_logs (admin_id, activity_id, action, details, ip_address)
        VALUES (:admin_id, :activity_id, 'bulk_cancel_unchecked', :details, :ip_address)
    ");
    $log_stmt->execute([
        'admin_id' => $_SESSION['admin_id'] ?? 'unknown',
        'activity_id' => $activity_id,
        'details' => json_encode([
            'cancelled_count' => $count,
            'reason' => $reason
        ], JSON_UNESCAPED_UNICODE),
        'ip_address' => $ip_address
    ]);

    $pdo->commit();

    $_SESSION['me_success'] = '미체크인 승인 신청 ' . $count . '건을 취소했습니다.';
    header('Location: /Admin/ManageEnrollment/me-detail.php?id=' . $activity_id);
    exit();

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Bulk cancel unchecked error: ' . $e->getMessage());
    $_SESSION['me_error'] = $e->getMessage();
    if ($activity_id > 0) {
        header('Location: /Admin/ManageEnrollment/me-detail.php?id=' . $activity_id);
    } else {
        header('Location: /Admin/ManageEnrollment/me-index.php');
    }
    exit();
}
