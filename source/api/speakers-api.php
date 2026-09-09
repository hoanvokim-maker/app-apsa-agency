<?php
/**
 * APSA1830 - Thu vien Speaker
 *  - sp_speakers : bac si / speaker / lanh dao cong ty
 *  Anh PNG duoc chuan hoa phia client (canvas) roi upload: anh 800x800 + thumb 200x200.
 *  Moi nhan vien da dang nhap deu duoc xem / them / sua / xoa.
 */

declare(strict_types=0);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db-config.php';
if (session_status() !== PHP_SESSION_ACTIVE && is_file(__DIR__ . '/session-boot.php')) {
    require_once __DIR__ . '/session-boot.php';
}

/* ---------------- tien ich ---------------- */

function sp_out($d, $code = 200)
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function sp_ok($d = array())   { sp_out(array_merge(array('ok' => true), $d)); }
function sp_fail($m, $c = 400) { sp_out(array('ok' => false, 'error' => $m), $c); }

function sp_pdo()
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

function sp_me()
{
    static $me = false;
    if ($me !== false) return $me;
    $me = null;
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid > 0) {
        try {
            $st = sp_pdo()->prepare("SELECT id, username, display_name, role, position
                                       FROM `app_users` WHERE id = ? AND active = 1 LIMIT 1");
            $st->execute(array($uid));
            $me = $st->fetch() ?: null;
        } catch (Exception $e) { $me = null; }
    }
    return $me;
}
function sp_uid()      { $m = sp_me(); return $m ? (int) $m['id'] : 0; }
function sp_uname()    { $m = sp_me(); return $m ? (string) ($m['display_name'] !== '' ? $m['display_name'] : $m['username']) : ''; }
function sp_is_admin() { $m = sp_me(); return $m && strtolower((string) $m['role']) === 'admin'; }
function sp_login()    { if (!sp_me()) sp_fail('Chưa đăng nhập.', 401); }

function sp_s($v, $max = 255)
{
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $v));
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
}

/** bo dau tieng Viet + ha chu thuong, de tim kiem */
function sp_norm($s)
{
    $s = mb_strtolower(trim((string) $s), 'UTF-8');
    $map = array(
        'a' => 'áàảãạăắằẳẵặâấầẩẫậ',
        'e' => 'éèẻẽẹêếềểễệ',
        'i' => 'íìỉĩị',
        'o' => 'óòỏõọôốồổỗộơớờởỡợ',
        'u' => 'úùủũụưứừửữự',
        'y' => 'ýỳỷỹỵ',
        'd' => 'đ',
    );
    foreach ($map as $to => $froms) {
        $chars = preg_split('//u', $froms, -1, PREG_SPLIT_NO_EMPTY);
        $s = str_replace($chars, $to, $s);
    }
    return preg_replace('/\s+/u', ' ', $s);
}

/* ---------------- danh muc ---------------- */

function sp_kinds()
{
    return array(
        'doctor' => 'Bác sĩ / Chuyên gia',
        'leader' => 'Lãnh đạo công ty',
        'other'  => 'Khách mời khác',
    );
}

/** Goi y linh vuc (nguoi dung van go them duoc) */
function sp_fields_seed()
{
    return array(
        'Nội khoa', 'Ngoại khoa', 'Sản phụ khoa', 'Nhi khoa', 'Ung bướu',
        'Chẩn đoán hình ảnh', 'Xét nghiệm', 'Dược', 'Y học cổ truyền',
        'Y tế dự phòng', 'Dinh dưỡng', 'Thẩm mỹ - Da liễu', 'Răng hàm mặt',
        'Quản lý y tế', 'Khác',
    );
}

/** Goi y chuyen nganh */
function sp_specs_seed()
{
    return array(
        'Tim mạch', 'Nội tiết - Đái tháo đường', 'Hô hấp', 'Tiêu hoá - Gan mật',
        'Thận - Tiết niệu', 'Thần kinh', 'Cơ xương khớp', 'Huyết học',
        'Truyền nhiễm', 'Hồi sức cấp cứu', 'Gây mê hồi sức', 'Da liễu',
        'Mắt', 'Tai mũi họng', 'Tâm thần', 'Lão khoa', 'Sơ sinh',
        'Vô sinh hiếm muộn', 'Ung thư vú', 'Ung thư phổi', 'Miễn dịch - Dị ứng',
        'Phục hồi chức năng', 'Vắc xin', 'Khác',
    );
}

/** Hoc ham hoc vi thuong gap */
function sp_degrees_seed()
{
    return array(
        'GS.TS.BS', 'PGS.TS.BS', 'TS.BS', 'ThS.BS', 'BS.CKII', 'BS.CKI', 'BS',
        'GS.TS', 'PGS.TS', 'TS', 'ThS', 'DS.CKII', 'DS.CKI', 'DS', 'ĐD', 'CN',
    );
}

/* ---------------- bang ---------------- */

function sp_init()
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = sp_pdo();

    $pdo->exec("CREATE TABLE IF NOT EXISTS `sp_speakers` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `code`       VARCHAR(24)  NOT NULL DEFAULT '',
        `kind`       VARCHAR(16)  NOT NULL DEFAULT 'doctor',
        `degree`     VARCHAR(48)  NOT NULL DEFAULT '',
        `full_name`  VARCHAR(160) NOT NULL DEFAULT '',
        `name_norm`  VARCHAR(200) NOT NULL DEFAULT '',
        `title`      VARCHAR(200) NOT NULL DEFAULT '',
        `org`        VARCHAR(200) NOT NULL DEFAULT '',
        `dept`       VARCHAR(160) NOT NULL DEFAULT '',
        `field`      VARCHAR(120) NOT NULL DEFAULT '',
        `spec`       VARCHAR(120) NOT NULL DEFAULT '',
        `photo`      VARCHAR(200) NOT NULL DEFAULT '',
        `thumb`      VARCHAR(200) NOT NULL DEFAULT '',
        `note`       TEXT NULL,
        `active`     TINYINT(1) NOT NULL DEFAULT 1,
        `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
        `created_at` DATETIME NULL,
        `updated_by` INT UNSIGNED NOT NULL DEFAULT 0,
        `updated_at` DATETIME NULL,
        `deleted_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `k_kind` (`kind`),
        KEY `k_field` (`field`),
        KEY `k_spec` (`spec`),
        KEY `k_del` (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function sp_dir()
{
    $d = dirname(__DIR__) . '/uploads/speakers';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

function sp_next_code($pdo)
{
    $n = (int) $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)), 0)
                              FROM `sp_speakers` WHERE code LIKE 'SPK-%'")->fetchColumn();
    return 'SPK-' . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
}

function sp_row($r)
{
    $k = sp_kinds();
    $r['id']         = (int) $r['id'];
    $r['active']     = (int) $r['active'];
    $r['kind_label'] = isset($k[$r['kind']]) ? $k[$r['kind']] : $r['kind'];
    $r['display']    = trim(($r['degree'] !== '' ? $r['degree'] . ' ' : '') . $r['full_name']);
    unset($r['name_norm']);
    return $r;
}

/* ---------------- input ---------------- */

$IN = array();
$raw = file_get_contents('php://input');
if ($raw !== '' && $raw !== false) {
    $j = json_decode($raw, true);
    if (is_array($j)) $IN = $j;
}
if (!$IN && $_POST) $IN = $_POST;
function sp_in($k, $d = '') { global $IN; return isset($IN[$k]) ? $IN[$k] : (isset($_GET[$k]) ? $_GET[$k] : $d); }

$action = (string) (isset($_GET['action']) ? $_GET['action'] : sp_in('action', ''));

try {
    sp_init();
    $pdo = sp_pdo();

    switch ($action) {

        /* ---------- danh muc + goi y ---------- */
        case 'meta': {
            sp_login();
            $fields = sp_fields_seed();
            $specs  = sp_specs_seed();
            $orgs   = array();
            $titles = array();
            foreach ($pdo->query("SELECT DISTINCT field FROM `sp_speakers` WHERE deleted_at IS NULL AND field <> ''") as $x) $fields[] = $x['field'];
            foreach ($pdo->query("SELECT DISTINCT spec  FROM `sp_speakers` WHERE deleted_at IS NULL AND spec  <> ''") as $x) $specs[]  = $x['spec'];
            foreach ($pdo->query("SELECT DISTINCT org   FROM `sp_speakers` WHERE deleted_at IS NULL AND org   <> ''") as $x) $orgs[]   = $x['org'];
            foreach ($pdo->query("SELECT DISTINCT title FROM `sp_speakers` WHERE deleted_at IS NULL AND title <> ''") as $x) $titles[] = $x['title'];
            $u = function ($a) { $a = array_values(array_unique($a)); sort($a, SORT_NATURAL | SORT_FLAG_CASE); return $a; };
            sp_ok(array('data' => array(
                'kinds'   => sp_kinds(),
                'fields'  => $u($fields),
                'specs'   => $u($specs),
                'orgs'    => $u($orgs),
                'titles'  => $u($titles),
                'degrees' => sp_degrees_seed(),
                'me'      => array('id' => sp_uid(), 'name' => sp_uname(), 'admin' => sp_is_admin() ? 1 : 0),
            )));
            break;
        }

        /* ---------- danh sach ---------- */
        case 'list': {
            sp_login();
            $trash = (int) sp_in('trash', 0) === 1;
            $sql = "SELECT s.*,
                           (SELECT COALESCE(u.display_name, u.username) FROM `app_users` u WHERE u.id = s.created_by) AS created_name
                      FROM `sp_speakers` s
                     WHERE s.deleted_at IS " . ($trash ? "NOT NULL" : "NULL") . "
                     ORDER BY s.kind = 'leader' DESC, s.field ASC, s.spec ASC, s.full_name ASC";
            $rows = array();
            foreach ($pdo->query($sql) as $r) $rows[] = sp_row($r);
            sp_ok(array('rows' => $rows));
            break;
        }

        /* ---------- luu ---------- */
        case 'save': {
            sp_login();
            $id   = (int) sp_in('id', 0);
            $name = sp_s(sp_in('full_name'), 160);
            if ($name === '') sp_fail('Chưa nhập tên speaker.');

            $kind = sp_s(sp_in('kind', 'doctor'), 16);
            if (!isset(sp_kinds()[$kind])) $kind = 'doctor';

            $photo = sp_s(sp_in('photo'), 200);
            $thumb = sp_s(sp_in('thumb'), 200);
            foreach (array($photo, $thumb) as $p) {
                if ($p !== '' && !preg_match('#^uploads/speakers/[A-Za-z0-9._-]{1,120}$#', $p)) {
                    sp_fail('Đường dẫn ảnh không hợp lệ.');
                }
            }

            $d = array(
                'kind'      => $kind,
                'degree'    => sp_s(sp_in('degree'), 48),
                'full_name' => $name,
                'name_norm' => sp_norm(sp_s(sp_in('degree'), 48) . ' ' . $name . ' ' . sp_in('org') . ' ' . sp_in('title')),
                'title'     => sp_s(sp_in('title'), 200),
                'org'       => sp_s(sp_in('org'), 200),
                'dept'      => sp_s(sp_in('dept'), 160),
                'field'     => sp_s(sp_in('field'), 120),
                'spec'      => sp_s(sp_in('spec'), 120),
                'photo'     => $photo,
                'thumb'     => $thumb,
                'note'      => sp_s(sp_in('note'), 2000),
                'active'    => ((int) sp_in('active', 1) === 0) ? 0 : 1,
            );

            if ($id > 0) {
                $st = $pdo->prepare("SELECT id FROM `sp_speakers` WHERE id = ? LIMIT 1");
                $st->execute(array($id));
                if (!$st->fetchColumn()) sp_fail('Không tìm thấy speaker.', 404);
                $set = array();
                foreach ($d as $k => $v) $set[] = "`$k` = :$k";
                $set[] = "`updated_by` = :ub";
                $set[] = "`updated_at` = NOW()";
                $q = $pdo->prepare("UPDATE `sp_speakers` SET " . implode(', ', $set) . " WHERE id = :id");
                $d['ub'] = sp_uid(); $d['id'] = $id;
                $q->execute($d);
                sp_ok(array('id' => $id, 'message' => 'Đã lưu ' . $name . '.'));
            } else {
                $d['code']       = sp_next_code($pdo);
                $d['created_by'] = sp_uid();
                $cols = array_keys($d);
                $q = $pdo->prepare("INSERT INTO `sp_speakers` (`" . implode('`, `', $cols) . "`, `created_at`, `updated_at`)
                                    VALUES (:" . implode(', :', $cols) . ", NOW(), NOW())");
                $q->execute($d);
                sp_ok(array('id' => (int) $pdo->lastInsertId(), 'code' => $d['code'], 'message' => 'Đã thêm ' . $name . '.'));
            }
            break;
        }

        /* ---------- xoa / khoi phuc ---------- */
        case 'del': {
            sp_login();
            $id = (int) sp_in('id', 0);
            if (!$id) sp_fail('Thiếu id.');
            $q = $pdo->prepare("UPDATE `sp_speakers` SET deleted_at = NOW(), updated_by = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL");
            $q->execute(array(sp_uid(), $id));
            sp_ok(array('message' => 'Đã chuyển vào thùng rác.'));
            break;
        }
        case 'restore': {
            sp_login();
            $id = (int) sp_in('id', 0);
            if (!$id) sp_fail('Thiếu id.');
            $q = $pdo->prepare("UPDATE `sp_speakers` SET deleted_at = NULL, updated_by = ?, updated_at = NOW() WHERE id = ?");
            $q->execute(array(sp_uid(), $id));
            sp_ok(array('message' => 'Đã khôi phục.'));
            break;
        }

        /* ---------- upload anh (da chuan hoa phia client) ---------- */
        case 'photo': {
            sp_login();
            if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                sp_fail('Không nhận được tệp ảnh.');
            }
            $dir  = sp_dir();
            $out  = array();
            $base = date('Ymd_His') . '_' . bin2hex(random_bytes(4));

            foreach (array('photo' => 800, 'thumb' => 200) as $k => $maxpx) {
                if (empty($_FILES[$k]) || $_FILES[$k]['error'] !== UPLOAD_ERR_OK) continue;
                if ($_FILES[$k]['size'] > 6 * 1024 * 1024) sp_fail('Ảnh quá lớn (tối đa 6MB).');
                $inf = @getimagesize($_FILES[$k]['tmp_name']);
                if (!$inf || !in_array($inf[2], array(IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP), true)) {
                    sp_fail('Tệp không phải ảnh PNG / JPG / WEBP hợp lệ.');
                }
                $ext = ($inf[2] === IMAGETYPE_PNG) ? 'png' : (($inf[2] === IMAGETYPE_WEBP) ? 'webp' : 'jpg');
                $fn  = $base . ($k === 'thumb' ? '_t' : '') . '.' . $ext;
                if (!@move_uploaded_file($_FILES[$k]['tmp_name'], $dir . '/' . $fn)) {
                    sp_fail('Không lưu được ảnh lên máy chủ.');
                }
                @chmod($dir . '/' . $fn, 0644);
                $out[$k] = 'uploads/speakers/' . $fn;
            }
            if (empty($out['photo'])) sp_fail('Không lưu được ảnh.');
            if (empty($out['thumb'])) $out['thumb'] = $out['photo'];
            sp_ok(array('data' => $out));
            break;
        }

        /* ---------- thong ke ---------- */
        case 'summary': {
            sp_login();
            $tot = (int) $pdo->query("SELECT COUNT(*) FROM `sp_speakers` WHERE deleted_at IS NULL")->fetchColumn();
            $doc = (int) $pdo->query("SELECT COUNT(*) FROM `sp_speakers` WHERE deleted_at IS NULL AND kind = 'doctor'")->fetchColumn();
            $led = (int) $pdo->query("SELECT COUNT(*) FROM `sp_speakers` WHERE deleted_at IS NULL AND kind = 'leader'")->fetchColumn();
            $noi = (int) $pdo->query("SELECT COUNT(*) FROM `sp_speakers` WHERE deleted_at IS NULL AND (photo = '' OR photo IS NULL)")->fetchColumn();
            $spn = (int) $pdo->query("SELECT COUNT(DISTINCT spec) FROM `sp_speakers` WHERE deleted_at IS NULL AND spec <> ''")->fetchColumn();
            sp_ok(array('data' => array(
                'total' => $tot, 'doctor' => $doc, 'leader' => $led, 'nophoto' => $noi, 'specs' => $spn,
            )));
            break;
        }

        default:
            sp_fail('Hành động không hợp lệ: ' . $action, 404);
    }
} catch (Throwable $e) {
    sp_fail('Lỗi máy chủ: ' . $e->getMessage(), 500);
}
