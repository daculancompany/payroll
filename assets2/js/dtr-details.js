(function ($) {
    $(function () {
        $("#date-picker").select2({
            dropdownParent: $("#form-add"),
        });
        $("#employee_id").select2({
            dropdownParent: $("#form-add"),
        });
    });
})(jQuery);

$(document).ready(function () {
    function initializeDateTimePicker() {
        $(".date").datetimepicker({
            allowInputToggle: true,
            showClose: true,
            showClear: true,
            showTodayButton: true,
            format: "hh:mm:ss A",
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
    }

    // Initialize the datetime picker for the initial input
    initializeDateTimePicker();
    // Function to clone the div
    $("#container-clone").on("click", ".cloneButton", function () {
        var clonedDiv = $(this).closest(".item").clone();
        clonedDiv.find(".date").val("08:00:00"); // Clear the value of the cloned input
        $("#container-clone").append(clonedDiv);
        initializeDateTimePicker(); // Re-initialize the datetime picker for the new input
    });

    // Function to remove the div
    $("#container-clone").on("click", ".removeButton", function () {
        if ($("#container-clone .item").length > 1) {
            // Ensure at least one item remains
            $(this).closest(".item").remove();
        } else {
            Swal.fire({ icon: "warning", title: "Cannot remove", text: "You must have at least one date and time input field." });
        }
    });

    $("#form-add").on("submit", async function (e) {
        e.preventDefault();
        var form = $(this);

        form.parsley().validate();

        if (form.parsley().isValid()) {
            e.preventDefault();
            // $('.submitbutton').attr('disabled',true).html('Saving...');
            Swal.fire({
                title: "Saving, please wait...",
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                },
            });
            await new Promise((resolve) => setTimeout(resolve, 1000));
            $.ajax({
                url: "ajax.php?action=save_employee_attendance",
                method: "POST",
                data: $(this).serialize(),
                dataType: "JSON",
                error: (xhr, status, error) => {
                    Swal.close();
                    handleError(error || "");
                },
                success: function (res) {
                    if (res?.result) {
                        Swal.fire({
                            icon: "success",
                            title: "Success!",
                            text: "Attendace  successfully saved.",
                        }).then((result) => {
                            if (result.isConfirmed) {
                                $("#modal").modal("hide");
                                form[0].reset();
                                if (typeof refreshDtrPanel === "function") refreshDtrPanel();
                            }
                        });
                    } else {
                        Swal.close();
                        handleError(res?.message || "");
                    }
                },
            });
        }
    });
});

async function deleteDTRLogs(id) {
    const result = await Swal.fire({
        title: "Are you sure?",
        text: "You won't be able to revert this!",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: "Yes, delete it!",
    });

    // If the user confirmed the deletion
    if (result.isConfirmed) {
        // Show loading dialog
        Swal.fire({
            title: "Deleting, please wait...",
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            },
        });
        await new Promise((resolve) => setTimeout(resolve, 1000));
        $.ajax({
            url: "ajax.php?action=delete_dtr_logs",
            method: "POST",
            data: {
                id: id,
            },
            dataType: "JSON",
            error: (xhr, status, error) => {
                Swal.close();
                handleError(error || "");
            },
            success: function (res) {
                if (res?.result) {
                    Swal.fire({
                        icon: "success",
                        title: "Success!",
                        text: "Selected payroll successfully deleted.",
                    }).then((result) => {
                        if (result.isConfirmed && typeof refreshDtrPanel === "function") {
                            refreshDtrPanel();
                        }
                    });
                } else {
                    Swal.close();
                    handleError(res?.message || "");
                }
            },
        });
    }
}

function handleError(e) {
    $(".submitbutton").removeAttr("disabled");
    $(".fa-spinner-button").hide();
    toastr["error"](
        e ? e : "Someting went wrong. Please contact administrator.",
        "Error Notification"
    );
}

function addSchedule(id) {
    $("#id").val(id);
    $("#modal").modal("show");
}

// Single event handler for all updates
$(document).on("click", ".update-dtr-field", function () {
    const button = $(this);
    const id = button.data("id");
    const fieldType = button.data("field");
    const inputField = button.closest(".editable-field").find("input");


    updateDTRField(inputField[0], id, fieldType);
});

async function updateDTRField(el, id, fieldType) {
   
    const inputField = $(el).closest(".input-group").find('input[type="text"]');
    const value =  $(el).val();
    

    // Field configuration
    const fieldConfig = {
        work_hours: {
            title: "Hours Worked",
            field: "work_hours",
            color: "#28a745",
        },
        overtime: {
            title: "Overtime",
            field: "overtime",
            color: "#ffc107",
        },
        undertime: {
            title: "Undertime",
            field: "undertime",
            color: "#17a2b8",
        },
        late: {
            title: "Late",
            field: "late",
            color: "#dc3545",
        },
    };

    const config = fieldConfig[fieldType];
    if (!config) {
        handleError("Invalid field type");
        return;
    }

    // Validation
    if (Number.isNaN(value) || value < 0) {
        Swal.fire({
            icon: "error",
            title: "Invalid Value",
            text: `Please enter a valid ${config.title.toLowerCase()} value.`,
        });
        return;
    }

    // Confirmation dialog
    const result = await Swal.fire({
        title: `Update ${config.title}?`,
        text: `Are you sure you want to update ${config.title.toLowerCase()} to ${value}?`,
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: config.color,
        cancelButtonColor: "#6c757d",
        confirmButtonText: "Yes, update it!",
        cancelButtonText: "Cancel",
    });

    if (!result.isConfirmed) return;

    // Show loading
    Swal.fire({
        title: `Updating ${config.title}...`,
        text: "Please wait while we save your changes.",
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading(),
    });

    try {
        // Simulate API delay
        await new Promise((resolve) => setTimeout(resolve, 800));

        // Make API call
        const response = await $.ajax({
            url: "ajax.php?action=update_dtr_logs",
            method: "POST",
            data: {
                id: id,
                [config.field]: value,
            },
            dataType: "JSON",
        });

        // Handle response
        if (response?.result) {
            await Swal.fire({
                icon: "success",
                title: "Updated!",
                text: `${config.title} has been successfully updated.`,
                confirmButtonColor: config.color,
                timer: 2000,
                timerProgressBar: true,
            });

            // Optional: Update UI without full reload for better UX
            updateUIAfterSuccess(el, value, config);
        } else {
            throw new Error(response?.message || "Update failed");
        }
    } catch (error) {
        Swal.close();
        handleError(error.message || "An error occurred while updating");
    }
}

// Update UI without a page reload: flash the edited field, then re-fetch the
// panel so per-day/per-employee/grand totals recompute server-side and stay accurate.
function updateUIAfterSuccess(el, value, config) {
    // Update the input field visually
    const inputField = $(el).closest(".input-group").find('input[type="text"]');

    // Add success animation
    inputField.addClass("update-success");
    setTimeout(() => inputField.removeClass("update-success"), 2000);

    if (typeof refreshDtrPanel === "function") refreshDtrPanel();
}

// Individual wrapper functions for backward compatibility
async function updateHoursWork(el, id) {
    return updateDTRField(el, id, "work_hours");
}

async function updateOvertime(el, id) {
    return updateDTRField(el, id, "overtime");
}

async function updateUndertime(el, id) {
    return updateDTRField(el, id, "undertime");
}

async function updateLate(el, id) {
    return updateDTRField(el, id, "late");
}

// Enhanced error handler
function handleError(message) {
    Swal.fire({
        icon: "error",
        title: "Error",
        text: message || "Something went wrong. Please try again.",
        confirmButtonColor: "#dc3545",
    });
}

let isduplicate = "<?= $is_duplicate ?>";

// (function($) {
//     $(function() {
//         var isMouseDown = false,
//             $panelOne = $(".panel.one"),
//             $panelTwo = $(".panel.two"),
//             $panelContainer = $panelOne.parent(),
//             getParentWidth = function() {
//                 return $panelContainer.width();
//             },
//             mouseMoveHandler = function(e) {
//                 if (!isMouseDown) return;

//                 var clientX = e.clientX || (e.touches && e.touches[0].clientX);
//                 if (isNaN(clientX))
//                     return;

//                 var width = (clientX / getParentWidth()) * 100;

//                 // don't allow a value that's smaller than zero;
//                 width = width < 0 ? 0 : width;

//                 // apply size to panel 1
//                 $panelOne.css({
//                     width: width + "%"
//                 });

//                 // apply size to panel 2
//                 $panelTwo.css({
//                     width: 100 - width + "%"
//                 });
//             };

//         // mouseDown event
//         $(".slider").on("mousedown touchstart", function() {
//             // only bind a the mouseMove handler on the first cycle
//             !isMouseDown && $panelContainer.on("mousemove touchmove", mouseMoveHandler);
//             isMouseDown = true;
//         });

//         $(window).on("mouseup touchend", function() {
//             isMouseDown = false;
//             // detach then mouseMove handler
//             $panelContainer.off("mousemove touchmove");
//         });
//     });
// })(jQuery);
$(document).ready(function () {
    // $(".table-basic").freezeTable();
    // $('#table-modal').one('shown.bs.modal', function(e) {
    //     $(this).find(".table-modal").freezeTable({
    //         'container': '#table-modal.modal',
    //     });
    // });
    // $(".table-columns-only").freezeTable({
    //     'freezeHead': false,
    // });
    // $(".table-head-only").freezeTable({
    //     'freezeColumn': false,
    // });
    // // 2 Columns to be fixed
    // $(".table-multi-columns").freezeTable({
    //     'columnNum': 2,
    // });
    // // Shadow enabled
    // $(".table-shadow").freezeTable({
    //     'shadow': true,
    // });
    // // Customized styles
    // $(".table-wrap-styles").freezeTable({
    //     'headWrapStyles': {
    //         'box-shadow': '0px 9px 10px -5px rgba(159, 159, 160, 0.8)'
    //     },
    // });
    // $(".table-with-scrollbar").freezeTable({
    //     'scrollBar': true,
    // });
    // // Freeze Column(s) Keep
    // $(".table-column-keep").freezeTable({
    //     'columnNum': 2,
    //     'columnKeep': true,
    // });
});
$(document).ready(function () {
    $("#myInput").on("keyup", function () {
        var value = $(this).val().toLowerCase();
        $("#table-1 tbody tr").filter(function () {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
        });
        // $("#table-2 tbody tr").filter(function() {
        //     $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        // });
    });
});

// ── Resolve a disputed review (shared shape with payroll page) ──
function openResolveDispute(type, reviewId, empName) {
    Swal.fire({
        title: "Resolve dispute",
        html: 'Reply to <b>' + (empName || 'employee') + '</b>. They will be notified.',
        input: "textarea",
        inputPlaceholder: "Explain what was checked / corrected…",
        inputAttributes: { "aria-label": "Reply" },
        showCancelButton: true,
        confirmButtonColor: "#0f9d58",
        confirmButtonText: "Resolve & notify",
        preConfirm: (val) => {
            if (!val || !val.trim()) {
                Swal.showValidationMessage("A reply is required.");
                return false;
            }
            return val.trim();
        },
    }).then((result) => {
        if (!result.isConfirmed) return;
        Swal.fire({ title: "Saving…", allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: "ajax.php?action=resolve_review_dispute",
            method: "POST",
            dataType: "JSON",
            data: { type: type, id: reviewId, reply: result.value },
            error: (xhr, status, error) => { Swal.close(); handleError(error || ""); },
            success: function (res) {
                if (res?.result) {
                    Swal.fire({ icon: "success", title: "Resolved", text: res.message })
                        .then((r) => { if (r.isConfirmed) location.reload(); });
                } else {
                    Swal.close(); handleError(res?.message || "");
                }
            },
        });
    });
}

// ── Remind employees who haven't reviewed this DTR yet ──
function remindDtrReview(id) {
    Swal.fire({
        title: "Send reminder?",
        text: "Only employees who haven't reviewed yet will be notified.",
        icon: "question",
        showCancelButton: true,
        confirmButtonColor: "#f7b84b",
        confirmButtonText: "Yes, remind them",
    }).then((result) => {
        if (!result.isConfirmed) return;
        Swal.fire({ title: "Sending…", allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: "ajax.php?action=remind_dtr_review",
            method: "POST",
            dataType: "JSON",
            data: { id: id },
            error: (xhr, status, error) => { Swal.close(); handleError(error || ""); },
            success: function (res) {
                if (res?.result) {
                    Swal.fire({ icon: "success", title: "Reminder sent", text: res.message });
                } else {
                    Swal.close(); handleError(res?.message || "");
                }
            },
        });
    });
}

function approveDtr(id) {
    if (isduplicate == "1") {
        Swal.fire({
            icon: "error",
            title: "Erorr!",
            text: "The system cannot process duplicate attendance entries. Please ensure you are submitting a unique record.",
        }).then((result) => {});
        return;
    }
    Swal.fire({
        title: "Are you sure?",
        text: "You are about to approve this DTR.",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: "Yes, approve it!",
    }).then(async (result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: "Approving, please wait...",
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                },
            });
            await new Promise((resolve) => setTimeout(resolve, 1000));
            $.ajax({
                url: "ajax.php?action=update_status_dtr",
                method: "POST",
                dataType: "JSON",
                data: {
                    id,
                    status,
                },
                error: (xhr, status, error) => {
                    Swal.close();
                    handleError(error || "");
                    $(".submitbutton").removeAttr("disabled");
                },
                success: function (res) {
                    if (res?.result) {
                        Swal.fire({
                            icon: "success",
                            title: "Success!",
                            text: "DTR successfully approve.",
                        }).then((result) => {
                            if (result.isConfirmed) {
                                window.location.href = `index.php?page=dtr`;
                            }
                        });
                    } else {
                        Swal.fire({
                            icon: "error",
                            title: "Erorr!",
                            text: res.message,
                        }).then((result) => {});
                        return false;
                    }
                },
            });
        }
    });
}

// Send the whole DTR batch to employees for review (status 1 → 3).
function sendDtrForReview(id) {
    if (isduplicate == "1") {
        Swal.fire({
            icon: "error",
            title: "Duplicate detected",
            text: "Resolve the duplicate attendance entries before sending this DTR for review.",
        });
        return;
    }
    Swal.fire({
        title: "Send for Employee Review?",
        text: "Employees on this DTR will be notified to confirm their own attendance before final approval.",
        icon: "question",
        showCancelButton: true,
        confirmButtonColor: "#0dcaf0",
        cancelButtonColor: "#6c757d",
        confirmButtonText: "Yes, send for review",
    }).then(async (result) => {
        if (!result.isConfirmed) return;
        Swal.fire({
            title: "Sending, please wait...",
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
        });
        await new Promise((resolve) => setTimeout(resolve, 800));
        $.ajax({
            url: "ajax.php?action=send_dtr_for_review",
            method: "POST",
            dataType: "JSON",
            data: { id: id },
            error: (xhr, status, error) => {
                Swal.close();
                handleError(error || "");
            },
            success: function (res) {
                if (res?.result) {
                    Swal.fire({
                        icon: "success",
                        title: "Sent for Review",
                        text: res.message || "Employees have been notified.",
                    }).then((r) => {
                        if (r.isConfirmed) location.reload();
                    });
                } else {
                    Swal.fire({ icon: "error", title: "Error!", text: res.message });
                }
            },
        });
    });
}

// ── Approve / disapprove attendance records (DTR_details.status) ──────────────
// decision: 1 = approve, 2 = disapprove. Updates the DOM in place — no page reload.

function _recBadge(status) {
    if (status === 1) return '<span class="badge bg-success" style="font-size:10px;"><i class="ri-checkbox-circle-line me-1"></i>Approved</span>';
    if (status === 2) return '<span class="badge bg-danger" style="font-size:10px;"><i class="ri-close-circle-line me-1"></i>Disapproved</span>';
    return '<span class="badge bg-warning text-dark" style="font-size:10px;"><i class="ri-time-line me-1"></i>Pending</span>';
}

// Update a record's status badge + button states, live — in BOTH the
// By Day table row and the By Employee card entry (same data-rec-id).
function _applyRowStatus(id, status) {
    document.querySelectorAll('[data-rec-id="' + id + '"]').forEach(function (el) {
        el.dataset.recStatus = status;
        var appr = el.querySelector(".btn-rec-approve");
        var disa = el.querySelector(".btn-rec-disapprove");
        if (appr) appr.disabled = (status === 1);
        if (disa) disa.disabled = (status === 2);
        var chk = el.querySelector(".rec-check");
        if (chk) chk.checked = false;
        // subtle row/entry tint
        el.classList.toggle("is-approved", status === 1);
        el.classList.toggle("is-disapproved", status === 2);
        if (el.tagName === "TR") {
            el.style.background = status === 1 ? "rgba(15,157,88,.06)" : (status === 2 ? "rgba(198,40,40,.06)" : "");
        }
    });
    document.querySelectorAll('[data-rec-badge="' + id + '"]').forEach(function (badge) {
        badge.innerHTML = _recBadge(status);
    });
}

// Recompute summary cards, batch-approve button and per-day buttons from the DOM.
function _recomputeTotals() {
    var appr = 0, disa = 0, pend = 0, perDay = {};
    document.querySelectorAll("tr[data-rec-id]").forEach(function (tr) {
        var s = parseInt(tr.dataset.recStatus || "0", 10);
        if (s === 1) appr++;
        else if (s === 2) disa++;
        else { pend++; var d = tr.dataset.recDate; perDay[d] = (perDay[d] || 0) + 1; }
    });
    var setTxt = function (idv, val) { var el = document.getElementById(idv); if (el) el.textContent = val; };
    setTxt("stat-approved", appr);
    setTxt("stat-disapproved", disa);
    setTxt("stat-pending", pend);

    var b = document.getElementById("btn-batch-approve");
    if (b) {
        b.disabled = (pend > 0) || (b.dataset.dup === "1");
        var badge = document.getElementById("batch-pending-badge");
        var cnt   = document.getElementById("batch-pending-count");
        if (cnt) cnt.textContent = pend;
        if (badge) badge.style.display = (pend > 0) ? "" : "none";
    }

    document.querySelectorAll(".approveday-btn").forEach(function (btn) {
        var d = btn.dataset.approvedayDate;
        var c = perDay[d] || 0;
        var span = btn.querySelector(".approveday-count");
        if (span) span.textContent = c;
        btn.style.display = (c > 0) ? "" : "none";
    });

    // Per-employee summary chips + Approve All button on each card
    document.querySelectorAll("#view-by-employee .ecard").forEach(function (card) {
        var a = 0, p = 0, d = 0;
        card.querySelectorAll(".ecard-entry[data-rec-id]").forEach(function (en) {
            var s = parseInt(en.dataset.recStatus || "0", 10);
            if (s === 1) a++; else if (s === 2) d++; else p++;
        });
        var set = function (cls, val) { var el = card.querySelector(cls); if (el) el.textContent = val; };
        set(".emp-sum-appr", a);
        set(".emp-sum-pend", p);
        set(".emp-sum-disa", d);
        var btn = card.querySelector(".ecard-approve-all");
        if (btn) {
            btn.style.display = (p > 0) ? "" : "none";
            var c = btn.querySelector(".emp-appr-count");
            if (c) c.textContent = p;
        }
    });
}

function _toast(msg) {
    Swal.fire({ toast: true, position: "top-end", icon: "success", title: msg, timer: 1600, showConfirmButton: false });
}

function _postDecision(payload, decision, confirmText, applyFn) {
    var label = decision === 1 ? "Approve" : "Disapprove";
    Swal.fire({
        title: label + "?",
        text: confirmText,
        icon: "question",
        showCancelButton: true,
        confirmButtonColor: decision === 1 ? "#0f9d58" : "#c62828",
        confirmButtonText: "Yes, " + label.toLowerCase(),
    }).then(function (result) {
        if (!result.isConfirmed) return;
        payload.decision = decision;
        $.ajax({
            url: "ajax.php?action=decide_dtr_details",
            method: "POST",
            dataType: "JSON",
            data: payload,
            error: function () { handleError(""); },
            success: function (res) {
                if (res && res.result) {
                    applyFn();
                    _recomputeTotals();
                    _resetRecSelection();
                    _toast((res.affected || 0) + " record(s) " + (decision === 1 ? "approved" : "disapproved"));
                } else {
                    Swal.fire({ icon: "error", title: "Error!", text: (res && res.message) || "Failed." });
                }
            },
        });
    });
}

// Single record
function decideRecord(id, decision) {
    _postDecision({ ids: [id] }, decision,
        "This attendance record will be " + (decision === 1 ? "approved." : "disapproved."),
        function () { _applyRowStatus(id, decision); });
}

// All pending records for one day
function approveDay(ddtrId, date) {
    _postDecision({ ddtr_id: ddtrId, date: date }, 1,
        "All pending records for " + date + " will be approved.",
        function () {
            document.querySelectorAll('tr[data-rec-id][data-rec-date="' + date + '"]').forEach(function (tr) {
                if (parseInt(tr.dataset.recStatus || "0", 10) === 0) _applyRowStatus(tr.dataset.recId, 1);
            });
        });
}

// ── Checkbox selection ────────────────────────────────────────────────────────
// A record can appear in BOTH views (table row + employee card), so ids are
// deduped and checkbox state is mirrored across the two views.
function _selectedRecIds() {
    var seen = {}, ids = [];
    document.querySelectorAll(".rec-check:checked").forEach(function (c) {
        if (!seen[c.value]) { seen[c.value] = true; ids.push(c.value); }
    });
    return ids;
}
function _refreshRecSelCount() {
    var n    = _selectedRecIds().length;
    var cnt  = document.getElementById("rec-sel-count");
    var appr = document.getElementById("btn-approve-selected");
    var disa = document.getElementById("btn-disapprove-selected");
    if (cnt)  cnt.textContent = n;
    if (appr) appr.disabled = (n === 0);
    if (disa) disa.disabled = (n === 0);
}
function _resetRecSelection() {
    var all = document.getElementById("chk-all-records");
    if (all) all.checked = false;
    _refreshRecSelCount();
}
document.addEventListener("change", function (e) {
    if (!e.target) return;
    if (e.target.id === "chk-all-records") {
        document.querySelectorAll(".rec-check").forEach(function (c) { c.checked = e.target.checked; });
        _refreshRecSelCount();
    } else if (e.target.classList && e.target.classList.contains("rec-check")) {
        // mirror the same record's checkbox in the other view
        var v = e.target.value, on = e.target.checked;
        document.querySelectorAll('.rec-check[value="' + v + '"]').forEach(function (c) { c.checked = on; });
        _refreshRecSelCount();
    }
});

// Bulk approve/disapprove selected checkboxes
function decideSelected(decision) {
    var ids = _selectedRecIds();
    if (!ids.length) return;
    _postDecision({ ids: ids }, decision,
        ids.length + " selected record(s) will be " + (decision === 1 ? "approved." : "disapproved."),
        function () { ids.forEach(function (id) { _applyRowStatus(id, decision); }); });
}

// Approve every pending record of one employee (By Employee card header button)
function approveEmployee(btn, name) {
    var card = btn.closest(".ecard");
    if (!card) return;
    var ids = [];
    card.querySelectorAll(".ecard-entry[data-rec-id]").forEach(function (en) {
        if (parseInt(en.dataset.recStatus || "0", 10) === 0) ids.push(en.dataset.recId);
    });
    if (!ids.length) return;
    _postDecision({ ids: ids }, 1,
        ids.length + " pending record(s) of " + name + " will be approved.",
        function () { ids.forEach(function (id) { _applyRowStatus(id, 1); }); });
}
