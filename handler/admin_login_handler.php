<?php
/**
 * 관리자 로그인 핸들러
 * portal 데이터베이스를 사용하여 관리자 인증 처리
 * Argon2id 비밀번호 해싱 알고리즘 사용
 */

/**
 * 관리자 로그인 인증 처리
 *
 * @param PDO $pdo_portal portal DB 연결 객체
 * @return void
 */
function handle_admin_login(PDO $pdo_portal) {
    // 관리자 로그 기록 함수
    $log_admin_event = static function (string $adminId, string $action, array $info = []) use ($pdo_portal): void {
        try {
            $stmt = $pdo_portal->prepare("
                INSERT INTO cultural_activity_admin_logs (admin_id, activity_id, action, details, ip_address)
                VALUES (:admin_id, NULL, :action, :details, :ip_address)
            ");
            $stmt->execute([
                'admin_id' => $adminId,
                'action' => $action,
                'details' => json_encode($info, JSON_UNESCAPED_UNICODE),
                'ip_address' => get_client_ip()
            ]);
        } catch (PDOException $e) {
            error_log('Admin log error: ' . $e->getMessage());
        }
    };

    // CSRF 토큰 검증
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!csrf_validate_token($csrf_token)) {
        $log_admin_event('unknown', 'admin_login_failed', [
            'reason' => 'csrf_token_invalid'
        ]);
        $_SESSION['admin_login_error'] = '보안 토큰이 유효하지 않습니다. 다시 시도해주세요.';
        header('Location: /admin.php?error=csrf');
        exit();
    }

    // Rate Limiting 체크
    $client_ip = get_client_ip() ?? '0.0.0.0';
    $rate_limit = check_rate_limit($client_ip, 5, 900); // 5회 시도, 15분 잠금

    if (!$rate_limit['allowed']) {
        $wait_time = format_wait_time($rate_limit['wait_seconds']);
        $_SESSION['admin_login_error'] = "로그인 시도 횟수를 초과했습니다. {$wait_time} 후 다시 시도해주세요.";
        header('Location: /admin.php?error=rate_limit');
        exit();
    }

    // 입력값 존재 여부 확인
    if (empty($_POST['login_id']) || empty($_POST['password'])) {
        record_failed_login($client_ip);
        $log_admin_event($_POST['login_id'] ?? 'unknown', 'admin_login_failed', [
            'reason' => 'missing_fields'
        ]);
        $_SESSION['admin_login_error'] = '로그인 ID와 비밀번호를 모두 입력해주세요.';
        header('Location: /admin.php');
        exit();
    }

    $login_id = trim($_POST['login_id']);
    $password = $_POST['password'];

    try {
        // portal.admins 테이블에서 관리자 조회
        $stmt = $pdo_portal->prepare("
            SELECT
                id,
                name,
                login_id,
                position,
                password_hash,
                is_enabled
            FROM admins
            WHERE login_id = :login_id
        ");

        $stmt->execute(['login_id' => $login_id]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        // 관리자 계정 존재 여부 확인
        if (!$admin) {
            record_failed_login($client_ip);
            $log_admin_event($login_id, 'admin_login_failed', [
                'reason' => 'account_not_found'
            ]);
            $_SESSION['admin_login_error'] = '로그인 ID 또는 비밀번호가 올바르지 않습니다.';
            header('Location: /admin.php');
            exit();
        }

        // 관리자 계정 활성화 여부 확인
        if (!$admin['is_enabled']) {
            record_failed_login($client_ip);
            $log_admin_event($login_id, 'admin_login_failed', [
                'reason' => 'account_disabled',
                'name' => $admin['name']
            ]);
            $_SESSION['admin_login_error'] = '계정이 비활성화되었습니다. 시스템 관리자에게 문의하세요.';
            header('Location: /admin.php');
            exit();
        }

        // Argon2id 비밀번호 검증
        // password_verify()는 자동으로 해싱 알고리즘을 감지
        if (!password_verify($password, $admin['password_hash'])) {
            record_failed_login($client_ip);
            $log_admin_event($login_id, 'admin_login_failed', [
                'reason' => 'invalid_password',
                'name' => $admin['name']
            ]);
            $_SESSION['admin_login_error'] = '로그인 ID 또는 비밀번호가 올바르지 않습니다.';
            header('Location: /admin.php');
            exit();
        }

        // 인증 성공 - 관리자 세션 생성
        // 세션 고정 공격 방지를 위한 세션 ID 재생성
        session_regenerate_id(true);

        // 로그인 성공 시 시도 횟수 리셋
        reset_login_attempts($client_ip);

        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = $admin['login_id'];
        $_SESSION['admin_name'] = $admin['name'];
        $_SESSION['admin_position'] = $admin['position'];

        // 로그인 성공 로그 기록
        $log_admin_event($admin['login_id'], 'admin_login_success', [
            'name' => $admin['name'],
            'position' => $admin['position'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        // 관리자 대시보드로 리다이렉트
        header('Location: /Admin/dashboard.php');
        exit();

    } catch (PDOException $e) {
        // 에러 로깅 (운영 환경에서는 적절한 로깅 시스템 사용)
        error_log('Admin login error: ' . $e->getMessage());

        record_failed_login($client_ip);
        $log_admin_event($login_id ?? 'unknown', 'admin_login_failed', [
            'reason' => 'server_error',
            'error' => $e->getMessage()
        ]);

        $_SESSION['admin_login_error'] = '내부 서버 오류가 발생했습니다. 나중에 다시 시도해주세요.';
        header('Location: /admin.php');
        exit();
    }
}
