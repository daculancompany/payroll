<?php
/* TEMPORARY — i-upload sa root, ablihi ang domain.com/debug.php, DELETE dayon.
   Gipagawas ang error nga gitago sa 500 page. Molihok bisan sa parse error
   sa index.php, kay na-on na ang display_errors sa wala pa ang require. */
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@ini_set('html_errors', '0');
error_reporting(E_ALL);

// Kung fatal, ipakita gihapon sa katapusan imbis puti nga panid.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/plain; charset=utf-8');
        echo "=== FATAL ===\n";
        echo $e['message'] . "\n\n";
        echo "File: " . $e['file'] . "\n";
        echo "Line: " . $e['line'] . "\n";
    }
});

$page = isset($_GET['f']) ? basename($_GET['f']) : 'index.php';
echo "<pre>PHP " . PHP_VERSION . " — gisulayan: $page</pre>";
require __DIR__ . '/' . $page;
