<?php
/**
 * APSA - Lop ket noi Microsoft Graph de ghi su kien vao lich Outlook.
 *
 * Dung luong quyen APPLICATION (client credentials) nen khong can ai dang nhap:
 * server tu lay token roi ghi thang vao lich cua hop thu duoc cau hinh.
 *
 * Quyen can cap tren Entra:  Calendars.ReadWrite  (Application) + Grant admin consent
 *
 * Ham public:
 *   mg_enabled()                       -> bool, da cau hinh xong chua
 *   mg_config()                        -> mang cau hinh
 *   mg_create_event($ev)               -> array('ok'=>bool,'id'=>string,'web_link'=>string,'error'=>string)
 *   mg_delete_event($eventId)          -> array('ok'=>bool,'error'=>string)
 *   mg_test()                          -> array('ok'=>bool,'error'=>string,'mailbox'=>string)
 */

if (!defined('MG_TOKEN_CACHE')) {
    define('MG_TOKEN_CACHE', sys_get_temp_dir() . '/apsa_msgraph_token.json');
}

/* ------------------------------------------------------------------ *
 *  Cau hinh
 * ------------------------------------------------------------------ */

function mg_config()
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $file = __DIR__ . '/msgraph-config.php';
    $cfg  = array(
        'enabled'          => false,
        'tenant_id'        => '',
        'client_id'        => '',
        'client_secret'    => '',
        'mailbox'          => 'hello@apsa.agency',
        'timezone'         => 'SE Asia Standard Time',
        'am_start'         => '08:30',
        'am_end'           => '12:00',
        'pm_start'         => '13:30',
        'pm_end'           => '17:30',
        'category_color'   => 'lightOrange',
        'invite_requester' => false,
    );

    if (is_file($file)) {
        $loaded = @include $file;
        if (is_array($loaded)) $cfg = array_merge($cfg, $loaded);
    }
    return $cfg;
}

function mg_enabled()
{
    $c = mg_config();
    return !empty($c['enabled'])
        && $c['tenant_id'] !== ''
        && $c['client_id'] !== ''
        && $c['client_secret'] !== ''
        && $c['mailbox'] !== '';
}

/* ------------------------------------------------------------------ *
 *  HTTP
 * ------------------------------------------------------------------ */

function mg_http($method, $url, $headers, $body, &$httpCode)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $out = curl_exec($ch);
    if ($out === false) {
        $err = curl_error($ch);
        curl_close($ch);
        $httpCode = 0;
        return array('__curl_error' => $err);
    }
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($out === '') return array();
    $json = json_decode($out, true);
    return is_array($json) ? $json : array('__raw' => $out);
}

/** Doc thong bao loi cho de hieu tu phan hoi cua Microsoft. */
function mg_err($res, $code, $fallback)
{
    if (isset($res['__curl_error'])) return 'Khong ket noi duoc Microsoft: ' . $res['__curl_error'];
    if (isset($res['error']['message'])) return $res['error']['message'] . ' (HTTP ' . $code . ')';
    if (isset($res['error_description'])) {
        // Cat bot phan trace dai loang ngoang cua Entra
        $m = $res['error_description'];
        $p = strpos($m, "\r\n");
        if ($p !== false) $m = substr($m, 0, $p);
        return $m . ' (HTTP ' . $code . ')';
    }
    if (isset($res['__raw'])) return substr($res['__raw'], 0, 300) . ' (HTTP ' . $code . ')';
    return $fallback . ' (HTTP ' . $code . ')';
}

/* ------------------------------------------------------------------ *
 *  Token  (cache lai vi token song ~1 tieng)
 * ------------------------------------------------------------------ */

function mg_token(&$error)
{
    $error = '';
    $c = mg_config();

    // 1) Thu lay tu cache
    if (is_file(MG_TOKEN_CACHE)) {
        $j = json_decode((string) @file_get_contents(MG_TOKEN_CACHE), true);
        if (is_array($j)
            && isset($j['token'], $j['expires'], $j['client'])
            && $j['client'] === $c['client_id']
            && $j['expires'] > time() + 120) {
            return $j['token'];
        }
    }

    // 2) Xin token moi
    $url  = 'https://login.microsoftonline.com/' . rawurlencode($c['tenant_id']) . '/oauth2/v2.0/token';
    $body = http_build_query(array(
        'client_id'     => $c['client_id'],
        'client_secret' => $c['client_secret'],
        'scope'         => 'https://graph.microsoft.com/.default',
        'grant_type'    => 'client_credentials',
    ));

    $code = 0;
    $res  = mg_http('POST', $url, array('Content-Type: application/x-www-form-urlencoded'), $body, $code);

    if ($code !== 200 || empty($res['access_token'])) {
        $error = mg_err($res, $code, 'Khong lay duoc token');
        return '';
    }

    $expires = time() + (int) (isset($res['expires_in']) ? $res['expires_in'] : 3600);
    @file_put_contents(MG_TOKEN_CACHE, json_encode(array(
        'token'   => $res['access_token'],
        'expires' => $expires,
        'client'  => $c['client_id'],
    )), LOCK_EX);
    @chmod(MG_TOKEN_CACHE, 0600);

    return $res['access_token'];
}

/* ------------------------------------------------------------------ *
 *  Su kien
 * ------------------------------------------------------------------ */

/**
 * $ev = array(
 *   'subject'   => 'Nghi phep - Nguyen Van A',
 *   'body'      => 'html',
 *   'all_day'   => true|false,
 *   'start'     => '2026-09-01' (all day) hoac '2026-09-01T13:30:00',
 *   'end'       => '2026-09-03' (all day: NGAY KE TIEP ngay cuoi) hoac '2026-09-01T17:30:00',
 *   'attendee'  => 'a@apsa.agency' | ''
 * )
 */
function mg_create_event($ev)
{
    $fail = array('ok' => false, 'id' => '', 'web_link' => '', 'error' => '');

    if (!mg_enabled()) {
        $fail['error'] = 'Chua cau hinh Microsoft Graph (api/msgraph-config.php).';
        return $fail;
    }

    $err   = '';
    $token = mg_token($err);
    if ($token === '') { $fail['error'] = $err; return $fail; }

    $c  = mg_config();
    $tz = $c['timezone'];

    $payload = array(
        'subject'   => isset($ev['subject']) ? $ev['subject'] : 'Nghi phep',
        'body'      => array(
            'contentType' => 'HTML',
            'content'     => isset($ev['body']) ? $ev['body'] : '',
        ),
        'isAllDay'  => !empty($ev['all_day']),
        'start'     => array('dateTime' => $ev['start'], 'timeZone' => $tz),
        'end'       => array('dateTime' => $ev['end'],   'timeZone' => $tz),
        'showAs'    => 'oof',
        'categories'=> array('Nghi phep'),
    );

    if (!empty($ev['all_day'])) {
        // Graph yeu cau all-day phai la 00:00:00
        $payload['start']['dateTime'] = substr($ev['start'], 0, 10) . 'T00:00:00';
        $payload['end']['dateTime']   = substr($ev['end'],   0, 10) . 'T00:00:00';
    }

    if (!empty($c['invite_requester']) && !empty($ev['attendee'])) {
        $payload['attendees'] = array(array(
            'emailAddress' => array('address' => $ev['attendee']),
            'type'         => 'required',
        ));
    }

    $url  = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($c['mailbox']) . '/events';
    $code = 0;
    $res  = mg_http('POST', $url, array(
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ), json_encode($payload, JSON_UNESCAPED_UNICODE), $code);

    if (($code === 200 || $code === 201) && !empty($res['id'])) {
        return array(
            'ok'       => true,
            'id'       => $res['id'],
            'web_link' => isset($res['webLink']) ? $res['webLink'] : '',
            'error'    => '',
        );
    }

    $fail['error'] = mg_err($res, $code, 'Tao su kien that bai');
    return $fail;
}

function mg_delete_event($eventId)
{
    if (!mg_enabled())  return array('ok' => false, 'error' => 'Chua cau hinh Microsoft Graph.');
    if ($eventId === '') return array('ok' => true,  'error' => '');

    $err   = '';
    $token = mg_token($err);
    if ($token === '') return array('ok' => false, 'error' => $err);

    $c    = mg_config();
    $url  = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($c['mailbox'])
          . '/events/' . rawurlencode($eventId);
    $code = 0;
    $res  = mg_http('DELETE', $url, array('Authorization: Bearer ' . $token), null, $code);

    // 404 = da bi xoa tay tren Outlook roi, coi nhu thanh cong
    if ($code === 204 || $code === 200 || $code === 404) return array('ok' => true, 'error' => '');
    return array('ok' => false, 'error' => mg_err($res, $code, 'Xoa su kien that bai'));
}

/** Kiem tra ket noi: lay token + doc thu lich cua hop thu. */
function mg_test()
{
    $c   = mg_config();
    $out = array('ok' => false, 'error' => '', 'mailbox' => $c['mailbox']);

    if (!mg_enabled()) {
        $out['error'] = 'Chua bat hoac chua dien du thong tin trong api/msgraph-config.php.';
        return $out;
    }

    $err   = '';
    $token = mg_token($err);
    if ($token === '') { $out['error'] = $err; return $out; }

    $url  = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($c['mailbox']) . '/calendar';
    $code = 0;
    $res  = mg_http('GET', $url, array('Authorization: Bearer ' . $token), null, $code);

    if ($code === 200 && !empty($res['id'])) {
        $out['ok']       = true;
        $out['calendar'] = isset($res['name']) ? $res['name'] : '';
        return $out;
    }

    $out['error'] = mg_err($res, $code, 'Khong doc duoc lich');
    return $out;
}
