<?php
// Load student-specific config (includes session validation)
require_once '../../config/config_student.php';

$student_id = $_SESSION['student_id'] ?? null;
$recent_enrollments = [];
$featured_activities = [];
$enrollment_error = '';
$activity_error = '';

// Fetch a glimpse of the student's recent enrollments
if ($student_id) {
    try {
        $enrollment_stmt = $pdo->prepare("
            SELECT
                e.id AS enrollment_id,
                e.status,
                e.enrolled_at,
                ca.id AS activity_id,
                ca.program_name,
                ca.program_description,
                ca.activity_date,
                ca.activity_time,
                ca.location,
                ca.main_image_path
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
            LIMIT 3
        ");
        $enrollment_stmt->execute(['student_id' => $student_id]);
        $recent_enrollments = $enrollment_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Dashboard enrollment preview error: ' . $e->getMessage());
        $enrollment_error = 'We could not load your recent enrollments.';
    }
}

// Fetch a glimpse of upcoming activities
try {
    $activity_stmt = $pdo->query("
        SELECT
            id,
            program_name,
            program_description,
            activity_date,
            activity_time,
            location,
            main_image_path,
            capacity
        FROM cultural_activities
        WHERE is_active = 1
          AND is_deleted = FALSE
          AND activity_date >= CURDATE()
        ORDER BY activity_date ASC, IFNULL(activity_time, '23:59:59') ASC, created_at DESC
        LIMIT 4
    ");
    $featured_activities = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Dashboard activity preview error: ' . $e->getMessage());
    $activity_error = 'We could not load upcoming activities.';
}

$student_email = '';
if ($student_id) {
    try {
        $student_stmt = $pdo->prepare("
            SELECT email
            FROM uwayxlsx_current
            WHERE application_no = :application_no
            LIMIT 1
        ");
        $student_stmt->execute(['application_no' => $student_id]);
        $student_data = $student_stmt->fetch(PDO::FETCH_ASSOC);
        $student_email = $student_data['email'] ?? '';
    } catch (PDOException $e) {
        error_log('Dashboard student info error: ' . $e->getMessage());
        $student_email = '';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Student Dashboard | <?= htmlspecialchars($PAGE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <style>
        .temp-modal-backdrop {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgba(0, 0, 0, 0.4);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
            z-index: 999;
        }

        .temp-modal-backdrop.is-open {
            opacity: 1;
            pointer-events: auto;
        }

        .temp-modal {
            width: min(520px, 100%);
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            opacity: 0;
            transform: translateY(16px);
            transition: transform 0.25s ease, opacity 0.25s ease;
        }

        .temp-modal-stack {
            display: flex;
            gap: 16px;
            align-items: stretch;
            justify-content: center;
            width: 100%;
            max-width: 1100px;
        }

        .temp-modal-stack.is-single {
            justify-content: center;
        }

        .temp-modal-backdrop.is-open .temp-modal {
            opacity: 1;
            transform: translateY(0);
        }

        .temp-modal.is-hidden {
            opacity: 0;
            transform: translateY(16px);
            pointer-events: none;
            position: absolute;
        }

        .temp-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            gap: 16px;
        }

        .temp-modal-title {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            color: #111827;
        }

        .temp-modal-close {
            background: transparent;
            border: none;
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
            color: #6b7280;
        }

        .temp-modal-close:hover {
            color: #111827;
        }

        .temp-modal-body {
            padding: 16px 20px 20px;
            font-size: 15px;
            color: #374151;
            line-height: 1.6;
            flex: 1;
        }

        .temp-modal-footer {
            padding: 12px 20px 16px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            margin-top: auto;
        }

        .temp-modal-snooze {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #374151;
        }

        .temp-modal-snooze input {
            width: 14px;
            height: 14px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <?php include __DIR__ . "/includes/header.php"; ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Title -->
        <div class="page-header">
            <div>
                <h4><?= htmlspecialchars($student_id, ENT_QUOTES, 'UTF-8') ?></h4>
                <h1 class="page-greeting">Hello, <?= htmlspecialchars(rtrim(explode(' ', $student_name)[0], '.'), ENT_QUOTES, 'UTF-8') ?></h1>
                <?php if ($student_email): ?>
                    <p><?= htmlspecialchars($student_email, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </div>
            <p class="page-subtitle">Welcome to your Cultural Activity Dashboard</p>
        </div>

        <!-- Dashboard Cards -->
        <div class="dashboard-grid">
            <a class="dashboard-card dashboard-link" href="/Student/BrowseActivity/ba-index.php">
                <div class="card-icon-wrapper">
                    <span class="card-icon">📋</span>
                </div>
                <h3 class="card-title">Browse Activities</h3>
                <p class="card-description">Explore available cultural activities and programs offered this semester.</p>
            </a>

            <a class="dashboard-card dashboard-link" href="/Student/MyEnrollments/me-index.php">
                <div class="card-icon-wrapper">
                    <span class="card-icon">🧾</span>
                </div>
                <h3 class="card-title">My Enrollments</h3>
                <p class="card-description">View and manage your enrolled cultural activities and check enrollment status.</p>
            </a>

            <a class="dashboard-card dashboard-link" href="/Student/FAQs/faq-index.php">
                <div class="card-icon-wrapper">
                    <span class="card-icon">❓</span>
                </div>
                <h3 class="card-title">Frequently Asked Questions</h3>
                <p class="card-description">Find quick answers about cultural activity registration, attendance rules, and preparation tips.</p>
            </a>
        </div>

        <!-- Recent Enrollments Preview -->
        <section class="dashboard-section">
            <div class="section-header">
                <div>
                    <p class="section-eyebrow">My Cultural Activity Board</p>
                    <h2 class="section-title">Recently Applied Activities</h2>
                </div>
                <a class="section-link" href="/Student/MyEnrollments/me-index.php">Go to My Enrollments →</a>
            </div>

            <?php if ($enrollment_error): ?>
                <div class="section-alert">
                    <?= htmlspecialchars($enrollment_error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php elseif (empty($recent_enrollments)): ?>
                <div class="section-empty">
                    <p>You have not applied for any cultural activities yet.</p>
                    <a class="btn-text" href="/Student/BrowseActivity/ba-index.php">Browse Activities</a>
                </div>
            <?php else: ?>
                <div class="section-card-grid">
                    <?php foreach ($recent_enrollments as $enrollment):
                        $activity_date = $enrollment['activity_date']
                            ? date('M j, Y', strtotime($enrollment['activity_date']))
                            : 'Date TBD';
                        $time_display = $enrollment['activity_time']
                            ? date('g:i A', strtotime($enrollment['activity_time']))
                            : 'Time TBD';
                        $status_slug = preg_replace('/[^a-z]/', '-', strtolower($enrollment['status']));
                        ?>
                    <a class="preview-card" href="/Student/MyEnrollments/me-index.php#enrollment-<?= htmlspecialchars($enrollment['enrollment_id'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="preview-card-media" style="background-image: url('<?= htmlspecialchars($enrollment['main_image_path'] ?? '', ENT_QUOTES, 'UTF-8') ?>');"></div>
                            <div class="preview-card-body">
                                <div class="preview-card-top">
                                    <span class="status-pill status-<?= htmlspecialchars($status_slug, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(ucfirst($enrollment['status']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <time class="preview-date">Applied <?= htmlspecialchars(date('M j', strtotime($enrollment['enrolled_at'])), ENT_QUOTES, 'UTF-8') ?></time>
                                </div>
                                <h3 class="preview-title"><?= htmlspecialchars($enrollment['program_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <p class="preview-meta">📅 <?= htmlspecialchars($activity_date, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8') ?></p>
                                <span class="preview-location">📍 <?= htmlspecialchars($enrollment['location'], ENT_QUOTES, 'UTF-8') ?></span>
                                <p class="preview-description">
                                    <?= htmlspecialchars(mb_strimwidth($enrollment['program_description'] ?? '', 0, 120, '...', 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            </div>
                            <div class="preview-card-footer">
                                <span class="preview-link">View details →</span>
                            </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Featured Activities Preview -->
        <section class="dashboard-section">
            <div class="section-header">
                <div>
                    <p class="section-eyebrow">Discover What's Next</p>
                    <h2 class="section-title">Upcoming Activities To Explore</h2>
                </div>
                <a class="section-link" href="/Student/BrowseActivity/ba-index.php">Browse All Activities →</a>
            </div>

            <?php if ($activity_error): ?>
                <div class="section-alert">
                    <?= htmlspecialchars($activity_error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php elseif (empty($featured_activities)): ?>
                <div class="section-empty">
                    <p>No upcoming activities are available right now. Please check back soon.</p>
                </div>
            <?php else: ?>
                <div class="section-card-grid">
                    <?php foreach ($featured_activities as $activity):
                        $activity_date = $activity['activity_date']
                            ? date('M j, Y', strtotime($activity['activity_date']))
                            : 'Date TBD';
                        $time_display = $activity['activity_time']
                            ? date('g:i A', strtotime($activity['activity_time']))
                            : 'Time TBD';
                        ?>
                        <article class="preview-card">
                            <div class="preview-card-media" style="background-image: url('<?= htmlspecialchars($activity['main_image_path'] ?? '', ENT_QUOTES, 'UTF-8') ?>');"></div>
                            <div class="preview-card-body">
                                <div class="preview-card-top">
                                    <span class="status-pill status-open">Open</span>
                                    <time class="preview-date"><?= htmlspecialchars($activity_date, ENT_QUOTES, 'UTF-8') ?></time>
                                </div>
                                <h3 class="preview-title"><?= htmlspecialchars($activity['program_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <p class="preview-meta">📍 <?= htmlspecialchars($activity['location'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="preview-description">
                                    <?= htmlspecialchars(mb_strimwidth($activity['program_description'] ?? '', 0, 120, '...', 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            </div>
                            <div class="preview-card-footer">
                                <span class="preview-location">
                                    <?= $activity['capacity'] === null ? 'Unlimited seats' : 'Limited seats' ?>
                                </span>
                                <a class="preview-link" href="/Student/BrowseActivity/ba-detail.php?id=<?= htmlspecialchars($activity['id'], ENT_QUOTES, 'UTF-8') ?>">See activity</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <!-- <div id="temp-modal-backdrop" class="temp-modal-backdrop">
        <div id="temp-modal-stack" class="temp-modal-stack">
            <div class="temp-modal" role="dialog" aria-modal="true" aria-labelledby="temp-modal-title-1" data-cookie="dashboard_temp_modal_hidden_1">
                <div class="temp-modal-header">
                    <h3 id="temp-modal-title-1" class="temp-modal-title">📢 Women's Volleyball Registration Update</h3>
                    <button type="button" class="temp-modal-close" aria-label="Close modal">&times;</button>
                </div>
            <div class="temp-modal-body">
                <p>
                    All 100 seats have been fully booked.<br>
                    An additional 20 seats will open for registration on Wednesday(31) at 9:00 a.m.<br><br>

                    📅 Event Date: Tuesday, Jan 6<br>
                    🕔 Time: 19:00 ~
                </p>
            </div>
            <div class="temp-modal-footer">
                <label class="temp-modal-snooze">
                    <input type="checkbox" class="temp-modal-snooze-check">
                    Do not show again today
                </label>
            </div>
        </div> -->
        <div class="temp-modal" role="dialog" aria-modal="true" aria-labelledby="temp-modal-title-2" data-cookie="dashboard_temp_modal_hidden_2">
            <div class="temp-modal-header">
                <h3 id="temp-modal-title-2" class="temp-modal-title">📌 Notice for Banned Students</h3>
                    <button type="button" class="temp-modal-close" aria-label="Close modal">&times;</button>
                </div>
                <div class="temp-modal-body">
                    <p>
                        Students who have been banned due to no-shows are not allowed to apply for any cultural activities.<br>
                        They are also not eligible to apply for openings from remaining seats, and all previously registered activities will be canceled.
                        (Please refer to the Cultural Activity Operating Rules for details.)<br><br>
                        However, graduation ceremonies and gown rentals are part of a separate program, not cultural activities, so you may apply,
                        cancel, and participate in those events without restrictions.
                    </p>
                </div>
                <div class="temp-modal-footer">
                    <label class="temp-modal-snooze">
                        <input type="checkbox" class="temp-modal-snooze-check">
                        Do not show again today
                    </label>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php include __DIR__ . "/includes/footer.php"; ?>
    <script>
        (function () {
            const backdrop = document.getElementById('temp-modal-backdrop');
            const stack = document.getElementById('temp-modal-stack');
            const modals = Array.from(backdrop.querySelectorAll('.temp-modal'));

            let openTimer = null;
            const openModal = () => {
                if (backdrop.classList.contains('is-open')) {
                    return;
                }
                openTimer = window.setTimeout(() => {
                    backdrop.classList.add('is-open');
                }, 120);
            };
            const closeModal = () => backdrop.classList.remove('is-open');

            const setCookie = (name, value, days) => {
                const date = new Date();
                date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
                document.cookie = `${name}=${encodeURIComponent(value)}; expires=${date.toUTCString()}; path=/`;
            };

            const getCookie = (name) => {
                const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
                return match ? decodeURIComponent(match[1]) : null;
            };

            const updateLayout = () => {
                const visible = modals.filter((modal) => !modal.classList.contains('is-hidden'));
                if (visible.length === 0) {
                    closeModal();
                    return;
                }
                stack.classList.toggle('is-single', visible.length === 1);
                openModal();
            };

            modals.forEach((modal) => {
                const closeButton = modal.querySelector('.temp-modal-close');
                const snoozeCheck = modal.querySelector('.temp-modal-snooze-check');
                const cookieName = modal.getAttribute('data-cookie');

                if (cookieName && getCookie(cookieName)) {
                    modal.classList.add('is-hidden');
                }

                closeButton.addEventListener('click', () => {
                    if (cookieName && snoozeCheck && snoozeCheck.checked) {
                        setCookie(cookieName, '1', 1);
                    }
                    modal.classList.add('is-hidden');
                    updateLayout();
                });
            });

            backdrop.addEventListener('click', (event) => {
                if (event.target === backdrop) {
                    modals.forEach((modal) => modal.classList.add('is-hidden'));
                    updateLayout();
                }
            });

            updateLayout();
        })();
    </script>
</body>
</html>
