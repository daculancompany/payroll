<?php
// "My Profile" — the signed-in user's own name and password. Saves through
// ajax.php?action=save_profile (mapped to this page, so every role that can
// open it can save), never through save_user, which is the admin's
// user-management write and is closed to approver roles.
$emp = $conn->query("SELECT u.name, u.username, u.role, u.employee_id, u.department_id,
                            d.name AS dept_name,
                            CONCAT(e.firstname, ' ', e.lastname) AS emp_name, e.employee_no
                     FROM users u
                     LEFT JOIN department d ON d.id = u.department_id
                     LEFT JOIN employee   e ON e.id = u.employee_id
                     WHERE u.id = " . (int) $_SESSION['login_id'])->fetch_assoc() ?: [];
$profile_name     = (string) ($emp['name'] ?? '');
$profile_username = (string) ($emp['username'] ?? '');
$profile_role     = (int) ($emp['role'] ?? 0);
$role_names = [1 => 'Administrator', 5 => 'Timekeeper', 7 => 'Auditor', 8 => 'Department Head', 9 => 'HR', 10 => 'Supervisor', 11 => 'Section/Unit Head'];
$profile_role_lbl = $role_names[$profile_role] ?? 'Staff';
// Initials for the avatar: first letters of the first two words of the name.
$__w = preg_split('/[\s,]+/', trim($profile_name)) ?: [];
$profile_initials = strtoupper(mb_substr($__w[0] ?? '', 0, 1) . mb_substr($__w[1] ?? '', 0, 1)) ?: '?';
// Areas this account approves for (approver roles only) — shown so a head can
// see at a glance which wards their bell and queue cover.
$profile_areas = [];
if (in_array($profile_role, [8, 10, 11], true)) {
    $aq = $conn->query("SELECT DISTINCT a.name FROM area_approver ap INNER JOIN area a ON a.id = ap.area_id
                        WHERE ap.user_id = " . (int) $_SESSION['login_id'] . " ORDER BY a.name");
    if ($aq) while ($ar = $aq->fetch_assoc()) $profile_areas[] = $ar['name'];
}
?>
<style>
    .pf-wrap { max-width: 860px; }
    .pf-hero { background: linear-gradient(135deg, #673bb6, #4e3483); border-radius: 12px; padding: 22px 24px; color: #fff;
               display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
    .pf-avatar { width: 64px; height: 64px; border-radius: 50%; background: rgba(255,255,255,.18); border: 2px solid rgba(255,255,255,.55);
                 display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 800; letter-spacing: .5px; flex-shrink: 0; }
    .pf-hero-name { font-size: 18px; font-weight: 800; line-height: 1.2; }
    .pf-hero-sub  { font-size: 12px; opacity: .85; margin-top: 3px; }
    .pf-chip { display: inline-block; background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.35); border-radius: 20px;
               padding: 2px 10px; font-size: 11px; font-weight: 700; margin: 6px 6px 0 0; }
    .pf-card { border: 1px solid #e8eaf6; border-radius: 12px; background: #fff; box-shadow: 0 2px 10px rgba(58,40,93,.06); }
    .pf-card-hd { padding: 12px 18px; border-bottom: 1px solid #eef0f7; display: flex; align-items: center; gap: 8px; }
    .pf-card-hd i { color: #673bb6; font-size: 16px; }
    .pf-card-hd b { font-size: 12.5px; text-transform: uppercase; letter-spacing: .5px; color: #4e3483; }
    .pf-card-hd small { margin-left: auto; color: #8a8a99; font-size: 11px; }
    .pf-card-bd { padding: 18px; }
    .pf-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: #673bb6; margin-bottom: 5px; display: block; }
    .pf-help { font-size: 11px; color: #8a8a99; margin-top: 5px; }
    .pf-ro .form-control[readonly] { background: #f6f5fb; color: #6b6b7a; }
    .pf-input-group .input-group-text { background: #f6f5fb; border-color: #dfe1ec; color: #673bb6; }
    .pf-eye { cursor: pointer; }
    .pf-strength { height: 5px; border-radius: 3px; background: #ececf3; margin-top: 8px; overflow: hidden; }
    .pf-strength > span { display: block; height: 100%; width: 0; border-radius: 3px; transition: width .2s, background .2s; }
    .pf-rules { list-style: none; padding: 0; margin: 8px 0 0; display: flex; flex-wrap: wrap; gap: 6px 14px; font-size: 11px; color: #8a8a99; }
    .pf-rules li i { margin-right: 3px; }
    .pf-rules li.ok { color: #1b8a3e; }
    .pf-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 18px; border-top: 1px solid #eef0f7; background: #fafafe; border-radius: 0 0 12px 12px; }
    .pf-save { background: #673bb6; border-color: #673bb6; color: #fff; font-weight: 600; padding: 8px 18px; }
    .pf-save:hover { background: #56309a; border-color: #56309a; color: #fff; }
    .parsley-errors-list { font-size: 11px; color: #d32f2f; margin: 4px 0 0; padding-left: 0; list-style: none; }
    .form-control.parsley-error { border-color: #e57373; }
</style>
<div class="main-content">
	<div class="page-content">
		<div class="container-fluid">
			<div class="row">
				<div class="col-12">
					<div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
						<h4 class="mb-sm-0">My Profile</h4>
						<div class="page-title-right">
							<ol class="breadcrumb m-0">
								<li class="breadcrumb-item"><a href="javascript: void(0);">Pages</a></li>
								<li class="breadcrumb-item active">Profile</li>
							</ol>
						</div>
					</div>
				</div>
			</div>

			<div class="pf-wrap">
				<div class="pf-hero mb-3">
					<div class="pf-avatar"><?= htmlspecialchars($profile_initials) ?></div>
					<div style="flex:1 1 220px;min-width:0;">
						<div class="pf-hero-name" id="pf-hero-name"><?= htmlspecialchars($profile_name) ?></div>
						<div class="pf-hero-sub"><i class="ri-at-line me-1"></i><?= htmlspecialchars($profile_username) ?></div>
						<div>
							<span class="pf-chip"><i class="ri-shield-user-line me-1"></i><?= htmlspecialchars($profile_role_lbl) ?></span>
							<?php if (!empty($emp['dept_name'])): ?>
								<span class="pf-chip"><i class="ri-building-2-line me-1"></i><?= htmlspecialchars($emp['dept_name']) ?></span>
							<?php endif; ?>
							<?php if (!empty($emp['emp_name'])): ?>
								<span class="pf-chip" title="Employee record linked to this account"><i class="ri-user-3-line me-1"></i><?= htmlspecialchars($emp['emp_name']) ?><?= !empty($emp['employee_no']) ? ' · ' . htmlspecialchars($emp['employee_no']) : '' ?></span>
							<?php endif; ?>
						</div>
						<?php if ($profile_areas): ?>
							<div class="pf-hero-sub" style="margin-top:8px;"><i class="ri-map-pin-2-line me-1"></i>Approves for: <?= htmlspecialchars(implode(', ', $profile_areas)) ?></div>
						<?php endif; ?>
					</div>
				</div>

				<form id="form-profile" method="post" novalidate autocomplete="off">
					<div class="pf-card mb-3">
						<div class="pf-card-hd"><i class="ri-user-settings-line"></i><b>Account</b><small>Shown in the header and on records you approve</small></div>
						<div class="pf-card-bd">
							<div class="row g-3">
								<div class="col-md-6">
									<label class="pf-label" for="pf-name">Display Name <span class="text-danger">*</span></label>
									<div class="input-group pf-input-group">
										<span class="input-group-text"><i class="ri-user-line"></i></span>
										<input type="text" id="pf-name" name="name" class="form-control" value="<?= htmlspecialchars($profile_name) ?>"
											placeholder="Your name" maxlength="100"
											data-parsley-required-message="Name is required."
											data-parsley-trigger="change" required>
									</div>
								</div>
								<div class="col-md-6 pf-ro">
									<label class="pf-label">Username</label>
									<div class="input-group pf-input-group">
										<span class="input-group-text"><i class="ri-at-line"></i></span>
										<input type="text" class="form-control" value="<?= htmlspecialchars($profile_username) ?>" readonly tabindex="-1">
									</div>
									<div class="pf-help"><i class="ri-lock-line me-1"></i>Your sign-in name is set by the administrator and cannot be changed here.</div>
								</div>
							</div>
						</div>
					</div>

					<div class="pf-card">
						<div class="pf-card-hd"><i class="ri-shield-keyhole-line"></i><b>Change Password</b><small>Leave both fields blank to keep your current password</small></div>
						<div class="pf-card-bd">
							<div class="row g-3">
								<div class="col-md-6">
									<label class="pf-label" for="pf-password">New Password</label>
									<div class="input-group pf-input-group">
										<span class="input-group-text"><i class="ri-key-2-line"></i></span>
										<input type="password" id="pf-password" name="password" class="form-control" placeholder="At least 8 characters" autocomplete="new-password"
											data-parsley-minlength="8" data-parsley-minlength-message="Password must be at least 8 characters."
											data-parsley-trigger="input">
										<span class="input-group-text pf-eye" data-target="pf-password" title="Show / hide"><i class="ri-eye-line"></i></span>
									</div>
									<div class="pf-strength"><span id="pf-strength-bar"></span></div>
									<ul class="pf-rules" id="pf-rules">
										<li data-rule="len"><i class="ri-checkbox-blank-circle-line"></i>8+ characters</li>
										<li data-rule="upper"><i class="ri-checkbox-blank-circle-line"></i>Uppercase letter</li>
										<li data-rule="num"><i class="ri-checkbox-blank-circle-line"></i>Number</li>
										<li data-rule="sym"><i class="ri-checkbox-blank-circle-line"></i>Symbol</li>
									</ul>
								</div>
								<div class="col-md-6">
									<label class="pf-label" for="pf-password2">Confirm New Password</label>
									<div class="input-group pf-input-group">
										<span class="input-group-text"><i class="ri-key-2-line"></i></span>
										<input type="password" id="pf-password2" name="password_confirm" class="form-control" placeholder="Repeat the new password" autocomplete="new-password"
											data-parsley-equalto="#pf-password" data-parsley-equalto-message="The two passwords do not match."
											data-parsley-trigger="input">
										<span class="input-group-text pf-eye" data-target="pf-password2" title="Show / hide"><i class="ri-eye-line"></i></span>
									</div>
									<div class="pf-help"><i class="ri-information-line me-1"></i>After saving, use the new password the next time you sign in.</div>
								</div>
							</div>
						</div>
						<div class="pf-footer">
							<button type="reset" class="btn btn-sm btn-outline-secondary" id="pf-reset"><i class="ri-arrow-go-back-line me-1"></i>Reset</button>
							<button type="submit" class="btn btn-sm pf-save submitbutton"><i class="ri-save-line me-1"></i>Save Changes</button>
						</div>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>
<script>
// index.php loads jQuery / Parsley / Swal AFTER the page content, so wait for
// DOMContentLoaded (fires once those bottom scripts have run) before binding.
document.addEventListener('DOMContentLoaded', function () {
	var form = $('#form-profile');

	// Show / hide either password field.
	$('.pf-eye').on('click', function () {
		var inp = document.getElementById(this.getAttribute('data-target'));
		var show = inp.type === 'password';
		inp.type = show ? 'text' : 'password';
		$(this).find('i').attr('class', show ? 'ri-eye-off-line' : 'ri-eye-line');
	});

	// Live strength meter + rule checklist (advisory; only the 8-char minimum
	// and the match are enforced, here by Parsley and again on the server).
	function scorePassword(v) {
		var r = { len: v.length >= 8, upper: /[A-Z]/.test(v), num: /\d/.test(v), sym: /[^A-Za-z0-9]/.test(v) };
		var n = Object.keys(r).filter(function (k) { return r[k]; }).length;
		return { rules: r, score: v ? n : 0 };
	}
	$('#pf-password').on('input', function () {
		var s = scorePassword(this.value);
		$('#pf-rules li').each(function () {
			var ok = s.rules[this.getAttribute('data-rule')];
			$(this).toggleClass('ok', !!ok).find('i').attr('class', ok ? 'ri-checkbox-circle-fill' : 'ri-checkbox-blank-circle-line');
		});
		var bar = document.getElementById('pf-strength-bar');
		bar.style.width = (s.score * 25) + '%';
		bar.style.background = ['#ececf3', '#e57373', '#f0ad4e', '#8bc34a', '#1b8a3e'][s.score];
		// Re-check the confirm box as the first box changes.
		if ($('#pf-password2').val()) $('#pf-password2').parsley().validate();
	});

	$('#pf-reset').on('click', function () {
		setTimeout(function () {
			form.parsley().reset();
			$('#pf-password').trigger('input');
		}, 0);
	});

	form.on('submit', function (e) {
		e.preventDefault();
		form.parsley().validate();
		if (!form.parsley().isValid()) return;

		var pw = $('#pf-password').val(), pw2 = $('#pf-password2').val();
		// Typed a confirmation but no password: Parsley's equalto passes on the
		// blank first box, so say it plainly here.
		if (!pw && pw2) {
			Swal.fire({ icon: 'warning', title: 'Enter the new password', text: 'Type the new password in both boxes, or clear both to keep your current one.' });
			return;
		}

		var btn = form.find('.submitbutton');
		btn.attr('disabled', true).html('<i class="fa fa-spinner fa-spin me-1"></i> Saving...');
		$.ajax({
			url: 'ajax.php?action=save_profile',   // csrf.js adds the token header
			method: 'POST',
			dataType: 'JSON',
			data: form.serialize(),
			error: function () {
				btn.removeAttr('disabled').html('<i class="ri-save-line me-1"></i>Save Changes');
				Swal.fire({ icon: 'error', title: 'Error!', text: 'Could not reach the server. Please try again.' });
			},
			success: function (res) {
				btn.removeAttr('disabled').html('<i class="ri-save-line me-1"></i>Save Changes');
				if (res && res.result) {
					$('#pf-password, #pf-password2').val('');
					$('#pf-password').trigger('input');
					$('#pf-hero-name').text($('#pf-name').val());
					Swal.fire({ icon: 'success', title: 'Saved', text: res.message, timer: 1800, showConfirmButton: false })
						.then(function () { window.location.reload(); });
				} else {
					Swal.fire({ icon: 'error', title: 'Not saved', text: (res && res.message) || 'Failed to save your profile.' });
				}
			}
		});
	});
});
</script>
