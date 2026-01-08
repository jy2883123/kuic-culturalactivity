<?php
require_once '../../../config/config_student.php';

$student_id = $_SESSION['student_id'] ?? null;
$student_name = $_SESSION['student_name'] ?? 'Student';
$institution_role = $_SESSION['institution_role'] ?? 'Student';

if (!$student_id) {
    header('Location: /index.php');
    exit();
}

function redirect_with_message(int $activity_id, string $message, string $type = 'error'): void {
    if ($type === 'success') {
        $_SESSION['browse_activity_success'] = $message;
    } else {
        $_SESSION['browse_activity_error'] = $message;
    }
    header('Location: /Student/BrowseActivity/ba-detail.php?id=' . $activity_id);
    exit();
}

$activity_id = (int)($_GET['activity_id'] ?? $_POST['activity_id'] ?? 0);
if ($activity_id <= 0) {
    $_SESSION['browse_activity_error'] = 'Invalid activity ID.';
    header('Location: /Student/BrowseActivity/ba-index.php');
    exit();
}

$errors = [];
$requires_gown_size = false;
$selected_gown_size = null;

try {
    $activity_stmt = $pdo->prepare("
        SELECT
            id,
            program_name,
            program_description,
            activity_date,
            activity_time,
            location,
            requires_gown_size,
            gown_capacity_s,
            gown_capacity_m,
            gown_capacity_l,
            capacity,
            current_enrollment,
            has_fee,
            fee_amount,
            registration_start_date,
            registration_end_date,
            main_image_path,
            is_active,
            is_deleted
        FROM cultural_activities
        WHERE id = :id
        LIMIT 1
    ");
    $activity_stmt->execute(['id' => $activity_id]);
    $activity = $activity_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity || !empty($activity['is_deleted']) || !$activity['is_active']) {
        redirect_with_message($activity_id, 'This activity is not available for enrollment.');
    }
    $requires_gown_size = activity_requires_gown_size($activity);

    if (!$requires_gown_size) {
        $ban_stmt = $pdo->prepare("
            SELECT ban_reason, ban_type
            FROM cultural_activity_bans
            WHERE student_id = :student_id
              AND is_active = 1
            ORDER BY ban_type = 'all' DESC, banned_at DESC
            LIMIT 1
        ");
        $ban_stmt->execute(['student_id' => $student_id]);
        $ban_row = $ban_stmt->fetch(PDO::FETCH_ASSOC);
        if ($ban_row) {
            redirect_with_message(
                $activity_id,
                'You are not allowed to register for any activities: ' . ($ban_row['ban_reason'] ?? 'Restricted by administrator.')
            );
        }
    }

    // 기존 신청 확인 (cancelled 제외)
    $existing_stmt = $pdo->prepare("
        SELECT id, status, cancelled_by, gown_size FROM cultural_activity_enrollments
        WHERE activity_id = :activity_id AND student_id = :student_id
        LIMIT 1
    ");
    $existing_stmt->execute([
        'activity_id' => $activity_id,
        'student_id' => $student_id
    ]);
    $existing_enrollment = $existing_stmt->fetch(PDO::FETCH_ASSOC);
    $existing_gown_size = is_array($existing_enrollment) ? ($existing_enrollment['gown_size'] ?? null) : null;

    if ($existing_enrollment) {
        // cancelled 상태가 아니면 이미 신청된 것
        if ($existing_enrollment['status'] !== 'cancelled') {
            $status_message = $existing_enrollment['status'] === 'approved'
                ? 'You have already enrolled in this activity!'
                : 'You have a pending enrollment for this activity.';
            redirect_with_message($activity_id, $status_message, 'success');
        }

        // 관리자에 의해 취소된 경우 재신청 불가
        if ($existing_enrollment['status'] === 'cancelled' && $existing_enrollment['cancelled_by'] === 'admin') {
            redirect_with_message($activity_id, 'You cannot re-enroll in this activity. Your previous enrollment was cancelled by an administrator.');
        }

        // cancelled 상태 (학생이 직접 취소한 경우)면 재신청 가능 (아래 로직으로 계속)
    }
    $selected_gown_size = normalize_gown_size($_POST['gown_size'] ?? $existing_gown_size);

    $student_stmt = $pdo->prepare("
        SELECT applicant_name, email, origin_school, birthdate, nationality
        FROM uwayxlsx_current
        WHERE application_no = :application_no
        LIMIT 1
    ");
    $student_stmt->execute(['application_no' => $student_id]);
    $student_profile = $student_stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'applicant_name' => $student_name,
        'email' => '',
        'origin_school' => '',
        'birthdate' => '',
        'nationality' => ''
    ];
} catch (Exception $e) {
    redirect_with_message(
        $activity_id,
        'Failed to load application form: ' . $e->getMessage() . ' (line ' . $e->getLine() . ')'
    );
}

$seoulTz = new DateTimeZone('Asia/Seoul');
$now = new DateTime('now', $seoulTz);
$start_ts = (new DateTime($activity['registration_start_date'], $seoulTz))->getTimestamp();
$end_ts = (new DateTime($activity['registration_end_date'], $seoulTz))->getTimestamp();
$now_ts = $now->getTimestamp();

if ($now_ts < $start_ts) {
    redirect_with_message($activity_id, 'Registration has not opened yet.');
}
if ($now_ts > $end_ts) {
    redirect_with_message($activity_id, 'Registration period has ended.');
}
if (!is_null($activity['capacity']) && $activity['current_enrollment'] >= $activity['capacity']) {
    redirect_with_message($activity_id, 'This activity is already full.');
}

$agreements = [
    'agree_profile' => !empty($_POST['agree_profile']),
    'agree_policy' => !empty($_POST['agree_policy']),
    'agree_commitment' => !empty($_POST['agree_commitment'])
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 토큰 검증
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!csrf_validate_token($csrf_token)) {
        redirect_with_message($activity_id, 'Invalid security token. Please try again.');
    }

    if (!$agreements['agree_profile']) {
        $errors[] = 'Please confirm that your personal information is correct.';
    }
    if (!$agreements['agree_policy']) {
        $errors[] = 'Please agree to the KU ISC/IWC cultural activity policies.';
    }
    if (!$agreements['agree_commitment']) {
        $errors[] = 'Please acknowledge the attendance and cancellation commitment.';
    }
    if ($requires_gown_size && $selected_gown_size === null) {
        $errors[] = 'Please select your graduation gown size (S / M / L).';
    }

    if (empty($errors) && !$requires_gown_size) {
        $ban_check_stmt = $pdo->prepare("
            SELECT id FROM cultural_activity_bans
            WHERE student_id = :student_id
              AND is_active = 1
            LIMIT 1
        ");
        $ban_check_stmt->execute(['student_id' => $student_id]);
        if ($ban_check_stmt->fetch()) {
            $errors[] = 'You are currently restricted from registering for any activities.';
        }
    }

    if (empty($errors)) {
        try {
            $gown_size_value = $requires_gown_size ? $selected_gown_size : null;
            $pdo->beginTransaction();

            $lock_stmt = $pdo->prepare("
                SELECT capacity, current_enrollment, registration_start_date, registration_end_date
                     , requires_gown_size, gown_capacity_s, gown_capacity_m, gown_capacity_l
                FROM cultural_activities
                WHERE id = :id
                FOR UPDATE
            ");
            $lock_stmt->execute(['id' => $activity_id]);
            $locked_activity = $lock_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$locked_activity) {
                throw new Exception('Activity not found.');
            }

            $lock_start_ts = (new DateTime($locked_activity['registration_start_date'], $seoulTz))->getTimestamp();
            $lock_end_ts = (new DateTime($locked_activity['registration_end_date'], $seoulTz))->getTimestamp();
            $current_ts = (new DateTime('now', $seoulTz))->getTimestamp();

            if ($current_ts < $lock_start_ts || $current_ts > $lock_end_ts) {
                throw new Exception('Registration is not open at the moment.');
            }

            if (!is_null($locked_activity['capacity']) && $locked_activity['current_enrollment'] >= $locked_activity['capacity']) {
                throw new Exception('This activity is already full.');
            }

            // 기존 신청 확인 (cancelled 제외)
            $dup_stmt = $pdo->prepare("
                SELECT id, status FROM cultural_activity_enrollments
                WHERE activity_id = :activity_id AND student_id = :student_id
                LIMIT 1
            ");
            $dup_stmt->execute([
                'activity_id' => $activity_id,
                'student_id' => $student_id
            ]);
            $existing = $dup_stmt->fetch(PDO::FETCH_ASSOC);

            if ($requires_gown_size) {
                if ($gown_size_value === null) {
                    throw new Exception('Please select your graduation gown size.');
                }
                $capMap = [
                    'S' => $locked_activity['gown_capacity_s'] ?? null,
                    'M' => $locked_activity['gown_capacity_m'] ?? null,
                    'L' => $locked_activity['gown_capacity_l'] ?? null
                ];
                $size_cap = $capMap[$gown_size_value] ?? null;
                if (!is_null($size_cap)) {
                    $size_count_stmt = $pdo->prepare("
                        SELECT COUNT(*) FROM cultural_activity_enrollments
                        WHERE activity_id = :activity_id
                          AND gown_size = :gown_size
                          AND status = 'approved'
                        FOR UPDATE
                    ");
                    $size_count_stmt->execute([
                        'activity_id' => $activity_id,
                        'gown_size' => $gown_size_value
                    ]);
                    $size_count = (int)$size_count_stmt->fetchColumn();
                    if ($size_count >= (int)$size_cap) {
                        throw new Exception('The selected gown size is no longer available.');
                    }
                }
            }

            $student_name_value = $student_profile['applicant_name'] ?? $student_name;

            // IP 주소 추출 (Cloudflare 우선)
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

            if ($existing) {
                if ($existing['status'] === 'cancelled') {
                    // 취소된 신청을 재활용 (UPDATE)
                    $reactivate_stmt = $pdo->prepare("
                        UPDATE cultural_activity_enrollments
                        SET status = 'approved',
                            student_name = :student_name,
                            gown_size = :gown_size,
                            enrollment_type = 'student',
                            enrolled_at = NOW(),
                            fee_paid = 0,
                            checked_in = 0
                        WHERE id = :id
                    ");
                    $reactivate_stmt->execute([
                        'id' => $existing['id'],
                        'student_name' => $student_name_value,
                        'gown_size' => $gown_size_value
                    ]);

                    // 재신청 이력 기록
                    $history_stmt = $pdo->prepare("
                        INSERT INTO cultural_activity_enrollment_history
                        (enrollment_id, student_id, student_name, activity_id, action, ip_address)
                        VALUES (:enrollment_id, :student_id, :student_name, :activity_id, 're-enrolled', :ip_address)
                    ");
                    $history_stmt->execute([
                        'enrollment_id' => $existing['id'],
                        'student_id' => $student_id,
                        'student_name' => $student_name_value,
                        'activity_id' => $activity_id,
                        'ip_address' => $client_ip
                    ]);
                } else {
                    // approved, pending, rejected 상태는 재신청 불가
                    throw new Exception('You have already applied for this activity.');
                }
            } else {
                // 신규 신청 (INSERT)
                $insert_stmt = $pdo->prepare("
                    INSERT INTO cultural_activity_enrollments (
                        activity_id,
                        student_id,
                        student_name,
                        gown_size,
                        status,
                        enrollment_type,
                        enrolled_at
                    ) VALUES (
                        :activity_id,
                        :student_id,
                        :student_name,
                        :gown_size,
                        'approved',
                        'student',
                        NOW()
                    )
                ");
                $insert_stmt->execute([
                    'activity_id' => $activity_id,
                    'student_id' => $student_id,
                    'student_name' => $student_name_value,
                    'gown_size' => $gown_size_value
                ]);

                $enrollment_id = $pdo->lastInsertId();

                // 신규 신청 이력 기록
                $history_stmt = $pdo->prepare("
                    INSERT INTO cultural_activity_enrollment_history
                    (enrollment_id, student_id, student_name, activity_id, action, ip_address)
                    VALUES (:enrollment_id, :student_id, :student_name, :activity_id, 'enrolled', :ip_address)
                ");
                $history_stmt->execute([
                    'enrollment_id' => $enrollment_id,
                    'student_id' => $student_id,
                    'student_name' => $student_name_value,
                    'activity_id' => $activity_id,
                    'ip_address' => $client_ip
                ]);
            }

            $update_stmt = $pdo->prepare("UPDATE cultural_activities SET current_enrollment = current_enrollment + 1 WHERE id = :id");
            $update_stmt->execute(['id' => $activity_id]);

            $pdo->commit();

            $_SESSION['browse_activity_success'] = 'Your cultural activity enrollment has been completed.';
            header('Location: /Student/BrowseActivity/ba-detail.php?id=' . $activity_id);
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }
}

$formatted_date = date('F j, Y (l)', strtotime($activity['activity_date']));
$time_display = is_null($activity['activity_time']) ? 'Time TBD' : date('g:i A', strtotime($activity['activity_time']));
$capacity_text = is_null($activity['capacity']) ? 'Unlimited' : $activity['current_enrollment'] . ' / ' . $activity['capacity'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Apply | <?= htmlspecialchars($activity['program_name'], ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            --student-primary: #862633;
            --student-accent: #a53345;
            --bg-soft: #f6f8fb;
            --border-color: #d1dce8;
            --text-main: #2f2f2f;
            --text-muted: #7a7a7a;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
            background: var(--bg-soft);
            color: var(--text-main);
        }

        .container {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .header {
            background: linear-gradient(135deg, var(--student-primary), var(--student-accent));
            color: #ffffff;
            padding: 20px 32px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .header-content {
            max-width: 900px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .header-left {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .header-right a {
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            font-size: 0.9rem;
        }

        .header-left a:hover {
            text-decoration: underline;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .student-badge {
            padding: 6px 14px;
            background: rgba(255, 255, 255, 0.25);
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .btn-logout {
            padding: 8px 20px;
            background: rgba(255, 255, 255, 0.95);
            color: var(--student-primary);
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
        }

        main {
            flex: 1;
            max-width: 900px;
            width: 100%;
            margin: 0 auto;
            padding: 32px;
        }

        .card {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
            margin-bottom: 24px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .info-item {
            background: rgba(134, 38, 51, 0.05);
            border-radius: 12px;
            padding: 16px;
        }

        .info-label {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .info-value {
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            padding: 10px 12px;
            background: #f9fafb;
            font-size: 0.95rem;
        }

        .confirmations {
            margin-top: 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .confirmations label {
            display: flex;
            gap: 10px;
            font-size: 0.9rem;
            color: var(--text-main);
        }

        .btn-primary {
            width: 100%;
            padding: 12px 16px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            background: var(--student-primary);
            color: #ffffff;
            margin-top: 24px;
        }

        .alert-error {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 10px;
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        footer {
            text-align: center;
            padding: 20px;
            color: var(--text-muted);
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="header-content">
                <div class="header-left">
                    <h1>Enroll in <?= htmlspecialchars($activity['program_name'], ENT_QUOTES, 'UTF-8') ?></h1>
                    <span><?= htmlspecialchars($formatted_date, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="header-right">
                    <a href="/Student/BrowseActivity/ba-detail.php?id=<?= $activity_id ?>">← Back to Detail</a>
                </div>
            </div>
        </header>

        <main>
            <?php if (!empty($errors)): ?>
                <div class="alert-error">
                    <ul style="margin-left: 18px;">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="card">
                <h2 style="margin-bottom: 12px;">Program Summary</h2>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Location</div>
                        <div class="info-value"><?= htmlspecialchars($activity['location'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Registration Period</div>
                        <div class="info-value"><?= htmlspecialchars(date('M d, Y g:i A', $start_ts), ENT_QUOTES, 'UTF-8') ?> — <br><?= htmlspecialchars(date('M d, Y g:i A', $end_ts), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Participation Fee</div>
                        <div class="info-value">
                            <?php if ($activity['has_fee']): ?>
                                <?= number_format($activity['fee_amount']) ?> KRW (cash only)
                            <?php else: ?>
                                Free
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <form action="" method="POST" class="card">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8') ?>">
                <h2 style="margin-bottom: 12px;">Confirm Your Information</h2>
                <input type="hidden" name="activity_id" value="<?= $activity_id ?>" />
                <div class="form-group">
                    <label for="applicant_name">Legal Name</label>
                    <input type="text" id="applicant_name" value="<?= htmlspecialchars($student_profile['applicant_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" readonly />
                </div>
                <div class="form-group">
                    <label for="applicant_email">Email</label>
                    <input type="text" id="applicant_email" value="<?= htmlspecialchars($student_profile['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" readonly />
                </div>
                <div class="form-group">
                    <label for="origin_school">Home University</label>
                    <input type="text" id="origin_school" value="<?= htmlspecialchars($student_profile['origin_school'] ?? '', ENT_QUOTES, 'UTF-8') ?>" readonly />
                </div>
                <div class="form-group">
                    <label for="birthdate">Date of Birth</label>
                    <input type="text" id="birthdate" value="<?= htmlspecialchars($student_profile['birthdate'] ?? '', ENT_QUOTES, 'UTF-8') ?>" readonly />
                </div>
                <div class="form-group">
                    <label for="nationality">Nationality</label>
                    <input type="text" id="nationality" value="<?= htmlspecialchars($student_profile['nationality'] ?? '', ENT_QUOTES, 'UTF-8') ?>" readonly />
                </div>
                <?php if ($requires_gown_size): ?>
                <div class="form-group">
                    <label for="gown_size">Graduation Gown Size</label>
                    <select id="gown_size" name="gown_size" required>
                        <option value="">Select size</option>
                        <option value="S" <?= $selected_gown_size === 'S' ? 'selected' : '' ?>>S</option>
                        <option value="M" <?= $selected_gown_size === 'M' ? 'selected' : '' ?>>M</option>
                        <option value="L" <?= $selected_gown_size === 'L' ? 'selected' : '' ?>>L</option>
                    </select>
                    <span style="display:block; margin-top:6px; color: var(--text-muted); font-size:0.9rem;">
                        Please choose your gown size (S / M / L) for the graduation event.
                    </span>
                </div>
                <?php endif; ?>

                <div class="confirmations">
                    <label>
                        <input type="checkbox" name="agree_profile" value="1" <?= $agreements['agree_profile'] ? 'checked' : '' ?> />
                        I confirm that the information above is accurate.
                    </label>
                    <label>
                        <input type="checkbox" name="agree_policy" value="1" <?= $agreements['agree_policy'] ? 'checked' : '' ?> />
                        Applying for this cultural activity means agreeing to the <a href="<?= BASE_URL ?>Legal/cultural-activity-rule.html" target="_blank" style="color: #2563eb; text-decoration: underline;">KU ISC/IWC Cultural Activity Rules.</a>
                    </label>
                    <label>
                        <input type="checkbox" name="agree_commitment" value="1" <?= $agreements['agree_commitment'] ? 'checked' : '' ?> />
                        Applying for this cultural activity means agreeing to the <a href="<?= BASE_URL ?>Legal/privacy-policy.html" target="_blank" style="color: #2563eb; text-decoration: underline;">Privacy Policy</a> and <a href="<?= BASE_URL ?>Legal/terms-of-service.html" target="_blank" style="color: #2563eb; text-decoration: underline;">Terms of Service</a> of KU ISC/IWC.
                    </label>
                </div>

                <button type="submit" class="btn-primary">Submit</button>
            </form>
        </main>

        <footer>
            © DATANEST, KOREA UNIVERSITY – Int'l Summer &amp; Winter Campus
        </footer>
    </div>
</body>
</html>
