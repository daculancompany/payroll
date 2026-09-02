<?php
// Temporary diagnostic — DELETE after use.
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

echo "PHP version : " . PHP_VERSION . "\n";
echo "PhpSpreadsheet needs 7.4+ : " . (version_compare(PHP_VERSION,'7.4','>=') ? "OK" : "*** TOO OLD ***") . "\n";
echo "memory_limit: " . ini_get('memory_limit') . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n\n";

foreach (['db_connect.php','admin_class.php','dtr-employee-server.php','dtr-documents.php','export-dtr-documents.php'] as $f) {
    if (!file_exists($f)) { printf("%-26s MISSING\n", $f); continue; }
    $out = []; $rc = 0;
    @exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $lint = $out ? implode(' ', $out) : '(php CLI not available)';
    printf("%-26s %8d bytes  %s\n", $f, filesize($f), $rc === 0 ? 'syntax OK' : "*** $lint ***");
}

echo "\n--- trying to load the core files ---\n";
try { require_once 'db_connect.php'; echo "db_connect.php loaded OK\n"; }
catch (Throwable $e) { echo "db_connect.php FAILED: " . $e->getMessage() . "\n"; }
try { require_once 'admin_class.php'; echo "admin_class.php loaded OK\n"; }
catch (Throwable $e) { echo "admin_class.php FAILED: " . $e->getMessage() . "\n"; }
try { require_once 'vendor/autoload.php';
      echo "vendor/autoload OK, PhpSpreadsheet " . (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet') ? "present" : "*** MISSING ***") . "\n"; }
catch (Throwable $e) { echo "vendor FAILED: " . $e->getMessage() . "\n"; }

echo "\n--- last 25 lines of any error log here ---\n";
foreach (['error_log','php_errorlog','../error_log'] as $lg)
    if (file_exists($lg)) { echo "== $lg ==\n"; echo implode('', array_slice(file($lg), -25)); }
