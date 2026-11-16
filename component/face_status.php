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
 *   employee_face_photo($conn, $employee_id);   // avatar source, or null
 *
 * Colours come from the v2 theme tokens with a light fallback, so the block
 * reads correctly in the classic shell, the v2 dark themes and the portal.
 */

if (!function_exists('render_face_status')) {

    /**
     * The model an employee's face is actually enrolled under — their most
     * recent enrollment.
     *
     * The app names the model it used ('facenet', then 'facenet-v2', and
     * whatever comes next). Reading with a model hardcoded here means every
     * screen goes blank the day the app ships a new one, with the shots sitting
     * in the table and the files on disk: that is exactly what happened to
     * 'facenet-v2'. Which embedding produced the vector is the app's business,
     * not something these pages should have to know.
     */
    function face_latest_model($conn, $employee_id)
    {
        $employee_id = (int) $employee_id;
        $q = $conn->query("SELECT model FROM biometric_kiosk_faces
                           WHERE employee_id = $employee_id
                           ORDER BY updated_at DESC, face_index ASC LIMIT 1");
        $r = $q ? $q->fetch_assoc() : null;
        return $r ? $r['model'] : null;
    }

    /**
     * Enrolled face shots for an employee, oldest slot first.
     *
     * $model null (the default) means "whichever model they are enrolled
     * under"; pass one only to look at a specific older enrollment.
     */
    function fetch_face_status($conn, $employee_id, $model = null)
    {
        $employee_id = (int) $employee_id;
        if ($model === null) $model = face_latest_model($conn, $employee_id);
        if ($model === null) return [];          // nothing enrolled at all
        $model = $conn->real_escape_string($model);
        $rows = [];
        $q = $conn->query("SELECT face_index, photo, dimensions, model, updated_at
                           FROM biometric_kiosk_faces
                           WHERE employee_id = $employee_id AND model = '$model'
                           ORDER BY face_index");
        while ($q && $r = $q->fetch_assoc()) {
            $rows[] = $r;
        }
        return $rows;
    }

    /**
     * The employee's enrolled face as a web path, for use as their picture.
     *
     * The earliest slot that still has a file on disk wins — a row whose image
     * was removed must not leave a broken <img> where an avatar belongs. The
     * `?v=` is the file's mtime: re-enrolling a slot overwrites the same
     * filename, so without it browsers keep showing the previous person.
     *
     * Returns null when nothing is enrolled, which every caller renders as
     * their initials instead.
     */
    function employee_face_photo($conn, $employee_id, $model = null)
    {
        foreach (fetch_face_status($conn, $employee_id, $model) as $row) {
            $rel = (string) ($row['photo'] ?? '');
            if ($rel === '') continue;
            $abs = __DIR__ . '/../' . $rel;
            if (is_file($abs)) {
                return htmlspecialchars($rel) . '?v=' . filemtime($abs);
            }
        }
        return null;
    }

    /**
     * One tile per registered shot: the stored photo when the file is still on
     * disk, otherwise a placeholder that still reports the shot as registered.
     */
    function _fs_tile($row, $who = '')
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
            $cap = trim($who . ' · ' . $slot, ' ·');
            $out .= '<img src="' . $src . '" alt="' . $slot . '" loading="lazy" style="' . $box . 'cursor:zoom-in;"'
                 . ' onclick="kioskPhotoView(this.src, ' . htmlspecialchars(json_encode($cap), ENT_QUOTES) . ')">';
        } else {
            $out .= '<div style="' . $box . 'display:flex;align-items:center;justify-content:center;background:var(--v2-surface-3,#f3f2f5);color:var(--v2-text-3,#94a3b8);font-size:24px;">'
                 . '<i class="ri-user-3-line"></i></div>';
        }
        $out .= '<div style="font-size:10px;color:var(--v2-text-3,#918d9c);text-align:center;margin-top:3px;">' . $slot . '</div>';
        return $out . '</div>';
    }

    /**
     * Render the face block.
     * $opts: ['title' => string, 'model' => string,
     *         'can_delete' => bool,    // admin-only "Remove face"
     *         'who' => string]         // name shown in the enlarged photo
     */
    function render_face_status($conn, $employee_id, $opts = [])
    {
        $model = $opts['model'] ?? null;        // null = whatever they enrolled under
        $rows  = fetch_face_status($conn, $employee_id, $model);
        $model = $rows ? $rows[0]['model'] : $model;
        $title = $opts['title'] ?? 'Face Registration';
        $who   = (string) ($opts['who'] ?? '');
        $count = count($rows);
        // Un-enrolling a face means the employee cannot be recognised at the
        // kiosk until they register again, so the caller opts in (admin only).
        $can_delete = !empty($opts['can_delete']) && $count > 0;

        require_once __DIR__ . '/photo_viewer.php';

        // Newest enrollment across the shots, for the "registered on" line.
        $last = '';
        foreach ($rows as $r) {
            if ($r['updated_at'] && $r['updated_at'] > $last) $last = $r['updated_at'];
        }

        ob_start(); ?>
        <div class="face-status-block" style="border-top:1px dashed var(--v2-border,#e8e7eb);margin-top:12px;padding-top:10px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;flex-wrap:wrap;">
                <div style="font-weight:700;font-size:13px;color:var(--v2-text,#2b2639);"><i class="ri-user-smile-line me-1" style="color:var(--app-primary,#673bb6);"></i><?= htmlspecialchars($title) ?></div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:11px;font-weight:800;padding:2px 8px;border-radius:999px;<?= $count
                        ? 'color:#0f9d58;background:rgba(16,185,129,.14);'
                        : 'color:var(--v2-text-3,#918d9c);background:var(--v2-surface-3,#f3f2f5);' ?>">
                        <?= $count ? $count . ' shot' . ($count > 1 ? 's' : '') . ' registered' : 'Not registered' ?>
                    </span>
                    <?php if ($can_delete): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger js-face-clear"
                                data-employee="<?= (int) $employee_id ?>" data-model="<?= htmlspecialchars($model) ?>"
                                style="font-size:11px;padding:2px 8px;white-space:nowrap;">
                            <i class="ri-delete-bin-line me-1"></i>Remove face
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($count === 0): ?>
                <div style="font-size:12.5px;color:var(--v2-text-2,#908c9c);padding:2px 0;"><i class="ri-information-line me-1"></i>No face registered on the kiosk yet.</div>
            <?php else: ?>
                <div style="display:flex;flex-wrap:wrap;gap:8px;">
                    <?php foreach ($rows as $r) echo _fs_tile($r, $who); ?>
                </div>
                <div style="font-size:11px;color:var(--v2-text-3,#918d9c);margin-top:8px;">
                    Model <?= htmlspecialchars($model) ?><?= $last ? ' · last updated ' . date('M d, Y g:i A', strtotime($last)) : '' ?>
                </div>
            <?php endif; ?>
        </div>
        <?= render_photo_viewer() ?>
        <?php if ($can_delete): ?>
        <script>
        // Delegated once per page — a page may render more than one card.
        (function () {
            if (window.__faceDeleteBound) return;
            window.__faceDeleteBound = true;

            document.addEventListener('click', async function (e) {
                const btn = e.target.closest('.js-face-clear');
                if (!btn) return;

                const ask = await Swal.fire({
                    icon: 'warning',
                    title: 'Are you sure?',
                    html: 'Remove <b>every registered face</b> for this employee?<br><small class="text-muted">They will not be recognised at the kiosk until they register again. Punches already recorded are not affected.</small>',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, remove it',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#e15b64',
                    reverseButtons: true,
                });
                if (!ask.isConfirmed) return;

                btn.disabled = true;
                try {
                    const res = await fetch('ajax.php?action=delete_employee_face', {
                        method: 'POST',
                        body: new URLSearchParams({ employee_id: btn.dataset.employee, model: btn.dataset.model }),
                    });
                    const json = await res.json();
                    if (json && json.result) {
                        await Swal.fire({ icon: 'success', title: 'Removed', text: json.message || 'Face removed', timer: 1400, showConfirmButton: false });
                        location.reload();
                    } else {
                        btn.disabled = false;
                        Swal.fire({ icon: 'error', title: 'Error', text: (json && json.message) || 'Failed to remove the face.' });
                    }
                } catch (err) {
                    btn.disabled = false;
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to remove the face. Please try again.' });
                }
            });
        })();
        </script>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }
}
