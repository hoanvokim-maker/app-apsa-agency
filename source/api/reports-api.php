<?php
/* ==============================================================
 *  reports-api.php  —  Bao cao dong tien (CHI ADMIN)
 *  GET ./api/reports-api.php?from=YYYY-MM&to=YYYY-MM
 *  Tra ve: { ok, months:[...], sup:[...], cus:[...], debt:{...} }
 *  Moc thoi gian: theo NGAY DIEN RA SU KIEN (event_date).
 * ============================================================== */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/session-boot.php';
require_once __DIR__ . '/perm.php';

function rp_out($d, $code = 200)
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Tong tien bao gia — dung dung cong thuc calcTotals() cua quotation-api.php */
function rp_total($r)
{
    $sub = round((float) $r['sub'], 2);
    $ma  = !empty($r['show_ma'])  ? round($sub * (float) $r['ma_percent'] / 100, 2) : 0.0;
    $aft = round($sub + $ma, 2);
    $vat = !empty($r['show_vat']) ? round($aft * (float) $r['vat_percent'] / 100, 2) : 0.0;
    return round($aft + $vat, 2);
}

/** Doc ngay dien ra su kien tu o text tu do -> 'Y-m-d' hoac '' */
function rp_evdate($s)
{
    $s = trim((string) $s);
    if ($s === '') return '';
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
    rp_out(array('ok' => false, 'error' => 'Chua dang nhap.'), 401);
}

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
);

pm_init($pdo);
if (!pm_is_admin()) {
    rp_out(array('ok' => false, 'error' => 'Chi Admin xem duoc bao cao nay.'), 403);
}

/* --- Khoang thoi gian --- */
$fm = isset($_GET['from']) ? preg_replace('/[^0-9-]/', '', (string) $_GET['from']) : '';
$to = isset($_GET['to'])   ? preg_replace('/[^0-9-]/', '', (string) $_GET['to'])   : '';
if (!preg_match('/^\d{4}-\d{2}$/', $fm)) $fm = date('Y') . '-01';
if (!preg_match('/^\d{4}-\d{2}$/', $to)) $to = date('Y') . '-12';
if ($to < $fm) { $tmp = $fm; $fm = $to; $to = $tmp; }
/* Moc thoi gian: ev = ngay dien ra su kien (mac dinh), qd = ngay bao gia */
$basis = (isset($_GET['basis']) && $_GET['basis'] === 'qd') ? 'qd' : 'ev';

$months = array();
$cur = $fm;
$guard = 0;
while ($cur <= $to && $guard < 120) {
    $months[$cur] = array('ym' => $cur, 'rev' => 0.0, 'cost' => 0.0, 'profit' => 0.0, 'n' => 0);
    $cur = date('Y-m', strtotime($cur . '-01 +1 month'));
    $guard++;
}
$d0 = $fm . '-01';
$d1 = date('Y-m-d', strtotime($to . '-01 +1 month'));

/* Trang thai coi la "da nhan du an" — giong home-stats.php */
$WON = array('confirmed', 'running', 'service_done', 'liq_sent', 'done', 'paid', 'dong_du_an');

/* --- Doanh thu tung du an --- */
$qsql = "SELECT q.id, q.status, q.event_date, q.quotation_date, q.client_name, q.customer_id, q.code, q.title,
                q.show_ma, q.ma_percent, q.show_vat, q.vat_percent,
                COALESCE(s.sub, 0) AS sub
           FROM quotations q
           LEFT JOIN (SELECT quotation_id, SUM(qty * unit_price) AS sub
                        FROM quotation_items
                       WHERE kind IS NULL OR kind = '' OR kind = 'item'
                       GROUP BY quotation_id) s ON s.quotation_id = q.id
          WHERE q.deleted_at IS NULL";

/* --- Chi phi thuc te tung du an --- */
$esql = "SELECT quotation_id,
                SUM(qty * price * (1 + COALESCE(vat_percent, 0) / 100)) AS cost,
                SUM(CASE WHEN paid = 1 THEN qty * price * (1 + COALESCE(vat_percent, 0) / 100)
                         ELSE 0 END) AS paid_amt
           FROM quotation_expenses
          WHERE kind IS NULL OR kind <> 'group'
          GROUP BY quotation_id";
$exp = array();
foreach ($pdo->query($esql) as $r) {
    $exp[(int) $r['quotation_id']] = array(
        'cost' => (float) $r['cost'],
        'paid' => (float) $r['paid_amt']
    );
}

$inRange = array();   /* id du an nam trong khoang — dung cho bang NCC */
$cusMap  = array();
$nodate  = 0;
$tot = array('rev' => 0.0, 'cost' => 0.0, 'n' => 0, 'paid' => 0.0);

foreach ($pdo->query($qsql) as $r) {
    if (!in_array((string) $r['status'], $WON, true)) continue;
    if ($basis === 'qd') {
        $ev = substr(trim((string) $r['quotation_date']), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ev)) $ev = '';
    } else {
        $ev = rp_evdate($r['event_date']);
    }
    if ($ev === '') { $nodate++; continue; }
    if ($ev < $d0 || $ev >= $d1) continue;
    $ym = substr($ev, 0, 7);
    if (!isset($months[$ym])) continue;

    $qid  = (int) $r['id'];
    $rev  = rp_total($r);
    $cost = isset($exp[$qid]) ? $exp[$qid]['cost'] : 0.0;
    $paid = isset($exp[$qid]) ? $exp[$qid]['paid'] : 0.0;

    $months[$ym]['rev']  += $rev;
    $months[$ym]['cost'] += $cost;
    $months[$ym]['n']    += 1;

    $inRange[$qid] = true;
    $tot['rev'] += $rev; $tot['cost'] += $cost; $tot['paid'] += $paid; $tot['n'] += 1;

    $cn = trim((string) $r['client_name']);
    if ($cn === '') $cn = '(chua co ten khach)';
    if (!isset($cusMap[$cn])) $cusMap[$cn] = array('name' => $cn, 'rev' => 0.0, 'cost' => 0.0, 'n' => 0);
    $cusMap[$cn]['rev']  += $rev;
    $cusMap[$cn]['cost'] += $cost;
    $cusMap[$cn]['n']    += 1;
}

/* --- Chi phi tach theo nha cung cap (chi cac du an trong khoang) --- */
$sup = array();
if (count($inRange) > 0) {
    $ids = implode(',', array_map('intval', array_keys($inRange)));
    $ssql = "SELECT payee_name, payee_id, paid,
                    qty * price * (1 + COALESCE(vat_percent, 0) / 100) AS amt
               FROM quotation_expenses
              WHERE quotation_id IN ($ids)
                AND (kind IS NULL OR kind <> 'group')";
    foreach ($pdo->query($ssql) as $r) {
        $nm = trim((string) $r['payee_name']);
        if ($nm === '') $nm = '(chua ghi ben nhan)';
        if (!isset($sup[$nm])) {
            $sup[$nm] = array('name' => $nm, 'total' => 0.0, 'paid' => 0.0, 'unpaid' => 0.0, 'n' => 0);
        }
        $amt = (float) $r['amt'];
        $sup[$nm]['total'] += $amt;
        $sup[$nm]['n'] += 1;
        if ((int) $r['paid'] === 1) $sup[$nm]['paid'] += $amt;
        else                        $sup[$nm]['unpaid'] += $amt;
    }
}

/* --- Cong no: tong tien da duyet nhung chua tra (toan bo du an con song) --- */
$dsql = "SELECT SUM(e.qty * e.price * (1 + COALESCE(e.vat_percent, 0) / 100)) AS s,
                COUNT(*) AS n
           FROM quotation_expenses e
           JOIN quotations q ON q.id = e.quotation_id
          WHERE q.deleted_at IS NULL
            AND (e.kind IS NULL OR e.kind <> 'group')
            AND (e.paid IS NULL OR e.paid = 0)";
$drow = $pdo->query($dsql)->fetch();

/* --- Ket qua --- */
$out = array();
foreach ($months as $m) {
    $m['rev']    = round($m['rev'], 0);
    $m['cost']   = round($m['cost'], 0);
    $m['profit'] = round($m['rev'] - $m['cost'], 0);
    $out[] = $m;
}

$supL = array_values($sup);
usort($supL, function ($a, $b) { return ($b['total'] < $a['total']) ? -1 : (($b['total'] > $a['total']) ? 1 : 0); });
$supL = array_slice($supL, 0, 20);
foreach ($supL as $i => $v) {
    $supL[$i]['total']  = round($v['total'], 0);
    $supL[$i]['paid']   = round($v['paid'], 0);
    $supL[$i]['unpaid'] = round($v['unpaid'], 0);
}

$cusL = array_values($cusMap);
usort($cusL, function ($a, $b) { return ($b['rev'] < $a['rev']) ? -1 : (($b['rev'] > $a['rev']) ? 1 : 0); });
$cusL = array_slice($cusL, 0, 20);
foreach ($cusL as $i => $v) {
    $cusL[$i]['rev']    = round($v['rev'], 0);
    $cusL[$i]['cost']   = round($v['cost'], 0);
    $cusL[$i]['profit'] = round($v['rev'] - $v['cost'], 0);
}

rp_out(array(
    'ok'     => true,
    'basis'  => $basis,
    'from'   => $fm,
    'to'     => $to,
    'months' => $out,
    'sup'    => $supL,
    'cus'    => $cusL,
    'total'  => array(
        'rev'    => round($tot['rev'], 0),
        'cost'   => round($tot['cost'], 0),
        'profit' => round($tot['rev'] - $tot['cost'], 0),
        'paid'   => round($tot['paid'], 0),
        'unpaid' => round($tot['cost'] - $tot['paid'], 0),
        'n'      => (int) $tot['n']
    ),
    'debt'   => array(
        'amount' => round((float) $drow['s'], 0),
        'lines'  => (int) $drow['n']
    ),
    'nodate' => (int) $nodate
));
