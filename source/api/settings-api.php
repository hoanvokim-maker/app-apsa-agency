<?php
/**
 * APSA - API Cai dat he thong (chi Admin)
 * ------------------------------------------------------------------
 *  Bang:
 *    app_settings         key/value chung (font, quy phep, gio lam, cong ty)
 *    quotation_statuses   trang thai du an  (them / sua / xoa)
 *    leave_types          loai nghi phep
 *    holidays             ngay nghi le
 *
 *  Cac file khac doc lai qua ham:
 *    st_get($key, $default)      - 1 gia tri
 *    st_statuses()               - mang trang thai du an
 *    st_leave_types()            - mang loai nghi
 *    st_holidays()               - mang ngay le  ['Y-m-d' => 'Ten']
 *
 *  Cac ham doc KHONG yeu cau dang nhap Admin (de quotation-api dung duoc),
 *  chi cac action ghi moi bat buoc Admin.
 */

require_once __DIR__ . '/db-config.php';

/* ------------------------------------------------------------------ *
 *  Ket noi + tao bang
 * ------------------------------------------------------------------ */

function st_pdo()
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
    return $pdo;
}

/** Gia tri goc, dung de seed lan dau va lam du phong neu bang loi. */
function st_seed_statuses()
{
    // key, nhan, mau, thu tu hien thi, uu tien sap xep danh sach (0 = khong nam trong ORDER BY FIELD),
    // dang chay, chua ket thuc, khoa (khong cho xoa)
    return array(
        array('request',      'Nhận yêu cầu',            '#FFE066', 1, 7, 0, 1, 1),
        array('quote',        'Báo giá',                 '#FFD23F', 2, 6, 0, 1, 0),
        array('order',        'Đặt hàng',                '#FF9F45', 3, 2, 1, 1, 0),
        array('confirmed',    'Xác nhận báo giá',        '#3BC9FF', 4, 3, 1, 1, 0),
        array('running',      'Đang thực hiện',          '#7C9CFF', 5, 1, 1, 1, 0),
        array('service_done', 'Hoàn thành dịch vụ',      '#FF6BAA', 6, 4, 1, 1, 0),
        array('liq_sent',     'Gửi nghiệm thu',          '#C77DFF', 7, 5, 1, 1, 0),
        array('done',         'Đóng và chờ thanh toán',  '#39FF88', 8, 0, 0, 0, 0),
        array('lost',         'Trượt Bidding',           '#FF4D6D', 9, 0, 0, 0, 0),
    );
}

function st_seed_leave_types()
{
    return array(
        array('annual', 'Phép năm',         1, 1),
        array('unpaid', 'Nghỉ không lương', 0, 2),
        array('sick',   'Nghỉ ốm',          0, 3),
        array('other',  'Khác',             0, 4),
    );
}

function st_defaults()
{
    return array(
        'leave.default_quota' => '14',
        'leave.work_hours'    => '{"am_start":"08:30","am_end":"12:00","pm_start":"13:30","pm_end":"17:30"}',
        // Ngay lam viec trong tuan theo ISO: 1=T2 ... 6=T7, 7=CN. APSA lam ca thu 7.
        'leave.work_days'     => '[1,2,3,4,5,6]',
        'ui.font_sizes'       => '{"default":12.5,"large":14,"max":15}',
        'company.info'        => '{"name":"APSA Agency","tax":"0317301221","address":"26 Ung Văn Khiêm, Phường Thạnh Mỹ Tây, TP Hồ Chí Minh, Việt Nam","email":"hello@apsa.agency","phone":"","bank":""}',
    );
}

function st_boot()
{
    $stamp = sys_get_temp_dir() . '/apsa_settings_schema_' . filemtime(__FILE__) . '.ok';
    if (is_file($stamp)) return;

    $pdo = st_pdo();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS app_settings (
            skey       VARCHAR(64) NOT NULL PRIMARY KEY,
            sval       TEXT NULL,
            updated_at DATETIME NOT NULL,
            updated_by VARCHAR(190) NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS quotation_statuses (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            skey       VARCHAR(40) NOT NULL,
            label      VARCHAR(120) NOT NULL,
            color      VARCHAR(9) NOT NULL DEFAULT '#FFE066',
            sort       INT NOT NULL DEFAULT 50,
            prio       INT NOT NULL DEFAULT 0,
            is_active  TINYINT(1) NOT NULL DEFAULT 0,
            is_open    TINYINT(1) NOT NULL DEFAULT 1,
            locked     TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_skey (skey)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS leave_types (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            tkey         VARCHAR(40) NOT NULL,
            label        VARCHAR(120) NOT NULL,
            deduct_quota TINYINT(1) NOT NULL DEFAULT 0,
            sort         INT NOT NULL DEFAULT 50,
            active       TINYINT(1) NOT NULL DEFAULT 1,
            created_at   DATETIME NOT NULL,
            updated_at   DATETIME NOT NULL,
            UNIQUE KEY uq_tkey (tkey)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS holidays (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            hdate      DATE NOT NULL,
            name       VARCHAR(160) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_hdate (hdate)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $now = date('Y-m-d H:i:s');

    // Seed cai dat chung (chi them cai chua co)
    $ins = $pdo->prepare('INSERT IGNORE INTO app_settings (skey, sval, updated_at) VALUES (?,?,?)');
    foreach (st_defaults() as $k => $v) $ins->execute(array($k, $v, $now));

    // Seed trang thai du an
    if ((int) $pdo->query('SELECT COUNT(*) FROM quotation_statuses')->fetchColumn() === 0) {
        $q = $pdo->prepare(
            'INSERT INTO quotation_statuses (skey,label,color,sort,prio,is_active,is_open,locked,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        foreach (st_seed_statuses() as $s) {
            $q->execute(array($s[0], $s[1], $s[2], $s[3], $s[4], $s[5], $s[6], $s[7], $now, $now));
        }
    }

    // Seed loai nghi
    if ((int) $pdo->query('SELECT COUNT(*) FROM leave_types')->fetchColumn() === 0) {
        $q = $pdo->prepare('INSERT INTO leave_types (tkey,label,deduct_quota,sort,active,created_at,updated_at) VALUES (?,?,?,?,1,?,?)');
        foreach (st_seed_leave_types() as $t) $q->execute(array($t[0], $t[1], $t[2], $t[3], $now, $now));
    }

    @file_put_contents($stamp, '1');
}

/* ------------------------------------------------------------------ *
 *  Doc (dung chung cho cac file khac)
 * ------------------------------------------------------------------ */

function st_all()
{
    static $cache = null;
    if ($cache !== null) return $cache;
    st_boot();
    $cache = st_defaults();
    try {
        foreach (st_pdo()->query('SELECT skey, sval FROM app_settings') as $r) {
            $cache[$r['skey']] = $r['sval'];
        }
    } catch (Exception $e) { /* dung mac dinh */ }
    return $cache;
}

function st_get($key, $default = null)
{
    $a = st_all();
    return array_key_exists($key, $a) ? $a[$key] : $default;
}

function st_json($key, $default = array())
{
    $v = st_get($key, null);
    if ($v === null) return $default;
    $j = json_decode($v, true);
    return is_array($j) ? array_merge($default, $j) : $default;
}

/** Danh sach trang thai du an, da sap xep theo sort. */
function st_statuses()
{
    static $cache = null;
    if ($cache !== null) return $cache;
    st_boot();
    $cache = array();
    try {
        foreach (st_pdo()->query('SELECT * FROM quotation_statuses ORDER BY sort, id') as $r) {
            $cache[] = array(
                'id'        => (int) $r['id'],
                'key'       => $r['skey'],
                'label'     => $r['label'],
                'color'     => $r['color'],
                'sort'      => (int) $r['sort'],
                'prio'      => (int) $r['prio'],
                'is_active' => (int) $r['is_active'],
                'is_open'   => (int) $r['is_open'],
                'locked'    => (int) $r['locked'],
            );
        }
    } catch (Exception $e) { $cache = array(); }

    if (!$cache) {  // du phong: dung danh sach goc
        foreach (st_seed_statuses() as $s) {
            $cache[] = array('key'=>$s[0],'label'=>$s[1],'color'=>$s[2],'sort'=>$s[3],
                             'prio'=>$s[4],'is_active'=>$s[5],'is_open'=>$s[6],'locked'=>$s[7]);
        }
    }
    return $cache;
}

/** ['key' => 'Nhan'] theo dung thu tu. */
function st_status_labels()
{
    $out = array();
    foreach (st_statuses() as $s) $out[$s['key']] = $s['label'];
    return $out;
}

function st_status_keys($flag)
{
    $out = array();
    foreach (st_statuses() as $s) if (!empty($s[$flag])) $out[] = $s['key'];
    return $out;
}

/** Danh sach key cho ORDER BY FIELD, xep theo prio tang dan (bo prio = 0). */
function st_status_prio_keys()
{
    $rows = array();
    foreach (st_statuses() as $s) if ($s['prio'] > 0) $rows[] = $s;
    usort($rows, function ($a, $b) { return $a['prio'] - $b['prio']; });
    $out = array();
    foreach ($rows as $r) $out[] = $r['key'];
    return $out;
}

function st_leave_types()
{
    static $cache = null;
    if ($cache !== null) return $cache;
    st_boot();
    $cache = array();
    try {
        foreach (st_pdo()->query('SELECT * FROM leave_types WHERE active = 1 ORDER BY sort, id') as $r) {
            $cache[] = array(
                'key'    => $r['tkey'],
                'label'  => $r['label'],
                'deduct' => (int) $r['deduct_quota'],
            );
        }
    } catch (Exception $e) { $cache = array(); }
    if (!$cache) {
        foreach (st_seed_leave_types() as $t) $cache[] = array('key'=>$t[0],'label'=>$t[1],'deduct'=>$t[2]);
    }
    return $cache;
}

/** Ngay lam viec trong tuan, dang [1..7] voi 1=T2, 7=CN. */
function st_work_days()
{
    $v = st_get('leave.work_days', '[1,2,3,4,5,6]');
    $a = json_decode((string) $v, true);
    $out = array();
    if (is_array($a)) {
        foreach ($a as $d) { $d = (int) $d; if ($d >= 1 && $d <= 7) $out[$d] = true; }
    }
    if (!$out) $out = array(1=>true,2=>true,3=>true,4=>true,5=>true,6=>true);
    return $out;
}

/** ['Y-m-d' => 'Ten ngay le'] */
function st_holidays()
{
    static $cache = null;
    if ($cache !== null) return $cache;
    st_boot();
    $cache = array();
    try {
        foreach (st_pdo()->query('SELECT hdate, name FROM holidays ORDER BY hdate') as $r) {
            $cache[$r['hdate']] = $r['name'];
        }
    } catch (Exception $e) { $cache = array(); }
    return $cache;
}

/* ================================================================== *
 *  Phan API - chi chay khi goi truc tiep file nay
 * ================================================================== */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== basename(__FILE__)) return;

require_once __DIR__ . '/session-boot.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function s_out($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function s_fail($m, $code = 400) { s_out(array('ok' => false, 'error' => $m), $code); }
function s_body() { $r = file_get_contents('php://input'); $j = $r ? json_decode($r, true) : null; return is_array($j) ? $j : array(); }

function s_me()
{
    static $me = null;
    if ($me !== null) return $me;
    $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if ($uid <= 0) s_fail('Chưa đăng nhập.', 401);
    $st = st_pdo()->prepare('SELECT id, username, display_name, role, active FROM app_users WHERE id = ? LIMIT 1');
    $st->execute(array($uid));
    $u = $st->fetch();
    if (!$u || (int) $u['active'] === 0) s_fail('Chưa đăng nhập.', 401);
    $me = array(
        'id'    => (int) $u['id'],
        'name'  => trim($u['display_name'] !== '' ? $u['display_name'] : $u['username']),
        'admin' => (strcasecmp((string) $u['role'], 'admin') === 0),
    );
    return $me;
}

function s_admin()
{
    $me = s_me();
    if (!$me['admin']) s_fail('Trang này chỉ dành cho Admin.', 403);
    return $me;
}

function s_put($key, $val)
{
    $me = s_me();
    $st = st_pdo()->prepare(
        'INSERT INTO app_settings (skey, sval, updated_at, updated_by) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE sval = VALUES(sval), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)'
    );
    $st->execute(array($key, $val, date('Y-m-d H:i:s'), $me['name']));
}

/** Tao key an toan tu nhan tieng Viet: "Chờ khách duyệt" -> "cho_khach_duyet" */
function s_slug($s)
{
    $map = array('à','á','ạ','ả','ã','â','ầ','ấ','ậ','ẩ','ẫ','ă','ằ','ắ','ặ','ẳ','ẵ',
                 'è','é','ẹ','ẻ','ẽ','ê','ề','ế','ệ','ể','ễ',
                 'ì','í','ị','ỉ','ĩ','ò','ó','ọ','ỏ','õ','ô','ồ','ố','ộ','ổ','ỗ','ơ','ờ','ớ','ợ','ở','ỡ',
                 'ù','ú','ụ','ủ','ũ','ư','ừ','ứ','ự','ử','ữ','ỳ','ý','ỵ','ỷ','ỹ','đ');
    $rep = array('a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a',
                 'e','e','e','e','e','e','e','e','e','e','e',
                 'i','i','i','i','i','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
                 'u','u','u','u','u','u','u','u','u','u','u','y','y','y','y','y','d');
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = str_replace($map, $rep, $s);
    $s = preg_replace('/[^a-z0-9]+/u', '_', $s);
    $s = trim($s, '_');
    if ($s === '') $s = 'tt';
    return mb_substr($s, 0, 30, 'UTF-8');
}

function s_color_ok($c) { return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $c); }

/* ------------------------------------------------------------------ */

st_boot();
$action = isset($_GET['action']) ? $_GET['action'] : '';
$B      = s_body();
$now    = date('Y-m-d H:i:s');

switch ($action) {

/* ---- Doc: cho MOI nguoi dung dang nhap (quotation.html can) ---- */
case 'public':
    s_me();   // phai dang nhap
    $labels = array(); $colors = array();
    foreach (st_statuses() as $s) { $labels[$s['key']] = $s['label']; $colors[$s['key']] = $s['color']; }
    s_out(array(
        'ok'          => true,
        'labels'      => $labels,
        'colors'      => $colors,
        'order'       => array_keys($labels),
        'active'      => st_status_keys('is_active'),
        'open'        => st_status_keys('is_open'),
        'leave_types' => st_leave_types(),
        'holidays'    => st_holidays(),
        'work_days'   => array_map('intval', array_keys(st_work_days())),
        'font_sizes'  => st_json('ui.font_sizes', array('default' => 12.5, 'large' => 14, 'max' => 15)),
        'company'     => st_json('company.info', array()),
    ));
    break;

/* ---- Toan bo cai dat cho trang Settings (Admin) ---- */
case 'all':
    s_admin();
    $counts = array();
    try {
        foreach (st_pdo()->query('SELECT status, COUNT(*) n FROM quotations WHERE (is_deleted IS NULL OR is_deleted = 0) GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
    } catch (Exception $e) {
        try {
            foreach (st_pdo()->query('SELECT status, COUNT(*) n FROM quotations GROUP BY status') as $r) {
                $counts[$r['status']] = (int) $r['n'];
            }
        } catch (Exception $e2) {}
    }

    $hol = array();
    foreach (st_pdo()->query('SELECT id, hdate, name FROM holidays ORDER BY hdate') as $r) $hol[] = $r;

    $lt = array();
    foreach (st_pdo()->query('SELECT id, tkey, label, deduct_quota, sort, active FROM leave_types ORDER BY sort, id') as $r) $lt[] = $r;

    s_out(array(
        'ok'            => true,
        'statuses'      => st_statuses(),
        'status_counts' => $counts,
        'leave_types'   => $lt,
        'holidays'      => $hol,
        'default_quota' => (float) st_get('leave.default_quota', 14),
        'work_days'     => array_map('intval', array_keys(st_work_days())),
        'work_hours'    => st_json('leave.work_hours', array('am_start'=>'08:30','am_end'=>'12:00','pm_start'=>'13:30','pm_end'=>'17:30')),
        'font_sizes'    => st_json('ui.font_sizes', array('default'=>12.5,'large'=>14,'max'=>15)),
        'company'       => st_json('company.info', array('name'=>'','tax'=>'','address'=>'','email'=>'','phone'=>'','bank'=>'')),
    ));
    break;

/* ---- Luu nhom cai dat chung ---- */
case 'save-general':
    s_admin();

    if (isset($B['default_quota'])) {
        $q = (float) $B['default_quota'];
        if ($q < 0 || $q > 365) s_fail('Số ngày phép mặc định không hợp lệ.');
        s_put('leave.default_quota', (string) (round($q * 2) / 2));
    }

    if (isset($B['font_sizes']) && is_array($B['font_sizes'])) {
        $f = $B['font_sizes'];
        $d = (float) (isset($f['default']) ? $f['default'] : 12.5);
        $l = (float) (isset($f['large'])   ? $f['large']   : 14);
        $m = (float) (isset($f['max'])     ? $f['max']     : 15);
        foreach (array($d, $l, $m) as $v) {
            if ($v < 9 || $v > 28) s_fail('Cỡ chữ phải nằm trong khoảng 9–28px.');
        }
        if (!($d <= $l && $l <= $m)) s_fail('Cỡ chữ phải tăng dần: Mặc định ≤ To ≤ Tối đa.');
        s_put('ui.font_sizes', json_encode(array('default'=>$d,'large'=>$l,'max'=>$m)));
    }

    if (isset($B['work_hours']) && is_array($B['work_hours'])) {
        $w = $B['work_hours'];
        $out = array();
        foreach (array('am_start','am_end','pm_start','pm_end') as $k) {
            $v = isset($w[$k]) ? trim($w[$k]) : '';
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) s_fail('Giờ làm việc phải theo dạng HH:MM (24 giờ).');
            $out[$k] = $v;
        }
        if (!($out['am_start'] < $out['am_end'] && $out['am_end'] <= $out['pm_start'] && $out['pm_start'] < $out['pm_end'])) {
            s_fail('Giờ làm việc chưa hợp lý — sáng phải trước chiều.');
        }
        s_put('leave.work_hours', json_encode($out));
    }

    if (isset($B['work_days']) && is_array($B['work_days'])) {
        $d = array();
        foreach ($B['work_days'] as $v) { $v = (int) $v; if ($v >= 1 && $v <= 7) $d[$v] = true; }
        if (!$d) s_fail('Phải chọn ít nhất một ngày làm việc trong tuần.');
        $d = array_map('intval', array_keys($d));
        sort($d);
        s_put('leave.work_days', json_encode($d));
    }

    if (isset($B['company']) && is_array($B['company'])) {
        $c = array();
        foreach (array('name','tax','address','email','phone','bank') as $k) {
            $c[$k] = isset($B['company'][$k]) ? mb_substr(trim($B['company'][$k]), 0, 255, 'UTF-8') : '';
        }
        s_put('company.info', json_encode($c, JSON_UNESCAPED_UNICODE));
    }

    s_out(array('ok' => true, 'message' => 'Đã lưu cài đặt.'));
    break;

/* ---- Trang thai du an: them / sua ---- */
case 'status-save':
    s_admin();
    $id    = isset($B['id']) ? (int) $B['id'] : 0;
    $label = isset($B['label']) ? trim($B['label']) : '';
    $color = isset($B['color']) ? trim($B['color']) : '#FFE066';
    $act   = !empty($B['is_active']) ? 1 : 0;
    $open  = !empty($B['is_open'])   ? 1 : 0;

    if ($label === '') s_fail('Nhập tên trạng thái.');
    if (mb_strlen($label, 'UTF-8') > 60) s_fail('Tên trạng thái quá dài (tối đa 60 ký tự).');
    if (!s_color_ok($color)) s_fail('Mã màu không hợp lệ (cần dạng #RRGGBB).');

    if ($id > 0) {
        $st = st_pdo()->prepare('SELECT * FROM quotation_statuses WHERE id = ?');
        $st->execute(array($id));
        $r = $st->fetch();
        if (!$r) s_fail('Không tìm thấy trạng thái.', 404);

        $u = st_pdo()->prepare('UPDATE quotation_statuses SET label=?, color=?, is_active=?, is_open=?, updated_at=? WHERE id=?');
        $u->execute(array($label, $color, $act, $open, $now, $id));
        s_out(array('ok' => true, 'message' => 'Đã lưu trạng thái “' . $label . '”.'));
    }

    // Them moi
    $base = s_slug($label);
    $key  = $base; $i = 2;
    $chk  = st_pdo()->prepare('SELECT COUNT(*) FROM quotation_statuses WHERE skey = ?');
    while (true) {
        $chk->execute(array($key));
        if ((int) $chk->fetchColumn() === 0) break;
        $key = $base . '_' . $i; $i++;
        if ($i > 50) s_fail('Không tạo được mã cho trạng thái này.');
    }
    $maxSort = (int) st_pdo()->query('SELECT COALESCE(MAX(sort),0) FROM quotation_statuses')->fetchColumn();

    $ins = st_pdo()->prepare(
        'INSERT INTO quotation_statuses (skey,label,color,sort,prio,is_active,is_open,locked,created_at,updated_at)
         VALUES (?,?,?,?,0,?,?,0,?,?)'
    );
    $ins->execute(array($key, $label, $color, $maxSort + 1, $act, $open, $now, $now));
    s_out(array('ok' => true, 'message' => 'Đã thêm trạng thái “' . $label . '”.', 'key' => $key));
    break;

/* ---- Trang thai du an: doi thu tu ---- */
case 'status-order':
    s_admin();
    $ids = isset($B['ids']) && is_array($B['ids']) ? $B['ids'] : array();
    if (!$ids) s_fail('Thiếu thứ tự.');
    $u = st_pdo()->prepare('UPDATE quotation_statuses SET sort = ?, updated_at = ? WHERE id = ?');
    $n = 1;
    foreach ($ids as $id) { $u->execute(array($n, $now, (int) $id)); $n++; }
    s_out(array('ok' => true, 'message' => 'Đã lưu thứ tự.'));
    break;

/* ---- Trang thai du an: dem so du an dang dung ---- */
case 'status-usage':
    s_admin();
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $st = st_pdo()->prepare('SELECT skey, label, locked FROM quotation_statuses WHERE id = ?');
    $st->execute(array($id));
    $r = $st->fetch();
    if (!$r) s_fail('Không tìm thấy trạng thái.', 404);

    $c = st_pdo()->prepare('SELECT COUNT(*) FROM quotations WHERE status = ?');
    $c->execute(array($r['skey']));
    s_out(array('ok' => true, 'key' => $r['skey'], 'label' => $r['label'],
                'locked' => (int) $r['locked'], 'count' => (int) $c->fetchColumn()));
    break;

/* ---- Trang thai du an: xoa (co chuyen du an sang trang thai khac) ---- */
case 'status-delete':
    s_admin();
    $id   = isset($B['id']) ? (int) $B['id'] : 0;
    $moveTo = isset($B['move_to']) ? trim($B['move_to']) : '';

    $st = st_pdo()->prepare('SELECT * FROM quotation_statuses WHERE id = ?');
    $st->execute(array($id));
    $r = $st->fetch();
    if (!$r) s_fail('Không tìm thấy trạng thái.', 404);
    if ((int) $r['locked'] === 1) s_fail('“' . $r['label'] . '” là trạng thái mặc định của hệ thống, không xoá được.');
    if ((int) st_pdo()->query('SELECT COUNT(*) FROM quotation_statuses')->fetchColumn() <= 1) {
        s_fail('Phải còn ít nhất một trạng thái.');
    }

    $c = st_pdo()->prepare('SELECT COUNT(*) FROM quotations WHERE status = ?');
    $c->execute(array($r['skey']));
    $used = (int) $c->fetchColumn();

    $moved = 0;
    if ($used > 0) {
        if ($moveTo === '') s_fail('Còn ' . $used . ' dự án đang ở trạng thái này — chọn trạng thái để chuyển sang.');
        if ($moveTo === $r['skey']) s_fail('Không thể chuyển sang chính trạng thái đang xoá.');
        $chk = st_pdo()->prepare('SELECT COUNT(*) FROM quotation_statuses WHERE skey = ?');
        $chk->execute(array($moveTo));
        if ((int) $chk->fetchColumn() === 0) s_fail('Trạng thái đích không tồn tại.');

        $mv = st_pdo()->prepare('UPDATE quotations SET status = ? WHERE status = ?');
        $mv->execute(array($moveTo, $r['skey']));
        $moved = $mv->rowCount();
    }

    $d = st_pdo()->prepare('DELETE FROM quotation_statuses WHERE id = ?');
    $d->execute(array($id));

    s_out(array(
        'ok'      => true,
        'moved'   => $moved,
        'message' => 'Đã xoá “' . $r['label'] . '”'
                   . ($moved > 0 ? ' và chuyển ' . $moved . ' dự án sang trạng thái mới.' : '.'),
    ));
    break;

/* ---- Loai nghi phep ---- */
case 'ltype-save':
    s_admin();
    $id     = isset($B['id']) ? (int) $B['id'] : 0;
    $label  = isset($B['label']) ? trim($B['label']) : '';
    $deduct = !empty($B['deduct_quota']) ? 1 : 0;
    if ($label === '') s_fail('Nhập tên loại nghỉ.');

    if ($id > 0) {
        $u = st_pdo()->prepare('UPDATE leave_types SET label=?, deduct_quota=?, updated_at=? WHERE id=?');
        $u->execute(array($label, $deduct, $now, $id));
        s_out(array('ok' => true, 'message' => 'Đã lưu loại nghỉ.'));
    }

    $base = s_slug($label); $key = $base; $i = 2;
    $chk = st_pdo()->prepare('SELECT COUNT(*) FROM leave_types WHERE tkey = ?');
    while (true) {
        $chk->execute(array($key));
        if ((int) $chk->fetchColumn() === 0) break;
        $key = $base . '_' . $i; $i++;
        if ($i > 50) s_fail('Không tạo được mã cho loại nghỉ này.');
    }
    $maxSort = (int) st_pdo()->query('SELECT COALESCE(MAX(sort),0) FROM leave_types')->fetchColumn();
    $ins = st_pdo()->prepare('INSERT INTO leave_types (tkey,label,deduct_quota,sort,active,created_at,updated_at) VALUES (?,?,?,?,1,?,?)');
    $ins->execute(array($key, $label, $deduct, $maxSort + 1, $now, $now));
    s_out(array('ok' => true, 'message' => 'Đã thêm loại nghỉ “' . $label . '”.'));
    break;

case 'ltype-delete':
    s_admin();
    $id = isset($B['id']) ? (int) $B['id'] : 0;
    $st = st_pdo()->prepare('SELECT tkey, label FROM leave_types WHERE id = ?');
    $st->execute(array($id));
    $r = $st->fetch();
    if (!$r) s_fail('Không tìm thấy loại nghỉ.', 404);

    $c = st_pdo()->prepare('SELECT COUNT(*) FROM leave_requests WHERE leave_type = ?');
    $c->execute(array($r['tkey']));
    $used = (int) $c->fetchColumn();
    if ($used > 0) {
        // Con don dang dung -> chi tat, khong xoa, de lich su van doc duoc nhan
        $u = st_pdo()->prepare('UPDATE leave_types SET active = 0, updated_at = ? WHERE id = ?');
        $u->execute(array($now, $id));
        s_out(array('ok' => true, 'message' => 'Còn ' . $used . ' đơn đang dùng loại này nên tôi chỉ ẩn đi — đơn cũ vẫn hiển thị đúng tên.'));
    }
    st_pdo()->prepare('DELETE FROM leave_types WHERE id = ?')->execute(array($id));
    s_out(array('ok' => true, 'message' => 'Đã xoá loại nghỉ “' . $r['label'] . '”.'));
    break;

/* ---- Ngay nghi le ---- */
case 'holiday-save':
    s_admin();
    $date = isset($B['hdate']) ? trim($B['hdate']) : '';
    $name = isset($B['name']) ? trim($B['name']) : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) s_fail('Ngày không hợp lệ.');
    if ($name === '') s_fail('Nhập tên ngày lễ.');
    $ins = st_pdo()->prepare(
        'INSERT INTO holidays (hdate, name, created_at) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE name = VALUES(name)'
    );
    $ins->execute(array($date, mb_substr($name, 0, 160, 'UTF-8'), $now));
    s_out(array('ok' => true, 'message' => 'Đã lưu ngày lễ.'));
    break;

case 'holiday-delete':
    s_admin();
    $id = isset($B['id']) ? (int) $B['id'] : 0;
    st_pdo()->prepare('DELETE FROM holidays WHERE id = ?')->execute(array($id));
    s_out(array('ok' => true, 'message' => 'Đã xoá ngày lễ.'));
    break;

default:
    s_fail('Action không hợp lệ.', 404);
}
