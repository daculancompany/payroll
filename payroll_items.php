<?php if ($login_role === 4 || $login_role === 5 || $login_role === 6) {?>
    <?php
    echo "<script> location.href='index.php?page=dtr'; </script>";
    exit;
    ?>
<?php } ?>
<?php include 'db_connect.php'; ?>
<?php
$pay = $conn->query("SELECT * FROM payroll where id = " . $_GET['id'])->fetch_array();
$pt = array(1 => "Monhtly", 2 => "Semi-Monthly");
?>
<style>
/* ── Payroll Details — brand navy (#394b7c) enhanced table ── */
.pd-table-wrap { border:1px solid #c9d0e3; border-radius:8px; overflow:hidden; box-shadow:0 1px 3px rgba(57,75,124,.08); }
#data-table.pd-table { width:100%; border-collapse:collapse; margin:0; font-size:13px; }
#data-table.pd-table thead th {
    background:#394b7c; color:#fff; font-weight:600; font-size:12px;
    padding:11px 14px; text-align:left; border:1px solid #2e3d66; white-space:nowrap;
    text-transform:uppercase; letter-spacing:.3px;
}
#data-table.pd-table thead th.r { text-align:right; }
#data-table.pd-table thead th.c { text-align:center; }
#data-table.pd-table tbody td { padding:9px 14px; border:1px solid #e4e8f2; vertical-align:middle; }
#data-table.pd-table tbody td.text-right { text-align:right; font-variant-numeric:tabular-nums; }
#data-table.pd-table tbody td.text-center { text-align:center; }
#data-table.pd-table tbody tr:nth-child(even) td { background:#f6f8fc; }
#data-table.pd-table tbody tr:hover td { background:#eaeefa; }
#data-table.pd-table tbody .pd-id { font-family:monospace; font-size:12px; color:#394b7c; font-weight:700; }
#data-table.pd-table tbody .pd-name { font-weight:600; color:#2b2b3a; }
#data-table.pd-table tbody .pd-name-link { color:#394b7c; text-decoration:none; border-bottom:1px dotted #9aa6c9; transition:color .15s,border-color .15s; }
#data-table.pd-table tbody .pd-name-link:hover { color:#2e3d66; border-bottom-color:#394b7c; text-decoration:none; }
#data-table.pd-table tbody .pd-ded { color:#c0392b; }
#data-table.pd-table tbody .pd-net { font-weight:800; color:#394b7c; }
#data-table.pd-table tfoot td {
    background:#eef1f8; border:1px solid #c9d0e3; padding:11px 14px;
    font-weight:800; color:#394b7c; font-size:13px; font-variant-numeric:tabular-nums;
}
#data-table.pd-table tfoot td.text-right { text-align:right; }
.pd-net-chip { background:#394b7c; color:#fff; border-radius:6px; padding:3px 9px; font-weight:700; font-size:12px; display:inline-block; }
.pd-meta-bar { display:flex; flex-wrap:wrap; gap:22px; padding:4px 2px 14px; }
.pd-meta-bar .pd-meta { font-size:12px; color:#6b7280; }
.pd-meta-bar .pd-meta b { color:#394b7c; font-size:13px; }
.pd-view-btn { border:1px solid #c0392b; color:#c0392b; background:#fff; border-radius:5px; padding:3px 10px; font-size:12px; line-height:1.6; text-decoration:none; display:inline-flex; align-items:center; gap:4px; transition:all .15s; }
.pd-view-btn:hover { background:#c0392b; color:#fff; }
</style>
<div class="container-fluid">
	<?php $title = 'Payroll Details -' . '<small>' . $pay["ref_no"] . '</small>';
	include 'includes/breadcrumb.php'; ?>
	<div class="row clearfix">
		<div class="col-lg-12">
			<div class="card">
				<div class="body">
					<div class="row">
						<div class="col-sm-6">
							<form id="navbar-search" class="navbar-form search-form">
								<input id="search-input" class="form-control" placeholder="Search here..." type="text">
								<button type="button" class="btn btn-default"><i class="icon-magnifier"></i></button>
							</form>
						</div>
						<div class="col-sm-6 text-right">
							<button class="btn btn-outline-info" type="button" id="new_payroll_btn"><span class="fa fa-refresh"></span> Re-Caclulate Payroll</button>
							<a href="payroll_items_pdf.php?id=<?php echo $_GET['id'] ?>" target="blank" class="btn btn-sm btn-outline-danger" type="button"><i class="fa fa-file-pdf-o"></i> View PDF</a>
							<!-- <button class="btn btn-outline-danger" type="button" id=""><span class="fa fa-file-pdf-o"></span> Print in PDF</button> -->
						</div>
					</div>
				</div>
			</div>
			<div class="card">
				<div class="header">
					<div class="pd-meta-bar">
						<span class="pd-meta">Payroll Range: <b><?php echo date("M d, Y", strtotime($pay['date_from'])) . " &ndash; " . date("M d, Y", strtotime($pay['date_to'])) ?></b></span>
						<span class="pd-meta">Payroll Type: <b><?php echo $pt[$pay['type']] ?></b></span>
						<span class="pd-meta">Ref No: <b><?php echo htmlspecialchars($pay['ref_no']) ?></b></span>
					</div>
				</div>
				<div class="body">
					<div class="table-responsive pd-table-wrap">
						<table id="data-table" class="table dataTable c_list pd-table m-b-0">
							<thead>
								<tr>
									<th>ID</th>
									<th>Name</th>
									<th class="r">Overtime</th>
									<th class="r">Late</th>
									<th class="r">Total Allowance</th>
									<th class="r">Total Contribution</th>
									<th class="r">Total Deduction</th>
									<th class="r">Net</th>
									<th class="c">Action</th>
								</tr>
							</thead>
							<tbody>
								<?php
								$t_ot = $t_late = $t_allow = $t_contrib = $t_ded = $t_net = 0;
								$payroll = $conn->query("SELECT p.*,concat(e.lastname,', ',e.firstname,' ',e.middlename) as ename,e.employee_no FROM payroll_items p inner join employee e on e.id = p.employee_id where p.payroll_id=" . $_GET['id'] . " ") or die(mysqli_error());
								while ($row = $payroll->fetch_array()) {
									$t_ot      += $row['time_log_amount'];
									$t_late    += $row['late'];
									$t_allow   += $row['allowance_amount'];
									$t_contrib += $row['contribute_amount'];
									$t_ded     += $row['deduction_amount'];
									$t_net     += $row['net'];
								?>
									<tr>
										<td class="pd-id"><?php echo $row['employee_no'] ?></td>
										<td class="pd-name"><a href="index.php?page=employee-details&id=<?= $row['employee_id'] ?>" class="pd-name-link"><?php echo ucwords($row['ename']) ?></a></td>
										<td class="text-right"><?= number_format($row['time_log_amount'], 2) ?></td>
										<td class="text-right"><?= number_format($row['late'], 2) ?></td>
										<td class="text-right"><?= number_format($row['allowance_amount'], 2) ?></td>
										<td class="text-right"><?= number_format($row['contribute_amount'], 2) ?></td>
										<td class="text-right pd-ded"><?= number_format($row['deduction_amount'], 2) ?></td>
										<td class="text-right pd-net"><?= number_format($row['net'], 2) ?></td>
										<td class="text-center"><a href="view_payslip.php?id=<?php echo $row['id'] ?>" target="blank" class="pd-view-btn"><i class="fa fa-file-pdf-o"></i> View</a></td>
									</tr>
								<?php
								}
								?>
							</tbody>
							<tfoot>
								<tr>
									<td colspan="2">TOTAL</td>
									<td class="text-right"><?= number_format($t_ot, 2) ?></td>
									<td class="text-right"><?= number_format($t_late, 2) ?></td>
									<td class="text-right"><?= number_format($t_allow, 2) ?></td>
									<td class="text-right"><?= number_format($t_contrib, 2) ?></td>
									<td class="text-right"><?= number_format($t_ded, 2) ?></td>
									<td class="text-right"><span class="pd-net-chip">&#8369;<?= number_format($t_net, 2) ?></span></td>
									<td></td>
								</tr>
							</tfoot>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
<script>
	var id = "<?= $_GET['id'] ?>";
</script>