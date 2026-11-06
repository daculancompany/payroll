let btnText = 'Create';
let id = null;

// Put the submit button back the way it was. Every failure path needs this, or
// the dialog stays stuck on "Adding..." with no way to retry.
function resetSubmit() {
    $('.submitbutton').removeAttr('disabled').html(id ? 'Save Changes' : btnText);
}

oTable = $('#data-table').DataTable();   
$('#search-input').keyup(function(){
      oTable.search($(this).val()).draw() ;
})

$(function () {

    $("#form-add").on('submit', function(e){
        e.preventDefault();
        var form = $(this);

        form.parsley().validate();

        if (form.parsley().isValid()){
            e.preventDefault()
            if(id){
                $('.submitbutton').attr('disabled',true).html('Saving...');
            }else{
                $('.submitbutton').attr('disabled',true).html('Adding...');
            }
	    	
            $.ajax({
                url:'ajax.php?action=save_allowances',
                method:'POST',
                data:$(this).serialize(),
                error:err=>{
                    Swal.fire({ icon: "error", title: "Error", text: "Something went wrong!" });
                    resetSubmit();
                },
                // SweetAlert, to match the Deductions screen. This used to fire a
                // toastr and then reload on a blind 2s timer, so the page reloaded
                // just as often when the save had FAILED — the row quietly never
                // appeared and nothing said why.
                success:function(resp){
                    resp = String(resp).trim();
                    if (resp === '1' || resp === '2') {
                        Swal.fire({
                            icon: "success",
                            title: "Success!",
                            text: resp === '1'
                                ? 'New allowance successfully saved!'
                                : 'Allowance successfully updated!',
                        }).then(function () {
                            window.location.reload();
                        });
                    } else {
                        Swal.fire({ icon: "error", title: "Not saved", text: "The allowance could not be saved." });
                        resetSubmit();
                    }
                }
            })
        }
    });

});

function edit_function(e){
    //uni_modal("Edit Employee","manage_employee.php?id="+e)
    id = $(e).attr("id");
    $("#modal").modal('show');
    $(".title").html('Edit Allowance');
    $("#allowance").val($(e).attr("name"));
    $("#id").val($(e).attr("id"));
    $('.submitbutton').html('Save Changes');
}



$(document).on('hide.bs.modal','#modal', function () {
    // Said "Create Department" — copy-paste from department.js. The heading is
    // what tells you whether the dialog will add a new allowance or save over
    // the one you just edited, so it has to reset to the add label.
    $(".title").html('Create Allowance');
    $("#allowance").val("");
    $("#id").val("");
    id = null;
    $('.submitbutton').html(btnText);

}); 