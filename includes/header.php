<?php
/**
 * Page header. Set before including:
 *   $pageTitle  - page title
 *   $activeNav  - sidebar key (dashboard, income, ...)
 *   $authPage   - true for login/register (no sidebar)
 *   $needCharts - true to load Chart.js
 */
require_once __DIR__ . '/functions.php';
$authPage   = $authPage ?? false;
$pageTitle  = $pageTitle ?? APP_NAME;
$activeNav  = $activeNav ?? '';
$needCharts = $needCharts ?? false;
$user       = current_user();
$unread     = $authPage ? 0 : unread_count(uid());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="<?= $authPage ? 'auth-body' : 'app-body' ?>">
<?php if (!$authPage): ?>
    <?php require __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
    <div class="main">
        <header class="topbar">
            <button class="icon-btn menu-toggle" id="menuToggle" type="button" aria-label="Open menu"
                    aria-controls="sidebar" aria-expanded="false">
                <span class="menu-icon menu-icon-open"><?= icon('menu', 22) ?></span>
                <span class="menu-icon menu-icon-close"><?= icon('x', 22) ?></span>
            </button>
            <h1 class="topbar-title"><?= e($pageTitle) ?></h1>
            <div class="topbar-right">
                <a class="icon-btn bell" href="<?= e(url('pages/notifications.php')) ?>" aria-label="Notifications">
                    <?= icon('bell', 20) ?>
                    <?php if ($unread > 0): ?>
                        <span class="bell-badge"><?= $unread > 99 ? '99+' : $unread ?></span>
                    <?php endif; ?>
                </a>
                <a class="user-chip" href="<?= e(url('pages/settings.php')) ?>">
                    <?= avatar_html($user, 'avatar-sm') ?>
                    <span class="user-chip-name"><?= e($user['full_name']) ?></span>
                </a>
            </div>
        </header>
        <main class="content">
<?php endif; ?>
<div class="toasts" id="toasts">
    <?php foreach (flash_pull() as $f): ?>
        <div class="toast toast-<?= e($f['type']) ?>" role="status">
            <span><?= e($f['message']) ?></span>
            <button type="button" class="toast-close" aria-label="Dismiss"><?= icon('x', 14) ?></button>
        </div>
    <?php endforeach; ?>
</div>
