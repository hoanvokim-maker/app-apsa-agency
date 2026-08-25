<?php
/**
 * APSA - API cho To-do list tren trang chu (dashboard)
 *
 *   GET  ?action=list           -> viec duoc giao + (admin) don nghi cho duyet + yeu cau mo lai
 *   POST ?action=done  {id}     -> danh dau 1 viec duoc giao la "Xong" + bao cho nguoi giao
 *
 * v1.2.5
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/session-boot.php';
require_once __DIR__ . '/zalo.php';

/* ------------------------------------------------------------------ */
/*  Ha tang chung                                                      */
/* ------------------------------------------------------------------ */

function td_out($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function td_fail($msg, $code = 400)
{
    td_out(array('ok' => false, 'error' => $msg), $code);
}

function td_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return array();
    $j = json_decode($raw, true);
    return is_array($j) ? $j : array();
}

function td_pdo()
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
        td_fail('Khong ket noi duoc co so du lieu.', 500);
    }
    return $pdo;
}

function td_me()
{
    static $me = null;
    if ($me !== null) return $me;
    $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if (!$uid) td_fail('Chưa đăng nhập.', 401);
    $st = td_pdo()->prepare("SELECT id, username, display_name, role, email
                               FROM `app_users` WHERE id = ? AND active = 1");
    $st->execute(array($uid));
    $r = $st->fetch();
    if (!$r) td_fail('Chưa đăng nhập.', 401);
    $r['id'] = (int) $r['id'];
    $me = $r;
    return $me;
}

function td_is_admin()
{
    $me = td_me();
    return isset($me['role']) && strtolower((string) $me['role']) === 'admin';
}

/** Bang co ton tai khong - de API khong vo neu module chua migrate. */
function td_has_table($name)
{
    static $cache = array();
    if (isset($cache[$name])) return $cache[$name];
    try {
        $st = td_pdo()->prepare('SHOW TABLES LIKE ?');
        $st->execute(array($name));
        $cache[$name] = (bool) $st->fetchColumn();
    } catch (Exception $e) {
        $cache[$name] = false;
    }
    return $cache[$name];
}

/** Ghi 1 thong bao vao app_notifications (toast se tu hien len). */
function td_notify($userId, $kind, $title, $body, $url, $actor)
{
    $userId = (int) $userId;
    if ($userId <= 0) return;
    try {
        td_pdo()->prepare("INSERT INTO `app_notifications` (user_id, kind, title, body, url, actor)
                                VALUES (?,?,?,?,?,?)")
            ->execute(array($userId, $kind, mb_substr((string) $title, 0, 190, 'UTF-8'),
                mb_substr((string) $body, 0, 500, 'UTF-8'), (string) $url, mb_substr((string) $actor, 0, 120, 'UTF-8')));
    } catch (Exception $e) { /* thong bao hong thi thoi */ }

    /* Day sang Zalo neu nguoi nhan da ket noi */
    if (function_exists('zb_push')) zb_push(td_pdo(), $userId, $kind, $title, $body, $url);
}

/** assigned_by luu ten hien thi (varchar) -> doi ra user id. */
function td_user_id_by_name($name)
{
    $name = trim((string) $name);
    if ($name === '') return 0;
    try {
        $st = td_pdo()->prepare("SELECT id FROM `app_users`
                                  WHERE (display_name = ? OR username = ?) AND active = 1
                                  ORDER BY (display_name = ?) DESC LIMIT 1");
        $st->execute(array($name, $name, $name));
        return (int) $st->fetchColumn();
    } catch (Exception $e) { return 0; }
}

$ASG_STATUS = array('todo' => 'Chưa làm', 'doing' => 'Đang làm', 'review' => 'Chờ duyệt', 'done' => 'Xong');

/* ------------------------------------------------------------------ */
/*  Dieu huong                                                         */
/* ------------------------------------------------------------------ */

$action = isset($_GET['action']) ? $_GET['action'] : '';
$B      = td_body();
$ME     = td_me();
$pdo    = td_pdo();

switch ($action) {

case 'list': {
    $out = array(
        'ok'       => true,
        'is_admin' => td_is_admin(),
        'tasks'    => array(),
        'leaves'   => array(),
        'reopens'  => array(),
    );

    /* --- Viec duoc giao trong bao gia --- */
    if (td_has_table('quotation_assignees')) {
        try {
            $st = $pdo->prepare(
                "SELECT a.id, a.quotation_id, a.task, a.due_date, a.status, a.position,
                        q.code, q.title, q.closed_at
                   FROM `quotation_assignees` a
                   JOIN `quotations` q ON q.id = a.quotation_id
                  WHERE a.user_id = ?
                    AND a.status <> 'done'
                    AND q.deleted_at IS NULL
                  ORDER BY (a.due_date IS NULL OR a.due_date = '0000-00-00') ASC,
                           a.due_date ASC, a.id ASC
                  LIMIT 60");
            $st->execute(array($ME['id']));
            $today = date('Y-m-d');
            foreach ($st->fetchAll() as $r) {
                if (!empty($r['closed_at'])) continue;          // du an da dong thi bo qua
                $due = (string) $r['due_date'];
                $out['tasks'][] = array(
                    'id'           => (int) $r['id'],
                    'quotation_id' => (int) $r['quotation_id'],
                    'code'         => (string) $r['code'],
                    'title'        => (string) $r['title'],
                    'task'         => (string) $r['task'],
                    'position'     => (string) $r['position'],
                    'due_date'     => ($due === '0000-00-00' ? '' : $due),
                    'status'       => (string) $r['status'],
                    'status_label' => isset($ASG_STATUS[$r['status']]) ? $ASG_STATUS[$r['status']] : $r['status'],
                    'late'         => ($due !== '' && $due !== '0000-00-00' && $due < $today),
                    'url'          => './quotation.html?q=' . rawurlencode((string) $r['code']) . '&tab=quote#giaoviec',
                );
            }
        } catch (Exception $e) { /* bo qua */ }
    }

    if ($out['is_admin']) {

        /* --- Don xin nghi cho duyet --- */
        if (td_has_table('leave_requests')) {
            try {
                $rows = $pdo->query(
                    "SELECT id, user_name, leave_type, start_date, end_date, days, reason
                       FROM `leave_requests`
                      WHERE status = 'pending'
                      ORDER BY id DESC LIMIT 30")->fetchAll();
                foreach ($rows as $r) {
                    $out['leaves'][] = array(
                        'id'         => (int) $r['id'],
                        'user_name'  => (string) $r['user_name'],
                        'start_date' => (string) $r['start_date'],
                        'end_date'   => (string) $r['end_date'],
                        'days'       => (float) $r['days'],
                        'reason'     => (string) $r['reason'],
                        'url'        => './leave.html#pending',
                    );
                }
            } catch (Exception $e) { /* bo qua */ }
        }

        /* --- Yeu cau mo lai du an da dong --- */
        if (td_has_table('quotation_reopen_reqs')) {
            try {
                $rows = $pdo->query(
                    "SELECT r.id, r.quotation_id, r.user_name, r.reason, r.created_at,
                            q.code, q.title
                       FROM `quotation_reopen_reqs` r
                       JOIN `quotations` q ON q.id = r.quotation_id
                      WHERE r.state = 'pending'
                      ORDER BY r.id DESC LIMIT 30")->fetchAll();
                foreach ($rows as $r) {
                    $out['reopens'][] = array(
                        'id'         => (int) $r['id'],
                        'code'       => (string) $r['code'],
                        'title'      => (string) $r['title'],
                        'user_name'  => (string) $r['user_name'],
                        'reason'     => (string) $r['reason'],
                        'created_at' => (string) $r['created_at'],
                        'url'        => './quotation.html?q=' . rawurlencode((string) $r['code']),
                    );
                }
            } catch (Exception $e) { /* bo qua */ }
        }
    }

    $out['total'] = count($out['tasks']) + count($out['leaves']) + count($out['reopens']);
    td_out($out);
    break;
}

case 'done': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') td_fail('Sai phương thức.', 405);
    if (!td_has_table('quotation_assignees')) td_fail('Không tìm thấy phân công.', 404);

    $id = isset($B['id']) ? (int) $B['id'] : 0;
    if (!$id) td_fail('Thiếu id công việc.');

    $st = $pdo->prepare(
        "SELECT a.id, a.user_id, a.task, a.status, a.assigned_by, a.quotation_id,
                q.code, q.title
           FROM `quotation_assignees` a
           JOIN `quotations` q ON q.id = a.quotation_id
          WHERE a.id = ?");
    $st->execute(array($id));
    $r = $st->fetch();
    if (!$r) td_fail('Không tìm thấy công việc.', 404);

    if ((int) $r['user_id'] !== $ME['id'] && !td_is_admin()) {
        td_fail('Bạn chỉ đánh dấu xong công việc của mình.', 403);
    }
    if ($r['status'] === 'done') td_out(array('ok' => true, 'message' => 'Việc này đã xong rồi.'));

    $pdo->prepare("UPDATE `quotation_assignees` SET status = 'done' WHERE id = ?")->execute(array($id));

    $boss = td_user_id_by_name($r['assigned_by']);
    if ($boss > 0 && $boss !== $ME['id']) {
        $label = trim((string) $r['code'] . ' • ' . (string) $r['title']);
        $task  = trim((string) $r['task']);
        td_notify(
            $boss,
            'task_done',
            $ME['display_name'] . ' đã hoàn thành việc được giao',
            ($task !== '' ? $task . ' — ' : '') . $label,
            './quotation.html?q=' . rawurlencode((string) $r['code']) . '&tab=quote#giaoviec',
            $ME['display_name']
        );
    }

    td_out(array('ok' => true, 'message' => 'Đã đánh dấu xong.'));
    break;
}

default:
    td_fail('Hành động không hợp lệ.', 404);
}
