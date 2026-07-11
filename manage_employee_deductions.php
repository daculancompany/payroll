<?php include 'db_connect.php' ?>

<div class="container-fluid">
	<form action="" id="employee-deduction">
		<input type="hidden" name="employee_id" value="<?php echo $_GET['id'] ?>">
		<div class="row form-group">
			<div class="col-md-5">
				<label for="" class="control-label">Deduction</label>
				<select id="deduction_id" class="borwser-default select2">
					<option value=""></option>
					<?php
					$deduction = $conn->query("SELECT * FROM deductions order by deduction asc");
					while ($row = $deduction->fetch_assoc()):
					?>
						<option value="<?php echo $row['id'] ?>"><?php echo $row['deduction'] ?></option>
					<?php endwhile; ?>
				</select>
			</div>
			<div class="col-md-3">
				<label for="" class="control-label">Type</label>
				<select id="type" class="borwser-default custom-select">
					<option value="1">Monthly</option>
					<option value="2">Semi-Monthly</option>
					<option value="3">Once</option>
				</select>
			</div>
			<div class="col-md-4" id="dfield">
				<label for="" class="control-label">Start Date <small class="text-muted">(first deduction)</small></label>
				<input type="date" id="edate" class="form-control">
			</div>
		</div>
		<div class="row form-group">
			<div class="col-md-4">
				<label for="" class="control-label">Amount per period</label>
				<input type="number" id="amount" class="form-control text-right" step="any">
			</div>
			<div class="col-md-4">
				<label for="" class="control-label">Total <small class="text-muted">(0 = recurring)</small></label>
				<input type="number" id="total_amount" class="form-control text-right" step="any" value="0">
			</div>
			<div class="col-md-2 offset-md-2">
				<label for="" class="control-label">&nbsp;</label>
				<button class="btn btn-primary btn-block btn-sm" type="button" id="add_list"> Add to List</button>
			</div>
		</div>
		<hr>
		<div class="row">
			<table class="table table-bordered" id="deduction-list">
				<thead>
					<tr>
						<th class="text-center">deduction</th>
						<th class="text-center">Type</th>
						<th class="text-center">Amount</th>
						<th class="text-center">Total</th>
						<th class="text-center">Start Date</th>
						<th class="text-center"></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>
	</form>
</div>
<div id="tr_clone" style="display: none">
	<table>
		<tr>
			<td>
				<input type="hidden" name="deduction_id[]">
				<p class="deduction"></p>
			</td>
			<td>
				<input type="hidden" name="type[]">
				<p class="type"></p>
			</td>
			<td>
				<input type="hidden" name="amount[]">
				<p class="amount"></p>
			</td>
			<td>
				<input type="hidden" name="total_amount[]">
				<p class="total_amount"></p>
			</td>
			<td>
				<input type="hidden" name="effective_date[]">
				<p class="edate"></p>
			</td>
			<td class="text-center">
				<button class="btn-sm btn-danger" type="button" onclick="$(this).closest('tr').remove()"><i class="fa fa-trash"></i></button>
			</td>
		</tr>
	</table>
</div>

<script>
	$('.select2').select2({
		placeholder: "Select here",
		width: "100%"
	})
	$('#add_list').click(function () {
		var deduction_id = $('#deduction_id').val(),
			type = $('#type').val(),
			amount = $('#amount').val(),
			total_amount = $('#total_amount').val() || 0,
			edate = $('#edate').val();
		if (!deduction_id) { alert('Please select a deduction.'); return; }
		var tr = $('#tr_clone tr').clone()
		tr.find('[name="deduction_id[]"]').val(deduction_id)
		tr.find('[name="type[]"]').val(type)
		tr.find('[name="effective_date[]"]').val(edate)
		tr.find('[name="amount[]"]').val(amount)
		tr.find('[name="total_amount[]"]').val(total_amount)
		tr.find('.deduction').html($('#deduction_id option[value="' + deduction_id + '"]').html())
		tr.find('.type').html($('#type option[value="' + type + '"]').html())
		tr.find('.amount').html(amount)
		tr.find('.total_amount').html(total_amount)
		tr.find('.edate').html(edate)
		$('#deduction-list tbody').append(tr)
		$('#deduction_id').val('').select2({
			placeholder: "Select here",
			width: "100%"
		})
		$('#type').val('')
		$('#amount').val('')
		$('#total_amount').val('0')
		$('#edate').val('')
	})
	$(document).ready(function () {
		$('#employee-deduction').submit(function (e) {
			e.preventDefault()
			start_load();
			$.ajax({
				url: 'ajax.php?action=save_employee_deduction',
				method: "POST",
				data: $(this).serialize(),
				error: err => console.log(),
				success: function (resp) {
					if (resp == 1) {
						alert_toast("Employee's data successfully saved", "success");
						end_load()
						uni_modal("Employee Details", 'view_employee.php?id=<?php echo $_GET['id'] ?>', 'mid-large')
					}
				}
			})
		})
	})
</script>
