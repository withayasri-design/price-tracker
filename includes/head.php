<?php
/**
 * Common HTML Head Include
 *
 * Include this in all pages for consistent meta tags, favicon, and PWA support.
 *
 * Usage:
 *   <?php $pageTitle = 'Dashboard'; include __DIR__ . '/../includes/head.php'; ?>
 */

$pageTitle = $pageTitle ?? 'Price Tracker';
$pageDescription = $pageDescription ?? 'Multi-platform price tracking for Thai e-commerce websites';
$basePath = $basePath ?? '';
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
<meta name="theme-color" content="#0d6efd">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Price Tracker">

<title><?= htmlspecialchars($pageTitle) ?> - Price Tracker</title>

<!-- Favicon -->
<link rel="icon" type="image/svg+xml" href="<?= $basePath ?>/assets/img/icon.svg">
<link rel="apple-touch-icon" href="<?= $basePath ?>/assets/img/icon-192.png">

<!-- PWA Manifest -->
<link rel="manifest" href="<?= $basePath ?>/manifest.json">

<!-- Preconnect to CDNs -->
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>

<!-- Bootstrap CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">

<!-- Custom CSS -->
<link href="<?= $basePath ?>/assets/css/style.css" rel="stylesheet">
