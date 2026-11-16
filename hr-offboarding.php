<?php
// Offboarding — clearances for employees who are leaving. Included via
// index.php / app.php (?page=hr-offboarding). Admin + HR.
//
// Start a clearance → each office signs off its line → Complete. Completing
// writes the separation to the 201 file and the history, and deactivates the
// employee only when HR ticks that box (see includes/hr/offboarding.php).

require_once __DIR__ . '/hr_class.php';

$hof      = new HrManagement($conn);
$hof_has  = $hof->tableExists('hr_clearances');
$hof_can  = can_edit('employee-201');
$hof_tab  = ($_GET['status'] ?? 'open') === 'completed' ? 'completed' : 'open';
$hof_rows = $hof->clearances($hof_tab);

$hof_emps = [];
$q = $conn->query("SELECT id, TRIM(CONCAT(lastname, ', ', firstname)) AS name FROM employee WHERE status = 1 ORDER BY lastname, firstname");
while ($q && ($r = $q->fetch_assoc())) $hof_emps[] = ['value' => (int) $r['id'], 'label' => $r['name']];

$hof_open_n = 0;
if ($hof_has) {
    $c = $conn->query("SELECT COUNT(*) AS n FROM hr_clearances WHERE status = 'open'");
    $hof_open_n = (int) ($c ? $c->fetch_assoc()['n'] : 0);
}

if (!function_exists('hof_e')) {
    function hof_e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
?>
<style>
.hof-tabs{display:flex;gap:6px;margin-bottom:12px;}
.hof-tabs a{padding:5px 12px;border-radius:8px;font-size:12.5px;font-weight:700;text-decoration:none;color:var(--v2-text-2,#6f6a80);border:1px solid var(--v2-border,#e2deeb);background:var(--v2-surface,#fff);}
.hof-tabs a.on{background:var(--app-primary,#673bb6);border-color:var(--app-primary,#673bb6);color:#fff;}
.hof-card{border:1px solid var(--v2-border,#ece9f3);border-radius:12px;background:var(--v2-surface,#fff);margin-bottom:12px;overflow:hidden;}
.hof-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 14px;border-bottom:1px solid var(--v2-border,#f1eff5);}
.hof-name{font-weight:800;font-size:15px;color:var(--v2-text,#2b2639);text-decoration:none;}
.hof-meta{font-size:12px;color:var(--v2-text-2,#6f6a80);margin-top:2px;}
.hof-prog{min-width:180px;}
.hof-prog-bar{height:8px;border-radius:4px;background:var(--v2-surface-2,#f1eff5);overflow:hidden;margin-top:4px;}
.hof-prog-bar div{height:100%;background:#10b981;border-radius:0 4px 4px 0;}
.hof-prog-txt{font-size:11.5px;font-weight:700;color:var(--v2-text-2,#6f6a80);text-align:right;}
.hof-body{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:0;}
@media (max-width:900px){.hof-body{grid-template-columns:1fr;}}
.hof-col{padding:12px 14px;}
.hof-col + .hof-col{border-left:1px solid var(--v2-border,#f1eff5);}
@media (max-width:900px){.hof-col + .hof-col{border-left:0;border-top:1px solid var(--v2-border,#f1eff5);}}
.hof-lbl{font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--v2-text-3,#918d9c);margin-bottom:6px;}
.hof-item{display:flex;align-items:flex-start;gap:10px;padding:6px 0;border-bottom:1px dashed var(--v2-border,#f1eff5);font-size:13px;}
.hof-item:last-child{border-bottom:0;}
.hof-item input{width:17px;height:17px;margin-top:2px;flex-shrink:0;cursor:pointer;}
.hof-item.done .t{color:var(--v2-text-2,#6f6a80);text-decoration:line-through;text-decoration-color:rgba(0,0,0,.25);}
.hof-item .by{font-size:11px;color:var(--v2-text-3,#918d9c);}
.hof-asset{font-size:12.5px;padding:4px 0;}
.hof-chip{display:inline-block;font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:9px;text-transform:uppercase;letter-spacing:.3px;background:var(--v2-surface-3,#efedf3);color:var(--v2-text-2,#666);}
.hof-chip.warn{background:rgba(230,168,23,.16);color:#9a6700;}
.hof-chip.good{background:rgba(16,185,129,.14);color:#0f9d58;}
.hof-chip.bad{background:rgba(198,40,40,.13);color:#c62828;}
.hof-foot{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;padding:10px 14px;background:var(--v2-surface-2,#faf9fc);border-top:1px solid var(--v2-border,#f1eff5);}
.hof-empty{font-size:12.5px;color:var(--v2-text-2,#908c9c);padding:14px 0;}
</style>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <div class="row mb-3"><div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
            <h4 class="mb-sm-0"><i class="ri-logout-box-r-line me-2" style="color:var(--app-primary,#673bb6);"></i>Offboarding</h4>
            <ol class="breadcrumb m-0"><li class="breadcrumb-item">HR</li><li class="breadcrumb-item active">Offboarding</li></ol>
        </div>
    </div></div>

    <?php if (!$hof_has): ?>
    <div class="alert alert-warning"><i class="ri-error-warning-line me-1"></i>The HR tables are missing. Run <code>migrations/2026_11_hr_modules.sql</code> on this database.</div>
    <?php else: ?>

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="hof-tabs">
            <a href="index.php?page=hr-offboarding" class="<?= $hof_tab === 'open' ? 'on' : '' ?>">Open<?= $hof_open_n ? ' (' . $hof_open_n . ')' : '' ?></a>
            <a href="index.php?page=hr-offboarding&amp;status=completed" class="<?= $hof_tab === 'completed' ? 'on' : '' ?>">Completed</a>
        </div>
        <?php if ($hof_can): ?>
        <button class="btn btn-primary btn-sm mb-2" onclick="hofStart()"><i class="ri-add-line me-1"></i>Start clearance</button>
        <?php endif; ?>
    </div>

    <?php if (!$hof_rows): ?>
        <div class="hof-empty"><i class="ri-information-line me-1"></i><?= $hof_tab === 'open' ? 'No one is being cleared right now.' : 'No completed clearances yet.' ?></div>
    <?php endif; ?>

    <?php foreach ($hof_rows as $c):
        $items  = $hof->clearanceItems((int) $c['id']);
        $assets = $hof->unreturnedAssets((int) $c['employee_id']);
        $done   = (int) $c['items_done'];
        $total  = max(1, (int) $c['items_total']);
        $open   = $c['status'] === 'open';
        $fp     = ['pending' => 'warn', 'processing' => '', 'released' => 'good'][$c['final_pay_status']] ?? '';
    ?>
    <div class="hof-card">
        <div class="hof-head">
            <div>
                <a class="hof-name" href="index.php?page=employee-details&amp;id=<?= (int) $c['employee_id'] ?>#arrow-hrhistory"><?= hof_e(trim($c['lastname'] . ', ' . $c['firstname'])) ?></a>
                <?php if ((int) $c['employee_status'] !== 1): ?> <span class="hof-chip">Inactive</span><?php endif; ?>
                <div class="hof-meta">
                    <b><?= hof_e($c['separation_type']) ?></b> · last day <?= hof_e(date('M j, Y', strtotime($c['separation_date']))) ?>
                    <?= $c['department_name'] ? ' · ' . hof_e($c['department_name']) : '' ?>
                    <?= $c['reason'] ? '<br>' . hof_e($c['reason']) : '' ?>
                </div>
            </div>
            <div class="hof-prog">
                <div class="hof-prog-txt"><?= $done ?> of <?= (int) $c['items_total'] ?> signed off</div>
                <div class="hof-prog-bar"><div style="width:<?= round($done / $total * 100) ?>%"></div></div>
                <?php if (!$open): ?><div class="hof-prog-txt mt-1">Completed <?= hof_e(date('M j, Y', strtotime($c['completed_at']))) ?><?= (int) $c['deactivated'] ? ' · deactivated' : '' ?></div><?php endif; ?>
            </div>
        </div>
        <div class="hof-body">
            <div class="hof-col">
                <div class="hof-lbl">Clearance checklist</div>
                <?php foreach ($items as $it): ?>
                <label class="hof-item <?= (int) $it['cleared'] ? 'done' : '' ?>">
                    <input type="checkbox" <?= (int) $it['cleared'] ? 'checked' : '' ?> <?= $open && $hof_can ? '' : 'disabled' ?>
                           onchange="hofTick(this, <?= (int) $it['id'] ?>)">
                    <span>
                        <span class="t"><?= hof_e($it['label']) ?></span>
                        <?php if ((int) $it['cleared']): ?><div class="by">Signed off <?= hof_e(date('M j, g:i A', strtotime($it['cleared_at']))) ?><?= $it['cleared_by_name'] ? ' by ' . hof_e($it['cleared_by_name']) : '' ?></div><?php endif; ?>
                        <?php if ($it['remarks']): ?><div class="by"><?= hof_e($it['remarks']) ?></div><?php endif; ?>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="hof-col">
                <div class="hof-lbl">Company property still with the employee</div>
                <?php if (!$assets): ?>
                    <div class="hof-asset text-muted"><i class="ri-checkbox-circle-line me-1" style="color:#0f9d58;"></i>Nothing outstanding.</div>
                <?php endif; ?>
                <?php foreach ($assets as $a): ?>
                    <div class="hof-asset"><span class="hof-chip warn">Out</span> <?= hof_e($a['item']) ?><?= $a['serial_no'] ? ' <span class="text-muted">(' . hof_e($a['serial_no']) . ')</span>' : '' ?></div>
                <?php endforeach; ?>
                <?php if ($assets): ?><div class="hof-asset"><a href="index.php?page=employee-details&amp;id=<?= (int) $c['employee_id'] ?>#arrow-hrassets">Record returns on the employee's Property tab</a></div><?php endif; ?>

                <div class="hof-lbl mt-3">Final pay</div>
                <div class="hof-asset"><span class="hof-chip <?= $fp ?>"><?= hof_e(ucfirst($c['final_pay_status'])) ?></span>
                    <?= $c['final_pay_note'] ? ' ' . hof_e($c['final_pay_note']) : '' ?>
                    <?php if ($hof_can): ?> <a href="#" onclick="hofFinalPay(<?= hof_e(json_encode(['id' => (int) $c['id'], 'final_pay_status' => $c['final_pay_status'], 'final_pay_note' => $c['final_pay_note']])) ?>); return false;" class="ms-1 small">Change</a><?php endif; ?>
                </div>
                <div class="text-muted" style="font-size:11px;">Computed in Payroll as usual; tracked here so the clearance shows it.</div>
            </div>
        </div>
        <?php if ($open && $hof_can): ?>
        <div class="hof-foot">
            <button class="btn btn-outline-danger btn-sm" onclick="hofCancel(<?= (int) $c['id'] ?>)">Cancel clearance</button>
            <button class="btn btn-success btn-sm" onclick="hofComplete(<?= (int) $c['id'] ?>, <?= $done ?>, <?= (int) $c['items_total'] ?>, <?= count($assets) ?>)"><i class="ri-check-double-line me-1"></i>Complete</button>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
</div>
</div>
</div>

<?php if ($hof_has && $hof_can): ?>
<script src="assets2/js/hr-modules.js?v=2"></script>
<script>
var HOF_EMPS = <?= json_encode($hof_emps, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var HOF_TYPES = <?= json_encode(HrManagement::separationTypes()) ?>;
var HOF_ITEMS = <?= json_encode(HrManagement::defaultClearanceItems()) ?>;

function hofStart() {
    HR.form({
        title: 'Start clearance',
        width: 680,
        values: { checklist: HOF_ITEMS.join('\n') },
        fields: [
            { name: 'employee_id', label: 'Employee', type: 'select', options: HOF_EMPS, required: true, wide: true },
            { name: 'separation_type', label: 'Type of separation', type: 'select', options: HOF_TYPES, required: true },
            { name: 'separation_date', label: 'Last day', type: 'date', required: true },
            { name: 'reason', label: 'Reason / notes', max: 255, wide: true },
            { name: 'checklist', label: 'Checklist (one office per line)', type: 'textarea', rows: 7, required: true }
        ],
        confirmText: 'Start'
    }).then(function (v) {
        if (!v) return;
        var items = v.checklist.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
        delete v.checklist;
        v.items = items;
        HR.post('hr_clearance_start', v).then(function (j) { HR.done(j, 'Clearance started'); });
    });
}

function hofTick(box, itemId) {
    var want = box.checked;
    box.disabled = true;
    HR.post('hr_clearance_item', { item_id: itemId, cleared: want ? 1 : '' }).then(function (j) {
        if (!j.result) {
            box.checked = !want;
            box.disabled = false;
            Swal.fire({ icon: 'error', title: 'Not saved', text: j.message });
            return;
        }
        location.reload();
    });
}

function hofFinalPay(c) {
    HR.form({
        title: 'Final pay',
        values: c,
        fields: [
            { name: 'final_pay_status', label: 'Status', type: 'select', required: true,
              options: [{ value: 'pending', label: 'Pending' }, { value: 'processing', label: 'Processing' }, { value: 'released', label: 'Released' }] },
            { name: 'final_pay_note', label: 'Note', max: 255 }
        ]
    }).then(function (v) {
        if (v) HR.post('hr_clearance_final_pay', Object.assign({ id: c.id }, v)).then(function (j) { HR.done(j); });
    });
}

function hofComplete(id, done, total, assetsOut) {
    if (done < total) {
        Swal.fire({ icon: 'info', title: 'Not yet', text: (total - done) + ' checklist line' + (total - done === 1 ? ' is' : 's are') + ' still waiting for a sign-off.' });
        return;
    }
    HR.form({
        title: 'Complete clearance',
        fields: [
            assetsOut ? { name: 'w', type: 'note', html: '<b>' + assetsOut + '</b> item' + (assetsOut === 1 ? ' is' : 's are') + ' still recorded as with the employee.' } : null,
            { name: 'n', type: 'note', html: 'The 201 file gets the separation date and reason, and the employee\'s history gets a Separation entry.' },
            { name: 'deactivate', label: 'Also deactivate the employee (they can no longer sign in to the portal or punch at a kiosk)', type: 'checkbox', checked: true }
        ].filter(Boolean),
        confirmText: 'Complete'
    }).then(function (v) {
        if (v) HR.post('hr_clearance_complete', Object.assign({ id: id }, v)).then(function (j) { HR.done(j, 'Completed', { timer: 2200 }); });
    });
}

function hofCancel(id) {
    HR.confirm({ title: 'Cancel this clearance?', text: 'Use this for a clearance started by mistake. The sign-offs are discarded.', confirmText: 'Cancel clearance', danger: true })
        .then(function (ok) { if (ok) HR.post('hr_clearance_cancel', { id: id }).then(function (j) { HR.done(j, 'Cancelled'); }); });
}
</script>
<?php endif; ?>
