<?php
/**
 * v2 theme layer for standalone pages (pages with their own <head>, outside
 * app.php: payroll_calculations.php, dtr-documents.php, …).
 *
 * Usage, in the page's <head>:
 *     <?php $V2_STANDALONE = !empty($_SESSION['in_v2']); ?>
 *     <?php if ($V2_STANDALONE) include __DIR__ . '/includes/v2/standalone_head.php'; else { ?>
 *         …classic template CSS…
 *     <?php } ?>
 * then add the `v2` class to <body> when $V2_STANDALONE, and before </body>:
 *     <?php if ($V2_STANDALONE) include __DIR__ . '/includes/v2/standalone_foot.php'; ?>
 *
 * Loads the same theme engine and design system as app.php: the user's
 * theme, colour theme and accent follow them onto this page. Template-free:
 * official Bootstrap 5.3 + open-source icons.
 */
$__ui = $_SESSION['ui_settings'] ?? [];
?>
    <script>window.__UI_SERVER = <?= json_encode((object) $__ui, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="<?= av('assets3/js/color-themes.js') ?>"></script>
    <script src="<?= av('assets3/js/theme-engine.js') ?>"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Nunito:wght@400;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@7.4.47/css/materialdesignicons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css">
    <link rel="stylesheet" href="<?= av('assets2/css/theme.css') ?>">
    <link rel="stylesheet" href="<?= av('assets3/css/app.css') ?>">
    <link rel="stylesheet" href="<?= av('assets3/css/standalone.css') ?>">
