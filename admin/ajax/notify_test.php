<?php
/** Send a test message through the configured gateway (Super Admin). */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_super_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Invalid request.', 405);
csrf_verify();
$channel = (string)post('channel');
$to = trim((string)post('to', ''));
if (!isset(NOTIFY_CHANNELS[$channel])) json_error('Unknown channel.');
if ($to === '') json_error('Enter a recipient.');
if (!notify_channel_ready($channel)) json_error(NOTIFY_CHANNELS[$channel] . ' is not configured yet — fill in and save the settings above first.');
$msg = 'Test message from ' . setting('org_name', ORG_NAME) . ' (' . APP_NAME . ') sent ' . fmt_datetime(date('Y-m-d H:i:s')) . '.';
try {
    [$ok, $info] = match ($channel) {
        'email'    => filter_var($to, FILTER_VALIDATE_EMAIL) ? notify_send_email($to, 'Test', APP_NAME . ' test email', $msg) : [false, 'Invalid email address.'],
        'sms'      => ($n = notify_phone($to)) ? notify_send_sms($n, $msg) : [false, 'Invalid mobile number.'],
        'whatsapp' => ($n = notify_phone($to)) ? notify_send_whatsapp($n, $msg) : [false, 'Invalid mobile number.'],
    };
} catch (Throwable $e) { [$ok, $info] = [false, $e->getMessage()]; }
log_activity('NOTIFY_TEST', 'settings', null, null, ['channel' => $channel, 'to' => $to, 'ok' => $ok], 'Test ' . NOTIFY_CHANNELS[$channel] . ' sent');
$ok ? json_ok(NOTIFY_CHANNELS[$channel] . ' test sent successfully. Gateway response: ' . $info) : json_error(NOTIFY_CHANNELS[$channel] . ' test failed: ' . $info);
