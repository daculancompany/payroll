// oTable = $("#table-employee").DataTable();
document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".nav.nav-pills").forEach((nav, index) => {
        let tabGroupKey = `activeTabGroup${index}`; // Unique key for each tab group
        let activeTab = localStorage.getItem(tabGroupKey);

        // Restore active tab if it exists
        if (activeTab) {
            let tabElement = nav.querySelector(`[href="${activeTab}"]`);
            if (tabElement) {
                new bootstrap.Tab(tabElement).show();
            }
        }

        // Save active tab when clicked
        nav.querySelectorAll(".nav-link").forEach((tab) => {
            tab.addEventListener("click", function () {
                localStorage.setItem(tabGroupKey, this.getAttribute("href"));
            });
        });
    });
});

$(document).ready(function () {
    // Timekeepers (role 5) get the roster without the pay columns — employee.php
    // omits the matching <th>, so the column list has to drop them too or every
    // header would be off by four.
    var hidePay = window.EMP_HIDE_PAY === true;

    var payColumns = [
        { data: "3", className: "text-end", createdCell: (td) => td.setAttribute("data-label", "Basic Pay") },      // Basic Pay
        { data: "4", className: "text-end", createdCell: (td) => td.setAttribute("data-label", "Daily Rate") },     // Daily Rate
        { data: "10", className: "text-center", orderable: false, createdCell: (td) => td.setAttribute("data-label", "Rate Type") }, // Rate Type (chip)
        { data: "5", className: "text-end", createdCell: (td) => td.setAttribute("data-label", "OT Rate") },        // OT Rate
    ];

    var oTable = $("#table-employee").DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: "employee-server.php",
            type: "POST",
            data: function (d) {
                d.status = $("#filter-status").val();
                d.position_id = $("#filter-position").val();
                d.department_id = $("#filter-department").val();
                d.fingerprint = $("#filter-fingerprint").val();
            },
        },
        columns: [
            { data: "0", createdCell: (td) => td.setAttribute("data-label", "Employee") },        // Employee (name + no.)
            { data: "1", createdCell: (td) => td.setAttribute("data-label", "Position") },         // Position
            { data: "2", createdCell: (td) => td.setAttribute("data-label", "Department") },       // Department
        ].concat(hidePay ? [] : payColumns).concat([
            { data: "7", className: "text-center", createdCell: (td) => td.setAttribute("data-label", "Classification") }, // Classification (6 = loan, hidden)
            { data: "8", className: "text-center", createdCell: (td) => td.setAttribute("data-label", "Status") },      // Status
            { data: "9", className: "text-center", orderable: false, createdCell: (td) => td.setAttribute("data-label", "Action") }, // Action
        ]),
        columnDefs: [
           
          
        ],
        createdRow: function (row) {
            // Make the whole employee card/row clickable (View link stays as-is)
            var link = row.querySelector('.emp-actions a[href]');
            if (link) {
                row.style.cursor = "pointer";
                row.addEventListener("click", function (e) {
                    if (e.target.closest("a, button")) return; // let real buttons/links work
                    window.location.href = link.getAttribute("href");
                });
            }
        },
    }).on("draw.dt", function () {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            bootstrap.Tooltip.getInstance(el)?.dispose();
            new bootstrap.Tooltip(el, { trigger: "hover" });
        });
    });

    // Search input filter
    $("#search-input").keyup(function () {
        oTable.search($(this).val()).draw();
    });

    $("#filter-status").select2({
        allowClear: true,
        width: "resolve",
        dropdownParent: $("body"),
    }).on("change", function () {
        oTable.draw();
    });

    $("#filter-position").select2({
        allowClear: true,
        width: "resolve",
        dropdownParent: $("body"),
    }).on("change", function () {
        oTable.draw();
    });

    $("#filter-department").select2({
        allowClear: true,
        width: "resolve",
        dropdownParent: $("body"),
    }).on("change", function () {
        oTable.draw();
    });

    $("#filter-fingerprint").select2({
        allowClear: true,
        width: "resolve",
        dropdownParent: $("body"),
    }).on("change", function () {
        oTable.draw();
    });
});

// DataTables throws on tables whose tbody holds a manual colspan empty-state row;
// a throw here would kill every handler binding below, so init each table safely.
function safeDataTable(selector, opts) {
    try {
        var $t = $(selector);
        // Skip init when the only body row is the colspan empty-state placeholder.
        if (!$t.length || $t.find("tbody td[colspan]").length) return null;
        return $t.DataTable(opts || {});
    } catch (e) {
        console.warn("DataTable init skipped for " + selector, e);
        return null;
    }
}
let tloan = safeDataTable("#table-loan");
let tcontribution = safeDataTable("#table-contributions");
let tdeductions = safeDataTable("#table-deductions");
let tsites = safeDataTable("#table-sites");
let tleave = safeDataTable("#table-leave", { order: [[0, "desc"]] });
let tschedhist = safeDataTable("#table-schedule-history", { order: [[3, "desc"]] });
// $("#search-input").keyup(function () {
//     oTable.search($(this).val()).draw();
// });

$(function () {
    $("#position-select").select2({
        dropdownParent: $("#addemployee"),
    });
    $("#department-select").select2({
        dropdownParent: $("#addemployee"),
        allowClear: true,
    });
    $("#clasification-select").select2({
        dropdownParent: $("#addemployee"),
    });
    $(".fa-spinner-button").hide();
    //$(".select2").select2();
    $("#table-payslip").DataTable();
    // $('#table-deductions').DataTable();
    // $('#table-contribution').DataTable();
    // $('#table-allowances').DataTable();
    $("#table-timelogs").DataTable();
    // $('.datetimepicker').datetimepicker({
    //     format:"H:i",
    //     datepicker:false,
    // })

    $(".datetimepicker").datetimepicker({
        allowInputToggle: true,
        showClose: true,
        showClear: true,
        showTodayButton: true,
        format: "YYYY-MM-DD ",
        icons: {
            time: "fa fa-clock-o",
            date: "fa fa-calendar",
            up: "fa fa-chevron-up",
            down: "fa fa-chevron-down",
            previous: "fa fa-chevron-left", // Fix for missing prev button
            next: "fa fa-chevron-right", // Fix for missing next button
            today: "fa fa-crosshairs",
            clear: "fa fa-trash",
            close: "fa fa-times",
        },
    });

    $(".datetimepicker2").datetimepicker({
        allowInputToggle: true,
        showClose: true,
        showClear: true,
        showTodayButton: true,
        format: "HH:mm",
        icons: {
            time: "fa fa-clock-o",
            date: "fa fa-calendar",
            up: "fa fa-chevron-up",
            down: "fa fa-chevron-down",
            previous: "fa fa-chevron-left", // Fix for missing prev button
            next: "fa fa-chevron-right", // Fix for missing next button
            today: "fa fa-crosshairs",
            clear: "fa fa-trash",
            close: "fa fa-times",
        },
    });

    // Birthdate — opens on the year list (viewMode) so a birth year is one click
    // away instead of paging back through hundreds of months. Future dates are
    // out of range, and an empty field lands on ~30 years ago rather than today.
    var $bday = $(".datetimepicker2emp");
    $bday.datetimepicker({
        allowInputToggle: true,
        showClose: true,
        showClear: true,
        useCurrent: false,
        // The input is readonly (picker-only entry); without this the plugin's
        // show() bails out on readonly inputs and nothing ever opens.
        ignoreReadonly: true,
        viewMode: "years",
        format: "YYYY-MM-DD",
        minDate: moment("1940-01-01"),
        maxDate: moment().endOf("day"),
        icons: {
            time: "fa fa-clock-o",
            date: "fa fa-calendar",
            up: "fa fa-chevron-up",
            down: "fa fa-chevron-down",
            previous: "fa fa-chevron-left",
            next: "fa fa-chevron-right",
            today: "fa fa-crosshairs",
            clear: "fa fa-trash",
            close: "fa fa-times",
        },
    });

    // Empty field → start the year list around a plausible birth year. An
    // employee being edited keeps their own year, so this only fires when blank.
    $bday.on("dp.show", function () {
        var dtp = $(this).data("DateTimePicker");
        if (dtp && !$.trim($(this).val())) dtp.viewDate(moment().subtract(30, "years"));
    });

    // Drilling down to a day leaves the widget in day mode; put it back on the
    // year list so the next open behaves like the first (safe while closed —
    // the plugin's showMode() no-ops when the widget isn't built).
    $bday.on("dp.hide", function () {
        var dtp = $(this).data("DateTimePicker");
        if (dtp) dtp.viewMode("years");
    });

    // The plugin already opens on focus, so the icon just hands focus over —
    // calling toggle() here would close what that focus just opened.
    $(document).on("click", ".bday-toggle", function () {
        $(this).closest(".input-group").find(".datetimepicker2emp").focus();
    });

    // Portal Login → show / hide the typed password (admin-only fields, so the
    // markup is absent for every other role and this simply never fires).
    $("#togglePortalPassword").on("click", function () {
        var $pw = $("#portal_password");
        var show = $pw.attr("type") === "password";
        $pw.attr("type", show ? "text" : "password");
        $("#togglePortalIcon")
            .toggleClass("ri-eye-off-line", !show)
            .toggleClass("ri-eye-line", show);
    });

    $('[name="department_id"]').change(function () {
        var did = $(this).val();
        var $pos = $("#position-select");
        $pos.val("");
        $pos.find("option.opt").each(function () {
            var pdid = $(this).attr("data-did") || "";
            // Only narrow the list when a department is actually picked, and never
            // hide positions that aren't tied to any department — they belong to
            // every one of them.
            $(this).prop("disabled", !!did && !!pdid && pdid != did);
        });
        // The dropdown widget renders its menu from the option list, so it has to
        // be rebuilt after toggling disabled. Without this the user clicks an item
        // from the stale menu, the widget paints the label, but the underlying
        // <select> refuses the now-disabled option and keeps an empty value —
        // which is why Parsley kept failing "Please select position."
        if ($pos.data("bs-select") && $pos.selectpicker) {
            $pos.selectpicker("refresh");
        }
    });
});

$("#form-add").on("submit", async function (e) {
    e.preventDefault();
    var form = $(this);

    form.parsley().validate();
    if (form.parsley().isValid()) {
        Swal.fire({
            title: "Saving, please wait...",
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            },
        });
        await new Promise((resolve) => setTimeout(resolve, 1000));
        $.ajax({
            url: "ajax.php?action=save_employee",
            method: "POST",
            data: $(this).serialize(),
            // dataType: 'JSON',
            error: (xhr, status, error) => {
                Swal.close();
                handleError(error || "");
                $(".submitbutton").removeAttr("disabled");
            },
            success: function (res) {
                res = (typeof res === 'string') ? res.trim() : res;
                // Server-side validation / error response
                if (typeof res === 'string' && res.indexOf('error:') === 0) {
                    $(".submitbutton").removeAttr("disabled");
                    Swal.fire({ icon: 'error', title: 'Could not save', text: res.slice(6) });
                    return;
                }
                if (!res) {
                    $(".submitbutton").removeAttr("disabled");
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong. Please try again.' });
                    return;
                }
                if (res == "updated") {
                    Swal.fire({
                        icon: "success",
                        title: "Success!",
                        text: "Employee successfully saved!",
                    }).then((result) => {
                        window.location.reload();
                    });
                    return;
                }
                Swal.fire({
                    icon: "success",
                    title: "Success!",
                    text: "Employee successfully saved!",
                    showCancelButton: true,
                    confirmButtonText: "View",
                    cancelButtonText: "Close",
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = `index.php?page=employee-details&id=${res}`;
                    } else {
                        window.location.reload();
                    }
                });
            },
        });
    } else {
        Swal.fire({
            icon: 'warning', // Use 'error', 'success', 'info', or 'warning'
            title: 'Validation Error',
            text: 'Please enter required fields!',
            confirmButtonColor: '#3085d6',
            confirmButtonText: 'OK'
          });
    }
});

$("#employee-contribution").on("submit", async function (e) {
    e.preventDefault();
    var form = $(this);

    form.parsley().validate();

    if (form.parsley().isValid()) {
        Swal.fire({
            title: "Saving, please wait...",
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            },
        });
        await new Promise((resolve) => setTimeout(resolve, 1000));
        $.ajax({
            url: "ajax.php?action=save_contribution",
            method: "POST",
            data: $(this).serialize(),
            error: (xhr, status, error) => {
                Swal.close();
                handleError(error || "");
                $(".submitbutton").removeAttr("disabled");
            },
            success: function (resp) {
                if (resp == 1) {
                    Swal.fire({
                        icon: "success",
                        title: "Success!",
                        text: "Cotribution successfully updated!",
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.reload();
                        }
                    });
                } else {
                    handleError(error || "");
                }
            },
        });
    }
});

$("#employee-deduction").on("submit", async function (e) {
    e.preventDefault();
    var form = $(this);

    form.parsley().validate();

    if (form.parsley().isValid()) {
        Swal.fire({
            title: "Saving, please wait...",
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            },
        });
        await new Promise((resolve) => setTimeout(resolve, 1000));
        $.ajax({
            url: "ajax.php?action=save_employee_deduction",
            method: "POST",
            data: $(this).serialize(),
            error: (xhr, status, error) => {
                Swal.close();
                handleError(error || "");
                $(".submitbutton").removeAttr("disabled");
            },
            success: function (resp) {
                if (resp == 1) {
                    Swal.fire({
                        icon: "success",
                        title: "Success!",
                        text: "New deduction successfully saved!",
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.reload();
                        }
                    });
                } else if (resp == 2) {
                    Swal.fire({
                        icon: "success",
                        title: "Success!",
                        text: "Deduction successfully updated!",
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.reload();
                        }
                    });
                }
            },
        });
    }
});

$("#employee-allowance").on("submit", async function (e) {
    e.preventDefault();
    var form = $(this);

    form.parsley().validate();

    if (form.parsley().isValid()) {
        Swal.fire({
            title: "Saving, please wait...",
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            },
        });
        await new Promise((resolve) => setTimeout(resolve, 1000));
        $.ajax({
            url: "ajax.php?action=save_employee_allowance",
            method: "POST",
            data: $(this).serialize(),
            error: (xhr, status, error) => {
                Swal.close();
                handleError(error || "");
                $(".submitbutton").removeAttr("disabled");
            },
            success: function (resp) {
                if (resp == 1) {
                    Swal.fire({
                        icon: "success",
                        title: "Success!",
                        text: "New allowance successfully saved!",
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.reload();
                        }
                    });
                } else if (resp == 2) {
                    Swal.fire({
                        icon: "success",
                        title: "Success!",
                        text: "Site successfully updated!",
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.reload();
                        }
                    });
                }
            },
        });
    }
});

function add_deductions(e) {
    $("#modal-deduction").modal("show");
}

function add_contritions(e) {
    $("#modal-contrition").modal("show");
}
function add_allowance(e) {
    $("#modal-allowance").modal("show");
}

function edit_details(e) {
    $("#addemployee").modal("show");
}

$("#type").change(function () {
    if ($(this).val() == 3) {
        $("#dfield").show();
    } else {
        $("#dfield").hide();
    }
});

$("#type2").change(function () {
    if ($(this).val() == 3) {
        $("#dfield2").show();
    } else {
        $("#dfield2").hide();
    }
});

$(".remove_deduction").click(function () {
    var d = $(this).attr("data-id");
    console.log({ d });
    _conf(
        "Are you sure to delete this employee's deduction?",
        "remove_deduction",
        [d]
    );
});

async function remove_deduction(id) {
    Swal.fire({
        title: "Removing, please wait...",
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        },
    });
    await new Promise((resolve) => setTimeout(resolve, 1000));
    $.ajax({
        url: "ajax.php?action=delete_employee_deduction",
        method: "POST",
        data: { id: parseInt(id) },
        error: (err) => {
            console.log(err);
            handleError();
        },
        success: function (resp) {
            $("#confirm_modal").modal("hide");
            if (resp == 1) {
                Swal.fire({
                    icon: "success",
                    title: "Success!",
                    text: "Selected deduction successfully deleted.",
                }).then((result) => {
                    if (result.isConfirmed) {
                        location.reload();
                    }
                });
            }
        },
    });
}

$(".remove_allowance").click(function () {
    _conf("Are you sure to delete this allowance?", "remove_allowance", [
        $(this).attr("data-id"),
    ]);
});

async function remove_allowance(id) {
    Swal.fire({
        title: "Removing, please wait...",
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        },
    });
    await new Promise((resolve) => setTimeout(resolve, 1000));
    $.ajax({
        url: "ajax.php?action=delete_employee_allowance",
        method: "POST",
        data: { id: id },
        error: (err) => console.log(err),
        success: function (resp) {
            if (resp == 1) {
                Swal.fire({
                    icon: "success",
                    title: "Success!",
                    text: "Selected allowance successfully deleted.",
                }).then((result) => {
                    if (result.isConfirmed) {
                        location.reload();
                    }
                });
            } else {
                handleError();
            }
        },
    });
}

$(".remove_contrition").click(function () {
    _conf("Are you sure to delete this contribution?", "remove_contrition", [
        $(this).attr("data-id"),
    ]);
});

async function remove_contrition(id) {
    Swal.fire({
        title: "Removing, please wait...",
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        },
    });
    await new Promise((resolve) => setTimeout(resolve, 1000));
    $.ajax({
        url: "ajax.php?action=delete_employee_contribution",
        method: "POST",
        data: { id: id },
        error: (err) => console.log(err),
        success: function (resp) {
            if (resp == 1) {
                Swal.fire({
                    icon: "success",
                    title: "Success!",
                    text: "Selected contrition successfully deleted.",
                }).then((result) => {
                    if (result.isConfirmed) {
                        location.reload();
                    }
                });
            } else {
                handleError();
            }
        },
    });
}

$(".select2").change(function () {
    let data_parsley_id = $(this).attr("data-parsley-id");
});

$(".remove_timelog").click(function () {
    var d = '"' + $(this).attr("data-id").toString() + '"';
    _conf(
        "Are you sure to delete this employee's log time?",
        "remove_timelogs",
        [d]
    );
});

function remove_timelogs(id) {
    $.ajax({
        url: "ajax.php?action=delete_employee_timelogs",
        method: "POST",
        data: { id: id },
        error: (err) => {
            console.log(err);
            handleError();
        },
        success: function (resp) {
            $("#confirm_modal").modal("hide");
            if (resp == 1) {
                toastr["success"](
                    "Selected log time successfully deleted!",
                    "Success Notification"
                );
            }
            setTimeout(function () {
                location.reload();
            }, 2000);
        },
    });
}

$(document).ready(function () {
    $(".sss_no").change(function () {
        $.ajax({
            url: "ajax.php?action=save_employee_contribution",
            method: "POST",
            data: {
                id: employee_id,
                value: $(this).val(),
                type: $(this).attr("data-id"),
            },
            error: (xhr, status, error) => {
                Swal.close();
                handleError(error || "");
                $(".submitbutton").removeAttr("disabled");
            },
            success: function (resp) {
                if (resp == 1) {
                    Swal.fire({
                        icon: "success",
                        title: "Success!",
                        text: "Contribution successfully saved!",
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // window.location.reload();
                        }
                    });
                }
            },
        });
    });
});

function add_loans(e) {
    $("#modal-loan").modal("show");
}

$("#employee-loan").on("submit", async function (e) {
    e.preventDefault();
    var form = $(this);

    form.parsley().validate();

    if (form.parsley().isValid()) {
        Swal.fire({
            title: "Saving, please wait...",
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            },
        });
        await new Promise((resolve) => setTimeout(resolve, 1000));
        $.ajax({
            url: "ajax.php?action=save_employee_loan",
            method: "POST",
            data: $(this).serialize(),
            error: (xhr, status, error) => {
                Swal.close();
                handleError(error || "");
                $(".submitbutton").removeAttr("disabled");
            },
            success: function (resp) {
                if (resp == 1) {
                    Swal.fire({
                        icon: "success",
                        title: "Success!",
                        text: "New loan successfully saved!",
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.reload();
                        }
                    });
                } else if (resp == 2) {
                    Swal.fire({
                        icon: "success",
                        title: "Success!",
                        text: "Loan successfully updated!",
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.reload();
                        }
                    });
                }
            },
        });
    }
});

function saveLoanActive() {
    var loan_id = $("#loan-select").val();
    var loan = $("#loan").val();
    var loan_deduction = $("#loan_deduction").val();
    if (!loan_id) {
        Swal.fire({
            icon: "error",
            title: "Oops...",
            text: "Please select loan!",
        });
        return;
    }
    Swal.fire({
        title: "Confirmation",
        text: "Are you certain you want to proceed with updating the loan?",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonColor: "#2196F3",
        confirmButtonText: "Yes, update it!",
        cancelButtonText: "No, cancel!",
        reverseButtons: true,
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: "ajax.php?action=active_employee_loan",
                method: "POST",
                data: { loan_id, id: employee_id, loan, loan_deduction },
                error: (err) => console.log(err),
                success: function (resp) {
                    if (resp == 1) {
                        Swal.fire({
                            icon: "success",
                            title: "Success!",
                            text: "Loan  successfully updated.",
                        }).then((result) => {
                            if (result.isConfirmed) {
                                location.reload();
                            }
                        });
                    } else {
                        handleError();
                    }
                },
            });
        }
    });
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString("en-US", {
        month: "long",
        day: "2-digit",
        year: "numeric",
    });
}

function loanHistory(id) {
    $("#modal-loan-history").modal("show");
    $.ajax({
        url: "ajax.php?action=loan_history_details",
        method: "POST",
        dataType: "JSON",
        data: { id: id },
        error: (err) => {
            console.log(err);
            handleError();
        },
        success: function (res) {
            if (res && res.length > 0) {
                let table = `
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Payroll ID</th>
                                <th>Current Balance</th>
                                <th>Amount</th>
                                <th>New Balance</th>
                                
                            </tr>
                        </thead>
                        <tbody>`;

                res.forEach((row) => {
                    table += `
                        <tr>
                            <td> <a data-toggle="tooltip" title="View Payroll Details"  href="index.php?page=payroll_calculations&id=${
                                row.payroll_id
                            }" class="btn btn-link waves-effect" >${
                        row.ref_no
                    }</a> (${formatDate(row.date_from)} - ${formatDate(
                        row.date_to
                    )})</td>
                            <td class="text-right text-info">
                                ${parseFloat(row.current_bal).toLocaleString(
                                    "en-US",
                                    {
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2,
                                    }
                                )}
                            </td>
                            <td class="text-right text-success">
                                ${parseFloat(row.amount).toLocaleString(
                                    "en-US",
                                    {
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2,
                                    }
                                )}
                            </td>
                            <td class="text-right text-danger">
                                ${parseFloat(row.new_bal).toLocaleString(
                                    "en-US",
                                    {
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2,
                                    }
                                )}
                            </td>

                        </tr>`;
                });

                table += `</tbody></table>`;
                $(document).ready(function () {
                    $('[data-toggle="tooltip"]').tooltip(); // Initialize tooltips globally
                });

                // Append or replace the content inside a div (assumed div with ID #loanHistoryDiv)
                $("#loanHistoryDiv").html(table);
            } else {
                $("#loanHistoryDiv").html("<p>No loan history found.</p>");
            }
        },
    });
}

function editLoan(e) {
    $("#loan_id").val($(e).attr("loan_id"));
    $("#loan_employee_id").val($(e).attr("employee_id"));
    $("#loan-select").val($(e).attr("loan_type"));
    $("#loan_date").val($(e).attr("loan_date"));
    $("#loan_effective_date").val($(e).attr("effective_date") || "");
    $("#damount").val($(e).attr("damount"));
    $("#loan_balance").val($(e).attr("loan_balance"));
    $("#loan_amount").val($(e).attr("loan_amount"));
    console.log($(e).attr("loan_status"));
    if ($(e).attr("loan_status") == 1) {
        $("#loan_status").prop("checked", true);
    }
    $("#modal-loan").modal("show");
}

$(document).on("hide.bs.modal", "#modal-loan", function () {
    window.location.reload();
});

function editContriAmount(el) {
    $("#contribution-id").val($(el).attr("data-id"));
    $("#contribution-name").val($(el).attr("data-name"));
    $("#contribution-amount").val($(el).attr("data-amount"));
    $("#modal-contrition").modal("show");
    console.log(el);
}



// ── Import wizard: upload → server-side dry-run preview → confirm ──
(function () {
    var money = function (n) {
        n = parseFloat(n) || 0;
        return n > 0
            ? n.toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })
            : '<span class="text-muted">—</span>';
    };
    var esc = function (s) {
        return $("<div>").text(s == null ? "" : String(s)).html();
    };

    function setStep(step) {
        $("#modal-upload .imp-step").each(function () {
            $(this).toggleClass("active", +$(this).data("step") <= step);
        });
        var preview = step >= 2;
        $("#import-step-upload").toggleClass("d-none", preview);
        $("#import-step-preview").toggleClass("d-none", !preview);
        $("#import-preview-btn").toggleClass("d-none", preview);
        $("#import-back-btn, #import-confirm-btn").toggleClass("d-none", !preview);
        $("#import-dialog").toggleClass("modal-xl", preview);
    }

    // Dropzone: filename chip + drag highlight (the input itself covers the zone).
    $("#excelFile").on("change", function () {
        var f = this.files[0];
        $("#import-file-chip").toggleClass("show", !!f);
        if (f) {
            $("#import-file-name").text(f.name);
            $("#import-file-size").text((f.size / 1024).toFixed(0) + " KB");
        }
    });
    $("#import-dropzone")
        .on("dragover dragenter", function () { $(this).addClass("dragover"); })
        .on("dragleave drop", function () { $(this).removeClass("dragover"); });

    function badge(action) {
        if (action === "insert") return '<span class="imp-badge b-new">New</span>';
        if (action === "update") return '<span class="imp-badge b-upd">Update</span>';
        return '<span class="imp-badge b-skip">Skip</span>';
    }

    function renderPreview(data) {
        var c = data.counts;
        $("#import-stats").html(
            '<div class="imp-stat"><div class="v">' + data.total + '</div><div class="l">Rows</div></div>' +
            '<div class="imp-stat s-new"><div class="v">' + c.insert + '</div><div class="l">New</div></div>' +
            '<div class="imp-stat s-upd"><div class="v">' + c.update + '</div><div class="l">Updates</div></div>' +
            '<div class="imp-stat s-skip"><div class="v">' + c.skip + '</div><div class="l">Skipped</div></div>' +
            '<div class="imp-stat s-warn"><div class="v">' + c.warning + '</div><div class="l">Warnings</div></div>'
        );

        var html = "";
        $.each(data.rows, function (_, r) {
            var name = (r.lastname || r.firstname)
                ? esc(r.lastname) + ", " + esc(r.firstname) + (r.middlename ? " " + esc(r.middlename) : "")
                : '<span class="text-muted fst-italic">(no name)</span>';
            var issues = "";
            if (r.issues.length) {
                issues = '<ul class="imp-issues">';
                $.each(r.issues, function (_, i) { issues += "<li>" + esc(i) + "</li>"; });
                issues += "</ul>";
            }
            var ids = [];
            if (r.sss_no) ids.push("SSS " + esc(r.sss_no));
            if (r.ph_no) ids.push("PHIC " + esc(r.ph_no));
            if (r.hdmf_no) ids.push("HDMF " + esc(r.hdmf_no));

            html +=
                '<tr class="' + (r.action === "skip" ? "row-skip" : r.issues.length ? "row-warn" : "") + '">' +
                '<td class="text-muted">' + r.row_no + "</td>" +
                "<td>" + badge(r.action) + "</td>" +
                '<td><div class="fw-semibold">' + name + "</div>" +
                (ids.length ? '<div class="imp-sub">' + ids.join(" · ") + "</div>" : "") + issues + "</td>" +
                "<td>" + esc(r.position || "—") +
                (r.position_new && r.action !== "skip" ? ' <span class="imp-badge b-new">+ new</span>' : "") + "</td>" +
                "<td>" + esc(r.clas) + "</td>" +
                '<td class="imp-num">' + money(r.daily_rate) + "</td>" +
                '<td class="imp-num">' + money(r.basic_pay) + "</td>" +
                "<td>" + esc(r.rate_type) + "</td>" +
                '<td class="imp-num">' + money(r.sss) + " / " + money(r.phic) + " / " + money(r.hdmf) + "</td>" +
                "<td>" + esc(r.shift) + "</td>" +
                "<td>" + (r.deduction ? esc(r.deduction) + " · " + money(r.ded_amount) : '<span class="text-muted">—</span>') + "</td>" +
                "</tr>";
        });
        $("#import-preview-body").html(html);
        $("#import-truncated-note").toggle(!!data.truncated);

        var importable = c.insert + c.update;
        $("#import-confirm-btn")
            .prop("disabled", importable === 0)
            .html('<i class="ri-check-double-line me-1"></i>Confirm Import (' + importable + ")");
        setStep(2);
    }

    // Step 1 → dry-run preview (no writes).
    $("#uploadForm").on("submit", function (e) {
        e.preventDefault();
        var form = $(this);
        form.parsley().validate();
        if (!form.parsley().isValid()) return;

        Swal.fire({
            title: "Analyzing file...",
            text: "Building the import preview — nothing is saved yet.",
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
        });
        $.ajax({
            url: "ajax.php?action=preview_import_employee",
            type: "POST",
            data: new FormData(this),
            contentType: false,
            processData: false,
            dataType: "json",
            error: (xhr, status, error) => {
                Swal.close();
                handleError(error || "");
            },
            success: function (resp) {
                Swal.close();
                if (!resp || !resp.result) {
                    Swal.fire({
                        icon: "error",
                        title: "Cannot preview file",
                        text: (resp && resp.message) || "The file could not be read.",
                    });
                    return;
                }
                renderPreview(resp);
            },
        });
    });

    // Step 2 → commit for real.
    $("#import-confirm-btn").on("click", function () {
        Swal.fire({
            title: "Importing, please wait...",
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
        });
        $.ajax({
            url: "ajax.php?action=import_employee",
            type: "POST",
            data: new FormData(document.getElementById("uploadForm")),
            contentType: false,
            processData: false,
            error: (xhr, status, error) => {
                Swal.close();
                handleError(error || "");
            },
            success: function () {
                Swal.fire({
                    icon: "success",
                    title: "Import complete!",
                    text: "Employees were successfully imported.",
                }).then((result) => {
                    if (result.isConfirmed) window.location.reload();
                });
            },
        });
    });

    $("#import-back-btn").on("click", function () { setStep(1); });

    // Reset the wizard whenever the modal closes.
    $(document).on("hidden.bs.modal", "#modal-upload", function () {
        setStep(1);
        $("#uploadForm")[0].reset();
        $("#uploadForm").parsley().reset();
        $("#import-file-chip").removeClass("show");
        $("#import-preview-body").empty();
    });
})();


