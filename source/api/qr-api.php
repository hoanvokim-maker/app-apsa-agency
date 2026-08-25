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
if ($method === 'GET') {
    $stmt = $pdo->query(
        'SELECT id, uid, team, type, url, name, start_time, end_time, location, description, svg, png, created_at, updated_at
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
        'INSERT INTO qr_events (uid, team, type, url, name, start_time, end_time, location, description, svg, png)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
    ]);

    $id = (int)$pdo->lastInsertId();
    ok(['id' => $id, 'message' => 'QR saved']);
}

// ── PUT: update existing QR ───────────────────────────────────
if ($method === 'PUT') {
    $id   = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id is required');

    $check = $pdo->prepare('SELECT id FROM qr_events WHERE id = ?');
    $check->execute([$id]);
    if (!$check->fetch()) fail('Not found', 404);

    $type = trim($body['type'] ?? 'event');
    if (!in_array($type, ['event', 'link'])) $type = 'event';

    $stmt = $pdo->prepare(
        'UPDATE qr_events SET type=?, url=?, name=?, start_time=?, end_time=?, location=?, description=?, svg=?, png=?, team=?
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
        $id,
    ]);

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
