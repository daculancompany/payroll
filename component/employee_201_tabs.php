<?php
/**
 * The 201 file as two tab panes, rendered inside Employee Details.
 *
 * One page per person was the ask: the payroll view and the HR view of the
 * same employee now share one tab strip instead of living on two screens.
 *
 * ⚠️ TWO PERMISSIONS ON ONE PAGE. Employee Details is READ-ONLY for HR
 * (HR_READONLY_PAGES) because that screen is about PAY. The 201 file is HR's
 * own record, so these panes ask a different question — can_edit('employee-201'),
 * which is Admin + HR — and hr-ajax.php enforces exactly the same one. An HR
 * user therefore sees Save buttons here and none on the pay tabs. That is the
 * intent, not an inconsistency.
 *
 * Expects from the including page: $conn, $emp_id, $emp (the employee row) and
 * $e201_init (initials). Everything else is derived here, so the component can
 * be dropped into any page that has those.
 */

require_once __DIR__ . '/../hr_class.php';

$hr         = new HrManagement($conn);
$e201_id    = (int) $emp_id;
$e201_can   = can_edit('employee-201');
$e201_has   = $hr->tableExists('employee_profile');
$profile    = $hr->profileRow($e201_id);
$dependents = $hr->dependents($e201_id);
$MAP        = HrManagement::profileFieldMap();

if (!function_exists('e201')) {
    function e201($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }

    /** One labelled input bound to a 201 column. */
    function e201_field($col, $label, $profile, $can, $type = 'text', $opts = [])
    {
        $val  = $profile[$col] ?? '';
        $wide = !empty($opts['wide']);
        echo '<div class="col-md-' . ($wide ? '12' : '4') . ' mb-3">';
        echo '<label class="form-label e201-lbl" for="f_' . e201($col) . '">' . e201($label) . '</label>';

        if ($type === 'select') {
            echo '<select class="form-control" id="f_' . e201($col) . '" name="' . e201($col) . '"'
               . ($can ? '' : ' disabled') . '>';
            echo '<option value="">—</option>';
            foreach ($opts['values'] as $v) {
                echo '<option value="' . e201($v) . '"' . ((string) $val === (string) $v ? ' selected' : '') . '>' . e201($v) . '</option>';
            }
            echo '</select>';
        } elseif ($type === 'textarea') {
            echo '<textarea class="form-control" id="f_' . e201($col) . '" name="' . e201($col) . '" rows="2"'
               . ($can ? '' : ' readonly') . '>' . e201($val) . '</textarea>';
        } else {
            echo '<input type="' . e201($type) . '" class="form-control" id="f_' . e201($col) . '"'
               . ' name="' . e201($col) . '" value="' . e201($val) . '"'
               . (isset($opts['max']) ? ' maxlength="' . (int) $opts['max'] . '"' : '')
               . ($type === 'number' ? ' step="0.01" min="0"' : '')
               . ($can ? '' : ' readonly') . '>';
        }
        echo '</div>';
    }
}
?>

<style>
.e201-head{display:flex;gap:16px;align-items:center;flex-wrap:wrap;}
.e201-ava{width:92px;height:92px;border-radius:14px;object-fit:cover;border:2px solid var(--v2-border,#e4e1ec);background:var(--v2-surface-2,#f3f2f5);display:flex;align-items:center;justify-content:center;font-size:30px;font-weight:800;color:var(--app-primary,#673bb6);flex-shrink:0;}
.e201-name{font-size:18px;font-weight:800;color:var(--v2-text,#2b2639);line-height:1.2;}
.e201-meta{font-size:12.5px;color:var(--v2-text-2,#6f6a80);margin-top:3px;}
.e201-lbl{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--v2-text-3,#918d9c);font-weight:700;margin-bottom:3px;}
.e201-sec{font-size:13px;font-weight:800;color:var(--app-primary,#673bb6);margin:4px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--v2-border,#ece9f3);}
.e201-empty{font-size:12.5px;color:var(--v2-text-2,#908c9c);padding:10px 0;}
.e201-chip{display:inline-block;font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:9px;text-transform:uppercase;letter-spacing:.3px;background:rgba(16,185,129,.14);color:#0f9d58;}
.e201-photo-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;}
</style>
                <!-- ── PROFILE ─────────────────────────────────────────── -->
                <div class="tab-pane" id="arrow-201" role="tabpanel">
                    <form id="e201-form" onsubmit="return e201Save(event);">
                        <input type="hidden" name="employee_id" value="<?= (int) $e201_id ?>">

                        <div class="e201-sec"><i class="ri-user-line me-1"></i>Personal</div>
                        <div class="row">
                            <?php
                            e201_field('sex', 'Sex', $profile, $e201_can, 'select', ['values' => $MAP['sex']['values']]);
                            e201_field('civil_status', 'Civil status', $profile, $e201_can, 'select', ['values' => $MAP['civil_status']['values']]);
                            e201_field('blood_type', 'Blood type', $profile, $e201_can, 'select', ['values' => $MAP['blood_type']['values']]);
                            e201_field('nationality', 'Nationality', $profile, $e201_can, 'text', ['max' => 50]);
                            e201_field('religion', 'Religion', $profile, $e201_can, 'text', ['max' => 50]);
                            e201_field('birth_place', 'Birth place', $profile, $e201_can, 'text', ['max' => 150]);
                            e201_field('height_cm', 'Height (cm)', $profile, $e201_can, 'number');
                            e201_field('weight_kg', 'Weight (kg)', $profile, $e201_can, 'number');
                            ?>
                            <div class="col-md-4 mb-3">
                                <label class="form-label e201-lbl">Birthdate (payroll record)</label>
                                <input type="text" class="form-control" value="<?= $emp['bday'] ? e201(date('F d, Y', strtotime($emp['bday']))) : '—' ?>" readonly>
                            </div>
                            <?php
                            e201_field('allergies', 'Allergies', $profile, $e201_can, 'text', ['max' => 255, 'wide' => false]);
                            e201_field('medical_conditions', 'Medical conditions', $profile, $e201_can, 'text', ['max' => 255]);
                            ?>
                        </div>

                        <div class="e201-sec"><i class="ri-phone-line me-1"></i>Contact</div>
                        <div class="row">
                            <?php
                            e201_field('mobile_no', 'Mobile no.', $profile, $e201_can, 'text', ['max' => 30]);
                            e201_field('telephone_no', 'Telephone no.', $profile, $e201_can, 'text', ['max' => 30]);
                            e201_field('personal_email', 'Personal email', $profile, $e201_can, 'email', ['max' => 100]);
                            e201_field('present_address', 'Present address', $profile, $e201_can, 'textarea', ['wide' => true]);
                            e201_field('permanent_address', 'Permanent address', $profile, $e201_can, 'textarea', ['wide' => true]);
                            ?>
                        </div>

                        <div class="e201-sec"><i class="ri-alarm-warning-line me-1"></i>In case of emergency</div>
                        <div class="row">
                            <?php
                            e201_field('emergency_name', 'Contact person', $profile, $e201_can, 'text', ['max' => 120]);
                            e201_field('emergency_relation', 'Relationship', $profile, $e201_can, 'text', ['max' => 50]);
                            e201_field('emergency_mobile', 'Contact no.', $profile, $e201_can, 'text', ['max' => 30]);
                            e201_field('emergency_address', 'Address', $profile, $e201_can, 'textarea', ['wide' => true]);
                            ?>
                        </div>

                        <div class="e201-sec"><i class="ri-briefcase-line me-1"></i>Employment</div>
                        <div class="row">
                            <?php
                            e201_field('date_hired', 'Date hired', $profile, $e201_can, 'date');
                            e201_field('employment_status', 'Employment status', $profile, $e201_can, 'select', ['values' => $MAP['employment_status']['values']]);
                            e201_field('regularization_date', 'Regularization date', $profile, $e201_can, 'date');
                            e201_field('date_separated', 'Date separated', $profile, $e201_can, 'date');
                            e201_field('separation_reason', 'Separation reason', $profile, $e201_can, 'text', ['max' => 255]);
                            ?>
                        </div>

                        <div class="e201-sec"><i class="ri-shield-cross-line me-1"></i>HMO &amp; insurance</div>
                        <div class="row">
                            <?php
                            e201_field('hmo_provider', 'HMO provider', $profile, $e201_can, 'text', ['max' => 100]);
                            e201_field('hmo_card_no', 'HMO card no.', $profile, $e201_can, 'text', ['max' => 50]);
                            e201_field('hmo_effective_date', 'HMO effective', $profile, $e201_can, 'date');
                            e201_field('hmo_expiry_date', 'HMO expiry', $profile, $e201_can, 'date');
                            e201_field('life_insurance_provider', 'Life insurance provider', $profile, $e201_can, 'text', ['max' => 100]);
                            e201_field('life_insurance_policy_no', 'Policy no.', $profile, $e201_can, 'text', ['max' => 50]);
                            e201_field('insurance_beneficiary', 'Insurance beneficiary', $profile, $e201_can, 'text', ['max' => 150]);
                            ?>
                        </div>

                        <div class="e201-sec"><i class="ri-award-line me-1"></i>PRC licence</div>
                        <div class="row">
                            <?php
                            e201_field('prc_license_no', 'Licence no.', $profile, $e201_can, 'text', ['max' => 50]);
                            e201_field('prc_profession', 'Profession', $profile, $e201_can, 'text', ['max' => 80]);
                            e201_field('prc_issued_date', 'Date issued', $profile, $e201_can, 'date');
                            e201_field('prc_expiry_date', 'Expiry date', $profile, $e201_can, 'date');
                            ?>
                        </div>

                        <div class="e201-sec"><i class="ri-graduation-cap-line me-1"></i>Education</div>
                        <div class="row">
                            <?php
                            e201_field('highest_education', 'Highest education', $profile, $e201_can, 'text', ['max' => 60]);
                            e201_field('school_name', 'School', $profile, $e201_can, 'text', ['max' => 150]);
                            e201_field('course', 'Course', $profile, $e201_can, 'text', ['max' => 150]);
                            e201_field('year_graduated', 'Year graduated', $profile, $e201_can, 'text', ['max' => 9]);
                            ?>
                        </div>

                        <?php if ($e201_can): ?>
                        <div class="d-flex align-items-center gap-2 mt-2">
                            <button type="submit" class="btn btn-primary" id="e201-save"><i class="ri-save-3-line me-1"></i>Save 201 information</button>
                            <?php if (!empty($profile['updated_at'])): ?>
                            <span class="e201-empty">Last saved <?= e201(date('M d, Y g:i A', strtotime($profile['updated_at']))) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- ── DEPENDANTS ──────────────────────────────────────── -->
                <div class="tab-pane" id="arrow-201deps" role="tabpanel">
                    <?php if ($e201_can): ?>
                    <button class="btn btn-primary btn-sm mb-3" onclick="e201Dependent()"><i class="ri-add-line me-1"></i>Add dependant</button>
                    <?php endif; ?>

                    <?php if (!$dependents): ?>
                        <div class="e201-empty"><i class="ri-information-line me-1"></i>No dependants or beneficiaries recorded.</div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light"><tr>
                                <th>Name</th><th style="width:150px;">Relationship</th>
                                <th style="width:150px;">Birthdate</th><th style="width:90px;">Age</th>
                                <th style="width:120px;">Beneficiary</th><th>Remarks</th>
                                <?php if ($e201_can): ?><th style="width:100px;"></th><?php endif; ?>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($dependents as $d): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e201($d['name']) ?></td>
                                    <td><?= e201($d['relationship'] ?: '—') ?></td>
                                    <td><?= $d['birthdate'] ? e201(date('M d, Y', strtotime($d['birthdate']))) : '—' ?></td>
                                    <td><?= $d['birthdate'] ? (int) ((time() - strtotime($d['birthdate'])) / 31556952) : '—' ?></td>
                                    <td><?= (int) $d['is_beneficiary'] === 1 ? '<span class="e201-chip">Beneficiary</span>' : '—' ?></td>
                                    <td class="e201-empty" style="padding-top:8px;"><?= e201($d['remarks'] ?: '') ?></td>
                                    <?php if ($e201_can): ?>
                                    <td class="text-end">
                                        <button class="btn btn-outline-secondary btn-sm py-0" onclick='e201Dependent(<?= json_encode($d, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="ri-edit-line"></i></button>
                                        <button class="btn btn-outline-danger btn-sm py-0" onclick="e201DeleteDependent(<?= (int) $d['id'] ?>, <?= json_encode($d['name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)"><i class="ri-delete-bin-line"></i></button>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>


<script>
    const E201_ID = <?= (int) $e201_id ?>;

    async function e201Post(action, body) {
        const res = await fetch('hr-ajax.php?action=' + action, { method: 'POST', body: body });
        return res.json().catch(() => ({ result: false, message: 'The server sent an unreadable answer.' }));
    }

    function e201Done(j, okTitle) {
        if (!j.result) { Swal.fire({ icon: 'error', title: 'Not saved', text: j.message || 'Could not save.' }); return false; }
        Swal.fire({ icon: 'success', title: okTitle || 'Saved', text: j.message || '', timer: 1300, showConfirmButton: false })
            .then(() => location.reload());
        return true;
    }

    async function e201Save(ev) {
        ev.preventDefault();
        const btn = document.getElementById('e201-save');
        btn.disabled = true;
        const j = await e201Post('save_profile', new FormData(document.getElementById('e201-form')));
        if (!e201Done(j)) btn.disabled = false;
        return false;
    }

    // ── Photo ────────────────────────────────────────────────────────────
    document.getElementById('e201-photo')?.addEventListener('change', async function () {
        const file = this.files && this.files[0];
        if (!file) return;
        if (file.size > 4 * 1024 * 1024) {
            Swal.fire({ icon: 'error', title: 'Too large', text: 'That photo is over 4 MB. Please pick a smaller one.' });
            this.value = '';
            return;
        }
        const body = new FormData();
        body.append('employee_id', E201_ID);
        body.append('photo', file);
        this.value = '';
        e201Done(await e201Post('save_photo', body), 'Photo updated');
    });

    async function e201DeletePhoto() {
        const c = await Swal.fire({
            icon: 'warning', title: 'Remove the photo?',
            text: 'The record falls back to the kiosk face, or to initials.',
            showCancelButton: true, confirmButtonText: 'Remove', confirmButtonColor: '#e15b64', reverseButtons: true,
        });
        if (!c.isConfirmed) return;
        const body = new FormData();
        body.append('employee_id', E201_ID);
        e201Done(await e201Post('delete_photo', body), 'Photo removed');
    }

    // ── Dependants ───────────────────────────────────────────────────────
    async function e201Dependent(row) {
        const d = row || {};
        const r = await Swal.fire({
            title: d.id ? 'Edit dependant' : 'Add dependant',
            html:
                '<input id="sw-name" class="swal2-input" placeholder="Full name" maxlength="120" value="' + (d.name || '').replace(/"/g, '&quot;') + '">' +
                '<input id="sw-rel" class="swal2-input" placeholder="Relationship (e.g. Spouse, Child)" maxlength="40" value="' + (d.relationship || '').replace(/"/g, '&quot;') + '">' +
                '<input id="sw-bday" type="date" class="swal2-input" value="' + (d.birthdate || '') + '">' +
                '<input id="sw-rem" class="swal2-input" placeholder="Remarks (optional)" maxlength="255" value="' + (d.remarks || '').replace(/"/g, '&quot;') + '">' +
                '<label style="display:flex;align-items:center;gap:8px;justify-content:center;margin-top:10px;font-size:14px;">' +
                '<input id="sw-ben" type="checkbox" ' + (Number(d.is_beneficiary) === 1 ? 'checked' : '') + '> Beneficiary</label>',
            showCancelButton: true,
            confirmButtonText: 'Save',
            focusConfirm: false,
            preConfirm: () => {
                const name = document.getElementById('sw-name').value.trim();
                if (!name) { Swal.showValidationMessage('Name is required.'); return false; }
                return {
                    name: name,
                    relationship: document.getElementById('sw-rel').value.trim(),
                    birthdate: document.getElementById('sw-bday').value,
                    remarks: document.getElementById('sw-rem').value.trim(),
                    is_beneficiary: document.getElementById('sw-ben').checked ? 1 : '',
                };
            },
        });
        if (!r.isConfirmed) return;

        const body = new FormData();
        body.append('employee_id', E201_ID);
        if (d.id) body.append('id', d.id);
        Object.keys(r.value).forEach(k => body.append(k, r.value[k]));
        e201Done(await e201Post('save_dependent', body));
    }

    async function e201DeleteDependent(id, name) {
        const c = await Swal.fire({
            icon: 'warning', title: 'Remove ' + name + '?',
            showCancelButton: true, confirmButtonText: 'Remove', confirmButtonColor: '#e15b64', reverseButtons: true,
        });
        if (!c.isConfirmed) return;
        const body = new FormData();
        body.append('employee_id', E201_ID);
        body.append('id', id);
        e201Done(await e201Post('delete_dependent', body), 'Removed');
    }
</script>
