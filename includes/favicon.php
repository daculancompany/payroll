<?php
/**
 * App favicon — the COMC seal, the same mark the app bar and the PWA icons
 * use. One include so the routed pages (includes/header.php) and every
 * standalone page that renders its own <head> (duty-roster, dtr-documents,
 * payroll_calculations, the print/payslip pages…) show the same tab icon.
 *
 * ?v= busts the browser's aggressive favicon cache when the mark changes.
 */
?>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32.png?v=2">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/images/favicon-192.png?v=2">
    <link rel="shortcut icon" href="assets/images/favicon.ico?v=2">
    <link rel="apple-touch-icon" href="assets2/images/pwa/apple-touch-icon.png">
