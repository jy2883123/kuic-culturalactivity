<?php
// Load student configuration (session check included)
require_once '../../../config/config_student.php';

$browse_notice = $_SESSION['browse_activity_notice'] ?? '';
unset($_SESSION['browse_activity_notice']);

$activity_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($activity_id <= 0) {
    $_SESSION['browse_activity_error'] = 'Invalid activity ID.';
    header('Location: /Student/BrowseActivity/ba-index.php');
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT
            id,
            program_name,
            program_description,
            activity_date,
            activity_time,
            location,
            capacity,
            current_enrollment,
            has_fee,
            fee_amount,
            registration_start_date,
            registration_end_date,
            cancellation_deadline,
            main_image_path
        FROM cultural_activities
        WHERE id = :id AND is_active = 1 AND is_deleted = FALSE
        LIMIT 1
    ");
    $stmt->execute(['id' => $activity_id]);
    $activity = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        $_SESSION['browse_activity_error'] = 'We could not find that activity or it is not available.';
        header('Location: /Student/BrowseActivity/ba-index.php');
        exit();
    }

    $img_stmt = $pdo->prepare("
        SELECT image_path
        FROM cultural_activity_images
        WHERE activity_id = :activity_id
        ORDER BY display_order ASC
    ");
    $img_stmt->execute(['activity_id' => $activity_id]);
    $gallery_images = $img_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Activity detail fetch error: ' . $e->getMessage());
    $_SESSION['browse_activity_error'] = 'There was a problem loading the activity information.';
    header('Location: /Student/BrowseActivity/ba-index.php');
    exit();
}

$activity_date = date('F j, Y', strtotime($activity['activity_date']));
$time_display = is_null($activity['activity_time']) ? 'Time TBD' : date('g:i A', strtotime($activity['activity_time']));
$capacity_text = is_null($activity['capacity'])
    ? 'Unlimited capacity'
    : number_format($activity['current_enrollment']) . ' / ' . number_format($activity['capacity']);

$success_message = $_SESSION['browse_activity_success'] ?? '';
$error_message = $_SESSION['browse_activity_error'] ?? '';
unset($_SESSION['browse_activity_success'], $_SESSION['browse_activity_error']);

$student_id = $_SESSION['student_id'] ?? null;
$already_enrolled = false;
$admin_cancelled = false;
$existing_enrollment = null;
if ($student_id) {
    $enrollment_stmt = $pdo->prepare("
        SELECT status, cancelled_by
        FROM cultural_activity_enrollments
        WHERE activity_id = :activity_id AND student_id = :student_id
        LIMIT 1
    ");
    $enrollment_stmt->execute([
        'activity_id' => $activity_id,
        'student_id' => $student_id
    ]);
    $existing_enrollment = $enrollment_stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing_enrollment) {
        // cancelled 상태가 아닌 경우만 이미 신청된 것으로 처리
        if ($existing_enrollment['status'] !== 'cancelled') {
            $already_enrolled = true;
        }
        // 관리자가 취소한 경우 재신청 불가
        if ($existing_enrollment['status'] === 'cancelled' && $existing_enrollment['cancelled_by'] === 'admin') {
            $admin_cancelled = true;
        }
    }
}

$seoulTz = new DateTimeZone('Asia/Seoul');
$now = new DateTime('now', $seoulTz);
$reg_start = new DateTime($activity['registration_start_date'], $seoulTz);
$reg_end = new DateTime($activity['registration_end_date'], $seoulTz);

$now_ts = $now->getTimestamp();
$start_ts = $reg_start->getTimestamp();
$end_ts = $reg_end->getTimestamp();

$registration_window = $reg_start->format('F j, Y g:i A') . ' — ' . $reg_end->format('F j, Y g:i A');
$time_until_open = null;
$countdown_seconds = null;
if ($now_ts < $start_ts) {
    $countdown_seconds = $start_ts - $now_ts;
    $days = floor($countdown_seconds / 86400);
    $hours = floor(($countdown_seconds % 86400) / 3600);
    $minutes = floor(($countdown_seconds % 3600) / 60);
    $seconds = $countdown_seconds % 60;
    $time_until_open = sprintf('%dday %02d:%02d:%02d', $days, $hours, $minutes, $seconds);
}

$registration_open = ($now_ts >= $start_ts && $now_ts <= $end_ts);
$has_space = is_null($activity['capacity']) || $activity['current_enrollment'] < $activity['capacity'];
$can_apply = $registration_open && $has_space;
$apply_button_text = 'Apply Now';
$apply_button_disabled = !$can_apply;
if (!$can_apply) {
    if (!$registration_open && $time_until_open) {
        $apply_button_text = $time_until_open . ' to open!';
    } elseif (!$registration_open) {
        $apply_button_text = 'Registration closed';
    } elseif (!$has_space) {
        $apply_button_text = 'Fully booked';
    }
}
$apply_button_url = '/Student/BrowseActivity/ba-apply.php?activity_id=' . urlencode($activity['id']);

if ($already_enrolled) {
    $apply_button_text = 'Already Enrolled';
    $apply_button_disabled = true;
}

if ($admin_cancelled) {
    $apply_button_text = 'Cancelled by Admin';
    $apply_button_disabled = true;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($activity['program_name'], ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars($PAGE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="../css/global.css">
    <link rel="stylesheet" href="../css/ba-detail.css">
</head>
<body>
    <!-- Header -->
    <?php include __DIR__ . "/../includes/header.php"; ?>

    <main class="main-content detail-main">
        <?php if ($success_message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <section class="detail-hero">
            <div class="hero-media" style="background-image: url('<?= htmlspecialchars($activity['main_image_path'], ENT_QUOTES, 'UTF-8') ?>');" aria-hidden="true"></div>
            <div class="hero-content">
                <p class="hero-eyebrow">Cultural Activity</p>
                <h1 class="hero-title"><?= htmlspecialchars($activity['program_name'], ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="hero-meta">
                    📅 <?= htmlspecialchars($activity_date, ENT_QUOTES, 'UTF-8') ?>
                    &nbsp;·&nbsp;
                    <?= htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8') ?>
                    <br/>📍 <?= htmlspecialchars($activity['location'], ENT_QUOTES, 'UTF-8') ?>
                </p>
                <div class="hero-tags">
                    <span class="status-pill <?= $has_space ? 'status-open' : 'status-closed' ?>">
                        <?= $has_space ? 'Seats Available' : 'Fully Booked' ?>
                    </span>
                    <span class="status-pill <?= $registration_open ? 'status-open' : 'status-closed' ?>">
                        <?= $registration_open ? 'Registration Available' : 'Registration Not Available' ?>
                    </span>
                </div>
            </div>
        </section>

        <section class="detail-summary">
            <div class="summary-card">
                <p class="summary-label">Location</p>
                <p class="summary-value"><?= htmlspecialchars($activity['location'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="summary-card">
                <p class="summary-label">Registration Window</p>
                <p class="summary-value"><?= htmlspecialchars($registration_window, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="summary-card">
                <p class="summary-label">Cancellation Deadline</p>
                <p class="summary-value">
                    <?php if (!empty($activity['cancellation_deadline'])): ?>
                        <?php
                            $cancel_deadline = new DateTime($activity['cancellation_deadline'], $seoulTz);
                            echo $cancel_deadline->format('F j, Y g:i A');
                        ?>
                    <?php else: ?>
                        Until activity start time
                    <?php endif; ?>
                </p>
            </div>
            <div class="summary-card">
                <p class="summary-label">Participation Fee</p>
                <p class="summary-value">
                    <?php if ($activity['has_fee']): ?>
                        ₩<?= number_format($activity['fee_amount']) ?> (cash payment at the office)
                    <?php else: ?>
                        Free
                    <?php endif; ?>
                </p>
            </div>
        </section>

        <section class="detail-layout">
            <article class="detail-description">
                <h2>About this activity</h2>
                <p><?= nl2br(htmlspecialchars($activity['program_description'], ENT_QUOTES, 'UTF-8')) ?></p>
            </article>

            <aside class="apply-panel">
                <h3>Apply to Join</h3>
                <p>Use the button below to submit your interest. Final confirmation will be handled by the program office. To cancel your application, visit <a style="color: var(--ku-crimson);" href="/Student/MyEnrollments/me-index.php">My Enrollments</a> page.</p>
                <div class="apply-status">
                    <?php if ($admin_cancelled): ?>
                        <span class="status-pill status-closed">Cancelled by Admin</span>
                    <?php elseif ($already_enrolled): ?>
                        <span class="status-pill status-open">Already Enrolled</span>
                    <?php elseif ($can_apply): ?>
                        <span class="status-pill status-open">Registration Available</span>
                    <?php else: ?>
                        <span class="status-pill status-closed">
                            <?= $registration_open ? 'Fully Booked' : 'Outside Registration Window' ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php if (!$already_enrolled): ?>
                    <button
                        type="button"
                        class="btn-primary apply-trigger"
                        data-apply-url="<?= htmlspecialchars($apply_button_url, ENT_QUOTES, 'UTF-8') ?>"
                        <?= $apply_button_disabled ? 'disabled' : '' ?>
                        <?php if ($countdown_seconds !== null && !$admin_cancelled): ?>
                            data-countdown="<?= $countdown_seconds ?>"
                        <?php endif; ?>>
                        <?= htmlspecialchars($apply_button_text, ENT_QUOTES, 'UTF-8') ?>
                    </button>
                <?php endif; ?>

                <?php if ($admin_cancelled): ?>
                    <div class="inline-alert alert-error">
                        Your enrollment was cancelled by an administrator. You cannot re-enroll in this activity.
                    </div>
                <?php endif; ?>
                <?php if ($browse_notice): ?>
                    <div class="inline-alert alert-info"><?= htmlspecialchars($browse_notice, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if (!$registration_open && !$already_enrolled): ?>
                    <p class="helper-text">
                        Registration is not open at this moment. Please check again during the enrollment window.
                    </p>
                <?php elseif (!$has_space && !$already_enrolled): ?>
                    <p class="helper-text">
                        The program is currently full. We will update this page if additional seats become available.
                    </p>
                <?php endif; ?>
            </aside>
        </section>

        <?php if (!empty($gallery_images)): ?>
            <section class="gallery-section" aria-label="Additional images (click to enlarge)">
                <div class="section-heading">
                    <h3>Gallery</h3>
                    <p>Tap any thumbnail to view it in full size.</p>
                </div>
                <div class="gallery">
                    <?php foreach ($gallery_images as $image): ?>
                        <img src="<?= htmlspecialchars($image['image_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Activity image" />
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <?php include __DIR__ . "/../includes/footer.php"; ?>
    <div class="lightbox" role="dialog" aria-modal="true">
        <span class="lightbox-close" aria-label="Close">×</span>
        <img src="" alt="Activity image enlarged" />
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const applyButton = document.querySelector('.apply-trigger');
            const statusTag = document.querySelector('.apply-panel .status-pill');

            if (applyButton) {
                const targetUrl = applyButton.dataset.applyUrl;

                applyButton.addEventListener('click', () => {
                    if (!applyButton.disabled && targetUrl) {
                        window.location.href = targetUrl;
                    }
                });

                if (applyButton.dataset.countdown) {
                    let remaining = parseInt(applyButton.dataset.countdown, 10);
                    if (!Number.isNaN(remaining) && remaining > 0) {
                        const formatTime = (seconds) => {
                            const days = Math.floor(seconds / 86400);
                            const hours = Math.floor((seconds % 86400) / 3600);
                            const minutes = Math.floor((seconds % 3600) / 60);
                            const secs = seconds % 60;
                            return `${days}day ${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
                        };

                        const updateButton = () => {
                            if (remaining <= 0) {
                                applyButton.textContent = 'Apply Now';
                                applyButton.disabled = false;
                                applyButton.removeAttribute('data-countdown');
                                if (statusTag) {
                                    statusTag.textContent = 'Enrollment Open';
                                    statusTag.classList.remove('status-closed');
                                    statusTag.classList.add('status-open');
                                }
                                return false;
                            }

                            applyButton.textContent = `${formatTime(remaining)} to open!`;
                            applyButton.disabled = true;
                            applyButton.setAttribute('data-countdown', String(remaining));
                            return true;
                        };

                        updateButton();
                        const interval = setInterval(() => {
                            remaining -= 1;
                            if (!updateButton()) {
                                clearInterval(interval);
                            }
                        }, 1000);
                    }
                }
            }

            // Lightbox for gallery images
            const galleryImages = document.querySelectorAll('.gallery img');
            const lightbox = document.querySelector('.lightbox');
            const lightboxImg = document.querySelector('.lightbox img');
            const lightboxClose = document.querySelector('.lightbox-close');

            if (galleryImages.length && lightbox && lightboxImg) {
                const closeLightbox = () => {
                    lightbox.style.display = 'none';
                    lightboxImg.src = '';
                };

                galleryImages.forEach((img) => {
                    img.addEventListener('click', () => {
                        lightboxImg.src = img.src;
                        lightbox.style.display = 'flex';
                    });
                });

                if (lightboxClose) {
                    lightboxClose.addEventListener('click', closeLightbox);
                }

                lightbox.addEventListener('click', (event) => {
                    if (event.target === lightbox) {
                        closeLightbox();
                    }
                });
            }
        });
    </script>
</body>
</html>
