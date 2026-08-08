$(document).ready(function () {
    $('#data-table').DataTable({ order: [[0, 'desc']] });

    // ── Bulk "Send for Review" selection ──
    function refreshDtrBulk() {
        var n = document.querySelectorAll('.dtr-bulk-check:checked').length;
        var cnt = document.getElementById('dtr-bulk-count');
        var btn = document.getElementById('btn-bulk-send-dtr');
        if (cnt) cnt.textContent = n;
        if (btn) btn.disabled = (n === 0);
    }
    document.addEventListener('change', function (e) {
        if (e.target && e.target.id === 'dtr-check-all') {
            document.querySelectorAll('.dtr-bulk-check').forEach(function (c) { c.checked = e.target.checked; });
            refreshDtrBulk();
        } else if (e.target && e.target.classList.contains('dtr-bulk-check')) {
            refreshDtrBulk();
        }
    });
});

function bulkSendDTRForReview() {
    var ids = Array.prototype.map.call(document.querySelectorAll('.dtr-bulk-check:checked'), function (c) { return c.value; });
    if (!ids.length) return;
    Swal.fire({
        title: "Send " + ids.length + " DTR batch(es) for review?",
        text: "Employees on the selected DTRs will be notified to review their attendance.",
        icon: "question",
        showCancelButton: true,
        confirmButtonColor: "#0dcaf0",
        cancelButtonColor: "#6c757d",
        confirmButtonText: "Yes, send them",
    }).then(function (result) {
        if (!result.isConfirmed) return;
        Swal.fire({ title: "Sending, please wait...", allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: "ajax.php?action=bulk_send_dtr_for_review",
            method: "POST",
            dataType: "JSON",
            data: { ids: ids },
            error: (xhr, status, error) => { Swal.close(); handleError(error || ""); },
            success: function (res) {
                if (res?.result) {
                    Swal.fire({ icon: "success", title: "Sent for Review", text: res.message })
                        .then((r) => { if (r.isConfirmed) location.reload(); });
                } else {
                    Swal.close(); handleError(res?.message || "");
                }
            },
        });
    });
}

async function deleteDTR(id) {
    // Show confirmation dialog
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
            url: "ajax.php?action=delete_dtr",
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
                    let msg = "";
                    try { msg = (JSON.parse(resp) || {}).message || ""; } catch (e) {}
                    if (msg) {
                        Swal.fire({ icon: "error", title: "Cannot delete", text: msg });
                    } else {
                        handleError();
                    }
                }
            },
        });
    }
}

async function approveDTR(id) {
    const result = await Swal.fire({
        title: "Are you sure?",
        text: "You are about to approve this DTR.",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: "Yes, approve it!",
    });

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
            data: { id: id },
            error: (xhr, status, error) => {
                Swal.close();
                handleError(error || "");
            },
            success: function (res) {
                if (res?.result) {
                    Swal.fire({
                        icon: "success",
                        title: "Success!",
                        text: "DTR successfully approved.",
                    }).then((result) => {
                        if (result.isConfirmed) {
                            location.reload();
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

async function sendDTRForReview(id) {
    const result = await Swal.fire({
        title: "Send for Employee Review?",
        text: "Employees on this DTR will be notified to review and confirm their own attendance before payroll.",
        icon: "question",
        showCancelButton: true,
        confirmButtonColor: "#0dcaf0",
        cancelButtonColor: "#6c757d",
        confirmButtonText: "Yes, send for review",
    });

    if (!result.isConfirmed) return;

    Swal.fire({
        title: "Sending, please wait...",
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading(),
    });

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
                Swal.close();
                handleError(res?.message || "");
            }
        },
    });
}
