<?php
/**
 * APSA1816 - Bao gia / nghiem thu: render <title> + og:* phia server
 * de link gui qua Zalo hien ten du an thay vi tieu de chung.
 */
require_once __DIR__ . '/api/db-config.php';
require_once __DIR__ . '/api/og-wrap.php';

$q     = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$id    = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$title = '';
$desc  = '';

try {
    $st = null;
    if ($q !== '' && preg_match('/^[A-Za-z0-9._-]{1,60}$/', $q)) {
        $st = apsa_og_pdo()->prepare('SELECT `code`, `title` FROM `quotations` WHERE `code` = ? AND `deleted_at` IS NULL LIMIT 1');
        $st->execute(array($q));
    } elseif ($id > 0) {
        $st = apsa_og_pdo()->prepare('SELECT `code`, `title` FROM `quotations` WHERE `id` = ? AND `deleted_at` IS NULL LIMIT 1');
        $st->execute(array($id));
    }
    if ($st) {
        $r = $st->fetch();
        if ($r) {
            $c = trim((string) $r['code']);
            $n = trim((string) $r['title']);
            $title = trim($c . (($c !== '' && $n !== '') ? ' · ' : '') . $n);
            $desc  = 'Báo giá & nghiệm thu dự án trên hệ thống APSA.';
        }
    }
} catch (Exception $ex) { /* im lang */ }

if ($title === '') {
    $title = 'Báo giá — APSA';
    $desc  = 'Hệ thống quản lý báo giá & dự án của APSA.';
}

apsa_og_wrap(__DIR__ . '/quotation.html', '/quotation.html', $title, $desc, 'website');
