<?php
/**
 * zact-api.php - Thuc thi hanh dong 1 cham tu nut trong tin Zalo.
 *
 * Token trong link chi noi RO HANH DONG. Danh tinh nguoi bam lay tu
 * phien dang nhap, nen link bi chuyen tiep cho nguoi khac cung vo hai.
 *
 * action=info : xem truoc hanh dong (GET  ?t=...)
 * action=run  : thuc thi          (POST {t:...})
 */

require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/session-boot.php';
require_once __DIR__ . '/zalo.php';
require_once __DIR__ . '/zact.php';

/* ------------------------------------------------------------------ */
/*  Ha tang chung                                                      */
/* ------------------------------------------------------------------ */

function zx_out($d, $c = 200)
{
    http_response_code($c);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
function zx_fail($m, $c = 400) { zx_out(array('ok' => false, 'error' => $m), $c); }

function zx_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return array();
    $j = json_decode($raw, true);
    return is_array($j) ? $j : array();
}

function zx_pdo()
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
        );
    } catch (PDOException $e) { zx_fail('Khong ket noi duoc co so du lieu.', 500); }
    return $pdo;
}

function zx_me()
{
    static $me = null;
    if ($me !== null) return $me;
    $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if (!$uid) zx_fail('Chưa đăng nhập.', 401);
    $st = zx_pdo()->prepare("SELECT id, username, display_name, role FROM `app_users` WHERE id = ? AND active = 1");
    $st->execute(array($uid));
    $r = $st->fetch();
    if (!$r) zx_fail('Chưa đăng nhập.', 401);
    $r['id'] = (int) $r['id'];
    $me = $r;
    return $me;
}

function zx_is_admin()
{
    $m = zx_me();
    return isset($m['role']) && strtolower((string) $m['role']) === 'admin';
}

function zx_ip()
{
    $k = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    return mb_substr($k, 0, 45);
}

function zx_dmy($d)
{
    $d = trim((string) $d);
    if ($d === '' || strpos($d, '0000') === 0) return '';
    $p = explode('-', substr($d, 0, 10));
    return count($p) === 3 ? ($p[2] . '/' . $p[1] . '/' . $p[0]) : $d;
}

function zx_money($n)
{
    return number_format((float) $n, 0, ',', '.') . ' d';
}

/** Ghi 1 thong bao trong app + day sang Zalo (giong td_notify). */
function zx_notify($userId, $kind, $title, $body, $url, $actor)
{
    $userId = (int) $userId;
    if ($userId <= 0) return;
    try {
        zx_pdo()->prepare("INSERT INTO `app_notifications` (user_id, kind, title, body, url, actor)
                           VALUES (?,?,?,?,?,?)")
            ->execute(array($userId, $kind, mb_substr((string) $title, 0, 190, 'UTF-8'),
                            mb_substr((string) $body, 0, 500, 'UTF-8'), (string) $url,
                            mb_substr((string) $actor, 0, 120, 'UTF-8')));
    } catch (Exception $e) { /* thong bao hong thi thoi */ }
    if (function_exists('zb_push')) zb_push(zx_pdo(), $userId, $kind, $title, $body, $url);
}

/** assigned_by luu ten hien thi -> doi ra user id. */
function zx_uid_by_name($name)
{
    $name = trim((string) $name);
    if ($name === '') return 0;
    try {
        $st = zx_pdo()->prepare("SELECT id FROM `app_users`
                                  WHERE (display_name = ? OR username = ?) AND active = 1
                                  ORDER BY (display_name = ?) DESC, id ASC LIMIT 1");
        $st->execute(array($name, $name, $name));
        return (int) $st->fetchColumn();
    } catch (Exception $e) { return 0; }
}

/* ------------------------------------------------------------------ */
/*  Doc token + mo ta hanh dong                                        */
/* ------------------------------------------------------------------ */

function zx_load($tok)
{
    $tok = preg_replace('/[^0-9a-f]/', '', strtolower((string) $tok));
    if ($tok === '' || strlen($tok) > 24) return null;
    za_migrate(zx_pdo());
    $st = zx_pdo()->prepare("SELECT * FROM `zalo_actions` WHERE token = ? LIMIT 1");
    $st->execute(array($tok));
    $r = $st->fetch();
    return $r ? $r : null;
}

/**
 * Tra ve mang mo ta, hoac null neu doi tuong khong con.
 * Khoa 'state': ok | done | gone
 */
function zx_describe(array $a)
{
    $pdo  = zx_pdo();
    $kind = (string) $a['kind'];
    $id   = (int) $a['target_id'];

    $o = array(
        'kind'    => $kind,
        'label'   => (string) $a['label'],
        'title'   => '',
        'lines'   => array(),
        'confirm' => 'Xác nhận',
        'state'   => 'ok',
        'url'     => za_abs((string) $a['url']),
    );

    if ($kind === 'task_done' || $kind === 'snooze') {
        $st = $pdo->prepare(
            "SELECT a.id, a.task, a.status, a.due_date, a.user_id, a.assigned_by, a.quotation_id,
                    q.code, q.title
               FROM `quotation_assignees` a
               JOIN `quotations` q ON q.id = a.quotation_id
              WHERE a.id = ?");
        $st->execute(array($id));
        $r = $st->fetch();
        if (!$r) return null;

        $o['title'] = trim((string) $r['task']) !== '' ? (string) $r['task'] : 'Việc được giao';
        $o['lines'][] = 'Dự án: ' . $r['code'] . ' - ' . $r['title'];
        $dd = zx_dmy($r['due_date']);
        if ($dd !== '') $o['lines'][] = 'Hạn: ' . $dd;
        if ($o['url'] === '') {
            $o['url'] = za_abs('./quotation.html?q=' . rawurlencode((string) $r['code']) . '&tab=quote#giaoviec');
        }
        if ($kind === 'task_done') {
            $o['confirm'] = 'Đánh dấu đã hoàn thành';
            if ((string) $r['status'] === 'done') { $o['state'] = 'done'; $o['done_msg'] = 'Việc này đã xong rồi.'; }
        } else {
            $d = max(1, (int) $a['extra']);
            $o['confirm'] = 'Hoãn nhắc ' . $d . ' ngày';
            $o['lines'][] = 'Hệ thống sẽ ngừng nhắc việc này trong ' . $d . ' ngày.';
        }
        $o['row'] = $r;
        return $o;
    }

    if ($kind === 'exp_paid') {
        $st = $pdo->prepare(
            "SELECT e.id, e.name, e.qty, e.price, e.vat_percent, e.paid, e.payee_name, e.quotation_id,
                    q.code, q.title
               FROM `quotation_expenses` e
               JOIN `quotations` q ON q.id = e.quotation_id
              WHERE e.id = ?");
        $st->execute(array($id));
        $r = $st->fetch();
        if (!$r) return null;

        $amt = (float) $r['qty'] * (float) $r['price'];
        $amt = $amt + $amt * ((float) $r['vat_percent']) / 100;

        $o['title'] = trim((string) $r['name']) !== '' ? (string) $r['name'] : 'Khoản chi';
        $o['lines'][] = 'Dự án: ' . $r['code'] . ' - ' . $r['title'];
        if (trim((string) $r['payee_name']) !== '') $o['lines'][] = 'Người nhận: ' . $r['payee_name'];
        $o['lines'][] = 'Số tiền: ' . zx_money(round($amt));
        $o['confirm'] = 'Xác nhận đã thanh toán';
        if ($o['url'] === '') $o['url'] = za_abs('./chi-phi.html');
        if ((int) $r['paid'] === 1) { $o['state'] = 'done'; $o['done_msg'] = 'Khoản chi này đã được đánh dấu đã trả.'; }
        $o['row'] = $r;
        return $o;
    }

    return null;
}

/** Chuoi rong = duoc phep. */
function zx_deny(array $a, array $d)
{
    if (zx_is_admin()) return '';
    $me  = zx_me();
    $own = (int) $a['user_id'];
    if ($own > 0 && $own !== (int) $me['id']) {
        return 'Nút này được gửi cho người khác, bạn không dùng được.';
    }
    if (($a['kind'] === 'task_done' || $a['kind'] === 'snooze') && isset($d['row'])) {
        if ((int) $d['row']['user_id'] !== (int) $me['id']) {
            return 'Bạn chỉ đánh dấu xong công việc của mình.';
        }
    }
    return '';
}

/* ------------------------------------------------------------------ */
/*  Dieu huong                                                         */
/* ------------------------------------------------------------------ */

$ACT = isset($_GET['action']) ? (string) $_GET['action'] : '';
$B   = zx_body();
$TOK = isset($_GET['t']) ? (string) $_GET['t'] : (isset($B['t']) ? (string) $B['t'] : '');

switch ($ACT) {

case 'info': {
    zx_me();
    $a = zx_load($TOK);
    if (!$a) zx_fail('Link không hợp lệ.', 404);

    if (!empty($a['used_at'])) {
        zx_out(array('ok' => true, 'data' => array(
            'state' => 'used', 'kind' => $a['kind'], 'label' => $a['label'],
            'used_at' => $a['used_at'], 'used_by' => $a['used_by'],
            'url' => za_abs((string) $a['url']),
            'message' => 'Nút này đã được bấm rồi'
                . (!empty($a['used_by']) ? ' (' . $a['used_by'] . ')' : '') . '.',
        )));
    }
    if (!empty($a['expires_at']) && strtotime($a['expires_at']) < time()) {
        zx_out(array('ok' => true, 'data' => array(
            'state' => 'expired', 'kind' => $a['kind'], 'label' => $a['label'],
            'url' => za_abs((string) $a['url']),
            'message' => 'Link đã hết hạn. Vào app để xử lý trực tiếp.',
        )));
    }

    $d = zx_describe($a);
    if (!$d) zx_fail('Không tìm thấy dữ liệu của hành động này.', 404);

    $deny = zx_deny($a, $d);
    if ($deny !== '') {
        zx_out(array('ok' => true, 'data' => array(
            'state' => 'denied', 'kind' => $a['kind'], 'label' => $a['label'],
            'url' => $d['url'], 'message' => $deny,
        )));
    }

    unset($d['row']);
    zx_out(array('ok' => true, 'data' => $d));
}

case 'run': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') zx_fail('Sai phương thức.', 405);
    $me   = zx_me();
    $pdo  = zx_pdo();
    $a    = zx_load($TOK);
    if (!$a) zx_fail('Link không hợp lệ.', 404);
    if (!empty($a['used_at'])) zx_fail('Nút này đã được bấm rồi.', 409);
    if (!empty($a['expires_at']) && strtotime($a['expires_at']) < time()) zx_fail('Link đã hết hạn.', 410);

    $d = zx_describe($a);
    if (!$d) zx_fail('Không tìm thấy dữ liệu của hành động này.', 404);

    $deny = zx_deny($a, $d);
    if ($deny !== '') zx_fail($deny, 403);

    $kind = (string) $a['kind'];
    $id   = (int) $a['target_id'];
    $msg  = '';
    $next = $d['url'];

    if ($kind === 'task_done') {
        $r = $d['row'];
        if ((string) $r['status'] === 'done') {
            $msg = 'Việc này đã xong từ trước.';
        } else {
            $pdo->prepare("UPDATE `quotation_assignees` SET status = 'done' WHERE id = ?")->execute(array($id));
            $msg = 'Đã đánh dấu hoàn thành.';
            $boss = zx_uid_by_name($r['assigned_by']);
            if ($boss > 0 && $boss !== (int) $me['id']) {
                $label = trim((string) $r['code'] . ' * ' . (string) $r['title']);
                $task  = trim((string) $r['task']);
                zx_notify(
                    $boss, 'task_done',
                    $me['display_name'] . ' đã hoàn thành việc được giao',
                    ($task !== '' ? $task . ' - ' : '') . $label,
                    './quotation.html?q=' . rawurlencode((string) $r['code']) . '&tab=quote#giaoviec',
                    $me['display_name']
                );
            }
        }
    } elseif ($kind === 'snooze') {
        $days = max(1, min(30, (int) $a['extra']));
        $pdo->prepare("UPDATE `quotation_assignees`
                          SET snooze_until = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?")
            ->execute(array($days, $id));
        $msg = 'Đã hoãn nhắc ' . $days . ' ngày.';
    } elseif ($kind === 'exp_paid') {
        $r = $d['row'];
        if ((int) $r['paid'] === 1) {
            $msg = 'Khoản chi này đã được đánh dấu đã trả từ trước.';
        } else {
            $pdo->prepare("UPDATE `quotation_expenses`
                              SET paid = 1, paid_at = NOW(), paid_by = ? WHERE id = ?")
                ->execute(array(mb_substr((string) $me['display_name'], 0, 120), $id));
            $msg = 'Đã đánh dấu đã thanh toán.';
        }
        $next = za_abs('./chi-phi.html');
    } else {
        zx_fail('Hành động không hợp lệ.', 400);
    }

    $pdo->prepare("UPDATE `zalo_actions`
                      SET used_at = NOW(), used_by = ?, used_ip = ? WHERE id = ? AND used_at IS NULL")
        ->execute(array(mb_substr((string) $me['display_name'], 0, 120), zx_ip(), (int) $a['id']));

    zx_out(array('ok' => true, 'data' => array(
        'message' => $msg,
        'next'    => $next,
        'kind'    => $kind,
        'upload'  => ($kind === 'exp_paid'),
    )));
}

default:
    zx_fail('Hành động không hợp lệ: ' . $ACT, 404);
}
