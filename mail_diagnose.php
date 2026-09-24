<?php
/**
 * Mail diagnostics — prints the full SMTP conversation.
 *
 *   php mail_diagnose.php recipient@example.com
 *
 * Use this when mail "is not arriving": it shows exactly what the mail
 * server replied, including the queue ID it assigned. A queue ID means
 * the message was accepted and the problem is on the receiving side
 * (filtering, folders), not in this system.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool runs from the command line only.');
}

require_once __DIR__ . '/mailer.php';

$to = $argv[1] ?? MAIL_SENDER_EMAIL;

echo "MAEXX mail diagnostics\n";
echo str_repeat('=', 68) . "\n\n";

echo "Mode      : " . MAIL_MODE . "\n";
echo "Server    : " . SMTP_HOST . ':' . SMTP_PORT . " (" . (SMTP_ENCRYPTION ?: 'no encryption') . ")\n";
echo "Sender    : " . MAIL_SENDER_EMAIL . "\n";
echo "Recipient : " . $to . "\n";

if (strcasecmp($to, MAIL_SENDER_EMAIL) === 0) {
    echo "\n";
    echo "NOTE: sender and recipient are the SAME Gmail account.\n";
    echo "      Gmail often keeps such messages out of the Inbox and files\n";
    echo "      them under 'All Mail' / 'Sent' instead. Test with a second\n";
    echo "      address to rule this out.\n";
}

echo "\n" . str_repeat('-', 68) . "\n";
echo "SMTP conversation\n";
echo str_repeat('-', 68) . "\n\n";

$body  = email_paragraph('Diagnostic message from the MAEXX inventory system.');
$body .= email_detail_panel([
    'Sent at' => date('F d, Y g:i:s A'),
    'Server'  => SMTP_HOST . ':' . SMTP_PORT,
], 'Diagnostics');

$html = email_shell('Mail Diagnostics', 'MAEXX diagnostic message.', $body, 'System Test', '#16a34a');
$text = 'Diagnostic message sent ' . date('F d, Y g:i:s A');

$messageId = sprintf('<%s.%s@%s>', date('YmdHis'), bin2hex(random_bytes(8)), mail_message_id_domain());
$message   = build_mime_message([['email' => $to, 'name' => '']], 'MAEXX diagnostic ' . date('H:i:s'), $html, $text, $messageId);

$result = smtp_deliver([$to], $message);

foreach ($result['dialogue'] ?? [] as $line) {
    echo '  ' . $line . "\n";
}

echo "\n" . str_repeat('-', 68) . "\n\n";

if (!$result['success']) {
    echo "RESULT: FAILED — " . $result['message'] . "\n";
    exit(1);
}

echo "RESULT: Gmail ACCEPTED the message.\n\n";
echo "Message-ID : " . $messageId . "\n";
echo "Size       : " . round(strlen($message) / 1024) . " KB\n\n";

echo "The last '< 250 2.0.0 OK ...' line above is Gmail's queue ID. Once\n";
echo "Gmail returns that, the message is in Google's system and this\n";
echo "application's job is done.\n\n";

echo "If it is not in the Inbox, search Gmail for:\n";
echo "  in:anywhere subject:(MAEXX)\n\n";
echo "That searches Spam, All Mail and Sent together.\n";
