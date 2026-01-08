<?php
/**
 * 미출석 학생 일괄 밴 처리
 */

require_once '../../../config/config_admin.php';

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

$activity_id = isset($_POST['activity_id']) ? (int)$_POST['activity_id'] : 0;

if ($activity_id <= 0) {
    $_SESSION['me_error'] = '유효하지 않은 활동 ID입니다.';
    header('Location: /Admin/ManageEnrollment/me-index.php');
    exit();
}

try {
    $pdo->beginTransaction();

    $activity_stmt = $pdo->prepare("
        SELECT id, program_name
        FROM cultural_activities
        WHERE id = :id AND is_deleted = 0
        FOR UPDATE
    ");
    $activity_stmt->execute(['id' => $activity_id]);
    $activity = $activity_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        throw new Exception('프로그램을 찾을 수 없습니다.');
    }

    $unchecked_stmt = $pdo->prepare("
        SELECT student_id, student_name
        FROM cultural_activity_enrollments
        WHERE activity_id = :activity_id
          AND status = 'approved'
          AND checked_in = 0
    ");
    $unchecked_stmt->execute(['activity_id' => $activity_id]);
    $unchecked_students = $unchecked_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($unchecked_students)) {
        throw new Exception('미출석 상태의 승인 학생이 없습니다.');
    }

    $student_ids = array_column($unchecked_students, 'student_id');
    $existing_banned_map = [];

    if (!empty($student_ids)) {
        $placeholders = [];
        $params = ['activity_id' => $activity_id];
        foreach ($student_ids as $idx => $sid) {
            $key = ':sid' . $idx;
            $placeholders[] = $key;
            $params[$key] = $sid;
        }

        $existing_stmt = $pdo->prepare("
            SELECT student_id
            FROM cultural_activity_bans
            WHERE student_id IN (" . implode(',', $placeholders) . ")
              AND is_active = 1
              AND (ban_type = 'all' OR (ban_type = 'specific' AND activity_id = :activity_id))
        ");
        $existing_stmt->execute($params);
        $existing_banned = $existing_stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $existing_banned_map = array_flip($existing_banned);
    }

    $admin_login_id = $_SESSION['admin_id'] ?? null;
    if (!$admin_login_id) {
        throw new Exception('관리자 세션이 만료되었습니다.');
    }

    $admin_lookup = $pdo->prepare("SELECT id FROM admins WHERE login_id = :login LIMIT 1");
    $admin_lookup->execute(['login' => $admin_login_id]);
    $admin_numeric_id = $admin_lookup->fetchColumn();

    if (!$admin_numeric_id) {
        throw new Exception('관리자 정보를 확인할 수 없습니다.');
    }

    $insert_stmt = $pdo->prepare("
        INSERT INTO cultural_activity_bans (
            student_id,
            ban_type,
            activity_id,
            ban_reason,
            is_active,
            banned_by
        ) VALUES (
            :student_id,
            'specific',
            :activity_id,
            :ban_reason,
            1,
            :banned_by
        )
    ");

    $ban_reason = sprintf('Restricted due to no-show: %s', $activity['program_name']);
    $banned_count = 0;

    foreach ($unchecked_students as $student) {
        if (isset($existing_banned_map[$student['student_id']])) {
            continue;
        }
        $insert_stmt->execute([
            'student_id' => $student['student_id'],
            'activity_id' => $activity_id,
            'ban_reason' => $ban_reason,
            'banned_by' => $admin_numeric_id
        ]);
        $banned_count++;
    }

    if ($banned_count === 0) {
        throw new Exception('대상 학생이 이미 제한되어 있습니다.');
    }

    $ip_address = null;
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip_address = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip_address = $_SERVER['HTTP_X_REAL_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'];
        $ip_address = is_string($forwarded) && strpos($forwarded, ',') !== false
            ? trim(explode(',', $forwarded)[0])
            : $forwarded;
    } else {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    }

    $log_stmt = $pdo->prepare("
        INSERT INTO cultural_activity_admin_logs (admin_id, activity_id, action, details, ip_address)
        VALUES (:admin_id, :activity_id, :action, :details, :ip_address)
    ");
    $log_details = json_encode([
        'banned_count' => $banned_count,
        'activity_id' => $activity_id,
        'program_name' => $activity['program_name']
    ], JSON_UNESCAPED_UNICODE);
    $log_stmt->execute([
        'admin_id' => $admin_login_id,
        'activity_id' => $activity_id,
        'action' => 'ban_unchecked_students',
        'details' => $log_details,
        'ip_address' => get_client_ip()
    ]);

    $pdo->commit();

    $_SESSION['me_success'] = sprintf('미출석 학생 %d명이 밴 처리되었습니다.', $banned_count);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['me_error'] = $e->getMessage();
}

header('Location: /Admin/ManageEnrollment/me-detail.php?id=' . $activity_id);
exit();
