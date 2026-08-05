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
    if ($.fn.select2) $(".select2").select2();
});
$("#form-payroll").on("submit", function (event) {
    event.preventDefault();
});
$(document).ready(function () {
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

// Payslip selection now lives in the payroll workbench's employee list, which
// exposes the ticked ids via window.pcwSelectedPayslipIds(). The legacy
// `.ps-row-chk` table checkboxes are the fallback for any page still using them.
function selectedPayslipIds() {
    if (typeof window.pcwSelectedPayslipIds === 'function') return window.pcwSelectedPayslipIds();
    return Array.from(document.querySelectorAll('.ps-row-chk:checked')).map(function(c){ return c.value; });
}

function updatePayslipCount() {
    var cnt = document.getElementById('ps-count');
    if (cnt) cnt.textContent = selectedPayslipIds().length;
}

function printSelectedPayslips() {
    const ids = selectedPayslipIds();
    if (ids.length === 0) {
        Swal.fire({ toast:true, position:'top-end', icon:'info', title:'Tick at least one employee in the list first', showConfirmButton:false, timer:2500 });
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
    showToast(e ? e : "Something went wrong. Please contact administrator.", "error");
}

// ── Answer an employee's sign-off (shared shape with DTR page) ──
// isDispute=false is a confirmation that carried a message — same endpoint,
// softer wording, since there is no problem to "resolve".
function openResolveDispute(type, reviewId, empName, isDispute) {
    var disp = isDispute !== false;
    Swal.fire({
        title: disp ? "Resolve dispute" : "Reply to employee",
        html: 'Reply to <b>' + (empName || 'employee') + '</b>. They will be notified.',
        input: "textarea",
        inputPlaceholder: disp ? "Explain what was checked / corrected…" : "Answer their question or note…",
        showCancelButton: true,
        confirmButtonColor: "#107c41",
        confirmButtonText: disp ? "Resolve & notify" : "Send reply",
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
                    Swal.fire({ icon: "success", title: disp ? "Resolved" : "Reply sent", text: res.message })
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
    // ── Pre-lock sanity check: surface problems BEFORE committing the lock ──
    Swal.fire({ title: "Checking payroll…", allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    let chk = null;
    try {
        chk = await $.ajax({ url: "ajax.php?action=payroll_sanity_check", method: "POST", dataType: "JSON", data: { id: id } });
    } catch (e) { /* check unavailable — still allow locking below */ }

    let issues = 0;
    let html = "";
    if (chk?.result) {
        const peso = (v) => "₱" + Number(v).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const section = (icon, title, rows, fmt) => {
            if (!rows || !rows.length) return "";
            issues += rows.length;
            return "<div style='text-align:left;margin-top:10px;'><b style='font-size:13px;'>" + icon + " " + title + " (" + rows.length + ")</b>"
                 + "<ul style='margin:4px 0 0 20px;padding:0;max-height:110px;overflow:auto;'>"
                 + rows.map(fmt).map(s => "<li style='font-size:12px;margin:2px 0;'>" + s + "</li>").join("")
                 + "</ul></div>";
        };
        html += section("🔴", "Net pay ≤ 0", chk.negative, r => "<b>" + r.name + "</b> — " + peso(r.net));
        html += section("⚪", "Zero days present", chk.zero_days, r => "<b>" + r.name + "</b>");
        html += section("🟠", "Net changed > " + chk.threshold + "%" + (chk.prev_label ? " vs " + chk.prev_label : ""), chk.swings,
            r => "<b>" + r.name + "</b> — " + peso(r.prev) + " → " + peso(r.net) + " (" + (r.pct > 0 ? "+" : "") + r.pct + "%)");
        html += section("🟡", "Missing DTR days", chk.missing,
            r => "<b>" + r.name + "</b> — " + r.missing + " day(s) unaccounted (" + r.counted + " of " + r.expected + ")");
        html += section("🌙", "Night hours but ₱0 night differential", chk.nd_zero,
            r => "<b>" + r.name + "</b> — " + r.hours + " hr(s) 10PM–6AM unpaid (check the shift's NSD setting)");
        // Arithmetic reconciliation: the stored net vs the net its own components
        // produce. Every other finding is a judgement call; this one is a defect.
        html += section("🧮", "Stored net does not match its components", chk.unbalanced,
            r => "<b>" + r.name + "</b> — stored " + peso(r.stored_net) + ", computes to " + peso(r.expected_net)
               + " (" + (r.diff > 0 ? "+" : "") + peso(r.diff) + ") — recalculate this payroll");
    }

    const allClear = chk?.result && issues === 0;
    const result = await Swal.fire({
        title: allClear ? "All clear" : (chk?.result ? "Review before locking" : "Are you sure?"),
        icon: allClear ? "success" : "warning",
        html: (allClear
                ? "Checked <b>" + chk.total + "</b> employees — no issues found."
                : (chk?.result
                    ? "<div style='font-size:13px;'>Checked <b>" + chk.total + "</b> employees — <b>" + issues + "</b> finding(s):</div>" + html
                    : "Couldn't run the sanity check."))
            + "<div style='margin-top:12px;font-size:12px;color:#888;'>Locking commits loan deductions and freezes this payroll. Employees will be notified their final payslip is ready.</div>",
        width: 620,
        showCancelButton: true,
        confirmButtonColor: allClear ? "#3085d6" : "#d33",
        cancelButtonColor: "#6c757d",
        confirmButtonText: allClear ? "Lock payroll" : "Lock anyway",
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

// ── Keep SweetAlert dialogs visible while #myDiv is fullscreen ──
// Swal appends to <body>, which doesn't paint while another element holds the
// fullscreen top-layer. Re-target every Swal.fire into the fullscreen element
// when one is active, so saving/locking no longer has to exit fullscreen.
(function patchSwalForFullscreen() {
    function apply() {
        if (typeof Swal === "undefined" || Swal.__fsPatched) return;
        const orig = Swal.fire.bind(Swal);
        Swal.fire = function (...args) {
            const fs = document.fullscreenElement || document.webkitFullscreenElement;
            if (fs && args.length === 1 && args[0] && typeof args[0] === "object" && !args[0].target) {
                args[0] = Object.assign({}, args[0], { target: fs });
            }
            return orig(...args);
        };
        Swal.__fsPatched = true;
    }
    apply();
    document.addEventListener("DOMContentLoaded", apply);
})();

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

        // ── Amount guard: strip anything that isn't a valid number as it's
        // typed. Digits + ONE decimal point; adjustment may start with "-".
        // adjustment_remarks is free text and skips the filter.
        if (inputType !== "adjustment_remarks") {
            const allowNeg = inputType === "adjustment";
            const raw = $(this).val();
            let cleaned = raw.replace(allowNeg ? /[^0-9.\-]/g : /[^0-9.]/g, "");
            if (allowNeg) cleaned = cleaned.charAt(0) + cleaned.slice(1).replace(/-/g, ""); // "-" only leading
            const dot = cleaned.indexOf(".");
            if (dot !== -1) cleaned = cleaned.slice(0, dot + 1) + cleaned.slice(dot + 1).replace(/\./g, ""); // one "."
            if (cleaned !== raw) {
                const pos = Math.max(0, (this.selectionStart || cleaned.length) - (raw.length - cleaned.length));
                $(this).val(cleaned);
                try { this.setSelectionRange(pos, pos); } catch (e) { /* non-text input */ }
            }
        }

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
        const NET_FIELDS = { adjustment: 1, nsd_amount: 1, other_deduction: -1, sss_fund: -1, jei_advances: -1, jcc_advances: -1, tax: -1 };
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
    const cell = tr.querySelector('[data-computed="total_basic_rate"]');
    if (!cell) return;
    // Monthly/fixed basic is the fixed half-month salary — days present don't
    // change it, so leave the server-rendered value untouched (daily only).
    const rt = tr.getAttribute('data-rate-type') || 'daily';
    if (rt === 'monthly' || rt === 'fixed') { recalcBasicRateFooter(); return; }
    const present = parseFloat(tr.querySelector('input[data-type="present"]')?.value) || 0;
    const perDay  = parseFloat(tr.querySelector('input[data-type="per_day"]')?.value) || 0;
    cell.textContent = fmtNum(present * perDay);
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
    // Stay in fullscreen: Swal renders inside the fullscreen element (see the
    // fullscreen patch below), so there's no need to exit before saving.
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
            } else if (res?.reload) {
                // The batch was sent for review / locked (or the row re-locked)
                // while this tab was open, so the server refused the write. The
                // page is stale — reload rather than leave editable-looking
                // fields that can never save.
                Swal.fire({
                    icon: "warning",
                    title: "This payroll changed",
                    text: res.message || "It is no longer open for editing.",
                    confirmButtonText: "Reload",
                    allowOutsideClick: false,
                }).then(() => location.reload());
            } else {
                Swal.close();
                handleError(res?.message || "");
            }
        },
    });
}
