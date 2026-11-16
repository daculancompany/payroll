<?php
/**
 * The HR record of one employee beyond the 201 form: Documents, History
 * (movements), Memos, Trainings and company Property — five tab panes inside
 * Employee Details, next to the 201 File and Dependants tabs.
 *
 * Same two-permission rule as component/employee_201_tabs.php: these panes
 * ask can_edit('employee-201') (Admin + HR), which is exactly what hr-ajax.php
 * enforces for every hr_* action.
 *
 * Expects from the including page: $conn, $emp_id.
 */

require_once __DIR__ . '/../hr_class.php';

$ehr      = new HrManagement($conn);
$ehr_id   = (int) $emp_id;
$ehr_can  = can_edit('employee-201');
$ehr_has  = $ehr->tableExists('hr_documents');
$ehr_docs = $ehr->documents($ehr_id);
$ehr_mov  = $ehr->movements($ehr_id);
$ehr_memo = $ehr->memos($ehr_id);
$ehr_trn  = $ehr->trainings($ehr_id);
$ehr_ast  = $ehr->assets($ehr_id);
$ehr_snap = $ehr->currentSnapshot($ehr_id);
$ehr_clr  = $ehr->openClearanceFor($ehr_id);
$ehr_out  = count(array_filter($ehr_ast, function ($a) { return !$a['returned_date']; }));

if (!function_exists('ehr')) {
    function ehr($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
    function ehr_date($ymd) { return $ymd ? ehr(date('M j, Y', strtotime($ymd))) : '<span class="ehr-dim">—</span>'; }

    /** "Expired" / "in 12 days" badge for an expiry date, or a plain date. */
    function ehr_expiry($ymd)
    {
        if (!$ymd) return '<span class="ehr-dim">—</span>';
        $days = (int) floor((strtotime($ymd) - strtotime(date('Y-m-d'))) / 86400);
        $txt  = ehr(date('M j, Y', strtotime($ymd)));
        if ($days < 0)   return $txt . ' <span class="ehr-chip bad">Expired</span>';
        if ($days <= 60) return $txt . ' <span class="ehr-chip warn">' . ($days === 0 ? 'Today' : 'in ' . $days . ' d') . '</span>';
        return $txt;
    }

    function ehr_json($v) { return ehr(json_encode($v, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)); }
}
?>

<style>
.ehr-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px;}
.ehr-note{font-size:12px;color:var(--v2-text-2,#6f6a80);}
.ehr-dim{color:var(--v2-text-3,#a19dad);}
.ehr-empty{font-size:12.5px;color:var(--v2-text-2,#908c9c);padding:14px 0;}
.ehr-chip{display:inline-block;font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:9px;text-transform:uppercase;letter-spacing:.3px;background:var(--v2-surface-3,#efedf3);color:var(--v2-text-2,#666);vertical-align:middle;}
.ehr-chip.good{background:rgba(16,185,129,.14);color:#0f9d58;}
.ehr-chip.warn{background:rgba(230,168,23,.16);color:#9a6700;}
.ehr-chip.bad{background:rgba(198,40,40,.13);color:#c62828;}
.ehr-chip.info{background:rgba(59,130,246,.13);color:#2563eb;}
.ehr-sub{font-size:11px;color:var(--v2-text-3,#918d9c);}
.ehr-table td{vertical-align:middle;}
.ehr-change{font-size:12.5px;line-height:1.55;}
.ehr-change b{font-weight:700;}
.ehr-arrow{color:var(--v2-text-3,#a19dad);margin:0 4px;}
.ehr-memo{border:1px solid var(--v2-border,#ece9f3);border-radius:12px;padding:12px 14px;margin-bottom:10px;background:var(--v2-surface,#fff);}
.ehr-memo-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap;}
.ehr-memo-subj{font-weight:800;font-size:14px;color:var(--v2-text,#2b2639);}
.ehr-memo-body{white-space:pre-wrap;font-size:12.8px;line-height:1.55;color:var(--v2-text-2,#4b4660);margin-top:8px;max-height:7.5em;overflow:hidden;position:relative;}
.ehr-memo-body.open{max-height:none;}
.ehr-memo-more{font-size:11.5px;font-weight:700;color:var(--app-primary,#673bb6);background:none;border:0;padding:0;margin-top:4px;}
.ehr-resp{margin-top:10px;padding:9px 12px;border-left:3px solid var(--app-primary,#673bb6);background:var(--v2-surface-2,#f7f5fc);border-radius:0 8px 8px 0;font-size:12.5px;white-space:pre-wrap;}
.ehr-resp-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--v2-text-3,#918d9c);margin-bottom:3px;white-space:normal;}
.ehr-alert{font-size:12.5px;padding:9px 12px;border-radius:10px;margin-bottom:12px;background:rgba(230,168,23,.1);border:1px solid rgba(230,168,23,.3);color:#8a5d00;}
</style>

<?php if (!$ehr_has): ?>
    <?php foreach (['hrdocs', 'hrhistory', 'hrmemos', 'hrtrain', 'hrassets'] as $pane): ?>
    <div class="tab-pane" id="arrow-<?= $pane ?>" role="tabpanel">
        <div class="alert alert-warning mb-0"><i class="ri-error-warning-line me-1"></i>The HR tables are missing. Run <code>migrations/2026_11_hr_modules.sql</code> on this database.</div>
    </div>
    <?php endforeach; ?>
<?php else: ?>

<!-- ── DOCUMENTS ─────────────────────────────────────────────── -->
<div class="tab-pane" id="arrow-hrdocs" role="tabpanel">
    <div class="ehr-bar">
        <span class="ehr-note"><i class="ri-shield-keyhole-line me-1"></i>Files are private: only Admin, HR and (when visible) the employee can open them.</span>
        <?php if ($ehr_can): ?>
        <button class="btn btn-primary btn-sm" onclick="ehrDocUpload()"><i class="ri-upload-2-line me-1"></i>Upload document</button>
        <?php endif; ?>
    </div>
    <?php if (!$ehr_docs): ?>
        <div class="ehr-empty"><i class="ri-information-line me-1"></i>No documents on file yet.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0 ehr-table">
            <thead class="table-light"><tr>
                <th>Document</th><th style="width:140px;">Category</th><th style="width:170px;">Expiry</th>
                <th style="width:150px;">Source</th><th style="width:150px;">Uploaded</th>
                <?php if ($ehr_can): ?><th style="width:110px;"></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($ehr_docs as $d): ?>
                <tr>
                    <td>
                        <a href="hr-file.php?kind=doc&amp;id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener" class="fw-semibold">
                            <i class="<?= $d['mime'] === 'application/pdf' ? 'ri-file-pdf-2-line' : 'ri-image-line' ?> me-1"></i><?= ehr($d['title']) ?>
                        </a>
                        <?php if ((int) $d['visible_to_employee'] !== 1): ?> <span class="ehr-chip" title="Hidden from the employee portal">HR only</span><?php endif; ?>
                        <?php if ($d['notes']): ?><div class="ehr-sub"><?= ehr($d['notes']) ?></div><?php endif; ?>
                    </td>
                    <td><?= ehr($d['category']) ?></td>
                    <td><?= ehr_expiry($d['expiry_date']) ?></td>
                    <td>
                        <?php if ($d['source'] === 'employee'): ?>
                            <?= $d['verified_at'] ? '<span class="ehr-chip good">Employee · verified</span>' : '<span class="ehr-chip info">Employee · to verify</span>' ?>
                        <?php else: ?>
                            <span class="ehr-chip">HR</span>
                        <?php endif; ?>
                    </td>
                    <td><?= ehr_date(substr($d['created_at'], 0, 10)) ?><div class="ehr-sub"><?= ehr($d['uploaded_by_name'] ?: ($d['source'] === 'employee' ? 'Employee' : '')) ?></div></td>
                    <?php if ($ehr_can): ?>
                    <td class="text-end text-nowrap">
                        <?php if ($d['source'] === 'employee' && !$d['verified_at']): ?>
                        <button class="btn btn-success btn-sm py-0" title="Verify" onclick="ehrDocVerify(<?= (int) $d['id'] ?>)"><i class="ri-check-line"></i></button>
                        <?php endif; ?>
                        <button class="btn btn-outline-secondary btn-sm py-0" title="Edit details" onclick="ehrDocEdit(<?= ehr_json($d) ?>)"><i class="ri-edit-line"></i></button>
                        <button class="btn btn-outline-danger btn-sm py-0" title="Delete" onclick="ehrDelete('hr_doc_delete', <?= (int) $d['id'] ?>, <?= ehr_json($d['title']) ?>)"><i class="ri-delete-bin-line"></i></button>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── HISTORY ───────────────────────────────────────────────── -->
<div class="tab-pane" id="arrow-hrhistory" role="tabpanel">
    <?php if ($ehr_clr): ?>
    <div class="ehr-alert"><i class="ri-logout-box-r-line me-1"></i>This employee has an open clearance (<?= ehr($ehr_clr['separation_type']) ?>, last day <?= ehr_date($ehr_clr['separation_date']) ?>).
        <a href="index.php?page=hr-offboarding">Open Offboarding</a></div>
    <?php endif; ?>
    <div class="ehr-bar">
        <span class="ehr-note"><i class="ri-information-line me-1"></i>A record of changes. Saving one here does <b>not</b> change pay, position or department — update those on the employee record.</span>
        <?php if ($ehr_can): ?>
        <button class="btn btn-primary btn-sm" onclick="ehrMovement()"><i class="ri-add-line me-1"></i>Add entry</button>
        <?php endif; ?>
    </div>
    <?php if (!$ehr_mov): ?>
        <div class="ehr-empty"><i class="ri-information-line me-1"></i>No history recorded yet. Start with the "Hired" entry.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0 ehr-table">
            <thead class="table-light"><tr>
                <th style="width:130px;">Effective</th><th style="width:150px;">Type</th><th>Change</th>
                <th style="width:150px;">Recorded</th><?php if ($ehr_can): ?><th style="width:90px;"></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($ehr_mov as $m): ?>
                <tr>
                    <td class="fw-semibold"><?= ehr_date($m['effective_date']) ?></td>
                    <td><span class="ehr-chip <?= $m['movement_type'] === 'Separation' ? 'bad' : ($m['movement_type'] === 'Promotion' ? 'good' : 'info') ?>"><?= ehr($m['movement_type']) ?></span></td>
                    <td class="ehr-change">
                        <?php foreach ([['Position', 'from_position', 'to_position'], ['Department', 'from_department', 'to_department'], ['Status', 'from_status', 'to_status']] as $pair):
                            if ($m[$pair[1]] === null && $m[$pair[2]] === null) continue; ?>
                            <div><span class="ehr-sub"><?= $pair[0] ?>:</span> <?= ehr($m[$pair[1]] ?: '—') ?><span class="ehr-arrow">→</span><b><?= ehr($m[$pair[2]] ?: '—') ?></b></div>
                        <?php endforeach; ?>
                        <?php if ($m['from_salary'] !== null || $m['to_salary'] !== null): ?>
                            <div><span class="ehr-sub">Monthly pay:</span> <?= $m['from_salary'] !== null ? number_format((float) $m['from_salary'], 2) : '—' ?><span class="ehr-arrow">→</span><b><?= $m['to_salary'] !== null ? number_format((float) $m['to_salary'], 2) : '—' ?></b></div>
                        <?php endif; ?>
                        <?php if ($m['remarks']): ?><div class="ehr-sub"><?= ehr($m['remarks']) ?></div><?php endif; ?>
                    </td>
                    <td><?= ehr_date(substr($m['created_at'], 0, 10)) ?><div class="ehr-sub"><?= ehr($m['recorded_by_name']) ?></div></td>
                    <?php if ($ehr_can): ?>
                    <td class="text-end text-nowrap">
                        <button class="btn btn-outline-secondary btn-sm py-0" onclick="ehrMovement(<?= ehr_json($m) ?>)"><i class="ri-edit-line"></i></button>
                        <button class="btn btn-outline-danger btn-sm py-0" onclick="ehrDelete('hr_movement_delete', <?= (int) $m['id'] ?>, <?= ehr_json($m['movement_type'] . ' — ' . $m['effective_date']) ?>)"><i class="ri-delete-bin-line"></i></button>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── MEMOS ─────────────────────────────────────────────────── -->
<div class="tab-pane" id="arrow-hrmemos" role="tabpanel">
    <div class="ehr-bar">
        <span class="ehr-note"><i class="ri-information-line me-1"></i>The employee reads and acknowledges memos in the portal. A Notice to Explain asks for their written explanation.</span>
        <?php if ($ehr_can): ?>
        <button class="btn btn-primary btn-sm" onclick="ehrMemo()"><i class="ri-quill-pen-line me-1"></i>Issue memo</button>
        <?php endif; ?>
    </div>
    <?php if (!$ehr_memo): ?>
        <div class="ehr-empty"><i class="ri-information-line me-1"></i>No memos issued.</div>
    <?php endif; ?>
    <?php foreach ($ehr_memo as $m):
        $tone = $m['memo_type'] === 'Commendation' ? 'good' : (in_array($m['memo_type'], ['Final Warning', 'Suspension'], true) ? 'bad' : 'warn');
        if ($m['status'] === 'closed')          { $st = '<span class="ehr-chip">Closed</span>'; }
        elseif ($m['employee_response'])        { $st = '<span class="ehr-chip info">Explanation received</span>'; }
        elseif ($m['acknowledged_at'])          { $st = '<span class="ehr-chip good">Acknowledged ' . ehr(date('M j', strtotime($m['acknowledged_at']))) . '</span>'; }
        elseif ((int) $m['requires_ack'] === 1) { $st = '<span class="ehr-chip warn">Awaiting acknowledgment</span>'; }
        else                                    { $st = '<span class="ehr-chip">For information</span>'; }
    ?>
    <div class="ehr-memo">
        <div class="ehr-memo-head">
            <div>
                <span class="ehr-chip <?= $tone ?>"><?= ehr($m['memo_type']) ?></span> <?= $st ?>
                <div class="ehr-memo-subj mt-1"><?= ehr($m['subject']) ?></div>
                <div class="ehr-sub">Issued <?= ehr_date($m['issued_date']) ?><?= $m['issued_by_name'] ? ' by ' . ehr($m['issued_by_name']) : '' ?><?= $m['respond_by'] ? ' · respond by ' . ehr_date($m['respond_by']) : '' ?></div>
            </div>
            <?php if ($ehr_can): ?>
            <div class="text-nowrap">
                <?php if (!$m['acknowledged_at']): ?>
                <button class="btn btn-outline-secondary btn-sm py-0" title="Edit" onclick="ehrMemo(<?= ehr_json($m) ?>)"><i class="ri-edit-line"></i></button>
                <?php endif; ?>
                <?php if ($m['status'] === 'open'): ?>
                <button class="btn btn-outline-primary btn-sm py-0" title="Close the matter" onclick="ehrMemoClose(<?= (int) $m['id'] ?>)"><i class="ri-checkbox-circle-line"></i> Close</button>
                <?php endif; ?>
                <button class="btn btn-outline-danger btn-sm py-0" title="Delete" onclick="ehrDelete('hr_memo_delete', <?= (int) $m['id'] ?>, <?= ehr_json($m['subject']) ?>)"><i class="ri-delete-bin-line"></i></button>
            </div>
            <?php endif; ?>
        </div>
        <div class="ehr-memo-body" id="ehr-memo-<?= (int) $m['id'] ?>"><?= ehr($m['body']) ?></div>
        <?php if (mb_strlen($m['body']) > 300 || substr_count($m['body'], "\n") > 4): ?>
        <button class="ehr-memo-more" onclick="document.getElementById('ehr-memo-<?= (int) $m['id'] ?>').classList.toggle('open'); this.textContent = this.textContent === 'Show all' ? 'Show less' : 'Show all';">Show all</button>
        <?php endif; ?>
        <?php if ($m['employee_response']): ?>
        <div class="ehr-resp"><div class="ehr-resp-lbl">Employee's explanation · <?= ehr(date('M j, Y g:i A', strtotime($m['responded_at']))) ?></div><?= ehr($m['employee_response']) ?></div>
        <?php endif; ?>
        <?php if ($m['status'] === 'closed'): ?>
        <div class="ehr-sub mt-2"><i class="ri-checkbox-circle-line me-1"></i>Closed <?= ehr(date('M j, Y', strtotime($m['closed_at']))) ?><?= $m['resolution'] ? ' — ' . ehr($m['resolution']) : '' ?></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── TRAININGS ─────────────────────────────────────────────── -->
<div class="tab-pane" id="arrow-hrtrain" role="tabpanel">
    <div class="ehr-bar">
        <span class="ehr-note"><i class="ri-information-line me-1"></i>Certificates with an expiry show up in HR reminders before they lapse. Keep the scanned certificate in Documents.</span>
        <?php if ($ehr_can): ?>
        <button class="btn btn-primary btn-sm" onclick="ehrTraining()"><i class="ri-add-line me-1"></i>Add training</button>
        <?php endif; ?>
    </div>
    <?php if (!$ehr_trn): ?>
        <div class="ehr-empty"><i class="ri-information-line me-1"></i>No trainings recorded.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0 ehr-table">
            <thead class="table-light"><tr>
                <th>Training</th><th style="width:190px;">Dates</th><th style="width:70px;">Hours</th>
                <th style="width:150px;">Certificate</th><th style="width:170px;">Expiry</th>
                <?php if ($ehr_can): ?><th style="width:90px;"></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($ehr_trn as $t): ?>
                <tr>
                    <td><span class="fw-semibold"><?= ehr($t['title']) ?></span><?= $t['provider'] ? '<div class="ehr-sub">' . ehr($t['provider']) . '</div>' : '' ?><?= $t['remarks'] ? '<div class="ehr-sub">' . ehr($t['remarks']) . '</div>' : '' ?></td>
                    <td><?= ehr_date($t['start_date']) ?><?= $t['end_date'] && $t['end_date'] !== $t['start_date'] ? ' – ' . ehr_date($t['end_date']) : '' ?></td>
                    <td><?= $t['hours'] !== null ? rtrim(rtrim(number_format((float) $t['hours'], 2), '0'), '.') : '—' ?></td>
                    <td><?= ehr($t['certificate_no'] ?: '—') ?></td>
                    <td><?= ehr_expiry($t['expiry_date']) ?></td>
                    <?php if ($ehr_can): ?>
                    <td class="text-end text-nowrap">
                        <button class="btn btn-outline-secondary btn-sm py-0" onclick="ehrTraining(<?= ehr_json($t) ?>)"><i class="ri-edit-line"></i></button>
                        <button class="btn btn-outline-danger btn-sm py-0" onclick="ehrDelete('hr_training_delete', <?= (int) $t['id'] ?>, <?= ehr_json($t['title']) ?>)"><i class="ri-delete-bin-line"></i></button>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── COMPANY PROPERTY ──────────────────────────────────────── -->
<div class="tab-pane" id="arrow-hrassets" role="tabpanel">
    <div class="ehr-bar">
        <span class="ehr-note"><i class="ri-information-line me-1"></i><?= $ehr_out ? '<b>' . $ehr_out . '</b> item' . ($ehr_out === 1 ? ' is' : 's are') . ' still with the employee. ' : '' ?>Unreturned items are listed on the clearance when the employee leaves.</span>
        <?php if ($ehr_can): ?>
        <button class="btn btn-primary btn-sm" onclick="ehrAsset()"><i class="ri-add-line me-1"></i>Issue item</button>
        <?php endif; ?>
    </div>
    <?php if (!$ehr_ast): ?>
        <div class="ehr-empty"><i class="ri-information-line me-1"></i>No company property issued.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0 ehr-table">
            <thead class="table-light"><tr>
                <th>Item</th><th style="width:140px;">Serial / tag</th><th style="width:140px;">Issued</th>
                <th style="width:200px;">Returned</th><?php if ($ehr_can): ?><th style="width:150px;"></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($ehr_ast as $a): ?>
                <tr>
                    <td><span class="fw-semibold"><?= ehr($a['item']) ?></span><div class="ehr-sub"><?= ehr(trim(($a['category'] ?: '') . ($a['issued_condition'] ? ' · ' . $a['issued_condition'] : ''), ' ·')) ?></div><?= $a['remarks'] ? '<div class="ehr-sub">' . ehr($a['remarks']) . '</div>' : '' ?></td>
                    <td><?= ehr($a['serial_no'] ?: '—') ?></td>
                    <td><?= ehr_date($a['issued_date']) ?></td>
                    <td><?= $a['returned_date'] ? ehr_date($a['returned_date']) . ' <span class="ehr-chip ' . ($a['return_condition'] === 'Lost' || $a['return_condition'] === 'Damaged' ? 'bad' : 'good') . '">' . ehr($a['return_condition']) . '</span>' : '<span class="ehr-chip warn">With employee</span>' ?></td>
                    <?php if ($ehr_can): ?>
                    <td class="text-end text-nowrap">
                        <?php if (!$a['returned_date']): ?>
                        <button class="btn btn-outline-success btn-sm py-0" onclick="ehrAssetReturn(<?= ehr_json($a) ?>)"><i class="ri-arrow-go-back-line"></i> Return</button>
                        <?php endif; ?>
                        <button class="btn btn-outline-secondary btn-sm py-0" onclick="ehrAsset(<?= ehr_json($a) ?>)"><i class="ri-edit-line"></i></button>
                        <button class="btn btn-outline-danger btn-sm py-0" onclick="ehrDelete('hr_asset_delete', <?= (int) $a['id'] ?>, <?= ehr_json($a['item']) ?>)"><i class="ri-delete-bin-line"></i></button>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php if ($ehr_has && $ehr_can): ?>
<script src="assets2/js/hr-modules.js?v=2"></script>
<script>
var EHR_ID = <?= (int) $ehr_id ?>;
var EHR_LISTS = <?= json_encode([
    'docCategories' => HrManagement::docCategories(),
    'movementTypes' => HrManagement::movementTypes(),
    'statuses'      => HrManagement::profileFieldMap()['employment_status']['values'],
    'memoTypes'     => HrManagement::memoTypes(),
    'assetCats'     => HrManagement::assetCategories(),
    'assetConds'    => HrManagement::assetConditions(),
    'snapshot'      => $ehr_snap,
], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var EHR_TODAY = <?= json_encode(date('Y-m-d')) ?>;

// The page remembers the open tab across the reload a save triggers
// (employee-details.php, sessionStorage per employee), so `pane` is only
// documentation of where each action lives.
function ehrSave(action, values, pane, okTitle) {
    var data = Object.assign({ employee_id: EHR_ID }, values);
    return HR.post(action, data).then(function (j) { return HR.done(j, okTitle); });
}

function ehrDelete(action, id, label) {
    HR.confirm({ title: 'Delete "' + label + '"?', text: 'This cannot be undone.', confirmText: 'Delete', danger: true })
        .then(function (ok) {
            if (!ok) return;
            var pane = { hr_doc_delete: 'hrdocs', hr_movement_delete: 'hrhistory', hr_memo_delete: 'hrmemos',
                         hr_training_delete: 'hrtrain', hr_asset_delete: 'hrassets' }[action];
            ehrSave(action, { id: id }, pane, 'Deleted');
        });
}

// ── Documents ───────────────────────────────────────────────────────
function ehrDocUpload() {
    HR.form({
        title: 'Upload document',
        fields: [
            { name: 'file', label: 'File (PDF, JPEG, PNG or WebP · max 10 MB)', type: 'file', required: true, wide: true,
              accept: 'application/pdf,image/jpeg,image/png,image/webp', maxBytes: 10485760 },
            { name: 'category', label: 'Category', type: 'select', options: EHR_LISTS.docCategories, required: true },
            { name: 'title', label: 'Title', max: 150, placeholder: 'Defaults to the file name' },
            { name: 'expiry_date', label: 'Expiry date', type: 'date', help: 'For IDs, clearances and medical — HR is reminded before it lapses.' },
            { name: 'notes', label: 'Notes', max: 255 },
            { name: 'visible_to_employee', label: 'The employee can see this file in the portal', type: 'checkbox', checked: true }
        ],
        confirmText: 'Upload'
    }).then(function (v) { if (v) ehrSave('hr_doc_upload', v, 'hrdocs', 'Uploaded'); });
}

function ehrDocEdit(d) {
    HR.form({
        title: 'Document details',
        values: d,
        fields: [
            { name: 'category', label: 'Category', type: 'select', options: EHR_LISTS.docCategories, required: true },
            { name: 'title', label: 'Title', max: 150, required: true },
            { name: 'expiry_date', label: 'Expiry date', type: 'date' },
            { name: 'notes', label: 'Notes', max: 255 },
            { name: 'visible_to_employee', label: 'The employee can see this file in the portal', type: 'checkbox' }
        ]
    }).then(function (v) { if (v) ehrSave('hr_doc_update', Object.assign({ id: d.id }, v), 'hrdocs'); });
}

function ehrDocVerify(id) {
    ehrSave('hr_doc_verify', { id: id }, 'hrdocs', 'Verified');
}

// ── History ─────────────────────────────────────────────────────────
function ehrMovement(m) {
    var snap = EHR_LISTS.snapshot || {};
    var values = m || {
        effective_date: EHR_TODAY,
        from_position: snap.position || '', from_department: snap.department || '',
        from_salary: snap.basic_pay ? Number(snap.basic_pay).toFixed(2) : '', from_status: snap.employment_status || ''
    };
    HR.form({
        title: m ? 'Edit history entry' : 'Add history entry',
        values: values,
        fields: [
            { name: 'movement_type', label: 'Type', type: 'select', options: EHR_LISTS.movementTypes, required: true },
            { name: 'effective_date', label: 'Effective date', type: 'date', required: true },
            { name: 'from_position', label: 'From position', max: 120 },
            { name: 'to_position', label: 'To position', max: 120 },
            { name: 'from_department', label: 'From department', max: 120 },
            { name: 'to_department', label: 'To department', max: 120 },
            { name: 'from_salary', label: 'From monthly pay', type: 'number', step: '0.01', min: 0 },
            { name: 'to_salary', label: 'To monthly pay', type: 'number', step: '0.01', min: 0 },
            { name: 'from_status', label: 'From employment status', type: 'select', options: EHR_LISTS.statuses },
            { name: 'to_status', label: 'To employment status', type: 'select', options: EHR_LISTS.statuses },
            { name: 'remarks', label: 'Remarks', max: 255, wide: true },
            { name: 'apply_201', label: 'Also update the 201 file (employment status, regularization and hire date)', type: 'checkbox', checked: !m,
              help: 'Only for a change already in effect. Pay, position and department are never changed from here.' }
        ]
    }).then(function (v) {
        if (v) ehrSave('hr_movement_save', Object.assign(m ? { id: m.id } : {}, v), 'hrhistory');
    });
}

// ── Memos ───────────────────────────────────────────────────────────
function ehrMemo(m) {
    HR.form({
        title: m ? 'Edit memo' : 'Issue memo',
        width: 720,
        values: m || { issued_date: EHR_TODAY, requires_ack: 1 },
        fields: [
            { name: 'memo_type', label: 'Type', type: 'select', options: EHR_LISTS.memoTypes, required: true },
            { name: 'issued_date', label: 'Issued date', type: 'date', required: true },
            { name: 'subject', label: 'Subject', max: 150, required: true, wide: true },
            { name: 'body', label: 'Memo text', type: 'textarea', rows: 9, required: true },
            { name: 'respond_by', label: 'Respond by (for a Notice to Explain)', type: 'date' },
            { name: 'requires_ack', label: 'The employee must acknowledge it', type: 'checkbox', checked: true },
            m ? { name: 'x', type: 'note', html: 'The wording can be changed until the employee acknowledges the memo.' }
              : { name: 'notify', label: 'Notify the employee now (portal bell + phone notification)', type: 'checkbox', checked: true }
        ]
    }).then(function (v) {
        if (v) ehrSave('hr_memo_save', Object.assign(m ? { id: m.id } : {}, v), 'hrmemos', m ? 'Saved' : 'Issued');
    });
}

function ehrMemoClose(id) {
    HR.form({
        title: 'Close this memo',
        fields: [{ name: 'resolution', label: 'Resolution (optional)', max: 255, wide: true,
                   placeholder: 'e.g. Explanation accepted; no further action' }],
        confirmText: 'Close memo'
    }).then(function (v) { if (v) ehrSave('hr_memo_close', Object.assign({ id: id }, v), 'hrmemos', 'Closed'); });
}

// ── Trainings ───────────────────────────────────────────────────────
function ehrTraining(t) {
    HR.form({
        title: t ? 'Edit training' : 'Add training',
        values: t || {},
        fields: [
            { name: 'title', label: 'Training / seminar', max: 150, required: true, wide: true },
            { name: 'provider', label: 'Provider', max: 150 },
            { name: 'hours', label: 'Hours', type: 'number', step: '0.5', min: 0 },
            { name: 'start_date', label: 'Start date', type: 'date' },
            { name: 'end_date', label: 'End date', type: 'date' },
            { name: 'certificate_no', label: 'Certificate no.', max: 80 },
            { name: 'expiry_date', label: 'Certificate expiry', type: 'date' },
            { name: 'remarks', label: 'Remarks', max: 255, wide: true }
        ]
    }).then(function (v) { if (v) ehrSave('hr_training_save', Object.assign(t ? { id: t.id } : {}, v), 'hrtrain'); });
}

// ── Company property ────────────────────────────────────────────────
function ehrAsset(a) {
    HR.form({
        title: a ? 'Edit item' : 'Issue item',
        values: a || { issued_date: EHR_TODAY, issued_condition: 'Good' },
        fields: [
            { name: 'item', label: 'Item', max: 120, required: true, placeholder: 'e.g. Dell Latitude 5440' },
            { name: 'category', label: 'Category', type: 'select', options: EHR_LISTS.assetCats },
            { name: 'serial_no', label: 'Serial / tag no.', max: 80 },
            { name: 'issued_date', label: 'Issued date', type: 'date', required: true },
            { name: 'issued_condition', label: 'Condition when issued', type: 'select', options: EHR_LISTS.assetConds },
            { name: 'remarks', label: 'Remarks', max: 255 }
        ]
    }).then(function (v) { if (v) ehrSave('hr_asset_save', Object.assign(a ? { id: a.id } : {}, v), 'hrassets'); });
}

function ehrAssetReturn(a) {
    HR.form({
        title: 'Return: ' + a.item,
        values: { returned_date: EHR_TODAY, return_condition: 'Good' },
        fields: [
            { name: 'returned_date', label: 'Returned date', type: 'date', required: true },
            { name: 'return_condition', label: 'Condition', type: 'select', options: EHR_LISTS.assetConds, required: true },
            { name: 'remarks', label: 'Remarks', max: 255, wide: true }
        ],
        confirmText: 'Record return'
    }).then(function (v) { if (v) ehrSave('hr_asset_return', Object.assign({ id: a.id }, v), 'hrassets', 'Returned'); });
}
</script>
<?php endif; ?>
