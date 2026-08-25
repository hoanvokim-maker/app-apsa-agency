<?php
// ============================================================
// APSA — Rate Card API  /api/ratecard-api.php
//
// Quản lý bảng giá tham khảo (Event / Design / Media / Printing),
// mỗi hạng mục có 3 mức giá BASIC / STANDARD / PREMIUM, song ngữ EN-VN.
//
// GET  ?action=list&sheet=event&q=&trash=1     → danh sách hạng mục
// GET  ?action=sheets                          → danh sách 5 sheet + số lượng
// GET  ?action=limits                          → giới hạn dung lượng upload của server
// GET  ?action=zip&id=..                       → tải file .zip sản xuất (cần đăng nhập)
// POST ?action=upload-zip  (multipart)         → tải file .zip lên cho 1 sản phẩm VFR
// POST ?action=delete-zip  (JSON) { id }       → gỡ file .zip
// GET  ?action=terms                           → nội dung sheet Điều khoản (tĩnh)
// POST ?action=create   (JSON)                 → thêm hạng mục mới
// POST ?action=update   (JSON) { id, ... }     → sửa hạng mục
// POST ?action=delete   (JSON) { id }          → xoá mềm
// POST ?action=restore  (JSON) { id }          → khôi phục
// POST ?action=reseed   (cần X-API-Key)        → chèn lại dữ liệu gốc (chỉ khi bảng rỗng)
// ============================================================

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/session-boot.php';

function ok($data)             { echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE); exit; }
function fail($msg, $code=400) { http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

function body_json() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function num($v) {
    if ($v === '' || $v === null) return 0;
    $v = str_replace([',', ' '], '', (string)$v);
    return is_numeric($v) ? round((float)$v, 2) : 0;
}
function s($v, $len = 500) { return mb_substr(trim((string)($v ?? '')), 0, $len); }

$SHEETS = [
    'event'    => ['en' => 'Event Production',   'vn' => 'Sự kiện'],
    'design'   => ['en' => 'Design & Creative',   'vn' => 'Thiết kế'],
    'media'    => ['en' => 'Media & Livestream',  'vn' => 'Media'],
    'printing' => ['en' => 'Printing',            'vn' => 'In ấn'],
    'vfr'      => ['en' => 'VFR Products',        'vn' => 'VFR'],
];

// ── Kết nối DB ───────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fail('DB connection failed', 500);
}

// ── Tạo bảng nếu chưa có ─────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `ratecard_items` (
  `id`          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `sheet_key`   VARCHAR(20)    NOT NULL COMMENT 'event|design|media|printing',
  `cat_code`    VARCHAR(5)     DEFAULT NULL COMMENT 'Mã danh mục con: A, B, C...',
  `cat_en`      VARCHAR(200)   DEFAULT NULL,
  `cat_vn`      VARCHAR(200)   DEFAULT NULL,
  `no_label`    VARCHAR(20)    DEFAULT NULL COMMENT 'Số thứ tự hiển thị trong danh mục',
  `item_en`     VARCHAR(300)   NOT NULL,
  `item_vn`     VARCHAR(300)   DEFAULT NULL,
  `desc_en`     TEXT           DEFAULT NULL,
  `desc_vn`     TEXT           DEFAULT NULL,
  `unit_en`     VARCHAR(50)    DEFAULT NULL,
  `unit_vn`     VARCHAR(50)    DEFAULT NULL,
  `basic`       DECIMAL(14,2)  NOT NULL DEFAULT 0,
  `standard`    DECIMAL(14,2)  NOT NULL DEFAULT 0,
  `premium`     DECIMAL(14,2)  NOT NULL DEFAULT 0,
  `notes_en`    TEXT           DEFAULT NULL,
  `notes_vn`    TEXT           DEFAULT NULL,
  `sort_order`  INT            NOT NULL DEFAULT 0,
  `updated_by`  VARCHAR(120)   DEFAULT NULL,
  `deleted_at`  DATETIME       DEFAULT NULL,
  `created_at`  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_sheet`   (`sheet_key`),
  INDEX `idx_cat`     (`sheet_key`, `cat_code`),
  INDEX `idx_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Migrate: cột dành cho sản phẩm VFR (giá vốn + file sản xuất) ──
function rc_hasColumn(PDO $pdo, $table, $col) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $st->execute([$table, $col]);
        return (int)$st->fetchColumn() > 0;
    } catch (PDOException $e) { return true; }
}
if (!rc_hasColumn($pdo, 'ratecard_items', 'cost_price')) {
    try {
        $pdo->exec("ALTER TABLE `ratecard_items`
            ADD COLUMN `cost_price` DECIMAL(14,2)   NOT NULL DEFAULT 0    COMMENT 'Giá vốn sản xuất (VFR)',
            ADD COLUMN `zip_file`   VARCHAR(200)    DEFAULT NULL          COMMENT 'Tên file zip lưu trên đĩa',
            ADD COLUMN `zip_name`   VARCHAR(200)    DEFAULT NULL          COMMENT 'Tên file gốc khi upload',
            ADD COLUMN `zip_size`   BIGINT UNSIGNED NOT NULL DEFAULT 0    COMMENT 'Dung lượng file zip (byte)',
            ADD COLUMN `zip_at`     DATETIME        DEFAULT NULL          COMMENT 'Thời điểm upload'");
    } catch (PDOException $e) { /* bỏ qua nếu đã có */ }
}

// ── File sản xuất (.zip) cho sản phẩm VFR ────────────────────
define('RC_FILE_DIR', dirname(__DIR__) . '/uploads/ratecard');

/** Giới hạn dung lượng thật sự của server (lấy min của upload_max_filesize / post_max_size). */
function rc_maxUpload() {
    $toBytes = function ($v) {
        $v = trim((string)$v);
        if ($v === '' || $v === '-1' || $v === '0') return 0;
        $u = strtolower(substr($v, -1));
        $n = (float)$v;
        if ($u === 'g') $n *= 1024 * 1024 * 1024;
        elseif ($u === 'm') $n *= 1024 * 1024;
        elseif ($u === 'k') $n *= 1024;
        return (int)$n;
    };
    $caps = array_filter([$toBytes(ini_get('upload_max_filesize')), $toBytes(ini_get('post_max_size'))]);
    $caps[] = 200 * 1024 * 1024;           // trần tự đặt: 200MB
    return min($caps);
}

function rc_fileDir($id, $create = false) {
    $dir = RC_FILE_DIR . '/' . (int)$id;
    if ($create && !is_dir($dir)) {
        @mkdir($dir, 0755, true);
        $ht = RC_FILE_DIR . '/.htaccess';
        if (!is_file($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
    return $dir;
}

/** Tên file an toàn, giữ đuôi .zip */
function rc_safeName($name) {
    $name = preg_replace('/\.zip$/i', '', (string)$name);
    $name = preg_replace('/[^\p{L}\p{N}\-_. ]+/u', '-', $name);
    $name = trim(preg_replace('/-{2,}/', '-', $name), '-. ');
    if ($name === '') $name = 'file-san-xuat';
    return mb_substr($name, 0, 90) . '.zip';
}

/** Kiểm tra file upload có đúng là .zip không (đuôi + magic bytes PK). */
function rc_checkZip($f) {
    if (!$f || !isset($f['error'])) return 'Không nhận được file';
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE)
        return 'File vượt quá giới hạn của máy chủ (' . round(rc_maxUpload() / 1048576) . 'MB)';
    if ($f['error'] !== UPLOAD_ERR_OK) return 'Tải file lên thất bại (mã ' . $f['error'] . ')';
    if (($f['size'] ?? 0) <= 0) return 'File rỗng';
    if ($f['size'] > rc_maxUpload()) return 'File quá lớn — tối đa ' . round(rc_maxUpload() / 1048576) . 'MB';
    if (!preg_match('/\.zip$/i', (string)($f['name'] ?? ''))) return 'Chỉ nhận file .zip';
    $fh = @fopen($f['tmp_name'], 'rb');
    if (!$fh) return 'Không đọc được file';
    $magic = fread($fh, 4);
    fclose($fh);
    // PK\x03\x04 (zip thường), PK\x05\x06 (zip rỗng), PK\x07\x08 (zip chia nhỏ)
    if (substr($magic, 0, 2) !== 'PK' || !in_array(substr($magic, 2, 2), ["\x03\x04", "\x05\x06", "\x07\x08"], true))
        return 'File không phải ZIP hợp lệ';
    return '';
}

/** Người dùng đang đăng nhập — chỉ bắt buộc với thao tác file. */
function rc_currentUser(PDO $pdo) {
    if (empty($_SESSION['user_id'])) return null;
    try {
        $st = $pdo->prepare("SELECT id, username, display_name, role FROM `app_users` WHERE id = ? AND active = 1");
        $st->execute([$_SESSION['user_id']]);
        return $st->fetch() ?: null;
    } catch (PDOException $e) { return null; }
}
function rc_requireLogin(PDO $pdo) {
    if (!rc_currentUser($pdo)) fail('Unauthorized — vui lòng đăng nhập', 401);
}

// ── Seed dữ liệu gốc từ rate card lần chạy đầu tiên ───────────
function maybe_seed(PDO $pdo) {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM ratecard_items")->fetchColumn();
    if ($count > 0) return;

    $seedFile = __DIR__ . '/ratecard-seed-data.php';
    if (!is_file($seedFile)) return;
    $rows = include $seedFile;
    if (!is_array($rows) || !$rows) return;

    $stmt = $pdo->prepare(
        'INSERT INTO ratecard_items
            (sheet_key, cat_code, cat_en, cat_vn, no_label, item_en, item_vn, desc_en, desc_vn,
             unit_en, unit_vn, basic, standard, premium, notes_en, notes_vn, sort_order, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $stmt->execute([
            $r['sheet_key'], $r['cat_code'], $r['cat_en'], $r['cat_vn'], $r['no_label'],
            $r['item_en'], $r['item_vn'], $r['desc_en'], $r['desc_vn'],
            $r['unit_en'], $r['unit_vn'], $r['basic'], $r['standard'], $r['premium'],
            $r['notes_en'], $r['notes_vn'], $r['sort_order'], 'APSA Rate Card Master 2026 (seed)',
        ]);
    }
    $pdo->commit();
}
maybe_seed($pdo);

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
//  ACTION: SHEETS — danh sách 4 nhóm + số lượng hạng mục
// ============================================================
if ($action === 'sheets') {
    $rows = $pdo->query(
        "SELECT sheet_key, COUNT(*) AS cnt FROM ratecard_items WHERE deleted_at IS NULL GROUP BY sheet_key"
    )->fetchAll();
    $counts = [];
    foreach ($rows as $r) $counts[$r['sheet_key']] = (int)$r['cnt'];

    $out = [];
    foreach ($SHEETS as $key => $meta) {
        $out[] = ['key' => $key, 'en' => $meta['en'], 'vn' => $meta['vn'], 'count' => $counts[$key] ?? 0];
    }
    ok($out);
}

// ============================================================
//  ACTION: TERMS — nội dung điều khoản & công thức (tĩnh)
// ============================================================
if ($action === 'terms') {
    ok([
        'ma_percent'   => 10,
        'vat_percent'  => 8,
        'formula_en'   => 'TOTAL PAYABLE = Total NET × (1 + MA%) × 1.08',
        'formula_vn'   => 'TỔNG THANH TOÁN = Tổng NET × (1 + MA%) × 1,08',
        'notes_en'     => 'All rates are NET, before management fee (MA) and VAT. Standard shift is 8 hours; overtime +30%. In-city transport applies to HCMC / Hanoi / Da Nang.',
        'notes_vn'     => 'Giá trong rate card là giá NET, chưa gồm phí quản lý (MA) và VAT. Ca chuẩn 8 giờ, vượt ca +30%. Giá vận chuyển nội thành áp dụng cho HCM / HN / ĐN.',
    ]);
}

// ============================================================
//  ACTION: LIST
// ============================================================
if ($action === 'list' || ($method === 'GET' && $action === '')) {
    $where  = ['deleted_at IS NULL'];
    $params = [];

    if (!empty($_GET['trash'])) { $where = ['deleted_at IS NOT NULL']; }
    if (!empty($_GET['sheet']) && isset($SHEETS[$_GET['sheet']])) { $where[] = 'sheet_key = ?'; $params[] = $_GET['sheet']; }
    if (!empty($_GET['cat']))   { $where[] = 'cat_code = ?'; $params[] = $_GET['cat']; }
    if (!empty($_GET['q'])) {
        $where[] = '(item_en LIKE ? OR item_vn LIKE ? OR desc_en LIKE ? OR desc_vn LIKE ? OR notes_en LIKE ? OR notes_vn LIKE ?)';
        $like = '%' . $_GET['q'] . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    $sql = 'SELECT * FROM ratecard_items WHERE ' . implode(' AND ', $where)
         . ' ORDER BY sheet_key, cat_code, sort_order, id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    ok(['items' => $rows, 'total' => count($rows)]);
}

// ============================================================
//  ACTION: LIMITS — giới hạn dung lượng upload của máy chủ
// ============================================================
if ($action === 'limits') {
    ok([
        'max_bytes' => rc_maxUpload(),
        'max_mb'    => round(rc_maxUpload() / 1048576),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size'       => ini_get('post_max_size'),
    ]);
}

// ============================================================
//  ACTION: ZIP — tải file sản xuất của 1 sản phẩm
// ============================================================
if ($action === 'zip') {
    rc_requireLogin($pdo);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('Thiếu id');
    $st = $pdo->prepare("SELECT zip_file, zip_name FROM ratecard_items WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r || empty($r['zip_file'])) fail('Sản phẩm chưa có file sản xuất', 404);
    $path = rc_fileDir($id) . '/' . $r['zip_file'];
    if (!is_file($path)) fail('Không tìm thấy file trên máy chủ', 404);

    while (ob_get_level()) { ob_end_clean(); }
    header_remove('Content-Type');
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . rc_safeName($r['zip_name'] ?: $r['zip_file']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0');
    readfile($path);
    exit;
}

// ── Từ đây là thao tác ghi ────────────────────────────────────
if ($method !== 'POST') fail('Method not allowed', 405);

// ============================================================
//  ACTION: UPLOAD-ZIP / DELETE-ZIP — file sản xuất cho sản phẩm VFR
//  (multipart/form-data: id, file)
// ============================================================
if ($action === 'upload-zip') {
    rc_requireLogin($pdo);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) fail('Thiếu id sản phẩm');

    $st = $pdo->prepare("SELECT id, zip_file FROM ratecard_items WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) fail('Không tìm thấy sản phẩm', 404);

    $f   = $_FILES['file'] ?? null;
    $err = rc_checkZip($f);
    if ($err !== '') fail($err);

    $dir = rc_fileDir($id, true);
    if (!is_dir($dir)) fail('Không tạo được thư mục lưu file', 500);

    $orig  = rc_safeName($f['name']);
    $saved = 'zip-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 6) . '.zip';
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $saved)) fail('Không lưu được file lên máy chủ', 500);

    // Mỗi sản phẩm chỉ giữ 1 file — xoá file cũ
    if (!empty($row['zip_file']) && $row['zip_file'] !== $saved) @unlink($dir . '/' . $row['zip_file']);

    $pdo->prepare("UPDATE ratecard_items SET zip_file = ?, zip_name = ?, zip_size = ?, zip_at = NOW() WHERE id = ?")
        ->execute([$saved, $orig, (int)$f['size'], $id]);

    ok([
        'id'       => $id,
        'zip_file' => $saved,
        'zip_name' => $orig,
        'zip_size' => (int)$f['size'],
        'message'  => 'Đã tải lên: ' . $orig,
    ]);
}

if ($action === 'delete-zip') {
    rc_requireLogin($pdo);
    $id = (int)(body_json()['id'] ?? 0);
    if (!$id) fail('Thiếu id sản phẩm');
    $st = $pdo->prepare("SELECT zip_file FROM ratecard_items WHERE id = ?");
    $st->execute([$id]);
    $old = $st->fetchColumn();
    if ($old) @unlink(rc_fileDir($id) . '/' . $old);
    $pdo->prepare("UPDATE ratecard_items SET zip_file = NULL, zip_name = NULL, zip_size = 0, zip_at = NULL WHERE id = ?")
        ->execute([$id]);
    ok(['message' => 'Đã gỡ file sản xuất']);
}

// ============================================================
//  ACTION: CREATE
// ============================================================
if ($action === 'create') {
    $b = body_json();
    $sheet = $b['sheet_key'] ?? '';
    if (!isset($SHEETS[$sheet])) fail('sheet_key không hợp lệ');
    $itemEn = s($b['item_en'] ?? '', 300);
    if ($itemEn === '') fail("Tên hạng mục (item_en) là bắt buộc");

    // sort_order mặc định: cuối danh mục (hoặc cuối nhóm nếu không phân danh mục — VFR)
    if (!empty($b['cat_code'])) {
        $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM ratecard_items WHERE sheet_key=? AND cat_code=? AND deleted_at IS NULL');
        $st->execute([$sheet, $b['cat_code']]);
    } else {
        $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM ratecard_items WHERE sheet_key=? AND (cat_code IS NULL OR cat_code=\'\') AND deleted_at IS NULL');
        $st->execute([$sheet]);
    }
    $nextOrder = (int)$st->fetchColumn() ?: 1;

    $stmt = $pdo->prepare(
        'INSERT INTO ratecard_items
            (sheet_key, cat_code, cat_en, cat_vn, no_label, item_en, item_vn, desc_en, desc_vn,
             unit_en, unit_vn, basic, standard, premium, cost_price, notes_en, notes_vn, sort_order, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $sheet, s($b['cat_code'] ?? '', 5), s($b['cat_en'] ?? '', 200), s($b['cat_vn'] ?? '', 200),
        s($b['no_label'] ?? '', 20), $itemEn, s($b['item_vn'] ?? '', 300),
        s($b['desc_en'] ?? '', 2000), s($b['desc_vn'] ?? '', 2000),
        s($b['unit_en'] ?? '', 50), s($b['unit_vn'] ?? '', 50),
        num($b['basic'] ?? 0), num($b['standard'] ?? 0), num($b['premium'] ?? 0), num($b['cost_price'] ?? 0),
        s($b['notes_en'] ?? '', 2000), s($b['notes_vn'] ?? '', 2000),
        $nextOrder, s($b['updated_by'] ?? '', 120),
    ]);
    $id = (int)$pdo->lastInsertId();
    ok($pdo->query("SELECT * FROM ratecard_items WHERE id = $id")->fetch());
}

// ============================================================
//  ACTION: UPDATE
// ============================================================
if ($action === 'update') {
    $b  = body_json();
    $id = (int)($b['id'] ?? 0);
    if (!$id) fail('Thiếu id');

    $map = [
        'cat_code' => 5, 'cat_en' => 200, 'cat_vn' => 200, 'no_label' => 20,
        'item_en' => 300, 'item_vn' => 300, 'desc_en' => 2000, 'desc_vn' => 2000,
        'unit_en' => 50, 'unit_vn' => 50, 'notes_en' => 2000, 'notes_vn' => 2000,
        'updated_by' => 120,
    ];
    $sets = []; $params = [];
    foreach ($map as $field => $len) {
        if (array_key_exists($field, $b)) { $sets[] = "$field = ?"; $params[] = s($b[$field], $len); }
    }
    foreach (['basic', 'standard', 'premium', 'cost_price'] as $field) {
        if (array_key_exists($field, $b)) { $sets[] = "$field = ?"; $params[] = num($b[$field]); }
    }
    if (array_key_exists('sort_order', $b)) { $sets[] = 'sort_order = ?'; $params[] = (int)$b['sort_order']; }
    if (array_key_exists('sheet_key', $b) && isset($SHEETS[$b['sheet_key']])) { $sets[] = 'sheet_key = ?'; $params[] = $b['sheet_key']; }
    if (!$sets) fail('Không có gì để cập nhật');

    $params[] = $id;
    $pdo->prepare('UPDATE ratecard_items SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    ok($pdo->query("SELECT * FROM ratecard_items WHERE id = $id")->fetch());
}

// ============================================================
//  ACTION: DELETE (mềm) / RESTORE
// ============================================================
if ($action === 'delete') {
    $id = (int)(body_json()['id'] ?? 0);
    if (!$id) fail('Thiếu id');
    $stmt = $pdo->prepare('UPDATE ratecard_items SET deleted_at = NOW() WHERE id = ?');
    $stmt->execute([$id]);
    ok(['deleted' => $stmt->rowCount()]);
}

if ($action === 'restore') {
    $id = (int)(body_json()['id'] ?? 0);
    if (!$id) fail('Thiếu id');
    $stmt = $pdo->prepare('UPDATE ratecard_items SET deleted_at = NULL WHERE id = ?');
    $stmt->execute([$id]);
    ok(['restored' => $stmt->rowCount()]);
}

// Xoá vĩnh viễn — cần API key
if ($action === 'purge') {
    if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== API_SECRET) fail('Unauthorized', 401);
    $id = (int)(body_json()['id'] ?? 0);
    if (!$id) fail('Thiếu id');
    $st = $pdo->prepare('SELECT zip_file FROM ratecard_items WHERE id = ?');
    $st->execute([$id]);
    $z = $st->fetchColumn();
    if ($z) { $d = rc_fileDir($id); @unlink($d . '/' . $z); @rmdir($d); }
    $pdo->prepare('DELETE FROM ratecard_items WHERE id = ?')->execute([$id]);
    ok(['purged' => $id]);
}

// Chèn lại dữ liệu gốc — chỉ chạy khi bảng đang rỗng, cần API key
if ($action === 'reseed') {
    if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== API_SECRET) fail('Unauthorized', 401);
    $count = (int)$pdo->query("SELECT COUNT(*) FROM ratecard_items")->fetchColumn();
    if ($count > 0) fail('Bảng đã có dữ liệu — không tự động reseed để tránh trùng lặp. Xoá bảng thủ công nếu chắc chắn muốn nạp lại.');
    maybe_seed($pdo);
    ok(['seeded' => (int)$pdo->query("SELECT COUNT(*) FROM ratecard_items")->fetchColumn()]);
}

fail('Action không hợp lệ: ' . htmlspecialchars($action));
