<?php
// =====================================================
// APSA - Kho Font API   /api/font-api.php
// Actions: me, list, save, delete, upload, file-save,
//          file-delete, set-preview, file, download, zip
// Quyen: moi nguoi dang nhap deu xem / them / sua / xoa duoc.
// =====================================================
require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/session-boot.php';
require_once __DIR__ . '/act-log.php';

$ROOT    = dirname(__DIR__);
$UPDIR   = $ROOT . '/uploads/fonts';
$MAXBYTE = 120 * 1024 * 1024;

const FT_ALLOW = array('ttf', 'otf', 'ttc', 'woff', 'woff2', 'zip');
const FT_WEB   = array('ttf', 'otf', 'woff', 'woff2');

$action = (string) (isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : ''));
$IS_BIN = in_array($action, array('file', 'download', 'zip'), true);
if ($action === 'upload' || $IS_BIN) {
    $B = array_merge($_GET, $_POST);
} else {
    $RAW = (string) file_get_contents('php://input');
    $B   = $RAW !== '' ? (json_decode($RAW, true) ?: array()) : array_merge($_GET, $_POST);
}
if (!$IS_BIN) header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'DB connection failed'));
    exit;
}

function ft_labels() {
    return array(
        'save'        => 'Thêm / sửa bộ font',
        'delete'      => 'Xoá bộ font',
        'upload'      => 'Tải file font lên',
        'file-save'   => 'Sửa thông tin file font',
        'file-delete' => 'Xoá file font',
        'set-preview' => 'Đổi font xem thử',
    );
}
function ok($d = array())   { al_auto2('font', ft_labels()); echo json_encode(array('ok' => true, 'data' => $d)); exit; }
function fail($m, $c = 400) { http_response_code($c); echo json_encode(array('ok' => false, 'error' => $m)); exit; }

function ft_user($pdo) {
    static $u = false;
    if ($u !== false) return $u;
    if (empty($_SESSION['user_id'])) { $u = null; return $u; }
    $st = $pdo->prepare('SELECT `id`, `username`, `display_name`, `role` FROM `app_users` WHERE `id` = ? AND `active` = 1');
    $st->execute(array((int) $_SESSION['user_id']));
    $u = $st->fetch() ?: null;
    return $u;
}
function ft_ext($n)  { return strtolower(pathinfo($n, PATHINFO_EXTENSION)); }
function ft_safe($n) {
    $n = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $n);
    $n = trim(preg_replace('/-+/', '-', $n), '-.');
    return $n === '' ? 'font' : substr($n, 0, 80);
}
// Doan ten kieu chu tu ten file: Montserrat-BoldItalic.ttf -> Bold Italic
function ft_guess_style($orig) {
    $b = pathinfo((string) $orig, PATHINFO_FILENAME);
    $b = preg_replace('/[_\\.]+/', '-', $b);
    $p = explode('-', $b);
    $tail = count($p) > 1 ? $p[count($p) - 1] : '';
    $tail = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $tail);
    $tail = trim(preg_replace('/\\s+/', ' ', $tail));
    $known = 'thin|extra|ultra|semi|demi|light|book|regular|normal|medium|bold|black|heavy|italic|oblique|condensed|expanded|variable|hairline';
    if ($tail !== '' && preg_match('/^(' . $known . ')( (' . $known . '))*$/i', $tail)) {
        return ucwords(strtolower($tail));
    }
    return 'Regular';
}
function ft_migrate($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `font_families` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(160) NOT NULL,
            `foundry` VARCHAR(160) NOT NULL DEFAULT '',
            `licence` VARCHAR(60) NOT NULL DEFAULT '',
            `source` VARCHAR(300) NOT NULL DEFAULT '',
            `tags` VARCHAR(300) NOT NULL DEFAULT '',
            `note` VARCHAR(600) NOT NULL DEFAULT '',
            `preview_id` INT NOT NULL DEFAULT 0,
            `user_id` INT NOT NULL DEFAULT 0,
            `user_name` VARCHAR(120) NOT NULL DEFAULT '',
            `created_at` DATETIME NULL,
            `updated_at` DATETIME NULL,
            UNIQUE KEY `uk_font_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `font_files` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `family_id` INT NOT NULL,
            `style` VARCHAR(80) NOT NULL DEFAULT '',
            `ext` VARCHAR(10) NOT NULL DEFAULT '',
            `orig_name` VARCHAR(255) NOT NULL DEFAULT '',
            `path` VARCHAR(300) NOT NULL DEFAULT '',
            `bytes` INT NOT NULL DEFAULT 0,
            `is_web` TINYINT(1) NOT NULL DEFAULT 0,
            `user_id` INT NOT NULL DEFAULT 0,
            `user_name` VARCHAR(120) NOT NULL DEFAULT '',
            `created_at` DATETIME NULL,
            KEY `k_fam` (`family_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) { }
}

ft_migrate($pdo);
$me = ft_user($pdo);
if (!$me) fail('Chưa đăng nhập', 401);
$NOW = date('Y-m-d H:i:s');

switch ($action) {

case 'me':
    ok(array('id' => (int) $me['id'], 'name' => $me['display_name'], 'role' => $me['role']));

case 'list':
    $fams = $pdo->query('SELECT * FROM `font_families` ORDER BY `name` ASC')->fetchAll();
    $fs   = $pdo->query('SELECT * FROM `font_files` ORDER BY `family_id` ASC, `style` ASC, `ext` ASC')->fetchAll();
    $byFam = array();
    foreach ($fs as $f) {
        $k = (int) $f['family_id'];
        if (!isset($byFam[$k])) $byFam[$k] = array();
        $byFam[$k][] = array(
            'id'    => (int) $f['id'],   'style' => $f['style'],
            'ext'   => $f['ext'],        'orig'  => $f['orig_name'],
            'bytes' => (int) $f['bytes'], 'web'  => (int) $f['is_web'],
            'by'    => $f['user_name'],  'at'    => $f['created_at'],
        );
    }
    $out = array();
    foreach ($fams as $r) {
        $id  = (int) $r['id'];
        $lst = isset($byFam[$id]) ? $byFam[$id] : array();
        $pv  = (int) $r['preview_id'];
        if (!$pv) { foreach ($lst as $f) { if ($f['web']) { $pv = $f['id']; break; } } }
        $sum = 0;
        foreach ($lst as $f) $sum += $f['bytes'];
        $out[] = array(
            'id' => $id, 'name' => $r['name'], 'foundry' => $r['foundry'],
            'licence' => $r['licence'], 'source' => $r['source'], 'tags' => $r['tags'],
            'note' => $r['note'], 'preview_id' => $pv, 'bytes' => $sum,
            'by' => $r['user_name'], 'at' => $r['created_at'], 'files' => $lst,
        );
    }
    ok(array('rows' => $out, 'me' => array('id' => (int) $me['id'], 'name' => $me['display_name'], 'role' => $me['role'])));

case 'save':
    $id   = (int) (isset($B['id']) ? $B['id'] : 0);
    $name = trim((string) (isset($B['name']) ? $B['name'] : ''));
    if ($name === '') fail('Chưa nhập tên bộ font');
    if (mb_strlen($name) > 160) fail('Tên bộ font quá dài');
    $st = $pdo->prepare('SELECT `id` FROM `font_families` WHERE `name` = ? AND `id` <> ?');
    $st->execute(array($name, $id));
    if ($st->fetch()) fail('Đã có bộ font tên này rồi');
    $v = array(
        $name,
        mb_substr(trim((string) (isset($B['foundry']) ? $B['foundry'] : '')), 0, 160),
        mb_substr(trim((string) (isset($B['licence']) ? $B['licence'] : '')), 0, 60),
        mb_substr(trim((string) (isset($B['source'])  ? $B['source']  : '')), 0, 300),
        mb_substr(trim((string) (isset($B['tags'])    ? $B['tags']    : '')), 0, 300),
        mb_substr(trim((string) (isset($B['note'])    ? $B['note']    : '')), 0, 600),
    );
    if ($id > 0) {
        $v[] = $NOW; $v[] = $id;
        $pdo->prepare('UPDATE `font_families` SET `name`=?, `foundry`=?, `licence`=?, `source`=?, `tags`=?, `note`=?, `updated_at`=? WHERE `id`=?')->execute($v);
    } else {
        $v[] = (int) $me['id']; $v[] = $me['display_name']; $v[] = $NOW; $v[] = $NOW;
        $pdo->prepare('INSERT INTO `font_families` (`name`,`foundry`,`licence`,`source`,`tags`,`note`,`user_id`,`user_name`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute($v);
        $id = (int) $pdo->lastInsertId();
    }
    ok(array('id' => $id));

case 'set-preview':
    $fid = (int) (isset($B['file_id']) ? $B['file_id'] : 0);
    $st  = $pdo->prepare('SELECT `family_id` FROM `font_files` WHERE `id` = ?');
    $st->execute(array($fid));
    $f = $st->fetch();
    if (!$f) fail('Không tìm thấy file', 404);
    $pdo->prepare('UPDATE `font_families` SET `preview_id` = ?, `updated_at` = ? WHERE `id` = ?')->execute(array($fid, $NOW, (int) $f['family_id']));
    ok(array('file_id' => $fid));

case 'upload':
    if (empty($_FILES['file'])) fail('Không nhận được file (có thể file quá lớn so với giới hạn máy chủ)');
    $F = $_FILES['file'];
    if ($F['error'] !== UPLOAD_ERR_OK) fail('Lỗi tải lên (mã ' . $F['error'] . ')');
    if ($F['size'] <= 0)       fail('File rỗng');
    if ($F['size'] > $MAXBYTE) fail('File vượt quá 120 MB');

    $orig = basename((string) $F['name']);
    $ext  = ft_ext($orig);
    if (!in_array($ext, FT_ALLOW, true)) fail('Chỉ nhận file .ttf .otf .ttc .woff .woff2 hoặc .zip');

    $famId = (int) (isset($_POST['family_id']) ? $_POST['family_id'] : 0);
    if ($famId > 0) {
        $c = $pdo->prepare('SELECT 1 FROM `font_families` WHERE `id` = ?');
        $c->execute(array($famId));
        if (!$c->fetch()) fail('Không tìm thấy bộ font', 404);
    } else {
        $fname = trim((string) (isset($_POST['family_name']) ? $_POST['family_name'] : ''));
        if ($fname === '') $fname = pathinfo($orig, PATHINFO_FILENAME);
        $fname = mb_substr($fname, 0, 160);
        $c = $pdo->prepare('SELECT `id` FROM `font_families` WHERE `name` = ?');
        $c->execute(array($fname));
        $r = $c->fetch();
        if ($r) {
            $famId = (int) $r['id'];
        } else {
            $pdo->prepare('INSERT INTO `font_families` (`name`,`user_id`,`user_name`,`created_at`,`updated_at`) VALUES (?,?,?,?,?)')
                ->execute(array($fname, (int) $me['id'], $me['display_name'], $NOW, $NOW));
            $famId = (int) $pdo->lastInsertId();
        }
    }

    $style = trim((string) (isset($_POST['style']) ? $_POST['style'] : ''));
    if ($style === '') $style = ($ext === 'zip') ? 'Cả bộ (zip)' : ft_guess_style($orig);
    $style = mb_substr($style, 0, 80);

    $dir = $UPDIR . '/' . $famId;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) fail('Không tạo được thư mục lưu file', 500);

    $isWeb = in_array($ext, FT_WEB, true) ? 1 : 0;
    $pdo->prepare('INSERT INTO `font_files` (`family_id`,`style`,`ext`,`orig_name`,`path`,`bytes`,`is_web`,`user_id`,`user_name`,`created_at`) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute(array($famId, $style, $ext, mb_substr($orig, 0, 255), '', (int) $F['size'], $isWeb, (int) $me['id'], $me['display_name'], $NOW));
    $fid = (int) $pdo->lastInsertId();

    $stored = $fid . '_' . ft_safe(pathinfo($orig, PATHINFO_FILENAME)) . '.' . $ext;
    if (!@move_uploaded_file($F['tmp_name'], $dir . '/' . $stored)) {
        $pdo->prepare('DELETE FROM `font_files` WHERE `id` = ?')->execute(array($fid));
        fail('Không lưu được file lên máy chủ', 500);
    }
    @chmod($dir . '/' . $stored, 0644);
    $rel = 'uploads/fonts/' . $famId . '/' . $stored;
    $pdo->prepare('UPDATE `font_files` SET `path` = ? WHERE `id` = ?')->execute(array($rel, $fid));
    $pdo->prepare('UPDATE `font_families` SET `updated_at` = ? WHERE `id` = ?')->execute(array($NOW, $famId));
    ok(array('file_id' => $fid, 'family_id' => $famId, 'style' => $style, 'ext' => $ext, 'web' => $isWeb));

case 'file-save':
    $fid   = (int) (isset($B['id']) ? $B['id'] : 0);
    $style = mb_substr(trim((string) (isset($B['style']) ? $B['style'] : '')), 0, 80);
    if ($style === '') fail('Chưa nhập kiểu chữ');
    $n = $pdo->prepare('UPDATE `font_files` SET `style` = ? WHERE `id` = ?');
    $n->execute(array($style, $fid));
    if (!$n->rowCount()) fail('Không tìm thấy file', 404);
    ok(array('id' => $fid, 'style' => $style));

case 'file-delete':
    $fid = (int) (isset($B['id']) ? $B['id'] : 0);
    $st  = $pdo->prepare('SELECT `family_id`, `path` FROM `font_files` WHERE `id` = ?');
    $st->execute(array($fid));
    $f = $st->fetch();
    if (!$f) fail('Không tìm thấy file', 404);
    $real = realpath($ROOT . '/' . $f['path']);
    if ($real && strpos($real, realpath($UPDIR)) === 0 && is_file($real)) @unlink($real);
    $pdo->prepare('DELETE FROM `font_files` WHERE `id` = ?')->execute(array($fid));
    $pdo->prepare('UPDATE `font_families` SET `preview_id` = 0 WHERE `preview_id` = ?')->execute(array($fid));
    ok(array('id' => $fid));

case 'delete':
    $id = (int) (isset($B['id']) ? $B['id'] : 0);
    $st = $pdo->prepare('SELECT `id` FROM `font_families` WHERE `id` = ?');
    $st->execute(array($id));
    if (!$st->fetch()) fail('Không tìm thấy bộ font', 404);
    $q = $pdo->prepare('SELECT `path` FROM `font_files` WHERE `family_id` = ?');
    $q->execute(array($id));
    $base = realpath($UPDIR);
    foreach ($q->fetchAll() as $f) {
        $real = realpath($ROOT . '/' . $f['path']);
        if ($real && $base && strpos($real, $base) === 0 && is_file($real)) @unlink($real);
    }
    @rmdir($UPDIR . '/' . $id);
    $pdo->prepare('DELETE FROM `font_files` WHERE `family_id` = ?')->execute(array($id));
    $pdo->prepare('DELETE FROM `font_families` WHERE `id` = ?')->execute(array($id));
    ok(array('id' => $id));

case 'file':
case 'download':
    $fid = (int) (isset($_GET['id']) ? $_GET['id'] : 0);
    $st  = $pdo->prepare('SELECT `orig_name`, `path`, `ext` FROM `font_files` WHERE `id` = ?');
    $st->execute(array($fid));
    $f = $st->fetch();
    if (!$f) { http_response_code(404); exit('Not found'); }
    $real = realpath($ROOT . '/' . $f['path']);
    $base = realpath($UPDIR);
    if (!$real || !$base || strpos($real, $base) !== 0 || !is_file($real)) { http_response_code(404); exit('Not found'); }
    $mimes = array(
        'ttf' => 'font/ttf', 'otf' => 'font/otf', 'ttc' => 'font/collection',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'zip' => 'application/zip',
    );
    $mime = isset($mimes[$f['ext']]) ? $mimes[$f['ext']] : 'application/octet-stream';
    $name = $f['orig_name'] !== '' ? $f['orig_name'] : basename($real);
    $name = preg_replace('/[\\r\\n"]/', '', $name);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($real));
    header('X-Content-Type-Options: nosniff');
    if ($action === 'download') {
        header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Cache-Control: private, max-age=0');
    } else {
        header('Content-Disposition: inline');
        header('Cache-Control: private, max-age=86400');
    }
    readfile($real);
    exit;

case 'zip':
    $id = (int) (isset($_GET['id']) ? $_GET['id'] : 0);
    $st = $pdo->prepare('SELECT `name` FROM `font_families` WHERE `id` = ?');
    $st->execute(array($id));
    $fam = $st->fetch();
    if (!$fam) { http_response_code(404); exit('Not found'); }
    if (!class_exists('ZipArchive')) { http_response_code(500); exit('ZipArchive not available'); }
    $q = $pdo->prepare('SELECT `orig_name`, `path` FROM `font_files` WHERE `family_id` = ? ORDER BY `style` ASC');
    $q->execute(array($id));
    $rows = $q->fetchAll();
    if (!$rows) { http_response_code(404); exit('Empty'); }
    $base = realpath($UPDIR);
    $tmp  = tempnam(sys_get_temp_dir(), 'apsafont');
    $zip  = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { http_response_code(500); exit('Zip failed'); }
    $seen = array();
    foreach ($rows as $r) {
        $real = realpath($ROOT . '/' . $r['path']);
        if (!$real || !$base || strpos($real, $base) !== 0 || !is_file($real)) continue;
        $nm = $r['orig_name'] !== '' ? basename($r['orig_name']) : basename($real);
        $k  = strtolower($nm);
        if (isset($seen[$k])) { $nm = pathinfo($nm, PATHINFO_FILENAME) . '-' . (++$seen[$k]) . '.' . ft_ext($nm); }
        else $seen[$k] = 1;
        $zip->addFile($real, $nm);
    }
    $zip->close();
    $out = ft_safe($fam['name']) . '.zip';
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($tmp));
    header('Content-Disposition: attachment; filename="' . $out . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($tmp);
    @unlink($tmp);
    exit;

default:
    fail('Thao tác không hợp lệ: ' . $action, 404);
}
