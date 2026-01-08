<?php
/**
 * Dev/Test student login helper.
 * Requires ENABLE_DEV_LOGIN=true and DEV_LOGIN_SECRET set in config.
 * Optional IP whitelist via DEV_LOGIN_ALLOWED_IPS.
 */

require_once '../../config/config.php';

if (!defined('ENABLE_DEV_LOGIN') || ENABLE_DEV_LOGIN !== true) {
    http_response_code(403);
    echo 'Dev login disabled.';
    exit;
}

$secret_config = defined('DEV_LOGIN_SECRET') ? DEV_LOGIN_SECRET : '';
if ($secret_config === '') {
    http_response_code(403);
    echo 'Dev login not configured.';
    exit;
}

$client_ip = get_client_ip() ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$allowed_ips = defined('DEV_LOGIN_ALLOWED_IPS') ? DEV_LOGIN_ALLOWED_IPS : [];
if (!empty($allowed_ips) && !in_array($client_ip, $allowed_ips, true)) {
    http_response_code(403);
    echo 'Access denied for IP ' . htmlspecialchars($client_ip, ENT_QUOTES, 'UTF-8') . '.';
    exit;
}

$secret = $_GET['secret'] ?? '';
$student_id = isset($_GET['student_id']) ? trim($_GET['student_id']) : '';

if (!hash_equals($secret_config, $secret)) {
    http_response_code(403);
    echo 'Invalid secret.';
    exit;
}

if ($student_id === '') {
    http_response_code(400);
    echo 'student_id is required.';
    exit;
}

try {
    $stmt = $pdo_uwaysync->prepare("
        SELECT user_id, firstname, lastname, institution_role, company
        FROM uway_user_current
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        http_response_code(404);
        echo 'Student not found.';
        exit;
    }

    $company = isset($student['company']) ? trim((string)$student['company']) : '';
    if ($company === '고려대학교') {
        http_response_code(403);
        echo 'Students from Korea University cannot log in via dev login.';
        exit;
    }

    // 세션 고정 방지
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $_SESSION['logged_in'] = true;
    $_SESSION['student_id'] = $student['user_id'];
    $_SESSION['student_name'] = trim(($student['firstname'] ?? '') . ' ' . ($student['lastname'] ?? ''));
    $_SESSION['institution_role'] = $student['institution_role'] ?? '';
    $_SESSION['dev_login'] = true;

    header('Location: /Student/dashboard.php');
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo 'Failed to perform dev login: ' . $e->getMessage();
    exit;
}
