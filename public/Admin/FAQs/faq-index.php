<?php
require_once '../../../config/config_admin.php';

$success_message = $_SESSION['faq_success'] ?? '';
$error_message = $_SESSION['faq_error'] ?? '';
unset($_SESSION['faq_success'], $_SESSION['faq_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $question = trim($_POST['question'] ?? '');
            $answer = trim($_POST['answer'] ?? '');
            $display_order = (int)($_POST['display_order'] ?? 0);

            if ($question === '' || $answer === '') {
                throw new Exception('질문과 답변을 모두 입력해주세요.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO cultural_activity_faqs (question, answer, display_order, is_active)
                VALUES (:question, :answer, :display_order, 1)
            ");
            $stmt->execute([
                'question' => $question,
                'answer' => $answer,
                'display_order' => $display_order
            ]);

            $_SESSION['faq_success'] = 'FAQ가 등록되었습니다.';
        } elseif ($action === 'update') {
            $faq_id = (int)($_POST['faq_id'] ?? 0);
            $question = trim($_POST['question'] ?? '');
            $answer = trim($_POST['answer'] ?? '');
            $display_order = (int)($_POST['display_order'] ?? 0);
            $is_active = !empty($_POST['is_active']) ? 1 : 0;

            if ($faq_id <= 0) {
                throw new Exception('FAQ 정보를 찾을 수 없습니다.');
            }
            if ($question === '' || $answer === '') {
                throw new Exception('질문과 답변을 모두 입력해주세요.');
            }

            $stmt = $pdo->prepare("
                UPDATE cultural_activity_faqs
                SET question = :question,
                    answer = :answer,
                    display_order = :display_order,
                    is_active = :is_active
                WHERE id = :id
            ");
            $stmt->execute([
                'question' => $question,
                'answer' => $answer,
                'display_order' => $display_order,
                'is_active' => $is_active,
                'id' => $faq_id
            ]);

            $_SESSION['faq_success'] = 'FAQ가 수정되었습니다.';
        } elseif ($action === 'delete') {
            $faq_id = (int)($_POST['faq_id'] ?? 0);
            if ($faq_id <= 0) {
                throw new Exception('FAQ 정보를 찾을 수 없습니다.');
            }
            $stmt = $pdo->prepare("DELETE FROM cultural_activity_faqs WHERE id = :id");
            $stmt->execute(['id' => $faq_id]);
            $_SESSION['faq_success'] = 'FAQ가 삭제되었습니다.';
        }
    } catch (Exception $e) {
        $_SESSION['faq_error'] = $e->getMessage();
    }

    header('Location: /Admin/FAQs/faq-index.php');
    exit();
}

try {
    $faq_stmt = $pdo->query("
        SELECT id, question, answer, display_order, is_active, updated_at
        FROM cultural_activity_faqs
        ORDER BY display_order ASC, id ASC
    ");
    $faqs = $faq_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $faqs = [];
    $error_message = 'FAQ 목록을 불러오는 중 오류가 발생했습니다: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>FAQ 관리 | <?= htmlspecialchars($PAGE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            --admin-primary: #1e40af;
            --admin-accent: #3b82f6;
            --bg-soft: #f6f8fb;
            --border-color: #d1dce8;
            --text-main: #1f2933;
            --text-muted: #6b7280;
            --success-green: #16a34a;
            --error-red: #dc2626;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { min-height: 100vh; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif; background: var(--bg-soft); color: var(--text-main); }
        .container { min-height: 100vh; display: flex; flex-direction: column; }
        .header {
            background: linear-gradient(135deg, var(--admin-primary), var(--admin-accent));
            color: #fff;
            padding: 20px 32px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: #fff;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
        }
        .main-content {
            flex: 1;
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
            padding: 32px;
        }
        .alert {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .alert-success { background: #dcfce7; color: var(--success-green); border: 1px solid var(--success-green); }
        .alert-error { background: #fee2e2; color: var(--error-red); border: 1px solid var(--error-red); }
        .section {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 24px;
        }
        .section-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--admin-primary);
            margin-bottom: 12px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
        }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        label { font-weight: 600; font-size: 0.9rem; }
        input[type="text"], textarea, input[type="number"] {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        textarea { min-height: 120px; resize: vertical; }
        .btn-primary {
            background: var(--admin-primary);
            color: #fff;
            padding: 12px 18px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
        }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 12px; border-bottom: 1px solid var(--border-color); text-align: left; font-size: 0.9rem; }
        th { text-transform: uppercase; font-size: 0.8rem; color: var(--text-muted); letter-spacing: 0.5px; }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-inactive { background: #f3f4f6; color: #9ca3af; }
        .action-buttons { display: flex; gap: 6px; flex-wrap: wrap; }
        .btn-secondary, .btn-danger {
            padding: 6px 10px;
            border-radius: 6px;
            border: none;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-secondary { background: #e5e7eb; color: #111827; }
        .btn-danger { background: #ef4444; color: #fff; }
        .edit-form {
            background: #f9fafb;
            border-radius: 12px;
            padding: 16px;
            margin-top: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="header-content">
                <a href="/Admin/dashboard.php" class="back-btn">← 대시보드</a>
                <h1>FAQ 관리</h1>
                <div>
                    <span><?= htmlspecialchars($admin_position, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
        </header>
        <main class="main-content">
            <?php if ($success_message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <section class="section">
                <h2 class="section-title">FAQ 작성</h2>
                <form method="POST" action="/Admin/FAQs/faq-index.php">
                    <input type="hidden" name="action" value="create" />
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="question">질문 *</label>
                            <input type="text" id="question" name="question" required />
                        </div>
                        <div class="form-group">
                            <label for="display_order">노출 순서</label>
                            <input type="number" id="display_order" name="display_order" value="0" />
                        </div>
                    </div>
                    <div class="form-group" style="margin-top: 12px;">
                        <label for="answer">답변 *</label>
                        <textarea id="answer" name="answer" required></textarea>
                    </div>
                    <button type="submit" class="btn-primary">FAQ 등록</button>
                </form>
            </section>

            <section class="section">
                <h2 class="section-title">FAQ 목록</h2>
                <?php if (empty($faqs)): ?>
                    <p style="color: var(--text-muted);">등록된 FAQ가 없습니다.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>질문</th>
                                <th>순서</th>
                                <th>상태</th>
                                <th>수정</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($faqs as $faq): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($faq['question'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                        <small>최근 수정: <?= htmlspecialchars($faq['updated_at'], ENT_QUOTES, 'UTF-8') ?></small>
                                    </td>
                                    <td><?= (int)$faq['display_order'] ?></td>
                                    <td>
                                        <?php if ((int)$faq['is_active'] === 1): ?>
                                            <span class="badge badge-active">활성</span>
                                        <?php else: ?>
                                            <span class="badge badge-inactive">비활성</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <details>
                                            <summary style="cursor:pointer;color:var(--admin-primary);font-weight:600;">수정</summary>
                                            <div class="edit-form">
                                                <form method="POST" action="/Admin/FAQs/faq-index.php">
                                                    <input type="hidden" name="action" value="update" />
                                                    <input type="hidden" name="faq_id" value="<?= (int)$faq['id'] ?>" />
                                                    <div class="form-group">
                                                        <label>질문</label>
                                                        <input type="text" name="question" value="<?= htmlspecialchars($faq['question'], ENT_QUOTES, 'UTF-8') ?>" required />
                                                    </div>
                                                    <div class="form-group">
                                                        <label>노출 순서</label>
                                                        <input type="number" name="display_order" value="<?= (int)$faq['display_order'] ?>" />
                                                    </div>
                                                    <div class="form-group">
                                                        <label>답변</label>
                                                        <textarea name="answer" required><?= htmlspecialchars($faq['answer'], ENT_QUOTES, 'UTF-8') ?></textarea>
                                                    </div>
                                                    <div class="form-group" style="flex-direction: row; align-items: center; gap: 8px;">
                                                        <input type="checkbox" id="active_<?= (int)$faq['id'] ?>" name="is_active" value="1" <?= (int)$faq['is_active'] === 1 ? 'checked' : '' ?> />
                                                        <label for="active_<?= (int)$faq['id'] ?>">활성화</label>
                                                    </div>
                                                    <div class="action-buttons">
                                                        <button type="submit" class="btn-secondary">저장</button>
                                                    </div>
                                                </form>
                                                <form method="POST" action="/Admin/FAQs/faq-index.php" onsubmit="return confirm('FAQ를 삭제할까요?');" style="margin-top:10px;">
                                                    <input type="hidden" name="action" value="delete" />
                                                    <input type="hidden" name="faq_id" value="<?= (int)$faq['id'] ?>" />
                                                    <button type="submit" class="btn-danger">삭제</button>
                                                </form>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </main>
        <footer style="background:#fff;border-top:1px solid var(--border-color);padding:20px 32px;text-align:center;color:var(--text-muted);font-size:0.85rem;">
            © DATANEST, KOREA UNIVERSITY – Int'l Summer &amp; Winter Campus
        </footer>
    </div>
</body>
</html>
