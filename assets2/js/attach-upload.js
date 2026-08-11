/* ============================================================================
   Attach Upload — shared one-file attachment picker with live preview.
   Pairs with assets2/css/attach-upload.css and payroll_save_attachment()
   (db_connect.php) on the server side. Used by the employee portal's
   "File a Request" modal and the admin loan form.

   Markup convention (auto-wired on DOMContentLoaded, or call AttachUpload.wire):

     <div class="att-up">
         <input type="file" name="attachment" hidden
                accept=".jpg,.jpeg,.png,.webp,.pdf">
         <button type="button" class="att-up-btn">
             <i class="ri-attachment-2"></i> Attach image or PDF…</button>
         <div class="att-up-hint">One file · max 5 MB — please compress your
             attachment (image or PDF).</div>
         <div class="att-up-prev"></div>
     </div>

   Client-side checks mirror the server rule (image/pdf, ≤ 5 MB) so the user
   hears about an oversized file instantly — the server check stays the
   authority. AttachUpload.viewHTML(file) renders the "view" side for an
   ALREADY-STORED uploads/<file>: an image becomes a thumbnail card, a PDF a
   document chip; both open the file in a new tab.
   ========================================================================= */
(function (window, document) {
    'use strict';

    var MAX_BYTES = 5 * 1024 * 1024;
    var OK_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    function fmtSize(b) {
        if (b >= 1024 * 1024) return (b / (1024 * 1024)).toFixed(1) + ' MB';
        return Math.max(1, Math.round(b / 1024)) + ' KB';
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function clear(box) {
        var input = box.querySelector('input[type="file"]');
        var prev = box.querySelector('.att-up-prev');
        if (input) input.value = '';
        if (prev) { prev.innerHTML = ''; prev.style.display = 'none'; }
        box.classList.remove('has-file');
    }

    function render(box, file) {
        var prev = box.querySelector('.att-up-prev');
        if (!prev) return;
        var isImg = file.type.indexOf('image/') === 0;
        var html = '<div class="att-up-card">';
        if (isImg) {
            html += '<img class="att-up-thumb" alt="attachment preview" src="' + URL.createObjectURL(file) + '">';
        } else {
            html += '<span class="att-up-pdf"><i class="ri-file-pdf-2-fill"></i></span>';
        }
        html += '<span class="att-up-meta"><span class="att-up-name">' + esc(file.name) + '</span>'
            + '<span class="att-up-size">' + fmtSize(file.size) + '</span></span>'
            + '<button type="button" class="att-up-x" title="Remove attachment"><i class="ri-close-line"></i></button>'
            + '</div>';
        prev.innerHTML = html;
        // 'block', not '' — the stylesheet hides .att-up-prev by default, and an
        // empty inline value falls back to that display:none.
        prev.style.display = 'block';
        box.classList.add('has-file');
        prev.querySelector('.att-up-x').addEventListener('click', function () { clear(box); });
    }

    function accept(box, input, f) {
        if (!f) { clear(box); return; }
        if (OK_TYPES.indexOf(f.type) === -1) {
            clear(box);
            if (window.Swal) Swal.fire({ icon: 'error', title: 'Not supported', text: 'Only an image (JPG/PNG/WebP) or a PDF can be attached.' });
            return;
        }
        if (f.size > MAX_BYTES) {
            clear(box);
            if (window.Swal) Swal.fire({ icon: 'error', title: 'File too large', text: 'That file is ' + fmtSize(f.size) + ' — the limit is 5 MB. Please compress it first.' });
            return;
        }
        // A dropped file must land in the input too — that is what the form
        // actually submits.
        if (!input.files || input.files[0] !== f) {
            try {
                var dt = new DataTransfer();
                dt.items.add(f);
                input.files = dt.files;
            } catch (e) { /* very old browsers: picker path still works */ }
        }
        render(box, f);
    }

    function wire(box) {
        if (!box || box.dataset.attUpDone) return;
        box.dataset.attUpDone = '1';
        var input = box.querySelector('input[type="file"]');
        var btn = box.querySelector('.att-up-btn');
        if (!input || !btn) return;
        btn.addEventListener('click', function () { input.click(); });
        input.addEventListener('change', function () { accept(box, input, input.files && input.files[0]); });
        // Drag & drop straight onto the box
        ['dragover', 'dragenter'].forEach(function (ev) {
            box.addEventListener(ev, function (e) { e.preventDefault(); box.classList.add('dragover'); });
        });
        ['dragleave', 'dragend'].forEach(function (ev) {
            box.addEventListener(ev, function () { box.classList.remove('dragover'); });
        });
        box.addEventListener('drop', function (e) {
            e.preventDefault();
            box.classList.remove('dragover');
            var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (f) accept(box, input, f);
        });
    }

    /* The "view" side for a stored attachment (uploads/<file>). Clicking opens
       the in-app viewer overlay below — never a new tab. The href stays real
       so middle-click / "open in new tab" still work as an escape hatch. */
    function viewHTML(file, label) {
        if (!file) return '';
        var url = 'uploads/' + encodeURIComponent(file);
        var isPdf = /\.pdf$/i.test(file);
        if (isPdf) {
            return '<a class="att-view att-view-pdf" href="' + url + '" data-att-name="' + esc(file) + '">'
                + '<i class="ri-file-pdf-2-fill"></i><span>' + esc(label || 'View attached PDF') + '</span>'
                + '<i class="ri-eye-line att-view-open"></i></a>';
        }
        return '<a class="att-view att-view-img" href="' + url + '" data-att-name="' + esc(file) + '" title="View">'
            + '<img src="' + url + '" alt="attachment">'
            + '<span class="att-view-zoom"><i class="ri-zoom-in-line"></i> ' + esc(label || 'View attachment') + '</span></a>';
    }

    /* ── In-app viewer overlay — image lightbox / embedded PDF ──────────────
       One overlay, built lazily, parented to <body> and layered above every
       bootstrap modal (they cap at ~1055). Closes on ×, backdrop, or Esc. */
    var viewer = null;

    function buildViewer() {
        if (viewer) return viewer;
        viewer = document.createElement('div');
        viewer.className = 'att-viewer';
        viewer.innerHTML =
            '<div class="att-viewer-card">'
            + '<div class="att-viewer-head">'
            + '<span class="att-viewer-title"><i class="ri-attachment-2"></i><span class="t"></span></span>'
            + '<span class="att-viewer-acts">'
            + '<a class="att-viewer-open" href="#" target="_blank" rel="noopener" title="Open in a new tab"><i class="ri-external-link-line"></i></a>'
            + '<button type="button" class="att-viewer-x" title="Close"><i class="ri-close-line"></i></button>'
            + '</span></div>'
            + '<div class="att-viewer-body"></div>'
            + '</div>';
        document.body.appendChild(viewer);
        viewer.addEventListener('click', function (e) { if (e.target === viewer) close(); });
        viewer.querySelector('.att-viewer-x').addEventListener('click', close);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && viewer.classList.contains('open')) { e.stopPropagation(); close(); }
        }, true);
        function close() {
            viewer.classList.remove('open');
            viewer.querySelector('.att-viewer-body').innerHTML = '';   // stop PDF loads
        }
        return viewer;
    }

    function open(url, name) {
        var v = buildViewer();
        var isPdf = /\.pdf(\?|$)/i.test(url);
        v.querySelector('.att-viewer-title .t').textContent = name || url.split('/').pop();
        v.querySelector('.att-viewer-open').href = url;
        v.querySelector('.att-viewer-body').innerHTML = isPdf
            ? '<iframe src="' + url + '" title="attachment"></iframe>'
            : '<img src="' + url + '" alt="attachment">';
        v.classList.add('open');
    }

    /* Any .att-view anchor — whether rendered by viewHTML() here or by PHP on
       the admin pages — opens the viewer instead of navigating. */
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a.att-view');
        if (!a) return;
        e.preventDefault();
        open(a.getAttribute('href'), a.getAttribute('data-att-name') || '');
    });

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('.att-up'), wire);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();

    window.AttachUpload = { wire: wire, clear: clear, viewHTML: viewHTML, open: open, scan: boot };
})(window, document);
