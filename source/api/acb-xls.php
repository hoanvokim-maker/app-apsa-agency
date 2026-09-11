<?php
/**
 * APSA - Xuat file "Chi Lo" ACB (.xls BIFF8) dung dinh dang file mau ChiLo_mau.xls
 * POST JSON {rows:[[stt, ten, ma_nh, so_tk, so_the, so_tien, noi_dung], ...]}  -> file .xls
 * Sinh file bang PHP thuan (api/acb-biff.php), khong can Python / thu vien ngoai.
 */
require_once __DIR__ . '/session-boot.php';
require_once __DIR__ . '/acb-biff.php';

function ax_fail($m, $c = 400) { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode(array('ok' => false, 'error' => $m), JSON_UNESCAPED_UNICODE); exit; }

if (empty($_SESSION['user_id'])) ax_fail('Bạn cần đăng nhập.', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') ax_fail('Chỉ nhận POST.', 405);

$B = json_decode(file_get_contents('php://input'), true);
if (!is_array($B) || empty($B['rows']) || !is_array($B['rows'])) ax_fail('Không có dòng nào để xuất.');
if (count($B['rows']) > 2000) ax_fail('Quá nhiều dòng (tối đa 2000).');

$rows = array();
foreach ($B['rows'] as $r) {
    if (!is_array($r)) continue;
    $r = array_values($r);
    while (count($r) < 7) $r[] = '';
    $name = trim(preg_replace('/\s+/', ' ', (string)$r[1]));
    $code = strtoupper(trim((string)$r[2]));
    $acc  = preg_replace('/[^0-9A-Za-z]/', '', (string)$r[3]);
    $card = preg_replace('/[^0-9]/', '', (string)$r[4]);
    $amt  = is_numeric($r[5]) ? (float)$r[5] : (float)preg_replace('/[^0-9.\-]/', '', (string)$r[5]);
    $memo = trim(preg_replace('/\s+/', ' ', (string)$r[6]));
    if ($name === '' && $acc === '' && $amt == 0) continue;
    $rows[] = array(count($rows) + 1, $name, $code, $acc, $card, $amt, $memo);
}
if (!$rows) ax_fail('Không có dòng hợp lệ.');

$xls = acb_make_xls($rows);
if (strlen($xls) < 512) ax_fail('Sinh file lỗi.', 500);

$fn = 'ACB_CHI_' . date('Ymd') . '.xls';
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $fn . '"');
header('Content-Length: ' . strlen($xls));
header('Cache-Control: private, no-store');
echo $xls;
