<?php
/* ══════════════════════════════════════════════════════════
   APSA — gửi mail qua SMTP
   Host này KHÔNG có sendmail nên mail() của PHP không dùng được.
   Cấu hình đặt ở api/mail-config.php (xem mail-config.example.php).
   Chưa cấu hình thì apsa_mail() lặng lẽ bỏ qua — chuông trong app vẫn chạy.
   ══════════════════════════════════════════════════════════ */

function apsa_mail_config() {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $f = __DIR__ . '/mail-config.php';
    $cfg = is_file($f) ? (include $f) : [];
    if (!is_array($cfg)) $cfg = [];
    return $cfg;
}

function apsa_mail_ready() {
    $c = apsa_mail_config();
    return !empty($c['host']) && !empty($c['user']) && !empty($c['pass']);
}

/** Gửi 1 mail. Trả về true/false, không bao giờ ném lỗi ra ngoài. */
function apsa_mail($to, $subject, $htmlBody) {
    $c = apsa_mail_config();
    if (!apsa_mail_ready() || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $host   = $c['host'];
    $port   = (int)($c['port'] ?? 587);
    $secure = strtolower($c['secure'] ?? 'tls');          // tls | ssl | none
    $from   = $c['from'] ?? $c['user'];
    $name   = $c['from_name'] ?? 'APSA Tools';
    $to     = trim($to);

    $eol = "\r\n";
    $boundary = 'apsa' . md5(uniqid('', true));
    $head  = 'From: =?UTF-8?B?' . base64_encode($name) . '?= <' . $from . '>' . $eol;
    $head .= 'To: <' . $to . '>' . $eol;
    $head .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=' . $eol;
    $head .= 'MIME-Version: 1.0' . $eol;
    $head .= 'Date: ' . date('r') . $eol;
    $head .= 'Content-Type: text/html; charset=UTF-8' . $eol;
    $head .= 'Content-Transfer-Encoding: base64' . $eol;
    $data  = $head . $eol . chunk_split(base64_encode($htmlBody), 76, $eol);

    $target = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $fp = @stream_socket_client($target, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return false;
    stream_set_timeout($fp, 12);

    $read = function () use ($fp) {
        $out = '';
        while (($line = fgets($fp, 515)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return $out;
    };
    $cmd = function ($line, $expect) use ($fp, $read) {
        if ($line !== null) fwrite($fp, $line . "\r\n");
        $r = $read();
        return (int)substr(trim($r), 0, 3) === $expect || substr($r, 0, 1) === (string)(int)($expect / 100);
    };

    $ok = true;
    $read();                                              // banner
    $ok = $ok && $cmd('EHLO apsa.agency', 250);
    if ($secure === 'tls') {
        $ok = $ok && $cmd('STARTTLS', 220);
        $ok = $ok && @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $ok = $ok && $cmd('EHLO apsa.agency', 250);
    }
    $ok = $ok && $cmd('AUTH LOGIN', 334);
    $ok = $ok && $cmd(base64_encode($c['user']), 334);
    $ok = $ok && $cmd(base64_encode($c['pass']), 235);
    $ok = $ok && $cmd('MAIL FROM:<' . $from . '>', 250);
    $ok = $ok && $cmd('RCPT TO:<' . $to . '>', 250);
    $ok = $ok && $cmd('DATA', 354);
    if ($ok) {
        fwrite($fp, $data . "\r\n.\r\n");
        $ok = (int)substr(trim($read()), 0, 3) === 250;
    }
    @fwrite($fp, "QUIT\r\n");
    @fclose($fp);
    return $ok;
}

/** Khung mail dùng chung — nền tối, chữ lime cho khớp app */
function apsa_mail_html($title, $lines, $btnText = '', $btnUrl = '') {
    $esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $h  = '<div style="background:#0b0b0b;padding:26px;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif">';
    $h .= '<div style="max-width:560px;margin:0 auto;background:#111;border:1px solid #262626;border-radius:14px;overflow:hidden">';
    $h .= '<div style="background:#dff20d;padding:13px 20px;font-weight:800;letter-spacing:1px;color:#000">APSA TOOLS</div>';
    $h .= '<div style="padding:22px 20px;color:#e4e4e4;font-size:14px;line-height:1.6">';
    $h .= '<h2 style="margin:0 0 12px;font-size:17px;color:#fff">' . $esc($title) . '</h2>';
    foreach ((array)$lines as $l) $h .= '<p style="margin:0 0 10px">' . $l . '</p>';
    if ($btnText && $btnUrl) {
        $h .= '<p style="margin:20px 0 4px"><a href="' . $esc($btnUrl) . '" '
            . 'style="display:inline-block;background:#dff20d;color:#000;text-decoration:none;'
            . 'font-weight:800;padding:11px 20px;border-radius:9px">' . $esc($btnText) . '</a></p>';
    }
    $h .= '</div><div style="padding:12px 20px;border-top:1px solid #262626;color:#6b6b6b;font-size:11.5px">'
        . 'Thư tự động từ APSA Tools — vui lòng không trả lời.</div></div></div>';
    return $h;
}
