<?php
/* ──────────────────────────────────────────────────────────────────────────
 * Shared leave approval timeline.
 * Renders a vertical "Filed → stage → stage → …" timeline showing who acted on
 * each stage and WHEN it was approved/rejected. Driven entirely by
 * LEAVE_APPROVAL_STAGES (db_connect.php) so it stays in sync with the workflow.
 *
 * Used by both the admin leaves page (leaves.php) and the employee portal
 * (employee-portal.php).
 *
 * Expected keys on $row (per stage KEY in LEAVE_APPROVAL_STAGES):
 *     {KEY}_status, {KEY}_at, {KEY}_remarks, {KEY}_name (approver full name)
 * plus `created_at` / `date_applied` for the "Filed" node.
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
        .lvtl{list-style:none;margin:0;padding:6px 2px 2px;position:relative;}
        .lvtl li{position:relative;padding:0 0 14px 26px;font-size:12px;line-height:1.35;}
        .lvtl li:last-child{padding-bottom:2px;}
        .lvtl li::before{content:'';position:absolute;left:7px;top:16px;bottom:0;width:2px;background:#e3e8ef;}
        .lvtl li:last-child::before{display:none;}
        .lvtl .lvtl-dot{position:absolute;left:0;top:1px;width:16px;height:16px;border-radius:50%;
            display:flex;align-items:center;justify-content:center;font-size:10px;color:#fff;background:#c2c9d6;}
        .lvtl .lvtl-dot.ok{background:#176358;}
        .lvtl .lvtl-dot.no{background:#dc3545;}
        .lvtl .lvtl-dot.wait{background:#fd7e14;}
        .lvtl .lvtl-dot.filed{background:#0d6efd;}
        .lvtl-stage{font-weight:700;color:#333;}
        .lvtl-meta{color:#8a94a6;font-size:11px;}
        .lvtl-name{color:#176358;font-weight:600;}
        .lvtl-rej{color:#dc3545;font-weight:600;}
        .lvtl-remark{color:#dc3545;font-size:11px;margin-top:1px;}
        .lvtl-badge{display:inline-block;font-size:10px;font-weight:700;border-radius:8px;padding:1px 7px;margin-left:4px;
            background:#fff3e0;color:#fd7e14;border:1px solid #ffe0b2;}
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
        $h .= '<li><span class="lvtl-dot filed"><i class="ri-flag-line"></i></span>'
            . '<span class="lvtl-stage">Filed</span>'
            . ($filed_at ? '<div class="lvtl-meta">' . $esc($fmt($filed_at)) . '</div>' : '')
            . '</li>';

        $current = leave_current_stage($row); // stage awaiting action right now

        foreach (leave_stages() as $key => $s) {
            $status = (int) ($row[$key . '_status'] ?? 0);
            $name   = trim((string) ($row[$key . '_name'] ?? ''));
            $at     = $row[$key . '_at'] ?? null;
            $remark = trim((string) ($row[$key . '_remarks'] ?? ''));
            $label  = $esc($s['label']);

            if ($status === 1) {          // approved
                $dot  = '<span class="lvtl-dot ok"><i class="ri-check-line"></i></span>';
                $line = '<span class="lvtl-stage">' . $label . '</span> '
                      . '<span class="lvtl-name">Approved' . ($name ? ' · ' . $esc($name) : '') . '</span>'
                      . ($at ? '<div class="lvtl-meta">' . $esc($fmt($at)) . '</div>' : '');
            } elseif ($status === 2) {    // rejected
                $dot  = '<span class="lvtl-dot no"><i class="ri-close-line"></i></span>';
                $line = '<span class="lvtl-stage">' . $label . '</span> '
                      . '<span class="lvtl-rej">Rejected' . ($name ? ' · ' . $esc($name) : '') . '</span>'
                      . ($at ? '<div class="lvtl-meta">' . $esc($fmt($at)) . '</div>' : '')
                      . ($remark ? '<div class="lvtl-remark"><i class="ri-information-line"></i> ' . $esc($remark) . '</div>' : '');
            } elseif ($key === $current) { // awaiting this stage now
                $dot  = '<span class="lvtl-dot wait"><i class="ri-time-line"></i></span>';
                $line = '<span class="lvtl-stage">' . $label . '</span>'
                      . '<span class="lvtl-badge">Awaiting</span>';
            } else {                       // not reached yet
                $dot  = '<span class="lvtl-dot"><i class="ri-more-line"></i></span>';
                $line = '<span class="lvtl-stage" style="color:#9aa3b2;">' . $label . '</span>'
                      . '<div class="lvtl-meta">Pending earlier approval</div>';
            }
            $h .= '<li>' . $dot . $line . '</li>';
        }

        $h .= '</ul>';
        return $h;
    }
}
