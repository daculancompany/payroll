<?php
/**
 * Reusable two-hand fingerprint status view (mirrors the mobile enrollment
 * screen). Renders the 10 canonical fingers for one employee, marking each
 * enrolled / not, colored by capture quality.
 *
 * Data source: biometric_kiosk_templates (the live mobile-app table), format
 * 'sourceafis'. finger_index holds canonical codes (RIGHT_THUMB … LEFT_PINKY).
 *
 *   render_finger_hands($conn, $employee_id);
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

    /** Fetch enrolled fingers for an employee → [code => quality|null]. */
    function fetch_finger_status($conn, $employee_id)
    {
        $map = [];
        $employee_id = (int) $employee_id;
        $q = $conn->query("SELECT finger_index, quality FROM biometric_kiosk_templates
                           WHERE employee_id = $employee_id AND format = 'sourceafis'");
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
        $svg .= '<path d="M40 150 Q40 122 66 120 L156 120 Q182 122 182 152 L178 206 Q170 232 138 232 L86 232 Q52 232 44 206 Z" fill="rgba(0,150,136,.06)" stroke="#cfe3e0"/>';
        $svg .= '<rect x="86" y="226" width="52" height="24" rx="11" fill="rgba(0,150,136,.06)" stroke="#cfe3e0"/>';
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
     * $opts: ['title' => string, 'compact' => bool]
     */
    function render_finger_hands($conn, $employee_id, $opts = [])
    {
        $status  = fetch_finger_status($conn, $employee_id);
        $fingers = _fh_fingers();
        $all = array_merge($fingers['right'], $fingers['left']);
        $count = 0;
        foreach ($all as $f) if (array_key_exists($f['code'], $status)) $count++;
        $title = $opts['title'] ?? 'Registered Fingerprints';

        ob_start(); ?>
        <div class="finger-hands-card" style="background:#fff;border:1px solid #e6ebe9;border-radius:14px;padding:14px 16px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                <div style="font-weight:700;font-size:14px;color:#1b2b27;"><i class="ri-fingerprint-line me-1" style="color:#009688;"></i><?= htmlspecialchars($title) ?></div>
                <span style="font-size:12px;font-weight:800;color:<?= $count ? '#009688' : '#94a3b8' ?>;"><?= $count ?>/10</span>
            </div>
            <?php if ($count === 0): ?>
                <div style="font-size:12.5px;color:#8a9a95;padding:6px 0;"><i class="ri-information-line me-1"></i>No fingerprints enrolled yet.</div>
            <?php endif; ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;max-width:360px;margin:0 auto;">
                <div style="text-align:center;">
                    <div style="font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#8b9a96;font-weight:700;margin-bottom:2px;">Left hand</div>
                    <?= _fh_hand_svg($fingers['left'], $status) ?>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#8b9a96;font-weight:700;margin-bottom:2px;">Right hand</div>
                    <?= _fh_hand_svg($fingers['right'], $status) ?>
                </div>
            </div>
            <div style="display:flex;gap:14px;justify-content:center;margin-top:8px;flex-wrap:wrap;">
                <span style="font-size:11px;color:#5a6b67;display:inline-flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:50%;background:#10b981;display:inline-block;"></span>Enrolled</span>
                <span style="font-size:11px;color:#5a6b67;display:inline-flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:50%;background:#f59e0b;display:inline-block;"></span>Low quality</span>
                <span style="font-size:11px;color:#5a6b67;display:inline-flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:50%;background:#94a3b8;display:inline-block;"></span>Not enrolled</span>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
