<?php
/**
 * APSA — Phan quyen he thong theo VI TRI (co the ghi de theo tung USER)
 * ---------------------------------------------------------------------
 * Muc quyen:  0 = Khong vao duoc | 1 = Chi xem | 2 = Toan quyen
 *
 * Cac module lien quan nhau duoc gom chung 1 NHOM de khong xung dot
 * (vi du: Chi phi thuc te doc du lieu cua Bao gia -> chung nhom "project").
 *
 * Dung trong API:
 *     require_once __DIR__ . '/perm.php';
 *     pm_gate('project', $action, array('list', 'get'));   // chan truoc khi chay
 */

require_once __DIR__ . '/db-config.php';

function pm_pdo()
{
    static $p = null;
    if ($p instanceof PDO) return $p;
    $p = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
    return $p;
}

/** Danh muc nhom module. 'mods' la id trong APSA_NAV, 'pages' la file .html. */
function pm_groups()
{
    return array(
        array(
            'key' => 'project', 'name' => 'Dự án & Báo giá', 'def' => 2,
            'mods' => array(35, 32, 97, 26),
            'pages' => array('assignments.html', 'quotation.html', 'bao-gia.html', 'chi-phi.html', 'ratecard.html'),
            'note' => 'Làm việc · Báo giá & Nghiệm thu · Chi phí thực tế · Rate Card',
            'needs' => array('partner'),
        ),
        array(
            'key' => 'partner', 'name' => 'Khách hàng & Đối tác', 'def' => 2,
            'mods' => array(29, 31, 95),
            'pages' => array('customers.html', 'companies.html', 'suppliers.html'),
            'note' => 'Khách hàng · Công ty · Nhà cung cấp',
            'needs' => array(),
        ),
        array(
            'key' => 'finance', 'name' => 'Công nợ & Hợp đồng', 'def' => 2,
            'mods' => array(30, 96),
            'pages' => array('debts.html', 'debt-detail.html', 'contracts.html'),
            'note' => 'Công nợ · Tủ hợp đồng',
            'needs' => array('partner'),
        ),
        array(
            'key' => 'payroll', 'name' => 'Bảng lương', 'def' => 0,
            'mods' => array(99),
            'pages' => array('luong.html'),
            'note' => 'Lương nhân viên — dữ liệu nhạy cảm, mặc định chỉ Admin',
            'needs' => array(),
        ),
        array(
            'key' => 'media', 'name' => 'Sản xuất & Media', 'def' => 2,
            'mods' => array(98, 34, 23, 17, 18, 25, 24, 100),
            'pages' => array('videos.html', 'review.html', 'albums.html', 'album.html', 'logos.html',
                             'brand-guidelines.html', 'inspiration.html', 'frame.html'),
            'note' => 'Duyệt video · Album gửi khách · Thư viện ảnh · Kho Logos · Brand Guidelines · Inspiration · AI Studio · Frame Avatar',
            'needs' => array(),
        ),
        array(
            'key' => 'hr', 'name' => 'Nhân sự', 'def' => 2,
            'mods' => array(90, 91, 94),
            'pages' => array('accounts.html', 'leave.html', 'policy.html'),
            'note' => 'Accounts nhân viên · Xin nghỉ phép · Policy công ty',
            'needs' => array(),
        ),
        array(
            'key' => 'tools', 'name' => 'Công cụ chung', 'def' => 2,
            'mods' => array(1, 28),
            'pages' => array('event-qr-generator.html', 'qr-tool.html', 'tracking.html'),
            'note' => 'Quản lý Link · Badminton · các công cụ lẻ',
            'needs' => array(),
        ),
        array(
            'key' => 'system', 'name' => 'Hệ thống', 'def' => 0, 'adminOnly' => true,
            'mods' => array(27, 92, 93),
            'pages' => array('users.html', 'settings.html', 'zalo.html'),
            'note' => 'Quản lý User · Cài đặt hệ thống · Thông báo Zalo — chỉ Admin',
            'needs' => array(),
        ),
    );
}

function pm_group($key)
{
    foreach (pm_groups() as $g) if ($g['key'] === $key) return $g;
    return null;
}

/** Tao bang luu quyen (chay 1 lan). */
function pm_init($pdo = null)
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = $pdo ?: pm_pdo();
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `perm_rules` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `scope` VARCHAR(8) NOT NULL,
                `scope_key` VARCHAR(64) NOT NULL,
                `grp` VARCHAR(32) NOT NULL,
                `lvl` TINYINT NOT NULL DEFAULT 0,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_rule` (`scope`, `scope_key`, `grp`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (PDOException $e) { /* da co */ }
}

/** Thong tin user dang dang nhap. */
function pm_me()
{
    static $me = false;
    if ($me !== false) return $me;
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) { $me = null; return $me; }
    try {
        $st = pm_pdo()->prepare("SELECT id, username, display_name, role, position, staff_type, active
                                 FROM `app_users` WHERE id = ? LIMIT 1");
        $st->execute(array($uid));
        $r = $st->fetch();
        $me = ($r && (int) $r['active'] === 1) ? $r : null;
    } catch (PDOException $e) { $me = null; }
    return $me;
}

function pm_is_admin()
{
    $u = pm_me();
    return $u && strtolower((string) $u['role']) === 'admin';
}

/** Tat ca luat dang luu, dang [scope][scope_key][grp] = lvl */
function pm_rules()
{
    static $r = null;
    if ($r !== null) return $r;
    pm_init();
    $r = array('pos' => array(), 'user' => array());
    try {
        foreach (pm_pdo()->query("SELECT scope, scope_key, grp, lvl FROM `perm_rules`") as $x) {
            $r[$x['scope']][$x['scope_key']][$x['grp']] = (int) $x['lvl'];
        }
    } catch (PDOException $e) { /* bo qua */ }
    return $r;
}

/** Muc quyen cua user hien tai voi 1 nhom: 0 / 1 / 2 */
function pm_level($key)
{
    $g = pm_group($key);
    if (!$g) return 0;
    $u = pm_me();
    if (!$u) return 0;
    if (pm_is_admin()) return 2;
    if (!empty($g['adminOnly'])) return 0;

    $rules = pm_rules();
    $uid = (string) $u['id'];
    if (isset($rules['user'][$uid][$key])) return (int) $rules['user'][$uid][$key];

    $pos = strtolower(trim((string) $u['position']));
    if ($pos === '') $pos = '-';
    if (isset($rules['pos'][$pos][$key])) return (int) $rules['pos'][$pos][$key];

    return (int) $g['def'];
}

/** Bang quyen cua user hien tai — dung cho giao dien. */
function pm_my_map()
{
    $out = array();
    foreach (pm_groups() as $g) $out[$g['key']] = pm_level($g['key']);
    return $out;
}

function pm_deny($msg, $code = 403)
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(array('ok' => false, 'error' => $msg), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Chan truy cap. $reads = danh sach action chi doc (duoc phep o muc "Chi xem").
 * Moi action khong nam trong $reads deu bi coi la ghi.
 */
function pm_gate($key, $action = '', $reads = array())
{
    $lvl = pm_level($key);
    $g = pm_group($key);
    $nm = $g ? $g['name'] : $key;
    if ($lvl <= 0) pm_deny('Bạn không có quyền truy cập mục "' . $nm . '". Liên hệ Admin để được cấp quyền.');
    if ($lvl === 1 && $action !== '' && !in_array($action, $reads, true)) {
        pm_deny('Bạn chỉ được xem mục "' . $nm . '", không được thay đổi dữ liệu.');
    }
    return $lvl;
}
