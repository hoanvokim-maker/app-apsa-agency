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
/* APSA1839: link cong khai co token (khong can dang nhap) de Zalo tai anh UNC */
function py_proof_token($id, $file)
{
    $sec = (defined('DB_PASS') ? DB_PASS : '') . '|apsa-unc-v1';
    return substr(hash_hmac('sha256', (int) $id . '|' . (string) $file, $sec), 0, 32);
}
if ((isset($_GET['action']) ? (string) $_GET['action'] : '') === 'proof-pub') {
    $pid = (int) (isset($_GET['id']) ? $_GET['id'] : 0);
    $tok = (string) (isset($_GET['t']) ? $_GET['t'] : '');
    $st  = $pdo->prepare("SELECT proof_file, proof_name, proof_mime FROM `quotation_expenses` WHERE id = ?");
    $st->execute(array($pid));
    $pr  = $st->fetch();
    if (!$pr || !$pr['proof_file'] || $tok === '' || !hash_equals(py_proof_token($pid, $pr['proof_file']), $tok)) { http_response_code(404); exit('not found'); }
    $pp = PY_DIR . '/' . $pr['proof_file'];
    if (!is_file($pp)) { http_response_code(404); exit('not found'); }
    header('Content-Type: ' . ($pr['proof_mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($pp));
    header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\- ]/u', '', $pr['proof_name'] ?: basename($pp)) . '"');
    header('Cache-Control: private, max-age=600');
    readfile($pp);
    exit;
}

/* APSA1839: bao Zalo cho nguoi tao khoan chi: da thanh toan + kem anh UNC */
function py_notify_paid(PDO $pdo, $id, $fname, $mime, $now, $byName)
{
    /* APSA1848: bao Zalo cho nguoi them dong chi phi + nguoi tao du an + admin */
    try {
        if (!function_exists('zb_api') || !function_exists('zb_send')) return;
        $st = $pdo->prepare("SELECT e.*, q.code AS q_code, q.title AS q_title, q.created_by AS q_by
                             FROM `quotation_expenses` e
                             LEFT JOIN `quotations` q ON q.id = e.quotation_id WHERE e.id = ?");
        $st->execute(array((int) $id));
        $r = $st->fetch();
        if (!$r) return;

        $ids = array();
        $uid = (int) (isset($r['created_by']) ? $r['created_by'] : 0);
        if ($uid > 0) $ids[] = $uid;
        $qby = trim((string) (isset($r['q_by']) ? $r['q_by'] : ''));
        if ($qby !== '') {
            $sq = $pdo->prepare("SELECT id FROM `app_users` WHERE active = 1 AND display_name = ? LIMIT 1");
            $sq->execute(array($qby));
            $qid = (int) $sq->fetchColumn();
            if ($qid > 0) $ids[] = $qid;
        }
        foreach ($pdo->query("SELECT id FROM `app_users` WHERE active = 1 AND role = 'admin'") as $ad)
            $ids[] = (int) $ad['id'];
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) return;

        $pub = 'https://app.apsa.agency/api/pay-api.php?action=proof-pub&id=' . (int) $id . '&t=' . py_proof_token($id, $fname);
        $lines = array(
            "\xE2\x9C\x85 Da thanh toan - co UNC dinh kem",
            'Du an: ' . $r['q_code'] . ($r['q_title'] ? ' - ' . $r['q_title'] : ''),
            'Hang muc: ' . $r['name'],
        );
        if ($r['payee_name']) $lines[] = 'Nguoi nhan: ' . $r['payee_name'];
        $lines[] = 'So tien: ' . py_money(py_amount($r));
        if (!empty($r['created_by_name'])) $lines[] = 'Nguoi them khoan chi: ' . $r['created_by_name'];
        if ($qby !== '') $lines[] = 'Nguoi tao du an: ' . $qby;
        $lines[] = 'Nguoi thanh toan: ' . $byName . ' - ' . date('d/m/Y H:i', strtotime($now));
        $lines[] = '';
        $lines[] = 'File UNC: ' . $pub;
        $text = implode("\n", $lines);

        $sel = $pdo->prepare("SELECT zalo_chat_id FROM `app_users` WHERE id = ? AND active = 1");
        foreach ($ids as $one) {
            $sel->execute(array($one));
            $chat = trim((string) $sel->fetchColumn());
            if ($chat === '') continue;
            $res = null;
            if (strpos((string) $mime, 'image/') === 0) {
                $res = zb_api('sendPhoto', array('chat_id' => $chat, 'photo' => $pub, 'caption' => $text));
            }
            if (!$res || empty($res['ok'])) zb_send($chat, $text);
        }
    } catch (Exception $e) {} catch (Throwable $e) {}
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
    $isCty = ((string) $r['payee_type'] === 'sup');
    $lines = array(
        $isCty ? '🔴 THANH TOÁN DẠNG DOANH NGHIỆP' : '🔴 THANH TOÁN CHO CÁ NHÂN',
        '💸 YÊU CẦU THANH TOÁN',
        '',
        'Dự án: ' . $r['q_code'] . ($r['q_title'] ? ' — ' . $r['q_title'] : ''),
    );
    if ($r['q_client']) $lines[] = 'Khách: ' . $r['q_client'];
    $lines[] = 'Hạng mục: ' . $r['name'];
    $lines[] = '';
    $lines[] = 'Người nhận: ' . $r['payee_name'] . ($isCty ? ' (công ty)' : ' (freelancer)');
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
        $btnPay = '';
        if (function_exists('za_block')) {
            try {
                $btnPay = za_block($pdo, (int) $t['id'], array(
                    array('kind' => 'exp_paid', 'id' => (int) $r['id'], 'label' => 'Đã thanh toán'),
                    array('kind' => 'open', 'label' => 'Mở trong app', 'url' => './chi-phi.html'),
                ));
            } catch (Exception $e) { $btnPay = ''; }
        }
        $text2 = $text . ($btnPay !== '' ? "\n\n" . $btnPay : '');
        if ($qr !== '') {
            $res = zb_api('sendPhoto', array('chat_id' => $t['zalo_chat_id'], 'photo' => $qr, 'caption' => $text2));
        }
        if ($qr === '' || empty($res['ok'])) {
            $body = $text2 . ($qr !== '' ? "\n\nMã QR: " . $qr : '');
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
    foreach ($done as $nid) py_notify_paid($pdo, (int) $nid, $fname, $mime, $now, $MENAME);   /* APSA1839 */
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
