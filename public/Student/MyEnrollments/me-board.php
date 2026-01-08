<?php
require_once '../../../config/config_student.php';

$student_id = $_SESSION['student_id'] ?? null;
$student_name = $_SESSION['student_name'] ?? 'Student';
$activity_id = isset($_GET['activity_id']) ? (int)$_GET['activity_id'] : 0;
$attachments_by_post = [];

if (!$student_id || $activity_id <= 0) {
    header('Location: /Student/NoticeAttendance/na-index.php');
    exit();
}

try {
    $enrollment_stmt = $pdo->prepare("
        SELECT
            ca.id,
            ca.program_name,
            ca.activity_date,
            ca.activity_time,
            ca.location,
            ca.qr_code,
            ca.main_image_path,
            ca.registration_start_date,
            ca.registration_end_date,
            ca.cancellation_deadline,
            ca.has_fee,
            ca.fee_amount,
            ca.requires_gown_size,
            e.id AS enrollment_id,
            e.status,
            e.enrolled_at,
            e.cancelled_by,
            e.checked_in,
            e.check_in_time,
            e.gown_rented_at,
            e.gown_returned_at
        FROM cultural_activity_enrollments e
        INNER JOIN cultural_activities ca ON ca.id = e.activity_id
        WHERE e.activity_id = :activity_id
          AND e.student_id = :student_id
          AND e.status = 'approved'
          AND ca.is_deleted = FALSE
        LIMIT 1
    ");
    $enrollment_stmt->execute([
        'activity_id' => $activity_id,
        'student_id' => $student_id
    ]);
    $activity = $enrollment_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        $access_denied = true;
        $posts = [];
    } else {
        $access_denied = false;
        $posts_stmt = $pdo->prepare("
            SELECT id, title, body, is_pinned, author_name, created_at
            FROM cultural_activity_board_posts
            WHERE activity_id = :activity_id
            ORDER BY is_pinned DESC, created_at DESC
        ");
        $posts_stmt->execute(['activity_id' => $activity_id]);
        $posts = $posts_stmt->fetchAll(PDO::FETCH_ASSOC);

        $attachments_by_post = [];
        if (!empty($posts)) {
            try {
                $post_ids = array_column($posts, 'id');
                $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
                $files_stmt = $pdo->prepare("
                    SELECT post_id, file_name, file_path
                    FROM cultural_activity_board_files
                    WHERE post_id IN ($placeholders)
                    ORDER BY id ASC
                ");
                $files_stmt->execute($post_ids);
                $files = $files_stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($files as $file) {
                    $attachments_by_post[$file['post_id']][] = $file;
                }
            } catch (PDOException $attachError) {
                error_log('Student notice attachment load error: ' . $attachError->getMessage());
                $attachments_by_post = [];
            }
        }
    }
} catch (PDOException $e) {
    error_log('Notice board detail error: ' . $e->getMessage());
    $access_denied = true;
    $posts = [];
    $attachments_by_post = [];
}

$generateToken = function(array $activity, string $studentId): string {
    if (!empty($activity['qr_code'])) {
        return (string)$activity['qr_code'];
    }
    $seed = $activity['id'] . '|' . $studentId . '|' . ($activity['program_name'] ?? '');
    return hash('sha256', $seed);
};

$qr_url = '';
$qr_token = '';
$seoulTz = new DateTimeZone('Asia/Seoul');
$now = new DateTime('now', $seoulTz);
$registration_window = 'Not specified';
$cancellation_deadline_display = 'Until activity start time';
$fee_display = 'Free';
$can_cancel = false;
$cancel_button_label = 'Cancel Enrollment';

if (!$access_denied && $activity) {
    $qr_token = $generateToken($activity, $student_id);
    $qr_payload = sprintf(
        'CA-CHECKIN|ACT=%d|STU=%s|TOKEN=%s',
        $activity_id,
        $student_id,
        $qr_token
    );
    $encoded_payload = base64_encode($qr_payload);
    $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($encoded_payload);

    if (!empty($activity['registration_start_date']) && !empty($activity['registration_end_date'])) {
        $reg_start = new DateTime($activity['registration_start_date'], $seoulTz);
        $reg_end = new DateTime($activity['registration_end_date'], $seoulTz);
        $registration_window = $reg_start->format('F j, Y g:i A') . ' — ' . $reg_end->format('F j, Y g:i A');
    }

    if (!empty($activity['cancellation_deadline'])) {
        $cancel_deadline = new DateTime($activity['cancellation_deadline'], $seoulTz);
        $cancellation_deadline_display = $cancel_deadline->format('F j, Y g:i A');
    }

    if (!empty($activity['has_fee']) && $activity['has_fee']) {
        $fee_display = '₩' . number_format($activity['fee_amount']) . ' (cash payment at the office)';
    }

    $activity_date_obj = new DateTime($activity['activity_date'], $seoulTz);
    $is_past = $activity_date_obj < $now;
    $requires_gown = !empty($activity['requires_gown_size']);
    $has_rented_gown = !empty($activity['gown_rented_at']);
    $has_returned_gown = !empty($activity['gown_returned_at']);
    $is_checked_in = $requires_gown
        ? $has_rented_gown
        : (!empty($activity['checked_in']) && (int)$activity['checked_in'] === 1);
    $deadline_passed = false;
    if (!empty($activity['cancellation_deadline'])) {
        $deadline = new DateTime($activity['cancellation_deadline'], $seoulTz);
        $deadline_passed = $now > $deadline;
    }

    $can_cancel = ($activity['status'] ?? '') === 'approved' && !$is_past && !$is_checked_in && !$deadline_passed;

    if ($is_checked_in) {
        $cancel_button_label = $requires_gown ? 'Gown Rented' : 'Checked In';
    } elseif ($deadline_passed) {
        $cancel_button_label = 'Deadline Passed';
    } elseif ($is_past) {
        $cancel_button_label = 'Past Event';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="color-scheme" content="light" />
    <title>Notice Board | <?= htmlspecialchars($PAGE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="../css/global.css">
    <link rel="stylesheet" href="../css/me-board.css">
</head>
<body>
    <!-- Header -->
    <?php include __DIR__ . "/../includes/header.php"; ?>

    <main class="main-content na-board-main">
        <?php if ($access_denied): ?>
            <div class="alert warning">
                You do not have access to this detail page. Please check your enrollment status.
            </div>
            <div style="text-align: center; margin-top: 20px;">
                <a href="/Student/MyEnrollments/me-index.php" style="color: var(--ku-crimson); text-decoration: none; font-weight: 600;">← Back to My Enrollments</a>
            </div>
        <?php else: ?>
            <section class="activity-hero">
                <div class="hero-media" style="background-image: url('<?= htmlspecialchars($activity['main_image_path'], ENT_QUOTES, 'UTF-8') ?>');"></div>
                <?php if (!empty($activity['enrollment_id'])): ?>
                    <?php if ($can_cancel): ?>
                        <button
                            type="button"
                            class="hero-cancel-btn"
                            data-enrollment="<?= htmlspecialchars($activity['enrollment_id'], ENT_QUOTES, 'UTF-8') ?>"
                            data-program="<?= htmlspecialchars($activity['program_name'], ENT_QUOTES, 'UTF-8') ?>"
                            onclick="showCancelModal(this)">
                            Cancel Enrollment
                        </button>
                    <?php else: ?>
                        <button type="button" class="hero-cancel-btn hero-cancel-btn--disabled" disabled>
                            <?= htmlspecialchars($cancel_button_label, ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="hero-content">
                    <div class="hero-top">
                        <p class="hero-eyebrow">My Enrollments</p>
                    </div>
                    <h1><?= htmlspecialchars($activity['program_name'], ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="hero-meta">
                        📅 <?= htmlspecialchars(date('F j, Y', strtotime($activity['activity_date'])), ENT_QUOTES, 'UTF-8') ?>
                        · <?= htmlspecialchars(is_null($activity['activity_time']) ? 'Time TBD' : date('g:i A', strtotime($activity['activity_time'])), ENT_QUOTES, 'UTF-8') ?><br />
                        📍 <?= htmlspecialchars($activity['location'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <?php if ($requires_gown): ?>
                        <?php
                            if ($has_returned_gown) {
                                $gown_status = 'Gown returned';
                                $gown_class = 'status-complete';
                            } elseif ($has_rented_gown) {
                                $gown_status = 'Gown rented (return pending)';
                                $gown_class = 'status-pending';
                            } else {
                                $gown_status = 'Gown pickup pending';
                                $gown_class = 'status-pending';
                            }
                        ?>
                        <span class="hero-status <?= $gown_class ?>">
                            <?= htmlspecialchars($gown_status, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php else: ?>
                        <span class="hero-status <?= $activity['checked_in'] ? 'status-complete' : 'status-pending' ?>">
                            <?= $activity['checked_in'] ? 'Attendance confirmed' : 'Attendance pending' ?>
                        </span>
                    <?php endif; ?>
                </div>
            </section>

            <section class="summary-grid">
                <div class="summary-card">
                    <p class="summary-label">Location</p>
                    <p class="summary-value"><?= htmlspecialchars($activity['location'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="summary-card">
                    <p class="summary-label">Registration Period</p>
                    <p class="summary-value"><?= htmlspecialchars($registration_window, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="summary-card">
                    <p class="summary-label">Cancellation Deadline</p>
                    <p class="summary-value"><?= htmlspecialchars($cancellation_deadline_display, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="summary-card">
                    <p class="summary-label">Participation Fee</p>
                    <p class="summary-value"><?= htmlspecialchars($fee_display, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <?php if ($requires_gown): ?>
                    <div class="summary-card">
                        <p class="summary-label">Gown Status</p>
                        <p class="summary-value">
                            <?php if ($has_returned_gown): ?>
                                Returned
                            <?php elseif ($has_rented_gown): ?>
                                Rented (return pending)
                            <?php else: ?>
                                Pickup pending
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </section>

            <section class="board-layout">
                <div class="qr-card">
                    <h2>Check-in QR</h2>
                    <p>Show this QR to the staff on site for attendance. Bring your student ID for verification.</p>
                    <div class="qr-wrapper" data-darkreader-ignore>
                        <?php if ($qr_url): ?>
                            <canvas class="qr-canvas" width="260" height="260" data-darkreader-ignore aria-hidden="true"></canvas>
                            <img src="<?= $qr_url ?>" alt="Check-in QR" class="qr-image" data-darkreader-ignore />
                        <?php else: ?>
                            <span>No QR available</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($requires_gown): ?>
                        <?php if (!empty($activity['gown_rented_at'])): ?>
                            <p class="qr-note">Gown rented on <?= htmlspecialchars(date('M d, Y g:i A', strtotime($activity['gown_rented_at'])), ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                        <?php if (!empty($activity['gown_returned_at'])): ?>
                            <p class="qr-note">Gown returned on <?= htmlspecialchars(date('M d, Y g:i A', strtotime($activity['gown_returned_at'])), ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                        <?php if (!empty($activity['gown_rented_at']) && empty($activity['gown_returned_at'])): ?>
                            <p class="qr-note">Please note that failure to return your gown and cap after the ceremony may be considered a violation of university regulations and may result in administrative action.</p>
                        <?php endif; ?>
                    <?php elseif ($activity['checked_in'] && $activity['check_in_time']): ?>
                        <p class="qr-note">Checked in on <?= htmlspecialchars(date('M d, Y g:i A', strtotime($activity['check_in_time'])), ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                </div>

                <div class="notice-feed">
                    <?php if (empty($posts)): ?>
                        <div class="empty-posts">
                            No announcements have been posted yet. Please check back later.
                        </div>
                    <?php else: ?>
                        <?php foreach ($posts as $post): ?>
                            <article class="notice-card">
                                <div class="notice-header">
                                    <h3><?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                                    <?php if ($post['is_pinned']): ?>
                                        <span class="pin-label">Pinned</span>
                                    <?php endif; ?>
                                </div>
                                <div class="notice-meta">
                                    Posted by <?= htmlspecialchars($post['author_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8') ?> ·
                                    <?= htmlspecialchars(date('F j, Y g:i A', strtotime($post['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="notice-body">
                                    <?= nl2br(htmlspecialchars($post['body'], ENT_QUOTES, 'UTF-8')) ?>
                                </div>
                                <?php $post_files = $attachments_by_post[$post['id']] ?? []; ?>
                                <?php if (!empty($post_files)): ?>
                                    <div class="attachments">
                                        <h4>Attachments</h4>
                                        <ul>
                                            <?php foreach ($post_files as $file): ?>
                                                <li>
                                                    <a href="<?= htmlspecialchars($file['file_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                                                        <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>
                                                    </a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>
    <!-- Footer -->
    <?php include __DIR__ . "/../includes/footer.php"; ?>

    <?php if (!$access_denied && !empty($activity['enrollment_id'])): ?>
        <div id="cancelModal" class="modal">
            <div class="modal-content">
                <h3 class="modal-title">Cancel Enrollment</h3>
                <div class="modal-body">
                    <p>Are you sure you want to cancel your enrollment for <strong id="cancelProgramName"></strong>?</p>
                    <p style="margin-top: 12px; color: var(--text-muted); font-size: 0.9rem;">
                        This action cannot be undone. Your spot will be made available to other students.
                    </p>
                </div>
                <div class="modal-actions">
                    <form id="cancelForm" method="POST" action="/Student/MyEnrollments/me-cancel.php" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="enrollment_id" id="cancelEnrollmentId" />
                        <button type="submit" class="btn-secondary">Yes, Cancel</button>
                    </form>
                    <button type="button" class="btn-danger" onclick="hideCancelModal()">Keep Enrollment</button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        function showCancelModal(button) {
            const modal = document.getElementById('cancelModal');
            if (!modal || !button) {
                return;
            }
            document.getElementById('cancelProgramName').textContent = button.dataset.program || '';
            document.getElementById('cancelEnrollmentId').value = button.dataset.enrollment || '';
            modal.classList.add('active');
        }

        function hideCancelModal() {
            const modal = document.getElementById('cancelModal');
            if (modal) {
                modal.classList.remove('active');
            }
        }

        (function () {
            const modal = document.getElementById('cancelModal');
            if (!modal) {
                return;
            }
            modal.addEventListener('click', function (e) {
                if (e.target === modal) {
                    hideCancelModal();
                }
            });
        })();
    </script>
</body>
</html>
