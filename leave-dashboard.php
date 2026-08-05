<?php
/* ═══════════════════════════════════════════════════════════════════════
   Leave Dashboard — two audiences, one page.

   SUPERVISOR (10) / DEPARTMENT HEAD (8) — their whole app is this plus the
   requests screen (see LEAVE_APPROVER_ALLOWED_PAGES in db_connect.php). They
   are locked to their own department by dept-scope.php and see deliberately
   LESS than HR: name, number, position and leave counts. No salary, no rate,
   no personal details, no link into employee-details.

   HR (9) / ADMIN (1) — unscoped, so they land on an ORG view instead: a
   per-department breakdown they can drill into with ?dept=. Same page, but a
   300-employee flat list would be useless at that level, so the default view
   aggregates and the roster appears only once a department is chosen.

   Whichever audience: every query is department-scoped, either by the session
   (dept-scope.php) or by the chosen ?dept=.
   ═══════════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/dept-scope.php';

$my_role  = (int) ($_SESSION['login_role'] ?? 0);
$my_stage = leave_stage_for_role($my_role);      // stage this user owns, or null
$scope_id = dept_scope_id();                      // >0 = locked to one department
$year     = leave_current_year();

// Unscoped viewers (HR / Admin) may drill into a department; scoped ones are
// pinned to theirs and the ?dept= parameter is ignored outright.
$is_org_view = ($scope_id === 0);
$view_dept   = $is_org_view ? (int) ($_GET['dept'] ?? 0) : $scope_id;

// One scope fragment used by every query below.
$w_emp  = $view_dept > 0 ? " AND employee_id IN (SELECT id FROM employee WHERE department_id = $view_dept)" : '';
$w_dept = $view_dept > 0 ? " AND e.department_id = $view_dept" : '';

$dept_name = 'All Departments';
if ($view_dept > 0) {
    $dr = $conn->query("SELECT name FROM department WHERE id = $view_dept");
    if ($dr && ($d = $dr->fetch_assoc())) $dept_name = $d['name'];
}

/* ── Counters ───────────────────────────────────────────────────────────
   "Awaiting me" reuses the predicate the leaves page uses to decide whether
   the action buttons appear, so the number and the buttons cannot disagree. */
$counts = ['awaiting' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];

if ($my_stage) {
    $p = leave_stage_pending_predicate($my_stage);
    $r = $conn->query("SELECT COUNT(*) c FROM leave_requests WHERE ($p) $w_emp");
    if ($r) $counts['awaiting'] = (int) $r->fetch_assoc()['c'];
}
$r = $conn->query("SELECT status, COUNT(*) c FROM leave_requests
                   WHERE YEAR(date_from) = $year $w_emp GROUP BY status");
if ($r) while ($x = $r->fetch_assoc()) {
    if ((int) $x['status'] === 0) $counts['pending']  = (int) $x['c'];
    if ((int) $x['status'] === 1) $counts['approved'] = (int) $x['c'];
    if ((int) $x['status'] === 2) $counts['rejected'] = (int) $x['c'];
}

/* ── Paid leave types — the credit model matches
      includes/leave_balances_report.php: a missing employee_leave_credits row
      falls back to the type's days_allowed. ── */
$types = [];
$tq = $conn->query("SELECT id, name, days_allowed FROM leave_types
                    WHERE is_paid = 1 AND status = 1 ORDER BY name");
if ($tq) while ($t = $tq->fetch_assoc()) $types[(int) $t['id']] = $t;
$default_credits = array_sum(array_column($types, 'days_allowed'));

$nice = function ($v) { return rtrim(rtrim(number_format((float) $v, 1), '0'), '.'); };


/* ── ORG VIEW: per-department breakdown (HR / Admin, no department chosen) ── */
$dept_rows = [];
if ($is_org_view && $view_dept === 0) {
    $dq = $conn->query("
        SELECT d.id, d.name, COUNT(e.id) AS emps
        FROM department d
        LEFT JOIN employee e ON e.department_id = d.id AND e.status = 1
        GROUP BY d.id, d.name
        HAVING emps > 0
        ORDER BY d.name
    ");
    if ($dq) while ($d = $dq->fetch_assoc()) {
        $dept_rows[(int) $d['id']] = [
            'id' => (int) $d['id'], 'name' => $d['name'], 'emps' => (int) $d['emps'],
            'pending' => 0, 'approved' => 0, 'days' => 0.0,
        ];
    }
    $sq = $conn->query("
        SELECT e.department_id d, lr.status, COUNT(*) c, SUM(lr.duration) days
        FROM leave_requests lr
        INNER JOIN employee e ON e.id = lr.employee_id
        WHERE YEAR(lr.date_from) = $year AND lr.status IN (0,1)
        GROUP BY e.department_id, lr.status
    ");
    if ($sq) while ($s = $sq->fetch_assoc()) {
        $d = (int) $s['d'];
        if (!isset($dept_rows[$d])) continue;
        $dept_rows[$d][(int) $s['status'] === 1 ? 'approved' : 'pending'] = (int) $s['c'];
        if ((int) $s['status'] === 1) $dept_rows[$d]['days'] += (float) $s['days'];
    }

    // Which departments have nobody to approve at each mandatory stage — the
    // requests filed there will sit unactioned, so surface it here rather than
    // letting HR discover it from a complaint.
    foreach ($dept_rows as $id => $row) {
        $missing = [];
        foreach (leave_stages() as $k => $s) {
            if (!empty($s['optional'])) continue;      // optional stages auto-skip
            $role = (int) $s['role'];
            $h = $conn->query("SELECT 1 FROM users WHERE role = $role AND status = 1
                               AND (department_id = $id OR department_id IS NULL OR department_id = 0) LIMIT 1");
            if (!$h || !$h->num_rows) $missing[] = $s['label'];
        }
        $dept_rows[$id]['missing'] = $missing;
    }
}

/* ── ROSTER: employees + their leave counts (scoped viewer, or a drill-down) ── */
$emps = [];
if (!$is_org_view || $view_dept > 0) {
    $eq = $conn->query("
        SELECT e.id, e.employee_no, e.lastname, e.firstname, e.leave_override,
               p.name AS position, cl.clasification AS classification
        FROM employee e
        LEFT JOIN position p ON p.id = e.position_id
        LEFT JOIN clasification cl ON cl.id = e.clasification_id
        WHERE e.status = 1 $w_dept
        ORDER BY e.lastname, e.firstname
    ");
    if ($eq) while ($e = $eq->fetch_assoc()) {
        $e['eligible'] = leave_eligibility_from($e['classification'], $e['leave_override']);
        $e['credits']  = $e['eligible'] ? (float) $default_credits : 0.0;
        $e['used']     = $e['pending'] = 0.0;
        $emps[(int) $e['id']] = $e;
    }

    if ($emps) {
        $ids = implode(',', array_map('intval', array_keys($emps)));

        // Explicit per-employee credit lines override the days_allowed default.
        $cq = $conn->query("SELECT employee_id, leave_type_id, credits
                            FROM employee_leave_credits
                            WHERE year = $year AND employee_id IN ($ids)");
        if ($cq) while ($c = $cq->fetch_assoc()) {
            $eid = (int) $c['employee_id'];
            $tid = (int) $c['leave_type_id'];
            if (!isset($emps[$eid]) || !$emps[$eid]['eligible'] || !isset($types[$tid])) continue;
            $emps[$eid]['credits'] += (float) $c['credits'] - (float) $types[$tid]['days_allowed'];
        }

        $uq = $conn->query("SELECT employee_id, status, SUM(duration) d
                            FROM leave_requests
                            WHERE YEAR(date_from) = $year AND status IN (0,1)
                              AND employee_id IN ($ids)
                            GROUP BY employee_id, status");
        if ($uq) while ($u = $uq->fetch_assoc()) {
            $eid = (int) $u['employee_id'];
            if (!isset($emps[$eid])) continue;
            $emps[$eid][(int) $u['status'] === 1 ? 'used' : 'pending'] += (float) $u['d'];
        }
    }
}
/* ── Chart data ─────────────────────────────────────────────────────────
   Every series is scoped by $w_emp, so a Supervisor's charts only ever plot
   their own department.

   NOTE: a request is attributed to the month of its date_from. A leave that
   straddles a month boundary therefore counts wholly in the month it started.
   Splitting it per-day would mean walking the `dates` JSON for every row —
   not worth it for a trend line, but it is why these totals can differ
   slightly from a strict per-day count. */
$months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$trend  = ['approved' => array_fill(0, 12, 0.0), 'pending' => array_fill(0, 12, 0.0)];
$mq = $conn->query("SELECT MONTH(date_from) m, status, SUM(duration) d
                    FROM leave_requests
                    WHERE YEAR(date_from) = $year AND status IN (0,1) $w_emp
                    GROUP BY MONTH(date_from), status");
if ($mq) while ($x = $mq->fetch_assoc()) {
    $i = (int) $x['m'] - 1;
    if ($i < 0 || $i > 11) continue;
    $trend[(int) $x['status'] === 1 ? 'approved' : 'pending'][$i] += (float) $x['d'];
}

// Days taken per leave type (approved only — this is "what leave is actually
// being used for", so pending guesses would blur it).
$by_type = [];
$tq2 = $conn->query("SELECT lt.name, SUM(lr.duration) d
                     FROM leave_requests lr
                     INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
                     WHERE YEAR(lr.date_from) = $year AND lr.status = 1 $w_emp
                     GROUP BY lt.id, lt.name
                     HAVING d > 0 ORDER BY d DESC");
if ($tq2) while ($x = $tq2->fetch_assoc()) $by_type[$x['name']] = (float) $x['d'];

// Org view: the ten departments taking the most leave.
$top_depts = [];
if ($is_org_view && $view_dept === 0) {
    $sorted = $dept_rows;
    usort($sorted, function ($a, $b) { return $b['days'] <=> $a['days']; });
    foreach (array_slice($sorted, 0, 10) as $d) {
        if ($d['days'] > 0) $top_depts[$d['name']] = $d['days'];
    }
}

/* ── Insights — the numbers worth calling out in words ── */
$busiest_i   = 0;
$month_tot   = [];
for ($i = 0; $i < 12; $i++) $month_tot[$i] = $trend['approved'][$i] + $trend['pending'][$i];
$busiest_i   = array_search(max($month_tot), $month_tot, true);
$busiest     = max($month_tot) > 0 ? $months[$busiest_i] : null;
$top_type    = $by_type ? array_key_first($by_type) : null;

$no_leave = $over_credit = $eligible_n = 0;
foreach ($emps as $e) {
    if (!$e['eligible']) continue;
    $eligible_n++;
    if ($e['used'] + $e['pending'] <= 0) $no_leave++;
    if ($e['credits'] - $e['used'] - $e['pending'] < 0) $over_credit++;
}

/* ── Upcoming holidays ──────────────────────────────────────────────────
   Org-wide (holidays are not departmental), so this is the one block on the
   page that is deliberately NOT scoped. `blocks_leave` marks the days on
   which leave cannot be filed at all — the reason an approver needs to see
   this list beside the requests. */
$holidays = [];
$hq = $conn->query("SELECT title, start_date, end_date, type, blocks_leave, note
                    FROM calendar_events
                    WHERE COALESCE(end_date, start_date) >= CURDATE()
                    ORDER BY start_date LIMIT 8");
if ($hq) while ($h = $hq->fetch_assoc()) $holidays[] = $h;
?>
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0">
                            Leave Dashboard
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-2">
                                <i class="ri-building-line me-1"></i><?= htmlspecialchars($dept_name) ?>
                            </span>
                        </h4>
                        <div class="page-title-right d-flex align-items-center gap-2">
                            <?php if ($is_org_view && $view_dept > 0): ?>
                                <a href="leave-dashboard" class="btn btn-sm btn-outline-secondary">
                                    <i class="ri-arrow-left-line me-1"></i>All departments
                                </a>
                            <?php endif; ?>
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Leave Management</a></li>
                                <li class="breadcrumb-item active">Dashboard</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Counters ── -->
            <div class="row">
                <?php
                $cards = [
                    ['Awaiting my approval', $counts['awaiting'], 'ri-inbox-unarchive-line', 'primary',
                     $my_stage ? leave_stages()[$my_stage]['label'] . ' stage' : 'Your role approves no stage'],
                    ['Pending',  $counts['pending'],  'ri-time-line',            'warning', 'Anywhere in the chain'],
                    ['Approved', $counts['approved'], 'ri-checkbox-circle-line', 'success', 'Fully approved this year'],
                    [$is_org_view && $view_dept === 0 ? 'Departments' : 'Employees',
                     $is_org_view && $view_dept === 0 ? count($dept_rows) : count($emps),
                     $is_org_view && $view_dept === 0 ? 'ri-building-line' : 'ri-team-line', 'info',
                     $is_org_view && $view_dept === 0 ? 'With active employees' : 'Active in this department'],
                ];
                foreach ($cards as [$label, $val, $icon, $tone, $sub]): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="card card-animate">
                        <div class="card-body d-flex align-items-center">
                            <div class="rounded bg-<?= $tone ?>-subtle d-flex align-items-center justify-content-center me-3"
                                 style="width:48px;height:48px;">
                                <i class="<?= $icon ?> fs-22 text-<?= $tone ?>"></i>
                            </div>
                            <div>
                                <p class="text-muted mb-1"><?= htmlspecialchars($label) ?></p>
                                <h4 class="mb-0"><?= (int) $val ?></h4>
                                <small class="text-muted" style="font-size:11px;"><?= htmlspecialchars($sub) ?></small>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($counts['awaiting'] > 0 && $my_stage): ?>
            <div class="row">
                <div class="col-12">
                    <div class="alert alert-primary d-flex align-items-center" role="alert">
                        <i class="ri-notification-3-line fs-20 me-2"></i>
                        <div class="flex-grow-1">
                            <b><?= (int) $counts['awaiting'] ?></b> leave request<?= $counts['awaiting'] === 1 ? '' : 's' ?>
                            waiting on your <?= htmlspecialchars(leave_stages()[$my_stage]['label']) ?> approval.
                        </div>
                        <a href="leaves?lstatus=pending" class="btn btn-sm btn-primary">
                            <i class="ri-arrow-right-line me-1"></i>Review now
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══ Charts + holidays ══
                 Palette: categorical slots 1 (blue) and 2 (orange) from the
                 design system, validated against this card's white surface —
                 all six checks pass, contrast ≥ 3:1, CVD ΔE 24.7. Do not
                 recolour these by hand. -->
            <!-- Masonry container: the cards below have very different natural
                 heights (charts vs. sentence lists vs. the holiday feed), so a
                 plain row leaves large gaps. Masonry packs them; the hidden
                 1-col sizer makes the packing grid 1/12 wide so the mixed
                 8/7/5/4 column spans place correctly. -->
            <div class="row" id="lv-masonry">
                <div class="lv-msnry-sizer col-xl-1 p-0 m-0" style="height:0;"></div>
                <div class="col-xl-8 lv-card-col">
                    <div class="card">
                        <div class="card-header d-flex align-items-center">
                            <h4 class="card-title mb-0 flex-grow-1">
                                <i class="ri-line-chart-line me-2 text-primary"></i>Leave Days by Month
                            </h4>
                            <span class="text-muted" style="font-size:12px;"><?= (int) $year ?></span>
                        </div>
                        <div class="card-body">
                            <div id="lv-chart-trend" style="min-height:290px;"></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 lv-card-col">
                    <div class="card">
                        <div class="card-header">
                            <h4 class="card-title mb-0">
                                <i class="ri-lightbulb-line me-2 text-primary"></i>Insights
                            </h4>
                        </div>
                        <div class="card-body">
                            <?php
                            // Plain sentences, not more tiles — these answer "so what?"
                            // and each names the number it is built from.
                            $insights = [];
                            if ($busiest !== null) {
                                $insights[] = ['ri-calendar-event-line', 'primary',
                                    'Busiest month', $busiest . ' — ' . $nice(max($month_tot)) . ' day(s) filed'];
                            }
                            if ($top_type !== null) {
                                $insights[] = ['ri-bookmark-line', 'info',
                                    'Most used leave type', $top_type . ' — ' . $nice(reset($by_type)) . ' day(s)'];
                            }
                            if ($eligible_n > 0) {
                                $insights[] = ['ri-user-follow-line', 'success',
                                    'Took no leave yet', $no_leave . ' of ' . $eligible_n . ' entitled employee(s)'];
                                if ($over_credit > 0) {
                                    $insights[] = ['ri-error-warning-line', 'danger',
                                        'Over their credits', $over_credit . ' employee(s) — approving more adds unpaid days'];
                                }
                            }
                            if ($counts['rejected'] > 0) {
                                $insights[] = ['ri-close-circle-line', 'secondary',
                                    'Rejected this year', $counts['rejected'] . ' request(s)'];
                            }
                            if (!$insights) $insights[] = ['ri-inbox-line', 'secondary', 'No leave activity', 'Nothing filed for ' . $year . ' yet'];
                            foreach ($insights as [$ic, $tone, $ttl, $sub]): ?>
                            <div class="d-flex align-items-start mb-3">
                                <div class="rounded bg-<?= $tone ?>-subtle d-flex align-items-center justify-content-center me-2 flex-shrink-0"
                                     style="width:34px;height:34px;">
                                    <i class="<?= $ic ?> text-<?= $tone ?>"></i>
                                </div>
                                <div>
                                    <div style="font-size:12.5px;font-weight:600;"><?= htmlspecialchars($ttl) ?></div>
                                    <div class="text-muted" style="font-size:11.5px;"><?= htmlspecialchars($sub) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="col-xl-<?= ($is_org_view && $view_dept === 0) ? '4' : '7' ?> lv-card-col">
                    <div class="card">
                        <div class="card-header">
                            <h4 class="card-title mb-0">
                                <i class="ri-bar-chart-horizontal-line me-2 text-primary"></i>Days by Leave Type
                            </h4>
                        </div>
                        <div class="card-body">
                            <?php if ($by_type): ?>
                                <div id="lv-chart-type" style="min-height:260px;"></div>
                            <?php else: ?>
                                <div class="text-center text-muted py-5" style="font-size:12.5px;">
                                    <i class="ri-inbox-line d-block mb-2" style="font-size:26px;"></i>
                                    No approved leave yet for <?= (int) $year ?>.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($is_org_view && $view_dept === 0): ?>
                <div class="col-xl-4 lv-card-col">
                    <div class="card">
                        <div class="card-header">
                            <h4 class="card-title mb-0">
                                <i class="ri-building-line me-2 text-primary"></i>Top Departments
                            </h4>
                        </div>
                        <div class="card-body">
                            <?php if ($top_depts): ?>
                                <div id="lv-chart-dept" style="min-height:260px;"></div>
                            <?php else: ?>
                                <div class="text-center text-muted py-5" style="font-size:12.5px;">No approved leave yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="col-xl-<?= ($is_org_view && $view_dept === 0) ? '4' : '5' ?> lv-card-col">
                    <div class="card">
                        <div class="card-header">
                            <h4 class="card-title mb-0">
                                <i class="ri-calendar-2-line me-2 text-primary"></i>Upcoming Holidays
                            </h4>
                        </div>
                        <div class="card-body" style="max-height:300px;overflow-y:auto;">
                            <?php if (!$holidays): ?>
                                <div class="text-center text-muted py-5" style="font-size:12.5px;">
                                    <i class="ri-calendar-line d-block mb-2" style="font-size:26px;"></i>
                                    No upcoming holidays on the calendar.
                                </div>
                            <?php else: foreach ($holidays as $h):
                                $span = date('M j, Y', strtotime($h['start_date']));
                                if (!empty($h['end_date']) && $h['end_date'] !== $h['start_date']) {
                                    $span .= ' – ' . date('M j, Y', strtotime($h['end_date']));
                                }
                                $days_off = (int) ceil((strtotime($h['start_date']) - strtotime(date('Y-m-d'))) / 86400);
                                // type 1 = legal, 3 = special (matches dtr_compute_day)
                                $tone = ((int) $h['type'] === 1) ? 'danger' : 'warning';
                            ?>
                            <div class="d-flex align-items-start mb-3">
                                <div class="rounded bg-<?= $tone ?>-subtle text-<?= $tone ?> d-flex flex-column align-items-center justify-content-center me-2 flex-shrink-0"
                                     style="width:44px;height:44px;line-height:1;">
                                    <span style="font-size:9px;font-weight:700;text-transform:uppercase;"><?= date('M', strtotime($h['start_date'])) ?></span>
                                    <span style="font-size:15px;font-weight:700;"><?= date('j', strtotime($h['start_date'])) ?></span>
                                </div>
                                <div class="flex-grow-1" style="min-width:0;">
                                    <div style="font-size:12.5px;font-weight:600;"><?= htmlspecialchars($h['title']) ?></div>
                                    <div class="text-muted" style="font-size:11px;">
                                        <?= htmlspecialchars($span) ?>
                                        <?= $days_off === 0 ? ' · today' : ($days_off > 0 ? ' · in ' . $days_off . ' day(s)' : '') ?>
                                    </div>
                                    <div class="mt-1">
                                        <span class="badge bg-<?= $tone ?>-subtle text-<?= $tone ?> border border-<?= $tone ?>-subtle" style="font-size:9.5px;">
                                            <?= ((int) $h['type'] === 1) ? 'Legal holiday' : 'Special holiday' ?>
                                        </span>
                                        <?php if (!empty($h['blocks_leave'])): ?>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" style="font-size:9.5px;"
                                              title="Leave cannot be filed on this date">
                                            <i class="ri-forbid-line me-1"></i>No leave
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($is_org_view && $view_dept === 0): ?>
            <!-- ══ HR / ADMIN: per-department breakdown ══ -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex align-items-center">
                            <h4 class="card-title mb-0 flex-grow-1">
                                <i class="ri-building-line me-2 text-primary"></i>Leave by Department
                            </h4>
                            <span class="text-muted" style="font-size:12px;"><?= (int) $year ?> · click a row to drill in</span>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="lvdash-dept" class="table table-hover table-bordered align-middle">
                                    <thead class="table-dark">
                                        <tr>
                                            <th><i class="ri-building-line me-1"></i>Department</th>
                                            <th class="text-center"><i class="ri-team-line me-1"></i>Employees</th>
                                            <th class="text-center"><i class="ri-time-line me-1"></i>Pending</th>
                                            <th class="text-center"><i class="ri-checkbox-circle-line me-1"></i>Approved</th>
                                            <th class="text-center"><i class="ri-calendar-check-line me-1"></i>Days taken</th>
                                            <th><i class="ri-user-star-line me-1"></i>Approver coverage</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dept_rows as $d): ?>
                                        <tr style="cursor:pointer;" onclick="location.href='leave-dashboard?dept=<?= $d['id'] ?>'">
                                            <td><b><?= htmlspecialchars($d['name']) ?></b></td>
                                            <td class="text-center"><?= $d['emps'] ?></td>
                                            <td class="text-center">
                                                <?= $d['pending'] > 0
                                                    ? '<span class="badge bg-warning-subtle text-warning border border-warning-subtle">' . $d['pending'] . '</span>'
                                                    : '<span class="text-muted">0</span>' ?>
                                            </td>
                                            <td class="text-center"><?= $d['approved'] ?></td>
                                            <td class="text-center"><?= $nice($d['days']) ?></td>
                                            <td>
                                                <?php if (empty($d['missing'])): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                        <i class="ri-check-line me-1"></i>Complete
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle"
                                                          title="Requests filed here cannot be approved until someone holds this role">
                                                        <i class="ri-error-warning-line me-1"></i>No <?= htmlspecialchars(implode(', ', $d['missing'])) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <!-- ══ Department roster ══ -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex align-items-center">
                            <h4 class="card-title mb-0 flex-grow-1">
                                <i class="ri-team-line me-2 text-primary"></i>Department Employees
                            </h4>
                            <span class="text-muted" style="font-size:12px;">Leave counts for <?= (int) $year ?></span>
                        </div>
                        <div class="card-body">
                            <!-- Filter bar. Everything here is client-side over the rows already
                                 rendered — the roster is one department, so there is nothing to
                                 gain from a round trip, and the charts above stay in step with
                                 the unfiltered department by design. -->
                            <div class="row g-2 mb-3" id="lvdash-filters">
                                <div class="col-lg-4 col-md-6">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-light"><i class="ri-search-2-line"></i></span>
                                        <input type="text" id="lvf-q" class="form-control"
                                               placeholder="Search name, number or position…" autocomplete="off">
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <select id="lvf-pos" class="form-select form-select-sm">
                                        <option value="">Position: All</option>
                                        <?php
                                        $pos_opts = [];
                                        foreach ($emps as $e) {
                                            $pn = trim((string) ($e['position'] ?? ''));
                                            if ($pn !== '') $pos_opts[$pn] = true;
                                        }
                                        ksort($pos_opts);
                                        foreach (array_keys($pos_opts) as $pn):
                                        ?>
                                        <option value="<?= htmlspecialchars($pn) ?>"><?= htmlspecialchars($pn) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <select id="lvf-state" class="form-select form-select-sm">
                                        <option value="">Leave status: All</option>
                                        <option value="pending">Has pending request</option>
                                        <option value="used">Has taken leave</option>
                                        <option value="none">Took no leave yet</option>
                                        <option value="over">Over their credits</option>
                                        <option value="ineligible">Not entitled to leave</option>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-6 d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary flex-grow-1" id="lvf-reset">
                                        <i class="ri-restart-line me-1"></i>Reset
                                    </button>
                                </div>
                                <div class="col-12">
                                    <small class="text-muted" id="lvf-count" style="font-size:11.5px;"></small>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table id="lvdash-table" class="table table-hover table-bordered align-middle">
                                    <thead class="table-dark">
                                        <tr>
                                            <th><i class="ri-user-line me-1"></i>Employee</th>
                                            <th><i class="ri-briefcase-line me-1"></i>Position</th>
                                            <th class="text-center"><i class="ri-coins-line me-1"></i>Credits</th>
                                            <th class="text-center"><i class="ri-check-line me-1"></i>Used</th>
                                            <th class="text-center"><i class="ri-time-line me-1"></i>Pending</th>
                                            <th class="text-center"><i class="ri-wallet-3-line me-1"></i>Remaining</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($emps as $e):
                                            $remaining = $e['credits'] - $e['used'] - $e['pending'];
                                        ?>
                                        <!-- Filter reads these, never the rendered cell text: the
                                             numbers are formatted for humans and an ineligible row
                                             collapses its four value cells into one. -->
                                        <tr data-pos="<?= htmlspecialchars($e['position'] ?? '') ?>"
                                            data-eligible="<?= $e['eligible'] ? 1 : 0 ?>"
                                            data-used="<?= (float) $e['used'] ?>"
                                            data-pending="<?= (float) $e['pending'] ?>"
                                            data-remaining="<?= (float) $remaining ?>">
                                            <td>
                                                <b><?= htmlspecialchars($e['lastname'] . ', ' . $e['firstname']) ?></b>
                                                <div class="text-muted" style="font-size:11px;">
                                                    <i class="ri-hashtag"></i><?= htmlspecialchars($e['employee_no']) ?>
                                                </div>
                                            </td>
                                            <td><span class="text-muted"><?= htmlspecialchars($e['position'] ?? '—') ?></span></td>
                                            <?php if (!$e['eligible']): ?>
                                            <td colspan="4" class="text-center">
                                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                                    <i class="ri-forbid-line me-1"></i>Not entitled to leave
                                                </span>
                                            </td>
                                            <?php else: ?>
                                            <td class="text-center"><?= $nice($e['credits']) ?></td>
                                            <td class="text-center"><?= $nice($e['used']) ?></td>
                                            <td class="text-center">
                                                <?= $e['pending'] > 0
                                                    ? '<span class="badge bg-warning-subtle text-warning border border-warning-subtle">' . $nice($e['pending']) . '</span>'
                                                    : '<span class="text-muted">0</span>' ?>
                                            </td>
                                            <td class="text-center">
                                                <b class="<?= $remaining <= 0 ? 'text-danger' : 'text-success' ?>">
                                                    <?= $nice($remaining) ?>
                                                </b>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<script src="<?= av('assets/libs/apexcharts/apexcharts.min.js') ?>"></script>
<script src="<?= av('assets/libs/masonry-layout/masonry.pkgd.min.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    /* ── Masonry over the mixed-height cards ─────────────────────────────
       Init manually (not data-masonry) because the charts render async and
       change card heights AFTER first layout — every chart render below
       calls lvRelayout(). columnWidth is the hidden 1-col sizer so the
       8/7/5/4 spans pack on a 12-column grid; Bootstrap's own column
       padding provides the gutters. */
    var lvMsnry = null;
    (function () {
        var el = document.getElementById('lv-masonry');
        if (!el || typeof Masonry === 'undefined') return;
        lvMsnry = new Masonry(el, {
            itemSelector: '.lv-card-col',
            columnWidth: '.lv-msnry-sizer',
            percentPosition: true,
            transitionDuration: '0.2s'
        });
        // Fonts / scrollbars can nudge heights after first paint.
        window.addEventListener('load', function () { lvMsnry.layout(); });
    })();
    function lvRelayout() { if (lvMsnry) lvMsnry.layout(); }
    // jQuery and DataTables load near the END of index.php, after this block.
    // DOMContentLoaded normally covers that, but poll briefly rather than
    // assume — if they are not ready we silently lose paging AND the filters.
    (function whenReady(tries) {
        if (!window.jQuery || !jQuery.fn || !jQuery.fn.DataTable) {
            if (tries > 0) return setTimeout(function () { whenReady(tries - 1); }, 100);
            return;                                   // give up after ~3s
        }
        initTables();
    })(30);

    function initTables() {
        // Bootstrap-5 layout WITHOUT the built-in search box (the roster has its
        // own filter bar). Keep the row/col wrappers — DataTables' Bootstrap
        // integration styles length + info + pagination through them, and a bare
        // dom string like 'rtip' loses the paging controls entirely.
        var DOM_NO_SEARCH =
            "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'>>" +
            "<'row'<'col-sm-12'tr>>" +
            "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>";

        var rosterDt = null;
        ['#lvdash-dept', '#lvdash-table'].forEach(function (sel) {
            if (!document.querySelector(sel)) return;
            var isRoster = (sel === '#lvdash-table');
            // Reuse an existing instance instead of bailing out — bailing left
            // rosterDt null and the filter bar inert with no visible error.
            var opts = {
                order: [[0, 'asc']],
                paging: true,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                language: { search: '', searchPlaceholder: 'Search…' }
            };
            // Only the roster drops the search box. Never set dom:undefined —
            // that overwrites the Bootstrap integration's default instead of
            // leaving it alone.
            if (isRoster) opts.dom = DOM_NO_SEARCH;
            var dt = jQuery.fn.DataTable.isDataTable(sel)
                ? jQuery(sel).DataTable()
                : jQuery(sel).DataTable(opts);
            if (isRoster) rosterDt = dt;
        });

        // ── Roster filter bar ────────────────────────────────────────────
        if (rosterDt) {
            var qEl     = document.getElementById('lvf-q'),
                posEl   = document.getElementById('lvf-pos'),
                stateEl = document.getElementById('lvf-state'),
                cntEl   = document.getElementById('lvf-count');

            // Position + leave-state are read off each row's data-* attributes:
            // the cells are formatted for humans (and an ineligible row merges
            // four of them), so parsing text would be wrong.
            jQuery.fn.dataTable.ext.search.push(function (settings, data, idx, rowData, counter) {
                if (settings.nTable.id !== 'lvdash-table') return true;   // don't touch the dept table
                var tr    = settings.aoData[idx].nTr,
                    pos   = posEl ? posEl.value : '',
                    state = stateEl ? stateEl.value : '';
                if (pos && (tr.getAttribute('data-pos') || '') !== pos) return false;
                if (!state) return true;

                var eligible = tr.getAttribute('data-eligible') === '1',
                    used     = parseFloat(tr.getAttribute('data-used')) || 0,
                    pending  = parseFloat(tr.getAttribute('data-pending')) || 0,
                    remain   = parseFloat(tr.getAttribute('data-remaining')) || 0;

                switch (state) {
                    case 'pending':    return eligible && pending > 0;
                    case 'used':       return eligible && used > 0;
                    case 'none':       return eligible && used <= 0 && pending <= 0;
                    case 'over':       return eligible && remain < 0;
                    case 'ineligible': return !eligible;
                }
                return true;
            });

            var showCount = function () {
                if (!cntEl) return;
                var info = rosterDt.page.info();
                cntEl.textContent = info.recordsDisplay === info.recordsTotal
                    ? info.recordsTotal + ' employee(s)'
                    : 'Showing ' + info.recordsDisplay + ' of ' + info.recordsTotal + ' employee(s)';
            };
            var apply = function () { rosterDt.draw(); showCount(); };

            if (qEl) {
                var t = null;
                qEl.addEventListener('input', function () {
                    clearTimeout(t);                       // debounce so long lists stay responsive
                    t = setTimeout(function () { rosterDt.search(qEl.value); apply(); }, 180);
                });
            }
            if (posEl)   posEl.addEventListener('change', apply);
            if (stateEl) stateEl.addEventListener('change', apply);

            var resetEl = document.getElementById('lvf-reset');
            if (resetEl) resetEl.addEventListener('click', function () {
                if (qEl) qEl.value = '';
                if (posEl) posEl.value = '';
                if (stateEl) stateEl.value = '';
                rosterDt.search('');
                apply();
            });
            showCount();
        }
    }
    if (typeof ApexCharts === 'undefined') return;

    /* Design-system tokens. Slots 1 and 2 validated against this card's white
       surface: contrast 4.35 / 3.36, CVD ΔE 24.7, normal-vision ΔE 33.6.
       Ink and grid stay in text tokens — never the series color. */
    var SERIES_1 = '#2a78d6',        // blue  — approved
        SERIES_2 = '#eb6834',        // orange — pending
        INK_MUTED = '#898781',
        GRID = '#e1e0d9';

    var axisStyle = { colors: INK_MUTED, fontSize: '11px', fontFamily: 'inherit' };
    var baseOpts = {
        chart: { fontFamily: 'inherit', toolbar: { show: false }, animations: { speed: 400 } },
        grid: { borderColor: GRID, strokeDashArray: 3, padding: { left: 6, right: 6 } },
        dataLabels: { enabled: false },
        tooltip: { theme: 'light', style: { fontFamily: 'inherit' } }
    };

    // ── Leave days by month — two series, so a legend is mandatory ──
    var trendEl = document.getElementById('lv-chart-trend');
    if (trendEl) {
        new ApexCharts(trendEl, Object.assign({}, baseOpts, {
            chart: Object.assign({}, baseOpts.chart, { type: 'area', height: 290 }),
            series: [
                { name: 'Approved', data: <?= json_encode(array_map(function ($v) { return round($v, 1); }, $trend['approved'])) ?> },
                { name: 'Pending',  data: <?= json_encode(array_map(function ($v) { return round($v, 1); }, $trend['pending'])) ?> }
            ],
            colors: [SERIES_1, SERIES_2],
            stroke: { curve: 'smooth', width: 2 },          // 2px lines per spec
            fill: { type: 'gradient', gradient: { opacityFrom: 0.28, opacityTo: 0.02 } },
            markers: { size: 0, hover: { size: 6 } },
            xaxis: {
                categories: <?= json_encode($months) ?>,
                labels: { style: axisStyle },
                axisBorder: { color: GRID }, axisTicks: { color: GRID }
            },
            yaxis: { labels: { style: axisStyle }, title: { text: 'Days', style: axisStyle } },
            legend: { position: 'top', horizontalAlign: 'right', fontSize: '12px', markers: { width: 9, height: 9, radius: 3 }, labels: { colors: INK_MUTED } },
            tooltip: Object.assign({}, baseOpts.tooltip, { shared: true, intersect: false, x: { show: true } })
        })).render().then(lvRelayout);
    }

    // ── Days by leave type — single series, so no legend; bars carry
    //    direct value labels (also the relief for any low-contrast fill) ──
    var typeEl = document.getElementById('lv-chart-type');
    if (typeEl) {
        new ApexCharts(typeEl, Object.assign({}, baseOpts, {
            chart: Object.assign({}, baseOpts.chart, { type: 'bar', height: 260 }),
            series: [{ name: 'Days', data: <?= json_encode(array_map(function ($v) { return round($v, 1); }, array_values($by_type))) ?> }],
            colors: [SERIES_1],
            plotOptions: { bar: { horizontal: true, borderRadius: 4, borderRadiusApplication: 'end', barHeight: '58%' } },
            dataLabels: { enabled: true, style: { fontSize: '11px', colors: ['#0b0b0b'], fontFamily: 'inherit' }, offsetX: 22 },
            xaxis: {
                categories: <?= json_encode(array_keys($by_type)) ?>,
                labels: { style: axisStyle }, axisBorder: { color: GRID }, axisTicks: { color: GRID }
            },
            yaxis: { labels: { style: axisStyle } }
        })).render().then(lvRelayout);
    }

    // ── Top departments (org view only) ──
    var deptEl = document.getElementById('lv-chart-dept');
    if (deptEl) {
        new ApexCharts(deptEl, Object.assign({}, baseOpts, {
            chart: Object.assign({}, baseOpts.chart, { type: 'bar', height: 260 }),
            series: [{ name: 'Days', data: <?= json_encode(array_map(function ($v) { return round($v, 1); }, array_values($top_depts))) ?> }],
            colors: [SERIES_1],
            plotOptions: { bar: { horizontal: true, borderRadius: 4, borderRadiusApplication: 'end', barHeight: '58%' } },
            dataLabels: { enabled: true, style: { fontSize: '11px', colors: ['#0b0b0b'], fontFamily: 'inherit' }, offsetX: 22 },
            xaxis: {
                categories: <?= json_encode(array_map(function ($n) { return strlen($n) > 22 ? substr($n, 0, 21) . '…' : $n; }, array_keys($top_depts))) ?>,
                labels: { style: axisStyle }, axisBorder: { color: GRID }, axisTicks: { color: GRID }
            },
            yaxis: { labels: { style: axisStyle, maxWidth: 150 } }
        })).render().then(lvRelayout);
    }
});
</script>
