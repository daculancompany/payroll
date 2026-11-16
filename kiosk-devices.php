<?php
// Kiosk Devices — the tablets running the UareUApp kiosk. Included via
// index.php / app.php (?page=kiosk-devices). Admin only (ADMIN_ONLY_PAGES).
//
// Installing a kiosk needs NO approval: a tablet signs in with a timekeeper
// account and works at once, and lists itself here the first time it reports
// in. This screen is for afterwards — is that tablet still alive, is its
// offline queue draining, what version is it on — and for shutting one out
// when it is lost or retired.
//
// Everything here is reported BY the tablet (kiosk-api.php heartbeat), except
// the name, status and note, which an administrator sets.

require_once __DIR__ . '/includes/kiosk_admin.php';

$kd_has_table = $conn->query("SHOW TABLES LIKE 'kiosk_devices'");
$kd_has_table = $kd_has_table && $kd_has_table->num_rows > 0;

// A kiosk beats every 5 minutes (HEARTBEAT_INTERVAL_MS in the app), so a
// tablet unheard-of for 12 is genuinely not reporting, not just between beats.
const KD_ONLINE_MINUTES = 12;

$kd_rows = [];
$kd_stat = ['total' => 0, 'online' => 0, 'blocked' => 0, 'queued' => 0];
if ($kd_has_table) {
    $q = $conn->query("
        SELECT d.*, s.site_name, u.name AS operator_name, b.name AS blocked_by_name,
               TIMESTAMPDIFF(MINUTE, d.last_seen_at, NOW()) AS mins_ago
        FROM kiosk_devices d
        LEFT JOIN sites s ON s.id = d.site_id
        LEFT JOIN users u ON u.id = d.operator_user_id
        LEFT JOIN users b ON b.id = d.blocked_by
        ORDER BY (d.status = 'blocked'), d.last_seen_at IS NULL, d.last_seen_at DESC
    ");
    while ($q && ($r = $q->fetch_assoc())) {
        $r['online'] = $r['last_seen_at'] !== null && $r['mins_ago'] !== null
            && (int) $r['mins_ago'] <= KD_ONLINE_MINUTES && $r['status'] !== 'blocked';
        $kd_rows[] = $r;

        $kd_stat['total']++;
        if ($r['online'])                $kd_stat['online']++;
        if ($r['status'] === 'blocked')  $kd_stat['blocked']++;
        $kd_stat['queued'] += (int) $r['queue_depth'];
    }
}

$kd_can = in_array((int) ($login_role ?? 0), PAYROLL_DTR_ROLES, true);

// ── Site attendance areas (Phase C) ───────────────────────────────────────
// A circle per site. Listed here rather than on the site editor because this
// is kiosk behaviour, and because the numbers only mean anything next to the
// tablets that will be measured against them.
$kd_has_geo = $conn->query("SHOW COLUMNS FROM sites LIKE 'geofence_mode'");
$kd_has_geo = $kd_has_geo && $kd_has_geo->num_rows > 0;

$kd_sites = [];
if ($kd_has_geo) {
    $q = $conn->query("
        SELECT s.id, s.site_code, s.site_name, s.status,
               s.latitude, s.longitude, s.geofence_radius_m, s.geofence_mode,
               s.geofence_note, s.geofence_set_at, u.name AS set_by_name
        FROM sites s
        LEFT JOIN users u ON u.id = s.geofence_set_by
        WHERE s.status = 1
        ORDER BY s.site_name
    ");
    while ($q && ($r = $q->fetch_assoc())) $kd_sites[] = $r;
}

/** "4 minutes ago" / "2 days ago" — a relative age reads faster than a date. */
function kd_ago($mins)
{
    if ($mins === null) return 'never';
    $mins = (int) $mins;
    if ($mins < 1)     return 'just now';
    if ($mins < 60)    return $mins . ' min ago';
    if ($mins < 1440)  return floor($mins / 60) . ' hr ago';
    return floor($mins / 1440) . ' day' . (floor($mins / 1440) === 1 ? '' : 's') . ' ago';
}

/** Hardware state the tablet reports: ok / missing / no-permission / unknown. */
function kd_hw($value, $okLabel, $icon)
{
    $v = (string) $value;
    if ($v === 'ok')            return '<span class="kd-hw ok"><i class="' . $icon . '"></i>' . $okLabel . '</span>';
    if ($v === 'missing')       return '<span class="kd-hw bad"><i class="' . $icon . '"></i>Missing</span>';
    if ($v === 'no-permission') return '<span class="kd-hw warn"><i class="' . $icon . '"></i>No permission</span>';
    return '<span class="kd-hw"><i class="' . $icon . '"></i>Unknown</span>';
}
?>
<style>
.kd-stat{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
.kd-stat .s{flex:1 1 150px;background:var(--v2-surface,#fff);border:1px solid var(--v2-border,#ece9f3);border-left:3px solid var(--app-primary,#673bb6);border-radius:10px;padding:10px 14px;}
.kd-stat .s .v{font-size:20px;font-weight:800;color:var(--v2-text,#4e3483);line-height:1.1;}
.kd-stat .s .l{font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--v2-text-3,#999);font-weight:700;margin-top:2px;}
.kd-stat .s.good{border-left-color:#10b981;} .kd-stat .s.good .v{color:#0f9d58;}
.kd-stat .s.warn{border-left-color:#e6a817;} .kd-stat .s.warn .v{color:#b07800;}
.kd-stat .s.bad{border-left-color:#c62828;} .kd-stat .s.bad .v{color:#c62828;}
.kd-legend{font-size:12px;color:var(--v2-text-2,#6f6a80);background:var(--v2-surface-2,#f7f5fc);border:1px solid var(--v2-border,#e9e4f5);border-radius:10px;padding:9px 13px;margin-bottom:14px;line-height:1.55;}
.kd-table td{vertical-align:middle;}
.kd-id{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:700;color:var(--v2-text,#3a285d);}
.kd-sub{font-size:11px;color:var(--v2-text-3,#918d9c);}
.kd-dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:6px;background:#cbd5e1;}
.kd-dot.on{background:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,.18);}
.kd-dot.off{background:#cbd5e1;}
.kd-dot.blocked{background:#c62828;box-shadow:0 0 0 3px rgba(198,40,40,.15);}
.kd-chip{display:inline-block;font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:9px;text-transform:uppercase;letter-spacing:.3px;}
.kd-chip.online{background:rgba(16,185,129,.14);color:#0f9d58;}
.kd-chip.offline{background:var(--v2-surface-3,#f0f0f0);color:var(--v2-text-2,#666);}
.kd-chip.blocked{background:rgba(198,40,40,.13);color:#c62828;}
.kd-q{font-weight:800;}
.kd-q.has{color:#b07800;}
.kd-hw{display:inline-flex;align-items:center;gap:4px;font-size:11px;color:var(--v2-text-2,#666);margin-right:8px;}
.kd-hw.ok{color:#0f9d58;} .kd-hw.bad{color:#c62828;} .kd-hw.warn{color:#b07800;}
tr.kd-blocked{opacity:.72;}

/* Attendance-area dialog (map picker). Lives inside a SweetAlert popup, so it
   resets the popup's centred text and large font for its own content. */
.swal2-html-container.kd-ga-swal{margin:.6em 1.2em 0;text-align:left;overflow:visible;}
.kd-ga{font-size:13px;text-align:left;border:1px solid var(--v2-border,#e4e8ef);border-radius:12px;padding:12px;background:var(--v2-surface-2,#f8fafc);color:var(--v2-text,#334155);}
.kd-ga [hidden]{display:none!important;}
.kd-ga-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px;}
@media (max-width:480px){ .kd-ga-pill{display:none;} .swal2-html-container.kd-ga-swal{margin:.6em .4em 0;} .kd-ga{padding:10px;} }
.kd-ga-title{font-size:12px;font-weight:700;color:var(--v2-text-2,#475569);display:flex;align-items:center;gap:6px;white-space:nowrap;min-width:0;}
.kd-ga-pill{font-size:10px;font-weight:600;padding:2px 8px;border-radius:999px;background:var(--v2-surface-3,#eef2f7);color:var(--v2-text-3,#94a3b8);}
.kd-ga .kd-ga-mode{flex:0 0 auto;width:auto;min-width:118px;font-size:12px;}
.kd-ga-warn{font-size:11.5px;line-height:1.5;padding:8px 11px;border-radius:8px;margin-bottom:10px;background:rgba(230,168,23,.12);border:1px solid rgba(230,168,23,.35);color:#9a6700;}
.kd-ga-search{position:relative;margin-bottom:8px;}
.kd-ga-row{display:flex;gap:8px;}
.kd-ga-row .form-control{font-size:12px;}
.kd-ga-btn{flex-shrink:0;border:1px solid var(--v2-border,#e2e8f0);background:var(--v2-surface,#fff);color:var(--v2-text-2,#475569);border-radius:8px;padding:0 12px;font-size:12px;font-weight:600;}
.kd-ga-btn:hover{color:var(--app-primary,#673bb6);}
.kd-ga-icon{padding:0 9px;font-size:16px;line-height:1;}
.kd-ga-icon.busy i{animation:kd-ga-pulse 1s ease-in-out infinite;color:var(--app-primary,#673bb6);}
@keyframes kd-ga-pulse{50%{opacity:.35;}}
.kd-ga-results{position:absolute;left:0;right:0;top:100%;z-index:1100;margin:4px 0 0;padding:4px 0;list-style:none;max-height:190px;overflow-y:auto;background:var(--v2-surface,#fff);border:1px solid var(--v2-border,#e2e8f0);border-radius:8px;box-shadow:0 10px 24px rgba(15,23,42,.14);}
.kd-ga-results button{display:block;width:100%;text-align:left;border:0;background:none;padding:7px 12px;font-size:12px;color:var(--v2-text,#334155);}
.kd-ga-results button:hover{background:var(--v2-surface-2,#f1f5f9);}
.kd-ga-none{padding:7px 12px;font-size:12px;color:var(--v2-text-3,#94a3b8);}
.kd-ga-map{position:relative;isolation:isolate;overflow:hidden;border:1px solid var(--v2-border,#e2e8f0);border-radius:10px;}
#ga-map{height:256px;width:100%;background:var(--v2-surface-3,#eef2f7);}
.kd-ga-over{position:absolute;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;padding:0 24px;text-align:center;font-size:12px;color:var(--v2-text-3,#94a3b8);background:var(--v2-surface-3,#eef2f7);}
.kd-ga-over.bad{color:#c62828;}
.kd-ga-tools{position:absolute;left:8px;bottom:8px;z-index:1000;display:flex;gap:6px;}
.kd-ga-tools button{border:0;border-radius:6px;padding:4px 9px;font-size:11px;font-weight:700;background:rgba(255,255,255,.95);color:#475569;box-shadow:0 1px 3px rgba(15,23,42,.25);}
.kd-ga-tools button:hover{color:var(--app-primary,#673bb6);}
.kd-ga-tools button.bad{color:#c62828;}
.kd-ga-pin{color:var(--app-primary,#673bb6);background:none;border:0;}
.kd-ga-hint{margin:6px 0 0;font-size:11px;line-height:1.5;color:var(--v2-text-3,#94a3b8);}
.kd-ga-radius{margin-top:12px;}
.kd-ga-radius-top{display:flex;justify-content:space-between;font-size:11px;font-weight:700;color:var(--v2-text-2,#64748b);margin-bottom:2px;}
.kd-ga-radius-top b{color:var(--app-primary,#673bb6);}
#ga-radius{width:100%;accent-color:var(--app-primary,#673bb6);}
.kd-ga-row2{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px;}
.kd-ga-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;}
.kd-ga .leaflet-container{font:inherit;}
.kd-ga .leaflet-control-attribution{font-size:10px;}
</style>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <div class="row mb-3"><div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
            <h4 class="mb-sm-0"><i class="ri-tablet-line me-2" style="color:var(--app-primary,#673bb6);"></i>Kiosk Devices</h4>
            <ol class="breadcrumb m-0">
                <li class="breadcrumb-item">Attendance</li>
                <li class="breadcrumb-item active">Kiosk Devices</li>
            </ol>
        </div>
    </div></div>

    <div class="kd-legend">
        <i class="ri-information-line me-1"></i>
        Every tablet running the kiosk app reports in every few minutes and appears here on its own — <b>nothing has to be approved to install one</b>.
        <b>Queued</b> is punches the tablet has taken but not yet uploaded; they are safe on the device and go up when it reconnects.
        <b>Block</b> is for a tablet that is lost, stolen or retired: it is refused from its next request onward. Punches it already made are not affected.
    </div>

    <?php if (!$kd_has_table): ?>
    <div class="alert alert-warning"><i class="ri-error-warning-line me-1"></i>The kiosk tables are missing. Run <code>migrations/2026_11_kiosk_api.sql</code> and <code>migrations/2026_11_kiosk_devices.sql</code> on this database.</div>
    <?php endif; ?>

    <div class="kd-stat">
        <div class="s"><div class="v"><?= $kd_stat['total'] ?></div><div class="l">Kiosks</div></div>
        <div class="s good"><div class="v"><?= $kd_stat['online'] ?></div><div class="l">Online now</div></div>
        <div class="s warn"><div class="v"><?= $kd_stat['queued'] ?></div><div class="l">Punches waiting to upload</div></div>
        <div class="s bad"><div class="v"><?= $kd_stat['blocked'] ?></div><div class="l">Blocked</div></div>
    </div>

    <div class="card rpt-card">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
            <span class="fw-bold"><i class="ri-list-check-2 me-1"></i><?= count($kd_rows) ?> device<?= count($kd_rows) === 1 ? '' : 's' ?></span>
            <span class="kd-sub">Online = reported in the last <?= KD_ONLINE_MINUTES ?> minutes</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 kd-table">
                    <thead class="table-light"><tr>
                        <th>Kiosk</th>
                        <th style="width:150px;">Site</th>
                        <th style="width:130px;">Last seen</th>
                        <th style="width:110px;">Queued</th>
                        <th style="width:190px;">Hardware</th>
                        <th style="width:130px;">App</th>
                        <th style="width:150px;"></th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$kd_rows): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">
                            No kiosk has reported in yet. Sign in on the tablet and it appears here within a few minutes.
                        </td></tr>
                    <?php else: foreach ($kd_rows as $d):
                        $blocked = $d['status'] === 'blocked'; ?>
                        <tr class="<?= $blocked ? 'kd-blocked' : '' ?>">
                            <td>
                                <span class="kd-dot <?= $blocked ? 'blocked' : ($d['online'] ? 'on' : 'off') ?>"></span>
                                <span class="kd-id"><?= htmlspecialchars($d['device_id']) ?></span>
                                <?php if (!empty($d['name'])): ?>
                                    <div class="kd-sub"><?= htmlspecialchars($d['name']) ?></div>
                                <?php endif; ?>
                                <?php if ($blocked): ?>
                                    <div><span class="kd-chip blocked">Blocked</span></div>
                                    <div class="kd-sub">
                                        <?= $d['blocked_by_name'] ? 'by ' . htmlspecialchars($d['blocked_by_name']) : '' ?>
                                        <?= $d['blocked_at'] ? ' · ' . date('M j, Y', strtotime($d['blocked_at'])) : '' ?>
                                    </div>
                                    <?php if (!empty($d['note'])): ?><div class="kd-sub"><?= htmlspecialchars($d['note']) ?></div><?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($d['operator_name'])): ?>
                                    <div class="kd-sub"><i class="ri-user-line"></i> last enrolled by <?= htmlspecialchars($d['operator_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($d['site_name'] ?? '—') ?>
                                <?php if (!empty($d['ip'])): ?><div class="kd-sub"><?= htmlspecialchars($d['ip']) ?></div><?php endif; ?>
                            </td>
                            <td>
                                <span class="kd-chip <?= $blocked ? 'blocked' : ($d['online'] ? 'online' : 'offline') ?>">
                                    <?= $blocked ? 'Blocked' : ($d['online'] ? 'Online' : 'Offline') ?>
                                </span>
                                <div class="kd-sub"><?= kd_ago($d['mins_ago']) ?></div>
                            </td>
                            <td>
                                <span class="kd-q <?= (int) $d['queue_depth'] > 0 ? 'has' : '' ?>"><?= (int) $d['queue_depth'] ?></span>
                                <?php if ((int) $d['rejected_depth'] > 0): ?>
                                    <div class="kd-sub text-danger"><?= (int) $d['rejected_depth'] ?> rejected</div>
                                <?php endif; ?>
                                <?php if (!empty($d['last_sync_at'])): ?>
                                    <div class="kd-sub">synced <?= date('M j, g:i A', strtotime($d['last_sync_at'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= kd_hw($d['reader'], 'Reader', 'ri-fingerprint-line') ?>
                                <?= kd_hw($d['camera'], 'Camera', 'ri-camera-line') ?>
                            </td>
                            <td class="kd-sub"><?= htmlspecialchars($d['app_version'] ?? '—') ?></td>
                            <td class="text-end">
                                <?php if ($kd_can): ?>
                                <div class="d-flex gap-1 justify-content-end flex-wrap">
                                    <button class="btn btn-outline-secondary btn-sm py-0" onclick="kdRename('<?= htmlspecialchars($d['device_id'], ENT_QUOTES) ?>', <?= htmlspecialchars(json_encode($d['name'] ?? ''), ENT_QUOTES) ?>)"><i class="ri-edit-line"></i></button>
                                    <?php if ($blocked): ?>
                                        <button class="btn btn-outline-success btn-sm py-0" onclick="kdBlock('<?= htmlspecialchars($d['device_id'], ENT_QUOTES) ?>', false)">Unblock</button>
                                    <?php else: ?>
                                        <button class="btn btn-outline-danger btn-sm py-0" onclick="kdBlock('<?= htmlspecialchars($d['device_id'], ENT_QUOTES) ?>', true)">Block</button>
                                        <button class="btn btn-outline-secondary btn-sm py-0" title="Remove from this list" onclick="kdForget('<?= htmlspecialchars($d['device_id'], ENT_QUOTES) ?>')"><i class="ri-delete-bin-line"></i></button>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── Site attendance areas ─────────────────────────────────────── -->
    <div class="card rpt-card mt-3">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
            <h6 class="mb-0"><i class="ri-map-pin-range-line me-2" style="color:var(--app-primary,#673bb6);"></i>Site attendance areas</h6>
            <span class="kd-sub">Where a punch may be made from</span>
        </div>
        <div class="card-body">
            <div class="kd-legend mb-3">
                <i class="ri-information-line me-1"></i>
                A punch that carries coordinates is measured against its site's circle.
                <b>Flag</b> records the punch and marks it for review — start here, so you can see what your radius really needs to be.
                <b>Refuse</b> turns the punch away outright; only switch to it once the flagged punches look right, because too tight a circle stops a whole site from clocking in.
                A punch with no location is never refused by this.
            </div>

            <?php if (!$kd_has_geo): ?>
                <div class="alert alert-warning mb-0"><i class="ri-error-warning-line me-1"></i>Run <code>migrations/2026_11_kiosk_geofence.sql</code> to set attendance areas.</div>
            <?php elseif (!$kd_sites): ?>
                <div class="text-muted">No active sites.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table kd-table align-middle mb-0">
                    <thead><tr>
                        <th>Site</th><th>Centre</th><th>Radius</th><th>Mode</th><th>Set</th>
                        <?php if ($kd_can): ?><th class="text-end">Action</th><?php endif; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($kd_sites as $st):
                        $has = $st['latitude'] !== null && $st['longitude'] !== null && (int) $st['geofence_radius_m'] > 0;
                        $mode = in_array($st['geofence_mode'], ['flag','strict'], true) && $has ? $st['geofence_mode'] : 'off';
                    ?>
                        <tr>
                            <td>
                                <div class="kd-id"><?= htmlspecialchars($st['site_name']) ?></div>
                                <div class="kd-sub"><?= htmlspecialchars($st['site_code'] ?? '') ?></div>
                            </td>
                            <td>
                                <?php if ($has): ?>
                                    <span class="kd-sub" style="font-family:ui-monospace,Menlo,monospace;"><?= number_format((float) $st['latitude'], 6) ?>, <?= number_format((float) $st['longitude'], 6) ?></span>
                                <?php else: ?><span class="text-muted">&mdash;</span><?php endif; ?>
                            </td>
                            <td><?= $has ? geofence_format_m((int) $st['geofence_radius_m']) : '<span class="text-muted">&mdash;</span>' ?></td>
                            <td>
                                <?php if ($mode === 'strict'): ?><span class="kd-chip blocked">Refuse outside</span>
                                <?php elseif ($mode === 'flag'): ?><span class="kd-chip online" style="background:rgba(230,168,23,.16);color:#b07800;">Flag outside</span>
                                <?php else: ?><span class="kd-chip offline">No area</span><?php endif; ?>
                                <?php if (!empty($st['geofence_note'])): ?><div class="kd-sub"><?= htmlspecialchars($st['geofence_note']) ?></div><?php endif; ?>
                            </td>
                            <td class="kd-sub">
                                <?php if ($st['geofence_set_at']): ?>
                                    <?= date('M j, Y', strtotime($st['geofence_set_at'])) ?>
                                    <?php if ($st['set_by_name']): ?><div>by <?= htmlspecialchars($st['set_by_name']) ?></div><?php endif; ?>
                                <?php else: ?>&mdash;<?php endif; ?>
                            </td>
                            <?php if ($kd_can): ?>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-primary"
                                        onclick='kdArea(<?= json_encode([
                                            "id" => (int) $st["id"], "name" => $st["site_name"],
                                            "lat" => $has ? (float) $st["latitude"] : null,
                                            "lng" => $has ? (float) $st["longitude"] : null,
                                            "radius" => $has ? (int) $st["geofence_radius_m"] : 150,
                                            "mode" => $mode, "note" => $st["geofence_note"] ?? "",
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                    <i class="ri-map-pin-line me-1"></i><?= $has ? 'Edit area' : 'Set area' ?>
                                </button>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>
</div>
</div>

<script>
    async function kdPost(action, data) {
        var res = await fetch('ajax.php?action=' + action, { method: 'POST', body: new URLSearchParams(data) });
        return res.json().catch(function () { return { result: false, message: 'The server sent an unreadable answer.' }; });
    }

    function kdDone(j) {
        if (!j.result) { Swal.fire({ icon: 'error', title: 'Kiosk device', text: j.message || 'Could not do that.' }); return; }
        Swal.fire({ icon: 'success', title: 'Done', text: j.message, timer: 1400, showConfirmButton: false }).then(function () { location.reload(); });
    }

    async function kdRename(id, current) {
        var r = await Swal.fire({
            title: 'Name this kiosk',
            input: 'text',
            inputValue: current || '',
            inputLabel: 'Where it stands, e.g. "Main gate" or "HR office"',
            inputAttributes: { maxlength: 80 },
            showCancelButton: true,
            confirmButtonText: 'Save',
        });
        if (!r.isConfirmed) return;
        kdDone(await kdPost('rename_kiosk_device', { device_id: id, name: r.value || '' }));
    }

    async function kdBlock(id, block) {
        if (block) {
            var r = await Swal.fire({
                icon: 'warning',
                title: 'Block this kiosk?',
                html: 'It is refused from its next request onward — no roster, no enrollment, no punching.<br><small class="text-muted">Punches it already recorded are not affected. Anything still queued on the tablet stays there.</small>',
                input: 'text',
                inputLabel: 'Reason (optional)',
                inputAttributes: { maxlength: 255 },
                showCancelButton: true,
                confirmButtonText: 'Block it',
                confirmButtonColor: '#e15b64',
                reverseButtons: true,
            });
            if (!r.isConfirmed) return;
            kdDone(await kdPost('block_kiosk_device', { device_id: id, block: 1, note: r.value || '' }));
        } else {
            var c = await Swal.fire({ icon: 'question', title: 'Unblock this kiosk?', text: 'It can punch again immediately.', showCancelButton: true, confirmButtonText: 'Unblock' });
            if (!c.isConfirmed) return;
            kdDone(await kdPost('block_kiosk_device', { device_id: id, block: 0 }));
        }
    }

    async function kdForget(id) {
        var c = await Swal.fire({
            icon: 'warning',
            title: 'Remove from the list?',
            html: 'Use this for a tablet that will never report again.<br><small class="text-muted">It is only a monitoring row — punches it made stay. If the tablet is still in use it simply re-appears on its next heartbeat.</small>',
            showCancelButton: true,
            confirmButtonText: 'Remove',
            confirmButtonColor: '#e15b64',
            reverseButtons: true,
        });
        if (!c.isConfirmed) return;
        kdDone(await kdPost('forget_kiosk_device', { device_id: id }));
    }

    /* ── Site attendance area ─────────────────────────────────────────────
       A map picker (Mapbox tiles drawn by Leaflet) on top of the same three
       fields the server has always taken: centre, radius, mode.

       Drop the pin by clicking the map, searching an address, or "Use my
       location" while standing on site — the last is still the most accurate,
       because an address search lands on the street, not the compound.

       The token comes from db_config.php / the MAPBOX_TOKEN env var, never
       from git, and only a public pk. token ever reaches this page (see
       db_connect.php). Without one the map hides and the typed coordinates
       still work exactly as before.

       Leaflet is loaded on first open, not with the page: most visits to
       Kiosk Devices are about tablets and never open this dialog. */
    var KD_MAPBOX  = <?= json_encode($kd_can ? MAPBOX_TOKEN : '', JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var KD_LEAFLET = <?= json_encode(['js' => av('assets3/vendor/leaflet/leaflet.js'), 'css' => av('assets3/vendor/leaflet/leaflet.css')], JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>;
    var KD_GA_CENTER = [8.4822, 124.6472];   // where an unpinned map opens
    var KD_GA_STYLES = { streets: 'mapbox/streets-v12', satellite: 'mapbox/satellite-streets-v12' };
    var KD_GA_PIN = '<svg viewBox="0 0 24 32" width="28" height="36"><path d="M12 0C5.4 0 0 5.4 0 12c0 9 12 20 12 20s12-11 12-20C24 5.4 18.6 0 12 0z" fill="currentColor" stroke="#fff" stroke-width="1.5"/><circle cx="12" cy="12" r="4.5" fill="#fff"/></svg>';
    var KD_GA_HELP = {
        off:    'Employees can punch from anywhere. Saving with Off removes the area.',
        flag:   'Punches outside the area are recorded, but marked as outside for review.',
        strict: 'Phones and kiosk tablets can only punch inside the area. Fixed biometric devices, and punches that carry no location, are not checked.',
    };

    function kdLoadLeaflet() {
        if (window.L && window.L.map) return Promise.resolve(window.L);
        if (kdLoadLeaflet.p) return kdLoadLeaflet.p;
        kdLoadLeaflet.p = new Promise(function (ok, bad) {
            var css = document.createElement('link');
            css.rel = 'stylesheet'; css.href = KD_LEAFLET.css;
            document.head.appendChild(css);
            var s = document.createElement('script');
            s.src = KD_LEAFLET.js;
            s.onload = function () { ok(window.L); };
            s.onerror = function () { kdLoadLeaflet.p = null; bad(new Error('Leaflet did not load')); };
            document.head.appendChild(s);
        });
        return kdLoadLeaflet.p;
    }

    var KD_GA_HTML =
        '<div class="kd-ga">' +
          '<div class="kd-ga-head">' +
            '<span class="kd-ga-title">Attendance area <span class="kd-ga-pill">Phone &amp; kiosk</span></span>' +
            // data-no-cs: stays a native select. The app-wide skin only re-reads
            // on a real change event (the pin flips Off→Flag from code), and its
            // body-parented menu would open underneath the SweetAlert overlay.
            '<select id="ga-mode" class="form-select form-select-sm kd-ga-mode" data-no-cs>' +
              '<option value="off">Off</option><option value="flag">Flag only</option><option value="strict">Strict</option>' +
            '</select>' +
          '</div>' +
          '<div id="ga-nomap" class="kd-ga-warn" hidden>' +
            'Map is unavailable. Add <code>mapbox_token</code> to <code>db_config.php</code>, or type the coordinates below.' +
          '</div>' +
          '<div class="kd-ga-search">' +
            '<div class="kd-ga-row">' +
              '<input id="ga-q" type="text" class="form-control form-control-sm" placeholder="Search a place or address" autocomplete="off">' +
              '<button type="button" id="ga-go" class="kd-ga-btn">Search</button>' +
              '<button type="button" id="ga-here" class="kd-ga-btn kd-ga-icon" title="Use my location" aria-label="Use my location"><i class="ri-focus-3-line"></i></button>' +
            '</div>' +
            '<ul id="ga-results" class="kd-ga-results" hidden></ul>' +
          '</div>' +
          // data-v2-keep: the map is a light picture whatever the theme, so its
          // white controls stay white instead of being remapped to dark surfaces.
          '<div id="ga-mapbox" class="kd-ga-map" data-v2-keep>' +
            '<div id="ga-map"></div>' +
            '<div id="ga-loading" class="kd-ga-over">Loading map…</div>' +
            '<div id="ga-failed" class="kd-ga-over bad" hidden></div>' +
            '<div id="ga-tools" class="kd-ga-tools" hidden>' +
              '<button type="button" id="ga-style">Satellite</button>' +
              '<button type="button" id="ga-fit" hidden>Fit area</button>' +
              '<button type="button" id="ga-clear" class="bad" hidden>Remove pin</button>' +
            '</div>' +
          '</div>' +
          '<p id="ga-hint" class="kd-ga-hint">Click the map to drop the site pin.</p>' +
          '<div class="kd-ga-radius">' +
            '<div class="kd-ga-radius-top"><span>Radius</span><b><span id="ga-rad-v"></span> m</b></div>' +
            '<input id="ga-radius" type="range" min="20" max="1000" step="5">' +
          '</div>' +
          '<div class="kd-ga-row2">' +
            '<input id="ga-lat" class="form-control form-control-sm kd-ga-mono" inputmode="decimal" placeholder="Latitude">' +
            '<input id="ga-lng" class="form-control form-control-sm kd-ga-mono" inputmode="decimal" placeholder="Longitude">' +
          '</div>' +
          '<input id="ga-note" class="form-control form-control-sm mt-2" maxlength="255" placeholder="Note (optional)">' +
          '<p id="ga-help" class="kd-ga-hint"></p>' +
          '<p class="kd-ga-hint">Up to 50 m of a phone\'s own GPS error is forgiven, so cover the whole compound, not just the door.</p>' +
        '</div>';

    async function kdArea(site) {
        var st = { map: null, tiles: null, marker: null, circle: null, ro: null, style: 'streets', tileOk: false };

        var r = await Swal.fire({
            titleText: 'Attendance area — ' + site.name,   // text, not HTML: a site name is user input
            width: 600,
            html: KD_GA_HTML,
            showCancelButton: true,
            confirmButtonText: 'Save area',
            reverseButtons: true,
            customClass: { htmlContainer: 'kd-ga-swal' },
            didOpen: function () { kdGaOpen(site, st); },
            willClose: function () {
                if (st.ro) st.ro.disconnect();
                if (st.map) st.map.remove();
            },
            preConfirm: function () {
                var mode = document.getElementById('ga-mode').value;
                if (mode === 'off') return { geofence_mode: 'off' };
                var lat = document.getElementById('ga-lat').value.trim();
                var lng = document.getElementById('ga-lng').value.trim();
                var rad = parseInt(document.getElementById('ga-radius').value, 10);
                var la = Number(lat), lo = Number(lng);
                if (lat === '' || lng === '' || !isFinite(la) || !isFinite(lo)) {
                    Swal.showValidationMessage('Drop the pin on the map, or type the centre, first.'); return false;
                }
                if (Math.abs(la) > 90 || Math.abs(lo) > 180) {
                    Swal.showValidationMessage('Those coordinates are not on the map.'); return false;
                }
                if (!(rad >= 20)) { Swal.showValidationMessage('Radius must be at least 20 metres.'); return false; }
                return {
                    geofence_mode: mode, latitude: lat, longitude: lng,
                    geofence_radius_m: rad, geofence_note: document.getElementById('ga-note').value.trim(),
                };
            },
        });
        if (!r.isConfirmed) return;

        var payload = r.value; payload.site_id = site.id;
        var j = await kdPost('save_site_area', payload);
        if (!j.result) { Swal.fire({ icon: 'error', title: 'Attendance area', text: j.message }); return; }
        // The count of punches that would fall outside is the useful part of
        // this answer, so it is shown long enough to read rather than flashed.
        await Swal.fire({ icon: 'success', title: 'Saved', text: j.message });
        location.reload();
    }

    function kdGaOpen(site, st) {
        var $ = function (id) { return document.getElementById(id); };
        var mode = $('ga-mode'), latIn = $('ga-lat'), lngIn = $('ga-lng'), rad = $('ga-radius');

        mode.value = site.mode || 'off';
        latIn.value = site.lat === null ? '' : site.lat;
        lngIn.value = site.lng === null ? '' : site.lng;
        var r0 = site.radius || 150;
        rad.max = Math.max(1000, r0);            // an older, wider circle must still fit the slider
        rad.value = r0;
        $('ga-rad-v').textContent = r0;
        $('ga-note').value = site.note || '';

        function pinned() {
            var a = latIn.value.trim(), b = lngIn.value.trim();
            return a !== '' && b !== '' && isFinite(Number(a)) && isFinite(Number(b));
        }
        function pinLatLng() { return [Number(latIn.value), Number(lngIn.value)]; }

        function refresh() {
            $('ga-help').textContent = KD_GA_HELP[mode.value] || '';
            var p = pinned();
            $('ga-fit').hidden = !p;
            $('ga-clear').hidden = !p;
            $('ga-hint').textContent = p ? 'Drag the pin to adjust.' : 'Click the map to drop the site pin.';
        }

        function drawCircle(lat, lng) {
            if (!st.map) return;
            if (lat === undefined) {
                if (!pinned()) { if (st.circle) { st.circle.remove(); st.circle = null; } return; }
                var ll = pinLatLng(); lat = ll[0]; lng = ll[1];
            }
            var radius = Number(rad.value) || 0;
            if (st.circle) { st.circle.setLatLng([lat, lng]).setRadius(radius); return; }
            // Leaflet writes SVG attributes, which do not resolve var(), so the
            // theme colour is read once here instead.
            var c = getComputedStyle(document.documentElement).getPropertyValue('--app-primary').trim() || '#673bb6';
            st.circle = L.circle([lat, lng], {
                radius: radius, color: c, weight: 2, fillColor: c, fillOpacity: 0.18, interactive: false,
            }).addTo(st.map);
        }

        function ensureMarker() {
            if (!st.marker) {
                st.marker = L.marker(st.map.getCenter(), {
                    draggable: true,
                    icon: L.divIcon({ html: KD_GA_PIN, className: 'kd-ga-pin', iconSize: [28, 36], iconAnchor: [14, 36] }),
                }).addTo(st.map);
                st.marker.on('drag', function () { var p = st.marker.getLatLng(); drawCircle(p.lat, p.lng); });
                st.marker.on('dragend', function () { var p = st.marker.getLatLng(); place(p.lat, p.lng); });
            }
            return st.marker;
        }

        function place(lat, lng) {
            lat = Math.round(lat * 1e7) / 1e7;
            lng = Math.round(lng * 1e7) / 1e7;
            latIn.value = lat; lngIn.value = lng;
            // A pin with the mode still Off would save as "no area". Flag, not
            // Strict: a first circle is a guess, and Flag shows how good a
            // guess before anyone is turned away.
            if (mode.value === 'off') mode.value = 'flag';
            if (st.map) { ensureMarker().setLatLng([lat, lng]); drawCircle(lat, lng); }
            refresh();
        }

        function clearPin() {
            latIn.value = ''; lngIn.value = '';
            mode.value = 'off';
            if (st.marker) { st.marker.remove(); st.marker = null; }
            drawCircle();
            refresh();
        }

        function syncFromInputs(fly) {
            if (!st.map) { refresh(); return; }
            if (!pinned()) {
                if (st.marker) { st.marker.remove(); st.marker = null; }
                drawCircle();
            } else {
                ensureMarker().setLatLng(pinLatLng());
                drawCircle();
                if (fly) st.map.setView(pinLatLng(), 17);
            }
            refresh();
        }

        function setTiles() {
            if (st.tiles) st.tiles.remove();
            st.tiles = L.tileLayer(
                'https://api.mapbox.com/styles/v1/' + KD_GA_STYLES[st.style] +
                '/tiles/512/{z}/{x}/{y}@2x?access_token=' + encodeURIComponent(KD_MAPBOX),
                { tileSize: 512, zoomOffset: -1, maxZoom: 20 }
            ).addTo(st.map);
            st.tiles.on('tileerror', function () {
                if (!st.tileOk) {
                    $('ga-failed').textContent = 'Map tiles could not load. Check the Mapbox token in db_config.php — or type the coordinates below.';
                    $('ga-failed').hidden = false;
                }
            });
            st.tiles.on('tileload', function () { st.tileOk = true; $('ga-failed').hidden = true; });
            $('ga-style').textContent = st.style === 'streets' ? 'Satellite' : 'Streets';
        }

        async function geocode(text) {
            if (!text || !KD_MAPBOX) return [];
            var near = st.map ? st.map.getCenter() : { lat: KD_GA_CENTER[0], lng: KD_GA_CENTER[1] };
            var url = 'https://api.mapbox.com/search/searchbox/v1/forward?q=' + encodeURIComponent(text) +
                '&country=ph&limit=6&language=en&proximity=' + near.lng + ',' + near.lat +
                '&access_token=' + encodeURIComponent(KD_MAPBOX);
            try {
                var body = await (await fetch(url)).json();
                return (body.features || []).map(function (f) {
                    var p = f.properties || {};
                    var exact = p.feature_type === 'poi' || p.feature_type === 'address';
                    return {
                        name: [p.name, p.full_address || p.place_formatted].filter(Boolean).join(' — '),
                        center: [f.geometry.coordinates[1], f.geometry.coordinates[0]],
                        exact: exact, zoom: exact ? 17 : 13,
                    };
                });
            } catch (e) { return []; }
        }

        async function search() {
            var q = $('ga-q').value.trim();
            if (!q) return;
            var go = $('ga-go'), list = $('ga-results');
            go.textContent = '…';
            var places = await geocode(q);
            go.textContent = 'Search';
            list.innerHTML = '';
            if (!places.length) {
                var none = document.createElement('li');
                none.className = 'kd-ga-none'; none.textContent = 'No match. Try a barangay or landmark.';
                list.appendChild(none);
            }
            places.forEach(function (pl) {
                var li = document.createElement('li'), b = document.createElement('button');
                b.type = 'button'; b.textContent = pl.name;          // API text → textContent, never HTML
                b.addEventListener('click', function () {
                    list.hidden = true;
                    $('ga-q').value = pl.name;
                    if (st.map) st.map.flyTo(pl.center, pl.zoom);
                    if (pl.exact) place(pl.center[0], pl.center[1]);
                });
                li.appendChild(b); list.appendChild(li);
            });
            list.hidden = false;
        }

        function useMyLocation() {
            var btn = $('ga-here');
            if (!navigator.geolocation || !window.isSecureContext) {
                Swal.showValidationMessage('The browser only shares your location over HTTPS. Use the search or the map instead.');
                return;
            }
            btn.classList.add('busy');
            navigator.geolocation.getCurrentPosition(function (pos) {
                btn.classList.remove('busy');
                place(pos.coords.latitude, pos.coords.longitude);
                if (st.map) st.map.flyTo([pos.coords.latitude, pos.coords.longitude], 17);
                Swal.resetValidationMessage();
            }, function (err) {
                btn.classList.remove('busy');
                Swal.showValidationMessage('Could not read your location: ' + err.message);
            }, { enableHighAccuracy: true, timeout: 15000 });
        }

        // ── wiring ──
        mode.addEventListener('change', refresh);
        rad.addEventListener('input', function () { $('ga-rad-v').textContent = rad.value; drawCircle(); });
        function typed() {
            // Typed coordinates are a pin too: same Off→Flag rule as a click.
            if (pinned() && mode.value === 'off') mode.value = 'flag';
            syncFromInputs(true);
        }
        latIn.addEventListener('change', typed);
        lngIn.addEventListener('change', typed);
        $('ga-here').addEventListener('click', useMyLocation);
        $('ga-go').addEventListener('click', search);
        $('ga-q').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); search(); } });
        document.querySelector('.kd-ga').addEventListener('click', function (e) {
            if (!e.target.closest('.kd-ga-search')) $('ga-results').hidden = true;
        });
        $('ga-style').addEventListener('click', function () { st.style = st.style === 'streets' ? 'satellite' : 'streets'; setTiles(); });
        $('ga-fit').addEventListener('click', function () { if (st.circle) st.map.fitBounds(st.circle.getBounds(), { padding: [30, 30], maxZoom: 19 }); });
        $('ga-clear').addEventListener('click', clearPin);
        refresh();

        if (!KD_MAPBOX) {
            $('ga-nomap').hidden = false;
            $('ga-mapbox').hidden = true;
            $('ga-hint').hidden = true;
            $('ga-q').hidden = true;
            $('ga-go').hidden = true;
            $('ga-here').classList.remove('kd-ga-icon');
            $('ga-here').innerHTML = '<i class="ri-focus-3-line me-1"></i>Use my location';
            return;
        }

        kdLoadLeaflet().then(function () {
            if (!document.getElementById('ga-map')) return;   // dialog closed while loading
            st.map = L.map('ga-map', {
                center: pinned() ? pinLatLng() : KD_GA_CENTER,
                zoom: pinned() ? 17 : 13,
                attributionControl: false,
            });
            L.control.attribution({ prefix: false, position: 'bottomright' })
                .addAttribution('© <a href="https://www.mapbox.com/about/maps/" target="_blank" rel="noopener">Mapbox</a> © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>')
                .addTo(st.map);
            setTiles();
            st.map.on('click', function (e) { place(e.latlng.lat, e.latlng.lng); });
            $('ga-loading').hidden = true;
            $('ga-tools').hidden = false;
            syncFromInputs(false);
            // The dialog grows in with an animation, so Leaflet first measures a
            // smaller box than it ends up in — re-measure whenever it changes.
            st.ro = new ResizeObserver(function () { if (st.map) st.map.invalidateSize(); });
            st.ro.observe($('ga-map'));
        }).catch(function () {
            $('ga-loading').hidden = true;
            $('ga-failed').textContent = 'The map could not start. Type the coordinates below.';
            $('ga-failed').hidden = false;
        });
    }
</script>
