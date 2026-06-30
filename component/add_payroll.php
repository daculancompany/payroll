<!-- ── Create Payroll ─────────────────────────────────────────────── -->
<div class="modal fade" id="modal" tabindex="-1" role="dialog">
    <form id="form-submit" novalidate>
        <input type="hidden" name="id" id="id">
        <input type="hidden" name="p2" value="<?= (isset($_GET['p2']) && $_GET['p2'] === 'true') ? 'yes' : 'no' ?>">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">
                        <i class="ri-money-dollar-circle-line me-2" style="color:#009688;"></i>Create Payroll
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">

                        <!-- Employer fixed to default (1) — selection removed -->
                        <input type="hidden" id="employer-select" name="employer_id" value="1">

                        <div class="col-md-12">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#009688;">
                                <i class="ri-calendar-range-line me-1"></i>Payroll Period <span class="text-danger">*</span>
                            </label>
                            <div id="payroll-daterange" style="display:flex;align-items:center;gap:8px;width:100%;padding:8px 12px;border:1px solid #ced4da;border-radius:6px;background:#fff;cursor:pointer;font-weight:600;color:#0c5460;">
                                <i class="ri-calendar-2-line" style="color:#009688;"></i>
                                <span id="payroll-range-label" style="color:#888;font-weight:500;">Select payroll period…</span>
                                <i class="ri-arrow-down-s-line" style="margin-left:auto;color:#aaa;"></i>
                            </div>
                            <!-- hidden values consumed on submit -->
                            <input type="hidden" name="date_from" id="date_from" required data-parsley-required-message="Please select the payroll period.">
                            <input type="hidden" name="date_to" id="date_to" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#009688;">
                                <i class="ri-list-check-2 me-1"></i>Payroll Type <span class="text-danger">*</span>
                            </label>
                            <select id="type" name="type" class="form-control"
                                data-placeholder="Select type"
                                data-parsley-required-message="Please select type." required>
                                <option value="">Select a type</option>
                                <option value="1"><i class="ri-calendar-2-line"></i> Week 1</option>
                                <option value="2">Week 2</option>
                                <option value="3">Week 3</option>
                                <option value="4">Week 4</option>
                                <option value="5">Monthly</option>
                            </select>
                        </div>

                        <!-- Category/Site fixed to default (1) — selection removed -->
                        <input type="hidden" id="category-select" name="category_id" value="1">

                    </div>
                </div>
                <div class="modal-footer" style="background:#f8f9fa;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="ri-close-line me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-sm text-white submitbutton" style="background:#009688;border-color:#009688;">
                        <i class="ri-arrow-right-line me-1"></i>Next
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
                        <i class="ri-settings-3-line me-2" style="color:#009688;"></i>Payroll Settings
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="settings-id">

                    <?php
                    $sections = [
                        [
                            'title' => 'Contributions',
                            'icon'  => 'ri-hand-coin-line',
                            'query' => "SELECT id, contribution AS label FROM contributions ORDER BY id ASC",
                            'prefix'=> 'contributions',
                            'name'  => 'contributions[]',
                            'id_col'=> 'id',
                        ],
                        [
                            'title' => 'Deductions',
                            'icon'  => 'ri-subtract-line',
                            'query' => "SELECT id, deduction AS label FROM deductions ORDER BY id ASC",
                            'prefix'=> 'deductions',
                            'name'  => 'deductions[]',
                            'id_col'=> 'id',
                        ],
                        [
                            'title' => 'Loans',
                            'icon'  => 'ri-bank-card-line',
                            'query' => "SELECT clt_id AS id, loan_type AS label FROM contribution_loan_types ORDER BY clt_id ASC",
                            'prefix'=> 'loan',
                            'name'  => 'loans[]',
                            'id_col'=> 'id',
                        ],
                        [
                            'title' => 'Refunds',
                            'icon'  => 'ri-refund-2-line',
                            'query' => "SELECT id, refunds AS label FROM refunds ORDER BY id ASC",
                            'prefix'=> 'refund',
                            'name'  => 'refunds[]',
                            'id_col'=> 'id',
                        ],
                    ];

                    foreach ($sections as $i => $sec):
                        $rows = $conn->query($sec['query']);
                    ?>
                    <?= $i > 0 ? '<hr class="my-3">' : '' ?>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="<?= $sec['icon'] ?>" style="color:#009688;font-size:16px;"></i>
                        <span class="fw-bold" style="font-size:13px;color:#009688;"><?= $sec['title'] ?></span>
                    </div>
                    <div class="row g-2">
                        <?php while ($row = $rows->fetch_assoc()): ?>
                        <div class="col-md-4 col-sm-6">
                            <div class="form-check" style="border:1px solid #e8eaf6;border-radius:4px;padding:6px 10px 6px 32px;background:#f9f9ff;">
                                <input class="form-check-input" type="checkbox"
                                    id="<?= $sec['prefix'] ?>-<?= $row[$sec['id_col']] ?>"
                                    name="<?= $sec['name'] ?>"
                                    value="<?= $row[$sec['id_col']] ?>">
                                <label class="form-check-label" style="font-size:12px;font-weight:600;cursor:pointer;"
                                    for="<?= $sec['prefix'] ?>-<?= $row[$sec['id_col']] ?>">
                                    <?= htmlspecialchars($row['label']) ?>
                                </label>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <?php endforeach; ?>

                </div>
                <div class="modal-footer" style="background:#f8f9fa;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="ri-close-line me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-sm text-white submitbutton" style="background:#009688;border-color:#009688;">
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
                    <i class="ri-history-line" style="color:#219688;font-size:17px;"></i>
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

<!-- ── Select Sites ──────────────────────────────────────────────── -->
<div class="modal fade" id="modal-sites" tabindex="-1" role="dialog">
    <form id="form-add" novalidate>
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title title mb-0">
                        <i class="ri-map-pin-2-line me-2" style="color:#009688;"></i>Select Sites
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row" id="show-sites"></div>
                </div>
                <div class="modal-footer" style="background:#f8f9fa;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="ri-close-line me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-sm text-white submitbutton" style="background:#009688;border-color:#009688;">
                        <i class="ri-save-line me-1"></i>Create Payroll
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>
