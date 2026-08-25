<?php
/**
 * APSA - API cho trang Zalo (kết nối tài khoản + quản trị bot).
 *
 *   GET  ?action=status        -> tình trạng bot + tình trạng kết nối của tôi
 *   POST ?action=link-code     -> sinh mã 6 số để nhắn cho bot
 *   POST ?action=unlink        -> ngắt kết nối của tôi
 *   POST ?action=test          -> bot gửi thử 1 tin cho tôi
 *   GET  ?action=users         -> (Admin) ai đã kết nối
 *   POST ?action=set-webhook   -> (Admin) đăng ký webhook với Zalo
 *   GET  ?action=cron-due&key= -> nhắc việc đến hạn / quá hạn (cron gọi, không cần đăng nhập)
 *
 * v1.2.7
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/zalo.php';

$ACTION = isset($_GET['action']) ? (string) $_GET['action'] : '';

function za_out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function za_fail($m, $c = 400) { za_out(array('ok' => false, 'error' => $m), $c); }

function za_pdo()
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
        );
    } catch (PDOException $e) { za_fail('Không kết nối được cơ sở dữ liệu.', 500); }
    return $pdo;
}

/* ================================================================== */
/*  Cron: nhắc việc đến hạn / quá hạn  (không cần đăng nhập)          */
/* ================================================================== */

if ($ACTION === 'cron-due') {
    $cfg = zb_cfg();
    $key = trim((string) $cfg['cron_key']);
    $got = isset($_GET['key']) ? (string) $_GET['key'] : '';
    if ($key === '' || !hash_equals($key, $got)) za_fail('Sai khoá.', 403);
    if (!zb_enabled()) za_out(array('ok' => true, 'sent' => 0, 'message' => 'Zalo đang tắt.'));

    $pdo = za_pdo();
    zb_migrate($pdo);
    $today = date('Y-m-d');
    $sent = 0; $skipped = 0;

    try {
        $rows = $pdo->query(
            "SELECT a.user_id, a.task, a.due_date, q.code, q.title
               FROM `quotation_assignees` a
               JOIN `quotations` q ON q.id = a.quotation_id
               JOIN `app_users`  u ON u.id = a.user_id
              WHERE a.status <> 'done'
                AND q.deleted_at IS NULL
                AND q.closed_at IS NULL
                AND a.due_date IS NOT NULL
                AND a.due_date <> '0000-00-00'
                AND a.due_date <= '" . $today . "'
                AND u.active = 1
                AND u.zalo_chat_id IS NOT NULL
              ORDER BY a.user_id, a.due_date")->fetchAll();
    } catch (Exception $e) { $rows = array(); }

    $byUser = array();
    foreach ($rows as $r) $byUser[(int) $r['user_id']][] = $r;

    foreach ($byUser as $uid => $list) {
        $late = 0;
        $lines = array();
        foreach ($list as $r) {
            $d = (string) $r['due_date'];
            $tag = ($d < $today) ? 'QUÁ HẠN' : 'hôm nay';
            if ($d < $today) $late++;
            $p = explode('-', $d);
            $lines[] = '• ' . trim((string) $r['task'] !== '' ? $r['task'] : 'Việc được giao')
                     . ' — ' . $r['code'] . ' (' . $tag . ' ' . $p[2] . '/' . $p[1] . ')';
        }
        $title = 'Bạn có ' . count($list) . ' việc cần xử lý'
               . ($late > 0 ? ' (' . $late . ' việc đã quá hạn)' : '');
        if (zb_push($pdo, $uid, 'task_due', $title, implode("\n", $lines), './index.html')) $sent++;
        else $skipped++;
    }

    za_out(array('ok' => true, 'sent' => $sent, 'skipped' => $skipped, 'users' => count($byUser)));
}

/* ================================================================== */
/*  Các action còn lại đều cần đăng nhập                              */
/* ================================================================== */

require_once __DIR__ . '/session-boot.php';

function za_me()
{
    static $me = null;
    if ($me !== null) return $me;
    $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if (!$uid) za_fail('Chưa đăng nhập.', 401);
    $st = za_pdo()->prepare('SELECT id, username, display_name, role FROM `app_users` WHERE id = ? AND active = 1');
    $st->execute(array($uid));
    $r = $st->fetch();
    if (!$r) za_fail('Chưa đăng nhập.', 401);
    $r['id'] = (int) $r['id'];
    $me = $r;
    return $me;
}
function za_is_admin() { $m = za_me(); return strtolower((string) $m['role']) === 'admin'; }
function za_admin_only() { if (!za_is_admin()) za_fail('Chỉ Admin dùng được chức năng này.', 403); }

$pdo = za_pdo();
zb_migrate($pdo);
$ME = za_me();

switch ($ACTION) {

case 'status': {
    $st = $pdo->prepare('SELECT zalo_chat_id, zalo_name, zalo_linked_at FROM `app_users` WHERE id = ?');
    $st->execute(array($ME['id']));
    $r = $st->fetch();

    $out = array(
        'ok'         => true,
        'is_admin'   => za_is_admin(),
        'configured' => zb_configured(),
        'enabled'    => zb_enabled(),
        'me'         => array(
            'linked'    => !empty($r['zalo_chat_id']),
            'zalo_name' => isset($r['zalo_name']) ? (string) $r['zalo_name'] : '',
            'linked_at' => isset($r['zalo_linked_at']) ? (string) $r['zalo_linked_at'] : '',
        ),
        'kinds'      => zb_kinds(),
    );

    if (za_is_admin()) {
        $cfg = zb_cfg();
        $out['webhook_url'] = rtrim((string) $cfg['app_url'], '/') . '/api/zalo-hook.php';
        $out['has_secret']  = trim((string) $cfg['secret_token']) !== '';
        $out['has_cronkey'] = trim((string) $cfg['cron_key']) !== '';
        if (zb_configured()) {
            $me = zb_api('getMe', array(), 6);
            $out['bot'] = $me['ok'] ? $me['result'] : null;
            if (!$me['ok']) $out['bot_error'] = $me['error'];
        }
    }
    za_out($out);
}

case 'link-code': {
    if (!zb_configured()) za_fail('Chưa cấu hình Bot Token trên máy chủ.', 503);
    $c = zb_make_code($pdo, $ME['id']);
    za_out(array('ok' => true, 'code' => $c['code'], 'expires_at' => $c['expires_at']));
}

case 'unlink': {
    $pdo->prepare('UPDATE `app_users` SET zalo_chat_id = NULL, zalo_name = NULL, zalo_linked_at = NULL WHERE id = ?')
        ->execute(array($ME['id']));
    za_out(array('ok' => true, 'message' => 'Đã ngắt kết nối Zalo.'));
}

case 'test': {
    if (!zb_enabled()) za_fail('Zalo đang tắt hoặc chưa cấu hình.', 503);
    $chat = zb_chat_id($pdo, $ME['id']);
    if ($chat === '') za_fail('Bạn chưa kết nối Zalo.', 409);
    $r = zb_send($chat, '🔔 Tin thử từ APSA Tools' . "\n"
        . 'Nếu bạn đọc được tin này thì kết nối đang hoạt động tốt.' . "\n\n"
        . zb_abs('./index.html'));
    if (empty($r['ok'])) za_fail('Gửi thất bại: ' . (isset($r['error']) ? $r['error'] : 'không rõ'), 502);
    za_out(array('ok' => true, 'message' => 'Đã gửi tin thử, kiểm tra Zalo của bạn.'));
}

case 'users': {
    za_admin_only();
    $rows = $pdo->query("SELECT id, display_name, username, role, zalo_name, zalo_linked_at,
                                (zalo_chat_id IS NOT NULL) AS linked
                           FROM `app_users` WHERE active = 1
                          ORDER BY (zalo_chat_id IS NULL) ASC, display_name ASC")->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['linked'] = (int) $r['linked'] === 1; }
    unset($r);
    za_out(array('ok' => true, 'rows' => $rows));
}

case 'set-webhook': {
    za_admin_only();
    $cfg = zb_cfg();
    if (!zb_configured()) za_fail('Chưa cấu hình Bot Token.', 503);
    $secret = trim((string) $cfg['secret_token']);
    if (strlen($secret) < 8) za_fail('secret_token phải dài ít nhất 8 ký tự.', 400);

    $url = rtrim((string) $cfg['app_url'], '/') . '/api/zalo-hook.php';
    $r = zb_api('setWebhook', array('url' => $url, 'secret_token' => $secret), 15);
    if (empty($r['ok'])) za_fail('Zalo từ chối: ' . (isset($r['error']) ? $r['error'] : 'không rõ'), 502);
    za_out(array('ok' => true, 'result' => $r['result'], 'url' => $url));
}

default:
    za_fail('Hành động không hợp lệ.', 404);
}
