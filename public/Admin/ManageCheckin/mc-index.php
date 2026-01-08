<?php
define('ALLOW_CHECKIN_TOKEN', true);
require_once '../../../config/config_admin.php';

$admin_name = $_SESSION['admin_name'] ?? 'Administrator';
$admin_position = $_SESSION['admin_position'] ?? 'Admin';
$success_message = '';
$error_message = '';
$force_return_allowed = false;
$force_return_message = '이미 체크인된 학생입니다. 10분 후 다시 시도해주세요.';
$lookup_data = null;
$student_profile = null;
$selected_activity = isset($_POST['activity_id'])
    ? (int)$_POST['activity_id']
    : (isset($_GET['activity_id']) ? (int)$_GET['activity_id'] : 0);
$scan_value = trim($_POST['scan_value'] ?? '');
$action = $_POST['action'] ?? '';

// Flash success message after redirect
if (isset($_SESSION['checkin_flash'])) {
    $success_message = $_SESSION['checkin_flash'];
    unset($_SESSION['checkin_flash']);
}

// INFOCHECK AES/HMAC 키 (타 시스템과 동일한 방식으로 암호화된 학번을 지원)
if (!defined('INFOCHECK_AES_KEY')) {
    define('INFOCHECK_AES_KEY', hex2bin('caf426160ca9673795aa76349d6e2622a19ad98c401997eb9dd9f9a9482332ea'));
}
if (!defined('INFOCHECK_HMAC_KEY')) {
    define('INFOCHECK_HMAC_KEY', hex2bin('1275478753c53a930573837e9286cd1e246f5b9b4c25d948c79465ccad35cb7d'));
}

try {
    $activities_stmt = $pdo->prepare("
        SELECT id, program_name, activity_date
        FROM cultural_activities
        WHERE is_deleted = FALSE
        ORDER BY activity_date ASC, program_name ASC
    ");
    $activities_stmt->execute();
    $activities = $activities_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $activities = [];
    $error_message = '활동 목록을 불러오지 못했습니다: ' . $e->getMessage();
}

if ($selected_activity <= 0 && !empty($activities)) {
    foreach ($activities as $activity) {
        if (strcasecmp(trim($activity['program_name'] ?? ''), 'Graduation Ceremony') === 0) {
            $selected_activity = (int)$activity['id'];
            break;
        }
    }
}

// base64url 디코더 (패딩 자동 보정)
function b64url_decode_strict(string $data)
{
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($data, true);
}

// AES-256-CBC + HMAC-SHA256으로 암호화된 학번을 복호화 (INFOCHECK 포맷)
function decrypt_infocheck_student_id(string $code): ?string {
    if ($code === '') {
        return null;
    }
    $bin = b64url_decode_strict($code);
    if ($bin === false || strlen($bin) < 49) { // 16(iv) + 1(min payload) + 32(hmac)
        return null;
    }
    $iv = substr($bin, 0, 16);
    $hmacGiven = substr($bin, -32);
    $ciphertext = substr($bin, 16, -32);

    $hmacCalc = hash_hmac('sha256', $iv . $ciphertext, INFOCHECK_HMAC_KEY, true);
    if (!hash_equals($hmacCalc, $hmacGiven)) {
        return null;
    }

    $plain = openssl_decrypt($ciphertext, 'AES-256-CBC', INFOCHECK_AES_KEY, OPENSSL_RAW_DATA, $iv);
    if ($plain === false || $plain === '') {
        return null;
    }
    return $plain;
}

function parse_scan_payload(string $payload): array {
    $decoded = base64_decode($payload, true);
    if ($decoded !== false && stripos($decoded, 'CA-CHECKIN') === 0) {
        $payload = $decoded;
    }

    // Expected format: CA-CHECKIN|ACT=123|STU=2025xxxx
    if (stripos($payload, 'CA-CHECKIN') === 0) {
        $parts = explode('|', $payload);
        $data = [];
        foreach ($parts as $part) {
            if (strpos($part, '=') !== false) {
                [$key, $value] = explode('=', $part, 2);
                $data[strtoupper(trim($key))] = trim($value);
            }
        }
        if (!empty($data['ACT']) && !empty($data['STU'])) {
            return [
                'activity_id' => (int)$data['ACT'],
                'student_id' => $data['STU'],
                'token' => $data['TOKEN'] ?? null
            ];
        }
    }
    // Treat fallback payload as direct student ID.
    $studentId = $payload;

    // 암호화된 학번(InfoCheck 포맷)일 경우 복호화
    $decrypted = decrypt_infocheck_student_id($studentId);
    if ($decrypted !== null) {
        $studentId = $decrypted;
    }

    return [
        'activity_id' => null,
        'student_id' => $studentId,
        'token' => null
    ];
}

function expected_qr_token(array $activityRow, string $studentId): string {
    if (!empty($activityRow['qr_code'])) {
        return (string)$activityRow['qr_code'];
    }
    $activityId = $activityRow['activity_id'] ?? $activityRow['id'] ?? '';
    $seed = $activityId . '|' . $studentId . '|' . ($activityRow['program_name'] ?? '');
    return hash('sha256', $seed);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['lookup', 'checkin', 'force_return'], true)) {
    if ($selected_activity <= 0) {
        $error_message = '활동을 선택해주세요.';
    } elseif ($action !== 'force_return' && $scan_value === '') {
        $error_message = 'QR 코드 또는 학생 정보를 입력해주세요.';
    } else {
        try {
            if ($action === 'force_return') {
                $enrollment_id = (int)($_POST['enrollment_id'] ?? 0);
                if ($enrollment_id <= 0) {
                    throw new Exception('잘못된 요청입니다. 다시 시도해주세요.');
                }
                $enrollment_stmt = $pdo->prepare("
                    SELECT
                        e.id AS enrollment_id,
                        e.student_id,
                        e.student_name,
                        e.gown_size,
                        e.status,
                        e.checked_in,
                        e.check_in_time,
                        e.gown_rented_at,
                        e.gown_returned_at,
                        ca.id AS activity_id,
                        ca.program_name,
                        ca.activity_date,
                        ca.activity_time,
                        ca.qr_code,
                        ca.requires_gown_size
                    FROM cultural_activity_enrollments e
                    INNER JOIN cultural_activities ca ON ca.id = e.activity_id
                    WHERE e.id = :enrollment_id
                      AND e.activity_id = :activity_id
                      AND e.status = 'approved'
                      AND ca.is_deleted = FALSE
                    LIMIT 1
                ");
                $enrollment_stmt->execute([
                    'enrollment_id' => $enrollment_id,
                    'activity_id' => $selected_activity
                ]);
                $lookup_data = $enrollment_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$lookup_data) {
                    throw new Exception('해당 학생을 찾을 수 없습니다.');
                }
                if (!activity_requires_gown_size($lookup_data)) {
                    throw new Exception('가운 반납 처리가 필요한 활동이 아닙니다.');
                }
                if (empty($lookup_data['gown_rented_at'])) {
                    throw new Exception('가운 대여 기록이 없습니다.');
                }
                if (!empty($lookup_data['gown_returned_at'])) {
                    throw new Exception('이미 가운 반납까지 완료된 학생입니다.');
                }

                $pdo->beginTransaction();
                $update_stmt = $pdo->prepare("
                    UPDATE cultural_activity_enrollments
                    SET gown_returned_at = NOW()
                    WHERE id = :id
                ");
                $update_stmt->execute(['id' => $lookup_data['enrollment_id']]);

                $history_stmt = $pdo->prepare("
                    INSERT INTO cultural_activity_enrollment_history
                    (enrollment_id, student_id, student_name, activity_id, action, action_details, ip_address)
                    VALUES (:enrollment_id, :student_id, :student_name, :activity_id, :action, :action_details, :ip_address)
                ");
                $history_stmt->execute([
                    'enrollment_id' => $lookup_data['enrollment_id'],
                    'student_id' => $lookup_data['student_id'],
                    'student_name' => $lookup_data['student_name'],
                    'activity_id' => $selected_activity,
                    'action' => 'checkin',
                    'action_details' => 'gown-returned-forced',
                    'ip_address' => get_client_ip()
                ]);

                $admin_id = $_SESSION['admin_id'] ?? 'unknown';
                $log_stmt = $pdo->prepare("
                    INSERT INTO cultural_activity_admin_logs (admin_id, activity_id, action, details, ip_address)
                    VALUES (:admin_id, :activity_id, 'manual_checkin', :details, :ip_address)
                ");
                $log_stmt->execute([
                    'admin_id' => $admin_id,
                    'activity_id' => $selected_activity,
                    'details' => json_encode([
                        'enrollment_id' => $lookup_data['enrollment_id'],
                        'student_id' => $lookup_data['student_id'],
                        'student_name' => $lookup_data['student_name'],
                        'checkin_type' => 'gown-returned-forced'
                    ], JSON_UNESCAPED_UNICODE),
                    'ip_address' => get_client_ip()
                ]);

                $pdo->commit();

                $_SESSION['checkin_flash'] = '가운 반납이 처리되었습니다.';
                header('Location: ' . $_SERVER['PHP_SELF'] . '?activity_id=' . urlencode((string)$selected_activity));
                exit;
            }

            $parsed = parse_scan_payload($scan_value);
            $student_id = $parsed['student_id'];
            $qr_activity = $parsed['activity_id'];
            $qr_token = $parsed['token'];

            if ($qr_activity !== null && $qr_activity !== $selected_activity) {
                throw new Exception('QR 코드에 기록된 활동과 선택한 활동이 일치하지 않습니다.');
            }

            $enrollment_stmt = $pdo->prepare("
                SELECT
                    e.id AS enrollment_id,
                    e.student_id,
                    e.student_name,
                    e.gown_size,
                    e.status,
                    e.checked_in,
                    e.check_in_time,
                    e.gown_rented_at,
                    e.gown_returned_at,
                    ca.id AS activity_id,
                    ca.program_name,
                    ca.activity_date,
                    ca.activity_time,
                    ca.qr_code,
                    ca.requires_gown_size,
                    TIMESTAMPDIFF(SECOND, e.check_in_time, NOW()) AS seconds_since_checkin,
                    TIMESTAMPDIFF(SECOND, e.gown_rented_at, NOW()) AS seconds_since_rent
                FROM cultural_activity_enrollments e
                INNER JOIN cultural_activities ca ON ca.id = e.activity_id
                WHERE e.activity_id = :activity_id
                  AND e.student_id = :student_id
                  AND e.status = 'approved'
                  AND ca.is_deleted = FALSE
                LIMIT 1
            ");
            $enrollment_stmt->execute([
                'activity_id' => $selected_activity,
                'student_id' => $student_id
            ]);
            $lookup_data = $enrollment_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$lookup_data) {
                throw new Exception('해당 학생은 이 활동에 승인된 상태가 아닙니다.');
            }

            if ($qr_token !== null) {
                $expected_token = expected_qr_token($lookup_data, $lookup_data['student_id']);
                if (!hash_equals($expected_token, $qr_token)) {
                    throw new Exception('유효하지 않은 QR 코드입니다.');
                }
            }

            $profile_stmt = $pdo->prepare("
                SELECT applicant_name, email, birthdate
                FROM uwayxlsx_current
                WHERE application_no = :app_no
                LIMIT 1
            ");
            $profile_stmt->execute(['app_no' => $student_id]);
            $student_profile = $profile_stmt->fetch(PDO::FETCH_ASSOC) ?: [
                'applicant_name' => $lookup_data['student_name'],
                'email' => '',
                'birthdate' => ''
            ];

            if ($action === 'checkin') {
                $pdo->beginTransaction();
                $requires_gown = activity_requires_gown_size($lookup_data);
                $history_action = 'checkin';
                $history_details = null;
                $flash_message = '출석이 완료되었습니다.';

                if ($requires_gown) {
                    $has_rented = !empty($lookup_data['gown_rented_at']);
                    $has_returned = !empty($lookup_data['gown_returned_at']);

                    if ($has_returned) {
                        throw new Exception('이미 가운 반납까지 완료된 학생입니다.');
                    }

                    if (!$has_rented) {
                        $update_stmt = $pdo->prepare("
                            UPDATE cultural_activity_enrollments
                            SET checked_in = 1,
                                check_in_time = NOW(),
                                gown_rented_at = NOW()
                            WHERE id = :id
                        ");
                        $update_stmt->execute(['id' => $lookup_data['enrollment_id']]);
                        $history_details = 'gown-rented';
                        $flash_message = '가운 대여 체크인이 완료되었습니다.';
                    } else {
                        $seconds_since_checkin = null;
                        if ($lookup_data['seconds_since_checkin'] !== null) {
                            $seconds_since_checkin = (int)$lookup_data['seconds_since_checkin'];
                        } elseif ($lookup_data['seconds_since_rent'] !== null) {
                            $seconds_since_checkin = (int)$lookup_data['seconds_since_rent'];
                        }
                        if ($seconds_since_checkin !== null && $seconds_since_checkin < 600) {
                            throw new Exception($force_return_message);
                        }
                        $update_stmt = $pdo->prepare("
                            UPDATE cultural_activity_enrollments
                            SET gown_returned_at = NOW()
                            WHERE id = :id
                        ");
                        $update_stmt->execute(['id' => $lookup_data['enrollment_id']]);
                        $history_details = 'gown-returned';
                        $flash_message = '가운 반납 체크인이 완료되었습니다.';
                    }
                } else {
                    if ((int)$lookup_data['checked_in'] === 1) {
                        throw new Exception('이미 출석 처리된 학생입니다.');
                    }

                    $update_stmt = $pdo->prepare("
                        UPDATE cultural_activity_enrollments
                        SET checked_in = 1, check_in_time = NOW()
                        WHERE id = :id
                    ");
                    $update_stmt->execute(['id' => $lookup_data['enrollment_id']]);
                }

                $history_stmt = $pdo->prepare("
                    INSERT INTO cultural_activity_enrollment_history
                    (enrollment_id, student_id, student_name, activity_id, action, action_details, ip_address)
                    VALUES (:enrollment_id, :student_id, :student_name, :activity_id, :action, :action_details, :ip_address)
                ");
                $history_stmt->execute([
                    'enrollment_id' => $lookup_data['enrollment_id'],
                    'student_id' => $lookup_data['student_id'],
                    'student_name' => $lookup_data['student_name'],
                    'activity_id' => $selected_activity,
                    'action' => $history_action,
                    'action_details' => $history_details,
                    'ip_address' => get_client_ip()
                ]);

                // 관리자 로그 기록
                $admin_id = $_SESSION['admin_id'] ?? 'unknown';

                $log_stmt = $pdo->prepare("
                    INSERT INTO cultural_activity_admin_logs (admin_id, activity_id, action, details, ip_address)
                    VALUES (:admin_id, :activity_id, 'manual_checkin', :details, :ip_address)
                ");
                $log_stmt->execute([
                    'admin_id' => $admin_id,
                    'activity_id' => $selected_activity,
                    'details' => json_encode([
                        'enrollment_id' => $lookup_data['enrollment_id'],
                        'student_id' => $lookup_data['student_id'],
                        'student_name' => $lookup_data['student_name'],
                        'checkin_type' => $history_details ?? 'checkin'
                    ], JSON_UNESCAPED_UNICODE),
                    'ip_address' => get_client_ip()
                ]);

                $pdo->commit();

                // Redirect to clear the form but keep the selected activity for the next scan
                $_SESSION['checkin_flash'] = $flash_message;
                header('Location: ' . $_SERVER['PHP_SELF'] . '?activity_id=' . urlencode((string)$selected_activity));
                exit;
            }

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = $e->getMessage();
            if ($error_message === $force_return_message && !empty($lookup_data)) {
                $force_return_allowed = true;
            } else {
                $lookup_data = null;
                $student_profile = null;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>체크인 관리 | <?= htmlspecialchars($PAGE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <script src="html5-qrcode.min.js"></script>
    <style>
        :root {
            --admin-primary: #1e40af;
            --admin-accent: #3b82f6;
            --bg-soft: #f6f8fb;
            --border-color: #d1dce8;
            --text-main: #1f2933;
            --text-muted: #6b7280;
            --success-green: #16a34a;
            --warning-orange: #ea580c;
            --error-red: #dc2626;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { min-height: 100vh; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif; background: var(--bg-soft); color: var(--text-main); }
        .container { min-height: 100vh; display: flex; flex-direction: column; }
        .header { background: linear-gradient(135deg, var(--admin-primary), var(--admin-accent)); color: #fff; padding: 20px 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .header-content { max-width: 1400px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
        .header-left { display: flex; align-items: center; gap: 16px; }
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: #ffffff;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            transition: background 0.2s ease;
        }
        .back-btn:hover { background: rgba(255,255,255,0.35); }
        .header-title h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: 2px; }
        .header-subtitle { font-size: 0.85rem; opacity: 0.9; }
        .header-right { display: flex; align-items: center; gap: 12px; }
        .admin-badge { padding: 6px 14px; background: rgba(255,255,255,0.25); border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .btn-logout { padding: 8px 20px; background: rgba(255,255,255,0.95); color: var(--admin-primary); border-radius: 8px; text-decoration: none; font-weight: 600; }
        .main-content { flex: 1; max-width: 1100px; margin: 0 auto; width: 100%; padding: 32px 24px 48px; }
        .page-header { margin-bottom: 24px; }
        .page-title { font-size: 1.8rem; font-weight: 700; color: var(--admin-primary); margin-bottom: 8px; }
        .page-description { color: var(--text-muted); line-height: 1.5; }
        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; }
        .alert-success { background: #dcfce7; color: var(--success-green); }
        .alert-error { background: #fee2e2; color: var(--error-red); }
        .card { background: #fff; border-radius: 18px; padding: 24px; box-shadow: 0 4px 16px rgba(0,0,0,0.08); margin-bottom: 24px; }
        label { font-weight: 600; display: block; margin-bottom: 6px; }
        select, input[type="text"] { width: 100%; border-radius: 10px; border: 1px solid var(--border-color); padding: 10px 12px; font-size: 0.95rem; }
        .actions { margin-top: 16px; display: flex; gap: 12px; flex-wrap: wrap; }
        .btn-primary { padding: 10px 18px; border-radius: 10px; border: none; background: linear-gradient(135deg, #1a5490, #2563eb); color: #fff; font-weight: 600; cursor: pointer; }
        .btn-secondary { padding: 10px 18px; border-radius: 10px; border: 1px solid var(--border-color); background: #fff; color: var(--text-main); font-weight: 600; cursor: pointer; }
        .force-return-form { margin: -8px 0 20px; }
        .lookup-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-top: 16px; }
        .info-box { background: #f9fafb; border-radius: 12px; padding: 16px; border: 1px solid #e5e7eb; }
        .info-label { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); margin-bottom: 4px; }
        .info-value { font-size: 1rem; font-weight: 600; }
        .gown-size-box { text-align: center; }
        .gown-size-box .info-value { font-size: 1.6rem; font-weight: 800; letter-spacing: 0.04em; }
        .gown-size-s { background: #dbeafe; border-color: #93c5fd; }
        .gown-size-s .info-value { color: #1e3a8a; }
        .gown-size-m { background: #dcfce7; border-color: #86efac; }
        .gown-size-m .info-value { color: #166534; }
        .gown-size-l { background: #ffedd5; border-color: #fdba74; }
        .gown-size-l .info-value { color: #9a3412; }
        .gown-size-unknown { background: #f3f4f6; border-color: #e5e7eb; }
        .footer { background: #fff; border-top: 1px solid var(--border-color); padding: 20px 32px; text-align: center; color: var(--text-muted); font-size: 0.85rem; }

        /* QR Scanner Styles */
        .qr-scanner-section { display: none; }
        .qr-scanner-container { margin: 16px 0; padding: 16px; background: #f0f4f8; border-radius: 12px; border: 2px dashed var(--admin-accent); }
        #qr-reader { max-width: 100%; width: 100%; border-radius: 8px; overflow: hidden; }
        #qr-reader video { width: 100% !important; border-radius: 8px; }
        #qr-reader__dashboard_section { display: none !important; }
        #qr-reader__camera_selection { margin-bottom: 10px; }
        .qr-status { margin-top: 12px; padding: 10px; background: #fff; border-radius: 8px; font-size: 0.9rem; text-align: center; }
        .qr-status.scanning { color: var(--admin-accent); }
        .qr-status.success { color: var(--success-green); font-weight: 600; }
        .qr-status.error { color: var(--error-red); }
        .btn-qr { width: 100%; padding: 12px; border-radius: 10px; border: none; background: linear-gradient(135deg, var(--success-green), #22c55e); color: #fff; font-weight: 600; cursor: pointer; font-size: 1rem; }
        .btn-qr:disabled { background: #d1d5db; cursor: not-allowed; }
        .btn-qr-stop { background: linear-gradient(135deg, var(--error-red), #ef4444); }

        @media (min-width: 769px) {
            .qr-scanner-section { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="header-content">
                <div class="header-left">
                    <a href="/Admin/dashboard.php" class="back-btn">← 대시보드</a>
                    <div>
                        <h1>체크인 관리</h1>
                        <div class="header-subtitle">선택한 활동에서 QR/학번으로 출석을 처리합니다.</div>
                    </div>
                </div>
                <div class="header-right">
                    <span class="admin-badge"><?= htmlspecialchars($admin_position, ENT_QUOTES, 'UTF-8') ?></span>
                    <a href="/Auth/admin_logout.php" class="btn-logout">로그아웃</a>
                </div>
            </div>
        </header>

        <main class="main-content">
            <div class="page-header">
                <h2 class="page-title">빠른 체크인</h2>
                <p class="page-description">QR 코드를 계속 스캔하거나 예외 상황에서는 학번을 수동으로 입력해 출석을 처리하세요.</p>
            </div>

            <?php if ($success_message): ?><div class="alert alert-success"><?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <?php if ($error_message): ?><div class="alert alert-error"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <?php if ($force_return_allowed && !empty($lookup_data['enrollment_id'])): ?>
                <form method="POST" class="force-return-form">
                    <input type="hidden" name="activity_id" value="<?= htmlspecialchars((string)$selected_activity, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="enrollment_id" value="<?= htmlspecialchars((string)$lookup_data['enrollment_id'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="force_return">
                    <button type="submit" class="btn-primary">가운 반납 처리(무시)</button>
                </form>
            <?php endif; ?>

            <section class="card">
                <form method="POST" id="checkin-form">
                    <label for="activity_id">활동 선택</label>
                    <select id="activity_id" name="activity_id" required>
                        <option value="">-- Select activity --</option>
                        <?php foreach ($activities as $activity): ?>
                            <?php $label = $activity['program_name'] . ' (' . date('m/d', strtotime($activity['activity_date'])) . ')'; ?>
                            <option value="<?= $activity['id'] ?>" <?= $selected_activity == $activity['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- QR Scanner Section (Mobile Only) -->
                    <div class="qr-scanner-section" id="qr-scanner-section">
                        <label style="margin-top:14px;">📷 QR 코드 스캔</label>
                        <div class="qr-scanner-container">
                            <button type="button" id="start-scan-btn" class="btn-qr" disabled>
                                QR 스캔 시작
                            </button>
                            <div id="qr-reader"></div>
                            <button type="button" id="stop-scan-btn" class="btn-qr btn-qr-stop" style="display:none; margin-top:12px;">
                                스캔 중지
                            </button>
                            <div id="qr-status" class="qr-status" style="display:none;"></div>
                        </div>
                    </div>

                    <label for="scan_value" style="margin-top:14px;">QR 코드 / 학번 입력</label>
                    <input type="text" id="scan_value" name="scan_value" value="<?= htmlspecialchars($scan_value, ENT_QUOTES, 'UTF-8') ?>" placeholder="QR 또는 학번 입력" required autofocus />

                    <div class="actions">
                        <?php if (!$lookup_data): ?>
                            <button type="submit" name="action" value="lookup" class="btn-secondary">학생 정보 확인</button>
                        <?php endif; ?>
                        <?php if ($lookup_data): ?>
                            <button type="submit" id="checkin-submit" name="action" value="checkin" class="btn-primary" style="width: 100%; font-size: 1.1rem; padding: 14px;">
                                ✓ 체크인 처리
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <?php if ($lookup_data): ?>
                <section class="card">
                    <h3 style="margin-bottom:12px;">학생 정보 확인</h3>
                    <div class="lookup-grid">
                        <div class="info-box">
                            <div class="info-label">학생 이름</div>
                            <div class="info-value"><?= htmlspecialchars($lookup_data['student_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="info-box">
                            <div class="info-label">학번</div>
                            <div class="info-value"><?= htmlspecialchars($lookup_data['student_id'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="info-box">
                            <div class="info-label">생년월일</div>
                            <div class="info-value"><?= htmlspecialchars($student_profile['birthdate'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="info-box">
                        <div class="info-label">이메일</div>
                        <div class="info-value"><?= htmlspecialchars($student_profile['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <?php if (activity_requires_gown_size($lookup_data)): ?>
                        <?php
                            $gown_size_value = strtoupper(trim((string)($lookup_data['gown_size'] ?? '')));
                            $gown_size_key = in_array($gown_size_value, ['S', 'M', 'L'], true) ? strtolower($gown_size_value) : 'unknown';
                        ?>
                        <div class="info-box gown-size-box gown-size-<?= $gown_size_key ?>">
                            <div class="info-label">졸업가운 사이즈</div>
                            <div class="info-value"><?= htmlspecialchars($lookup_data['gown_size'] ?? '-', ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if (activity_requires_gown_size($lookup_data)): ?>
                        <?php
                            $has_rented = !empty($lookup_data['gown_rented_at']);
                            $has_returned = !empty($lookup_data['gown_returned_at']);
                            if ($has_returned) {
                                $gown_status_label = '반납 완료';
                            } elseif ($has_rented) {
                                $gown_status_label = '대여 중 (반납 대기)';
                            } else {
                                $gown_status_label = '대여 대기';
                            }
                        ?>
                        <div class="info-box">
                            <div class="info-label">가운 상태</div>
                            <div class="info-value"><?= htmlspecialchars($gown_status_label, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <?php if (!empty($lookup_data['gown_rented_at'])): ?>
                            <div class="info-box">
                                <div class="info-label">대여 시간</div>
                                <div class="info-value"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($lookup_data['gown_rented_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($lookup_data['gown_returned_at'])): ?>
                            <div class="info-box">
                                <div class="info-label">반납 시간</div>
                                <div class="info-value"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($lookup_data['gown_returned_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="info-box">
                            <div class="info-label">현재 상태</div>
                            <div class="info-value"><?= $lookup_data['checked_in'] ? 'Checked-in' : 'Pending' ?></div>
                        </div>
                    <?php endif; ?>
                    </div>
                    <?php if ($lookup_data['checked_in'] && $lookup_data['check_in_time']): ?>
                        <p style="margin-top:12px; color: var(--success-green);">출석 완료 시간: <?= htmlspecialchars(date('Y-m-d H:i', strtotime($lookup_data['check_in_time'])), ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>

        <footer class="footer">
            © DATANEST, KOREA UNIVERSITY – Int'l Summer &amp; Winter Campus
        </footer>
    </div>

    <script>
        // Mobile detection
        function isMobileDevice() {
            return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        }

        // QR Scanner initialization
        let html5QrCode = null;
        let isScanning = false;

        const qrScannerSection = document.getElementById('qr-scanner-section');
        const startScanBtn = document.getElementById('start-scan-btn');
        const stopScanBtn = document.getElementById('stop-scan-btn');
        const qrReaderDiv = document.getElementById('qr-reader');
        const qrStatus = document.getElementById('qr-status');
        const scanValueInput = document.getElementById('scan_value');
        const activitySelect = document.getElementById('activity_id');
        const isMobile = isMobileDevice();
        const hasPreselectedActivity = activitySelect && activitySelect.value;

        // Prevent Enter key from causing check-in
        scanValueInput.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' || event.keyCode === 13) {
                // Always treat 'Enter' in the scan box as a 'lookup' request.
                // This prevents the default behavior of submitting the form and triggering the 'checkin' button.
                event.preventDefault();

                if (!scanValueInput.value.trim()) {
                    return; // Do nothing if the input is empty
                }

                const form = document.getElementById('checkin-form');
                
                // Remove any existing hidden action inputs to prevent conflicts
                const existingActionInput = form.querySelector('input[type="hidden"][name="action"]');
                if (existingActionInput) {
                    existingActionInput.remove();
                }

                // Create and append a hidden input to specify the 'lookup' action
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'lookup';
                form.appendChild(actionInput);
                
                // Submit the form for lookup
                form.submit();
            }
        });

        function focusScanInput() {
            if (!scanValueInput) return;
            try {
                scanValueInput.focus({ preventScroll: true });
                scanValueInput.select();
            } catch (e) {
                scanValueInput.focus();
                scanValueInput.select();
            }
        }

        window.addEventListener('load', focusScanInput);
        window.addEventListener('pageshow', focusScanInput);
        // Immediate focus on load (desktop+mobile 모두)
        focusScanInput();
        // Re-assert focus a few times to handle mobile UI overlays stealing focus
        let focusAttempts = 0;
        const focusInterval = setInterval(() => {
            if (document.activeElement === scanValueInput || focusAttempts > 10) {
                clearInterval(focusInterval);
                return;
            }
            focusAttempts += 1;
            focusScanInput();
        }, 300);

        // Show QR scanner only on mobile devices
        if (isMobile) {
            qrScannerSection.style.display = 'block';

            // Auto-start scanner after checkin if activity is selected
            <?php if ($success_message && $selected_activity): ?>
                // Wait for page load, then auto-start scanner
                window.addEventListener('load', function() {
                    setTimeout(function() {
                        if (activitySelect.value && !isScanning) {
                            startScanBtn.click();
                        }
                    }, 1000);
                });
            <?php endif; ?>
        }
        // Enable scan button if activity is already selected on load
        if (startScanBtn && activitySelect && activitySelect.value) {
            startScanBtn.disabled = false;
        }
        // On mobile, auto-start scanner when activity is preselected (e.g., after a previous check-in)
        if (isMobile && activitySelect && activitySelect.value && startScanBtn) {
            setTimeout(function() {
                if (!isScanning && !startScanBtn.disabled) {
                    startScanBtn.click();
                }
            }, 300);
        }

        // Enable/disable scan button based on activity selection
        activitySelect.addEventListener('change', function() {
            if (this.value) {
                startScanBtn.disabled = false;
            } else {
                startScanBtn.disabled = true;
                if (isScanning) {
                    stopScanner();
                }
            }
        });

        // Check initial state
        if (activitySelect.value) {
            startScanBtn.disabled = false;
        }

        // Start scanner
        startScanBtn.addEventListener('click', async function() {
            if (!activitySelect.value) {
                showStatus('활동을 먼저 선택해주세요.', 'error');
                return;
            }

            try {
                html5QrCode = new Html5Qrcode("qr-reader");

                // Get rear camera
                const devices = await Html5Qrcode.getCameras();
                if (devices && devices.length > 0) {
                    // Try to find rear camera (environment)
                    let selectedCamera = devices.find(device =>
                        device.label.toLowerCase().includes('back') ||
                        device.label.toLowerCase().includes('rear') ||
                        device.label.toLowerCase().includes('environment')
                    );

                    // If no rear camera found, use the last camera (usually rear on mobile)
                    if (!selectedCamera && devices.length > 1) {
                        selectedCamera = devices[devices.length - 1];
                    } else if (!selectedCamera) {
                        selectedCamera = devices[0];
                    }

                    await html5QrCode.start(
                        selectedCamera.id,
                        {
                            fps: 10,
                            qrbox: { width: 250, height: 250 }
                        },
                        onScanSuccess,
                        onScanFailure
                    );

                    isScanning = true;
                    startScanBtn.style.display = 'none';
                    stopScanBtn.style.display = 'block';
                    showStatus('스캔 중... QR 코드를 카메라에 비춰주세요', 'scanning');
                }
            } catch (err) {
                console.error('QR Scanner Error:', err);
                let errorMsg = '카메라를 시작할 수 없습니다.';

                if (err.name === 'NotAllowedError') {
                    errorMsg = '카메라 권한이 거부되었습니다. 브라우저 설정에서 카메라 권한을 허용해주세요.';
                } else if (err.name === 'NotFoundError') {
                    errorMsg = '카메라를 찾을 수 없습니다.';
                } else if (err.name === 'NotReadableError') {
                    errorMsg = '카메라가 다른 앱에서 사용 중입니다.';
                } else if (err.name === 'OverconstrainedError') {
                    errorMsg = '요청한 카메라 설정을 지원하지 않습니다.';
                } else if (err.name === 'SecurityError') {
                    errorMsg = 'HTTPS 환경에서만 카메라를 사용할 수 있습니다.';
                } else {
                    errorMsg = '카메라 시작 실패: ' + (err.message || err.toString());
                }

                showStatus(errorMsg, 'error');
            }
        });

        // Stop scanner
        stopScanBtn.addEventListener('click', function() {
            stopScanner();
        });

        function stopScanner() {
            if (html5QrCode && isScanning) {
                html5QrCode.stop().then(() => {
                    html5QrCode.clear();
                    isScanning = false;
                    startScanBtn.style.display = 'block';
                    stopScanBtn.style.display = 'none';
                    qrStatus.style.display = 'none';
                }).catch(err => {
                    console.error('Stop scanner error:', err);
                });
            }
        }

        // Scan success handler
        function onScanSuccess(decodedText) {
            const isStudentLookedUp = document.getElementById('checkin-submit') !== null;
            const previousScanValue = "<?= htmlspecialchars($scan_value ?? '', ENT_QUOTES, 'UTF-8') ?>";

            // If a student is already looked up and the user scans the same QR code again,
            // do nothing. This forces them to press the check-in button manually.
            if (isStudentLookedUp && decodedText === previousScanValue) {
                showStatus('이미 조회된 학생입니다. 체크인 버튼을 눌러주세요.', 'success');
                if (navigator.vibrate) {
                    navigator.vibrate(150);
                }
                return;
            }

            // Update input field with scanned data
            scanValueInput.value = decodedText;
            showStatus('✓ QR 코드 스캔 완료! 학생 정보를 조회합니다...', 'success');

            // Optional: vibrate on success (if supported)
            if (navigator.vibrate) {
                navigator.vibrate(200);
            }

            // Auto-submit form for lookup
            setTimeout(function() {
                const form = document.getElementById('checkin-form');
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'lookup';

                // Remove any other hidden action inputs to avoid conflict
                const otherActions = form.querySelectorAll('input[type="hidden"][name="action"]');
                otherActions.forEach(input => input.remove());
                
                form.appendChild(actionInput);
                form.submit();
            }, 500); // Small delay for better UX
        }

        // Scan failure handler (fires on every frame without QR code)
        function onScanFailure(error) {
            // Don't show errors - just keep scanning silently
        }

        // Show status message
        function showStatus(message, type) {
            qrStatus.textContent = message;
            qrStatus.className = 'qr-status ' + type;
            qrStatus.style.display = 'block';
        }

        const checkinForm = document.getElementById('checkin-form');
        const checkinSubmit = document.getElementById('checkin-submit');
        if (checkinForm) {
            checkinForm.addEventListener('submit', function (e) {
                const submitter = e.submitter || document.activeElement;
                const submitterAction = submitter && submitter.name === 'action' ? submitter.value : '';
                const hiddenActions = Array.from(checkinForm.querySelectorAll('input[type="hidden"][name="action"]'));
                const actionInput = hiddenActions[0] || null;
                const actionValue = actionInput ? actionInput.value : '';
                if (submitterAction) {
                    hiddenActions.forEach(input => input.remove());
                    const normalizedAction = document.createElement('input');
                    normalizedAction.type = 'hidden';
                    normalizedAction.name = 'action';
                    normalizedAction.value = submitterAction;
                    checkinForm.appendChild(normalizedAction);
                }
                if (submitter === checkinSubmit) {
                    return;
                }
                if (submitterAction === 'lookup' || actionValue === 'lookup') {
                    return;
                }
                e.preventDefault();
            });
        }
    </script>
</body>
</html>
