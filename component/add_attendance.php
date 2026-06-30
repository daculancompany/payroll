<div class="modal" id="modal" tabindex="-1" role="dialog">
	<form class="form-auth-small" id="form-add" method="post" novalidate>
		<input type="hidden" name="id" id="id">
		<div class="modal-dialog" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h6 class="modal-title" id="defaultModalLabel">Create Attendance</h6>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body" style="min-height: 590px;">
					<div class="row clearfix">
						<div class="col-md-12">
							<div class="form-group">
								<label>Employee</label>
								<select id="employee_id" name="employee_id" class="form-control show-tick ms select2" data-placeholder="Select employee" data-parsley-required-message="Please select employee." required>
									<option value=""></option>
									<?php
									$employee = $conn->query("SELECT *,concat(lastname,', ',firstname,' ',middlename) as ename FROM employee order by concat(lastname,', ',firstname,' ',middlename) asc");
									while ($row = $employee->fetch_assoc()) :
									?>
										<option value="<?php echo $row['id'] ?>"><?php echo $row['ename'] . ' | ' . $row['employee_no'] ?></option>
									<?php endwhile; ?>
								</select>
							</div>
						</div>
						<div class="col-md-12">
							<div class="form-group">
								<label> Date</label>
								<?php
								$dateFrom = new DateTime($dtr['date_from']);
								$dateTo = new DateTime($dtr['date_to']);
								// Generate the dropdown
								echo '<select id="date-picker" name="date_time"  class="form-control show-tick ms select2" data-placeholder="Select Date" data-parsley-required-message="Please select date." required>';
								echo '<option value="">Select a date</option>'; // Add empty option
								for ($date = $dateFrom; $date < $dateTo; $date->modify('+1 day')) {
									$dateValue = $date->format('Y-m-d');
									echo "<option value=\"$dateValue\">$dateValue</option>";
								}
								echo '</select>';
								?>
							</div>
						</div>
						<div class="col-md-12">
						<label>Logs</label>
						<div id="container-clone">
							<div class="item">
								<div class="row">
									<div class="col-md-9">
										<div class="form-group">
											<div id="id_1">
												<input value="08:00:00" type="text" name="datetime_log[]" class="form-control date" data-parsley-required-message="Please select date." required />
											</div>
										</div>
									</div>
									<div class="col-md-3">
										<button type="button" class="btn btn-secondary cloneButton"><i class="fa fa-plus"></i></button>
										<button type="button" class="btn btn-danger removeButton"><i class="fa fa-minus"></i></button>
									</div>
								</div>
							</div>
						</div>
						</div>
						<!-- <div class="col-md-12">
							<div class="form-group">   
								<label>Date</label>   
								<input   id="reportrange" class="form-control" autocomplete="off" >                              
							</div>
						</div> -->
					</div>
				</div>
				<div class="modal-footer">
					<button type="submit" class="btn btn-info submitbutton">Create</button>
				</div>
			</div>
		</div>
	</form>
</div>
