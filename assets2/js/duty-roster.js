/**
 * Duty Roster — the per-cutoff day grid for rotating staff.
 *
 * One request (duty_roster_data) brings back the whole cutoff; everything after
 * that is drawn here and re-drawn on filter changes. Edits are held in a local
 * `dirty` map and written in one Save, because a 40 x 15 grid painted by drag
 * would otherwise fire hundreds of requests.
 */
$(document).ready(function () {

    var CAN_EDIT = window.DR_CAN_EDIT !== false;
    var CAN_QUICKVIEW = window.DR_CAN_QUICKVIEW !== false;

    // Shift colours are assigned by position, not stored, so adding a shift
    // never forces a migration. Night shifts get the dark end of the ramp so a
    // graveyard block reads differently from a day one at a glance.
    var DAY_COLORS   = ['#d6e4ff', '#d9f7be', '#fff1b8', '#ffd8bf', '#e4d7ff', '#b5f5ec', '#ffd6e7', '#f4ffb8'];
    var NIGHT_COLORS = ['#4c4a6b', '#3f5c8a', '#5c4a7a', '#2f4858', '#584a3f', '#4a5c4a'];

    // dept is kept as a STRING: '' means nothing chosen yet, '0' means every
    // department. Collapsing the two would make a bare page load pull the whole
    // company's grid.
    var S = { period: '', dept: '', area: '', days: [], shifts: [], employees: [], cells: {}, zones: {}, leaves: {}, from: '', to: '' };
    var shiftById = {};
    // Below this many hours between yesterday's shift end and today's shift
    // start, the pair is flagged the same way a leave clash is — a planning
    // mistake, not a valid roster. Not user-configurable yet; a fixed floor
    // beats no check at all, and hospitals rarely negotiate this number.
    var MIN_REST_HOURS = 8;
    var dirty = new Map();
    // What the next click writes. kind stays null until the planner picks from
    // the palette — nothing is chosen for them. It used to default to 'clear',
    // so a first click ERASED: invisible on an empty grid, destructive on a
    // planned one, and the only trace was an unsaved-changes prompt later.
    // Now every paint action asks requirePaint() first and says what to do.
    var paint = { kind: null, s: null, alsoRest: false };
    var painting = false;
    var pendingRecompute = [];

    var $grid = $('#dr-grid');
    var $period = $('#dr-period');
    var $dept = $('#dr-dept');
    var $area = $('#dr-area');   // absent (0 length) for an area-scoped session — every $area.* call below already no-ops on an empty jQuery set
    var $search = $('#dr-search');
    var $min = $('#dr-min');

    /* ── helpers ─────────────────────────────────────────────────────────── */

    function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
    // esc() leaves double quotes alone, which is fine inside element text and
    // fatal inside an attribute — a shift described as 7-3 "day" would end the
    // attribute early and spill the rest into markup.
    function escAttr(s) { return esc(s).replace(/"/g, '&quot;'); }
    function key(e, d) { return e + '|' + d; }

    /* ── tooltip ─────────────────────────────────────────────────────────── */

    // One body-parented, position:fixed bubble shared by every [data-tip] on the
    // page. It has to live outside the palette: that strip is an overflow-x
    // scroller, so a tooltip drawn inside it is clipped at the very edge it
    // needs to cross. First line is treated as the heading.
    var $tip = $('<div class="dr-tip"></div>').appendTo('body');
    var tipTimer = null;

    function showTip($el) {
        var raw = String($el.attr('data-tip') || '');
        if (!raw) return;
        var lines = raw.split('\n');
        var html = '<b>' + esc(lines[0]) + '</b>';
        if (lines.length > 1) html += '\n<span class="k">' + esc(lines.slice(1).join('\n')) + '</span>';
        $tip.html(html);

        var r = $el[0].getBoundingClientRect();
        $tip.addClass('show');
        var tw = $tip.outerWidth(), th = $tip.outerHeight();
        var left = r.left + r.width / 2 - tw / 2;
        // Clamped to the viewport: the last swatch in the strip and the tools on
        // the far right would otherwise hang off the edge.
        left = Math.max(8, Math.min(left, window.innerWidth - tw - 8));
        var top = r.bottom + 8;
        if (top + th > window.innerHeight - 8) top = r.top - th - 8;
        $tip.css({ left: left + 'px', top: top + 'px' });
    }
    function hideTip() { clearTimeout(tipTimer); $tip.removeClass('show'); }

    $(document).on('mouseenter focus', '[data-tip]', function () {
        var $el = $(this);
        clearTimeout(tipTimer);
        tipTimer = setTimeout(function () { showTip($el); }, 260);
    });
    $(document).on('mouseleave blur mousedown', '[data-tip]', hideTip);
    $(window).on('scroll', hideTip);

    // "AM / 7-3 (7AM-3PM)" → "AM". The grid cell is 44px wide, so the label has
    // to be the part a planner actually says out loud.
    function shortLabel(desc) {
        var s = String(desc || '').split('(')[0];
        if (s.indexOf('/') !== -1) s = s.split('/')[0];
        return s.trim().substring(0, 5) || '?';
    }

    function shiftColor(sh) {
        var pool = sh.noc ? NIGHT_COLORS : DAY_COLORS;
        return pool[sh.idx % pool.length];
    }
    function shiftTextColor(sh) { return sh.noc ? '#fff' : '#28223b'; }

    // The value a cell would have if saved right now: a pending edit wins over
    // what the server sent.
    function cellValue(e, d) {
        var k = key(e, d);
        if (dirty.has(k)) return dirty.get(k);
        var c = S.cells[k];
        if (!c) return null;
        return { s: c.s, r: c.r, st: c.st, ps: c.ps, pr: c.pr, by: c.by, at: c.at };
    }

    function zoneOf(e, d) { return S.zones[key(e, d)] || 'free'; }

    // Approved leave on this day, or null. A rest day agrees with leave, so the
    // clash is only worth showing when an actual SHIFT is planned — flagging
    // "rest day during leave" would be noise, and noise is how a warning gets
    // ignored on the day it matters.
    function leaveOf(e, d) { return (S.leaves && S.leaves[key(e, d)]) || null; }

    /* ── back-to-back rest check ─────────────────────────────────────────── */
    // "6:00 AM" / "2:00 PM" (the format duty_roster_data sends) → minutes since
    // midnight. Parsed by hand rather than through Date(): a bare time string
    // fed to the constructor is a browser-dependent guess, and this runs once
    // per cell on every render.
    function timeToMinutes(t) {
        var m = /^(\d{1,2}):(\d{2})\s*([AP]M)$/i.exec(String(t || '').trim());
        if (!m) return null;
        var h = parseInt(m[1], 10) % 12;
        if (m[3].toUpperCase() === 'PM') h += 12;
        return h * 60 + parseInt(m[2], 10);
    }

    function dateIndex(date) {
        for (var i = 0; i < S.days.length; i++) if (S.days[i].date === date) return i;
        return -1;
    }
    function prevDateOf(date) { var i = dateIndex(date); return i > 0 ? S.days[i - 1].date : null; }
    function nextDateOf(date) {
        var i = dateIndex(date);
        return (i >= 0 && i < S.days.length - 1) ? S.days[i + 1].date : null;
    }

    // Hours of rest between yesterday's shift end and today's shift start, or
    // null when either day has no shift to measure from (a plain rest day, an
    // unplotted day, or the first day on screen). Both ends resolved off the
    // SAME shift-id lookup cellHtml renders from, so this can never disagree
    // with what the cell itself is showing.
    function restGapHours(empId, date, shiftId) {
        var prevDate = prevDateOf(date);
        if (!prevDate || !shiftId) return null;
        var prevV = cellValue(empId, prevDate);
        var prevShiftId = prevV ? prevV.s : null;
        if (!prevShiftId) return null;
        var cur = shiftById[shiftId], prev = shiftById[prevShiftId];
        var curStart = cur && timeToMinutes(cur.start);
        var prevEnd = prev && timeToMinutes(prev.end);
        var prevStart = prev && timeToMinutes(prev.start);
        if (curStart == null || prevEnd == null || prevStart == null) return null;
        // An overnight shift's END clock time is numerically before its own
        // START — it lands on the FOLLOWING calendar day, one day closer to
        // "today" than a day shift's end is.
        var prevEndDay = (prevEnd <= prevStart) ? 1 : 0;
        var prevEndAbs = prevEndDay * 1440 + prevEnd;
        var curStartAbs = 1 * 1440 + curStart;   // "today" is always day 1 relative to "yesterday"
        return (curStartAbs - prevEndAbs) / 60;
    }

    function visibleEmployees() {
        var q = ($search.val() || '').toLowerCase().trim();
        if (!q) return S.employees;
        return S.employees.filter(function (emp) {
            return (emp.name || '').toLowerCase().indexOf(q) !== -1
                || (emp.employee_no || '').toLowerCase().indexOf(q) !== -1;
        });
    }

    function toast(icon, title, text) {
        if (window.Swal) Swal.fire({ icon: icon, title: title, text: text, timer: icon === 'success' ? 1800 : undefined, showConfirmButton: icon !== 'success' });
        else alert(text || title);
    }

    /* ── palette ─────────────────────────────────────────────────────────── */

    // The swatch carries the code and nothing else — the same fill the grid cell
    // gets, so the row reads as a colour key. Spelling the hours out beside each
    // one pushed a dozen shifts off the end of the strip; they are one hover
    // away instead, which is where you look once rather than read every time.
    function renderPalette() {
        if (!CAN_EDIT) return;
        var html = '';
        html += swatchHtml('rest', null, 'REST', '#f4f4f6', '#8c8998',
                           'Rest day\nThe employee is off duty. Counts as a plotted day, not a blank.');
        S.shifts.forEach(function (sh) {
            html += swatchHtml('shift', sh.id, shortLabel(sh.desc), shiftColor(sh), shiftTextColor(sh),
                               sh.desc + '\n' + sh.start + ' – ' + sh.end
                               + (sh.hours ? '  ·  ' + sh.hours + ' hrs' : '')
                               + (sh.noc ? '\nNight differential' : ''));
        });
        html += swatchHtml('clear', null, 'CLEAR', '', '',
                           'Clear the day\nRemoves it from the day grid — the employee falls back to their fixed shift roster.');
        $('#dr-palette').html(html);
        syncPalette();
    }

    function swatchHtml(kind, id, label, bg, fg, tip) {
        var style = bg ? ' style="background:' + bg + ';color:' + fg + ';"' : '';
        return '<div class="dr-swatch' + (kind === 'clear' ? ' dr-sw-clear' : '') + '"' + style
            + ' data-kind="' + kind + '" data-id="' + (id == null ? '' : id) + '"'
            + ' data-tip="' + escAttr(tip) + '">' + esc(label) + '</div>';
    }

    function syncPalette() {
        $('#dr-palette .dr-swatch').each(function () {
            var kind = $(this).data('kind');
            var id = $(this).data('id');
            var on = kind === paint.kind && (kind !== 'shift' || String(id) === String(paint.s));
            $(this).toggleClass('on', !!on);
        });
        // Only a shift swatch reads alsoRest — rest already carries r:1 on its
        // own, and clear wipes the day outright — so dim it the rest of the time
        // as a hint, without disabling the checkbox itself.
        $('#dr-also-rest').toggleClass('dimmed', paint.kind !== 'shift');
    }

    $(document).on('click', '#dr-palette .dr-swatch', function () {
        paint.kind = $(this).data('kind');
        paint.s = paint.kind === 'shift' ? parseInt($(this).data('id'), 10) : null;
        syncPalette();
    });

    $('#dr-also-rest-chk').on('change', function () {
        paint.alsoRest = this.checked;
        $('#dr-also-rest').toggleClass('on', paint.alsoRest);
    });

    /* ── grid ────────────────────────────────────────────────────────────── */

    // Top-bar chips. The page has no sidebar breadcrumb, so these are the only
    // thing saying which cutoff and ward is on screen.
    function syncChips() {
        var drafts = 0;
        Object.keys(S.cells).forEach(function (k) { if (S.cells[k].st === 0) drafts++; });
        $('#chip-period').html('<i class="ri-calendar-2-line"></i>' +
            (S.from ? esc(fmtRange(S.from, S.to)) : '—'));
        $('#chip-dept').html('<i class="ri-building-line"></i>' + esc(S.dept === '' ? 'No department' : deptLabel()));
        $('#chip-count').html('<i class="ri-team-line"></i>' + S.employees.length + ' employee' + (S.employees.length === 1 ? '' : 's'));
        $('#chip-drafts').toggle(drafts > 0).html('<i class="ri-draft-line"></i>' + drafts + ' draft day' + (drafts === 1 ? '' : 's'));
        syncActions(drafts);
    }

    // Publish and Discard both act on DRAFT days and do nothing without them.
    // They used to stay live on an all-published cutoff, so pressing Publish
    // opened a confirm that named the ward and the people it would notify, and
    // then published nothing — the dialog implied an effect the button no
    // longer had. Disabled, with the tooltip saying why.
    function syncActions(drafts) {
        if (!CAN_EDIT) return;
        var none = drafts === 0;
        $('#dr-publish, #dr-discard').prop('disabled', none);
        $('#dr-publish').attr('data-tip', none
            ? 'Nothing to publish\nEvery day in this cutoff is already published. Paint and Save first — new days start as drafts.'
            : 'Publish ' + drafts + ' draft day' + (drafts === 1 ? '' : 's') + '\nMakes them real and notifies the employees.');
        $('#dr-discard').attr('data-tip', none
            ? 'Nothing to discard\nThere are no unpublished drafts in this cutoff.'
            : 'Discard ' + drafts + ' draft day' + (drafts === 1 ? '' : 's') + '\nDeletes them. Published days are untouched.');

        // Export and import are per-department, so they mean nothing until one
        // is chosen — and the export would otherwise 400 on an empty period.
        var noDept = S.dept === '';
        $('#dr-print, #dr-export, #dr-import, #dr-pattern').prop('disabled', noDept);
    }

    // What the chosen department is called, for the chips and the confirms.
    // Publishing notifies real people, so the dialog has to name the scope —
    // "All departments" and one ward must never read the same.
    function deptLabel() {
        var t = ($dept.find('option:selected').text() || '').replace(/\s*\(\d+\)\s*$/, '').trim();
        return t || 'this department';
    }

    var MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    function fmtRange(from, to) {
        var a = from.split('-'), b = to.split('-');
        return MON[+a[1] - 1] + ' ' + (+a[2]) + ' – ' + MON[+b[1] - 1] + ' ' + (+b[2]) + ', ' + b[0];
    }

    // Local calendar date as YYYY-MM-DD — the grid's dates are already local,
    // so a UTC-based toISOString() would flip the "today" column an hour or
    // more either side of midnight.
    function todayIso() {
        var d = new Date(), m = d.getMonth() + 1, day = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
    }
    // Per-date column classes (weekend / holiday / today), built once per
    // render so cellHtml() can stamp the same state on every body cell that
    // the header already carries — a Sunday column reads as one shaded band
    // instead of a red header over plain white.
    var dayCls = {};

    function render() {
        var emps = visibleEmployees();
        var has = !!(S.employees.length && S.days.length);
        var tIso = todayIso();
        dayCls = {};
        S.days.forEach(function (d) {
            var c = '';
            if (d.w === 0 || d.w === 6) c += ' dr-we';
            if (S.holidays && S.holidays[d.date]) c += ' dr-hol';
            if (d.date === tIso) c += ' dr-today';
            dayCls[d.date] = c;
        });
        // The grid and the placeholder are siblings that each want the leftover
        // height, so exactly one of them may be in the layout at a time.
        $('#dr-placeholder').toggleClass('d-none', has);
        $('#dr-scroll').toggleClass('d-none', !has);
        syncChips();
        if (!has) {
            $grid.find('thead,tbody,tfoot').empty();
            return;
        }

        // Header
        var h = '<tr><th class="dr-emp"><div class="dr-emp-name">Employee</div>'
              + '<div class="dr-emp-sub">' + esc(S.employees.length + ' shown') + '</div></th>';
        S.days.forEach(function (d) {
            var hol = S.holidays && S.holidays[d.date];
            var cls = 'dr-dayhead' + (dayCls[d.date] || '');
            var isToday = d.date === tIso;
            var tip = hol ? esc(hol.title) : (isToday ? 'Today · click to fill this whole column' : 'Click to fill this whole column');
            h += '<th class="' + cls + '" data-date="' + d.date + '" title="' + tip + '">'
               + '<div class="dr-dom">' + d.dom + '</div><div class="dr-dow">' + esc(d.dow) + '</div></th>';
        });
        h += '</tr>';
        $grid.find('thead').html(h);
        // Natural size of the grid (name column + one column per day). Under
        // table-layout:fixed the columns split the pane evenly when there is
        // room; below this the table scrolls rather than crushing the days.
        $grid.css('min-width', (210 + S.days.length * 46) + 'px');

        // Body
        var rows = '';
        emps.forEach(function (emp) {
            rows += '<tr data-emp="' + emp.id + '">';
            // The name opens the shared quick-view drawer (data-emp-quickview,
            // wired globally in component/employee_quick_view.php), with the
            // href as the plain fallback to the full detail page — same
            // contract the Shift Roster uses.
            // ...but only for roles that may actually open it. The quick view is
            // mapped to employee-details, which is closed to a Department Head
            // on purpose — it carries salary and personal details. Rendering the
            // link anyway gave them a name that answered "your role does not
            // have access" on every click.
            rows += '<td class="dr-emp"><div class="d-flex align-items-center gap-1">'
                  + (CAN_QUICKVIEW
                        ? '<a href="index.php?page=employee-details&id=' + emp.id + '"'
                          + ' data-emp-quickview="' + emp.id + '" class="dr-emp-link"'
                          + ' title="Employee quick view">'
                        : '<div class="dr-emp-plain">')
                  + '<div class="dr-emp-name">' + esc(emp.name) + '</div>'
                  // With every department on screen the rows are one long
                  // alphabetical list, so the ward matters more than the fixed
                  // shift for knowing where you are. Inside one department it
                  // is the other way round.
                  + '<div class="dr-emp-sub">' + esc(emp.employee_no || '')
                  + (S.dept === '0'
                        ? (emp.dept ? ' · ' + esc(emp.dept) : '')
                        : (emp.period_shift ? ' · ' + esc(emp.period_shift) : ''))
                  + '</div>'
                  + (CAN_QUICKVIEW ? '</a>' : '</div>')
                  + (CAN_EDIT ? '<button type="button" class="dr-emp-fill" data-emp="' + emp.id + '" title="Fill this whole row with the selected shift"><i class="ri-more-2-fill"></i></button>' : '')
                  + '</div></td>';
            S.days.forEach(function (d) {
                rows += cellHtml(emp.id, d.date);
            });
            rows += '</tr>';
        });
        $grid.find('tbody').html(rows);

        renderCoverage(emps);
        syncDirtyBar();
        renderElsewhere();
        renderClashes();
    }

    // "Your roster is over there." Only shown when THIS cutoff holds nothing —
    // with days on screen there is nothing to be confused about.
    function renderElsewhere() {
        var empty = Object.keys(S.cells).length === 0 && dirty.size === 0;
        var list = (S.other || []);
        $('#dr-elsewhere').toggleClass('d-none', !(empty && list.length));
        if (!(empty && list.length)) return;
        $('#dr-elsewhere-links').html(list.map(function (o) {
            return '<button type="button" class="go" data-period="' + esc(o.period) + '">'
                 + '<i class="ri-arrow-right-line"></i>' + esc(o.label)
                 + '<small>' + o.days + ' day' + (o.days === 1 ? '' : 's')
                 + (o.published < o.days ? ', ' + (o.days - o.published) + ' draft' : '') + '</small></button>';
        }).join(''));
    }

    $(document).on('click', '#dr-elsewhere-links .go', function () {
        $period.val($(this).data('period')).trigger('change');
    });

    function cellHtml(empId, date) {
        var v = cellValue(empId, date);
        var zone = zoneOf(empId, date);
        var k = key(empId, date);
        var cls = 'dr-cell' + (dayCls[date] || '');
        var style = '';
        var label = '·';
        var title = [];
        var curShiftId = null;   // whichever branch below assigns an actual shift

        if (v && v.r) {
            cls += ' dr-rest';
            // No text label: the moon glyph is drawn by .dr-cell.dr-rest::before
            // in CSS, so the grid cell and the legend swatch (a real .dr-cell.dr-rest
            // wearing the same class) can never show two different icons for "rest".
            label = '';
            title.push('Rest day');
            if (v.s) {
                var shr = shiftById[v.s];
                if (shr) {
                    // Rest day WITH a shift on file (planned duty on a day off) —
                    // show the shift's own code as the label, same as any shift
                    // cell, and let the moon become a small corner badge instead
                    // of the cell's only content (dr-rest-shift moves it in CSS).
                    cls += ' dr-rest-shift';
                    label = shortLabel(shr.desc);
                    title.push('shift on file: ' + shr.desc + ' (' + shr.start + '–' + shr.end + ')');
                    curShiftId = v.s;
                }
            }
        } else if (v && v.s) {
            var sh = shiftById[v.s];
            if (sh) {
                label = shortLabel(sh.desc);
                style = 'background:' + shiftColor(sh) + ';color:' + shiftTextColor(sh) + ';';
                title.push(sh.desc + ' (' + sh.start + '–' + sh.end + ')');
                curShiftId = v.s;
            } else {
                label = '?';
            }
        } else {
            cls += ' dr-empty';
            label = '';   // dash glyph from .dr-cell.dr-empty::before, same reasoning as rest
            title.push('Not on the day grid — falls back to the fixed shift roster');
        }

        // Too little rest since yesterday's shift ended. Checked against
        // whichever shift the cell is ACTUALLY showing (curShiftId), so a
        // combo rest+shift day is measured the same as a plain shift day.
        var gap = restGapHours(empId, date, curShiftId);
        if (gap !== null && gap < MIN_REST_HOURS) {
            cls += ' dr-restclash';
            title.push('ONLY ' + gap.toFixed(1) + 'h rest since the previous day\'s shift ended (need ' + MIN_REST_HOURS + 'h)');
        }

        var lv = leaveOf(empId, date);
        var lvMark = '';   // suitcase marker, drawn as a child element (see .dr-lv in the CSS)
        if (lv) {
            if (v && v.s && !v.r) {
                // A shift planned on approved leave — the one that costs money.
                cls += ' dr-leaveclash';
                title.push('CLASH: on ' + lv.name + (lv.half ? ' (half day)' : '') + ' this day');
            } else {
                cls += ' dr-onleave';
                lvMark = '<i class="dr-lv"></i>';
                title.push('On ' + lv.name + (lv.half ? ' (half day)' : ''));
            }
        }

        if (zone === 'locked') { cls += ' dr-locked'; title.push('DTR approved — locked'); }
        else if (zone === 'punched') { cls += ' dr-punched'; title.push('Already punched — a change needs Recompute'); }

        if (v && v.st === 0) { cls += ' dr-draft'; title.push('Draft — not yet published'); }
        if (dirty.has(k)) { cls += ' dr-dirty'; title.push('Unsaved'); }

        // A swap is only worth surfacing when it actually differs from what was
        // first handed out — that is the question asked later, not the history.
        if (v && v.st === 1 && (v.ps !== undefined && v.ps !== null || v.pr !== undefined && v.pr !== null)) {
            var changedShift = (v.ps != null && v.s != null && v.ps !== v.s);
            var changedRest = (v.pr != null && v.pr !== v.r);
            if (changedShift || changedRest) {
                var was = v.pr === 1 ? 'REST' : (shiftById[v.ps] ? shortLabel(shiftById[v.ps].desc) : '—');
                title.push('changed from ' + was + (v.by ? ' by ' + v.by : '') + (v.at ? ' · ' + v.at : ''));
            }
        }

        // data-tip (not the native title=) — the same bubble the legend uses,
        // so hovering a cell gets the identical instant, readable tooltip
        // instead of the browser's slow plain one. title[0] is always the
        // day's primary state (Rest day / the shift name), so it becomes the
        // bubble's bold heading and everything else — shift-on-file, clash
        // warnings, zone, draft, unsaved — reads as the detail block below it.
        return '<td class="' + cls + '" style="' + style + '" data-emp="' + empId + '" data-date="' + date
             + '" data-tip="' + escAttr(title.join('\n')) + '">' + esc(label) + lvMark + '</td>';
    }

    // Native querySelector scoped to the employee's row, not a jQuery scan of
    // the whole table: filling a column with "All departments" selected repaints
    // 368 cells in a row, and searching all ~5,500 cells for each one was the
    // difference between instant and a visible freeze.
    function repaintCell(empId, date) {
        var el = $grid[0].querySelector('tr[data-emp="' + empId + '"] > td[data-date="' + date + '"]');
        if (el) el.outerHTML = cellHtml(empId, date);
        // Tomorrow's rest-gap reading depends on TODAY's shift, so a change here
        // can flip tomorrow's dr-restclash without tomorrow's own cell changing.
        var nd = nextDateOf(date);
        if (nd) {
            var el2 = $grid[0].querySelector('tr[data-emp="' + empId + '"] > td[data-date="' + nd + '"]');
            if (el2) el2.outerHTML = cellHtml(empId, nd);
        }
    }

    /* ── coverage ────────────────────────────────────────────────────────── */

    // The column question — "how many nurses do we have on NOC that day" — is
    // the one a paper sheet cannot answer, so it is always on screen.
    //
    // The breakdown is collapsible because it costs one grid row per shift, and
    // a ward running four shifts loses five rows of roster to it permanently.
    // What never collapses is the SUMMARY line: total on duty per day, with the
    // understaffed days still flagged red. Folding the panel away therefore
    // hides detail, never the warning.
    var covCollapsed = (function () {
        // Folded until the planner opens it: on a 150-row department the
        // breakdown otherwise costs a third of the viewport before a single
        // roster row is visible. The summary line is always on screen.
        try { return localStorage.getItem('dr-cov-collapsed') !== '0'; } catch (e) { return true; }
    })();

    function renderCoverage(emps) {
        // The "Min per shift" input is currently commented out in duty-roster.php,
        // so $min is an empty set and this resolves to 0 — every `min > 0` guard
        // below then short-circuits and nothing is ever flagged. Uncommenting the
        // field is all it takes to switch the warnings back on.
        var min = parseInt($min.val(), 10) || 0;
        var nd = S.days.length;
        var zeros = function () { var a = new Array(nd); for (var i = 0; i < nd; i++) a[i] = 0; return a; };

        // One pass over the grid instead of one per shift per day: a 160-employee
        // department across 4 shifts was 10k cellValue() lookups per repaint,
        // and this runs on every drag-painted cell.
        var byShift = {}, offs = zeros(), totals = zeros();
        emps.forEach(function (emp) {
            S.days.forEach(function (d, i) {
                var v = cellValue(emp.id, d.date);
                if (!v) return;
                if (v.r) { offs[i]++; return; }
                if (!v.s) return;
                if (!byShift[v.s]) byShift[v.s] = zeros();
                byShift[v.s][i]++;
                totals[i]++;
            });
        });
        var usedShifts = S.shifts.filter(function (sh) { return byShift[sh.id]; });

        // How many shift-days sit below the floor — the number the badge shows.
        var short = 0;
        if (min > 0) {
            usedShifts.forEach(function (sh) {
                for (var i = 0; i < nd; i++) if (byShift[sh.id][i] < min) short++;
            });
        }

        // ── Summary row: always visible, carries the toggle ──
        var f = '<tr class="dr-cov-head">'
              + '<th class="dr-emp"><button type="button" class="dr-cov-toggle" id="dr-cov-toggle"'
              + ' title="Show or hide the per-shift breakdown">'
              + '<i class="ri-arrow-down-s-line dr-cov-caret"></i>'
              + '<span class="dr-cov-title">On duty</span>'
              + (usedShifts.length ? '<span class="dr-cov-n">' + usedShifts.length + '</span>' : '')
              + (short ? '<span class="dr-cov-warn" title="' + short + ' shift-day(s) below the minimum of ' + min + '">'
                       + '<i class="ri-alert-fill"></i>' + short + '</span>' : '')
              + '</button></th>';
        for (var i = 0; i < nd; i++) {
            var lowDay = min > 0 && usedShifts.some(function (sh) { return byShift[sh.id][i] < min; });
            f += '<td class="dr-cov dr-cov-total' + (lowDay ? ' dr-cov-low' : '') + '">' + (totals[i] || '') + '</td>';
        }
        f += '</tr>';

        // ── Breakdown rows: the collapsible part ──
        usedShifts.forEach(function (sh) {
            f += '<tr class="dr-cov-row"><th class="dr-emp"><div class="dr-cov-label">'
               + '<span class="dr-chip" style="background:' + shiftColor(sh) + ';border:1px solid rgba(0,0,0,.12);"></span>'
               + esc(shortLabel(sh.desc)) + ' <span class="dr-cov-time">' + esc(sh.start) + '</span></div></th>';
            for (var j = 0; j < nd; j++) {
                var n = byShift[sh.id][j];
                f += '<td class="dr-cov' + (min > 0 && n < min ? ' dr-cov-low' : '') + '">' + (n || '') + '</td>';
            }
            f += '</tr>';
        });

        f += '<tr class="dr-cov-row dr-cov-off"><th class="dr-emp"><div class="dr-cov-label">'
           + '<span class="dr-chip" style="background:#f4f4f6;border:1px solid rgba(0,0,0,.12);"></span>Off</div></th>';
        for (var k = 0; k < nd; k++) f += '<td class="dr-cov">' + (offs[k] || '') + '</td>';
        f += '</tr>';

        $grid.find('tfoot').html(f).toggleClass('collapsed', covCollapsed);
        stackFooter();
    }

    $(document).on('click', '#dr-cov-toggle', function () {
        covCollapsed = !covCollapsed;
        try { localStorage.setItem('dr-cov-collapsed', covCollapsed ? '1' : '0'); } catch (e) {}
        $grid.find('tfoot').toggleClass('collapsed', covCollapsed);
        stackFooter();
    });

    // Every coverage row is position:sticky, so they all pin to the SAME
    // bottom:0 and collapse into one another once the body scrolls (invisible
    // on a short ward, obvious on a 150-row department). Give each row its own
    // offset — the height of everything below it — so they stack.
    function stackFooter() {
        var trs = $grid.find('tfoot tr').toArray();
        var acc = 0;
        for (var i = trs.length - 1; i >= 0; i--) {
            $(trs[i]).children().css('bottom', acc + 'px');
            // Rounded: a fractional offset leaves a hairline between two
            // stacked rows through which the body row beneath bleeds.
            acc += Math.round(trs[i].getBoundingClientRect().height);
        }
    }

    /* ── painting ────────────────────────────────────────────────────────── */

    // Every paint entry point goes through this. Without a chosen swatch there
    // is no honest thing to write, so it says so instead of guessing — a toast
    // rather than a modal, because it fires on a stray click and must not need
    // dismissing before the planner can carry on.
    function requirePaint() {
        if (paint.kind) return true;
        if (window.Swal) {
            Swal.fire({
                toast: true, position: 'top', icon: 'info',
                title: 'Pick a shift first',
                text: 'Choose one from the "Paint with" row, then click or drag on the grid.',
                showConfirmButton: false, timer: 2600, timerProgressBar: true,
            });
        }
        // Nudge the eye to where the choice is made.
        $('#dr-palette').css('animation', 'none').outerWidth();
        $('#dr-palette').css('animation', 'dr-flash .6s');
        return false;
    }

    function applyPaint(empId, date) {
        if (!CAN_EDIT || !paint.kind) return false;
        if (zoneOf(empId, date) === 'locked') return false;

        var next = paint.kind === 'rest' ? { s: null, r: 1 }
                 : paint.kind === 'shift' ? { s: paint.s, r: paint.alsoRest ? 1 : 0 }
                 : { s: null, r: 0 };

        var cur = cellValue(empId, date) || { s: null, r: 0 };
        if ((cur.s || null) === (next.s || null) && (cur.r ? 1 : 0) === next.r) {
            // Already that value — but if it was a pending edit that now matches
            // the server again, drop it so Save doesn't write a no-op.
            var k0 = key(empId, date);
            if (dirty.has(k0)) {
                var srv = S.cells[k0] || { s: null, r: 0 };
                if ((srv.s || null) === (next.s || null) && (srv.r ? 1 : 0) === next.r) {
                    dirty.delete(k0);
                    repaintCell(empId, date);
                    return true;
                }
            }
            return false;
        }

        var k = key(empId, date);
        var srvCell = S.cells[k] || { s: null, r: 0 };
        if ((srvCell.s || null) === (next.s || null) && (srvCell.r ? 1 : 0) === next.r) dirty.delete(k);
        else dirty.set(k, { s: next.s, r: next.r, st: S.cells[k] ? S.cells[k].st : 0 });

        repaintCell(empId, date);
        return true;
    }

    function afterPaintBatch() {
        renderCoverage(visibleEmployees());
        syncDirtyBar();
        renderClashes();
    }

    // Every shift currently planned on a day of approved leave, pending edits
    // included — so painting one is called out immediately, not at publish.
    function findClashes() {
        var out = [];
        if (!S.leaves) return out;
        S.employees.forEach(function (emp) {
            S.days.forEach(function (d) {
                var lv = leaveOf(emp.id, d.date);
                if (!lv) return;
                var v = cellValue(emp.id, d.date);
                if (!v || !v.s || v.r) return;      // rest day agrees with leave
                out.push({ emp: emp, date: d.date, leave: lv });
            });
        });
        return out;
    }

    // Longest run of planned WORK days with no rest day in between, per person.
    // Only counts days that are actually plotted: a blank is not evidence of
    // work, and treating it as one would flag every half-filled sheet.
    var LONG_RUN = 7;
    function findLongRuns() {
        var out = [];
        S.employees.forEach(function (emp) {
            var run = 0, start = null, worst = 0, worstStart = null;
            S.days.forEach(function (d) {
                var v = cellValue(emp.id, d.date);
                if (v && v.s && !v.r) {
                    if (!run) start = d.date;
                    run++;
                    if (run > worst) { worst = run; worstStart = start; }
                } else if (v && v.r) {
                    run = 0;             // a rest day breaks the run
                } // blank: neither extends nor breaks — it is simply unknown
            });
            if (worst >= LONG_RUN) out.push({ emp: emp, days: worst, from: worstStart });
        });
        return out;
    }

    // Held so the "+N more" link can show the full list without recomputing —
    // and so the modal can show the WHOLE row (shift, leave type) rather than
    // the abbreviated name-and-date the strip has room for.
    var lastClashes = [], lastRuns = [];

    function renderClashes() {
        var cl = findClashes();
        var runs = findLongRuns();
        var $bar = $('#dr-clash');
        lastClashes = cl;
        lastRuns = runs;

        if (!cl.length && runs.length) {
            // No leave clash, but somebody is working a long stretch with no
            // rest day. Worth saying, in a quieter voice than the clash.
            var r0 = runs.slice(0, 4).map(function (r) {
                return r.emp.name.split(',')[0] + ' · ' + r.days + ' days from ' + fmtDay(r.from);
            }).join('   ·   ');
            $('#dr-clash-text').html(
                '<b>' + runs.length + ' employee(s) work ' + LONG_RUN + '+ days with no rest day.</b> '
                + 'Check this is intended.'
                + '<div class="who">' + esc(r0) + moreLink(runs.length, 4, 'runs') + '</div>'
            );
            $bar.removeClass('d-none').addClass('soft');
            return;
        }
        $bar.removeClass('soft');
        if (!cl.length) { $bar.addClass('d-none'); return; }

        var names = [];
        cl.forEach(function (c) {
            var s = c.emp.name.split(',')[0] + ' · ' + fmtDay(c.date);
            if (names.indexOf(s) === -1) names.push(s);
        });
        var shown = names.slice(0, 6).join('   ·   ');
        $('#dr-clash-text').html(
            '<b>' + cl.length + ' day(s) rostered on approved leave.</b> '
            + 'They will not be on the ward, so the day becomes an absence against a shift nobody was going to work.'
            + '<div class="who">' + esc(shown) + moreLink(names.length, 6, 'clashes') + '</div>'
        );
        $bar.removeClass('d-none');
    }

    // "+3 more" was a dead end: it told you something was hidden and gave you no
    // way to see it, on the one banner whose whole job is naming who is affected.
    function moreLink(total, shownCount, kind) {
        if (total <= shownCount) return '';
        return '   <a href="#" class="dr-more" data-kind="' + kind + '">+'
             + (total - shownCount) + ' more</a>';
    }

    $(document).on('click', '.dr-more', function (e) {
        e.preventDefault();
        if ($(this).data('kind') === 'runs') showRunList();
        else showClashList();
    });

    function tableModal(opts) {
        Swal.fire({
            icon: opts.icon,
            title: opts.title,
            html: '<div style="text-align:left;font-size:13px;">'
                + '<p style="margin:0 0 10px;color:#6b6878;">' + opts.lede + '</p>'
                + '<div style="max-height:320px;overflow:auto;border:1px solid ' + opts.line
                + ';border-radius:8px;background:' + opts.bg + ';">'
                + '<table style="width:100%;border-collapse:collapse;font-size:12.5px;">'
                + '<thead><tr>' + opts.head.map(function (h) {
                    return '<th style="text-align:left;padding:6px 9px;position:sticky;top:0;background:'
                         + opts.bg + ';border-bottom:1px solid ' + opts.line + ';font-size:10.5px;'
                         + 'text-transform:uppercase;letter-spacing:.06em;color:#8a8598;">' + esc(h) + '</th>';
                  }).join('') + '</tr></thead>'
                + '<tbody>' + opts.rows + '</tbody></table></div>'
                + (opts.foot ? '<p style="margin:10px 0 0;color:#6b6878;">' + opts.foot + '</p>' : '')
                + '</div>',
            width: 560,
            confirmButtonText: 'Close',
        });
    }

    function showClashList() {
        var rows = lastClashes.map(function (c) {
            var sh = shiftById[(cellValue(c.emp.id, c.date) || {}).s];
            return '<tr>'
                 + '<td style="padding:5px 9px;border-bottom:1px solid #f0e7e6;">' + esc(c.emp.name) + '</td>'
                 + '<td style="padding:5px 9px;border-bottom:1px solid #f0e7e6;white-space:nowrap;">' + esc(fmtDay(c.date)) + '</td>'
                 + '<td style="padding:5px 9px;border-bottom:1px solid #f0e7e6;white-space:nowrap;">'
                 + esc(sh ? shortLabel(sh.desc) : '—') + '</td>'
                 + '<td style="padding:5px 9px;border-bottom:1px solid #f0e7e6;color:#8a2c28;">'
                 + esc(c.leave.name + (c.leave.half ? ' (half day)' : '')) + '</td></tr>';
        }).join('');
        tableModal({
            icon: 'warning',
            title: lastClashes.length + ' rostered on approved leave',
            lede: 'Each of these is a shift planned on a day the employee already has approved leave.',
            head: ['Employee', 'Day', 'Shift', 'Leave'],
            rows: rows, bg: '#fdefee', line: '#f2ccc9',
            foot: 'Change the shift to OFF, or leave it if the leave was cancelled.',
        });
    }

    function showRunList() {
        var rows = lastRuns.map(function (r) {
            return '<tr>'
                 + '<td style="padding:5px 9px;border-bottom:1px solid #f2e9cf;">' + esc(r.emp.name) + '</td>'
                 + '<td style="padding:5px 9px;border-bottom:1px solid #f2e9cf;white-space:nowrap;">' + r.days + ' days</td>'
                 + '<td style="padding:5px 9px;border-bottom:1px solid #f2e9cf;white-space:nowrap;">from ' + esc(fmtDay(r.from)) + '</td></tr>';
        }).join('');
        tableModal({
            icon: 'info',
            title: lastRuns.length + ' working ' + LONG_RUN + '+ days straight',
            lede: 'The longest unbroken run of planned work days, with no rest day in between. Blank days are not counted.',
            head: ['Employee', 'Run', 'Starting'],
            rows: rows, bg: '#fff8e1', line: '#f2e9cf',
            foot: 'Some wards run long stretches on purpose — this is a check, not an error.',
        });
    }

    function fmtDay(ymd) {
        var p = ymd.split('-');
        return MON[+p[1] - 1] + ' ' + (+p[2]);
    }

    $(document).on('mousedown', '#dr-grid td.dr-cell', function (e) {
        if (!CAN_EDIT || e.which !== 1) return;
        e.preventDefault();
        if (!requirePaint()) return;
        painting = true;
        applyPaint($(this).data('emp'), $(this).data('date'));
        afterPaintBatch();
    });
    $(document).on('mouseenter', '#dr-grid td.dr-cell', function () {
        if (!painting) return;
        applyPaint($(this).data('emp'), $(this).data('date'));
    });
    $(document).on('mouseup', function () {
        if (!painting) return;
        painting = false;
        afterPaintBatch();
    });

    // Column fill — one date across everyone currently visible.
    $(document).on('click', '#dr-grid th.dr-dayhead', function () {
        if (!CAN_EDIT || !requirePaint()) return;
        var date = $(this).data('date');
        visibleEmployees().forEach(function (emp) { applyPaint(emp.id, date); });
        afterPaintBatch();
    });

    // Row fill — one employee across the whole cutoff, using the palette.
    $(document).on('click', '.dr-emp-fill', function (e) {
        e.stopPropagation();
        if (!CAN_EDIT || !requirePaint()) return;
        var empId = $(this).data('emp');
        S.days.forEach(function (d) { applyPaint(empId, d.date); });
        afterPaintBatch();
    });

    /* ── dirty bar ───────────────────────────────────────────────────────── */

    function syncDirtyBar() {
        if (!CAN_EDIT) return;
        $('#dr-dirty-count').text(dirty.size);
        $('#dr-dirtybar').toggleClass('show', dirty.size > 0);
    }

    $('#dr-revert').on('click', function () {
        dirty.clear();
        render();
    });

    // Leaving with unsaved paint loses the whole sheet — the one place a
    // confirm is worth the friction.
    $(window).on('beforeunload', function () {
        if (dirty.size > 0) return 'You have unsaved roster changes.';
    });

    /* ── server calls ────────────────────────────────────────────────────── */

    function post(action, data) {
        return $.post('ajax.php?action=' + action, data).then(function (res) {
            var j = res;
            try { if (typeof res === 'string') j = JSON.parse(res); } catch (e) { j = null; }
            if (!j) return $.Deferred().reject('Bad response from server.');
            return j;
        });
    }

    // Areas (wards) inside the chosen department, for the optional Area filter
    // — absent entirely for an area-scoped session ($area is a 0-length jQuery
    // set there, so every call here is a no-op). Rebuilds #dr-area's options
    // and resets its selection to "All areas" SYNCHRONOUSLY before the AJAX
    // call resolves, so the load() that follows never reads a stale area_id
    // left over from the previous department.
    function loadAreas() {
        if (!$area.length) return;
        var deptVal = $dept.val();
        var dept = (deptVal == null || deptVal === '' || deptVal === '0') ? 0 : parseInt(deptVal, 10);
        $area.prop('disabled', true).html('<option value="">All areas</option>').trigger('change');
        if (!dept) return;
        post('duty_roster_areas', { department_id: dept })
            .done(function (j) {
                if (!j.result || $dept.val() != dept) return;   // department may have changed again while this was in flight
                var opts = '<option value="">All areas</option>' + (j.areas || []).map(function (a) {
                    return '<option value="' + a.id + '">' + esc(a.name) + '</option>';
                }).join('');
                $area.html(opts).prop('disabled', !(j.areas && j.areas.length)).trigger('change');
            });
    }

    function load() {
        S.period = $period.val();
        S.dept = $dept.val() == null ? '' : String($dept.val());
        S.area = $area.length ? ($area.val() == null ? '' : String($area.val())) : '';
        if (S.dept === '') {
            S.employees = []; S.days = []; S.cells = {}; S.zones = {}; S.from = ''; S.to = '';
            render();
            return;
        }
        post('duty_roster_data', { period: S.period, department_id: S.dept, area_id: S.area })
            .done(function (j) {
                if (!j.result) { toast('error', 'Error', j.message); return; }
                S.days = j.days || [];
                S.shifts = (j.shifts || []).map(function (sh, i) { sh.idx = i; return sh; });
                S.employees = j.employees || [];
                S.cells = j.cells || {};
                S.zones = j.zones || {};
                S.leaves = j.leaves || {};
                S.holidays = j.holidays || {};
                S.other = j.other || [];
                S.from = j.from; S.to = j.to;
                shiftById = {};
                S.shifts.forEach(function (sh) { shiftById[sh.id] = sh; });
                // The shift they had selected may have been retired since.
                if (paint.kind === 'shift' && !shiftById[paint.s]) { paint.kind = null; paint.s = null; }
                dirty.clear();
                renderPalette();
                render();
            })
            .fail(function () { toast('error', 'Error', 'Could not load the roster.'); });
    }

    $('#dr-save').on('click', function () {
        if (!dirty.size) return;
        var cells = [];
        dirty.forEach(function (v, k) {
            var parts = k.split('|');
            cells.push({ e: parseInt(parts[0], 10), d: parts[1], s: v.s, r: v.r });
        });
        var $b = $(this).prop('disabled', true);
        post('duty_roster_save', { period: S.period, cells: JSON.stringify(cells) })
            .done(function (j) {
                $b.prop('disabled', false);
                if (!j.result) { toast('error', 'Error', j.message); return; }
                showRecompute(j.needs_recompute, 'saved');
                toast('success', 'Saved', j.message);
                load();
            })
            .fail(function () { $b.prop('disabled', false); toast('error', 'Error', 'Save failed.'); });
    });

    $('#dr-publish').on('click', function () {
        if (dirty.size) { toast('warning', 'Save first', 'Save your changes before publishing.'); return; }
        var $b = $(this).prop('disabled', true);
        Swal.fire({
            icon: 'question',
            title: 'Publish this cutoff?',
            text: 'Draft days in ' + deptLabel() + ' (' + S.employees.length + ' employees) become live, '
                + 'and every affected employee is notified.',
            showCancelButton: true, confirmButtonText: 'Publish',
        }).then(function (r) {
            if (!r.isConfirmed) { $b.prop('disabled', false); return; }
            doPublish($b, false);
        });
    });

    // The server refuses the first attempt when someone is rostered on approved
    // leave, and hands back the list. Publishing is what TELLS the employee they
    // are working, so the check belongs on this side of it — the second call
    // carries confirm_leave once the planner has read the names.
    function doPublish($b, confirmed) {
        var data = { period: S.period, department_id: S.dept };
        if (confirmed) data.confirm_leave = 1;

        post('duty_roster_publish', data)
            .done(function (j) {
                $b.prop('disabled', false);
                if (!j.result) {
                    if (j.conflicts && j.conflicts.length) return showLeaveConflicts($b, j);
                    toast('error', 'Nothing published', j.message);
                    return;
                }
                showRecompute(j.needs_recompute, 'published');
                toast('success', 'Published', j.message);
                load();
            })
            .fail(function () { $b.prop('disabled', false); toast('error', 'Error', 'Publish failed.'); });
    }

    function showLeaveConflicts($b, j) {
        var rows = j.conflicts.map(function (c) {
            return '<tr><td style="padding:4px 8px;border-bottom:1px solid #f0e7e6;">' + esc(c.employee) + '</td>'
                 + '<td style="padding:4px 8px;border-bottom:1px solid #f0e7e6;white-space:nowrap;">' + esc(c.date) + '</td>'
                 + '<td style="padding:4px 8px;border-bottom:1px solid #f0e7e6;color:#8a2c28;">' + esc(c.leave) + '</td></tr>';
        }).join('');
        var more = j.conflict_total > j.conflicts.length
            ? '<div style="margin-top:6px;color:#8a2c28;">… and ' + (j.conflict_total - j.conflicts.length) + ' more</div>' : '';

        Swal.fire({
            icon: 'warning',
            title: j.conflict_total + ' rostered on approved leave',
            html: '<div style="text-align:left;font-size:13px;">'
                + '<p style="margin:0 0 10px;color:#6b6878;">These people have approved leave on the day they are rostered '
                + 'to work. They will not be on the ward, and the day becomes an absence against a shift nobody was '
                + 'going to work.</p>'
                + '<div style="max-height:230px;overflow:auto;border:1px solid #f2ccc9;border-radius:8px;background:#fdefee;">'
                + '<table style="width:100%;border-collapse:collapse;font-size:12.5px;">' + rows + '</table></div>'
                + more
                + '<p style="margin:10px 0 0;color:#6b6878;">If the leave was cancelled or the duty was swapped, publish anyway.</p>'
                + '</div>',
            showCancelButton: true,
            confirmButtonText: 'Publish anyway',
            cancelButtonText: 'Let me fix them',
            confirmButtonColor: '#c62828',
        }).then(function (r) {
            if (r.isConfirmed) { $b.prop('disabled', true); doPublish($b, true); }
        });
    }

    $('#dr-copy').on('click', function () {
        var $b = $(this).prop('disabled', true);
        post('duty_roster_copy', { period: S.period, department_id: S.dept })
            .done(function (j) {
                $b.prop('disabled', false);
                toast(j.result ? 'success' : 'error', j.result ? 'Copied' : 'Nothing copied', j.message);
                if (j.result) load();
            })
            .fail(function () { $b.prop('disabled', false); toast('error', 'Error', 'Copy failed.'); });
    });

    $('#dr-discard').on('click', function () {
        var $b = $(this);
        Swal.fire({
            icon: 'warning',
            title: 'Discard drafts?',
            text: 'Every unpublished day in this cutoff for ' + deptLabel() + ' is deleted. Published days are kept.',
            showCancelButton: true, confirmButtonText: 'Discard', confirmButtonColor: '#d33',
        }).then(function (r) {
            if (!r.isConfirmed) return;
            $b.prop('disabled', true);
            post('duty_roster_clear_drafts', { period: S.period, department_id: S.dept })
                .done(function (j) {
                    $b.prop('disabled', false);
                    toast(j.result ? 'success' : 'error', j.result ? 'Discarded' : 'Error', j.message);
                    if (j.result) load();
                })
                .fail(function () { $b.prop('disabled', false); toast('error', 'Error', 'Discard failed.'); });
        });
    });

    function showRecompute(ids, verb) {
        pendingRecompute = ids || [];
        if (!pendingRecompute.length) { $('#dr-recompute-bar').addClass('d-none'); return; }
        $('#dr-recompute-text').text(
            pendingRecompute.length + ' employee(s) have attendance already recorded on days you ' + verb +
            '. Their DTR still shows the old shift until you recompute — and the batch cannot be approved before you do.'
        );
        $('#dr-recompute-bar').removeClass('d-none');
    }

    $('#dr-recompute-btn').on('click', function () {
        if (!pendingRecompute.length) return;
        var $b = $(this).prop('disabled', true);
        post('duty_roster_recompute', { period: S.period, employee_ids: pendingRecompute.join(',') })
            .done(function (j) {
                $b.prop('disabled', false);
                if (!j.result) { toast('error', 'Error', j.message); return; }
                $('#dr-recompute-bar').addClass('d-none');
                pendingRecompute = [];
                toast('success', 'Recomputed', j.message);
                load();
            })
            .fail(function () { $b.prop('disabled', false); toast('error', 'Error', 'Recompute failed.'); });
    });

    // Export reads the DATABASE, not the grid on screen, so unsaved paint would
    // be missing from the file without a word. Say so rather than hand over a
    // sheet that quietly disagrees with what they are looking at.
    $('#dr-export').on('click', function () {
        if (S.dept === '') { toast('info', 'Choose a department', 'Pick a department first, then export.'); return; }
        var go = function () {
            window.location = 'export-duty-roster.php?period=' + encodeURIComponent(S.period)
                            + '&department_id=' + encodeURIComponent(S.dept);
        };
        if (!dirty.size) return go();
        Swal.fire({
            icon: 'warning',
            title: 'You have unsaved days',
            text: dirty.size + ' day(s) are not saved yet. The Excel file is built from what is saved, so those will be missing.',
            showCancelButton: true,
            confirmButtonText: 'Export saved version',
            cancelButtonText: 'Cancel',
        }).then(function (r) { if (r.isConfirmed) go(); });
    });

    // Same DATABASE-not-screen caveat as Export. Shown in the wide #dr-print-modal
    // (an iframe), not a new tab — the roster stays open underneath instead of
    // being replaced by the navigation.
    function printUrl() {
        return 'print-duty-roster.php?period=' + encodeURIComponent(S.period)
             + '&department_id=' + encodeURIComponent(S.dept)
             + '&area_id=' + encodeURIComponent(S.area || '');
    }
    function printOpen() {
        var url = printUrl();
        $('#dr-print-open').attr('href', url);
        $('#dr-print-frame').attr('src', url);
        $('#dr-print-modal').addClass('show');
    }
    function printClose() {
        $('#dr-print-modal').removeClass('show');
        $('#dr-print-frame').attr('src', '');   // stop the embedded PDF viewer once hidden
    }
    $('#dr-print').on('click', function () {
        if (S.dept === '') { toast('info', 'Choose a department', 'Pick a department first, then print.'); return; }
        if (!dirty.size) return printOpen();
        Swal.fire({
            icon: 'warning',
            title: 'You have unsaved days',
            text: dirty.size + ' day(s) are not saved yet. The PDF is built from what is saved, so those will be missing.',
            showCancelButton: true,
            confirmButtonText: 'Print saved version',
            cancelButtonText: 'Cancel',
        }).then(function (r) { if (r.isConfirmed) printOpen(); });
    });
    $('#dr-print-close').on('click', printClose);
    $('#dr-print-modal').on('click', function (e) { if (e.target === this) printClose(); });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $('#dr-print-modal').hasClass('show')) printClose();
    });

    // Import is a DRY RUN on the server. What comes back is a list of changed
    // days, and those are dropped into `dirty` — the same map a click fills — so
    // the sheet lands as pending paint on the grid, gets looked at, and goes out
    // through the ordinary Save/Publish. An upload never writes by itself.
    $('#dr-import').on('click', function () {
        if (S.dept === '') { toast('info', 'Choose a department', 'Pick a department first, then import.'); return; }
        if (dirty.size) {
            return Swal.fire({
                icon: 'warning',
                title: 'You have unsaved days',
                text: 'Importing replaces what you have painted but not saved. Save or Revert first.',
                confirmButtonText: 'OK',
            });
        }
        $('#dr-import-file').val('').trigger('click');
    });

    $('#dr-import-file').on('change', function () {
        var file = this.files && this.files[0];
        if (!file) return;

        var fd = new FormData();
        fd.append('file', file);
        fd.append('period', S.period);
        fd.append('department_id', S.dept);

        Swal.fire({ title: 'Reading the sheet…', text: file.name, allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });

        $.ajax({
            url: 'ajax.php?action=duty_roster_import',
            type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
        }).done(function (j) {
            if (!j || !j.result) {
                return Swal.fire({ icon: 'error', title: 'Could not import', text: (j && j.message) || 'The file could not be read.' });
            }
            showImportPreview(j, file.name);
        }).fail(function () {
            Swal.fire({ icon: 'error', title: 'Upload failed', text: 'The file did not reach the server. It may be too large.' });
        });
    });

    function showImportPreview(j, filename) {
        var n = (j.changes || []).length;

        if (!n) {
            return Swal.fire({
                icon: 'info',
                title: 'Nothing to change',
                html: 'Read <b>' + j.rows + '</b> employee row(s) from <i>' + esc(filename) + '</i>.<br>'
                    + 'Everything in the sheet already matches what is planned here.'
                    + (j.unknown ? '<br><br><span style="color:#c1544f">' + j.unknown + ' cell(s) were not a shift code and were skipped.</span>' : ''),
            });
        }

        // Every number that is NOT a straightforward change gets said out loud.
        // A silent skip is how an import quietly loses half a sheet.
        var notes = [];
        if (j.cleared)    notes.push('<li><b>' + j.cleared + '</b> day(s) will be CLEARED — blank in the sheet, planned here.</li>');
        if (j.recovered)  notes.push('<li><b>' + j.recovered + '</b> cell(s) had been turned into dates by Excel and were read back as codes.</li>');
        if (j.locked)     notes.push('<li><b>' + j.locked + '</b> cell(s) ignored — the DTR is already approved and locked.</li>');
        if (j.unknown)    notes.push('<li style="color:#c1544f"><b>' + j.unknown + '</b> cell(s) skipped — not a shift code.</li>');
        if (j.blank_rows) notes.push('<li><b>' + j.blank_rows + '</b> row(s) left completely blank were skipped, not cleared.</li>');
        if (j.unchanged)  notes.push('<li>' + j.unchanged + ' day(s) already matched.</li>');

        var probs = (j.problems || []).length
            ? '<div style="margin-top:10px;text-align:left;background:#fff6f5;border:1px solid #f3d6d3;border-radius:8px;padding:8px 10px;font-size:12px;max-height:150px;overflow:auto">'
              + (j.problems || []).map(function (p) { return '<div>' + esc(p) + '</div>'; }).join('') + '</div>'
            : '';

        Swal.fire({
            icon: 'question',
            title: n + ' day(s) will change',
            html: '<div style="text-align:left;font-size:13px">'
                + '<div style="color:#6b6878;margin-bottom:8px">From <i>' + esc(filename) + '</i>, ' + j.rows + ' employee row(s).</div>'
                + (notes.length ? '<ul style="margin:0 0 0 16px;padding:0">' + notes.join('') + '</ul>' : '')
                + probs
                + '<div style="margin-top:10px;color:#6b6878">Nothing is saved yet. The changes will be painted on the grid so you can check them, then press <b>Save</b>.</div>'
                + '</div>',
            showCancelButton: true,
            confirmButtonText: 'Load onto the grid',
            cancelButtonText: 'Cancel',
        }).then(function (r) {
            if (!r.isConfirmed) return;
            dirty.clear();
            (j.changes || []).forEach(function (c) {
                var k = key(c.e, c.d);
                dirty.set(k, { s: c.s, r: c.r, st: S.cells[k] ? S.cells[k].st : 0 });
            });
            render();
            toast('success', 'Loaded', n + ' day(s) are on the grid as unsaved changes. Review, then Save.');
        });
    }

    /* ── rotation pattern ────────────────────────────────────────────────── */
    //
    // A cutoff painted by hand is 15 clicks per nurse, 600 for a ward of 40 —
    // but almost all of it is one short cycle repeating. This takes the cycle
    // once and unrolls it.
    //
    // Everything happens client-side and lands in `dirty`, so it goes out
    // through the ordinary Save/Publish with the same lock rules as a click.
    // Nothing new can reach the table.

    var patSteps = [];   // [{ kind:'shift'|'rest', s:<id|null>, n:<days> }]

    function patDefault() {
        // Seeded from the ward's own shifts rather than something invented, so
        // the first thing shown is already nearly right.
        var a = S.shifts[0], b = S.shifts[S.shifts.length - 1];
        if (!a) return [];
        return [
            { kind: 'shift', s: a.id, n: 2 },
            { kind: 'shift', s: (b && b.id !== a.id) ? b.id : a.id, n: 2 },
            { kind: 'rest', s: null, n: 1 },
        ];
    }

    function patLoad() {
        // A ward's rotation is the same every cutoff, so remembering it is most
        // of the value — the second month is one click.
        try {
            var raw = localStorage.getItem('dr-pattern');
            if (raw) {
                var p = JSON.parse(raw);
                if (p && p.length) {
                    // Drop steps whose shift has since been retired.
                    p = p.filter(function (st) { return st.kind === 'rest' || shiftById[st.s]; });
                    if (p.length) return p;
                }
            }
        } catch (e) {}
        return patDefault();
    }

    function patCycleLen() {
        return patSteps.reduce(function (t, s) { return t + Math.max(1, s.n | 0); }, 0);
    }

    // The cycle unrolled into one entry per day: [{s,r}, {s,r}, ...]
    function patUnroll() {
        var out = [];
        patSteps.forEach(function (st) {
            var n = Math.max(1, st.n | 0);
            for (var i = 0; i < n; i++) {
                out.push(st.kind === 'rest' ? { s: null, r: 1 } : { s: st.s, r: 0 });
            }
        });
        return out;
    }

    function patRenderSteps() {
        var html = '';
        patSteps.forEach(function (st, i) {
            html += '<div class="dr-pat-step" data-i="' + i + '">'
                  + '<span class="n">' + (i + 1) + '</span>'
                  + '<select class="form-select form-select-sm dr-pat-what">'
                  + '<option value="rest"' + (st.kind === 'rest' ? ' selected' : '') + '>REST — day off</option>';
            S.shifts.forEach(function (sh) {
                html += '<option value="' + sh.id + '"'
                     + (st.kind === 'shift' && String(st.s) === String(sh.id) ? ' selected' : '') + '>'
                     + esc(shortLabel(sh.desc)) + ' — ' + esc(sh.start + '–' + sh.end) + '</option>';
            });
            html += '</select>'
                  + '<input type="number" class="form-control form-control-sm dr-pat-n" min="1" max="31" value="'
                  + Math.max(1, st.n | 0) + '">'
                  + '<span style="font-size:11px;color:#9895a3;">day(s)</span>'
                  + '<button type="button" class="x dr-pat-del" title="Remove"><i class="ri-close-line"></i></button>'
                  + '</div>';
        });
        $('#dr-pat-steps').html(html);
        patRenderPreview();
    }

    function patRenderPreview() {
        var cycle = patUnroll();
        var len = cycle.length;
        var emps = visibleEmployees();
        var stag = parseInt($('#dr-pat-stagger').val(), 10) || 0;

        $('#dr-pat-scope').text(emps.length + ' employee' + (emps.length === 1 ? '' : 's') + ' shown · '
                                + S.days.length + ' days · cycle of ' + len);

        if (!len || !S.days.length) { $('#dr-pat-preview').html(''); return; }

        var html = '<div class="h">First 3 employees, day 1 onwards</div>';
        for (var e = 0; e < Math.min(3, emps.length); e++) {
            html += '<div class="dr-pat-strip"><span class="who">' + esc(emps[e].name.split(',')[0]) + '</span>';
            for (var d = 0; d < Math.min(S.days.length, 15); d++) {
                var v = cycle[((d + e * stag) % len + len) % len];
                var sh = v.r ? null : shiftById[v.s];
                var bg = v.r ? '#f4f4f6' : (sh ? shiftColor(sh) : '#fff');
                var fg = v.r ? '#8c8998' : (sh ? shiftTextColor(sh) : '#c0bdc9');
                var lb = v.r ? 'OFF' : (sh ? shortLabel(sh.desc) : '?');
                html += '<span class="dr-pat-cell" style="background:' + bg + ';color:' + fg + ';">'
                      + esc(lb) + '</span>';
            }
            html += '</div>';
        }

        // The one mistake this screen can hide: a cycle that divides evenly into
        // the stagger leaves whole groups on the same rotation, so a shift can
        // end up with nobody on it. Say so rather than let it show up as a gap
        // in the coverage row after they have already saved.
        var warn = '';
        if (stag === 0 && emps.length > 1) {
            warn = 'Every employee gets the identical cycle, so the whole ward works and rests together. Use a stagger unless that is what you want.';
        } else if (stag > 0 && len % stag === 0 && (len / stag) < emps.length && (len / stag) < 3) {
            warn = 'This stagger only produces ' + (len / stag) + ' distinct rotations, so the ward splits into '
                 + (len / stag) + ' groups. Check the coverage row after applying.';
        }
        if (warn) html += '<div class="dr-pat-warn"><i class="ri-error-warning-line"></i> ' + esc(warn) + '</div>';

        $('#dr-pat-preview').html(html);
        $('#dr-pat-stagger-help').text(len ? 'Employee 2 starts ' + stag + ' day(s) into the cycle, employee 3 starts '
                                              + (stag * 2) + ', and so on.' : '');
    }

    function patOpen() {
        if (!CAN_EDIT) return;
        if (!S.shifts.length || !S.days.length) {
            return toast('info', 'Nothing to work with', 'Choose a department and cutoff first.');
        }
        patSteps = patLoad();
        patRenderSteps();
        $('#dr-pattern-modal').addClass('show');
    }
    function patClose() { $('#dr-pattern-modal').removeClass('show'); }

    $('#dr-pattern').on('click', patOpen);
    $('#dr-pat-close, #dr-pat-cancel').on('click', patClose);
    $('#dr-pattern-modal').on('click', function (e) { if (e.target === this) patClose(); });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $('#dr-pattern-modal').hasClass('show')) patClose();
    });

    $('#dr-pat-add').on('click', function () {
        patSteps.push({ kind: 'rest', s: null, n: 1 });
        patRenderSteps();
    });
    $(document).on('click', '.dr-pat-del', function () {
        var i = $(this).closest('.dr-pat-step').data('i');
        if (patSteps.length <= 1) return;
        patSteps.splice(i, 1);
        patRenderSteps();
    });
    $(document).on('change input', '.dr-pat-what, .dr-pat-n', function () {
        var $s = $(this).closest('.dr-pat-step');
        var i = $s.data('i');
        if (!patSteps[i]) return;
        var what = $s.find('.dr-pat-what').val();
        patSteps[i].kind = what === 'rest' ? 'rest' : 'shift';
        patSteps[i].s = what === 'rest' ? null : parseInt(what, 10);
        patSteps[i].n = Math.max(1, parseInt($s.find('.dr-pat-n').val(), 10) || 1);
        patRenderPreview();
    });
    $('#dr-pat-stagger, #dr-pat-overwrite').on('change', patRenderPreview);

    $('#dr-pat-apply').on('click', function () {
        var cycle = patUnroll();
        var len = cycle.length;
        if (!len) return;

        var emps = visibleEmployees();
        var stag = parseInt($('#dr-pat-stagger').val(), 10) || 0;
        var overwrite = $('#dr-pat-overwrite').val() === '1';

        var painted = 0, skippedLocked = 0, skippedPlanned = 0;

        emps.forEach(function (emp, e) {
            S.days.forEach(function (d, di) {
                if (zoneOf(emp.id, d.date) === 'locked') { skippedLocked++; return; }

                var k = key(emp.id, d.date);
                var existing = S.cells[k];
                if (existing && !overwrite) { skippedPlanned++; return; }

                var v = cycle[((di + e * stag) % len + len) % len];
                var srv = existing || { s: null, r: 0 };
                if ((srv.s || null) === (v.s || null) && (srv.r ? 1 : 0) === v.r) {
                    dirty.delete(k);          // already that value on the server
                } else {
                    dirty.set(k, { s: v.s, r: v.r, st: existing ? existing.st : 0 });
                    painted++;
                }
            });
        });

        try { localStorage.setItem('dr-pattern', JSON.stringify(patSteps)); } catch (e) {}

        patClose();
        render();

        var bits = [painted + ' day(s) painted'];
        if (skippedPlanned) bits.push(skippedPlanned + ' left alone (already planned)');
        if (skippedLocked) bits.push(skippedLocked + ' skipped (locked)');
        toast(painted ? 'success' : 'info', painted ? 'Pattern applied' : 'Nothing changed',
              bits.join(', ') + '. Nothing is saved yet — review, then Save.');
    });

    /* ── filters ─────────────────────────────────────────────────────────── */

    // Restore BEFORE the change handlers below are bound. .val() alone leaves
    // the custom-select trigger showing whatever was selected when it enhanced
    // the element — the page came back reading "— Select a department —" while
    // the grid had already loaded the remembered ward. The documented way to
    // resync it is .val().trigger('change'), and doing that here (rather than
    // after the bindings) means it reaches only the custom select, so the grid
    // is still fetched exactly once by the load() at the end of this file.
    try {
        var savedMin = localStorage.getItem('dr-min');
        if (savedMin !== null) $min.val(savedMin);

        // The CUTOFF is remembered too, not just the department. Planning
        // always runs ahead of the calendar — you build next cutoff's sheet
        // during this one — but the page used to reopen on the cutoff
        // containing TODAY. Coming back to work you had just published landed
        // you on an empty grid, which reads exactly like the data was lost.
        var savedPer = localStorage.getItem('dr-period');
        if (savedPer !== null && $period.find('option').filter(function () { return this.value === savedPer; }).length) {
            $period.val(savedPer).trigger('change');
        }
        var savedDept = localStorage.getItem('dr-dept');
        if (savedDept !== null && $dept.find('option').filter(function () { return this.value === savedDept; }).length) {
            $dept.val(savedDept).trigger('change');
        }
    } catch (e) {}

    $period.on('change', function () {
        if (dirty.size && !confirm('You have unsaved changes. Switch cutoff and lose them?')) {
            return;
        }
        dirty.clear();
        load();
    });
    $dept.on('change', function () {
        if (dirty.size && !confirm('You have unsaved changes. Switch department and lose them?')) {
            return;
        }
        dirty.clear();
        loadAreas();   // resets #dr-area to "All areas" for the new department, synchronously
        load();
    });
    $area.on('change', function () {
        if (dirty.size && !confirm('You have unsaved changes. Switch area and lose them?')) {
            return;
        }
        dirty.clear();
        load();
    });
    $search.on('input', function () { render(); });
    $min.on('input', function () {
        try { localStorage.setItem('dr-min', $min.val()); } catch (e) {}
        renderCoverage(visibleEmployees());
    });

    $dept.on('change', function () { try { localStorage.setItem('dr-dept', $dept.val()); } catch (e) {} });
    $period.on('change', function () { try { localStorage.setItem('dr-period', $period.val()); } catch (e) {} });

    // Final label resync. autocomplete="off" already stops the browser writing
    // a restored value in behind the custom select, but this init runs on
    // jQuery's ready and the enhancement on DOMContentLoaded, and the two have
    // fired in both orders across builds. Re-rendering the label from whatever
    // the native <select> now holds is cheap and makes the outcome independent
    // of that race. Deferred so it lands after the enhancement either way.
    setTimeout(function () {
        if (!window.CustomSelect || !window.CustomSelect.refresh) return;
        window.CustomSelect.refresh($dept[0]);
        window.CustomSelect.refresh($period[0]);
    }, 0);

    // A department may already be restored from localStorage above — populate
    // its areas before the initial load() so the Area filter isn't blank for
    // one refresh on an unscoped session that reopens the page.
    loadAreas();
    load();
});
