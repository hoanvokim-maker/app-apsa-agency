<?php
// =========================================================
// APSA — Kho Logo API   /api/logo-api.php
// Actions: list, groups, item-save, item-delete, file-delete,
//          upload, download, set-cover, import-legacy
// Quyen: ai dang nhap cung upload/sua duoc; chi Admin moi xoa.
// =========================================================

require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/session-boot.php';

$ROOT    = dirname(__DIR__);                    // document root
$UPDIR   = $ROOT . '/uploads/logos';
$LEGACY  = 'Pharmaceutical Logos';
$MAXBYTE = 400 * 1024 * 1024;                   // 400 MB / file

$IS_DOWNLOAD = (($_GET['action'] ?? '') === 'download');
if (!$IS_DOWNLOAD) header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection failed']);
    exit;
}

function ok($d)                { echo json_encode(['ok' => true, 'data' => $d]); exit; }
function fail($m, $c = 400)    { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

function lg_user($pdo) {
    if (empty($_SESSION['user_id'])) return null;
    $st = $pdo->prepare("SELECT id, username, display_name, role, active FROM `app_users` WHERE id = ? AND active = 1");
    $st->execute([$_SESSION['user_id']]);
    return $st->fetch() ?: null;
}

// ── Migrate once ────────────────────────────────────────
$LOCK = sys_get_temp_dir() . '/apsa_logo_mig_' . @filemtime(__FILE__) . '.lock';
if (!file_exists($LOCK)) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `logo_groups` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `gkey` VARCHAR(40) NOT NULL,
        `name` VARCHAR(160) NOT NULL,
        `icon` VARCHAR(16) DEFAULT NULL,
        `descr` VARCHAR(400) DEFAULT NULL,
        `sort` INT NOT NULL DEFAULT 0,
        UNIQUE KEY `uk_logo_gkey` (`gkey`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `logo_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(200) NOT NULL,
        `mkey` VARCHAR(200) NOT NULL,
        `gkey` VARCHAR(40) NOT NULL DEFAULT 'other',
        `note` VARCHAR(500) DEFAULT NULL,
        `cover_id` INT DEFAULT NULL,
        `user_id` INT DEFAULT NULL,
        `user_name` VARCHAR(120) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_logo_g` (`gkey`),
        KEY `idx_logo_k` (`mkey`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `logo_files` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `item_id` INT NOT NULL,
        `ext` VARCHAR(12) NOT NULL,
        `variant` VARCHAR(24) DEFAULT NULL,
        `orig_name` VARCHAR(255) NOT NULL,
        `path` VARCHAR(500) NOT NULL,
        `legacy` TINYINT(1) NOT NULL DEFAULT 0,
        `bytes` BIGINT NOT NULL DEFAULT 0,
        `is_preview` TINYINT(1) NOT NULL DEFAULT 0,
        `user_id` INT DEFAULT NULL,
        `user_name` VARCHAR(120) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_lf_item` (`item_id`),
        KEY `idx_lf_path` (`path`(191))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    @touch($LOCK);
}

// ── Helpers ─────────────────────────────────────────────
const LG_PREVIEW = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'];
const LG_ALLOW   = ['png','jpg','jpeg','webp','gif','svg','ai','eps','pdf','psd','psb',
                    'zip','rar','7z','cdr','indd','idml','sketch','fig','afdesign','tif','tiff','otf','ttf'];

function lg_ext($n)  { return strtolower(pathinfo($n, PATHINFO_EXTENSION)); }

// URL cong khai cua file (ma hoa tung doan duong dan, giu dau /)
// Do ten file khi dau tieng Viet khac chuan hoa (NFC vs NFD)
function lg_fold($s) {
    static $map = null;
    if ($map === null) {
        $map = [
        'à' => 'a',
        'á' => 'a',
        'ả' => 'a',
        'ã' => 'a',
        'ạ' => 'a',
        'ă' => 'a',
        'ằ' => 'a',
        'ắ' => 'a',
        'ẳ' => 'a',
        'ẵ' => 'a',
        'ặ' => 'a',
        'â' => 'a',
        'ầ' => 'a',
        'ấ' => 'a',
        'ẩ' => 'a',
        'ẫ' => 'a',
        'ậ' => 'a',
        'è' => 'e',
        'é' => 'e',
        'ẻ' => 'e',
        'ẽ' => 'e',
        'ẹ' => 'e',
        'ê' => 'e',
        'ề' => 'e',
        'ế' => 'e',
        'ể' => 'e',
        'ễ' => 'e',
        'ệ' => 'e',
        'ì' => 'i',
        'í' => 'i',
        'ỉ' => 'i',
        'ĩ' => 'i',
        'ị' => 'i',
        'ò' => 'o',
        'ó' => 'o',
        'ỏ' => 'o',
        'õ' => 'o',
        'ọ' => 'o',
        'ô' => 'o',
        'ồ' => 'o',
        'ố' => 'o',
        'ổ' => 'o',
        'ỗ' => 'o',
        'ộ' => 'o',
        'ơ' => 'o',
        'ờ' => 'o',
        'ớ' => 'o',
        'ở' => 'o',
        'ỡ' => 'o',
        'ợ' => 'o',
        'ù' => 'u',
        'ú' => 'u',
        'ủ' => 'u',
        'ũ' => 'u',
        'ụ' => 'u',
        'ư' => 'u',
        'ừ' => 'u',
        'ứ' => 'u',
        'ử' => 'u',
        'ữ' => 'u',
        'ự' => 'u',
        'ỳ' => 'y',
        'ý' => 'y',
        'ỷ' => 'y',
        'ỹ' => 'y',
        'ỵ' => 'y',
        'đ' => 'd',
        'À' => 'A',
        'Á' => 'A',
        'Ả' => 'A',
        'Ã' => 'A',
        'Ạ' => 'A',
        'Ă' => 'A',
        'Ằ' => 'A',
        'Ắ' => 'A',
        'Ẳ' => 'A',
        'Ẵ' => 'A',
        'Ặ' => 'A',
        'Â' => 'A',
        'Ầ' => 'A',
        'Ấ' => 'A',
        'Ẩ' => 'A',
        'Ẫ' => 'A',
        'Ậ' => 'A',
        'È' => 'E',
        'É' => 'E',
        'Ẻ' => 'E',
        'Ẽ' => 'E',
        'Ẹ' => 'E',
        'Ê' => 'E',
        'Ề' => 'E',
        'Ế' => 'E',
        'Ể' => 'E',
        'Ễ' => 'E',
        'Ệ' => 'E',
        'Ì' => 'I',
        'Í' => 'I',
        'Ỉ' => 'I',
        'Ĩ' => 'I',
        'Ị' => 'I',
        'Ò' => 'O',
        'Ó' => 'O',
        'Ỏ' => 'O',
        'Õ' => 'O',
        'Ọ' => 'O',
        'Ô' => 'O',
        'Ồ' => 'O',
        'Ố' => 'O',
        'Ổ' => 'O',
        'Ỗ' => 'O',
        'Ộ' => 'O',
        'Ơ' => 'O',
        'Ờ' => 'O',
        'Ớ' => 'O',
        'Ở' => 'O',
        'Ỡ' => 'O',
        'Ợ' => 'O',
        'Ù' => 'U',
        'Ú' => 'U',
        'Ủ' => 'U',
        'Ũ' => 'U',
        'Ụ' => 'U',
        'Ư' => 'U',
        'Ừ' => 'U',
        'Ứ' => 'U',
        'Ử' => 'U',
        'Ữ' => 'U',
        'Ự' => 'U',
        'Ỳ' => 'Y',
        'Ý' => 'Y',
        'Ỷ' => 'Y',
        'Ỹ' => 'Y',
        'Ỵ' => 'Y',
        'Đ' => 'D'
        ];
    }
    // 1) bo dau ket hop (chuoi dang NFD)
    $s = preg_replace('/\pM/u', '', $s);
    // 2) doi ky tu co dau dung san (NFC) ve chu khong dau
    $s = strtr($s, $map);
    // 3) chi giu chu thuong, so va dau cham
    $s = mb_strtolower($s, 'UTF-8');
    return preg_replace('/[^a-z0-9.]+/u', '', $s);
}

function lg_resolve($dir, $want) {
    static $maps = [];
    if (!isset($maps[$dir])) {
        $m = [];
        foreach ((array)@scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $m[lg_fold($f)] = $f;
        }
        $maps[$dir] = $m;
    }
    $k = lg_fold($want);
    return $maps[$dir][$k] ?? null;
}

function lg_url($rel) {
    $parts = array_map('rawurlencode', explode('/', $rel));
    return '/' . implode('/', $parts);
}

function lg_safe($n) {
    $n = preg_replace('/[^A-Za-z0-9._-]+/', '-', $n);
    $n = trim(preg_replace('/-+/', '-', $n), '-.');
    return $n === '' ? 'file' : substr($n, 0, 120);
}

// Khoa gom nhom: bo duoi mo rong, bo chu "logo", chuan hoa
function lg_key($base) {
    $s = strtolower($base);
    $s = preg_replace('/\.[a-z0-9]+$/', '', $s);
    $s = str_replace(['_', '-', '+', '.'], ' ', $s);
    $s = preg_replace('/\b(logo|logos|vector|final|new|copy)\b/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

function lg_title($base) {
    $s = preg_replace('/\.[a-z0-9]+$/i', '', $base);
    $s = str_replace(['_', '-'], ' ', $s);
    $s = preg_replace('/\s+/', ' ', trim($s));
    return $s === '' ? 'Logo' : mb_substr($s, 0, 200);
}

function lg_seed_groups($pdo) {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM `logo_groups`")->fetchColumn();
    if ($n > 0) return;
    $seed = [
        ['az','AstraZeneca & Nhãn thuốc AZ','🟣','Logo AstraZeneca và các nhãn thuốc: Breztri, Forxiga, Tagrisso, Symbicort, Tezspire…',1],
        ['novartis','Novartis','♥️','Novartis và các nhãn thuốc liên quan.',2],
        ['opella','Opella & PharmAcademy','🌿','Opella Healthcare, PharmAcademy và nhãn hàng trực thuộc.',3],
        ['pharma','Tập đoàn Dược khác','💊','Các tập đoàn và nhãn dược phẩm khác.',4],
        ['chain','Chuỗi nhà thuốc','🏪','Long Châu, Pharmacity, An Khang và các chuỗi khác.',5],
        ['hospital','Bệnh viện & Cơ quan Y tế','🏥','Logo bệnh viện, Bộ Y tế, FDA (đủ định dạng vector).',6],
        ['assoc','Hội & Hiệp hội','🤝','Các hội ngành y dược trong nước và quốc tế (ACC, ESC, AHA…).',7],
        ['aqua','AQUA Smart Home','🏠','Logo AQUA Smart Home bản dọc, ngang và file AI gốc.',8],
        ['other','Khác','🌍','Y360 và các asset, hình ảnh chưa phân loại.',9],
    ];
    $st = $pdo->prepare("INSERT INTO `logo_groups` (gkey,name,icon,descr,sort) VALUES (?,?,?,?,?)");
    foreach ($seed as $g) $st->execute($g);
}
lg_seed_groups($pdo);

function lg_refresh_cover($pdo, $itemId) {
    $st = $pdo->prepare("SELECT id, ext FROM `logo_files` WHERE item_id = ? AND is_preview = 1
                         ORDER BY FIELD(ext,'svg','png','webp','jpg','jpeg','gif'), id LIMIT 1");
    $st->execute([$itemId]);
    $r = $st->fetch();
    $pdo->prepare("UPDATE `logo_items` SET cover_id = ? WHERE id = ?")->execute([$r ? (int)$r['id'] : null, $itemId]);
}

function lg_find_or_make_item($pdo, $me, $name, $gkey) {
    $mkey = lg_key($name);
    if ($mkey === '') $mkey = strtolower($name);
    $st = $pdo->prepare("SELECT id FROM `logo_items` WHERE mkey = ? AND gkey = ? LIMIT 1");
    $st->execute([$mkey, $gkey]);
    if ($r = $st->fetch()) return (int)$r['id'];

    $ins = $pdo->prepare("INSERT INTO `logo_items` (name, mkey, gkey, user_id, user_name) VALUES (?,?,?,?,?)");
    $ins->execute([lg_title($name), $mkey, $gkey,
                   $me ? (int)$me['id'] : null,
                   $me ? ($me['display_name'] ?: $me['username']) : null]);
    return (int)$pdo->lastInsertId();
}

// ── Dispatch ────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$raw    = file_get_contents('php://input');
$body   = json_decode($raw, true) ?? [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

$me = lg_user($pdo);
if (!$me) fail('Unauthorized', 401);
$isAdmin = (($me['role'] ?? '') === 'admin');

switch ($action) {

case 'groups': {
    ok($pdo->query("SELECT gkey, name, icon, descr, sort FROM `logo_groups` ORDER BY sort, id")->fetchAll());
}

case 'list': {
    $items = $pdo->query("SELECT id, name, gkey, note, cover_id, user_name, created_at
                          FROM `logo_items` ORDER BY name")->fetchAll();
    $files = $pdo->query("SELECT id, item_id, ext, variant, orig_name, path, bytes, is_preview, legacy
                          FROM `logo_files` ORDER BY FIELD(ext,'svg','png','ai','eps','pdf','psd'), id")->fetchAll();

    $byItem = [];
    foreach ($files as $f) {
        $f['id']         = (int)$f['id'];
        $f['bytes']      = (int)$f['bytes'];
        $f['is_preview'] = (int)$f['is_preview'] === 1;
        $f['legacy']     = (int)$f['legacy'] === 1;
        $f['url']        = lg_url($f['path']);
        $byItem[(int)$f['item_id']][] = $f;
    }

    $out = [];
    foreach ($items as $it) {
        $id = (int)$it['id'];
        $fs = $byItem[$id] ?? [];
        if (!$fs) continue;
        $cover = null;
        foreach ($fs as $f) { if ($f['id'] === (int)$it['cover_id']) { $cover = $f; break; } }
        if (!$cover) foreach ($fs as $f) { if ($f['is_preview']) { $cover = $f; break; } }
        $out[] = [
            'id'    => $id,
            'name'  => $it['name'],
            'gkey'  => $it['gkey'],
            'note'  => $it['note'],
            'who'   => $it['user_name'],
            'cover' => $cover ? $cover['url'] : null,
            'files' => array_values($fs),
        ];
    }
    ok($out);
}

case 'item-save': {
    $id   = (int)($body['id'] ?? 0);
    $name = trim((string)($body['name'] ?? ''));
    $gkey = trim((string)($body['gkey'] ?? ''));
    $note = trim((string)($body['note'] ?? ''));
    if (!$id)   fail('Thiếu id');
    if ($name === '') fail('Tên logo không được để trống');

    $chk = $pdo->prepare("SELECT 1 FROM `logo_groups` WHERE gkey = ?");
    $chk->execute([$gkey]);
    if (!$chk->fetch()) fail('Nhóm không hợp lệ');

    $st = $pdo->prepare("UPDATE `logo_items` SET name = ?, mkey = ?, gkey = ?, note = ? WHERE id = ?");
    $st->execute([$name, lg_key($name), $gkey, ($note !== '' ? $note : null), $id]);
    ok(['id' => $id]);
}

case 'set-cover': {
    $fid = (int)($body['file_id'] ?? 0);
    if (!$fid) fail('Thiếu file_id');
    $st = $pdo->prepare("SELECT item_id, is_preview FROM `logo_files` WHERE id = ?");
    $st->execute([$fid]);
    $f = $st->fetch();
    if (!$f) fail('Không tìm thấy file', 404);
    if (!(int)$f['is_preview']) fail('Chỉ ảnh PNG/JPG/SVG mới làm ảnh đại diện được');
    $pdo->prepare("UPDATE `logo_items` SET cover_id = ? WHERE id = ?")->execute([$fid, (int)$f['item_id']]);
    ok(['id' => $fid]);
}

case 'upload': {
    if (empty($_FILES['file'])) fail('Không nhận được file (có thể file quá lớn so với giới hạn máy chủ)');
    $F = $_FILES['file'];
    if ($F['error'] !== UPLOAD_ERR_OK) fail('Lỗi tải lên (mã ' . $F['error'] . ')');
    if ($F['size'] <= 0)        fail('File rỗng');
    if ($F['size'] > $MAXBYTE)  fail('File vượt quá 400 MB');

    $orig = basename((string)$F['name']);
    $ext  = lg_ext($orig);
    if (!in_array($ext, LG_ALLOW, true)) fail('Định dạng .' . $ext . ' chưa được hỗ trợ');

    $itemId = (int)($_POST['item_id'] ?? 0);
    if ($itemId) {
        $c = $pdo->prepare("SELECT 1 FROM `logo_items` WHERE id = ?");
        $c->execute([$itemId]);
        if (!$c->fetch()) fail('Không tìm thấy logo', 404);
    } else {
        $gkey = trim((string)($_POST['gkey'] ?? 'other'));
        $g = $pdo->prepare("SELECT 1 FROM `logo_groups` WHERE gkey = ?");
        $g->execute([$gkey]);
        if (!$g->fetch()) $gkey = 'other';
        $nm = trim((string)($_POST['name'] ?? ''));
        if ($nm === '') $nm = $orig;
        $itemId = lg_find_or_make_item($pdo, $me, $nm, $gkey);
    }

    $dir = $UPDIR . '/' . $itemId;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) fail('Không tạo được thư mục lưu file', 500);

    $ins = $pdo->prepare("INSERT INTO `logo_files` (item_id, ext, orig_name, path, bytes, is_preview, user_id, user_name)
                          VALUES (?,?,?,?,?,?,?,?)");
    $ins->execute([$itemId, $ext, mb_substr($orig, 0, 255), '', (int)$F['size'],
                   in_array($ext, LG_PREVIEW, true) ? 1 : 0,
                   (int)$me['id'], $me['display_name'] ?: $me['username']]);
    $fid = (int)$pdo->lastInsertId();

    $stored = $fid . '_' . lg_safe(pathinfo($orig, PATHINFO_FILENAME)) . '.' . $ext;
    if (!@move_uploaded_file($F['tmp_name'], $dir . '/' . $stored)) {
        $pdo->prepare("DELETE FROM `logo_files` WHERE id = ?")->execute([$fid]);
        fail('Không lưu được file lên máy chủ', 500);
    }
    @chmod($dir . '/' . $stored, 0644);

    $rel = 'uploads/logos/' . $itemId . '/' . $stored;
    $pdo->prepare("UPDATE `logo_files` SET path = ? WHERE id = ?")->execute([$rel, $fid]);
    lg_refresh_cover($pdo, $itemId);

    ok(['file_id' => $fid, 'item_id' => $itemId, 'path' => $rel, 'ext' => $ext]);
}

case 'download': {
    $fid = (int)($_GET['id'] ?? 0);
    $st  = $pdo->prepare("SELECT orig_name, path FROM `logo_files` WHERE id = ?");
    $st->execute([$fid]);
    $f = $st->fetch();
    if (!$f) { http_response_code(404); exit('Not found'); }

    $abs = $ROOT . '/' . $f['path'];
    $real = realpath($abs);
    if (!$real || strpos($real, realpath($ROOT)) !== 0 || !is_file($real)) { http_response_code(404); exit('Not found'); }

    $name = $f['orig_name'] !== '' ? $f['orig_name'] : basename($real);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($real));
    header('Content-Disposition: attachment; filename="' . preg_replace('/["\r\n]/', '', $name) . '"; '
         . "filename*=UTF-8''" . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0');
    readfile($real);
    exit;
}

case 'file-delete': {
    if (!$isAdmin) fail('Chỉ Admin mới xoá được file', 403);
    $fid = (int)($body['id'] ?? 0);
    $st  = $pdo->prepare("SELECT item_id, path, legacy FROM `logo_files` WHERE id = ?");
    $st->execute([$fid]);
    $f = $st->fetch();
    if (!$f) fail('Không tìm thấy file', 404);

    if (!(int)$f['legacy']) {
        $abs  = $ROOT . '/' . $f['path'];
        $real = realpath($abs);
        if ($real && strpos($real, realpath($UPDIR)) === 0 && is_file($real)) @unlink($real);
    }
    $pdo->prepare("DELETE FROM `logo_files` WHERE id = ?")->execute([$fid]);

    $item = (int)$f['item_id'];
    $left = (int)$pdo->query("SELECT COUNT(*) FROM `logo_files` WHERE item_id = $item")->fetchColumn();
    if ($left === 0) $pdo->prepare("DELETE FROM `logo_items` WHERE id = ?")->execute([$item]);
    else             lg_refresh_cover($pdo, $item);

    ok(['id' => $fid, 'item_removed' => $left === 0]);
}

case 'item-delete': {
    if (!$isAdmin) fail('Chỉ Admin mới xoá được logo', 403);
    $id = (int)($body['id'] ?? 0);
    $st = $pdo->prepare("SELECT id, path, legacy FROM `logo_files` WHERE item_id = ?");
    $st->execute([$id]);
    foreach ($st->fetchAll() as $f) {
        if ((int)$f['legacy']) continue;
        $real = realpath($ROOT . '/' . $f['path']);
        if ($real && strpos($real, realpath($UPDIR)) === 0 && is_file($real)) @unlink($real);
    }
    $pdo->prepare("DELETE FROM `logo_files` WHERE item_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM `logo_items` WHERE id = ?")->execute([$id]);
    @rmdir($UPDIR . '/' . $id);
    ok(['id' => $id]);
}

// ── Import kho cu tu danh sach cung trong logos.html ─────
case 'import-legacy': {
    if (!$isAdmin) fail('Chỉ Admin mới chạy được import', 403);

    $src = @file_get_contents($ROOT . '/logos.html.bak-kholog');
    if ($src === false) $src = @file_get_contents($ROOT . '/logos.html');
    if ($src === false) fail('Không đọc được logos.html', 500);

    if (!preg_match('/const\s+FILES\s*=\s*\[(.*?)\n\s*\];/s', $src, $m)) fail('Không tìm thấy danh sách FILES cũ', 500);
    preg_match_all("/\[\s*'([^']+)'\s*,\s*'([^']+)'\s*\]/", $m[1], $rows, PREG_SET_ORDER);
    if (!$rows) fail('Danh sách cũ rỗng', 500);

    $have = [];
    foreach ($pdo->query("SELECT path FROM `logo_files` WHERE legacy = 1")->fetchAll() as $r) $have[$r['path']] = 1;

    $added = 0; $skip = 0; $missing = [];
    foreach ($rows as $r) {
        $gkey = $r[1];
        $rel  = $LEGACY . '/' . $r[2];
        if (isset($have[$rel])) { $skip++; continue; }

        $abs = $ROOT . '/' . $rel;
        if (!is_file($abs)) {
            // Thu do lai theo ten da bo dau (khac chuan hoa Unicode)
            $sub  = trim(dirname($r[2]), '.');
            $dirA = $ROOT . '/' . $LEGACY . ($sub !== '' && $sub !== '/' ? '/' . $sub : '');
            $hit  = lg_resolve($dirA, basename($r[2]));
            if ($hit === null) { $missing[] = $r[2]; continue; }
            $rel = $LEGACY . ($sub !== '' && $sub !== '/' ? '/' . $sub : '') . '/' . $hit;
            if (isset($have[$rel])) { $skip++; continue; }
            $abs = $ROOT . '/' . $rel;
        }

        $g = $pdo->prepare("SELECT 1 FROM `logo_groups` WHERE gkey = ?");
        $g->execute([$gkey]);
        if (!$g->fetch()) $gkey = 'other';

        $base    = basename($r[2]);
        $variant = null;
        $dirName = trim(dirname($r[2]), '.');
        if (preg_match('/^([234]x)$/i', $dirName, $vm)) $variant = strtolower($vm[1]);

        $itemId = lg_find_or_make_item($pdo, null, $base, $gkey);
        $ext    = lg_ext($base);

        $ins = $pdo->prepare("INSERT INTO `logo_files` (item_id, ext, variant, orig_name, path, legacy, bytes, is_preview)
                              VALUES (?,?,?,?,?,1,?,?)");
        $ins->execute([$itemId, $ext, $variant, $base, $rel, (int)@filesize($abs),
                       in_array($ext, LG_PREVIEW, true) ? 1 : 0]);
        $added++;
    }

    foreach ($pdo->query("SELECT id FROM `logo_items`")->fetchAll() as $it) lg_refresh_cover($pdo, (int)$it['id']);

    ok(['added' => $added, 'skipped' => $skip, 'missing' => $missing,
        'items' => (int)$pdo->query("SELECT COUNT(*) FROM `logo_items`")->fetchColumn(),
        'files' => (int)$pdo->query("SELECT COUNT(*) FROM `logo_files`")->fetchColumn()]);
}

case 'me': {
    ok(['id' => (int)$me['id'], 'name' => $me['display_name'] ?: $me['username'], 'admin' => $isAdmin]);
}

default:
    fail('Unknown action: ' . $action, 404);
}
