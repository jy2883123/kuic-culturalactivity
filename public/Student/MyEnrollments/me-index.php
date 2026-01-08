<?php
// 학생 전용 설정 파일 로드 (세션 검증 포함)
require_once '../../../config/config_student.php';

$student_id = $_SESSION['student_id'] ?? null;
$student_name = $_SESSION['student_name'] ?? 'Student';
$institution_role = $_SESSION['institution_role'] ?? 'Student';

// 성공/에러 메시지 처리
$success_message = $_SESSION['me_success'] ?? '';
$error_message = $_SESSION['me_error'] ?? '';
unset($_SESSION['me_success'], $_SESSION['me_error']);

// 내 신청 내역 조회
$debug_info = [];
try {
    $debug_info['student_id'] = $student_id;
    $debug_info['pdo_status'] = isset($pdo) ? 'Connected' : 'Not Connected';

    $enrollments_stmt = $pdo->prepare("
        SELECT
            e.id as enrollment_id,
            e.activity_id,
            e.status,
            e.enrolled_at,
            e.updated_at,
            e.checked_in,
            e.check_in_time,
            e.fee_paid,
            e.cancelled_by,
            e.admin_reason,
            e.gown_size,
            e.gown_rented_at,
            e.gown_returned_at,
            ca.program_name,
            ca.program_description,
            ca.activity_date,
            ca.activity_time,
            ca.location,
            ca.requires_gown_size,
            ca.capacity,
            ca.current_enrollment,
            ca.has_fee,
            ca.fee_amount,
            ca.main_image_path,
            ca.registration_start_date,
            ca.registration_end_date,
            ca.cancellation_deadline,
            ca.is_active,
            ca.is_deleted
        FROM cultural_activity_enrollments e
        INNER JOIN cultural_activities ca ON e.activity_id = ca.id
        WHERE e.student_id = :student_id
        ORDER BY
            CASE e.status
                WHEN 'approved' THEN 1
                WHEN 'pending' THEN 2
                WHEN 'cancelled' THEN 3
                WHEN 'rejected' THEN 4
                ELSE 5
            END,
            ca.activity_date ASC,
            e.enrolled_at DESC
    ");

    $debug_info['prepare_status'] = 'Success';
    $enrollments_stmt->execute(['student_id' => $student_id]);
    $debug_info['execute_status'] = 'Success';

    $enrollments = $enrollments_stmt->fetchAll(PDO::FETCH_ASSOC);
    $debug_info['enrollment_count'] = count($enrollments);

    // 각 신청에 대한 이력 조회
    $history_stmt = $pdo->prepare("
        SELECT action, action_details, ip_address, created_at
        FROM cultural_activity_enrollment_history
        WHERE enrollment_id = :enrollment_id
        ORDER BY created_at ASC
    ");

    foreach ($enrollments as &$enrollment) {
        $history_stmt->execute(['enrollment_id' => $enrollment['enrollment_id']]);
        $enrollment['history'] = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($enrollment); // 참조 해제
} catch (PDOException $e) {
    error_log('My Enrollments fetch error: ' . $e->getMessage());
    error_log('Error details: ' . json_encode([
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]));

    $error_message = 'Failed to load your enrollments. Please try again later.';
    $error_details = [
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    $debug_info['error'] = $e->getMessage();
    $debug_info['error_code'] = $e->getCode();
    $enrollments = [];
}

// 현재 서울 시간
$seoulTz = new DateTimeZone('Asia/Seoul');
$now = new DateTime('now', $seoulTz);
$total_enrollments = count($enrollments);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Enrollments | <?= htmlspecialchars($PAGE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="../css/global.css">
    <link rel="stylesheet" href="../css/me-index.css">
</head>
<body>
    <!-- Header -->
    <?php include __DIR__ . "/../includes/header.php"; ?>

        <!-- Main Content -->
        <main class="main-content me-main">
            <section class="me-hero">
                <div>
                    <p class="me-eyebrow">Check Your Registrations</p>
                    <h1>My Enrollments</h1>
                    <p>Track every cultural activity you have signed up for. Manage cancellations, review timelines, and jump to program details.</p>
                </div>
                <div class="me-highlight">
                    <span class="highlight-count"><?= $total_enrollments ?></span>
                    <span class="highlight-label">Total Enrollments</span>
                </div>
            </section>

            <?php if ($success_message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <strong>Error:</strong> <?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?>

                    <?php if (isset($error_details)): ?>
                        <details style="margin-top: 12px; padding: 12px; background: rgba(0,0,0,0.05); border-radius: 6px;">
                            <summary style="cursor: pointer; font-weight: 600; margin-bottom: 8px;">상세 에러 정보 보기</summary>
                            <div style="font-family: monospace; font-size: 0.85rem; white-space: pre-wrap;">
                                <strong>Message:</strong> <?= htmlspecialchars($error_details['message'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                <br><strong>Code:</strong> <?= htmlspecialchars($error_details['code'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                <br><strong>File:</strong> <?= htmlspecialchars($error_details['file'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                <br><strong>Line:</strong> <?= htmlspecialchars($error_details['line'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                <br><br><strong>Stack Trace:</strong>
                                <br><?= htmlspecialchars($error_details['trace'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </details>
                    <?php endif; ?>

                    <?php if (!empty($debug_info)): ?>
                        <details style="margin-top: 12px; padding: 12px; background: rgba(0,0,0,0.05); border-radius: 6px;">
                            <summary style="cursor: pointer; font-weight: 600; margin-bottom: 8px;">디버그 정보 보기</summary>
                            <div style="font-family: monospace; font-size: 0.85rem;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <?php foreach ($debug_info as $key => $value): ?>
                                        <tr style="border-bottom: 1px solid rgba(0,0,0,0.1);">
                                            <td style="padding: 4px 8px; font-weight: 600; width: 40%;"><?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?></td>
                                            <td style="padding: 4px 8px;"><?= htmlspecialchars(is_array($value) ? json_encode($value) : $value, ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($enrollments)): ?>
                <!-- Empty State -->
                <div class="empty-state">
                    <div class="empty-icon">📋</div>
                    <h3 class="empty-title">No Enrollments Yet</h3>
                    <p class="empty-description">You haven't registered for any cultural activities yet. Browse available activities and enroll to get started!</p>
                    <a href="/Student/BrowseActivity/ba-index.php" class="btn-primary">Browse Activities</a>
                </div>
            <?php else: ?>
                <!-- Enrollments Grid -->
                <div class="enrollments-grid">
                    <?php foreach ($enrollments as $enrollment): ?>
                        <?php
                            $activity_date = new DateTime($enrollment['activity_date'], $seoulTz);
                            $is_past = $activity_date < $now;
                            $time_display = is_null($enrollment['activity_time']) ? 'Time TBD' : date('g:i A', strtotime($enrollment['activity_time']));
                            $formatted_date = $activity_date->format('F j, Y (l)');

                            // 신청 취소 가능 여부 (체크인 전, 승인 상태, 과거 이벤트 아닐 때, 취소 기한 내일 때만)
                            $requires_gown = !empty($enrollment['requires_gown_size']);
                            $has_rented_gown = !empty($enrollment['gown_rented_at']);
                            $has_returned_gown = !empty($enrollment['gown_returned_at']);
                            $is_checked_in = $requires_gown
                                ? $has_rented_gown
                                : (!empty($enrollment['checked_in']) && (int)$enrollment['checked_in'] === 1);
                            $deadline_passed = false;
                            if (!empty($enrollment['cancellation_deadline'])) {
                                $deadline = new DateTime($enrollment['cancellation_deadline'], $seoulTz);
                                $deadline_passed = $now > $deadline;
                            }
                            $can_cancel = ($enrollment['status'] === 'approved') && !$is_past && !$is_checked_in && !$deadline_passed;

                            // 상태 표시
                            $status_class = 'badge-' . $enrollment['status'];
                            $status_text = ucfirst($enrollment['status']);
                        ?>
                        <article class="enrollment-card">
                            <div class="enrollment-media">
                                <img src="<?= htmlspecialchars($enrollment['main_image_path'], ENT_QUOTES, 'UTF-8') ?>"
                                     alt="<?= htmlspecialchars($enrollment['program_name'], ENT_QUOTES, 'UTF-8') ?>" />
                                <span class="status-pill <?= htmlspecialchars($status_class, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($status_text, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                            <div class="enrollment-body">
                                <div class="enrollment-top">
                                    <h3><?= htmlspecialchars($enrollment['program_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                    <p><?= htmlspecialchars($formatted_date, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <p class="enrollment-location">📍 <?= htmlspecialchars($enrollment['location'], ENT_QUOTES, 'UTF-8') ?></p>
                                <?php if (!empty($enrollment['requires_gown_size'])): ?>
                                    <p class="enrollment-location">🎓 Gown Size: <?= htmlspecialchars($enrollment['gown_size'] ?? '-', ENT_QUOTES, 'UTF-8') ?></p>
                                <?php endif; ?>
                                <?php if ($enrollment['has_fee']): ?>
                                    <p class="enrollment-fee">💳 Fee: ₩<?= number_format($enrollment['fee_amount']) ?></p>
                                <?php endif; ?>

                                <div class="badge-row">
                                    <?php if ($is_past): ?>
                                        <span class="meta-badge badge-neutral">Past Event</span>
                                    <?php endif; ?>
                                    <?php if ($enrollment['status'] === 'cancelled' && $enrollment['updated_at']): ?>
                                        <span class="meta-badge badge-warning">Cancelled on <?= date('M j, Y', strtotime($enrollment['updated_at'])) ?></span>
                                    <?php endif; ?>
                                <?php if ($enrollment['has_fee'] && $enrollment['fee_paid']): ?>
                                    <span class="meta-badge badge-success">Fee Paid</span>
                                <?php endif; ?>
                                <?php if ($requires_gown): ?>
                                    <?php if ($has_returned_gown): ?>
                                        <span class="meta-badge badge-success">Gown Returned</span>
                                    <?php elseif ($has_rented_gown): ?>
                                        <span class="meta-badge badge-warning">Gown Rented</span>
                                    <?php else: ?>
                                        <span class="meta-badge badge-neutral">Gown Pickup Pending</span>
                                    <?php endif; ?>
                                <?php elseif ($enrollment['checked_in']): ?>
                                    <span class="meta-badge badge-success">Checked In</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($enrollment['status'] === 'cancelled' && $enrollment['cancelled_by'] === 'admin' && !empty($enrollment['admin_reason'])): ?>
                                <div class="admin-alert">
                                    <strong>Cancelled by Administrator</strong>
                                        <span>Reason: <?= htmlspecialchars($enrollment['admin_reason'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($enrollment['requires_gown_size'])): ?>
                                    <p style="margin-top:8px; color: #6b7280; font-size: 0.92rem;">
                                        If you need to adjust your gown size, please cancel this enrollment and re-apply.
                                    </p>
                                <?php if ($has_rented_gown && !$has_returned_gown): ?>
                                    <p style="margin-top:6px; color: #b45309; font-size: 0.92rem;">
                                        Gown rental is active. Please return the gown to complete the process.
                                    </p>
                                    <p style="margin-top:6px; color: #b45309; font-size: 0.92rem;">
                                        Please note that failure to return your gown and cap after the ceremony may be considered a violation of university regulations and may result in administrative action.
                                    </p>
                                <?php elseif ($has_returned_gown): ?>
                                    <p style="margin-top:6px; color: #047857; font-size: 0.92rem;">
                                        Gown return has been completed. Thank you!
                                    </p>
                                <?php endif; ?>
                            <?php endif; ?>

                                <div class="enrollment-actions">
                                    <a href="/Student/MyEnrollments/me-board.php?activity_id=<?= $enrollment['activity_id'] ?>" class="btn-secondary">View Details</a>
                                </div>

                                <?php if (!empty($enrollment['history'])): ?>
                                    <div class="history-section">
                                        <button type="button" class="history-toggle" onclick="toggleHistory(<?= $enrollment['enrollment_id'] ?>, this)">
                                            <span class="history-toggle-icon">▶</span>
                                            <span>View Enrollment History (<?= count($enrollment['history']) ?> events)</span>
                                        </button>
                                        <div id="history-<?= $enrollment['enrollment_id'] ?>" class="history-timeline">
                                            <?php foreach ($enrollment['history'] as $history): ?>
                                                <?php
                                                    $raw_action = $history['action'];
                                                    $detail_action = $history['action_details'] ?? '';
                                                    $use_detail = $raw_action === 'checkin'
                                                        && in_array($detail_action, ['gown-rented', 'gown-returned'], true);
                                                    $display_action = $use_detail ? $detail_action : $raw_action;
                                                    $action_display = ucfirst(str_replace('-', ' ', $display_action));
                                                    $action_class = str_replace('-', '_', $display_action);
                                                    $history_date = new DateTime($history['created_at'], $seoulTz);
                                                    $formatted_history_date = $history_date->format('M j, Y g:i A');
                                                ?>
                                                <div class="history-item">
                                                    <div class="history-action <?= htmlspecialchars($action_class, ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= htmlspecialchars($action_display, ENT_QUOTES, 'UTF-8') ?>
                                                    </div>
                                                    <div class="history-details">
                                                        <?= htmlspecialchars($formatted_history_date, ENT_QUOTES, 'UTF-8') ?>
                                                        <?php if ($history['ip_address']): ?>
                                                            • IP: <?= htmlspecialchars($history['ip_address'], ENT_QUOTES, 'UTF-8') ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>

        <!-- Footer -->
        <?php include __DIR__ . "/../includes/footer.php"; ?>

    <script>
        function toggleHistory(enrollmentId, trigger) {
            const timelineId = 'history-' + enrollmentId;
            const timeline = document.getElementById(timelineId);
            if (!timeline) {
                return;
            }

            document.querySelectorAll('.history-timeline').forEach((node) => {
                if (node.id !== timelineId) {
                    node.classList.remove('active');
                }
            });

            document.querySelectorAll('.history-toggle').forEach((button) => {
                if (button !== trigger) {
                    button.classList.remove('active');
                }
            });

            timeline.classList.toggle('active');
            if (trigger) {
                trigger.classList.toggle('active');
            }
        }
    </script>
</body>
</html>
