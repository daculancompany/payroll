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
        .lvtl{list-style:none;margin:0;padding:4px 2px 2px;position:relative;}

        /* Each stage is a card so the row the request is sitting on can be
           lifted out of the list visually instead of only by dot colour. */
        .lvtl li{position:relative;padding:2px 10px 16px 34px;font-size:12px;line-height:1.4;}
        .lvtl li:last-child{padding-bottom:2px;}
        .lvtl li .lvtl-card{border-radius:10px;padding:5px 10px 6px;margin-left:-6px;
            background:transparent;border:1px solid transparent;transition:background .15s,border-color .15s;}
        .lvtl li:hover .lvtl-card{background:#f7f8fb;border-color:#eef1f6;}

        /* Connector: solid through decided stages, dashed once the chain runs
           out of decisions — the trail stops being a record and starts being a
           forecast at exactly that point. */
        .lvtl li::before{content:'';position:absolute;left:10px;top:22px;bottom:-2px;width:2px;
            background:linear-gradient(#dfe4ec,#e8ecf3);border-radius:2px;}
        .lvtl li.is-ok::before,.lvtl li.is-filed::before{background:linear-gradient(#4e3483,#6c4bb0);opacity:.55;}
        .lvtl li.is-no::before{background:#f0b6bd;}
        .lvtl li.is-next::before{background:repeating-linear-gradient(#dfe4ec 0 4px,transparent 4px 8px);}
        .lvtl li:last-child::before{display:none;}

        .lvtl .lvtl-dot{position:absolute;left:1px;top:4px;width:20px;height:20px;border-radius:50%;
            display:flex;align-items:center;justify-content:center;font-size:11px;color:#fff;background:#c2c9d6;
            box-shadow:0 0 0 3px #fff,0 1px 3px rgba(20,25,45,.18);z-index:1;}
        .lvtl .lvtl-dot.ok{background:linear-gradient(135deg,#5b3d96,#4e3483);}
        .lvtl .lvtl-dot.no{background:linear-gradient(135deg,#e4606d,#dc3545);}
        .lvtl .lvtl-dot.wait{background:linear-gradient(135deg,#ff9a3c,#fd7e14);
            box-shadow:0 0 0 3px #fff,0 0 0 6px rgba(253,126,20,.16),0 1px 3px rgba(20,25,45,.18);}
        .lvtl .lvtl-dot.filed{background:linear-gradient(135deg,#3d8bfd,#0d6efd);}

        /* The awaiting stage is the one thing a reader is looking for. */
        .lvtl li.is-wait .lvtl-card{background:#fff9f2;border-color:#ffe3c4;}
        .lvtl li.is-wait:hover .lvtl-card{background:#fff5e9;border-color:#ffd7ab;}
        .lvtl li.is-no .lvtl-card{background:#fff5f6;border-color:#f7d6da;}

        .lvtl-stage{font-weight:700;color:#242a35;letter-spacing:.1px;}
        .lvtl li.is-wait .lvtl-stage{color:#b35b00;}
        .lvtl-meta{color:#8a94a6;font-size:11px;margin-top:1px;}
        .lvtl-name{color:#4e3483;font-weight:600;}
        .lvtl-who{color:#5c6b85;font-weight:600;}
        .lvtl-nobody{color:#c77700;font-weight:600;}
        .lvtl-rej{color:#dc3545;font-weight:600;}
        .lvtl-remark{color:#c0303d;background:#fdf1f2;border-left:2px solid #f0b6bd;border-radius:0 5px 5px 0;
            font-size:11px;margin-top:4px;padding:3px 7px;}
        .lvtl li.is-skip .lvtl-remark{color:#5f6b7a;background:#f2f4f7;border-left-color:#cfd6e0;}
        .lvtl-badge{display:inline-block;font-size:10px;font-weight:700;border-radius:20px;padding:1px 8px;margin-left:5px;
            background:#fff3e0;color:#e07500;border:1px solid #ffd9ab;vertical-align:1px;}
        .lvtl-badge::before{content:'';display:inline-block;width:5px;height:5px;border-radius:50%;
            background:#fd7e14;margin-right:5px;vertical-align:1px;animation:lvtl-pulse 1.6s ease-in-out infinite;}
        @keyframes lvtl-pulse{0%,100%{opacity:1;}50%{opacity:.25;}}
        @media (prefers-reduced-motion:reduce){.lvtl-badge::before{animation:none;}}
        </style>
        <?php
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

        // "Filed" node — first thing that happened.
        $filed_at = $row['created_at'] ?? $row['date_applied'] ?? null;
        $h  = '<ul class="lvtl">';
        $h .= '<li class="is-filed"><span class="lvtl-dot filed"><i class="ri-flag-line"></i></span>'
            . '<div class="lvtl-card"><span class="lvtl-stage">Filed</span>'
            . ($filed_at ? '<div class="lvtl-meta">' . $esc($fmt($filed_at)) . '</div>' : '')
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

            if ($skipped) {
                $cls  = 'is-skip';
                $dot  = '<span class="lvtl-dot" style="background:#eceff1;color:#78909c;box-shadow:0 0 0 3px #fff;"><i class="ri-skip-forward-line"></i></span>';
                $line = '<span class="lvtl-stage" style="color:#78909c;">' . $label . '</span> '
                      . '<span class="lvtl-name" style="color:#78909c;">Skipped</span>'
                      . '<div class="lvtl-remark"><i class="ri-information-line"></i> ' . $esc($remark) . '</div>';
            } elseif ($status === 1) {    // approved
                $cls  = 'is-ok';
                $dot  = '<span class="lvtl-dot ok"><i class="ri-check-line"></i></span>';
                $line = '<span class="lvtl-stage">' . $label . '</span> '
                      . '<span class="lvtl-name">Approved' . ($name ? ' · ' . $esc($name) : '') . '</span>'
                      . ($at ? '<div class="lvtl-meta">' . $esc($fmt($at)) . '</div>' : '');
            } elseif ($status === 2) {    // rejected
                $cls  = 'is-no';
                $dot  = '<span class="lvtl-dot no"><i class="ri-close-line"></i></span>';
                $line = '<span class="lvtl-stage">' . $label . '</span> '
                      . '<span class="lvtl-rej">Rejected' . ($name ? ' · ' . $esc($name) : '') . '</span>'
                      . ($at ? '<div class="lvtl-meta">' . $esc($fmt($at)) . '</div>' : '')
                      . ($remark ? '<div class="lvtl-remark"><i class="ri-information-line"></i> ' . $esc($remark) . '</div>' : '');
            } elseif ($key === $current) { // awaiting this stage now
                $who  = $whoFor($key);
                $cls  = 'is-wait';
                $dot  = '<span class="lvtl-dot wait"><i class="ri-time-line"></i></span>';
                $line = '<span class="lvtl-stage">' . $label . '</span>'
                      . '<span class="lvtl-badge">Awaiting</span>'
                      . '<div class="lvtl-meta">' . ($who !== ''
                            ? 'To be approved by <span class="lvtl-who">' . $esc($who) . '</span>'
                            : '<span class="lvtl-nobody">No approver assigned</span>') . '</div>';
            } else {                       // not reached yet
                $who  = $whoFor($key);
                $cls  = 'is-next';
                $icon = $esc($s['icon'] ?? 'ri-more-line');
                $dot  = '<span class="lvtl-dot"><i class="' . $icon . '"></i></span>';
                $line = '<span class="lvtl-stage" style="color:#9aa3b2;">' . $label . '</span>'
                      . '<div class="lvtl-meta">Pending earlier approval'
                      . ($who !== '' ? ' · <span class="lvtl-who">' . $esc($who) . '</span>' : '')
                      . '</div>';
            }
            $h .= '<li class="' . $cls . '">' . $dot . '<div class="lvtl-card">' . $line . '</div></li>';
        }

        $h .= '</ul>';
        return $h;
    }
}
