<?php
/**
 * APSA - Thư viện gửi thông báo qua Zalo Bot.
 *
 * Tài liệu: https://docs.zaloplatforms.com/docs/BOT
 *   - Gửi tin  : POST https://bot-api.zaloplatforms.com/bot<TOKEN>/sendMessage  {chat_id, text}
 *   - Webhook  : Zalo POST về app, kèm header X-Bot-Api-Secret-Token
 *
 * File này CHỈ khai báo hàm, không tự chạy gì cả -> include ở đâu cũng an toàn.
 * Mọi hàm đều "nuốt" lỗi: thông báo hỏng thì thôi, không được làm hỏng nghiệp vụ chính.
 *
 * v1.2.7
 */

if (defined('APSA_ZALO_LOADED')) return;
define('APSA_ZALO_LOADED', 1);

define('ZB_API', 'https://bot-api.zaloplatforms.com/bot');

/* ------------------------------------------------------------------ */
/*  Cấu hình                                                           */
/* ------------------------------------------------------------------ */

function zb_cfg()
{
    static $c = null;
    if ($c !== null) return $c;

    $f = __DIR__ . '/zalo-config.php';
    $c = is_file($f) ? @include $f : array();
    if (!is_array($c)) $c = array();

    $c += array(
        'enabled'      => false,
        'bot_token'    => '',
        'secret_token' => '',
        'app_url'      => 'https://app.apsa.agency/',
        'cron_key'     => '',
        'kinds'        => null,
    );
    return $c;
}

/** Đã khai báo đủ để gọi API chưa (chưa xét bật/tắt). */
function zb_configured()
{
    $c = zb_cfg();
    return trim((string) $c['bot_token']) !== '';
}

/** Có được phép gửi tin không. */
function zb_enabled()
{
    $c = zb_cfg();
    return !empty($c['enabled']) && zb_configured();
}

/** Danh sách loại thông báo được đẩy sang Zalo. */
function zb_kinds()
{
    $c = zb_cfg();
    if (is_array($c['kinds']) && $c['kinds']) return $c['kinds'];
    return array(
        'assign',           // được giao việc
        'task_done',        // người được giao đã báo xong
        'task_due',         // nhắc việc đến hạn / quá hạn
        'mention',          // bị nhắc tên trong thảo luận
        'reply',            // có người trả lời bình luận
        'leave_new',        // đơn xin nghỉ mới (Admin)
        'leave_canceled',   // đơn nghỉ bị huỷ
        'leave_approved',   // đơn nghỉ được duyệt
        'leave_rejected',   // đơn nghỉ bị từ chối
        'reopen_request',   // xin mở lại dự án đã đóng (Admin)
    );
}

/** Biểu tượng đầu tin cho từng loại. */
function zb_icon($kind)
{
    $m = array(
        'assign'         => '📌',
        'task_done'      => '✅',
        'task_due'       => '⏰',
        'mention'        => '💬',
        'reply'          => '↩️',
        'leave_new'      => '🌴',
        'leave_canceled' => '🌴',
        'leave_approved' => '🌴',
        'leave_rejected' => '🌴',
        'reopen_request' => '🔓',
    );
    return isset($m[$kind]) ? $m[$kind] : '🔔';
}

/* ------------------------------------------------------------------ */
/*  Gọi Zalo Bot API                                                   */
/* ------------------------------------------------------------------ */

/**
 * Gọi một method của Zalo Bot API.
 * @return array {ok:bool, result?:mixed, error?:string}
 */
function zb_api($method, $payload = array(), $timeout = 8)
{
    if (!zb_configured()) return array('ok' => false, 'error' => 'Chưa cấu hình Bot Token.');

    $c   = zb_cfg();
    $url = ZB_API . trim((string) $c['bot_token']) . '/' . $method;

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json; charset=utf-8'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
    ));
    $raw  = curl_exec($ch);
    $errn = curl_errno($ch);
    $errs = curl_error($ch);
    curl_close($ch);

    if ($errn) return array('ok' => false, 'error' => 'Không gọi được Zalo: ' . $errs);

    $j = json_decode((string) $raw, true);
    if (!is_array($j)) return array('ok' => false, 'error' => 'Zalo trả về dữ liệu lạ.', 'raw' => mb_substr((string) $raw, 0, 300));

    if (empty($j['ok'])) {
        return array(
            'ok'    => false,
            'error' => isset($j['description']) ? (string) $j['description'] : 'Zalo báo lỗi.',
            'code'  => isset($j['error_code']) ? $j['error_code'] : null,
        );
    }
    return array('ok' => true, 'result' => isset($j['result']) ? $j['result'] : null);
}

/** Gửi 1 tin nhắn văn bản tới 1 chat_id. */
function zb_send($chatId, $text)
{
    $chatId = trim((string) $chatId);
    $text   = trim((string) $text);
    if ($chatId === '' || $text === '') return array('ok' => false, 'error' => 'Thiếu chat_id hoặc nội dung.');
    if (mb_strlen($text, 'UTF-8') > 1990) $text = mb_substr($text, 0, 1990, 'UTF-8') . '…';
    return zb_api('sendMessage', array('chat_id' => $chatId, 'text' => $text));
}

/* ------------------------------------------------------------------ */
/*  Cơ sở dữ liệu                                                      */
/* ------------------------------------------------------------------ */

/** Thêm các cột Zalo vào app_users (chỉ chạy 1 lần cho mỗi phiên bản file). */
function zb_migrate(PDO $pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;

    $lock = sys_get_temp_dir() . '/apsa_zalo_mig_' . @filemtime(__FILE__) . '.lock';
    if (file_exists($lock)) return;

    try {
        $cols = array();
        foreach ($pdo->query('SHOW COLUMNS FROM `app_users`') as $r) $cols[$r['Field']] = 1;

        $add = array(
            'zalo_chat_id'  => "ALTER TABLE `app_users` ADD COLUMN `zalo_chat_id` VARCHAR(64) NULL DEFAULT NULL",
            'zalo_name'     => "ALTER TABLE `app_users` ADD COLUMN `zalo_name` VARCHAR(120) NULL DEFAULT NULL",
            'zalo_linked_at'=> "ALTER TABLE `app_users` ADD COLUMN `zalo_linked_at` DATETIME NULL DEFAULT NULL",
            'zalo_code'     => "ALTER TABLE `app_users` ADD COLUMN `zalo_code` VARCHAR(12) NULL DEFAULT NULL",
            'zalo_code_exp' => "ALTER TABLE `app_users` ADD COLUMN `zalo_code_exp` DATETIME NULL DEFAULT NULL",
        );
        foreach ($add as $col => $sql) {
            if (!isset($cols[$col])) $pdo->exec($sql);
        }
        @file_put_contents($lock, '1');
    } catch (Exception $e) { /* bỏ qua */ }
}

/** Lấy chat_id Zalo của 1 nhân viên (rỗng nếu chưa kết nối). */
function zb_chat_id(PDO $pdo, $userId)
{
    $userId = (int) $userId;
    if ($userId <= 0) return '';
    try {
        zb_migrate($pdo);
        $st = $pdo->prepare('SELECT zalo_chat_id FROM `app_users` WHERE id = ? AND active = 1');
        $st->execute(array($userId));
        return (string) $st->fetchColumn();
    } catch (Exception $e) { return ''; }
}

/* ------------------------------------------------------------------ */
/*  Đẩy thông báo                                                      */
/* ------------------------------------------------------------------ */

/** Ghép link tương đối thành link tuyệt đối. */
function zb_abs($url)
{
    $url = trim((string) $url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    $c = zb_cfg();
    return rtrim((string) $c['app_url'], '/') . '/' . ltrim($url, './');
}

/**
 * Đẩy 1 thông báo sang Zalo cho 1 nhân viên.
 * Gọi được từ bất kỳ đâu; tự bỏ qua nếu tắt / chưa kết nối / loại tin không nằm trong danh sách.
 */
function zb_push(PDO $pdo, $userId, $kind, $title, $body, $url = '')
{
    try {
        if (!zb_enabled()) return false;
        if (!in_array((string) $kind, zb_kinds(), true)) return false;

        $chat = zb_chat_id($pdo, $userId);
        if ($chat === '') return false;

        $text = zb_icon($kind) . ' ' . trim((string) $title);
        $b    = trim((string) $body);
        if ($b !== '') $text .= "\n" . $b;
        $abs = zb_abs($url);
        if ($abs !== '') $text .= "\n\n" . $abs;

        $r = zb_send($chat, $text);
        return !empty($r['ok']);
    } catch (Exception $e) { return false; }
      catch (Throwable $e) { return false; }
}

/* ------------------------------------------------------------------ */
/*  Kết nối tài khoản                                                  */
/* ------------------------------------------------------------------ */

/** Sinh mã kết nối 6 số cho 1 nhân viên, hạn 15 phút. */
function zb_make_code(PDO $pdo, $userId)
{
    zb_migrate($pdo);
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE `app_users` SET zalo_code = ?, zalo_code_exp = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?')
        ->execute(array($code, (int) $userId));
    $exp = date('Y-m-d H:i:s', time() + 900);
    return array('code' => $code, 'expires_at' => $exp);
}

/**
 * Nhận 1 tin nhắn từ webhook, xử lý kết nối / huỷ kết nối.
 * @return string Nội dung bot nên trả lời (rỗng = không trả lời)
 */
function zb_handle_message(PDO $pdo, $chatId, $displayName, $text)
{
    zb_migrate($pdo);

    $chatId = trim((string) $chatId);
    $raw    = trim((string) $text);
    $low    = mb_strtolower($raw, 'UTF-8');
    if ($chatId === '') return '';

    /* --- Huỷ kết nối --- */
    if (in_array($low, array('huy', 'huỷ', 'hủy', 'ngung', 'ngừng', 'unlink', 'stop'), true)) {
        $st = $pdo->prepare('UPDATE `app_users` SET zalo_chat_id = NULL, zalo_name = NULL, zalo_linked_at = NULL
                              WHERE zalo_chat_id = ?');
        $st->execute(array($chatId));
        return $st->rowCount() > 0
            ? 'Đã ngắt kết nối. Bạn sẽ không nhận thông báo từ APSA Tools nữa. Gửi lại mã kết nối bất cứ lúc nào để bật lại.'
            : 'Tài khoản Zalo này chưa kết nối với APSA Tools.';
    }

    /* --- Kiểm tra kết nối --- */
    if (in_array($low, array('trangthai', 'trạng thái', 'status', 'kiemtra'), true)) {
        $st = $pdo->prepare('SELECT display_name FROM `app_users` WHERE zalo_chat_id = ? AND active = 1');
        $st->execute(array($chatId));
        $n = $st->fetchColumn();
        return $n ? ('Đang kết nối với tài khoản APSA: ' . $n) : 'Chưa kết nối. Vui lòng gửi mã 6 số lấy từ trang Zalo trong APSA Tools.';
    }

    /* --- Mã kết nối 6 số --- */
    if (preg_match('/(\d{6})/', $raw, $m)) {
        $code = $m[1];
        $st = $pdo->prepare('SELECT id, display_name FROM `app_users`
                              WHERE zalo_code = ? AND zalo_code_exp > NOW() AND active = 1 LIMIT 1');
        $st->execute(array($code));
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) return 'Mã không đúng hoặc đã hết hạn. Vào APSA Tools › Zalo để lấy mã mới (mã có hiệu lực 15 phút).';

        /* Một tài khoản Zalo chỉ gắn với 1 người */
        $pdo->prepare('UPDATE `app_users` SET zalo_chat_id = NULL, zalo_name = NULL, zalo_linked_at = NULL
                        WHERE zalo_chat_id = ? AND id <> ?')->execute(array($chatId, (int) $u['id']));

        $pdo->prepare('UPDATE `app_users`
                          SET zalo_chat_id = ?, zalo_name = ?, zalo_linked_at = NOW(),
                              zalo_code = NULL, zalo_code_exp = NULL
                        WHERE id = ?')
            ->execute(array($chatId, mb_substr((string) $displayName, 0, 120, 'UTF-8'), (int) $u['id']));

        return 'Kết nối thành công với tài khoản ' . $u['display_name'] . '.' . "\n"
             . 'Từ giờ mọi thông báo của APSA Tools sẽ được gửi vào đây.' . "\n\n"
             . 'Nhắn "huy" nếu muốn ngắt kết nối.';
    }

    /* --- Không hiểu --- */
    $st = $pdo->prepare('SELECT display_name FROM `app_users` WHERE zalo_chat_id = ? AND active = 1');
    $st->execute(array($chatId));
    if ($st->fetchColumn()) return '';   // đã kết nối rồi thì im lặng, khỏi làm phiền

    return 'Đây là bot thông báo nội bộ của APSA.' . "\n"
         . 'Để nhận thông báo, mở APSA Tools › Zalo, lấy mã 6 số rồi gửi vào đây.';
}
