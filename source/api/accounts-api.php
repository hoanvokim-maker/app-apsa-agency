<?php
// ============================================================
// APSA Accounts API
// Endpoints:
//   GET    ?                    → lấy tất cả accounts
//   POST   (JSON body)          → tạo account mới
//   PUT    ?id=xxx (JSON body)  → cập nhật account
//   DELETE ?id=xxx              → xóa account
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

// Preflight
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

// ── GET: list all accounts ────────────────────────────────────
if ($method === 'GET') {
    $stmt = $pdo->query(
        'SELECT id, platform, label, username, password, people, note, created_at, updated_at
         FROM accounts ORDER BY id ASC'
    );
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        // Decode JSON people field
        $row['people'] = $row['people'] ? json_decode($row['people'], true) : [];
    }

    ok($rows);
}

// ── POST: create account ──────────────────────────────────────
if ($method === 'POST') {
    $username = trim($body['username'] ?? '');
    $platform = trim($body['platform'] ?? '');
    $label    = trim($body['label']    ?? '');
    $password = trim($body['password'] ?? '');

    if (!$username) fail('username is required');
    if (!$platform) fail('platform is required');
    if (!$label)    fail('label is required');

    $people = isset($body['people']) ? json_encode($body['people'], JSON_UNESCAPED_UNICODE) : null;
    $note   = $body['note'] ?? null;

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO accounts (platform, label, username, password, people, note)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$platform, $label, $username, $password, $people, $note]);
        $id = (int)$pdo->lastInsertId();
        ok(['id' => $id, 'message' => 'Account created']);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            fail('Username đã tồn tại', 409);
        }
        fail($e->getMessage());
    }
}

// ── PUT: update account ───────────────────────────────────────
if ($method === 'PUT') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id is required');

    // Check exists
    $check = $pdo->prepare('SELECT id FROM accounts WHERE id = ?');
    $check->execute([$id]);
    if (!$check->fetch()) fail('Not found', 404);

    $people = isset($body['people']) ? json_encode($body['people'], JSON_UNESCAPED_UNICODE) : null;

    $stmt = $pdo->prepare(
        'UPDATE accounts SET platform=?, label=?, username=?, password=?, people=?, note=?
         WHERE id=?'
    );
    $stmt->execute([
        trim($body['platform'] ?? ''),
        trim($body['label']    ?? ''),
        trim($body['username'] ?? ''),
        trim($body['password'] ?? ''),
        $people,
        $body['note'] ?? null,
        $id,
    ]);

    ok(['id' => $id, 'message' => 'Account updated']);
}

// ── DELETE: remove account ────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id is required');

    $stmt = $pdo->prepare('DELETE FROM accounts WHERE id = ?');
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) fail('Not found', 404);
    ok(['message' => 'Account deleted']);
}

fail('Method not allowed', 405);
