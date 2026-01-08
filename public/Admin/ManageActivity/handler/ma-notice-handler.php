<?php
require_once '../../../../config/config_admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /Admin/ManageActivity/ma-index.php');
    exit();
}

// CSRF 토큰 검증
$csrf_token = $_POST['csrf_token'] ?? '';
if (!csrf_validate_token($csrf_token)) {
    $_SESSION['notice_error'] = '보안 토큰이 유효하지 않습니다. 다시 시도해주세요.';
    header('Location: /Admin/ManageActivity/ma-index.php');
    exit();
}

$activity_id = isset($_POST['activity_id']) ? (int)$_POST['activity_id'] : 0;
$action = $_POST['action'] ?? '';

if ($activity_id <= 0 || !in_array($action, ['create', 'delete', 'update'], true)) {
    $_SESSION['notice_error'] = '잘못된 요청입니다.';
    header('Location: /Admin/ManageActivity/ma-notice.php?id=' . $activity_id);
    exit();
}

try {
    $stored_files = [];
    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $author_name = trim($_POST['author_name'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $is_pinned = !empty($_POST['is_pinned']) ? 1 : 0;
        $stored_files = [];

        if ($title === '' || $body === '') {
            throw new Exception('제목과 내용을 모두 입력해주세요.');
        }

        if ($author_name === '') {
            $author_name = $_SESSION['admin_name'] ?? 'Admin';
        }

        $admin_login = $_SESSION['admin_id'] ?? null;
        $author_admin_id = null;
        if ($admin_login) {
            $lookup_stmt = $pdo->prepare("SELECT id FROM admins WHERE login_id = :login LIMIT 1");
            $lookup_stmt->execute(['login' => $admin_login]);
            $author_admin_id = $lookup_stmt->fetchColumn() ?: null;
        }

        if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
            $names = $_FILES['attachments']['name'];
            $tmp_names = $_FILES['attachments']['tmp_name'];
            $errors = $_FILES['attachments']['error'];
            $sizes = $_FILES['attachments']['size'];
            $allowed_extensions = ['pdf','doc','docx','ppt','pptx','xls','xlsx','zip','png','jpg','jpeg'];
            $max_files = 5;
            $max_size = 10 * 1024 * 1024; // 10MB

            $selected_count = 0;
            foreach ($names as $name) {
                if ($name !== '') {
                    $selected_count++;
                }
            }
            if ($selected_count > $max_files) {
                throw new Exception('첨부파일은 최대 5개까지 업로드할 수 있습니다.');
            }

            if ($selected_count > 0) {
                $upload_dir = ROOT_PATH . 'public/uploads/board_files/';
                if (!is_dir($upload_dir)) {
                    if (!mkdir($upload_dir, 0775, true) && !is_dir($upload_dir)) {
                        throw new Exception('첨부파일 디렉터리를 생성할 수 없습니다.');
                    }
                }

                foreach ($names as $index => $original_name) {
                    if ($original_name === '' || (int)$errors[$index] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    if ((int)$errors[$index] !== UPLOAD_ERR_OK) {
                        throw new Exception('파일 업로드 중 오류가 발생했습니다.');
                    }
                    if ($sizes[$index] > $max_size) {
                        throw new Exception('각 첨부파일은 10MB 이하만 업로드 가능합니다.');
                    }
                    $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                    if (!in_array($extension, $allowed_extensions, true)) {
                        throw new Exception('허용되지 않은 파일 형식이 포함되어 있습니다.');
                    }

                    $new_name = uniqid('board_', true) . '.' . $extension;
                    $destination = $upload_dir . $new_name;
                    if (!move_uploaded_file($tmp_names[$index], $destination)) {
                        throw new Exception('첨부파일을 저장할 수 없습니다.');
                    }

                    $stored_files[] = [
                        'file_name' => $original_name,
                        'file_path' => '/uploads/board_files/' . $new_name,
                        'file_size' => (int)$sizes[$index]
                    ];
                }
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO cultural_activity_board_posts (
                activity_id,
                title,
                body,
                is_pinned,
                author_admin_id,
                author_name
            ) VALUES (
                :activity_id,
                :title,
                :body,
                :is_pinned,
                :author_admin_id,
                :author_name
            )
        ");
        $stmt->execute([
            'activity_id' => $activity_id,
            'title' => $title,
            'body' => $body,
            'is_pinned' => $is_pinned,
            'author_admin_id' => $author_admin_id,
            'author_name' => $author_name
        ]);

        $post_id = (int)$pdo->lastInsertId();

        if (!empty($stored_files)) {
            $file_stmt = $pdo->prepare("
                INSERT INTO cultural_activity_board_files (post_id, file_name, file_path, file_size)
                VALUES (:post_id, :file_name, :file_path, :file_size)
            ");
            foreach ($stored_files as $file) {
                $file_stmt->execute([
                    'post_id' => $post_id,
                    'file_name' => $file['file_name'],
                    'file_path' => $file['file_path'],
                    'file_size' => $file['file_size']
                ]);
            }
        }

        // 관리자 로그 기록
        $admin_id = $_SESSION['admin_id'] ?? 'unknown';
        $client_ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        if (is_string($client_ip) && strpos($client_ip, ',') !== false) {
            $client_ip = trim(explode(',', $client_ip)[0]);
        }

        $log_stmt = $pdo->prepare("
            INSERT INTO cultural_activity_admin_logs (admin_id, activity_id, action, details, ip_address)
            VALUES (:admin_id, :activity_id, 'create_notice', :details, :ip_address)
        ");
        $log_stmt->execute([
            'admin_id' => $admin_id,
            'activity_id' => $activity_id,
            'details' => json_encode([
                'post_id' => $post_id,
                'title' => $title,
                'is_pinned' => $is_pinned,
                'attachments_count' => count($stored_files)
            ], JSON_UNESCAPED_UNICODE),
            'ip_address' => $client_ip
        ]);

        $_SESSION['notice_success'] = '공지가 등록되었습니다.';
    } elseif ($action === 'update') {
        $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
        $title = trim($_POST['title'] ?? '');
        $author_name = trim($_POST['author_name'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $is_pinned = !empty($_POST['is_pinned']) ? 1 : 0;
        $max_files = 5;
        $allowed_extensions = ['pdf','doc','docx','ppt','pptx','xls','xlsx','zip','png','jpg','jpeg'];
        $max_size = 10 * 1024 * 1024;

        if ($post_id <= 0) {
            throw new Exception('공지 ID가 올바르지 않습니다.');
        }
        if ($title === '' || $body === '') {
            throw new Exception('제목과 내용을 모두 입력해주세요.');
        }
        if ($author_name === '') {
            $author_name = $_SESSION['admin_name'] ?? 'Admin';
        }

        $post_check = $pdo->prepare("
            SELECT id FROM cultural_activity_board_posts
            WHERE id = :id AND activity_id = :activity_id
            LIMIT 1
        ");
        $post_check->execute([
            'id' => $post_id,
            'activity_id' => $activity_id
        ]);
        if (!$post_check->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception('공지 정보를 찾을 수 없습니다.');
        }

        $update_stmt = $pdo->prepare("
            UPDATE cultural_activity_board_posts
            SET title = :title,
                body = :body,
                author_name = :author_name,
                is_pinned = :is_pinned
            WHERE id = :id AND activity_id = :activity_id
        ");
        $update_stmt->execute([
            'title' => $title,
            'body' => $body,
            'author_name' => $author_name,
            'is_pinned' => $is_pinned,
            'id' => $post_id,
            'activity_id' => $activity_id
        ]);

        $delete_files = array_filter(array_map('intval', $_POST['delete_files'] ?? []));
        if (!empty($delete_files)) {
            $placeholders = implode(',', array_fill(0, count($delete_files), '?'));
            $params = array_merge([$post_id], $delete_files);
            $files_fetch = $pdo->prepare("
                SELECT id, file_path
                FROM cultural_activity_board_files
                WHERE post_id = ? AND id IN ($placeholders)
            ");
            $files_fetch->execute($params);
            $files_to_delete = $files_fetch->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($files_to_delete)) {
                $delete_stmt = $pdo->prepare("
                    DELETE FROM cultural_activity_board_files
                    WHERE post_id = ? AND id IN ($placeholders)
                ");
                $delete_stmt->execute(array_merge([$post_id], $delete_files));

                foreach ($files_to_delete as $file_row) {
                    if (empty($file_row['file_path'])) {
                        continue;
                    }
                    $full_path = ROOT_PATH . 'public' . (strpos($file_row['file_path'], '/') === 0 ? $file_row['file_path'] : '/' . $file_row['file_path']);
                    if (is_file($full_path)) {
                        @unlink($full_path);
                    }
                }
            }
        }

        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM cultural_activity_board_files WHERE post_id = :post_id");
        $count_stmt->execute(['post_id' => $post_id]);
        $current_count = (int)$count_stmt->fetchColumn();

        $remaining_slots = max(0, $max_files - $current_count);

        if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
            $names = $_FILES['attachments']['name'];
            $tmp_names = $_FILES['attachments']['tmp_name'];
            $errors = $_FILES['attachments']['error'];
            $sizes = $_FILES['attachments']['size'];

            $selected_count = 0;
            foreach ($names as $name) {
                if ($name !== '') {
                    $selected_count++;
                }
            }

            if ($selected_count > $remaining_slots) {
                throw new Exception('첨부파일은 최대 ' . $max_files . '개까지 업로드할 수 있습니다. (현재 ' . $current_count . '개)');
            }

            if ($selected_count > 0) {
                $upload_dir = ROOT_PATH . 'public/uploads/board_files/';
                if (!is_dir($upload_dir)) {
                    if (!mkdir($upload_dir, 0775, true) && !is_dir($upload_dir)) {
                        throw new Exception('첨부파일 디렉터리를 생성할 수 없습니다.');
                    }
                }

                foreach ($names as $index => $original_name) {
                    if ($original_name === '' || (int)$errors[$index] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    if ((int)$errors[$index] !== UPLOAD_ERR_OK) {
                        throw new Exception('파일 업로드 중 오류가 발생했습니다.');
                    }
                    if ($sizes[$index] > $max_size) {
                        throw new Exception('각 첨부파일은 10MB 이하만 업로드 가능합니다.');
                    }

                    $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                    if (!in_array($extension, $allowed_extensions, true)) {
                        throw new Exception('허용되지 않은 파일 형식이 포함되어 있습니다.');
                    }

                    $new_name = uniqid('board_', true) . '.' . $extension;
                    $destination = $upload_dir . $new_name;
                    if (!move_uploaded_file($tmp_names[$index], $destination)) {
                        throw new Exception('첨부파일을 저장할 수 없습니다.');
                    }

                    $stored_files[] = [
                        'file_name' => $original_name,
                        'file_path' => '/uploads/board_files/' . $new_name,
                        'file_size' => (int)$sizes[$index],
                        'post_id' => $post_id
                    ];
                }

                if (!empty($stored_files)) {
                    $file_stmt = $pdo->prepare("
                        INSERT INTO cultural_activity_board_files (post_id, file_name, file_path, file_size)
                        VALUES (:post_id, :file_name, :file_path, :file_size)
                    ");
                    foreach ($stored_files as $file) {
                        $file_stmt->execute([
                            'post_id' => $file['post_id'],
                            'file_name' => $file['file_name'],
                            'file_path' => $file['file_path'],
                            'file_size' => $file['file_size']
                        ]);
                    }
                    $stored_files = [];
                }
            }
        }

        // 관리자 로그 기록
        $admin_id = $_SESSION['admin_id'] ?? 'unknown';
        $client_ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        if (is_string($client_ip) && strpos($client_ip, ',') !== false) {
            $client_ip = trim(explode(',', $client_ip)[0]);
        }

        $log_stmt = $pdo->prepare("
            INSERT INTO cultural_activity_admin_logs (admin_id, activity_id, action, details, ip_address)
            VALUES (:admin_id, :activity_id, 'update_notice', :details, :ip_address)
        ");
        $log_stmt->execute([
            'admin_id' => $admin_id,
            'activity_id' => $activity_id,
            'details' => json_encode([
                'post_id' => $post_id,
                'title' => $title,
                'is_pinned' => $is_pinned,
                'deleted_files_count' => count($delete_files ?? []),
                'new_attachments_count' => count($stored_files)
            ], JSON_UNESCAPED_UNICODE),
            'ip_address' => $client_ip
        ]);

        $_SESSION['notice_success'] = '공지가 수정되었습니다.';
    } elseif ($action === 'delete') {
        $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
        if ($post_id <= 0) {
            throw new Exception('공지 ID가 올바르지 않습니다.');
        }

        $file_stmt = $pdo->prepare("SELECT file_path FROM cultural_activity_board_files WHERE post_id = :post_id");
        $file_stmt->execute(['post_id' => $post_id]);
        $attached_files = $file_stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        if (!empty($attached_files)) {
            $pdo->prepare("DELETE FROM cultural_activity_board_files WHERE post_id = :post_id")
                ->execute(['post_id' => $post_id]);
            foreach ($attached_files as $file_path) {
                if (!$file_path) {
                    continue;
                }
                $full_path = ROOT_PATH . 'public' . (strpos($file_path, '/') === 0 ? $file_path : '/' . $file_path);
                if (is_file($full_path)) {
                    @unlink($full_path);
                }
            }
        }

        $stmt = $pdo->prepare("DELETE FROM cultural_activity_board_posts WHERE id = :id AND activity_id = :activity_id");
        $stmt->execute(['id' => $post_id, 'activity_id' => $activity_id]);

        // 관리자 로그 기록
        $admin_id = $_SESSION['admin_id'] ?? 'unknown';
        $client_ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        if (is_string($client_ip) && strpos($client_ip, ',') !== false) {
            $client_ip = trim(explode(',', $client_ip)[0]);
        }

        $log_stmt = $pdo->prepare("
            INSERT INTO cultural_activity_admin_logs (admin_id, activity_id, action, details, ip_address)
            VALUES (:admin_id, :activity_id, 'delete_notice', :details, :ip_address)
        ");
        $log_stmt->execute([
            'admin_id' => $admin_id,
            'activity_id' => $activity_id,
            'details' => json_encode([
                'post_id' => $post_id,
                'deleted_files_count' => count($attached_files)
            ], JSON_UNESCAPED_UNICODE),
            'ip_address' => $client_ip
        ]);

        $_SESSION['notice_success'] = '공지가 삭제되었습니다.';
    }

} catch (Exception $e) {
    if (!empty($stored_files)) {
        foreach ($stored_files as $file) {
            $full_path = ROOT_PATH . 'public' . $file['file_path'];
            if (is_file($full_path)) {
                @unlink($full_path);
            }
        }
    }
    $_SESSION['notice_error'] = $e->getMessage();
}

header('Location: /Admin/ManageActivity/ma-notice.php?id=' . $activity_id);
exit();
