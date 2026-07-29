<?php
/**
 * Reusable two-hand fingerprint status view (mirrors the mobile enrollment
 * screen). Renders the 10 canonical fingers for one employee, marking each
 * enrolled / not, colored by capture quality.
 *
 * Two independent sources, because the two scanners keep their templates in
 * separate tables (neither SDK can read the other's blobs):
 *   'kiosk'  → biometric_kiosk_templates, format 'sourceafis' (mobile app)
 *   'device' → employee_fingerprints                          (desktop scanner)
 * Both store canonical finger codes (RIGHT_THUMB … LEFT_PINKY); only the kiosk
 * table carries a capture quality.
 *
 *   render_finger_hands($conn, $employee_id);                        // kiosk
 *   render_finger_hands($conn, $employee_id, ['source' => 'device']); // desktop
 */

if (!function_exists('render_finger_hands')) {

    /** Canonical fingers in NIST order, with per-hand SVG tip/base geometry. */
    function _fh_fingers()
    {
        // [code, short, hand, tipX, tipY, baseX, baseY, width] on a 0..220 x 0..260 box (right hand).
        $right = [
            ['THUMB',  'Thumb',  22, 120, 64, 150, 20],
            ['INDEX',  'Index',  76, 48,  80, 122, 19],
            ['MIDDLE', 'Middle', 110, 32, 110, 118, 20],
            ['RING',   'Ring',   144, 48, 140, 122, 19],
            ['PINKY',  'Pinky',  178, 78, 166, 130, 17],
        ];
        $out = ['right' => [], 'left' => []];
        foreach ($right as $f) {
            [$suf, $short, $tx, $ty, $bx, $by, $w] = $f;
            $out['right'][] = ['code' => 'RIGHT_' . $suf, 'short' => $short, 'tx' => $tx, 'ty' => $ty, 'bx' => $bx, 'by' => $by, 'w' => $w];
            // Left hand = mirror about x = 220
            $out['left'][] = ['code' => 'LEFT_' . $suf, 'short' => $short, 'tx' => 220 - $tx, 'ty' => $ty, 'bx' => 220 - $bx, 'by' => $by, 'w' => $w];
        }
        return $out;
    }

    /**
     * Fetch enrolled fingers for an employee → [code => quality|null].
     * $source: 'kiosk' (mobile app) or 'device' (desktop scanner).
     */
    function fetch_finger_status($conn, $employee_id, $source = 'kiosk')
    {
        $map = [];
        $employee_id = (int) $employee_id;
        $sql = $source === 'device'
            ? "SELECT finger_index, NULL AS quality FROM employee_fingerprints
               WHERE employee_id = $employee_id"
            : "SELECT finger_index, quality FROM biometric_kiosk_templates
               WHERE employee_id = $employee_id AND format = 'sourceafis'";
        $q = $conn->query($sql);
        while ($q && $r = $q->fetch_assoc()) {
            $map[$r['finger_index']] = $r['quality'] !== null ? (int) $r['quality'] : null;
        }
        return $map;
    }

    /** Green (good) / amber (low) / gray (not enrolled) for a finger's quality. */
    function _fh_color($registered, $quality)
    {
        if (!$registered) return '#94a3b8';
        if ($quality === null) return '#10b981';   // enrolled, quality unknown → treat as good
        return $quality >= 45 ? ($quality >= 70 ? '#10b981' : '#f59e0b') : '#f59e0b';
    }

    function _fh_hand_svg($fingers, $status)
    {
        // Palm + wrist shared across hands; fingers drawn as rounded capsules with a tip dot.
        $svg = '<svg viewBox="0 0 220 260" style="width:100%;height:auto;display:block;">';
        $svg .= '<path d="M40 150 Q40 122 66 120 L156 120 Q182 122 182 152 L178 206 Q170 232 138 232 L86 232 Q52 232 44 206 Z" fill="rgba(103,59,182,.06)" stroke="#d9d3e4"/>';
        $svg .= '<rect x="86" y="226" width="52" height="24" rx="11" fill="rgba(103,59,182,.06)" stroke="#d9d3e4"/>';
        foreach ($fingers as $f) {
            $svg .= '<line x1="' . $f['bx'] . '" y1="' . $f['by'] . '" x2="' . $f['tx'] . '" y2="' . $f['ty'] . '" stroke="rgba(0,0,0,.07)" stroke-width="' . $f['w'] . '" stroke-linecap="round"/>';
        }
        foreach ($fingers as $f) {
            $registered = array_key_exists($f['code'], $status);
            $quality = $registered ? $status[$f['code']] : null;
            $color = _fh_color($registered, $quality);
            $title = $f['short'] . ($registered ? ' — enrolled' . ($quality !== null ? " ({$quality}%)" : '') : ' — not enrolled');
            $svg .= '<circle cx="' . $f['tx'] . '" cy="' . $f['ty'] . '" r="10" fill="' . $color . '" stroke="#fff" stroke-width="2.5">'
                . '<title>' . htmlspecialchars($title) . '</title></circle>';
        }
        return $svg . '</svg>';
    }

    /**
     * Render the full two-hand block.
     * $opts: ['title' => string, 'source' => 'kiosk'|'device', 'subtitle' => string,
     *         'face' => bool, 'face_model' => string,
     *         'can_delete' => bool]   // admin-only; adds per-finger + clear-all delete
     */
    function render_finger_hands($conn, $employee_id, $opts = [])
    {
        $source  = ($opts['source'] ?? 'kiosk') === 'device' ? 'device' : 'kiosk';
        $status  = fetch_finger_status($conn, $employee_id, $source);
        $fingers = _fh_fingers();
        $all = array_merge($fingers['right'], $fingers['left']);
        $count = 0;
        foreach ($all as $f) if (array_key_exists($f['code'], $status)) $count++;
        // Un-enrolling wipes a template the employee can only replace by scanning
        // again, so the caller must opt in (employee-details does, for role 1 only).
        $can_delete = !empty($opts['can_delete']);
        $title = $opts['title'] ?? ($source === 'device'
            ? 'Registered Fingerprints (Scanner Device)'
            : 'Registered Fingerprints (Mobile Kiosk)');
        $subtitle = $opts['subtitle'] ?? ($source === 'device'
            ? 'Enrolled on the desktop fingerprint scanner'
            : 'Enrolled on the mobile kiosk app');
        // Only the kiosk table records a capture quality.
        $has_quality = $source !== 'device';
        // Faces are a kiosk-only enrollment — the desktop scanner has no camera.
        $show_face = $opts['face'] ?? ($source === 'kiosk');
        if ($show_face) {
            require_once __DIR__ . '/face_status.php';
        }

        ob_start(); ?>
        <div class="finger-hands-card" style="background:#fff;border:1px solid #e8e7eb;border-radius:14px;padding:14px 16px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:10px;">
                <div>
                    <div style="font-weight:700;font-size:14px;color:#2b2639;"><i class="ri-fingerprint-line me-1" style="color:#673bb6;"></i><?= htmlspecialchars($title) ?></div>
                    <?php if ($subtitle !== ''): ?>
                        <div style="font-size:11px;color:#918d9c;margin-top:2px;"><?= htmlspecialchars($subtitle) ?></div>
                    <?php endif; ?>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:12px;font-weight:800;color:<?= $count ? '#673bb6' : '#94a3b8' ?>;white-space:nowrap;"><?= $count ?>/10</span>
                    <?php if ($can_delete && $count): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger js-fp-clear"
                                data-employee="<?= (int) $employee_id ?>" data-source="<?= $source ?>"
                                style="font-size:11px;padding:2px 8px;white-space:nowrap;">
                            <i class="ri-delete-bin-line me-1"></i>Clear all
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($count === 0): ?>
                <div style="font-size:12.5px;color:#908c9c;padding:6px 0;"><i class="ri-information-line me-1"></i>No fingerprints enrolled yet.</div>
            <?php endif; ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;max-width:360px;margin:0 auto;">
                <div style="text-align:center;">
                    <div style="font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#918d9c;font-weight:700;margin-bottom:2px;">Left hand</div>
                    <?= _fh_hand_svg($fingers['left'], $status) ?>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#918d9c;font-weight:700;margin-bottom:2px;">Right hand</div>
                    <?= _fh_hand_svg($fingers['right'], $status) ?>
                </div>
            </div>
            <div style="display:flex;gap:14px;justify-content:center;margin-top:8px;flex-wrap:wrap;">
                <span style="font-size:11px;color:#625e6e;display:inline-flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:50%;background:#10b981;display:inline-block;"></span>Enrolled</span>
                <?php if ($has_quality): ?>
                <span style="font-size:11px;color:#625e6e;display:inline-flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:50%;background:#f59e0b;display:inline-block;"></span>Low quality</span>
                <?php endif; ?>
                <span style="font-size:11px;color:#625e6e;display:inline-flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:50%;background:#94a3b8;display:inline-block;"></span>Not enrolled</span>
            </div>
            <?php if ($can_delete && $count): ?>
                <!-- One removable chip per enrolled finger. The diagram is a status
                     view; the delete target has to be named, so it is spelled out. -->
                <div style="border-top:1px dashed #ece9f2;margin-top:10px;padding-top:9px;">
                    <div style="font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#918d9c;font-weight:700;margin-bottom:6px;">Un-enroll a finger</div>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;">
                        <?php foreach ($all as $f):
                            if (!array_key_exists($f['code'], $status)) continue;
                            $hand  = strpos($f['code'], 'LEFT_') === 0 ? 'L' : 'R';
                            $label = $hand . ' ' . $f['short'];
                        ?>
                            <button type="button" class="js-fp-del"
                                    data-employee="<?= (int) $employee_id ?>" data-source="<?= $source ?>"
                                    data-finger="<?= htmlspecialchars($f['code']) ?>"
                                    data-label="<?= htmlspecialchars($label) ?>"
                                    title="Remove <?= htmlspecialchars($label) ?>"
                                    style="display:inline-flex;align-items:center;gap:5px;background:#f6f4fa;border:1px solid #e3dded;color:#4a4458;border-radius:999px;padding:3px 9px;font-size:11.5px;font-weight:600;cursor:pointer;">
                                <?= htmlspecialchars($label) ?>
                                <i class="ri-close-circle-fill" style="color:#e15b64;font-size:13px;"></i>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($show_face): ?>
                <?= render_face_status($conn, $employee_id, ['model' => $opts['face_model'] ?? 'facenet']) ?>
            <?php endif; ?>
        </div>
        <?php if ($can_delete): ?>
        <script>
        // Delegated once per page — a page may render one card per scanner source.
        (function () {
            if (window.__fhDeleteBound) return;
            window.__fhDeleteBound = true;

            async function del(btn, finger, confirmHtml, okText) {
                const ask = await Swal.fire({
                    icon: 'warning',
                    title: 'Are you sure?',
                    html: confirmHtml,
                    showCancelButton: true,
                    confirmButtonText: okText,
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#e15b64',
                    reverseButtons: true,
                });
                if (!ask.isConfirmed) return;

                btn.disabled = true;
                try {
                    const res = await fetch('ajax.php?action=delete_employee_fingerprint', {
                        method: 'POST',
                        body: new URLSearchParams({
                            employee_id:  btn.dataset.employee,
                            source:       btn.dataset.source,
                            finger_index: finger,
                        }),
                    });
                    const json = await res.json();
                    if (json && json.result) {
                        await Swal.fire({ icon: 'success', title: 'Deleted', text: json.message || 'Fingerprint deleted', timer: 1400, showConfirmButton: false });
                        location.reload();
                    } else {
                        btn.disabled = false;
                        Swal.fire({ icon: 'error', title: 'Error', text: (json && json.message) || 'Failed to delete fingerprint.' });
                    }
                } catch (e) {
                    btn.disabled = false;
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to delete fingerprint. Please try again.' });
                }
            }

            document.addEventListener('click', function (e) {
                const one = e.target.closest('.js-fp-del');
                if (one) {
                    del(one, one.dataset.finger,
                        'Un-enroll <b>' + one.dataset.label + '</b>? The employee will have to scan this finger again.',
                        'Yes, delete it');
                    return;
                }
                const all = e.target.closest('.js-fp-clear');
                if (all) {
                    del(all, '',
                        'Delete <b>all enrolled fingerprints</b> for this employee on this scanner?<br><small class="text-muted">They will not be able to punch in until re-enrolled.</small>',
                        'Yes, delete all');
                }
            });
        })();
        </script>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }
}
