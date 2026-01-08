<?php
// Load student configuration (session check included)
require_once '../../../config/config_student.php';

// Retrieve any error message passed from previous page
$browse_error = $_SESSION['browse_activity_error'] ?? '';
unset($_SESSION['browse_activity_error']);

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
            main_image_path,
            registration_start_date,
            registration_end_date
        FROM cultural_activities
        WHERE is_active = 1
          AND is_deleted = FALSE
          AND activity_date >= CURDATE()
        ORDER BY activity_date ASC, IFNULL(activity_time, '23:59:59') ASC, created_at DESC
    ");
    $stmt->execute();
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('BrowseActivity fetch error: ' . $e->getMessage());
    $activities = [];
    $browse_error = 'We could not load the activity list. Please try again in a moment.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Browse Activities | <?= htmlspecialchars($PAGE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="../css/global.css">
    <link rel="stylesheet" href="../css/ba-index.css">
</head>
<body>
    <!-- Header -->
    <?php include __DIR__ . "/../includes/header.php"; ?>

    <main class="main-content">
        <div class="page-header">
            <div>
                <p class="page-header-eyebrow">Browse Activities</p>
                <h1>Upcoming Activities</h1>
                <p>All scheduled activities appear here regardless of their registration period. Select a program to see details and learn how to sign up.</p>
            </div>
        </div>

        <?php if ($browse_error): ?>
            <div class="alert alert-error">
                <?= htmlspecialchars($browse_error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if (empty($activities)): ?>

            <div class="empty-state">
                <h3>No activities are available right now.</h3>
                <p>Check back soon—new cultural experiences will appear here as they are added.</p>
            </div>

        <?php else: ?>

            <div class="activities-grid">
                <?php foreach ($activities as $activity): ?>
                    <?php
                        $activity_date = date('F j, Y', strtotime($activity['activity_date']));
                        $time_display = is_null($activity['activity_time'])
                            ? 'Time TBD'
                            : date('g:i A', strtotime($activity['activity_time']));
                        $has_capacity = is_null($activity['capacity'])
                            || (int)$activity['current_enrollment'] < (int)$activity['capacity'];
                        $availability_text = $has_capacity ? 'Seats Available' : 'Full';
                        $availability_class = $has_capacity ? 'badge-open' : 'badge-full';
                    ?>
                    <a class="activity-card" href="/Student/BrowseActivity/ba-detail.php?id=<?= $activity['id'] ?>">
                        <img src="<?= htmlspecialchars($activity['main_image_path'], ENT_QUOTES, 'UTF-8') ?>"
                                alt="<?= htmlspecialchars($activity['program_name'], ENT_QUOTES, 'UTF-8') ?>"
                                class="activity-image" />
                        <div class="activity-body">
                            <span class="availability-badge <?= $availability_class ?>">
                                <?= htmlspecialchars($availability_text, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <h3 class="activity-title"><?= htmlspecialchars($activity['program_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <div class="activity-meta">
                                📅 <?= htmlspecialchars($activity_date, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8') ?><br />
                                📍 <?= htmlspecialchars($activity['location'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <p class="activity-meta" style="line-height: 1.6;">
                                <?= nl2br(htmlspecialchars(mb_strimwidth($activity['program_description'], 0, 160, '...', 'UTF-8'), ENT_QUOTES, 'UTF-8')) ?>
                            </p>
                            <span class="card-hint">View details →</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </main>

    <!-- Footer -->
    <?php include __DIR__ . "/../includes/footer.php"; ?>
</body>
</html>
