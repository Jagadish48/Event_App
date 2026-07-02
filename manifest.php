<?php
/**
 * manifest.php — Dynamic PWA manifest
 * Generates correct paths regardless of whether the app is running on:
 *   - localhost:8000 (PHP built-in server, root = Backup_Files/)
 *   - localhost/Backup_Files/ (Apache/XAMPP)
 *   - any other host/path
 */
require_once __DIR__ . '/config/database.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

// SITE_URL is already computed dynamically in database.php (e.g. http://localhost:8000/ or http://localhost/Backup_Files/)
$base = rtrim(SITE_URL, '/');

$appName = getAppSetting('app_name', 'Network Events');
if (trim($appName) === '') $appName = 'Network Events';

$manifest = [
    'name'             => $appName,
    'short_name'       => 'Events',
    'description'      => 'Event and Employee Management System',
    'theme_color'      => '#0F172A',
    'background_color' => '#0F172A',
    'display'          => 'standalone',
    'orientation'      => 'portrait-primary',
    'scope'            => $base . '/',
    'start_url'        => $base . '/index.php?source=pwa',
    'icons'            => [
        [
            'src'     => $base . '/assets/icons/icon-192.png',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any'
        ],
        [
            'src'     => $base . '/assets/icons/icon-512.png',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any maskable'
        ]
    ],
    'screenshots' => [],
    'categories'  => ['business', 'productivity'],
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
