<?php
// ============================================================
// APSA — Inspiration Board API  /api/inspiration-api.php
//
// Lưu ý tưởng cho sự kiện: ảnh, video, link FB/IG/YouTube/TikTok…
//
// GET  ?action=list&tag=&q=&limit=&offset=   → danh sách items
// GET  ?action=tags                          → tất cả tag + số lượng
// POST ?action=upload   (multipart/form-data) → upload 1 hoặc nhiều file
//        fields: files[] , tags, note, added_by
// POST ?action=link     (JSON)                → thêm link
//        { url, title, note, tags, added_by }
// POST ?action=update   (JSON)                → sửa title/note/tags/pinned
//        { id, title, note, tags, pinned }
// POST ?action=delete   (JSON) { id }         → xoá mềm (vào thùng rác)
// POST ?action=restore  (JSON) { id }         → khôi phục
// GET  ?action=meta&url=...                   → lấy preview metadata của 1 link
// ============================================================

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db-config.php';

// ── Cấu hình ─────────────────────────────────────────────────
define('UPLOAD_DIR',  __DIR__ . '/../uploads/inspiration');
define('MAX_IMAGE',   25 * 1024 * 1024);   // 25 MB
define('MAX_VIDEO',   200 * 1024 * 1024);  // 200 MB
define('THUMB_W',     720);                // chiều rộng tối đa của thumbnail

$ALLOWED_IMAGE = ['jpg','jpeg','png','gif','webp','avif','bmp','heic','heif'];
$ALLOWED_VIDEO = ['mp4','mov','webm','m4v','avi','mkv'];
$ALLOWED_DOC   = ['pdf'];

// ── Helpers ──────────────────────────────────────────────────
function ok($data)             { echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE); exit; }
function fail($msg, $code=400) { http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

/** Đường dẫn web tới thư mục gốc của site (vd: "" hoặc "/apps") */
function base_path() {
    $p = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/x.php')));
    return ($p === '/' || $p === '.') ? '' : rtrim($p, '/');
}
function public_url($relative) { return base_path() . '/uploads/inspiration/' . $relative; }

function body_json() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

/** Chuẩn hoá chuỗi tag: "Backdrop, Sân khấu" → "backdrop,sân khấu" */
function norm_tags($raw) {
    if (is_array($raw)) $parts = $raw;
    else                $parts = preg_split('/[,;#]+/u', (string)$raw);
    $out = [];
    foreach ($parts as $p) {
        $p = trim(preg_replace('/\s+/u', ' ', (string)$p));
        if ($p === '') continue;
        $p = mb_strtolower($p, 'UTF-8');
        if (mb_strlen($p) > 40) $p = mb_substr($p, 0, 40);
        if (!in_array($p, $out, true)) $out[] = $p;
    }
    return implode(',', array_slice($out, 0, 15));
}

function slugify_name($name) {
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
    $name = trim($name, '-.');
    return $name === '' ? 'file' : mb_substr($name, 0, 60);
}

// ── Kết nối DB ───────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fail('DB connection failed', 500);
}

// ── Tạo bảng nếu chưa có ─────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `inspiration_items` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `type`        VARCHAR(20)   NOT NULL DEFAULT 'image' COMMENT 'image|video|embed|link|file',
  `source`      VARCHAR(30)   NOT NULL DEFAULT 'upload' COMMENT 'upload|youtube|facebook|instagram|tiktok|pinterest|web',
  `title`       VARCHAR(300)  DEFAULT NULL,
  `note`        TEXT          DEFAULT NULL,
  `url`         TEXT          NOT NULL         COMMENT 'link gốc hoặc đường dẫn file',
  `thumb`       TEXT          DEFAULT NULL     COMMENT 'ảnh preview',
  `embed`       TEXT          DEFAULT NULL     COMMENT 'iframe src để nhúng',
  `file_name`   VARCHAR(255)  DEFAULT NULL,
  `file_size`   INT UNSIGNED  DEFAULT NULL,
  `width`       INT UNSIGNED  DEFAULT NULL,
  `height`      INT UNSIGNED  DEFAULT NULL,
  `tags`        VARCHAR(600)  DEFAULT NULL,
  `added_by`    VARCHAR(120)  DEFAULT NULL,
  `pinned`      TINYINT(1)    NOT NULL DEFAULT 0,
  `deleted_at`  DATETIME      DEFAULT NULL,
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_created` (`created_at`),
  INDEX `idx_deleted` (`deleted_at`),
  INDEX `idx_type`    (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
//  PHÂN TÍCH LINK  →  loại, nguồn, thumbnail, mã nhúng
// ============================================================
function analyze_link($url) {
    $url = trim($url);
    if ($url === '') return null;
    if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
    if (!filter_var($url, FILTER_VALIDATE_URL)) return null;

    $res = ['type'=>'link', 'source'=>'web', 'url'=>$url, 'thumb'=>null, 'embed'=>null, 'title'=>null];
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');

    // ── Ảnh trực tiếp ──
    if (preg_match('~\.(jpe?g|png|gif|webp|avif|bmp)(\?|$)~i', $url)) {
        $res['type'] = 'image'; $res['thumb'] = $url;
        return $res;
    }
    // ── Video trực tiếp ──
    if (preg_match('~\.(mp4|webm|mov|m4v)(\?|$)~i', $url)) {
        $res['type'] = 'video'; $res['source'] = 'web';
        return $res;
    }

    // ── YouTube ──
    if (preg_match('~(?:youtube\.com/(?:watch\?[^ ]*v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{6,})~i', $url, $m)) {
        $vid = $m[1];
        $res['type']   = 'embed';
        $res['source'] = 'youtube';
        $res['thumb']  = "https://i.ytimg.com/vi/$vid/hqdefault.jpg";
        $res['embed']  = "https://www.youtube-nocookie.com/embed/$vid";
        $o = fetch_oembed("https://www.youtube.com/oembed?format=json&url=" . rawurlencode($url));
        if ($o) {
            if (!empty($o['title']))         $res['title'] = $o['title'];
            if (!empty($o['thumbnail_url'])) $res['thumb'] = $o['thumbnail_url'];
        }
        return $res;
    }

    // ── Vimeo ──
    if (preg_match('~vimeo\.com/(?:video/)?(\d+)~i', $url, $m)) {
        $res['type']   = 'embed';
        $res['source'] = 'vimeo';
        $res['embed']  = "https://player.vimeo.com/video/{$m[1]}";
        $o = fetch_oembed("https://vimeo.com/api/oembed.json?url=" . rawurlencode($url));
        if ($o) {
            $res['title'] = $o['title']         ?? null;
            $res['thumb'] = $o['thumbnail_url'] ?? null;
        }
        return $res;
    }

    // ── TikTok ──
    if (strpos($host, 'tiktok.com') !== false) {
        $res['type'] = 'embed'; $res['source'] = 'tiktok';
        $o = fetch_oembed("https://www.tiktok.com/oembed?url=" . rawurlencode($url));
        if ($o) {
            $res['title'] = $o['title']         ?? null;
            $res['thumb'] = $o['thumbnail_url'] ?? null;
        }
        if (preg_match('~/video/(\d+)~', $url, $m)) {
            $res['embed'] = "https://www.tiktok.com/embed/v2/{$m[1]}";
        }
        return $res;
    }

    // ── Instagram (post / reel) ──
    if (strpos($host, 'instagram.com') !== false) {
        $res['type'] = 'embed'; $res['source'] = 'instagram';
        if (preg_match('~instagram\.com/(?:[^/]+/)?(p|reel|reels|tv)/([A-Za-z0-9_-]+)~i', $url, $m)) {
            $kind = ($m[1] === 'reels') ? 'reel' : $m[1];
            $res['embed'] = "https://www.instagram.com/{$kind}/{$m[2]}/embed/captioned/";
        }
        $og = fetch_og($url);
        if ($og) { $res['title'] = $og['title'] ?? null; $res['thumb'] = $og['image'] ?? null; }
        return $res;
    }

    // ── Facebook (post / video / reel) ──
    if (preg_match('~(facebook\.com|fb\.watch|fb\.com)~i', $host)) {
        $res['type'] = 'embed'; $res['source'] = 'facebook';
        $isVideo = preg_match('~(/videos?/|/reel/|fb\.watch|/watch)~i', $url);
        $plugin  = $isVideo ? 'video.php' : 'post.php';
        $res['embed'] = "https://www.facebook.com/plugins/{$plugin}?href=" . rawurlencode($url)
                      . "&show_text=false&width=560";
        $og = fetch_og($url);
        if ($og) { $res['title'] = $og['title'] ?? null; $res['thumb'] = $og['image'] ?? null; }
        return $res;
    }

    // ── Pinterest ──
    if (strpos($host, 'pinterest.') !== false || strpos($host, 'pin.it') !== false) {
        $res['source'] = 'pinterest';
        $og = fetch_og($url);
        if ($og) {
            $res['title'] = $og['title'] ?? null;
            $res['thumb'] = $og['image'] ?? null;
            if (!empty($og['image'])) $res['type'] = 'image';
        }
        return $res;
    }

    // ── Link thường: đọc thẻ Open Graph ──
    $og = fetch_og($url);
    if ($og) {
        $res['title'] = $og['title'] ?? null;
        $res['thumb'] = $og['image'] ?? null;
    }
    return $res;
}

/** Gọi endpoint oEmbed công khai */
function fetch_oembed($endpoint) {
    $raw = http_get($endpoint, 6);
    if (!$raw) return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

/** Đọc thẻ og:title / og:image của 1 trang */
function fetch_og($url) {
    $html = http_get($url, 8, 400000);
    if (!$html) return null;
    $out = [];
    if (preg_match('~<meta[^>]+(?:property|name)=["\'](?:og:|twitter:)image(?::src)?["\'][^>]+content=["\']([^"\']+)~i', $html, $m)) {
        $out['image'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    } elseif (preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\'](?:og:|twitter:)image~i', $html, $m)) {
        $out['image'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    if (preg_match('~<meta[^>]+(?:property|name)=["\'](?:og:|twitter:)title["\'][^>]+content=["\']([^"\']+)~i', $html, $m)) {
        $out['title'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    } elseif (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
        $out['title'] = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
    }
    if (!empty($out['title'])) $out['title'] = mb_substr($out['title'], 0, 300);
    return $out ?: null;
}

/** Chặn địa chỉ nội bộ (tránh SSRF) */
function is_public_url($url) {
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return false;
    if (preg_match('~^(localhost|.*\.local|.*\.internal)$~i', $host)) return false;
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : @gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return true; // không phân giải được → để cURL tự xử lý
    return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/** GET đơn giản qua cURL, fallback file_get_contents */
function http_get($url, $timeout = 8, $maxBytes = 0) {
    if (!is_public_url($url)) return null;

    if (function_exists('curl_init')) {
        $headers = ['Accept-Language: vi,en;q=0.8'];
        if ($maxBytes > 0) $headers[] = 'Range: bytes=0-' . $maxBytes;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; APSA-Inspiration/1.0)',
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $data = curl_exec($ch);
        curl_close($ch);
        return $data ?: null;
    }

    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => 'Mozilla/5.0 (compatible; APSA-Inspiration/1.0)']]);
    $data = $maxBytes > 0
        ? @file_get_contents($url, false, $ctx, 0, $maxBytes)
        : @file_get_contents($url, false, $ctx);
    return $data ?: null;
}

// ============================================================
//  TẠO THUMBNAIL WEBP
// ============================================================
function make_thumb($srcPath, $ext, $destPath) {
    if (!function_exists('imagecreatetruecolor')) return false;
    $info = @getimagesize($srcPath);
    if (!$info) return false;
    list($w, $h) = $info;
    if ($w < 1 || $h < 1) return false;

    switch (strtolower($ext)) {
        case 'jpg': case 'jpeg': $img = @imagecreatefromjpeg($srcPath); break;
        case 'png':              $img = @imagecreatefrompng($srcPath);  break;
        case 'gif':              $img = @imagecreatefromgif($srcPath);  break;
        case 'webp':             $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : null; break;
        default:                 $img = null;
    }
    if (!$img) return false;

    $scale = min(1, THUMB_W / $w);
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $done = false;
    if (function_exists('imagewebp')) $done = @imagewebp($dst, $destPath, 82);
    if (!$done) { $destPath = preg_replace('/\.webp$/', '.jpg', $destPath); $done = @imagejpeg($dst, $destPath, 85); }

    imagedestroy($img); imagedestroy($dst);
    return $done ? basename($destPath) : false;
}

// ============================================================
//  ACTION: META (xem trước link, không lưu)
// ============================================================
if ($action === 'meta') {
    $info = analyze_link($_GET['url'] ?? '');
    if (!$info) fail('Link không hợp lệ');
    ok($info);
}

// ============================================================
//  ACTION: LIST
// ============================================================
if ($action === 'list' || ($method === 'GET' && $action === '')) {
    $where  = ['deleted_at IS NULL'];
    $params = [];

    if (!empty($_GET['trash'])) { $where = ['deleted_at IS NOT NULL']; }

    if (!empty($_GET['tag'])) {
        $tags = array_filter(array_map('trim', explode(',', mb_strtolower($_GET['tag'], 'UTF-8'))));
        foreach ($tags as $t) { $where[] = "CONCAT(',', tags, ',') LIKE ?"; $params[] = '%,' . $t . ',%'; }
    }
    if (!empty($_GET['type']))   { $where[] = 'type = ?';   $params[] = $_GET['type']; }
    if (!empty($_GET['source'])) { $where[] = 'source = ?';  $params[] = $_GET['source']; }
    if (!empty($_GET['q'])) {
        $where[] = '(title LIKE ? OR note LIKE ? OR tags LIKE ? OR added_by LIKE ?)';
        $like = '%' . $_GET['q'] . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $limit  = min(max((int)($_GET['limit']  ?? 200), 1), 500);
    $offset = max((int)($_GET['offset'] ?? 0), 0);

    $sql = 'SELECT * FROM inspiration_items WHERE ' . implode(' AND ', $where)
         . ' ORDER BY pinned DESC, created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $cnt = $pdo->prepare('SELECT COUNT(*) FROM inspiration_items WHERE ' . implode(' AND ', $where));
    $cnt->execute($params);

    ok(['items' => $rows, 'total' => (int)$cnt->fetchColumn()]);
}

// ============================================================
//  ACTION: TAGS
// ============================================================
if ($action === 'tags') {
    $rows = $pdo->query("SELECT tags FROM inspiration_items WHERE deleted_at IS NULL AND tags <> ''")->fetchAll();
    $count = [];
    foreach ($rows as $r) {
        foreach (explode(',', $r['tags']) as $t) {
            $t = trim($t);
            if ($t === '') continue;
            $count[$t] = ($count[$t] ?? 0) + 1;
        }
    }
    arsort($count);
    $out = [];
    foreach ($count as $t => $c) $out[] = ['tag' => $t, 'count' => $c];
    ok($out);
}

// ── Từ đây là các thao tác ghi ───────────────────────────────
if ($method !== 'POST') fail('Method not allowed', 405);

// ============================================================
//  ACTION: UPLOAD FILE
// ============================================================
if ($action === 'upload') {
    if (!is_dir(UPLOAD_DIR)) {
        if (!@mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) fail('Không tạo được thư mục upload', 500);
    }
    if (!is_writable(UPLOAD_DIR)) fail('Thư mục uploads/inspiration không có quyền ghi (chmod 755/775)', 500);

    if (empty($_FILES['files'])) fail('Không có file nào được gửi lên');

    $tags    = norm_tags($_POST['tags'] ?? '');
    $note    = mb_substr(trim((string)($_POST['note']     ?? '')), 0, 2000);
    $addedBy = mb_substr(trim((string)($_POST['added_by'] ?? '')), 0, 120);

    $files = $_FILES['files'];
    $n     = is_array($files['name']) ? count($files['name']) : 1;
    if ($n > 30) fail('Tối đa 30 file mỗi lần');

    $stmt = $pdo->prepare(
        'INSERT INTO inspiration_items
            (type, source, title, note, url, thumb, file_name, file_size, width, height, tags, added_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $saved = []; $errors = [];

    for ($i = 0; $i < $n; $i++) {
        $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        $tmp  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $size = is_array($files['size']) ? (int)$files['size'][$i] : (int)$files['size'];
        $err  = is_array($files['error']) ? (int)$files['error'][$i] : (int)$files['error'];

        if ($err !== UPLOAD_ERR_OK) { $errors[] = "$name: lỗi upload (code $err)"; continue; }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (in_array($ext, $ALLOWED_IMAGE, true))      { $type = 'image'; $max = MAX_IMAGE; }
        elseif (in_array($ext, $ALLOWED_VIDEO, true))  { $type = 'video'; $max = MAX_VIDEO; }
        elseif (in_array($ext, $ALLOWED_DOC, true))    { $type = 'file';  $max = MAX_IMAGE; }
        else { $errors[] = "$name: định dạng .$ext không được hỗ trợ"; continue; }

        if ($size > $max) { $errors[] = "$name: vượt quá " . round($max / 1048576) . " MB"; continue; }

        $base    = slugify_name(pathinfo($name, PATHINFO_FILENAME));
        $unique  = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $newName = "$unique-$base.$ext";
        $dest    = UPLOAD_DIR . '/' . $newName;

        if (!@move_uploaded_file($tmp, $dest)) { $errors[] = "$name: không lưu được file"; continue; }
        @chmod($dest, 0644);

        $w = $h = null; $thumbUrl = null;
        if ($type === 'image') {
            $info = @getimagesize($dest);
            if ($info) { $w = $info[0]; $h = $info[1]; }
            // Ảnh lớn → tạo thumbnail webp để lưới tải nhanh
            if ($w && $w > THUMB_W) {
                $tn = make_thumb($dest, $ext, UPLOAD_DIR . '/thumb-' . pathinfo($newName, PATHINFO_FILENAME) . '.webp');
                if ($tn) $thumbUrl = public_url($tn);
            }
            if (!$thumbUrl) $thumbUrl = public_url($newName);
        }

        $title = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 300);
        $stmt->execute([
            $type, 'upload', $title, $note, public_url($newName), $thumbUrl,
            mb_substr($name, 0, 255), $size, $w, $h, $tags, $addedBy,
        ]);
        $id = (int)$pdo->lastInsertId();
        $saved[] = $pdo->query("SELECT * FROM inspiration_items WHERE id = $id")->fetch();
    }

    if (!$saved && $errors) fail(implode(' · ', $errors));
    ok(['items' => $saved, 'errors' => $errors]);
}

// ============================================================
//  ACTION: LINK
// ============================================================
if ($action === 'link') {
    $b   = body_json();
    $url = trim((string)($b['url'] ?? ''));
    if ($url === '') fail('Thiếu link');

    $info = analyze_link($url);
    if (!$info) fail('Link không hợp lệ');

    $title = trim((string)($b['title'] ?? '')) ?: ($info['title'] ?? '');
    $title = mb_substr($title ?: parse_url($info['url'], PHP_URL_HOST), 0, 300);

    $stmt = $pdo->prepare(
        'INSERT INTO inspiration_items (type, source, title, note, url, thumb, embed, tags, added_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $info['type'], $info['source'], $title,
        mb_substr(trim((string)($b['note'] ?? '')), 0, 2000),
        $info['url'], $info['thumb'], $info['embed'],
        norm_tags($b['tags'] ?? ''),
        mb_substr(trim((string)($b['added_by'] ?? '')), 0, 120),
    ]);
    $id = (int)$pdo->lastInsertId();
    ok($pdo->query("SELECT * FROM inspiration_items WHERE id = $id")->fetch());
}

// ============================================================
//  ACTION: UPDATE
// ============================================================
if ($action === 'update') {
    $b  = body_json();
    $id = (int)($b['id'] ?? 0);
    if (!$id) fail('Thiếu id');

    $sets = []; $params = [];
    if (array_key_exists('title', $b))  { $sets[] = 'title = ?';  $params[] = mb_substr(trim((string)$b['title']), 0, 300); }
    if (array_key_exists('note', $b))   { $sets[] = 'note = ?';   $params[] = mb_substr(trim((string)$b['note']), 0, 2000); }
    if (array_key_exists('tags', $b))   { $sets[] = 'tags = ?';   $params[] = norm_tags($b['tags']); }
    if (array_key_exists('pinned', $b)) { $sets[] = 'pinned = ?'; $params[] = !empty($b['pinned']) ? 1 : 0; }
    if (!$sets) fail('Không có gì để cập nhật');

    $params[] = $id;
    $pdo->prepare('UPDATE inspiration_items SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    ok($pdo->query("SELECT * FROM inspiration_items WHERE id = $id")->fetch());
}

// ============================================================
//  ACTION: DELETE (mềm)  /  RESTORE  /  PURGE (xoá hẳn)
// ============================================================
if ($action === 'delete') {
    $id = (int)(body_json()['id'] ?? 0);
    if (!$id) fail('Thiếu id');
    $stmt = $pdo->prepare('UPDATE inspiration_items SET deleted_at = NOW() WHERE id = ?');
    $stmt->execute([$id]);
    ok(['deleted' => $stmt->rowCount()]);
}

if ($action === 'restore') {
    $id = (int)(body_json()['id'] ?? 0);
    if (!$id) fail('Thiếu id');
    $stmt = $pdo->prepare('UPDATE inspiration_items SET deleted_at = NULL WHERE id = ?');
    $stmt->execute([$id]);
    ok(['restored' => $stmt->rowCount()]);
}

// Xoá vĩnh viễn — cần API key (tránh mất dữ liệu do nhầm)
if ($action === 'purge') {
    if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== API_SECRET) fail('Unauthorized', 401);
    $id  = (int)(body_json()['id'] ?? 0);
    if (!$id) fail('Thiếu id');
    $row = $pdo->query("SELECT * FROM inspiration_items WHERE id = $id")->fetch();
    if ($row && $row['source'] === 'upload' && $row['file_name']) {
        foreach ([$row['url'], $row['thumb']] as $u) {
            if (!$u) continue;
            $f = UPLOAD_DIR . '/' . basename(parse_url($u, PHP_URL_PATH));
            if (is_file($f)) @unlink($f);
        }
    }
    $pdo->prepare('DELETE FROM inspiration_items WHERE id = ?')->execute([$id]);
    ok(['purged' => $id]);
}

fail('Action không hợp lệ: ' . htmlspecialchars($action));
