$(document).ready(function () {
    $("#site-wrapper").hide();

    // Password show/hide toggle lives inline in component/add_user_form.php
    // (single handler — binding it here too caused both to fire and cancel out).

    // DataTable
    const oTable = $("#data-table").DataTable();
    $("#search-input").keyup(function () {
        oTable.search($(this).val()).draw();
    });

    // Select2 setup
    $("#role").select2({ dropdownParent: $("#modal") });
    $("#employer-select").select2({ dropdownParent: $("#modal") });
    $("#department_id").select2({
        dropdownParent: $("#modal"),
        placeholder: "Search department…",
        allowClear: true,
        width: "100%",
    });

    // Hide site selection by default
    $("#site-select").removeAttr("required");
    $(".fa-spinner-button").hide();

    // Role change toggle
    $("#role").on("change", function () {
        const selectedValue = $(this).val();
        if (selectedValue == 5 || selectedValue == 6) {
            $("#site-select").attr("required", true);
            $("#site-wrapper").show();
        } else {
            $("#site-select").removeAttr("required");
            $("#site-wrapper").hide();
        }
        toggleDepartment();
    });

    // Reflect the loaded role when the modal opens (edit)
    $(document).on("shown.bs.modal", "#modal", toggleDepartment);
});

// Show the Department picker only for a Department Head (role 8) and make it
// required only while visible, so other roles aren't blocked by validation.
function toggleDepartment() {
    const role = $("#role").val();
    if (role === "8") {
        $("#department-wrapper").removeClass("d-none");
        $("#department_id").attr("required", "required");
    } else {
        $("#department-wrapper").addClass("d-none");
        $("#department_id").removeAttr("required").val("").trigger("change");
    }
}

// Track mode
let id = null;

// Handle form submit (Create + Edit)
$("#form-add").on("submit", async function (e) {
    e.preventDefault();

    const form = $(this);
    form.parsley().validate();

    if (form.parsley().isValid()) {
        Swal.fire({
            title: id ? "Saving changes..." : "Creating user...",
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
        });

        $(".submitbutton")
            .attr("disabled", true)
            .html(
                id
                    ? '<i class="fa fa-spinner fa-spin me-1"></i> Saving...'
                    : '<i class="fa fa-spinner fa-spin me-1"></i> Creating...'
            );

        $.ajax({
            url: "ajax.php?action=save_user",
            method: "POST",
            dataType: "JSON",
            data: form.serialize(),
            error: function (xhr, status, error) {
                Swal.close();
                handleError(error || "");
                $(".submitbutton")
                    .removeAttr("disabled")
                    .html(id ? "Save Changes" : "Create");
            },
            success: function (res) {
                Swal.close();
                if (res?.result) {
                    Swal.fire({
                        icon: "success",
                        title: "Success!",
                        text: res.message,
                    }).then(() => window.location.reload());
                } else {
                    $(".submitbutton")
                        .removeAttr("disabled")
                        .html(id ? "Save Changes" : "Create");
                    Swal.fire({
                        icon: "error",
                        title: "Error!",
                        text: res.message,
                    });
                }
            },
        });
    }
});

// Edit button handler (called from table button)
function edit_function(e) {
    console.log("edit_function --->", $(e).attr("employer_id"));
    id = $(e).attr("id");

    // Show modal
    $("#modal").modal("show");

    // Hide only employer and role fields
    $("#employer-select").closest(".form-group").hide();
    $("#role").closest(".form-group").hide();

    // Keep password visible and required (for optional update)
    $("#password-wrapper").show();
    $("#password").removeAttr("required"); // optional: only required for create

    // Hide username field (cannot edit username)
    // $("#username-wrapper").hide();
    // $("#username").removeAttr("required");

    // Update title and button text
    $(".modal-title").html("Edit User");
    $(".submitbutton").html("Save Changes");

    // Fill form fields
    $("#name").val($(e).attr("name"));
    $("#username").val($(e).attr("username"));
    $("#id").val($(e).attr("id"));
    $("#employer-select").val($(e).attr("employer_id")).trigger("change");
    $("#role").val($(e).attr("role")).trigger("change");
    // Role change toggles the Department field; set its value afterwards.
    $("#department_id").val($(e).attr("department_id") || "").trigger("change");
}

$(document).on("hide.bs.modal", "#modal", function () {
    // Reset title and button
    $(".modal-title").html("Create User");
    $(".submitbutton").html("Create");

    // Reset all form fields
    $("#form-add")[0].reset();
    $("#id").val("");

    // Re-show all form groups for next create action
    $("#employer-select").closest(".form-group").show();
    $("#role").closest(".form-group").show();
    $("#username-wrapper").show();
    $("#password-wrapper").show();
});
