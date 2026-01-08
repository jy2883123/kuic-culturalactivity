<?php
/**
 * 학생 로그아웃 핸들러
 * 학생 세션을 파기하고 학생 로그인 페이지로 리다이렉트
 */

session_start();
require_once '../../config/config.php';

// 로그아웃 전 세션 정보 저장
$student_id = $_SESSION['student_id'] ?? 'unknown';
$student_name = $_SESSION['student_name'] ?? '';
$institution_role = $_SESSION['institution_role'] ?? '';

// 학생 로그아웃 로그 기록
if (!empty($_SESSION['logged_in'])) {
    try {
        $log_stmt = $pdo->prepare("
            INSERT INTO cultural_activity_student_logs (student_id, activity_id, action, details, ip_address)
            VALUES (:student_id, NULL, 'student_logout', :details, :ip_address)
        ");
        $log_stmt->execute([
            'student_id' => $student_id,
            'details' => json_encode([
                'name' => $student_name,
                'institution_role' => $institution_role
            ], JSON_UNESCAPED_UNICODE),
            'ip_address' => get_client_ip()
        ]);
    } catch (PDOException $e) {
        error_log('Student logout log error: ' . $e->getMessage());
    }
}

// 모든 학생 세션 변수 제거
unset($_SESSION['logged_in']);
unset($_SESSION['student_id']);
unset($_SESSION['student_name']);
unset($_SESSION['institution_role']);

// 세션 완전히 파기
session_destroy();

// 학생 로그인 페이지로 리다이렉트
header('Location: /index.php');
exit();
