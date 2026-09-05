<?php
/* ============================================================
   APSA - Better Me  ·  Board phat trien ky nang theo thang
   ============================================================ */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/session-boot.php';
require_once __DIR__ . '/perm.php';
require_once __DIR__ . '/zalo.php';

define('BM_MOD', 102);

/* ---------------- Ha tang chung ---------------- */
function bm_out($d, $code = 200)
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
function bm_ok($d = array()) { bm_out(array_merge(array('ok' => true), $d)); }
function bm_fail($m, $c = 400) { bm_out(array('ok' => false, 'error' => $m), $c); }

function bm_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return array();
    $j = json_decode($raw, true);
    return is_array($j) ? $j : array();
}
function bm_s($v, $n) { return mb_substr(trim((string) $v), 0, $n, 'UTF-8'); }
function bm_ym($v)
{
    $v = trim((string) $v);
    if (preg_match('/^(\d{4})-(\d{2})$/', $v, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) return $v;
    return date('Y-m');
}

function bm_pdo()
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
        bm_fail('Khong ket noi duoc co so du lieu.', 500);
    }
    return $pdo;
}

function bm_me()
{
    static $me = null;
    if ($me !== null) return $me;
    $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if ($uid <= 0) bm_fail('Chưa đăng nhập.', 401);
    $st = bm_pdo()->prepare("SELECT id, username, display_name, role, email
                               FROM `app_users` WHERE id = ? AND active = 1");
    $st->execute(array($uid));
    $r = $st->fetch();
    if (!$r) bm_fail('Chưa đăng nhập.', 401);
    $r['id'] = (int) $r['id'];
    if (trim((string) $r['display_name']) === '') $r['display_name'] = (string) $r['username'];
    $me = $r;
    return $me;
}
function bm_admin()
{
    $m = bm_me();
    return isset($m['role']) && strtolower((string) $m['role']) === 'admin';
}

/* ---------------- Bang du lieu ---------------- */
function bm_migrate(PDO $pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;
    $eng = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `bm_topics` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `owner_id` INT UNSIGNED NOT NULL,
            `owner_name` VARCHAR(120) NOT NULL DEFAULT '',
            `ym` CHAR(7) NOT NULL,
            `title` VARCHAR(300) NOT NULL DEFAULT '',
            `how` TEXT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'open',
            `resolved_by` INT UNSIGNED NOT NULL DEFAULT 0,
            `resolved_name` VARCHAR(120) NOT NULL DEFAULT '',
            `resolved_at` DATETIME NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `idx_owner` (`owner_id`),
            KEY `idx_ym` (`ym`)
        )" . $eng);
        $pdo->exec("CREATE TABLE IF NOT EXISTS `bm_steps` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `topic_id` INT UNSIGNED NOT NULL,
            `content` VARCHAR(500) NOT NULL DEFAULT '',
            `done` TINYINT(1) NOT NULL DEFAULT 0,
            `sort_order` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_topic` (`topic_id`)
        )" . $eng);
        $pdo->exec("CREATE TABLE IF NOT EXISTS `bm_members` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `topic_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `user_name` VARCHAR(120) NOT NULL DEFAULT '',
            `role` VARCHAR(20) NOT NULL DEFAULT 'peer',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_tu` (`topic_id`,`user_id`),
            KEY `idx_user` (`user_id`)
        )" . $eng);
        $pdo->exec("CREATE TABLE IF NOT EXISTS `bm_comments` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `topic_id` INT UNSIGNED NOT NULL,
            `parent_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `user_id` INT UNSIGNED NOT NULL,
            `user_name` VARCHAR(120) NOT NULL DEFAULT '',
            `body` TEXT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `idx_topic` (`topic_id`)
        )" . $eng);
    } catch (PDOException $e) {
        bm_fail('Khong tao duoc bang Better Me: ' . $e->getMessage(), 500);
    }
}

/* ---------------- Truy cap ---------------- */
function bm_topic(PDO $pdo, $id)
{
    $st = $pdo->prepare("SELECT * FROM `bm_topics` WHERE id = ? AND deleted_at IS NULL");
    $st->execute(array((int) $id));
    $t = $st->fetch();
    if (!$t) bm_fail('Không tìm thấy topic.', 404);
    $t['id'] = (int) $t['id'];
    $t['owner_id'] = (int) $t['owner_id'];
    return $t;
}
function bm_members(PDO $pdo, $tid)
{
    $st = $pdo->prepare("SELECT user_id, user_name, role FROM `bm_members`
                          WHERE topic_id = ? ORDER BY role ASC, id ASC");
    $st->execute(array((int) $tid));
    $out = array();
    foreach ($st->fetchAll() as $r) {
        $out[] = array(
            'user_id'   => (int) $r['user_id'],
            'user_name' => (string) $r['user_name'],
            'role'      => ((string) $r['role'] === 'mentor') ? 'mentor' : 'peer',
        );
    }
    return $out;
}
function bm_my_role(PDO $pdo, $t)
{
    $me = bm_me();
    if ((int) $t['owner_id'] === (int) $me['id']) return 'owner';
    $st = $pdo->prepare("SELECT role FROM `bm_members` WHERE topic_id = ? AND user_id = ?");
    $st->execute(array((int) $t['id'], (int) $me['id']));
    $r = $st->fetchColumn();
    if ($r === false || $r === null) return '';
    return ((string) $r === 'mentor') ? 'mentor' : 'peer';
}
function bm_can_see(PDO $pdo, $t)
{
    if (bm_admin()) return true;
    return bm_my_role($pdo, $t) !== '';
}
/** Sua noi dung (HOW / PROCESS / tieu de): chu topic + nguoi cung phat trien. Da resolve = khoa. */
function bm_can_edit(PDO $pdo, $t)
{
    if ((string) $t['status'] === 'resolved') return false;
    $r = bm_my_role($pdo, $t);
    if ($r === 'owner' || $r === 'peer') return true;
    return bm_admin();
}
function bm_gate_edit(PDO $pdo, $t)
{
    if (bm_can_edit($pdo, $t)) return;
    if ((string) $t['status'] === 'resolved') {
        bm_fail('Topic đã được Admin resolve nên đang khoá. Cần Admin mở lại mới sửa được.', 403);
    }
    bm_fail('Bạn không có quyền sửa topic này.', 403);
}

/* ---------------- Thong bao ---------------- */
function bm_notify($userId, $kind, $title, $body, $url)
{
    $userId = (int) $userId;
    if ($userId <= 0) return;
    $me = bm_me();
    if ($userId === (int) $me['id']) return;
    try {
        bm_pdo()->prepare("INSERT INTO `app_notifications` (user_id, kind, title, body, url, actor)
                                VALUES (?,?,?,?,?,?)")
            ->execute(array($userId, $kind, bm_s($title, 190), bm_s($body, 500),
                (string) $url, bm_s($me['display_name'], 120)));
    } catch (Exception $e) { /* thong bao hong thi thoi */ }
    if (function_exists('zb_push')) {
        try { zb_push(bm_pdo(), $userId, $kind, $title, $body, $url); } catch (Exception $e) { }
    }
}
function bm_url($tid) { return './betterme.html?t=' . (int) $tid; }

/** Danh sach nguoi can bao tin cua 1 topic (chu topic + thanh vien), tru nguoi dang thao tac */
function bm_audience(PDO $pdo, $t)
{
    $ids = array((int) $t['owner_id']);
    foreach (bm_members($pdo, $t['id']) as $m) $ids[] = (int) $m['user_id'];
    $me = (int) bm_me()['id'];
    $out = array();
    foreach (array_unique($ids) as $i) if ($i > 0 && $i !== $me) $out[] = $i;
    return $out;
}

/* ============================================================
   ROUTER
   ============================================================ */
$pdo = bm_pdo();
bm_migrate($pdo);
$ME = bm_me();
$IS_ADMIN = bm_admin();
if (function_exists('pm_can') && !pm_can(BM_MOD, 'view')) {
    bm_fail('Bạn không có quyền xem mục Better Me. Liên hệ Admin để được cấp quyền.', 403);
}
$action = isset($_GET['action']) ? trim((string) $_GET['action']) : '';
$B = bm_body();

switch ($action) {

/* --- Danh sach user + thong tin phien --- */
case 'meta': {
    $us = $pdo->query("SELECT id, display_name, username FROM `app_users`
                        WHERE active = 1 ORDER BY display_name ASC, username ASC")->fetchAll();
    $users = array();
    foreach ($us as $u) {
        $nm = trim((string) $u['display_name']);
        if ($nm === '') $nm = (string) $u['username'];
        $users[] = array('id' => (int) $u['id'], 'name' => $nm);
    }
    /* Board nao toi duoc xem */
    if ($IS_ADMIN) {
        $bs = $pdo->query("SELECT t.owner_id AS uid, t.owner_name AS nm, COUNT(*) AS n
                             FROM `bm_topics` t WHERE t.deleted_at IS NULL
                            GROUP BY t.owner_id, t.owner_name ORDER BY nm ASC")->fetchAll();
    } else {
        $st = $pdo->prepare("SELECT t.owner_id AS uid, t.owner_name AS nm, COUNT(*) AS n
                               FROM `bm_topics` t
                          LEFT JOIN `bm_members` m ON m.topic_id = t.id AND m.user_id = ?
                              WHERE t.deleted_at IS NULL AND (t.owner_id = ? OR m.user_id IS NOT NULL)
                           GROUP BY t.owner_id, t.owner_name ORDER BY nm ASC");
        $st->execute(array((int) $ME['id'], (int) $ME['id']));
        $bs = $st->fetchAll();
    }
    $boards = array();
    $hasMe = false;
    foreach ($bs as $b) {
        if ((int) $b['uid'] === (int) $ME['id']) $hasMe = true;
        $boards[] = array('user_id' => (int) $b['uid'], 'name' => (string) $b['nm'], 'topics' => (int) $b['n']);
    }
    if (!$hasMe) {
        array_unshift($boards, array('user_id' => (int) $ME['id'],
            'name' => (string) $ME['display_name'], 'topics' => 0));
    }
    bm_ok(array(
        'me'       => array('id' => (int) $ME['id'], 'name' => (string) $ME['display_name']),
        'is_admin' => $IS_ADMIN ? 1 : 0,
        'users'    => $users,
        'boards'   => $boards,
    ));
}

/* --- Board cua 1 user: tat ca topic, group theo thang --- */
case 'board': {
    $uid = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
    if ($uid <= 0) $uid = (int) $ME['id'];
    $mine = ($uid === (int) $ME['id']);
    if (!$mine && !$IS_ADMIN) {
        /* chi cho xem neu co it nhat 1 topic minh duoc moi */
        $ck = $pdo->prepare("SELECT COUNT(*) FROM `bm_topics` t
                               JOIN `bm_members` m ON m.topic_id = t.id
                              WHERE t.owner_id = ? AND m.user_id = ? AND t.deleted_at IS NULL");
        $ck->execute(array($uid, (int) $ME['id']));
        if ((int) $ck->fetchColumn() <= 0) bm_fail('Bạn không có quyền xem board này.', 403);
    }
    $st = $pdo->prepare("SELECT * FROM `bm_topics`
                          WHERE owner_id = ? AND deleted_at IS NULL
                       ORDER BY ym DESC, sort_order ASC, id ASC");
    $st->execute(array($uid));
    $rows = $st->fetchAll();

    $out = array();
    foreach ($rows as $r) {
        $tid = (int) $r['id'];
        if (!$mine && !$IS_ADMIN) {
            $vis = $pdo->prepare("SELECT COUNT(*) FROM `bm_members` WHERE topic_id = ? AND user_id = ?");
            $vis->execute(array($tid, (int) $ME['id']));
            if ((int) $vis->fetchColumn() <= 0) continue;
        }
        $ss = $pdo->prepare("SELECT id, content, done FROM `bm_steps`
                              WHERE topic_id = ? ORDER BY sort_order ASC, id ASC");
        $ss->execute(array($tid));
        $steps = array();
        $done = 0;
        foreach ($ss->fetchAll() as $s) {
            $d = (int) $s['done'];
            if ($d) $done++;
            $steps[] = array('id' => (int) $s['id'], 'content' => (string) $s['content'], 'done' => $d);
        }
        $cc = $pdo->prepare("SELECT COUNT(*) FROM `bm_comments` WHERE topic_id = ? AND deleted_at IS NULL");
        $cc->execute(array($tid));
        $t = array(
            'id'            => $tid,
            'owner_id'      => (int) $r['owner_id'],
            'owner_name'    => (string) $r['owner_name'],
            'ym'            => (string) $r['ym'],
            'title'         => (string) $r['title'],
            'how'           => (string) $r['how'],
            'status'        => (string) $r['status'],
            'resolved_name' => (string) $r['resolved_name'],
            'resolved_at'   => (string) $r['resolved_at'],
            'created_at'    => (string) $r['created_at'],
            'steps'         => $steps,
            'steps_done'    => $done,
            'members'       => bm_members($pdo, $tid),
            'comments'      => (int) $cc->fetchColumn(),
        );
        $t['my_role']  = bm_my_role($pdo, $r);
        $t['can_edit'] = bm_can_edit($pdo, $r) ? 1 : 0;
        $t['can_del']  = ((string) $r['status'] !== 'resolved'
                           && ((int) $r['owner_id'] === (int) $ME['id'] || $IS_ADMIN)) ? 1 : 0;
        $out[] = $t;
    }
    bm_ok(array('user_id' => $uid, 'topics' => $out, 'is_admin' => $IS_ADMIN ? 1 : 0));
}

/* --- Tao / sua topic --- */
case 'topic-save': {
    $id    = isset($B['id']) ? (int) $B['id'] : 0;
    $title = bm_s(isset($B['title']) ? $B['title'] : '', 300);
    $how   = mb_substr((string) (isset($B['how']) ? $B['how'] : ''), 0, 20000, 'UTF-8');
    if ($title === '') bm_fail('Nhập tên kỹ năng muốn phát triển.');

    if ($id > 0) {
        $t = bm_topic($pdo, $id);
        bm_gate_edit($pdo, $t);
        $ym = bm_ym(isset($B['ym']) ? $B['ym'] : $t['ym']);
        $pdo->prepare("UPDATE `bm_topics` SET title = ?, how = ?, ym = ? WHERE id = ?")
            ->execute(array($title, $how, $ym, $id));
        bm_ok(array('id' => $id, 'message' => 'Đã lưu topic.'));
    }

    if (!pm_can(BM_MOD, 'add')) bm_fail('Bạn không có quyền thêm topic.', 403);
    $ym = bm_ym(isset($B['ym']) ? $B['ym'] : '');
    $so = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM `bm_topics` WHERE owner_id = ? AND ym = ?");
    $so->execute(array((int) $ME['id'], $ym));
    $pdo->prepare("INSERT INTO `bm_topics` (owner_id, owner_name, ym, title, how, sort_order)
                   VALUES (?,?,?,?,?,?)")
        ->execute(array((int) $ME['id'], bm_s($ME['display_name'], 120), $ym, $title, $how,
            (int) $so->fetchColumn()));
    bm_ok(array('id' => (int) $pdo->lastInsertId(), 'message' => 'Đã tạo topic.'));
}

/* --- Xoa topic --- */
case 'topic-del': {
    $t = bm_topic($pdo, isset($B['id']) ? $B['id'] : 0);
    if ((string) $t['status'] === 'resolved') {
        bm_fail('Topic đã resolve nên đang khoá. Cần Admin mở lại mới xoá được.', 403);
    }
    if ((int) $t['owner_id'] !== (int) $ME['id'] && !$IS_ADMIN) {
        bm_fail('Chỉ chủ topic hoặc Admin mới xoá được.', 403);
    }
    $pdo->prepare("UPDATE `bm_topics` SET deleted_at = NOW() WHERE id = ?")->execute(array($t['id']));
    bm_ok(array('message' => 'Đã xoá topic.'));
}

/* --- Resolve / mo lai: chi Admin --- */
case 'topic-resolve': {
    if (!$IS_ADMIN) bm_fail('Chỉ Admin mới resolve được topic.', 403);
    $t  = bm_topic($pdo, isset($B['id']) ? $B['id'] : 0);
    $on = !empty($B['on']);
    if ($on) {
        $pdo->prepare("UPDATE `bm_topics` SET status = 'resolved', resolved_by = ?,
                           resolved_name = ?, resolved_at = NOW() WHERE id = ?")
            ->execute(array((int) $ME['id'], bm_s($ME['display_name'], 120), $t['id']));
        foreach (bm_audience($pdo, $t) as $uid) {
            bm_notify($uid, 'bm_resolved', 'Better Me · topic đã được duyệt hoàn thành',
                $ME['display_name'] . ' đã resolve topic “' . $t['title'] . '”', bm_url($t['id']));
        }
        bm_ok(array('message' => 'Đã resolve topic.', 'status' => 'resolved'));
    }
    $pdo->prepare("UPDATE `bm_topics` SET status = 'open', resolved_by = 0,
                       resolved_name = '', resolved_at = NULL WHERE id = ?")
        ->execute(array($t['id']));
    bm_ok(array('message' => 'Đã mở lại topic.', 'status' => 'open'));
}

/* --- PROCESS: luu ca danh sach buoc --- */
case 'steps-save': {
    $t = bm_topic($pdo, isset($B['topic_id']) ? $B['topic_id'] : 0);
    bm_gate_edit($pdo, $t);
    $list = (isset($B['steps']) && is_array($B['steps'])) ? $B['steps'] : array();

    $old = array();
    $os = $pdo->prepare("SELECT id FROM `bm_steps` WHERE topic_id = ?");
    $os->execute(array($t['id']));
    foreach ($os->fetchAll(PDO::FETCH_COLUMN) as $oid) $old[(int) $oid] = true;

    $upd = $pdo->prepare("UPDATE `bm_steps` SET content = ?, done = ?, sort_order = ?
                           WHERE id = ? AND topic_id = ?");
    $ins = $pdo->prepare("INSERT INTO `bm_steps` (topic_id, content, done, sort_order) VALUES (?,?,?,?)");
    $keep = array();
    $n = 0;
    foreach ($list as $s) {
        if (!is_array($s)) continue;
        $c = bm_s(isset($s['content']) ? $s['content'] : '', 500);
        if ($c === '') continue;
        $n++;
        $d   = empty($s['done']) ? 0 : 1;
        $sid = isset($s['id']) ? (int) $s['id'] : 0;
        if ($sid > 0 && isset($old[$sid])) {
            $upd->execute(array($c, $d, $n, $sid, $t['id']));
            $keep[] = $sid;
        } else {
            $ins->execute(array($t['id'], $c, $d, $n));
            $keep[] = (int) $pdo->lastInsertId();
        }
    }
    if (count($keep)) {
        $in = implode(',', array_map('intval', $keep));
        $pdo->prepare("DELETE FROM `bm_steps` WHERE topic_id = ? AND id NOT IN ($in)")
            ->execute(array($t['id']));
    } else {
        $pdo->prepare("DELETE FROM `bm_steps` WHERE topic_id = ?")->execute(array($t['id']));
    }
    bm_ok(array('message' => 'Đã lưu PROCESS.'));
}

/* --- Moi nguoi cung phat trien / mentor --- */
case 'members-save': {
    $t = bm_topic($pdo, isset($B['topic_id']) ? $B['topic_id'] : 0);
    if ((string) $t['status'] === 'resolved') bm_fail('Topic đã resolve nên đang khoá.', 403);
    if ((int) $t['owner_id'] !== (int) $ME['id'] && !$IS_ADMIN) {
        bm_fail('Chỉ chủ topic hoặc Admin mới mời được người khác.', 403);
    }
    $list = (isset($B['members']) && is_array($B['members'])) ? $B['members'] : array();

    $before = array();
    foreach (bm_members($pdo, $t['id']) as $m) $before[(int) $m['user_id']] = $m['role'];

    $names = array();
    foreach ($pdo->query("SELECT id, display_name, username FROM `app_users` WHERE active = 1")->fetchAll() as $u) {
        $nm = trim((string) $u['display_name']);
        if ($nm === '') $nm = (string) $u['username'];
        $names[(int) $u['id']] = $nm;
    }

    $want = array();
    foreach ($list as $m) {
        if (!is_array($m)) continue;
        $uid = isset($m['user_id']) ? (int) $m['user_id'] : 0;
        if ($uid <= 0 || $uid === (int) $t['owner_id'] || !isset($names[$uid])) continue;
        $want[$uid] = (isset($m['role']) && (string) $m['role'] === 'mentor') ? 'mentor' : 'peer';
    }

    $pdo->prepare("DELETE FROM `bm_members` WHERE topic_id = ?")->execute(array($t['id']));
    $ins = $pdo->prepare("INSERT INTO `bm_members` (topic_id, user_id, user_name, role) VALUES (?,?,?,?)");
    foreach ($want as $uid => $role) $ins->execute(array($t['id'], $uid, bm_s($names[$uid], 120), $role));

    foreach ($want as $uid => $role) {
        if (isset($before[$uid]) && $before[$uid] === $role) continue;
        $lb = ($role === 'mentor') ? 'mentor' : 'cùng phát triển';
        bm_notify($uid, 'bm_invite', 'Better Me · bạn được mời tham gia',
            $ME['display_name'] . ' mời bạn vào topic “' . $t['title'] . '” với vai trò ' . $lb,
            bm_url($t['id']));
    }
    bm_ok(array('message' => 'Đã cập nhật người tham gia.', 'members' => bm_members($pdo, $t['id'])));
}

/* --- Binh luan --- */
case 'comments': {
    $t = bm_topic($pdo, isset($_GET['topic_id']) ? (int) $_GET['topic_id'] : 0);
    if (!bm_can_see($pdo, $t)) bm_fail('Bạn không có quyền xem topic này.', 403);
    $st = $pdo->prepare("SELECT id, parent_id, user_id, user_name, body, created_at
                           FROM `bm_comments`
                          WHERE topic_id = ? AND deleted_at IS NULL
                       ORDER BY id ASC");
    $st->execute(array($t['id']));
    $out = array();
    foreach ($st->fetchAll() as $c) {
        $out[] = array(
            'id'         => (int) $c['id'],
            'parent_id'  => (int) $c['parent_id'],
            'user_id'    => (int) $c['user_id'],
            'user_name'  => (string) $c['user_name'],
            'body'       => (string) $c['body'],
            'created_at' => (string) $c['created_at'],
            'mine'       => ((int) $c['user_id'] === (int) $ME['id']) ? 1 : 0,
        );
    }
    bm_ok(array('rows' => $out));
}

case 'comment-add': {
    $t = bm_topic($pdo, isset($B['topic_id']) ? $B['topic_id'] : 0);
    if (!bm_can_see($pdo, $t)) bm_fail('Bạn không có quyền bình luận trong topic này.', 403);
    if ((string) $t['status'] === 'resolved') bm_fail('Topic đã resolve nên đang khoá.', 403);
    $body = mb_substr(trim((string) (isset($B['body']) ? $B['body'] : '')), 0, 5000, 'UTF-8');
    if ($body === '') bm_fail('Nhập nội dung bình luận.');
    $pid = isset($B['parent_id']) ? (int) $B['parent_id'] : 0;
    if ($pid > 0) {
        $ck = $pdo->prepare("SELECT COUNT(*) FROM `bm_comments` WHERE id = ? AND topic_id = ?");
        $ck->execute(array($pid, $t['id']));
        if ((int) $ck->fetchColumn() <= 0) $pid = 0;
    }
    $pdo->prepare("INSERT INTO `bm_comments` (topic_id, parent_id, user_id, user_name, body)
                   VALUES (?,?,?,?,?)")
        ->execute(array($t['id'], $pid, (int) $ME['id'], bm_s($ME['display_name'], 120), $body));
    $cid = (int) $pdo->lastInsertId();
    foreach (bm_audience($pdo, $t) as $uid) {
        bm_notify($uid, 'bm_comment', 'Better Me · có bình luận mới',
            $ME['display_name'] . ' vừa bình luận trong topic “' . $t['title'] . '”: '
            . mb_substr($body, 0, 160, 'UTF-8'), bm_url($t['id']));
    }
    bm_ok(array('id' => $cid, 'message' => 'Đã gửi bình luận.'));
}

case 'comment-del': {
    $id = isset($B['id']) ? (int) $B['id'] : 0;
    $st = $pdo->prepare("SELECT * FROM `bm_comments` WHERE id = ? AND deleted_at IS NULL");
    $st->execute(array($id));
    $c = $st->fetch();
    if (!$c) bm_fail('Không tìm thấy bình luận.', 404);
    if ((int) $c['user_id'] !== (int) $ME['id'] && !$IS_ADMIN) {
        bm_fail('Chỉ người viết hoặc Admin mới xoá được bình luận.', 403);
    }
    $pdo->prepare("UPDATE `bm_comments` SET deleted_at = NOW() WHERE id = ?")->execute(array($id));
    bm_ok(array('message' => 'Đã xoá bình luận.'));
}

default:
    bm_fail('Hành động không hợp lệ.', 404);
}
