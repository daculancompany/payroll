// A select's owning widget has to be told explicitly after its value changes
// programmatically — setting .val() alone leaves the visible label behind:
//   - bootstrap-select (what the .select2() shim in index.php actually builds):
//     .trigger('change') reaches the shim's 'change.bsshim' handler, but that
//     calls .selectpicker('render'), which repaints from the widget's own cached
//     selection rather than the native <select>. Only 'refresh' re-reads the DOM.
//   - CustomSelect: .trigger('change') isn't seen at all, because it listens with
//     a native addEventListener, not a jQuery-bound one.
// #role and #department_id wear bootstrap-select; #employee_id is CustomSelect.
// Handling both here is the same helper employee.js carries for the same reason.
function refreshSelectDisplay($el) {
    var el = $el[0];
    if (!el) return;
    if ($el.data("bs-select") && $el.selectpicker) $el.selectpicker("refresh");
    if (window.CustomSelect) window.CustomSelect.refresh(el);
}

$(document).ready(function () {
    $("#site-wrapper").hide();

    // Password show/hide toggle lives inline in component/add_user_form.php
    // (single handler — binding it here too caused both to fire and cancel out).

    // DataTable — every column sortable except the last (Action).
    const oTable = $("#data-table").DataTable({
        order: [[0, "asc"]],
        columnDefs: [{ orderable: false, targets: -1 }],
    });
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

// Show the Department picker for a Department Head (role 8) or a Supervisor
// (role 10) and make it required only while visible, so other roles aren't
// blocked by validation.
//
// A Section/Unit Head (role 11) does NOT get one: they are scoped by area, and
// an area is assigned on the Areas page rather than here. The Linked Employee
// picker shows for all three approver roles — that link is what stops someone
// being handed their own leave request to approve.
function toggleDepartment() {
    const role = $("#role").val();
    if (role === "8" || role === "10") {
        $("#department-wrapper").removeClass("d-none");
    } else {
        $("#department-wrapper").addClass("d-none");
        $("#department_id").val("").trigger("change");
        refreshSelectDisplay($("#department_id"));
    }

    if (role === "8" || role === "10" || role === "11" || role === "9") {
        $("#employee-link-wrapper").removeClass("d-none");
    } else {
        $("#employee-link-wrapper").addClass("d-none");
        $("#employee_id").val("").trigger("change");
        refreshSelectDisplay($("#employee_id"));
    }

    // Areas are read-only here and only exist for an account that already has
    // them, so the block shows on edit and stays hidden while creating.
    const showAreas =
        currentAreas !== "" && (role === "8" || role === "10" || role === "11");
    $("#user-areas-wrapper").toggleClass("d-none", !showAreas);
}

// Track mode
let id = null;
// Comma-separated area names of the account being edited ("" while creating).
let currentAreas = "";

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

/* Activate / deactivate an account.
 *
 * users.php has called this from its status buttons since the page was written,
 * but it was never defined anywhere — the endpoint, its ajax route and its
 * ACTION_PAGE_MAP entry all existed, so the button simply threw a ReferenceError
 * and looked like nothing had happened.
 */
function updateUserStatus(btn, status) {
    // Older users.php passes the bare id — updateUserStatus(12, 2) — instead of
    // the button. Accept both, or a JS/PHP pair deployed out of step posts no
    // id and the server answers "Invalid parameters".
    const byId = typeof btn === 'number' || typeof btn === 'string';
    const id = byId ? btn : $(btn).data('id');
    const name = (byId ? '' : $(btn).data('name')) || 'this user';
    const areas = String((byId ? '' : $(btn).data('areas')) || '').trim();
    const activating = Number(status) === 1;

    // Deactivating an approver is not just a login switch: the approver pickers
    // list active users only, so the next save of any of these areas drops them.
    let warn = '';
    if (!activating && areas) {
        const list = areas.split(/\s*,\s*/);
        warn = '<div style="font-size:12px;text-align:left;margin-top:8px;padding:8px;'
            + 'border:1px dashed #e0b4b4;border-radius:6px;background:#fdf6f6;">'
            + '<b>Approves leave for ' + list.length + ' area' + (list.length > 1 ? 's' : '') + ':</b><br>'
            + list.join(' · ')
            + '<br><span style="color:#a33;">They will be removed from those stages the next time '
            + 'the area is saved.</span></div>';
    }

    Swal.fire({
        icon: activating ? 'question' : 'warning',
        title: activating ? 'Reactivate account?' : 'Deactivate account?',
        html: '<div style="font-size:13px;">' + (activating
            ? '<b>' + name + '</b> will be able to sign in again.'
            : '<b>' + name + '</b> will no longer be able to sign in.') + '</div>' + warn,
        showCancelButton: true,
        confirmButtonText: activating ? 'Reactivate' : 'Deactivate',
        confirmButtonColor: activating ? '#198754' : '#d33',
    }).then(function (res) {
        if (!res.isConfirmed) return;

        $.ajax({
            url: 'ajax.php?action=update_status_user',
            method: 'POST',
            dataType: 'JSON',
            data: { id: id, status: status },
            success: function (r) {
                if (r && r.result) {
                    Swal.fire({
                        icon: 'success', title: 'Success',
                        text: name + ' is now ' + (activating ? 'active' : 'inactive') + '.',
                        timer: 1400, showConfirmButton: false
                    }).then(function () { location.reload(); });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: (r && r.message) || 'Could not change the status.' });
                }
            },
            error: function (xhr) {
                let msg = 'Could not reach the server.';
                try { msg = JSON.parse(xhr.responseText).message || msg; } catch (e) {}
                Swal.fire({ icon: 'error', title: 'Error', text: msg });
            }
        });
    });
}

// Edit button handler (called from table button)
function edit_function(e) {
    id = $(e).attr("id");
    currentAreas = $(e).attr("data-areas") || "";

    // Show modal
    $("#modal").modal("show");

    // Keep password visible and required (for optional update)
    $("#password-wrapper").show();
    $("#password").removeAttr("required"); // optional: only required for create

    // Update title and button text
    $(".modal-title").html("Edit User");
    $(".submitbutton").html("Save Changes");

    // Areas (read-only) — owned by the Areas page, shown here for context.
    const areaNames = currentAreas ? currentAreas.split(/\s*,\s*/) : [];
    $("#user-areas-count").text(areaNames.length ? "(" + areaNames.length + ")" : "");
    $("#user-areas-list").text(areaNames.join(" · "));

    // Fill form fields
    $("#name").val($(e).attr("name"));
    $("#username").val($(e).attr("username"));
    $("#id").val($(e).attr("id"));
    $("#employer-select").val($(e).attr("employer_id")).trigger("change");
    $("#role").val($(e).attr("role")).trigger("change");
    // Role change toggles the Department field; set its value afterwards.
    $("#department_id").val($(e).attr("department_id") || "").trigger("change");
    $("#employee_id").val($(e).attr("employee_id") || "").trigger("change");

    // .val() moves the native <select> but not the widget painted over it —
    // without this the modal opens showing the previous user's role, or nothing.
    refreshSelectDisplay($("#role"));
    refreshSelectDisplay($("#department_id"));
    refreshSelectDisplay($("#employee_id"));

    // shown.bs.modal fires this too, but only after the fade; run it now so the
    // right rows are visible the instant the dialog paints.
    toggleDepartment();
}

$(document).on("hide.bs.modal", "#modal", function () {
    // Reset title and button
    $(".modal-title").html("Create User");
    $(".submitbutton").html("Create");

    // Reset all form fields
    $("#form-add")[0].reset();
    $("#id").val("");
    id = null;
    currentAreas = "";

    // form.reset() snaps each <select> back to its first option and fires no
    // change event, so every widget label would otherwise keep the edited
    // user's values into the next Create.
    refreshSelectDisplay($("#role"));
    refreshSelectDisplay($("#department_id"));
    refreshSelectDisplay($("#employee_id"));
    toggleDepartment();

    $("#username-wrapper").show();
    $("#password-wrapper").show();
});
