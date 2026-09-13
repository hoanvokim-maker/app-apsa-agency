<?php
/* APSA1857: trang khung anh cong khai - render <title> + og:* phia server de share link co ten su kien */
require_once __DIR__ . '/api/db-config.php';

$file = __DIR__ . '/f.html';
$html = @file_get_contents($file);
if ($html === false) {
    http_response_code(500);
    exit('Missing f.html');
}

$slug  = isset($_GET['e']) ? (string) $_GET['e'] : '';
$title = '';
$note  = '';
$img   = '';

if ($slug !== '' && preg_match('/^[A-Za-z0-9_-]{1,80}$/', $slug)) {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
        );
        $st = $pdo->prepare('SELECT `id`, `name`, `note`, `active` FROM `frame_events` WHERE `slug` = ? LIMIT 1');
        $st->execute(array($slug));
        $r = $st->fetch();
        if ($r && (int) $r['active']) {
            $title = trim((string) $r['name']);
            $note  = trim((string) $r['note']);
            $si = $pdo->prepare('SELECT `file` FROM `frame_items` WHERE `event_id` = ? ORDER BY `sort_order` ASC, `id` ASC LIMIT 1');
            $si->execute(array((int) $r['id']));
            $f = $si->fetch();
            if ($f && !empty($f['file'])) $img = (string) $f['file'];
        }
    } catch (Exception $e) {
        /* Khong tra loi ra ngoai: van phuc vu trang mac dinh, JS se tu bao loi. */
    }
}

if ($title === '') { $title = 'Khung ảnh sự kiện — APSA'; }
$desc = $note !== '' ? $note : 'Tạo ảnh đại diện với khung sự kiện: tải ảnh lên, chọn khung, lưu về máy.';

$e = function ($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
};

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.:_-]/', '', $_SERVER['HTTP_HOST']) : 'app.apsa.agency';
$base   = $scheme . '://' . $host;
$self   = $slug !== '' ? $base . '/f/' . rawurlencode($slug) : $base . '/f.html';
if ($img !== '') {
    if (strpos($img, 'http') !== 0) $img = $base . '/' . ltrim($img, '/');
} else {
    $img = $base . '/logo-apsa.png';
}

$meta = implode("\n", array(
    '',
    '<meta name="description" content="' . $e($desc) . '" />',
    '<meta property="og:type" content="website" />',
    '<meta property="og:site_name" content="APSA Agency" />',
    '<meta property="og:url" content="' . $e($self) . '" />',
    '<meta property="og:title" content="' . $e($title) . '" />',
    '<meta property="og:description" content="' . $e($desc) . '" />',
    '<meta property="og:image" content="' . $e($img) . '" />',
    '<meta name="twitter:card" content="summary_large_image" />',
    '<meta name="twitter:title" content="' . $e($title) . '" />',
    '<meta name="twitter:description" content="' . $e($desc) . '" />',
    '<meta name="twitter:image" content="' . $e($img) . '" />',
    ''
));

$out = preg_replace(
    '#<title>.*?</title>#is',
    '<title>' . $e($title) . '</title>' . $meta,
    $html,
    1
);
if ($out === null || $out === '') { $out = $html; }

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $out;
