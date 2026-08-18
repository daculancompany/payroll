<?php
$settings = [];
$sq = $conn->query("SELECT setting_key, setting_value, description FROM pay_settings");
if ($sq) while ($r = $sq->fetch_assoc()) $settings[$r['setting_key']] = $r;

function ps($key, $settings) {
    return isset($settings[$key]) ? (float)$settings[$key]['setting_value'] : 0;
}
// Payroll period is stored as a numeric code (float column): 1=semi_monthly, 2=weekly, 3=monthly.
$period_codes   = [1 => 'semi_monthly', 2 => 'weekly', 3 => 'monthly'];
$pp_code        = (int) ps('payroll_period', $settings);
$payroll_period = isset($period_codes[$pp_code]) ? $period_codes[$pp_code] : 'semi_monthly';
?>
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0">Pay Rate Settings</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Settings</a></li>
                                <li class="breadcrumb-item active">Pay Rate Settings</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4 class="card-title mb-0">
                                <i class="ri-money-dollar-circle-line me-2 text-success"></i>
                                Government-Mandated Pay Rates (DOLE)
                            </h4>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info d-flex align-items-center gap-2 py-2">
                                <i class="ri-information-line fs-18"></i>
                                <span>These rates follow DOLE minimum standards. You may increase above the minimum but not below. Changes apply to all future payroll calculations.</span>
                            </div>

                            <form id="form-pay-settings">
                                <div class="row g-4">

                                    <!-- Payroll Period -->
                                    <div class="col-12">
                                        <h6 class="fw-bold text-uppercase text-muted mb-3" style="font-size:11px;letter-spacing:1px;">
                                            <i class="ri-calendar-2-line me-1"></i>Payroll Cutoff Period
                                        </h6>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">Period Type</label>
                                                <select class="form-select" name="payroll_period">
                                                    <option value="semi_monthly" <?= $payroll_period==='semi_monthly'?'selected':'' ?>>Semi-monthly (1–15 / 16–end)</option>
                                                    <option value="weekly" <?= $payroll_period==='weekly'?'selected':'' ?>>Weekly (Mon–Sun)</option>
                                                    <option value="monthly" <?= $payroll_period==='monthly'?'selected':'' ?>>Monthly (1–end)</option>
                                                </select>
                                                <small class="text-muted">Controls how biometric attendance is grouped into DTR batches for approval. You can change this anytime.</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12"><hr class="my-1"></div>

                                    <!-- Holiday Rates -->
                                    <div class="col-12">
                                        <h6 class="fw-bold text-uppercase text-muted mb-3" style="font-size:11px;letter-spacing:1px;">
                                            <i class="ri-calendar-event-line me-1"></i>Holiday Pay Rates
                                        </h6>
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold">
                                                    <span class="badge bg-danger me-1">Legal Holiday</span>
                                                    Pay Rate
                                                    <small class="text-muted fw-normal">(DOLE min: 2.00)</small>
                                                </label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" name="legal_holiday_rate"
                                                           value="<?= ps('legal_holiday_rate', $settings) ?>"
                                                           min="2.00" step="0.01" required>
                                                    <span class="input-group-text">× daily rate</span>
                                                </div>
                                                <small class="text-muted">e.g. 2.00 = 200% of daily rate</small>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold">
                                                    <span class="badge bg-warning text-dark me-1">Special Holiday</span>
                                                    Pay Rate
                                                    <small class="text-muted fw-normal">(DOLE min: 1.30)</small>
                                                </label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" name="special_holiday_rate"
                                                           value="<?= ps('special_holiday_rate', $settings) ?>"
                                                           min="1.30" step="0.01" required>
                                                    <span class="input-group-text">× daily rate</span>
                                                </div>
                                                <small class="text-muted">e.g. 1.30 = 130% of daily rate</small>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold">
                                                    <span class="badge bg-secondary me-1">Rest Day</span>
                                                    Pay Rate
                                                    <small class="text-muted fw-normal">(DOLE min: 1.30)</small>
                                                </label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" name="rest_day_rate"
                                                           value="<?= ps('rest_day_rate', $settings) ?>"
                                                           min="1.30" step="0.01" required>
                                                    <span class="input-group-text">× daily rate</span>
                                                </div>
                                                <small class="text-muted">e.g. 1.30 = 130% of daily rate</small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Rest Day / Day-Off Work Authorization -->
                                    <div class="col-12">
                                        <hr class="my-1">
                                        <h6 class="fw-bold text-uppercase text-muted mb-3" style="font-size:11px;letter-spacing:1px;">
                                            <i class="ri-moon-line me-1"></i>Rest Day / Day-Off Work Authorization
                                        </h6>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" name="rest_day_auto_authorize" id="rest-auto" value="1"
                                                        <?= ps('rest_day_auto_authorize', $settings) >= 1 ? 'checked' : '' ?>>
                                                    <label class="form-check-label fw-semibold" for="rest-auto">Auto-authorize rest-day work</label>
                                                </div>
                                                <small class="text-muted">
                                                    Off (default) = <b>any</b> attendance record on a rest day <b>cannot be approved</b> — single or bulk —
                                                    until an approved <b>Rest Day Work</b> request exists for that employee and date. The employee files it
                                                    from their portal, where the day is flagged with the hours their scans credit.
                                                    Approving that request authorizes the duty only: it never rewrites the DTR figures, so the day stays
                                                    paid once (base pay + 30% rest-day premium, plus any OT the scans show).<br>
                                                    On = rest-day records are approved normally, no filing required.
                                                </small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- OT Rates -->
                                    <div class="col-12">
                                        <hr class="my-1">
                                        <h6 class="fw-bold text-uppercase text-muted mb-3" style="font-size:11px;letter-spacing:1px;">
                                            <i class="ri-timer-flash-line me-1"></i>Overtime Rates
                                        </h6>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">
                                                    OT on Regular Day
                                                    <small class="text-muted fw-normal">(DOLE min: 1.25)</small>
                                                </label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" name="ot_regular_rate"
                                                           value="<?= ps('ot_regular_rate', $settings) ?>"
                                                           min="1.25" step="0.01" required>
                                                    <span class="input-group-text">× hourly rate</span>
                                                </div>
                                                <small class="text-muted">e.g. 1.25 = 125% — DOLE standard OT pay</small>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">
                                                    OT Holiday/Rest Day Multiplier
                                                    <small class="text-muted fw-normal">(DOLE min: 1.30)</small>
                                                </label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" name="ot_holiday_multiplier"
                                                           value="<?= ps('ot_holiday_multiplier', $settings) ?>"
                                                           min="1.30" step="0.01" required>
                                                    <span class="input-group-text">× holiday/rest rate</span>
                                                </div>
                                                <small class="text-muted">
                                                    Applied on top of holiday rate:<br>
                                                    Legal holiday OT = 2.00 × 1.30 = <b>260%</b><br>
                                                    Special holiday OT = 1.30 × 1.30 = <b>169%</b>
                                                </small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- NSD -->
                                    <div class="col-12">
                                        <hr class="my-1">
                                        <h6 class="fw-bold text-uppercase text-muted mb-3" style="font-size:11px;letter-spacing:1px;">
                                            <i class="ri-moon-line me-1"></i>Night Shift Differential (NSD)
                                        </h6>
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold">
                                                    NSD Rate (global default)
                                                    <small class="text-muted fw-normal">(DOLE min: 0.10)</small>
                                                </label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" name="nsd_rate"
                                                           value="<?= ps('nsd_rate', $settings) ?>"
                                                           min="0.10" step="0.01" required>
                                                    <span class="input-group-text">× hourly rate</span>
                                                </div>
                                                <small class="text-muted">10PM–6AM hours only. Per-shift rate in Work Schedules overrides this.</small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- 13th Month Pay -->
                                    <div class="col-12">
                                        <hr class="my-1">
                                        <h6 class="fw-bold text-uppercase text-muted mb-3" style="font-size:11px;letter-spacing:1px;">
                                            <i class="ri-hand-coin-line me-1"></i>13th Month Pay (PD 851)
                                        </h6>
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" name="th13_include_paid_leave" id="th13-leave" value="1"
                                                        <?= ps('th13_include_paid_leave', $settings) >= 1 ? 'checked' : '' ?>>
                                                    <label class="form-check-label fw-semibold" for="th13-leave">Include paid leave days</label>
                                                </div>
                                                <small class="text-muted">Approved paid leave counts as basic salary earned (standard DOLE reading).</small>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" name="th13_include_allowance" id="th13-allow" value="1"
                                                        <?= ps('th13_include_allowance', $settings) >= 1 ? 'checked' : '' ?>>
                                                    <label class="form-check-label fw-semibold" for="th13-allow">Include allowances</label>
                                                </div>
                                                <small class="text-muted">Off = strict basic only (DOLE minimum). On = allowances integrated into the basis.</small>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" name="th13_round_to_peso" id="th13-round" value="1"
                                                        <?= ps('th13_round_to_peso', $settings) >= 1 ? 'checked' : '' ?>>
                                                    <label class="form-check-label fw-semibold" for="th13-round">Round to whole peso</label>
                                                </div>
                                                <small class="text-muted">Off = centavo-exact (basic &divide; 12 as-is).</small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Withholding Tax -->
                                    <div class="col-12">
                                        <hr class="my-1">
                                        <h6 class="fw-bold text-uppercase text-muted mb-3" style="font-size:11px;letter-spacing:1px;">
                                            <i class="ri-government-line me-1"></i>Withholding Tax (BIR / TRAIN)
                                        </h6>

                                        <?php
                                        // Show what turning this on would actually do. An admin deciding
                                        // whether to post computed tax should be able to see the size of the
                                        // change first — the figures are already stored on every payroll row
                                        // (tax_computed vs tax), so this costs one query, not a recalculation.
                                        $tx = $conn->query("
                                            SELECT ROUND(SUM(tax), 2)          AS typed,
                                                   ROUND(SUM(tax_computed), 2) AS computed,
                                                   SUM(tax > 0)                AS n_typed,
                                                   SUM(tax_computed > 0)       AS n_computed
                                              FROM payroll_items");
                                        $tx = $tx ? $tx->fetch_assoc() : null;
                                        $tax_on = ps('tax_auto_post', $settings) >= 1;
                                        ?>
                                        <?php if ($tx && ($tx['computed'] > 0 || $tx['typed'] > 0)): ?>
                                        <div class="alert <?= $tax_on ? 'alert-success' : 'alert-warning' ?> py-2 mb-3" style="font-size:12.5px;">
                                            <div class="d-flex align-items-start gap-2">
                                                <i class="ri-scales-3-line fs-18"></i>
                                                <div>
                                                    <b>Across all payrolls:</b>
                                                    currently withholding <b>&#8369;<?= number_format((float) $tx['typed'], 2) ?></b>
                                                    (<?= (int) $tx['n_typed'] ?> rows);
                                                    the BIR schedule computes <b>&#8369;<?= number_format((float) $tx['computed'], 2) ?></b>
                                                    (<?= (int) $tx['n_computed'] ?> rows).
                                                    <?php if (!$tax_on): ?>
                                                        <br><span class="text-muted">Switch on below only once you can explain that difference — it becomes real money off payslips.</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <div class="row g-3">
                                            <div class="col-md-5">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" name="tax_auto_post" id="tax-auto" value="1"
                                                        <?= $tax_on ? 'checked' : '' ?>>
                                                    <label class="form-check-label fw-semibold" for="tax-auto">Deduct the computed tax</label>
                                                </div>
                                                <small class="text-muted">
                                                    Off = the system computes tax and shows it beside your typed figure, but deducts nothing.<br>
                                                    On = the computed tax comes off net pay. A tax typed by an admin always wins and survives recalculation.
                                                </small>
                                            </div>
                                            <div class="col-md-7">
                                                <label class="form-label fw-semibold">How tax is computed each cutoff</label>
                                                <?php $tm = (int) ps('tax_method', $settings) === 2 ? 2 : 1; ?>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="tax_method" id="tm-1" value="1" <?= $tm === 1 ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="tm-1">
                                                        <b>Per cutoff</b> — each payroll taxed on its own.
                                                        <small class="text-muted d-block">Simple and easy to audit. A period with heavy overtime over-deducts and needs a year-end adjustment.</small>
                                                    </label>
                                                </div>
                                                <div class="form-check mt-2">
                                                    <input class="form-check-input" type="radio" name="tax_method" id="tm-2" value="2" <?= $tm === 2 ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="tm-2">
                                                        <b>Cumulative (annualised)</b> — prices against the year to date.
                                                        <small class="text-muted d-block">Self-correcting, so no December true-up. Can produce a refund line when too much was withheld earlier (RR 11-2018).</small>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        <small class="text-muted d-block mt-2">
                                            Rates come from the BIR TRAIN tables, versioned by effectivity date, so changing them later cannot restate a payroll already computed.
                                            Taxable pay = gross &minus; non-taxable allowances &minus; SSS/PhilHealth/Pag-IBIG employee share.
                                        </small>
                                    </div>

                                    <!-- Pre-lock payroll checks -->
                                    <div class="col-12">
                                        <hr class="my-1">
                                        <h6 class="fw-bold text-uppercase text-muted mb-3" style="font-size:11px;letter-spacing:1px;">
                                            <i class="ri-shield-check-line me-1"></i>Pre-Lock Payroll Checks
                                        </h6>
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold">Net pay swing alert threshold</label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" name="sanity_net_swing_pct"
                                                           value="<?= ps('sanity_net_swing_pct', $settings) ?: 30 ?>"
                                                           min="1" max="500" step="1" required>
                                                    <span class="input-group-text">%</span>
                                                </div>
                                                <small class="text-muted">Before locking, employees whose net pay changed more than this vs. the previous period are flagged for review.</small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Attendance pairing -->
                                    <div class="col-12">
                                        <hr class="my-1">
                                        <h6 class="fw-bold text-uppercase text-muted mb-3" style="font-size:11px;letter-spacing:1px;">
                                            <i class="ri-fingerprint-line me-1"></i>Attendance Scan Pairing
                                        </h6>
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold">Early-arrival grace window</label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" name="dtr_early_grace_hours"
                                                           value="<?= ps('dtr_early_grace_hours', $settings) ?: 4 ?>"
                                                           min="0.5" max="24" step="0.5" required>
                                                    <span class="input-group-text">hrs</span>
                                                </div>
                                                <small class="text-muted">A scan earlier than this before the shift start is discarded as a stray tap, so it cannot be paired as the day&rsquo;s time-in.</small>
                                            </div>
                                            <div class="col-md-8">
                                                <div class="alert alert-warning py-2 px-3 mb-0" style="font-size:12px;">
                                                    <i class="ri-alert-line me-1"></i>
                                                    <b>This does not change anyone&rsquo;s pay.</b> Time before the shift start is never paid, whatever this is set to &mdash; it only decides <i>which</i> scan becomes the time-in.
                                                    <b>Lowering</b> it discards more scans, which can leave a day with no time-out and zero hours.
                                                    Existing records keep their stored figures until a <b>Recompute</b> is run, and that recompute re-pairs them against the new value.
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Late / tardiness rules -->
                                    <?php
                                    // Defaults mirror migrations/2026_08_late_rules.sql; a DB that predates it
                                    // (no rows) shows the pre-migration behaviour: exact minutes, no grace.
                                    $lm  = isset($settings['late_mode']) ? ((int) ps('late_mode', $settings) === 1 ? 1 : 0) : 0;
                                    $lv  = function ($k, $d) use ($settings) { return isset($settings[$k]) ? rtrim(rtrim(number_format(ps($k, $settings), 2), '0'), '.') : $d; };
                                    ?>
                                    <div class="col-12">
                                        <hr class="my-1">
                                        <h6 class="fw-bold text-uppercase text-muted mb-3" style="font-size:11px;letter-spacing:1px;">
                                            <i class="ri-timer-flash-line me-1"></i>Late (Tardiness) Rules
                                        </h6>
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold d-block">How is a late arrival charged?</label>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="late_mode" id="lm-1" value="1" <?= $lm === 1 ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="lm-1"><b>Brackets</b> &mdash; grace, then fixed hours per bracket, then half day</label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="late_mode" id="lm-0" value="0" <?= $lm === 0 ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="lm-0"><b>Exact minutes</b> &mdash; every minute after the grace period is deducted</label>
                                                </div>
                                                <label class="form-label fw-semibold mt-3">Grace period</label>
                                                <div class="input-group" style="max-width:220px;">
                                                    <input type="number" class="form-control" name="late_grace_minutes" id="lr-grace"
                                                           value="<?= $lv('late_grace_minutes', 0) ?>" min="0" max="120" step="1" required>
                                                    <span class="input-group-text">min after shift start</span>
                                                </div>
                                                <small class="text-muted">Arrivals up to this many minutes after the shift start are not late at all. Applies in both modes.</small>
                                            </div>
                                            <div class="col-md-8">
                                                <div id="late-brackets" <?= $lm === 1 ? '' : 'style="opacity:.5;"' ?>>
                                                    <table class="table table-sm table-bordered align-middle mb-2" style="max-width:640px;">
                                                        <thead class="table-light">
                                                            <tr><th>Arrives (minutes after shift start)</th><th class="text-center" style="width:180px;">Charged</th><th class="text-center" style="width:150px;">On an 8:00 shift</th></tr>
                                                        </thead>
                                                        <tbody>
                                                            <tr>
                                                                <td>1 &ndash; <span class="lr-echo" data-for="lr-grace"><?= $lv('late_grace_minutes', 0) ?></span> min</td>
                                                                <td class="text-center text-success fw-semibold">not late</td>
                                                                <td class="text-center text-muted lr-clock" data-from="0" data-to="lr-grace"></td>
                                                            </tr>
                                                            <tr>
                                                                <td><span class="lr-echo-plus" data-for="lr-grace"></span> &ndash;
                                                                    <input type="number" class="form-control form-control-sm d-inline-block" style="width:80px;" name="late_bracket_1_max" id="lr-b1max" value="<?= $lv('late_bracket_1_max', 30) ?>" min="1" max="600" step="1" required> min</td>
                                                                <td class="text-center"><div class="input-group input-group-sm"><input type="number" class="form-control" name="late_bracket_1_hours" value="<?= $lv('late_bracket_1_hours', 1) ?>" min="0" max="12" step="0.25" required><span class="input-group-text">hr</span></div></td>
                                                                <td class="text-center text-muted lr-clock" data-from="lr-grace" data-to="lr-b1max"></td>
                                                            </tr>
                                                            <tr>
                                                                <td><span class="lr-echo-plus" data-for="lr-b1max"></span> &ndash;
                                                                    <input type="number" class="form-control form-control-sm d-inline-block" style="width:80px;" name="late_bracket_2_max" id="lr-b2max" value="<?= $lv('late_bracket_2_max', 60) ?>" min="1" max="600" step="1" required> min</td>
                                                                <td class="text-center"><div class="input-group input-group-sm"><input type="number" class="form-control" name="late_bracket_2_hours" value="<?= $lv('late_bracket_2_hours', 2) ?>" min="0" max="12" step="0.25" required><span class="input-group-text">hr</span></div></td>
                                                                <td class="text-center text-muted lr-clock" data-from="lr-b1max" data-to="lr-b2max"></td>
                                                            </tr>
                                                            <tr>
                                                                <td>more than
                                                                    <input type="number" class="form-control form-control-sm d-inline-block" style="width:80px;" name="late_half_day_after" id="lr-hd" value="<?= $lv('late_half_day_after', 60) ?>" min="1" max="720" step="1" required> min</td>
                                                                <td class="text-center text-danger fw-semibold">HALF DAY</td>
                                                                <td class="text-center text-muted lr-clock" data-from="lr-hd" data-to=""></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <div class="alert alert-info py-2 px-3 mb-0" style="font-size:12px;">
                                                    <i class="ri-information-line me-1"></i>
                                                    Measured from <b>each employee&rsquo;s own shift start</b> (8-5, Evening, Night alike), in paid time &mdash; the shift&rsquo;s break is skipped when counting.
                                                    <b>Half day</b> charges half the shift&rsquo;s hours and caps hours worked at the other half.
                                                    Like every DTR figure this is <b>frozen at punch time</b>: new scans use the rules right away; records already on file keep their stored late until a <b>Recompute</b> is run.
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Summary -->
                                    <div class="col-12">
                                        <hr class="my-1">
                                        <h6 class="fw-bold text-uppercase text-muted mb-3" style="font-size:11px;letter-spacing:1px;">
                                            <i class="ri-table-line me-1"></i>Effective Rate Summary
                                        </h6>
                                        <table class="table table-sm table-bordered align-middle" style="max-width:500px;">
                                            <thead class="table-dark">
                                                <tr><th>Scenario</th><th class="text-center">Effective Rate</th></tr>
                                            </thead>
                                            <tbody id="rate-summary">
                                                <tr><td>Regular Day</td><td class="text-center">100%</td></tr>
                                                <tr><td>Regular Day OT</td><td class="text-center" id="s-ot-reg">—</td></tr>
                                                <tr><td>Legal Holiday</td><td class="text-center" id="s-lh">—</td></tr>
                                                <tr><td>Legal Holiday OT</td><td class="text-center" id="s-lh-ot">—</td></tr>
                                                <tr><td>Special Holiday</td><td class="text-center" id="s-sh">—</td></tr>
                                                <tr><td>Special Holiday OT</td><td class="text-center" id="s-sh-ot">—</td></tr>
                                                <tr><td>NSD (per NSD hour)</td><td class="text-center" id="s-nsd">—</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <button type="submit" class="btn btn-success px-4">
                                        <i class="ri-save-line me-1"></i>Save Pay Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function updateSummary() {
    const lh  = parseFloat(document.querySelector('[name="legal_holiday_rate"]').value) || 2;
    const sh  = parseFloat(document.querySelector('[name="special_holiday_rate"]').value) || 1.3;
    const ot  = parseFloat(document.querySelector('[name="ot_regular_rate"]').value) || 1.25;
    const ohm = parseFloat(document.querySelector('[name="ot_holiday_multiplier"]').value) || 1.3;
    const nsd = parseFloat(document.querySelector('[name="nsd_rate"]').value) || 0.1;

    const pct = v => (v * 100).toFixed(0) + '%';
    document.getElementById('s-ot-reg').textContent = pct(ot);
    document.getElementById('s-lh').textContent     = pct(lh);
    document.getElementById('s-lh-ot').textContent  = pct(lh * ohm);
    document.getElementById('s-sh').textContent     = pct(sh);
    document.getElementById('s-sh-ot').textContent  = pct(sh * ohm);
    document.getElementById('s-nsd').textContent    = '+' + pct(nsd);
}

document.querySelectorAll('#form-pay-settings input[type="number"]').forEach(function(el) {
    el.addEventListener('input', updateSummary);
});
updateSummary();

// Late-rule table: echo the boundaries into the neighbouring rows and show what
// each bracket means on an 8:00 shift, so the rule reads as a timetable while
// it is being edited. Brackets are dimmed (not disabled — values still post)
// when the exact-minute mode is picked, since only the grace applies then.
function updateLateRules() {
    const v = id => parseFloat(document.getElementById(id)?.value) || 0;
    document.querySelectorAll('.lr-echo').forEach(el => { el.textContent = v(el.dataset.for); });
    document.querySelectorAll('.lr-echo-plus').forEach(el => { el.textContent = v(el.dataset.for) + 1; });
    const clock = m => {
        const t = new Date(2000, 0, 1, 8, 0, 0); t.setMinutes(t.getMinutes() + m);
        let h = t.getHours(), mi = t.getMinutes(); const ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12;
        return h + ':' + String(mi).padStart(2, '0') + ' ' + ap;
    };
    document.querySelectorAll('.lr-clock').forEach(el => {
        const from = el.dataset.from === '0' ? 0 : v(el.dataset.from);
        if (!el.dataset.to) { el.textContent = 'after ' + clock(from); return; }
        const to = v(el.dataset.to);
        el.textContent = to > from ? clock(from + 1) + ' – ' + clock(to) : '—';
    });
    const bracket = document.querySelector('input[name="late_mode"]:checked')?.value === '1';
    const box = document.getElementById('late-brackets');
    if (box) box.style.opacity = bracket ? '' : '.5';
}
document.querySelectorAll('#late-brackets input, #lr-grace, input[name="late_mode"]').forEach(function (el) {
    el.addEventListener('input', updateLateRules);
    el.addEventListener('change', updateLateRules);
});
updateLateRules();

document.getElementById('form-pay-settings').addEventListener('submit', async function(e) {
    e.preventDefault();
    const res  = await fetch('ajax.php?action=save_pay_settings', { method: 'POST', body: new URLSearchParams(new FormData(this)) });
    const json = await res.json();
    if (json?.result) {
        Swal.fire({ icon: 'success', title: 'Saved', text: json.message, timer: 1500, showConfirmButton: false });
        updateSummary();
    } else {
        Swal.fire({ icon: 'error', title: 'Error', text: json?.message || 'Failed to save.' });
    }
});
</script>
