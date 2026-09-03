<?php
/**
 * APSA - API bo sung cho sheet VFR cua Rate Card
 * ------------------------------------------------------------------
 *  - Gia ban theo so luong  : dung lai 3 cot co san cua ratecard_items
 *                             basic = < 10, standard = 10-50, premium = > 50
 *  - Thong so + link thu muc file san xuat (SharePoint) : cot moi tren ratecard_items
 *  - Nha cung cap va don gia theo so luong : 2 bang moi
 *
 *  GET  ?action=me                       -> {admin, name}
 *  GET  ?action=suppliers                -> danh sach nha cung cap
 *  POST ?action=supplier-save   {id?, name, contact, note, active}
 *  POST ?action=supplier-delete {id}
 *  GET  ?action=supply&item_id=..        -> don gia NCC cua 1 san pham   (Admin)
 *  GET  ?action=supply&sheet=vfr         -> don gia NCC cua ca sheet     (Admin)
 *  POST ?action=supply-save     {item_id, rows:[{supplier_id,p1,p2,p3,note}]}  (Admin)
 *  POST ?action=extra-save      {item_id, specs, prod_url, prod_name, prod_id}
 */

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/session-boot.php';

function vf_out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function vf_fail($m, $c = 400) { vf_out(array('ok' => false, 'error' => $m), $c); }

function vf_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return array();
    $j = json_decode($raw, true);
    return is_array($j) ? $j : array();
}

function vf_num($v)
{
    if ($v === '' || $v === null) return 0;
    $v = str_replace(array(',', ' '), '', (string) $v);
    return is_numeric($v) ? round((float) $v, 2) : 0;
}

function vf_s($v, $len = 300) { return mb_substr(trim((string) ($v === null ? '' : $v)), 0, $len); }

function vf_pdo()
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
        );
    } catch (PDOException $e) { vf_fail('Khong ket noi duoc co so du lieu.', 500); }
    return $pdo;
}

function vf_me()
{
    static $me = null;
    if ($me !== null) return $me;
    $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if ($uid <= 0) vf_fail('Chua dang nhap.', 401);
    $st = vf_pdo()->prepare('SELECT * FROM app_users WHERE id = ? LIMIT 1');
    $st->execute(array($uid));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) vf_fail('Chua dang nhap.', 401);
    if (isset($r['active']) && (int) $r['active'] === 0) vf_fail('Tai khoan da bi khoa.', 403);
    $me = array(
        'id'   => (int) $r['id'],
        'name' => trim((string) (!empty($r['display_name']) ? $r['display_name'] : $r['username'])),
        'role' => isset($r['role']) ? (string) $r['role'] : '',
    );
    return $me;
}

function vf_is_admin() { $m = vf_me(); return strcasecmp($m['role'], 'admin') === 0; }
function vf_need_admin() { if (!vf_is_admin()) vf_fail('Chi Admin moi xem/sua duoc phan nay.', 403); }
/** Quyen chi tiet theo module. $what: view|add|edit|del */
function vf_need_cap($mid, $what)
{
    require_once __DIR__ . '/perm.php';
    $ok = false;
    if (function_exists('pm_can')) { pm_init(); $ok = pm_can($mid, $what); }
    if ($ok) return;
    $m  = function_exists('pm_mod') ? pm_mod($mid) : null;
    $lb = array('view' => 'xem', 'add' => 'thêm', 'edit' => 'sửa', 'del' => 'xoá');
    $w  = isset($lb[$what]) ? $lb[$what] : $what;
    vf_fail('Bạn không có quyền ' . $w . ' trong mục '
        . ($m ? $m['name'] : 'này') . '. Liên hệ Admin để được cấp quyền.', 403);
}


/* ------------------------------------------------------------------ *
 *  Tao bang / cot (chay 1 lan)
 * ------------------------------------------------------------------ */

function vf_has_col($table, $col)
{
    try {
        $st = vf_pdo()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute(array($table, $col));
        return (int) $st->fetchColumn() > 0;
    } catch (PDOException $e) { return true; }
}

function vf_boot()
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = vf_pdo();

    if (!vf_has_col('ratecard_items', 'specs')) {
        try {
            $pdo->exec("ALTER TABLE `ratecard_items`
                ADD COLUMN `specs` TEXT DEFAULT NULL COMMENT 'Thong so ky thuat (VFR)',
                ADD COLUMN `prod_url` VARCHAR(600) DEFAULT NULL COMMENT 'Link SharePoint toi file/thu muc san xuat',
                ADD COLUMN `prod_name` VARCHAR(300) DEFAULT NULL COMMENT 'Ten hien thi cua muc SharePoint',
                ADD COLUMN `prod_id` VARCHAR(200) DEFAULT NULL COMMENT 'driveItem id tren SharePoint',
                ADD COLUMN `prod_dir` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = la thu muc'");
        } catch (PDOException $e) { /* da co */ }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `ratecard_suppliers` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(200) NOT NULL,
        `contact` VARCHAR(200) DEFAULT NULL,
        `note` VARCHAR(300) DEFAULT NULL,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `u_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `ratecard_item_supply` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `item_id` INT UNSIGNED NOT NULL,
        `supplier_id` INT UNSIGNED NOT NULL,
        `p1` DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Don gia < 10',
        `p2` DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Don gia 10-50',
        `p3` DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Don gia > 50',
        `note` VARCHAR(300) DEFAULT NULL,
        `sort_order` INT NOT NULL DEFAULT 0,
        `updated_by` VARCHAR(120) DEFAULT NULL,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `u_pair` (`item_id`, `supplier_id`),
        KEY `k_item` (`item_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/* ------------------------------------------------------------------ *
 *  Dieu phoi
 * ------------------------------------------------------------------ */

$ME  = vf_me();
vf_boot();
$ACT = isset($_GET['action']) ? (string) $_GET['action'] : '';
$pdo = vf_pdo();

switch ($ACT) {

case 'me':
    vf_out(array('ok' => true, 'admin' => vf_is_admin(), 'name' => $ME['name'], 'id' => $ME['id']));
    break;

case 'suppliers':
    $rows = $pdo->query('SELECT id, name, contact, note, active FROM ratecard_suppliers ORDER BY active DESC, name')->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['active'] = (int) $r['active']; }
    unset($r);
    vf_out(array('ok' => true, 'rows' => $rows));
    break;

case 'supplier-save':
    $b  = vf_body();
    $id = isset($b['id']) ? (int) $b['id'] : 0;
    vf_need_cap(95, $id > 0 ? 'edit' : 'add');
    $nm = vf_s(isset($b['name']) ? $b['name'] : '', 200);
    if ($nm === '') vf_fail('Nhap ten nha cung cap.');
    $ct = vf_s(isset($b['contact']) ? $b['contact'] : '', 200);
    $nt = vf_s(isset($b['note']) ? $b['note'] : '', 300);
    $ac = isset($b['active']) ? ((int) $b['active'] ? 1 : 0) : 1;

    try {
        if ($id > 0) {
            $pdo->prepare('UPDATE ratecard_suppliers SET name=?, contact=?, note=?, active=? WHERE id=?')
                ->execute(array($nm, $ct, $nt, $ac, $id));
        } else {
            $pdo->prepare('INSERT INTO ratecard_suppliers (name, contact, note, active) VALUES (?,?,?,?)')
                ->execute(array($nm, $ct, $nt, $ac));
            $id = (int) $pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        vf_fail('Ten nha cung cap nay da ton tai.');
    }
    $st = $pdo->prepare('SELECT id, name, contact, note, active FROM ratecard_suppliers WHERE id=?');
    $st->execute(array($id));
    vf_out(array('ok' => true, 'row' => $st->fetch()));
    break;

case 'supplier-delete':
    vf_need_cap(95, 'del');
    $b  = vf_body();
    $id = isset($b['id']) ? (int) $b['id'] : 0;
    if (!$id) vf_fail('Thieu id.');
    $st = $pdo->prepare('SELECT COUNT(*) FROM ratecard_item_supply WHERE supplier_id=?');
    $st->execute(array($id));
    if ((int) $st->fetchColumn() > 0) {
        $pdo->prepare('UPDATE ratecard_suppliers SET active=0 WHERE id=?')->execute(array($id));
        vf_out(array('ok' => true, 'mode' => 'archived'));
    }
    $pdo->prepare('DELETE FROM ratecard_suppliers WHERE id=?')->execute(array($id));
    vf_out(array('ok' => true, 'mode' => 'deleted'));
    break;

case 'supply':
    vf_need_cap(26, 'view');
    $iid = isset($_GET['item_id']) ? (int) $_GET['item_id'] : 0;
    if ($iid > 0) {
        $st = $pdo->prepare('SELECT s.*, p.name AS supplier_name FROM ratecard_item_supply s
            LEFT JOIN ratecard_suppliers p ON p.id = s.supplier_id
            WHERE s.item_id = ? ORDER BY s.sort_order, s.id');
        $st->execute(array($iid));
        $rows = $st->fetchAll();
    } else {
        $sheet = isset($_GET['sheet']) ? vf_s($_GET['sheet'], 20) : 'vfr';
        $st = $pdo->prepare('SELECT s.*, p.name AS supplier_name FROM ratecard_item_supply s
            LEFT JOIN ratecard_suppliers p ON p.id = s.supplier_id
            INNER JOIN ratecard_items i ON i.id = s.item_id
            WHERE i.sheet_key = ? AND i.deleted_at IS NULL ORDER BY s.item_id, s.sort_order, s.id');
        $st->execute(array($sheet));
        $rows = $st->fetchAll();
    }
    $out = array();
    foreach ($rows as $r) {
        $k = (string) (int) $r['item_id'];
        if (!isset($out[$k])) $out[$k] = array();
        $out[$k][] = array(
            'supplier_id'   => (int) $r['supplier_id'],
            'supplier_name' => (string) $r['supplier_name'],
            'p1' => (float) $r['p1'], 'p2' => (float) $r['p2'], 'p3' => (float) $r['p3'],
            'note' => (string) $r['note'],
        );
    }
    vf_out(array('ok' => true, 'map' => (object) $out));
    break;

case 'supply-save':
    vf_need_cap(26, 'edit');
    $b   = vf_body();
    $iid = isset($b['item_id']) ? (int) $b['item_id'] : 0;
    if (!$iid) vf_fail('Thieu item_id.');
    $st = $pdo->prepare('SELECT id FROM ratecard_items WHERE id=?');
    $st->execute(array($iid));
    if (!$st->fetchColumn()) vf_fail('Khong tim thay san pham.', 404);

    $rows = isset($b['rows']) && is_array($b['rows']) ? $b['rows'] : array();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM ratecard_item_supply WHERE item_id=?')->execute(array($iid));
        $ins = $pdo->prepare('INSERT INTO ratecard_item_supply
            (item_id, supplier_id, p1, p2, p3, note, sort_order, updated_by) VALUES (?,?,?,?,?,?,?,?)');
        $i = 0; $seen = array();
        foreach ($rows as $r) {
            $sid = isset($r['supplier_id']) ? (int) $r['supplier_id'] : 0;
            if ($sid <= 0 || isset($seen[$sid])) continue;
            $seen[$sid] = 1;
            $ins->execute(array(
                $iid, $sid,
                vf_num(isset($r['p1']) ? $r['p1'] : 0),
                vf_num(isset($r['p2']) ? $r['p2'] : 0),
                vf_num(isset($r['p3']) ? $r['p3'] : 0),
                vf_s(isset($r['note']) ? $r['note'] : '', 300),
                $i++, $ME['name'],
            ));
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        vf_fail('Khong luu duoc don gia nha cung cap.', 500);
    }

    // Dong bo cot cost_price = don gia thap nhat o muc < 10 (de bao cao cu van chay)
    $st = $pdo->prepare('SELECT MIN(NULLIF(p1,0)) FROM ratecard_item_supply WHERE item_id=?');
    $st->execute(array($iid));
    $best = $st->fetchColumn();
    $pdo->prepare('UPDATE ratecard_items SET cost_price=? WHERE id=?')->execute(array($best === null ? 0 : $best, $iid));

    vf_out(array('ok' => true, 'count' => count($rows), 'cost_price' => $best === null ? 0 : (float) $best));
    break;

case 'extra-save':
    $b   = vf_body();
    $iid = isset($b['item_id']) ? (int) $b['item_id'] : 0;
    if (!$iid) vf_fail('Thieu item_id.');
    $pdo->prepare('UPDATE ratecard_items SET specs=?, prod_url=?, prod_name=?, prod_id=?, prod_dir=? WHERE id=?')
        ->execute(array(
            vf_s(isset($b['specs']) ? $b['specs'] : '', 4000),
            vf_s(isset($b['prod_url']) ? $b['prod_url'] : '', 600),
            vf_s(isset($b['prod_name']) ? $b['prod_name'] : '', 300),
            vf_s(isset($b['prod_id']) ? $b['prod_id'] : '', 200),
            !empty($b['prod_dir']) ? 1 : 0,
            $iid,
        ));
    vf_out(array('ok' => true));
    break;

default:
    vf_fail('Thao tac khong hop le.', 404);
}
