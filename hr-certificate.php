<?php
/**
 * Certificate of Employment, rendered as a PDF from the employee's record.
 *
 * Nothing is stored: each open renders from the 201 file as it stands, so a
 * corrected hire date or position fixes every future copy, and there is no
 * file on disk to leak.
 *
 *   hr-certificate.php?request_id=12
 *       An employee's released request. Opened by HR (any) or by the employee
 *       it belongs to (portal session), and only once HR has released it.
 *
 *   hr-certificate.php?employee_id=7&type=coe|coe_comp&purpose=…&sign_name=…&sign_title=…
 *       HR printing one directly (Document Requests → Print certificate).
 *       Staff only.
 *
 * The wording avoids pronouns on purpose: the record has no field for them,
 * and a certificate must not guess.
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/hr_class.php';

function hr_cert_stop($status, $message)
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}

$hr    = new HrManagement($conn);
$staff = !empty($_SESSION['is_login']) && $hr->canRead();
$types = HrManagement::requestTypes();

$request_id = (int) ($_GET['request_id'] ?? 0);
if ($request_id > 0) {
    $req = $hr->tableExists('hr_doc_requests') ? $hr->docRequestRow($request_id) : null;
    if (!$req) hr_cert_stop(404, 'No such request.');

    $self = !empty($_SESSION['emp_is_login']) && (int) ($_SESSION['emp_id'] ?? 0) === (int) $req['employee_id'];
    if (!$staff && !$self) hr_cert_stop(403, 'Not allowed.');
    if ($req['status'] !== 'released') hr_cert_stop(403, 'This certificate has not been released yet.');

    $employee_id = (int) $req['employee_id'];
    $type        = $req['request_type'];
    $purpose     = (string) $req['purpose'];
    $signName    = (string) $req['signatory_name'];
    $signTitle   = (string) $req['signatory_title'];
    $issuedOn    = $req['released_at'] ? strtotime($req['released_at']) : time();
} else {
    if (!$staff) hr_cert_stop(403, 'Not allowed.');
    $employee_id = (int) ($_GET['employee_id'] ?? 0);
    $type        = (string) ($_GET['type'] ?? 'coe');
    $purpose     = mb_substr(trim((string) ($_GET['purpose'] ?? '')), 0, 190);
    $signName    = mb_substr(trim((string) ($_GET['sign_name'] ?? '')), 0, 120);
    $signTitle   = mb_substr(trim((string) ($_GET['sign_title'] ?? '')), 0, 120);
    $issuedOn    = time();
}

if (!isset($types[$type])) hr_cert_stop(400, 'Unknown certificate type.');
$d = $hr->certificateData($employee_id);
if (!$d) hr_cert_stop(404, 'No such employee.');

$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$fmtDate = function ($ymd) { return $ymd ? date('F j, Y', strtotime($ymd)) : null; };

// "14th day of November 2026"
$dayN   = (int) date('j', $issuedOn);
$suffix = ($dayN % 100 >= 11 && $dayN % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$dayN % 10] ?? 'th');
$issuedText = $dayN . $suffix . ' day of ' . date('F Y', $issuedOn);

// The employment sentence, built only from what the record actually has.
$role = $d['position'] ? ' as <b>' . $h($d['position']) . '</b>' : '';
$dept = $d['department'] ? ' in the ' . $h($d['department']) . ' department' : '';
$hired = $fmtDate($d['date_hired']);
$left  = $fmtDate($d['date_separated']);
if ($left && !$d['active']) {
    $span  = $hired ? ' from <b>' . $h($hired) . '</b> to <b>' . $h($left) . '</b>' : ' until <b>' . $h($left) . '</b>';
    $verb  = 'was employed by';
} else {
    $span  = $hired ? ' since <b>' . $h($hired) . '</b> up to the present' : '';
    $verb  = $d['active'] ? 'is employed by' : 'was employed by';
}
$sentence = 'This is to certify that <b>' . $h(mb_strtoupper($d['name'])) . '</b>'
          . ($d['employee_no'] ? ' (Employee No. ' . $h($d['employee_no']) . ')' : '')
          . ' ' . $verb . ' <b>' . $h($d['company']) . '</b>' . $role . $dept . $span . '.';

$pay = '';
if ($type === 'coe_comp' && $d['monthly_pay']) {
    $pay = '<p>The basic monthly salary ' . ($d['active'] ? 'is' : 'was') . ' <b>PHP ' . number_format($d['monthly_pay'], 2) . '</b>.</p>';
}

$purposeText = $purpose !== ''
    ? 'This certification is issued upon the request of the above-named employee for ' . $h($purpose) . '.'
    : 'This certification is issued upon the request of the above-named employee for whatever legal purpose it may serve.';

$contact = array_filter([$d['company_email'], $d['company_contact']]);

$html = '<!doctype html><html><head><meta charset="utf-8"><style>
    @page { margin: 70px 72px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11.5pt; color: #222; line-height: 1.65; }
    .head { text-align: center; border-bottom: 2px solid #444; padding-bottom: 10px; margin-bottom: 36px; }
    .head .co { font-size: 17pt; font-weight: bold; letter-spacing: .5px; }
    .head .ct { font-size: 9pt; color: #666; margin-top: 2px; }
    h1 { text-align: center; font-size: 15pt; letter-spacing: 3px; margin: 0 0 34px; }
    p { text-align: justify; margin: 0 0 16px; text-indent: 36px; }
    .sign { margin-top: 70px; width: 280px; margin-left: auto; text-align: center; }
    .sign .line { border-top: 1px solid #222; padding-top: 4px; font-weight: bold; }
    .sign .title { font-size: 10pt; color: #444; }
    .foot { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 8pt; color: #999; text-align: center; }
</style></head><body>
    <div class="head"><div class="co">' . $h($d['company']) . '</div>'
    . ($contact ? '<div class="ct">' . $h(implode('  ·  ', $contact)) . '</div>' : '') . '</div>
    <h1>' . $h(mb_strtoupper($types[$type])) . '</h1>
    <p>' . $sentence . '</p>' . $pay . '
    <p>' . $purposeText . '</p>
    <p>Issued this ' . $h($issuedText) . '.</p>
    <div class="sign">'
    . ($signName !== '' ? '<div class="line">' . $h($signName) . '</div>' : '<div class="line">&nbsp;</div>')
    . ($signTitle !== '' ? '<div class="title">' . $h($signTitle) . '</div>' : '<div class="title">Authorized signatory</div>') . '
    </div>
    <div class="foot">Generated ' . $h(date('M j, Y g:i A')) . ($request_id ? ' · Request #' . $request_id : '') . '</div>
</body></html>';

require __DIR__ . '/vendor/autoload.php';

// dompdf writes a font subset to a temp folder while it renders. On XAMPP the
// web-server user often cannot write PHP's default temp dir, and dompdf then
// dies inside FontLib with "Font not found" — the same fix as
// export-leave-balances.php: hand it the first folder that is writable.
$tmpDir = null;
foreach ([__DIR__ . '/tmp', sys_get_temp_dir(), ini_get('upload_tmp_dir'), session_save_path()] as $cand) {
    if ($cand && is_dir($cand) && is_writable($cand)) { $tmpDir = $cand; break; }
}

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
if ($tmpDir) $options->set('tempDir', $tmpDir);
$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$file = 'COE-' . preg_replace('/[^A-Za-z0-9]+/', '-', $d['name']) . '.pdf';
header('Cache-Control: private, no-store');
$dompdf->stream($file, ['Attachment' => !empty($_GET['download']) ? 1 : 0]);
