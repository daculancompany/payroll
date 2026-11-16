<?php
// Site Settings — the system's own name, contact details and cover image.
//
// Saved by Action::save_settings() (ajax.php?action=save_site_settings), which
// keeps one row in `system_settings` and mirrors it into $_SESSION.
//
// The table is created by migrations/2026_11_system_settings.sql. It had
// never been created on this database, so this page opened with a PHP notice
// ("Trying to get property 'num_rows' of non-object") and an empty form, and
// Save threw. The query below no longer assumes the table is there: a missing
// one is reported in plain words with the fix, instead of a raw notice.

require_once __DIR__ . '/includes/require_login.php';

include 'db_connect.php';
require_once __DIR__ . '/includes/brand.php';

$meta        = [];
$ss_no_table = false;

$ss_has = $conn->query("SHOW TABLES LIKE 'system_settings'");
if (!$ss_has || $ss_has->num_rows === 0) {
    $ss_no_table = true;
} else {
    $qry = $conn->query("SELECT * FROM system_settings LIMIT 1");
    if ($qry && $qry->num_rows > 0) {
        $meta = $qry->fetch_assoc();
    }
}

/** Current cover image as a usable src, or '' when none is set. */
$ss_img = '';
if (!empty($meta['cover_img'])) {
    $ss_path = 'assets/img/' . $meta['cover_img'];
    // Cache-bust on the file's own mtime so a replaced image shows at once
    // instead of the browser serving yesterday's copy.
    $ss_abs  = __DIR__ . '/' . $ss_path;
    $ss_img  = $ss_path . (is_file($ss_abs) ? '?v=' . filemtime($ss_abs) : '');
}
$ss_val = function ($k) use ($meta) { return htmlspecialchars((string) ($meta[$k] ?? ''), ENT_QUOTES); };
?>
<style>
/* Scoped to this page. Colours come from the shell's tokens so the card reads
   the same in the light, dark and colour themes. */
.ss-wrap { max-width: 860px; }
.ss-card { background: var(--v2-surface, #fff); border: 1px solid var(--v2-border, #e4e8ef); border-radius: 14px; }
.ss-head { padding: 16px 20px; border-bottom: 1px solid var(--v2-border, #e4e8ef); }
.ss-head h5 { margin: 0; font-size: 15px; font-weight: 700; color: var(--v2-text, #0f172a); }
.ss-head p { margin: 3px 0 0; font-size: 12.5px; color: var(--v2-text-2, #475569); }
.ss-body { padding: 20px; }
.ss-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.ss-grid .ss-full { grid-column: 1 / -1; }
@media (max-width: 767.98px) { .ss-grid { grid-template-columns: 1fr; } }
.ss-label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .4px; color: var(--v2-text-2, #475569); margin-bottom: 5px; }
.ss-hint { font-size: 11.5px; color: var(--v2-text-3, #7b8799); margin-top: 4px; }

/* Cover image: drop target that doubles as the preview, the pattern the
   employee import dialog already uses. */
.ss-drop { position: relative; display: flex; align-items: center; gap: 16px; padding: 14px;
           border: 2px dashed var(--v2-border-strong, #d3d9e3); border-radius: 12px;
           background: var(--v2-surface-2, #f7f8fb); transition: border-color .15s, background .15s; }
.ss-drop.dragover { border-color: var(--app-primary, #673bb6); background: var(--app-tint, #f4f1fa); }
.ss-drop input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.ss-thumb { width: 104px; height: 104px; flex-shrink: 0; border-radius: 10px; object-fit: cover;
            border: 1px solid var(--v2-border, #e4e8ef); background: var(--v2-surface-3, #eef1f6); }
.ss-thumb-empty { display: flex; align-items: center; justify-content: center;
                  color: var(--v2-text-3, #7b8799); font-size: 26px; }
.ss-dropmain { flex: 1; min-width: 0; }
.ss-dropmain b { font-size: 13.5px; color: var(--v2-text, #0f172a); }
.ss-file { font-size: 12px; color: var(--v2-text-2, #475569); margin-top: 4px; word-break: break-all; }
.ss-foot { display: flex; gap: 10px; align-items: center; justify-content: flex-end;
           padding: 14px 20px; border-top: 1px solid var(--v2-border, #e4e8ef); }
</style>

<div class="container-fluid">
<div class="ss-wrap">

    <?php if ($ss_no_table): ?>
    <div class="alert alert-warning">
        <i class="ri-error-warning-line me-1"></i>
        The <code>system_settings</code> table is missing, so nothing can be saved yet.
        Run <code>migrations/2026_11_system_settings.sql</code> on this database.
    </div>
    <?php endif; ?>

    <form id="manage-settings" autocomplete="off">
        <div class="ss-card">
            <div class="ss-head">
                <h5><i class="ri-settings-3-line me-2" style="color:var(--app-primary,#673bb6);"></i>Site Settings</h5>
                <p>The system's own name and contact details. They appear on printed reports and in the browser tab.</p>
            </div>

            <div class="ss-body">
                <div class="ss-grid">
                    <div>
                        <label class="ss-label" for="name">System Name</label>
                        <input type="text" class="form-control" id="name" name="name" maxlength="150"
                               value="<?= $ss_val('name') ?>" placeholder="<?= htmlspecialchars(APP_BRAND, ENT_QUOTES) ?>" required>
                        <div class="ss-hint">Shown in the browser tab and on report headers.</div>
                    </div>

                    <div>
                        <label class="ss-label" for="contact">Contact</label>
                        <input type="text" class="form-control" id="contact" name="contact" maxlength="80"
                               value="<?= $ss_val('contact') ?>" placeholder="e.g. (088) 123 4567" required>
                        <div class="ss-hint">A number staff can actually reach.</div>
                    </div>

                    <div class="ss-full">
                        <label class="ss-label" for="email">Email</label>
                        <input type="email" class="form-control" id="email" name="email" maxlength="150"
                               value="<?= $ss_val('email') ?>" placeholder="hr@yourcompany.com" required>
                    </div>

                    <div class="ss-full">
                        <label class="ss-label" for="about">About Content</label>
                        <textarea name="about" id="about" class="form-control text-jqte" rows="4"
                                  placeholder="A short description of this system."><?= htmlspecialchars((string) ($meta['about_content'] ?? '')) ?></textarea>
                    </div>

                    <div class="ss-full">
                        <label class="ss-label">Cover Image</label>
                        <!-- The whole box is the file input: click anywhere, or drop
                             a file on it. The thumbnail is the live preview. -->
                        <div class="ss-drop" id="ss-drop">
                            <input type="file" name="img" id="ss-file" accept="image/jpeg,image/png,image/gif,image/webp"
                                   aria-label="Choose a cover image">
                            <?php if ($ss_img !== ''): ?>
                                <img src="<?= htmlspecialchars($ss_img, ENT_QUOTES) ?>" alt="" class="ss-thumb" id="ss-thumb">
                            <?php else: ?>
                                <div class="ss-thumb ss-thumb-empty" id="ss-thumb-empty"><i class="ri-image-line"></i></div>
                                <img src="" alt="" class="ss-thumb" id="ss-thumb" style="display:none;">
                            <?php endif; ?>
                            <div class="ss-dropmain">
                                <b>Click to choose an image, or drop one here</b>
                                <div class="ss-hint">JPG, PNG, GIF or WebP, up to 4 MB. Leave it alone to keep the current image.</div>
                                <div class="ss-file" id="ss-filename"><?= $ss_img !== '' ? 'Current: ' . htmlspecialchars((string) $meta['cover_img']) : '' ?></div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="ss-clear"
                                    style="display:none;position:relative;z-index:2;" onclick="ssClear()">
                                <i class="ri-close-line me-1"></i>Undo
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ss-foot">
                <button type="submit" class="btn btn-primary" id="ss-save">
                    <i class="ri-save-line me-1"></i>Save changes
                </button>
            </div>
        </div>
    </form>
</div>
</div>

<script>
(function () {
    var MAX_BYTES = 4 * 1024 * 1024;

    var drop  = document.getElementById('ss-drop');
    var file  = document.getElementById('ss-file');
    var thumb = document.getElementById('ss-thumb');
    var empty = document.getElementById('ss-thumb-empty');
    var label = document.getElementById('ss-filename');
    var undo  = document.getElementById('ss-clear');

    // What the page loaded with, so "Undo" can put the preview back rather
    // than leaving a chosen-then-rejected image on screen.
    var originalSrc   = thumb ? thumb.getAttribute('src') || '' : '';
    var originalLabel = label ? label.textContent : '';

    function showPreview(src) {
        if (!thumb) return;
        thumb.src = src;
        thumb.style.display = '';
        if (empty) empty.style.display = 'none';
    }

    function restore() {
        if (originalSrc) {
            showPreview(originalSrc);
        } else if (thumb) {
            thumb.style.display = 'none';
            if (empty) empty.style.display = '';
        }
        if (label) label.textContent = originalLabel;
        if (undo) undo.style.display = 'none';
    }

    window.ssClear = function () {
        file.value = '';
        restore();
    };

    function take(f) {
        if (!f) return;
        if (!/^image\/(jpeg|png|gif|webp)$/.test(f.type)) {
            Swal.fire({ icon: 'error', title: 'Not an image we can use',
                        text: 'Choose a JPG, PNG, GIF or WebP file.' });
            window.ssClear();
            return;
        }
        if (f.size > MAX_BYTES) {
            Swal.fire({ icon: 'error', title: 'That image is too big',
                        text: 'It is ' + (f.size / 1048576).toFixed(1) + ' MB. The limit is 4 MB.' });
            window.ssClear();
            return;
        }
        var reader = new FileReader();
        reader.onload = function (e) { showPreview(e.target.result); };
        reader.readAsDataURL(f);
        if (label) label.textContent = f.name + ' · ' + (f.size / 1024).toFixed(0) + ' KB';
        if (undo) undo.style.display = '';
    }

    file.addEventListener('change', function () { take(this.files && this.files[0]); });

    ['dragenter', 'dragover'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('dragover'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('dragover'); });
    });
    drop.addEventListener('drop', function (e) {
        var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (!f) return;
        // Put the dropped file INTO the input, or the form would post without
        // it — a drop that previews but does not upload is the worst outcome.
        try {
            var dt = new DataTransfer();
            dt.items.add(f);
            file.files = dt.files;
            take(f);
        } catch (err) {
            Swal.fire({ icon: 'info', title: 'Use the file picker',
                        text: 'This browser cannot accept a dropped file here. Click the box to choose one.' });
        }
    });

    // Runs once jQuery is loaded (this fragment is parsed before the footer
    // scripts). The jQuery-TE editor plugin is optional: without it the
    // textarea stays a plain textarea instead of killing the whole script.
    document.addEventListener('DOMContentLoaded', function () {
        if (window.jQuery && $.fn.jqte) $('.text-jqte').jqte();

        $('#manage-settings').submit(function (e) {
            e.preventDefault();
            var $btn = $('#ss-save').prop('disabled', true);
            if (typeof start_load === 'function') start_load();

            $.ajax({
                url: 'ajax.php?action=save_site_settings',
                data: new FormData(this),
                cache: false, contentType: false, processData: false, method: 'POST',
                success: function (resp) {
                    if (typeof end_load === 'function') end_load();
                    $btn.prop('disabled', false);

                    // save_settings() answers 1 on success, or a JSON object
                    // with a message when the upload was refused. The old page
                    // treated anything that was not 1 as silence.
                    var txt = (typeof resp === 'string') ? resp.trim() : resp;
                    if (txt == 1) {
                        if (typeof alert_toast === 'function') alert_toast('Settings saved.', 'success');
                        setTimeout(function () { location.reload(); }, 900);
                        return;
                    }
                    var msg = 'Could not save the settings.';
                    try {
                        var j = (typeof txt === 'string') ? JSON.parse(txt) : txt;
                        if (j && j.message) msg = j.message;
                    } catch (err) { if (typeof txt === 'string' && txt) msg = txt; }
                    Swal.fire({ icon: 'error', title: 'Site Settings', text: msg });
                },
                error: function (xhr) {
                    if (typeof end_load === 'function') end_load();
                    $btn.prop('disabled', false);
                    Swal.fire({ icon: 'error', title: 'Site Settings',
                                text: 'The server did not accept that (' + xhr.status + '). Reload and try again.' });
                },
            });
        });
    });
})();
</script>
