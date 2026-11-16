/*
 * HR modules — shared front-end helpers for every HR screen (Employee Details
 * HR tabs, HR Overview, Announcements, Document Requests, Offboarding) and the
 * employee portal's HR tab.
 *
 *   HR.post(action, data)   POST to hr-ajax.php; resolves to the JSON answer
 *   HR.done(j, okTitle)     error popup, or success toast then reload
 *   HR.form(spec)           SweetAlert form from a field list → values | null
 *   HR.confirm(spec)        yes/no → boolean
 *
 * Safe to load more than once (the v2 shell re-runs page scripts on every
 * in-place navigation). The CSRF header is added by assets2/js/csrf.js, which
 * patches fetch() on every shell that loads this.
 */
(function () {
    'use strict';

    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    if (!document.getElementById('hr-mod-css')) {
        var css = document.createElement('style');
        css.id = 'hr-mod-css';
        css.textContent = [
            '.swal2-html-container.hrf-wrap{margin:.8em 1.2em 0;text-align:left;overflow:visible;}',
            '.hrf{display:grid;grid-template-columns:1fr 1fr;gap:10px 12px;text-align:left;font-size:13px;}',
            '.hrf .wide{grid-column:1/-1;}',
            '.hrf label{display:block;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--v2-text-3,#8a8696);margin:0 0 4px;}',
            '.hrf label .req{color:#e15b64;margin-left:2px;}',
            '.hrf input:not([type=checkbox]),.hrf select,.hrf textarea{width:100%;font-size:13px;padding:7px 10px;border:1px solid var(--v2-border,#dcd8e6);border-radius:8px;background:var(--v2-surface,#fff);color:var(--v2-text,#222);}',
            '.hrf textarea{min-height:88px;resize:vertical;font-family:inherit;line-height:1.45;}',
            '.hrf input:focus,.hrf select:focus,.hrf textarea:focus{outline:none;border-color:var(--app-primary,#673bb6);box-shadow:0 0 0 3px rgba(103,59,182,.14);}',
            '.hrf .chk{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--v2-text,#333);text-transform:none;letter-spacing:0;font-weight:600;margin:4px 0 0;}',
            '.hrf .chk input{width:16px;height:16px;flex-shrink:0;}',
            '.hrf .help{font-size:11px;color:var(--v2-text-3,#918d9c);margin-top:3px;line-height:1.4;}',
            '.hrf .note{grid-column:1/-1;font-size:12px;line-height:1.5;padding:8px 11px;border-radius:8px;background:var(--v2-surface-2,#f6f4fb);border:1px solid var(--v2-border,#e8e4f2);color:var(--v2-text-2,#5b5670);}',
            '@media (max-width:560px){.hrf{grid-template-columns:1fr;}.swal2-html-container.hrf-wrap{margin:.6em .4em 0;}}'
        ].join('\n');
        document.head.appendChild(css);
    }

    function fieldHtml(f, values) {
        var id = 'hrf-' + f.name;
        var v = values && values[f.name] != null ? values[f.name] : (f.value != null ? f.value : '');
        var wide = f.wide || f.type === 'textarea' || f.type === 'note' ? ' wide' : '';
        if (f.type === 'note') return '<div class="note">' + f.html + '</div>';
        if (f.type === 'checkbox') {
            var on = values && values[f.name] != null ? Number(values[f.name]) === 1 || values[f.name] === true : !!f.checked;
            return '<div class="' + wide.trim() + ' wide"><label class="chk"><input type="checkbox" id="' + id + '"' + (on ? ' checked' : '') + '> ' + esc(f.label) + '</label>'
                + (f.help ? '<div class="help">' + esc(f.help) + '</div>' : '') + '</div>';
        }
        var lab = '<label for="' + id + '">' + esc(f.label) + (f.required ? '<span class="req">*</span>' : '') + '</label>';
        var input;
        if (f.type === 'select') {
            // data-no-cs: keep the global custom-select skin (custom-select.js)
            // off these. A SweetAlert popup is not a .modal, so its menu would
            // go to <body> and outlive the popup as an unstyled orphan — a
            // screen-sized chevron after the dialog closes. Plain selects are
            // styled by the .hrf rules above.
            input = '<select id="' + id + '" data-no-cs>' + (f.required ? '' : '<option value="">—</option>')
                + (f.options || []).map(function (o) {
                    var val = typeof o === 'object' ? o.value : o, text = typeof o === 'object' ? o.label : o;
                    return '<option value="' + esc(val) + '"' + (String(val) === String(v) ? ' selected' : '') + '>' + esc(text) + '</option>';
                }).join('') + '</select>';
        } else if (f.type === 'textarea') {
            input = '<textarea id="' + id + '"' + (f.max ? ' maxlength="' + f.max + '"' : '') + ' placeholder="' + esc(f.placeholder || '') + '"'
                + (f.rows ? ' rows="' + f.rows + '"' : '') + '>' + esc(v) + '</textarea>';
        } else if (f.type === 'file') {
            input = '<input type="file" id="' + id + '"' + (f.accept ? ' accept="' + esc(f.accept) + '"' : '') + '>';
        } else {
            input = '<input type="' + (f.type || 'text') + '" id="' + id + '" value="' + esc(v) + '"'
                + (f.max ? ' maxlength="' + f.max + '"' : '') + (f.step ? ' step="' + f.step + '"' : '')
                + (f.min != null ? ' min="' + esc(f.min) + '"' : '')
                + ' placeholder="' + esc(f.placeholder || '') + '">';
        }
        return '<div class="' + wide.trim() + '">' + lab + input + (f.help ? '<div class="help">' + esc(f.help) + '</div>' : '') + '</div>';
    }

    var HR = {
        esc: esc,

        /** "Nov 14, 2026" from "2026-11-14"; '—' for empty. */
        date: function (ymd) {
            if (!ymd) return '—';
            var d = new Date(String(ymd).slice(0, 10) + 'T00:00:00');
            return isNaN(d) ? esc(ymd) : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
        },

        post: function (action, data) {
            var body = data instanceof FormData ? data : new FormData();
            if (!(data instanceof FormData) && data) {
                Object.keys(data).forEach(function (k) {
                    var val = data[k];
                    if (val === undefined || val === null) return;
                    if (Array.isArray(val)) val.forEach(function (x) { body.append(k + '[]', x); });
                    else body.append(k, val === true ? 1 : val === false ? '' : val);
                });
            }
            return fetch('hr-ajax.php?action=' + encodeURIComponent(action), { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .catch(function () { return { result: false, message: 'The server sent an unreadable answer. Please try again.' }; });
        },

        /** Error popup, or a short success toast followed by a reload. */
        done: function (j, okTitle, opts) {
            opts = opts || {};
            if (!j || !j.result) {
                Swal.fire({ icon: 'error', title: opts.failTitle || 'Not saved', text: (j && j.message) || 'Could not save.' });
                return false;
            }
            Swal.fire({ icon: 'success', title: okTitle || 'Saved', text: j.message || '', timer: opts.timer || 1500, showConfirmButton: false })
                .then(function () { if (opts.reload !== false) location.reload(); });
            return true;
        },

        /**
         * A form in a SweetAlert. spec: {title, fields:[{name,label,type,options,
         * required,max,help,wide,value,checked,accept}], values, confirmText, width}.
         * Resolves to {name: value} (File for type=file, 1/'' for checkboxes) or null.
         */
        form: function (spec) {
            var fields = spec.fields || [];
            return Swal.fire({
                title: spec.title,
                html: '<div class="hrf">' + fields.map(function (f) { return fieldHtml(f, spec.values); }).join('') + '</div>',
                width: spec.width || 640,
                showCancelButton: true,
                confirmButtonText: spec.confirmText || 'Save',
                reverseButtons: true,
                focusConfirm: false,
                customClass: { htmlContainer: 'hrf-wrap' },
                didOpen: function () {
                    var first = Swal.getHtmlContainer().querySelector('input:not([type=checkbox]),select,textarea');
                    if (first && !spec.noAutofocus) first.focus();
                },
                preConfirm: function () {
                    var out = {};
                    for (var i = 0; i < fields.length; i++) {
                        var f = fields[i];
                        if (f.type === 'note') continue;
                        var el = document.getElementById('hrf-' + f.name);
                        if (!el) continue;
                        var v;
                        if (f.type === 'checkbox') v = el.checked ? 1 : '';
                        else if (f.type === 'file') v = el.files && el.files[0] ? el.files[0] : null;
                        else v = String(el.value).trim();
                        if (f.required && (v === '' || v === null)) {
                            Swal.showValidationMessage(f.label + ' is required.');
                            el.focus();
                            return false;
                        }
                        if (f.type === 'file' && v && f.maxBytes && v.size > f.maxBytes) {
                            Swal.showValidationMessage('That file is too large (limit ' + Math.round(f.maxBytes / 1048576) + ' MB).');
                            return false;
                        }
                        out[f.name] = v;
                    }
                    if (spec.validate) {
                        var msg = spec.validate(out);
                        if (msg) { Swal.showValidationMessage(msg); return false; }
                    }
                    return out;
                }
            }).then(function (r) { return r.isConfirmed ? r.value : null; });
        },

        confirm: function (spec) {
            return Swal.fire({
                icon: spec.icon || 'warning',
                title: spec.title,
                text: spec.text || '',
                html: spec.html,
                showCancelButton: true,
                confirmButtonText: spec.confirmText || 'Yes',
                confirmButtonColor: spec.danger ? '#e15b64' : undefined,
                reverseButtons: true
            }).then(function (r) { return r.isConfirmed; });
        }
    };

    window.HR = HR;
})();
