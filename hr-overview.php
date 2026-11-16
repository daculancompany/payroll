<?php
// HR Overview — what HR should act on (reminders) and what the workforce
// looks like (reports). Included via index.php / app.php (?page=hr-overview).
// Admin + HR (PAGE_ROLE_RESTRICTIONS). Read-only: every number links to the
// employee record where it can be acted on.

require_once __DIR__ . '/includes/hr/insights.php';

$hro_days = (int) ($_GET['days'] ?? 60);
if (!in_array($hro_days, [30, 60, 90], true)) $hro_days = 60;

$hro_alerts  = hr_alerts($conn, $hro_days);
$hro_reports = hr_reports($conn);
$hro_action  = hr_alert_action_count($hro_alerts);
$hro_byKey   = array_column($hro_alerts, null, 'key');
$hro_has     = hr_table_exists($conn, 'hr_documents');

/** Which Employee Details tab an alert opens on. */
$hro_tab = [
    'prc' => 'arrow-201', 'hmo' => 'arrow-201', 'regularization' => 'arrow-hrhistory',
    'anniversaries' => 'arrow-201', 'birthdays' => 'arrow-overview', 'documents' => 'arrow-hrdocs',
    'doc_review' => 'arrow-hrdocs', 'certificates' => 'arrow-hrtrain', 'memos' => 'arrow-hrmemos',
    'requests' => 'arrow-201', 'clearances' => 'arrow-hrhistory',
];

if (!function_exists('hro_e')) {
    function hro_e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

    function hro_when($days)
    {
        $days = (int) $days;
        if ($days === 0) return 'today';
        if ($days === 1) return 'tomorrow';
        if ($days === -1) return 'yesterday';
        return $days > 0 ? 'in ' . $days . ' days' : abs($days) . ' days ago';
    }

    /** One labelled bar list: a single measure (headcount), one hue, value printed. */
    function hro_bars($title, $icon, array $rows, $total)
    {
        $max = 0;
        foreach ($rows as $r) $max = max($max, (int) $r['n']);
        echo '<div class="card rpt-card hro-card"><div class="card-header py-2"><span class="fw-bold"><i class="' . $icon . ' me-1"></i>' . hro_e($title) . '</span></div>';
        echo '<div class="card-body py-2">';
        if (!$rows) {
            echo '<div class="hro-empty">No data yet.</div>';
        }
        foreach ($rows as $r) {
            $n   = (int) $r['n'];
            $pct = $max > 0 ? max(2, round($n / $max * 100)) : 0;
            $share = $total > 0 ? round($n / $total * 100) : 0;
            $unset = $r['label'] === 'Not set' || $r['label'] === 'No department';
            echo '<div class="hro-bar-row" title="' . hro_e($r['label'] . ': ' . $n . ' (' . $share . '%)') . '">'
               . '<div class="hro-bar-lbl' . ($unset ? ' unset' : '') . '">' . hro_e($r['label']) . '</div>'
               . '<div class="hro-bar-track"><div class="hro-bar' . ($unset ? ' unset' : '') . '" style="width:' . $pct . '%"></div></div>'
               . '<div class="hro-bar-val">' . $n . '<span>' . $share . '%</span></div></div>';
        }
        echo '</div></div>';
    }
}

$hro_active = $hro_reports['active'];
$hro_fill   = $hro_active > 0 ? round($hro_reports['profile_fill'] / $hro_active * 100) : 0;
$hro_req    = count($hro_byKey['requests']['items'] ?? []);
$hro_clr    = count($hro_byKey['clearances']['items'] ?? []);
?>
<style>
.hro-stat{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
.hro-stat a.s,.hro-stat .s{flex:1 1 160px;background:var(--v2-surface,#fff);border:1px solid var(--v2-border,#ece9f3);border-left:3px solid var(--app-primary,#673bb6);border-radius:10px;padding:10px 14px;text-decoration:none;color:inherit;}
.hro-stat .v{font-size:22px;font-weight:800;color:var(--v2-text,#2b2639);line-height:1.1;}
.hro-stat .l{font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--v2-text-3,#999);font-weight:700;margin-top:2px;}
.hro-stat .s.warn{border-left-color:#e6a817;}
.hro-stat .s.info{border-left-color:#3b82f6;}
.hro-stat .sub{font-size:11px;color:var(--v2-text-3,#918d9c);margin-top:2px;}
.hro-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:6px 0 10px;}
.hro-head h5{margin:0;font-weight:800;font-size:15px;color:var(--v2-text,#2b2639);}
.hro-range{display:inline-flex;border:1px solid var(--v2-border,#e2deeb);border-radius:8px;overflow:hidden;}
.hro-range a{padding:4px 11px;font-size:12px;font-weight:700;color:var(--v2-text-2,#6f6a80);text-decoration:none;border-right:1px solid var(--v2-border,#e2deeb);}
.hro-range a:last-child{border-right:0;}
.hro-range a.on{background:var(--app-primary,#673bb6);color:#fff;}
.hro-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px;margin-bottom:18px;}
.hro-card{margin-bottom:0;height:100%;}
.hro-alert-list{list-style:none;margin:0;padding:0;max-height:260px;overflow-y:auto;}
.hro-alert-list li{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--v2-border,#f1eff5);font-size:12.5px;}
.hro-alert-list li:last-child{border-bottom:0;}
.hro-alert-list .nm{font-weight:700;color:var(--v2-text,#2b2639);text-decoration:none;}
.hro-alert-list .nm:hover{color:var(--app-primary,#673bb6);}
.hro-alert-list .dt{font-size:11px;color:var(--v2-text-3,#918d9c);}
.hro-alert-list .when{margin-left:auto;font-size:11px;font-weight:700;white-space:nowrap;color:var(--v2-text-2,#6f6a80);}
.hro-alert-list .when.past{color:#c62828;}
.hro-count{display:inline-block;min-width:22px;text-align:center;font-size:11px;font-weight:800;padding:1px 7px;border-radius:9px;background:var(--v2-surface-3,#efedf3);color:var(--v2-text-2,#555);margin-left:6px;}
.hro-tone-warn .card-header i{color:#b07800;} .hro-tone-info .card-header i{color:#2563eb;} .hro-tone-good .card-header i{color:#0f9d58;}
.hro-empty{font-size:12.5px;color:var(--v2-text-3,#a19dad);padding:6px 0;}
.hro-ok{font-size:12.5px;color:var(--v2-text-2,#6f6a80);padding:12px 14px;border:1px dashed var(--v2-border,#e2deeb);border-radius:10px;margin-bottom:18px;}
.hro-bar-row{display:grid;grid-template-columns:minmax(90px,38%) 1fr 64px;align-items:center;gap:10px;padding:4px 0;font-size:12.5px;}
.hro-bar-lbl{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--v2-text,#2b2639);}
.hro-bar-lbl.unset{color:var(--v2-text-3,#a19dad);font-style:italic;}
.hro-bar-track{height:10px;background:var(--v2-surface-2,#f4f2f8);border-radius:5px;overflow:hidden;}
.hro-bar{height:100%;background:var(--app-primary,#673bb6);border-radius:0 4px 4px 0;}
.hro-bar.unset{background:var(--v2-text-3,#b9b5c4);opacity:.55;}
.hro-bar-val{text-align:right;font-weight:800;color:var(--v2-text,#2b2639);}
.hro-bar-val span{font-weight:600;font-size:10.5px;color:var(--v2-text-3,#918d9c);margin-left:5px;}
.hro-month td,.hro-month th{font-size:12px;padding:4px 8px !important;}
.hro-mini{display:inline-block;height:8px;border-radius:0 3px 3px 0;vertical-align:middle;margin-right:6px;background:var(--app-primary,#673bb6);}
.hro-mini.sep{background:var(--v2-text-2,#6f6a80);}
.hro-note{font-size:12px;color:var(--v2-text-2,#6f6a80);background:var(--v2-surface-2,#f7f5fc);border:1px solid var(--v2-border,#e9e4f5);border-radius:10px;padding:9px 13px;margin-bottom:14px;line-height:1.55;}
</style>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <div class="row mb-3"><div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
            <h4 class="mb-sm-0"><i class="ri-user-heart-line me-2" style="color:var(--app-primary,#673bb6);"></i>HR Overview</h4>
            <ol class="breadcrumb m-0"><li class="breadcrumb-item">HR</li><li class="breadcrumb-item active">Overview</li></ol>
        </div>
    </div></div>

    <?php if (!$hro_has): ?>
    <div class="alert alert-warning"><i class="ri-error-warning-line me-1"></i>The HR tables are missing. Run <code>migrations/2026_11_hr_modules.sql</code> on this database.</div>
    <?php endif; ?>

    <div class="hro-stat">
        <div class="s"><div class="v"><?= $hro_active ?></div><div class="l">Active employees</div></div>
        <div class="s <?= $hro_action ? 'warn' : '' ?>"><div class="v"><?= $hro_action ?></div><div class="l">Reminders to act on</div><div class="sub">next <?= $hro_days ?> days</div></div>
        <div class="s info"><div class="v"><?= $hro_fill ?>%</div><div class="l">201 files with a hire date</div><div class="sub"><?= $hro_reports['profile_fill'] ?> of <?= $hro_active ?></div></div>
        <a class="s" href="index.php?page=hr-requests"><div class="v"><?= $hro_req ?></div><div class="l">Document requests waiting</div></a>
        <a class="s" href="index.php?page=hr-offboarding"><div class="v"><?= $hro_clr ?></div><div class="l">Open clearances</div></a>
    </div>

    <?php if ($hro_fill < 50 && $hro_active > 0): ?>
    <div class="hro-note"><i class="ri-information-line me-1"></i>
        Most reminders and reports read the <b>201 file</b> (hire date, employment status, PRC, HMO, sex).
        Only <?= $hro_reports['profile_fill'] ?> of <?= $hro_active ?> active employees have a hire date there yet, so expect "Not set" below until the 201 files are filled in.
    </div>
    <?php endif; ?>

    <!-- ── Reminders ─────────────────────────────────────────────── -->
    <div class="hro-head">
        <h5><i class="ri-alarm-warning-line me-1"></i>Reminders</h5>
        <div class="hro-range" role="group" aria-label="Look ahead">
            <?php foreach ([30, 60, 90] as $d): ?>
            <a href="index.php?page=hr-overview&amp;days=<?= $d ?>" class="<?= $d === $hro_days ? 'on' : '' ?>">Next <?= $d ?> days</a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php $hro_shown = array_filter($hro_alerts, function ($g) { return $g['items']; }); ?>
    <?php if (!$hro_shown): ?>
        <div class="hro-ok"><i class="ri-checkbox-circle-line me-1" style="color:#0f9d58;"></i>Nothing to act on in the next <?= $hro_days ?> days.</div>
    <?php else: ?>
    <div class="hro-grid">
        <?php foreach ($hro_shown as $g): ?>
        <div class="card rpt-card hro-card hro-tone-<?= hro_e($g['tone']) ?>">
            <div class="card-header py-2 d-flex align-items-center justify-content-between">
                <span class="fw-bold"><i class="<?= hro_e($g['icon']) ?> me-1"></i><?= hro_e($g['title']) ?><span class="hro-count"><?= count($g['items']) ?></span></span>
                <?php if (!empty($g['link'])): ?><a href="<?= hro_e($g['link']) ?>" class="small">Open</a><?php endif; ?>
            </div>
            <div class="card-body py-1">
                <ul class="hro-alert-list">
                <?php foreach ($g['items'] as $it): ?>
                    <li>
                        <div>
                            <a class="nm" href="index.php?page=employee-details&amp;id=<?= (int) $it['employee_id'] ?>#<?= $hro_tab[$g['key']] ?? 'arrow-overview' ?>"><?= hro_e($it['name']) ?></a>
                            <div class="dt"><?= hro_e($it['detail']) ?><?= $it['date'] ? ' · ' . hro_e(date('M j, Y', strtotime($it['date']))) : '' ?></div>
                        </div>
                        <span class="when <?= (int) $it['days'] < 0 && $g['tone'] !== 'info' ? 'past' : '' ?>"><?= hro_e(hro_when($it['days'])) ?></span>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Workforce ─────────────────────────────────────────────── -->
    <div class="hro-head"><h5><i class="ri-bar-chart-horizontal-line me-1"></i>Workforce · active employees</h5></div>
    <div class="hro-grid">
        <?php hro_bars('By department', 'ri-building-line', $hro_reports['by_department'], $hro_active); ?>
        <?php hro_bars('By employment status', 'ri-briefcase-4-line', $hro_reports['by_status'], $hro_active); ?>
        <?php hro_bars('By tenure', 'ri-time-line', $hro_reports['by_tenure'], $hro_active); ?>
        <?php hro_bars('By age', 'ri-user-3-line', $hro_reports['by_age'], $hro_active); ?>
        <?php hro_bars('By sex', 'ri-genderless-line', $hro_reports['by_sex'], $hro_active); ?>

        <div class="card rpt-card hro-card">
            <div class="card-header py-2"><span class="fw-bold"><i class="ri-arrow-left-right-line me-1"></i>Hires and separations · last 12 months</span></div>
            <div class="card-body p-0">
                <?php
                $hro_mmax = 1;
                foreach ($hro_reports['monthly'] as $m) $hro_mmax = max($hro_mmax, $m['hires'], $m['separations']);
                ?>
                <table class="table table-sm mb-0 hro-month">
                    <thead class="table-light"><tr><th>Month</th><th>Hires</th><th>Separations</th></tr></thead>
                    <tbody>
                    <?php if (!$hro_reports['monthly']): ?>
                        <tr><td colspan="3" class="hro-empty">No data yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach (array_reverse($hro_reports['monthly']) as $m): ?>
                        <tr>
                            <td><?= hro_e($m['label']) ?></td>
                            <td><span class="hro-mini" style="width:<?= round($m['hires'] / $hro_mmax * 70) ?>px"></span><?= $m['hires'] ?></td>
                            <td><span class="hro-mini sep" style="width:<?= round($m['separations'] / $hro_mmax * 70) ?>px"></span><?= $m['separations'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
</div>
</div>
