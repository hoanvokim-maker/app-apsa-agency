<?php
/**
 * APSA - Webhook nhận sự kiện từ Zalo Bot.
 *
 * Zalo gọi POST về đây kèm header X-Bot-Api-Secret-Token.
 * Dùng để: nhận mã kết nối 6 số của nhân viên -> gắn chat_id vào tài khoản.
 *
 * v1.2.7
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/zalo.php';

function zh_out($arr, $code = 200)
{
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---- Xác thực ---- */
$cfg = zb_cfg();
$expect = trim((string) $cfg['secret_token']);
if ($expect === '') zh_out(array('ok' => false, 'message' => 'Webhook chưa cấu hình.'), 503);

$got = '';
foreach (array('HTTP_X_BOT_API_SECRET_TOKEN', 'HTTP_X_BOT_API_SECRETTOKEN') as $k) {
    if (!empty($_SERVER[$k])) { $got = (string) $_SERVER[$k]; break; }
}
if ($got === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) {
        if (strtolower($k) === 'x-bot-api-secret-token') { $got = (string) $v; break; }
    }
}
if (!hash_equals($expect, trim($got))) zh_out(array('ok' => false, 'message' => 'Unauthorized'), 403);

/* ---- Đọc sự kiện ---- */
$raw = file_get_contents('php://input');
$ev  = json_decode((string) $raw, true);
if (!is_array($ev)) zh_out(array('ok' => true, 'message' => 'Bỏ qua'));

/* Zalo gui event_name/message o cap ngoai cung, KHONG boc trong result nhu tai lieu */
$res  = (isset($ev['result']) && is_array($ev['result'])) ? $ev['result'] : $ev;
$name = isset($res['event_name']) ? (string) $res['event_name'] : '';
$msg  = isset($res['message']) && is_array($res['message']) ? $res['message'] : array();

/* Zalo gọi thử khi đăng ký webhook -> chỉ cần trả 200 */
if ($name === '' || strpos($name, 'message.') !== 0) zh_out(array('ok' => true, 'message' => 'Success'));

$chatId = '';
if (isset($msg['chat']['id']))      $chatId = (string) $msg['chat']['id'];
elseif (isset($msg['from']['id']))  $chatId = (string) $msg['from']['id'];

$display = isset($msg['from']['display_name']) ? (string) $msg['from']['display_name'] : '';
$text    = isset($msg['text']) ? (string) $msg['text'] : '';

if ($chatId === '') zh_out(array('ok' => true, 'message' => 'Success'));

if ($name !== 'message.text.received') {
    zb_send($chatId, 'Bot chỉ xử lý tin nhắn dạng chữ. Vui lòng gửi mã 6 số lấy từ APSA Tools › Zalo.');
    zh_out(array('ok' => true, 'message' => 'Success'));
}

/* ---- Xử lý ---- */
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
    $reply = zb_handle_message($pdo, $chatId, $display, $text);
    if ($reply !== '') zb_send($chatId, $reply);
} catch (Exception $e) {
    /* không lộ chi tiết lỗi ra ngoài */
}

zh_out(array('ok' => true, 'message' => 'Success'));
