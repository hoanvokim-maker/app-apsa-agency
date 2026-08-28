<?php
/**
 * APSA — Yêu cầu thanh toán & chứng từ Ủy nhiệm chi
 *
 *  info · req · paid · unpaid · proof · proof-del
 *  Chỉ dùng cho các dòng trong `quotation_expenses` (kind = 'item').
 */
require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/session-boot.php';
require_once __DIR__ . '/zalo.php';

define('PY_DIR', dirname(__DIR__) . '/uploads/uy-nhiem-chi');
define('PY_MAX', 12 * 1024 * 1024);

function py_ok($d = array())   { header('Content-Type: application/json; charset=utf-8'); echo json_encode(array('ok' => true, 'data' => $d), JSON_UNESCAPED_UNICODE); exit; }
function py_fail($m, $c = 400) { header('Content-Type: application/json; charset=utf-8'); http_response_code($c); echo json_encode(array('ok' => false, 'error' => $m), JSON_UNESCAPED_UNICODE); exit; }
function py_s($v, $n = 255)    { return mb_substr(trim((string) $v), 0, $n); }
function py_money($n)          { return number_format((float) $n, 0, ',', '.') . ' đ'; }

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
} catch (PDOException $e) { py_fail('DB connection failed', 500); }

/* ── migration ─────────────────────────────────────────── */
function py_hasCol(PDO $pdo, $t, $c) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute(array($t, $c));
    return (int) $st->fetchColumn() > 0;
}
if (!py_hasCol($pdo, 'quotation_expenses', 'pay_req_at')) {
    try {
        $pdo->exec("ALTER TABLE `quotation_expenses`
            ADD COLUMN `pay_req_at` DATETIME NULL DEFAULT NULL,
            ADD COLUMN `pay_req_by` VARCHAR(120) NULL DEFAULT NULL,
            ADD COLUMN `paid_at`    DATETIME NULL DEFAULT NULL,
            ADD COLUMN `paid_by`    VARCHAR(120) NULL DEFAULT NULL,
            ADD COLUMN `proof_file` VARCHAR(200) NULL DEFAULT NULL,
            ADD COLUMN `proof_name` VARCHAR(200) NULL DEFAULT NULL,
            ADD COLUMN `proof_mime` VARCHAR(80)  NULL DEFAULT NULL");
    } catch (PDOException $e) { /* da co */ }
}

/* ── nguoi dung ────────────────────────────────────────── */
function py_me(PDO $pdo) {
    if (empty($_SESSION['user_id'])) return null;
    try {
        $st = $pdo->prepare("SELECT id, username, display_name, role, position FROM `app_users` WHERE id = ? AND active = 1");
        $st->execute(array($_SESSION['user_id']));
        return $st->fetch() ?: null;
    } catch (PDOException $e) { return null; }
}
$ME = py_me($pdo);
if (!$ME) py_fail('Bạn cần đăng nhập.', 401);
$MENAME = $ME['display_name'] ?: $ME['username'];
function py_isAdmin($me) {
    return strcasecmp((string) $me['role'], 'admin') === 0
        || strcasecmp((string) $me['position'], 'admin') === 0;
}

/* ── lay 1 dong chi phi kem thong tin du an ───────────── */
function py_row(PDO $pdo, $id) {
    $st = $pdo->prepare("SELECT e.*, q.code AS q_code, q.title AS q_title, q.client_name AS q_client
        FROM `quotation_expenses` e
        LEFT JOIN `quotations` q ON q.id = e.quotation_id
        WHERE e.id = ? AND e.kind = 'item'");
    $st->execute(array((int) $id));
    $r = $st->fetch();
    if (!$r) py_fail('Không tìm thấy dòng chi phí #' . (int) $id, 404);
    return $r;
}
function py_amount($r) {
    $amt = (float) $r['qty'] * (float) $r['price'];
    return round($amt * (1 + (float) $r['vat_percent'] / 100));
}

$ACT = isset($_GET['action']) ? (string) $_GET['action'] : '';
$B   = json_decode(file_get_contents('php://input'), true);
if (!is_array($B)) $B = array();

switch ($ACT) {

/* ═══ danh sach trang thai thanh toan ═══ */
case 'info': {
    $st = $pdo->query("SELECT id, pay_req_at, pay_req_by, paid_at, paid_by, proof_name, proof_mime,
            (proof_file IS NOT NULL) AS has_proof
        FROM `quotation_expenses`
        WHERE kind = 'item' AND (pay_req_at IS NOT NULL OR proof_file IS NOT NULL)
        LIMIT 5000");
    $out = array();
    foreach ($st->fetchAll() as $r) $out[(string) $r['id']] = $r;
    py_ok(array('map' => $out, 'admin' => py_isAdmin($ME) ? 1 : 0, 'me' => $MENAME));
}

/* ═══ gui yeu cau thanh toan qua Zalo ═══ */
case 'preview':
case 'req': {
    $PREVIEW = ($ACT === 'preview');
    $r = py_row($pdo, $B['id'] ?? ($_GET['id'] ?? 0));
    if ((int) $r['paid'] === 1) py_fail('Khoản này đã thanh toán rồi.');
    if (py_s($r['payee_name']) === '') py_fail('Dòng này chưa gán người nhận.');

    $qr = (string) ($B['qr_url'] ?? '');
    if ($qr !== '' && !preg_match('#^https://img\.vietqr\.io/image/[0-9]{6}-[0-9A-Za-z]+-compact2\.png\?#', $qr)) {
        $qr = '';   // chi chap nhan link VietQR chuan
    }

    $amount = py_amount($r);
    $lines = array(
        '💸 YÊU CẦU THANH TOÁN',
        '',
        'Dự án: ' . $r['q_code'] . ($r['q_title'] ? ' — ' . $r['q_title'] : ''),
    );
    if ($r['q_client']) $lines[] = 'Khách: ' . $r['q_client'];
    $lines[] = 'Hạng mục: ' . $r['name'];
    $lines[] = '';
    $lines[] = 'Người nhận: ' . $r['payee_name'] . ((string) $r['payee_type'] === 'sup' ? ' (công ty)' : ' (cá nhân)');
    if ($r['bank_name'])    $lines[] = 'Ngân hàng: ' . $r['bank_name'];
    if ($r['bank_account']) $lines[] = 'Số TK: ' . $r['bank_account'];
    if ($r['bank_holder'])  $lines[] = 'Chủ TK: ' . $r['bank_holder'];
    $lines[] = 'Số tiền: ' . py_money($amount)
        . ((float) $r['vat_percent'] > 0 ? ' (đã gồm VAT ' . rtrim(rtrim(number_format((float) $r['vat_percent'], 2, '.', ''), '0'), '.') . '%)' : '');
    $lines[] = '';
    $lines[] = 'Người yêu cầu: ' . $MENAME . ' · ' . date('d/m/Y H:i');
    $text = implode("\n", $lines);

    /* Nguoi nhan thong bao: chi gui cho anh Harris (theo yeu cau). */
    $st = $pdo->query("SELECT id, display_name, username, zalo_chat_id FROM `app_users`
        WHERE active = 1 AND zalo_chat_id IS NOT NULL AND zalo_chat_id <> ''
          AND (LOWER(username) = 'harris' OR LOWER(display_name) = 'harris' OR id = 1)
        ORDER BY (LOWER(username) = 'harris') DESC, id ASC
        LIMIT 1");
    $targets = $st->fetchAll();
    if (!$targets) py_fail('Tài khoản Harris chưa liên kết Zalo. Vào trang Zalo để liên kết trước.');

    if ($PREVIEW) py_ok(array('id' => (int) $r['id'], 'text' => $text, 'qr_url' => $qr, 'amount' => $amount,
        'to' => array_map(function ($t) { return $t['display_name'] ?: $t['username']; }, $targets)));

    $sent = array(); $errs = array();
    foreach ($targets as $t) {
        $res = null;
        if ($qr !== '') {
            $res = zb_api('sendPhoto', array('chat_id' => $t['zalo_chat_id'], 'photo' => $qr, 'caption' => $text));
        }
        if ($qr === '' || empty($res['ok'])) {
            $body = $text . ($qr !== '' ? "\n\nMã QR: " . $qr : '');
            $res = zb_send($t['zalo_chat_id'], $body);
        }
        if (!empty($res['ok'])) $sent[] = $t['display_name'] ?: $t['username'];
        else $errs[] = ($t['display_name'] ?: $t['username']) . ': ' . (isset($res['error']) ? $res['error'] : 'lỗi');
    }
    if (!$sent) py_fail('Không gửi được Zalo — ' . implode(' · ', $errs), 502);

    $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE `quotation_expenses` SET pay_req_at = ?, pay_req_by = ? WHERE id = ?")
        ->execute(array($now, $MENAME, (int) $r['id']));

    py_ok(array('id' => (int) $r['id'], 'pay_req_at' => $now, 'pay_req_by' => $MENAME,
        'sent' => $sent, 'errors' => $errs, 'amount' => $amount));
}

/* ═══ danh dau da tra + dinh kem uy nhiem chi ═══ */
case 'paid': {
    $ids = isset($B['ids']) && is_array($B['ids']) ? array_slice($B['ids'], 0, 200) : array();
    if (!$ids) py_fail('Chưa chọn dòng nào.');
    $f = isset($B['file']) && is_array($B['file']) ? $B['file'] : null;
    if (!$f || empty($f['data'])) py_fail('Cần đính kèm ảnh hoặc PDF Ủy nhiệm chi.');

    if (!preg_match('#^data:(image/(png|jpe?g|webp)|application/pdf);base64,#i', (string) $f['data'], $m))
        py_fail('Chỉ nhận ảnh PNG/JPG/WEBP hoặc file PDF.');
    $raw = base64_decode(substr($f['data'], strpos($f['data'], ',') + 1), true);
    if ($raw === false) py_fail('File hỏng.');
    if (strlen($raw) > PY_MAX) py_fail('File quá lớn (tối đa 12MB).');
    $mime = strtolower($m[1]);
    $ext  = $mime === 'application/pdf' ? 'pdf' : ($mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg'));

    if (!is_dir(PY_DIR)) @mkdir(PY_DIR, 0755, true);
    $sub = date('Y-m');
    if (!is_dir(PY_DIR . '/' . $sub)) @mkdir(PY_DIR . '/' . $sub, 0755, true);
    $fname = $sub . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (file_put_contents(PY_DIR . '/' . $fname, $raw) === false) py_fail('Không lưu được file.', 500);

    $now = date('Y-m-d H:i:s');
    $up = $pdo->prepare("UPDATE `quotation_expenses`
        SET paid = 1, paid_at = ?, paid_by = ?, proof_file = ?, proof_name = ?, proof_mime = ?
        WHERE id = ? AND kind = 'item'");
    $done = array();
    foreach ($ids as $id) {
        $id = (int) $id; if (!$id) continue;
        $up->execute(array($now, $MENAME, $fname, py_s($f['name'] ?? '', 200), $mime, $id));
        $done[] = $id;
    }
    py_ok(array('ids' => $done, 'paid_at' => $now, 'paid_by' => $MENAME,
        'proof_name' => py_s($f['name'] ?? '', 200), 'proof_mime' => $mime));
}

/* ═══ bo danh dau da tra ═══ */
case 'unpaid': {
    $id = (int) ($B['id'] ?? 0);
    if (!$id) py_fail('Thiếu id');
    $pdo->prepare("UPDATE `quotation_expenses` SET paid = 0, paid_at = NULL, paid_by = NULL WHERE id = ?")
        ->execute(array($id));
    py_ok(array('id' => $id));
}

/* ═══ xem chung tu ═══ */
case 'proof': {
    $r = py_row($pdo, $_GET['id'] ?? 0);
    if (!$r['proof_file']) py_fail('Dòng này chưa có chứng từ.', 404);
    $p = PY_DIR . '/' . $r['proof_file'];
    if (!is_file($p)) py_fail('File không còn trên máy chủ.', 404);
    if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
    @header_remove('Pragma'); @header_remove('Expires');
    header('Content-Type: ' . ($r['proof_mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($p));
    header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\- ]/u', '', $r['proof_name'] ?: basename($p)) . '"');
    header('Cache-Control: private, max-age=600');
    readfile($p);
    exit;
}

/* ═══ xoa chung tu (Admin) ═══ */
case 'proof-del': {
    if (!py_isAdmin($ME)) py_fail('Chỉ Admin xoá được chứng từ.', 403);
    $r = py_row($pdo, $B['id'] ?? 0);
    if ($r['proof_file']) @unlink(PY_DIR . '/' . $r['proof_file']);
    $pdo->prepare("UPDATE `quotation_expenses` SET proof_file = NULL, proof_name = NULL, proof_mime = NULL WHERE id = ?")
        ->execute(array((int) $r['id']));
    py_ok(array('id' => (int) $r['id']));
}

default:
    py_fail('Hành động không hợp lệ: ' . $ACT, 404);
}
