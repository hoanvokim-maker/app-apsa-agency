<?php
// ============================================================
// APSA — Slide Tracking API  /api/tracking-api.php
//
// POST (public, không cần key) → lưu 1 record hoặc mảng records
//   JSON body:
//   {
//     "project":       "Tên dự án",            (bắt buộc)
//     "user_name":     "Tên người sử dụng",     (bắt buộc)
//     "hospital":      "Tên bệnh viện",
//     "ip":            "1.2.3.4",               (tuỳ chọn — nếu bỏ trống server tự lấy)
//     "started_at":    "2026-07-12 14:30:00",   (tuỳ chọn — mặc định thời điểm hiện tại)
//     "slide":         "5" hoặc "intro",
//     "view_seconds":  12.5                     (thời gian xem, giây)
//   }
//   Hoặc gửi mảng: [ {...}, {...} ] để lưu nhiều record 1 lần.
//
// GET  (cần X-API-Key) → lấy danh sách
//   ?project=xxx     lọc theo dự án
//   ?user=xxx        lọc theo tên người dùng
//   ?from=YYYY-MM-DD lọc từ ngày
//   ?to=YYYY-MM-DD   lọc đến ngày
//   ?limit=500       số dòng (mặc định 500, tối đa 5000)
//
// DELETE ?id=xxx (cần X-API-Key) → xóa record
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db-config.php';

// ── Helpers ──────────────────────────────────────────────────
function ok($data)             { echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE); exit; }
function fail($msg, $code=400) { http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

function client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return null;
}

// ── DB ───────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fail('DB connection failed', 500);
}

// ── Auto-create table nếu chưa có ────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `slide_tracking` (
  `id`           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `project`      VARCHAR(300)   NOT NULL COMMENT 'Tên dự án',
  `user_name`    VARCHAR(200)   NOT NULL COMMENT 'Tên người sử dụng',
  `hospital`     VARCHAR(300)   DEFAULT NULL COMMENT 'Bệnh viện của người sử dụng',
  `ip`           VARCHAR(45)    DEFAULT NULL COMMENT 'Địa chỉ IP (IPv4/IPv6)',
  `started_at`   DATETIME       NOT NULL COMMENT 'Ngày giờ bắt đầu xem',
  `slide`        VARCHAR(100)   DEFAULT NULL COMMENT 'Slide (số hoặc tên)',
  `view_seconds` DECIMAL(10,2)  DEFAULT NULL COMMENT 'Thời gian xem (giây)',
  `created_at`   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_project`    (`project`(191)),
  INDEX `idx_user`       (`user_name`(191)),
  INDEX `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$method = $_SERVER['REQUEST_METHOD'];

// ── POST: lưu tracking (public — không cần API key) ──────────
if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if ($body === null) fail('Invalid JSON body');

    // Cho phép 1 object hoặc 1 mảng objects
    $records = (isset($body[0]) && is_array($body[0])) ? $body : [$body];
    if (count($records) > 200) fail('Tối đa 200 records mỗi lần gửi');

    $stmt = $pdo->prepare(
        'INSERT INTO slide_tracking (project, user_name, hospital, ip, started_at, slide, view_seconds)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $serverIp = client_ip();
    $ids = [];

    foreach ($records as $i => $r) {
        $project  = trim((string)($r['project']   ?? $r['project_name'] ?? ''));
        $userName = trim((string)($r['user_name'] ?? $r['username']     ?? $r['name'] ?? ''));
        if ($project === '')  fail("Record #$i: 'project' is required");
        if ($userName === '') fail("Record #$i: 'user_name' is required");

        $hospital = isset($r['hospital']) ? mb_substr(trim((string)$r['hospital']), 0, 300) : null;

        // IP: ưu tiên client gửi lên, nếu không có thì server tự lấy
        $ip = trim((string)($r['ip'] ?? $r['ip_address'] ?? ''));
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) $ip = $serverIp;

        // started_at: nhận 'YYYY-MM-DD HH:MM:SS', ISO 8601, hoặc timestamp ms
        $startedAt = $r['started_at'] ?? $r['start_time'] ?? null;
        if (is_numeric($startedAt)) {
            $ts = (float)$startedAt;
            if ($ts > 1e12) $ts = $ts / 1000; // milliseconds → seconds
            $startedAt = date('Y-m-d H:i:s', (int)$ts);
        } elseif (is_string($startedAt) && $startedAt !== '') {
            $t = strtotime($startedAt);
            $startedAt = $t ? date('Y-m-d H:i:s', $t) : null;
        } else {
            $startedAt = null;
        }
        if (!$startedAt) $startedAt = date('Y-m-d H:i:s');

        $slide = isset($r['slide']) ? mb_substr(trim((string)$r['slide']), 0, 100) : null;

        $viewSeconds = $r['view_seconds'] ?? $r['view_time'] ?? $r['duration'] ?? null;
        $viewSeconds = is_numeric($viewSeconds) ? round((float)$viewSeconds, 2) : null;

        try {
            $stmt->execute([
                mb_substr($project, 0, 300),
                mb_substr($userName, 0, 200),
                $hospital, $ip, $startedAt, $slide, $viewSeconds,
            ]);
            $ids[] = (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            fail("Record #$i: insert failed", 500);
        }
    }

    ok(['ids' => $ids, 'count' => count($ids)]);
}

// ── Từ đây trở xuống cần API key ─────────────────────────────
$key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($key !== API_SECRET) fail('Unauthorized', 401);

// ── GET: danh sách tracking ──────────────────────────────────
if ($method === 'GET') {
    $where  = [];
    $params = [];

    if (!empty($_GET['project'])) { $where[] = 'project = ?';         $params[] = $_GET['project']; }
    if (!empty($_GET['user']))    { $where[] = 'user_name = ?';       $params[] = $_GET['user']; }
    if (!empty($_GET['from']))    { $where[] = 'started_at >= ?';     $params[] = $_GET['from'] . ' 00:00:00'; }
    if (!empty($_GET['to']))      { $where[] = 'started_at <= ?';     $params[] = $_GET['to']   . ' 23:59:59'; }

    $limit = min(max((int)($_GET['limit'] ?? 500), 1), 5000);
    $sql   = 'SELECT * FROM slide_tracking'
           . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
           . ' ORDER BY started_at DESC, id DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    ok($stmt->fetchAll());
}

// ── DELETE: xóa record ───────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id is required');
    $stmt = $pdo->prepare('DELETE FROM slide_tracking WHERE id = ?');
    $stmt->execute([$id]);
    ok(['deleted' => $stmt->rowCount()]);
}

fail('Method not allowed', 405);
