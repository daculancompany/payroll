<?php
$settings = [];
$sq = $conn->query("SELECT setting_key, setting_value, description FROM pay_settings");
if ($sq) while ($r = $sq->fetch_assoc()) $settings[$r['setting_key']] = $r;

function ps($key, $settings) {
    return isset($settings[$key]) ? (float)$settings[$key]['setting_value'] : 0;
}
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

document.getElementById('form-pay-settings').addEventListener('submit', async function(e) {
    e.preventDefault();
    const res  = await fetch('ajax.php?action=save_pay_settings', { method: 'POST', body: new URLSearchParams(new FormData(this)) });
    const json = await res.json();
    if (json?.result) {
        Swal.fire({ icon: 'success', title: 'Saved', text: json.message, timer: 1500, showConfirmButton: false });
        updateSummary();
    } else {
        alert(json?.message || 'Failed to save.');
    }
});
</script>
