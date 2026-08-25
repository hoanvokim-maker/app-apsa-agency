<?php
// ============================================================
// APSA — Session boot  /api/session-boot.php
// Cấu hình session dùng chung cho toàn bộ app.apsa.agency.
// Mục tiêu: đăng nhập 1 lần dùng được ~1 THÁNG, không phải login lại mỗi ngày.
//
// Cách dùng:  require_once __DIR__ . '/session-boot.php';
//             (từ thư mục con:  require_once __DIR__ . '/../../api/session-boot.php';)
// ============================================================

if (!defined('APSA_SESSION_TTL')) {
    define('APSA_SESSION_TTL', 30 * 24 * 60 * 60); // 30 ngày
}

// Biến này một số file API cũ vẫn dùng lại → giữ ở global scope.
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if (session_status() !== PHP_SESSION_ACTIVE) {

    // 1) Thư mục lưu session riêng của app.
    //    Nếu dùng thư mục mặc định dùng chung, garbage collector của server có thể
    //    xoá file session sau ~24 phút (session.gc_maxlifetime mặc định = 1440s),
    //    khiến user bị đăng xuất dù cookie vẫn còn hạn.
    $apsaSessDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'apsa-sessions';
    if (!is_dir($apsaSessDir)) { @mkdir($apsaSessDir, 0700, true); }
    if (is_dir($apsaSessDir) && is_writable($apsaSessDir)) {
        @session_save_path($apsaSessDir);
    }

    // 2) Session sống 30 ngày ở cả 2 phía: server (gc) và trình duyệt (cookie).
    @ini_set('session.gc_maxlifetime', (string)APSA_SESSION_TTL);
    @ini_set('session.cookie_lifetime', (string)APSA_SESSION_TTL);
    @ini_set('session.use_strict_mode', '1');
    // Giảm xác suất GC quét nhầm khi dùng chung máy chủ.
    @ini_set('session.gc_probability', '1');
    @ini_set('session.gc_divisor', '1000');

    session_set_cookie_params([
        'lifetime' => APSA_SESSION_TTL,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('APSASESSID');
    session_start();

    // 3) Sliding expiration: mỗi lần user vào lại thì gia hạn thêm 30 ngày nữa
    //    (chỉ ghi lại tối đa 1 lần/ngày cho nhẹ).
    if (!empty($_SESSION['user_id'])) {
        $now = time();
        if ((int)($_SESSION['_touched_at'] ?? 0) < $now - 86400) {
            $_SESSION['_touched_at'] = $now;  // ghi lại file session → mtime mới, GC không xoá
            if (!headers_sent()) {
                apsa_send_session_cookie();
            }
        }
    }
}

/**
 * Gửi lại cookie session với hạn 30 ngày kể từ bây giờ.
 * Gọi sau khi login thành công và khi gia hạn định kỳ.
 */
function apsa_send_session_cookie() {
    if (headers_sent()) return;
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $params = [
        'expires'  => time() + APSA_SESSION_TTL,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if (PHP_VERSION_ID >= 70300) {
        setcookie(session_name(), session_id(), $params);
    } else {
        setcookie(session_name(), session_id(), $params['expires'], '/', '', $isHttps, true);
    }
}
