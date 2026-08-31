<?php
/**
 * aiphoto.php - Thu vien cho module Chup anh AI.
 *
 * Gom: cau hinh, tao bang, goi model tao anh (Gemini -> fal du phong),
 * cat/resize theo kich thuoc event, dan watermark.
 *
 * Khoa API nam trong api/ai-config.php (khong commit len git).
 */

/* ------------------------------------------------------------------ */
/*  Cau hinh                                                           */
/* ------------------------------------------------------------------ */

function ap_cfg()
{
    static $c = null;
    if ($c !== null) return $c;
    $d = array(
        'gemini_key' => '', 'gemini_model' => 'gemini-3.1-flash-image',
        'fal_key' => '', 'fal_model' => 'fal-ai/nano-banana/edit',
        'max_inflight' => 8, 'timeout' => 90, 'max_attempts' => 3,
    );
    $f = __DIR__ . '/ai-config.php';
    if (is_file($f)) {
        $u = @include $f;
        if (is_array($u)) $d = array_merge($d, $u);
    }
    $c = $d;
    return $c;
}

/** Da khai bao it nhat 1 nha cung cap chua. */
function ap_enabled()
{
    $c = ap_cfg();
    return trim((string) $c['gemini_key']) !== '' || trim((string) $c['fal_key']) !== '';
}

function ap_root()  { return dirname(__DIR__); }
function ap_updir() { return ap_root() . '/uploads/aiphoto'; }

function ap_slugify($s)
{
    $s = trim((string) $s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
    $s = trim(strtolower($s), '-');
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($t !== false) $s = preg_replace('/[^a-z0-9-]+/', '', strtolower($t));
    }
    $s = trim(preg_replace('/-+/', '-', $s), '-');
    return $s !== '' ? substr($s, 0, 60) : 'event';
}

/* ------------------------------------------------------------------ */
/*  Bang du lieu                                                       */
/* ------------------------------------------------------------------ */

function ap_migrate(PDO $pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;

    $sql = array(
        "CREATE TABLE IF NOT EXISTS `ai_events` (
           `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
           `slug` VARCHAR(64) NOT NULL,
           `name` VARCHAR(160) NOT NULL,
           `note` VARCHAR(500) NULL DEFAULT NULL,
           `start_at` DATETIME NULL DEFAULT NULL,
           `end_at` DATETIME NULL DEFAULT NULL,
           `active` TINYINT(1) NOT NULL DEFAULT 1,
           `cover_file` VARCHAR(200) NULL DEFAULT NULL,
           `wm_file` VARCHAR(200) NULL DEFAULT NULL,
           `wm_pos` VARCHAR(16) NOT NULL DEFAULT 'br',
           `wm_scale` INT NOT NULL DEFAULT 22,
           `out_w` INT NOT NULL DEFAULT 1024,
           `out_h` INT NOT NULL DEFAULT 1024,
           `max_images` INT NOT NULL DEFAULT 0,
           `views` INT NOT NULL DEFAULT 0,
           `uses` INT NOT NULL DEFAULT 0,
           `created_by` VARCHAR(120) NULL DEFAULT NULL,
           `created_at` DATETIME NOT NULL,
           PRIMARY KEY (`id`), UNIQUE KEY `uq_slug` (`slug`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `ai_prompts` (
           `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
           `title` VARCHAR(160) NOT NULL,
           `body` TEXT NOT NULL,
           `tag` VARCHAR(60) NULL DEFAULT NULL,
           `thumb_file` VARCHAR(200) NULL DEFAULT NULL,
           `active` TINYINT(1) NOT NULL DEFAULT 1,
           `sort_order` INT NOT NULL DEFAULT 0,
           `uses` INT NOT NULL DEFAULT 0,
           `created_by` VARCHAR(120) NULL DEFAULT NULL,
           `created_at` DATETIME NOT NULL,
           PRIMARY KEY (`id`), KEY `k_tag` (`tag`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `ai_event_prompts` (
           `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
           `event_id` INT UNSIGNED NOT NULL,
           `prompt_id` INT UNSIGNED NOT NULL,
           `sort_order` INT NOT NULL DEFAULT 0,
           PRIMARY KEY (`id`), UNIQUE KEY `uq_ep` (`event_id`, `prompt_id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `ai_jobs` (
           `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
           `token` VARCHAR(24) NOT NULL,
           `event_id` INT UNSIGNED NOT NULL,
           `prompt_id` INT UNSIGNED NOT NULL,
           `state` VARCHAR(12) NOT NULL DEFAULT 'queued',
           `provider` VARCHAR(16) NULL DEFAULT NULL,
           `src_file` VARCHAR(200) NULL DEFAULT NULL,
           `out_file` VARCHAR(200) NULL DEFAULT NULL,
           `err` VARCHAR(400) NULL DEFAULT NULL,
           `attempts` INT NOT NULL DEFAULT 0,
           `ms` INT NOT NULL DEFAULT 0,
           `device_key` VARCHAR(40) NULL DEFAULT NULL,
           `ip` VARCHAR(45) NULL DEFAULT NULL,
           `sp_state` VARCHAR(12) NOT NULL DEFAULT 'no',
           `sp_url` VARCHAR(500) NULL DEFAULT NULL,
           `started_at` DATETIME NULL DEFAULT NULL,
           `finished_at` DATETIME NULL DEFAULT NULL,
           `created_at` DATETIME NOT NULL,
           PRIMARY KEY (`id`), UNIQUE KEY `uq_token` (`token`),
           KEY `k_state` (`state`, `id`), KEY `k_event` (`event_id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    );
    foreach ($sql as $q) { try { $pdo->exec($q); } catch (Exception $e) { } }
}

/* ------------------------------------------------------------------ */
/*  HTTP                                                               */
/* ------------------------------------------------------------------ */

function ap_http($method, $url, array $headers, $body, &$code, $timeout = 90)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => (int) $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
    ));
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $res  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($res === false) { $e = curl_error($ch); curl_close($ch); $code = 0; return 'CURL: ' . $e; }
    curl_close($ch);
    return $res;
}

/* ------------------------------------------------------------------ */
/*  Tao anh                                                            */
/* ------------------------------------------------------------------ */

/** Ti le gan nhat ma Gemini chap nhan. */
function ap_ratio($w, $h)
{
    $w = max(1, (int) $w); $h = max(1, (int) $h);
    $r = $w / $h;
    $opts = array('1:1' => 1, '16:9' => 16 / 9, '9:16' => 9 / 16, '4:3' => 4 / 3,
                  '3:4' => 3 / 4, '3:2' => 3 / 2, '2:3' => 2 / 3, '5:4' => 5 / 4, '4:5' => 4 / 5);
    $best = '1:1'; $bd = 1e9;
    foreach ($opts as $k => $v) { $d = abs($v - $r); if ($d < $bd) { $bd = $d; $best = $k; } }
    return $best;
}

function ap_size_label($w, $h)
{
    $m = max((int) $w, (int) $h);
    if ($m <= 640)  return '512px';
    if ($m <= 1280) return '1K';
    if ($m <= 2560) return '2K';
    return '4K';
}

/** Google Gemini. Tra ve chuoi byte anh, hoac false. */
function ap_gen_gemini($imgBytes, $mime, $prompt, $w, $h, &$err)
{
    $c = ap_cfg();
    $key = trim((string) $c['gemini_key']);
    if ($key === '') { $err = 'Chua khai bao gemini_key'; return false; }

    $payload = json_encode(array(
        'model' => (string) $c['gemini_model'],
        'input' => array(
            array('type' => 'text', 'text' => (string) $prompt),
            array('type' => 'image', 'mime_type' => $mime, 'data' => base64_encode($imgBytes)),
        ),
        'response_format' => array(
            'type'         => 'image',
            'mime_type'    => 'image/jpeg',
            'aspect_ratio' => ap_ratio($w, $h),
            'image_size'   => ap_size_label($w, $h),
        ),
    ), JSON_UNESCAPED_UNICODE);

    $code = 0;
    $res = ap_http('POST', 'https://generativelanguage.googleapis.com/v1beta/interactions',
        array('Content-Type: application/json', 'x-goog-api-key: ' . $key),
        $payload, $code, (int) $c['timeout']);

    if ($code !== 200) {
        $err = 'Gemini HTTP ' . $code . ' ' . mb_substr(preg_replace('/\s+/', ' ', (string) $res), 0, 200);
        return false;
    }
    $j = json_decode($res, true);
    $b64 = ap_pick_image($j);
    if ($b64 === null) { $err = 'Gemini khong tra ve anh'; return false; }
    $bin = base64_decode($b64, true);
    if (!ap_is_img($bin)) { $err = 'Du lieu anh Gemini tra ve khong doc duoc'; return false; }
    return $bin;
}

/** fal.ai (mac dinh cung chay nano-banana). Tra ve chuoi byte anh, hoac false. */
function ap_gen_fal($imgBytes, $mime, $prompt, $w, $h, &$err)
{
    $c = ap_cfg();
    $key = trim((string) $c['fal_key']);
    if ($key === '') { $err = 'Chua khai bao fal_key'; return false; }

    $dataUri = 'data:' . $mime . ';base64,' . base64_encode($imgBytes);
    $payload = json_encode(array(
        'prompt'       => (string) $prompt,
        'image_urls'   => array($dataUri),
        'num_images'   => 1,
        'output_format' => 'jpeg',
        'aspect_ratio' => ap_ratio($w, $h),
    ), JSON_UNESCAPED_UNICODE);

    $hdr = array('Content-Type: application/json', 'Authorization: Key ' . $key);
    $base = 'https://queue.fal.run/' . trim((string) $c['fal_model'], '/');

    $code = 0;
    $res = ap_http('POST', $base, $hdr, $payload, $code, 30);
    if ($code < 200 || $code > 299) {
        $err = 'fal HTTP ' . $code . ' ' . mb_substr(preg_replace('/\s+/', ' ', (string) $res), 0, 200);
        return false;
    }
    $j = json_decode($res, true);
    $rid = isset($j['request_id']) ? $j['request_id'] : '';
    if ($rid === '') { $err = 'fal khong tra ve request_id'; return false; }

    $stat = isset($j['status_url']) ? $j['status_url'] : ($base . '/requests/' . $rid . '/status');
    $resp = isset($j['response_url']) ? $j['response_url'] : ($base . '/requests/' . $rid);

    $deadline = time() + (int) $c['timeout'];
    while (time() < $deadline) {
        usleep(1500000);
        $sc = 0;
        $s = ap_http('GET', $stat, $hdr, null, $sc, 20);
        $sj = json_decode($s, true);
        $st = isset($sj['status']) ? strtoupper($sj['status']) : '';
        if ($st === 'COMPLETED') break;
        if ($st === 'FAILED' || $st === 'CANCELLED') { $err = 'fal ' . $st; return false; }
    }

    $rc = 0;
    $r = ap_http('GET', $resp, $hdr, null, $rc, 30);
    if ($rc !== 200) { $err = 'fal ket qua HTTP ' . $rc; return false; }
    $rj = json_decode($r, true);
    $url = ap_dig($rj, array('images', 0, 'url'));
    if ($url === null) $url = ap_dig($rj, array('image', 'url'));
    if ($url === null) { $err = 'fal khong tra ve anh'; return false; }

    if (strpos($url, 'data:') === 0) {
        $p = strpos($url, ',');
        $bin = $p === false ? false : base64_decode(substr($url, $p + 1), true);
    } else {
        $ic = 0;
        $bin = ap_http('GET', $url, array(), null, $ic, 60);
        if ($ic !== 200) $bin = false;
    }
    if (!ap_is_img($bin)) { $err = 'Khong tai duoc anh tu fal'; return false; }
    return $bin;
}

/** Duong chinh Gemini, hong thi sang fal. */
function ap_generate($imgBytes, $mime, $prompt, $w, $h, &$provider, &$err)
{
    $e1 = ''; $e2 = '';
    $c = ap_cfg();

    if (trim((string) $c['gemini_key']) !== '') {
        $b = ap_gen_gemini($imgBytes, $mime, $prompt, $w, $h, $e1);
        if ($b !== false) { $provider = 'gemini'; return $b; }
    }
    if (trim((string) $c['fal_key']) !== '') {
        $b = ap_gen_fal($imgBytes, $mime, $prompt, $w, $h, $e2);
        if ($b !== false) { $provider = 'fal'; return $b; }
    }
    $err = trim($e1 . ($e2 !== '' ? ' | ' . $e2 : ''));
    if ($err === '') $err = 'Chua khai bao khoa API nao';
    return false;
}

/** Tim chuoi base64 cua anh trong phan hoi Gemini. */
function ap_pick_image($j)
{
    if (!is_array($j)) return null;

    /* Duong chinh: steps[].content[] co type=image (bo qua steps[].signature) */
    if (isset($j['steps']) && is_array($j['steps'])) {
        foreach ($j['steps'] as $st) {
            if (!is_array($st) || !isset($st['content']) || !is_array($st['content'])) continue;
            foreach ($st['content'] as $ct) {
                if (!is_array($ct) || !isset($ct['data']) || !is_string($ct['data'])) continue;
                $isImg = (isset($ct['type']) && $ct['type'] === 'image')
                      || (isset($ct['mime_type']) && strpos((string) $ct['mime_type'], 'image/') === 0);
                if ($isImg) return $ct['data'];
            }
        }
    }

    /* Cac dang phan hoi khac */
    $p = ap_dig($j, array('output_image', 'data'));
    if ($p !== null) return $p;
    $p = ap_dig($j, array('interaction', 'output_image', 'data'));
    if ($p !== null) return $p;

    return null;
}

/** Chuoi byte co dung la anh khong (xem chu ky dau file). */
function ap_is_img($bin)
{
    if (!is_string($bin) || strlen($bin) < 128) return false;
    if (substr($bin, 0, 3) === "\xFF\xD8\xFF") return true;                        /* JPEG */
    if (substr($bin, 0, 8) === "\x89PNG\r\n\x1a\n") return true;                   /* PNG  */
    if (substr($bin, 0, 4) === 'RIFF' && substr($bin, 8, 4) === 'WEBP') return true;  /* WEBP */
    if (substr($bin, 0, 6) === 'GIF89a' || substr($bin, 0, 6) === 'GIF87a') return true;
    return false;
}

/* Lay gia tri long trong mang lien tuc. */
function ap_dig($a, array $path)
{
    foreach ($path as $k) {
        if (!is_array($a) || !isset($a[$k])) return null;
        $a = $a[$k];
    }
    return is_string($a) ? $a : null;
}


/* ------------------------------------------------------------------ */
/*  Xu ly anh (GD)                                                     */
/* ------------------------------------------------------------------ */

function ap_load($bytes)
{
    $im = @imagecreatefromstring($bytes);
    if (!$im) return false;
    if (function_exists('imagepalettetotruecolor')) @imagepalettetotruecolor($im);
    return $im;
}

/** Resize kieu "cover": phu kin khung w x h roi cat phan thua o giua. */
function ap_fit_cover($im, $w, $h)
{
    $w = max(16, (int) $w); $h = max(16, (int) $h);
    $sw = imagesx($im); $sh = imagesy($im);
    if ($sw === $w && $sh === $h) return $im;

    $s  = max($w / $sw, $h / $sh);
    $nw = (int) ceil($sw * $s); $nh = (int) ceil($sh * $s);
    $dx = (int) (($nw - $w) / 2); $dy = (int) (($nh - $h) / 2);

    $out = imagecreatetruecolor($w, $h);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $im, -$dx, -$dy, 0, 0, $nw, $nh, $sw, $sh);
    imagedestroy($im);
    return $out;
}

/**
 * Dan watermark len anh.
 * $pos: tl tr bl br c    $scale: % chieu rong anh (5-100)
 */
function ap_watermark($im, $wmPath, $pos = 'br', $scale = 22)
{
    if ($wmPath === '' || !is_file($wmPath)) return $im;
    $wm = @imagecreatefromstring((string) file_get_contents($wmPath));
    if (!$wm) return $im;

    $iw = imagesx($im); $ih = imagesy($im);
    $ww = imagesx($wm); $wh = imagesy($wm);

    $scale = max(5, min(100, (int) $scale));
    $tw = (int) round($iw * $scale / 100);
    $th = (int) round($wh * ($tw / max(1, $ww)));
    if ($tw < 8 || $th < 8) { imagedestroy($wm); return $im; }

    $tmp = imagecreatetruecolor($tw, $th);
    imagealphablending($tmp, false);
    imagesavealpha($tmp, true);
    imagefill($tmp, 0, 0, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
    imagecopyresampled($tmp, $wm, 0, 0, 0, 0, $tw, $th, $ww, $wh);
    imagedestroy($wm);

    $m = (int) round(min($iw, $ih) * 0.035);
    switch ($pos) {
        case 'tl': $x = $m;                 $y = $m;                 break;
        case 'tr': $x = $iw - $tw - $m;     $y = $m;                 break;
        case 'bl': $x = $m;                 $y = $ih - $th - $m;     break;
        case 'c':  $x = (int) (($iw - $tw) / 2); $y = (int) (($ih - $th) / 2); break;
        default:   $x = $iw - $tw - $m;     $y = $ih - $th - $m;     break;
    }

    imagealphablending($im, true);
    imagecopy($im, $tmp, $x, $y, 0, 0, $tw, $th);
    imagedestroy($tmp);
    return $im;
}

function ap_jpeg($im, $q = 90)
{
    ob_start();
    imagejpeg($im, null, (int) $q);
    return ob_get_clean();
}

/**
 * Hoan thien anh AI: cat dung kich thuoc event roi dan watermark.
 * Tra ve chuoi byte JPEG, hoac false.
 */
function ap_finish($bytes, array $ev)
{
    $im = ap_load($bytes);
    if (!$im) return false;
    $im = ap_fit_cover($im, $ev['out_w'], $ev['out_h']);
    if (!empty($ev['wm_file'])) {
        $p = ap_updir() . '/' . (int) $ev['id'] . '/' . $ev['wm_file'];
        $im = ap_watermark($im, $p, isset($ev['wm_pos']) ? $ev['wm_pos'] : 'br',
                           isset($ev['wm_scale']) ? $ev['wm_scale'] : 22);
    }
    $out = ap_jpeg($im, 90);
    imagedestroy($im);
    return $out;
}

/** Thu nho anh selfie truoc khi gui len model (do canh dai nhat). */
function ap_shrink($bytes, $maxEdge = 1280)
{
    $im = ap_load($bytes);
    if (!$im) return $bytes;
    $w = imagesx($im); $h = imagesy($im);
    $m = max($w, $h);
    if ($m <= $maxEdge) { $out = ap_jpeg($im, 92); imagedestroy($im); return $out; }
    $s  = $maxEdge / $m;
    $nw = (int) round($w * $s); $nh = (int) round($h * $s);
    $o  = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($o, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($im);
    $out = ap_jpeg($o, 92);
    imagedestroy($o);
    return $out;
}
