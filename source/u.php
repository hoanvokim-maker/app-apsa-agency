<?php
// =========================================================
// APSA — Short Link redirector
// /u/<code>  →  (rewrite)  →  /u.php?c=<code>
// Công khai, không cần đăng nhập.
// =========================================================

require_once __DIR__ . '/api/db-config.php';

$code = (string) ($_GET['c'] ?? '');

// Cho phép gọi trực tiếp /u.php/<code> nếu rewrite không hoạt động
if ($code === '' && !empty($_SERVER['PATH_INFO'])) {
    $code = ltrim($_SERVER['PATH_INFO'], '/');
}

function sl_not_found($msg = 'Link không tồn tại hoặc đã bị gỡ.') {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    $m = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!doctype html><html lang="vi"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Không tìm thấy link — APSA</title>
<link rel="icon" href="/favicon.ico">
<style>
  :root{color-scheme:dark}
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0d0d0d;color:#f2f2f2;
       font-family:'Oxanium',system-ui,-apple-system,'Segoe UI',sans-serif;padding:24px}
  .b{max-width:420px;text-align:center}
  .d{width:64px;height:64px;margin:0 auto 20px;border-radius:16px;background:#1a1a1a;
     display:grid;place-items:center;border:1px solid #2a2a2a}
  .d svg{width:30px;height:30px;stroke:#dff20d;fill:none;stroke-width:2;stroke-linecap:round}
  h1{font-size:19px;margin:0 0 8px;font-weight:600;letter-spacing:.01em}
  p{margin:0 0 24px;color:#8a8a8a;font-size:14px;line-height:1.6}
  a{display:inline-block;padding:11px 22px;border-radius:10px;background:#dff20d;color:#0d0d0d;
    text-decoration:none;font-weight:600;font-size:14px}
</style></head><body>
<div class="b">
  <div class="d"><svg viewBox="0 0 24 24"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg></div>
  <h1>Không tìm thấy link</h1>
  <p>$m</p>
  <a href="https://apsa.agency/">Về trang chủ APSA</a>
</div></body></html>
HTML;
    exit;
}

if ($code === '' || !preg_match('/^[A-Za-z0-9_-]{3,32}$/', $code)) sl_not_found();

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(503);
    exit('Service unavailable');
}

$st = $pdo->prepare('SELECT id, url, active FROM `short_links` WHERE code = ?');
$st->execute([$code]);
$row = $st->fetch();

if (!$row)              sl_not_found();
if (!(int) $row['active']) sl_not_found('Link này đã được tạm ngưng.');

$dest = (string) $row['url'];
if (!preg_match('#^https?://#i', $dest)) sl_not_found('Link đích không hợp lệ.');

// Đếm lượt bấm — lỗi ở bước này không được chặn việc chuyển hướng
try {
    $up = $pdo->prepare('UPDATE `short_links` SET clicks = clicks + 1, last_click_at = NOW() WHERE id = ?');
    $up->execute([(int) $row['id']]);
} catch (Exception $e) { /* bỏ qua */ }

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer-when-downgrade');
header('Location: ' . $dest, true, 302);
exit;
