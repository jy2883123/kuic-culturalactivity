<?php
// 로그인 처리 핸들러
// - index 페이지에서 POST로 전달된 아이디/비밀번호를 검증
// - uway_user_current 테이블에서 bcrypt 해시(passwd)와 institution_role 확인
// - institution_role 이 허용된 값(예: IWC_STUDENT)일 때만 로그인 성공

function handle_login(PDO $pdo_uwaysync, PDO $pdo_portal, string $allowed_institution_role = 'IWC_STUDENT'): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // CSRF 토큰 검증
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!csrf_validate_token($csrf_token)) {
        $_SESSION['login_error'] = 'Invalid security token. Please try again.';
        header('Location: /index.php?error=csrf');
        exit;
    }

    // Rate Limiting 체크
    $client_ip = get_client_ip() ?? '0.0.0.0';
    $rate_limit = check_rate_limit($client_ip, 5, 900); // 5회 시도, 15분 잠금

    if (!$rate_limit['allowed']) {
        $wait_time = format_wait_time($rate_limit['wait_seconds']);
        $_SESSION['login_error'] = "Too many login attempts. Please try again after {$wait_time}.";
        header('Location: /index.php?error=rate_limit');
        exit;
    }

    // 폼 데이터 수신
    $student_number = isset($_POST['student_number']) ? trim($_POST['student_number']) : '';
    $password       = isset($_POST['password']) ? (string)$_POST['password'] : '';

    $log_student_event = static function (string $studentId, string $action, array $info = []) use ($pdo_portal): void {
        try {
            $stmt = $pdo_portal->prepare("
                INSERT INTO cultural_activity_student_logs (student_id, activity_id, action, details, ip_address)
                VALUES (:student_id, NULL, :action, :details, :ip_address)
            ");
            $stmt->execute([
                'student_id' => $studentId,
                'action' => $action,
                'details' => json_encode($info, JSON_UNESCAPED_UNICODE),
                'ip_address' => get_client_ip()
            ]);
        } catch (PDOException $e) {
            error_log('Student log error: ' . $e->getMessage());
        }
    };

    // 기본 검증
    if ($student_number === '' || $password === '') {
        record_failed_login($client_ip);
        $log_student_event($student_number ?: 'unknown', 'student_login_failed', [
            'reason' => 'missing_fields'
        ]);
        $_SESSION['login_error'] = 'Please enter both student number and password.';
        header('Location: /index.php?error=empty');
        exit;
    }

    try {
        $stmt = $pdo_uwaysync->prepare(
            'SELECT user_id, passwd, institution_role, firstname, lastname, company
             FROM uway_user_current
             WHERE user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([':user_id' => $student_number]);
        $user = $stmt->fetch();

        // 아이디 없음
        if (!$user) {
            record_failed_login($client_ip);
            $log_student_event($student_number, 'student_login_failed', [
                'reason' => 'not_found'
            ]);
            $_SESSION['login_error'] = 'Invalid student number or password.';
            header('Location: /index.php?error=invalid');
            exit;
        }

        // institution_role 체크
        if (!isset($user['institution_role']) || $user['institution_role'] !== $allowed_institution_role) {
            record_failed_login($client_ip);
            $log_student_event($student_number, 'student_login_failed', [
                'reason' => 'role_mismatch',
                'institution_role' => $user['institution_role'] ?? null
            ]);
            $_SESSION['login_error'] = 'You are not eligible to log in at this time.';
            header('Location: /index.php?error=role');
            exit;
        }

        // 회사(소속) 체크: 고려대학교 학생 로그인 차단
        $company = isset($user['company']) ? trim((string)$user['company']) : '';
        if ($company === '고려대학교') {
            record_failed_login($client_ip);
            $log_student_event($student_number, 'student_login_failed', [
                'reason' => 'blocked_company',
                'company' => $company
            ]);
            $_SESSION['login_error'] = 'Students from Korea University cannot log in.';
            header('Location: /index.php?error=company');
            exit;
        }

        // 비밀번호 검증 (bcrypt 해시)
        if (!password_verify($password, $user['passwd'])) {
            record_failed_login($client_ip);
            $log_student_event($student_number, 'student_login_failed', [
                'reason' => 'invalid_password'
            ]);
            $_SESSION['login_error'] = 'Invalid student number or password.';
            header('Location: /index.php?error=invalid');
            exit;
        }

        // --- 로그인 성공 처리 ---
        // 세션 고정 공격 방지를 위한 세션 ID 재생성
        session_regenerate_id(true);

        // 로그인 성공 시 시도 횟수 리셋
        reset_login_attempts($client_ip);

        $_SESSION['logged_in']        = true;
        $_SESSION['student_id']       = $user['user_id'];
        $_SESSION['student_name']     = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''));
        $_SESSION['institution_role'] = $user['institution_role'];

        // 로그인 로그 기록
        $log_student_event($user['user_id'], 'student_login_success', [
            'institution_role' => $user['institution_role'],
            'name' => $_SESSION['student_name'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        header('Location: /Student/dashboard.php');
        exit;

    } catch (Throwable $e) {
        record_failed_login($client_ip);
        $log_student_event($student_number ?: 'unknown', 'student_login_failed', [
            'reason' => 'server_error',
            'message' => $e->getMessage()
        ]);
        // 내부 오류
        $_SESSION['login_error'] = 'Internal server error. Please try again later.';
        // 필요시 로그 파일에 $e->getMessage() 기록
        header('Location: /index.php?error=server');
        exit;
    }
}
