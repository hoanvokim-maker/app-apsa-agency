<?php
/* =====================================================================
 *  home-stats.php  —  Tong hop du an cho trang chu (index.html)
 *  GET  ./api/home-stats.php
 *  Tra ve: { ok, allowed, month:{...}, next:{...} }
 *  v1.6.25
 * ===================================================================*/

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/session-boot.php';
require_once __DIR__ . '/perm.php';

function hs_out($d, $code = 200)
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Tong tien bao gia — dung dung cong thuc calcTotals() cua quotation-api.php */
function hs_total($r)
{
    $sub = round((float) $r['sub'], 2);
    $ma  = !empty($r['show_ma'])  ? round($sub * (float) $r['ma_percent'] / 100, 2) : 0.0;
    $aft = round($sub + $ma, 2);
    $vat = !empty($r['show_vat']) ? round($aft * (float) $r['vat_percent'] / 100, 2) : 0.0;
    return round($aft + $vat, 2);
}

/** Doc ngay dien ra su kien tu o text tu do -> 'Y-m-d' hoac '' */
function hs_evdate($s)
{
    $s = trim((string) $s);
    if ($s === '') {
        return '';
    }
    if (preg_match('#(\d{4})-(\d{1,2})-(\d{1,2})#', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
    }
    if (preg_match('#(\d{1,2})\s*[/.-]\s*(\d{1,2})\s*[/.-]\s*(\d{4})#', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }
    if (preg_match('#(\d{1,2})\s*[/.-]\s*(\d{1,2})\s*[/.-]\s*(\d{2})(?!\d)#', $s, $m)) {
        return sprintf('%04d-%02d-%02d', 2000 + (int) $m[3], (int) $m[2], (int) $m[1]);
    }
    return '';
}

$uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
if (!$uid) {
    hs_out(array('ok' => false, 'error' => 'Chua dang nhap.'), 401);
}

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
);

pm_init($pdo);
if (pm_level('project') < 1) {
    hs_out(array('ok' => true, 'allowed' => false));
}

/* Trang thai coi la "da nhan du an" — tu buoc Dat hang tro di. */
$WON  = array('confirmed', 'running', 'service_done', 'liq_sent', 'done', 'paid', 'dong_du_an');
/* Con dang chao gia — chua chot. */
$PEND = array('request', 'quote');
/* Dang chay that su — can co ngay dien ra de xep lich. */
$RUN  = array('confirmed', 'running', 'service_done', 'liq_sent');

/* --- Moc thoi gian --- */
$p0 = date('Y-m-01', strtotime('first day of last month'));
$m0 = date('Y-m-01');
$m1 = date('Y-m-01', strtotime('first day of next month'));
$m2 = date('Y-m-01', strtotime('first day of +2 month'));

$sql = "SELECT q.id, q.status, q.quotation_date, q.event_date,
               q.show_ma, q.ma_percent, q.show_vat, q.vat_percent,
               COALESCE(s.sub, 0) AS sub
          FROM quotations q
          LEFT JOIN (SELECT quotation_id, SUM(qty * unit_price) AS sub
                       FROM quotation_items
                      WHERE kind IS NULL OR kind = '' OR kind = 'item'
                      GROUP BY quotation_id) s ON s.quotation_id = q.id
         WHERE q.deleted_at IS NULL";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$mon = array('count' => 0, 'total' => 0.0, 'pend_count' => 0, 'pend_total' => 0.0, 'lost' => 0,
             'prev_count' => 0, 'prev_total' => 0.0);
$nxt = array('count' => 0, 'total' => 0.0, 'pend_count' => 0, 'pend_total' => 0.0, 'nodate' => 0);

foreach ($rows as $r) {
    $st  = (string) $r['status'];
    $won = in_array($st, $WON, true);
    $pen = in_array($st, $PEND, true);
    $tot = hs_total($r);

    /* Khoi 1 — theo ngay tao bao gia */
    $qd = (string) $r['quotation_date'];
    if ($qd !== '' && $qd >= $m0 && $qd < $m1) {
        if ($won) {
            $mon['count']++;
            $mon['total'] += $tot;
        } elseif ($pen) {
            $mon['pend_count']++;
            $mon['pend_total'] += $tot;
        } elseif ($st === 'lost') {
            $mon['lost']++;
        }
    }

    /* Khoi 2 — theo ngay dien ra su kien */
    $ev = hs_evdate($r['event_date']);
    if ($ev !== '' && $ev >= $m1 && $ev < $m2) {
        if ($won) {
            $nxt['count']++;
            $nxt['total'] += $tot;
        } elseif ($pen) {
            $nxt['pend_count']++;
            $nxt['pend_total'] += $tot;
        }
    } elseif ($ev === '' && in_array($st, $RUN, true)) {
        /* Du an dang chay nhung chua nhap ngay dien ra -> khong xep lich duoc */
        $nxt['nodate']++;
    }

    /* Thang truoc — de so sanh */
    if ($won && $qd !== '' && $qd >= $p0 && $qd < $m0) {
        $mon['prev_count']++;
        $mon['prev_total'] += $tot;
    }
}

$mon['total']      = round($mon['total'], 2);
$mon['pend_total'] = round($mon['pend_total'], 2);
$mon['prev_total'] = round($mon['prev_total'], 2);
$nxt['total']      = round($nxt['total'], 2);
$nxt['pend_total'] = round($nxt['pend_total'], 2);

$mon['label']      = date('m/Y');
$mon['prev_label'] = date('m/Y', strtotime($p0));
$nxt['label'] = date('m/Y', strtotime($m1));

/* ---- Du an cua rieng nguoi dang dang nhap, trong thang hien tai ---- */
$mine  = array('label' => date('m/Y'), 'doing' => 0, 'done' => 0, 'total' => 0, 'lost' => 0);
$uidMe = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
if ($uidMe > 0) {
    try {
        $stM = $pdo->prepare(
            "SELECT q.status
               FROM `quotations` q
              WHERE q.deleted_at IS NULL
                AND DATE_FORMAT(q.quotation_date, '%Y-%m') = ?
                AND EXISTS (SELECT 1 FROM `quotation_assignees` a
                             WHERE a.quotation_id = q.id AND a.user_id = ?)");
        $stM->execute(array(date('Y-m'), $uidMe));
        foreach ($stM->fetchAll(PDO::FETCH_COLUMN) as $stt) {
            $stt = (string) $stt;
            $mine['total']++;
            if ($stt === 'done' || $stt === 'paid')  $mine['done']++;
            elseif ($stt === 'lost')                 $mine['lost']++;
            else                                     $mine['doing']++;
        }
    } catch (PDOException $e) { /* bo qua */ }
}

hs_out(array('ok' => true, 'allowed' => true, 'month' => $mon, 'next' => $nxt, 'mine' => $mine));
