<?php
/* APSA1942 — phuc vu /ck/<ma>: chen the og: de link co thumbnail khi share */
$t = isset($_GET['t']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) $_GET['t']) : '';
$t = strtolower($t);
$title = 'Tien do du an';
$desc  = 'Theo doi tien do du an cung APSA.';
$img   = '';
if (strlen($t) >= 8 && strlen($t) <= 40) {
    try {
        require __DIR__ . '/api/db-config.php';
        $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $st = $pdo->prepare("SELECT q.* FROM `quotation_shares` s
                             JOIN `quotations` q ON q.id = s.quotation_id
                             WHERE (s.token = ? OR s.slug = ?) AND s.revoked_at IS NULL
                               AND s.scope = 'chk' AND q.deleted_at IS NULL LIMIT 1");
        $st->execute(array($t, $t));
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            if (trim((string) $r['title']) !== '') $title = (string) $r['title'];
            $cl = trim((string) $r['client_name']);
            $desc = 'Tien do du an' . ($cl !== '' ? ' - ' . $cl : '') . ' | APSA';
            $th = isset($r['thumb']) ? (string) $r['thumb'] : '';
            if (preg_match('/^q[0-9]+_[a-f0-9]{8}[.]jpg$/', $th)) $img = '/uploads/thumbs/' . $th;
        }
    } catch (Exception $e) { }
}
$html = @file_get_contents(__DIR__ . '/checklist.html');
if ($html === false) { http_response_code(500); echo 'Khong doc duoc trang.'; exit; }
$sc   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'app.apsa.agency';
$base = $sc . '://' . $host;
$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$og  = '<meta property="og:type" content="website" />' . "\n";
$og .= '<meta property="og:site_name" content="APSA" />' . "\n";
$og .= '<meta property="og:title" content="' . $e($title) . '" />' . "\n";
$og .= '<meta property="og:description" content="' . $e($desc) . '" />' . "\n";
$og .= '<meta property="og:url" content="' . $e($base . $_SERVER['REQUEST_URI']) . '" />' . "\n";
$og .= '<meta name="twitter:card" content="' . ($img ? 'summary_large_image' : 'summary') . '" />' . "\n";
$og .= '<meta name="twitter:title" content="' . $e($title) . '" />' . "\n";
$og .= '<meta name="twitter:description" content="' . $e($desc) . '" />' . "\n";
if ($img) {
    $og .= '<meta property="og:image" content="' . $e($base . $img) . '" />' . "\n";
    $og .= '<meta property="og:image:alt" content="' . $e($title) . '" />' . "\n";
    $og .= '<meta name="twitter:image" content="' . $e($base . $img) . '" />' . "\n";
}
$html = str_replace('</head>', $og . '</head>', $html);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
echo $html;
