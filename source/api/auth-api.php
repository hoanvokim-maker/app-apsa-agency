<?php
// ============================================================
// APSA — Auth API  /api/auth-api.php
// Session-based auth cho toàn bộ app.apsa.agency
// Actions: login, logout, me, list, create, update, delete
// ============================================================

require_once __DIR__ . '/db-config.php';

// Session 30 ngày — cấu hình tập trung ở session-boot.php
require_once __DIR__ . '/session-boot.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
}

// ── Migrate schema + seed: chỉ chạy 1 lần sau mỗi lần deploy file này ──
$MIG_LOCK   = sys_get_temp_dir() . '/apsa_mig_auth_' . md5(__FILE__) . '.lock';
$DO_MIGRATE = !is_file($MIG_LOCK) || filemtime($MIG_LOCK) < filemtime(__FILE__);
function au_mig(PDO $pdo, $sql) {
    global $DO_MIGRATE;
    if ($DO_MIGRATE) $pdo->exec($sql);
}

// ── Auto-create table ───────────────────────────────────────
au_mig($pdo, "CREATE TABLE IF NOT EXISTS `app_users` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`       VARCHAR(50)  NOT NULL,
  `password_hash`  VARCHAR(255) NOT NULL,
  `display_name`   VARCHAR(100) NOT NULL,
  `role`           ENUM('admin','member') NOT NULL DEFAULT 'member',
  `active`         TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login_at`  TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Migrate: vị trí công việc + nhân sự freelancer ───────────
function au_hasColumn(PDO $pdo, $table, $col) {
    global $DO_MIGRATE;
    if (!$DO_MIGRATE) return true;          // đã migrate rồi, coi như cột đã có
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $st->execute([$table, $col]);
        return (int)$st->fetchColumn() > 0;
    } catch (PDOException $e) { return true; }
}
if (!au_hasColumn($pdo, 'app_users', 'position')) {
    try {
        au_mig($pdo, "ALTER TABLE `app_users`
            ADD COLUMN `position`   VARCHAR(20)  DEFAULT NULL COMMENT 'account|admin|designer|editor',
            ADD COLUMN `staff_type` VARCHAR(12)  NOT NULL DEFAULT 'inhouse' COMMENT 'inhouse|freelancer',
            ADD COLUMN `can_login`  TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = chỉ là tên để giao việc',
            ADD COLUMN `phone`      VARCHAR(40)  DEFAULT NULL,
            ADD COLUMN `email`      VARCHAR(150) DEFAULT NULL,
            ADD COLUMN `note`       VARCHAR(300) DEFAULT NULL");
    } catch (PDOException $e) { /* đã có */ }
}

/** Danh sách vị trí — sửa ở đây thì UI tự đổi theo (auth-api?action=positions). */
require_once __DIR__ . '/settings-api.php';
/* Danh sach vi tri lay tu trang Cai dat he thong */
$POSITIONS = st_positions();
function au_position($v) {
    global $POSITIONS;
    $v = strtolower(trim((string)$v));
    return isset($POSITIONS[$v]) ? $v : null;
}
function au_staffType($v) {
    return (strtolower(trim((string)$v)) === 'freelancer') ? 'freelancer' : 'inhouse';
}
/** Tạo username tự động cho freelancer không đăng nhập (fl-nguyen-van-a…) */
function au_slugUser(PDO $pdo, $name) {
    $base = mb_strtolower(trim((string)$name), 'UTF-8');
    $tr = ['à','á','ạ','ả','ã','â','ầ','ấ','ậ','ẩ','ẫ','ă','ằ','ắ','ặ','ẳ','ẵ','è','é','ẹ','ẻ','ẽ','ê','ề','ế','ệ','ể','ễ',
           'ì','í','ị','ỉ','ĩ','ò','ó','ọ','ỏ','õ','ô','ồ','ố','ộ','ổ','ỗ','ơ','ờ','ớ','ợ','ở','ỡ',
           'ù','ú','ụ','ủ','ũ','ư','ừ','ứ','ự','ử','ữ','ỳ','ý','ỵ','ỷ','ỹ','đ'];
    $en = ['a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','e','e','e','e','e','e','e','e','e','e','e',
           'i','i','i','i','i','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
           'u','u','u','u','u','u','u','u','u','u','u','y','y','y','y','y','d'];
    $base = str_replace($tr, $en, $base);
    $base = preg_replace('/[^a-z0-9]+/', '.', $base);
    $base = trim(preg_replace('/\.{2,}/', '.', $base), '.');
    if ($base === '') $base = 'freelancer';
    $base = 'fl.' . mb_substr($base, 0, 40);
    $try = $base; $i = 1;
    while (true) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM `app_users` WHERE username = ?");
        $st->execute([$try]);
        if (!(int)$st->fetchColumn()) return $try;
        $try = $base . (++$i);
    }
}

au_mig($pdo, "CREATE TABLE IF NOT EXISTS `app_user_prefs` (
  `user_id`    INT UNSIGNED NOT NULL,
  `pref_key`   VARCHAR(50)  NOT NULL COMMENT 'VD: home = tuỳ chỉnh trang chủ',
  `pref_value` MEDIUMTEXT   NOT NULL COMMENT 'JSON',
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`pref_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Seed default admin nếu bảng rỗng ────────────────────────
$count = $DO_MIGRATE ? (int)$pdo->query("SELECT COUNT(*) FROM `app_users`")->fetchColumn() : 1;
if ($count === 0) {
    $hash = password_hash('Password@123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO `app_users` (`username`,`password_hash`,`display_name`,`role`) VALUES (?,?,?,?)")
        ->execute(['admin', $hash, 'Admin', 'admin']);
}

// Mật khẩu mặc định khi Admin khôi phục cho một tài khoản
define('APSA_DEFAULT_PASSWORD', 'Password@123');

function ok($data)             { echo json_encode(['ok' => true, 'data' => $data]); exit; }
function fail($msg, $code=400) { http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }

function currentUser($pdo) {
    if (empty($_SESSION['user_id'])) return null;
    $st = $pdo->prepare("SELECT id, username, display_name, role, active FROM `app_users` WHERE id = ? AND active = 1");
    $st->execute([$_SESSION['user_id']]);
    $u = $st->fetch();
    return $u ?: null;
}

function requireAuth($pdo) {
    $u = currentUser($pdo);
    if (!$u) fail('Unauthorized', 401);
    return $u;
}

function requireAdmin($pdo) {
    $u = requireAuth($pdo);
    if ($u['role'] !== 'admin') fail('Forbidden — chỉ Admin', 403);
    return $u;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'OPTIONS') { http_response_code(204); exit; }

au_mig($pdo, "CREATE TABLE IF NOT EXISTS `app_notifications` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `kind`       VARCHAR(24)  NOT NULL,
  `title`      VARCHAR(200) NOT NULL,
  `body`       VARCHAR(500) DEFAULT NULL,
  `url`        VARCHAR(300) DEFAULT NULL,
  `actor`      VARCHAR(120) DEFAULT NULL,
  `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`, `is_read`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($DO_MIGRATE) @touch($MIG_LOCK);   // đánh dấu đã migrate cho lần sau

switch ($action) {

    case 'login':
        if ($method !== 'POST') fail('Method not allowed', 405);
        $username = trim($body['username'] ?? '');
        $password = (string)($body['password'] ?? '');
        if (!$username || !$password) fail('Vui lòng nhập tài khoản và mật khẩu');

        $st = $pdo->prepare("SELECT * FROM `app_users` WHERE username = ?");
        $st->execute([$username]);
        $u = $st->fetch();

        if (!$u || !$u['active'] || !password_verify($password, $u['password_hash'])) {
            fail('Sai tài khoản hoặc mật khẩu', 401);
        }
        // Freelancer chỉ là tên để giao việc — không có quyền đăng nhập
        if (isset($u['can_login']) && !(int)$u['can_login']) {
            fail('Tài khoản này không được phép đăng nhập', 403);
        }

        session_regenerate_id(true);
        $_SESSION['user_id']    = (int)$u['id'];
        $_SESSION['_touched_at'] = time();
        apsa_send_session_cookie();   // cookie sống 30 ngày

        $pdo->prepare("UPDATE `app_users` SET last_login_at = NOW() WHERE id = ?")->execute([$u['id']]);

        ok([
            'id' => (int)$u['id'], 'username' => $u['username'],
            'display_name' => $u['display_name'], 'role' => $u['role'],
        ]);
        break;

    case 'logout':
        $_SESSION = [];
        session_destroy();
        ok(['message' => 'Logged out']);
        break;

    case 'me':
        $u = currentUser($pdo);
        if (!$u) fail('Unauthorized', 401);
        ok($u);
        break;

    // ── Tuỳ chỉnh giao diện riêng của từng user (thứ tự / ẩn module trang chủ) ──
    case 'prefs-get': {
        $u   = requireAuth($pdo);
        $key = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['key'] ?? 'home'));
        if ($key === '') $key = 'home';
        $st = $pdo->prepare("SELECT pref_value, updated_at FROM `app_user_prefs` WHERE user_id = ? AND pref_key = ?");
        $st->execute([$u['id'], $key]);
        $row = $st->fetch();
        $val = $row ? json_decode($row['pref_value'], true) : null;
        ok(['key' => $key, 'value' => $val, 'updated_at' => $row['updated_at'] ?? null]);
    }

    case 'prefs-save': {
        $u = requireAuth($pdo);
        if ($method !== 'POST') fail('Method not allowed', 405);
        $key = preg_replace('/[^a-z0-9_\-]/i', '', (string)($body['key'] ?? 'home'));
        if ($key === '') $key = 'home';
        if (!array_key_exists('value', $body)) fail('value is required');
        $json = json_encode($body['value'], JSON_UNESCAPED_UNICODE);
        if ($json === false) fail('Dữ liệu không hợp lệ');
        if (strlen($json) > 400000) fail('Dữ liệu quá lớn', 413);
        $st = $pdo->prepare("INSERT INTO `app_user_prefs` (user_id, pref_key, pref_value) VALUES (?,?,?)
                             ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)");
        $st->execute([$u['id'], $key, $json]);
        ok(['key' => $key, 'message' => 'Đã lưu']);
    }

    case 'list':
        requireAdmin($pdo);
        $rows = $pdo->query("SELECT id, username, display_name, role, active, position, staff_type, can_login,
                                    phone, email, note, created_at, last_login_at
                               FROM `app_users` ORDER BY (staff_type='freelancer') ASC, display_name ASC, id ASC")->fetchAll();
        ok($rows);
        break;

    // Danh sách vị trí công việc (dùng chung cho users.html và phần giao việc)
    case 'positions':
        ok($POSITIONS);
        break;

    case 'notif-list': {
        $me = requireAuth($pdo);
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
        $st = $pdo->prepare("SELECT id, kind, title, body, url, actor, is_read, created_at
                               FROM `app_notifications` WHERE user_id = ?
                              ORDER BY id DESC LIMIT $limit");
        $st->execute([(int)$me['id']]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['is_read'] = (int)$r['is_read']; }
        $c = $pdo->prepare("SELECT COUNT(*) FROM `app_notifications` WHERE user_id = ? AND is_read = 0");
        $c->execute([(int)$me['id']]);
        ok(['rows' => $rows, 'unread' => (int)$c->fetchColumn()]);
        break;
    }

    case 'notif-read': {
        $me = requireAuth($pdo);
        if (!empty($body['all'])) {
            $pdo->prepare("UPDATE `app_notifications` SET is_read = 1 WHERE user_id = ? AND is_read = 0")
                ->execute([(int)$me['id']]);
        } else {
            $id = (int)($body['id'] ?? 0);
            if (!$id) fail('Thiếu mã thông báo');
            $pdo->prepare("UPDATE `app_notifications` SET is_read = 1 WHERE id = ? AND user_id = ?")
                ->execute([$id, (int)$me['id']]);
        }
        ok(['message' => 'ok']);
        break;
    }

    case 'basic-list':
        // Danh sách rút gọn cho mọi user đã đăng nhập (không cần quyền Admin) —
        // dùng để chọn "Nhân viên sử dụng" ở các trang như accounts.html.
        requireAuth($pdo);
        $rows = $pdo->query("SELECT id, username, display_name, role, position, staff_type, can_login, phone, email
                               FROM `app_users` WHERE active = 1
                              ORDER BY (staff_type='freelancer') ASC, display_name ASC")->fetchAll();
        ok($rows);
        break;

    case 'create':
        requireAdmin($pdo);
        $username = trim($body['username'] ?? '');
        $password = (string)($body['password'] ?? '');
        $display  = trim($body['display_name'] ?? '');
        $role     = (($body['role'] ?? 'member') === 'admin') ? 'admin' : 'member';
        $staff    = au_staffType($body['staff_type'] ?? 'inhouse');
        $pos      = au_position($body['position'] ?? '');
        // Freelancer mặc định KHÔNG đăng nhập được — chỉ là tên để giao việc
        $canLogin = array_key_exists('can_login', $body)
                    ? (int)!!$body['can_login']
                    : ($staff === 'freelancer' ? 0 : 1);

        if (!$display) fail('Vui lòng nhập tên hiển thị');
        if (!$canLogin) {
            $role = 'member';
            if ($username === '') $username = au_slugUser($pdo, $display);
            if ($password === '') $password = bin2hex(random_bytes(16));   // hash rác, không dùng để đăng nhập
        }
        if (!$username) fail('username is required');
        if ($canLogin && strlen($password) < 6) fail('Mật khẩu tối thiểu 6 ký tự');
        if (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $username)) fail('Username chỉ gồm chữ, số, dấu chấm, gạch dưới');

        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $st = $pdo->prepare("INSERT INTO `app_users`
                (`username`,`password_hash`,`display_name`,`role`,`position`,`staff_type`,`can_login`,`phone`,`email`,`note`)
                VALUES (?,?,?,?,?,?,?,?,?,?)");
            $st->execute([$username, $hash, $display, $role, $pos, $staff, $canLogin,
                          mb_substr(trim((string)($body['phone'] ?? '')), 0, 40),
                          mb_substr(trim((string)($body['email'] ?? '')), 0, 150),
                          mb_substr(trim((string)($body['note']  ?? '')), 0, 300)]);
            $id = (int)$pdo->lastInsertId();
            ok(['id' => $id, 'username' => $username, 'staff_type' => $staff, 'can_login' => $canLogin,
                'message' => $canLogin ? 'Đã tạo tài khoản' : 'Đã thêm ' . $display . ' vào danh sách nhân sự']);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') fail('Username đã tồn tại', 409);
            fail($e->getMessage());
        }
        break;

    case 'update':
        $me = requireAdmin($pdo);
        $id = (int)($body['id'] ?? 0);
        if (!$id) fail('id is required');

        $fields = []; $params = [];
        if (isset($body['display_name'])) { $fields[] = '`display_name`=?'; $params[] = trim($body['display_name']); }
        if (isset($body['role'])) {
            if ($id === $me['id'] && $body['role'] !== 'admin') fail('Không thể tự hạ quyền của chính mình');
            $fields[] = '`role`=?'; $params[] = ($body['role'] === 'admin') ? 'admin' : 'member';
        }
        if (isset($body['active'])) {
            if ($id === $me['id'] && !$body['active']) fail('Không thể tự khoá tài khoản của chính mình');
            $fields[] = '`active`=?'; $params[] = (int)$body['active'];
        }
        if (array_key_exists('position', $body))   { $fields[] = '`position`=?';   $params[] = au_position($body['position']); }
        if (array_key_exists('staff_type', $body)) { $fields[] = '`staff_type`=?'; $params[] = au_staffType($body['staff_type']); }
        if (array_key_exists('can_login', $body)) {
            if ($id === $me['id'] && !$body['can_login']) fail('Không thể tự tắt quyền đăng nhập của chính mình');
            $fields[] = '`can_login`=?'; $params[] = (int)!!$body['can_login'];
        }
        foreach (['phone' => 40, 'email' => 150, 'note' => 300] as $f => $len) {
            if (array_key_exists($f, $body)) { $fields[] = "`$f`=?"; $params[] = mb_substr(trim((string)$body[$f]), 0, $len); }
        }
        if (!empty($body['password'])) {
            if (strlen($body['password']) < 6) fail('Mật khẩu tối thiểu 6 ký tự');
            $fields[] = '`password_hash`=?'; $params[] = password_hash($body['password'], PASSWORD_DEFAULT);
        }
        if (!$fields) fail('Nothing to update');

        $params[] = $id;
        $pdo->prepare("UPDATE `app_users` SET ".implode(',', $fields)." WHERE id=?")->execute($params);
        ok(['id' => $id, 'message' => 'User updated']);
        break;

    // ── Khôi phục mật khẩu mặc định cho 1 user (chỉ Admin) ──
    case 'reset-password': {
        $me = requireAdmin($pdo);
        if ($method !== 'POST') fail('Method not allowed', 405);
        $id = (int)($body['id'] ?? 0);
        if (!$id) fail('id is required');

        $st = $pdo->prepare("SELECT id, username, display_name FROM `app_users` WHERE id = ?");
        $st->execute([$id]);
        $u = $st->fetch();
        if (!$u) fail('Không tìm thấy user', 404);

        $hash = password_hash(APSA_DEFAULT_PASSWORD, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE `app_users` SET password_hash = ? WHERE id = ?")->execute([$hash, $id]);

        ok([
            'id'       => $id,
            'username' => $u['username'],
            'password' => APSA_DEFAULT_PASSWORD,
            'message'  => 'Đã khôi phục mật khẩu mặc định cho ' . $u['username'],
        ]);
    }

    case 'delete':
        $me = requireAdmin($pdo);
        $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
        if (!$id) fail('id is required');
        if ($id === $me['id']) fail('Không thể tự xoá chính mình');

        // Không cho xoá admin cuối cùng
        $st = $pdo->prepare("SELECT role FROM `app_users` WHERE id = ?");
        $st->execute([$id]);
        $target = $st->fetch();
        if (!$target) fail('Not found', 404);
        if ($target['role'] === 'admin') {
            $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM `app_users` WHERE role='admin' AND active=1")->fetchColumn();
            if ($adminCount <= 1) fail('Không thể xoá Admin cuối cùng');
        }

        $pdo->prepare("DELETE FROM `app_users` WHERE id = ?")->execute([$id]);
        ok(['message' => 'User deleted']);
        break;

    default:
        fail('Unknown action', 404);
}
