<?php
// Announcements — HR posts news to the employee portal, for everyone or one
// department. Included via index.php / app.php (?page=hr-announcements).
// Admin + HR. Writes go through hr-ajax.php (hr_announcement_*).

require_once __DIR__ . '/hr_class.php';

$han      = new HrManagement($conn);
$han_has  = $han->tableExists('hr_announcements');
$han_rows = $han->announcementsAdmin();
$han_can  = can_edit('employee-201');
$han_now  = date('Y-m-d H:i:s');

$han_depts = [];
$q = $conn->query("SELECT id, name FROM department ORDER BY name");
while ($q && ($r = $q->fetch_assoc())) $han_depts[] = ['value' => (int) $r['id'], 'label' => $r['name']];

if (!function_exists('han_e')) {
    function han_e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
?>
<style>
.han-item{border:1px solid var(--v2-border,#ece9f3);border-radius:12px;padding:12px 14px;margin-bottom:10px;background:var(--v2-surface,#fff);}
.han-item.pinned{border-left:3px solid var(--app-primary,#673bb6);}
.han-item.off{opacity:.7;}
.han-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap;}
.han-title{font-weight:800;font-size:14.5px;color:var(--v2-text,#2b2639);}
.han-meta{font-size:11.5px;color:var(--v2-text-3,#918d9c);margin-top:2px;}
.han-body{white-space:pre-wrap;font-size:12.8px;line-height:1.55;color:var(--v2-text-2,#4b4660);margin-top:8px;max-height:6em;overflow:hidden;}
.han-chip{display:inline-block;font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:9px;text-transform:uppercase;letter-spacing:.3px;background:var(--v2-surface-3,#efedf3);color:var(--v2-text-2,#666);margin-right:4px;}
.han-chip.live{background:rgba(16,185,129,.14);color:#0f9d58;}
.han-chip.sched{background:rgba(59,130,246,.13);color:#2563eb;}
.han-chip.pin{background:rgba(103,59,182,.12);color:var(--app-primary,#673bb6);}
.han-reads{font-size:12px;color:var(--v2-text-2,#6f6a80);margin-top:8px;}
.han-reads b{color:var(--v2-text,#2b2639);}
.han-empty{font-size:12.5px;color:var(--v2-text-2,#908c9c);padding:14px 0;}
</style>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <div class="row mb-3"><div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
            <h4 class="mb-sm-0"><i class="ri-megaphone-line me-2" style="color:var(--app-primary,#673bb6);"></i>Announcements</h4>
            <ol class="breadcrumb m-0"><li class="breadcrumb-item">HR</li><li class="breadcrumb-item active">Announcements</li></ol>
        </div>
    </div></div>

    <?php if (!$han_has): ?>
    <div class="alert alert-warning"><i class="ri-error-warning-line me-1"></i>The HR tables are missing. Run <code>migrations/2026_11_hr_modules.sql</code> on this database.</div>
    <?php else: ?>

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <span class="text-muted small"><i class="ri-information-line me-1"></i>Employees see live announcements in the portal's HR tab. Notifying is optional and happens once per announcement.</span>
        <?php if ($han_can): ?>
        <button class="btn btn-primary btn-sm" onclick="hanEdit()"><i class="ri-add-line me-1"></i>New announcement</button>
        <?php endif; ?>
    </div>

    <?php if (!$han_rows): ?>
        <div class="han-empty"><i class="ri-information-line me-1"></i>No announcements yet.</div>
    <?php endif; ?>

    <?php foreach ($han_rows as $a):
        $live   = $a['publish_at'] <= $han_now && (!$a['expires_at'] || $a['expires_at'] > $han_now);
        $sched  = $a['publish_at'] > $han_now;
        $state  = $live ? '<span class="han-chip live">Live</span>' : ($sched ? '<span class="han-chip sched">Scheduled</span>' : '<span class="han-chip">Expired</span>');
        $aud    = (int) $a['audience'];
        $reads  = (int) $a['read_count'];
    ?>
    <div class="han-item <?= (int) $a['pinned'] ? 'pinned' : '' ?> <?= $live || $sched ? '' : 'off' ?>">
        <div class="han-top">
            <div>
                <?= $state ?><?= (int) $a['pinned'] ? '<span class="han-chip pin">Pinned</span>' : '' ?>
                <span class="han-chip"><?= han_e($a['department_name'] ?: 'Everyone') ?></span>
                <div class="han-title mt-1"><?= han_e($a['title']) ?></div>
                <div class="han-meta">
                    <?= $sched ? 'Shows from ' : 'Posted ' ?><?= han_e(date('M j, Y g:i A', strtotime($a['publish_at']))) ?>
                    <?= $a['expires_at'] ? ' · until ' . han_e(date('M j, Y g:i A', strtotime($a['expires_at']))) : '' ?>
                    <?= $a['created_by_name'] ? ' · by ' . han_e($a['created_by_name']) : '' ?>
                    <?= $a['notified_at'] ? ' · notified ' . han_e(date('M j, g:i A', strtotime($a['notified_at']))) : '' ?>
                </div>
            </div>
            <?php if ($han_can): ?>
            <div class="text-nowrap">
                <button class="btn btn-outline-secondary btn-sm py-0" onclick="hanEdit(<?= han_e(json_encode($a, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>)"><i class="ri-edit-line"></i> Edit</button>
                <button class="btn btn-outline-danger btn-sm py-0" onclick="hanDelete(<?= (int) $a['id'] ?>, <?= han_e(json_encode($a['title'], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>)"><i class="ri-delete-bin-line"></i></button>
            </div>
            <?php endif; ?>
        </div>
        <div class="han-body"><?= han_e($a['body']) ?></div>
        <div class="han-reads"><i class="ri-eye-line me-1"></i><b><?= $reads ?></b> of <?= $aud ?> employee<?= $aud === 1 ? '' : 's' ?> opened it<?= $aud > 0 ? ' (' . round($reads / $aud * 100) . '%)' : '' ?></div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
</div>
</div>
</div>

<?php if ($han_has && $han_can): ?>
<script src="assets2/js/hr-modules.js?v=2"></script>
<script>
var HAN_DEPTS = <?= json_encode($han_depts, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

function hanLocal(dt) { return dt ? String(dt).replace(' ', 'T').slice(0, 16) : ''; }

function hanEdit(a) {
    var isNew = !a;
    HR.form({
        title: isNew ? 'New announcement' : 'Edit announcement',
        width: 720,
        values: a ? Object.assign({}, a, { publish_at: hanLocal(a.publish_at), expires_at: hanLocal(a.expires_at), department_id: a.department_id || 0 }) : { department_id: 0 },
        fields: [
            { name: 'title', label: 'Title', max: 150, required: true, wide: true },
            { name: 'body', label: 'Message', type: 'textarea', rows: 8, required: true },
            { name: 'department_id', label: 'Who sees it', type: 'select', options: [{ value: 0, label: 'Everyone' }].concat(HAN_DEPTS), required: true },
            { name: 'pinned', label: 'Pin to the top', type: 'checkbox' },
            { name: 'publish_at', label: 'Show from', type: 'datetime-local', help: 'Leave empty to show it now.' },
            { name: 'expires_at', label: 'Hide after', type: 'datetime-local', help: 'Leave empty to keep it up.' },
            (a && a.notified_at)
                ? { name: 'x', type: 'note', html: 'Employees were already notified about this announcement.' }
                : { name: 'notify', label: 'Notify employees now (portal bell + phone notification)', type: 'checkbox',
                    help: 'Sent once. Leave unticked to post quietly.' }
        ]
    }).then(function (v) {
        if (!v) return;
        if (!isNew) v.id = a.id;
        HR.post('hr_announcement_save', v).then(function (j) { HR.done(j, isNew ? 'Posted' : 'Saved', { timer: 2200 }); });
    });
}

function hanDelete(id, title) {
    HR.confirm({ title: 'Delete "' + title + '"?', text: 'Employees will no longer see it.', confirmText: 'Delete', danger: true })
        .then(function (ok) { if (ok) HR.post('hr_announcement_delete', { id: id }).then(function (j) { HR.done(j, 'Deleted'); }); });
}
</script>
<?php endif; ?>
