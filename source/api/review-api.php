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
function rv_needAdmin(PDO $pdo) { if (!rv_isAdmin($pdo)) rv_fail('Chỉ Admin dùng được chức năng này.', 403); }

/* ── review theo token ─────────────────────────────────────── */
function rv_byToken(PDO $pdo, $t, $needActive = true) {
    $t = preg_replace('/[^a-f0-9]/', '', strtolower((string) $t));
    if (strlen($t) !== 32) rv_fail('Link không hợp lệ.', 404);
    $st = $pdo->prepare("SELECT * FROM `video_reviews` WHERE token = ?");
    $st->execute(array($t));
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
    rv_ok(array('admin' => rv_isAdmin($pdo) ? 1 : 0, 'name' => $me ? ($me['display_name'] ?: $me['username']) : ''));
}

case 'browse': {
    rv_needAdmin($pdo);
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
    $me = rv_me($pdo);
    $tk = bin2hex(random_bytes(16));
    $st = $pdo->prepare("INSERT INTO `video_reviews`
        (token, title, note, drive_id, item_id, file_name, file_size, created_by)
        VALUES (?,?,?,?,?,?,?,?)");
    $st->execute(array($tk, $title, rv_s($B['note'] ?? '', 500), RV_DRIVE, $item, $name,
        (int) ($B['size'] ?? 0), $me ? ($me['display_name'] ?: $me['username']) : ''));
    rv_ok(array('id' => (int) $pdo->lastInsertId(), 'token' => $tk));
}

case 'list': {
    rv_needAdmin($pdo);
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
    rv_ok(array(
        'title' => $r['title'], 'note' => $r['note'], 'file_name' => $r['file_name'],
        'size' => (int) $r['file_size'], 'created_at' => $r['created_at'],
        'admin' => rv_isAdmin($pdo) ? 1 : 0,
    ));
}

case 'comments': {
    $r = rv_byToken($pdo, $_GET['t'] ?? '');
    $st = $pdo->prepare("SELECT id, t_ms, author, body, img, resolved, created_at
        FROM `video_comments` WHERE review_id = ? ORDER BY t_ms ASC, id ASC");
    $st->execute(array((int) $r['id']));
    $rows = array();
    foreach ($st->fetchAll() as $c) {
        $c['has_img'] = $c['img'] ? 1 : 0;
        unset($c['img']);
        $rows[] = $c;
    }
    rv_ok(array('rows' => $rows, 'admin' => rv_isAdmin($pdo) ? 1 : 0));
}

case 'comment': {
    $r = rv_byToken($pdo, $B['t'] ?? ($_GET['t'] ?? ''));
    $author = rv_s($B['author'] ?? '', 120);
    $body   = rv_s($B['body'] ?? '', 4000);
    $tms    = max(0, (int) ($B['t_ms'] ?? 0));
    $img    = isset($B['img']) ? (string) $B['img'] : '';
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
    $st = $pdo->prepare("INSERT INTO `video_comments` (review_id, t_ms, author, body, img) VALUES (?,?,?,?,?)");
    $st->execute(array((int) $r['id'], $tms, $author, $body, $fname));
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
