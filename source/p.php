<?php
/* APSA1916: /p/<token> - phat lai anh ghep tu SharePoint cho dien thoai quet QR */
require_once __DIR__ . '/api/db-config.php';
require_once __DIR__ . '/api/frame-sp.php';

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');

$t = isset($_GET['t']) ? preg_replace('/[^a-f0-9]/', '', (string) $_GET['t']) : '';
if (strlen($t) !== 32) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Khong tim thay anh.'); }

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $st = $pdo->prepare('SELECT sp_item, fname FROM frame_shots WHERE token = ? LIMIT 1');
    $st->execute(array($t));
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { $row = null; }

if (!$row) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Khong tim thay anh.'); }

$err = '';
$tok = apsp_tok($err);
$png = $tok ? fsp_get_png($tok, $row['sp_item']) : false;
if ($png === false) { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); exit('Chua lay duoc anh, thu lai sau it phut.'); }

$name = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $row['fname']);
if ($name === '') $name = 'apsa-frame.png';

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Content-Disposition: inline; filename="' . $name . '"');
header('Cache-Control: private, max-age=900');
echo $png;
