<?php
/**
 * zact.php - Lop hanh dong 1 cham cho thong bao Zalo.
 *
 * Moi "nut" trong tin Zalo la 1 link co token ngau nhien, dung 1 lan,
 * het han sau ZA_TTL_DAYS ngay. Nguoi bam VAN PHAI dang nhap app:
 * token chi xac dinh HANH DONG, phien dang nhap xac dinh AI bam.
 * Nho vay link bi chuyen tiep cung khong lam thay ai duoc.
 *
 * Sau nay chuyen sang Zalo OA thi chi doi cho render nut (za_block),
 * toan bo phan thuc thi giu nguyen.
 */

if (!defined('ZA_TTL_DAYS')) define('ZA_TTL_DAYS', 14);

/** Tao bang + cot can thiet. Chay 1 lan moi request. */
function za_migrate(PDO $pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `zalo_actions` (
               `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
               `token`      VARCHAR(24)  NOT NULL,
               `kind`       VARCHAR(24)  NOT NULL,
               `target_id`  BIGINT       NOT NULL DEFAULT 0,
               `label`      VARCHAR(120) NOT NULL DEFAULT '',
               `user_id`    INT          NOT NULL DEFAULT 0,
               `extra`      VARCHAR(255) NULL DEFAULT NULL,
               `url`        VARCHAR(255) NOT NULL DEFAULT '',
               `expires_at` DATETIME     NULL DEFAULT NULL,
               `used_at`    DATETIME     NULL DEFAULT NULL,
               `used_by`    VARCHAR(120) NULL DEFAULT NULL,
               `used_ip`    VARCHAR(45)  NULL DEFAULT NULL,
               `created_at` DATETIME     NOT NULL,
               PRIMARY KEY (`id`),
               UNIQUE KEY `uq_token` (`token`),
               KEY `k_user` (`user_id`),
               KEY `k_target` (`kind`, `target_id`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Exception $e) { /* bo qua */ }

    try {
        $c = array();
        foreach ($pdo->query('SHOW COLUMNS FROM `quotation_assignees`') as $r) $c[$r['Field']] = 1;
        if (!isset($c['snooze_until'])) {
            $pdo->exec("ALTER TABLE `quotation_assignees` ADD COLUMN `snooze_until` DATETIME NULL DEFAULT NULL");
        }
    } catch (Exception $e) { /* bang chua ton tai */ }
}

/** Sinh 1 token hanh dong. Tra ve chuoi rong neu that bai. */
function za_make(PDO $pdo, $userId, $kind, $targetId, $label, $extra = null, $url = '')
{
    za_migrate($pdo);
    try {
        $st = $pdo->prepare(
            "INSERT IGNORE INTO `zalo_actions`
               (token, kind, target_id, label, user_id, extra, url, expires_at, created_at)
             VALUES (?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL " . (int) ZA_TTL_DAYS . " DAY), NOW())");
        for ($i = 0; $i < 5; $i++) {
            $t = bin2hex(random_bytes(8));
            $st->execute(array(
                $t,
                mb_substr((string) $kind, 0, 24),
                (int) $targetId,
                mb_substr((string) $label, 0, 120),
                (int) $userId,
                ($extra === null || $extra === '') ? null : mb_substr((string) $extra, 0, 255),
                mb_substr((string) $url, 0, 255),
            ));
            if ($st->rowCount() > 0) return $t;
        }
    } catch (Exception $e) { /* bo qua */ }
    return '';
}

/** Doi duong dan tuong doi thanh tuyet doi. */
function za_abs($url)
{
    if (function_exists('zb_abs')) return zb_abs($url);
    $url = trim((string) $url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    return 'https://app.apsa.agency/' . ltrim($url, './');
}

/** Link cua 1 token. */
function za_link($token)
{
    $token = trim((string) $token);
    if ($token === '') return '';
    return za_abs('./z.html') . '?t=' . $token;
}

/** Bieu tuong mac dinh cho tung loai nut (viet duoi dang byte de file thuan ASCII). */
function za_ico($kind)
{
    $m = array(
        'task_done' => "\xE2\x9C\x85",   /* dau tich xanh */
        'exp_paid'  => "\xF0\x9F\x92\xB8",   /* tien bay */
        'snooze'    => "\xE2\x8F\xB0",   /* dong ho bao thuc */
        'open'      => "\xF0\x9F\x91\x80",   /* doi mat */
    );
    return isset($m[$kind]) ? $m[$kind] : '';
}

/**
 * Dung khoi nut de chen vao cuoi tin Zalo.
 *
 * $specs: mang cac phan tu
 *   array('kind' => 'task_done'|'exp_paid'|'snooze'|'open',
 *         'id' => <id doi tuong>, 'label' => 'Da hoan thanh',
 *         'icon' => 'OK', 'extra' => '1', 'url' => './index.html')
 *
 * 'open' khong sinh token - chi la link thuong toi app.
 */
function za_block(PDO $pdo, $userId, array $specs)
{
    $out = array();
    foreach ($specs as $s) {
        $kind = isset($s['kind']) ? (string) $s['kind'] : '';
        if ($kind === '') continue;
        $ic  = isset($s['icon']) ? (string) $s['icon'] : za_ico($kind);
        $lab = trim(($ic !== '' ? $ic . ' ' : '') . (isset($s['label']) ? $s['label'] : ''));

        if ($kind === 'open') {
            $u = za_abs(isset($s['url']) ? $s['url'] : '');
            if ($u === '') continue;
            $out[] = $lab . ' -> ' . $u;
            continue;
        }

        $t = za_make(
            $pdo, $userId, $kind,
            isset($s['id']) ? $s['id'] : 0,
            isset($s['label']) ? $s['label'] : '',
            isset($s['extra']) ? $s['extra'] : null,
            isset($s['url']) ? $s['url'] : ''
        );
        if ($t === '') continue;
        $out[] = $lab . ' -> ' . za_link($t);
    }
    return $out ? implode("\n", $out) : '';
}

/** Don token cu (goi tu cron, khong bat buoc). */
function za_gc(PDO $pdo)
{
    try {
        $pdo->exec("DELETE FROM `zalo_actions`
                     WHERE expires_at IS NOT NULL
                       AND expires_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    } catch (Exception $e) { }
}
