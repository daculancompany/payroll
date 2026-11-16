<?php
/**
 * The employee's own 201 file, inside the portal's "My Information" tab.
 *
 * The counterpart of employee-201.php: that is HR's view of a person, this is
 * the person's view of themselves. It deliberately lives in the EXISTING
 * "My Information" tab rather than a new one — an employee looking for their
 * address or emergency contact goes to "My Information", and a second tab
 * holding the other half of the same answer would only split it.
 *
 * What they may change is decided server-side by
 * HrManagement::SELF_EDITABLE, not here: this file renders the same list, so
 * the form and the endpoint cannot drift apart. Employment, pay, HMO and PRC
 * are shown read-only — they are HR's record of the employment, not the
 * employee's description of themselves.
 *
 * Expects: $conn, $emp_id, $emp (the employee row), $initials.
 */

require_once __DIR__ . '/../hr_class.php';

$phr      = new HrManagement($conn);
$phr_has  = $phr->tableExists('employee_profile');
$phr_p    = $phr_has ? $phr->profileRow($emp_id) : [];
$phr_deps = $phr_has ? $phr->dependents($emp_id) : [];
$phr_map  = HrManagement::profileFieldMap();
$phr_photo = !empty($phr_p['photo']);

if (!function_exists('phr_val')) {
    /** A stored value for display, or an em dash. */
    function phr_val($profile, $col, $fmt = null)
    {
        $v = $profile[$col] ?? '';
        if ($v === '' || $v === null) return '<span style="color:#ccc;">Not set</span>';
        if ($fmt === 'date') return htmlspecialchars(date('F d, Y', strtotime($v)));
        return htmlspecialchars((string) $v);
    }

    /** One read-only cell. */
    function phr_item($label, $html)
    {
        echo '<div class="info-item"><div class="info-lbl">' . htmlspecialchars($label) . '</div>'
           . '<div class="info-val">' . $html . '</div></div>';
    }
}
?>

<?php if (!$phr_has): ?>
    <!-- The 201 tables have not been applied on this server yet. Say nothing
         to the employee: it is not their problem and not their fix. -->
<?php else: ?>

<style>
.phr-edit-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:10px 16px;background:#faf9fc;border-bottom:1px solid #f2f1f5;}
.phr-note{font-size:11.5px;color:#908c9c;}
.phr-form{padding:12px 16px 16px;display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;}
.phr-form .wide{grid-column:1/-1;}
.phr-form label{display:block;font-size:10px;color:#aaa;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}
.phr-form input,.phr-form select,.phr-form textarea{width:100%;font-size:13px;padding:8px 10px;border:1px solid #e3dded;border-radius:9px;background:#fff;color:#222;}
.phr-form input:focus,.phr-form select:focus,.phr-form textarea:focus{outline:none;border-color:#6642aa;box-shadow:0 0 0 3px rgba(102,66,170,.14);}
.phr-actions{grid-column:1/-1;display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.phr-btn{background:#6642aa;color:#fff;border:0;border-radius:9px;padding:9px 16px;font-size:13px;font-weight:700;cursor:pointer;}
.phr-btn.ghost{background:#fff;color:#6642aa;border:1px solid #d9cff0;}
.phr-photo{display:flex;align-items:center;gap:14px;padding:12px 16px;flex-wrap:wrap;}
.phr-ava{width:76px;height:76px;border-radius:50%;object-fit:cover;border:2px solid #e7e3ef;background:#f3f2f5;display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:800;color:#6642aa;flex-shrink:0;}
</style>

<!-- ── My photo ─────────────────────────────────────────────────────────── -->
<div class="info-section">
    <div class="info-sec-title"><i class="ri-camera-line"></i> My Photo</div>
    <div class="phr-photo">
        <?php if ($my_face): ?>
            <img src="<?= htmlspecialchars($my_face) ?>" alt="" class="phr-ava"
                 onerror="this.replaceWith(Object.assign(document.createElement('div'),{className:'phr-ava',textContent:this.dataset.init}))"
                 data-init="<?= htmlspecialchars($initials) ?>">
        <?php else: ?>
            <div class="phr-ava"><?= htmlspecialchars($initials) ?></div>
        <?php endif; ?>
        <div style="flex:1;min-width:200px;">
            <div class="phr-note" style="margin-bottom:8px;">
                This is the picture shown on your record. JPEG, PNG or WebP, up to 4 MB.
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <label class="phr-btn" style="display:inline-block;">
                    <i class="ri-upload-2-line"></i> <?= $phr_photo ? 'Change photo' : 'Upload photo' ?>
                    <input type="file" id="phr-photo" accept="image/jpeg,image/png,image/webp" hidden>
                </label>
                <?php if ($phr_photo): ?>
                <button type="button" class="phr-btn ghost" onclick="phrDeletePhoto()"><i class="ri-delete-bin-line"></i> Remove</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── What the employee may change ─────────────────────────────────────── -->
<div class="info-section" id="sec-mydetails">
    <div class="info-sec-title"><i class="ri-edit-2-line"></i> My Contact &amp; Emergency Details</div>
    <div class="phr-edit-bar">
        <span class="phr-note"><i class="ri-information-line"></i> Keep these current — HR uses them to reach you, and your emergency contact in an emergency.</span>
        <button type="button" class="phr-btn ghost" id="phr-toggle" onclick="phrToggle()"><i class="ri-pencil-line"></i> Edit</button>
    </div>

    <!-- Read-only view -->
    <div class="info-grid" id="phr-view">
        <?php
        phr_item('Mobile No.', phr_val($phr_p, 'mobile_no'));
        phr_item('Telephone No.', phr_val($phr_p, 'telephone_no'));
        phr_item('Personal Email', phr_val($phr_p, 'personal_email'));
        phr_item('Civil Status', phr_val($phr_p, 'civil_status'));
        phr_item('Blood Type', phr_val($phr_p, 'blood_type'));
        phr_item('Religion', phr_val($phr_p, 'religion'));
        phr_item('Present Address', phr_val($phr_p, 'present_address'));
        phr_item('Permanent Address', phr_val($phr_p, 'permanent_address'));
        phr_item('Allergies', phr_val($phr_p, 'allergies'));
        phr_item('Medical Conditions', phr_val($phr_p, 'medical_conditions'));
        phr_item('Emergency Contact', phr_val($phr_p, 'emergency_name'));
        phr_item('Relationship', phr_val($phr_p, 'emergency_relation'));
        phr_item('Emergency Contact No.', phr_val($phr_p, 'emergency_mobile'));
        phr_item('Emergency Address', phr_val($phr_p, 'emergency_address'));
        ?>
    </div>

    <!-- Edit form: exactly the SELF_EDITABLE columns, nothing more -->
    <form class="phr-form" id="phr-form" style="display:none;" onsubmit="return phrSave(event);">
        <div><label for="p_mobile_no">Mobile No.</label><input type="text" id="p_mobile_no" name="mobile_no" maxlength="30" value="<?= htmlspecialchars($phr_p['mobile_no'] ?? '') ?>"></div>
        <div><label for="p_telephone_no">Telephone No.</label><input type="text" id="p_telephone_no" name="telephone_no" maxlength="30" value="<?= htmlspecialchars($phr_p['telephone_no'] ?? '') ?>"></div>
        <div><label for="p_personal_email">Personal Email</label><input type="email" id="p_personal_email" name="personal_email" maxlength="100" value="<?= htmlspecialchars($phr_p['personal_email'] ?? '') ?>"></div>
        <div>
            <label for="p_civil_status">Civil Status</label>
            <select id="p_civil_status" name="civil_status">
                <option value="">—</option>
                <?php foreach ($phr_map['civil_status']['values'] as $v): ?>
                    <option value="<?= htmlspecialchars($v) ?>" <?= ($phr_p['civil_status'] ?? '') === $v ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="p_blood_type">Blood Type</label>
            <select id="p_blood_type" name="blood_type">
                <option value="">—</option>
                <?php foreach ($phr_map['blood_type']['values'] as $v): ?>
                    <option value="<?= htmlspecialchars($v) ?>" <?= ($phr_p['blood_type'] ?? '') === $v ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label for="p_religion">Religion</label><input type="text" id="p_religion" name="religion" maxlength="50" value="<?= htmlspecialchars($phr_p['religion'] ?? '') ?>"></div>
        <div class="wide"><label for="p_present_address">Present Address</label><textarea id="p_present_address" name="present_address" rows="2" maxlength="255"><?= htmlspecialchars($phr_p['present_address'] ?? '') ?></textarea></div>
        <div class="wide"><label for="p_permanent_address">Permanent Address</label><textarea id="p_permanent_address" name="permanent_address" rows="2" maxlength="255"><?= htmlspecialchars($phr_p['permanent_address'] ?? '') ?></textarea></div>
        <div><label for="p_allergies">Allergies</label><input type="text" id="p_allergies" name="allergies" maxlength="255" value="<?= htmlspecialchars($phr_p['allergies'] ?? '') ?>"></div>
        <div><label for="p_medical_conditions">Medical Conditions</label><input type="text" id="p_medical_conditions" name="medical_conditions" maxlength="255" value="<?= htmlspecialchars($phr_p['medical_conditions'] ?? '') ?>"></div>
        <div><label for="p_emergency_name">Emergency Contact</label><input type="text" id="p_emergency_name" name="emergency_name" maxlength="120" value="<?= htmlspecialchars($phr_p['emergency_name'] ?? '') ?>"></div>
        <div><label for="p_emergency_relation">Relationship</label><input type="text" id="p_emergency_relation" name="emergency_relation" maxlength="50" value="<?= htmlspecialchars($phr_p['emergency_relation'] ?? '') ?>"></div>
        <div><label for="p_emergency_mobile">Emergency Contact No.</label><input type="text" id="p_emergency_mobile" name="emergency_mobile" maxlength="30" value="<?= htmlspecialchars($phr_p['emergency_mobile'] ?? '') ?>"></div>
        <div class="wide"><label for="p_emergency_address">Emergency Address</label><textarea id="p_emergency_address" name="emergency_address" rows="2" maxlength="255"><?= htmlspecialchars($phr_p['emergency_address'] ?? '') ?></textarea></div>
        <div class="phr-actions">
            <button type="submit" class="phr-btn" id="phr-save"><i class="ri-save-3-line"></i> Save my details</button>
            <button type="button" class="phr-btn ghost" onclick="phrToggle()">Cancel</button>
            <span class="phr-note">Leave a box empty to clear it.</span>
        </div>
    </form>
</div>

<!-- ── HR's record of the employment: read-only ─────────────────────────── -->
<div class="info-section">
    <div class="info-sec-title"><i class="ri-briefcase-line"></i> Employment Record</div>
    <div class="info-grid">
        <?php
        phr_item('Date Hired', phr_val($phr_p, 'date_hired', 'date'));
        phr_item('Employment Status', phr_val($phr_p, 'employment_status'));
        phr_item('Regularization Date', phr_val($phr_p, 'regularization_date', 'date'));
        phr_item('Nationality', phr_val($phr_p, 'nationality'));
        phr_item('Birth Place', phr_val($phr_p, 'birth_place'));
        phr_item('Highest Education', phr_val($phr_p, 'highest_education'));
        phr_item('School', phr_val($phr_p, 'school_name'));
        phr_item('Course', phr_val($phr_p, 'course'));
        ?>
    </div>
    <div class="phr-note" style="padding:9px 16px;border-top:1px solid #f2f1f5;">
        <i class="ri-lock-line"></i> These are kept by HR. If something is wrong, tell them — they can correct it.
    </div>
</div>

<?php if (!empty($phr_p['hmo_provider']) || !empty($phr_p['prc_license_no'])): ?>
<div class="info-section">
    <div class="info-sec-title"><i class="ri-shield-cross-line"></i> HMO &amp; Licence</div>
    <div class="info-grid">
        <?php
        if (!empty($phr_p['hmo_provider'])) {
            phr_item('HMO Provider', phr_val($phr_p, 'hmo_provider'));
            phr_item('HMO Card No.', phr_val($phr_p, 'hmo_card_no'));
            phr_item('HMO Expiry', phr_val($phr_p, 'hmo_expiry_date', 'date'));
        }
        if (!empty($phr_p['prc_license_no'])) {
            phr_item('PRC Licence No.', phr_val($phr_p, 'prc_license_no'));
            phr_item('Profession', phr_val($phr_p, 'prc_profession'));
            phr_item('PRC Expiry', phr_val($phr_p, 'prc_expiry_date', 'date'));
        }
        ?>
    </div>
</div>
<?php endif; ?>

<?php if ($phr_deps): ?>
<div class="info-section">
    <div class="info-sec-title"><i class="ri-parent-line"></i> My Dependants &amp; Beneficiaries</div>
    <div class="info-grid">
        <?php foreach ($phr_deps as $d): ?>
            <?php phr_item(
                ($d['relationship'] ?: 'Dependant') . ((int) $d['is_beneficiary'] === 1 ? ' · Beneficiary' : ''),
                htmlspecialchars($d['name'])
                    . ($d['birthdate'] ? '<div class="phr-note">' . htmlspecialchars(date('M d, Y', strtotime($d['birthdate']))) . '</div>' : '')
            ); ?>
        <?php endforeach; ?>
    </div>
    <div class="phr-note" style="padding:9px 16px;border-top:1px solid #f2f1f5;">
        <i class="ri-lock-line"></i> Recorded by HR. Ask them to add or change anyone here.
    </div>
</div>
<?php endif; ?>

<script>
    function phrToggle() {
        const view = document.getElementById('phr-view');
        const form = document.getElementById('phr-form');
        const btn  = document.getElementById('phr-toggle');
        const editing = form.style.display !== 'none';
        form.style.display = editing ? 'none' : 'grid';
        view.style.display = editing ? 'grid' : 'none';
        btn.innerHTML = editing ? '<i class="ri-pencil-line"></i> Edit' : '<i class="ri-close-line"></i> Close';
    }

    async function phrPost(action, body) {
        const res = await fetch('hr-ajax.php?action=' + action, { method: 'POST', body: body });
        return res.json().catch(() => ({ result: false, message: 'The server sent an unreadable answer.' }));
    }

    function phrDone(j, okTitle) {
        if (!j.result) { Swal.fire({ icon: 'error', title: 'Not saved', text: j.message || 'Could not save.' }); return false; }
        Swal.fire({ icon: 'success', title: okTitle || 'Saved', text: j.message || '', timer: 1300, showConfirmButton: false })
            .then(() => location.reload());
        return true;
    }

    async function phrSave(ev) {
        ev.preventDefault();
        const btn = document.getElementById('phr-save');
        btn.disabled = true;
        // No employee_id is sent: the server takes it from the session.
        const j = await phrPost('my_save_profile', new FormData(document.getElementById('phr-form')));
        if (!phrDone(j, 'Details saved')) btn.disabled = false;
        return false;
    }

    document.getElementById('phr-photo')?.addEventListener('change', async function () {
        const file = this.files && this.files[0];
        if (!file) return;
        if (file.size > 4 * 1024 * 1024) {
            Swal.fire({ icon: 'error', title: 'Too large', text: 'That photo is over 4 MB. Please pick a smaller one.' });
            this.value = '';
            return;
        }
        const body = new FormData();
        body.append('photo', file);
        this.value = '';
        phrDone(await phrPost('my_save_photo', body), 'Photo updated');
    });

    async function phrDeletePhoto() {
        const c = await Swal.fire({
            icon: 'warning', title: 'Remove your photo?',
            showCancelButton: true, confirmButtonText: 'Remove', confirmButtonColor: '#e15b64', reverseButtons: true,
        });
        if (!c.isConfirmed) return;
        phrDone(await phrPost('my_delete_photo', new FormData()), 'Photo removed');
    }
</script>

<?php endif; ?>
