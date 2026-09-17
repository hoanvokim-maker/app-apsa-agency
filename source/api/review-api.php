<?php
/**
 * APSA — Duyệt video (video review)
 * Admin tạo link từ file video trên SharePoint; khách mở link, dừng đúng giây và comment.
 *
 *  Admin  : me · browse · create · list · toggle · del · cdel · resolve
 *  Công khai (cần token): open · stream · comments · comment · img
 */
require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/session-boot.php';
require_once __DIR__ . '/msgraph.php';
require_once __DIR__ . '/perm.php';

define('RV_DRIVE', 'b!e4unr15XWkyaVG8edY6MkfZmAYHtCUBDugOk42Ie06yPpJHpQ4j6SpiGKdrEhZ21');
define('RV_DIR', dirname(__DIR__) . '/uploads/review');
define('RV_MAX_IMG', 8 * 1024 * 1024);

function rv_ok($d = array())  { header('Content-Type: application/json; charset=utf-8'); echo json_encode(array('ok' => true, 'data' => $d), JSON_UNESCAPED_UNICODE); exit; }
function rv_fail($m, $c = 400) { header('Content-Type: application/json; charset=utf-8'); http_response_code($c); echo json_encode(array('ok' => false, 'error' => $m), JSON_UNESCAPED_UNICODE); exit; }
function rv_s($v, $n = 255) { return mb_substr(trim((string) $v), 0, $n); }

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
} catch (PDOException $e) { rv_fail('DB connection failed', 500); }

$pdo->exec("CREATE TABLE IF NOT EXISTS `video_reviews` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token` CHAR(32) NOT NULL,
  `title` VARCHAR(300) NOT NULL DEFAULT '',
  `note` VARCHAR(500) DEFAULT NULL,
  `drive_id` VARCHAR(200) NOT NULL DEFAULT '',
  `item_id` VARCHAR(120) NOT NULL DEFAULT '',
  `file_name` VARCHAR(300) NOT NULL DEFAULT '',
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `dl_url` TEXT DEFAULT NULL,
  `dl_exp` INT UNSIGNED NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` VARCHAR(120) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `video_comments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `review_id` INT UNSIGNED NOT NULL,
  `t_ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `author` VARCHAR(120) NOT NULL DEFAULT '',
  `body` TEXT DEFAULT NULL,
  `img` VARCHAR(200) DEFAULT NULL,
  `resolved` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `k_rev` (`review_id`, `t_ms`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── ai đang đăng nhập ─────────────────────────────────────── */
/* --- Bang playlist --- */
$pdo->exec("CREATE TABLE IF NOT EXISTS `video_playlists` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token` CHAR(32) NOT NULL,
  `title` VARCHAR(300) NOT NULL DEFAULT '',
  `note` VARCHAR(500) NOT NULL DEFAULT '',
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` VARCHAR(120) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `k_tok` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* APSA1826: chuoi phien ban cho video duyet */
try {
    $cols = array();
    foreach ($pdo->query("SHOW COLUMNS FROM `video_reviews`") as $c) $cols[] = $c['Field'];
    if (!in_array('root_id', $cols, true)) $pdo->exec("ALTER TABLE `video_reviews` ADD COLUMN `root_id` INT UNSIGNED NOT NULL DEFAULT 0");
    if (!in_array('ver', $cols, true))     $pdo->exec("ALTER TABLE `video_reviews` ADD COLUMN `ver` SMALLINT UNSIGNED NOT NULL DEFAULT 1");
} catch (Exception $e) {}

/* APSA1928: link ngan cho video va playlist */
function rv_newSlug(PDO $pdo, $tb) {
    $al = '23456789abcdefghjkmnpqrstuvwxyz';
    $n  = strlen($al);
    for ($k = 0; $k < 40; $k++) {
        $x = '';
        for ($i = 0; $i < 8; $i++) $x .= $al[random_int(0, $n - 1)];
        $q = $pdo->prepare("SELECT 1 FROM `" . $tb . "` WHERE slug = ? LIMIT 1");
        $q->execute(array($x));
        if (!$q->fetchColumn()) return $x;
    }
    return bin2hex(random_bytes(5));
}
try {
    foreach (array('video_reviews', 'video_playlists') as $tb) {
        $cols = array();
        foreach ($pdo->query("SHOW COLUMNS FROM `" . $tb . "`") as $c) $cols[] = $c['Field'];
        if (!in_array('slug', $cols, true)) {
            $pdo->exec("ALTER TABLE `" . $tb . "` ADD COLUMN `slug` VARCHAR(16) NOT NULL DEFAULT ''");
            $pdo->exec("ALTER TABLE `" . $tb . "` ADD KEY `k_slug` (`slug`)");
        }
        $q = $pdo->query("SELECT id FROM `" . $tb . "` WHERE slug = '' LIMIT 50");
        foreach ($q->fetchAll() as $row) {
            $pdo->prepare("UPDATE `" . $tb . "` SET slug = ? WHERE id = ?")
                ->execute(array(rv_newSlug($pdo, $tb), (int) $row['id']));
        }
    }
} catch (Exception $e) {}

/* APSA1927: ghi nhan luot xem video */
$pdo->exec("CREATE TABLE IF NOT EXISTS `video_views` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `review_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `playlist_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `vkey` CHAR(32) NOT NULL DEFAULT '',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `country` VARCHAR(80) NOT NULL DEFAULT '',
  `city` VARCHAR(80) NOT NULL DEFAULT '',
  `isp` VARCHAR(120) NOT NULL DEFAULT '',
  `ua` VARCHAR(255) NOT NULL DEFAULT '',
  `device` VARCHAR(60) NOT NULL DEFAULT '',
  `secs` INT UNSIGNED NOT NULL DEFAULT 0,
  `max_pos` INT UNSIGNED NOT NULL DEFAULT 0,
  `dur` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `k_rv` (`review_id`), KEY `k_pl` (`playlist_id`), KEY `k_vk` (`vkey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function rvw_ip() {
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $h) {
        if (empty($_SERVER[$h])) continue;
        $v = explode(',', (string) $_SERVER[$h]);
        $v = trim($v[0]);
        if (filter_var($v, FILTER_VALIDATE_IP)) return $v;
    }
    return '';
}
function rvw_device($ua) {
    $u = strtolower((string) $ua); $os = 'Khác'; $br = '';
    if (strpos($u, 'iphone') !== false) $os = 'iPhone';
    elseif (strpos($u, 'ipad') !== false) $os = 'iPad';
    elseif (strpos($u, 'android') !== false) $os = 'Android';
    elseif (strpos($u, 'mac os') !== false) $os = 'Mac';
    elseif (strpos($u, 'windows') !== false) $os = 'Windows';
    elseif (strpos($u, 'linux') !== false) $os = 'Linux';
    if (strpos($u, 'edg/') !== false) $br = 'Edge';
    elseif (strpos($u, 'chrome') !== false) $br = 'Chrome';
    elseif (strpos($u, 'firefox') !== false) $br = 'Firefox';
    elseif (strpos($u, 'safari') !== false) $br = 'Safari';
    return $br ? ($os . ' · ' . $br) : $os;
}
function rvw_geo($ip) {
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
    $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,country,city,isp';
    $raw = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        $raw = curl_exec($ch); curl_close($ch);
    }
    if ($raw === false) return null;
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['status']) || $j['status'] !== 'success') return null;
    return array(
        'country' => (string) (isset($j['country']) ? $j['country'] : ''),
        'city'    => (string) (isset($j['city']) ? $j['city'] : ''),
        'isp'     => (string) (isset($j['isp']) ? $j['isp'] : ''),
    );
}

/* APSA1926: playlist chi xem, khong cho gop y */
try {
    $c1 = array();
    foreach ($pdo->query("SHOW COLUMNS FROM `video_playlists`") as $c) $c1[] = $c['Field'];
    if (!in_array('no_cmt', $c1, true)) $pdo->exec("ALTER TABLE `video_playlists` ADD COLUMN `no_cmt` TINYINT(1) NOT NULL DEFAULT 0");
    $c2 = array();
    foreach ($pdo->query("SHOW COLUMNS FROM `video_reviews`") as $c) $c2[] = $c['Field'];
    if (!in_array('no_cmt', $c2, true)) $pdo->exec("ALTER TABLE `video_reviews` ADD COLUMN `no_cmt` TINYINT(1) NOT NULL DEFAULT 0");
} catch (Exception $e) {}

/* APSA1922: khach duyet video */
try {
    $cols = array();
    foreach ($pdo->query("SHOW COLUMNS FROM `video_reviews`") as $c) $cols[] = $c['Field'];
    if (!in_array('appr_at', $cols, true)) $pdo->exec("ALTER TABLE `video_reviews` ADD COLUMN `appr_at` DATETIME NULL DEFAULT NULL");
    if (!in_array('appr_by', $cols, true)) $pdo->exec("ALTER TABLE `video_reviews` ADD COLUMN `appr_by` VARCHAR(120) NOT NULL DEFAULT ''");
} catch (Exception $e) {}

/* APSA1825: cot anh thumbnail cho playlist */
try {
    if (!$pdo->query("SHOW COLUMNS FROM `video_playlists` LIKE 'thumb'")->fetch()) {
        $pdo->exec("ALTER TABLE `video_playlists` ADD COLUMN `thumb` VARCHAR(255) NOT NULL DEFAULT '' AFTER `note`");
    }
} catch (Exception $e) {}

$pdo->exec("CREATE TABLE IF NOT EXISTS `video_playlist_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `playlist_id` INT UNSIGNED NOT NULL,
  `review_id` INT UNSIGNED NOT NULL,
  `sort` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `k_pl` (`playlist_id`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* --- Bo sung cot cho tinh nang sua gop y --- */
foreach (array(
    'edited_at' => "ALTER TABLE `video_comments` ADD COLUMN `edited_at` DATETIME NULL DEFAULT NULL",
    'owner_key' => "ALTER TABLE `video_comments` ADD COLUMN `owner_key` VARCHAR(40) NOT NULL DEFAULT ''",
) as $rvCol => $rvSql) {
    try {
        $rvHas = $pdo->query("SHOW COLUMNS FROM `video_comments` LIKE " . $pdo->quote($rvCol))->fetch();
        if (!$rvHas) $pdo->exec($rvSql);
    } catch (PDOException $e) { }
}

function rv_me(PDO $pdo) {
    if (empty($_SESSION['user_id'])) return null;
    try {
        $st = $pdo->prepare("SELECT id, username, display_name, role, position FROM `app_users` WHERE id = ? AND active = 1");
        $st->execute(array($_SESSION['user_id']));
        return $st->fetch() ?: null;
    } catch (PDOException $e) { return null; }
}
function rv_isAdmin(PDO $pdo) {
    $me = rv_me($pdo);
    if (!$me) return false;
    return strcasecmp((string) $me['role'], 'admin') === 0
        || strcasecmp((string) $me['position'], 'admin') === 0;
}
/**
 * Muc quyen cua user hien tai voi module "Duyet video" (id 98): 0 / 1 / 2.
 * Admin luon 2. Khach chua dang nhap luon 0.
 */
function rv_lvl(PDO $pdo)
{
    if (rv_isAdmin($pdo)) return 2;
    if (!rv_me($pdo)) return 0;
    if (!function_exists('pm_mod_level')) return 0;
    pm_init($pdo);
    return (int) pm_mod_level(98);
}

/** Can quyen XEM: doc file tren SharePoint, xem danh sach link. */
function rv_needRead(PDO $pdo)
{
    if (rv_lvl($pdo) >= 1) return;
    rv_fail(rv_me($pdo)
        ? 'Bạn không có quyền vào mục Duyệt video. Liên hệ Admin để được cấp quyền.'
        : 'Chưa đăng nhập.', 403);
}

/** Can quyen THAO TAC: tao link, doi trang thai, xoa. */
function rv_needAdmin(PDO $pdo)
{
    if (rv_lvl($pdo) >= 2) return;
    rv_fail(rv_me($pdo)
        ? 'Bạn chỉ được xem mục Duyệt video, không được thay đổi.'
        : 'Chưa đăng nhập.', 403);
}

/* ── review theo token ─────────────────────────────────────── */
function rv_byToken(PDO $pdo, $t, $needActive = true) {
    $t = preg_replace('/[^a-z0-9]/', '', strtolower((string) $t));
    if (strlen($t) < 6 || strlen($t) > 40) rv_fail('Link không hợp lệ.', 404);
    $st = $pdo->prepare("SELECT * FROM `video_reviews` WHERE token = ? OR slug = ? LIMIT 1");
    $st->execute(array($t, $t));
    $r = $st->fetch();
    if (!$r) rv_fail('Link không tồn tại hoặc đã bị gỡ.', 404);
    if ($needActive && !(int) $r['active']) rv_fail('Link này đã được tắt.', 403);
    return $r;
}

/* ── lấy link tải trực tiếp từ Graph (cache ~50 phút) ─────── */
function rv_dl(PDO $pdo, $rev) {
    if (!empty($rev['dl_url']) && (int) $rev['dl_exp'] > time() + 60) return $rev['dl_url'];
    $err = '';
    $tok = mg_token($err);
    if (!$tok) rv_fail('Không kết nối được SharePoint.', 502);
    $code = 0;
    $j = mg_http('GET',
        'https://graph.microsoft.com/v1.0/drives/' . $rev['drive_id'] . '/items/' . $rev['item_id'] . '?select=id,name,size,@microsoft.graph.downloadUrl',
        array('Authorization: Bearer ' . $tok, 'Accept: application/json'), null, $code);
    $j = is_array($j) ? $j : json_decode((string) $j, true);
    $u = isset($j['@microsoft.graph.downloadUrl']) ? $j['@microsoft.graph.downloadUrl'] : '';
    if ($u === '') rv_fail('Không lấy được video từ SharePoint (HTTP ' . $code . ').', 502);
    $up = $pdo->prepare("UPDATE `video_reviews` SET dl_url = ?, dl_exp = ? WHERE id = ?");
    $up->execute(array($u, time() + 3000, (int) $rev['id']));
    return $u;
}

$ACT = isset($_GET['action']) ? (string) $_GET['action'] : '';
$B   = json_decode(file_get_contents('php://input'), true);
if (!is_array($B)) $B = array();

switch ($ACT) {

/* ═══════════ ADMIN ═══════════ */
case 'me': {
    $me = rv_me($pdo);
    rv_ok(array('admin' => rv_lvl($pdo) >= 2 ? 1 : 0, 'name' => $me ? ($me['display_name'] ?: $me['username']) : ''));
}

case 'browse': {
    rv_needRead($pdo);
    $id  = isset($_GET['id']) && $_GET['id'] !== '' ? preg_replace('/[^A-Za-z0-9!._-]/', '', (string) $_GET['id']) : '';
    $err = ''; $tok = mg_token($err);
    if (!$tok) rv_fail('Không kết nối được SharePoint.', 502);
    $url = $id === ''
        ? 'https://graph.microsoft.com/v1.0/drives/' . RV_DRIVE . '/root/children'
        : 'https://graph.microsoft.com/v1.0/drives/' . RV_DRIVE . '/items/' . $id . '/children';
    $url .= '?$top=400&$select=id,name,size,folder,file,video,lastModifiedDateTime&$orderby=name';
    $code = 0;
    $j = mg_http('GET', $url, array('Authorization: Bearer ' . $tok, 'Accept: application/json'), null, $code);
    $j = is_array($j) ? $j : json_decode((string) $j, true);
    if (!isset($j['value'])) rv_fail('Không đọc được thư mục (HTTP ' . $code . ').', 502);
    $out = array();
    foreach ($j['value'] as $it) {
        $isDir = isset($it['folder']);
        $ext = strtolower(pathinfo(isset($it['name']) ? $it['name'] : '', PATHINFO_EXTENSION));
        $isVid = in_array($ext, array('mp4', 'mov', 'm4v', 'webm'), true);
        if (!$isDir && !$isVid) continue;
        $out[] = array(
            'id' => $it['id'], 'name' => $it['name'], 'dir' => $isDir ? 1 : 0,
            'size' => isset($it['size']) ? (int) $it['size'] : 0,
            'modified' => isset($it['lastModifiedDateTime']) ? $it['lastModifiedDateTime'] : '',
        );
    }
    rv_ok(array('items' => $out));
}

case 'create': {
    rv_needAdmin($pdo);
    $item = rv_s($B['item_id'] ?? '', 120);
    $name = rv_s($B['name'] ?? '', 300);
    if ($item === '') rv_fail('Chưa chọn video.');
    $title = rv_s($B['title'] ?? '', 300);
    if ($title === '') $title = $name;
    /* APSA1826: tao ban moi cua 1 video da co */
    $par = (int) (isset($B['parent']) ? $B['parent'] : 0);
    $rootId = 0; $ver = 1;
    if ($par > 0) {
        $ps = $pdo->prepare("SELECT id, root_id, title, note FROM `video_reviews` WHERE id = ? LIMIT 1");
        $ps->execute(array($par));
        $pr = $ps->fetch();
        if (!$pr) rv_fail('Khong tim thay video goc.', 404);
        $rootId = (int) $pr['root_id'] ? (int) $pr['root_id'] : (int) $pr['id'];
        $mx = $pdo->prepare("SELECT COALESCE(MAX(ver),1) FROM `video_reviews` WHERE id = ? OR root_id = ?");
        $mx->execute(array($rootId, $rootId));
        $ver = (int) $mx->fetchColumn() + 1;
        if ($title === '' || $title === $name) $title = rv_s((string) $pr['title'], 300);
        if (!isset($B['note']) || trim((string) $B['note']) === '') $B['note'] = (string) $pr['note'];
    }
    $me = rv_me($pdo);
    $tk = bin2hex(random_bytes(16));
    $sg = rv_newSlug($pdo, 'video_reviews');
    $st = $pdo->prepare("INSERT INTO `video_reviews`
        (token, slug, title, note, drive_id, item_id, file_name, file_size, created_by)
        VALUES (?,?,?,?,?,?,?,?,?)");
    $st->execute(array($tk, $sg, $title, rv_s($B['note'] ?? '', 500), RV_DRIVE, $item, $name,
        (int) ($B['size'] ?? 0), $me ? ($me['display_name'] ?: $me['username']) : ''));
    $nid = (int) $pdo->lastInsertId();
    if ($rootId > 0) $pdo->prepare("UPDATE `video_reviews` SET root_id = ?, ver = ? WHERE id = ?")->execute(array($rootId, $ver, $nid));
    rv_ok(array('id' => $nid, 'token' => $tk, 'slug' => $sg, 'ver' => $ver));
}

case 'list': {
    rv_needRead($pdo);
    $st = $pdo->query("SELECT r.*, 
            (SELECT COUNT(*) FROM `video_comments` c WHERE c.review_id = r.id) AS n_cmt,
            (SELECT COUNT(*) FROM `video_comments` c WHERE c.review_id = r.id AND c.resolved = 0) AS n_open
        FROM `video_reviews` r ORDER BY r.id DESC LIMIT 500");
    $rows = array();
    foreach ($st->fetchAll() as $r) {
        unset($r['dl_url'], $r['dl_exp'], $r['drive_id'], $r['item_id']);
        $rows[] = $r;
    }
    rv_ok(array('rows' => $rows));
}

case 'rename': {
    rv_needAdmin($pdo);
    $id    = (int) (isset($B['id']) ? $B['id'] : 0);
    $title = rv_s(isset($B['title']) ? $B['title'] : '', 300);
    $note  = rv_s(isset($B['note']) ? $B['note'] : '', 500);
    if (!$id) rv_fail('Thiếu id');
    if ($title === '') rv_fail('Tiêu đề không được để trống.');
    $pdo->prepare("UPDATE `video_reviews` SET title = ?, note = ? WHERE id = ?")->execute(array($title, $note, $id));
    rv_ok(array('id' => $id));
}

/* ===================== PLAYLIST ===================== */
case 'pl-list': {
    rv_needRead($pdo);
    $st = $pdo->query("SELECT p.*,
            (SELECT COUNT(*) FROM `video_playlist_items` i WHERE i.playlist_id = p.id) AS n_vid
        FROM `video_playlists` p ORDER BY p.id DESC LIMIT 300");
    rv_ok(array('rows' => $st->fetchAll(), 'admin' => rv_lvl($pdo) >= 2 ? 1 : 0));
}

case 'pl-save': {
    rv_needAdmin($pdo);
    $id    = (int) (isset($B['id']) ? $B['id'] : 0);
    $title = rv_s(isset($B['title']) ? $B['title'] : '', 300);
    $note  = rv_s(isset($B['note']) ? $B['note'] : '', 500);
    $ids   = (isset($B['items']) && is_array($B['items'])) ? $B['items'] : null;
    $thumb = rv_s(isset($B['thumb']) ? $B['thumb'] : '', 255);
    $nocm  = (int) !empty($B['no_cmt']);                       /* APSA1926 */
    if ($thumb !== '' && !preg_match('#^uploads/playlists/[A-Za-z0-9._-]{1,120}$#', $thumb)) $thumb = '';
    if ($title === '') rv_fail('Tên playlist không được để trống.');
    $tk = null;
    if ($id > 0) {
        $pdo->prepare("UPDATE `video_playlists` SET title = ?, note = ?, thumb = ?, no_cmt = ? WHERE id = ?")
            ->execute(array($title, $note, $thumb, $nocm, $id));
    } else {
        $me = rv_me($pdo);
        $tk = bin2hex(random_bytes(16));
        $who = $me ? (isset($me['display_name']) && $me['display_name'] !== '' ? $me['display_name'] : $me['username']) : '';
        $psg = rv_newSlug($pdo, 'video_playlists');
        $st = $pdo->prepare("INSERT INTO `video_playlists` (token, slug, title, note, thumb, no_cmt, created_by) VALUES (?,?,?,?,?,?,?)");
        $st->execute(array($tk, $psg, $title, $note, $thumb, $nocm, $who));
        $id = (int) $pdo->lastInsertId();
    }
    if ($ids !== null) {
        $pdo->prepare("DELETE FROM `video_playlist_items` WHERE playlist_id = ?")->execute(array($id));
        $ins = $pdo->prepare("INSERT INTO `video_playlist_items` (playlist_id, review_id, sort) VALUES (?,?,?)");
        $n = 0;
        foreach ($ids as $rid) {
            $rid = (int) $rid;
            if ($rid > 0) { $ins->execute(array($id, $rid, $n)); $n++; }
        }
    }
    /* APSA1926: ap che do chi xem xuong tung video trong playlist */
    try {
        $pdo->prepare("UPDATE `video_reviews` r
                         JOIN `video_playlist_items` i ON i.review_id = r.id
                          SET r.no_cmt = ?
                        WHERE i.playlist_id = ?")->execute(array($nocm, $id));
    } catch (Exception $e) {}
    rv_ok(array('id' => $id, 'token' => $tk, 'no_cmt' => $nocm));
}

case 'pl-items': {
    rv_needRead($pdo);
    $id = (int) (isset($_GET['id']) ? $_GET['id'] : 0);
    $st = $pdo->prepare("SELECT review_id FROM `video_playlist_items` WHERE playlist_id = ? ORDER BY sort ASC, id ASC");
    $st->execute(array($id));
    rv_ok(array('items' => array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))));
}

/* APSA1825: upload anh thumbnail cho playlist */
case 'pl-thumb': {
    rv_needAdmin($pdo);
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) rv_fail('Khong nhan duoc file anh.');
    if ((int) $_FILES['file']['size'] > 5 * 1024 * 1024) rv_fail('Anh toi da 5MB.');
    $inf = @getimagesize($_FILES['file']['tmp_name']);
    $ok  = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp');
    if (!$inf || !isset($ok[$inf[2]])) rv_fail('Chi nhan anh JPG, PNG hoac WEBP.');
    $dir = dirname(__DIR__) . '/uploads/playlists';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) rv_fail('Khong tao duoc thu muc anh.', 500);
    $fn = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ok[$inf[2]];
    if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dir . '/' . $fn)) rv_fail('Khong luu duoc anh.', 500);
    @chmod($dir . '/' . $fn, 0644);
    rv_ok(array('thumb' => 'uploads/playlists/' . $fn, 'w' => (int) $inf[0], 'h' => (int) $inf[1]));
}

case 'pl-toggle': {
    rv_needAdmin($pdo);
    $id = (int) (isset($B['id']) ? $B['id'] : 0);
    $a  = (int) !!(isset($B['active']) ? $B['active'] : 0);
    $pdo->prepare("UPDATE `video_playlists` SET active = ? WHERE id = ?")->execute(array($a, $id));
    rv_ok(array('id' => $id, 'active' => $a));
}

case 'pl-del': {
    rv_needAdmin($pdo);
    $id = (int) (isset($B['id']) ? $B['id'] : 0);
    $pdo->prepare("DELETE FROM `video_playlist_items` WHERE playlist_id = ?")->execute(array($id));
    $pdo->prepare("DELETE FROM `video_playlists` WHERE id = ?")->execute(array($id));
    rv_ok(array('id' => $id));
}

/* Cong khai theo token */
case 'pl-open': {
    $t = preg_replace('/[^a-z0-9]/', '', strtolower((string) (isset($_GET['t']) ? $_GET['t'] : '')));
    if (strlen($t) < 6 || strlen($t) > 40) rv_fail('Link không hợp lệ.', 404);
    $st = $pdo->prepare("SELECT * FROM `video_playlists` WHERE token = ? OR slug = ? LIMIT 1");
    $st->execute(array($t, $t));
    $p = $st->fetch();
    if (!$p) rv_fail('Link không tồn tại.', 404);
    if (!(int) $p['active'] && rv_lvl($pdo) < 2) rv_fail('Link đã bị tắt.', 403);
    $st = $pdo->prepare("SELECT r.token, r.title, r.file_name, r.file_size, r.active,
            (SELECT COUNT(*) FROM `video_comments` c WHERE c.review_id = r.id) AS n_cmt,
            (SELECT COUNT(*) FROM `video_comments` c WHERE c.review_id = r.id AND c.resolved = 0) AS n_open
        FROM `video_playlist_items` i JOIN `video_reviews` r ON r.id = i.review_id
        WHERE i.playlist_id = ? ORDER BY i.sort ASC, i.id ASC");
    $st->execute(array((int) $p['id']));
    $rows = array();
    foreach ($st->fetchAll() as $r) { if ((int) $r['active']) { unset($r['active']); $rows[] = $r; } }
    rv_ok(array('title' => $p['title'], 'note' => $p['note'], 'thumb' => isset($p['thumb']) ? $p['thumb'] : '', 'videos' => $rows));
}

case 'toggle': {
    rv_needAdmin($pdo);
    $id = (int) ($B['id'] ?? 0);
    $v  = (int) !!($B['active'] ?? 0);
    if (!$id) rv_fail('Thiếu id');
    $pdo->prepare("UPDATE `video_reviews` SET active = ? WHERE id = ?")->execute(array($v, $id));
    rv_ok(array('id' => $id, 'active' => $v));
}

case 'del': {
    rv_needAdmin($pdo);
    $id = (int) ($B['id'] ?? 0);
    if (!$id) rv_fail('Thiếu id');
    $st = $pdo->prepare("SELECT img FROM `video_comments` WHERE review_id = ? AND img IS NOT NULL");
    $st->execute(array($id));
    foreach ($st->fetchAll() as $c) @unlink(RV_DIR . '/' . $c['img']);
    $pdo->prepare("DELETE FROM `video_comments` WHERE review_id = ?")->execute(array($id));
    $pdo->prepare("DELETE FROM `video_reviews` WHERE id = ?")->execute(array($id));
    rv_ok(array('id' => $id));
}

case 'cedit': {
    $r    = rv_byToken($pdo, isset($B['t']) ? $B['t'] : (isset($_GET['t']) ? $_GET['t'] : ''));
    $id   = (int) (isset($B['id']) ? $B['id'] : 0);
    $body = rv_s(isset($B['body']) ? $B['body'] : '', 4000);
    $rvOk = substr(preg_replace('/[^a-zA-Z0-9]/', '', (string) (isset($B['ok']) ? $B['ok'] : '')), 0, 40);
    if (!$id) rv_fail('Thiếu id');
    $st = $pdo->prepare("SELECT id, review_id, owner_key, img FROM `video_comments` WHERE id = ?");
    $st->execute(array($id));
    $c = $st->fetch();
    if (!$c || (int) $c['review_id'] !== (int) $r['id']) rv_fail('Không tìm thấy góp ý.');
    $isAdmin = rv_lvl($pdo) >= 2;
    $isMine  = ($rvOk !== '' && (string) $c['owner_key'] === $rvOk);
    if (!$isAdmin && !$isMine) rv_fail('Bạn chỉ có thể sửa góp ý của mình.', 403);
    if ($body === '' && empty($c['img'])) rv_fail('Nội dung không được để trống.');
    $pdo->prepare("UPDATE `video_comments` SET body = ?, edited_at = NOW() WHERE id = ?")->execute(array($body, $id));
    rv_ok(array('id' => $id));
}

case 'cdel': {
    rv_needAdmin($pdo);
    $id = (int) ($B['id'] ?? 0);
    if (!$id) rv_fail('Thiếu id');
    $st = $pdo->prepare("SELECT img FROM `video_comments` WHERE id = ?");
    $st->execute(array($id));
    $c = $st->fetch();
    if ($c && $c['img']) @unlink(RV_DIR . '/' . $c['img']);
    $pdo->prepare("DELETE FROM `video_comments` WHERE id = ?")->execute(array($id));
    rv_ok(array('id' => $id));
}

case 'resolve': {
    rv_needAdmin($pdo);
    $id = (int) ($B['id'] ?? 0);
    $v  = (int) !!($B['resolved'] ?? 0);
    if (!$id) rv_fail('Thiếu id');
    $pdo->prepare("UPDATE `video_comments` SET resolved = ? WHERE id = ?")->execute(array($v, $id));
    rv_ok(array('id' => $id, 'resolved' => $v));
}

/* ═══════════ CÔNG KHAI (token) ═══════════ */
case 'open': {
    $r = rv_byToken($pdo, $_GET['t'] ?? '');
    $isAdm = rv_lvl($pdo) >= 2 ? 1 : 0;
    /* APSA1826: cac phien ban cung chuoi */
    $rootId = (int) (isset($r['root_id']) && $r['root_id'] ? $r['root_id'] : $r['id']);
    $vers = array();
    try {
        $vs = $pdo->prepare("SELECT id, token, title, file_name, ver, active, created_at, appr_at, appr_by,
                    (SELECT COUNT(*) FROM `video_comments` c WHERE c.review_id = v.id AND c.resolved = 0) AS n_open,
                    (SELECT COUNT(*) FROM `video_comments` c WHERE c.review_id = v.id) AS n_cmt
               FROM `video_reviews` v
              WHERE v.id = ? OR v.root_id = ?
           ORDER BY v.ver ASC, v.id ASC");
        $vs->execute(array($rootId, $rootId));
        foreach ($vs->fetchAll() as $x) {
            if (!$isAdm && !(int) $x['active'] && (int) $x['id'] !== (int) $r['id']) continue;
            $vers[] = array(
                'token' => $x['token'], 'ver' => (int) $x['ver'], 'title' => $x['title'],
                'file_name' => $x['file_name'], 'active' => (int) $x['active'],
                'created_at' => $x['created_at'],
                'n_open' => (int) $x['n_open'], 'n_cmt' => (int) $x['n_cmt'],
                'cur' => ((int) $x['id'] === (int) $r['id']) ? 1 : 0,
                        'appr_at' => $x['appr_at'], 'appr_by' => (string) $x['appr_by'],
            );
        }
    } catch (Exception $e) { $vers = array(); }
    $latest = '';
    foreach ($vers as $x) if ((int) $x['active']) $latest = $x['token'];
    if ($latest === '' && $vers) $latest = $vers[count($vers) - 1]['token'];

        /* APSA1922: ban da duoc khach duyet (neu co) */
        $appr = null;
        try {
            $ap = $pdo->prepare("SELECT token, ver, appr_by, appr_at FROM `video_reviews`
                                  WHERE (id = ? OR root_id = ?) AND appr_at IS NOT NULL
                                  ORDER BY ver DESC LIMIT 1");
            $ap->execute(array($rootId, $rootId));
            $ax = $ap->fetch();
            if ($ax) $appr = array('token' => $ax['token'], 'ver' => (int) $ax['ver'],
                                   'by' => (string) $ax['appr_by'], 'at' => $ax['appr_at']);
        } catch (Exception $e) { $appr = null; }

        $nView = 0;                                            /* APSA1927 */
        try {
            $nv = $pdo->prepare("SELECT COUNT(DISTINCT vkey) FROM `video_views` WHERE review_id = ?");
            $nv->execute(array((int) $r['id']));
            $nView = (int) $nv->fetchColumn();
        } catch (Exception $e) { $nView = 0; }

    rv_ok(array(
        'title' => $r['title'], 'note' => $r['note'], 'file_name' => $r['file_name'],
        'size' => (int) $r['file_size'], 'created_at' => $r['created_at'],
        'admin' => $isAdm,
        'ver' => (int) (isset($r['ver']) ? $r['ver'] : 1),
        'versions' => $vers,
        'latest' => $latest,
            'appr'     => $appr,
            'no_cmt'   => (int) (isset($r['no_cmt']) ? $r['no_cmt'] : 0),
            'views'    => $nView,
    ));
}

/* ===== APSA1927: ghi nhan luot xem ===== */
case 'vping': {
    $r  = rv_byToken($pdo, $B['t'] ?? ($_GET['t'] ?? ''), false);
    $vk = strtolower(preg_replace('/[^a-f0-9]/', '', (string) ($B['v'] ?? '')));
    if (strlen($vk) !== 32) rv_fail('Thiếu mã phiên', 400);
    $rid = (int) $r['id'];
    $pid = 0;
    $pt  = strtolower(preg_replace('/[^a-f0-9]/', '', (string) ($B['p'] ?? '')));
    if (strlen($pt) === 32) {
        $q = $pdo->prepare("SELECT id FROM `video_playlists` WHERE token = ? LIMIT 1");
        $q->execute(array($pt)); $pid = (int) $q->fetchColumn();
    }
    /* nguoi APSA dang nhap thi khong tinh vao luot xem cua khach */
    if (rv_lvl($pdo) >= 1) {
        $n0 = $pdo->prepare("SELECT COUNT(DISTINCT vkey) FROM `video_views` WHERE review_id = ?");
        $n0->execute(array($rid));
        rv_ok(array('view' => 0, 'views' => (int) $n0->fetchColumn(), 'skip' => 1));
    }
    $vid = (int) ($B['view'] ?? 0);
    if ($vid > 0) {
        $ck = $pdo->prepare("SELECT id FROM `video_views` WHERE id = ? AND vkey = ? AND review_id = ?");
        $ck->execute(array($vid, $vk, $rid));
        if (!$ck->fetchColumn()) $vid = 0;
    }
    if ($vid <= 0) {
        $q = $pdo->prepare("SELECT id FROM `video_views`
                             WHERE review_id = ? AND vkey = ?
                               AND created_at > DATE_SUB(NOW(), INTERVAL 6 HOUR)
                             ORDER BY id DESC LIMIT 1");
        $q->execute(array($rid, $vk));
        $vid = (int) $q->fetchColumn();
    }
    if ($vid <= 0) {
        $ip  = rvw_ip();
        $ua  = substr((string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''), 0, 255);
        $g   = null;
        $old = $pdo->prepare("SELECT country, city, isp FROM `video_views`
                               WHERE vkey = ? AND country <> '' ORDER BY id DESC LIMIT 1");
        $old->execute(array($vk));
        $g = $old->fetch();
        if (!$g) $g = rvw_geo($ip);
        $pdo->prepare("INSERT INTO `video_views`
                (review_id, playlist_id, vkey, ip, country, city, isp, ua, device, last_at)
                VALUES (?,?,?,?,?,?,?,?,?,NOW())")
            ->execute(array($rid, $pid, $vk, $ip,
                (string) (isset($g['country']) ? $g['country'] : ''),
                (string) (isset($g['city']) ? $g['city'] : ''),
                (string) (isset($g['isp']) ? $g['isp'] : ''),
                $ua, rvw_device($ua)));
        $vid = (int) $pdo->lastInsertId();
    }
    $sec = max(0, min(86400, (int) ($B['secs'] ?? 0)));
    $pos = max(0, min(999999999, (int) ($B['pos'] ?? 0)));
    $dur = max(0, min(999999999, (int) ($B['dur'] ?? 0)));
    $pdo->prepare("UPDATE `video_views`
                      SET last_at = NOW(), secs = GREATEST(secs, ?), max_pos = GREATEST(max_pos, ?),
                          dur = IF(? > 0, ?, dur), playlist_id = IF(playlist_id = 0, ?, playlist_id)
                    WHERE id = ?")
        ->execute(array($sec, $pos, $dur, $dur, $pid, $vid));
    $n = $pdo->prepare("SELECT COUNT(DISTINCT vkey) FROM `video_views` WHERE review_id = ?");
    $n->execute(array($rid));
    rv_ok(array('view' => $vid, 'views' => (int) $n->fetchColumn()));
}

/* ===== APSA1930: thong ke luot xem ===== */
case 'stats': {
    rv_needRead($pdo);
    $kind = (($B['kind'] ?? 'rv') === 'pl') ? 'pl' : 'rv';
    $id   = (int) ($B['id'] ?? 0);
    if ($id <= 0) rv_fail('Thieu id', 400);
    if ($kind === 'pl') {
        $q = $pdo->prepare("SELECT title FROM `video_playlists` WHERE id = ?");
        $q->execute(array($id)); $title = (string) $q->fetchColumn();
        $wh = "v.playlist_id = ?"; $pr = array($id);
    } else {
        $q = $pdo->prepare("SELECT title FROM `video_reviews` WHERE id = ?");
        $q->execute(array($id)); $title = (string) $q->fetchColumn();
        $wh = "(v.review_id = ? OR v.review_id IN (SELECT id FROM `video_reviews` WHERE root_id = ?))";
        $pr = array($id, $id);
    }
    $s = $pdo->prepare("SELECT COUNT(*) AS opens, COUNT(DISTINCT v.vkey) AS people,
            COALESCE(SUM(v.secs),0) AS secs, MAX(v.last_at) AS last_at
        FROM `video_views` v WHERE " . $wh);
    $s->execute($pr); $sum = $s->fetch(PDO::FETCH_ASSOC);
    $t = $pdo->prepare("SELECT v.review_id AS rid, r.title AS title, r.ver AS ver,
            COUNT(*) AS opens, COUNT(DISTINCT v.vkey) AS people,
            COALESCE(SUM(v.secs),0) AS secs, MAX(v.dur) AS dur, MAX(v.max_pos) AS pos
        FROM `video_views` v LEFT JOIN `video_reviews` r ON r.id = v.review_id
        WHERE " . $wh . " GROUP BY v.review_id, r.title, r.ver ORDER BY opens DESC");
    $t->execute($pr); $vids = $t->fetchAll(PDO::FETCH_ASSOC);
    $p = $pdo->prepare("SELECT v.vkey AS vkey, COUNT(*) AS opens,
            COALESCE(SUM(v.secs),0) AS secs, MAX(v.last_at) AS last_at,
            MIN(v.created_at) AS first_at, MAX(v.country) AS country,
            MAX(v.city) AS city, MAX(v.device) AS device, MAX(v.isp) AS isp
        FROM `video_views` v WHERE " . $wh . " GROUP BY v.vkey ORDER BY secs DESC LIMIT 300");
    $p->execute($pr); $ppl = $p->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ppl as $k => $r) {
        $ppl[$k]['who'] = 'Khách #' . strtoupper(substr((string) $r['vkey'], 0, 4));
        unset($ppl[$k]['vkey']);
    }
    rv_ok(array('kind' => $kind, 'title' => $title, 'sum' => $sum, 'vids' => $vids, 'people' => $ppl));
}

/* ===== APSA1922: khach duyet ban video ===== */
case 'approve': {
    $r  = rv_byToken($pdo, $B['t'] ?? ($_GET['t'] ?? ''));
    $nm = trim(rv_s($B['name'] ?? '', 120));
    if (mb_strlen($nm) < 2) rv_fail('Vui lòng nhập tên người duyệt.', 400);
    $rt = (int) ((isset($r['root_id']) && $r['root_id']) ? $r['root_id'] : $r['id']);
    $q  = $pdo->prepare("SELECT id, ver, appr_by FROM `video_reviews`
                          WHERE (id = ? OR root_id = ?) AND appr_at IS NOT NULL LIMIT 1");
    $q->execute(array($rt, $rt));
    $ex = $q->fetch();
    if ($ex && (int) $ex['id'] !== (int) $r['id']) {
        rv_fail('Video này đã được duyệt ở bản V' . (int) $ex['ver'] . ' rồi.', 409);
    }
    if ($ex) rv_ok(array('id' => (int) $r['id'], 'by' => (string) $ex['appr_by'], 'again' => 1));
    $pdo->prepare("UPDATE `video_reviews` SET appr_at = NOW(), appr_by = ? WHERE id = ?")
        ->execute(array($nm, (int) $r['id']));
    $pdo->prepare("INSERT INTO `video_comments` (review_id, t_ms, author, body)
                   VALUES (?,0,?,?)")
        ->execute(array((int) $r['id'], $nm, '[Đã duyệt] Bản V' . (int) (isset($r['ver']) ? $r['ver'] : 1) . ' được duyệt.'));
    rv_ok(array('id' => (int) $r['id'], 'by' => $nm));
}

case 'unapprove': {
    rv_needAdmin($pdo);
    $r  = rv_byToken($pdo, $B['t'] ?? ($_GET['t'] ?? ''), false);
    $rt = (int) ((isset($r['root_id']) && $r['root_id']) ? $r['root_id'] : $r['id']);
    $pdo->prepare("UPDATE `video_reviews` SET appr_at = NULL, appr_by = ''
                    WHERE id = ? OR root_id = ?")->execute(array($rt, $rt));
    rv_ok(array('id' => (int) $r['id']));
}

case 'comments': {
    $r = rv_byToken($pdo, $_GET['t'] ?? '');
    $st = $pdo->prepare("SELECT id, t_ms, author, body, img, resolved, created_at, edited_at, owner_key
        FROM `video_comments` WHERE review_id = ? ORDER BY t_ms ASC, id ASC");
    $st->execute(array((int) $r['id']));
    $rvOk = substr(preg_replace('/[^a-zA-Z0-9]/', '', (string) (isset($_GET['ok']) ? $_GET['ok'] : '')), 0, 40);
    $rows = array();
    foreach ($st->fetchAll() as $c) {
        $c['has_img'] = $c['img'] ? 1 : 0;
        $c['mine']    = ($rvOk !== '' && (string) $c['owner_key'] === $rvOk) ? 1 : 0;
        unset($c['img'], $c['owner_key']);
        $rows[] = $c;
    }
    rv_ok(array('rows' => $rows, 'admin' => rv_lvl($pdo) >= 2 ? 1 : 0));
}

case 'comment': {
    $r = rv_byToken($pdo, $B['t'] ?? ($_GET['t'] ?? ''));
    $author = rv_s($B['author'] ?? '', 120);
    $body   = rv_s($B['body'] ?? '', 4000);
    $tms    = max(0, (int) ($B['t_ms'] ?? 0));
    $img    = isset($B['img']) ? (string) $B['img'] : '';
    $ap0 = null;
        try {
            $rt0 = (int) ((isset($r['root_id']) && $r['root_id']) ? $r['root_id'] : $r['id']);
            $q0  = $pdo->prepare("SELECT ver FROM `video_reviews` WHERE (id = ? OR root_id = ?) AND appr_at IS NOT NULL LIMIT 1");
            $q0->execute(array($rt0, $rt0));
            $ap0 = $q0->fetch();
        } catch (Exception $e) { $ap0 = null; }
        if ($ap0 && rv_lvl($pdo) < 2) rv_fail('Video này đã được duyệt nên không nhận thêm góp ý.', 403);
        if (!empty($r['no_cmt']) && rv_lvl($pdo) < 2) rv_fail('Link này ở chế độ chỉ xem, không nhận góp ý.', 403);

        if ($author === '') rv_fail('Nhập tên của bạn trước khi gửi.');
    if ($body === '' && $img === '') rv_fail('Nhập nội dung hoặc đính hình.');

    $fname = null;
    if ($img !== '') {
        if (!preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#', $img, $m)) rv_fail('Ảnh không hợp lệ.');
        $raw = base64_decode(substr($img, strpos($img, ',') + 1), true);
        if ($raw === false) rv_fail('Ảnh hỏng.');
        if (strlen($raw) > RV_MAX_IMG) rv_fail('Ảnh quá lớn (tối đa 8MB).');
        $dir = RV_DIR . '/' . (int) $r['id'];
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
        $fname = (int) $r['id'] . '/' . bin2hex(random_bytes(10)) . '.' . $ext;
        if (file_put_contents(RV_DIR . '/' . $fname, $raw) === false) rv_fail('Không lưu được ảnh.', 500);
    }
    $rvOk = substr(preg_replace('/[^a-zA-Z0-9]/', '', (string) (isset($B['ok']) ? $B['ok'] : '')), 0, 40);
    $st = $pdo->prepare("INSERT INTO `video_comments` (review_id, t_ms, author, body, img, owner_key) VALUES (?,?,?,?,?,?)");
    $st->execute(array((int) $r['id'], $tms, $author, $body, $fname, $rvOk));
    rv_ok(array('id' => (int) $pdo->lastInsertId()));
}

case 'img': {
    $r = rv_byToken($pdo, $_GET['t'] ?? '');
    $id = (int) ($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT img FROM `video_comments` WHERE id = ? AND review_id = ?");
    $st->execute(array($id, (int) $r['id']));
    $c = $st->fetch();
    if (!$c || !$c['img']) rv_fail('Không có ảnh.', 404);
    $p = RV_DIR . '/' . $c['img'];
    if (!is_file($p)) rv_fail('Ảnh không còn.', 404);
    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
    header('Content-Type: image/' . ($ext === 'jpg' ? 'jpeg' : $ext));
    header('Content-Length: ' . filesize($p));
    header('Cache-Control: private, max-age=86400');
    readfile($p);
    exit;
}

case 'stream': {
    $r = rv_byToken($pdo, $_GET['t'] ?? '');
    $src = rv_dl($pdo, $r);
    // Nha khoa session: neu khong, request thu 2 cua trinh phat se bi treo cho khoa.
    if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
    @header_remove('Pragma'); @header_remove('Expires');
    @set_time_limit(0);
    while (ob_get_level()) ob_end_clean();

    $range = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : '';
    $ext = strtolower(pathinfo($r['file_name'], PATHINFO_EXTENSION));
    $mime = $ext === 'webm' ? 'video/webm' : ($ext === 'mov' ? 'video/quicktime' : 'video/mp4');

    $hdr = array('Accept: */*');
    if ($range !== '') $hdr[] = 'Range: ' . $range;

    $sentHeaders = false;
    $ch = curl_init($src);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 262144);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $line) use (&$sentHeaders, $mime) {
        $l = trim($line);
        if (stripos($l, 'HTTP/') === 0) {
            $p = explode(' ', $l);
            if (isset($p[1])) http_response_code((int) $p[1]);
            $sentHeaders = true;
        } elseif (preg_match('/^(Content-Length|Content-Range|Accept-Ranges):/i', $l)) {
            header($l);
        }
        return strlen($line);
    });
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($c, $data) use (&$sentHeaders, $mime) {
        static $once = false;
        if (!$once) {
            $once = true;
            header('Content-Type: ' . $mime);
            header('Accept-Ranges: bytes');
            header('Cache-Control: private, max-age=600');
            header('Content-Disposition: inline');
        }
        echo $data;
        flush();
        return strlen($data);
    });
    curl_exec($ch);
    curl_close($ch);
    exit;
}

default:
    rv_fail('Hành động không hợp lệ: ' . $ACT, 404);
}
