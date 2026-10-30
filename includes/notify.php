<?php
/**
 * Employee messaging: Email (SMTP or PHP mail), SMS (generic HTTP gateway),
 * WhatsApp (Meta Cloud API or generic HTTP gateway).
 *
 * All credentials / endpoints live in the settings table (System Settings →
 * Notifications). Nothing is hard-coded; every send is logged in
 * notification_log with the raw gateway response for troubleshooting.
 */
declare(strict_types=1);

const NOTIFY_CHANNELS = ['email' => 'Email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'];

/** Which channels are configured (endpoint/credentials present). */
function notify_channel_ready(string $channel): bool
{
    return match ($channel) {
        'email'    => (bool)setting('mail_from_address', ''),
        'sms'      => (bool)setting('sms_api_url', ''),
        'whatsapp' => setting('wa_provider', 'meta') === 'meta' ? (bool)(setting('wa_phone_number_id', '') && setting('wa_access_token', '')) : (bool)setting('wa_api_url', ''),
        default    => false,
    };
}

/** Normalise a Pakistani mobile number to international format without "+" (923001234567). */
function notify_phone(?string $raw): ?string
{
    $d = preg_replace('/\D/', '', (string)$raw);
    if ($d === '') return null;
    $cc = preg_replace('/\D/', '', (string)setting('sms_country_code', '92')) ?: '92';
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    if (str_starts_with($d, '0')) $d = $cc . substr($d, 1);
    elseif (!str_starts_with($d, $cc)) $d = $cc . $d;
    return strlen($d) >= 11 ? $d : null;
}

/** Fill {placeholders} in a template. */
function notify_fill(string $template, array $vars): string
{
    $rep = [];
    foreach ($vars as $k => $v) $rep['{' . $k . '}'] = (string)$v;
    return strtr($template, $rep);
}

/** Default message for a service record (used when the officer leaves the message blank). */
function notify_default_message(array $emp, string $recordLabel, string $summary, ?string $date = null): string
{
    $tpl = (string)setting('notify_template', "Dear {name}, {record} has been recorded in your service record on {date}: {summary}. — {org}");
    return notify_fill($tpl, [
        'name' => $emp['name'], 'code' => $emp['employee_code'], 'record' => $recordLabel, 'summary' => $summary,
        'date' => fmt_date($date ?: today()), 'org' => setting('org_name', ORG_NAME), 'designation' => $emp['designation'] ?? '', 'department' => $emp['department'] ?? '',
    ]);
}

/* =====================================================================
 * PUBLIC API
 * ===================================================================*/
/**
 * Send a message to an employee over the selected channels.
 * @param string[] $channels  any of email|sms|whatsapp
 * @return array  ['email' => ['ok' => bool, 'info' => string], ...]
 */
function notify_employee(array $emp, array $channels, string $subject, string $message, ?string $refTable = null, ?int $refId = null): array
{
    $results = [];
    foreach (array_unique($channels) as $ch) {
        if (!isset(NOTIFY_CHANNELS[$ch])) continue;
        $recipient = match ($ch) {
            'email'    => trim((string)($emp['email'] ?? '')),
            'sms'      => notify_phone($emp['contact'] ?? null),
            'whatsapp' => notify_phone(($emp['whatsapp_no'] ?? null) ?: ($emp['contact'] ?? null)),
        };
        if (!$recipient) {
            $results[$ch] = ['ok' => false, 'info' => 'No ' . NOTIFY_CHANNELS[$ch] . ' address/number on the employee profile.'];
            notify_log((int)$emp['id'], $ch, null, $subject, $message, 'skipped', $results[$ch]['info'], $refTable, $refId);
            continue;
        }
        if (!notify_channel_ready($ch)) {
            $results[$ch] = ['ok' => false, 'info' => NOTIFY_CHANNELS[$ch] . ' gateway is not configured (System Settings → Notifications).'];
            notify_log((int)$emp['id'], $ch, $recipient, $subject, $message, 'not_configured', $results[$ch]['info'], $refTable, $refId);
            continue;
        }
        try {
            [$ok, $info] = match ($ch) {
                'email'    => notify_send_email($recipient, $emp['name'], $subject, $message),
                'sms'      => notify_send_sms($recipient, $message),
                'whatsapp' => notify_send_whatsapp($recipient, $message),
            };
        } catch (Throwable $e) {
            [$ok, $info] = [false, $e->getMessage()];
        }
        $results[$ch] = ['ok' => $ok, 'info' => $info];
        notify_log((int)$emp['id'], $ch, $recipient, $subject, $message, $ok ? 'sent' : 'failed', $info, $refTable, $refId);
    }
    return $results;
}

function notify_log(int $empId, string $channel, ?string $recipient, string $subject, string $message, string $status, string $response, ?string $refTable, ?int $refId): void
{
    try {
        db_insert('notification_log', [
            'employee_id' => $empId, 'channel' => $channel, 'recipient' => $recipient, 'subject' => mb_substr($subject, 0, 200), 'message' => $message,
            'status' => $status, 'response' => mb_substr($response, 0, 2000), 'related_table' => $refTable, 'related_id' => $refId, 'sent_by' => user_id(),
        ]);
    } catch (Throwable $e) { /* logging must not break the flow */ }
}

/** Human readable summary of a send result (for flash messages). */
function notify_result_text(array $results): string
{
    $parts = [];
    foreach ($results as $ch => $r) $parts[] = NOTIFY_CHANNELS[$ch] . ': ' . ($r['ok'] ? 'sent' : 'not sent (' . $r['info'] . ')');
    return implode(' · ', $parts);
}

/* =====================================================================
 * EMAIL  — SMTP (with SSL / STARTTLS) or PHP mail() fallback
 * ===================================================================*/
function notify_send_email(string $to, string $toName, string $subject, string $body): array
{
    $from     = (string)setting('mail_from_address', '');
    $fromName = (string)setting('mail_from_name', setting('org_name', ORG_NAME));
    $host     = trim((string)setting('smtp_host', ''));
    $html     = nl2br(e($body));
    $htmlBody = '<div style="font-family:Inter,Arial,sans-serif;font-size:14px;color:#0f172a;line-height:1.6">' . $html . '<hr style="border:0;border-top:1px solid #e2e8f0;margin:16px 0"><div style="font-size:12px;color:#64748b">' . e($fromName) . ' · ' . e(APP_NAME) . '</div></div>';

    if ($host === '') {
        $headers = "From: " . mb_encode_mimeheader($fromName) . " <$from>\r\nReply-To: $from\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nX-Mailer: " . APP_NAME;
        $ok = @mail($to, mb_encode_mimeheader($subject), $htmlBody, $headers);
        return [$ok, $ok ? 'Sent via PHP mail()' : 'PHP mail() returned false — check the server mail configuration or set SMTP.'];
    }

    $port   = (int)setting('smtp_port', 587);
    $enc    = strtolower((string)setting('smtp_encryption', 'tls'));   // tls | ssl | none
    $user   = (string)setting('smtp_username', '');
    $pass   = (string)setting('smtp_password', '');
    $ctx    = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $sock   = @stream_socket_client(($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) return [false, "SMTP connect failed: $errstr ($errno)"];
    stream_set_timeout($sock, 15);
    $read = function () use ($sock): string { $out = ''; while (($line = fgets($sock, 515)) !== false) { $out .= $line; if (strlen($line) < 4 || $line[3] !== '-') break; } return $out; };
    $cmd = function (string $c, array $okCodes) use ($sock, $read): string { fwrite($sock, $c . "\r\n"); $r = $read(); if (!in_array((int)substr($r, 0, 3), $okCodes, true)) throw new RuntimeException('SMTP error after "' . preg_replace('/^(AUTH|.{0,12}).*/s', '$1…', $c) . '": ' . trim($r)); return $r; };
    try {
        $read();
        $cmd('EHLO ' . (parse_url(BASE_URL, PHP_URL_HOST) ?: 'localhost'), [250]);
        if ($enc === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('STARTTLS negotiation failed');
            $cmd('EHLO ' . (parse_url(BASE_URL, PHP_URL_HOST) ?: 'localhost'), [250]);
        }
        if ($user !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($user), [334]);
            $cmd(base64_encode($pass), [235]);
        }
        $cmd("MAIL FROM:<$from>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);
        $msg = "From: " . mb_encode_mimeheader($fromName) . " <$from>\r\nTo: " . mb_encode_mimeheader($toName) . " <$to>\r\nSubject: " . mb_encode_mimeheader($subject) . "\r\nDate: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(8)) . '@' . (parse_url(BASE_URL, PHP_URL_HOST) ?: 'localhost') . ">\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . str_replace("\n.", "\n..", $htmlBody) . "\r\n.";
        $cmd($msg, [250]);
        fwrite($sock, "QUIT\r\n");
        fclose($sock);
        return [true, "Sent via SMTP $host:$port"];
    } catch (Throwable $e) {
        @fclose($sock);
        return [false, $e->getMessage()];
    }
}

/* =====================================================================
 * GENERIC HTTP GATEWAY (used by SMS and generic WhatsApp providers)
 *   url / body support placeholders: {to} {message} {sender} {message_url}
 * ===================================================================*/
function notify_http(string $url, string $method, string $headersRaw, string $body, array $vars): array
{
    $vars['message_url']  = rawurlencode($vars['message'] ?? '');
    $vars['message_json'] = trim((string)json_encode($vars['message'] ?? '', JSON_UNESCAPED_UNICODE), '"');   // safe inside a JSON body template
    $url  = notify_fill($url, $vars);
    $body = notify_fill($body, $vars);
    $headers = [];
    foreach (preg_split('/\r?\n/', $headersRaw) as $h) if (str_contains($h, ':')) $headers[] = trim($h);
    $method = strtoupper($method) === 'POST' ? 'POST' : 'GET';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => false]);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) return [false, 'HTTP error: ' . $err];
    } else {
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $method === 'POST' ? $body : null, 'timeout' => 20, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
        if ($resp === false) return [false, 'HTTP request failed'];
    }
    $ok = $code >= 200 && $code < 300;
    return [$ok, "HTTP $code: " . mb_substr(trim((string)$resp), 0, 500)];
}

/* =====================================================================
 * SMS
 * ===================================================================*/
function notify_send_sms(string $to, string $message): array
{
    return notify_http((string)setting('sms_api_url', ''), (string)setting('sms_api_method', 'GET'), (string)setting('sms_api_headers', ''), (string)setting('sms_api_body', ''),
        ['to' => $to, 'message' => $message, 'sender' => (string)setting('sms_sender_id', '')]);
}

/* =====================================================================
 * WHATSAPP — Meta Cloud API (default) or generic gateway
 * ===================================================================*/
function notify_send_whatsapp(string $to, string $message): array
{
    if (setting('wa_provider', 'meta') !== 'meta') {
        return notify_http((string)setting('wa_api_url', ''), (string)setting('wa_api_method', 'POST'), (string)setting('wa_api_headers', ''), (string)setting('wa_api_body', ''),
            ['to' => $to, 'message' => $message, 'sender' => (string)setting('wa_sender', '')]);
    }
    $version = (string)setting('wa_api_version', 'v20.0');
    $url  = "https://graph.facebook.com/$version/" . setting('wa_phone_number_id') . '/messages';
    $tpl  = (string)setting('wa_template_name', '');
    if ($tpl !== '') {
        // Template message (works outside the 24-hour customer-care window). Template must have one {{1}} body parameter.
        $payload = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => ['name' => $tpl, 'language' => ['code' => (string)setting('wa_template_lang', 'en')],
            'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $message]]]]]];
    } else {
        $payload = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['preview_url' => false, 'body' => $message]];
    }
    return notify_http($url, 'POST', "Authorization: Bearer " . setting('wa_access_token') . "\nContent-Type: application/json", json_encode($payload, JSON_UNESCAPED_UNICODE), ['to' => $to, 'message' => $message]);
}
