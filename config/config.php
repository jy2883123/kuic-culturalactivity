<?php
// 세션 보안 설정 (session_start 전에 설정)
ini_set('session.cookie_httponly', 1);
// HTTPS 환경에서만 쿠키 전송, 테스트 서버이므로 종료된 상태. 배포 시 꼭 켜야 함.
ini_set('session.cookie_secure', 0);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);

// 세션 시작
session_start();

// 보안 헤더 설정
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://api.qrserver.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://api.qrserver.com; font-src 'self'; connect-src 'self'; frame-ancestors 'none';");

require_once __DIR__ . '/../functions/csrf.php';
require_once __DIR__ . '/../functions/ip_helper.php';
require_once __DIR__ . '/../functions/rate_limit.php';
require_once __DIR__ . '/../functions/activity_helpers.php';

// 루트 경로 정의
define('ROOT_PATH','/var/www/CulturalActivity/');
define('WEB_ROOT',ROOT_PATH . 'public/');

// 웹 경로 정의
define('BASE_URL', '/');

// 활동별 추가 설정
if (!defined('GOWN_SIZE_KEYWORDS')) {
    // 기본값은 비워두고, 필요 시 키워드 기반으로 활성화
    define('GOWN_SIZE_KEYWORDS', []);
}
if (!defined('GOWN_SIZE_ACTIVITY_IDS')) {
    // 필요 시 활동 ID를 배열로 추가해 키워드 대신 정확히 매칭할 수 있음
    define('GOWN_SIZE_ACTIVITY_IDS', []);
}

// Dev/Test 로그인 설정 (기본 비활성화)
if (!defined('ENABLE_DEV_LOGIN')) {
    define('ENABLE_DEV_LOGIN', filter_var(getenv('ENABLE_DEV_LOGIN') ?: 'false', FILTER_VALIDATE_BOOLEAN));
}
if (!defined('DEV_LOGIN_SECRET')) {
    define('DEV_LOGIN_SECRET', getenv('DEV_LOGIN_SECRET') ?: '');
}
if (!defined('DEV_LOGIN_ALLOWED_IPS')) {
    // 비워두면 IP 제한 없음. 필요 시 ['127.0.0.1', '::1'] 등으로 지정.
    define('DEV_LOGIN_ALLOWED_IPS', []);
}

// 페이지 이름 설정
$PAGE_NAME = "KUISC/IWC Cultural Activity";

// portal DB 접속 (테일스케일 경유)
try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('PORTAL_DB_HOST') ?: '127.0.0.1',
            getenv('PORTAL_DB_PORT') ?: '3306',
            getenv('PORTAL_DB_NAME') ?: 'portal'
        ),
        getenv('PORTAL_DB_USER') ?: '',
        getenv('PORTAL_DB_PASS') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET time_zone = '+09:00'");
} catch (PDOException $e) {
    throw new PDOException($e->getMessage(), (int)$e->getCode());
}

// UwaySync DB 접속 (테일스케일 경유)
try {
    $pdo_uwaysync = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('UWAYSYNC_DB_HOST') ?: '127.0.0.1',
            getenv('UWAYSYNC_DB_PORT') ?: '3306',
            getenv('UWAYSYNC_DB_NAME') ?: 'UwaySync'
        ),
        getenv('UWAYSYNC_DB_USER') ?: '',
        getenv('UWAYSYNC_DB_PASS') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo_uwaysync->exec("SET time_zone = '+09:00'");
} catch (PDOException $e) {
    throw new PDOException($e->getMessage(), (int)$e->getCode());
}
