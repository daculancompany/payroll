/* ==========================================================================
   Global DTR template renderer — Civil Service Form No. 48 (Daily Time Record)
   Shared by dtr-documents.php (admin) and employee-portal.php so both render an
   identical, enhanced Form 48. Framework-free: returns an HTML string.

   Styles: assets2/css/dtr-form48.css   (load it on any page that calls render)

   window.DTRForm48.render({
     name,                       // "LAST, FIRST MIDDLE"
     periodLabel,                // "July 16–31, 2026"  (pretty header text)
     dateFrom, dateTo,           // "YYYY-MM-DD" — used to walk every day of the period
     logMode,                    // 'single' (Arrival/Departure) | 'ampm' (A.M./P.M.)
     regularDays, saturdays,     // header blanks (optional)
     officialArrival, officialDeparture, // header blanks (optional)
     compact,                    // true → tighter type for modals
     days,                       // { 'YYYY-MM-DD': {in,out,am_in,am_out,pm_in,pm_out,wh,ot,ut,late} }
     totals,                     // { wh, ot, ut, late }
     marks                       // optional { 'YYYY-MM-DD': [marker,…] } — see markInfo()
   }) => HTML string

   Every day between dateFrom..dateTo is emitted; days missing from `days`
   render as blank (absent) rows so the sheet always spans the full period.
   ========================================================================== */
(function () {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function num(v, dp) { return Number(v || 0).toFixed(dp == null ? 2 : dp); }
    // Undertime is stored as decimal hours; the paper form wants Hours + Minutes.
    function utSplit(ut) { var h = Math.floor(ut || 0); return [h, Math.round(((ut || 0) - h) * 60)]; }

    function eachDay(from, to, cb) {
        if (!from || !to) return;
        var d = new Date(from + 'T00:00:00'), end = new Date(to + 'T00:00:00');
        while (d <= end) {
            var iso = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            cb(iso, d.getDate(), d.getDay());
            d.setDate(d.getDate() + 1);
        }
    }

    // ── Day markers: holiday / leave / day-off / attendance request ──
    // Server shape (dtr-employee-server.php `marks`):
    //   holiday {k,t:'legal'|'special',lbl}  leave {k,lbl,s,half}
    //   off {k}                              req  {k,t:'incident'|'overtime',s}
    function markInfo(m) {
        if (m.k === 'holiday') return {
            cls: m.t === 'legal' ? 'dm-hol' : 'dm-spc', ltr: 'H',
            note: (m.t === 'legal' ? 'LEGAL HOLIDAY' : 'SPECIAL HOLIDAY') + (m.lbl ? ' — ' + m.lbl : '')
        };
        if (m.k === 'leave') return {
            cls: 'dm-lv', ltr: 'L',
            note: String(m.lbl || 'LEAVE').toUpperCase()
                + (m.half ? ' (HALF DAY)' : '') + (m.s === 0 ? ' (PENDING)' : '')
        };
        if (m.k === 'off') return { cls: 'dm-off', ltr: 'D', note: 'DAY OFF' };
        return {
            cls: 'dm-req', ltr: 'R',
            note: (m.t === 'overtime' ? 'OT REQUEST' : 'INCIDENT REPORT')
                + (m.s === 0 ? ' (PENDING)' : ' (APPROVED)')
        };
    }

    function render(opt) {
        opt = opt || {};
        var ampm    = opt.logMode === 'ampm';
        var days    = opt.days || {};
        var marks   = opt.marks || {};
        var totals  = opt.totals || {};
        var rd      = opt.regularDays || 'Mon – Fri';
        var sat     = opt.saturdays || 'as required';
        var oa      = opt.officialArrival || '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
        var od      = opt.officialDeparture || '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';

        // ── Body rows: one per calendar day in the period ──
        var rows = '';
        eachDay(opt.dateFrom, opt.dateTo, function (iso, dayNo, dow) {
            var d = days[iso];
            var wkend = (dow === 0 || dow === 6) ? ' wkend' : '';
            var infos = (marks[iso] || []).map(markInfo);
            var badges = infos.map(function (i) {
                return '<span class="dm ' + i.cls + '" title="' + esc(i.note) + '">' + i.ltr + '</span>';
            }).join('');
            if (!d) {
                // A marked blank day explains itself across the time cells,
                // the way a paper DTR is annotated (HOLIDAY / SL / DAY OFF).
                var blankTimes = infos.length
                    ? '<td class="dm-note ' + infos[0].cls + '" colspan="' + (ampm ? 4 : 2) + '">'
                      + esc(infos.map(function (i) { return i.note; }).join(' · ')) + '</td>'
                    : (ampm ? '<td></td><td></td><td></td><td></td>' : '<td></td><td></td>');
                rows += '<tr class="absent' + wkend + '"><td class="day">' + dayNo + '</td>' + blankTimes
                    + '<td class="x-col num"></td><td class="x-col num ot"></td>'
                    + '<td class="ut"></td><td class="ut"></td>'
                    + '<td class="x-col num late"></td></tr>';
                return;
            }
            var ut = utSplit(d.ut);
            var times = ampm
                ? '<td>' + esc(d.am_in) + '</td><td>' + esc(d.am_out) + '</td><td>' + esc(d.pm_in) + '</td><td>' + esc(d.pm_out) + '</td>'
                : '<td>' + esc(d.in) + '</td><td>' + esc(d.out) + '</td>';
            rows += '<tr class="' + wkend.trim() + '">'
                + '<td class="day">' + dayNo + badges + '</td>' + times
                + '<td class="x-col num">' + (d.wh > 0 ? num(d.wh) : '') + '</td>'
                + '<td class="x-col num ot">' + (d.ot > 0 ? num(d.ot) : '') + '</td>'
                + '<td class="ut">' + (d.ut > 0 ? ut[0] : '') + '</td>'
                + '<td class="ut">' + (d.ut > 0 ? ut[1] : '') + '</td>'
                + '<td class="x-col num late">' + (d.late > 0 ? num(d.late) : '') + '</td>'
                + '</tr>';
        });

        // ── Header: adds Work Hrs / Overtime / Late alongside the official cols ──
        var head = ampm
            ? '<tr><th rowspan="2">Day</th><th colspan="2">A.M.</th><th colspan="2">P.M.</th>'
              + '<th class="x-col" rowspan="2">Work<br>Hrs</th><th class="x-col" rowspan="2">Over-<br>time</th>'
              + '<th colspan="2">UNDERTIME</th><th class="x-col" rowspan="2">Late<br>(min)</th></tr>'
              + '<tr><th>Arrival</th><th>Departure</th><th>Arrival</th><th>Departure</th><th>Hours</th><th>Minutes</th></tr>'
            : '<tr><th rowspan="2">Day</th><th colspan="2">TIME</th>'
              + '<th class="x-col" rowspan="2">Work<br>Hrs</th><th class="x-col" rowspan="2">Over-<br>time</th>'
              + '<th colspan="2">UNDERTIME</th><th class="x-col" rowspan="2">Late<br>(min)</th></tr>'
              + '<tr><th>Arrival</th><th>Departure</th><th>Hours</th><th>Minutes</th></tr>';

        // ── Footer: full totals — Work Hrs, OT, UT (H/M) and Late ──
        var tut = utSplit(totals.ut);
        var totalSpan = ampm ? 5 : 3;
        var foot = '<td colspan="' + totalSpan + '">TOTAL</td>'
            + '<td class="x-col num">' + num(totals.wh) + '</td>'
            + '<td class="x-col num ot">' + num(totals.ot) + '</td>'
            + '<td>' + tut[0] + '</td><td>' + tut[1] + '</td>'
            + '<td class="x-col num late">' + num(totals.late) + '</td>';

        return ''
            + '<div class="dtrf48' + (opt.compact ? ' is-compact' : '') + '">'
            + '<div class="p-title">DAILY TIME RECORD</div>'
            + '<div class="p-name">' + esc(opt.name || '') + '</div>'
            + '<div class="p-name-lbl">(NAME)</div>'
            + '<div class="p-line">For the Month of: <b>' + esc(opt.periodLabel || '') + '</b></div>'
            + '<div class="p-line">Official Hours arrival: <b>' + oa + '</b> / <b>' + od + '</b> &nbsp;Regular days: <b>' + esc(rd) + '</b></div>'
            + '<div class="p-line">And departure: <b>' + oa + '</b> / <b>' + od + '</b> &nbsp;Saturdays: <b>' + esc(sat) + '</b></div>'
            + '<table class="dtrf48-table">'
            + '<thead>' + head + '</thead>'
            + '<tbody>' + rows + '</tbody>'
            + '<tfoot><tr>' + foot + '</tr></tfoot>'
            + '</table>'
            + '<div class="p-sign">'
            + 'I certify on my honor that the above is a true and correct report of the hours of work performed, '
            + 'record of which was made daily at the time of arrival and departure from office.'
            + '<div class="sig-line"></div><div class="sig-lbl">(Signature)</div>'
            + '<div class="sig-line"></div><div class="sig-lbl">Verified as to the prescribed office hours &mdash; In Charge</div>'
            + '</div>'
            + '</div>';
    }

    window.DTRForm48 = { render: render };
})();
