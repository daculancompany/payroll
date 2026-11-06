<?php
// Shared option lists for payroll deduction settings. Rendered pre-checked in
// the Create modal (so a new payroll auto-calculates a complete payslip) and
// again in the gear "Payroll Settings" modal for editing an existing payroll.
//
// Refunds are disabled: with no refunds[] posted, settingsFromInput() records no
// type-4 entries, so new payrolls carry no refunds and the Refunds columns stay
// out of the payroll table. Uncomment the row below to bring the section back.
$payroll_setting_sections = [
    // 'new_checked' = ticked by default in the Create modal. Only Allowances is
    // on; these three are ticked per run by whoever creates it.
    ['title' => 'Contributions', 'icon' => 'ri-hand-coin-line', 'new_checked' => false, 'query' => "SELECT id, contribution AS label FROM contributions ORDER BY id ASC", 'prefix' => 'contributions', 'name' => 'contributions[]', 'id_col' => 'id'],
    ['title' => 'Deductions',    'icon' => 'ri-subtract-line',  'new_checked' => false, 'query' => "SELECT id, deduction AS label FROM deductions ORDER BY id ASC",       'prefix' => 'deductions',    'name' => 'deductions[]',    'id_col' => 'id'],
    ['title' => 'Loans',         'icon' => 'ri-bank-card-line', 'new_checked' => false, 'query' => "SELECT clt_id AS id, loan_type AS label FROM contribution_loan_types ORDER BY clt_id ASC", 'prefix' => 'loan', 'name' => 'loans[]', 'id_col' => 'id'],
    // ['title' => 'Refunds',       'icon' => 'ri-refund-2-line',  'query' => "SELECT id, refunds AS label FROM refunds ORDER BY id ASC",             'prefix' => 'refund',        'name' => 'refunds[]',       'id_col' => 'id'],
];
// Allowance types. Every ticked one becomes a column in the payroll table and
// is applied on (re)calculation; unticked = not applied, not shown. Type codes
// live in payroll_settings_split() (db_connect.php).
//
// This used to be shown ONLY in the gear modal. The Create modal silently
// ticked every allowance type through defaultColumnSettings(), so the one
// switch that decides whether a run pays allowances at all was invisible at
// the moment the run was created. Both modals offer it now.
//
// Allowances is the one group ticked by default on a new run. It only reaches
// employees who have that allowance assigned on their own record, so leaving
// it on cannot pay anyone who was not already set up for it.
$payroll_settings_only_sections = [
    ['title' => 'Allowances', 'icon' => 'ri-gift-line', 'new_checked' => true, 'query' => "SELECT id, allowance AS label FROM allowances ORDER BY id ASC", 'prefix' => 'allowance', 'name' => 'allowances[]', 'id_col' => 'id'],
];
// Rows for one section from its master table query.
$payroll_section_rows = function (array $sec) use ($conn): array {
    $out = [];
    if ($res = $conn->query($sec['query'])) {
        while ($r = $res->fetch_assoc()) $out[] = $r;
    }
    return $out;
};

// ONE renderer for the checkbox groups, shared by the Create modal and the gear
// Settings modal. They used to carry a copy each, and the copies had drifted —
// different icon and heading sizes, different rule spacing, a different card
// background — so the same list of switches looked like two different screens.
// $idPrefix keeps the two sets of element ids apart ('new-' on Create, so
// payroll.js can tick the gear modal's boxes without touching this one).
// $useDefaults true (Create modal) ticks each section per its 'new_checked'
// flag; false (gear modal) leaves everything clear for payroll.js to fill in
// from this run's saved settings.
$payroll_sections_html = function (array $sections, string $idPrefix, bool $useDefaults) use ($payroll_section_rows): string {
    $h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };
    $out = '';
    foreach (array_values($sections) as $i => $sec) {
        $rows = $payroll_section_rows($sec);
        if (!$rows) continue;   // a master with no rows gets no empty heading
        $checked = $useDefaults && !empty($sec['new_checked']);
        if ($out !== '') $out .= '<hr class="my-3">';
        $out .= '<div class="d-flex align-items-center gap-2 mb-2">'
              . '<i class="' . $h($sec['icon']) . '" style="color:#673bb6;font-size:16px;"></i>'
              . '<span class="fw-bold" style="font-size:13px;color:#673bb6;">' . $h($sec['title']) . '</span>'
              . '</div><div class="row g-2">';
        foreach ($rows as $row) {
            $id = $idPrefix . $sec['prefix'] . '-' . $row[$sec['id_col']];
            $out .= '<div class="col-md-4 col-sm-6">'
                  . '<div class="form-check" style="border:1px solid #e8eaf6;border-radius:4px;padding:6px 10px 6px 32px;background:#f9f9ff;">'
                  . '<input class="form-check-input" type="checkbox" id="' . $h($id) . '"'
                  . ' name="' . $h($sec['name']) . '" value="' . $h($row[$sec['id_col']]) . '"'
                  . ($checked ? ' checked' : '') . '>'
                  . '<label class="form-check-label" style="font-size:12px;font-weight:600;cursor:pointer;" for="' . $h($id) . '">'
                  . $h($row['label']) . '</label>'
                  . '</div></div>';
        }
        $out .= '</div>';
    }
    return $out;
};
?>
<!-- ── Create Payroll ─────────────────────────────────────────────── -->
<div class="modal fade" id="modal" tabindex="-1" role="dialog">
    <form id="form-submit" novalidate>
        <input type="hidden" name="id" id="id">
        <input type="hidden" name="p2" value="<?= (isset($_GET['p2']) && $_GET['p2'] === 'true') ? 'yes' : 'no' ?>">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">
                        <i class="ri-money-dollar-circle-line me-2" style="color:#673bb6;"></i>Create Payroll
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">

                        <div class="col-md-12">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                <i class="ri-calendar-range-line me-1"></i>Payroll Period <span class="text-danger">*</span>
                            </label>
                            <div id="payroll-daterange" style="display:flex;align-items:center;gap:8px;width:100%;padding:8px 12px;border:1px solid #ced4da;border-radius:6px;background:#fff;cursor:pointer;font-weight:600;color:#0c5460;">
                                <i class="ri-calendar-2-line" style="color:#673bb6;"></i>
                                <span id="payroll-range-label" style="color:#888;font-weight:500;">Select payroll period…</span>
                                <i class="ri-arrow-down-s-line" style="margin-left:auto;color:#aaa;"></i>
                            </div>
                            <!-- hidden values consumed on submit -->
                            <input type="hidden" name="date_from" id="date_from" required data-parsley-required-message="Please select the payroll period.">
                            <input type="hidden" name="date_to" id="date_to" required>
                        </div>

                        <!-- What this run applies. Allowances are ticked by default; the
                             contributions, deductions and loans to apply are chosen per run
                             (see 'new_checked' on the section definitions above).
                             Replaces the old post-create Settings step. -->
                        <?php // Allowances first, exactly the order and markup the gear modal uses. ?>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                <i class="ri-list-settings-line me-1"></i>What to apply
                            </label>
                            <div style="border:1px solid #e8eaf6;border-radius:6px;padding:12px;background:#fafaff;">
                                <?= $payroll_sections_html(
                                        array_merge($payroll_settings_only_sections, $payroll_setting_sections),
                                        'new-', true) ?>
                            </div>
                            <small class="text-muted" style="font-size:11px;"><b>Allowances are selected by default.</b> Tick the contributions, deductions and loans this run should apply &mdash; anything left unticked is neither applied nor shown.</small>
                            <!-- Marker so save_payroll() can tell "user unticked everything"
                                 (respect the empty selection) from "form never offered the
                                 checkboxes" (fall back to all defaults). -->
                            <input type="hidden" name="settings_offered" value="1">
                            <!-- The Allowances group is on this form now, so what is ticked is
                                 exactly what applies (settingsFromInput). Without this the
                                 defaults were merged back in and unticking an allowance here
                                 would have done nothing. -->
                            <input type="hidden" name="settings_v2" value="1">
                        </div>

                        <!-- Payroll Type dropdown removed — all payrolls run on the standard
                             semi-monthly (1-15 / 16-30) cutoff, so this is fixed to 1 (non-monthly
                             branch). Only the "== 5" check elsewhere cares about this value. -->
                        <input type="hidden" id="type" name="type" value="1">

                        <!-- Category/Site fixed to default (1) — selection removed -->
                        <input type="hidden" id="category-select" name="category_id" value="1">

                    </div>
                </div>
                <div class="modal-footer" style="background:#f8f9fa;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="ri-close-line me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-sm text-white submitbutton" style="background:#673bb6;border-color:#673bb6;">
                        <i class="ri-add-circle-line me-1"></i>Create Payroll
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- ── Payroll Settings ───────────────────────────────────────────── -->
<div class="modal fade" id="modal-settings" tabindex="-1" role="dialog">
    <form id="form-settings" novalidate>
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">
                        <i class="ri-settings-3-line me-2" style="color:#673bb6;"></i>Payroll Settings
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="settings-id">
                    <input type="hidden" name="settings_v2" value="1">
                    <p class="text-muted mb-3" style="font-size:12px;">Ticked items are applied on the next
                        Recalculate and shown as columns in the payroll table. Unticked items are neither
                        applied nor shown.</p>

                    <?php // Same renderer as the Create modal — Allowances first, unticked
                          // until payroll.js fills them in from this run's saved settings. ?>
                    <?= $payroll_sections_html(
                            array_merge($payroll_settings_only_sections, $payroll_setting_sections),
                            '', false) ?>

                    <!-- Non-ATM (paid in cash): its own NONATM block on the paysheet and the
                         ATM / NON ATM split on the Department Summary. Filled per run by
                         payroll.js from ajax.php?action=get_payroll_non_atm. -->
                    <hr class="my-3">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="ri-hand-coin-line" style="color:#673bb6;font-size:16px;"></i>
                        <span class="fw-bold" style="font-size:13px;color:#673bb6;">Non-ATM Employees (paid in cash)</span>
                    </div>
                    <input type="hidden" name="non_atm_offered" value="1" id="non-atm-offered" disabled>
                    <select id="non-atm-select" name="non_atm[]" class="form-control form-control-sm" multiple
                        data-placeholder="Select the employees paid in cash…"
                        data-cs-multi="true" data-cs-title="Non-ATM" data-cs-icon="ri-hand-coin-line" data-cs-search="true">
                    </select>
                    <div id="non-atm-note" class="text-muted mt-1" style="font-size:11px;"></div>

                </div>
                <div class="modal-footer" style="background:#f8f9fa;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="ri-close-line me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-sm text-white submitbutton" style="background:#673bb6;border-color:#673bb6;">
                        <i class="ri-save-line me-1"></i>Save Settings
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- ── Payroll History ───────────────────────────────────────────── -->
<div class="modal fade" id="modal-payroll-history" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border:none;border-radius:6px;overflow:hidden;">
            <div class="modal-header" style="background:#fff;border-bottom:1px solid #e1dfdd;padding:12px 16px;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <i class="ri-history-line" style="color:#6642aa;font-size:17px;"></i>
                    <span style="font-weight:700;font-size:14px;color:#323130;">Version History</span>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" style="background:#fff;">
                <div id="loanHistoryDiv"></div>
            </div>
        </div>
    </div>
</div>

<!-- Select Sites modal removed — payroll always covers all active sites. -->
