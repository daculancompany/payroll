let id = null;
$(document).ready(function () {
    // Get p2 from URL or set default
    function getUrlParameter(name) {
        name = name.replace(/[\[]/, "\\[").replace(/[\]]/, "\\]");
        var regex = new RegExp("[\\?&]" + name + "=([^&#]*)");
        var results = regex.exec(location.search);
        return results === null
            ? ""
            : decodeURIComponent(results[1].replace(/\+/g, " "));
    }
    var p2Raw = getUrlParameter("p2"); // get raw p2 value from URL

    // convert true/false or other to yes/no
    var p2 = p2Raw === "true" ? "yes" : "no";
    $("#table").DataTable({
        processing: true,
        serverSide: true,
        order: [[2, "desc"]], // Default sort: Period (date) newest first
        ajax: {
            url: "payroll-server.php",
            type: "POST",
            data: function (d) {
                d.p2 = p2;
            },
        },
        columns: [
            { data: "ref_no" },
            { data: "employer_name" },
            { data: "period" },
            { data: "category" },
            { data: "type" },
            { data: "status" },
            { data: "action", orderable: false },
        ],
    }).on("draw.dt", function () {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            bootstrap.Tooltip.getInstance(el)?.dispose();
            new bootstrap.Tooltip(el, { trigger: "hover" });
        });
    });
});

$(document).ready(function () {
    oTable = $("#data-table").DataTable({
        order: [[1, "asc"]],
    });
    $("#search-input").keyup(function () {
        oTable.search($(this).val()).draw();
    });
    // Payroll Type → plain native <select> (no select2 / bootstrap-select enhancement)
    $(document).ready(function () {
        try {
            if ($.fn.datetimepicker) {
                $(".datetimepicker").datetimepicker({
                    allowInputToggle: true, showClose: true, showClear: true,
                    showTodayButton: true, format: "YYYY/MM/DD"
                });
            }
        } catch (e) { /* BS3-era plugin; ignore under BS5 */ }

        // ── Payroll period range picker (daterangepicker) — fills #date_from / #date_to ──
        var $pr = $('#payroll-daterange');
        if ($pr.length && $.fn.daterangepicker) {
            $pr.daterangepicker({
                autoUpdateInput: false,
                parentEl: '#modal',
                opens: 'center',
                showDropdowns: true,
                locale: { format: 'YYYY-MM-DD', cancelLabel: 'Clear', applyLabel: 'Apply' },
                ranges: {
                    'This Week':    [moment().startOf('week'), moment().endOf('week')],
                    'Last Week':    [moment().subtract(1, 'week').startOf('week'), moment().subtract(1, 'week').endOf('week')],
                    'This Month':   [moment().startOf('month'), moment().endOf('month')],
                    'Last Month':   [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
                }
            });
            $pr.on('apply.daterangepicker', function (ev, picker) {
                $('#payroll-range-label').css({ color: '#0c5460', fontWeight: 600 })
                    .text(picker.startDate.format('MMM D, YYYY') + ' – ' + picker.endDate.format('MMM D, YYYY'));
                $('#date_from').val(picker.startDate.format('YYYY-MM-DD'));
                $('#date_to').val(picker.endDate.format('YYYY-MM-DD'));
            });
            $pr.on('cancel.daterangepicker', function () {
                $('#payroll-range-label').css({ color: '#888', fontWeight: 500 }).text('Select payroll period…');
                $('#date_from').val('');
                $('#date_to').val('');
            });
        }
    });

    $(document).on("click", ".edit_payroll", function () {
        var $id = $(this).attr("data-id");
        uni_modal("Edit Employee", "manage_payroll.php?id=" + $id);
    });

    $(document).on("click", ".add_settings", function () {
        $("#settings-id").val($(this).attr("data-id"));
        $("#modal-settings").modal("show");
        var settings = $(this).attr("settings");
        try {
            var settingsObject = JSON.parse(settings);
            settingsObject.forEach((item) => {
                if (item.type == 1) {
                    $("#contributions-" + item.id).prop("checked", true);
                }
                if (item.type == 2) {
                    $("#deductions-" + item.id).prop("checked", true);
                }
                if (item.type == 3) {
                    $("#loan-" + item.id).prop("checked", true);
                }
                if (item.type == 4) {
                    $("#refund-" + item.id).prop("checked", true);
                }
            });
        } catch (e) {
            console.error("Invalid JSON in settings attribute:", e);
        }
    });

    $(document).on("click", ".view_payroll", function () {
        var $id = $(this).attr("data-id");
        location.href = "index.php?page=payroll_calculations&id=" + $id;
    });

    $(document).on("click", ".remove_payroll", function () {
        _conf("Are you sure to delete this payroll?", "remove_payroll", [
            $(this).attr("data-id"),
        ]);
    });

    $(document).on("click", ".calculate_payroll", async function () {
        Swal.fire({
            title: "Calculating, please wait...",
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            },
        });
        await new Promise((resolve) => setTimeout(resolve, 1000));
        const id = $(this).attr("data-id");
        $.ajax({
            url: "ajax.php?action=calculate_payroll",
            method: "POST",
            dataType: "json",
            data: { id: id },
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
                        text: "Payroll successfully calculated.",
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.href = `index.php?page=payroll_calculations&id=${id}`;
                        }
                    });
                } else {
                    Swal.close();
                    handleError(res?.message || "");
                    $(".submitbutton").removeAttr("disabled");
                }
            },
        });
    });
});

async function remove_payroll(id) {
    Swal.fire({
        title: "Deleting, please wait...",
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        },
    });
    await new Promise((resolve) => setTimeout(resolve, 1000));
    $.ajax({
        url: "ajax.php?action=delete_payroll",
        method: "POST",
        data: { id: id },
        error: (err) => {
            Swal.close();
            handleError();
        },
        success: function (resp) {
            if (resp == 1) {
                Swal.fire({
                    icon: "success",
                    title: "Success!",
                    text: "Selected payroll successfully deleted.",
                }).then((result) => {
                    if (result.isConfirmed) {
                        location.reload();
                    }
                });
            } else {
                Swal.close();
                handleError();
            }
        },
    });
}

let form_data = "";
$("#form-submit").on("submit", async function (e) {
    e.preventDefault();
    var form = $(this);
    form.parsley().validate();

    if (form.parsley().isValid()) {
        e.preventDefault();
        Swal.fire({
            title: "Creating, please wait...",
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            },
        });
        await new Promise((resolve) => setTimeout(resolve, 1000));
        form_data = $(this).serialize();
        $.ajax({
            url: "ajax.php?action=get_sites",
            method: "POST",
            // dataType: "JSON",
            data: $(this).serialize(),
            error: (xhr, status, error) => {
                Swal.close();
                handleError(error || "");
            },
            success: function (res) {
                $("#modal").modal("hide");
                // Load the sites, auto-select them all, and create immediately
                // (no Select Sites modal step).
                $("#show-sites").html(res);
                $("#show-sites").find("input[type='checkbox']").prop("checked", true);
                Swal.close();
                $("#form-add").trigger("submit");
            },
        });
    }
});

$("#form-add").on("submit", async function (e) {
    e.preventDefault();
    Swal.fire({
        title: "Creating, please wait...",
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        },
    });
    await new Promise((resolve) => setTimeout(resolve, 1000));
    $.ajax({
        url: "ajax.php?action=save_payroll",
        method: "POST",
        dataType: "JSON",
        data: { site_ids: $(this).serialize(), form_data },
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
                    text: "Payroll successfully create.",
                }).then((result) => {
                    if (result.isConfirmed) {
                        $("#modal-sites").modal("hide");
                        $("#settings-id").val(res?.id);
                        $("#modal-settings").modal("show");
                    }
                });
            } else {
                Swal.close();
                handleError(res?.message || "");
                $(".submitbutton").removeAttr("disabled");
            }
        },
    });
});

$("#form-settings").on("submit", async function (e) {
    e.preventDefault();
    Swal.fire({
        title: "Creating, please wait...",
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        },
    });
    await new Promise((resolve) => setTimeout(resolve, 1000));
    $.ajax({
        url: "ajax.php?action=save_settings",
        method: "POST",
        dataType: "JSON",
        data: $(this).serialize(),
        error: (xhr, status, error) => {
            Swal.close();
            handleError(error || "");
            $(".submitbutton").removeAttr("disabled");
        },
        success: function (res) {
            $("#modal-settings").modal("hide");
            if (res?.result) {
                Swal.fire({
                    icon: "success",
                    title: "Success!",
                    text: "Payroll successfully create.",
                }).then((result) => {
                    if (result.isConfirmed) {
                        location.reload();
                    }
                });
            } else {
                Swal.close();
                handleError(res?.message || "");
                $(".submitbutton").removeAttr("disabled");
            }
        },
    });
});

async function islock(id, isLock) {
    let message = isLock == 2 ? "Lock" : "Unlock";
    // Show confirmation dialog
    const result = await Swal.fire({
        title: "Are you sure?",
        text: "You won't be able to revert this!",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: "Yes, " + message + " it!",
    });

    // If the user confirmed the deletion
    if (result.isConfirmed) {
        // Show loading dialog
        Swal.fire({
            title: "Updating, please wait...",
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            },
        });

        await new Promise((resolve) => setTimeout(resolve, 1000));

        $.ajax({
            url: "ajax.php?action=isLock222",
            method: "POST",
            data: { id: id, isLock },
            dataType: "JSON",
            error: (err) => {
                Swal.close();
                handleError();
            },
            success: function (res) {
                if (res?.result) {
                    Swal.fire({
                        icon: "success",
                        title: "Success!",
                        text: "Selected payroll successfully updated.",
                    }).then((result) => {
                        if (result.isConfirmed) {
                            location.reload();
                        }
                    });
                } else {
                    Swal.close();
                    handleError();
                }
            },
        });
    }
}

async function recalculate(id) {
    // Show confirmation dialog
    const result = await Swal.fire({
        title: "Are you sure?",
        text: "This action will modify existing values!",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: "Yes, recalculate it!",
    });

    // If the user confirmed the deletion
    if (result.isConfirmed) {
        // Show loading dialog
        Swal.fire({
            title: "Recalculating, please wait...",
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            },
        });

        await new Promise((resolve) => setTimeout(resolve, 1000));

        $.ajax({
            url: "ajax.php?action=calculate_payroll",
            method: "POST",
            data: { id: id, type: "recalculate" },
            dataType: "JSON",
            error: (err) => {
                Swal.close();
                handleError();
            },
            success: function (res) {
                if (res?.result) {
                    Swal.fire({
                        icon: "success",
                        title: "Success!",
                        text: "Selected payroll successfully recalculated.",
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.href = `index.php?page=payroll_calculations&id=${id}`;
                        }
                    });
                } else {
                    Swal.close();
                    handleError();
                }
            },
        });
    }
}

function formatDate(dateString) {
    let date = new Date(dateString);
    return date.toLocaleString("en-US", {
        month: "long",
        day: "numeric",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
        hour12: true,
    });
}

function historyRelTime(dateStr) {
    const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
    if (diff < 60)     return 'Just now';
    if (diff < 3600)   return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400)  return Math.floor(diff / 3600) + 'h ago';
    if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
    return new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function historyAbsTime(dateStr) {
    return new Date(dateStr).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}

async function payroll_history(id) {
    Swal.fire({ title: 'Loading history...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    try {
        const res = await $.ajax({ url: 'ajax.php?action=payroll_history_details', method: 'POST', dataType: 'JSON', data: { id } });
        Swal.close();

        const actionMeta = {
            created:    { color: '#219688', bg: '#e6f5f3', icon: 'ri-add-circle-line',       label: 'Created' },
            calculated: { color: '#2563eb', bg: '#eff6ff', icon: 'ri-calculator-line',        label: 'Calculated' },
            locked:     { color: '#7c3aed', bg: '#f5f3ff', icon: 'ri-lock-line',              label: 'Locked' },
            approved:   { color: '#16a34a', bg: '#f0fdf4', icon: 'ri-checkbox-circle-line',   label: 'Approved' },
            updated:    { color: '#d97706', bg: '#fffbeb', icon: 'ri-edit-line',              label: 'Updated' },
            default:    { color: '#6b7280', bg: '#f9fafb', icon: 'ri-time-line',              label: 'Changed' },
        };

        function getMeta(details) {
            const d = (details || '').toLowerCase();
            if (d.includes('creat'))   return actionMeta.created;
            if (d.includes('calc'))    return actionMeta.calculated;
            if (d.includes('lock'))    return actionMeta.locked;
            if (d.includes('approv'))  return actionMeta.approved;
            if (d.includes('updat') || d.includes('edit')) return actionMeta.updated;
            return actionMeta.default;
        }

        let html = '';

        if (res && res.length > 0) {
            html = `
            <div style="background:#f3f2f1;display:grid;grid-template-columns:150px 1fr 130px;padding:6px 16px;font-size:10px;font-weight:700;color:#605e5c;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #e1dfdd;">
                <span>When</span><span>Event</span><span>By</span>
            </div>
            <div style="max-height:420px;overflow-y:auto;">`;

            res.forEach((row, i) => {
                const meta = getMeta(row.details);
                const initials = (row.name || '?').trim().split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
                const isFirst = i === 0;

                html += `
                <div style="display:grid;grid-template-columns:150px 1fr 130px;align-items:center;padding:10px 16px;border-bottom:1px solid #f3f2f1;font-size:12px;${isFirst ? 'background:#f9fffe;' : ''}">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="display:flex;flex-direction:column;align-items:center;gap:0;">
                            <div style="width:10px;height:10px;border-radius:50%;background:${meta.color};border:2px solid ${meta.color}33;flex-shrink:0;"></div>
                            ${i < res.length - 1 ? `<div style="width:1px;height:18px;background:#e1dfdd;margin-top:2px;"></div>` : ''}
                        </div>
                        <div>
                            <div style="color:#323130;font-size:11px;font-weight:600;" title="${historyAbsTime(row.created_at)}">${historyRelTime(row.created_at)}</div>
                            <div style="color:#a19f9d;font-size:10px;">${new Date(row.created_at).toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'})}</div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:7px;">
                        <div style="width:26px;height:26px;border-radius:4px;background:${meta.bg};display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="${meta.icon}" style="color:${meta.color};font-size:13px;"></i>
                        </div>
                        <div>
                            <div style="color:#323130;font-weight:600;">${row.details}</div>
                            ${isFirst ? `<span style="font-size:10px;background:#e6f5f3;color:#219688;border-radius:2px;padding:1px 5px;font-weight:600;margin-top:2px;display:inline-block;">Latest</span>` : ''}
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <div style="width:24px;height:24px;border-radius:50%;background:${meta.color}22;border:1px solid ${meta.color}44;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:${meta.color};flex-shrink:0;">${initials}</div>
                        <span style="color:#605e5c;font-size:11px;">${row.name || '—'}</span>
                    </div>
                </div>`;
            });

            html += `</div>`;
        } else {
            html = `<div style="padding:40px;text-align:center;color:#a19f9d;">
                <i class="ri-history-line" style="font-size:32px;display:block;margin-bottom:8px;"></i>
                No history found for this payroll.
            </div>`;
        }

        $("#loanHistoryDiv").html(html);
        new bootstrap.Modal(document.getElementById('modal-payroll-history')).show();

    } catch (err) {
        Swal.close();
        console.error('Error fetching payroll history:', err);
    }
}

$(document).on("hide.bs.modal", "#modal-settings", function () {
    window.location.reload();
});
