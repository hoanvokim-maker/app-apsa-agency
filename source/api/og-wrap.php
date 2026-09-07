<?php
/**
 * APSA1816 - Boc <title> + the og:* phia server cho cac trang duoc chia se
 * (Zalo / Messenger / Facebook khong chay JS nen phai render san).
 * File .html van la nguon duy nhat cua giao dien.
 */

function apsa_og_pdo()
{
    return new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
}

function apsa_og_wrap($htmlFile, $selfPath, $title, $desc, $ogType = 'website', $ogImage = '')
{
    $html = @file_get_contents($htmlFile);
    if ($html === false) {
        http_response_code(500);
        exit('Missing ' . basename($htmlFile));
    }

    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = isset($_SERVER['HTTP_HOST'])
        ? preg_replace('/[^A-Za-z0-9.:-]/', '', $_SERVER['HTTP_HOST'])
        : 'app.apsa.agency';
    $qs     = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
    $self   = $scheme . '://' . $host . $selfPath . ($qs !== '' ? '?' . $qs : '');

    /* APSA1825: cho phep dat anh rieng (thumbnail playlist), khong co thi dung icon app */
    $ogImage = trim((string) $ogImage);
    $big     = ($ogImage !== '');
    $img     = $big ? ($scheme . '://' . $host . '/' . ltrim($ogImage, '/'))
                    : ($scheme . '://' . $host . '/icon-512.png');
    $meta = "\n<meta property=\"og:type\" content=\"" . $e($ogType) . "\" />"
          . "\n<meta property=\"og:site_name\" content=\"APSA Agency\" />"
          . "\n<meta property=\"og:url\" content=\"" . $e($self) . "\" />"
          . "\n<meta property=\"og:title\" content=\"" . $e($title) . "\" />"
          . "\n<meta property=\"og:description\" content=\"" . $e($desc) . "\" />"
          . "\n<meta property=\"og:image\" content=\"" . $e($img) . "\" />"
          . ($big ? "" : "\n<meta property=\"og:image:width\" content=\"512\" />"
                       . "\n<meta property=\"og:image:height\" content=\"512\" />")
          . "\n<meta name=\"twitter:image\" content=\"" . $e($img) . "\" />"
          . "\n<meta name=\"twitter:card\" content=\"" . ($big ? 'summary_large_image' : 'summary') . "\" />"
          . "\n<meta name=\"twitter:title\" content=\"" . $e($title) . "\" />"
          . "\n<meta name=\"twitter:description\" content=\"" . $e($desc) . "\" />";
    /* Dung callback de ky tu $ hoac \ trong tieu de khong bi hieu la backreference */
    $rep = '<title>' . $e($title) . '</title>' . $meta;
    $out = preg_replace_callback(
        '#<title>.*?</title>#is',
        function () use ($rep) { return $rep; },
        $html,
        1
    );
    if ($out === null || $out === '') $out = $html;

    $etag = '"' . md5($out) . '"';
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('ETag: ' . $etag);
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }
    echo $out;
    exit;
}
