<?php
/* APSA1916: dua anh ghep cua Frame Avatar len SharePoint + phat lai qua /p/<token> */
require_once __DIR__ . '/aiphoto-sp.php';

function fsp_table(PDO $pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `frame_shots` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `event_id`   INT NOT NULL DEFAULT 0,
        `token`      CHAR(32) NOT NULL,
        `sp_item`    VARCHAR(160) NOT NULL,
        `fname`      VARCHAR(190) NOT NULL DEFAULT '',
        `bytes`      INT NOT NULL DEFAULT 0,
        `ip`         VARCHAR(45) NOT NULL DEFAULT '',
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `u_token` (`token`),
        KEY `idx_ev` (`event_id`),
        KEY `idx_ip` (`ip`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Tai 1 file PNG len SharePoint. Tra ve itemId hoac false. */
function fsp_put_png($tok, $parentId, $filename, $bytes, &$err)
{
    $c = 0;
    $j = mg_http('PUT',
        APSP_BASE . '/items/' . rawurlencode($parentId) . ':/' . rawurlencode($filename)
            . ':/content?@microsoft.graph.conflictBehavior=rename',
        array('Authorization: Bearer ' . $tok, 'Content-Type: image/png'),
        $bytes, $c);
    if (($c === 200 || $c === 201) && isset($j['id'])) return $j['id'];
    $err = 'Khong tai duoc anh len SharePoint (HTTP ' . $c . ')';
    return false;
}

/** Lay lai bytes cua 1 file tren SharePoint. Tra ve chuoi hoac false. */
function fsp_get_png($tok, $itemId)
{
    $ch = curl_init(APSP_BASE . '/items/' . rawurlencode($itemId) . '/content');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $tok),
        CURLOPT_TIMEOUT        => 45,
    ));
    $b = curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($c === 200 && $b !== false && $b !== '') ? $b : false;
}
