<?php
/**
 * The only way an HR upload reaches a browser.
 *
 * Photos and 201 documents are photographs and papers about identifiable
 * staff. They are stored under storage/hr/, which the web server refuses to
 * serve, so there is no address to guess: a request comes here, this file
 * answers "who is asking, and may they see THIS employee's file", and only
 * then streams the bytes.
 *
 *   hr-file.php?kind=photo&employee_id=12[&v=…]
 *   hr-file.php?kind=doc&id=34[&download=1]          (201 documents)
 *
 * `v` is a cache-buster the callers append; it is ignored here.
 *
 * Who may read:
 *   • Admin and HR — any employee.
 *   • The employee — their own, from the portal session. For documents, only
 *     the ones HR left visible to them, plus anything they uploaded.
 * Anyone else gets 403, and a missing file gets 404. Never a redirect to a
 * public URL: that would hand out an unguarded address and make the check
 * above pointless.
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/hr_class.php';

function hr_file_stop($status, $message)
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}

$kind        = (string) ($_GET['kind'] ?? 'photo');
$employee_id = (int) ($_GET['employee_id'] ?? 0);

$hr = new HrManagement($conn);

// ── 201 documents ─────────────────────────────────────────────────────────
// The employee id comes from the DOCUMENT row, never the request, so a forged
// employee_id cannot pair someone's session with someone else's file.
if ($kind === 'doc') {
    $doc = $hr->tableExists('hr_documents') ? $hr->documentRow((int) ($_GET['id'] ?? 0)) : null;
    if (!$doc) hr_file_stop(404, 'No such document.');

    $owner = (int) $doc['employee_id'];
    $staff = !empty($_SESSION['is_login']) && $hr->canRead();
    $self  = !empty($_SESSION['emp_is_login']) && (int) ($_SESSION['emp_id'] ?? 0) === $owner
          && ((int) $doc['visible_to_employee'] === 1 || $doc['source'] === 'employee');
    if (!$staff && !$self) hr_file_stop(403, 'Not allowed.');

    $path = $hr->documentPath($doc['file_name']);
    if (!$path || !is_file($path)) hr_file_stop(404, 'The file is missing on the server.');

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
    if (!isset(HrManagement::docMimes()[$mime])) hr_file_stop(404, 'The file is missing on the server.');

    $ext  = HrManagement::docMimes()[$mime];
    $base = preg_replace('/[^A-Za-z0-9 ._-]+/', '', (string) $doc['title']) ?: 'document';
    $disp = !empty($_GET['download']) ? 'attachment' : 'inline';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . $disp . '; filename="' . substr($base, 0, 80) . '.' . $ext . '"');
    header('Cache-Control: private, no-store');
    // Only sniffed PDF/JPEG/PNG/WebP reach this line (docMimes), and nosniff
    // stops the browser second-guessing that. No CSP `sandbox`: Chrome refuses
    // to render a PDF inside a sandboxed document.
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if ($employee_id <= 0) hr_file_stop(400, 'Missing employee.');

// ── May the caller see this employee's files? ─────────────────────────────
$staff = !empty($_SESSION['is_login']) && $hr->canRead();
$self  = !empty($_SESSION['emp_is_login']) && (int) ($_SESSION['emp_id'] ?? 0) === $employee_id;

if (!$staff && !$self) hr_file_stop(403, 'Not allowed.');

if ($kind !== 'photo') hr_file_stop(400, 'Unknown file kind.');

$row  = $hr->profileRow($employee_id);
$name = $row['photo'] ?? null;
if (!$name) hr_file_stop(404, 'No photo.');

// photoPath() re-checks the generated-name pattern and that the resolved
// path is really inside the photo folder, so a doctored column cannot point
// this at a file elsewhere on the disk.
$path = $hr->photoPath($name);

// The column can outlive the file (a restored database, a half-finished
// copy). That is "absent", not a server error.
if (!$path || !is_file($path)) hr_file_stop(404, 'No photo.');

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
if (!in_array($mime, array_keys(HrManagement::PHOTO_MIME), true)) {
    // Whatever is on disk is not an image we wrote. Do not serve it.
    hr_file_stop(404, 'No photo.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="employee-' . $employee_id . '"');
// `private`: one person's photograph must not sit in a shared proxy cache.
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

readfile($path);
