<?php
/* ──────────────────────────────────────────────────────────────────────────
 * Shared leave approval timeline.
 * Renders a vertical "Filed → stage → stage → …" timeline showing who acted on
 * each stage and WHEN it was approved/rejected. Driven entirely by
 * LEAVE_APPROVAL_STAGES (db_connect.php) so it stays in sync with the workflow.
 *
 * Stages that have NOT acted yet name the person the request is waiting on,
 * resolved live through leave_stage_approver_names() — the same area/role
 * lookup that decides who may press Approve, so the trail answers "who is
 * sitting on this?" and not just "how far did it get?". A slot holding two
 * co-approvers shows both, joined by " / ", since either of them may act.
 *
 * Used by both the admin leaves page (leaves.php) and the employee portal
 * (employee-portal.php).
 *
 * Expected keys on $row (per stage KEY in LEAVE_APPROVAL_STAGES):
 *     {KEY}_status, {KEY}_at, {KEY}_remarks, {KEY}_name (approver full name)
 * plus `created_at` / `date_applied` for the "Filed" node and `employee_id`
 * (a plain `lr.*` gives you it) for the pending-approver lookup.
 * ────────────────────────────────────────────────────────────────────────── */

if (!function_exists('leave_timeline_css')) {
    /** Prints the timeline stylesheet once per page (no-op on later calls). */
    function leave_timeline_css(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        ?>
        <style>
        .lvtl{list-style:none;margin:0;padding:0;position:relative;}

        /* Each stage is a card, so scanning the list is reading a stack of
           records rather than hunting coloured dots down a bare rail. */
        .lvtl li{position:relative;padding:0 0 8px 34px;font-size:12px;line-height:1.45;}
        .lvtl li:last-child{padding-bottom:0;}
        .lvtl .lvtl-card{border:1px solid #e9edf4;background:#fbfcfe;border-radius:10px;padding:8px 12px;
            transition:background .15s,border-color .15s,box-shadow .15s;}
        .lvtl li:hover .lvtl-card{background:#fff;border-color:#dde4ee;box-shadow:0 1px 3px rgba(20,25,45,.06);}

        /* Connector: solid through decided stages, dashed once the chain runs
           out of decisions — the trail stops being a record and starts being a
           forecast at exactly that point. */
        .lvtl li::before{content:'';position:absolute;left:11px;top:28px;bottom:-2px;width:2px;
            background:#e3e8f0;border-radius:2px;}
        .lvtl li.is-ok::before,.lvtl li.is-filed::before{background:#c9bde6;}
        .lvtl li.is-no::before{background:#f0b6bd;}
        .lvtl li.is-next::before{background:repeating-linear-gradient(#dfe4ec 0 4px,transparent 4px 8px);}
        .lvtl li:last-child::before{display:none;}

        .lvtl .lvtl-dot{position:absolute;left:1px;top:7px;width:22px;height:22px;border-radius:50%;
            display:flex;align-items:center;justify-content:center;font-size:12px;color:#fff;background:#c2c9d6;
            box-shadow:0 0 0 3px #fff,0 1px 3px rgba(20,25,45,.18);z-index:1;}
        .lvtl .lvtl-dot.ok{background:linear-gradient(135deg,#5b3d96,#4e3483);}
        .lvtl .lvtl-dot.no{background:linear-gradient(135deg,#e4606d,#dc3545);}
        .lvtl .lvtl-dot.wait{background:linear-gradient(135deg,#ff9a3c,#fd7e14);
            box-shadow:0 0 0 3px #fff,0 0 0 6px rgba(253,126,20,.16),0 1px 3px rgba(20,25,45,.18);}
        .lvtl .lvtl-dot.filed{background:linear-gradient(135deg,#3d8bfd,#0d6efd);}
        .lvtl .lvtl-dot.skip{background:#eceff1;color:#78909c;box-shadow:0 0 0 3px #fff;}

        /* Tint only the cards that need attention or explain a problem. */
        .lvtl li.is-wait .lvtl-card{background:#fffaf3;border-color:#ffe0bd;}
        .lvtl li.is-wait:hover .lvtl-card{background:#fff6ec;border-color:#ffd4a3;}
        .lvtl li.is-no .lvtl-card{background:#fff6f7;border-color:#f6d3d7;}
        .lvtl li.is-skip .lvtl-card{background:#f8f9fb;border-color:#eceff4;}
        .lvtl li.is-next .lvtl-card{background:#fcfdfe;border-style:dashed;}

        /* Stage name and outcome share one row: the label reads down the left
           edge, the verdict lines up down the right. */
        .lvtl-head{display:flex;align-items:center;gap:8px;}
        .lvtl-stage{font-weight:700;color:#242a35;letter-spacing:.1px;flex:1 1 auto;min-width:0;}
        .lvtl li.is-next .lvtl-stage{color:#9aa3b2;font-weight:600;}
        .lvtl li.is-skip .lvtl-stage{color:#78909c;}

        .lvtl-pill{flex:0 0 auto;font-size:9.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;
            border-radius:20px;padding:2px 9px;border:1px solid transparent;white-space:nowrap;}
        .lvtl-pill.ok  {background:#e8f6ed;color:#1b7a43;border-color:#c9e9d5;}
        .lvtl-pill.no  {background:#fdecee;color:#c62828;border-color:#f7ccd1;}
        .lvtl-pill.wait{background:#fff3e0;color:#c76b00;border-color:#ffd9ab;}
        .lvtl-pill.skip{background:#eef1f5;color:#68758a;border-color:#e0e5ec;}
        .lvtl-pill.next{background:#f4f6f9;color:#9aa3b2;border-color:#e6eaf1;}
        .lvtl-pill.wait::before{content:'';display:inline-block;width:5px;height:5px;border-radius:50%;
            background:#fd7e14;margin-right:5px;vertical-align:1px;animation:lvtl-pulse 1.6s ease-in-out infinite;}
        @keyframes lvtl-pulse{0%,100%{opacity:1;}50%{opacity:.25;}}
        @media (prefers-reduced-motion:reduce){.lvtl-pill.wait::before{animation:none;}}

        .lvtl-name{color:#3f4a5a;font-weight:600;margin-top:3px;}
        .lvtl-meta{color:#8a94a6;font-size:11px;margin-top:2px;}

        /* Timestamps are the column a reader scans to answer "how long did this
           sit?", so they get one consistent shape everywhere instead of being
           loose grey text that blends into the names above them. */
        .lvtl-time{display:inline-flex;align-items:center;gap:5px;margin-top:5px;
            font-size:10.5px;font-weight:600;color:#77839a;font-variant-numeric:tabular-nums;
            background:#f1f4f8;border:1px solid #e6ebf2;border-radius:20px;padding:2px 9px;}
        .lvtl-time i{font-size:11px;line-height:1;color:#98a3b6;}
        .lvtl li.is-no .lvtl-time{background:#fdeef0;border-color:#f6d3d7;color:#b4525c;}
        .lvtl li.is-no .lvtl-time i{color:#d08b93;}
        .lvtl li.is-filed .lvtl-time{background:#eef4ff;border-color:#d8e5fb;color:#4a6fa5;}
        .lvtl li.is-filed .lvtl-time i{color:#7a9cd0;}
        .lvtl-who{color:#4e3483;font-weight:600;}
        .lvtl-nobody{color:#c77700;font-weight:600;}
        .lvtl-remark{color:#c0303d;background:#fdf1f2;border-left:2px solid #f0b6bd;border-radius:0 5px 5px 0;
            font-size:11px;margin-top:6px;padding:4px 8px;}
        .lvtl li.is-skip .lvtl-remark{color:#5f6b7a;background:#f2f4f7;border-left-color:#cfd6e0;}
        </style>
        <?php
    }
}

if (!function_exists('leave_stage_badge')) {
    /**
     * One approval-stage badge for a list cell (Approved / Rejected / Pending /
     * Skipped) with approver + reason in the tooltip. Shared by the leave queue
     * and the attendance-request queue — both run the same chain.
     */
    function leave_stage_badge($status, $by_name, $remarks, $at, $by_id = 0): string
    {
        // Auto-skipped stage: stored approved so the chain advances, but with no
        // approver. Distinguished so the column never credits a decision to nobody.
        if ($status == 1 && !$by_id && $remarks) {
            return '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" title="' . htmlspecialchars($remarks) . '"><i class="ri-skip-forward-line me-1"></i>Skipped</span>';
        }
        if ($status == 1) {
            $t = 'Approved' . ($by_name ? ' by ' . htmlspecialchars($by_name) : '') . ($at ? ' • ' . date('M d, Y', strtotime($at)) : '');
            return '<span class="badge bg-success-subtle text-success border border-success-subtle" title="' . $t . '"><i class="ri-check-line me-1"></i>Approved</span>'
                 . ($by_name ? '<div class="text-muted" style="font-size:10px;">' . htmlspecialchars($by_name) . '</div>' : '');
        }
        if ($status == 2) {
            $t = 'Rejected' . ($by_name ? ' by ' . htmlspecialchars($by_name) : '') . ($remarks ? ' — ' . htmlspecialchars($remarks) : '');
            return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle" title="' . $t . '"><i class="ri-close-line me-1"></i>Rejected</span>'
                 . ($remarks ? '<div class="text-danger" style="font-size:10px;" title="' . htmlspecialchars($remarks) . '"><i class="ri-information-line"></i> ' . htmlspecialchars(mb_strimwidth($remarks, 0, 28, '…')) . '</div>' : '');
        }
        return '<span class="badge bg-warning-subtle text-warning border border-warning-subtle"><i class="ri-time-line me-1"></i>Pending</span>';
    }
}

if (!function_exists('leave_stage_chips')) {
    /**
     * Compact one-icon-per-stage strip for the employee portal lists (leave and
     * attendance requests): green check / red cross / orange clock, the stage
     * name in the tooltip. Skipped stages read as passed — to the employee the
     * chain simply moved on.
     */
    function leave_stage_chips(array $row): string
    {
        $h = '';
        foreach (leave_stages() as $key => $cfg) {
            $s = (int) ($row[$key . '_status'] ?? 0);
            $label = htmlspecialchars($cfg['label']);
            if ($s === 1)      $h .= '<span class="lv-chip" style="color:#4e3483;" title="' . $label . ': Approved"><i class="ri-checkbox-circle-fill"></i></span>';
            elseif ($s === 2)  $h .= '<span class="lv-chip" style="color:#dc3545;" title="' . $label . ': Rejected"><i class="ri-close-circle-fill"></i></span>';
            else               $h .= '<span class="lv-chip" style="color:#fd7e14;" title="' . $label . ': Pending"><i class="ri-time-fill"></i></span>';
        }
        return $h;
    }
}

if (!function_exists('leave_timeline_html')) {
    /** Returns the HTML for one leave request's approval timeline. */
    function leave_timeline_html(array $row): string
    {
        $fmt = static function ($dt) {
            $ts = $dt ? strtotime($dt) : false;
            return $ts ? date('M d, Y · g:i A', $ts) : '';
        };
        $esc = static fn($v) => htmlspecialchars((string) $v);

        // Every timestamp in the trail renders through here, so the clock chip
        // stays identical on the Filed node and on each decided stage.
        $stamp = static function ($dt) use ($fmt, $esc): string {
            $t = $fmt($dt);
            return $t === '' ? '' : '<div class="lvtl-time"><i class="ri-time-line"></i>' . $esc($t) . '</div>';
        };

        // "Filed" node — first thing that happened.
        $filed_at = $row['created_at'] ?? $row['date_applied'] ?? null;
        $h  = '<ul class="lvtl">';
        $h .= '<li class="is-filed"><span class="lvtl-dot filed"><i class="ri-flag-line"></i></span>'
            . '<div class="lvtl-card">'
            . '<div class="lvtl-head"><span class="lvtl-stage">Filed</span>'
            . '<span class="lvtl-pill ok">Submitted</span></div>'
            . $stamp($filed_at)
            . '</div></li>';

        $current = leave_current_stage($row); // stage awaiting action right now

        // Who is designated to decide a stage that has not acted yet. Same
        // resolution the approve button uses (area_approver first, role second),
        // so the name shown is genuinely the person the request is sitting with.
        // Two co-approvers on one slot are joined with " / " — either may act.
        $whoFor = static function (string $key) use ($row): string {
            $db  = $GLOBALS['conn'] ?? null;
            $eid = (int) ($row['employee_id'] ?? 0);
            if (!($db instanceof mysqli) || $eid <= 0
                || !function_exists('leave_stage_approver_names')) return '';
            return implode(' / ', leave_stage_approver_names($db, $key, $eid));
        };

        foreach (leave_stages() as $key => $s) {
            $status = (int) ($row[$key . '_status'] ?? 0);
            $name   = trim((string) ($row[$key . '_name'] ?? ''));
            $at     = $row[$key . '_at'] ?? null;
            $remark = trim((string) ($row[$key . '_remarks'] ?? ''));
            $label  = $esc($s['label']);

            // An auto-skipped stage is stored as approved so the chain advances,
            // but it has no approver — showing it as "Approved" would credit a
            // decision nobody made. A real approval always stamps {key}_by.
            $skipped = ($status === 1 && ($row[$key . '_by'] ?? null) === null && $remark !== '');

            // One shape for every state: stage name and verdict on the head row,
            // then who, then when. Only the pill and the tint change.
            $head = static fn(string $pill, string $text): string =>
                '<div class="lvtl-head"><span class="lvtl-stage">' . $label . '</span>'
                . '<span class="lvtl-pill ' . $pill . '">' . $text . '</span></div>';

            if ($skipped) {
                $cls  = 'is-skip';
                $dot  = '<span class="lvtl-dot skip"><i class="ri-skip-forward-line"></i></span>';
                $line = $head('skip', 'Skipped')
                      . '<div class="lvtl-remark"><i class="ri-information-line"></i> ' . $esc($remark) . '</div>';
            } elseif ($status === 1) {    // approved
                $cls  = 'is-ok';
                $dot  = '<span class="lvtl-dot ok"><i class="ri-check-line"></i></span>';
                $line = $head('ok', 'Approved')
                      . ($name ? '<div class="lvtl-name">' . $esc($name) . '</div>' : '')
                      . $stamp($at);
            } elseif ($status === 2) {    // rejected
                $cls  = 'is-no';
                $dot  = '<span class="lvtl-dot no"><i class="ri-close-line"></i></span>';
                $line = $head('no', 'Rejected')
                      . ($name ? '<div class="lvtl-name">' . $esc($name) . '</div>' : '')
                      . $stamp($at)
                      . ($remark ? '<div class="lvtl-remark"><i class="ri-information-line"></i> ' . $esc($remark) . '</div>' : '');
            } elseif ($key === $current) { // awaiting this stage now
                $who  = $whoFor($key);
                $cls  = 'is-wait';
                $dot  = '<span class="lvtl-dot wait"><i class="ri-time-line"></i></span>';
                $line = $head('wait', 'Awaiting')
                      . '<div class="lvtl-meta">' . ($who !== ''
                            ? 'To be approved by <span class="lvtl-who">' . $esc($who) . '</span>'
                            : '<span class="lvtl-nobody">No approver assigned</span>') . '</div>';
            } else {                       // not reached yet
                $who  = $whoFor($key);
                $cls  = 'is-next';
                $icon = $esc($s['icon'] ?? 'ri-more-line');
                $dot  = '<span class="lvtl-dot"><i class="' . $icon . '"></i></span>';
                $line = $head('next', 'Pending')
                      . '<div class="lvtl-meta">Waiting on the stage before this'
                      . ($who !== '' ? ' · <span class="lvtl-who">' . $esc($who) . '</span>' : '')
                      . '</div>';
            }
            $h .= '<li class="' . $cls . '">' . $dot . '<div class="lvtl-card">' . $line . '</div></li>';
        }

        // Approved leave later cancelled by HR / Admin (leave_requests.status 3).
        // Keyed on cancelled_at so attendance requests, which share this
        // builder but have no such column, never hit it.
        if ((int) ($row['status'] ?? 0) === 3 && !empty($row['cancelled_at'])) {
            $cname = trim((string) ($row['cancelled_name'] ?? ''));
            if ($cname === '' && !empty($row['cancelled_by']) && ($GLOBALS['conn'] ?? null) instanceof mysqli) {
                $cq = $GLOBALS['conn']->query("SELECT name FROM users WHERE id = " . (int) $row['cancelled_by']);
                $cname = trim((string) (($cq ? $cq->fetch_assoc() : null)['name'] ?? ''));
            }
            $creason = trim((string) ($row['cancel_reason'] ?? ''));
            // Leave gives its days back; an attendance request is undone on the DTR / payroll.
            $cpill = array_key_exists('request_type', $row) ? 'Reversed' : 'Days returned';
            $h .= '<li class="is-no"><span class="lvtl-dot no"><i class="ri-arrow-go-back-line"></i></span>'
                . '<div class="lvtl-card">'
                . '<div class="lvtl-head"><span class="lvtl-stage">Cancelled</span>'
                . '<span class="lvtl-pill no">' . $cpill . '</span></div>'
                . ($cname !== '' ? '<div class="lvtl-name">' . $esc($cname) . '</div>' : '')
                . $stamp($row['cancelled_at'])
                . ($creason !== '' ? '<div class="lvtl-remark"><i class="ri-information-line"></i> ' . $esc($creason) . '</div>' : '')
                . '</div></li>';
        }

        $h .= '</ul>';
        return $h;
    }
}

if (!function_exists('leave_request_days')) {
    /**
     * The exact days a leave covers, as sorted 'Y-m-d' strings.
     *
     * Filing picks days on a calendar and stores them in `dates` (JSON), and they
     * need not be consecutive: a 5-day leave can be Sep 28–29 plus Oct 7–9. The
     * date_from/date_to pair is only the outer bound of that set, so printing it
     * reads as 12 days. It is the fallback for rows with no usable `dates`, never
     * the source of truth.
     */
    function leave_request_days(array $row): array
    {
        $days = [];
        $raw  = $row['dates'] ?? '';
        if (is_string($raw) && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                foreach ($j as $d) {
                    if (!is_string($d)) continue;
                    $dt = DateTime::createFromFormat('!Y-m-d', $d);
                    if ($dt && $dt->format('Y-m-d') === $d) $days[$d] = true;
                }
            }
        }
        if (!$days && !empty($row['date_from'])) {
            $s = strtotime((string) $row['date_from']);
            $e = strtotime((string) (!empty($row['date_to']) ? $row['date_to'] : $row['date_from']));
            // The cap only guards against a corrupt range looping for years.
            for ($t = $s, $i = 0; $s !== false && $e !== false && $t <= $e && $i < 366; $t = strtotime('+1 day', $t), $i++) {
                $days[date('Y-m-d', $t)] = true;
            }
        }
        $days = array_keys($days);
        sort($days);
        return $days;
    }
}

if (!function_exists('leave_days_label')) {
    /**
     * A leave's days as compact runs for a list cell: "Sep 28–29 · Oct 7–9, 2026".
     *
     * Consecutive days collapse into a run and a gap starts a new one, so a split
     * leave is visibly split. The month is repeated only when it changes and the
     * year only where it changes ("Dec 30–31, 2026 · Jan 2, 2027"). A half day is
     * always its own run with a ½ mark, because "Sep 15–17" would claim three
     * full days. Built from dates alone, so the result is safe to echo.
     */
    function leave_days_label(array $row): string
    {
        $days = leave_request_days($row);
        if (!$days) return '';
        $half = (!empty($row['is_half_day']) && !empty($row['half_date']))
            ? date('Y-m-d', strtotime((string) $row['half_date'])) : '';

        $runs = [];
        foreach ($days as $d) {
            $t    = strtotime($d);
            $last = count($runs) - 1;
            // A run never crosses New Year: "Dec 31–Jan 1, 2027" would date the
            // first day to the wrong year, since the year is printed once per run.
            $joins = $last >= 0 && !$runs[$last]['half'] && $d !== $half
                  && date('Y-m-d', strtotime('+1 day', $runs[$last]['end'])) === $d
                  && date('Y', $runs[$last]['end']) === substr($d, 0, 4);
            if ($joins) $runs[$last]['end'] = $t;
            else        $runs[] = ['start' => $t, 'end' => $t, 'half' => $d === $half];
        }

        $out = [];
        $prevMonth = '';
        foreach ($runs as $i => $r) {
            $piece = (date('Y-m', $r['start']) !== $prevMonth ? date('M j', $r['start']) : date('j', $r['start']));
            $prevMonth = date('Y-m', $r['start']);
            if ($r['end'] !== $r['start']) {
                $piece .= '–' . (date('Y-m', $r['end']) !== $prevMonth ? date('M j', $r['end']) : date('j', $r['end']));
                $prevMonth = date('Y-m', $r['end']);
            }
            if ($r['half']) $piece .= '½';
            $next = $runs[$i + 1] ?? null;
            if ($next === null || date('Y', $next['start']) !== date('Y', $r['end'])) {
                $piece .= ', ' . date('Y', $r['end']);
            }
            $out[] = $piece;
        }
        return implode(' · ', $out);
    }
}

if (!function_exists('leave_days_title')) {
    /** Every day spelled out with its weekday, for a tooltip: "Mon, Sep 28 · Tue, Sep 29". */
    function leave_days_title(array $row): string
    {
        $half = (!empty($row['is_half_day']) && !empty($row['half_date']))
            ? date('Y-m-d', strtotime((string) $row['half_date'])) : '';
        return implode(' · ', array_map(function ($d) use ($half) {
            return date('D, M j', strtotime($d)) . ($d === $half ? ' (half day)' : '');
        }, leave_request_days($row)));
    }
}
