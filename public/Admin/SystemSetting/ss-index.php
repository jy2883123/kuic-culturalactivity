<?php
require_once '../../../config/config_admin.php';

$success_message = $_SESSION['ss_success'] ?? '';
$error_message = $_SESSION['ss_error'] ?? '';
unset($_SESSION['ss_success'], $_SESSION['ss_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_token') {
            $label = trim($_POST['label'] ?? '');
            $expires_input = trim($_POST['expires_at'] ?? '');

            if ($label === '') {
                throw new Exception('토큰 설명을 입력하세요.');
            }

            $expires_at = null;
            if ($expires_input !== '') {
                $expires_dt = DateTime::createFromFormat('Y-m-d\TH:i', $expires_input);
                if (!$expires_dt) {
                    throw new Exception('만료일시 형식이 올바르지 않습니다.');
                }
                $expires_at = $expires_dt->format('Y-m-d H:i:s');
            }

            $token_value = bin2hex(random_bytes(32));

            $stmt = $pdo->prepare("
                INSERT INTO cultural_activity_checkin_tokens (token, label, expires_at, is_active, created_by)
                VALUES (:token, :label, :expires_at, 1, :created_by)
            ");
            $stmt->execute([
                'token' => $token_value,
                'label' => $label,
                'expires_at' => $expires_at,
                'created_by' => $_SESSION['admin_id'] ?? null
            ]);

            // 관리자 로그 기록
            $admin_id = $_SESSION['admin_id'] ?? 'unknown';

            $log_stmt = $pdo->prepare("
                INSERT INTO cultural_activity_admin_logs (admin_id, activity_id, action, details, ip_address)
                VALUES (:admin_id, NULL, 'create_checkin_token', :details, :ip_address)
            ");
            $log_stmt->execute([
                'admin_id' => $admin_id,
                'details' => json_encode([
                    'label' => $label,
                    'expires_at' => $expires_at,
                    'token' => substr($token_value, 0, 10) . '...'  // 토큰 일부만 기록
                ], JSON_UNESCAPED_UNICODE),
                'ip_address' => get_client_ip()
            ]);

            $_SESSION['ss_success'] = '새 토큰이 발급되었습니다: ' . $token_value;
        } elseif ($action === 'toggle_status') {
            $token_id = (int)($_POST['token_id'] ?? 0);
            if ($token_id <= 0) {
                throw new Exception('토큰 정보를 확인할 수 없습니다.');
            }

            $token_stmt = $pdo->prepare("
                SELECT id, is_active
                FROM cultural_activity_checkin_tokens
                WHERE id = :id
                LIMIT 1
            ");
            $token_stmt->execute(['id' => $token_id]);
            $token = $token_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$token) {
                throw new Exception('토큰을 찾을 수 없습니다.');
            }

            $new_status = (int)$token['is_active'] === 1 ? 0 : 1;
            $update_stmt = $pdo->prepare("
                UPDATE cultural_activity_checkin_tokens
                SET is_active = :status
                WHERE id = :id
            ");
            $update_stmt->execute([
                'status' => $new_status,
                'id' => $token_id
            ]);

            // 관리자 로그 기록
            $admin_id = $_SESSION['admin_id'] ?? 'unknown';
            $log_stmt = $pdo->prepare("
                INSERT INTO cultural_activity_admin_logs (admin_id, activity_id, action, details, ip_address)
                VALUES (:admin_id, NULL, 'toggle_checkin_token', :details, :ip_address)
            ");
            $log_stmt->execute([
                'admin_id' => $admin_id,
                'details' => json_encode([
                    'token_id' => $token_id,
                    'new_status' => $new_status ? 'active' : 'inactive'
                ], JSON_UNESCAPED_UNICODE),
                'ip_address' => get_client_ip()
            ]);

            $_SESSION['ss_success'] = $new_status ? '토큰이 활성화되었습니다.' : '토큰이 비활성화되었습니다.';
        } elseif ($action === 'delete_token') {
            $token_id = (int)($_POST['token_id'] ?? 0);
            if ($token_id <= 0) {
                throw new Exception('토큰 정보를 확인할 수 없습니다.');
            }

            $delete_stmt = $pdo->prepare("DELETE FROM cultural_activity_checkin_tokens WHERE id = :id");
            $delete_stmt->execute(['id' => $token_id]);

            // 관리자 로그 기록
            $admin_id = $_SESSION['admin_id'] ?? 'unknown';

            $log_stmt = $pdo->prepare("
                INSERT INTO cultural_activity_admin_logs (admin_id, activity_id, action, details, ip_address)
                VALUES (:admin_id, NULL, 'delete_checkin_token', :details, :ip_address)
            ");
            $log_stmt->execute([
                'admin_id' => $admin_id,
                'details' => json_encode([
                    'token_id' => $token_id
                ], JSON_UNESCAPED_UNICODE),
                'ip_address' => get_client_ip()
            ]);

            $_SESSION['ss_success'] = '토큰이 완전히 삭제되었습니다.';
        }
    } catch (Exception $e) {
        $_SESSION['ss_error'] = $e->getMessage();
    }

    header('Location: /Admin/SystemSetting/ss-index.php');
    exit();
}

try {
    $tokens_stmt = $pdo->query("
        SELECT id, token, label, expires_at, is_active, created_by, created_at
        FROM cultural_activity_checkin_tokens
        ORDER BY created_at DESC
    ");
    $tokens = $tokens_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tokens = [];
    $error_message = '토큰 목록을 불러오지 못했습니다: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>시스템 설정 | <?= htmlspecialchars($PAGE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
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
        .header-left {
            display: flex;
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
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        .main-content {
            flex: 1;
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
            padding: 32px;
        }
        .section {
            background: #fff;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 24px;
        }
        .section-title {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 12px;
            color: var(--admin-primary);
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        label { font-size: 0.9rem; font-weight: 600; color: var(--text-main); }
        input[type="text"], input[type="datetime-local"] {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        .btn-primary {
            background: var(--admin-primary);
            color: #fff;
            padding: 12px 20px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 8px;
        }
        .alert {
            padding: 16px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .alert-success { background: #dcfce7; color: var(--success-green); border: 1px solid var(--success-green); }
        .alert-error { background: #fee2e2; color: var(--error-red); border: 1px solid var(--error-red); }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 12px; border-bottom: 1px solid var(--border-color); text-align: left; }
        th { font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); }
        .token-value {
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", monospace;
            font-size: 0.85rem;
            word-break: break-all;
            background: #f3f4f6;
            padding: 8px 10px;
            border-radius: 8px;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-active { background: #fee2e2; color: #b91c1c; }
        .badge-inactive { background: #dcfce7; color: #166534; }
        .action-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-secondary, .btn-danger, .btn-copy {
            padding: 8px 12px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-secondary { background: #e5e7eb; color: #111827; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-copy { background: #bfdbfe; color: #1d4ed8; }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="header-content">
                <div class="header-left">
                    <a href="/Admin/dashboard.php" class="back-btn">← 대시보드</a>
                    <div>
                        <h1>시스템 설정</h1>
                        <p>운영에 필요한 도구와 토큰을 관리합니다.</p>
                    </div>
                </div>
                <div class="header-right">
                    <span class="badge badge-active" style="background:rgba(255,255,255,0.25);color:#fff;">
                        <?= htmlspecialchars($admin_position, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <a href="/Auth/admin_logout.php" class="back-btn" style="background: rgba(255,255,255,0.85); color: var(--admin-primary);">로그아웃</a>
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
                <h2 class="section-title">단기조교용 임시 토큰 발급</h2>
                <p style="color: var(--text-muted); margin-bottom: 16px;">
                    토큰을 발급하면 체크인 페이지에서 로그인 없이 사용할 수 있습니다. 토큰은 반드시 안전한 경로로 전달하고, 사용 종료 후 비활성화하세요.
                </p>
                <form method="POST" action="/Admin/SystemSetting/ss-index.php">
                    <input type="hidden" name="action" value="create_token" />
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="label">토큰 설명 *</label>
                            <input type="text" id="label" name="label" placeholder="예: 7월 1일 문화체험 체크인" required />
                        </div>
                        <div class="form-group">
                            <label for="expires_at">만료 일시 (선택)</label>
                            <input type="datetime-local" id="expires_at" name="expires_at" />
                        </div>
                    </div>
                    <button type="submit" class="btn-primary">토큰 발급</button>
                </form>
            </section>

            <section class="section">
                <h2 class="section-title">발급된 토큰 목록</h2>
                <div class="table-wrapper">
                    <?php if (empty($tokens)): ?>
                        <p style="color: var(--text-muted);">아직 발급된 토큰이 없습니다.</p>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>설명</th>
                                    <th>토큰</th>
                                    <th>만료일</th>
                                    <th>상태</th>
                                    <th>발급자</th>
                                    <th>기능</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tokens as $token): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($token['label'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                            <small><?= date('Y-m-d H:i', strtotime($token['created_at'])) ?></small>
                                        </td>
                                        <td>
                                            <div class="token-value"><?= htmlspecialchars($token['token'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </td>
                                        <td>
                                            <?= $token['expires_at'] ? htmlspecialchars(date('Y-m-d H:i', strtotime($token['expires_at'])), ENT_QUOTES, 'UTF-8') : '설정 없음' ?>
                                        </td>
                                        <td>
                                            <?php if ((int)$token['is_active'] === 1): ?>
                                                <span class="badge badge-active">활성</span>
                                            <?php else: ?>
                                                <span class="badge badge-inactive">비활성</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($token['created_by'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button"
                                                        class="btn-copy"
                                                        onclick="copyCheckinLink('<?= htmlspecialchars($token['token'], ENT_QUOTES, 'UTF-8') ?>')">
                                                    링크 복사
                                                </button>
                                                <form method="POST" action="/Admin/SystemSetting/ss-index.php" onsubmit="return confirm('상태를 변경할까요?');">
                                                    <input type="hidden" name="action" value="toggle_status" />
                                                    <input type="hidden" name="token_id" value="<?= (int)$token['id'] ?>" />
                                                    <button type="submit" class="btn-secondary">
                                                        <?= (int)$token['is_active'] === 1 ? '비활성화' : '활성화' ?>
                                                    </button>
                                                </form>
                                                <form method="POST" action="/Admin/SystemSetting/ss-index.php" onsubmit="return confirm('토큰을 완전히 삭제할까요?');">
                                                    <input type="hidden" name="action" value="delete_token" />
                                                    <input type="hidden" name="token_id" value="<?= (int)$token['id'] ?>" />
                                                    <button type="submit" class="btn-danger">삭제</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>
        </main>

        <footer style="background:#fff;border-top:1px solid var(--border-color);padding:20px 32px;text-align:center;color:var(--text-muted);font-size:0.85rem;">
            © DATANEST, KOREA UNIVERSITY – Int'l Summer &amp; Winter Campus
        </footer>
    </div>
    <script>
    function copyCheckinLink(token) {
        const origin = window.location.origin || '';
        const link = origin + '/Admin/ManageCheckin/mc-index.php?token=' + encodeURIComponent(token);
        navigator.clipboard.writeText(link).then(function() {
            alert('체크인 링크가 복사되었습니다.');
        }).catch(function() {
            // fallback
            const temp = document.createElement('textarea');
            temp.value = link;
            document.body.appendChild(temp);
            temp.select();
            document.execCommand('copy');
            document.body.removeChild(temp);
            alert('체크인 링크가 복사되었습니다.');
        });
    }
    </script>
</body>
</html>
