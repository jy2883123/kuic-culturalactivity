<?php
/**
 * 관리자 로그아웃 핸들러
 * 관리자 세션을 파기하고 관리자 로그인 페이지로 리다이렉트
 */

session_start();
require_once '../../config/config.php';

// 로그아웃 전 세션 정보 저장
$admin_id = $_SESSION['admin_id'] ?? 'unknown';
$admin_name = $_SESSION['admin_name'] ?? '';
$admin_position = $_SESSION['admin_position'] ?? '';

// 관리자 로그아웃 로그 기록
if (!empty($_SESSION['admin_logged_in'])) {
    try {
        $log_stmt = $pdo->prepare("
            INSERT INTO cultural_activity_admin_logs (admin_id, activity_id, action, details, ip_address)
            VALUES (:admin_id, NULL, 'admin_logout', :details, :ip_address)
        ");
        $log_stmt->execute([
            'admin_id' => $admin_id,
            'details' => json_encode([
                'name' => $admin_name,
                'position' => $admin_position
            ], JSON_UNESCAPED_UNICODE),
            'ip_address' => get_client_ip()
        ]);
    } catch (PDOException $e) {
        error_log('Admin logout log error: ' . $e->getMessage());
    }
}

// 모든 관리자 세션 변수 제거
unset($_SESSION['admin_logged_in']);
unset($_SESSION['admin_id']);
unset($_SESSION['admin_name']);
unset($_SESSION['admin_position']);

// 세션 완전히 파기
session_destroy();

// 관리자 로그인 페이지로 리다이렉트
header('Location: /admin.php');
exit();
