<?php
/**
 * APSA — Bảng lương (chỉ Admin)
 *
 *  Danh mục NH : banks-import · banks-count · banks-find
 *  Bảng lương  : runs · run · run-create · run-del · item-save · item-del
 *  Thanh toán  : pay-req · paid · unpaid · proof · proof-del
 */
require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/session-boot.php';
require_once __DIR__ . '/zalo.php';

define('PR_DIR', dirname(__DIR__) . '/uploads/uy-nhiem-chi');
define('PR_MAX', 12 * 1024 * 1024);

function pr_ok($d = array())   { header('Content-Type: application/json; charset=utf-8'); echo json_encode(array('ok' => true, 'data' => $d), JSON_UNESCAPED_UNICODE); exit; }
function pr_fail($m, $c = 400) { header('Content-Type: application/json; charset=utf-8'); http_response_code($c); echo json_encode(array('ok' => false, 'error' => $m), JSON_UNESCAPED_UNICODE); exit; }
function pr_s($v, $n = 255)    { return mb_substr(trim((string) $v), 0, $n); }
function pr_money($n)          { return number_format((float) $n, 0, ',', '.') . ' đ'; }
function pr_digits($v)         { return preg_replace('/[^0-9A-Za-z]/', '', (string) $v); }

/** Bỏ dấu tiếng Việt, còn chữ thường + số. */
function pr_nrm($s) {
    $s = mb_strtolower(trim((string) $s), 'UTF-8');
    $map = array('à','á','ạ','ả','ã','â','ầ','ấ','ậ','ẩ','ẫ','ă','ằ','ắ','ặ','ẳ','ẵ','è','é','ẹ','ẻ','ẽ','ê','ề','ế','ệ','ể','ễ',
        'ì','í','ị','ỉ','ĩ','ò','ó','ọ','ỏ','õ','ô','ồ','ố','ộ','ổ','ỗ','ơ','ờ','ớ','ợ','ở','ỡ',
        'ù','ú','ụ','ủ','ũ','ư','ừ','ứ','ự','ử','ữ','ỳ','ý','ỵ','ỷ','ỹ','đ');
    $rep = array('a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','e','e','e','e','e','e','e','e','e','e','e',
        'i','i','i','i','i','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
        'u','u','u','u','u','u','u','u','u','u','u','y','y','y','y','y','d');
    $s = str_replace($map, $rep, $s);
    return preg_replace('/[^a-z0-9 ]/', ' ', $s);
}
/** Tập hợp các từ trong tên, để so khớp không phụ thuộc thứ tự. */
function pr_words($s) {
    $w = preg_split('/\s+/', trim(pr_nrm($s)));
    $w = array_values(array_filter($w, function ($x) { return $x !== ''; }));
    sort($w);
    return $w;
}

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
} catch (PDOException $e) { pr_fail('DB connection failed', 500); }

$pdo->exec("CREATE TABLE IF NOT EXISTS `bank_codes` (
  `code` VARCHAR(12) NOT NULL,
  `name` VARCHAR(200) NOT NULL DEFAULT '',
  `province` VARCHAR(120) NOT NULL DEFAULT '',
  `src` VARCHAR(8) NOT NULL DEFAULT '',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`), KEY `k_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_runs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period` CHAR(7) NOT NULL DEFAULT '',
  `title` VARCHAR(200) NOT NULL DEFAULT '',
  `note` VARCHAR(500) DEFAULT NULL,
  `content` VARCHAR(200) NOT NULL DEFAULT '',
  `total` BIGINT NOT NULL DEFAULT 0,
  `n_items` INT NOT NULL DEFAULT 0,
  `created_by` VARCHAR(120) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `k_period` (`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` INT UNSIGNED NOT NULL,
  `stt` INT NOT NULL DEFAULT 0,
  `name` VARCHAR(200) NOT NULL DEFAULT '',
  `bank_code` VARCHAR(12) NOT NULL DEFAULT '',
  `bank_name` VARCHAR(200) NOT NULL DEFAULT '',
  `bank_short` VARCHAR(60) NOT NULL DEFAULT '',
  `account` VARCHAR(50) NOT NULL DEFAULT '',
  `card_no` VARCHAR(50) DEFAULT NULL,
  `amount` BIGINT NOT NULL DEFAULT 0,
  `content` VARCHAR(200) NOT NULL DEFAULT '',
  `user_id` INT NOT NULL DEFAULT 0,
  `paid` TINYINT(1) NOT NULL DEFAULT 0,
  `pay_req_at` DATETIME NULL DEFAULT NULL,
  `pay_req_by` VARCHAR(120) NULL DEFAULT NULL,
  `paid_at` DATETIME NULL DEFAULT NULL,
  `paid_by` VARCHAR(120) NULL DEFAULT NULL,
  `proof_file` VARCHAR(200) NULL DEFAULT NULL,
  `proof_name` VARCHAR(200) NULL DEFAULT NULL,
  `proof_mime` VARCHAR(80) NULL DEFAULT NULL,
  PRIMARY KEY (`id`), KEY `k_run` (`run_id`, `stt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── chỉ Admin ─────────────────────────────────────────── */
function pr_me(PDO $pdo) {
    if (empty($_SESSION['user_id'])) return null;
    try {
        $st = $pdo->prepare("SELECT id, username, display_name, role, position FROM `app_users` WHERE id = ? AND active = 1");
        $st->execute(array($_SESSION['user_id']));
        return $st->fetch() ?: null;
    } catch (PDOException $e) { return null; }
}
$ME = pr_me($pdo);
if (!$ME) pr_fail('Bạn cần đăng nhập.', 401);
$isAdmin = strcasecmp((string) $ME['role'], 'admin') === 0 || strcasecmp((string) $ME['position'], 'admin') === 0;
if (!$isAdmin) pr_fail('Bảng lương chỉ dành cho Admin.', 403);
$MENAME = $ME['display_name'] ?: $ME['username'];

/* ── tên ngân hàng ngắn gọn để dựng mã QR ─────────────── */
function pr_short($full) {
    $n = pr_nrm($full);
    $tbl = array(
        'ACB' => array('acb'), 'Vietcombank' => array('vcb', 'ngoai thuong'), 'VietinBank' => array('cong thuong', 'vietin'),
        'BIDV' => array('bidv', 'dau tu va phat trien'), 'Agribank' => array('nong nghiep', 'agribank'),
        'Techcombank' => array('ky thuong', 'techcom'), 'VPBank' => array('vp bank', 'viet nam thinh vuong', 'vpbank'),
        'TPBank' => array('tien phong'), 'Sacombank' => array('sai gon thuong tin', 'sacom'),
        'HDBank' => array('phat trien tp hcm', 'hdbank', 'hd bank'), 'MB Bank' => array('quan doi', 'mb bank', 'mbbank'),
        'VIB' => array('quoc te', 'vib'), 'SHB' => array('sai gon ha noi', 'shb'), 'Eximbank' => array('xuat nhap khau', 'exim'),
        'MSB' => array('hang hai', 'msb'), 'OCB' => array('phuong dong', 'ocb'), 'SCB' => array('sai gon', 'scb'),
        'SeABank' => array('dong nam a', 'seabank'), 'ABBANK' => array('an binh'), 'BacABank' => array('bac a'),
        'NCB' => array('quoc dan', 'ncb'), 'VietABank' => array('viet a'), 'VietBank' => array('viet nam thuong tin', 'vietbank'),
        'BVBank' => array('ban viet', 'bvbank'), 'KienLongBank' => array('kien long'), 'BaoVietBank' => array('bao viet'),
        'PGBank' => array('xang dau', 'pg bank'), 'PVcomBank' => array('dai chung', 'pvcom'),
        'SaigonBank' => array('sai gon cong thuong', 'saigonbank'), 'NamABank' => array('nam a'),
        'COOPBANK' => array('hop tac xa', 'coop'), 'ShinhanBank' => array('shinhan'), 'Woori' => array('woori'),
        'UOB' => array('uob'), 'HongLeong' => array('hong leong'), 'IVB' => array('indovina'), 'VRB' => array('viet nga'),
        'CBBank' => array('xay dung', 'cb bank'), 'Oceanbank' => array('dai duong', 'ocean'),
    );
    foreach ($tbl as $short => $keys)
        foreach ($keys as $k) if (strpos($n, $k) !== false) return $short;
    return '';
}

$ACT = isset($_GET['action']) ? (string) $_GET['action'] : '';
$B   = json_decode(file_get_contents('php://input'), true);
if (!is_array($B)) $B = array();

switch ($ACT) {

/* ═══════════ DANH MỤC MÃ NGÂN HÀNG ═══════════ */
case 'banks-count': {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM `bank_codes`")->fetchColumn();
    $up = $pdo->query("SELECT MAX(updated_at) FROM `bank_codes`")->fetchColumn();
    pr_ok(array('n' => $n, 'updated_at' => $up));
}

case 'banks-import': {
    $rows = isset($B['rows']) && is_array($B['rows']) ? $B['rows'] : array();
    if (!$rows) pr_fail('Không có dòng nào để nhập.');
    $st = $pdo->prepare("INSERT INTO `bank_codes` (code, name, province, src) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE name = VALUES(name), province = VALUES(province), src = VALUES(src)");
    $n = 0;
    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $code = pr_digits($r['code'] ?? '');
        if ($code === '') continue;
        $st->execute(array($code, pr_s($r['name'] ?? '', 200), pr_s($r['province'] ?? '', 120), pr_s($r['src'] ?? '', 8)));
        $n++;
    }
    $pdo->commit();
    pr_ok(array('n' => $n, 'total' => (int) $pdo->query("SELECT COUNT(*) FROM `bank_codes`")->fetchColumn()));
}

case 'banks-find': {
    $q = pr_s($_GET['q'] ?? '', 60);
    if ($q === '') pr_ok(array('rows' => array()));
    $st = $pdo->prepare("SELECT code, name, province FROM `bank_codes` WHERE code = ? OR name LIKE ? ORDER BY name LIMIT 30");
    $st->execute(array(pr_digits($q), '%' . $q . '%'));
    pr_ok(array('rows' => $st->fetchAll()));
}

/* ═══════════ BẢNG LƯƠNG ═══════════ */
case 'runs': {
    $st = $pdo->query("SELECT r.*,
            (SELECT COUNT(*) FROM `payroll_items` i WHERE i.run_id = r.id AND i.paid = 1) AS n_paid
        FROM `payroll_runs` r ORDER BY r.period DESC, r.id DESC LIMIT 200");
    pr_ok(array('rows' => $st->fetchAll()));
}

case 'run': {
    $id = (int) ($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM `payroll_runs` WHERE id = ?");
    $st->execute(array($id));
    $run = $st->fetch();
    if (!$run) pr_fail('Không tìm thấy bảng lương.', 404);
    $st = $pdo->prepare("SELECT i.*, u.display_name AS user_name, (i.proof_file IS NOT NULL) AS has_proof
        FROM `payroll_items` i LEFT JOIN `app_users` u ON u.id = i.user_id
        WHERE i.run_id = ? ORDER BY i.stt ASC, i.id ASC");
    $st->execute(array($id));
    $items = array();
    foreach ($st->fetchAll() as $r) { unset($r['proof_file']); $items[] = $r; }
    pr_ok(array('run' => $run, 'items' => $items));
}

case 'run-create': {
    $items = isset($B['items']) && is_array($B['items']) ? $B['items'] : array();
    if (!$items) pr_fail('Chưa có dòng lương nào.');
    $period = pr_s($B['period'] ?? '', 7);
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) pr_fail('Kỳ lương phải dạng YYYY-MM.');

    /* nạp sẵn user để tự ghép */
    $users = $pdo->query("SELECT id, display_name, IFNULL(bank_account,'') ba, IFNULL(bank_holder,'') bh FROM `app_users` WHERE active = 1")->fetchAll();
    $byAcc = array(); $byName = array();
    foreach ($users as $u) {
        if ($u['ba'] !== '') $byAcc[pr_digits($u['ba'])] = (int) $u['id'];
        foreach (array($u['display_name'], $u['bh']) as $nm) {
            if (trim((string) $nm) === '') continue;
            $k = implode(' ', pr_words($nm));
            if ($k !== '' && !isset($byName[$k])) $byName[$k] = (int) $u['id'];
        }
    }
    $bk = $pdo->prepare("SELECT name FROM `bank_codes` WHERE code = ?");

    $total = 0; $rows = array();
    foreach ($items as $it) {
        $code = pr_digits($it['bank_code'] ?? '');
        $bk->execute(array($code));
        $bankFull = (string) ($bk->fetchColumn() ?: '');
        $acc  = pr_digits($it['account'] ?? '');
        $name = pr_s($it['name'] ?? '', 200);
        $uid  = (int) ($it['user_id'] ?? 0);
        if (!$uid && $acc !== '' && isset($byAcc[$acc])) $uid = $byAcc[$acc];
        if (!$uid) { $k = implode(' ', pr_words($name)); if ($k !== '' && isset($byName[$k])) $uid = $byName[$k]; }
        $amt = (int) round((float) ($it['amount'] ?? 0));
        $total += $amt;
        $rows[] = array((int) ($it['stt'] ?? 0), $name, $code, $bankFull, pr_short($bankFull), $acc,
            pr_s($it['card_no'] ?? '', 50), $amt, pr_s($it['content'] ?? ($B['content'] ?? ''), 200), $uid);
    }

    $pdo->beginTransaction();
    $st = $pdo->prepare("INSERT INTO `payroll_runs` (period, title, note, content, total, n_items, created_by)
        VALUES (?,?,?,?,?,?,?)");
    $st->execute(array($period, pr_s($B['title'] ?? ('Lương tháng ' . $period), 200), pr_s($B['note'] ?? '', 500),
        pr_s($B['content'] ?? '', 200), $total, count($rows), $MENAME));
    $rid = (int) $pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO `payroll_items`
        (run_id, stt, name, bank_code, bank_name, bank_short, account, card_no, amount, content, user_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($rows as $r) { array_unshift($r, $rid); $ins->execute($r); }
    $pdo->commit();
    pr_ok(array('id' => $rid, 'n' => count($rows), 'total' => $total));
}

case 'run-del': {
    $id = (int) ($B['id'] ?? 0);
    if (!$id) pr_fail('Thiếu id');
    $st = $pdo->prepare("SELECT proof_file FROM `payroll_items` WHERE run_id = ? AND proof_file IS NOT NULL");
    $st->execute(array($id));
    foreach ($st->fetchAll() as $c) @unlink(PR_DIR . '/' . $c['proof_file']);
    $pdo->prepare("DELETE FROM `payroll_items` WHERE run_id = ?")->execute(array($id));
    $pdo->prepare("DELETE FROM `payroll_runs` WHERE id = ?")->execute(array($id));
    pr_ok(array('id' => $id));
}

case 'item-save': {
    $id = (int) ($B['id'] ?? 0);
    if (!$id) pr_fail('Thiếu id');
    $sets = array(); $par = array();
    if (array_key_exists('name', $B))    { $sets[] = '`name` = ?';    $par[] = pr_s($B['name'], 200); }
    if (array_key_exists('account', $B)) { $sets[] = '`account` = ?'; $par[] = pr_digits($B['account']); }
    if (array_key_exists('amount', $B))  { $sets[] = '`amount` = ?';  $par[] = (int) round((float) $B['amount']); }
    if (array_key_exists('user_id', $B)) { $sets[] = '`user_id` = ?'; $par[] = (int) $B['user_id']; }
    if (array_key_exists('bank_code', $B)) {
        $code = pr_digits($B['bank_code']);
        $bk = $pdo->prepare("SELECT name FROM `bank_codes` WHERE code = ?"); $bk->execute(array($code));
        $full = (string) ($bk->fetchColumn() ?: '');
        $sets[] = '`bank_code` = ?';  $par[] = $code;
        $sets[] = '`bank_name` = ?';  $par[] = $full;
        $sets[] = '`bank_short` = ?'; $par[] = pr_short($full);
    }
    if (!$sets) pr_fail('Không có gì để cập nhật');
    $par[] = $id;
    $pdo->prepare("UPDATE `payroll_items` SET " . implode(', ', $sets) . " WHERE id = ?")->execute($par);
    /* cập nhật lại tổng của bảng */
    $pdo->exec("UPDATE `payroll_runs` r SET r.total = (SELECT IFNULL(SUM(amount),0) FROM `payroll_items` i WHERE i.run_id = r.id),
        r.n_items = (SELECT COUNT(*) FROM `payroll_items` i WHERE i.run_id = r.id)");
    pr_ok(array('id' => $id));
}

case 'item-del': {
    $id = (int) ($B['id'] ?? 0);
    if (!$id) pr_fail('Thiếu id');
    $st = $pdo->prepare("SELECT proof_file FROM `payroll_items` WHERE id = ?");
    $st->execute(array($id)); $c = $st->fetch();
    if ($c && $c['proof_file']) @unlink(PR_DIR . '/' . $c['proof_file']);
    $pdo->prepare("DELETE FROM `payroll_items` WHERE id = ?")->execute(array($id));
    $pdo->exec("UPDATE `payroll_runs` r SET r.total = (SELECT IFNULL(SUM(amount),0) FROM `payroll_items` i WHERE i.run_id = r.id),
        r.n_items = (SELECT COUNT(*) FROM `payroll_items` i WHERE i.run_id = r.id)");
    pr_ok(array('id' => $id));
}

/* ═══════════ THANH TOÁN ═══════════ */
case 'preview':
case 'pay-req': {
    $PREVIEW = ($ACT === 'preview');
    $st = $pdo->prepare("SELECT i.*, r.period, r.title AS run_title FROM `payroll_items` i
        LEFT JOIN `payroll_runs` r ON r.id = i.run_id WHERE i.id = ?");
    $st->execute(array((int) ($B['id'] ?? ($_GET['id'] ?? 0))));
    $r = $st->fetch();
    if (!$r) pr_fail('Không tìm thấy dòng lương.', 404);
    if ((int) $r['paid'] === 1) pr_fail('Khoản này đã thanh toán rồi.');
    if ($r['account'] === '') pr_fail('Dòng này chưa có số tài khoản.');

    $qr = (string) ($B['qr_url'] ?? '');
    if ($qr !== '' && !preg_match('#^https://img\.vietqr\.io/image/[0-9]{6}-[0-9A-Za-z]+-compact2\.png\?#', $qr)) $qr = '';

    $lines = array(
        '🔴 THANH TOÁN LƯƠNG NHÂN VIÊN',
        '💸 YÊU CẦU THANH TOÁN',
        '',
        'Kỳ lương: ' . $r['period'] . ($r['run_title'] ? ' — ' . $r['run_title'] : ''),
        'Người nhận: ' . $r['name'],
    );
    if ($r['bank_name'])  $lines[] = 'Ngân hàng: ' . $r['bank_name'];
    $lines[] = 'Số TK: ' . $r['account'];
    $lines[] = 'Số tiền: ' . pr_money($r['amount']);
    if ($r['content']) $lines[] = 'Nội dung CK: ' . $r['content'];
    $lines[] = '';
    $lines[] = 'Người yêu cầu: ' . $MENAME . ' · ' . date('d/m/Y H:i');
    $text = implode("\n", $lines);

    $st = $pdo->query("SELECT id, display_name, username, zalo_chat_id FROM `app_users`
        WHERE active = 1 AND zalo_chat_id IS NOT NULL AND zalo_chat_id <> ''
          AND (LOWER(username) = 'harris' OR LOWER(display_name) = 'harris' OR id = 1)
        ORDER BY (LOWER(username) = 'harris') DESC, id ASC LIMIT 1");
    $targets = $st->fetchAll();
    if (!$targets) pr_fail('Tài khoản Harris chưa liên kết Zalo.');

    if ($PREVIEW) pr_ok(array('id' => (int) $r['id'], 'text' => $text, 'qr_url' => $qr, 'amount' => (int) $r['amount'],
        'to' => array_map(function ($t) { return $t['display_name'] ?: $t['username']; }, $targets)));

    $sent = array(); $errs = array();
    foreach ($targets as $t) {
        $res = null;
        if ($qr !== '') $res = zb_api('sendPhoto', array('chat_id' => $t['zalo_chat_id'], 'photo' => $qr, 'caption' => $text));
        if ($qr === '' || empty($res['ok'])) $res = zb_send($t['zalo_chat_id'], $text . ($qr !== '' ? "\n\nMã QR: " . $qr : ''));
        if (!empty($res['ok'])) $sent[] = $t['display_name'] ?: $t['username'];
        else $errs[] = ($t['display_name'] ?: $t['username']) . ': ' . (isset($res['error']) ? $res['error'] : 'lỗi');
    }
    if (!$sent) pr_fail('Không gửi được Zalo — ' . implode(' · ', $errs), 502);

    $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE `payroll_items` SET pay_req_at = ?, pay_req_by = ? WHERE id = ?")
        ->execute(array($now, $MENAME, (int) $r['id']));
    pr_ok(array('id' => (int) $r['id'], 'pay_req_at' => $now, 'pay_req_by' => $MENAME, 'sent' => $sent, 'errors' => $errs));
}

case 'paid': {
    $ids = isset($B['ids']) && is_array($B['ids']) ? array_slice($B['ids'], 0, 200) : array();
    if (!$ids) pr_fail('Chưa chọn dòng nào.');
    $f = isset($B['file']) && is_array($B['file']) ? $B['file'] : null;
    if (!$f || empty($f['data'])) pr_fail('Cần đính kèm ảnh hoặc PDF Ủy nhiệm chi.');
    if (!preg_match('#^data:(image/(png|jpe?g|webp)|application/pdf);base64,#i', (string) $f['data'], $m))
        pr_fail('Chỉ nhận ảnh PNG/JPG/WEBP hoặc file PDF.');
    $raw = base64_decode(substr($f['data'], strpos($f['data'], ',') + 1), true);
    if ($raw === false) pr_fail('File hỏng.');
    if (strlen($raw) > PR_MAX) pr_fail('File quá lớn (tối đa 12MB).');
    $mime = strtolower($m[1]);
    $ext  = $mime === 'application/pdf' ? 'pdf' : ($mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg'));
    if (!is_dir(PR_DIR)) @mkdir(PR_DIR, 0755, true);
    $sub = date('Y-m');
    if (!is_dir(PR_DIR . '/' . $sub)) @mkdir(PR_DIR . '/' . $sub, 0755, true);
    $fname = $sub . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (file_put_contents(PR_DIR . '/' . $fname, $raw) === false) pr_fail('Không lưu được file.', 500);

    $now = date('Y-m-d H:i:s');
    $up = $pdo->prepare("UPDATE `payroll_items` SET paid = 1, paid_at = ?, paid_by = ?, proof_file = ?, proof_name = ?, proof_mime = ? WHERE id = ?");
    $done = array();
    foreach ($ids as $id) { $id = (int) $id; if (!$id) continue; $up->execute(array($now, $MENAME, $fname, pr_s($f['name'] ?? '', 200), $mime, $id)); $done[] = $id; }
    pr_ok(array('ids' => $done, 'paid_at' => $now, 'paid_by' => $MENAME, 'proof_name' => pr_s($f['name'] ?? '', 200)));
}

case 'unpaid': {
    $id = (int) ($B['id'] ?? 0);
    if (!$id) pr_fail('Thiếu id');
    $pdo->prepare("UPDATE `payroll_items` SET paid = 0, paid_at = NULL, paid_by = NULL WHERE id = ?")->execute(array($id));
    pr_ok(array('id' => $id));
}

case 'proof': {
    $st = $pdo->prepare("SELECT proof_file, proof_name, proof_mime FROM `payroll_items` WHERE id = ?");
    $st->execute(array((int) ($_GET['id'] ?? 0)));
    $r = $st->fetch();
    if (!$r || !$r['proof_file']) pr_fail('Dòng này chưa có chứng từ.', 404);
    $p = PR_DIR . '/' . $r['proof_file'];
    if (!is_file($p)) pr_fail('File không còn trên máy chủ.', 404);
    if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
    @header_remove('Pragma'); @header_remove('Expires');
    header('Content-Type: ' . ($r['proof_mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($p));
    header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\- ]/u', '', $r['proof_name'] ?: basename($p)) . '"');
    header('Cache-Control: private, max-age=600');
    readfile($p);
    exit;
}

case 'proof-del': {
    $id = (int) ($B['id'] ?? 0);
    $st = $pdo->prepare("SELECT proof_file FROM `payroll_items` WHERE id = ?");
    $st->execute(array($id)); $r = $st->fetch();
    if ($r && $r['proof_file']) @unlink(PR_DIR . '/' . $r['proof_file']);
    $pdo->prepare("UPDATE `payroll_items` SET proof_file = NULL, proof_name = NULL, proof_mime = NULL WHERE id = ?")->execute(array($id));
    pr_ok(array('id' => $id));
}

case 'users': {
    $st = $pdo->query("SELECT id, display_name, staff_type, IFNULL(bank_name,'') bank_name, IFNULL(bank_account,'') bank_account
        FROM `app_users` WHERE active = 1 ORDER BY display_name");
    pr_ok(array('rows' => $st->fetchAll()));
}

default:
    pr_fail('Hành động không hợp lệ: ' . $ACT, 404);
}
