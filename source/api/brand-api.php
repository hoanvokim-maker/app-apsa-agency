<?php
/**
 * APSA - API module Brand Guidelines (luu tru truc tiep tren SharePoint)
 * ------------------------------------------------------------------
 *  Kho chinh: SharePoint > Documents > "Brand Guidlines"
 *  Moi nhan vien da dang nhap deu duoc xem / tai / tai len / doi ten / xoa.
 *  Xoa se vao Thung rac cua SharePoint (khoi phuc duoc trong 93 ngay).
 *
 *  Ket noi bang Microsoft Graph quyen APPLICATION (client credentials),
 *  dung chung cau hinh voi module lich Outlook: api/msgraph-config.php
 *
 *  Bang: brand_log (nhat ky thao tac) - tu tao khi chay lan dau.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/session-boot.php';
require_once __DIR__ . '/msgraph.php';

/* ------------------------------------------------------------------ *
 *  Cau hinh kho
 * ------------------------------------------------------------------ */

function bg_cfg()
{
    static $c = null;
    if ($c !== null) return $c;

    $c = array(
        'drive_id' => 'b!e4unr15XWkyaVG8edY6MkfZmAYHtCUBDugOk42Ie06yPpJHpQ4j6SpiGKdrEhZ21',
        'root_id'  => '01UTW2SKIMSRXZEURYYJGJ3AGAV7MZMTZM',
        'root_name' => 'Brand Guidlines',
    );

    $f = __DIR__ . '/brand-config.php';
    if (is_file($f)) {
        $l = @include $f;
        if (is_array($l)) $c = array_merge($c, $l);
    }
    return $c;
}

define('BG_CHUNK', 3932160);          // 3.75 MB - boi so cua 320 KiB theo yeu cau cua Graph
define('BG_MAX_UPLOAD', 524288000);   // 500 MB / file

/* ------------------------------------------------------------------ *
 *  Ha tang chung
 * ------------------------------------------------------------------ */

function bg_out($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function bg_fail($msg, $code = 400)
{
    bg_out(array('ok' => false, 'error' => $msg), $code);
}

function bg_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return array();
    $j = json_decode($raw, true);
    return is_array($j) ? $j : array();
}

function bg_pdo()
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
        );
    } catch (PDOException $e) {
        bg_fail('Khong ket noi duoc co so du lieu.', 500);
    }
    return $pdo;
}

function bg_me()
{
    static $me = null;
    if ($me !== null) return $me;

    $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if ($uid <= 0) bg_fail('Chua dang nhap.', 401);

    $st = bg_pdo()->prepare('SELECT * FROM app_users WHERE id = ? LIMIT 1');
    $st->execute(array($uid));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) bg_fail('Chua dang nhap.', 401);
    if (isset($row['active']) && (int) $row['active'] === 0) bg_fail('Tai khoan da bi khoa.', 403);

    $me = array(
        'id'    => (int) $row['id'],
        'name'  => trim((string) (!empty($row['display_name']) ? $row['display_name'] : $row['username'])),
        'email' => isset($row['email']) ? (string) $row['email'] : '',
        'role'  => isset($row['role']) ? (string) $row['role'] : '',
    );
    return $me;
}

function bg_is_admin()
{
    $me = bg_me();
    return strcasecmp($me['role'], 'admin') === 0;
}

/* ------------------------------------------------------------------ *
 *  Nhat ky
 * ------------------------------------------------------------------ */

function bg_boot()
{
    static $done = false;
    if ($done) return;
    $done = true;

    bg_pdo()->exec(
        'CREATE TABLE IF NOT EXISTS brand_log ('
        . ' id INT AUTO_INCREMENT PRIMARY KEY,'
        . ' user_id INT NOT NULL,'
        . ' user_name VARCHAR(150) NOT NULL DEFAULT "",'
        . ' act VARCHAR(20) NOT NULL DEFAULT "",'
        . ' item_name VARCHAR(400) NOT NULL DEFAULT "",'
        . ' detail VARCHAR(400) NOT NULL DEFAULT "",'
        . ' created_at DATETIME NOT NULL,'
        . ' KEY k_time (created_at)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function bg_log($act, $name, $detail = '')
{
    try {
        bg_boot();
        $me = bg_me();
        $st = bg_pdo()->prepare(
            'INSERT INTO brand_log (user_id, user_name, act, item_name, detail, created_at) VALUES (?,?,?,?,?,?)'
        );
        $st->execute(array($me['id'], $me['name'], $act, mb_substr($name, 0, 390), mb_substr($detail, 0, 390), date('Y-m-d H:i:s')));
    } catch (Exception $e) {
        // Nhat ky khong duoc lam hong thao tac chinh
    }
}

/* ------------------------------------------------------------------ *
 *  HTTP rieng (mg_http gioi han 25 giay, khong hop cho upload)
 * ------------------------------------------------------------------ */

function bg_http($method, $url, $headers, $body, &$code, $timeout = 120)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $out = curl_exec($ch);
    if ($out === false) {
        $e = curl_error($ch);
        curl_close($ch);
        $code = 0;
        return array('__curl_error' => $e);
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($out === '') return array();
    $j = json_decode($out, true);
    return is_array($j) ? $j : array('__raw' => $out);
}

function bg_headers()
{
    static $h = null;
    if ($h !== null) return $h;

    if (!mg_enabled()) bg_fail('Chua cau hinh ket noi Microsoft 365 (api/msgraph-config.php).', 500);

    $err = '';
    $tok = mg_token($err);
    if (!$tok) bg_fail('Khong lay duoc quyen truy cap SharePoint: ' . $err, 502);

    $h = array('Authorization: Bearer ' . $tok, 'Accept: application/json');
    return $h;
}

function bg_g($method, $path, $body = null, $extra = array(), $timeout = 120)
{
    $url = (strpos($path, 'http') === 0) ? $path : ('https://graph.microsoft.com/v1.0' . $path);
    $h   = array_merge(bg_headers(), $extra);
    $c   = 0;
    $r   = bg_http($method, $url, $h, $body, $c, $timeout);
    return array($c, $r);
}

function bg_drive()
{
    $c = bg_cfg();
    return '/drives/' . $c['drive_id'];
}

/* ------------------------------------------------------------------ *
 *  Doc / kiem tra pham vi
 * ------------------------------------------------------------------ */

$BG_SELECT = 'id,name,size,folder,file,webUrl,lastModifiedDateTime,createdDateTime,lastModifiedBy,parentReference';

function bg_item($id, $withUrl = false)
{
    global $BG_SELECT;
    $sel = $BG_SELECT . ($withUrl ? ',@microsoft.graph.downloadUrl' : '');
    list($c, $r) = bg_g('GET', bg_drive() . '/items/' . rawurlencode($id) . '?$select=' . rawurlencode($sel));
    if ($c !== 200 || !isset($r['id'])) bg_fail(mg_err($r, $c, 'Khong doc duoc muc nay tren SharePoint.'), $c === 404 ? 404 : 502);
    return $r;
}

/** Duong dan tuyet doi cua thu muc goc, dung de chan thao tac ra ngoai pham vi. */
function bg_root_path()
{
    static $p = null;
    if ($p !== null) return $p;

    $c = bg_cfg();
    $r = bg_item($c['root_id']);
    $base = isset($r['parentReference']['path']) ? $r['parentReference']['path'] : '/drive/root:';
    $p = rtrim($base, '/') . '/' . $r['name'];
    return $p;
}

/** Bao dam muc dang thao tac nam trong thu muc Brand Guidelines. */
function bg_guard($item)
{
    $cfg = bg_cfg();
    if ((string) $item['id'] === (string) $cfg['root_id']) return $item;

    $pref = isset($item['parentReference']['path']) ? (string) $item['parentReference']['path'] : '';
    $root = bg_root_path();
    if ($pref !== $root && strpos($pref, $root . '/') !== 0) {
        bg_fail('Thao tac nam ngoai thu muc Brand Guidelines - da tu choi.', 403);
    }
    return $item;
}

function bg_folder_id($id)
{
    $cfg = bg_cfg();
    $id  = ($id === '' || $id === null) ? $cfg['root_id'] : $id;
    $it  = bg_guard(bg_item($id));
    if (!isset($it['folder'])) bg_fail('Muc nay khong phai thu muc.', 400);
    return $it;
}

function bg_clean_name($n)
{
    $n = trim(preg_replace('/[\r\n\t]+/u', ' ', (string) $n));
    $n = str_replace(array('"', '*', ':', '<', '>', '?', '/', '\\', '|'), '-', $n);
    $n = ltrim($n, '.~');
    if ($n === '') bg_fail('Ten khong hop le.');
    if (mb_strlen($n) > 200) $n = mb_substr($n, 0, 200);
    return $n;
}

function bg_row($it)
{
    return array(
        'id'       => $it['id'],
        'name'     => $it['name'],
        'is_dir'   => isset($it['folder']),
        'size'     => isset($it['size']) ? (int) $it['size'] : 0,
        'count'    => isset($it['folder']['childCount']) ? (int) $it['folder']['childCount'] : 0,
        'ext'      => isset($it['folder']) ? '' : strtolower(pathinfo($it['name'], PATHINFO_EXTENSION)),
        'modified' => isset($it['lastModifiedDateTime']) ? $it['lastModifiedDateTime'] : '',
        'by'       => isset($it['lastModifiedBy']['user']['displayName']) ? $it['lastModifiedBy']['user']['displayName'] : '',
        'web_url'  => isset($it['webUrl']) ? $it['webUrl'] : '',
    );
}

/* ------------------------------------------------------------------ *
 *  Dieu phoi
 * ------------------------------------------------------------------ */

$ACT = isset($_GET['action']) ? (string) $_GET['action'] : '';
$ME  = bg_me();
$CFG = bg_cfg();

switch ($ACT) {

case 'me':
    bg_out(array(
        'ok'      => true,
        'me'      => $ME,
        'admin'   => bg_is_admin(),
        'enabled' => mg_enabled(),
        'root'    => $CFG['root_id'],
        'root_name' => $CFG['root_name'],
        'chunk'   => BG_CHUNK,
        'max'     => BG_MAX_UPLOAD,
    ));
    break;

case 'list':
    $fid = isset($_GET['id']) ? (string) $_GET['id'] : '';
    $f   = bg_folder_id($fid);

    global $BG_SELECT;
    $items = array();
    $url   = bg_drive() . '/items/' . rawurlencode($f['id']) . '/children?$top=999&$select=' . rawurlencode($BG_SELECT);
    $loops = 0;
    while ($url !== '' && $loops < 20) {
        $loops++;
        list($c, $r) = bg_g('GET', $url);
        if ($c !== 200 || !isset($r['value'])) bg_fail(mg_err($r, $c, 'Khong doc duoc danh sach file.'), 502);
        foreach ($r['value'] as $it) $items[] = bg_row($it);
        $url = isset($r['@odata.nextLink']) ? $r['@odata.nextLink'] : '';
    }

    usort($items, function ($a, $b) {
        if ($a['is_dir'] !== $b['is_dir']) return $a['is_dir'] ? -1 : 1;
        return strcasecmp($a['name'], $b['name']);
    });

    bg_out(array(
        'ok'      => true,
        'folder'  => array('id' => $f['id'], 'name' => $f['name'], 'web_url' => isset($f['webUrl']) ? $f['webUrl'] : ''),
        'is_root' => ((string) $f['id'] === (string) $CFG['root_id']),
        'items'   => $items,
    ));
    break;

case 'link':
    $id = isset($_GET['id']) ? (string) $_GET['id'] : '';
    if ($id === '') bg_fail('Thieu id.');
    $it = bg_guard(bg_item($id, true));
    if (isset($it['folder'])) bg_fail('Khong tai duoc thu muc.');
    $u = isset($it['@microsoft.graph.downloadUrl']) ? $it['@microsoft.graph.downloadUrl'] : '';
    if ($u === '') bg_fail('Khong lay duoc duong dan tai file.', 502);
    bg_out(array('ok' => true, 'url' => $u, 'name' => $it['name'], 'web_url' => isset($it['webUrl']) ? $it['webUrl'] : ''));
    break;

case 'search':
    $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
    if (mb_strlen($q) < 2) bg_fail('Nhap it nhat 2 ky tu.');
    global $BG_SELECT;
    list($c, $r) = bg_g(
        'GET',
        bg_drive() . '/items/' . rawurlencode($CFG['root_id'])
        . "/search(q='" . rawurlencode(str_replace("'", "''", $q)) . "')?\$top=200&\$select=" . rawurlencode($BG_SELECT)
    );
    if ($c !== 200 || !isset($r['value'])) bg_fail(mg_err($r, $c, 'Khong tim duoc.'), 502);
    $items = array();
    foreach ($r['value'] as $it) $items[] = bg_row($it);
    bg_out(array('ok' => true, 'items' => $items, 'q' => $q));
    break;

case 'mkdir':
    $b   = bg_body();
    $par = bg_folder_id(isset($b['parent']) ? (string) $b['parent'] : '');
    $nm  = bg_clean_name(isset($b['name']) ? $b['name'] : '');

    list($c, $r) = bg_g(
        'POST',
        bg_drive() . '/items/' . rawurlencode($par['id']) . '/children',
        json_encode(array('name' => $nm, 'folder' => new stdClass(), '@microsoft.graph.conflictBehavior' => 'rename'), JSON_UNESCAPED_UNICODE),
        array('Content-Type: application/json')
    );
    if ($c !== 201 && $c !== 200) bg_fail(mg_err($r, $c, 'Khong tao duoc thu muc.'), 502);
    bg_log('mkdir', $r['name']);
    bg_out(array('ok' => true, 'item' => bg_row($r)));
    break;

case 'rename':
    $b  = bg_body();
    $id = isset($b['id']) ? (string) $b['id'] : '';
    if ($id === '') bg_fail('Thieu id.');
    if ($id === (string) $CFG['root_id']) bg_fail('Khong doi ten duoc thu muc goc.', 403);

    $it  = bg_guard(bg_item($id));
    $old = $it['name'];
    $nm  = bg_clean_name(isset($b['name']) ? $b['name'] : '');

    // Giu nguyen duoi file neu nguoi dung xoa mat
    if (!isset($it['folder'])) {
        $oe = strtolower(pathinfo($old, PATHINFO_EXTENSION));
        $ne = strtolower(pathinfo($nm, PATHINFO_EXTENSION));
        if ($oe !== '' && $ne !== $oe) $nm .= '.' . $oe;
    }
    if ($nm === $old) bg_out(array('ok' => true, 'item' => bg_row($it)));

    list($c, $r) = bg_g(
        'PATCH',
        bg_drive() . '/items/' . rawurlencode($id),
        json_encode(array('name' => $nm), JSON_UNESCAPED_UNICODE),
        array('Content-Type: application/json')
    );
    if ($c !== 200) bg_fail(mg_err($r, $c, 'Khong doi ten duoc.'), 502);
    bg_log('rename', $r['name'], 'tu: ' . $old);
    bg_out(array('ok' => true, 'item' => bg_row($r)));
    break;

case 'delete':
    $b  = bg_body();
    $id = isset($b['id']) ? (string) $b['id'] : '';
    if ($id === '') bg_fail('Thieu id.');
    if ($id === (string) $CFG['root_id']) bg_fail('Khong xoa duoc thu muc goc.', 403);

    $it = bg_guard(bg_item($id));
    if (isset($it['folder']) && (int) $it['folder']['childCount'] > 0 && empty($b['force'])) {
        bg_fail('Thu muc con chua ' . (int) $it['folder']['childCount'] . ' muc. Xac nhan lai de xoa ca thu muc.', 409);
    }

    list($c, $r) = bg_g('DELETE', bg_drive() . '/items/' . rawurlencode($id));
    if ($c !== 204 && $c !== 200) bg_fail(mg_err($r, $c, 'Khong xoa duoc.'), 502);
    bg_log('delete', $it['name'], isset($it['folder']) ? 'thu muc' : 'file');
    bg_out(array('ok' => true));
    break;

case 'move':
    $b  = bg_body();
    $id = isset($b['id']) ? (string) $b['id'] : '';
    if ($id === '') bg_fail('Thieu id.');
    if ($id === (string) $CFG['root_id']) bg_fail('Khong di chuyen duoc thu muc goc.', 403);

    $it  = bg_guard(bg_item($id));
    $par = bg_folder_id(isset($b['parent']) ? (string) $b['parent'] : '');

    list($c, $r) = bg_g(
        'PATCH',
        bg_drive() . '/items/' . rawurlencode($id),
        json_encode(array('parentReference' => array('id' => $par['id'])), JSON_UNESCAPED_UNICODE),
        array('Content-Type: application/json')
    );
    if ($c !== 200) bg_fail(mg_err($r, $c, 'Khong di chuyen duoc.'), 502);
    bg_log('move', $it['name'], 'vao: ' . $par['name']);
    bg_out(array('ok' => true, 'item' => bg_row($r)));
    break;

/* ---- Tai len: chia goi de khong dung gioi han post_max_size cua PHP ---- */

case 'up-begin':
    $b    = bg_body();
    $par  = bg_folder_id(isset($b['parent']) ? (string) $b['parent'] : '');
    $nm   = bg_clean_name(isset($b['name']) ? $b['name'] : '');
    $size = isset($b['size']) ? (int) $b['size'] : 0;
    if ($size <= 0) bg_fail('File rong.');
    if ($size > BG_MAX_UPLOAD) bg_fail('File vuot qua ' . round(BG_MAX_UPLOAD / 1048576) . ' MB.');

    list($c, $r) = bg_g(
        'POST',
        bg_drive() . '/items/' . rawurlencode($par['id']) . ':/' . rawurlencode($nm) . ':/createUploadSession',
        json_encode(array('item' => array('@microsoft.graph.conflictBehavior' => 'replace', 'name' => $nm)), JSON_UNESCAPED_UNICODE),
        array('Content-Type: application/json')
    );
    if ($c !== 200 || empty($r['uploadUrl'])) bg_fail(mg_err($r, $c, 'Khong mo duoc phien tai len.'), 502);

    $key = bin2hex(random_bytes(12));
    if (!isset($_SESSION['bg_up']) || !is_array($_SESSION['bg_up'])) $_SESSION['bg_up'] = array();
    if (count($_SESSION['bg_up']) > 8) $_SESSION['bg_up'] = array_slice($_SESSION['bg_up'], -4, 4, true);
    $_SESSION['bg_up'][$key] = array('url' => $r['uploadUrl'], 'name' => $nm, 'size' => $size, 't' => time());

    bg_out(array('ok' => true, 'key' => $key, 'chunk' => BG_CHUNK, 'name' => $nm));
    break;

case 'up-chunk':
    $key = isset($_GET['key']) ? (string) $_GET['key'] : '';
    if ($key === '' || empty($_SESSION['bg_up'][$key])) bg_fail('Phien tai len khong hop le, vui long thu lai.', 400);
    $s = $_SESSION['bg_up'][$key];
    @session_write_close();   // nha khoa phien de cac tab khac cua nguoi dung khong bi treo

    $start = isset($_GET['start']) ? (int) $_GET['start'] : -1;
    $total = (int) $s['size'];
    $data  = file_get_contents('php://input');
    if ($data === false) $data = '';
    $len = strlen($data);
    if ($start < 0 || $len <= 0 || $start + $len > $total) bg_fail('Goi du lieu khong hop le.');

    $c = 0;
    $r = bg_http(
        'PUT',
        $s['url'],
        array(
            'Content-Length: ' . $len,
            'Content-Range: bytes ' . $start . '-' . ($start + $len - 1) . '/' . $total,
        ),
        $data,
        $c,
        300
    );

    if ($c === 202) bg_out(array('ok' => true, 'done' => false, 'next' => $start + $len));
    if ($c === 200 || $c === 201) {
        @session_start();
        unset($_SESSION['bg_up'][$key]);
        @session_write_close();
        bg_log('upload', isset($r['name']) ? $r['name'] : $s['name'], round($total / 1048576, 1) . ' MB');
        bg_out(array('ok' => true, 'done' => true, 'item' => isset($r['id']) ? bg_row($r) : null));
    }
    bg_fail(mg_err($r, $c, 'Tai len that bai.'), 502);
    break;

case 'up-abort':
    $b   = bg_body();
    $key = isset($b['key']) ? (string) $b['key'] : '';
    if ($key !== '' && !empty($_SESSION['bg_up'][$key])) {
        $c = 0;
        bg_http('DELETE', $_SESSION['bg_up'][$key]['url'], array(), null, $c, 30);
        unset($_SESSION['bg_up'][$key]);
    }
    bg_out(array('ok' => true));
    break;

case 'log':
    bg_boot();
    $lim = isset($_GET['limit']) ? max(1, min(300, (int) $_GET['limit'])) : 100;
    $st  = bg_pdo()->query('SELECT * FROM brand_log ORDER BY id DESC LIMIT ' . $lim);
    bg_out(array('ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)));
    break;

case 'test':
    $err = '';
    $tok = mg_token($err);
    if (!$tok) bg_fail('Token: ' . $err, 502);
    $r = bg_item($CFG['root_id']);
    bg_out(array('ok' => true, 'folder' => $r['name'], 'path' => bg_root_path(), 'children' => isset($r['folder']['childCount']) ? (int) $r['folder']['childCount'] : 0));
    break;

default:
    bg_fail('Thao tac khong hop le.', 404);
}
