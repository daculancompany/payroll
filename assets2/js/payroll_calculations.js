// ── Lightweight non-blocking toast (replaces the SweetAlert "Success!" popups) ──
function showToast(message, type = "success") {
    const accent = { success: "#009688", error: "#dc3545", info: "#0d6efd", warning: "#f0ad4e" }[type] || "#009688";
    let box = document.getElementById("app-toast-box");
    if (!box) {
        box = document.createElement("div");
        box.id = "app-toast-box";
        box.style.cssText = "position:fixed;top:16px;right:16px;z-index:20000;display:flex;flex-direction:column;gap:8px;";
        document.body.appendChild(box);
    }
    const t = document.createElement("div");
    t.style.cssText = "min-width:220px;max-width:340px;background:#fff;border-left:4px solid " + accent +
        ";box-shadow:0 4px 14px rgba(0,0,0,.15);border-radius:6px;padding:10px 14px;font-size:13px;color:#333;" +
        "opacity:0;transform:translateX(20px);transition:opacity .25s,transform .25s;";
    t.textContent = message;
    box.appendChild(t);
    requestAnimationFrame(() => { t.style.opacity = "1"; t.style.transform = "translateX(0)"; });
    setTimeout(() => {
        t.style.opacity = "0"; t.style.transform = "translateX(20px)";
        setTimeout(() => t.remove(), 300);
    }, 2600);
}

// Restore + persist horizontal scroll position
window.addEventListener("load", () => {
    const scrollContainer = document.getElementById("table-responsive2");
    if (!scrollContainer) return;
    const scrollPos = localStorage.getItem("scrollPosition");
    if (scrollPos) scrollContainer.scrollLeft = scrollPos;
    scrollContainer.addEventListener("scroll", () => {
        localStorage.setItem("scrollPosition", scrollContainer.scrollLeft);
    });
});

$(function () {
    $(".select2").select2();
});
$("#form-payroll").on("submit", function (event) {
    event.preventDefault();
});
$(document).ready(function () {
    setTimeout(() =>{
        $(".topnav-hamburger").click();
    },1000)
    $('.net-class').each(function() {
        if (parseFloat($(this).val()) <= 0) {  // Check if value is ≤ 0
            $(`.name-${$(this).attr("did")}`).addClass('net-danger'); // Add class
        }
    });

    $("#myInput").on("keyup", function () {
        var value = $(this).val().toLowerCase();
        $("#table-1 tbody tr").filter(function () {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
        });
        $("#table-2 tbody tr").filter(function () {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
        });
    });
    updatePayroll();
});

function updatePayslipCount() {
    var checked = document.querySelectorAll('.ps-row-chk:checked');
    var cnt    = document.getElementById('ps-count');
    var chkAll = document.getElementById('chk-all');
    var total  = document.querySelectorAll('.ps-row-chk').length;
    if (cnt) cnt.textContent = checked.length;
    if (chkAll) {
        chkAll.indeterminate = checked.length > 0 && checked.length < total;
        chkAll.checked = checked.length === total && total > 0;
    }
}

function toggleAllPayslips(el) {
    document.querySelectorAll('.ps-row-chk').forEach(function(chk) { chk.checked = el.checked; });
    updatePayslipCount();
}

// Delegate checkbox events via jQuery so they work regardless of script load order
$(document).on('change', '.ps-row-chk', function() {
    updatePayslipCount();
});
$(document).on('change', '#chk-all', function() {
    toggleAllPayslips(this);
});

function printSelectedPayslips() {
    const ids = Array.from(document.querySelectorAll('.ps-row-chk:checked')).map(function(c){ return c.value; });
    if (ids.length === 0) {
        Swal.fire({ toast:true, position:'top-end', icon:'info', title:'Check at least one employee row first', showConfirmButton:false, timer:2500 });
        return;
    }
    // Preview inside the payslip modal instead of a print pop-up window.
    var frame = document.getElementById('payslip-preview-frame');
    if (frame) {
        frame.src = 'view_payslip_bulk.php?ids=' + ids.join(',') + '&preview=1';
        new bootstrap.Modal(document.getElementById('modal-payslip-preview')).show();
    } else {
        window.open('view_payslip_bulk.php?ids=' + ids.join(','), '_blank', 'width=1000,height=750,scrollbars=yes');
    }
}

async function updateData(el, id, type, dd_id = null) {
    const inputField = $(el).closest(".input-group").find('input[type="text"]');
    const value = parseFloat(inputField.val());
    if (Number.isNaN(value)) {
        Swal.fire({
            icon: "error",
            title: "Oops...",
            text: "Invalid value!",
        });
    } else {
        const result = await Swal.fire({
            title: "Are you sure?",
            text: "You won't be able to revert this!",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#3085d6",
            cancelButtonColor: "#d33",
            confirmButtonText: "Yes, update it!",
        });
        // If the user confirmed the deletion
        if (result.isConfirmed) {
            // Show loading dialog
            Swal.fire({
                title: "Saving, please wait...",
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                },
            });
            await new Promise((resolve) => setTimeout(resolve, 1000));
            $.ajax({
                url: "ajax.php?action=update_payroll_item",
                method: "POST",
                data: {
                    id: id,
                    value,
                    type,
                    dd_id,
                },
                dataType: "JSON",
                error: (xhr, status, error) => {
                    Swal.close();
                    handleError(error || "");
                },
                success: async function(res) {
                    if (res?.result) {
                        await refreshPayrollRows(id);
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'success',
                            title: 'Changes saved',
                            showConfirmButton: false,
                            timer: 2500,
                            timerProgressBar: true,
                        });
                    } else {
                        Swal.close();
                        handleError(res?.message || "");
                    }
                },
            });
        }
    }
}

async function updatePayroll() {
    const form_data = $("#form-payroll").serialize();
    $.ajax({
        url: "ajax.php?action=save_payroll_amount",
        method: "POST",
        data: form_data,
        dataType: "JSON",
        success: function (res) {},
    });
}

function handleError(e) {
    $(".submitbutton").removeAttr("disabled");
    $(".fa-spinner-button").hide();
    toastr["error"](
        e ? e : "Someting went wrong. Please contact administrator.",
        "Error Notification"
    );
}

// ── Resolve a disputed review (shared shape with DTR page) ──
function openResolveDispute(type, reviewId, empName) {
    Swal.fire({
        title: "Resolve dispute",
        html: 'Reply to <b>' + (empName || 'employee') + '</b>. They will be notified.',
        input: "textarea",
        inputPlaceholder: "Explain what was checked / corrected…",
        showCancelButton: true,
        confirmButtonColor: "#107c41",
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

// ── Remind employees who haven't reviewed this payroll yet ──
function remindPayrollReview(id) {
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
            url: "ajax.php?action=remind_payroll_review",
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

// Send the whole payroll batch to employees for review (status 1 → 3).
function sendPayrollForReview(id) {
    Swal.fire({
        title: "Send for Employee Review?",
        text: "Employees in this payroll will be notified to review their payslip before it's locked.",
        icon: "question",
        showCancelButton: true,
        confirmButtonColor: "#107c41",
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
            url: "ajax.php?action=send_payroll_for_review",
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

async function lockPayroll(id) {
    const result = await Swal.fire({
        title: "Are you sure?",
        text: "You won't be able to revert this!",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: "Yes, lock it!",
    });
    if (result.isConfirmed) {
        $.ajax({
            url: "ajax.php?action=update_payroll_status",
            method: "POST",
            data: {
                id: id,
                status: 2,
            },
            error: (xhr, status, error) => handleError(error || ""),
            success: function (res) {
                if (res) {
                    showToast("Payroll locked.", "success");
                    setTimeout(() => location.reload(), 1200);
                } else {
                    handleError(res?.message || "");
                }
            },
        });
    }
}

const myDiv = document.getElementById("myDiv");

function openFullscreen() {
    $(".table-responsive2").css("height", "80vh");
    $("#sf").hide();
    $("#hf").show();
    if (myDiv.requestFullscreen) {
        myDiv.requestFullscreen();
    } else if (myDiv.webkitRequestFullscreen) {
        // Safari
        myDiv.webkitRequestFullscreen();
    } else if (myDiv.msRequestFullscreen) {
        // IE11
        myDiv.msRequestFullscreen();
    }
}

function closeFullscreen() {
    $(".table-responsive2").css("height", "40vh");
    $("#hf").hide();
    $("#sf").show();
    if (document.exitFullscreen) {
        document.exitFullscreen();
    } else if (document.webkitExitFullscreen) {
        // Safari
        document.webkitExitFullscreen();
    } else if (document.msExitFullscreen) {
        // IE11
        document.msExitFullscreen();
    }
}

// ── Keep Bootstrap modals visible while #myDiv is in the Fullscreen top-layer ──
// A fullscreened element only paints its own subtree. The page modals live on
// <body> (outside #myDiv), so in fullscreen they render *behind* the fullscreen
// layer and disappear. Relocate any opening modal + its backdrop into the
// fullscreen element while fullscreen is active, and restore them on exit.
(function () {
    function fsEl() {
        return document.fullscreenElement || document.webkitFullscreenElement || null;
    }
    // On open: pull the modal into the fullscreen element so it sits in the top-layer.
    document.addEventListener("show.bs.modal", function (e) {
        var fs = fsEl();
        if (fs && e.target && !fs.contains(e.target)) {
            e.target._fsPrevParent = e.target.parentNode;
            fs.appendChild(e.target);
        }
    });
    // Backdrop is created during show() and appended to <body>; move it in too so
    // the dim overlay stays visible behind the dialog.
    document.addEventListener("shown.bs.modal", function () {
        var fs = fsEl();
        if (fs) {
            document.querySelectorAll("body > .modal-backdrop").forEach(function (b) {
                fs.appendChild(b);
            });
        }
    });
    // On leaving fullscreen: send any relocated modals back to their original parent.
    function restoreModals() {
        if (fsEl()) return;
        document.querySelectorAll(".modal").forEach(function (m) {
            if (m._fsPrevParent) {
                m._fsPrevParent.appendChild(m);
                m._fsPrevParent = null;
            }
        });
    }
    document.addEventListener("fullscreenchange", restoreModals);
    document.addEventListener("webkitfullscreenchange", restoreModals);
})();

let changedInputs = [];
$(document).ready(function () {
    countUnsaved();

    $(".input-class").on("input", function () {
        let inputId = $(this).data("id");
        let inputType = $(this).data("type");
        let inputValue = $(this).val().trim(); // Trim to remove extra spaces
        let inputDID = $(this).data("dd_id");

        // Remove existing entry if it exists
        changedInputs = changedInputs.filter(
            (item) => !(item.id === inputId && item.type === inputType && item.dd_id === inputDID)
        );

        // Only add if value is not empty — except adjustment_remarks, where an
        // empty value is a deliberate "clear the remark".
        if (inputValue !== "" || inputType === "adjustment_remarks") {
            changedInputs.push({
                id: inputId,
                type: inputType,
                value: inputValue,
                dd_id: inputDID
            });
        }

        // Instantly reflect Basic Rate / No. of Days edits in Total Basic Rate
        if (inputType === "per_day" || inputType === "present") {
            recalcRowBasicRate(inputId);
        }
        // Instantly reflect adjustment / deduction edits in this row's Net Pay
        // (+1 = added to net, −1 = deducted). Server recomputes on Save.
        const NET_FIELDS = { adjustment: 1, other_deduction: -1, sss_fund: -1, jei_advances: -1, jcc_advances: -1, tax: -1 };
        if (inputType in NET_FIELDS) {
            recalcRowNet(this, inputId, NET_FIELDS[inputType]);
        }
        countUnsaved();
    });
});

function countUnsaved() {
    const btn = document.getElementById("btn-unsaved");
    if (!btn) return;
    if (changedInputs.length === 0) {
        btn.style.display = "none";
    } else {
        btn.style.display = "inline-flex";
        document.getElementById("counter-unsaved").textContent = changedInputs.length;
    }
}

function fmtNum(v) {
    return parseFloat(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Live-recompute a single row's "Total Basic Rate" (present × per_day) as the
// user edits the Basic Rate / No. of Days inputs, without waiting for a save.
function recalcRowBasicRate(rowId) {
    const tr = document.querySelector(`tr[data-row-id="${rowId}"]`);
    if (!tr) return;
    const present = parseFloat(tr.querySelector('input[data-type="present"]')?.value) || 0;
    const perDay  = parseFloat(tr.querySelector('input[data-type="per_day"]')?.value) || 0;
    const cell = tr.querySelector('[data-computed="total_basic_rate"]');
    if (cell) cell.textContent = fmtNum(present * perDay);
    recalcBasicRateFooter();
}

// Live-preview an adjustment/deduction edit in the row's Net Pay (and Total
// Deduction for deduction fields) without waiting for Save. Applies the delta
// between the previous and current input value; the server-side refresh after
// Save remains the authoritative recompute.
function recalcRowNet(el, rowId, sign) {
    const tr = document.querySelector(`tr[data-row-id="${rowId}"]`);
    if (!tr) return;
    const $el = $(el);
    const stored = parseFloat($el.data("prev"));
    const prevV = Number.isNaN(stored) ? (parseFloat(el.defaultValue) || 0) : stored;
    const newV = parseFloat(el.value) || 0;
    $el.data("prev", newV);
    const delta = (newV - prevV) * sign;
    if (delta === 0) return;
    const netCell = tr.querySelector('[data-computed="net"]');
    if (netCell) {
        const next = (parseFloat(String(netCell.textContent).replace(/,/g, "")) || 0) + delta;
        netCell.textContent = fmtNum(next);
        const wrap = tr.querySelector(".net-content");
        if (wrap) wrap.classList.toggle("net-danger", next <= 0);
    }
    if (sign === -1) {
        const dedCell = tr.querySelector('[data-computed="total_deductions"]');
        if (dedCell) dedCell.textContent = fmtNum((parseFloat(String(dedCell.textContent).replace(/,/g, "")) || 0) - delta);
    }
}

// Sum every visible Total Basic Rate cell into the table-2 footer total.
function recalcBasicRateFooter() {
    const foot = document.getElementById('tfoot-basic-rate');
    if (!foot) return;
    let total = 0;
    document.querySelectorAll('[data-computed="total_basic_rate"]').forEach(function (cell) {
        total += parseFloat(String(cell.textContent).replace(/,/g, '')) || 0;
    });
    foot.textContent = fmtNum(total);
}

function refreshPayrollRows(payrollId) {
    return new Promise((resolve) => {
        $.ajax({
            url: 'ajax.php?action=get_payroll_rows_data',
            method: 'POST',
            data: { payroll_id: payrollId },
            dataType: 'JSON',
            success: function(res) {
                if (!res || !res.result) { resolve(false); return; }

                res.rows.forEach(function(r) {
                    const tr = document.querySelector(`tr[data-row-id="${r.id}"]`);
                    if (!tr) return;

                    const set = (key, val) => {
                        const el = tr.querySelector(`[data-computed="${key}"]`);
                        if (el) el.textContent = fmtNum(val);
                    };

                    set('total_basic_rate',  r.total_basic_rate);
                    set('allowance_amount', r.allowance_total / (r.allowance_total > 0 ? 1 : 1));
                    set('allowance_total',   r.allowance_total);
                    set('absent_amount',     r.absent_amount);
                    set('total_amount',      r.total_amount);
                    set('legal_amount',      r.legal_amount);
                    set('sunday_amount',     r.sunday_amount);
                    set('special_amount',    r.special_amount);
                    set('overtime_amount',   r.overtime_amount);
                    set('late_amount',       r.late_amount);
                    set('gross',             r.gross);
                    set('total_deductions',  r.total_deductions);
                    set('net',               r.net);

                    // Net danger styling
                    const netCell = tr.querySelector('.net-content');
                    if (netCell) netCell.classList.toggle('net-danger', r.net <= 0);

                    // Hidden net input used by save_payroll_amount
                    const netInp = document.querySelector(`input.net-class[did="${r.id}"]`);
                    if (netInp) netInp.value = r.net;

                    // Absent/late badges
                    const badges = tr.querySelector(`[data-badges="${r.id}"]`);
                    if (badges) {
                        let html = '';
                        if (r.absent > 0) html += `<span class="pay-badge absent">${r.absent} absent</span>`;
                        if (r.late  > 0) html += `<span class="pay-badge late">${Math.round(r.late)} min late</span>`;
                        badges.innerHTML = html;
                    }
                });

                // Re-sum the Total Basic Rate footer from the live cells
                recalcBasicRateFooter();

                // Summary stats strip
                const t = res.totals;
                const statGross  = document.getElementById('stat-gross');
                const statDeduct = document.getElementById('stat-deduct');
                const statNet    = document.querySelector('.pay-stat.net .pay-stat-val');
                const statAbsent = document.querySelector('.pay-stat.absent .pay-stat-val');
                const statLate   = document.querySelector('.pay-stat.late .pay-stat-val');
                if (statGross)  statGross.textContent  = '₱ ' + fmtNum(t.gross);
                if (statDeduct) statDeduct.textContent = '₱ ' + fmtNum(t.deductions);
                if (statNet)    statNet.textContent    = '₱ ' + fmtNum(t.net);
                if (statAbsent) statAbsent.textContent = t.absent;
                if (statLate)   statLate.textContent   = Math.round(t.late) + ' min';

                resolve(true);
            },
            error: function() { resolve(false); }
        });
    });
}

async function saveUnsaved() {
    closeFullscreen();
    Swal.fire({
        title: "Saving, please wait...",
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); },
    });
    $.ajax({
        url: "ajax.php?action=update_payroll_item_new",
        method: "POST",
        data: { items: changedInputs },
        dataType: "JSON",
        error: (xhr, status, error) => {
            Swal.close();
            handleError(error || "");
        },
        success: async function(res) {
            if (res?.result) {
                await refreshPayrollRows(id);
                changedInputs = [];
                countUnsaved();
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Changes saved',
                    showConfirmButton: false,
                    timer: 2500,
                    timerProgressBar: true,
                });
            } else {
                Swal.close();
                handleError(res?.message || "");
            }
        },
    });
}
