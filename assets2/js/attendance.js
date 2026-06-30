 (function($){
    // Legacy single date picker — guarded so a failure can't break the page
    $(function(){
        try {
            if ($.fn.datetimepicker) {
                $("#date").datetimepicker({
                    allowInputToggle: true, showClose: true, showClear: true,
                    showTodayButton: true, format: "YYYY-MM-DD"
                });
            }
        } catch (e) { /* datetimepicker is BS3-era; ignore under BS5 */ }
    });

    // ── Date range picker (daterangepicker.com) — mirrors employee-portal.php ──
    jQuery(function ($) {
        var $picker = $('#att-daterange');
        if (!$picker.length || !$.fn.daterangepicker) return;
        $picker.daterangepicker({
            autoUpdateInput: false,
            opens: 'right',
            showDropdowns: true,
            locale: { format: 'YYYY-MM-DD', cancelLabel: 'Clear', applyLabel: 'Apply' },
            ranges: {
                'Today':        [moment(), moment()],
                'Yesterday':    [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                'Last 7 Days':  [moment().subtract(6, 'days'), moment()],
                'Last 30 Days': [moment().subtract(29, 'days'), moment()],
                'This Month':   [moment().startOf('month'), moment().endOf('month')],
                'Last Month':   [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
            }
        });
        $picker.on('apply.daterangepicker', function (ev, picker) {
            $('#att-range-label').text(picker.startDate.format('MMM D, YYYY') + ' – ' + picker.endDate.format('MMM D, YYYY'));
            $('#from').val(picker.startDate.format('YYYY-MM-DD'));
            $('#to').val(picker.endDate.format('YYYY-MM-DD'));
        });
        $picker.on('cancel.daterangepicker', function () {
            $('#att-range-label').text('Select date range…');
            $('#from').val('');
            $('#to').val('');
        });
    });
})(jQuery);


$(function () {

    $('#employee-select').select2({
        placeholder: 'Select one or more employees...',
        allowClear: true,
        width: '100%',
    });

    
    // oTable = $('#table-attendance').DataTable( {
    //     "order": [[ 0, "asc" ]]
    // } );
 
    // $('#search-input').keyup(function(){
    //     oTable.search($(this).val()).draw() ;
    // })
    

  



    // var start = moment().subtract(29, 'days');
    // var end = moment();

    // function cb(start, end) {
    //     $('#reportrange span').html(start.format('MMMM D, YYYY') + ' - ' + end.format('MMMM D, YYYY'));
    // }

    // $('#reportrange').daterangepicker({
    //     // startDate: start,
    //     // endDate: end,
    //     startDate: moment().startOf('hour'),
    //     endDate: moment().startOf('hour').add(23, 'hour'),
    //     ranges: {
    //        'Today': [moment(), moment()],
    //        'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
    //        'Last 7 Days': [moment().subtract(6, 'days'), moment()],
    //        'Last 30 Days': [moment().subtract(29, 'days'), moment()],
    //        'This Month': [moment().startOf('month'), moment().endOf('month')],
    //        'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
    //     }
    // }, cb);

    // cb(start, end);

    $("#form-add").on('submit', function(e){
        e.preventDefault();
        var form = $(this);

        form.parsley().validate();

        if (form.parsley().isValid()){ 
            e.preventDefault()
            $('.submitbutton').attr('disabled',true).html('Saving...');
            $.ajax({
                url:'ajax.php?action=save_employee_attendance',
                method:'POST',
                data:$(this).serialize(),
                error:err=>{
                    alert("Something went wrong!");
                  $('.submitbutton').removeAttr('disabled');

                },
                success:function(resp){  
                    if(resp == 1){
                    	toastr['success']('Attendance data successfully saved!',  "Success Notification");
                    }
                    setTimeout(function(){
						location.reload()
					},2000)
                }
            })
        }
    });

});

// Attendance DataTable + filter logic
if ($('#attendance-table').length) {
    var attTable = $('#attendance-table').DataTable({
        processing: true,
        serverSide: true,
        order: [[0, 'desc']],
        pageLength: 25,
        ajax: {
            url: 'attendance-server.php',
            type: 'POST',
            data: function (d) {
                d.from         = attFilter.from;
                d.to           = attFilter.to;
                d.employee_ids = attFilter.employee_ids;
                d.site_id      = attFilter.site_id;
            },
        },
        columns: [
            { data: 'date' },
            { data: 'employee' },
            { data: 'site' },
            { data: 'work_hours', className: 'text-center', orderable: true },
            { data: 'overtime',   className: 'text-center', orderable: true },
            { data: 'undertime',  className: 'text-center', orderable: true },
            { data: 'late',       className: 'text-center', orderable: true },
            { data: 'logs',       orderable: false },
            { data: 'status',     className: 'text-center', orderable: false },
        ],
        language: {
            emptyTable: [
                '<div class="att-empty">',
                  '<div class="att-empty-icon"><i class="ri-calendar-check-line"></i></div>',
                  '<h6>No Attendance Records</h6>',
                  '<p>Select employees and a date range,<br>then apply the filter to view records.</p>',
                  '<button class="att-empty-btn" type="button" onclick="document.getElementById(\'att-daterange\').click()">',
                    '<i class="ri-filter-3-line"></i> Choose Date Range',
                  '</button>',
                '</div>',
            ].join(''),
            zeroRecords: [
                '<div class="att-empty">',
                  '<div class="att-empty-icon"><i class="ri-search-line"></i></div>',
                  '<h6>No Matching Records</h6>',
                  '<p>No attendance records match your current<br>filter or search criteria.</p>',
                  '<button class="att-empty-btn" type="button" onclick="document.getElementById(\'att-daterange\').click()">',
                    '<i class="ri-edit-2-line"></i> Change Date Range',
                  '</button>',
                '</div>',
            ].join(''),
        },
        drawCallback: function (settings) {
            var json = settings.json;
            if (json && json.stats) {
                attUpdateStats(json.stats);
            }
        },
    });

    function attUpdateStats(stats) {
        if (!attFilter.from || !attFilter.to) {
            $('#att-stats-row, #att-filter-bar, #btn-clear-filter').hide();
            return;
        }
        $('#att-stats-row').show();
        $('#att-filter-bar').show();
        $('#btn-clear-filter').css('display', 'inline-flex');
        $('#stat-total').text(parseInt(stats.total_records).toLocaleString());
        $('#stat-hours').text(parseFloat(stats.total_hours).toFixed(1));
        $('#stat-ot').text(parseFloat(stats.total_overtime).toFixed(1));
        $('#stat-ut').text(parseFloat(stats.avg_undertime).toFixed(1) + 'm');
        $('#stat-late').text(parseFloat(stats.avg_late).toFixed(1) + 'm');
        $('#stat-emps').text(stats.unique_employees);

        var from = new Date(attFilter.from);
        var to   = new Date(attFilter.to);
        var fmt  = { month: 'short', day: 'numeric', year: 'numeric' };
        $('#filter-date-label').text(from.toLocaleDateString('en-US', fmt) + ' – ' + to.toLocaleDateString('en-US', fmt));
        $('#filter-emp-label').text(attFilter.emp_label || (attFilter.employee_ids ? attFilter.employee_ids.split(',').length + ' selected' : 'All'));
        $('#filter-site-label').text(attFilter.site_label || 'All Sites');
    }

    $('#form-filter').on('submit', function (e) {
        e.preventDefault();
        var empIds  = $('#employee-select').val() || [];

        attFilter.from         = $('#from').val();
        attFilter.to           = $('#to').val();
        attFilter.employee_ids = empIds.join(',');
        attFilter.site_id      = '';
        attFilter.emp_label    = empIds.length ? empIds.length + ' employee(s)' : 'All';
        attFilter.site_label   = 'All Sites';

        attTable.draw();
    });

    $('#btn-clear-filter').on('click', function () {
        attFilter = { from: '', to: '', employee_ids: '', site_id: '', emp_label: '', site_label: '' };
        $('#employee-select').val(null).trigger('change');
        $('#att-range-label').text('Select date range…');
        $('#from').val('');
        $('#to').val('');
        $('#att-stats-row, #att-filter-bar').hide();
        $(this).hide();
        attTable.draw();
    });

    // Auto-draw if URL params were present on page load
    if (attFilter.from && attFilter.to) {
        attTable.draw();
    }
}


$('.remove_attendance').click(function(){
    var d = '"'+($(this).attr('data-id')).toString()+'"';
    _conf("Are you sure to delete this employee's time log record?","remove_attendance",[d])
})
$('.rem_att').click(function(){
    var $id=$(this).attr('data-id');
    _conf("Are you sure to delete this time log?","rem_att",[$id])
})

function remove_attendance(id){
    $.ajax({
        url:'ajax.php?action=delete_employee_attendance',
        method:"POST",
        data:{id:id},
        error:err=>console.log(err),
        success:function(resp){
                if(resp == 1){
                    toastr['success']("Selected employee's time log data successfully deleted",  "Success Notification");
                    setTimeout(function(){
						location.reload()
					},2000)
                }
            }
    })
}
function rem_att(id){
    $.ajax({
        url:'ajax.php?action=delete_employee_attendance_single',
        method:"POST",
        data:{id:id},
        error:err=>console.log(err),
        success:function(resp){
                if(resp == 1){
                    toastr['success']("Selected employee's time log data successfully deleted",  "Success Notification");
                    setTimeout(function(){
						location.reload()
					},2000)
                }
            }
    })
}

