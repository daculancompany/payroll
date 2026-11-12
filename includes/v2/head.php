<?php
/**
 * v2 <head>. Self-contained on purpose: no Velzon template CSS or JS. Base
 * styling is official Bootstrap 5.3 (MIT) and the icon fonts are open-source
 * (Remix Icon, MDI, Boxicons: Apache/MIT; Font Awesome 4.7: OFL/MIT). The look
 * itself is assets3/css/app.css.
 *
 * Expects: $V2_BASE, $V2_BRAND, $V2_SETTINGS, $page_title.
 */
?><!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <!-- Clean URLs (/app/<page>) are one level deep, so every relative asset
         path resolves from the app root. -->
    <base href="<?= e($V2_BASE) ?>">
    <title><?= e($page_title) ?> · <?= e($V2_BRAND) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <script src="assets2/js/csrf.js"></script>
    <?php include __DIR__ . '/../favicon.php'; ?>
    <link rel="manifest" href="manifest-admin.webmanifest">
    <meta name="theme-color" content="#0f172a">

    <!-- Theme before first paint: no flash of the wrong theme. -->
    <script>window.__UI_SERVER = <?= json_encode((object) $V2_SETTINGS, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="<?= av('assets3/js/color-themes.js') ?>"></script>
    <script src="<?= av('assets3/js/theme-engine.js') ?>"></script>
    <!-- Page content stays hidden until theme-adapter.js has recoloured it
         (a few ms), so it never flashes in the old colours. app.css reveals
         it after 1.5s regardless, in case a script fails. -->
    <script>document.documentElement.classList.add('v2-pending');</script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Nunito:wght@400;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@7.4.47/css/materialdesignicons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/simplebar@6.2.7/dist/simplebar.min.css">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.2.9/css/responsive.bootstrap.min.css">
    <link rel="stylesheet" href="assets2/vendor/toastr/toastr.min.css">

    <!-- The app's own shared styles (not template files). -->
    <link rel="stylesheet" href="<?= av('assets2/css/theme.css') ?>">
    <link rel="stylesheet" href="<?= av('assets2/css/reports.css') ?>">
    <link rel="stylesheet" href="assets2/css/modal-stacking.css">
    <link rel="stylesheet" href="<?= av('assets2/css/custom-select.css') ?>">
    <link rel="stylesheet" href="<?= av('assets2/css/app-tooltip.css') ?>">
    <script defer src="<?= av('assets2/js/app-tooltip.js') ?>"></script>

    <!-- v2 design system: loaded last so it wins. -->
    <link rel="stylesheet" href="<?= av('assets3/css/app.css') ?>">
</head>
