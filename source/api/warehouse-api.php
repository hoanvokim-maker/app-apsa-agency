<?php
/**
 * APSA1829 - Quan ly kho
 *  wh_devices : so tai san thiet bi
 *  wh_loans   : phieu muon / tra thiet bi
 *  wh_items   : hang ky gui (standee / tai lieu / in an)
 *  wh_moves   : phieu nhap / xuat cua hang ky gui  (ton = tong cong don)
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db-config.php';
if (session_status() !== PHP_SESSION_ACTIVE && is_file(__DIR__ . '/session-boot.php')) {
    require_once __DIR__ . '/session-boot.php';
}

function wh_out($d, $code = 200)
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function wh_ok($d = array())   { wh_out(array_merge(array('ok' => true), $d)); }
function wh_fail($m, $c = 400) { wh_out(array('ok' => false, 'error' => $m), $c); }

function wh_pdo()
{
    static $p = null;
    if ($p !== null) return $p;
    $p = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
    return $p;
}

function wh_me()
{
    static $me = false;
    if ($me !== false) return $me;
    $me = null;
    $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if ($uid > 0) {
        try {
            $st = wh_pdo()->prepare("SELECT id, username, display_name, role, position
                                       FROM `app_users` WHERE id = ? AND active = 1 LIMIT 1");
            $st->execute(array($uid));
            $r = $st->fetch();
            $me = $r ? $r : null;
        } catch (Exception $e) { $me = null; }
    }
    return $me;
}
function wh_uid()      { $m = wh_me(); return $m ? (int) $m['id'] : 0; }
function wh_uname()    { $m = wh_me(); if (!$m) return ''; $d = trim((string) $m['display_name']); return $d !== '' ? $d : (string) $m['username']; }
function wh_is_admin() { $m = wh_me(); return $m && strtolower((string) $m['role']) === 'admin'; }
function wh_login()    { if (!wh_me()) wh_fail('Chưa đăng nhập.', 401); }
function wh_admin()    { wh_login(); if (!wh_is_admin()) wh_fail('Chỉ Admin mới làm được thao tác này.', 403); }

function wh_s($v, $max = 255)
{
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $v));
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
}
function wh_date($v)
{
    $v = trim((string) $v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}
function wh_num($v) { return round((float) str_replace(array(',', ' '), '', (string) $v), 2); }

function wh_cats()
{
    return array(
        'camera' => 'Máy ảnh / Máy quay', 'lens' => 'Ống kính', 'light' => 'Ánh sáng',
        'audio' => 'Âm thanh', 'grip' => 'Chân / Gimbal / Rig', 'computer' => 'Máy tính / Màn hình',
        'storage' => 'Thẻ nhớ / Ổ cứng', 'power' => 'Pin / Sạc / Điện', 'network' => 'Mạng / Bộ đàm',
        'furniture' => 'Bàn ghế / Vật dụng', 'other' => 'Khác',
    );
}
function wh_conds() { return array('new' => 'Mới', 'good' => 'Tốt', 'fair' => 'Bình thường', 'broken' => 'Hỏng', 'lost' => 'Mất'); }
function wh_stats() { return array('in_stock' => 'Trong kho', 'maintenance' => 'Đang bảo trì', 'retired' => 'Đã thanh lý'); }
function wh_kinds() { return array('standee' => 'Standee', 'document' => 'Tài liệu', 'print' => 'Ấn phẩm in', 'other' => 'Khác'); }
function wh_pick($map, $v, $def) { $v = (string) $v; return isset($map[$v]) ? $v : $def; }

function wh_init()
{
    $pdo = wh_pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `wh_devices` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `code` VARCHAR(40) NOT NULL DEFAULT '',
        `name` VARCHAR(300) NOT NULL DEFAULT '',
        `category` VARCHAR(60) NOT NULL DEFAULT '',
        `brand` VARCHAR(120) NOT NULL DEFAULT '',
        `serial` VARCHAR(120) NOT NULL DEFAULT '',
        `qty` INT NOT NULL DEFAULT 1,
        `unit` VARCHAR(30) NOT NULL DEFAULT '',
        `cond` VARCHAR(20) NOT NULL DEFAULT 'good',
        `status` VARCHAR(20) NOT NULL DEFAULT 'in_stock',
        `location` VARCHAR(200) NOT NULL DEFAULT '',
        `buy_date` DATE NULL DEFAULT NULL,
        `buy_price` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `note` TEXT NULL,
        `photo` VARCHAR(200) NOT NULL DEFAULT '',
        `created_by` VARCHAR(120) NOT NULL DEFAULT '',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `deleted_at` DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (`id`), KEY `k_code` (`code`), KEY `k_cat` (`category`), KEY `k_del` (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `wh_loans` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `device_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `qty` INT NOT NULL DEFAULT 1,
        `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `user_name` VARCHAR(200) NOT NULL DEFAULT '',
        `quotation_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `purpose` VARCHAR(300) NOT NULL DEFAULT '',
        `out_date` DATE NULL DEFAULT NULL,
        `due_date` DATE NULL DEFAULT NULL,
        `back_date` DATE NULL DEFAULT NULL,
        `back_cond` VARCHAR(20) NOT NULL DEFAULT '',
        `note` TEXT NULL,
        `created_by` VARCHAR(120) NOT NULL DEFAULT '',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `k_dev` (`device_id`), KEY `k_back` (`back_date`), KEY `k_q` (`quotation_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `wh_items` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `code` VARCHAR(40) NOT NULL DEFAULT '',
        `name` VARCHAR(300) NOT NULL DEFAULT '',
        `kind` VARCHAR(20) NOT NULL DEFAULT 'standee',
        `company_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `customer_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `owner_name` VARCHAR(200) NOT NULL DEFAULT '',
        `spec` VARCHAR(300) NOT NULL DEFAULT '',
        `unit` VARCHAR(30) NOT NULL DEFAULT '',
        `location` VARCHAR(200) NOT NULL DEFAULT '',
        `note` TEXT NULL,
        `photo` VARCHAR(200) NOT NULL DEFAULT '',
        `created_by` VARCHAR(120) NOT NULL DEFAULT '',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `deleted_at` DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (`id`), KEY `k_kind` (`kind`), KEY `k_co` (`company_id`), KEY `k_del` (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `wh_moves` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `item_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `kind` VARCHAR(10) NOT NULL DEFAULT 'in',
        `qty` INT NOT NULL DEFAULT 0,
        `move_date` DATE NULL DEFAULT NULL,
        `quotation_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `person` VARCHAR(200) NOT NULL DEFAULT '',
        `note` VARCHAR(500) NOT NULL DEFAULT '',
        `created_by` VARCHAR(120) NOT NULL DEFAULT '',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `k_item` (`item_id`), KEY `k_date` (`move_date`), KEY `k_q` (`quotation_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

$ACTION = isset($_GET['action']) ? (string) $_GET['action'] : '';
$RAW    = file_get_contents('php://input');
$B      = json_decode($RAW ? $RAW : '[]', true);
if (!is_array($B)) $B = array();

try {
    wh_init();
    $pdo = wh_pdo();

    switch ($ACTION) {

    case 'meta': {
        wh_login();
        $users = array(); $companies = array(); $customers = array(); $projects = array();
        try { $users = $pdo->query("SELECT id, display_name, username, position FROM `app_users`
                                     WHERE active = 1 ORDER BY display_name ASC, username ASC")->fetchAll(); } catch (Exception $e) {}
        try { $companies = $pdo->query("SELECT id, name FROM `crm_companies` ORDER BY name ASC LIMIT 800")->fetchAll(); } catch (Exception $e) {}
        try { $customers = $pdo->query("SELECT id, name, company_id FROM `crm_customers` ORDER BY name ASC LIMIT 1500")->fetchAll(); } catch (Exception $e) {}
        try {
            $hide = wh_is_admin() ? '' : ' AND (hidden IS NULL OR hidden = 0)';
            $projects = $pdo->query("SELECT id, code, title FROM `quotations`
                                      WHERE deleted_at IS NULL" . $hide . "
                                   ORDER BY id DESC LIMIT 600")->fetchAll();
        } catch (Exception $e) {}
        wh_ok(array('data' => array(
            'cats' => wh_cats(), 'conds' => wh_conds(), 'stats' => wh_stats(), 'kinds' => wh_kinds(),
            'users' => $users, 'companies' => $companies, 'customers' => $customers, 'projects' => $projects,
            'me' => array('id' => wh_uid(), 'name' => wh_uname(), 'admin' => wh_is_admin() ? 1 : 0),
        )));
    }

    case 'dev-list': {
        wh_login();
        $trash = !empty($_GET['trash']);
        $st = $pdo->query("SELECT d.*,
                (SELECT COALESCE(SUM(l.qty),0) FROM `wh_loans` l WHERE l.device_id = d.id AND l.back_date IS NULL) AS out_qty,
                (SELECT COUNT(*) FROM `wh_loans` l WHERE l.device_id = d.id AND l.back_date IS NULL) AS out_n
              FROM `wh_devices` d
             WHERE d.deleted_at IS " . ($trash ? 'NOT NULL' : 'NULL') . "
          ORDER BY d.category ASC, d.name ASC, d.id ASC");
        $rows = $st->fetchAll();
        foreach ($rows as $k => $r) {
            $rows[$k]['qty']     = (int) $r['qty'];
            $rows[$k]['out_qty'] = (int) $r['out_qty'];
            $rows[$k]['avail']   = max(0, (int) $r['qty'] - (int) $r['out_qty']);
        }
        wh_ok(array('rows' => $rows));
    }

    case 'dev-save': {
        wh_login();
        $id   = isset($B['id']) ? (int) $B['id'] : 0;
        $name = wh_s(isset($B['name']) ? $B['name'] : '', 300);
        if ($name === '') wh_fail('Tên thiết bị không được để trống.');
        $f = array(
            'code'      => wh_s(isset($B['code']) ? $B['code'] : '', 40),
            'name'      => $name,
            'category'  => wh_pick(wh_cats(),  isset($B['category']) ? $B['category'] : '', 'other'),
            'brand'     => wh_s(isset($B['brand']) ? $B['brand'] : '', 120),
            'serial'    => wh_s(isset($B['serial']) ? $B['serial'] : '', 120),
            'qty'       => max(0, isset($B['qty']) ? (int) $B['qty'] : 1),
            'unit'      => wh_s(isset($B['unit']) ? $B['unit'] : '', 30),
            'cond'      => wh_pick(wh_conds(), isset($B['cond']) ? $B['cond'] : '', 'good'),
            'status'    => wh_pick(wh_stats(), isset($B['status']) ? $B['status'] : '', 'in_stock'),
            'location'  => wh_s(isset($B['location']) ? $B['location'] : '', 200),
            'buy_date'  => wh_date(isset($B['buy_date']) ? $B['buy_date'] : ''),
            'buy_price' => wh_num(isset($B['buy_price']) ? $B['buy_price'] : 0),
            'note'      => wh_s(isset($B['note']) ? $B['note'] : '', 2000),
        );
        $cols = array_keys($f);
        if ($id > 0) {
            $set = array();
            foreach ($cols as $c) $set[] = "`$c` = ?";
            $pdo->prepare("UPDATE `wh_devices` SET " . implode(', ', $set) . " WHERE id = ?")
                ->execute(array_merge(array_values($f), array($id)));
        } else {
            $f['created_by'] = wh_uname();
            $cols = array_keys($f);
            $pdo->prepare("INSERT INTO `wh_devices` (`" . implode('`,`', $cols) . "`) VALUES ("
                . rtrim(str_repeat('?,', count($cols)), ',') . ")")->execute(array_values($f));
            $id = (int) $pdo->lastInsertId();
        }
        wh_ok(array('id' => $id, 'message' => 'Đã lưu thiết bị.'));
    }

    case 'dev-del': {
        wh_login();
        $id = isset($B['id']) ? (int) $B['id'] : 0;
        if (!$id) wh_fail('Thiếu id.');
        $c = $pdo->prepare("SELECT COUNT(*) FROM `wh_loans` WHERE device_id = ? AND back_date IS NULL");
        $c->execute(array($id));
        $n = (int) $c->fetchColumn();
        if ($n > 0) wh_fail('Thiết bị đang có ' . $n . ' phiếu mượn chưa trả - nhận lại trước đã.', 409);
        $pdo->prepare("UPDATE `wh_devices` SET deleted_at = NOW() WHERE id = ?")->execute(array($id));
        wh_ok(array('id' => $id, 'message' => 'Đã chuyển thiết bị vào thùng rác.'));
    }

    case 'dev-restore': {
        wh_login();
        $id = isset($B['id']) ? (int) $B['id'] : 0;
        if (!$id) wh_fail('Thiếu id.');
        $pdo->prepare("UPDATE `wh_devices` SET deleted_at = NULL WHERE id = ?")->execute(array($id));
        wh_ok(array('id' => $id, 'message' => 'Đã khôi phục thiết bị.'));
    }

    case 'loan-list': {
        wh_login();
        $dev  = isset($_GET['device_id']) ? (int) $_GET['device_id'] : 0;
        $open = isset($_GET['open']) ? (int) $_GET['open'] : -1;
        $w = array('1=1'); $p = array();
        if ($dev > 0)    { $w[] = 'l.device_id = ?'; $p[] = $dev; }
        if ($open === 1) { $w[] = 'l.back_date IS NULL'; }
        if ($open === 0) { $w[] = 'l.back_date IS NOT NULL'; }
        $st = $pdo->prepare("SELECT l.*, d.name AS device_name, d.code AS device_code, d.unit,
                                    q.code AS q_code, q.title AS q_title
                               FROM `wh_loans` l
                          LEFT JOIN `wh_devices` d ON d.id = l.device_id
                          LEFT JOIN `quotations` q ON q.id = l.quotation_id
                              WHERE " . implode(' AND ', $w) . "
                           ORDER BY (l.back_date IS NULL) DESC, l.out_date DESC, l.id DESC LIMIT 1000");
        $st->execute($p);
        wh_ok(array('rows' => $st->fetchAll()));
    }

    case 'loan-save': {
        wh_login();
        $id  = isset($B['id']) ? (int) $B['id'] : 0;
        $dev = isset($B['device_id']) ? (int) $B['device_id'] : 0;
        $qty = max(1, isset($B['qty']) ? (int) $B['qty'] : 1);
        if (!$dev) wh_fail('Chưa chọn thiết bị.');
        $d = $pdo->prepare("SELECT id, name, qty FROM `wh_devices` WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $d->execute(array($dev));
        $dr = $d->fetch();
        if (!$dr) wh_fail('Không tìm thấy thiết bị.', 404);

        $used = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM `wh_loans`
                                WHERE device_id = ? AND back_date IS NULL AND id <> ?");
        $used->execute(array($dev, $id));
        $avail = (int) $dr['qty'] - (int) $used->fetchColumn();
        if ($qty > $avail) wh_fail('Chỉ còn ' . $avail . ' cái trong kho, không mượn được ' . $qty . '.', 409);

        $uid = isset($B['user_id']) ? (int) $B['user_id'] : 0;
        $un  = wh_s(isset($B['user_name']) ? $B['user_name'] : '', 200);
        if ($uid > 0 && $un === '') {
            $u = $pdo->prepare("SELECT display_name, username FROM `app_users` WHERE id = ? LIMIT 1");
            $u->execute(array($uid));
            $ur = $u->fetch();
            if ($ur) $un = trim((string) $ur['display_name']) !== '' ? $ur['display_name'] : $ur['username'];
        }
        if ($un === '') wh_fail('Chưa ghi người mượn.');

        $od = wh_date(isset($B['out_date']) ? $B['out_date'] : '');
        $f = array(
            'device_id'    => $dev,
            'qty'          => $qty,
            'user_id'      => $uid,
            'user_name'    => $un,
            'quotation_id' => isset($B['quotation_id']) ? (int) $B['quotation_id'] : 0,
            'purpose'      => wh_s(isset($B['purpose']) ? $B['purpose'] : '', 300),
            'out_date'     => $od ? $od : date('Y-m-d'),
            'due_date'     => wh_date(isset($B['due_date']) ? $B['due_date'] : ''),
            'note'         => wh_s(isset($B['note']) ? $B['note'] : '', 1000),
        );
        $cols = array_keys($f);
        if ($id > 0) {
            $set = array();
            foreach ($cols as $c) $set[] = "`$c` = ?";
            $pdo->prepare("UPDATE `wh_loans` SET " . implode(', ', $set) . " WHERE id = ?")
                ->execute(array_merge(array_values($f), array($id)));
        } else {
            $f['created_by'] = wh_uname();
            $cols = array_keys($f);
            $pdo->prepare("INSERT INTO `wh_loans` (`" . implode('`,`', $cols) . "`) VALUES ("
                . rtrim(str_repeat('?,', count($cols)), ',') . ")")->execute(array_values($f));
            $id = (int) $pdo->lastInsertId();
        }
        wh_ok(array('id' => $id, 'message' => 'Đã ghi phiếu mượn.'));
    }

    case 'loan-return': {
        wh_login();
        $id = isset($B['id']) ? (int) $B['id'] : 0;
        if (!$id) wh_fail('Thiếu id.');
        $bd   = wh_date(isset($B['back_date']) ? $B['back_date'] : '');
        $back = $bd ? $bd : date('Y-m-d');
        $cond = wh_pick(wh_conds(), isset($B['back_cond']) ? $B['back_cond'] : '', 'good');
        $note = wh_s(isset($B['note']) ? $B['note'] : '', 1000);
        $pdo->prepare("UPDATE `wh_loans` SET back_date = ?, back_cond = ?,
                              note = CASE WHEN ? = '' THEN note ELSE ? END WHERE id = ?")
            ->execute(array($back, $cond, $note, $note, $id));
        if ($cond === 'broken' || $cond === 'lost') {
            $l = $pdo->prepare("SELECT device_id FROM `wh_loans` WHERE id = ? LIMIT 1");
            $l->execute(array($id));
            $dv = (int) $l->fetchColumn();
            if ($dv) $pdo->prepare("UPDATE `wh_devices` SET `cond` = ? WHERE id = ?")->execute(array($cond, $dv));
        }
        wh_ok(array('id' => $id, 'message' => 'Đã nhận lại thiết bị.'));
    }

    case 'loan-del': {
        wh_login();
        $id = isset($B['id']) ? (int) $B['id'] : 0;
        if (!$id) wh_fail('Thiếu id.');
        $pdo->prepare("DELETE FROM `wh_loans` WHERE id = ?")->execute(array($id));
        wh_ok(array('id' => $id, 'message' => 'Đã xoá phiếu mượn.'));
    }

    case 'item-list': {
        wh_login();
        $trash = !empty($_GET['trash']);
        $st = $pdo->query("SELECT i.*, co.name AS company_name, cu.name AS customer_name,
                (SELECT COALESCE(SUM(CASE m.kind WHEN 'in' THEN m.qty WHEN 'out' THEN -m.qty ELSE m.qty END),0)
                   FROM `wh_moves` m WHERE m.item_id = i.id) AS stock,
                (SELECT COALESCE(SUM(m.qty),0) FROM `wh_moves` m WHERE m.item_id = i.id AND m.kind = 'in')  AS in_qty,
                (SELECT COALESCE(SUM(m.qty),0) FROM `wh_moves` m WHERE m.item_id = i.id AND m.kind = 'out') AS out_qty,
                (SELECT MAX(m.move_date) FROM `wh_moves` m WHERE m.item_id = i.id) AS last_move
              FROM `wh_items` i
         LEFT JOIN `crm_companies` co ON co.id = i.company_id
         LEFT JOIN `crm_customers` cu ON cu.id = i.customer_id
             WHERE i.deleted_at IS " . ($trash ? 'NOT NULL' : 'NULL') . "
          ORDER BY i.kind ASC, i.name ASC, i.id ASC");
        $rows = $st->fetchAll();
        foreach ($rows as $k => $r) {
            $rows[$k]['stock']   = (int) $r['stock'];
            $rows[$k]['in_qty']  = (int) $r['in_qty'];
            $rows[$k]['out_qty'] = (int) $r['out_qty'];
        }
        wh_ok(array('rows' => $rows));
    }

    case 'item-save': {
        wh_login();
        $id   = isset($B['id']) ? (int) $B['id'] : 0;
        $name = wh_s(isset($B['name']) ? $B['name'] : '', 300);
        if ($name === '') wh_fail('Tên mặt hàng không được để trống.');
        $f = array(
            'code'        => wh_s(isset($B['code']) ? $B['code'] : '', 40),
            'name'        => $name,
            'kind'        => wh_pick(wh_kinds(), isset($B['kind']) ? $B['kind'] : '', 'standee'),
            'company_id'  => isset($B['company_id']) ? (int) $B['company_id'] : 0,
            'customer_id' => isset($B['customer_id']) ? (int) $B['customer_id'] : 0,
            'owner_name'  => wh_s(isset($B['owner_name']) ? $B['owner_name'] : '', 200),
            'spec'        => wh_s(isset($B['spec']) ? $B['spec'] : '', 300),
            'unit'        => wh_s(isset($B['unit']) ? $B['unit'] : '', 30),
            'location'    => wh_s(isset($B['location']) ? $B['location'] : '', 200),
            'note'        => wh_s(isset($B['note']) ? $B['note'] : '', 2000),
        );
        $cols = array_keys($f);
        if ($id > 0) {
            $set = array();
            foreach ($cols as $c) $set[] = "`$c` = ?";
            $pdo->prepare("UPDATE `wh_items` SET " . implode(', ', $set) . " WHERE id = ?")
                ->execute(array_merge(array_values($f), array($id)));
        } else {
            $f['created_by'] = wh_uname();
            $cols = array_keys($f);
            $pdo->prepare("INSERT INTO `wh_items` (`" . implode('`,`', $cols) . "`) VALUES ("
                . rtrim(str_repeat('?,', count($cols)), ',') . ")")->execute(array_values($f));
            $id = (int) $pdo->lastInsertId();
            $first = isset($B['first_qty']) ? (int) $B['first_qty'] : 0;
            if ($first > 0) {
                $pdo->prepare("INSERT INTO `wh_moves` (item_id, kind, qty, move_date, note, created_by)
                               VALUES (?, 'in', ?, ?, ?, ?)")
                    ->execute(array($id, $first, date('Y-m-d'), 'Nhập kho lần đầu', wh_uname()));
            }
        }
        wh_ok(array('id' => $id, 'message' => 'Đã lưu mặt hàng.'));
    }

    case 'item-del': {
        wh_login();
        $id = isset($B['id']) ? (int) $B['id'] : 0;
        if (!$id) wh_fail('Thiếu id.');
        $pdo->prepare("UPDATE `wh_items` SET deleted_at = NOW() WHERE id = ?")->execute(array($id));
        wh_ok(array('id' => $id, 'message' => 'Đã chuyển vào thùng rác.'));
    }

    case 'item-restore': {
        wh_login();
        $id = isset($B['id']) ? (int) $B['id'] : 0;
        if (!$id) wh_fail('Thiếu id.');
        $pdo->prepare("UPDATE `wh_items` SET deleted_at = NULL WHERE id = ?")->execute(array($id));
        wh_ok(array('id' => $id, 'message' => 'Đã khôi phục.'));
    }

    case 'move-list': {
        wh_login();
        $item = isset($_GET['item_id']) ? (int) $_GET['item_id'] : 0;
        $w = array('1=1'); $p = array();
        if ($item > 0) { $w[] = 'm.item_id = ?'; $p[] = $item; }
        $st = $pdo->prepare("SELECT m.*, i.name AS item_name, i.code AS item_code, i.unit, i.kind AS item_kind,
                                    q.code AS q_code, q.title AS q_title
                               FROM `wh_moves` m
                          LEFT JOIN `wh_items` i ON i.id = m.item_id
                          LEFT JOIN `quotations` q ON q.id = m.quotation_id
                              WHERE " . implode(' AND ', $w) . "
                           ORDER BY m.move_date DESC, m.id DESC LIMIT 1000");
        $st->execute($p);
        wh_ok(array('rows' => $st->fetchAll()));
    }

    case 'move-save': {
        wh_login();
        $item = isset($B['item_id']) ? (int) $B['item_id'] : 0;
        $kk   = isset($B['kind']) ? (string) $B['kind'] : '';
        $kind = in_array($kk, array('in', 'out', 'adjust'), true) ? $kk : 'in';
        $qty  = isset($B['qty']) ? (int) $B['qty'] : 0;
        if (!$item) wh_fail('Chưa chọn mặt hàng.');
        if ($kind !== 'adjust' && $qty <= 0) wh_fail('Số lượng phải lớn hơn 0.');
        if ($kind === 'adjust' && $qty === 0) wh_fail('Số lượng điều chỉnh phải khác 0.');

        $ck = $pdo->prepare("SELECT id FROM `wh_items` WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $ck->execute(array($item));
        if (!$ck->fetchColumn()) wh_fail('Không tìm thấy mặt hàng.', 404);

        if ($kind === 'out') {
            $s = $pdo->prepare("SELECT COALESCE(SUM(CASE kind WHEN 'in' THEN qty WHEN 'out' THEN -qty ELSE qty END),0)
                                  FROM `wh_moves` WHERE item_id = ?");
            $s->execute(array($item));
            $stock = (int) $s->fetchColumn();
            if ($qty > $stock) wh_fail('Tồn chỉ còn ' . $stock . ', không xuất được ' . $qty . '.', 409);
        }

        $md = wh_date(isset($B['move_date']) ? $B['move_date'] : '');
        $pdo->prepare("INSERT INTO `wh_moves` (item_id, kind, qty, move_date, quotation_id, person, note, created_by)
                       VALUES (?,?,?,?,?,?,?,?)")
            ->execute(array(
                $item, $kind, $kind === 'adjust' ? $qty : abs($qty),
                $md ? $md : date('Y-m-d'),
                isset($B['quotation_id']) ? (int) $B['quotation_id'] : 0,
                wh_s(isset($B['person']) ? $B['person'] : '', 200),
                wh_s(isset($B['note']) ? $B['note'] : '', 500),
                wh_uname(),
            ));
        wh_ok(array('id' => (int) $pdo->lastInsertId(), 'message' => 'Đã ghi phiếu.'));
    }

    case 'move-del': {
        wh_login();
        $id = isset($B['id']) ? (int) $B['id'] : 0;
        if (!$id) wh_fail('Thiếu id.');
        $pdo->prepare("DELETE FROM `wh_moves` WHERE id = ?")->execute(array($id));
        wh_ok(array('id' => $id, 'message' => 'Đã xoá phiếu.'));
    }

    case 'summary': {
        wh_login();
        $d = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(qty),0) q FROM `wh_devices` WHERE deleted_at IS NULL")->fetch();
        $o = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(qty),0) q FROM `wh_loans` WHERE back_date IS NULL")->fetch();
        $ov = (int) $pdo->query("SELECT COUNT(*) FROM `wh_loans`
                                  WHERE back_date IS NULL AND due_date IS NOT NULL AND due_date < CURDATE()")->fetchColumn();
        $it = (int) $pdo->query("SELECT COUNT(*) FROM `wh_items` WHERE deleted_at IS NULL")->fetchColumn();
        $sk = (int) $pdo->query("SELECT COALESCE(SUM(CASE m.kind WHEN 'in' THEN m.qty WHEN 'out' THEN -m.qty ELSE m.qty END),0)
                                   FROM `wh_moves` m
                                   JOIN `wh_items` i ON i.id = m.item_id AND i.deleted_at IS NULL")->fetchColumn();
        wh_ok(array('data' => array(
            'dev_n' => (int) $d['n'], 'dev_qty' => (int) $d['q'],
            'out_n' => (int) $o['n'], 'out_qty' => (int) $o['q'],
            'overdue' => $ov, 'item_n' => $it, 'item_qty' => $sk,
        )));
    }

    default:
        wh_fail('Hành động không hợp lệ: ' . $ACTION, 404);
    }
} catch (Exception $e) {
    wh_fail('Lỗi máy chủ: ' . $e->getMessage(), 500);
}
