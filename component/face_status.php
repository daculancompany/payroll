<?php
/**
 * Face-registration view for one employee — the companion to the two-hand
 * fingerprint block, shown inside the Mobile Kiosk card.
 *
 * Data source: biometric_kiosk_faces. An employee holds one row per shot
 * ('face-1' … 'face-6') per model; `photo` is a path relative to the payroll
 * root, written by the kiosk at enrollment and possibly NULL when the shot was
 * saved without an image.
 *
 *   render_face_status($conn, $employee_id);
 */

if (!function_exists('render_face_status')) {

    /** Enrolled face shots for an employee, oldest slot first. */
    function fetch_face_status($conn, $employee_id, $model = 'facenet')
    {
        $employee_id = (int) $employee_id;
        $model = $conn->real_escape_string($model);
        $rows = [];
        $q = $conn->query("SELECT face_index, photo, dimensions, updated_at
                           FROM biometric_kiosk_faces
                           WHERE employee_id = $employee_id AND model = '$model'
                           ORDER BY face_index");
        while ($q && $r = $q->fetch_assoc()) {
            $rows[] = $r;
        }
        return $rows;
    }

    /**
     * One tile per registered shot: the stored photo when the file is still on
     * disk, otherwise a placeholder that still reports the shot as registered.
     */
    function _fs_tile($row)
    {
        $slot = htmlspecialchars($row['face_index']);
        $rel  = $row['photo'] ?? '';
        $abs  = $rel ? __DIR__ . '/../' . $rel : '';
        $has  = $rel && is_file($abs);

        $box = 'width:100%;aspect-ratio:1/1;border-radius:10px;object-fit:cover;display:block;border:2px solid #10b981;';
        $out = '<div style="width:74px;">';
        if ($has) {
            // Cache-buster: re-enrolling overwrites the same filename.
            $src = htmlspecialchars($rel) . '?v=' . filemtime($abs);
            $out .= '<img src="' . $src . '" alt="' . $slot . '" style="' . $box . '">';
        } else {
            $out .= '<div style="' . $box . 'display:flex;align-items:center;justify-content:center;background:#f3f2f5;color:#94a3b8;font-size:24px;">'
                 . '<i class="ri-user-3-line"></i></div>';
        }
        $out .= '<div style="font-size:10px;color:#918d9c;text-align:center;margin-top:3px;">' . $slot . '</div>';
        return $out . '</div>';
    }

    /**
     * Render the face block.
     * $opts: ['title' => string, 'model' => string]
     */
    function render_face_status($conn, $employee_id, $opts = [])
    {
        $model = $opts['model'] ?? 'facenet';
        $rows  = fetch_face_status($conn, $employee_id, $model);
        $title = $opts['title'] ?? 'Face Registration';
        $count = count($rows);

        // Newest enrollment across the shots, for the "registered on" line.
        $last = '';
        foreach ($rows as $r) {
            if ($r['updated_at'] && $r['updated_at'] > $last) $last = $r['updated_at'];
        }

        ob_start(); ?>
        <div class="face-status-block" style="border-top:1px dashed #e8e7eb;margin-top:12px;padding-top:10px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;">
                <div style="font-weight:700;font-size:13px;color:#2b2639;"><i class="ri-user-smile-line me-1" style="color:#673bb6;"></i><?= htmlspecialchars($title) ?></div>
                <span style="font-size:11px;font-weight:800;padding:2px 8px;border-radius:999px;<?= $count
                    ? 'color:#5b3699;background:rgba(16,185,129,.12);'
                    : 'color:#918d9c;background:#f3f2f5;' ?>">
                    <?= $count ? $count . ' shot' . ($count > 1 ? 's' : '') . ' registered' : 'Not registered' ?>
                </span>
            </div>
            <?php if ($count === 0): ?>
                <div style="font-size:12.5px;color:#908c9c;padding:2px 0;"><i class="ri-information-line me-1"></i>No face registered on the kiosk yet.</div>
            <?php else: ?>
                <div style="display:flex;flex-wrap:wrap;gap:8px;">
                    <?php foreach ($rows as $r) echo _fs_tile($r); ?>
                </div>
                <div style="font-size:11px;color:#918d9c;margin-top:8px;">
                    Model <?= htmlspecialchars($model) ?><?= $last ? ' · last updated ' . date('M d, Y g:i A', strtotime($last)) : '' ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
