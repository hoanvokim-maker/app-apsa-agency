<?php
/**
 * APSA1816 - Playlist duyet video: render <title> + og:* phia server.
 * .htaccess dieu huong /playlist.html -> /playlist.php nen link cu van chay.
 */
require_once __DIR__ . '/api/db-config.php';
require_once __DIR__ . '/api/og-wrap.php';

$t     = isset($_GET['t']) ? trim((string) $_GET['t']) : '';
$title = '';
$note  = '';
$thumb = '';

if ($t !== '' && preg_match('/^[A-Za-z0-9]{8,64}$/', $t)) {
    try {
        $st = apsa_og_pdo()->prepare('SELECT `title`, `note`, `thumb`, `active` FROM `video_playlists` WHERE `token` = ? OR `slug` = ? LIMIT 1');
        $st->execute(array($t, $t));
        $r = $st->fetch();
        if ($r && (int) $r['active']) {
            $title = trim((string) $r['title']);
            $note  = trim((string) $r['note']);
            $tb    = trim((string) (isset($r['thumb']) ? $r['thumb'] : ''));
            if ($tb !== '' && preg_match('#^uploads/playlists/[A-Za-z0-9._-]{1,120}$#', $tb) && is_file(__DIR__ . '/' . $tb)) $thumb = $tb;
        }
    } catch (Exception $ex) { /* im lang: trang van chay binh thuong */ }
}

if ($title === '') $title = 'Playlist duyệt video — APSA';
$desc = $note !== '' ? $note : 'Danh sách video cần duyệt — xem và góp ý trực tiếp trên từng giây.';

apsa_og_wrap(__DIR__ . '/playlist.html', '/playlist.html', $title, $desc, 'video.other', $thumb);
