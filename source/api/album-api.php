<?php
// ============================================================
// APSA — Album ảnh API   /api/album-api.php
//
// Quản lý album ảnh + link chia sẻ bí mật cho khách xem trên điện thoại.
// Ảnh được nén sẵn ở trình duyệt (max ~2000px) rồi upload, server chỉ lưu file.
//
//  --- Cần đăng nhập (nhân sự APSA) ---
//  GET  ?action=list[&trash=1]
//  GET  ?action=detail&id=
//  POST ?action=create              {title, note}
//  POST ?action=update              {id, title, note, cover_photo_id}
//  POST ?action=delete|restore      {id}
//  POST ?action=upload              multipart: album_id, file, thumb, w, h  (1 ảnh / lần)
//  POST ?action=photo-delete        {id}
//  POST ?action=photo-update        {id, caption}
//  POST ?action=photo-sort          {album_id, ids:[...]}
//  GET  ?action=feedback&id=        → tim / ghi chú / ảnh khách đã chọn
//
//  --- Công khai, chỉ cần token (khách) ---
//  GET  ?action=view&k=TOKEN&v=VISITOR
//  POST ?action=like    {k, v, photo_id}
//  POST ?action=pick    {k, v, photo_id}
//  POST ?action=note    {k, v, photo_id, note, name}
// ============================================================

@ini_set('display_errors', '0');

require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/session-boot.php';

function a_ok($data)             { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE); exit; }
function a_fail($msg, $code=400) { header('Content-Type: application/json; charset=utf-8'); http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function a_body() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return $_POST ?: [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : ($_POST ?: []);
}
$B = a_body();

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) { a_fail('DB connection failed', 500); }

// ── Bảng ─────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `album_albums` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token` VARCHAR(32) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `note` VARCHAR(500) DEFAULT NULL,
  `cover_photo_id` INT UNSIGNED DEFAULT NULL,
  `views` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by` VARCHAR(100) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS `album_photos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `album_id` INT UNSIGNED NOT NULL,
  `file` VARCHAR(120) NOT NULL,
  `thumb` VARCHAR(120) NOT NULL,
  `w` INT UNSIGNED NOT NULL DEFAULT 0,
  `h` INT UNSIGNED NOT NULL DEFAULT 0,
  `bytes` INT UNSIGNED NOT NULL DEFAULT 0,
  `caption` VARCHAR(300) DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_album` (`album_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS `album_likes` (
  `photo_id` INT UNSIGNED NOT NULL,
  `visitor` VARCHAR(40) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`photo_id`, `visitor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS `album_picks` (
  `photo_id` INT UNSIGNED NOT NULL,
  `visitor` VARCHAR(40) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`photo_id`, `visitor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS `album_notes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `photo_id` INT UNSIGNED NOT NULL,
  `visitor` VARCHAR(40) NOT NULL,
  `guest_name` VARCHAR(80) DEFAULT NULL,
  `note` VARCHAR(600) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_pv` (`photo_id`, `visitor`), KEY `idx_photo` (`photo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Auth (chỉ cho các action quản lý) ────────────────────────
function currentUser($pdo) {
    if (empty($_SESSION['user_id'])) return null;
    try {
        $st = $pdo->prepare("SELECT id, username, display_name, role FROM `app_users` WHERE id = ? AND active = 1");
        $st->execute([$_SESSION['user_id']]);
        return $st->fetch() ?: null;
    } catch (PDOException $e) { return null; }
}
$ME  = currentUser($pdo);
$WHO = $ME ? ($ME['display_name'] ?: $ME['username']) : '';

$action = $_GET['action'] ?? '';
$PUBLIC = ['view', 'like', 'pick', 'note'];
if (!in_array($action, $PUBLIC, true) && !$ME) a_fail('Unauthorized — vui lòng đăng nhập', 401);

// ── Thư mục ảnh ──────────────────────────────────────────────
define('ALBUM_DIR', dirname(__DIR__) . '/uploads/albums');
define('ALBUM_URL', './uploads/albums');

function a_token() {
    $s = '';
    $abc = 'abcdefghijkmnpqrstuvwxyz23456789';
    for ($i = 0; $i < 22; $i++) $s .= $abc[random_int(0, strlen($abc) - 1)];
    return $s;
}
function a_visitor($v) { $v = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$v); return substr($v, 0, 40); }

function albumByToken($pdo, $k) {
    $k = preg_replace('/[^a-z0-9]/', '', strtolower((string)$k));
    if ($k === '') return null;
    $st = $pdo->prepare("SELECT * FROM album_albums WHERE token = ? AND deleted_at IS NULL");
    $st->execute([$k]);
    return $st->fetch() ?: null;
}
function albumById($pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM album_albums WHERE id = ?");
    $st->execute([(int)$id]);
    return $st->fetch() ?: null;
}
function photoUrl($album, $name) { return ALBUM_URL . '/' . $album['token'] . '/' . $name; }

function loadPhotos($pdo, $album, $visitor = '') {
    $st = $pdo->prepare("SELECT p.*,
            (SELECT COUNT(*) FROM album_likes l WHERE l.photo_id = p.id) AS likes,
            (SELECT COUNT(*) FROM album_picks k WHERE k.photo_id = p.id) AS picks,
            (SELECT COUNT(*) FROM album_notes n WHERE n.photo_id = p.id) AS notes
          FROM album_photos p WHERE p.album_id = ? ORDER BY p.sort_order ASC, p.id ASC");
    $st->execute([$album['id']]);
    $rows = $st->fetchAll();

    $mine = ['likes' => [], 'picks' => [], 'notes' => []];
    if ($visitor !== '') {
        foreach (['likes' => 'album_likes', 'picks' => 'album_picks'] as $key => $tbl) {
            $q = $pdo->prepare("SELECT x.photo_id FROM `$tbl` x JOIN album_photos p ON p.id = x.photo_id
                                 WHERE p.album_id = ? AND x.visitor = ?");
            $q->execute([$album['id'], $visitor]);
            $mine[$key] = array_map('intval', array_column($q->fetchAll(), 'photo_id'));
        }
        $q = $pdo->prepare("SELECT n.photo_id, n.note FROM album_notes n JOIN album_photos p ON p.id = n.photo_id
                             WHERE p.album_id = ? AND n.visitor = ?");
        $q->execute([$album['id'], $visitor]);
        foreach ($q->fetchAll() as $r) $mine['notes'][(int)$r['photo_id']] = $r['note'];
    }

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int)$r['id'], 'url' => photoUrl($album, $r['file']), 'thumb' => photoUrl($album, $r['thumb']),
            'w' => (int)$r['w'], 'h' => (int)$r['h'], 'bytes' => (int)$r['bytes'],
            'caption' => $r['caption'], 'likes' => (int)$r['likes'], 'picks' => (int)$r['picks'], 'notes' => (int)$r['notes'],
            'liked' => in_array((int)$r['id'], $mine['likes'], true),
            'picked' => in_array((int)$r['id'], $mine['picks'], true),
            'my_note' => $mine['notes'][(int)$r['id']] ?? ''
        ];
    }
    return $out;
}

switch ($action) {

// ══════════ QUẢN LÝ ══════════
case 'list': {
    $trash = !empty($_GET['trash']);
    $st = $pdo->query("SELECT a.*,
            (SELECT COUNT(*) FROM album_photos p WHERE p.album_id = a.id) AS photo_count,
            (SELECT COUNT(*) FROM album_likes l JOIN album_photos p ON p.id = l.photo_id WHERE p.album_id = a.id) AS likes,
            (SELECT COUNT(*) FROM album_picks k JOIN album_photos p ON p.id = k.photo_id WHERE p.album_id = a.id) AS picks,
            (SELECT COUNT(*) FROM album_notes n JOIN album_photos p ON p.id = n.photo_id WHERE p.album_id = a.id) AS notes,
            (SELECT CONCAT(p2.thumb) FROM album_photos p2 WHERE p2.album_id = a.id ORDER BY p2.sort_order ASC, p2.id ASC LIMIT 1) AS first_thumb
          FROM album_albums a
         WHERE a.deleted_at IS " . ($trash ? "NOT NULL" : "NULL") . "
         ORDER BY a.updated_at DESC, a.id DESC");
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['photo_count'] = (int)$r['photo_count'];
        $r['likes'] = (int)$r['likes']; $r['picks'] = (int)$r['picks']; $r['notes'] = (int)$r['notes'];
        $r['views'] = (int)$r['views'];
        $r['cover'] = $r['first_thumb'] ? (ALBUM_URL . '/' . $r['token'] . '/' . $r['first_thumb']) : '';
        unset($r['first_thumb']);
    }
    unset($r);
    a_ok($rows);
}

case 'detail': {
    $a = albumById($pdo, $_GET['id'] ?? 0);
    if (!$a) a_fail('Không tìm thấy album', 404);
    $a['id'] = (int)$a['id'];
    a_ok(['album' => $a, 'photos' => loadPhotos($pdo, $a)]);
}

case 'create': {
    $title = trim((string)($B['title'] ?? ''));
    if ($title === '') a_fail('Vui lòng nhập tên album');
    for ($i = 0; $i < 20; $i++) {
        $tok = a_token();
        $c = $pdo->prepare("SELECT id FROM album_albums WHERE token = ?");
        $c->execute([$tok]);
        if (!$c->fetch()) break;
    }
    $st = $pdo->prepare("INSERT INTO album_albums (token, title, note, created_by) VALUES (?,?,?,?)");
    $st->execute([$tok, $title, trim((string)($B['note'] ?? '')), $WHO]);
    $id = (int)$pdo->lastInsertId();
    @mkdir(ALBUM_DIR . '/' . $tok, 0755, true);
    a_ok(['id' => $id, 'token' => $tok, 'message' => 'Đã tạo album']);
}

case 'update': {
    $a = albumById($pdo, $B['id'] ?? 0);
    if (!$a) a_fail('Không tìm thấy album', 404);
    $title = trim((string)($B['title'] ?? $a['title']));
    if ($title === '') a_fail('Vui lòng nhập tên album');
    $st = $pdo->prepare("UPDATE album_albums SET title = ?, note = ? WHERE id = ?");
    $st->execute([$title, trim((string)($B['note'] ?? '')), $a['id']]);
    a_ok(['id' => (int)$a['id'], 'message' => 'Đã lưu album']);
}

case 'delete': {
    $st = $pdo->prepare("UPDATE album_albums SET deleted_at = NOW() WHERE id = ?");
    $st->execute([(int)($B['id'] ?? 0)]);
    a_ok(['message' => 'Đã chuyển album vào thùng rác']);
}

case 'restore': {
    $st = $pdo->prepare("UPDATE album_albums SET deleted_at = NULL WHERE id = ?");
    $st->execute([(int)($B['id'] ?? 0)]);
    a_ok(['message' => 'Đã khôi phục album']);
}

case 'upload': {
    $a = albumById($pdo, $_POST['album_id'] ?? 0);
    if (!$a) a_fail('Không tìm thấy album', 404);
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) a_fail('Không nhận được file ảnh');
    if (empty($_FILES['thumb']) || $_FILES['thumb']['error'] !== UPLOAD_ERR_OK) a_fail('Không nhận được ảnh thu nhỏ');

    $dir = ALBUM_DIR . '/' . $a['token'];
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) a_fail('Không tạo được thư mục ảnh', 500);

    $base = date('YmdHis') . '-' . bin2hex(random_bytes(4));
    $fileName  = $base . '.jpg';
    $thumbName = 't-' . $base . '.jpg';
    if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dir . '/' . $fileName))   a_fail('Không lưu được ảnh', 500);
    if (!@move_uploaded_file($_FILES['thumb']['tmp_name'], $dir . '/' . $thumbName)) a_fail('Không lưu được ảnh thu nhỏ', 500);
    @chmod($dir . '/' . $fileName, 0644); @chmod($dir . '/' . $thumbName, 0644);

    $ord = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM album_photos WHERE album_id = " . (int)$a['id'])->fetchColumn();
    $st = $pdo->prepare("INSERT INTO album_photos (album_id, file, thumb, w, h, bytes, caption, sort_order)
                         VALUES (?,?,?,?,?,?,?,?)");
    $st->execute([$a['id'], $fileName, $thumbName, (int)($_POST['w'] ?? 0), (int)($_POST['h'] ?? 0),
                  filesize($dir . '/' . $fileName), trim((string)($_POST['caption'] ?? '')), $ord]);
    $pid = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE album_albums SET updated_at = NOW() WHERE id = ?")->execute([$a['id']]);

    a_ok(['id' => $pid, 'url' => photoUrl($a, $fileName), 'thumb' => photoUrl($a, $thumbName)]);
}

case 'photo-delete': {
    $id = (int)($B['id'] ?? 0);
    $st = $pdo->prepare("SELECT p.*, a.token FROM album_photos p JOIN album_albums a ON a.id = p.album_id WHERE p.id = ?");
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) a_fail('Không tìm thấy ảnh', 404);
    @unlink(ALBUM_DIR . '/' . $p['token'] . '/' . $p['file']);
    @unlink(ALBUM_DIR . '/' . $p['token'] . '/' . $p['thumb']);
    $pdo->prepare("DELETE FROM album_photos WHERE id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM album_likes WHERE photo_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM album_picks WHERE photo_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM album_notes WHERE photo_id = ?")->execute([$id]);
    a_ok(['message' => 'Đã xoá ảnh']);
}

case 'photo-update': {
    $st = $pdo->prepare("UPDATE album_photos SET caption = ? WHERE id = ?");
    $st->execute([trim((string)($B['caption'] ?? '')), (int)($B['id'] ?? 0)]);
    a_ok(['message' => 'Đã lưu chú thích']);
}

case 'photo-sort': {
    $ids = $B['ids'] ?? [];
    if (!is_array($ids)) a_fail('ids không hợp lệ');
    $st = $pdo->prepare("UPDATE album_photos SET sort_order = ? WHERE id = ? AND album_id = ?");
    $aid = (int)($B['album_id'] ?? 0);
    foreach (array_values($ids) as $i => $pid) $st->execute([$i + 1, (int)$pid, $aid]);
    a_ok(['message' => 'Đã lưu thứ tự']);
}

case 'feedback': {
    $a = albumById($pdo, $_GET['id'] ?? 0);
    if (!$a) a_fail('Không tìm thấy album', 404);
    $st = $pdo->prepare("SELECT n.*, p.file, p.thumb FROM album_notes n JOIN album_photos p ON p.id = n.photo_id
                          WHERE p.album_id = ? ORDER BY n.created_at DESC");
    $st->execute([$a['id']]);
    $notes = [];
    foreach ($st->fetchAll() as $r) {
        $notes[] = ['photo_id' => (int)$r['photo_id'], 'thumb' => photoUrl($a, $r['thumb']),
                    'name' => $r['guest_name'], 'note' => $r['note'], 'at' => $r['created_at']];
    }
    a_ok(['notes' => $notes, 'photos' => loadPhotos($pdo, $a)]);
}

// ══════════ CÔNG KHAI (khách xem bằng link) ══════════
case 'view': {
    $a = albumByToken($pdo, $_GET['k'] ?? '');
    if (!$a) a_fail('Album không tồn tại hoặc đã bị gỡ', 404);
    $v = a_visitor($_GET['v'] ?? '');
    $pdo->prepare("UPDATE album_albums SET views = views + 1 WHERE id = ?")->execute([$a['id']]);
    a_ok([
        'album'  => ['title' => $a['title'], 'note' => $a['note'], 'token' => $a['token']],
        'photos' => loadPhotos($pdo, $a, $v)
    ]);
}

case 'like':
case 'pick': {
    $a = albumByToken($pdo, $B['k'] ?? '');
    if (!$a) a_fail('Album không tồn tại', 404);
    $v = a_visitor($B['v'] ?? '');
    if ($v === '') a_fail('Thiếu mã thiết bị');
    $pid = (int)($B['photo_id'] ?? 0);
    $chk = $pdo->prepare("SELECT id FROM album_photos WHERE id = ? AND album_id = ?");
    $chk->execute([$pid, $a['id']]);
    if (!$chk->fetch()) a_fail('Ảnh không thuộc album này', 404);

    $tbl = ($action === 'like') ? 'album_likes' : 'album_picks';
    $q = $pdo->prepare("SELECT 1 FROM `$tbl` WHERE photo_id = ? AND visitor = ?");
    $q->execute([$pid, $v]);
    if ($q->fetch()) {
        $pdo->prepare("DELETE FROM `$tbl` WHERE photo_id = ? AND visitor = ?")->execute([$pid, $v]);
        $on = false;
    } else {
        $pdo->prepare("INSERT IGNORE INTO `$tbl` (photo_id, visitor) VALUES (?,?)")->execute([$pid, $v]);
        $on = true;
    }
    $n = (int)$pdo->query("SELECT COUNT(*) FROM `$tbl` WHERE photo_id = " . $pid)->fetchColumn();
    a_ok(['photo_id' => $pid, 'on' => $on, 'count' => $n]);
}

case 'note': {
    $a = albumByToken($pdo, $B['k'] ?? '');
    if (!$a) a_fail('Album không tồn tại', 404);
    $v = a_visitor($B['v'] ?? '');
    if ($v === '') a_fail('Thiếu mã thiết bị');
    $pid = (int)($B['photo_id'] ?? 0);
    $chk = $pdo->prepare("SELECT id FROM album_photos WHERE id = ? AND album_id = ?");
    $chk->execute([$pid, $a['id']]);
    if (!$chk->fetch()) a_fail('Ảnh không thuộc album này', 404);

    $note = trim((string)($B['note'] ?? ''));
    $name = trim((string)($B['name'] ?? ''));
    if (mb_strlen($note) > 600) $note = mb_substr($note, 0, 600);
    if ($note === '') {
        $pdo->prepare("DELETE FROM album_notes WHERE photo_id = ? AND visitor = ?")->execute([$pid, $v]);
        a_ok(['photo_id' => $pid, 'note' => '', 'message' => 'Đã xoá ghi chú']);
    }
    $st = $pdo->prepare("INSERT INTO album_notes (photo_id, visitor, guest_name, note) VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE note = VALUES(note), guest_name = VALUES(guest_name)");
    $st->execute([$pid, $v, mb_substr($name, 0, 80), $note]);
    $n = (int)$pdo->query("SELECT COUNT(*) FROM album_notes WHERE photo_id = " . $pid)->fetchColumn();
    a_ok(['photo_id' => $pid, 'note' => $note, 'count' => $n, 'message' => 'Đã gửi ghi chú']);
}

default:
    a_fail('Unknown action: ' . $action, 404);
}
