<?php
/**
 * MAEXX — Mail configuration (plain SMTP, no external service)
 * ---------------------------------------------------------------
 * Mail is sent by mailer.php over a direct SMTP connection, so XAMPP
 * is all that is needed — no Composer, no API keys, no library.
 *
 * Pick ONE of these setups:
 *
 * A) Local mail catcher — recommended for a localhost-only system.
 *    Install Mailpit (or Papercut / MailHog), run it, and use:
 *        SMTP_HOST = 'localhost'   SMTP_PORT = 1025
 *        SMTP_USERNAME / SMTP_PASSWORD = ''   SMTP_ENCRYPTION = ''
 *    Every message is captured and viewable at http://localhost:8025.
 *    Nothing leaves the machine and no password is stored anywhere.
 *
 * B) Gmail relay — when the codes must reach a real inbox.
 *        SMTP_HOST = 'smtp.gmail.com'   SMTP_PORT = 587
 *        SMTP_ENCRYPTION = 'tls'
 *        SMTP_USERNAME = your full Gmail address
 *        SMTP_PASSWORD = a Google App Password (16 characters)
 *    A normal Gmail password will not work: turn on 2-Step Verification,
 *    then create an App Password at myaccount.google.com/apppasswords.
 *    Set MAIL_SENDER_EMAIL to that same Gmail address.
 *
 * Leaving SMTP_HOST blank disables sending; with MAIL_DEV_FALLBACK on,
 * codes are shown on screen instead so the system stays usable.
 */

// ---------------------------------------------------------------
// SMTP server — flip MAIL_MODE to switch between the two setups
// ---------------------------------------------------------------

/**
 * 'mailpit' — codes are caught locally and read at http://localhost:8025.
 *             Works offline, needs no password, and the logo displays.
 *             Nothing ever reaches a real inbox.
 *
 * 'gmail'   — codes are relayed through Gmail and arrive in the real
 *             inbox. Needs GMAIL_ADDRESS below plus an App Password in
 *             mail_secret.php. Requires internet; the logo will not
 *             display, because Google cannot load images from localhost.
 */
define('MAIL_MODE', 'gmail');

/** The Gmail account used to send when MAIL_MODE is 'gmail'. */
define('GMAIL_ADDRESS', 'kashena123456@gmail.com');

/**
 * The 16-character Google App Password.
 *
 * Kept in mail_secret.php rather than here, so this file can be copied,
 * submitted or shared without handing over the credential. An
 * SMTP_PASSWORD environment variable overrides the file.
 */
define('GMAIL_APP_PASSWORD', (function () {
    $fromEnv = getenv('SMTP_PASSWORD');
    if ($fromEnv) {
        return $fromEnv;
    }

    $secret = __DIR__ . '/mail_secret.php';
    return file_exists($secret) ? trim((string) require $secret) : '';
})());

if (MAIL_MODE === 'gmail') {

    define('SMTP_HOST',       'smtp.gmail.com');
    define('SMTP_PORT',       587);
    define('SMTP_ENCRYPTION', 'tls');
    define('SMTP_USERNAME',   GMAIL_ADDRESS);
    define('SMTP_PASSWORD',   GMAIL_APP_PASSWORD);

    // Gmail rewrites the sender to the authenticated account anyway,
    // so it must match, or the message is rejected.
    define('MAIL_SENDER_EMAIL', GMAIL_ADDRESS);

} else {

    define('SMTP_HOST',       '127.0.0.1');
    define('SMTP_PORT',       1025);
    define('SMTP_ENCRYPTION', '');
    define('SMTP_USERNAME',   '');
    define('SMTP_PASSWORD',   '');

    define('MAIL_SENDER_EMAIL', 'no-reply@maexx2.local');
}

define('MAIL_SENDER_NAME', 'MAEXX 2 Enterprises Inc.');

/** Seconds to wait for the server. */
define('SMTP_TIMEOUT', 15);

/**
 * Skip certificate checks on the TLS handshake. XAMPP on Windows often
 * ships without a CA bundle, which makes Gmail's handshake fail. Only
 * acceptable because this system runs on localhost.
 */
define('SMTP_ALLOW_SELF_SIGNED', true);

/** Replies from staff go here (leave blank to disable Reply-To). */
define('MAIL_REPLY_TO_EMAIL', '');
define('MAIL_REPLY_TO_NAME',  'MAEXX Support');

// ---------------------------------------------------------------
// Routing
// ---------------------------------------------------------------

/**
 * Extra administrator recipients for password-reset notifications.
 * Every active user with the "Administrator" role in users.json is
 * notified automatically; list additional mailboxes here.
 */
const MAIL_ADMIN_RECIPIENTS = [
    // 'itsupport@maexx2.com',
];

// ---------------------------------------------------------------
// Branding
// ---------------------------------------------------------------

define('APP_NAME',    'MAEXX 2 ENTERPRISES INC.');
define('APP_TAGLINE', 'Inventory & Sales Monitoring System');

/**
 * Logo shown in the email header.
 *
 * Blank means the template points at this installation's own
 * img/LOGO.png (http://localhost/MAEXX/img/LOGO.png). A local mail
 * catcher opens messages in a browser on this same machine, so the
 * logo displays normally there.
 *
 * It will NOT display if mail is relayed out to a real Gmail inbox,
 * because localhost means nothing to Google's servers. Only relevant
 * if you ever relay externally; paste a public image URL here then.
 */
define('MAIL_LOGO_URL', '');

/**
 * Logo file used when MAIL_LOGO_URL is blank.
 *
 * A 144px copy of img/LOGO.png, small enough to travel inside every
 * message without pushing it past Gmail's ~102KB clipping threshold —
 * a clipped message would hide the verification code behind a
 * "View entire message" link. Regenerate it after changing the logo:
 *
 *   php -d extension=gd regenerate_email_logo.php
 */
define('APP_LOGO_PATH', 'img/logo-email.png');

/** Content-ID used when the logo travels inside the message. */
define('MAIL_LOGO_CID', 'maexxlogo');

/** Brand palette — mirrors styles.css. */
define('BRAND_NAVY',   '#243f5f');
define('BRAND_DARK',   '#1b2f47');
define('BRAND_BG',     '#f1f5f9');
define('BRAND_TEXT',   '#334155');
define('BRAND_MUTED',  '#64748b');
define('BRAND_BORDER', '#e2e8f0');

// ---------------------------------------------------------------
// Behaviour
// ---------------------------------------------------------------

/** Minutes a password-reset OTP stays valid. */
define('OTP_LIFETIME_MINUTES', 10);

/** Seconds a user must wait before requesting another OTP. */
define('OTP_RESEND_COOLDOWN', 60);

/** Wrong OTP attempts allowed before the code is invalidated. */
define('OTP_MAX_ATTEMPTS', 5);

// ---------------------------------------------------------------
// Login protection
// ---------------------------------------------------------------

/** Failed sign-in attempts allowed before an account is locked. */
define('LOGIN_MAX_ATTEMPTS', 5);

/** Minutes a locked account stays locked. */
define('LOGIN_LOCKOUT_MINUTES', 15);

/** Failed attempts are forgotten after this many minutes of quiet. */
define('LOGIN_ATTEMPT_WINDOW_MINUTES', 15);

/** Warn the user once only this many attempts remain. */
define('LOGIN_WARN_THRESHOLD', 3);

/**
 * Fallback for when no SMTP server is configured or the send fails:
 * the OTP is written to mail_debug.log and shown on screen so the
 * system stays usable. Leave this on for a localhost-only install —
 * without it, an unreachable mail server makes login recovery and
 * signup impossible.
 */
define('MAIL_DEV_FALLBACK', true);

/** Log every send attempt and SMTP error to mail_debug.log. */
define('MAIL_DEBUG_LOG', __DIR__ . '/mail_debug.log');

/**
 * Is a usable mail server configured?
 */
function mail_is_configured(): bool
{
    if (SMTP_HOST === '' || MAIL_SENDER_EMAIL === '') {
        return false;
    }

    // A server that wants a login is useless without the password.
    if (SMTP_USERNAME !== '' && SMTP_PASSWORD === '') {
        return false;
    }

    return true;
}

/**
 * One-line description of where mail is going, for the test script.
 */
function mail_mode_summary(): string
{
    if (!mail_is_configured()) {
        return MAIL_MODE === 'gmail'
            ? 'Gmail relay selected, but no App Password found in mail_secret.php.'
            : 'No mail server configured.';
    }

    return MAIL_MODE === 'gmail'
        ? 'Relaying through Gmail as ' . GMAIL_ADDRESS . ' — mail reaches real inboxes.'
        : 'Delivering to Mailpit on ' . SMTP_HOST . ':' . SMTP_PORT . ' — read it at http://localhost:8025';
}

/**
 * Local path to the logo file, or null when it is missing.
 */
function mail_logo_file(): ?string
{
    $path = __DIR__ . '/' . ltrim(APP_LOGO_PATH, '/');

    return is_readable($path) ? $path : null;
}

/**
 * What the <img src> in the email header points at.
 *
 * Preference order:
 *   1. An explicit public URL, if one was configured.
 *   2. "cid:..." — the logo file travels inside the message itself.
 *      This is what makes it display in Gmail, which cannot fetch
 *      anything from localhost.
 *   3. This installation's own URL, as a last resort.
 */
function mail_logo_src(): string
{
    if (MAIL_LOGO_URL !== '') {
        return MAIL_LOGO_URL;
    }

    if (mail_logo_file() !== null) {
        return 'cid:' . MAIL_LOGO_CID;
    }

    return app_base_url() . '/' . ltrim(APP_LOGO_PATH, '/');
}

/**
 * Base URL of this installation, used for links inside emails.
 */
function app_base_url(): string
{
    // Run from the command line there is no request to read, and
    // dirname() would yield "." — producing links like
    // "http://localhost./login.php". Fall back to the folder name.
    if (PHP_SAPI === 'cli') {
        return 'http://localhost/' . basename(__DIR__);
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

    // dirname() returns "." for a script at the document root.
    if ($dir === '.' || $dir === '\\') {
        $dir = '';
    }

    return "{$scheme}://{$host}{$dir}";
}
