<?php
/**
 * PHP Built-in Server Router
 *
 * Enables static file serving when running via:
 *   php -S localhost:8000 router.php
 *
 * Without this, the PHP built-in server returns 404 for static assets
 * (images, CSS, JS) that reside in subdirectories like assets/uploads/logo/.
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Map request path to absolute file path
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
$filePath = $docRoot . $uri;

// Serve existing static files directly (CSS, JS, images, fonts, etc.)
if ($uri !== '/' && file_exists($filePath) && !is_dir($filePath)) {
    // Determine MIME type
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'eot'   => 'application/vnd.ms-fontobject',
        'pdf'   => 'application/pdf',
        'json'  => 'application/json',
        'xml'   => 'text/xml',
        'txt'   => 'text/plain',
        'html'  => 'text/html',
        'htm'   => 'text/html',
    ];

    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
    }

    // Cache headers for static assets
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'css', 'js'])) {
        header('Cache-Control: public, max-age=3600');
    }

    readfile($filePath);
    return true;
}

// Fall through to PHP — let the server handle .php files normally
return false;
