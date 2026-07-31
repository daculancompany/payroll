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

    // A punch time, marked "+N" when it landed N calendar days after the row it
    // belongs to. An overnight shift's out is filed under the day the shift
    // STARTED, so an unmarked "6:10" on the 29th looks like the employee left
    // twelve hours before arriving. The marker carries the real date on hover.
    function punch(txt, off, iso, tip) {
        if (!txt) return '';
        off = Number(off || 0);
        if (off <= 0) return esc(txt);
        // Line 1 states the fact, line 2 gives the full stamp the cell can't show
        // — the sheet prints "6:10" with no AM/PM, which is the whole ambiguity.
        var day = off === 1 ? 'Next day' : off + ' days later';
        // tabindex: on a phone there is no hover, so the marker has to be
        // focusable for a tap to reveal the tooltip.
        return esc(txt)
            + '<sup class="nextday" tabindex="0" role="note" data-tip="'
            + esc(day + ' — punched after midnight') + '\n'
            + esc(tip || fmtDay(iso, off) + ' · ' + txt) + '">+' + off + '</sup>';
    }
    var MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var DOW = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    function fmtDay(iso, off) {
        var d = new Date(iso + 'T00:00:00');
        d.setDate(d.getDate() + Number(off || 0));
        return DOW[d.getDay()] + ', ' + MON[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    }

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
        // Which shift the day ran on. Colour-coded by when it starts so a month
        // of mixed rotations is readable at a glance: day / afternoon / night.
        // Carries `note` for the tooltip only — it is not an absence reason, so
        // blank days must not print it across their time cells (see below).
        if (m.k === 'sched') {
            var kind = m.g ? 'noc' : (m.sh >= 12 ? 'eve' : 'day');
            var kindLbl = { day: 'Day shift', eve: 'Afternoon shift', noc: 'Night shift' }[kind];
            return {
                k: 'sched', cls: 'dm-sch dm-sch-' + kind,
                ltr: '<i class="ri-calendar-2-line"></i>',
                // Two lines: the shift's own name, then the hours it covers and
                // what kind of shift that makes it.
                note: String(m.lbl || 'SHIFT')
                    + (m.st ? '\n' + m.st + ' – ' + m.et + ' · ' + kindLbl : '\n' + kindLbl)
            };
        }
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
                return '<span class="dm ' + i.cls + '" tabindex="0" role="note" data-tip="'
                    + esc(i.note) + '">' + i.ltr + '</span>';
            }).join('');
            if (!d) {
                // A marked blank day explains itself across the time cells,
                // the way a paper DTR is annotated (HOLIDAY / SL / DAY OFF).
                // The shift chip is excluded — it says what was scheduled, not
                // why nobody clocked in, and would otherwise stamp every blank.
                var reasons = infos.filter(function (i) { return i.k !== 'sched'; });
                var blankTimes = reasons.length
                    ? '<td class="dm-note ' + reasons[0].cls + '" colspan="' + (ampm ? 4 : 2) + '">'
                      + esc(reasons.map(function (i) { return i.note; }).join(' · ')) + '</td>'
                    : (ampm ? '<td></td><td></td><td></td><td></td>' : '<td></td><td></td>');
                rows += '<tr class="absent' + wkend + '"><td class="day">' + dayNo + '</td>' + blankTimes
                    + '<td class="x-col num"></td><td class="x-col num ot"></td>'
                    + '<td class="ut"></td><td class="ut"></td>'
                    + '<td class="x-col num late"></td></tr>';
                return;
            }
            var ut = utSplit(d.ut);
            var times = ampm
                ? '<td>' + esc(d.am_in) + '</td><td>' + esc(d.am_out) + '</td><td>' + esc(d.pm_in) + '</td>'
                  + '<td>' + punch(d.pm_out, d.out_off, iso, d.out_tip) + '</td>'
                : '<td>' + punch(d.in, d.in_off, iso, d.in_tip) + '</td>'
                  + '<td>' + punch(d.out, d.out_off, iso, d.out_tip) + '</td>';
            // Hovering the day number of a worked day tells the whole story of
            // that day: the shift it ran on, then whatever was written into
            // DTR_details.notes. The chips stay for at-a-glance colour; this is
            // the detail behind them, on the target an admin naturally points at.
            var sched  = infos.filter(function (i) { return i.k === 'sched'; })[0];
            var tipLines = [];
            if (sched) tipLines.push(sched.note);
            if (d.note) tipLines.push(d.note);
            var noTip = tipLines.length
                ? '<span class="day-no has-tip" tabindex="0" role="note" data-tip="'
                  + esc(tipLines.join('\n')) + '">' + dayNo + '</span>'
                : '<span class="day-no">' + dayNo + '</span>';
            var dayCell = badges
                ? noTip + '<span class="day-marks">' + badges + '</span>'
                : (tipLines.length ? noTip : dayNo);
            rows += '<tr class="' + wkend.trim() + '">'
                + '<td class="day">' + dayCell + '</td>' + times
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
              + '<th class="x-col" rowspan="2">Work Hrs</th><th class="x-col" rowspan="2">Overtime</th>'
              + '<th colspan="2">UNDERTIME</th><th class="x-col" rowspan="2">Late (min)</th></tr>'
              + '<tr><th>Arrival</th><th>Departure</th><th>Arrival</th><th>Departure</th><th>Hours</th><th>Minutes</th></tr>'
            : '<tr><th rowspan="2">Day</th><th colspan="2">TIME</th>'
              + '<th class="x-col" rowspan="2">Work Hrs</th><th class="x-col" rowspan="2">Overtime</th>'
              + '<th colspan="2">UNDERTIME</th><th class="x-col" rowspan="2">Late (min)</th></tr>'
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
