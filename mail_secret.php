<?php
/**
 * Gmail App Password — the ONLY file you need to edit to send real email.
 *
 * ---------------------------------------------------------------
 * HOW TO GET ONE (about 2 minutes)
 * ---------------------------------------------------------------
 * 1. Sign in to the Google account that will send the mail
 *    (kashena123456@gmail.com).
 *
 * 2. Turn on 2-Step Verification if it is not already on:
 *    https://myaccount.google.com/signinoptions/two-step-verification
 *    App Passwords do not exist until this is enabled.
 *
 * 3. Create the App Password:
 *    https://myaccount.google.com/apppasswords
 *    Name it anything, e.g. "MAEXX". Google shows a 16-character
 *    code like: abcd efgh ijkl mnop
 *
 * 4. Paste it below, between the quotes. Spaces are fine — they are
 *    stripped automatically. This is NOT your normal Gmail password;
 *    that one will always be rejected.
 *
 * 5. In mail_config.php set:  define('MAIL_MODE', 'gmail');
 *
 * 6. Test it:   php mail_test.php your@email.com
 *
 * ---------------------------------------------------------------
 * Keep this file private. Do not submit or upload it with the rest
 * of the system — an App Password grants access to send mail as
 * this account. If it ever leaks, revoke it at the link in step 3.
 * ---------------------------------------------------------------
 */

return str_replace(' ', '', 'bjod mtgl lweh jukb');
