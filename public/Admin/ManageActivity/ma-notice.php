<?php
require_once '../../../config/config_admin.php';

$activity_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($activity_id <= 0) {
    header('Location: /Admin/ManageActivity/ma-index.php');
    exit();
}

try {
    $activity_stmt = $pdo->prepare("SELECT id, program_name FROM cultural_activities WHERE id = :id AND is_deleted = FALSE LIMIT 1");
    $activity_stmt->execute(['id' => $activity_id]);
    $activity = $activity_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        $_SESSION['ma_error'] = '프로그램을 찾을 수 없습니다.';
        header('Location: /Admin/ManageActivity/ma-index.php');
        exit();
    }

    $posts_stmt = $pdo->prepare("SELECT * FROM cultural_activity_board_posts WHERE activity_id = :activity_id ORDER BY is_pinned DESC, created_at DESC");
    $posts_stmt->execute(['activity_id' => $activity_id]);
    $posts = $posts_stmt->fetchAll(PDO::FETCH_ASSOC);

    $attachments_by_post = [];
    if (!empty($posts)) {
        try {
            $post_ids = array_column($posts, 'id');
            $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
            $files_stmt = $pdo->prepare("
                SELECT id, post_id, file_name, file_path, file_size
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
            error_log('Notice attachment load error: ' . $attachError->getMessage());
            $attachments_by_post = [];
        }
    }
} catch (PDOException $e) {
    error_log('Notice manage error: ' . $e->getMessage());
    $_SESSION['ma_error'] = '공지 데이터를 불러오는데 실패했습니다.';
    header('Location: /Admin/ManageActivity/ma-index.php');
    exit();
}

$success_message = $_SESSION['notice_success'] ?? '';
$error_message = $_SESSION['notice_error'] ?? '';
unset($_SESSION['notice_success'], $_SESSION['notice_error']);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8" />
    <title>공지 관리 | <?= htmlspecialchars($activity['program_name'], ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f6f8fb; margin: 0; }
        .container { max-width: 1100px; margin: 0 auto; padding: 32px 24px 48px; }
        h1 { font-size: 1.6rem; margin-bottom: 4px; }
        .subtitle { color: #6b7280; margin-bottom: 24px; }
        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; }
        .alert-success { background: #dcfce7; color: #15803d; }
        .alert-error { background: #fee2e2; color: #b91c1c; }
        .notice-form { background: #fff; border-radius: 18px; padding: 24px; box-shadow: 0 4px 16px rgba(0,0,0,0.08); margin-bottom: 24px; }
        .notice-form label { font-weight: 600; margin-bottom: 6px; display: block; }
        .notice-form input[type="text"], .notice-form textarea { width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid #d1d5db; font-family: inherit; }
        .notice-form textarea { min-height: 140px; resize: vertical; }
        .notice-form button { margin-top: 14px; padding: 10px 18px; border-radius: 10px; border: none; background: linear-gradient(135deg, #1a5490, #2563eb); color: #fff; font-weight: 600; cursor: pointer; }
        .notice-list article { background: #fff; border-radius: 18px; padding: 20px; box-shadow: 0 4px 16px rgba(0,0,0,0.08); margin-bottom: 16px; }
        .notice-list h3 { margin: 0 0 6px; }
        .notice-meta { color: #6b7280; font-size: 0.9rem; margin-bottom: 12px; }
        .notice-actions { display: flex; gap: 8px; }
        .notice-actions form { margin: 0; }
        .btn-delete { padding: 6px 12px; background: #fee2e2; color: #b91c1c; border-radius: 8px; border: none; cursor: pointer; }
    </style>
</head>
<body>
    <div class="container">
        <a href="/Admin/ManageActivity/ma-index.php" style="text-decoration:none; color:#2563eb;">← 목록으로</a>
        <h1><?= htmlspecialchars($activity['program_name'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="subtitle">공지 작성 및 관리</p>

        <?php if ($success_message): ?><div class="alert alert-success"><?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($error_message): ?><div class="alert alert-error"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <section class="notice-form">
            <form action="/Admin/ManageActivity/handler/ma-notice-handler.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="activity_id" value="<?= $activity_id ?>" />
                <input type="hidden" name="action" value="create" />
                <label for="title">제목</label>
                <input type="text" id="title" name="title" required />
                <label for="author_name" style="margin-top:14px;">작성자</label>
                <input type="text" id="author_name" name="author_name" placeholder="예: KU ISC Staff" />
                <label for="body" style="margin-top:14px;">내용</label>
                <textarea id="body" name="body" required></textarea>
                <label style="margin-top:14px; display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" name="is_pinned" value="1" />
                    상단 고정
                </label>
                <label for="attachments" style="margin-top:14px;">첨부파일 (최대 5개, 10MB 이하)</label>
                <input type="file" id="attachments" name="attachments[]" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.png,.jpg,.jpeg" />
                <small style="color:#6b7280;">PDF, Office 문서, 이미지, ZIP 파일을 업로드할 수 있습니다.</small>
                <button type="submit">공지 등록</button>
            </form>
        </section>

        <section class="notice-list">
            <?php if (empty($posts)): ?>
                <div class="alert" style="background:#fff; color:#6b7280;">등록된 공지가 없습니다.</div>
            <?php else: ?>
                <?php foreach ($posts as $post): ?>
                    <article>
                        <h3><?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?><?= $post['is_pinned'] ? ' · Pinned' : '' ?></h3>
                        <div class="notice-meta">
                            작성자: <?= htmlspecialchars($post['author_name'] ?? '관리자', ENT_QUOTES, 'UTF-8') ?> ·
                            작성일: <?= htmlspecialchars(date('Y-m-d H:i', strtotime($post['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <p style="white-space: pre-wrap; line-height:1.6;">
                            <?= nl2br(htmlspecialchars($post['body'], ENT_QUOTES, 'UTF-8')) ?>
                        </p>
                        <?php $post_files = $attachments_by_post[$post['id']] ?? []; ?>
                        <?php if (!empty($post_files)): ?>
                            <div style="margin-top:12px;">
                                <strong>첨부파일</strong>
                                <ul style="margin:8px 0 0 16px; color:#2563eb;">
                                    <?php foreach ($post_files as $file): ?>
                                        <li>
                                            <a href="<?= htmlspecialchars($file['file_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                                                <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                            <?php if (!empty($file['file_size'])): ?>
                                                <small style="color:#6b7280;">
                                                    (<?= number_format($file['file_size'] / 1024, 1) ?> KB)
                                                </small>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <details style="margin-top:16px;">
                            <summary style="cursor:pointer; color:#2563eb; font-weight:600;">공지 수정</summary>
                            <?php $existing_files = $attachments_by_post[$post['id']] ?? []; ?>
                            <?php $remaining_slots = max(0, 5 - count($existing_files)); ?>
                            <div style="margin-top:12px; background:#f9fafb; border-radius:12px; padding:16px;">
                                <form action="/Admin/ManageActivity/handler/ma-notice-handler.php"
                                      method="POST"
                                      enctype="multipart/form-data">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="activity_id" value="<?= $activity_id ?>" />
                                    <input type="hidden" name="action" value="update" />
                                    <input type="hidden" name="post_id" value="<?= $post['id'] ?>" />
                                    <label style="font-weight:600;">제목</label>
                                    <input type="text" name="title" value="<?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?>" required style="width:100%; padding:8px 10px; border-radius:8px; border:1px solid #d1d5db;" />
                                    <label style="font-weight:600; margin-top:10px;">작성자</label>
                                    <input type="text" name="author_name" value="<?= htmlspecialchars($post['author_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="width:100%; padding:8px 10px; border-radius:8px; border:1px solid #d1d5db;" />
                                    <label style="font-weight:600; margin-top:10px;">내용</label>
                                    <textarea name="body" required style="width:100%; min-height:120px; padding:10px; border-radius:10px; border:1px solid #d1d5db;"><?= htmlspecialchars($post['body'], ENT_QUOTES, 'UTF-8') ?></textarea>
                                    <label style="margin-top:10px; display:flex; align-items:center; gap:8px;">
                                        <input type="checkbox" name="is_pinned" value="1" <?= $post['is_pinned'] ? 'checked' : '' ?> />
                                        상단 고정
                                    </label>
                                    <?php if (!empty($existing_files)): ?>
                                        <div style="margin-top:12px;">
                                            <strong>첨부파일 관리</strong>
                                            <ul style="margin:8px 0 0 16px; color:#2563eb;">
                                                <?php foreach ($existing_files as $file): ?>
                                                    <li>
                                                        <a href="<?= htmlspecialchars($file['file_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                                                            <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>
                                                        </a>
                                                        <label style="margin-left:8px; color:#6b7280;">
                                                            <input type="checkbox" name="delete_files[]" value="<?= (int)$file['id'] ?>" />
                                                            삭제
                                                        </label>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                    <label style="margin-top:12px; display:block;">
                                        추가 첨부 (최대 <?= $remaining_slots > 0 ? $remaining_slots : 0 ?>개 가능)
                                    </label>
                                    <input type="file"
                                           name="attachments[]"
                                           multiple
                                           accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.png,.jpg,.jpeg"
                                           style="margin-bottom:4px;" />
                                    <small style="color:#6b7280;">현재 첨부: <?= count($existing_files) ?>/5</small>
                                    <button type="submit" style="margin-top:12px; padding:8px 16px; background:#1a5490; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">
                                        변경 사항 저장
                                    </button>
                                </form>
                            </div>
                        </details>
                        <div class="notice-actions" style="margin-top:16px;">
                            <form action="/Admin/ManageActivity/handler/ma-notice-handler.php" method="POST" onsubmit="return confirm('이 공지를 삭제하시겠습니까?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="activity_id" value="<?= $activity_id ?>" />
                                <input type="hidden" name="post_id" value="<?= $post['id'] ?>" />
                                <input type="hidden" name="action" value="delete" />
                                <button type="submit" class="btn-delete">삭제</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>
