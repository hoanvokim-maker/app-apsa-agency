<?php
/**
 * APSA — API quan ly nha cung cap.
 * Dung chung bang `ratecard_suppliers` voi rate card, bo sung dia chi / dien thoai / email.
 * Xem: moi nhan vien da dang nhap.  Them / sua / xoa: chi Admin.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/session-boot.php';

function sp_out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function sp_fail($m, $c = 400) { sp_out(array('ok' => false, 'error' => $m), $c); }
function sp_ok($d) { sp_out(array('ok' => true, 'data' => $d)); }

function sp_body()
{
    $b = json_decode((string) file_get_contents('php://input'), true);
    return is_array($b) ? $b : array();
}
function sp_s($v, $len = 300)
{
    return mb_substr(trim(preg_replace('/[\r\n\t]+/u', ' ', (string) ($v === null ? '' : $v))), 0, $len);
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) { sp_fail('DB connection failed', 500); }

$ME  = sp_currentUser($pdo);
if (!$ME) sp_fail('Unauthorized — vui lòng đăng nhập', 401);
$IS_ADMIN = (strcasecmp((string) ($ME['role'] ?? ''), 'admin') === 0);
function sp_need_admin() { global $IS_ADMIN; if (!$IS_ADMIN) sp_fail('Chỉ Admin mới thêm/sửa được nhà cung cấp.', 403); }

/* Bang co san tu rate card; bo sung cot neu chua co. */
function sp_ensure(PDO $pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS `ratecard_suppliers` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name`       VARCHAR(200) NOT NULL,
        `contact`    VARCHAR(200) DEFAULT NULL,
        `note`       VARCHAR(300) DEFAULT NULL,
        `active`     TINYINT(1)   NOT NULL DEFAULT 1,
        `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `u_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ratecard_suppliers'
                                AND COLUMN_NAME = 'address'");
        $st->execute();
        if ((int) $st->fetchColumn() > 0) return;
    } catch (PDOException $e) { return; }

    try {
        $pdo->exec("ALTER TABLE `ratecard_suppliers`
            ADD COLUMN `address`    VARCHAR(400) DEFAULT NULL COMMENT 'Dia chi',
            ADD COLUMN `phone`      VARCHAR(40)  DEFAULT NULL,
            ADD COLUMN `phone2`     VARCHAR(40)  DEFAULT NULL,
            ADD COLUMN `email`      VARCHAR(150) DEFAULT NULL,
            ADD COLUMN `tax_code`   VARCHAR(40)  DEFAULT NULL COMMENT 'Ma so thue',
            ADD COLUMN `updated_by` VARCHAR(120) DEFAULT NULL,
            ADD COLUMN `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    } catch (PDOException $e) { /* da co */ }
}

function sp_bank_cols(PDO $pdo)
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ratecard_suppliers'
                                AND COLUMN_NAME = 'bank_account'");
        $st->execute();
        if ((int) $st->fetchColumn() > 0) return;
    } catch (PDOException $e) { return; }
    try {
        $pdo->exec("ALTER TABLE `ratecard_suppliers`
            ADD COLUMN `bank_name`    VARCHAR(120) DEFAULT NULL COMMENT 'Ngan hang',
            ADD COLUMN `bank_branch`  VARCHAR(150) DEFAULT NULL COMMENT 'Chi nhanh',
            ADD COLUMN `bank_account` VARCHAR(50)  DEFAULT NULL COMMENT 'So tai khoan',
            ADD COLUMN `bank_holder`  VARCHAR(200) DEFAULT NULL COMMENT 'Chu tai khoan'");
    } catch (PDOException $e) { /* da co */ }
}

sp_ensure($pdo);
function sp_region_col(PDO $pdo)
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ratecard_suppliers'
                                AND COLUMN_NAME = 'region'");
        $st->execute();
        if ((int) $st->fetchColumn() > 0) return;
    } catch (PDOException $e) { return; }
    try {
        $pdo->exec("ALTER TABLE `ratecard_suppliers`
            ADD COLUMN `region` VARCHAR(10) DEFAULT NULL COMMENT 'bac|trung|nam|tay'");
    } catch (PDOException $e) { /* da co */ }
}

sp_bank_cols($pdo);
sp_region_col($pdo);
$WHO = (string) (($ME['display_name'] ?? '') !== '' ? $ME['display_name'] : ($ME['username'] ?? ''));
$act = isset($_GET['action']) ? (string) $_GET['action'] : '';

switch ($act) {

case 'me':
    sp_ok(array('name' => $WHO, 'is_admin' => $IS_ADMIN ? 1 : 0));
    break;

case 'list': {
    $q  = sp_s(isset($_GET['q']) ? $_GET['q'] : '', 120);
    $sql = "SELECT `id`,`name`,`contact`,`address`,`phone`,`phone2`,`email`,`tax_code`,`region`,`bank_name`,`bank_branch`,`bank_account`,`bank_holder`,`note`,`active`,`updated_by`,`updated_at`
              FROM `ratecard_suppliers`";
    $arg = array();
    if ($q !== '') {
        $sql .= " WHERE `name` LIKE ? OR `contact` LIKE ? OR `phone` LIKE ? OR `phone2` LIKE ? OR `address` LIKE ? OR `bank_account` LIKE ? OR `bank_name` LIKE ?";
        $like = '%' . $q . '%';
        $arg  = array($like, $like, $like, $like, $like, $like, $like);
    }
    $sql .= " ORDER BY `active` DESC, `name` ASC";
    $st = $pdo->prepare($sql);
    $st->execute($arg);
    sp_ok(array('items' => $st->fetchAll(), 'is_admin' => $IS_ADMIN ? 1 : 0));
    break;
}

case 'save': {
    sp_need_admin();
    $b    = sp_body();
    $id   = (int) (isset($b['id']) ? $b['id'] : 0);
    $name = sp_s(isset($b['name']) ? $b['name'] : '', 200);
    if ($name === '') sp_fail('Tên công ty là bắt buộc.');

    $f = array(
        $name,
        sp_s(isset($b['contact'])  ? $b['contact']  : '', 200),
        sp_s(isset($b['address'])  ? $b['address']  : '', 400),
        sp_s(isset($b['phone'])    ? $b['phone']    : '', 40),
        sp_s(isset($b['phone2'])   ? $b['phone2']   : '', 40),
        sp_s(isset($b['email'])    ? $b['email']    : '', 150),
        sp_s(isset($b['tax_code']) ? $b['tax_code'] : '', 40),
        sp_region(isset($b['region']) ? $b['region'] : ''),
        sp_s(isset($b['bank_name'])    ? $b['bank_name']    : '', 120),
        sp_s(isset($b['bank_branch'])  ? $b['bank_branch']  : '', 150),
        preg_replace('/[^0-9A-Za-z]/', '', sp_s(isset($b['bank_account']) ? $b['bank_account'] : '', 50)),
        sp_s(isset($b['bank_holder'])  ? $b['bank_holder']  : '', 200),
        sp_s(isset($b['note'])     ? $b['note']     : '', 300),
        (int) (isset($b['active']) ? (int) $b['active'] : 1) ? 1 : 0,
        $WHO,
    );

    try {
        if ($id > 0) {
            $f[] = $id;
            $st = $pdo->prepare("UPDATE `ratecard_suppliers`
                                    SET `name`=?,`contact`=?,`address`=?,`phone`=?,`phone2`=?,`email`=?,`tax_code`=?,`region`=?,`bank_name`=?,`bank_branch`=?,`bank_account`=?,`bank_holder`=?,`note`=?,`active`=?,`updated_by`=?
                                  WHERE `id`=?");
            $st->execute($f);
        } else {
            $st = $pdo->prepare("INSERT INTO `ratecard_suppliers`
                                 (`name`,`contact`,`address`,`phone`,`phone2`,`email`,`tax_code`,`region`,`bank_name`,`bank_branch`,`bank_account`,`bank_holder`,`note`,`active`,`updated_by`)
                                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute($f);
            $id = (int) $pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') sp_fail('Đã có nhà cung cấp trùng tên.', 409);
        sp_fail($e->getMessage(), 500);
    }
    sp_ok(array('id' => $id));
    break;
}

case 'delete': {
    sp_need_admin();
    $b  = sp_body();
    $id = (int) (isset($b['id']) ? $b['id'] : 0);
    if (!$id) sp_fail('id is required');

    /* Con gan voi san pham nao thi chi tat, khong xoa han. */
    $used = 0;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM `ratecard_item_supply` WHERE `supplier_id` = ?");
        $st->execute(array($id));
        $used = (int) $st->fetchColumn();
    } catch (PDOException $e) { $used = 0; }

    if ($used > 0) {
        $pdo->prepare("UPDATE `ratecard_suppliers` SET `active` = 0, `updated_by` = ? WHERE `id` = ?")
            ->execute(array($WHO, $id));
        sp_ok(array('deactivated' => true, 'used' => $used));
    }
    $pdo->prepare("DELETE FROM `ratecard_suppliers` WHERE `id` = ?")->execute(array($id));
    sp_ok(array('deleted' => true));
    break;
}

default:
    sp_fail('Action không hợp lệ: ' . $act, 404);
}

/* current user — theo dung mau cua ratecard-api.php */
function sp_currentUser(PDO $pdo)
{
    if (empty($_SESSION['user_id'])) return null;
    try {
        $st = $pdo->prepare("SELECT id, username, display_name, role, position FROM `app_users` WHERE id = ? AND active = 1");
        $st->execute([$_SESSION['user_id']]);
        return $st->fetch() ?: null;
    } catch (PDOException $e) { return null; }
}


/* Chi nhan 4 khu vuc; gia tri la nguon khac thi coi nhu de trong. */
function sp_region($v)
{
    $v = strtolower(trim((string) $v));
    return in_array($v, array('bac', 'trung', 'nam', 'tay'), true) ? $v : '';
}