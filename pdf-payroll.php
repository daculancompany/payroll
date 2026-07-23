<?php
// Renders the payroll print pages as a PDF via dompdf (landscape, tight margins)
// and streams it inline so it can be previewed inside a modal iframe.
require __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$sources = [
    'payroll'  => 'print-payroll.php',
    'monthly'  => 'print-montly.php',
    'employer' => 'print-payroll-employer.php',
    'dept'     => 'print-payroll-dept.php',
    'payslip'  => 'view_payslip.php',   // individual payslip — portrait A4
];

$src = isset($_GET['src']) ? $_GET['src'] : 'payroll';
if (!isset($sources[$src]) || !isset($_GET['id'])) {
    http_response_code(400);
    exit('Invalid request');
}

// The single-employee payslip prints portrait on A4, unlike the wide landscape sheets.
$isPayslip = ($src === 'payslip');

define('PDF_MODE', true);

// Payslip: render its embeddable body without the on-screen toolbar / auto-print.
if ($isPayslip) { $_GET['preview'] = 1; }

ob_start();
include __DIR__ . '/' . $sources[$src];
$html = ob_get_clean();

// Drop scripts (window.print, CDN libs) — the PDF replaces the browser print flow.
$html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
$html = preg_replace('#<script\b[^>]*/?>#is', '', $html);

// Drop linked stylesheets — every print page inlines its styles; the only real
// link is the payslip's icons.min.css (607KB icon-font CSS) whose @font-face
// made dompdf parse a huge icon TTF: ~20s per payslip. Icons are toolbar-only
// and the toolbar is hidden in PDF mode.
$html = preg_replace('#<link\b[^>]*rel=["\']stylesheet["\'][^>]*>#is', '', $html);

// Screen-only column stretchers: min-widths and the wide signature column
// force the dense sheets past the page edge in dompdf.
$html = preg_replace('/min-width:\s*\d+px;?/i', '', $html);
$html = str_replace('width: 130px', 'width: 70px', $html);

// The payslip is portrait A4; the wide payroll sheets print on long bond (legal) landscape.
$orientation = $isPayslip ? 'portrait' : 'landscape';
if ($isPayslip) {
    $paper = 'a4';
} else {
    $paper = isset($_GET['paper']) ? strtolower($_GET['paper']) : 'legal';
    if (!in_array($paper, ['a4', 'letter', 'legal', 'folio'])) {
        $paper = 'legal';
    }
}

// Compact type per sheet so every column fits the page width.
$density = [
    'payroll'  => 'table { font-size: 7px !important; } th, td { padding: 2px !important; }',
    'monthly'  => 'table { font-size: 6.5px !important; } th, td { padding: 2px 1px !important; } th { width: auto !important; }',
    'employer' => 'table { font-size: 7px !important; } th, td { padding: 2px !important; }',
    'dept'     => '',
    'payslip'  => '',
];

if ($isPayslip) {
    // The payslip carries its own portrait A4 layout — only strip the on-screen
    // frame (the wrapper is centered with a shadow) so it fills the page cleanly.
    $override = <<<CSS
<style>
    @page { margin: 8mm 10mm; }
    html, body { background: #fff !important; margin: 0; padding: 0; }
    .ps-wrap { width: 100% !important; margin: 0 !important; box-shadow: none !important; }
</style>
CSS;
} else {
    // Small page padding + full-width layout so the landscape sheet is fully utilized.
    $override = <<<CSS
<style>
    @page { margin: 16px 14px; }
    html, body { visibility: visible !important; background: #fff !important; margin: 0; padding: 0; }
    .wrapper { max-width: 100% !important; margin: 0 !important; box-shadow: none !important; }
    .container-fluid, .main-content, .page-content { margin: 0 !important; padding: 0 !important; }
    table { width: 100% !important; }
    .no-print, .no-print * { display: none !important; }
    {$density[$src]}
</style>
CSS;
}

if (stripos($html, '</head>') !== false) {
    $html = str_ireplace('</head>', $override . "\n</head>", $html);
} else {
    $html = $override . $html;
}

// Core PDF fonts (Helvetica) have no ₱ (peso sign) glyph — it would print as
// "?". Replace it with a plain "P" in the PDF output only; the on-screen HTML
// pages keep the real ₱ symbol.
$html = str_replace(['₱', '&#8369;', '&#x20B1;'], 'P', $html);

// Writable font-cache/temp dir for dompdf. The default font cache is the
// bundled vendor/dompdf/dompdf/lib/fonts dir, which the Apache user (daemon)
// cannot write to — font subsetting then fails with "fopen(): Filename cannot
// be empty" warnings inside php-font-lib. Keep it out of vendor/ so composer
// updates don't wipe it.
$dompdfCache = __DIR__ . '/logs/dompdf-cache';
if (!is_dir($dompdfCache)) { @mkdir($dompdfCache, 0777, true); @chmod($dompdfCache, 0777); }
$cacheOk = is_dir($dompdfCache) && is_writable($dompdfCache);

$filename = 'payroll-' . $src . '-' . (int)$_GET['id'] . '.pdf';

// ── PDF result cache ────────────────────────────────────────────────────
// dompdf's layout pass is the slow step (seconds for the wide sheets); the
// HTML render above is milliseconds. Hash the HTML — minus volatile
// "Generated:"/"Date Received" timestamps — and stream a cached PDF when
// nothing changed. Any edit to the payroll changes the HTML → new key →
// regenerate. Stale keys for the same document are pruned on write.
$cacheFile = null;
$cachePrefix = null;
if ($cacheOk) {
    $hashSrc  = preg_replace(['/Generated:[^<]*/', '/<div class="date-val">[^<]*<\/div>/'], '', $html);
    $cacheKey = md5($hashSrc . '|' . $paper . '|' . $orientation);
    $cachePrefix = $dompdfCache . '/' . $src . '-' . (int)$_GET['id'] . '-';
    $cacheFile = $cachePrefix . $cacheKey . '.pdf';
    if (is_file($cacheFile)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($cacheFile));
        readfile($cacheFile);
        exit;
    }
}

$options = new Options();
$options->setDefaultMediaType('print');   // apply the pages' @media print rules
$options->setIsRemoteEnabled(false);
$options->setChroot(__DIR__);
if ($cacheOk) {
    $options->setFontCache($dompdfCache);
    $options->setTempDir($dompdfCache);
}
$options->setDefaultFont('Helvetica');

$dompdf = new Dompdf($options);
$dompdf->setProtocol('');
$dompdf->setBasePath(__DIR__ . '/');      // resolve relative image paths (logo)
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper($paper, $orientation);
$dompdf->render();

$pdfOut = $dompdf->output();
if ($cacheFile !== null) {
    // Drop superseded versions of this document, then store the fresh one.
    foreach (glob($cachePrefix . '*.pdf') ?: [] as $old) { @unlink($old); }
    @file_put_contents($cacheFile, $pdfOut);
}

header('Content-Type: application/pdf');
header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdfOut));
echo $pdfOut;
