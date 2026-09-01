<?php
/**
 * APSA - Dong bo anh cua module Chup anh AI len SharePoint.
 *
 * Cau truc trong thu muc goc "storate-photos-ai":
 *
 *   ddmmyyyy-Ten su kien/
 *       01-Anh-goc/    anh chup thang tu camera cua khach
 *       02-Anh-AI/     anh da qua AI va dong watermark
 *
 * Ngay trong ten thu muc lay theo ngay bat dau su kien; su kien khong dat
 * ngay bat dau thi lay ngay tao. Hai file cua cung mot luot chup dung chung
 * phan dau ten nen doi chieu giua hai thu muc rat de.
 *
 * Dung quyen ung dung Sites.ReadWrite.All qua api/msgraph.php.
 */

require_once __DIR__ . '/msgraph.php';

define('APSP_DRIVE',   'b!e4unr15XWkyaVG8edY6MkfZmAYHtCUBDugOk42Ie06yPpJHpQ4j6SpiGKdrEhZ21');
define('APSP_ROOT',    '01UTW2SKPMMIOD6IHRHZHKONJLX3VVPJ75');
define('APSP_SRC_DIR', '01-Anh-goc');
define('APSP_OUT_DIR', '02-Anh-AI');
define('APSP_BASE',    'https://graph.microsoft.com/v1.0/drives/' . APSP_DRIVE);

/** Da khai bao du thong tin Microsoft 365 chua. */
function apsp_enabled()
{
    if (!function_exists('mg_config')) return false;
    $c = mg_config();
    return !empty($c['enabled'])
        && trim((string) $c['tenant_id'])     !== ''
        && trim((string) $c['client_id'])     !== ''
        && trim((string) $c['client_secret']) !== '';
}

/** Token dung lai trong cung mot request. */
function apsp_tok(&$err)
{
    static $t = null;
    if ($t !== null) return $t;
    $e = '';
    $t = mg_token($e);
    if (!$t) { $err = $e !== '' ? $e : 'Khong lay duoc token Microsoft.'; $t = false; }
    return $t;
}

/** Bo cac ky tu SharePoint khong nhan trong ten thu muc / file. */
function apsp_name($s, $max = 90)
{
    $s = preg_replace('#[\\\\/:\*\?"<>\|\#%]+#u', ' ', (string) $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = trim($s, " .\t\n\r\0\x0B");
    if ($s === '') $s = 'khong-ten';
    $s = mb_substr($s, 0, $max, 'UTF-8');
    return trim($s, ' .');
}

/** Tim thu muc con, chua co thi tao. Tra ve itemId hoac false. */
function apsp_dir($tok, $parentId, $name, &$err)
{
    $c = 0;
    $j = mg_http('GET',
        APSP_BASE . '/items/' . rawurlencode($parentId) . ':/' . rawurlencode($name) . '?$select=id',
        array('Authorization: Bearer ' . $tok, 'Accept: application/json'), null, $c);
    if ($c === 200 && isset($j['id'])) return $j['id'];

    $body = json_encode(array(
        'name'                              => $name,
        'folder'                            => new stdClass(),
        '@microsoft.graph.conflictBehavior' => 'fail',
    ), JSON_UNESCAPED_UNICODE);

    $j = mg_http('POST', APSP_BASE . '/items/' . rawurlencode($parentId) . '/children',
        array('Authorization: Bearer ' . $tok, 'Content-Type: application/json', 'Accept: application/json'),
        $body, $c);
    if (($c === 201 || $c === 200) && isset($j['id'])) return $j['id'];

    /* Ai do vua tao truoc mot nhip - doc lai */
    if ($c === 409) {
        $j = mg_http('GET',
            APSP_BASE . '/items/' . rawurlencode($parentId) . ':/' . rawurlencode($name) . '?$select=id',
            array('Authorization: Bearer ' . $tok, 'Accept: application/json'), null, $c);
        if ($c === 200 && isset($j['id'])) return $j['id'];
    }
    $err = mg_err($j, $c, 'Khong tao duoc thu muc "' . $name . '"');
    return false;
}

/** Bao dam 3 thu muc cua su kien ton tai. Tra ve array(dir, src, out) hoac false. */
function apsp_event_dirs(PDO $pdo, array $ev, &$err)
{
    if (!empty($ev['sp_dir']) && !empty($ev['sp_src']) && !empty($ev['sp_out'])) {
        return array($ev['sp_dir'], $ev['sp_src'], $ev['sp_out']);
    }
    $tok = apsp_tok($err);
    if (!$tok) return false;

    $d  = !empty($ev['start_at']) ? $ev['start_at'] : (!empty($ev['created_at']) ? $ev['created_at'] : 'now');
    $ts = strtotime((string) $d);
    if (!$ts) $ts = time();
    $name = date('dmY', $ts) . '-' . apsp_name(isset($ev['name']) ? $ev['name'] : '');

    $dir = apsp_dir($tok, APSP_ROOT, $name, $err);
    if (!$dir) return false;
    $src = apsp_dir($tok, $dir, APSP_SRC_DIR, $err);
    if (!$src) return false;
    $out = apsp_dir($tok, $dir, APSP_OUT_DIR, $err);
    if (!$out) return false;

    try {
        $pdo->prepare('UPDATE `ai_events` SET sp_dir = ?, sp_src = ?, sp_out = ? WHERE id = ?')
            ->execute(array($dir, $src, $out, (int) $ev['id']));
    } catch (Exception $e) { /* khong chan luong chinh */ }

    return array($dir, $src, $out);
}

/** Tai mot file len. Tra ve itemId hoac false. */
function apsp_put($tok, $parentId, $filename, $bytes, &$err)
{
    $c = 0;
    $j = mg_http('PUT',
        APSP_BASE . '/items/' . rawurlencode($parentId) . ':/' . rawurlencode($filename)
            . ':/content?@microsoft.graph.conflictBehavior=replace',
        array('Authorization: Bearer ' . $tok, 'Content-Type: image/jpeg'), $bytes, $c);
    if (($c === 200 || $c === 201) && isset($j['id'])) return $j['id'];
    $err = mg_err($j, $c, 'Khong tai duoc "' . $filename . '"');
    return false;
}

function apsp_note_err(PDO $pdo, $jobId, $msg)
{
    try {
        $pdo->prepare('UPDATE `ai_jobs` SET sp_err = ? WHERE id = ?')
            ->execute(array(mb_substr((string) $msg, 0, 300, 'UTF-8'), (int) $jobId));
    } catch (Exception $e) { }
}

/**
 * Dong bo mot luot chup da xong.
 * Anh goc tren server bi xoa sau khi da nam an toan tren SharePoint.
 */
function apsp_sync_job(PDO $pdo, $jobId, &$err)
{
    $err = '';
    if (!apsp_enabled()) { $err = 'Chua khai bao ket noi Microsoft 365 trong api/msgraph-config.php.'; return false; }

    $st = $pdo->prepare(
        "SELECT j.*, e.id AS ev_id, e.name AS ev_name, e.start_at, e.created_at AS ev_created,
                e.sp_dir, e.sp_src, e.sp_out
           FROM `ai_jobs` j
           JOIN `ai_events` e ON e.id = j.event_id
          WHERE j.id = ? AND j.state = 'done'");
    $st->execute(array((int) $jobId));
    $j = $st->fetch();
    if (!$j) { $err = 'Khong tim thay luot chup da xong.'; return false; }

    $needSrc = empty($j['sp_src_id']) && !empty($j['src_file']);
    $needOut = empty($j['sp_out_id']) && !empty($j['out_file']);
    if (!$needSrc && !$needOut) return true;

    $ev = array(
        'id' => $j['ev_id'], 'name' => $j['ev_name'], 'start_at' => $j['start_at'],
        'created_at' => $j['ev_created'], 'sp_dir' => $j['sp_dir'],
        'sp_src' => $j['sp_src'], 'sp_out' => $j['sp_out'],
    );
    $dirs = apsp_event_dirs($pdo, $ev, $err);
    if (!$dirs) { apsp_note_err($pdo, $jobId, $err); return false; }
    list($dir, $srcDir, $outDir) = $dirs;

    $tok = apsp_tok($err);
    if (!$tok) { apsp_note_err($pdo, $jobId, $err); return false; }

    $ts     = strtotime((string) $j['created_at']);
    $base   = date('Ymd-His', $ts ? $ts : time()) . '-' . substr((string) $j['token'], 0, 8);
    $folder = ap_updir() . '/' . (int) $j['event_id'];
    $srcId  = $j['sp_src_id'];
    $outId  = $j['sp_out_id'];

    if ($needSrc && is_file($folder . '/' . $j['src_file'])) {
        $b = @file_get_contents($folder . '/' . $j['src_file']);
        if ($b !== false) {
            $srcId = apsp_put($tok, $srcDir, $base . '-goc.jpg', $b, $err);
            if (!$srcId) { apsp_note_err($pdo, $jobId, $err); return false; }
        }
    }
    if ($needOut && is_file($folder . '/' . $j['out_file'])) {
        $b = @file_get_contents($folder . '/' . $j['out_file']);
        if ($b !== false) {
            $outId = apsp_put($tok, $outDir, $base . '-ai.jpg', $b, $err);
            if (!$outId) { apsp_note_err($pdo, $jobId, $err); return false; }
        }
    }

    $pdo->prepare('UPDATE `ai_jobs` SET sp_src_id = ?, sp_out_id = ?, sp_at = NOW(), sp_err = NULL WHERE id = ?')
        ->execute(array($srcId ? $srcId : null, $outId ? $outId : null, (int) $jobId));

    /* Anh goc da an toan tren SharePoint - xoa ban tren server cho nhe dia */
    if ($srcId && !empty($j['src_file'])) {
        @unlink($folder . '/' . $j['src_file']);
        try {
            $pdo->prepare('UPDATE `ai_jobs` SET src_file = NULL WHERE id = ?')->execute(array((int) $jobId));
        } catch (Exception $e) { }
    }
    return true;
}

/** Dong bo cac luot con thieu. Tra ve array(so_xong, so_loi, con_lai, loi_cuoi). */
function apsp_sync_pending(PDO $pdo, $eventId = 0, $limit = 25)
{
    $w = $eventId > 0 ? (' AND event_id = ' . (int) $eventId) : '';
    $q = "SELECT id FROM `ai_jobs`
           WHERE state = 'done' AND out_file IS NOT NULL AND sp_out_id IS NULL" . $w . "
           ORDER BY id ASC LIMIT " . (int) $limit;
    $ids = $pdo->query($q)->fetchAll(PDO::FETCH_COLUMN);

    $ok = 0; $bad = 0; $err = ''; $last = '';
    foreach ($ids as $id) {
        if (apsp_sync_job($pdo, (int) $id, $err)) $ok++;
        else { $bad++; if ($err !== '') $last = $err; }
    }
    $left = (int) $pdo->query(
        "SELECT COUNT(*) FROM `ai_jobs`
          WHERE state = 'done' AND out_file IS NOT NULL AND sp_out_id IS NULL" . $w)->fetchColumn();

    return array($ok, $bad, $left, $last);
}

/** Kiem tra ket noi: doc thu muc goc. Tra ve array(ok, thong_diep). */
function apsp_check()
{
    if (!apsp_enabled()) return array(false, 'Chua khai bao ket noi Microsoft 365.');
    $err = '';
    $tok = apsp_tok($err);
    if (!$tok) return array(false, $err);
    $c = 0;
    $j = mg_http('GET', APSP_BASE . '/items/' . rawurlencode(APSP_ROOT) . '?$select=id,name,webUrl',
        array('Authorization: Bearer ' . $tok, 'Accept: application/json'), null, $c);
    if ($c === 200 && isset($j['name'])) return array(true, 'Đã kết nối thư mục "' . $j['name'] . '" trên SharePoint.');
    return array(false, mg_err($j, $c, 'Không đọc được thư mục gốc trên SharePoint'));
}
