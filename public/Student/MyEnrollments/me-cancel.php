<?php
/**
 * 문화체험 신청 취소 핸들러
 * 학생이 자신의 신청을 취소할 수 있도록 처리
 */

// 학생 전용 설정 파일 로드 (세션 검증 포함)
require_once '../../../config/config_student.php';

$student_id = $_SESSION['student_id'] ?? null;

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /Student/MyEnrollments/me-index.php');
    exit();
}

// CSRF 토큰 검증
$csrf_token = $_POST['csrf_token'] ?? '';
if (!csrf_validate_token($csrf_token)) {
    $_SESSION['me_error'] = 'Invalid security token. Please try again.';
    header('Location: /Student/MyEnrollments/me-index.php');
    exit();
}

try {
    // 1. enrollment_id 확인
    $enrollment_id = isset($_POST['enrollment_id']) ? (int)$_POST['enrollment_id'] : 0;

    if ($enrollment_id <= 0) {
        throw new Exception('Invalid enrollment ID.');
    }

    // 2. 신청 정보 확인 (본인 것인지, 취소 가능한지)
    $check_stmt = $pdo->prepare("
        SELECT
            e.id,
            e.activity_id,
            e.student_id,
            e.student_name,
            e.status,
            e.checked_in,
            e.check_in_time,
            e.gown_rented_at,
            e.gown_returned_at,
            ca.program_name,
            ca.activity_date,
            ca.activity_time,
            ca.cancellation_deadline,
            ca.requires_gown_size,
            ca.is_active,
            ca.is_deleted
        FROM cultural_activity_enrollments e
        INNER JOIN cultural_activities ca ON e.activity_id = ca.id
        WHERE e.id = :enrollment_id
        LIMIT 1
    ");
    $check_stmt->execute(['enrollment_id' => $enrollment_id]);
    $enrollment = $check_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$enrollment) {
        throw new Exception('Enrollment not found.');
    }

    // 본인의 신청인지 확인
    if ($enrollment['student_id'] !== $student_id) {
        throw new Exception('You do not have permission to cancel this enrollment.');
    }

    // 이미 취소된 신청인지 확인
    if ($enrollment['status'] === 'cancelled') {
        throw new Exception('This enrollment has already been cancelled.');
    }

    // 승인된 신청만 취소 가능
    if ($enrollment['status'] !== 'approved') {
        throw new Exception('Only approved enrollments can be cancelled.');
    }

    // 이미 체크인한 경우 취소 불가
    $requires_gown = !empty($enrollment['requires_gown_size']);
    $has_rented_gown = !empty($enrollment['gown_rented_at']);
    if ($requires_gown && $has_rented_gown) {
        throw new Exception('Cannot cancel enrollment after gown rental. Please contact the ISC/IWC office if you need assistance.');
    }
    if (!$requires_gown && !empty($enrollment['checked_in']) && (int)$enrollment['checked_in'] === 1) {
        throw new Exception('Cannot cancel enrollment after check-in. Please contact the ISC/IWC office if you need assistance.');
    }

    // 취소 기한 확인
    $seoulTz = new DateTimeZone('Asia/Seoul');
    $now = new DateTime('now', $seoulTz);

    if (!empty($enrollment['cancellation_deadline'])) {
        $deadline = new DateTime($enrollment['cancellation_deadline'], $seoulTz);
        if ($now > $deadline) {
            throw new Exception('The cancellation deadline has passed. Please contact the ISC/IWC office if you need assistance.');
        }
    }

    // 활동 날짜가 지났는지 확인
    $activity_date = new DateTime($enrollment['activity_date'], $seoulTz);
    if ($activity_date < $now) {
        throw new Exception('Cannot cancel enrollment for past events.');
    }

    // 3. 트랜잭션 시작
    $pdo->beginTransaction();

    // 신청 상태를 'cancelled'로 업데이트 (updated_at은 자동 업데이트됨)
    $cancel_stmt = $pdo->prepare("
        UPDATE cultural_activity_enrollments
        SET status = 'cancelled',
            cancelled_by = 'student'
        WHERE id = :enrollment_id
    ");
    $cancel_stmt->execute(['enrollment_id' => $enrollment_id]);

    // 취소 이력 기록 - IP 주소 추출 (Cloudflare 우선)
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

    $history_stmt = $pdo->prepare("
        INSERT INTO cultural_activity_enrollment_history
        (enrollment_id, student_id, student_name, activity_id, action, ip_address)
        VALUES (:enrollment_id, :student_id, :student_name, :activity_id, 'cancelled', :ip_address)
    ");
    $history_stmt->execute([
        'enrollment_id' => $enrollment_id,
        'student_id' => $student_id,
        'student_name' => $enrollment['student_name'] ?? $student_id,
        'activity_id' => $enrollment['activity_id'],
        'ip_address' => $client_ip
    ]);

    // 활동의 current_enrollment 감소
    $update_count_stmt = $pdo->prepare("
        UPDATE cultural_activities
        SET current_enrollment = GREATEST(0, current_enrollment - 1)
        WHERE id = :activity_id
    ");
    $update_count_stmt->execute(['activity_id' => $enrollment['activity_id']]);

    $pdo->commit();

    // 성공 메시지
    $_SESSION['me_success'] = 'Your enrollment has been cancelled successfully. Your spot is now available for other students.';
    header('Location: /Student/MyEnrollments/me-index.php');
    exit();

} catch (Exception $e) {
    // 트랜잭션 롤백
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Enrollment cancellation error: ' . $e->getMessage());

    // 에러 메시지
    $_SESSION['me_error'] = $e->getMessage();
    header('Location: /Student/MyEnrollments/me-index.php');
    exit();
}
