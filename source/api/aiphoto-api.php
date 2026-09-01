<?php
/**
 * aiphoto-api.php - API cho module Chup anh AI.
 *
 * Cac action p-* la cong khai (khach mo link, khong can dang nhap).
 * Cac action con lai bat buoc dang nhap.
 *
 * Dieu phoi tai: chinh request hoi trang thai cua khach se lam viec tao anh,
 * nhung mot luc chi cho toi da max_inflight anh chay. Nguoi den sau xep hang
 * va thay so thu tu. Khong can tien trinh nen.
 */

require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/session-boot.php';
require_once __DIR__ . '/aiphoto.php';
require_once __DIR__ . '/aiphoto-sp.php';

/* ------------------------------------------------------------------ */
/*  Ha tang chung                                                      */
/* ------------------------------------------------------------------ */

function apx_out($d, $c = 200)
{
    http_response_code($c);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
function apx_ok($d = array())  { apx_out(array('ok' => true) + $d); }
function apx_fail($m, $c = 400) { apx_out(array('ok' => false, 'error' => $m), $c); }

function apx_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return array();
    $j = json_decode($raw, true);
    return is_array($j) ? $j : array();
}

function apx_pdo()
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
        );
    } catch (PDOException $e) { apx_fail('Khong ket noi duoc co so du lieu.', 500); }
    return $pdo;
}

function apx_me()
{
    static $me = null;
    if ($me !== null) return $me;
    $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if (!$uid) apx_fail('Chưa đăng nhập.', 401);
    $st = apx_pdo()->prepare("SELECT id, username, display_name, role FROM `app_users` WHERE id = ? AND active = 1");
    $st->execute(array($uid));
    $r = $st->fetch();
    if (!$r) apx_fail('Chưa đăng nhập.', 401);
    $r['id'] = (int) $r['id'];
    $me = $r;
    return $me;
}
function apx_admin()
{
    $m = apx_me();
    return isset($m['role']) && strtolower((string) $m['role']) === 'admin';
}
function apx_ip() { return mb_substr(isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '', 0, 45); }
function apx_s($v, $n = 255) { return mb_substr(trim((string) $v), 0, $n); }

/** Trang thai theo thoi gian cua event. */
function apx_state(array $e)
{
    if (empty($e['active'])) return 'off';
    $now = date('Y-m-d H:i:s');
    if (!empty($e['start_at']) && $e['start_at'] > $now) return 'early';
    if (!empty($e['end_at'])   && $e['end_at']   < $now) return 'ended';
    if ((int) $e['max_images'] > 0 && (int) $e['uses'] >= (int) $e['max_images']) return 'full';
    return 'ok';
}

function apx_evdir($id)
{
    $d = ap_updir() . '/' . (int) $id;
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

/** Doc anh tu dataURL. Tra ve array(bytes, mime) hoac false. */
function apx_data_uri($s, $maxBytes = 12000000)
{
    if (!preg_match('#^data:(image/(?:png|jpe?g|webp));base64,#i', (string) $s, $m)) return false;
    $raw = base64_decode(substr($s, strpos($s, ',') + 1), true);
    if ($raw === false || strlen($raw) < 512 || strlen($raw) > $maxBytes) return false;
    $mime = strtolower($m[1]);
    if ($mime === 'image/jpg') $mime = 'image/jpeg';
    return array($raw, $mime);
}

$PDO = apx_pdo();
ap_migrate($PDO);
$ACT = isset($_GET['action']) ? (string) $_GET['action'] : '';
$B   = apx_body();

/* ================================================================== */
/*  CONG KHAI - khach mo link, khong can dang nhap                     */
/* ================================================================== */

if ($ACT === 'p-event') {
    $slug = apx_s(isset($_GET['e']) ? $_GET['e'] : '', 64);
    $st = $PDO->prepare("SELECT * FROM `ai_events` WHERE slug = ? LIMIT 1");
    $st->execute(array($slug));
    $e = $st->fetch();
    if (!$e) apx_fail('Không tìm thấy sự kiện.', 404);

    $state = apx_state($e);
    try { $PDO->prepare("UPDATE `ai_events` SET views = views + 1 WHERE id = ?")->execute(array($e['id'])); } catch (Exception $x) { }

    $ps = array();
    if ($state === 'ok') {
        $q = $PDO->prepare(
            "SELECT p.id, p.title, p.tag, p.thumb_file
               FROM `ai_event_prompts` ep
               JOIN `ai_prompts` p ON p.id = ep.prompt_id
              WHERE ep.event_id = ? AND p.active = 1
              ORDER BY ep.sort_order ASC, p.id ASC");
        $q->execute(array($e['id']));
        foreach ($q->fetchAll() as $r) {
            $ps[] = array(
                'id'    => (int) $r['id'],
                'title' => $r['title'],
                'tag'   => $r['tag'],
                'thumb' => $r['thumb_file'] ? ('./uploads/aiphoto/p/' . $r['thumb_file']) : '',
            );
        }
    }

    apx_ok(array('data' => array(
        'state'   => $state,
        'name'    => $e['name'],
        'note'    => $e['note'],
        'cover'   => $e['cover_file'] ? ('./uploads/aiphoto/' . (int) $e['id'] . '/' . $e['cover_file']) : '',
        'w'       => (int) $e['out_w'],
        'h'       => (int) $e['out_h'],
        'start_at' => $e['start_at'],
        'end_at'  => $e['end_at'],
        'prompts' => $ps,
    )));
}

if ($ACT === 'p-submit') {
    $slug = apx_s(isset($B['e']) ? $B['e'] : '', 64);
    $pid  = (int) (isset($B['p']) ? $B['p'] : 0);
    $dk   = apx_s(isset($B['dk']) ? $B['dk'] : '', 40);

    $st = $PDO->prepare("SELECT * FROM `ai_events` WHERE slug = ? LIMIT 1");
    $st->execute(array($slug));
    $e = $st->fetch();
    if (!$e) apx_fail('Không tìm thấy sự kiện.', 404);
    $state = apx_state($e);
    if ($state !== 'ok') apx_fail('Sự kiện chưa mở hoặc đã kết thúc.', 403);

    $chk = $PDO->prepare("SELECT COUNT(*) FROM `ai_event_prompts` WHERE event_id = ? AND prompt_id = ?");
    $chk->execute(array($e['id'], $pid));
    if (!$chk->fetchColumn()) apx_fail('Kiểu ảnh không hợp lệ.', 400);

    $img = apx_data_uri(isset($B['photo']) ? $B['photo'] : '');
    if ($img === false) apx_fail('Ảnh không hợp lệ.', 400);

    $small = ap_shrink($img[0], 1280);
    $dir = apx_evdir($e['id']);
    $tok = bin2hex(random_bytes(8));
    $src = 'src-' . $tok . '.jpg';
    if (@file_put_contents($dir . '/' . $src, $small) === false) apx_fail('Không lưu được ảnh.', 500);

    $PDO->prepare(
        "INSERT INTO `ai_jobs` (token, event_id, prompt_id, state, src_file, device_key, ip, created_at)
         VALUES (?,?,?, 'queued', ?,?,?, NOW())")
        ->execute(array($tok, $e['id'], $pid, $src, $dk, apx_ip()));

    apx_ok(array('data' => array('job' => $tok)));
}

if ($ACT === 'p-status') {
    $tok = preg_replace('/[^0-9a-f]/', '', strtolower((string) (isset($_GET['j']) ? $_GET['j'] : '')));
    if ($tok === '') apx_fail('Thiếu mã.', 400);
    $st = $PDO->prepare("SELECT * FROM `ai_jobs` WHERE token = ? LIMIT 1");
    $st->execute(array($tok));
    $j = $st->fetch();
    if (!$j) apx_fail('Không tìm thấy yêu cầu.', 404);

    $cfg = ap_cfg();

    /* Tra lai hang doi nhung viec treo qua lau */
    try {
        $PDO->exec("UPDATE `ai_jobs` SET state = 'queued'
                     WHERE state = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL 3 MINUTE)");
    } catch (Exception $x) { }

    if ($j['state'] === 'done') {
        apx_ok(array('data' => array('state' => 'done', 'url' => './api/aiphoto-api.php?action=p-img&t=' . $tok)));
    }
    if ($j['state'] === 'error' && (int) $j['attempts'] >= (int) $cfg['max_attempts']) {
        apx_ok(array('data' => array('state' => 'error', 'error' => 'Tạo ảnh không thành công. Bạn thử chụp lại nhé.')));
    }

    /* Con luot thi gianh viec ve lam */
    $run = (int) $PDO->query("SELECT COUNT(*) FROM `ai_jobs` WHERE state = 'running'")->fetchColumn();
    if ($run < (int) $cfg['max_inflight']) {
        $cl = $PDO->prepare(
            "UPDATE `ai_jobs` SET state = 'running', started_at = NOW(), attempts = attempts + 1
              WHERE id = ? AND state IN ('queued','error')");
        $cl->execute(array($j['id']));
        if ($cl->rowCount() === 1) {
            @set_time_limit(180);
            @ignore_user_abort(true);
            apx_run_job($PDO, (int) $j['id']);
            $st->execute(array($tok));
            $j2 = $st->fetch();
            if ($j2 && $j2['state'] === 'done') {
                apx_ok(array('data' => array('state' => 'done', 'url' => './api/aiphoto-api.php?action=p-img&t=' . $tok)));
            }
            $left = (int) $cfg['max_attempts'] - (int) $j2['attempts'];
            apx_ok(array('data' => array(
                'state' => $left > 0 ? 'queued' : 'error',
                'error' => $left > 0 ? '' : 'Tạo ảnh không thành công. Bạn thử chụp lại nhé.',
            )));
        }
    }

    $pos = (int) $PDO->query("SELECT COUNT(*) FROM `ai_jobs`
                               WHERE state IN ('queued','running') AND id < " . (int) $j['id'])->fetchColumn();
    apx_ok(array('data' => array('state' => 'queued', 'position' => $pos)));
}

if ($ACT === 'p-img') {
    $tok = preg_replace('/[^0-9a-f]/', '', strtolower((string) (isset($_GET['t']) ? $_GET['t'] : '')));
    $st = $PDO->prepare("SELECT j.out_file, j.event_id FROM `ai_jobs` j WHERE j.token = ? AND j.state = 'done' LIMIT 1");
    $st->execute(array($tok));
    $j = $st->fetch();
    if (!$j || !$j['out_file']) { http_response_code(404); exit; }
    $p = ap_updir() . '/' . (int) $j['event_id'] . '/' . $j['out_file'];
    if (!is_file($p)) { http_response_code(404); exit; }
    if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($p));
    header('Content-Disposition: inline; filename="apsa-' . $tok . '.jpg"');
    header('Cache-Control: public, max-age=31536000');
    readfile($p);
    exit;
}

/**
 * Chay 1 viec: doc anh goc, goi model, hoan thien, luu.
 * Job da o trang thai running truoc khi goi ham nay.
 */
function apx_run_job(PDO $pdo, $jobId)
{
    $t0 = microtime(true);
    $st = $pdo->prepare(
        "SELECT j.*, p.body AS prompt_body, p.force_pro,
                e.out_w, e.out_h, e.wm_file, e.wm_scale, e.wm_pos, e.model_tier, e.id AS ev_id
           FROM `ai_jobs` j
           JOIN `ai_prompts` p ON p.id = j.prompt_id
           JOIN `ai_events`  e ON e.id = j.event_id
          WHERE j.id = ?");
    $st->execute(array((int) $jobId));
    $j = $st->fetch();
    if (!$j) return;

    $fail = function ($msg) use ($pdo, $jobId, $t0) {
        $pdo->prepare("UPDATE `ai_jobs` SET state = 'error', err = ?, ms = ?, finished_at = NOW() WHERE id = ?")
            ->execute(array(mb_substr((string) $msg, 0, 400), (int) ((microtime(true) - $t0) * 1000), (int) $jobId));
    };

    $dir = ap_updir() . '/' . (int) $j['ev_id'];
    $src = $dir . '/' . $j['src_file'];
    if (!is_file($src)) { $fail('Mat anh goc'); return; }

    $bytes = file_get_contents($src);
    /* Muc chat luong: theo su kien, nhung prompt co force_pro thi luon dung Pro */
    $tier = (!empty($j['force_pro']) || (string) $j['model_tier'] === 'pro') ? 'pro' : 'flash';
    $prov = ''; $err = '';
    $gen = ap_generate($bytes, 'image/jpeg', (string) $j['prompt_body'],
                       (int) $j['out_w'], (int) $j['out_h'], $prov, $err, $tier);
    if ($gen === false) { $fail($err); return; }

    $ev = array('id' => (int) $j['ev_id'], 'out_w' => (int) $j['out_w'], 'out_h' => (int) $j['out_h'],
                'wm_file' => $j['wm_file'], 'wm_pos' => $j['wm_pos'], 'wm_scale' => (int) $j['wm_scale']);
    $fin = ap_finish($gen, $ev);
    if ($fin === false) { $fail('Khong xu ly duoc anh tra ve'); return; }

    $out = 'out-' . $j['token'] . '.jpg';
    if (@file_put_contents($dir . '/' . $out, $fin) === false) { $fail('Khong luu duoc anh'); return; }

    $parts = explode('/', $prov, 2);
    $pdo->prepare("UPDATE `ai_jobs`
                      SET state = 'done', provider = ?, model = ?, out_file = ?, err = NULL, ms = ?, finished_at = NOW()
                    WHERE id = ?")
        ->execute(array($parts[0], isset($parts[1]) ? $parts[1] : null, $out,
                        (int) ((microtime(true) - $t0) * 1000), (int) $jobId));
    try {
        $pdo->prepare("UPDATE `ai_events`  SET uses = uses + 1 WHERE id = ?")->execute(array((int) $j['ev_id']));
        $pdo->prepare("UPDATE `ai_prompts` SET uses = uses + 1 WHERE id = ?")->execute(array((int) $j['prompt_id']));
    } catch (Exception $x) { }
    /* Dua len SharePoint ngay. That bai thi de lan sau thu lai, khong chan khach. */
    if (function_exists('apsp_enabled') && apsp_enabled()) {
        $e3 = '';
        try { apsp_sync_job($pdo, (int) $jobId, $e3); } catch (Exception $x) { }
    } else {
        @unlink($src);
    }
}

/* ================================================================== */
/*  QUAN TRI - tu day tro xuong bat buoc dang nhap                     */
/* ================================================================== */

$ME = apx_me();

switch ($ACT) {

/* --- Trang thai cau hinh --- */
case 'cfg': {
    $c = ap_cfg();
    apx_ok(array('data' => array(
        'gemini'  => trim((string) $c['gemini_key']) !== '',
        'fal'     => trim((string) $c['fal_key']) !== '',
        'model'     => $c['gemini_model'],
        'model_pro' => $c['gemini_model_pro'],
        'fal_model' => $c['fal_model'],
        'fal_model_pro' => $c['fal_model_pro'],
        'inflight' => (int) $c['max_inflight'],
        'is_admin' => apx_admin(),
    )));
}

/* --- Su kien --- */
case 'events': {
    $rows = $PDO->query("SELECT * FROM `ai_events` ORDER BY id DESC LIMIT 300")->fetchAll();
    $cnt = array();
    foreach ($PDO->query("SELECT event_id, COUNT(*) n FROM `ai_event_prompts` GROUP BY event_id") as $r)
        $cnt[(int) $r['event_id']] = (int) $r['n'];
    $out = array();
    foreach ($rows as $e) {
        $e['id'] = (int) $e['id'];
        $e['state']    = apx_state($e);
        $e['n_prompt'] = isset($cnt[$e['id']]) ? $cnt[$e['id']] : 0;
        $e['link']     = './ap.html?e=' . rawurlencode($e['slug']);
        $out[] = $e;
    }
    apx_ok(array('data' => array('items' => $out)));
}

case 'event-save': {
    $id   = (int) (isset($B['id']) ? $B['id'] : 0);
    $name = apx_s(isset($B['name']) ? $B['name'] : '', 160);
    if ($name === '') apx_fail('Chưa có tên sự kiện.');

    $f = array(
        'name'     => $name,
        'note'     => apx_s(isset($B['note']) ? $B['note'] : '', 500),
        'start_at' => apx_s(isset($B['start_at']) ? $B['start_at'] : '', 19) ?: null,
        'end_at'   => apx_s(isset($B['end_at'])   ? $B['end_at']   : '', 19) ?: null,
        'active'   => empty($B['active']) ? 0 : 1,
        'wm_pos'   => in_array(isset($B['wm_pos']) ? $B['wm_pos'] : 'br', array('tl','tr','bl','br','c'), true) ? $B['wm_pos'] : 'br',
        'wm_scale' => max(5, min(100, (int) (isset($B['wm_scale']) ? $B['wm_scale'] : 22))),
        'out_w'    => max(256, min(4096, (int) (isset($B['out_w']) ? $B['out_w'] : 1024))),
        'out_h'    => max(256, min(4096, (int) (isset($B['out_h']) ? $B['out_h'] : 1024))),
        'max_images' => max(0, (int) (isset($B['max_images']) ? $B['max_images'] : 0)),
        'model_tier' => ap_tier(isset($B['model_tier']) ? $B['model_tier'] : 'flash'),
    );

    if ($id > 0) {
        $sets = array(); $vals = array();
        foreach ($f as $k => $v) { $sets[] = "`$k` = ?"; $vals[] = $v; }
        $vals[] = $id;
        $PDO->prepare("UPDATE `ai_events` SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
    } else {
        $base = ap_slugify($name); $slug = $base; $i = 2;
        $q = $PDO->prepare("SELECT COUNT(*) FROM `ai_events` WHERE slug = ?");
        while (true) { $q->execute(array($slug)); if (!$q->fetchColumn()) break; $slug = $base . '-' . $i++; }
        $cols = array_keys($f); $cols[] = 'slug'; $cols[] = 'created_by'; $cols[] = 'created_at';
        $vals = array_values($f); $vals[] = $slug; $vals[] = $ME['display_name'];
        $ph = implode(',', array_fill(0, count($cols) - 1, '?')) . ', NOW()';
        $PDO->prepare("INSERT INTO `ai_events` (`" . implode('`,`', $cols) . "`) VALUES ($ph)")->execute($vals);
        $id = (int) $PDO->lastInsertId();
    }

    if (isset($B['prompts']) && is_array($B['prompts'])) {
        $PDO->prepare("DELETE FROM `ai_event_prompts` WHERE event_id = ?")->execute(array($id));
        $ins = $PDO->prepare("INSERT IGNORE INTO `ai_event_prompts` (event_id, prompt_id, sort_order) VALUES (?,?,?)");
        $i = 0;
        foreach ($B['prompts'] as $pid) { $ins->execute(array($id, (int) $pid, $i++)); }
    }
    apx_ok(array('data' => array('id' => $id)));
}

case 'event-del': {
    if (!apx_admin()) apx_fail('Chỉ Admin xoá được sự kiện.', 403);
    $id = (int) (isset($B['id']) ? $B['id'] : 0);
    if (!$id) apx_fail('Thiếu id.');
    $PDO->prepare("DELETE FROM `ai_event_prompts` WHERE event_id = ?")->execute(array($id));
    $PDO->prepare("DELETE FROM `ai_jobs` WHERE event_id = ?")->execute(array($id));
    $PDO->prepare("DELETE FROM `ai_events` WHERE id = ?")->execute(array($id));
    $d = ap_updir() . '/' . $id;
    if (is_dir($d)) { foreach (glob($d . '/*') as $x) @unlink($x); @rmdir($d); }
    apx_ok();
}

/* --- Tai anh bia / watermark / anh minh hoa prompt --- */
case 'upload': {
    $kind = isset($_GET['kind']) ? (string) $_GET['kind'] : '';
    if (!isset($_FILES['f']) || $_FILES['f']['error'] !== UPLOAD_ERR_OK) apx_fail('Chưa chọn file.');
    if ($_FILES['f']['size'] > 8 * 1024 * 1024) apx_fail('File quá lớn (tối đa 8MB).');
    $raw = file_get_contents($_FILES['f']['tmp_name']);
    $im = ap_load($raw);
    if (!$im) apx_fail('File không phải ảnh hợp lệ.');
    $isPng = (substr($raw, 0, 8) === "\x89PNG\r\n\x1a\n");
    imagedestroy($im);

    $ext = $isPng ? 'png' : 'jpg';
    $nm  = bin2hex(random_bytes(6)) . '.' . $ext;

    if ($kind === 'thumb') {
        $d = ap_updir() . '/p';
        if (!is_dir($d)) @mkdir($d, 0755, true);
        if (@file_put_contents($d . '/' . $nm, $raw) === false) apx_fail('Không lưu được.', 500);
        apx_ok(array('data' => array('file' => $nm, 'url' => './uploads/aiphoto/p/' . $nm)));
    }

    $id = (int) (isset($_GET['id']) ? $_GET['id'] : 0);
    if (!$id) apx_fail('Thiếu id sự kiện.');
    if ($kind === 'wm' && !$isPng) apx_fail('Watermark nên là PNG nền trong suốt.');
    $d = apx_evdir($id);
    if (@file_put_contents($d . '/' . $nm, $raw) === false) apx_fail('Không lưu được.', 500);
    $col = ($kind === 'wm') ? 'wm_file' : 'cover_file';
    $old = $PDO->query("SELECT `$col` FROM `ai_events` WHERE id = " . $id)->fetchColumn();
    $PDO->prepare("UPDATE `ai_events` SET `$col` = ? WHERE id = ?")->execute(array($nm, $id));
    if ($old && $old !== $nm) @unlink($d . '/' . $old);
    apx_ok(array('data' => array('file' => $nm, 'url' => './uploads/aiphoto/' . $id . '/' . $nm)));
}

case 'wm-clear': {
    $id = (int) (isset($B['id']) ? $B['id'] : 0);
    $old = $PDO->query("SELECT wm_file FROM `ai_events` WHERE id = " . $id)->fetchColumn();
    $PDO->prepare("UPDATE `ai_events` SET wm_file = NULL WHERE id = ?")->execute(array($id));
    if ($old) @unlink(ap_updir() . '/' . $id . '/' . $old);
    apx_ok();
}

/* --- Thu vien prompt --- */
case 'prompts': {
    $rows = $PDO->query("SELECT * FROM `ai_prompts` ORDER BY sort_order ASC, id DESC LIMIT 500")->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['thumb'] = $r['thumb_file'] ? ('./uploads/aiphoto/p/' . $r['thumb_file']) : '';
    }
    unset($r);
    apx_ok(array('data' => array('items' => $rows)));
}

case 'prompt-save': {
    $id    = (int) (isset($B['id']) ? $B['id'] : 0);
    $title = apx_s(isset($B['title']) ? $B['title'] : '', 160);
    $body  = trim((string) (isset($B['body']) ? $B['body'] : ''));
    if ($title === '') apx_fail('Chưa có tên kiểu ảnh.');
    if ($body === '')  apx_fail('Chưa có nội dung prompt.');
    $v = array(
        $title, mb_substr($body, 0, 4000), apx_s(isset($B['tag']) ? $B['tag'] : '', 60) ?: null,
        apx_s(isset($B['thumb_file']) ? $B['thumb_file'] : '', 200) ?: null,
        empty($B['active']) ? 0 : 1, (int) (isset($B['sort_order']) ? $B['sort_order'] : 0),
        empty($B['force_pro']) ? 0 : 1,
    );
    if ($id > 0) {
        $v[] = $id;
        $PDO->prepare("UPDATE `ai_prompts` SET title=?, body=?, tag=?, thumb_file=?, active=?, sort_order=?, force_pro=? WHERE id=?")
            ->execute($v);
    } else {
        $v[] = $ME['display_name'];
        $PDO->prepare("INSERT INTO `ai_prompts` (title, body, tag, thumb_file, active, sort_order, force_pro, created_by, created_at)
                       VALUES (?,?,?,?,?,?,?,?, NOW())")->execute($v);
        $id = (int) $PDO->lastInsertId();
    }
    apx_ok(array('data' => array('id' => $id)));
}

case 'prompt-del': {
    $id = (int) (isset($B['id']) ? $B['id'] : 0);
    if (!$id) apx_fail('Thiếu id.');
    $n = (int) $PDO->query("SELECT COUNT(*) FROM `ai_event_prompts` WHERE prompt_id = " . $id)->fetchColumn();
    if ($n > 0 && empty($B['force']))
        apx_fail('Prompt này đang được ' . $n . ' sự kiện dùng. Gỡ khỏi sự kiện trước, hoặc xác nhận xoá hẳn.', 409);
    $th = $PDO->query("SELECT thumb_file FROM `ai_prompts` WHERE id = " . $id)->fetchColumn();
    $PDO->prepare("DELETE FROM `ai_event_prompts` WHERE prompt_id = ?")->execute(array($id));
    $PDO->prepare("DELETE FROM `ai_prompts` WHERE id = ?")->execute(array($id));
    if ($th) @unlink(ap_updir() . '/p/' . $th);
    apx_ok();
}

/* --- Prompt cua 1 su kien --- */
case 'event-prompts': {
    $id = (int) (isset($_GET['id']) ? $_GET['id'] : 0);
    $q = $PDO->prepare("SELECT prompt_id FROM `ai_event_prompts` WHERE event_id = ? ORDER BY sort_order ASC");
    $q->execute(array($id));
    apx_ok(array('data' => array('ids' => array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)))));
}

/* --- Nhat ky tao anh --- */
case 'jobs': {
    $id = (int) (isset($_GET['id']) ? $_GET['id'] : 0);
    $w  = $id > 0 ? (' WHERE j.event_id = ' . $id) : '';
    $rows = $PDO->query(
        "SELECT j.token, j.state, j.provider, j.model, j.err, j.attempts, j.ms, j.created_at, j.finished_at,
                j.sp_out_id, j.sp_at, j.sp_err,
                e.name AS ev_name, p.title AS prompt_title
           FROM `ai_jobs` j
           LEFT JOIN `ai_events`  e ON e.id = j.event_id
           LEFT JOIN `ai_prompts` p ON p.id = j.prompt_id" . $w . "
          ORDER BY j.id DESC LIMIT 200")->fetchAll();
    $sum = $PDO->query(
        "SELECT state, COUNT(*) n, AVG(ms) avg_ms FROM `ai_jobs`" . str_replace('j.', '', $w) . " GROUP BY state")->fetchAll();
    $left = (int) $PDO->query("SELECT COUNT(*) FROM `ai_jobs`
         WHERE state = 'done' AND out_file IS NOT NULL AND sp_out_id IS NULL"
         . ($id > 0 ? ' AND event_id = ' . $id : ''))->fetchColumn();
    apx_ok(array('data' => array('items' => $rows, 'summary' => $sum,
                                 'sp_on' => apsp_enabled(), 'sp_left' => $left)));
}

/* --- Thu goi model 1 lan --- */
case 'test': {
    if (!ap_enabled()) apx_fail('Chưa khai báo khoá API trong api/ai-config.php.', 400);
    $im = imagecreatetruecolor(512, 512);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 210, 220));
    imagefilledellipse($im, 256, 210, 190, 190, imagecolorallocate($im, 235, 200, 175));
    imagefilledellipse($im, 256, 470, 300, 280, imagecolorallocate($im, 60, 80, 120));
    $bytes = ap_jpeg($im, 88);
    imagedestroy($im);

    $tier = ap_tier(isset($_GET['tier']) ? $_GET['tier'] : 'flash');
    $prov = ''; $err = '';
    $t0 = microtime(true);
    $g = ap_generate($bytes, 'image/jpeg',
        'A friendly professional headshot, studio lighting, plain background.', 1024, 1024, $prov, $err, $tier);
    $ms = (int) ((microtime(true) - $t0) * 1000);
    if ($g === false) apx_fail('Gọi model không thành công sau ' . $ms . 'ms: ' . $err, 502);
    apx_ok(array('data' => array('tier' => $tier, 'provider' => $prov, 'ms' => $ms, 'bytes' => strlen($g))));
}

/* --- SharePoint --- */
case 'sp-check': {
    list($ok, $msg) = apsp_check();
    if (!$ok) apx_fail($msg, 502);
    apx_ok(array('data' => array('msg' => $msg)));
}

case 'sp-sync': {
    $id = (int) (isset($B['id']) ? $B['id'] : 0);
    list($done, $bad, $left, $err) = apsp_sync_pending($PDO, $id, 25);
    $m = 'Đã đưa lên SharePoint ' . $done . ' ảnh';
    if ($bad > 0)  $m .= ', ' . $bad . ' ảnh lỗi';
    if ($left > 0) $m .= ', còn ' . $left . ' ảnh chờ (bấm lại để làm tiếp)';
    $m .= '.';
    if ($err !== '') $m .= ' Lỗi gần nhất: ' . $err;
    apx_ok(array('data' => array('msg' => $m, 'done' => $done, 'fail' => $bad, 'left' => $left)));
}

default:
    apx_fail('Hành động không hợp lệ: ' . $ACT, 404);
}
