<?php
// Document Requests — Certificates of Employment the employees asked for in
// the portal, plus printing one directly for anyone. Included via index.php /
// app.php (?page=hr-requests). Admin + HR. The PDF itself is hr-certificate.php.

require_once __DIR__ . '/hr_class.php';

$hrq      = new HrManagement($conn);
$hrq_has  = $hrq->tableExists('hr_doc_requests');
$hrq_can  = can_edit('employee-201');
$hrq_tab  = in_array($_GET['status'] ?? '', ['pending', 'released', 'declined', 'cancelled', 'all'], true) ? $_GET['status'] : 'pending';
$hrq_rows = $hrq->docRequests($hrq_tab === 'all' ? '' : $hrq_tab);
$hrq_types = HrManagement::requestTypes();

$hrq_count = ['pending' => 0];
if ($hrq_has) {
    $q = $conn->query("SELECT status, COUNT(*) AS n FROM hr_doc_requests GROUP BY status");
    while ($q && ($r = $q->fetch_assoc())) $hrq_count[$r['status']] = (int) $r['n'];
}

$hrq_emps = [];
$q = $conn->query("SELECT id, TRIM(CONCAT(lastname, ', ', firstname)) AS name FROM employee WHERE status = 1 ORDER BY lastname, firstname");
while ($q && ($r = $q->fetch_assoc())) $hrq_emps[] = ['value' => (int) $r['id'], 'label' => $r['name']];

if (!function_exists('hrq_e')) {
    function hrq_e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
?>
<style>
.hrq-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;}
.hrq-tabs a{padding:5px 12px;border-radius:8px;font-size:12.5px;font-weight:700;text-decoration:none;color:var(--v2-text-2,#6f6a80);border:1px solid var(--v2-border,#e2deeb);background:var(--v2-surface,#fff);}
.hrq-tabs a.on{background:var(--app-primary,#673bb6);border-color:var(--app-primary,#673bb6);color:#fff;}
.hrq-tabs .n{display:inline-block;margin-left:6px;font-size:10.5px;padding:0 6px;border-radius:8px;background:rgba(0,0,0,.08);}
.hrq-tabs a.on .n{background:rgba(255,255,255,.25);}
.hrq-chip{display:inline-block;font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:9px;text-transform:uppercase;letter-spacing:.3px;}
.hrq-chip.pending{background:rgba(230,168,23,.16);color:#9a6700;}
.hrq-chip.released{background:rgba(16,185,129,.14);color:#0f9d58;}
.hrq-chip.declined{background:rgba(198,40,40,.13);color:#c62828;}
.hrq-chip.cancelled{background:var(--v2-surface-3,#efedf3);color:var(--v2-text-2,#666);}
.hrq-sub{font-size:11px;color:var(--v2-text-3,#918d9c);}
.hrq-empty{font-size:12.5px;color:var(--v2-text-2,#908c9c);padding:16px;}
</style>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <div class="row mb-3"><div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
            <h4 class="mb-sm-0"><i class="ri-file-paper-2-line me-2" style="color:var(--app-primary,#673bb6);"></i>Document Requests</h4>
            <ol class="breadcrumb m-0"><li class="breadcrumb-item">HR</li><li class="breadcrumb-item active">Document Requests</li></ol>
        </div>
    </div></div>

    <?php if (!$hrq_has): ?>
    <div class="alert alert-warning"><i class="ri-error-warning-line me-1"></i>The HR tables are missing. Run <code>migrations/2026_11_hr_modules.sql</code> on this database.</div>
    <?php else: ?>

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
        <div class="hrq-tabs">
            <?php foreach (['pending' => 'Pending', 'released' => 'Released', 'declined' => 'Declined', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $label): ?>
            <a href="index.php?page=hr-requests&amp;status=<?= $k ?>" class="<?= $hrq_tab === $k ? 'on' : '' ?>"><?= $label ?><?php if ($k !== 'all' && !empty($hrq_count[$k])): ?><span class="n"><?= $hrq_count[$k] ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </div>
        <?php if ($hrq_can): ?>
        <button class="btn btn-outline-primary btn-sm" onclick="hrqPrint()"><i class="ri-printer-line me-1"></i>Print a certificate</button>
        <?php endif; ?>
    </div>

    <div class="card rpt-card">
        <div class="card-body p-0">
        <?php if (!$hrq_rows): ?>
            <div class="hrq-empty"><i class="ri-information-line me-1"></i>No <?= $hrq_tab === 'all' ? '' : hrq_e($hrq_tab) . ' ' ?>requests.</div>
        <?php else: ?>
            <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>Employee</th><th>Request</th><th>Purpose</th><th style="width:140px;">Requested</th>
                    <th style="width:180px;">Status</th><th style="width:190px;"></th>
                </tr></thead>
                <tbody>
                <?php foreach ($hrq_rows as $r): ?>
                    <tr>
                        <td><a class="fw-semibold" href="index.php?page=employee-details&amp;id=<?= (int) $r['employee_id'] ?>"><?= hrq_e(trim($r['lastname'] . ', ' . $r['firstname'])) ?></a>
                            <div class="hrq-sub"><?= hrq_e(trim(($r['employee_no'] ?: '') . ($r['department_name'] ? ' · ' . $r['department_name'] : ''), ' ·')) ?></div></td>
                        <td><?= hrq_e($hrq_types[$r['request_type']] ?? $r['request_type']) ?></td>
                        <td><?= hrq_e($r['purpose'] ?: '—') ?></td>
                        <td><?= hrq_e(date('M j, Y', strtotime($r['created_at']))) ?><div class="hrq-sub"><?= hrq_e(date('g:i A', strtotime($r['created_at']))) ?></div></td>
                        <td><span class="hrq-chip <?= hrq_e($r['status']) ?>"><?= hrq_e(ucfirst($r['status'])) ?></span>
                            <?php if ($r['status'] === 'released' && $r['released_at']): ?><div class="hrq-sub"><?= hrq_e(date('M j, Y', strtotime($r['released_at']))) ?> · <?= hrq_e($r['signatory_name']) ?></div><?php endif; ?>
                            <?php if ($r['hr_remarks']): ?><div class="hrq-sub"><?= hrq_e($r['hr_remarks']) ?></div><?php endif; ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($r['status'] === 'pending' && $hrq_can): ?>
                                <button class="btn btn-primary btn-sm py-0" onclick="hrqRelease(<?= (int) $r['id'] ?>, <?= (int) $r['employee_id'] ?>, <?= hrq_e(json_encode($r['request_type'])) ?>, <?= hrq_e(json_encode($r['purpose'] ?? '')) ?>)"><i class="ri-check-line"></i> Release</button>
                                <button class="btn btn-outline-danger btn-sm py-0" onclick="hrqDecline(<?= (int) $r['id'] ?>)">Decline</button>
                            <?php elseif ($r['status'] === 'released'): ?>
                                <a class="btn btn-outline-secondary btn-sm py-0" href="hr-certificate.php?request_id=<?= (int) $r['id'] ?>" target="_blank" rel="noopener"><i class="ri-file-pdf-2-line"></i> Open PDF</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
</div>
</div>

<?php if ($hrq_has && $hrq_can): ?>
<script src="assets2/js/hr-modules.js?v=2"></script>
<script>
var HRQ_TYPES = <?= json_encode(array_map(function ($k, $v) { return ['value' => $k, 'label' => $v]; }, array_keys($hrq_types), $hrq_types)) ?>;
var HRQ_EMPS = <?= json_encode($hrq_emps, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

/** The last signatory used on this browser, so HR types it once. */
function hrqSigner() {
    try { return JSON.parse(localStorage.getItem('hrq-signer') || '{}'); } catch (e) { return {}; }
}
function hrqRemember(v) {
    try { localStorage.setItem('hrq-signer', JSON.stringify({ signatory_name: v.signatory_name, signatory_title: v.signatory_title })); } catch (e) {}
}

function hrqPreview(employeeId, type, purpose, signer) {
    return 'hr-certificate.php?' + new URLSearchParams({
        employee_id: employeeId, type: type, purpose: purpose || '',
        sign_name: signer.signatory_name || '', sign_title: signer.signatory_title || ''
    }).toString();
}

function hrqRelease(id, employeeId, type, purpose) {
    var s = hrqSigner();
    HR.form({
        title: 'Release certificate',
        values: s,
        fields: [
            { name: 'x', type: 'note', html: 'The certificate is generated from the employee\'s 201 file (position, department, hire date' + (type === 'coe_comp' ? ', monthly pay' : '') + '). Check them first: <a href="#" id="hrq-prev">preview the PDF</a>.' },
            { name: 'signatory_name', label: 'Signed by', max: 120, required: true },
            { name: 'signatory_title', label: 'Title', max: 120, required: true, placeholder: 'e.g. HR Manager' },
            { name: 'hr_remarks', label: 'Note to the employee (optional)', max: 255, wide: true }
        ],
        confirmText: 'Release'
    }).then(function (v) {
        if (!v) return;
        hrqRemember(v);
        HR.post('hr_request_release', Object.assign({ id: id }, v)).then(function (j) { HR.done(j, 'Released', { timer: 2000 }); });
    });
    // The preview link reads whatever is typed in the dialog right now.
    setTimeout(function () {
        var a = document.getElementById('hrq-prev');
        if (a) a.addEventListener('click', function (e) {
            e.preventDefault();
            window.open(hrqPreview(employeeId, type, purpose, {
                signatory_name: (document.getElementById('hrf-signatory_name') || {}).value,
                signatory_title: (document.getElementById('hrf-signatory_title') || {}).value
            }), '_blank', 'noopener');
        });
    }, 50);
}

function hrqDecline(id) {
    HR.form({
        title: 'Decline request',
        fields: [{ name: 'hr_remarks', label: 'Reason (the employee sees this)', max: 255, required: true, wide: true }],
        confirmText: 'Decline'
    }).then(function (v) {
        if (v) HR.post('hr_request_decline', Object.assign({ id: id }, v)).then(function (j) { HR.done(j, 'Declined'); });
    });
}

function hrqPrint() {
    var s = hrqSigner();
    HR.form({
        title: 'Print a certificate',
        values: Object.assign({ type: 'coe' }, s),
        fields: [
            { name: 'employee_id', label: 'Employee', type: 'select', options: HRQ_EMPS, required: true, wide: true },
            { name: 'type', label: 'Certificate', type: 'select', options: HRQ_TYPES, required: true, wide: true },
            { name: 'purpose', label: 'Purpose (optional)', max: 190, wide: true, placeholder: 'e.g. a bank loan application' },
            { name: 'signatory_name', label: 'Signed by', max: 120, required: true },
            { name: 'signatory_title', label: 'Title', max: 120, required: true }
        ],
        confirmText: 'Open PDF'
    }).then(function (v) {
        if (!v) return;
        hrqRemember(v);
        window.open(hrqPreview(v.employee_id, v.type, v.purpose, v), '_blank', 'noopener');
    });
}
</script>
<?php endif; ?>
