<!-- ── Create / Edit Employee ─────────────────────────────────────── -->
<div class="modal fade" id="addemployee" tabindex="-1" role="dialog">
    <form id="form-add" data-parsley-validate>
        <input type="hidden" name="id" value="<?= isset($_GET['id']) ? (int)$_GET['id'] : '' ?>">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0" id="create-title">
                        <i class="ri-user-add-line me-2" style="color:#673bb6;"></i>
                        <?= isset($employee_no) ? 'Edit' : 'Create' ?> Employee
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">

                    <?php
                    $v = function($key) { return isset($$key) ? htmlspecialchars($$key) : ''; };
                    ?>

                    <!-- Personal Information -->
                    <div class="mb-1" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#673bb6;border-bottom:2px solid #eef0f8;padding-bottom:4px;margin-bottom:12px;">
                        <i class="ri-user-3-line me-1"></i>Personal Information
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-12">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                <i class="ri-shield-check-line me-1"></i>Classification <span class="text-danger">*</span>
                            </label>
                            <select id="clasification-select" class="form-control select2" name="clasification_id"
                                data-placeholder="Select classification"
                                data-parsley-required-message="Please select classification." required>
                                <option value="">Select a classification</option>
                                <?php
                                $pos = $conn->query("SELECT * FROM clasification");
                                while ($row = $pos->fetch_assoc()):
                                ?>
                                    <option class="opt" value="<?= $row['id'] ?>"
                                        <?= isset($clasification_id) && $clasification_id == $row['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($row['clasification']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                First Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" name="firstname"
                                value="<?= isset($employee_no) ? htmlspecialchars($firstname) : '' ?>"
                                placeholder="e.g. Juan"
                                data-parsley-required-message="First name is required." required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Middle Name
                            </label>
                            <input type="text" class="form-control" name="middlename" maxlength="225"
                                value="<?= isset($employee_no) ? htmlspecialchars($middlename) : '' ?>"
                                placeholder="e.g. Santos">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Last Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" name="lastname"
                                value="<?= isset($employee_no) ? htmlspecialchars($lastname) : '' ?>"
                                placeholder="e.g. Dela Cruz"
                                data-parsley-required-message="Last name is required." required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Extension
                            </label>
                            <input type="text" class="form-control" name="ext"
                                value="<?= isset($ext) ? htmlspecialchars($ext) : '' ?>"
                                placeholder="SR / JR">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Birthdate
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bday-toggle" style="cursor:pointer;"><i class="ri-cake-line"></i></span>
                                <input type="text" class="form-control datetimepicker2emp" name="bday"
                                    value="<?= (!empty($bday) && $bday !== '0000-00-00') ? htmlspecialchars($bday) : '' ?>"
                                    placeholder="YYYY-MM-DD" autocomplete="off" readonly
                                    style="background-color:#fff;cursor:pointer;">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                <i class="ri-building-3-line me-1"></i>Department
                            </label>
                            <select id="department-select" class="form-control select2" name="department_id"
                                data-placeholder="Select department">
                                <option value="">Select Department</option>
                                <?php
                                $depts = $conn->query("SELECT * FROM department ORDER BY name ASC");
                                if ($depts) while ($row = $depts->fetch_assoc()):
                                ?>
                                    <option value="<?= $row['id'] ?>"
                                        <?= isset($department_id) && $department_id == $row['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($row['name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                <i class="ri-node-tree me-1"></i>Area
                            </label>
                            <?php /* Ward/section inside the department. Decides who approves this
                                     employee's leave and whose duty roster they appear on. Left
                                     blank the employee falls back to department-level scoping. */ ?>
                            <select id="area-select" class="form-control select2" name="area_id"
                                data-placeholder="Select area">
                                <option value="">Select Area</option>
                                <?php
                                $__ar = $conn->query("SELECT id, name, department_id FROM area WHERE status = 1 ORDER BY name ASC");
                                if ($__ar) while ($row = $__ar->fetch_assoc()):
                                ?>
                                    <option class="opt" value="<?= (int)$row['id'] ?>" data-did="<?= (int)$row['department_id'] ?>"
                                        <?= isset($area_id) && $area_id == $row['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($row['name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                <i class="ri-briefcase-4-line me-1"></i>Position <span class="text-danger">*</span>
                            </label>
                            <select id="position-select" class="form-control select2" name="position_id"
                                data-placeholder="Select position"
                                data-parsley-required-message="Please select position." required>
                                <option value="">Select a position</option>
                                <?php
                                $pos = $conn->query("SELECT * FROM position ORDER BY name ASC");
                                while ($row = $pos->fetch_assoc()):
                                ?>
                                    <option class="opt" value="<?= $row['id'] ?>" data-did="<?= $row['department_id'] ?>"
                                        <?= isset($position_id) && $position_id == $row['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($row['name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>

                    <?php
                    /* ── Portal Login ────────────────────────────────────────────────
                       The employee signs in to the portal with this email + password
                       (their Employee No. keeps working as a username too).

                       ADMIN ONLY: the other roles that can reach this form (Staff,
                       Auditor) never see the fields, and save_employee() ignores them
                       when posted by anyone but an Administrator — hiding them here is
                       layout, the server is the boundary. */
                    $__is_admin    = (int) ($_SESSION['login_role'] ?? 0) === 1;
                    $__portal_eid  = isset($emp_id) ? (int) $emp_id : (isset($_GET['id']) ? (int) $_GET['id'] : 0);
                    $__portal_user = '';
                    if ($__is_admin && $__portal_eid > 0) {
                        // The table is optional on a fresh deploy — check before reading it.
                        $__pt = $conn->query("SHOW TABLES LIKE 'employee_portal_accounts'");
                        if ($__pt && $__pt->num_rows) {
                            $__pq = $conn->query("SELECT username FROM employee_portal_accounts WHERE employee_id = $__portal_eid LIMIT 1");
                            if ($__pq && ($__pr = $__pq->fetch_assoc())) $__portal_user = $__pr['username'];
                        }
                    }
                    ?>
                    <?php if ($__is_admin): ?>
                        <div class="mb-1" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#673bb6;border-bottom:2px solid #eef0f8;padding-bottom:4px;margin-bottom:12px;">
                            <i class="ri-shield-user-line me-1"></i>Portal Login
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                    <i class="ri-at-line me-1"></i>Email <small style="text-transform:none;color:#888;">(sign-in username)</small>
                                </label>
                                <input type="email" class="form-control" name="email" autocomplete="off"
                                    value="<?= htmlspecialchars($__portal_user) ?>"
                                    placeholder="e.g. juan.delacruz@example.com"
                                    data-parsley-type="email"
                                    data-parsley-type-message="Please enter a valid email address.">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                    <i class="ri-lock-password-line me-1"></i>Password
                                </label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="portal_password" name="portal_password"
                                        autocomplete="new-password" minlength="6"
                                        placeholder="<?= isset($employee_no) ? 'Leave blank to keep current' : 'Leave blank for the default' ?>"
                                        data-parsley-minlength="6"
                                        data-parsley-minlength-message="Password must be at least 6 characters.">
                                    <button class="btn btn-outline-secondary" type="button" id="togglePortalPassword" tabindex="-1">
                                        <i class="ri-eye-off-line" id="togglePortalIcon"></i>
                                    </button>
                                </div>
                                <!-- keeps the browser from autofilling the field above -->
                                <input type="password" style="display:none" autocomplete="off">
                            </div>
                            <div class="col-12">
                                <div id="addemployee-portal-note" style="font-size:11px;color:#57339d;background:#f4f3f8;border:1px dashed #cabede;border-radius:6px;padding:6px 10px;">
                                    <i class="ri-information-line me-1"></i><span id="addemployee-portal-note-text"><?php if (isset($employee_no)): ?>Leave both blank to keep the current login. The employee can always sign in with their
                                        Employee No. as well.<?php else: ?>Blank email &rarr; one is generated as <b>firstname.lastname@<?= htmlspecialchars(PORTAL_DEFAULT_EMAIL_DOMAIN) ?></b>.
                                        Blank password &rarr; the default password, which the employee is asked to change.<?php endif; ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Compensation -->
                    <div class="mb-1" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#673bb6;border-bottom:2px solid #eef0f8;padding-bottom:4px;margin-bottom:12px;">
                        <i class="ri-money-dollar-circle-line me-1"></i>Compensation
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Monthly Basic Pay <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input type="text" class="form-control filterme" name="basic_pay"
                                    value="<?= isset($basic_pay) ? htmlspecialchars($basic_pay) : '' ?>"
                                    placeholder="0.00"
                                    data-parsley-required-message="Amount is required." required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Basic Daily Rate <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input type="text" class="form-control filterme" name="salary"
                                    value="<?= isset($salary) ? htmlspecialchars($salary) : '' ?>"
                                    placeholder="0.00"
                                    data-parsley-required-message="Amount is required." required>
                            </div>
                        </div>
                        <?php $__rate_type = isset($rate_type) && in_array($rate_type, ['daily', 'monthly', 'fixed'], true) ? $rate_type : 'daily'; ?>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Rate Type <span class="text-danger">*</span>
                            </label>
                            <div class="d-flex flex-wrap gap-4 mt-1">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="rate_type" id="rt_daily" value="daily" <?= $__rate_type === 'daily' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="rt_daily">
                                        <b>Daily</b> — pay = days present × daily rate
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="rate_type" id="rt_monthly" value="monthly" <?= $__rate_type === 'monthly' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="rt_monthly">
                                        <b>Monthly</b> — pay = salary share − unpaid absences
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="rate_type" id="rt_fixed" value="fixed" <?= $__rate_type === 'fixed' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="rt_fixed">
                                        <b>Fixed</b> — full salary, no attendance needed
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Overtime Rate <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input type="text" class="form-control filterme" name="ot_rate"
                                    value="<?= isset($ot_rate) ? htmlspecialchars($ot_rate) : '' ?>"
                                    placeholder="0.00"
                                    data-parsley-required-message="Amount is required." required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Allowance Rate <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input type="text" class="form-control filterme" name="allowance_rate"
                                    value="<?= isset($allowance_rate) ? htmlspecialchars($allowance_rate) : '' ?>"
                                    placeholder="0.00"
                                    data-parsley-required-message="Amount is required." required>
                            </div>
                        </div>
                        <?php
                        /* SSS Provident Fund is hidden from the form, but the value still has
                           to be SUBMITTED: save() reads $_POST['sss_fund'] ?? 0 and writes the
                           column on every update, so dropping the input outright would zero out
                           the stored amount for every employee that gets edited. */
                        ?>
                        <input type="hidden" name="sss_fund" value="<?= isset($sss_fund) ? htmlspecialchars($sss_fund) : '0' ?>">
                        <!-- SSS Provident Fund field hidden on request
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                SSS Provident Fund <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input type="text" class="form-control filterme" name="sss_fund"
                                    placeholder="0.00"
                                    data-parsley-required-message="Amount is required." required>
                            </div>
                        </div>
                        -->
                    </div>

                    <!-- Bank / Payout details -->
                    <div class="mb-1" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#673bb6;border-bottom:2px solid #eef0f8;padding-bottom:4px;margin-bottom:12px;">
                        <i class="ri-bank-line me-1"></i>Bank / Payout Details
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Bank
                            </label>
                            <select class="form-select" name="bank_id">
                                <option value="">— No bank selected —</option>
                                <?php
                                $__bank_q = $conn->query("SELECT id, bank_name FROM banks WHERE status = 1 ORDER BY bank_name ASC");
                                if ($__bank_q) while ($__b = $__bank_q->fetch_assoc()): ?>
                                    <option value="<?= (int) $__b['id'] ?>" <?= isset($bank_id) && (int) $bank_id === (int) $__b['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($__b['bank_name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Account Number
                            </label>
                            <input type="text" class="form-control" name="bank_account_no" maxlength="50"
                                value="<?= isset($bank_account_no) ? htmlspecialchars($bank_account_no ?? '') : '' ?>"
                                placeholder="e.g. 001234567890"
                                pattern="[A-Za-z0-9 \-]*"
                                data-parsley-pattern="[A-Za-z0-9 \-]*"
                                data-parsley-pattern-message="Letters, numbers, spaces and dashes only.">
                        </div>
                    </div>

                    <!-- Settings -->
                    <div class="mb-1" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#673bb6;border-bottom:2px solid #eef0f8;padding-bottom:4px;margin-bottom:12px;">
                        <i class="ri-settings-3-line me-1"></i>Payroll Settings
                    </div>
                    <div class="row g-2">
                        <?php
                        /* Weekly Payroll / Auto Deductions toggles are hidden from the form.
                           Weekly payroll has been removed entirely, so it is no longer
                           submitted at all — save() forces it to 0.

                           Auto Deductions still has to be SUBMITTED: save() reads it with
                           isset($_POST['isAutoDeduct']) ? 1 : 0 and writes the column on
                           every update, so dropping it outright would switch auto
                           SSS/PhilHealth deductions off for every employee that gets edited.

                           This mirrors checkbox semantics exactly: emitted only when the
                           stored value is 1 (present = 1, absent = 0). A hidden input with
                           value="0" would NOT work — isset() would still see it and store 1. */
                        ?>
                        <?php if (isset($isAutoDeduct) && $isAutoDeduct == 1): ?>
                        <input type="hidden" name="isAutoDeduct" value="1">
                        <?php endif; ?>
                        <div class="col-md-4">
                            <div style="border:1px solid #e8eaf6;border-radius:4px;padding:8px 12px;background:#f9f9ff;">
                                <div class="form-check form-switch mb-0">
                                    <?php /* Create has no $status (edit mode extracts it from the row),
                                             so a new employee starts Active — the normal case. */ ?>
                                    <input class="form-check-input" name="status" type="checkbox" role="switch" id="sw_status"
                                        <?= (!isset($status) || $status == 1) ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="sw_status" style="font-size:12px;">
                                        <i class="ri-checkbox-circle-line me-1"></i>Active
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer" style="background:#f8f9fa;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="ri-close-line me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-sm text-white submitbutton" style="background:#673bb6;border-color:#673bb6;">
                        <i class="fa fa-spinner fa-spin fa-spinner-button"></i>
                        <i class="ri-save-line me-1"></i><span id="addemployee-submit-label"><?= isset($employee_no) ? 'Save Changes' : 'Create Employee' ?></span>
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- ── Add Deduction ─────────────────────────────────────────────── -->
<div class="modal fade" id="modal-deduction" tabindex="-1" role="dialog">
    <form id="employee-deduction" novalidate>
        <input type="hidden" name="employee_id" value="<?= isset($_GET['id']) ? (int)$_GET['id'] : '' ?>">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">
                        <i class="ri-subtract-line me-2" style="color:#673bb6;"></i>Add Deduction
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Deduction <span class="text-danger">*</span>
                            </label>
                            <select class="form-control select2" id="deduction_id" name="deduction_id[]"
                                data-placeholder="Select deduction"
                                data-parsley-required-message="Please select deduction." required>
                                <option value="">Select Deduction</option>
                                <?php
                                $deduction = $conn->query("SELECT * FROM deductions ORDER BY deduction ASC");
                                while ($row = $deduction->fetch_assoc()):
                                ?>
                                    <option value="<?= $row['id'] ?>"><?= htmlspecialchars($row['deduction']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-12" id="dfield">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Start Date <small style="text-transform:none;color:#888;">(first deduction)</small>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-calendar-2-line"></i></span>
                                <input type="text" id="edate" class="form-control datetimepicker" name="effective_date[]"
                                    value="<?= date('Y-m-d') ?>"
                                    data-parsley-required-message="Please enter date." required>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Amount <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input type="text" id="amount" name="amount[]" class="form-control filterme"
                                    placeholder="0.00"
                                    data-parsley-required-message="Please enter amount." required>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Total <small style="text-transform:none;color:#888;">(leave 0 for a recurring deduction; set an amount to amortize like a loan)</small>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input type="text" id="total_amount" name="total_amount[]" class="form-control"
                                    placeholder="0.00" value="0">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="background:#f8f9fa;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="ri-close-line me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-sm text-white submitbutton" style="background:#673bb6;border-color:#673bb6;">
                        <i class="ri-add-line me-1"></i>Add Deduction
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- ── Edit Contribution ─────────────────────────────────────────── -->
<div class="modal fade" id="modal-contrition" tabindex="-1" role="dialog">
    <form id="employee-contribution" novalidate>
        <input type="hidden" id="contribution-id" name="id" value="">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">
                        <i class="ri-hand-coin-line me-2" style="color:#673bb6;"></i>Edit Contribution
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Contribution
                            </label>
                            <input class="form-control" id="contribution-name" type="text" disabled
                                style="background:#eef0f8;font-weight:600;color:#673bb6;">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Amount <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input class="form-control" id="contribution-amount" name="amount" type="text"
                                    placeholder="0.00"
                                    data-parsley-type="number"
                                    data-parsley-required-message="Amount is required." required>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="background:#f8f9fa;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="ri-close-line me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-sm text-white submitbutton" style="background:#673bb6;border-color:#673bb6;">
                        <i class="fa fa-spinner fa-spin fa-spinner-button"></i>
                        <i class="ri-save-line me-1"></i>Save Changes
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- ── Add Allowance ─────────────────────────────────────────────── -->
<div class="modal fade" id="modal-allowance" tabindex="-1" role="dialog">
    <form id="employee-allowance" novalidate>
        <input type="hidden" name="employee_id" value="<?= isset($_GET['id']) ? (int)$_GET['id'] : '' ?>">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">
                        <i class="ri-gift-line me-2" style="color:#673bb6;"></i>Add Allowance
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Allowance <span class="text-danger">*</span>
                            </label>
                            <select class="form-control select2" id="allowance_id" name="allowance_id[]"
                                data-placeholder="Select allowance"
                                data-parsley-required-message="Please select allowance." required>
                                <option value="">Select Allowance</option>
                                <?php
                                $allowance = $conn->query("SELECT * FROM allowances ORDER BY allowance ASC");
                                while ($row = $allowance->fetch_assoc()):
                                ?>
                                    <option value="<?= $row['id'] ?>"><?= htmlspecialchars($row['allowance']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Type <span class="text-danger">*</span>
                            </label>
                            <select id="type2" class="form-control select2" name="type[]"
                                data-placeholder="Select type"
                                data-parsley-required-message="Please select type." required>
                                <option value="">Select Type</option>
                                <option value="1">Monthly</option>
                                <option value="2">Semi-Monthly</option>
                                <option value="3">Once</option>
                            </select>
                        </div>
                        <div class="col-12" style="display:none;" id="dfield2">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Effective Date
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-calendar-2-line"></i></span>
                                <input type="text" id="edate2" class="form-control datetimepicker" name="effective_date[]"
                                    value="<?= date('Y-m-d') ?>"
                                    data-parsley-required-message="Please enter date." required>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Amount <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input class="form-control filterme" name="amount[]" type="text"
                                    placeholder="0.00"
                                    data-parsley-type="number"
                                    data-parsley-required-message="Amount is required." required>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="background:#f8f9fa;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="ri-close-line me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-sm text-white submitbutton" style="background:#673bb6;border-color:#673bb6;">
                        <i class="fa fa-spinner fa-spin fa-spinner-button"></i>
                        <i class="ri-add-line me-1"></i>Add Allowance
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- ── Add / Edit Loan ───────────────────────────────────────────── -->
<!-- Shared attachment picker (loan supporting document — image/PDF, max 5 MB) -->
<link rel="stylesheet" href="<?= av('assets2/css/attach-upload.css') ?>">
<script src="<?= av('assets2/js/attach-upload.js') ?>"></script>
<div class="modal fade" id="modal-loan" tabindex="-1" role="dialog">
    <form id="employee-loan" novalidate>
        <input type="hidden" name="id" id="loan_id">
        <input type="hidden" name="employee_id" id="loan_employee_id" value="<?= isset($emp_id) ? (int)$emp_id : '' ?>">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">
                        <i class="ri-bank-card-line me-2" style="color:#673bb6;"></i>Add Loan
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Loan Type <span class="text-danger">*</span>
                                <?php if (function_exists('can_edit') && can_edit('loans')): ?>
                                    <a href="loans#loan-types-card" target="_blank" rel="noopener" class="ms-1 text-decoration-none" style="font-size:10px;font-weight:600;color:#6642aa;text-transform:none;letter-spacing:0;" title="Add or rename loan types (opens Active Loans)">Manage types <i class="ri-external-link-line"></i></a>
                                <?php endif; ?>
                            </label>
                            <select id="loan-select" class="form-control select2" name="loan_type"
                                data-placeholder="Select loan type"
                                data-parsley-required-message="Type is required." required>
                                <option value="">Select Loan Type</option>
                                <?php
                                $pos = $conn->query("SELECT * FROM contribution_loan_types ORDER BY loan_type ASC");
                                while ($row = $pos->fetch_assoc()):
                                ?>
                                    <option class="opt" value="<?= $row['clt_id'] ?>"><?= htmlspecialchars($row['loan_type']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Loan Date <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-calendar-2-line"></i></span>
                                <input type="text" id="loan_date" class="form-control datetimepicker" name="loan_date"
                                    autocomplete="off" placeholder="YYYY-MM-DD"
                                    data-parsley-required-message="Please select date." required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Loan Amount <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input class="form-control" id="loan_amount" name="loan_amount" type="text"
                                    placeholder="0.00"
                                    data-parsley-type="number"
                                    data-parsley-required-message="Amount is required." required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Deduction / Month <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input class="form-control" id="damount" name="damount" type="text"
                                    placeholder="0.00"
                                    data-parsley-type="number"
                                    data-parsley-required-message="Amount is required." required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Balance <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input class="form-control" id="loan_balance" name="loan_balance" type="text"
                                    placeholder="0.00"
                                    data-parsley-type="number"
                                    data-parsley-required-message="Amount is required." required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Start of Deduction
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ri-calendar-check-line"></i></span>
                                <input type="text" id="loan_effective_date" class="form-control datetimepicker"
                                    name="effective_date" autocomplete="off" placeholder="YYYY-MM-DD">
                            </div>
                            <small class="text-muted" style="font-size:10px;">
                                Leave blank to start on the loan date. Payrolls ending before this date skip the deduction.
                            </small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                                Attachment <span class="text-muted" style="text-transform:none;font-weight:600;">(optional)</span>
                            </label>
                            <div class="att-up" id="loan-attach">
                                <input type="file" name="attachment" hidden accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf">
                                <button type="button" class="att-up-btn"><i class="ri-attachment-2"></i> Attach image or PDF…</button>
                                <div class="att-up-hint">One file only · max <b>5 MB</b> — please compress your attachment (signed loan form, promissory note, etc.).</div>
                                <div class="att-up-prev"></div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div style="border:1px solid #e8eaf6;border-radius:4px;padding:8px 12px;background:#f9f9ff;">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" name="loan_status" type="checkbox" role="switch" id="loan_status">
                                    <label class="form-check-label fw-semibold" for="loan_status" style="font-size:12px;">
                                        <i class="ri-checkbox-circle-line me-1"></i>Mark as Paid
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="background:#f8f9fa;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="ri-close-line me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-sm text-white submitbutton" style="background:#673bb6;border-color:#673bb6;">
                        <i class="fa fa-spinner fa-spin fa-spinner-button"></i>
                        <i class="ri-save-line me-1"></i>Save Loan
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- ── Import Employees (upload → preview → commit) ──────────────── -->
<style>
    /* Scoped styling for the import wizard */
    #modal-upload .imp-steps { display: flex; align-items: center; gap: 6px; font-size: 10.5px; }
    #modal-upload .imp-step {
        display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px;
        border-radius: 20px; background: #f1f0f6; color: #8b86a0; font-weight: 600;
        text-transform: uppercase; letter-spacing: .4px; white-space: nowrap;
    }
    #modal-upload .imp-step .imp-step-num {
        width: 15px; height: 15px; border-radius: 50%; background: #d9d5e6; color: #fff;
        display: inline-flex; align-items: center; justify-content: center; font-size: 9.5px;
    }
    #modal-upload .imp-step.active { background: #efe9fb; color: #673bb6; }
    #modal-upload .imp-step.active .imp-step-num { background: #673bb6; }
    #modal-upload .imp-step-arrow { color: #c6c1d6; font-size: 13px; }

    #modal-upload .imp-dropzone {
        position: relative; border: 2px dashed #cabede; border-radius: 10px;
        background: #faf9fd; padding: 28px 16px; text-align: center; transition: all .15s ease;
    }
    #modal-upload .imp-dropzone:hover, #modal-upload .imp-dropzone.dragover {
        border-color: #673bb6; background: #f4f0fc;
    }
    #modal-upload .imp-dropzone input[type=file] {
        position: absolute; inset: 0; width: 100%; height: 100%;
        opacity: 0; cursor: pointer;
    }
    #modal-upload .imp-drop-icon {
        width: 46px; height: 46px; margin: 0 auto 8px; border-radius: 12px;
        background: #efe9fb; color: #673bb6; font-size: 24px;
        display: flex; align-items: center; justify-content: center;
    }
    #modal-upload .imp-file-chip {
        display: none; align-items: center; gap: 8px; margin-top: 12px;
        padding: 7px 12px; border: 1px solid #e2ddf0; border-radius: 8px;
        background: #fff; font-size: 12px; text-align: left;
    }
    #modal-upload .imp-file-chip.show { display: inline-flex; }

    #modal-upload .imp-stats { display: flex; gap: 8px; flex-wrap: wrap; }
    #modal-upload .imp-stat {
        flex: 1 1 0; min-width: 92px; border: 1px solid #eceaf3; border-radius: 8px;
        background: #fff; padding: 8px 12px;
    }
    #modal-upload .imp-stat .v { font-size: 18px; font-weight: 700; line-height: 1.1; }
    #modal-upload .imp-stat .l {
        font-size: 10px; text-transform: uppercase; letter-spacing: .5px;
        color: #8b86a0; font-weight: 600;
    }
    #modal-upload .imp-stat.s-new   .v { color: #0ab39c; }
    #modal-upload .imp-stat.s-upd   .v { color: #299cdb; }
    #modal-upload .imp-stat.s-skip  .v { color: #f06548; }
    #modal-upload .imp-stat.s-warn  .v { color: #f7b84b; }

    #modal-upload .imp-table-wrap {
        max-height: 52vh; overflow: auto; border: 1px solid #eceaf3; border-radius: 8px;
    }
    #modal-upload .imp-table { margin: 0; font-size: 12px; }
    #modal-upload .imp-table thead th {
        position: sticky; top: 0; z-index: 2; background: #2a2438; color: #fff;
        font-size: 10px; text-transform: uppercase; letter-spacing: .5px;
        border-color: #3b3450; padding: 8px 10px; white-space: nowrap;
    }
    #modal-upload .imp-table tbody td { padding: 7px 10px; vertical-align: top; }
    #modal-upload .imp-table tbody tr.row-warn { background: #fffbf0; }
    #modal-upload .imp-table tbody tr.row-skip { background: #fdf2f0; }
    #modal-upload .imp-badge {
        display: inline-block; padding: 2px 8px; border-radius: 12px;
        font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
    }
    #modal-upload .imp-badge.b-new  { background: #e6f8f5; color: #0ab39c; }
    #modal-upload .imp-badge.b-upd  { background: #e8f4fb; color: #299cdb; }
    #modal-upload .imp-badge.b-skip { background: #fdeae6; color: #f06548; }
    #modal-upload .imp-issues { margin: 4px 0 0; padding-left: 14px; color: #b8860b; font-size: 11px; }
    #modal-upload .imp-issues li { margin-top: 1px; }
    #modal-upload .imp-sub { color: #8b86a0; font-size: 10.5px; }
    #modal-upload .imp-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>
<div class="modal fade" id="modal-upload" tabindex="-1" role="dialog" data-bs-backdrop="static">
    <form id="uploadForm" novalidate>
        <div class="modal-dialog modal-dialog-centered" role="document" id="import-dialog">
            <div class="modal-content">
                <div class="modal-header align-items-center">
                    <h6 class="modal-title mb-0 flex-grow-1">
                        <i class="ri-file-excel-2-line me-2" style="color:#673bb6;"></i>Import Employees
                    </h6>
                    <div class="imp-steps me-3">
                        <span class="imp-step active" data-step="1"><span class="imp-step-num">1</span>Upload</span>
                        <i class="ri-arrow-right-s-line imp-step-arrow"></i>
                        <span class="imp-step" data-step="2"><span class="imp-step-num">2</span>Review</span>
                        <i class="ri-arrow-right-s-line imp-step-arrow"></i>
                        <span class="imp-step" data-step="3"><span class="imp-step-num">3</span>Import</span>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <!-- STEP 1 — choose file -->
                <div class="modal-body" id="import-step-upload">
                    <div class="imp-dropzone" id="import-dropzone">
                        <input type="file" name="excelFile" id="excelFile" accept=".xlsx,.xls,.csv"
                            data-parsley-errors-container="#excelFile-errors"
                            data-parsley-required-message="Please select a file." required>
                        <div class="imp-drop-icon"><i class="ri-upload-cloud-2-line"></i></div>
                        <div class="fw-semibold" style="font-size:13px;">Drag &amp; drop your file here, or <span style="color:#673bb6;text-decoration:underline;">browse</span></div>
                        <div class="imp-sub mt-1">Accepted formats: .xlsx, .xls, .csv</div>
                        <div class="imp-file-chip" id="import-file-chip">
                            <i class="ri-file-excel-2-line" style="color:#0ab39c;font-size:16px;"></i>
                            <span id="import-file-name" class="fw-semibold text-truncate" style="max-width:260px;"></span>
                            <span id="import-file-size" class="imp-sub"></span>
                        </div>
                    </div>
                    <div id="excelFile-errors" class="mt-1"></div>
                    <div class="mt-3 p-2" style="border:1px dashed #cabede;border-radius:8px;background:#f4f3f8;">
                        <div style="font-size:11px;color:#57339d;" class="mb-2">
                            <i class="ri-lightbulb-line me-1"></i>New to importing? Start from the template — its column order
                            must be kept, and the <b>Notes</b> sheet explains every field.
                        </div>
                        <a href="export-employee-template.php" class="btn btn-sm w-100"
                           style="background:#fff;border:1px solid #673bb6;color:#673bb6;font-weight:600;font-size:11px;">
                            <i class="ri-download-2-line me-1"></i>Download Import Template (.xlsx)
                        </a>
                    </div>
                </div>

                <!-- STEP 2 — preview -->
                <div class="modal-body d-none" id="import-step-preview">
                    <div class="imp-stats mb-3" id="import-stats"></div>
                    <div class="imp-table-wrap">
                        <table class="table table-hover imp-table">
                            <thead>
                                <tr>
                                    <th style="width:44px;">Row</th>
                                    <th style="width:74px;">Action</th>
                                    <th>Employee</th>
                                    <th>Position</th>
                                    <th>Classification</th>
                                    <th class="imp-num">Daily Rate</th>
                                    <th class="imp-num">Basic Pay</th>
                                    <th>Rate Type</th>
                                    <th class="imp-num">SSS / PHIC / HDMF</th>
                                    <th>Shift</th>
                                    <th>Deduction</th>
                                </tr>
                            </thead>
                            <tbody id="import-preview-body"></tbody>
                        </table>
                    </div>
                    <div class="imp-sub mt-2" id="import-truncated-note" style="display:none;">
                        <i class="ri-information-line me-1"></i>Showing the first 500 rows only — the summary counts above cover the whole file.
                    </div>
                </div>

                <div class="modal-footer" style="background:#f8f9fa;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="ri-close-line me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="import-back-btn">
                        <i class="ri-arrow-left-line me-1"></i>Back
                    </button>
                    <button type="submit" class="btn btn-sm text-white submitbutton" id="import-preview-btn"
                        style="background:#673bb6;border-color:#673bb6;">
                        <i class="fa fa-spinner fa-spin fa-spinner-button"></i>
                        <i class="ri-search-eye-line me-1"></i>Preview Import
                    </button>
                    <button type="button" class="btn btn-sm btn-success d-none" id="import-confirm-btn">
                        <i class="ri-check-double-line me-1"></i>Confirm Import
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>
