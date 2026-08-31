<?php
/**
 * APSA - Trang duyet video (lop boc render tieu de + OG phia server).
 *
 * .htaccess dieu huong /review.html -> /review.php, nen moi link chia se cu
 * van chay binh thuong, nhung tieu de trang va preview khi dan link vao
 * Zalo / Messenger / Facebook se hien dung ten video.
 *
 * File review.html van la nguon duy nhat cua giao dien; file nay chi doc no
 * tu dia va thay the the <title> + chen the og:*.
 */

require_once __DIR__ . '/api/db-config.php';

$file = __DIR__ . '/review.html';
$html = @file_get_contents($file);
if ($html === false) {
    http_response_code(500);
    exit('Missing review.html');
}

$t     = isset($_GET['t']) ? (string) $_GET['t'] : '';
$title = '';
$note  = '';

if ($t !== '' && preg_match('/^[A-Za-z0-9]{8,64}$/', $t)) {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
        );
        $st = $pdo->prepare('SELECT `title`, `note`, `file_name`, `active` FROM `video_reviews` WHERE `token` = ? LIMIT 1');
        $st->execute(array($t));
        $r = $st->fetch();
        if ($r && (int) $r['active']) {
            $title = trim((string) $r['title']) !== '' ? trim((string) $r['title']) : trim((string) $r['file_name']);
            $note  = trim((string) $r['note']);
        }
    } catch (Exception $e) {
        /* Khong tra loi ra ngoai: van phuc vu trang mac dinh, JS se tu bao loi. */
    }
}

if ($title === '') {
    $title = 'Duyet video - APSA';
}
$desc = $note !== '' ? $note : 'Xem va gop y truc tiep tren tung giay cua video.';

$e = function ($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
};

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.:-]/', '', $_SERVER['HTTP_HOST']) : 'app.apsa.agency';
$self   = $scheme . '://' . $host . '/review.html' . ($t !== '' ? '?t=' . rawurlencode($t) : '');

$meta = "\n<meta property=\"og:type\" content=\"video.other\" />"
      . "\n<meta property=\"og:site_name\" content=\"APSA Agency\" />"
      . "\n<meta property=\"og:url\" content=\"" . $e($self) . "\" />"
      . "\n<meta property=\"og:title\" content=\"" . $e($title) . "\" />"
      . "\n<meta property=\"og:description\" content=\"" . $e($desc) . "\" />"
      . "\n<meta name=\"twitter:card\" content=\"summary\" />"
      . "\n<meta name=\"twitter:title\" content=\"" . $e($title) . "\" />"
      . "\n<meta name=\"twitter:description\" content=\"" . $e($desc) . "\" />";

$out = preg_replace(
    '#<title>.*?</title>#is',
    '<title>' . $e($title) . '</title>' . $meta,
    $html,
    1
);
if ($out === null || $out === '') {
    $out = $html;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $out;
