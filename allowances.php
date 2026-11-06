<?php include 'db_connect.php'; ?>
<?php /* Layout mirrors deductions.php / contributions.php. This page used to carry
         the pre-reskin template markup with no .main-content wrapper, so it rendered
         underneath the fixed sidebar instead of beside it. */ ?>
<div class="main-content">
	<div class="page-content">
		<div class="container-fluid">
			<div class="row">
				<div class="col-12">
					<div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
						<h4 class="mb-sm-0">Allowances</h4>
						<div class="page-title-right">
							<ol class="breadcrumb m-0">
								<li class="breadcrumb-item"><a href="javascript: void(0);">Pages</a></li>
								<li class="breadcrumb-item active">Allowances</li>
							</ol>
						</div>
					</div>
				</div>
				<div class="card">
					<div class="card-header align-items-center d-flex">
						<h4 class="card-title mb-0 flex-grow-1">Allowance List</h4>
						<button type="button" class="btn btn-success add-btn" data-bs-toggle="modal" id="create-btn" data-bs-target="#modal"><i class="ri-add-line align-bottom me-1"></i> Create Allowance</button>
					</div>
					<div class="card-body">
						<p class="text-muted mb-2" style="font-size:12px;">
							Allowance types available to every employee. Assign one to a person on their
							<b>Employee &rarr; Allowances</b> tab, then tick it in that payroll run&rsquo;s
							<b>Payroll Settings</b> so it is applied on Calculate.
						</p>
						<div class="table-responsive mt-3 mb-1">
							<table id="data-table" class="table table-bordered dt-responsive nowrap table-striped align-middle">
								<thead class="table-light">
									<tr>
										<th>Allowance</th>
										<th class="text-center" style="width:100px;">Action</th>
									</tr>
								</thead>
								<tbody>
								<?php
									$query = $conn->query("SELECT * FROM allowances order by allowance asc");
									while ($row = $query->fetch_assoc()):
								?>
									<tr>
										<td><?php echo htmlspecialchars($row['allowance']) ?></td>
										<td class="text-center">
											<button type="button" class="btn btn-sm btn-outline-secondary" title="Edit Allowance"
												id="<?= (int) $row['id'] ?>"
												name="<?= htmlspecialchars($row['allowance'], ENT_QUOTES) ?>"
												onclick="edit_function(this)">Edit</button>
										</td>
									</tr>
								<?php endwhile; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
<?php include 'component/add_allowances.php'; ?>
