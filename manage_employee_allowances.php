<?php include 'db_connect.php' ?>

<?php ?>

<div class="container-fluid">
	<form action="" id="employee-allowance">
		<input type="hidden" name="employee_id" value="<?php echo (int) ($_GET['id'] ?? 0) ?>">
		<div class="row form-group">
			<div class="col-md-5">
				<label for="" class="control-label">Allowance</label>
				<select id="allowance_id" class="borwser-default select2">
					<option value="">Select Allowance</option>
					<?php 
					$allowance = $conn->query("SELECT * FROM allowances order by allowance asc");
					while($row = $allowance->fetch_assoc()):
					?>
						<option value="<?php echo $row['id'] ?>"><?php echo $row['allowance'] ?></option>
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
			<?php /* Effective Date used to be revealed only for type 3 (Once). It is
			         the START of the effectivity window for EVERY type, so a recurring
			         allowance had no way to say which cutoff it begins on — it was
			         saved blank and silently behaved as "since forever". */ ?>
			<div class="col-md-4" id="dfield">
				<label for="" class="control-label">Effective Date <span id="edate-hint" class="text-muted" style="font-weight:400;"></span></label>
				<input type="date" id="edate" class="form-control">
			</div>
		</div>
		<div class="row form-group">
			<div class="col-md-4">
				<label for="" class="control-label">Amount</label>
				<input type="number" id="amount" class="form-control text-right" step="any" >
			</div>
			<?php /* Optional end date. employee_allowances.effective_to has existed and
			         the calculation reads it (employee_allowance_amount_for_run), but no
			         screen ever set it — so a recurring allowance could only be stopped
			         by deleting the row, losing the record that it was ever paid. */ ?>
			<div class="col-md-4" id="tfield">
				<label for="" class="control-label">Effective To <span class="text-muted" style="font-weight:400;">(optional — blank = ongoing)</span></label>
				<input type="date" id="tdate" class="form-control">
			</div>
			<div class="col-md-2 offset-md-2">
				<label for="" class="control-label">&nbsp</label>
				<button class="btn btn-primary btn-block btn-sm" type="button" id="add_list"> Add to List</button>
			</div>
		</div>
		<hr>
		<div class="row">
			<table class="table table-bordered" id="allowance-list">
				<thead>
					<tr>
						<th class="text-center">
							Allowance
						</th>
						<th class="text-center">
							Type
						</th>
						<th class="text-center">
							Amount
						</th>
						<th class="text-center">
							From
						</th>
						<th class="text-center">
							To
						</th>
						<th class="text-center">
							
						</th>
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
				<input type="hidden" name="allowance_id[]">
				<p class="allowance"></p>
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
				<input type="hidden" name="effective_date[]">
				<p class="edate"></p>
			</td>
			<td>
				<input type="hidden" name="effective_to[]">
				<p class="tdate"></p>
			</td>
			<td class="text-center">
				<button class="btn-sm btn-danger" type="button" onclick="$(this).closest('tr').remove()"><i class="fa fa-trash"></i></button>
			</td>
		</tr>
	</table>
</div>

<script>
	$('.select2').select2({
		placeholder:"Select here",
		width:"100%"
	})
	// "Once" is paid in the single cutoff containing the date, so there is no
	// end date to set; the recurring types use the pair as a window.
	function syncDateFields(){
		var once = $('#type').val() == 3
		$('#edate-hint').text(once ? '(the cutoff it is paid in)' : '(first cutoff it applies)')
		$('#tfield').toggle(!once)
		if(once) $('#tdate').val('')
	}
	$('#type').change(syncDateFields)
	syncDateFields()

	$('#add_list').click(function(){
		var allowance_id = $('#allowance_id').val(),
			type = $('#type').val(),
			amount = $('#amount').val(),
			edate = $('#edate').val(),
			tdate = $('#type').val() == 3 ? '' : $('#tdate').val();

		// Each of these silently produced a junk row before: no allowance id
		// inserted allowance_id='' , no amount inserted 0, and a blank start
		// date landed in a NOT NULL DATE column as 0000-00-00.
		if(!allowance_id){ alert('Select an allowance first.'); return }
		if(!(parseFloat(amount) > 0)){ alert('Enter an amount greater than zero.'); return }
		if(!edate){ alert('Pick an effective date.'); return }
		if(tdate && tdate < edate){ alert('Effective To cannot be earlier than Effective Date.'); return }

		var tr = $('#tr_clone tr').clone()
		tr.find('[name="allowance_id[]"]').val(allowance_id)
		tr.find('[name="type[]"]').val(type)
		tr.find('[name="effective_date[]"]').val(edate)
		tr.find('[name="effective_to[]"]').val(tdate)
		tr.find('[name="amount[]"]').val(amount)
		tr.find('.allowance').html($('#allowance_id option[value="'+allowance_id+'"]').html())
		tr.find('.type').html($('#type option[value="'+type+'"]').html())
		tr.find('.amount').html(amount)
		tr.find('.edate').html(edate)
		tr.find('.tdate').html(tdate || '—')
		$('#allowance-list tbody').append(tr)
		$('#allowance_id').val('').select2({
			placeholder:"Select here",
			width:"100%"
		})
		$('#type').val('1')
		$('#amount').val('')
		$('#edate').val('')
		$('#tdate').val('')
		syncDateFields()
	})
	$(document).ready(function(){
		$('#employee-allowance').submit(function(e){
				e.preventDefault()
				start_load();
			$.ajax({
				url:'ajax.php?action=save_employee_allowance',
				method:"POST",
				data:$(this).serialize(),
				error:err=>console.log(),
				success:function(resp){
						if(resp == 1){
							alert_toast("Employee's data successfully saved","success");
							end_load()
							uni_modal("Employee Details",'view_employee.php?id=<?php echo $_GET['id'] ?>','mid-large')
						}
				}
			})
		})
	})
</script>