<?php
/**
 * 수강료 납부 표시 처리
 * 관리자가 학생의 수강료 납부 상태를 업데이트
 */

require_once '../../../config/config_admin.php';

$admin_id = $_SESSION['admin_id'] ?? null;
$admin_name = $_SESSION['admin_name'] ?? null;

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

try {
    $enrollment_id = isset($_POST['enrollment_id']) ? (int)$_POST['enrollment_id'] : 0;

    if ($enrollment_id <= 0) {
        throw new Exception('Invalid enrollment ID.');
    }

    // 신청 정보 확인
    $enrollment_stmt = $pdo->prepare("
        SELECT e.*, ca.program_name, ca.has_fee
        FROM cultural_activity_enrollments e
        INNER JOIN cultural_activities ca ON e.activity_id = ca.id
        WHERE e.id = :enrollment_id
        LIMIT 1
    ");
    $enrollment_stmt->execute(['enrollment_id' => $enrollment_id]);
    $enrollment = $enrollment_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$enrollment) {
        throw new Exception('Enrollment not found.');
    }

    if (!$enrollment['has_fee']) {
        throw new Exception('This activity does not have a fee.');
    }

    if ($enrollment['fee_paid']) {
        throw new Exception('Fee has already been marked as paid.');
    }

    if ($enrollment['status'] !== 'approved') {
        throw new Exception('Only approved enrollments can be marked as paid.');
    }

    // 수강료 납부 표시
    $update_stmt = $pdo->prepare("
        UPDATE cultural_activity_enrollments
        SET fee_paid = 1
        WHERE id = :enrollment_id
    ");
    $update_stmt->execute(['enrollment_id' => $enrollment_id]);

    // 관리자 로그 기록
    $log_stmt = $pdo->prepare("
        INSERT INTO cultural_activity_admin_logs (admin_id, activity_id, action, details, ip_address)
        VALUES (:admin_id, :activity_id, 'mark_fee_paid', :details, :ip_address)
    ");

    // IP 주소 추출 (Cloudflare 우선)
    $client_ip = null;
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $client_ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $client_ip = $_SERVER['HTTP_X_REAL_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'];
        $client_ip = is_string($forwarded) && strpos($forwarded, ',') !== false
            ? trim(explode(',', $forwarded)[0])
            : $forwarded;
    } else {
        $client_ip = $_SERVER['REMOTE_ADDR'] ?? null;
    }

    $log_details = json_encode([
        'enrollment_id' => $enrollment_id,
        'student_id' => $enrollment['student_id'],
        'student_name' => $enrollment['student_name'],
        'program_name' => $enrollment['program_name'],
        'admin_name' => $admin_name
    ], JSON_UNESCAPED_UNICODE);

    $log_stmt->execute([
        'admin_id' => $admin_id,
        'activity_id' => $enrollment['activity_id'],
        'details' => $log_details,
        'ip_address' => get_client_ip()
    ]);

    $_SESSION['me_success'] = 'Fee has been marked as paid for ' . htmlspecialchars($enrollment['student_name'], ENT_QUOTES, 'UTF-8') . '.';
    header('Location: /Admin/ManageEnrollment/me-detail.php?id=' . $enrollment['activity_id']);
    exit();

} catch (Exception $e) {
    error_log('Mark paid error: ' . $e->getMessage());
    $_SESSION['me_error'] = $e->getMessage();

    // 활동 ID가 있으면 detail 페이지로, 없으면 index로
    if (isset($enrollment['activity_id'])) {
        header('Location: /Admin/ManageEnrollment/me-detail.php?id=' . $enrollment['activity_id']);
    } else {
        header('Location: /Admin/ManageEnrollment/me-index.php');
    }
    exit();
}
