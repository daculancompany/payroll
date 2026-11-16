<?php
/**
 * The portal's HR tab: announcements, memos to acknowledge, my documents,
 * certificate requests, trainings and company property.
 *
 * Data comes from component/portal_hr_data.php (already included). Every
 * write goes to hr-ajax.php as a my_* action, which takes the employee id
 * from the portal session — never from this page.
 */

$phm_types = HrManagement::requestTypes();

if (!function_exists('phm_e')) {
    function phm_e($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
    function phm_d($ymd) { return $ymd ? phm_e(date('M j, Y', strtotime($ymd))) : '—'; }
}
?>
<style>
.phm-wrap .info-section{margin-bottom:14px;}
.phm-body{padding:4px 16px 14px;}
.phm-empty{font-size:12.5px;color:var(--v2-text-3,#a19dad);padding:8px 0;}
.phm-chip{display:inline-block;font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:9px;text-transform:uppercase;letter-spacing:.3px;background:var(--v2-surface-3,#efedf3);color:var(--v2-text-2,#666);vertical-align:middle;}
.phm-chip.new{background:rgba(103,59,182,.13);color:var(--app-primary,#6642aa);}
.phm-chip.good{background:rgba(16,185,129,.14);color:#0f9d58;}
.phm-chip.warn{background:rgba(230,168,23,.16);color:#9a6700;}
.phm-chip.bad{background:rgba(198,40,40,.13);color:#c62828;}
.phm-ann{border-bottom:1px solid var(--v2-border,#f1eff5);padding:12px 0;}
.phm-ann:last-child{border-bottom:0;}
.phm-ann-t{font-weight:800;font-size:14px;color:var(--v2-text,#222);display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.phm-ann-m{font-size:11px;color:var(--v2-text-3,#999);margin-top:2px;}
.phm-ann-b{white-space:pre-wrap;font-size:13px;line-height:1.55;color:var(--v2-text-2,#444);margin-top:6px;}
.phm-ann.unread .phm-ann-t::before{content:'';width:8px;height:8px;border-radius:50%;background:var(--app-primary,#6642aa);display:inline-block;}
.phm-memo{border:1px solid var(--v2-border,#ece9f3);border-radius:12px;padding:12px 14px;margin:10px 0;}
.phm-memo.todo{border-color:rgba(230,168,23,.55);box-shadow:0 0 0 3px rgba(230,168,23,.08);}
.phm-memo-s{font-weight:800;font-size:14px;color:var(--v2-text,#222);margin-top:4px;}
.phm-memo-b{white-space:pre-wrap;font-size:13px;line-height:1.55;color:var(--v2-text-2,#444);margin-top:8px;}
.phm-resp{margin-top:10px;padding:9px 12px;border-left:3px solid var(--app-primary,#6642aa);background:var(--v2-surface-2,#f7f5fc);border-radius:0 8px 8px 0;font-size:12.5px;white-space:pre-wrap;color:var(--v2-text,#333);}
.phm-btn{display:inline-flex;align-items:center;gap:6px;border:0;border-radius:9px;padding:8px 14px;font-size:13px;font-weight:700;background:var(--app-primary,#6642aa);color:#fff;cursor:pointer;}
.phm-btn.ghost{background:transparent;color:var(--app-primary,#6642aa);border:1px solid var(--v2-border,#ddd6ec);}
.phm-btn.sm{padding:5px 10px;font-size:12px;}
.phm-btn:disabled{opacity:.6;cursor:default;}
.phm-row{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--v2-border,#f1eff5);font-size:13px;}
.phm-row:last-child{border-bottom:0;}
.phm-row .main{flex:1;min-width:0;}
.phm-row .t{font-weight:700;color:var(--v2-text,#222);overflow:hidden;text-overflow:ellipsis;}
.phm-row .s{font-size:11.5px;color:var(--v2-text-3,#999);}
.phm-row a.t{text-decoration:none;}
.phm-row a.t:hover{color:var(--app-primary,#6642aa);}
.phm-head-act{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 0 4px;}
.phm-note{font-size:11.5px;color:var(--v2-text-3,#999);}
</style>

<div class="phm-wrap">
    <div class="sec"><i class="ri-user-heart-line"></i>HR</div>

    <?php if (!$phm_has): ?>
        <div class="info-section"><div class="phm-body"><div class="phm-empty">Nothing here yet.</div></div></div>
    <?php else: ?>

    <!-- ── Announcements ─────────────────────────────────────── -->
    <div class="info-section" id="phm-ann">
        <div class="info-sec-title"><i class="ri-megaphone-line"></i> Announcements
            <?php if ($phm_unread): ?><span class="phm-chip new ms-1"><?= $phm_unread ?> new</span><?php endif; ?></div>
        <div class="phm-body">
            <?php if (!$phm_ann): ?><div class="phm-empty">No announcements right now.</div><?php endif; ?>
            <?php foreach ($phm_ann as $a): ?>
            <div class="phm-ann <?= (int) $a['is_read'] ? '' : 'unread' ?>" data-ann="<?= (int) $a['id'] ?>">
                <div class="phm-ann-t"><?= phm_e($a['title']) ?><?= (int) $a['pinned'] ? ' <span class="phm-chip">Pinned</span>' : '' ?></div>
                <div class="phm-ann-m"><?= phm_e(date('M j, Y', strtotime($a['publish_at']))) ?><?= $a['department_name'] ? ' · ' . phm_e($a['department_name']) : '' ?></div>
                <div class="phm-ann-b"><?= phm_e($a['body']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Memos ─────────────────────────────────────────────── -->
    <div class="info-section" id="phm-memos">
        <div class="info-sec-title"><i class="ri-file-text-line"></i> Memos
            <?php if ($phm_unack): ?><span class="phm-chip warn ms-1"><?= $phm_unack ?> to acknowledge</span><?php endif; ?></div>
        <div class="phm-body">
            <?php if (!$phm_memo): ?><div class="phm-empty">You have no memos.</div><?php endif; ?>
            <?php foreach ($phm_memo as $m):
                $todo  = (int) $m['requires_ack'] === 1 && !$m['acknowledged_at'] && $m['status'] === 'open';
                $isNte = $m['memo_type'] === 'Notice to Explain';
                $tone  = $m['memo_type'] === 'Commendation' ? 'good' : (in_array($m['memo_type'], ['Final Warning', 'Suspension'], true) ? 'bad' : 'warn');
            ?>
            <div class="phm-memo <?= $todo ? 'todo' : '' ?>">
                <span class="phm-chip <?= $tone ?>"><?= phm_e($m['memo_type']) ?></span>
                <?php if ($m['status'] === 'closed'): ?><span class="phm-chip">Closed</span>
                <?php elseif ($m['acknowledged_at']): ?><span class="phm-chip good">Acknowledged <?= phm_e(date('M j', strtotime($m['acknowledged_at']))) ?></span><?php endif; ?>
                <div class="phm-memo-s"><?= phm_e($m['subject']) ?></div>
                <div class="phm-ann-m">Issued <?= phm_d($m['issued_date']) ?><?= $m['respond_by'] ? ' · please respond by ' . phm_d($m['respond_by']) : '' ?></div>
                <div class="phm-memo-b"><?= phm_e($m['body']) ?></div>
                <?php if ($m['employee_response']): ?>
                <div class="phm-resp"><b>Your explanation</b> · <?= phm_e(date('M j, Y g:i A', strtotime($m['responded_at']))) ?><br><?= phm_e($m['employee_response']) ?></div>
                <?php endif; ?>
                <?php if ($m['status'] === 'closed' && $m['resolution']): ?>
                <div class="phm-ann-m mt-2"><i class="ri-checkbox-circle-line"></i> <?= phm_e($m['resolution']) ?></div>
                <?php endif; ?>
                <?php if ($m['status'] === 'open' && ($todo || ($isNte && !$m['employee_response']))): ?>
                <div class="mt-2 d-flex gap-2 flex-wrap">
                    <?php if ($isNte): ?>
                    <button class="phm-btn" onclick="phmExplain(<?= (int) $m['id'] ?>, <?= phm_e(json_encode($m['subject'])) ?>)"><i class="ri-quill-pen-line"></i> Write my explanation</button>
                    <?php else: ?>
                    <button class="phm-btn" onclick="phmAck(<?= (int) $m['id'] ?>)"><i class="ri-check-line"></i> I have read this</button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Certificate requests ──────────────────────────────── -->
    <div class="info-section" id="phm-req">
        <div class="info-sec-title"><i class="ri-file-paper-2-line"></i> Certificates</div>
        <div class="phm-body">
            <div class="phm-head-act">
                <span class="phm-note">Ask HR for a Certificate of Employment. You can open it here once HR releases it.</span>
                <button class="phm-btn sm" onclick="phmRequest()"><i class="ri-add-line"></i> Request</button>
            </div>
            <?php if (!$phm_req): ?><div class="phm-empty">No requests yet.</div><?php endif; ?>
            <?php foreach ($phm_req as $r):
                $chip = ['pending' => 'warn', 'released' => 'good', 'declined' => 'bad', 'cancelled' => ''][$r['status']] ?? '';
            ?>
            <div class="phm-row">
                <div class="main">
                    <div class="t"><?= phm_e($phm_types[$r['request_type']] ?? $r['request_type']) ?></div>
                    <div class="s"><?= phm_e($r['purpose']) ?> · <?= phm_e(date('M j, Y', strtotime($r['created_at']))) ?><?= $r['hr_remarks'] ? ' · HR: ' . phm_e($r['hr_remarks']) : '' ?></div>
                </div>
                <span class="phm-chip <?= $chip ?>"><?= phm_e(ucfirst($r['status'])) ?></span>
                <?php if ($r['status'] === 'released'): ?>
                    <a class="phm-btn sm" href="hr-certificate.php?request_id=<?= (int) $r['id'] ?>" target="_blank" rel="noopener"><i class="ri-file-pdf-2-line"></i> Open</a>
                <?php elseif ($r['status'] === 'pending'): ?>
                    <button class="phm-btn ghost sm" onclick="phmCancelRequest(<?= (int) $r['id'] ?>)">Cancel</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── My documents ──────────────────────────────────────── -->
    <div class="info-section" id="phm-docs">
        <div class="info-sec-title"><i class="ri-folder-shield-2-line"></i> My Documents</div>
        <div class="phm-body">
            <div class="phm-head-act">
                <span class="phm-note">Files on your 201 record. Upload a new ID, clearance or certificate and HR will check it.</span>
                <button class="phm-btn sm" onclick="phmUpload()"><i class="ri-upload-2-line"></i> Upload</button>
            </div>
            <?php if (!$phm_docs): ?><div class="phm-empty">No documents yet.</div><?php endif; ?>
            <?php foreach ($phm_docs as $d):
                $exp = $d['expiry_date'] ? (int) floor((strtotime($d['expiry_date']) - strtotime(date('Y-m-d'))) / 86400) : null;
            ?>
            <div class="phm-row">
                <i class="<?= $d['mime'] === 'application/pdf' ? 'ri-file-pdf-2-line' : 'ri-image-line' ?>" style="font-size:20px;color:var(--app-primary,#6642aa);"></i>
                <div class="main">
                    <a class="t d-block" href="hr-file.php?kind=doc&amp;id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener"><?= phm_e($d['title']) ?></a>
                    <div class="s"><?= phm_e($d['category']) ?><?= $d['expiry_date'] ? ' · expires ' . phm_d($d['expiry_date']) : '' ?></div>
                </div>
                <?php if ($exp !== null && $exp < 0): ?><span class="phm-chip bad">Expired</span>
                <?php elseif ($exp !== null && $exp <= 60): ?><span class="phm-chip warn">Expiring</span><?php endif; ?>
                <?php if ($d['source'] === 'employee'): ?>
                    <?php if ($d['verified_at']): ?><span class="phm-chip good">Verified</span>
                    <?php else: ?><span class="phm-chip">Waiting for HR</span>
                        <button class="phm-btn ghost sm" title="Remove" onclick="phmDeleteDoc(<?= (int) $d['id'] ?>)"><i class="ri-delete-bin-line"></i></button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Trainings ─────────────────────────────────────────── -->
    <?php if ($phm_trn): ?>
    <div class="info-section">
        <div class="info-sec-title"><i class="ri-graduation-cap-line"></i> Trainings</div>
        <div class="phm-body">
            <?php foreach ($phm_trn as $t):
                $exp = $t['expiry_date'] ? (int) floor((strtotime($t['expiry_date']) - strtotime(date('Y-m-d'))) / 86400) : null;
            ?>
            <div class="phm-row">
                <div class="main">
                    <div class="t"><?= phm_e($t['title']) ?></div>
                    <div class="s"><?= phm_e(trim(($t['provider'] ?: '') . ($t['start_date'] ? ' · ' . date('M j, Y', strtotime($t['start_date'])) : '') . ($t['hours'] !== null ? ' · ' . rtrim(rtrim(number_format((float) $t['hours'], 2), '0'), '.') . ' hrs' : ''), ' ·')) ?></div>
                </div>
                <?php if ($t['expiry_date']): ?><span class="phm-chip <?= $exp < 0 ? 'bad' : ($exp <= 60 ? 'warn' : '') ?>"><?= $exp < 0 ? 'Expired' : 'Until ' . phm_e(date('M Y', strtotime($t['expiry_date']))) ?></span><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Company property ──────────────────────────────────── -->
    <?php if ($phm_ast): ?>
    <div class="info-section">
        <div class="info-sec-title"><i class="ri-archive-drawer-line"></i> Company Property</div>
        <div class="phm-body">
            <?php foreach ($phm_ast as $a): ?>
            <div class="phm-row">
                <div class="main">
                    <div class="t"><?= phm_e($a['item']) ?></div>
                    <div class="s"><?= phm_e(trim(($a['serial_no'] ? $a['serial_no'] . ' · ' : '') . 'issued ' . date('M j, Y', strtotime($a['issued_date'])))) ?></div>
                </div>
                <?= $a['returned_date'] ? '<span class="phm-chip good">Returned</span>' : '<span class="phm-chip">With you</span>' ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php if ($phm_has): ?>
<script src="<?= function_exists('av') ? av('assets2/js/hr-modules.js') : 'assets2/js/hr-modules.js' ?>"></script>
<script>
(function () {
    var REQ_TYPES = <?= json_encode(array_map(function ($k, $v) { return ['value' => $k, 'label' => $v]; }, array_keys($phm_types), $phm_types)) ?>;
    var DOC_CATS  = <?= json_encode(HrManagement::selfDocCategories()) ?>;

    // Reopen the HR tab after the reload an action triggers.
    function reloadHere() {
        try { sessionStorage.setItem('phm-open', '1'); } catch (e) {}
        location.reload();
    }
    function done(j, okTitle) {
        if (!j || !j.result) {
            Swal.fire({ icon: 'error', title: 'Not sent', text: (j && j.message) || 'Please try again.' });
            return;
        }
        Swal.fire({ icon: 'success', title: okTitle || 'Done', text: j.message || '', timer: 1600, showConfirmButton: false }).then(reloadHere);
    }

    window.phmAck = function (id) {
        HR.confirm({ icon: 'question', title: 'Acknowledge this memo?', text: 'HR will see that you have read it.', confirmText: 'I have read it' })
            .then(function (ok) { if (ok) HR.post('my_memo_ack', { id: id }).then(function (j) { done(j, 'Acknowledged'); }); });
    };

    window.phmExplain = function (id, subject) {
        HR.form({
            title: 'Your explanation',
            fields: [
                { name: 'n', type: 'note', html: 'Re: <b>' + HR.esc(subject) + '</b>. Once sent, your explanation becomes part of your record and cannot be edited.' },
                { name: 'response', label: 'Explanation', type: 'textarea', rows: 8, max: 5000, required: true }
            ],
            confirmText: 'Send to HR'
        }).then(function (v) { if (v) HR.post('my_memo_ack', { id: id, response: v.response }).then(function (j) { done(j, 'Sent'); }); });
    };

    window.phmRequest = function () {
        HR.form({
            title: 'Request a certificate',
            values: { request_type: 'coe' },
            fields: [
                { name: 'request_type', label: 'Certificate', type: 'select', options: REQ_TYPES, required: true, wide: true },
                { name: 'purpose', label: 'Purpose', max: 190, required: true, wide: true, placeholder: 'e.g. bank loan, visa application' }
            ],
            confirmText: 'Send request'
        }).then(function (v) { if (v) HR.post('my_request_create', v).then(function (j) { done(j, 'Requested'); }); });
    };

    window.phmCancelRequest = function (id) {
        HR.confirm({ title: 'Cancel this request?', confirmText: 'Cancel request', danger: true })
            .then(function (ok) { if (ok) HR.post('my_request_cancel', { id: id }).then(function (j) { done(j, 'Cancelled'); }); });
    };

    window.phmUpload = function () {
        HR.form({
            title: 'Upload a document',
            fields: [
                { name: 'file', label: 'File (PDF or photo · max 10 MB)', type: 'file', required: true, wide: true,
                  accept: 'application/pdf,image/jpeg,image/png,image/webp', maxBytes: 10485760 },
                { name: 'category', label: 'What is it?', type: 'select', options: DOC_CATS, required: true },
                { name: 'expiry_date', label: 'Expiry date (if any)', type: 'date' },
                { name: 'title', label: 'Title (optional)', max: 150, wide: true }
            ],
            confirmText: 'Upload'
        }).then(function (v) { if (v) HR.post('my_doc_upload', v).then(function (j) { done(j, 'Uploaded'); }); });
    };

    window.phmDeleteDoc = function (id) {
        HR.confirm({ title: 'Remove this file?', text: 'HR has not checked it yet, so you can still take it back.', confirmText: 'Remove', danger: true })
            .then(function (ok) { if (ok) HR.post('my_doc_delete', { id: id }).then(function (j) { done(j, 'Removed'); }); });
    };

    // Opening the HR tab marks the announcements on it as read — the
    // employee has now seen them. Watched on the panel itself (not by
    // wrapping switchTab, which the portal defines further down the page).
    var marked = false;
    function markRead(panel) {
        if (marked || !panel.classList.contains('active')) return;
        marked = true;
        panel.querySelectorAll('.phm-ann.unread').forEach(function (el) {
            HR.post('my_announcement_read', { id: el.getAttribute('data-ann') }).then(function (j) {
                if (j && j.result) el.classList.remove('unread');
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var panel = document.getElementById('tab-hr');
        if (!panel) return;
        new MutationObserver(function () { markRead(panel); }).observe(panel, { attributes: true, attributeFilter: ['class'] });

        var reopen = false;
        try { reopen = sessionStorage.getItem('phm-open') === '1'; sessionStorage.removeItem('phm-open'); } catch (e) {}
        if ((reopen || location.hash === '#hr') && typeof window.switchTab === 'function') window.switchTab('hr', null);
    });
})();
</script>
<?php endif; ?>
