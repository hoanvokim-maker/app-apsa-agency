<?php
// =========================================================
// APSA — Short Link API   /api/short-api.php
// Session-based auth (dùng chung session-boot.php)
// Actions: list, create, update, delete, toggle
// =========================================================

require_once __DIR__ . '/db-config.php';
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
    echo json_encode(['ok' => false, 'error' => 'DB connection failed']);
    exit;
}

function ok($data)               { echo json_encode(['ok' => true,  'data' => $data]); exit; }
function fail($msg, $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; }

// ── Auth ────────────────────────────────────────────────
function sl_user($pdo) {
    if (empty($_SESSION['user_id'])) return null;
    $st = $pdo->prepare("SELECT id, username, display_name, role, active FROM `app_users` WHERE id = ? AND active = 1");
    $st->execute([$_SESSION['user_id']]);
    $u = $st->fetch();
    return $u ?: null;
}

// ── Migrate once (khoá theo mtime của chính file này) ───
$SL_MIG_LOCK = sys_get_temp_dir() . '/apsa_shortlink_mig_' . @filemtime(__FILE__) . '.lock';
if (!file_exists($SL_MIG_LOCK)) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `short_links` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `code`          VARCHAR(32)  NOT NULL,
            `url`           TEXT         NOT NULL,
            `name`          VARCHAR(255) DEFAULT NULL,
            `is_public`     TINYINT(1)   NOT NULL DEFAULT 1,
            `active`        TINYINT(1)   NOT NULL DEFAULT 1,
            `clicks`        INT          NOT NULL DEFAULT 0,
            `last_click_at` DATETIME     DEFAULT NULL,
            `user_id`       INT          DEFAULT NULL,
            `user_name`     VARCHAR(120) DEFAULT NULL,
            `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_short_code` (`code`),
            KEY `idx_short_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    @touch($SL_MIG_LOCK);
}

// ── Helpers ─────────────────────────────────────────────
const SL_ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const SL_RESERVED = ['u', 'api', 'admin', 'login', 'index', 'assets', 'uploads', 'static', 'favicon', 'robots', 'sitemap'];

function sl_random_code($len = 6) {
    $a = SL_ALPHABET; $n = strlen($a); $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $a[random_int(0, $n - 1)];
    return $out;
}

function sl_valid_code($c) {
    return (bool) preg_match('/^[A-Za-z0-9_-]{3,32}$/', $c);
}

function sl_clean_url($u) {
    $u = trim((string) $u);
    if ($u === '') return null;
    if (!preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
    if (!filter_var($u, FILTER_VALIDATE_URL)) return null;
    $scheme = strtolower((string) parse_url($u, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) return null;
    if (!parse_url($u, PHP_URL_HOST)) return null;
    return $u;
}

function sl_base() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host  = $_SERVER['HTTP_HOST'] ?? 'app.apsa.agency';
    return ($https ? 'https' : 'http') . '://' . $host;
}

function sl_row($r) {
    $r['id']        = (int) $r['id'];
    $r['clicks']    = (int) $r['clicks'];
    $r['is_public'] = (int) $r['is_public'] === 1;
    $r['active']    = (int) $r['active'] === 1;
    $r['short']     = sl_base() . '/u/' . $r['code'];
    return $r;
}

function sl_unique_code($pdo, $len = 6) {
    for ($i = 0; $i < 12; $i++) {
        $c  = sl_random_code($len + intdiv($i, 4));
        $st = $pdo->prepare('SELECT 1 FROM `short_links` WHERE code = ?');
        $st->execute([$c]);
        if (!$st->fetch()) return $c;
    }
    fail('Không sinh được mã, vui lòng thử lại', 500);
}

// ── Dispatch ────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

$me = sl_user($pdo);
if (!$me) fail('Unauthorized', 401);

switch ($action) {

    // ── Danh sách ───────────────────────────────────────
    case 'list': {
        $q    = trim((string) ($_GET['q'] ?? ''));
        $sql  = 'SELECT * FROM `short_links`';
        $args = [];
        if ($q !== '') {
            $sql .= ' WHERE (name LIKE ? OR url LIKE ? OR code LIKE ?)';
            $like = '%' . $q . '%';
            $args = [$like, $like, $like];
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 500';
        $st = $pdo->prepare($sql);
        $st->execute($args);
        ok(array_map('sl_row', $st->fetchAll()));
    }

    // ── Tạo mới ─────────────────────────────────────────
    case 'create': {
        $url = sl_clean_url($body['url'] ?? '');
        if (!$url) fail('URL không hợp lệ');

        $name   = trim((string) ($body['name'] ?? ''));
        $public = array_key_exists('is_public', $body) ? (bool) $body['is_public'] : true;

        $code = trim((string) ($body['code'] ?? ''));
        if ($code !== '') {
            if (!sl_valid_code($code)) fail('Mã chỉ gồm chữ, số, gạch ngang, gạch dưới — dài 3 đến 32 ký tự');
            if (in_array(strtolower($code), SL_RESERVED, true)) fail('Mã này đã được hệ thống giữ chỗ, chọn mã khác nhé');
            $st = $pdo->prepare('SELECT 1 FROM `short_links` WHERE code = ?');
            $st->execute([$code]);
            if ($st->fetch()) fail('Mã này đã có người dùng rồi');
        } else {
            $code = sl_unique_code($pdo);
        }

        $st = $pdo->prepare(
            'INSERT INTO `short_links` (code, url, name, is_public, user_id, user_name)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $code, $url, ($name !== '' ? $name : null), $public ? 1 : 0,
            (int) $me['id'], $me['display_name'] ?: $me['username'],
        ]);

        $get = $pdo->prepare('SELECT * FROM `short_links` WHERE id = ?');
        $get->execute([(int) $pdo->lastInsertId()]);
        ok(sl_row($get->fetch()));
    }

    // ── Cập nhật ────────────────────────────────────────
    case 'update': {
        $id = (int) ($body['id'] ?? 0);
        if (!$id) fail('Thiếu id');

        $cur = $pdo->prepare('SELECT * FROM `short_links` WHERE id = ?');
        $cur->execute([$id]);
        $row = $cur->fetch();
        if (!$row) fail('Không tìm thấy link', 404);

        $url = sl_clean_url($body['url'] ?? $row['url']);
        if (!$url) fail('URL không hợp lệ');

        $name   = array_key_exists('name', $body) ? trim((string) $body['name']) : (string) $row['name'];
        $public = array_key_exists('is_public', $body) ? (bool) $body['is_public'] : ((int) $row['is_public'] === 1);
        $active = array_key_exists('active', $body)    ? (bool) $body['active']    : ((int) $row['active'] === 1);

        $code = array_key_exists('code', $body) ? trim((string) $body['code']) : $row['code'];
        if ($code === '') $code = $row['code'];
        if ($code !== $row['code']) {
            if (!sl_valid_code($code)) fail('Mã chỉ gồm chữ, số, gạch ngang, gạch dưới — dài 3 đến 32 ký tự');
            if (in_array(strtolower($code), SL_RESERVED, true)) fail('Mã này đã được hệ thống giữ chỗ, chọn mã khác nhé');
            $chk = $pdo->prepare('SELECT 1 FROM `short_links` WHERE code = ? AND id <> ?');
            $chk->execute([$code, $id]);
            if ($chk->fetch()) fail('Mã này đã có người dùng rồi');
        }

        $st = $pdo->prepare(
            'UPDATE `short_links` SET code = ?, url = ?, name = ?, is_public = ?, active = ? WHERE id = ?'
        );
        $st->execute([$code, $url, ($name !== '' ? $name : null), $public ? 1 : 0, $active ? 1 : 0, $id]);

        $cur->execute([$id]);
        ok(sl_row($cur->fetch()));
    }

    // ── Xoá ─────────────────────────────────────────────
    case 'delete': {
        $id = (int) ($body['id'] ?? ($_GET['id'] ?? 0));
        if (!$id) fail('Thiếu id');
        $st = $pdo->prepare('DELETE FROM `short_links` WHERE id = ?');
        $st->execute([$id]);
        if ($st->rowCount() === 0) fail('Không tìm thấy link', 404);
        ok(['id' => $id]);
    }

    default:
        fail('Unknown action: ' . $action, 404);
}
