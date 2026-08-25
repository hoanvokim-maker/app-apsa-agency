<?php
/**
 * APSA - API module Xin nghi phep
 * ------------------------------------------------------------------
 *  - Nhan vien nop don (nguyen ngay / nua ngay), he thong tu tinh so ngay
 *  - Admin duyet hoac tu choi
 *  - Duyet xong -> tu dong tao su kien tren lich Outlook cua hop thu cong ty
 *  - Quy phep nam mac dinh 14 ngay/nguoi/nam, Admin chinh duoc
 *
 *  Bang: leave_requests, leave_quota   (tu tao khi chay lan dau)
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/session-boot.php';
require_once __DIR__ . '/msgraph.php';
require_once __DIR__ . '/settings-api.php';

/* ------------------------------------------------------------------ *
 *  Ha tang chung
 * ------------------------------------------------------------------ */

function lv_out($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function lv_fail($msg, $code = 400)
{
    lv_out(array('ok' => false, 'error' => $msg), $code);
}

function lv_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return array();
    $j = json_decode($raw, true);
    return is_array($j) ? $j : array();
}

/** Ket noi CSDL - cung kieu voi cac file API khac trong app. */
function lv_pdo()
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
        lv_fail('Khong ket noi duoc co so du lieu.', 500);
    }
    return $pdo;
}

/** Nguoi dung dang dang nhap. */
function lv_me()
{
    static $me = null;
    if ($me !== null) return $me;

    $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if ($uid <= 0) lv_fail('Chua dang nhap.', 401);

    $st = lv_pdo()->prepare('SELECT * FROM app_users WHERE id = ? LIMIT 1');
    $st->execute(array($uid));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) lv_fail('Chua dang nhap.', 401);
    if (isset($row['active']) && (int) $row['active'] === 0) lv_fail('Tai khoan da bi khoa.', 403);

    $me = array(
        'id'    => (int) $row['id'],
        'name'  => trim((string) (!empty($row['display_name']) ? $row['display_name'] : $row['username'])),
        'user'  => (string) $row['username'],
        'email' => isset($row['email']) ? (string) $row['email'] : '',
        'role'  => isset($row['role']) ? (string) $row['role'] : '',
    );
    return $me;
}

function lv_is_admin()
{
    $me = lv_me();
    return strcasecmp($me['role'], 'admin') === 0;
}

function lv_need_admin()
{
    if (!lv_is_admin()) lv_fail('Chi Admin moi thuc hien duoc thao tac nay.', 403);
}

/* ------------------------------------------------------------------ *
 *  Tao bang (chay 1 lan, co khoa theo filemtime)
 * ------------------------------------------------------------------ */

function lv_boot()
{
    $stamp = sys_get_temp_dir() . '/apsa_leave_schema_' . filemtime(__FILE__) . '.ok';
    if (is_file($stamp)) return;

    $pdo = lv_pdo();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS leave_requests (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            user_id         INT NOT NULL,
            user_name       VARCHAR(190) NOT NULL DEFAULT '',
            user_email      VARCHAR(190) NOT NULL DEFAULT '',
            leave_type      VARCHAR(20)  NOT NULL DEFAULT 'annual',
            start_date      DATE NOT NULL,
            start_part      VARCHAR(4)   NOT NULL DEFAULT 'full',
            end_date        DATE NOT NULL,
            end_part        VARCHAR(4)   NOT NULL DEFAULT 'full',
            skip_weekend    TINYINT(1)   NOT NULL DEFAULT 1,
            days            DECIMAL(5,1) NOT NULL DEFAULT 0,
            reason          TEXT NULL,
            handover        VARCHAR(190) NOT NULL DEFAULT '',
            status          VARCHAR(20)  NOT NULL DEFAULT 'pending',
            decided_by      INT NULL,
            decided_by_name VARCHAR(190) NOT NULL DEFAULT '',
            decided_at      DATETIME NULL,
            decide_note     TEXT NULL,
            cal_event_id    VARCHAR(600) NOT NULL DEFAULT '',
            cal_status      VARCHAR(20)  NOT NULL DEFAULT '',
            cal_error       TEXT NULL,
            cal_link        VARCHAR(600) NOT NULL DEFAULT '',
            created_at      DATETIME NOT NULL,
            updated_at      DATETIME NOT NULL,
            INDEX ix_user   (user_id),
            INDEX ix_status (status),
            INDEX ix_start  (start_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS leave_quota (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT NOT NULL,
            year       SMALLINT NOT NULL,
            total      DECIMAL(5,1) NOT NULL DEFAULT 14,
            note       VARCHAR(255) NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_user_year (user_id, year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Bo sung cot cho cai dat da co tu truoc (khong bao loi neu da ton tai)
    $add = array(
        'user_email'   => "ALTER TABLE leave_requests ADD COLUMN user_email VARCHAR(190) NOT NULL DEFAULT ''",
        'handover'     => "ALTER TABLE leave_requests ADD COLUMN handover VARCHAR(190) NOT NULL DEFAULT ''",
        'cal_link'     => "ALTER TABLE leave_requests ADD COLUMN cal_link VARCHAR(600) NOT NULL DEFAULT ''",
        'skip_weekend' => "ALTER TABLE leave_requests ADD COLUMN skip_weekend TINYINT(1) NOT NULL DEFAULT 1",
    );
    $have = array();
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM leave_requests') as $c) $have[$c['Field']] = 1;
    } catch (Exception $e) { $have = array(); }
    foreach ($add as $col => $sql) {
        if ($have && !isset($have[$col])) { try { $pdo->exec($sql); } catch (Exception $e) {} }
    }

    @file_put_contents($stamp, '1');
}

/* ------------------------------------------------------------------ *
 *  Nhan loai nghi + trang thai
 * ------------------------------------------------------------------ */

function lv_types()
{
    static $c = null;
    if ($c !== null) return $c;
    $c = array();
    foreach (st_leave_types() as $t) $c[$t['key']] = $t['label'];
    if (!$c) $c = array('annual' => 'Phép năm', 'unpaid' => 'Nghỉ không lương', 'sick' => 'Nghỉ ốm', 'other' => 'Khác');
    return $c;
}

/** Nhung loai nghi co tru vao quy phep nam. */
function lv_deduct_types()
{
    static $c = null;
    if ($c !== null) return $c;
    $c = array();
    foreach (st_leave_types() as $t) if (!empty($t['deduct'])) $c[] = $t['key'];
    if (!$c) $c = array('annual');
    return $c;
}

/** Nhan loai nghi cho ca loai da bi an (don cu van doc duoc ten). */
function lv_type_label($k)
{
    $t = lv_types();
    if (isset($t[$k])) return $t[$k];
    try {
        $st = lv_pdo()->prepare('SELECT label FROM leave_types WHERE tkey = ? LIMIT 1');
        $st->execute(array($k));
        $l = $st->fetchColumn();
        if ($l) return $l;
    } catch (Exception $e) {}
    return $k;
}

function lv_statuses()
{
    return array(
        'pending'  => 'Chờ duyệt',
        'approved' => 'Đã duyệt',
        'rejected' => 'Từ chối',
        'canceled' => 'Đã huỷ',
    );
}

function lv_part_label($p)
{
    if ($p === 'am') return 'buổi sáng';
    if ($p === 'pm') return 'buổi chiều';
    return 'cả ngày';
}

/* ------------------------------------------------------------------ *
 *  Tinh so ngay nghi
 * ------------------------------------------------------------------ */

/**
 * Tra ve mang cac ngay nghi: array( array('date'=>'Y-m-d','part'=>'full|am|pm','val'=>1|0.5), ... )
 * $skipWeekend = bo qua thu 7 va chu nhat.
 */
function lv_expand($startDate, $startPart, $endDate, $endPart, $skipWeekend)
{
    $out = array();

    $s = DateTime::createFromFormat('Y-m-d', $startDate);
    $e = DateTime::createFromFormat('Y-m-d', $endDate);
    if (!$s || !$e) return $out;
    $s->setTime(0, 0, 0);
    $e->setTime(0, 0, 0);
    if ($e < $s) return $out;

    // Gioi han an toan: khong cho khoang qua 366 ngay
    $span = (int) $s->diff($e)->format('%a');
    if ($span > 366) return $out;

    $HOL     = st_holidays();
    $WD      = st_work_days();          // 1=T2 ... 7=CN
    $single  = ($s->format('Y-m-d') === $e->format('Y-m-d'));
    $cursor  = clone $s;
    $lastKey = $e->format('Y-m-d');

    while ($cursor <= $e) {
        $key = $cursor->format('Y-m-d');
        $dow = (int) $cursor->format('N'); // 1=T2 ... 6=T7, 7=CN

        if ($skipWeekend && empty($WD[$dow])) { $cursor->modify('+1 day'); continue; }   // ngoai ngay lam viec
        if (isset($HOL[$key]))          { $cursor->modify('+1 day'); continue; }   // ngay le -> khong tinh

        if ($single) {
            $part = $startPart;
        } elseif ($key === $s->format('Y-m-d')) {
            // Ngay dau: chi 'pm' moi co y nghia (nghi tu chieu). 'am' coi nhu nghi nua ngay sang.
            $part = $startPart;
        } elseif ($key === $lastKey) {
            $part = $endPart;
        } else {
            $part = 'full';
        }
        if ($part !== 'am' && $part !== 'pm') $part = 'full';

        $out[] = array(
            'date' => $key,
            'part' => $part,
            'val'  => ($part === 'full') ? 1.0 : 0.5,
        );
        $cursor->modify('+1 day');
    }

    return $out;
}

function lv_count_days($days)
{
    $t = 0.0;
    foreach ($days as $d) $t += $d['val'];
    return round($t, 1);
}

/* ------------------------------------------------------------------ *
 *  Quy phep nam
 * ------------------------------------------------------------------ */

function lv_quota_total($userId, $year)
{
    $st = lv_pdo()->prepare('SELECT total FROM leave_quota WHERE user_id = ? AND year = ? LIMIT 1');
    $st->execute(array($userId, $year));
    $v = $st->fetchColumn();
    if ($v === false || $v === null) return (float) st_get('leave.default_quota', 14);
    return (float) $v;
}

/** So ngay phep nam da duyet trong nam (tinh dong tu don, khong luu bien dem). */
function lv_quota_used($userId, $year, $exceptId = 0)
{
    $ded = lv_deduct_types();
    $in  = implode(',', array_fill(0, count($ded), '?'));
    $sql = "SELECT COALESCE(SUM(days),0) FROM leave_requests
             WHERE user_id = ? AND leave_type IN ($in) AND status = 'approved'
               AND YEAR(start_date) = ?";
    $arg = array_merge(array($userId), $ded, array($year));
    if ($exceptId > 0) { $sql .= ' AND id <> ?'; $arg[] = $exceptId; }
    $st = lv_pdo()->prepare($sql);
    $st->execute($arg);
    return round((float) $st->fetchColumn(), 1);
}

/** So ngay phep nam dang cho duyet (de canh bao truoc). */
function lv_quota_pending($userId, $year)
{
    $ded = lv_deduct_types();
    $in  = implode(',', array_fill(0, count($ded), '?'));
    $st  = lv_pdo()->prepare(
        "SELECT COALESCE(SUM(days),0) FROM leave_requests
          WHERE user_id = ? AND leave_type IN ($in) AND status = 'pending'
            AND YEAR(start_date) = ?"
    );
    $st->execute(array_merge(array($userId), $ded, array($year)));
    return round((float) $st->fetchColumn(), 1);
}

function lv_summary($userId, $year)
{
    $total = lv_quota_total($userId, $year);
    $used  = lv_quota_used($userId, $year);
    $pend  = lv_quota_pending($userId, $year);
    return array(
        'year'      => $year,
        'total'     => $total,
        'used'      => $used,
        'pending'   => $pend,
        'remaining' => round($total - $used, 1),
    );
}

/* ------------------------------------------------------------------ *
 *  Thong bao trong app (dung lai bang notification san co neu co)
 * ------------------------------------------------------------------ */

function lv_notify_table()
{
    static $info = null;
    if ($info !== null) return $info;

    $info = false;
    $pdo  = lv_pdo();
    foreach (array('notifications', 'app_notifications', 'quotation_notifications') as $t) {
        try {
            $cols = array();
            foreach ($pdo->query('SHOW COLUMNS FROM `' . $t . '`') as $c) $cols[$c['Field']] = 1;
            if ($cols) { $info = array('table' => $t, 'cols' => $cols); break; }
        } catch (Exception $e) { /* bang khong ton tai -> thu bang khac */ }
    }
    return $info;
}

/** Gui thong bao. Loi o day KHONG duoc lam hong nghiep vu chinh. */
require_once __DIR__ . '/zalo.php';   /* APSA127-ZALO */
function lv_notify($userId, $kind, $title, $body, $url)
{
    if (function_exists('zb_push')) zb_push(lv_pdo(), $userId, $kind, $title, $body, $url);
    try {
        $t = lv_notify_table();
        if (!$t) return;

        $me   = lv_me();
        $cut  = function ($s, $n) { return mb_substr((string) $s, 0, $n, 'UTF-8'); };
        $cand = array(
            'user_id'      => $userId,
            'kind'         => $cut($kind, 24),
            'type'         => $cut($kind, 24),
            'title'        => $cut($title, 200),
            'body'         => $cut($body, 500),
            'message'      => $cut($body, 500),
            'content'      => $cut($body, 500),
            'url'          => $cut($url, 300),
            'link'         => $cut($url, 300),
            'actor'        => $cut($me['name'], 120),
            'actor_name'   => $cut($me['name'], 120),
            'actor_id'     => $me['id'],
            'is_read'      => 0,
            'seen'         => 0,
            'created_at'   => date('Y-m-d H:i:s'),
        );

        $cols = array(); $marks = array(); $vals = array();
        foreach ($cand as $c => $v) {
            if (isset($t['cols'][$c])) { $cols[] = '`' . $c . '`'; $marks[] = '?'; $vals[] = $v; }
        }
        if (!$cols) return;

        $sql = 'INSERT INTO `' . $t['table'] . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $marks) . ')';
        lv_pdo()->prepare($sql)->execute($vals);
    } catch (Exception $e) { /* im lang */ }
}

function lv_admin_ids()
{
    $out = array();
    try {
        $st = lv_pdo()->query("SELECT id FROM app_users WHERE LOWER(role) = 'admin'");
        foreach ($st as $r) $out[] = (int) $r['id'];
    } catch (Exception $e) {}
    return $out;
}

/* ------------------------------------------------------------------ *
 *  Doc / dinh dang don
 * ------------------------------------------------------------------ */

function lv_row($id)
{
    $st = lv_pdo()->prepare('SELECT * FROM leave_requests WHERE id = ? LIMIT 1');
    $st->execute(array((int) $id));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? $r : null;
}

function lv_shape($r)
{
    $stt   = lv_statuses();
    $t     = $r['leave_type'];
    $s     = $r['status'];

    return array(
        'id'           => (int) $r['id'],
        'user_id'      => (int) $r['user_id'],
        'user_name'    => $r['user_name'],
        'user_email'   => $r['user_email'],
        'leave_type'   => $t,
        'type_label'   => lv_type_label($t),
        'start_date'   => $r['start_date'],
        'start_part'   => $r['start_part'],
        'end_date'     => $r['end_date'],
        'end_part'     => $r['end_part'],
        'skip_weekend' => (int) $r['skip_weekend'],
        'days'         => (float) $r['days'],
        'reason'       => (string) $r['reason'],
        'handover'     => (string) $r['handover'],
        'status'       => $s,
        'status_label' => isset($stt[$s]) ? $stt[$s] : $s,
        'decided_by_name' => $r['decided_by_name'],
        'decided_at'   => $r['decided_at'],
        'decide_note'  => (string) $r['decide_note'],
        'cal_status'   => $r['cal_status'],
        'cal_error'    => (string) $r['cal_error'],
        'cal_link'     => $r['cal_link'],
        'has_event'    => $r['cal_event_id'] !== '',
        'created_at'   => $r['created_at'],
    );
}

function lv_range_text($r)
{
    $a = date('d/m/Y', strtotime($r['start_date']));
    $b = date('d/m/Y', strtotime($r['end_date']));
    if ($r['start_date'] === $r['end_date']) {
        $p = lv_part_label($r['start_part']);
        return $a . ($p === 'cả ngày' ? '' : ' (' . $p . ')');
    }
    $pa = $r['start_part'] !== 'full' ? ' (' . lv_part_label($r['start_part']) . ')' : '';
    $pb = $r['end_part']   !== 'full' ? ' (' . lv_part_label($r['end_part'])   . ')' : '';
    return $a . $pa . ' → ' . $b . $pb;
}

/* ------------------------------------------------------------------ *
 *  Day len lich Outlook
 * ------------------------------------------------------------------ */

function lv_push_calendar($r)
{
    $cfg   = array_merge(mg_config(), st_json('leave.work_hours', array()));
    $days  = lv_expand($r['start_date'], $r['start_part'], $r['end_date'], $r['end_part'], (int) $r['skip_weekend'] === 1);
    if (!$days) return array('ok' => false, 'id' => '', 'web_link' => '', 'error' => 'Khoang ngay khong hop le.');

    $first = $days[0];
    $last  = $days[count($days) - 1];

    $allDay = ($first['part'] === 'full' && $last['part'] === 'full');

    if ($allDay) {
        $start = $first['date'];
        // Graph: ngay ket thuc cua su kien ca ngay la NGAY KE TIEP ngay cuoi cung
        $end   = date('Y-m-d', strtotime($last['date'] . ' +1 day'));
    } else {
        $sTime = ($first['part'] === 'pm') ? $cfg['pm_start'] : $cfg['am_start'];
        $eTime = ($last['part']  === 'am') ? $cfg['am_end']   : $cfg['pm_end'];
        $start = $first['date'] . 'T' . $sTime . ':00';
        $end   = $last['date']  . 'T' . $eTime . ':00';
    }

    $tname = lv_type_label($r['leave_type']);

    $body  = '<b>' . htmlspecialchars($r['user_name'], ENT_QUOTES, 'UTF-8') . '</b> — ' . $tname
           . '<br>Thời gian: ' . htmlspecialchars(lv_range_text($r), ENT_QUOTES, 'UTF-8')
           . '<br>Số ngày: <b>' . rtrim(rtrim(number_format((float) $r['days'], 1, ',', ''), '0'), ',') . '</b>';
    if (trim((string) $r['reason']) !== '') {
        $body .= '<br>Lý do: ' . nl2br(htmlspecialchars($r['reason'], ENT_QUOTES, 'UTF-8'));
    }
    if (trim((string) $r['handover']) !== '') {
        $body .= '<br>Bàn giao cho: ' . htmlspecialchars($r['handover'], ENT_QUOTES, 'UTF-8');
    }
    $body .= '<br><br><i>Tạo tự động từ app.apsa.agency — đơn #' . (int) $r['id'] . '</i>';

    return mg_create_event(array(
        'subject'  => 'Nghỉ phép — ' . $r['user_name'],
        'body'     => $body,
        'all_day'  => $allDay,
        'start'    => $start,
        'end'      => $end,
        'attendee' => $r['user_email'],
    ));
}

function lv_save_cal_result($id, $res)
{
    $st = lv_pdo()->prepare(
        'UPDATE leave_requests
            SET cal_event_id = ?, cal_status = ?, cal_error = ?, cal_link = ?, updated_at = ?
          WHERE id = ?'
    );
    $st->execute(array(
        $res['ok'] ? $res['id'] : '',
        $res['ok'] ? 'ok' : 'error',
        $res['ok'] ? '' : $res['error'],
        $res['ok'] && isset($res['web_link']) ? $res['web_link'] : '',
        date('Y-m-d H:i:s'),
        (int) $id,
    ));
}

/* ================================================================== *
 *  Dieu phoi
 * ================================================================== */

lv_boot();

$action = isset($_GET['action']) ? $_GET['action'] : '';
$B      = lv_body();
$ME     = lv_me();
$now    = date('Y-m-d H:i:s');

switch ($action) {

/* ---------------- thong tin nguoi dung + cau hinh ---------------- */
case 'me':
    $year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
    lv_out(array(
        'ok'        => true,
        'me'        => $ME,
        'is_admin'  => lv_is_admin(),
        'types'     => lv_types(),
        'deduct'    => lv_deduct_types(),
        'holidays'  => st_holidays(),
        'work_days' => array_map('intval', array_keys(st_work_days())),
        'statuses'  => lv_statuses(),
        'summary'   => lv_summary($ME['id'], $year),
        'calendar'  => array(
            'enabled' => mg_enabled(),
            'mailbox' => mg_config()['mailbox'],
        ),
    ));
    break;

/* ---------------- xem truoc so ngay ---------------- */
case 'preview':
    $days = lv_expand(
        isset($B['start_date']) ? $B['start_date'] : '',
        isset($B['start_part']) ? $B['start_part'] : 'full',
        isset($B['end_date'])   ? $B['end_date']   : '',
        isset($B['end_part'])   ? $B['end_part']   : 'full',
        !empty($B['skip_weekend'])
    );
    lv_out(array('ok' => true, 'days' => lv_count_days($days), 'detail' => $days));
    break;

/* ---------------- danh sach don ---------------- */
case 'list':
    $scope  = isset($_GET['scope'])  ? $_GET['scope']  : 'mine';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $year   = isset($_GET['year'])   ? (int) $_GET['year'] : 0;
    $uid    = isset($_GET['user_id'])? (int) $_GET['user_id'] : 0;

    $where = array(); $args = array();

    if ($scope === 'all') {
        lv_need_admin();
        if ($uid > 0) { $where[] = 'user_id = ?'; $args[] = $uid; }
    } else {
        $where[] = 'user_id = ?'; $args[] = $ME['id'];
    }
    if ($status !== '' && isset(lv_statuses()[$status])) { $where[] = 'status = ?'; $args[] = $status; }
    if ($year > 0) { $where[] = 'YEAR(start_date) = ?'; $args[] = $year; }

    $sql = 'SELECT * FROM leave_requests';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= " ORDER BY FIELD(status,'pending','approved','rejected','canceled'), start_date DESC, id DESC LIMIT 500";

    $st = lv_pdo()->prepare($sql);
    $st->execute($args);

    $rows = array();
    foreach ($st as $r) $rows[] = lv_shape($r);

    lv_out(array('ok' => true, 'rows' => $rows, 'is_admin' => lv_is_admin()));
    break;

/* ---------------- so don cho duyet (badge) ---------------- */
case 'pending-count':
    if (!lv_is_admin()) lv_out(array('ok' => true, 'count' => 0));
    $n = (int) lv_pdo()->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn();
    lv_out(array('ok' => true, 'count' => $n));
    break;

/* ---------------- nop don / sua don ---------------- */
case 'save':
    $id        = isset($B['id']) ? (int) $B['id'] : 0;
    $type      = isset($B['leave_type']) ? $B['leave_type'] : 'annual';
    $sDate     = isset($B['start_date']) ? trim($B['start_date']) : '';
    $eDate     = isset($B['end_date'])   ? trim($B['end_date'])   : '';
    $sPart     = isset($B['start_part']) ? $B['start_part'] : 'full';
    $ePart     = isset($B['end_part'])   ? $B['end_part']   : 'full';
    $skipWe    = !empty($B['skip_weekend']) ? 1 : 0;
    $reason    = isset($B['reason'])   ? trim($B['reason'])   : '';
    $handover  = isset($B['handover']) ? trim($B['handover']) : '';

    if (!isset(lv_types()[$type])) lv_fail('Loại nghỉ không hợp lệ.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sDate)) lv_fail('Thiếu ngày bắt đầu.');
    if ($eDate === '') $eDate = $sDate;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eDate)) lv_fail('Ngày kết thúc không hợp lệ.');
    if ($eDate < $sDate) lv_fail('Ngày kết thúc phải sau ngày bắt đầu.');
    if (!in_array($sPart, array('full', 'am', 'pm'), true)) $sPart = 'full';
    if (!in_array($ePart, array('full', 'am', 'pm'), true)) $ePart = 'full';
    if ($sDate === $eDate) $ePart = $sPart;

    $days = lv_count_days(lv_expand($sDate, $sPart, $eDate, $ePart, $skipWe === 1));
    if ($days <= 0) lv_fail('Khoảng ngày đã chọn không có ngày làm việc nào.');
    if ($reason === '') lv_fail('Vui lòng nhập lý do nghỉ.');

    if ($id > 0) {
        $r = lv_row($id);
        if (!$r) lv_fail('Không tìm thấy đơn.', 404);
        if ((int) $r['user_id'] !== $ME['id'] && !lv_is_admin()) lv_fail('Bạn chỉ sửa được đơn của mình.', 403);
        if ($r['status'] !== 'pending') lv_fail('Đơn đã được xử lý, không sửa được nữa.', 423);

        $st = lv_pdo()->prepare(
            'UPDATE leave_requests SET leave_type=?, start_date=?, start_part=?, end_date=?, end_part=?,
                    skip_weekend=?, days=?, reason=?, handover=?, updated_at=? WHERE id=?'
        );
        $st->execute(array($type, $sDate, $sPart, $eDate, $ePart, $skipWe, $days, $reason, $handover, $now, $id));

        lv_out(array('ok' => true, 'id' => $id, 'days' => $days, 'row' => lv_shape(lv_row($id))));
    }

    $st = lv_pdo()->prepare(
        'INSERT INTO leave_requests
            (user_id, user_name, user_email, leave_type, start_date, start_part, end_date, end_part,
             skip_weekend, days, reason, handover, status, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,\'pending\',?,?)'
    );
    $st->execute(array(
        $ME['id'], $ME['name'], $ME['email'], $type, $sDate, $sPart, $eDate, $ePart,
        $skipWe, $days, $reason, $handover, $now, $now
    ));
    $newId = (int) lv_pdo()->lastInsertId();

    $row  = lv_row($newId);
    $tl   = lv_types();
    $body = $ME['name'] . ' xin nghỉ ' . rtrim(rtrim(number_format($days, 1, ',', ''), '0'), ',')
          . ' ngày (' . $tl[$type] . '): ' . lv_range_text($row);
    foreach (lv_admin_ids() as $aid) {
        if ($aid === $ME['id']) continue;
        lv_notify($aid, 'leave_new', 'Đơn xin nghỉ mới', $body, '/leave.html?id=' . $newId);
    }

    lv_out(array('ok' => true, 'id' => $newId, 'days' => $days, 'row' => lv_shape($row)));
    break;

/* ---------------- nguoi nop tu huy don ---------------- */
case 'cancel':
    $id = isset($B['id']) ? (int) $B['id'] : 0;
    $r  = lv_row($id);
    if (!$r) lv_fail('Không tìm thấy đơn.', 404);
    if ((int) $r['user_id'] !== $ME['id'] && !lv_is_admin()) lv_fail('Bạn chỉ huỷ được đơn của mình.', 403);
    if ($r['status'] === 'approved' && !lv_is_admin()) {
        lv_fail('Đơn đã được duyệt — cần Admin huỷ giúp.', 423);
    }
    if ($r['status'] === 'canceled') lv_fail('Đơn đã huỷ rồi.');

    // Neu da co su kien tren lich thi go xuong
    $calNote = '';
    if ($r['cal_event_id'] !== '') {
        $del = mg_delete_event($r['cal_event_id']);
        if (!$del['ok']) $calNote = ' (chưa xoá được sự kiện trên lịch: ' . $del['error'] . ')';
    }

    $st = lv_pdo()->prepare(
        "UPDATE leave_requests SET status='canceled', cal_event_id='', cal_status='', cal_link='',
                decided_by=?, decided_by_name=?, decided_at=?, updated_at=? WHERE id=?"
    );
    $st->execute(array($ME['id'], $ME['name'], $now, $now, $id));

    if ((int) $r['user_id'] !== $ME['id']) {
        lv_notify((int) $r['user_id'], 'leave_canceled', 'Đơn nghỉ bị huỷ',
            $ME['name'] . ' đã huỷ đơn nghỉ ' . lv_range_text($r) . ' của bạn.', '/leave.html?id=' . $id);
    }

    lv_out(array('ok' => true, 'message' => 'Đã huỷ đơn.' . $calNote, 'row' => lv_shape(lv_row($id))));
    break;

/* ---------------- Xoa han don da huy / bi tu choi ---------------- */
case 'delete':
    $id = isset($B['id']) ? (int) $B['id'] : 0;
    $r  = lv_row($id);
    if (!$r) lv_fail('Không tìm thấy đơn.', 404);
    if ((int) $r['user_id'] !== $ME['id'] && !lv_is_admin()) {
        lv_fail('Bạn chỉ xoá được đơn của mình.', 403);
    }
    if (!in_array($r['status'], array('canceled', 'rejected'), true)) {
        lv_fail('Chỉ xoá được đơn đã huỷ hoặc bị từ chối. Đơn này hãy huỷ trước đã.', 423);
    }
    // Phong khi con sot su kien tren lich (don huy/tu choi le ra da go roi)
    if ($r['cal_event_id'] !== '') { mg_delete_event($r['cal_event_id']); }

    lv_pdo()->prepare('DELETE FROM leave_requests WHERE id = ?')->execute(array($id));
    lv_out(array('ok' => true, 'message' => 'Đã xoá đơn khỏi danh sách.'));
    break;

/* ---------------- Admin duyet ---------------- */
case 'approve':
    lv_need_admin();
    $id   = isset($B['id']) ? (int) $B['id'] : 0;
    $note = isset($B['note']) ? trim($B['note']) : '';
    $r    = lv_row($id);
    if (!$r) lv_fail('Không tìm thấy đơn.', 404);
    if ($r['status'] === 'approved') lv_fail('Đơn này đã duyệt rồi.');
    if ($r['status'] === 'canceled') lv_fail('Đơn đã huỷ, không duyệt được.');

    $st = lv_pdo()->prepare(
        "UPDATE leave_requests SET status='approved', decided_by=?, decided_by_name=?, decided_at=?,
                decide_note=?, updated_at=? WHERE id=?"
    );
    $st->execute(array($ME['id'], $ME['name'], $now, $note, $now, $id));

    // Day len lich Outlook - loi o day khong lam hong viec duyet
    $cal = array('ok' => false, 'error' => 'Chưa bật kết nối Outlook.', 'id' => '', 'web_link' => '');
    if (mg_enabled()) {
        $cal = lv_push_calendar(lv_row($id));
        lv_save_cal_result($id, $cal);
    }

    lv_notify((int) $r['user_id'], 'leave_approved', 'Đơn nghỉ đã được duyệt',
        $ME['name'] . ' đã duyệt đơn nghỉ ' . lv_range_text($r) . ' của bạn.', '/leave.html?id=' . $id);

    lv_out(array(
        'ok'       => true,
        'message'  => $cal['ok']
            ? 'Đã duyệt và thêm vào lịch Outlook.'
            : 'Đã duyệt. Chưa thêm được vào lịch: ' . $cal['error'],
        'calendar' => $cal['ok'],
        'cal_error'=> $cal['ok'] ? '' : $cal['error'],
        'row'      => lv_shape(lv_row($id)),
    ));
    break;

/* ---------------- Admin tu choi ---------------- */
case 'reject':
    lv_need_admin();
    $id   = isset($B['id']) ? (int) $B['id'] : 0;
    $note = isset($B['note']) ? trim($B['note']) : '';
    $r    = lv_row($id);
    if (!$r) lv_fail('Không tìm thấy đơn.', 404);
    if ($note === '') lv_fail('Vui lòng nhập lý do từ chối.');

    $calNote = '';
    if ($r['cal_event_id'] !== '') {
        $del = mg_delete_event($r['cal_event_id']);
        if (!$del['ok']) $calNote = ' (chưa xoá được sự kiện trên lịch: ' . $del['error'] . ')';
    }

    $st = lv_pdo()->prepare(
        "UPDATE leave_requests SET status='rejected', decided_by=?, decided_by_name=?, decided_at=?,
                decide_note=?, cal_event_id='', cal_status='', cal_link='', updated_at=? WHERE id=?"
    );
    $st->execute(array($ME['id'], $ME['name'], $now, $note, $now, $id));

    lv_notify((int) $r['user_id'], 'leave_rejected', 'Đơn nghỉ bị từ chối',
        $ME['name'] . ' đã từ chối đơn nghỉ ' . lv_range_text($r) . '. Lý do: ' . $note, '/leave.html?id=' . $id);

    lv_out(array('ok' => true, 'message' => 'Đã từ chối đơn.' . $calNote, 'row' => lv_shape(lv_row($id))));
    break;

/* ---------------- Admin day lai len lich ---------------- */
case 'cal-retry':
    lv_need_admin();
    $id = isset($B['id']) ? (int) $B['id'] : 0;
    $r  = lv_row($id);
    if (!$r) lv_fail('Không tìm thấy đơn.', 404);
    if ($r['status'] !== 'approved') lv_fail('Chỉ đơn đã duyệt mới đẩy lên lịch được.');
    if (!mg_enabled()) lv_fail('Chưa cấu hình Microsoft Graph (api/msgraph-config.php).');

    if ($r['cal_event_id'] !== '') mg_delete_event($r['cal_event_id']);
    $cal = lv_push_calendar($r);
    lv_save_cal_result($id, $cal);

    lv_out(array(
        'ok'      => $cal['ok'],
        'message' => $cal['ok'] ? 'Đã thêm vào lịch Outlook.' : $cal['error'],
        'row'     => lv_shape(lv_row($id)),
    ));
    break;

/* ---------------- Admin kiem tra ket noi Outlook ---------------- */
case 'cal-test':
    lv_need_admin();
    $t = mg_test();
    lv_out(array(
        'ok'      => $t['ok'],
        'mailbox' => $t['mailbox'],
        'message' => $t['ok']
            ? 'Kết nối tốt. Đang trỏ tới lịch của ' . $t['mailbox'] . '.'
            : $t['error'],
    ));
    break;

/* ---------------- Quy phep: xem ---------------- */
case 'quota-list':
    lv_need_admin();
    $year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
    $rows = array();
    $st   = lv_pdo()->query('SELECT id, username, display_name, role, active FROM app_users ORDER BY display_name, username');
    foreach ($st as $u) {
        if (isset($u['active']) && (int) $u['active'] === 0) continue;
        $uid = (int) $u['id'];
        $s   = lv_summary($uid, $year);
        $rows[] = array(
            'user_id'   => $uid,
            'name'      => trim($u['display_name'] !== '' ? $u['display_name'] : $u['username']),
            'role'      => $u['role'],
            'total'     => $s['total'],
            'used'      => $s['used'],
            'pending'   => $s['pending'],
            'remaining' => $s['remaining'],
        );
    }
    lv_out(array('ok' => true, 'year' => $year, 'rows' => $rows));
    break;

/* ---------------- Quy phep: chinh ---------------- */
case 'quota-set':
    lv_need_admin();
    $uid   = isset($B['user_id']) ? (int) $B['user_id'] : 0;
    $year  = isset($B['year'])    ? (int) $B['year']    : (int) date('Y');
    $total = isset($B['total'])   ? (float) $B['total'] : 14.0;
    $note  = isset($B['note'])    ? trim($B['note'])    : '';

    if ($uid <= 0) lv_fail('Thiếu nhân viên.');
    if ($year < 2000 || $year > 2100) lv_fail('Năm không hợp lệ.');
    if ($total < 0 || $total > 365) lv_fail('Số ngày phép không hợp lệ.');
    $total = round($total * 2) / 2; // lam tron ve boi so 0.5

    $st = lv_pdo()->prepare(
        'INSERT INTO leave_quota (user_id, year, total, note, updated_at) VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE total = VALUES(total), note = VALUES(note), updated_at = VALUES(updated_at)'
    );
    $st->execute(array($uid, $year, $total, $note, $now));

    lv_out(array('ok' => true, 'summary' => lv_summary($uid, $year)));
    break;

/* ---------------- Lich chung: ai nghi ngay nao ---------------- */
case 'calendar':
    $from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-01');
    $to   = isset($_GET['to'])   ? $_GET['to']   : date('Y-m-t');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        lv_fail('Khoảng ngày không hợp lệ.');
    }
    $st = lv_pdo()->prepare(
        "SELECT * FROM leave_requests
          WHERE status = 'approved' AND end_date >= ? AND start_date <= ?
          ORDER BY start_date"
    );
    $st->execute(array($from, $to));

    $out = array();
    foreach ($st as $r) {
        foreach (lv_expand($r['start_date'], $r['start_part'], $r['end_date'], $r['end_part'], (int) $r['skip_weekend'] === 1) as $d) {
            if ($d['date'] < $from || $d['date'] > $to) continue;
            $out[] = array(
                'date'    => $d['date'],
                'part'    => $d['part'],
                'user_id' => (int) $r['user_id'],
                'name'    => $r['user_name'],
                'type'    => $r['leave_type'],
                'id'      => (int) $r['id'],
            );
        }
    }
    lv_out(array('ok' => true, 'from' => $from, 'to' => $to, 'items' => $out));
    break;

default:
    lv_fail('Action không hợp lệ.', 404);
}
