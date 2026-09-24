<?php
/**
 * Mail delivery tester.
 *
 *   php mail_test.php you@example.com
 *
 * Sends one branded test message and reports exactly what the mail
 * server said, so a failure points at the cause instead of just
 * "delivery unavailable".
 */

// Command line only: over the web this would let anyone send mail from
// the system's Gmail account and read its mail settings.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool runs from the command line only: php mail_test.php you@example.com');
}

require_once __DIR__ . '/mailer.php';

$to = $argv[1] ?? '';

echo "MAEXX mail test\n";
echo str_repeat('=', 60) . "\n\n";

echo "Mode      : " . MAIL_MODE . "\n";
echo "Server    : " . (SMTP_HOST !== '' ? SMTP_HOST . ':' . SMTP_PORT : '(none)') . "\n";
echo "Encryption: " . (SMTP_ENCRYPTION !== '' ? SMTP_ENCRYPTION : 'none') . "\n";
echo "Login     : " . (SMTP_USERNAME !== '' ? SMTP_USERNAME : '(none)') . "\n";
echo "Password  : " . (SMTP_PASSWORD !== '' ? 'set (' . strlen(SMTP_PASSWORD) . " chars)" : 'NOT SET') . "\n";
echo "Sender    : " . MAIL_SENDER_EMAIL . "\n\n";

echo mail_mode_summary() . "\n\n";

if (MAIL_MODE === 'gmail' && SMTP_PASSWORD === '') {
    echo "STOP: no App Password found.\n";
    echo "      Open mail_secret.php and paste the 16-character code\n";
    echo "      from https://myaccount.google.com/apppasswords\n";
    exit(1);
}

if (MAIL_MODE === 'gmail' && strlen(SMTP_PASSWORD) !== 16) {
    echo "WARNING: a Google App Password is exactly 16 characters, this one is "
        . strlen(SMTP_PASSWORD) . ".\n";
    echo "         If it fails below, check you pasted the App Password and\n";
    echo "         not the normal account password.\n\n";
}

if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo "Usage: php mail_test.php you@example.com\n";
    exit(1);
}

echo "Sending to {$to} ...\n\n";

$body  = email_paragraph('This is a test message from the MAEXX inventory system.');
$body .= email_callout('If you are reading this in your inbox, email delivery is working and password reset codes will arrive here.', 'success');
$body .= email_detail_panel([
    'Mode'      => MAIL_MODE,
    'Server'    => SMTP_HOST . ':' . SMTP_PORT,
    'Sent at'   => date('F d, Y g:i A'),
], 'Test details');

$html = email_shell('Mail Delivery Test', 'MAEXX mail delivery test message.', $body, 'System Test', '#16a34a');
$text = "This is a test message from the MAEXX inventory system.\n\n"
      . "If you are reading this, email delivery is working.\n\n"
      . 'Sent ' . date('F d, Y g:i A') . " via " . SMTP_HOST . ':' . SMTP_PORT . "\n";

$result = send_email([['email' => $to]], 'MAEXX mail delivery test', $html, $text, ['test']);

if ($result['success']) {
    echo "SUCCESS - the mail server accepted the message.\n";
    echo "Message-ID: " . $result['message_id'] . "\n\n";

    echo MAIL_MODE === 'gmail'
        ? "Check the inbox for {$to} (look in Spam too, the first message often lands there).\n"
        : "Open http://localhost:8025 to read it.\n";

    exit(0);
}

echo "FAILED\n";
echo "Reason: " . $result['message'] . "\n\n";

echo "Common causes:\n";

if (MAIL_MODE === 'gmail') {
    echo "  - Wrong password: it must be the 16-character App Password,\n";
    echo "    not the Gmail account password.\n";
    echo "  - 2-Step Verification is off, so the App Password is invalid.\n";
    echo "  - No internet, or a firewall is blocking outbound port 587.\n";
} else {
    echo "  - Mailpit is not running. Start it with start_mailpit.bat\n";
    echo "    or by typing: mailpit\n";
}

echo "\nThe full SMTP conversation was written to mail_debug.log.\n";
exit(1);
