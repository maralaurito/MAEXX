<?php
/**
 * Rebuild the small logo that gets embedded in outgoing email.
 *
 *   php -d extension=gd regenerate_email_logo.php
 *
 * Run this after replacing img/LOGO.png. The -d flag loads GD just for
 * this command, so php.ini does not need editing.
 *
 * The email copy is kept small on purpose: it travels inside every
 * message, and Gmail clips messages over roughly 102KB — which would
 * hide the verification code behind a "View entire message" link.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool runs from the command line only.');
}

const SOURCE = __DIR__ . '/img/LOGO.png';
const TARGET = __DIR__ . '/img/logo-email.png';
const SIZE   = 144;   // 72px in the template, doubled for sharp rendering

if (!extension_loaded('gd')) {
    fwrite(STDERR, "GD is not loaded. Run it like this instead:\n");
    fwrite(STDERR, "  php -d extension=gd regenerate_email_logo.php\n");
    exit(1);
}

if (!is_readable(SOURCE)) {
    fwrite(STDERR, 'Cannot read ' . SOURCE . "\n");
    exit(1);
}

$source = @imagecreatefrompng(SOURCE);

if (!$source) {
    fwrite(STDERR, SOURCE . " is not a readable PNG.\n");
    exit(1);
}

$width  = imagesx($source);
$height = imagesy($source);

$target = imagecreatetruecolor(SIZE, SIZE);

// Preserve transparency rather than filling it black.
imagealphablending($target, false);
imagesavealpha($target, true);
imagefill($target, 0, 0, imagecolorallocatealpha($target, 255, 255, 255, 127));

imagecopyresampled($target, $source, 0, 0, 0, 0, SIZE, SIZE, $width, $height);
imagepng($target, TARGET, 9);

printf("source : %dx%d  %d KB\n", $width, $height, round(filesize(SOURCE) / 1024));
printf("email  : %dx%d  %d KB\n", SIZE, SIZE, round(filesize(TARGET) / 1024));
printf("embeds as ~%d KB of base64\n", round(filesize(TARGET) * 1.37 / 1024));
