<style>
    .action-buttons { display:flex; gap:4px; align-items:center; flex-wrap:nowrap; }
    .action-buttons .btn-sm { padding:3px 9px; font-size:11px; white-space:nowrap; }
    .payroll-ref { font-weight:700; color:#1976d2; font-family:'Segoe UI',monospace; letter-spacing:.3px; }
    .payroll-period { font-size:12px; color:#444; white-space:nowrap; }
    .type-badge { font-size:11px; }
    /* ── Summary stat cards ── */
    .pay-stat-icon { width:48px; height:48px; flex-shrink:0; }
    .pay-stat-sub { font-size:11px; display:block; line-height:1.5; }
    .pay-stat-sub .badge { font-size:10px; font-weight:600; padding:2px 6px; }
    #pay-sum-latest-net { letter-spacing:.2px; }
</style>
<div class="main-content">
	<div class="page-content">
		<div class="container-fluid">
			<!-- start page title -->
			<div class="row">
				<div class="col-12">
					<div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
						<h4 class="mb-sm-0">Payroll</h4>
						<div class="page-title-right">
							<ol class="breadcrumb m-0">
								<li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
								<li class="breadcrumb-item active">Payroll</li>
							</ol>
						</div>
					</div>
				</div>

				<!-- ── Payroll summary cards (values filled by payroll.js on every table refresh) ── -->
				<div class="col-xl-3 col-md-6">
					<div class="card card-animate">
						<div class="card-body d-flex align-items-center">
							<div class="rounded bg-primary-subtle d-flex align-items-center justify-content-center me-3 pay-stat-icon">
								<i class="ri-stack-line fs-22 text-primary"></i>
							</div>
							<div>
								<p class="text-muted mb-1">Payroll Batches</p>
								<h4 class="mb-0" id="pay-sum-total">—</h4>
								<small class="text-muted pay-stat-sub" id="pay-sum-total-sub">&nbsp;</small>
							</div>
						</div>
					</div>
				</div>
				<div class="col-xl-3 col-md-6">
					<div class="card card-animate">
						<div class="card-body d-flex align-items-center">
							<div class="rounded bg-warning-subtle d-flex align-items-center justify-content-center me-3 pay-stat-icon">
								<i class="ri-loader-4-line fs-22 text-warning"></i>
							</div>
							<div>
								<p class="text-muted mb-1">In Progress</p>
								<h4 class="mb-0" id="pay-sum-progress">—</h4>
								<small class="text-muted pay-stat-sub" id="pay-sum-progress-sub">&nbsp;</small>
							</div>
						</div>
					</div>
				</div>
				<div class="col-xl-3 col-md-6">
					<div class="card card-animate">
						<div class="card-body d-flex align-items-center">
							<div class="rounded bg-danger-subtle d-flex align-items-center justify-content-center me-3 pay-stat-icon">
								<i class="ri-lock-fill fs-22 text-danger"></i>
							</div>
							<div>
								<p class="text-muted mb-1">Locked</p>
								<h4 class="mb-0" id="pay-sum-locked">—</h4>
								<small class="text-muted pay-stat-sub">Finalized payroll runs</small>
							</div>
						</div>
					</div>
				</div>
				<div class="col-xl-3 col-md-6">
					<div class="card card-animate">
						<div class="card-body d-flex align-items-center">
							<div class="rounded bg-success-subtle d-flex align-items-center justify-content-center me-3 pay-stat-icon">
								<i class="ri-money-dollar-circle-line fs-22 text-success"></i>
							</div>
							<div>
								<p class="text-muted mb-1">Latest Net Pay</p>
								<h4 class="mb-0" id="pay-sum-latest-net">—</h4>
								<small class="text-muted pay-stat-sub" id="pay-sum-latest-sub">&nbsp;</small>
							</div>
						</div>
					</div>
				</div>

				<div class="card">
					<div class="card-header align-items-center d-flex">
						<h4 class="card-title mb-0 flex-grow-1">
							<i class="ri-money-dollar-circle-line me-2 text-success"></i>Payroll List
						</h4>
						<div class="flex-shrink-0 d-flex gap-2">
							<button type="button" class="btn btn-success add-btn" data-bs-toggle="modal" id="create-btn" data-bs-target="#modal">
								<i class="ri-add-circle-line align-bottom me-1"></i> Create Payroll
							</button>
						</div>
					</div>
					<div class="card-body">
						<div class="table-responsive mt-3 mb-1">
							<table id="table" class="table table-hover table-bordered align-middle">
								<thead class="table-dark">
									<tr>
										<th><i class="ri-hashtag me-1"></i>Payroll ID</th>
										<th><i class="ri-calendar-range-line me-1"></i>Period</th>
										<th><i class="ri-pulse-line me-1"></i>Status</th>
										<th class="text-center"><i class="ri-settings-3-line me-1"></i>Action</th>
									</tr>
								</thead>
								<tbody></tbody>
							</table>
						</div>
					</div>
				</div>
			</div>
			<!-- end page title -->
		</div>
		<!-- container-fluid -->
	</div>
	<!-- End Page-content -->
</div>
<?php include 'component/add_payroll.php'; ?>
