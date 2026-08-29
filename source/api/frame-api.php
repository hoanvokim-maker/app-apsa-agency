<?php
/* frame-api.php - Module Frame Avatar
 * Action cong khai (khong can dang nhap): p-event, p-hit, p-use
 * Action quan tri (can dang nhap):        events, event-save, event-del,
 *                                         frames, frame-up, frame-del, frame-sort, frame-name
 */
require_once __DIR__ . '/session-boot.php';
require_once __DIR__ . '/db-config.php';

function fr_out($d, $c = 200) { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fr_fail($m, $c = 400) { fr_out(array('ok' => false, 'error' => $m), $c); }
function fr_body() { $r = file_get_contents('php://input'); if (!$r) return array(); $j = json_decode($r, true); return is_array($j) ? $j : array(); }

function fr_pdo() {
    static $p = null;
    if ($p instanceof PDO) return $p;
    try {
        $p = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
    } catch (PDOException $e) { fr_fail('Khong ket noi duoc co so du lieu.', 500); }
    return $p;
}

function fr_boot() {
    $p = fr_pdo();
    $p->exec("CREATE TABLE IF NOT EXISTS `frame_events` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `slug` VARCHAR(80) NOT NULL,
        `name` VARCHAR(200) NOT NULL,
        `note` VARCHAR(400) DEFAULT NULL,
        `start_at` DATE DEFAULT NULL,
        `end_at` DATE DEFAULT NULL,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `views` INT UNSIGNED NOT NULL DEFAULT 0,
        `uses` INT UNSIGNED NOT NULL DEFAULT 0,
        `created_by` VARCHAR(120) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `u_slug` (`slug`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $p->exec("CREATE TABLE IF NOT EXISTS `frame_items` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `event_id` INT UNSIGNED NOT NULL,
        `name` VARCHAR(200) DEFAULT NULL,
        `ratio` VARCHAR(8) NOT NULL DEFAULT '1:1',
        `file` VARCHAR(300) NOT NULL,
        `w` INT UNSIGNED NOT NULL DEFAULT 0,
        `h` INT UNSIGNED NOT NULL DEFAULT 0,
        `sort_order` INT NOT NULL DEFAULT 0,
        `uses` INT UNSIGNED NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `k_ev` (`event_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function fr_me() {
    static $me = null;
    if ($me !== null) return $me;
    $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if ($uid <= 0) fr_fail('Chua dang nhap.', 401);
    $st = fr_pdo()->prepare('SELECT * FROM app_users WHERE id = ? LIMIT 1');
    $st->execute(array($uid));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) fr_fail('Chua dang nhap.', 401);
    if (isset($r['active']) && (int) $r['active'] === 0) fr_fail('Tai khoan da bi khoa.', 403);
    $me = array(
        'id' => (int) $r['id'],
        'name' => trim((string) (!empty($r['display_name']) ? $r['display_name'] : $r['username'])),
        'role' => isset($r['role']) ? (string) $r['role'] : ''
    );
    return $me;
}
function fr_is_admin() { $m = fr_me(); return strcasecmp($m['role'], 'admin') === 0; }

function fr_slug($s) {
    $s = trim(mb_strtolower((string) $s, 'UTF-8'));
    $from = array('à','á','ạ','ả','ã','â','ầ','ấ','ậ','ẩ','ẫ','ă','ằ','ắ','ặ','ẳ','ẵ','è','é','ẹ','ẻ','ẽ','ê','ề','ế','ệ','ể','ễ','ì','í','ị','ỉ','ĩ','ò','ó','ọ','ỏ','õ','ô','ồ','ố','ộ','ổ','ỗ','ơ','ờ','ớ','ợ','ở','ỡ','ù','ú','ụ','ủ','ũ','ư','ừ','ứ','ự','ử','ữ','ỳ','ý','ỵ','ỷ','ỹ','đ');
    $to   = array('a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','e','e','e','e','e','e','e','e','e','e','e','i','i','i','i','i','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','u','u','u','u','u','u','u','u','u','u','u','y','y','y','y','y','d');
    $s = str_replace($from, $to, $s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string) $s, '-');
    if ($s === '') $s = 'event';
    return substr($s, 0, 70);
}

function fr_ratio($w, $h) {
    if ($w <= 0 || $h <= 0) return '1:1';
    $r = $w / $h;
    $best = '1:1'; $bd = abs($r - 1);
    if (abs($r - 16 / 9) < $bd) { $best = '16:9'; $bd = abs($r - 16 / 9); }
    if (abs($r - 9 / 16) < $bd) { $best = '9:16'; }
    return $best;
}

function fr_dir($eid) {
    $d = dirname(__DIR__) . '/uploads/frames/' . (int) $eid;
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

/* ---------------------------------------------------------------- */

fr_boot();
$ACT = isset($_GET['action']) ? (string) $_GET['action'] : '';
$pdo = fr_pdo();

/* ============ CONG KHAI - khong can dang nhap ============ */

if ($ACT === 'p-event') {
    $slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';
    if ($slug === '') fr_fail('Thieu ma su kien.', 404);
    $st = $pdo->prepare('SELECT id, slug, name, note, start_at, end_at, active FROM frame_events WHERE slug = ? LIMIT 1');
    $st->execute(array($slug));
    $e = $st->fetch(PDO::FETCH_ASSOC);
    if (!$e) fr_fail('Khong tim thay su kien.', 404);
    $today = date('Y-m-d');
    $state = 'ok';
    if ((int) $e['active'] !== 1) $state = 'off';
    elseif (!empty($e['start_at']) && $today < $e['start_at']) $state = 'early';
    elseif (!empty($e['end_at']) && $today > $e['end_at']) $state = 'ended';
    $fr = array();
    if ($state === 'ok') {
        $q = $pdo->prepare('SELECT id, name, ratio, file, w, h FROM frame_items WHERE event_id = ? ORDER BY sort_order, id');
        $q->execute(array($e['id']));
        $fr = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    fr_out(array('ok' => true, 'state' => $state, 'event' => $e, 'frames' => $fr));
}

if ($ACT === 'p-hit') {
    $slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';
    $st = $pdo->prepare('UPDATE frame_events SET views = views + 1 WHERE slug = ?');
    $st->execute(array($slug));
    fr_out(array('ok' => true));
}

if ($ACT === 'p-use') {
    $fid = isset($_GET['frame']) ? (int) $_GET['frame'] : 0;
    if ($fid <= 0) fr_fail('Thieu frame.', 400);
    $st = $pdo->prepare('SELECT event_id FROM frame_items WHERE id = ? LIMIT 1');
    $st->execute(array($fid));
    $eid = (int) $st->fetchColumn();
    if ($eid <= 0) fr_fail('Khong tim thay frame.', 404);
    $pdo->prepare('UPDATE frame_items SET uses = uses + 1 WHERE id = ?')->execute(array($fid));
    $pdo->prepare('UPDATE frame_events SET uses = uses + 1 WHERE id = ?')->execute(array($eid));
    fr_out(array('ok' => true));
}

/* ============ QUAN TRI - can dang nhap ============ */

$ME = fr_me();

switch ($ACT) {

case 'me':
    fr_out(array('ok' => true, 'me' => $ME, 'admin' => fr_is_admin()));

case 'events': {
    $rows = $pdo->query('SELECT e.*, (SELECT COUNT(*) FROM frame_items f WHERE f.event_id = e.id) AS n_frames
        FROM frame_events e ORDER BY e.active DESC, e.id DESC')->fetchAll(PDO::FETCH_ASSOC);
    fr_out(array('ok' => true, 'rows' => $rows));
}

case 'event-save': {
    $b = fr_body();
    $id = isset($b['id']) ? (int) $b['id'] : 0;
    $name = trim((string) (isset($b['name']) ? $b['name'] : ''));
    if ($name === '') fr_fail('Chua nhap ten su kien.');
    $slug = trim((string) (isset($b['slug']) ? $b['slug'] : ''));
    $slug = fr_slug($slug !== '' ? $slug : $name);
    $chk = $pdo->prepare('SELECT id FROM frame_events WHERE slug = ? AND id <> ? LIMIT 1');
    $chk->execute(array($slug, $id));
    if ($chk->fetchColumn()) { $slug = $slug . '-' . substr(uniqid(), -4); }
    $note = mb_substr(trim((string) (isset($b['note']) ? $b['note'] : '')), 0, 400, 'UTF-8');
    $sa = isset($b['start_at']) && $b['start_at'] !== '' ? substr((string) $b['start_at'], 0, 10) : null;
    $ea = isset($b['end_at']) && $b['end_at'] !== '' ? substr((string) $b['end_at'], 0, 10) : null;
    $ac = !empty($b['active']) ? 1 : 0;
    if ($id > 0) {
        $st = $pdo->prepare('UPDATE frame_events SET slug=?, name=?, note=?, start_at=?, end_at=?, active=? WHERE id=?');
        $st->execute(array($slug, $name, $note, $sa, $ea, $ac, $id));
    } else {
        $st = $pdo->prepare('INSERT INTO frame_events (slug, name, note, start_at, end_at, active, created_by) VALUES (?,?,?,?,?,?,?)');
        $st->execute(array($slug, $name, $note, $sa, $ea, $ac, $ME['name']));
        $id = (int) $pdo->lastInsertId();
    }
    fr_out(array('ok' => true, 'id' => $id, 'slug' => $slug));
}

case 'event-del': {
    $b = fr_body();
    $id = isset($b['id']) ? (int) $b['id'] : 0;
    if ($id <= 0) fr_fail('Thieu id.');
    $q = $pdo->prepare('SELECT file FROM frame_items WHERE event_id = ?');
    $q->execute(array($id));
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $f) {
        $p = dirname(__DIR__) . '/' . ltrim((string) $f, '/');
        if (is_file($p)) @unlink($p);
    }
    $pdo->prepare('DELETE FROM frame_items WHERE event_id = ?')->execute(array($id));
    $pdo->prepare('DELETE FROM frame_events WHERE id = ?')->execute(array($id));
    @rmdir(dirname(__DIR__) . '/uploads/frames/' . $id);
    fr_out(array('ok' => true));
}

case 'frames': {
    $eid = isset($_GET['event']) ? (int) $_GET['event'] : 0;
    $st = $pdo->prepare('SELECT * FROM frame_items WHERE event_id = ? ORDER BY sort_order, id');
    $st->execute(array($eid));
    fr_out(array('ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)));
}

case 'frame-up': {
    $eid = isset($_POST['event']) ? (int) $_POST['event'] : 0;
    if ($eid <= 0) fr_fail('Thieu su kien.');
    $chk = $pdo->prepare('SELECT id FROM frame_events WHERE id = ? LIMIT 1');
    $chk->execute(array($eid));
    if (!$chk->fetchColumn()) fr_fail('Su kien khong ton tai.', 404);
    if (empty($_FILES['f'])) fr_fail('Chua chon file.');
    $dir = fr_dir($eid);
    $mx = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM frame_items WHERE event_id = ' . $eid)->fetchColumn();
    $names = (array) $_FILES['f']['name'];
    $tmps = (array) $_FILES['f']['tmp_name'];
    $errs = (array) $_FILES['f']['error'];
    $out = array(); $bad = array();
    for ($i = 0; $i < count($names); $i++) {
        if ((int) $errs[$i] !== 0) { $bad[] = $names[$i] . ' (loi tai len)'; continue; }
        $info = @getimagesize($tmps[$i]);
        if (!$info || $info[2] !== IMAGETYPE_PNG) { $bad[] = $names[$i] . ' (chi nhan file PNG)'; continue; }
        $w = (int) $info[0]; $h = (int) $info[1];
        if ($w < 100 || $h < 100) { $bad[] = $names[$i] . ' (anh qua nho)'; continue; }
        $fn = 'f' . $eid . '-' . bin2hex(random_bytes(6)) . '.png';
        if (!@move_uploaded_file($tmps[$i], $dir . '/' . $fn)) { $bad[] = $names[$i] . ' (khong luu duoc)'; continue; }
        @chmod($dir . '/' . $fn, 0644);
        $mx++;
        $rel = 'uploads/frames/' . $eid . '/' . $fn;
        $nm = pathinfo((string) $names[$i], PATHINFO_FILENAME);
        $st = $pdo->prepare('INSERT INTO frame_items (event_id, name, ratio, file, w, h, sort_order) VALUES (?,?,?,?,?,?,?)');
        $st->execute(array($eid, mb_substr($nm, 0, 200, 'UTF-8'), fr_ratio($w, $h), $rel, $w, $h, $mx));
        $out[] = (int) $pdo->lastInsertId();
    }
    fr_out(array('ok' => true, 'added' => count($out), 'ids' => $out, 'skipped' => $bad));
}

case 'frame-del': {
    $b = fr_body();
    $id = isset($b['id']) ? (int) $b['id'] : 0;
    $st = $pdo->prepare('SELECT file FROM frame_items WHERE id = ? LIMIT 1');
    $st->execute(array($id));
    $f = (string) $st->fetchColumn();
    if ($f !== '') { $p = dirname(__DIR__) . '/' . ltrim($f, '/'); if (is_file($p)) @unlink($p); }
    $pdo->prepare('DELETE FROM frame_items WHERE id = ?')->execute(array($id));
    fr_out(array('ok' => true));
}

case 'frame-set': {
    $b = fr_body();
    $id = isset($b['id']) ? (int) $b['id'] : 0;
    if ($id <= 0) fr_fail('Thieu id.');
    if (isset($b['name'])) {
        $st = $pdo->prepare('UPDATE frame_items SET name = ? WHERE id = ?');
        $st->execute(array(mb_substr(trim((string) $b['name']), 0, 200, 'UTF-8'), $id));
    }
    if (isset($b['ratio'])) {
        $r = (string) $b['ratio'];
        if (!in_array($r, array('1:1', '16:9', '9:16'), true)) fr_fail('Ti le khong hop le.');
        $pdo->prepare('UPDATE frame_items SET ratio = ? WHERE id = ?')->execute(array($r, $id));
    }
    if (isset($b['order']) && is_array($b['order'])) {
        $u = $pdo->prepare('UPDATE frame_items SET sort_order = ? WHERE id = ?');
        $i = 0;
        foreach ($b['order'] as $fid) { $u->execute(array(++$i, (int) $fid)); }
    }
    fr_out(array('ok' => true));
}

default:
    fr_fail('Hanh dong khong hop le: ' . $ACT, 404);
}
