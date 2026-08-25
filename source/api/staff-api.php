<?php
// ============================================================
// APSA — Staff API  /api/staff-api.php
// GET    → danh sách nhân sự active
// POST   → tạo mới
// PUT    → cập nhật (id required)
// DELETE → xoá (id required)
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db-config.php';

// ── Auth ─────────────────────────────────────────────────────
$key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($key !== API_SECRET) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── DB ───────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
}

// ── Auto-create table nếu chưa có ───────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `team_members` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100)  NOT NULL,
  `full_name`  VARCHAR(200)  DEFAULT NULL,
  `dept`       VARCHAR(100)  DEFAULT NULL,
  `active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Seed nếu bảng rỗng ───────────────────────────────────────
$count = $pdo->query("SELECT COUNT(*) FROM `team_members`")->fetchColumn();
if ((int)$count === 0) {
    $seed = [
        ['Harris',       'Harris Vo',               'Director'],
        ['Phương',       'Thái Lê Hoàng Phương',    'Video'],
        ['Anh Kim',      'Phan Lê Anh Kim',          'Account'],
        ['Anh Thư',      'Nguyễn Trần Anh Thư',      'Account'],
        ['Nguyên Thảo',  'Lý Nguyễn Nguyên Thảo',   'Account'],
        ['Nhật Tân',     'Nguyễn Nhật Tân',          'Account Leader'],
        ['Tiên',         'Ngô Thuỳ Tiên',            'Design'],
        ['Minh Trí',     'Nguyễn Phan Minh Trí',     'Designer Leader'],
        ['Thảo Vy',      'Nguyễn Lê Thảo Vy',        'Design'],
        ['Thảo Trang',   'Đỗ Thảo Trang',            'Admin'],
    ];
    $ins = $pdo->prepare("INSERT INTO `team_members` (`name`,`full_name`,`dept`) VALUES (?,?,?)");
    foreach ($seed as $row) $ins->execute($row);
}

$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $showAll = ($_GET['all'] ?? '') === '1';
    if ($showAll) {
        $rows = $pdo->query("SELECT * FROM `team_members` ORDER BY `id` ASC")->fetchAll();
    } else {
        $rows = $pdo->query("SELECT * FROM `team_members` WHERE `active`=1 ORDER BY `id` ASC")->fetchAll();
    }
    echo json_encode(['data' => $rows]);
    exit;
}

// ── POST ──────────────────────────────────────────────────────
if ($method === 'POST') {
    $name      = trim($body['name']      ?? '');
    $full_name = trim($body['full_name'] ?? '');
    $dept      = trim($body['dept']      ?? '');
    if (!$name) { http_response_code(400); echo json_encode(['error' => 'name required']); exit; }

    $st = $pdo->prepare("INSERT INTO `team_members` (`name`,`full_name`,`dept`) VALUES (?,?,?)");
    $st->execute([$name, $full_name ?: null, $dept ?: null]);
    $id = $pdo->lastInsertId();
    $row = $pdo->query("SELECT * FROM `team_members` WHERE `id`=$id")->fetch();
    http_response_code(201);
    echo json_encode(['data' => $row]);
    exit;
}

// ── PUT ───────────────────────────────────────────────────────
if ($method === 'PUT') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

    $fields = []; $params = [];
    foreach (['name','full_name','dept'] as $f) {
        if (isset($body[$f])) { $fields[] = "`$f`=?"; $params[] = trim($body[$f]); }
    }
    if (isset($body['active'])) { $fields[] = '`active`=?'; $params[] = (int)$body['active']; }
    if (!$fields) { http_response_code(400); echo json_encode(['error' => 'Nothing to update']); exit; }

    $params[] = $id;
    $pdo->prepare("UPDATE `team_members` SET ".implode(',',$fields)." WHERE `id`=?")->execute($params);
    $row = $pdo->query("SELECT * FROM `team_members` WHERE `id`=$id")->fetch();
    echo json_encode(['data' => $row]);
    exit;
}

// ── DELETE ────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
    $pdo->prepare("DELETE FROM `team_members` WHERE `id`=?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
