<?php
/**
 * MAEXX — SMTP mailer + branded email templates.
 *
 * Sends over a plain SMTP socket, so a stock XAMPP install is enough:
 * no Composer, no PHPMailer, no API keys. Configure the server in
 * mail_config.php.
 *
 * Templates reuse the palette and shapes of styles.css (navy #243f5f
 * header, rounded white card, slate body copy) so email matches the app.
 */

require_once __DIR__ . '/mail_config.php';

// ===============================================================
// Transport
// ===============================================================

/**
 * Send one email to one or more recipients over SMTP.
 *
 * @param array $recipients [['email' => ..., 'name' => ...], ...]
 * @param array $tags       Labels for the debug log only.
 * @return array ['success' => bool, 'message' => string, 'message_id' => ?string]
 */
function send_email(array $recipients, string $subject, string $htmlContent, string $textContent = '', array $tags = []): array
{
    $to = [];
    foreach ($recipients as $recipient) {
        $email = is_array($recipient) ? ($recipient['email'] ?? '') : $recipient;
        $email = trim((string) $email);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $to[] = [
            'email' => $email,
            'name'  => is_array($recipient) ? trim((string) ($recipient['name'] ?? '')) : '',
        ];
    }

    if (!$to) {
        return ['success' => false, 'message' => 'No valid recipient email address.', 'message_id' => null];
    }

    if (!mail_is_configured()) {
        mail_log('SKIPPED (SMTP not configured)', ['subject' => $subject, 'to' => array_column($to, 'email')]);
        return ['success' => false, 'message' => 'Email service is not configured yet.', 'message_id' => null];
    }

    $messageId = sprintf('<%s.%s@%s>', date('YmdHis'), bin2hex(random_bytes(8)), mail_message_id_domain());
    $message   = build_mime_message($to, $subject, $htmlContent, $textContent, $messageId);

    $result = smtp_deliver(array_column($to, 'email'), $message);

    if ($result['success']) {
        mail_log('SENT', [
            'subject'    => $subject,
            'to'         => array_column($to, 'email'),
            'tags'       => $tags,
            'message_id' => $messageId,
        ]);

        return ['success' => true, 'message' => 'Email sent.', 'message_id' => $messageId];
    }

    mail_log('FAILED', [
        'subject'  => $subject,
        'to'       => array_column($to, 'email'),
        'error'    => $result['message'],
        'dialogue' => $result['dialogue'] ?? [],
    ]);

    return ['success' => false, 'message' => $result['message'], 'message_id' => null];
}

/**
 * Turn a technical send error into a short, plain sentence for users.
 * The technical detail still goes to mail_debug.log.
 */
function mail_failure_reason(string $error): string
{
    $e = strtolower($error);

    if (str_contains($e, 'getaddrinfo') || str_contains($e, 'could not connect') || str_contains($e, 'no such host')
        || str_contains($e, 'timed out') || str_contains($e, 'network is unreachable')) {
        return 'There is no internet connection right now';
    }

    if (str_contains($e, 'not configured')) {
        return 'Email is not set up on this system yet';
    }

    return 'The email service is not working right now';
}

/**
 * Hostname announced in EHLO.
 */
function mail_hostname(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? (gethostname() ?: 'localhost');
    $host = preg_replace('/:\d+$/', '', $host);

    return $host !== '' ? $host : 'localhost';
}

/**
 * Domain used in the Message-ID.
 *
 * Taken from the sender address rather than the machine name: a PC
 * hostname like "SDBAKJDBKAJS1" is not a real domain, and a Message-ID
 * that does not look like one counts against deliverability.
 */
function mail_message_id_domain(): string
{
    $at = strrpos(MAIL_SENDER_EMAIL, '@');

    if ($at !== false) {
        $domain = substr(MAIL_SENDER_EMAIL, $at + 1);
        if ($domain !== '') {
            return $domain;
        }
    }

    return mail_hostname();
}

/**
 * Read one full SMTP reply, following multi-line continuations.
 *
 * @return array{0:int,1:string} [code, raw text]
 */
function smtp_read($socket): array
{
    $data = '';

    while (($line = fgets($socket, 515)) !== false) {
        $data .= $line;

        // "250-" continues, "250 " ends the reply.
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }

    return [intval(substr($data, 0, 3)), trim($data)];
}

/**
 * Write one command and check the reply code.
 *
 * @param int|int[] $expected
 * @return array{ok:bool, code:int, text:string}
 */
function smtp_command($socket, string $command, $expected, array &$dialogue, bool $secret = false): array
{
    if ($command !== '') {
        fwrite($socket, $command . "\r\n");
        $dialogue[] = '> ' . ($secret ? '[hidden]' : $command);
    }

    [$code, $text] = smtp_read($socket);
    $dialogue[]    = '< ' . $text;

    $expected = (array) $expected;

    return ['ok' => in_array($code, $expected, true), 'code' => $code, 'text' => $text];
}

/**
 * Open the connection, run the SMTP conversation, send the message.
 *
 * @param string[] $recipients
 * @return array{success:bool, message:string, dialogue?:array}
 */
function smtp_deliver(array $recipients, string $message): array
{
    $dialogue  = [];
    $encryption = strtolower(SMTP_ENCRYPTION);
    $prefix     = $encryption === 'ssl' ? 'ssl://' : '';

    $context = stream_context_create([
        'ssl' => [
            'verify_peer'       => !SMTP_ALLOW_SELF_SIGNED,
            'verify_peer_name'  => !SMTP_ALLOW_SELF_SIGNED,
            'allow_self_signed' => SMTP_ALLOW_SELF_SIGNED,
        ],
    ]);

    $socket = @stream_socket_client(
        $prefix . SMTP_HOST . ':' . SMTP_PORT,
        $errno,
        $errstr,
        SMTP_TIMEOUT,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        return [
            'success' => false,
            'message' => sprintf('Could not connect to %s:%s (%s).', SMTP_HOST, SMTP_PORT, $errstr ?: 'no response'),
        ];
    }

    stream_set_timeout($socket, SMTP_TIMEOUT);

    $fail = function (string $why) use ($socket, &$dialogue): array {
        @fwrite($socket, "QUIT\r\n");
        @fclose($socket);
        return ['success' => false, 'message' => $why, 'dialogue' => $dialogue];
    };

    // Greeting
    $reply = smtp_command($socket, '', 220, $dialogue);
    if (!$reply['ok']) {
        return $fail('The mail server refused the connection: ' . $reply['text']);
    }

    $hostname = mail_hostname();

    $reply = smtp_command($socket, 'EHLO ' . $hostname, 250, $dialogue);
    if (!$reply['ok']) {
        // Very old servers only speak HELO.
        $reply = smtp_command($socket, 'HELO ' . $hostname, 250, $dialogue);
        if (!$reply['ok']) {
            return $fail('The mail server rejected the greeting: ' . $reply['text']);
        }
    }

    // STARTTLS upgrade
    if ($encryption === 'tls') {
        $reply = smtp_command($socket, 'STARTTLS', 220, $dialogue);
        if (!$reply['ok']) {
            return $fail('The mail server does not support STARTTLS: ' . $reply['text']);
        }

        $crypto = @stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
        );

        if (!$crypto) {
            return $fail('The TLS handshake failed. Check SMTP_PORT and SMTP_ENCRYPTION in mail_config.php.');
        }

        $dialogue[] = '* TLS established';

        // The server must be re-greeted over the encrypted channel.
        $reply = smtp_command($socket, 'EHLO ' . $hostname, 250, $dialogue);
        if (!$reply['ok']) {
            return $fail('The mail server rejected the greeting after TLS: ' . $reply['text']);
        }
    }

    // Authentication (skipped when no username is configured)
    if (SMTP_USERNAME !== '') {
        $reply = smtp_command($socket, 'AUTH LOGIN', 334, $dialogue);
        if (!$reply['ok']) {
            return $fail('The mail server refused AUTH LOGIN: ' . $reply['text']);
        }

        $reply = smtp_command($socket, base64_encode(SMTP_USERNAME), 334, $dialogue);
        if (!$reply['ok']) {
            return $fail('The mail server rejected the username: ' . $reply['text']);
        }

        $reply = smtp_command($socket, base64_encode(SMTP_PASSWORD), 235, $dialogue, true);
        if (!$reply['ok']) {
            $hint = stripos($reply['text'], 'credential') !== false || $reply['code'] === 535
                ? ' (for Gmail this must be a 16-character App Password, not the account password)'
                : '';
            return $fail('Sign-in to the mail server failed' . $hint . ': ' . $reply['text']);
        }
    }

    // Envelope
    $reply = smtp_command($socket, 'MAIL FROM:<' . MAIL_SENDER_EMAIL . '>', 250, $dialogue);
    if (!$reply['ok']) {
        return $fail('The sender address was rejected: ' . $reply['text']);
    }

    $accepted = 0;
    foreach ($recipients as $recipient) {
        $reply = smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251], $dialogue);
        if ($reply['ok']) {
            $accepted++;
        }
    }

    if ($accepted === 0) {
        return $fail('Every recipient address was rejected by the mail server.');
    }

    // Body
    $reply = smtp_command($socket, 'DATA', 354, $dialogue);
    if (!$reply['ok']) {
        return $fail('The mail server refused the message body: ' . $reply['text']);
    }

    fwrite($socket, $message . "\r\n.\r\n");
    $dialogue[] = '> [message body]';

    [$code, $text] = smtp_read($socket);
    $dialogue[]    = '< ' . $text;

    @fwrite($socket, "QUIT\r\n");
    @fclose($socket);

    if ($code !== 250) {
        return ['success' => false, 'message' => 'The mail server did not accept the message: ' . $text, 'dialogue' => $dialogue];
    }

    return ['success' => true, 'message' => 'Email sent.', 'dialogue' => $dialogue];
}

/**
 * RFC 2047 encoding, so accented names and subjects survive transit.
 */
function mail_encode_header(string $text): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $text)) {
        return $text;
    }

    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

/**
 * "Name <email>" when a name is present, bare address otherwise.
 */
function mail_format_address(string $email, string $name = ''): string
{
    $name = trim($name);

    return $name === ''
        ? $email
        : '"' . str_replace('"', '', mail_encode_header($name)) . '" <' . $email . '>';
}

/**
 * Build a multipart/alternative message carrying both the plain-text
 * and HTML versions. Both parts are base64 encoded, which sidesteps
 * line-length limits and dot-stuffing entirely.
 */
function build_mime_message(array $to, string $subject, string $html, string $text, string $messageId): string
{
    $boundary = 'maexx_' . bin2hex(random_bytes(12));

    $toHeader = implode(', ', array_map(
        fn($entry) => mail_format_address($entry['email'], $entry['name'] ?? ''),
        $to
    ));

    if ($text === '') {
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    }

    $headers = [
        'From: ' . mail_format_address(MAIL_SENDER_EMAIL, MAIL_SENDER_NAME),
        'To: ' . $toHeader,
        'Subject: ' . mail_encode_header($subject),
        'Date: ' . date('r'),
        'Message-ID: ' . $messageId,
        'MIME-Version: 1.0',
        // Content-Type is appended below, once it is known whether the
        // logo needs to travel inside the message.
    ];

    if (MAIL_REPLY_TO_EMAIL !== '') {
        array_splice($headers, 1, 0, ['Reply-To: ' . mail_format_address(MAIL_REPLY_TO_EMAIL, MAIL_REPLY_TO_NAME)]);
    }

    // The text + HTML pair, which clients pick between.
    $alternative = implode("\r\n", [
        '',
        'This is a multi-part message in MIME format.',
        '',
        '--' . $boundary,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        trim(chunk_split(base64_encode($text), 76, "\r\n")),
        '',
        '--' . $boundary,
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        trim(chunk_split(base64_encode($html), 76, "\r\n")),
        '',
        '--' . $boundary . '--',
    ]);

    $logoFile = mail_logo_file();
    $usesCid  = strpos($html, 'cid:' . MAIL_LOGO_CID) !== false;

    // No inline image needed: a plain multipart/alternative will do.
    if (!$usesCid || $logoFile === null) {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        return implode("\r\n", $headers) . "\r\n" . $alternative;
    }

    // Otherwise wrap that pair together with the logo in a
    // multipart/related part, so the HTML can reference it by cid:
    // and the image travels with the message.
    $related = 'rel_' . bin2hex(random_bytes(12));

    $headers[] = 'Content-Type: multipart/related; type="multipart/alternative"; boundary="' . $related . '"';

    $body = implode("\r\n", [
        '',
        'This is a multi-part message in MIME format.',
        '',
        '--' . $related,
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        $alternative,
        '',
        '--' . $related,
        'Content-Type: ' . mail_mime_type($logoFile) . '; name="' . basename($logoFile) . '"',
        'Content-Transfer-Encoding: base64',
        'Content-ID: <' . MAIL_LOGO_CID . '>',
        'Content-Disposition: inline; filename="' . basename($logoFile) . '"',
        '',
        trim(chunk_split(base64_encode((string) file_get_contents($logoFile)), 76, "\r\n")),
        '',
        '--' . $related . '--',
    ]);

    return implode("\r\n", $headers) . "\r\n" . $body;
}

/**
 * Media type for an attached file, from its extension.
 */
function mail_mime_type(string $path): string
{
    $types = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
    ];

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    return $types[$extension] ?? 'application/octet-stream';
}

/**
 * Append a line to the mail debug log. Silent when logging is off.
 */
function mail_log(string $event, array $context = []): void
{
    if (!defined('MAIL_DEBUG_LOG') || MAIL_DEBUG_LOG === '') {
        return;
    }

    $line = sprintf(
        "[%s] %s %s%s",
        date('Y-m-d H:i:s'),
        $event,
        json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        PHP_EOL
    );

    @file_put_contents(MAIL_DEBUG_LOG, $line, FILE_APPEND | LOCK_EX);
}

// ===============================================================
// Template shell + building blocks
// ===============================================================

/**
 * Wrap body content in the branded MAEXX email shell.
 */
function email_shell(string $heading, string $preheader, string $bodyHtml, string $badge = '', string $badgeColor = ''): string
{
    $navy   = BRAND_NAVY;
    $dark   = BRAND_DARK;
    $bg     = BRAND_BG;
    $text   = BRAND_TEXT;
    $muted  = BRAND_MUTED;
    $border = BRAND_BORDER;

    $appName    = htmlspecialchars(APP_NAME, ENT_QUOTES);
    $tagline    = htmlspecialchars(APP_TAGLINE, ENT_QUOTES);
    $heading    = htmlspecialchars($heading, ENT_QUOTES);
    $preheader  = htmlspecialchars($preheader, ENT_QUOTES);
    $year       = date('Y');

    $logo = '<img src="' . htmlspecialchars(mail_logo_src(), ENT_QUOTES) . '" width="72" height="72" alt="' . $appName . '" style="display:block;border:0;width:72px;height:72px;border-radius:50%;background:#ffffff;">';

    $badgeHtml = '';
    if ($badge !== '') {
        $badgeColor = $badgeColor ?: $navy;
        $badgeHtml  = '
                        <tr>
                            <td style="padding:28px 32px 0;">
                                <span style="display:inline-block;padding:6px 14px;border-radius:999px;background:' . $badgeColor . '1a;color:' . $badgeColor . ';font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;font-family:Arial,Helvetica,sans-serif;">' . htmlspecialchars($badge, ENT_QUOTES) . '</span>
                            </td>
                        </tr>';
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title>{$heading}</title>
</head>
<body style="margin:0;padding:0;background:{$bg};-webkit-font-smoothing:antialiased;">

<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{$preheader}</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{$bg};">
<tr>
<td align="center" style="padding:32px 16px;">

    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;background:#ffffff;border:1px solid {$border};border-radius:18px;overflow:hidden;">

        <!-- HEADER -->
        <tr>
            <td align="center" bgcolor="{$navy}" style="background:{$navy};padding:34px 32px 30px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                    <tr><td align="center" style="padding-bottom:16px;">{$logo}</td></tr>
                </table>
                <div style="font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;color:#ffffff;letter-spacing:0.6px;">{$appName}</div>
                <div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;color:rgba(255,255,255,0.72);margin-top:4px;">{$tagline}</div>
            </td>
        </tr>

        <tr><td height="4" bgcolor="{$dark}" style="background:{$dark};height:4px;line-height:4px;font-size:0;">&nbsp;</td></tr>
        {$badgeHtml}

        <!-- BODY -->
        <tr>
            <td style="padding:22px 32px 34px;font-family:Arial,Helvetica,sans-serif;color:{$text};">
                <h1 style="margin:0 0 14px;font-size:22px;line-height:1.35;font-weight:700;color:#0f172a;">{$heading}</h1>
                {$bodyHtml}
            </td>
        </tr>

        <!-- FOOTER -->
        <tr>
            <td style="padding:22px 32px 26px;background:#f8fafc;border-top:1px solid {$border};font-family:Arial,Helvetica,sans-serif;">
                <p style="margin:0 0 6px;font-size:12px;line-height:1.6;color:{$muted};">
                    This is an automated message from the {$appName} inventory system. Please do not reply to this email.
                </p>
                <p style="margin:0;font-size:11px;color:#94a3b8;">&copy; {$year} {$appName} &middot; All rights reserved.</p>
            </td>
        </tr>

    </table>

</td>
</tr>
</table>

</body>
</html>
HTML;
}

/**
 * Standard paragraph.
 */
function email_paragraph(string $html): string
{
    return '<p style="margin:0 0 16px;font-size:14px;line-height:1.75;color:' . BRAND_TEXT . ';">' . $html . '</p>';
}

/**
 * Large, spaced-out one-time code block.
 */
function email_code_block(string $code, string $caption = ''): string
{
    $code    = htmlspecialchars($code, ENT_QUOTES);
    $caption = $caption !== '' ? '<div style="margin-top:10px;font-size:12px;color:' . BRAND_MUTED . ';">' . htmlspecialchars($caption, ENT_QUOTES) . '</div>' : '';

    return '
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px;">
                    <tr>
                        <td align="center" style="background:#f8fafc;border:1px solid ' . BRAND_BORDER . ';border-radius:14px;padding:22px 16px;">
                            <div style="font-size:11px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:' . BRAND_MUTED . ';margin-bottom:10px;font-family:Arial,Helvetica,sans-serif;">Verification Code</div>
                            <div style="font-family:\'Courier New\',Courier,monospace;font-size:34px;font-weight:700;letter-spacing:10px;color:' . BRAND_NAVY . ';padding-left:10px;">' . $code . '</div>
                            ' . $caption . '
                        </td>
                    </tr>
                </table>';
}

/**
 * Label/value detail card, e.g. the account behind a reset request.
 *
 * @param array<string,string> $rows
 */
function email_detail_panel(array $rows, string $title = ''): string
{
    $titleHtml = $title !== ''
        ? '<div style="font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:' . BRAND_MUTED . ';padding-bottom:12px;font-family:Arial,Helvetica,sans-serif;">' . htmlspecialchars($title, ENT_QUOTES) . '</div>'
        : '';

    $rowsHtml = '';
    foreach ($rows as $label => $value) {
        $rowsHtml .= '
                                <tr>
                                    <td style="padding:7px 0;font-size:13px;color:' . BRAND_MUTED . ';white-space:nowrap;vertical-align:top;width:40%;">' . htmlspecialchars($label, ENT_QUOTES) . '</td>
                                    <td style="padding:7px 0;font-size:13px;font-weight:600;color:#0f172a;vertical-align:top;word-break:break-word;">' . htmlspecialchars($value, ENT_QUOTES) . '</td>
                                </tr>';
    }

    return '
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
                    <tr>
                        <td style="background:#f8fafc;border:1px solid ' . BRAND_BORDER . ';border-radius:14px;padding:18px 20px;font-family:Arial,Helvetica,sans-serif;">
                            ' . $titleHtml . '
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rowsHtml . '
                            </table>
                        </td>
                    </tr>
                </table>';
}

/**
 * Coloured callout matching the .alert styles in styles.css.
 * $type: info | success | warning | danger
 */
function email_callout(string $html, string $type = 'info'): string
{
    $palette = [
        'info'    => ['#f8fafc', BRAND_BORDER, BRAND_TEXT],
        'success' => ['#f0fdf4', '#bbf7d0', '#15803d'],
        'warning' => ['#fffbeb', '#fde68a', '#b45309'],
        'danger'  => ['#fef2f2', '#fecaca', '#b91c1c'],
    ];

    [$bg, $border, $color] = $palette[$type] ?? $palette['info'];

    return '
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
                    <tr>
                        <td style="background:' . $bg . ';border:1px solid ' . $border . ';border-radius:12px;padding:14px 16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.65;color:' . $color . ';">' . $html . '</td>
                    </tr>
                </table>';
}

/**
 * Bulletproof-ish navy call-to-action button.
 */
function email_button(string $label, string $url): string
{
    return '
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 22px;">
                    <tr>
                        <td align="center" bgcolor="' . BRAND_NAVY . '" style="background:' . BRAND_NAVY . ';border-radius:12px;">
                            <a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" style="display:inline-block;padding:13px 30px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:12px;">' . htmlspecialchars($label, ENT_QUOTES) . '</a>
                        </td>
                    </tr>
                </table>';
}

/**
 * Small muted footnote inside the body.
 */
function email_note(string $html): string
{
    return '<p style="margin:0;font-size:12px;line-height:1.7;color:#94a3b8;">' . $html . '</p>';
}

// ===============================================================
// Password-reset messages
// ===============================================================

/**
 * Email the one-time code to the account owner.
 */
function send_password_reset_otp_email(array $user, string $otp, array $meta = []): array
{
    $name     = $user['name'] ?? 'there';
    $minutes  = OTP_LIFETIME_MINUTES;
    $loginUrl = app_base_url() . '/otp_verify.php';

    $body  = email_paragraph('Hi <strong>' . htmlspecialchars($name, ENT_QUOTES) . '</strong>,');
    $body .= email_paragraph('We received a request to reset the password for your MAEXX account. Use the verification code below to continue.');
    $body .= email_code_block($otp, 'Expires in ' . $minutes . ' minutes');
    $body .= email_button('Enter Verification Code', $loginUrl);
    $body .= email_detail_panel([
        'Account'      => $user['username'] ?? ($user['email'] ?? '—'),
        'Role'         => $user['role'] ?? '—',
        'Requested on' => $meta['requested_at'] ?? date('F d, Y g:i A'),
        'IP address'   => $meta['ip'] ?? 'Unknown',
    ], 'Request details');
    $body .= email_callout('<strong>Didn\'t request this?</strong> You can safely ignore this email — your password stays unchanged. Your administrator has also been notified of this request.', 'warning');
    $body .= email_note('For your security, never share this code with anyone, including MAEXX staff.');

    $text = "Hi {$name},\n\n"
        . "We received a request to reset your MAEXX account password.\n\n"
        . "Verification code: {$otp}\n"
        . "This code expires in {$minutes} minutes.\n\n"
        . "Enter it here: {$loginUrl}\n\n"
        . "If you did not request this, you can ignore this email. Your administrator has been notified.\n\n"
        . APP_NAME;

    $html = email_shell(
        'Password Reset Verification',
        'Your MAEXX verification code expires in ' . $minutes . ' minutes.',
        $body,
        'Account Security'
    );

    return send_email(
        [['email' => $user['email'] ?? '', 'name' => $user['name'] ?? '']],
        'Your MAEXX password reset code',
        $html,
        $text,
        ['password-reset', 'otp']
    );
}

/**
 * Notify administrators that a staff member requested a password reset.
 *
 * @param array $recipients [['email' => ..., 'name' => ...], ...]
 */
function send_admin_reset_notification(array $recipients, array $user, array $meta = []): array
{
    $requestedAt = $meta['requested_at'] ?? date('F d, Y g:i A');
    $ip          = $meta['ip'] ?? 'Unknown';
    $status      = $meta['account_status'] ?? ($user['status'] ?? 'active');
    $blocked     = strcasecmp($status, 'active') !== 0;
    $usersUrl    = app_base_url() . '/user_management.php';

    $body  = email_paragraph('A password reset was requested for a MAEXX system account. Review the details below and take action if this request looks unexpected.');

    $body .= email_detail_panel([
        'Name'           => $user['name'] ?? '—',
        'Username'       => $user['username'] ?? '—',
        'Email'          => $user['email'] ?? '—',
        'Role'           => $user['role'] ?? '—',
        'Account status' => ucfirst($status),
        'Requested on'   => $requestedAt,
        'IP address'     => $ip,
    ], 'Requesting account');

    if ($blocked) {
        $body .= email_callout(
            '<strong>No code was issued.</strong> This account is <strong>' . htmlspecialchars($status, ENT_QUOTES) . '</strong>, so the reset was blocked. Reactivate the account in User Management if this staff member should regain access.',
            'danger'
        );
    } else {
        $body .= email_callout(
            'A one-time verification code valid for <strong>' . OTP_LIFETIME_MINUTES . ' minutes</strong> was emailed to the account owner. No action is needed if this request is expected.',
            'success'
        );
    }

    $body .= email_button('Open User Management', $usersUrl);
    $body .= email_note('If this request was not made by the account owner, deactivate the account immediately and reset their credentials from User Management.');

    $text = "Password reset requested — " . APP_NAME . "\n\n"
        . "Name: " . ($user['name'] ?? '-') . "\n"
        . "Username: " . ($user['username'] ?? '-') . "\n"
        . "Email: " . ($user['email'] ?? '-') . "\n"
        . "Role: " . ($user['role'] ?? '-') . "\n"
        . "Account status: " . ucfirst($status) . "\n"
        . "Requested on: {$requestedAt}\n"
        . "IP address: {$ip}\n\n"
        . ($blocked
            ? "No verification code was issued because the account is {$status}.\n"
            : "A verification code valid for " . OTP_LIFETIME_MINUTES . " minutes was emailed to the account owner.\n")
        . "\nUser Management: {$usersUrl}\n";

    $subject = $blocked
        ? 'Blocked password reset request — ' . ($user['name'] ?? 'MAEXX user')
        : 'Password reset requested — ' . ($user['name'] ?? 'MAEXX user');

    $html = email_shell(
        $blocked ? 'Blocked Password Reset Request' : 'Password Reset Request',
        ($user['name'] ?? 'A user') . ' requested a password reset on ' . $requestedAt . '.',
        $body,
        $blocked ? 'Action Required' : 'Admin Notification',
        $blocked ? '#dc2626' : BRAND_NAVY
    );

    return send_email($recipients, $subject, $html, $text, ['password-reset', 'admin-notification']);
}

/**
 * Confirm to the user (and their admins) that the password was changed.
 */
function send_password_changed_email(array $user, array $meta = []): array
{
    $changedAt = $meta['changed_at'] ?? date('F d, Y g:i A');
    $loginUrl  = app_base_url() . '/login.php';

    $body  = email_paragraph('Hi <strong>' . htmlspecialchars($user['name'] ?? 'there', ENT_QUOTES) . '</strong>,');
    $body .= email_paragraph('The password for your MAEXX account was changed successfully. You can now sign in with your new password.');
    $body .= email_detail_panel([
        'Account'    => $user['username'] ?? ($user['email'] ?? '—'),
        'Changed on' => $changedAt,
        'IP address' => $meta['ip'] ?? 'Unknown',
    ], 'Change details');
    $body .= email_button('Go to Login', $loginUrl);
    $body .= email_callout('<strong>Wasn\'t you?</strong> Contact your system administrator right away so your account can be secured.', 'danger');

    $text = "Hi " . ($user['name'] ?? 'there') . ",\n\n"
        . "Your MAEXX account password was changed on {$changedAt}.\n"
        . "Sign in here: {$loginUrl}\n\n"
        . "If this wasn't you, contact your system administrator immediately.\n";

    $html = email_shell(
        'Your Password Was Changed',
        'Your MAEXX account password was updated on ' . $changedAt . '.',
        $body,
        'Account Security',
        '#16a34a'
    );

    return send_email(
        [['email' => $user['email'] ?? '', 'name' => $user['name'] ?? '']],
        'Your MAEXX password was changed',
        $html,
        $text,
        ['password-reset', 'confirmation']
    );
}

/**
 * Email the signup verification code to someone creating an account.
 * The account is only written to users.json after this code is entered.
 */
function send_signup_otp_email(string $name, string $email, string $otp, array $meta = []): array
{
    $minutes = OTP_LIFETIME_MINUTES;

    $body  = email_paragraph('Hi <strong>' . htmlspecialchars($name, ENT_QUOTES) . '</strong>,');
    $body .= email_paragraph('Thanks for signing up for the MAEXX inventory system. Enter the code below to confirm this email address and finish creating your account.');
    $body .= email_code_block($otp, 'Expires in ' . $minutes . ' minutes');
    $body .= email_detail_panel([
        'Email'        => $email,
        'Requested on' => $meta['requested_at'] ?? date('F d, Y g:i A'),
        'IP address'   => $meta['ip'] ?? 'Unknown',
    ], 'Signup details');
    $body .= email_callout('Your account stays unconfirmed until this code is entered. If you did not sign up, simply ignore this email — nothing was created.', 'warning');
    $body .= email_note('Never share this code with anyone, including MAEXX staff.');

    $text = "Hi {$name},\n\n"
        . "Use this code to confirm your email and finish creating your MAEXX account.\n\n"
        . "Verification code: {$otp}\n"
        . "This code expires in {$minutes} minutes.\n\n"
        . "If you did not sign up, ignore this email — no account was created.\n\n"
        . APP_NAME;

    $html = email_shell(
        'Confirm Your Email Address',
        'Your MAEXX signup code expires in ' . $minutes . ' minutes.',
        $body,
        'Email Verification'
    );

    return send_email(
        [['email' => $email, 'name' => $name]],
        'Confirm your MAEXX account',
        $html,
        $text,
        ['signup', 'otp']
    );
}

/**
 * Tell administrators that a new account finished email verification.
 */
function send_admin_new_account_notification(array $recipients, array $user, array $meta = []): array
{
    $createdAt = $meta['created_at'] ?? date('F d, Y g:i A');
    $usersUrl  = app_base_url() . '/user_management.php';

    $body  = email_paragraph('A new account was created on the MAEXX inventory system after confirming its email address.');
    $body .= email_detail_panel([
        'Name'         => $user['name'] ?? '-',
        'Email'        => $user['email'] ?? '-',
        'Role'         => $user['role'] ?? '-',
        'Created on'   => $createdAt,
        'IP address'   => $meta['ip'] ?? 'Unknown',
    ], 'New account');
    $body .= email_callout('Review the role assigned to this account. New signups default to <strong>Product Inventory</strong> access.', 'info');
    $body .= email_button('Open User Management', $usersUrl);

    $text = "A new MAEXX account was created after email verification.\n\n"
        . "Name: " . ($user['name'] ?? '-') . "\n"
        . "Email: " . ($user['email'] ?? '-') . "\n"
        . "Role: " . ($user['role'] ?? '-') . "\n"
        . "Created on: {$createdAt}\n\n"
        . "User Management: {$usersUrl}\n";

    $html = email_shell(
        'New Account Created',
        ($user['name'] ?? 'A new user') . ' verified their email and created an account.',
        $body,
        'Admin Notification'
    );

    return send_email($recipients, 'New MAEXX account: ' . ($user['name'] ?? 'user'), $html, $text, ['signup', 'admin-notification']);
}
