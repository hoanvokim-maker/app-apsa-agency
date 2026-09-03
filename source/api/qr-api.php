<?php
// ============================================================
// APSA QR API (v2 — no uid filter, supports type: event|link)
// Endpoints:
//   GET                          → lấy tất cả QR (mới nhất trước)
//   POST   (JSON body)           → tạo QR mới
//   PUT    ?id=xxx (JSON body)   → cập nhật QR
//   DELETE ?id=xxx               → xóa QR
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db-config.php';

// ── Auth ──────────────────────────────────────────────────────
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($apiKey !== API_SECRET) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── DB connection ─────────────────────────────────────────────
try {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
}

require_once __DIR__ . '/session-boot.php';

/* --- Nguoi tao + lich su chinh sua --- */
foreach (array(
    'created_by' => "ALTER TABLE `qr_events` ADD COLUMN `created_by` VARCHAR(120) NOT NULL DEFAULT ''",
    'updated_by' => "ALTER TABLE `qr_events` ADD COLUMN `updated_by` VARCHAR(120) NOT NULL DEFAULT ''",
) as $qcol => $qsql) {
    try {
        $qh = $pdo->query("SHOW COLUMNS FROM `qr_events` LIKE " . $pdo->quote($qcol))->fetch();
        if (!$qh) $pdo->exec($qsql);
    } catch (PDOException $e) { }
}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `qr_event_log` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `event_id` INT UNSIGNED NOT NULL,
      `actor` VARCHAR(120) NOT NULL DEFAULT '',
      `act` VARCHAR(16) NOT NULL DEFAULT 'edit',
      `detail` TEXT NULL,
      `at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`), KEY `k_ev` (`event_id`, `id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) { }

function qrActor(PDO $pdo)
{
    if (empty($_SESSION['user_id'])) return '';
    try {
        $st = $pdo->prepare("SELECT username, display_name FROM `app_users` WHERE id = ?");
        $st->execute(array($_SESSION['user_id']));
        $u = $st->fetch();
        if (!$u) return '';
        return trim((string) $u['display_name']) !== '' ? $u['display_name'] : $u['username'];
    } catch (PDOException $e) { return ''; }
}

function qrLog(PDO $pdo, $eventId, $act, $detail, $actor)
{
    try {
        $st = $pdo->prepare("INSERT INTO `qr_event_log` (event_id, actor, act, detail) VALUES (?,?,?,?)");
        $st->execute(array((int) $eventId, (string) $actor, (string) $act, (string) $detail));
    } catch (PDOException $e) { }
}

function qrDiff($old, $body)
{
    $map = array('name' => 'Tên', 'url' => 'Đường dẫn', 'type' => 'Loại', 'loc' => 'Địa điểm',
                 'description' => 'Mô tả', 'start' => 'Bắt đầu', 'end' => 'Kết thúc', 'team' => 'Nhóm');
    $col = array('loc' => 'location', 'start' => 'start_time', 'end' => 'end_time');
    $out = array();
    foreach ($map as $k => $lb) {
        if (!array_key_exists($k, $body)) continue;
        $src = isset($col[$k]) ? $col[$k] : $k;
        $a = isset($old[$src]) ? (string) $old[$src] : '';
        $b = (string) $body[$k];
        if ($k === 'start' || $k === 'end') $b = (string) parseLocalDT($b);
        if (trim($a) === trim($b)) continue;
        $out[] = $lb . ': "' . mb_substr($a, 0, 60, 'UTF-8') . '" -> "' . mb_substr($b, 0, 60, 'UTF-8') . '"';
    }
    if (isset($body['svg']) || isset($body['png'])) $out[] = 'Cập nhật ảnh QR';
    return implode(' · ', $out);
}

$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// ── Helpers ───────────────────────────────────────────────────
function ok($data)             { echo json_encode(['ok' => true, 'data' => $data]); exit; }
function fail($msg, $code=400) { http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }

function parseLocalDT($s) {
    if (!$s) return null;
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $s);
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

// ── GET: list all QRs ─────────────────────────────────────────
if ($method === 'GET' && (isset($_GET['action']) ? $_GET['action'] : '') === 'log') {
    $qeid = (int) (isset($_GET['id']) ? $_GET['id'] : 0);
    try {
        $st = $pdo->prepare("SELECT actor, act, detail, at FROM `qr_event_log`
            WHERE event_id = ? ORDER BY id DESC LIMIT 100");
        $st->execute(array($qeid));
        ok($st->fetchAll());
    } catch (PDOException $e) { ok(array()); }
}

if ($method === 'GET') {
    $stmt = $pdo->query(
        'SELECT id, uid, team, type, url, name, start_time, end_time, location, description, svg, png, created_at, updated_at, created_by, updated_by
         FROM qr_events ORDER BY created_at DESC LIMIT 200'
    );
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['type']    = $row['type'] ?? 'event';
        $row['start']   = $row['start_time'] ? (new DateTime($row['start_time']))->format('Y-m-d\TH:i') : '';
        $row['end']     = $row['end_time']   ? (new DateTime($row['end_time']))->format('Y-m-d\TH:i') : '';
        $row['loc']     = $row['location'] ?? '';
        $row['savedAt'] = (new DateTime($row['updated_at']))->format('d/m/Y H:i');
        unset($row['start_time'], $row['end_time'], $row['location']);
    }

    ok($rows);
}

// ── POST: create new QR ───────────────────────────────────────
if ($method === 'POST') {
    $name = trim($body['name'] ?? '');
    $type = trim($body['type'] ?? 'event');
    if (!$name) fail('name is required');
    if (!in_array($type, ['event', 'link'])) $type = 'event';

    $stmt = $pdo->prepare(
        'INSERT INTO qr_events (uid, team, type, url, name, start_time, end_time, location, description, svg, png, created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $body['uid']         ?? null,
        $body['team']        ?? null,
        $type,
        $body['url']         ?? null,
        $name,
        parseLocalDT($body['start'] ?? ''),
        parseLocalDT($body['end']   ?? ''),
        $body['loc']         ?? null,
        $body['description'] ?? null,
        $body['svg']         ?? null,
        $body['png']         ?? null,
        $qrWho = qrActor($pdo),
        $qrWho,
    ]);

    $id = (int)$pdo->lastInsertId();
    qrLog($pdo, $id, 'create', 'Tạo mới: ' . mb_substr($name, 0, 80, 'UTF-8'), $qrWho);
    ok(['id' => $id, 'message' => 'QR saved']);
}

// ── PUT: update existing QR ───────────────────────────────────
if ($method === 'PUT') {
    $id   = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id is required');

    $check = $pdo->prepare('SELECT * FROM qr_events WHERE id = ?');
    $check->execute([$id]);
    $qrOld = $check->fetch();
    if (!$qrOld) fail('Not found', 404);

    $type = trim($body['type'] ?? 'event');
    if (!in_array($type, ['event', 'link'])) $type = 'event';

    $stmt = $pdo->prepare(
        'UPDATE qr_events SET type=?, url=?, name=?, start_time=?, end_time=?, location=?, description=?, svg=?, png=?, team=?, updated_by=?
         WHERE id=?'
    );
    $stmt->execute([
        $type,
        $body['url']         ?? null,
        trim($body['name']   ?? ''),
        parseLocalDT($body['start'] ?? ''),
        parseLocalDT($body['end']   ?? ''),
        $body['loc']         ?? null,
        $body['description'] ?? null,
        $body['svg']         ?? null,
        $body['png']         ?? null,
        $body['team']        ?? null,
        $qrWho = qrActor($pdo),
        $id,
    ]);

    $qrDet = qrDiff($qrOld, $body);
    qrLog($pdo, $id, 'edit', $qrDet !== '' ? $qrDet : 'Lưu lại (không đổi nội dung)', $qrWho);

    ok(['id' => $id, 'message' => 'QR updated']);
}

// ── DELETE: remove QR ─────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id is required');

    $stmt = $pdo->prepare('DELETE FROM qr_events WHERE id = ?');
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) fail('Not found', 404);
    ok(['message' => 'QR deleted']);
}

fail('Method not allowed', 405);
