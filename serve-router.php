<?php

/**
 * Router script for PHP's built-in web server.
 *
 * The container serves the app with
 * `php -S 0.0.0.0:8000 -t public /app/serve-router.php`, i.e. the same mechanism
 * as `php artisan serve`, but through a router we own so the security guard below
 * cannot be lost on a `composer install` (which reinstalls the framework's
 * vendored router).
 *
 * SECURITY, CVE-2026-38526 (and the Krayin upload CVEs in general):
 * PHP built-in server EXECUTES any *.php file that exists on disk. User uploads
 * are served from /storage (a symlink to storage/app/public), so a malicious
 * upload written there would run as a web shell. Refuse to serve any PHP-family
 * script under /storage: serving an uploaded image is fine, running code from the
 * upload directory is not. Upload endpoints also validate content; this is
 * defence in depth.
 */
$uri = urldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

if (preg_match('#^/storage/.*\.(?:php|phtml|phar|php\d|pht|phps)(?:$|/)#i', $uri)) {
    http_response_code(403);

    header('Content-Type: text/plain; charset=UTF-8');

    echo 'Forbidden';

    return true;
}

$publicPath = __DIR__.'/public';

/*
 * Emulate Apache mod_rewrite: serve an existing static file as-is, otherwise hand
 * the request to Laravel front controller.
 */
if ($uri !== '/' && is_file($publicPath.$uri)) {
    return false;
}

require_once $publicPath.'/index.php';