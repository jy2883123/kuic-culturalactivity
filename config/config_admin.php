<?php
/**
 * 관리자 전용 설정 파일
 * 관리자 페이지에서 사용하는 전역 설정 및 세션 검증
 */

// 기본 설정 파일 로드
require_once __DIR__ . '/config.php';

$allow_checkin_token = defined('ALLOW_CHECKIN_TOKEN') && ALLOW_CHECKIN_TOKEN === true;
$checkin_temp_access = false;

if (empty($_SESSION['admin_logged_in'])) {
    if ($allow_checkin_token) {
        if (!empty($_SESSION['checkin_temp_access'])) {
            $checkin_temp_access = true;
        } elseif (!empty($_GET['token'])) {
            $token_value = trim($_GET['token']);
            if ($token_value !== '') {
                try {
                    $token_stmt = $pdo->prepare("
                        SELECT id, label, expires_at
                        FROM cultural_activity_checkin_tokens
                        WHERE token = :token
                          AND is_active = 1
                          AND (expires_at IS NULL OR expires_at > NOW())
                        LIMIT 1
                    ");
                    $token_stmt->execute(['token' => $token_value]);
                    $token = $token_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($token) {
                        $_SESSION['checkin_temp_access'] = true;
                        $_SESSION['checkin_token_id'] = $token['id'];
                        $_SESSION['checkin_token_label'] = $token['label'];
                        $_SESSION['checkin_token_value'] = $token_value;
                        $checkin_temp_access = true;
                    } else {
                        $_SESSION['admin_login_error'] = '유효하지 않은 체크인 토큰입니다.';
                    }
                } catch (PDOException $e) {
                    error_log('Check-in token validation error: ' . $e->getMessage());
                    $_SESSION['admin_login_error'] = '체크인 토큰 검증 중 오류가 발생했습니다.';
                }
            }
        }
    }

    if (!$checkin_temp_access) {
        header('Location: /admin.php');
        exit();
    }
} else {
    // 정식 관리자 로그인 상태에서는 임시 접근 플래그를 해제
    $_SESSION['checkin_temp_access'] = false;
}

if ($checkin_temp_access) {
    $_SESSION['admin_name'] = $_SESSION['admin_name'] ?? ($_SESSION['checkin_token_label'] ?? 'Check-in Staff');
    $_SESSION['admin_position'] = $_SESSION['admin_position'] ?? 'Check-in Staff';
    $_SESSION['admin_id'] = $_SESSION['admin_id'] ?? ('CHECKIN_TOKEN_' . ($_SESSION['checkin_token_id'] ?? 'TEMP'));
}

if (!defined('CHECKIN_TEMP_ACCESS')) {
    define('CHECKIN_TEMP_ACCESS', $checkin_temp_access);
}

// 세션에서 관리자 정보 가져오기 (전역 변수로 사용 가능)
$admin_name = $_SESSION['admin_name'] ?? 'Administrator';
$admin_position = $_SESSION['admin_position'] ?? 'N/A';
$admin_id = $_SESSION['admin_id'] ?? 'N/A';
