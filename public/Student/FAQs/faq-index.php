<?php
require_once '../../../config/config_student.php';

$search_query = trim($_GET['q'] ?? '');

try {
    $params = [];
    $sql = "
        SELECT question, answer, display_order
        FROM cultural_activity_faqs
        WHERE is_active = 1
    ";

    if ($search_query !== '') {
        $sql .= " AND (question LIKE :search OR answer LIKE :search) ";
        $params['search'] = '%' . $search_query . '%';
    }

    $sql .= " ORDER BY display_order ASC, id ASC ";

    $faq_stmt = $pdo->prepare($sql);
    $faq_stmt->execute($params);
    $faqs = $faq_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $faqs = [];
    $error_message = 'Failed to load FAQs: ' . $e->getMessage();
}
$faq_count = isset($faqs) ? count($faqs) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Frequently Asked Questions | <?= htmlspecialchars($PAGE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="../css/global.css">
    <link rel="stylesheet" href="../css/faq-index.css">
</head>
<body>
    <!-- Header -->
    <?php include __DIR__ . "/../includes/header.php"; ?>

    <main class="main-content faq-main">
        <section class="faq-hero">
            <div>
                <p class="faq-eyebrow">Need Help?</p>
                <h1>Frequently Asked Questions</h1>
                <p>Find quick answers about eligibility, registration timelines, attendance rules, and event-day logistics for cultural activities.</p>
            </div>
        </section>

        <form class="faq-search" method="GET" action="/Student/FAQs/faq-index.php">
            <input type="text" name="q" value="<?= htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search questions or keywords" aria-label="Search FAQs" />
            <button type="submit">Search</button>
            <?php if ($search_query !== ''): ?>
                <a class="clear-search" href="/Student/FAQs/faq-index.php">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (!empty($error_message)): ?>
            <div class="faq-empty"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif (empty($faqs)): ?>
            <div class="faq-empty">No FAQs have been published yet.</div>
        <?php else: ?>
            <div class="faq-accordion">
                <?php foreach ($faqs as $index => $faq): ?>
                    <details class="faq-item" <?= $search_query !== '' && stripos($faq['question'], $search_query) !== false ? 'open' : '' ?>>
                        <summary>
                            <span><?= htmlspecialchars($faq['question'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="faq-toggle-icon">+</span>
                        </summary>
                        <div class="faq-answer">
                            <?= nl2br(htmlspecialchars($faq['answer'], ENT_QUOTES, 'UTF-8')) ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
    <!-- Footer -->
    <?php include __DIR__ . "/../includes/footer.php"; ?>
</body>
</html>
