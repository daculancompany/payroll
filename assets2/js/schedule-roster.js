// Shift Roster — filter, inline save, and bulk-assign shifts without opening employee details.
// Supports two views (List = DataTable, Grid = cards) that share filters, selection, and saves.
$(document).ready(function () {

    // Keep selection across pages/filters/views by employee id
    const selected = new Set();

    const table = $("#roster-table").DataTable({
        pageLength: 25,
        lengthMenu: [[25, 50, 100, -1], [25, 50, 100, "All"]],
        order: [[1, "asc"]],
        columnDefs: [
            { targets: 0, orderable: false, searchable: false },
            { targets: 4, orderable: false },          // current shift + edit button cell
            { targets: [6, 7], visible: false },        // hidden filter helpers (shift text, status)
        ],
    });

    // ---- View toggle -----------------------------------------------------
    const $viewTable = $("#roster-view-table");
    const $viewGrid = $("#roster-grid");
    const $btnList = $("#btn-view-list");
    const $btnGrid = $("#btn-view-grid");

    function setView(mode) {
        const grid = mode === "grid";
        $viewTable.toggleClass("d-none", grid);
        $viewGrid.toggleClass("d-none", !grid);
        $btnList.toggleClass("active", !grid);
        $btnGrid.toggleClass("active", grid);
        $("#grid-search-wrap").toggle(grid);
        try { localStorage.setItem("roster-view-mode", mode); } catch (e) {}
        syncChecks();
    }
    $btnList.on("click", () => setView("list"));
    $btnGrid.on("click", () => setView("grid"));
    setView((function () { try { return localStorage.getItem("roster-view-mode") || "list"; } catch (e) { return "list"; } })());

    // ---- Date picker pills (same bootstrap-style single-date picker as Daily Board) ------
    // Wires a pill + hidden input to a daterangepicker instance in singleDatePicker mode.
    function initDatePill(pillId, inputId, labelId, initialYmd) {
        const $pill = $("#" + pillId);
        if (!$pill.length || !$.fn.daterangepicker) return null;
        const start = initialYmd ? moment(initialYmd, "YYYY-MM-DD") : moment();
        $pill.daterangepicker({
            singleDatePicker: true,
            autoUpdateInput: false,
            opens: "center",
            startDate: start.isValid() ? start : moment(),
            locale: { format: "YYYY-MM-DD" },
        });
        $pill.on("apply.daterangepicker", function (ev, picker) {
            $("#" + inputId).val(picker.startDate.format("YYYY-MM-DD"));
            $("#" + labelId).text(picker.startDate.format("MMM D, YYYY"));
        });
        return $pill.data("daterangepicker");
    }

    function setDatePill(pillId, inputId, labelId, ymd) {
        const inst = $("#" + pillId).data("daterangepicker");
        const m = moment(ymd, "YYYY-MM-DD");
        if (inst && m.isValid()) inst.setStartDate(m);
        $("#" + inputId).val(m.isValid() ? m.format("YYYY-MM-DD") : "");
        $("#" + labelId).text(m.isValid() ? m.format("MMM D, YYYY") : "Select date…");
    }

    initDatePill("eff-from-pill", "eff-from", "eff-from-label", $("#eff-from").val());
    initDatePill("redit-effective-pill", "redit-effective", "redit-effective-label", null);

    // ---- Helpers -----------------------------------------------------------
    function exact(col, val) {
        // anchored, non-regex-escaped exact match; empty clears the filter
        table.column(col).search(val ? "^" + $.fn.dataTable.util.escapeRegex(val) + "$" : "", true, false);
    }
    function updateCount() {
        $("#sel-count").text(selected.size);
    }
    function syncChecks() {
        // reflect the selected Set onto every checkbox currently in the DOM (both views)
        $(".row-chk").each(function () {
            this.checked = selected.has(this.value);
        });

        const filtered = table.rows({ search: "applied" }).nodes();
        let total = 0, on = 0;
        $(filtered).find(".row-chk").each(function () {
            total++; if (selected.has(this.value)) on++;
        });
        const all = document.getElementById("chk-all");
        if (all) { all.checked = total > 0 && on === total; all.indeterminate = on > 0 && on < total; }

        // Per-shift-group select-all checkboxes
        $("#roster-grid .roster-group").each(function () {
            let gTotal = 0, gOn = 0;
            $(this).find(".roster-card-col").not(".d-none").each(function () {
                const chk = $(this).find(".row-chk")[0];
                if (chk) { gTotal++; if (selected.has(chk.value)) gOn++; }
            });
            const box = $(this).find(".roster-group-chk")[0];
            if (box) { box.checked = gTotal > 0 && gOn === gTotal; box.indeterminate = gOn > 0 && gOn < gTotal; }
        });
    }

    // ---- Grid filtering (also hides shift groups with no visible cards) ------
    function applyGridFilter() {
        const dept = $("#f-dept").val();
        const cls = $("#f-class").val();
        const shift = $("#f-shift").val();
        const status = $("#f-status").val();
        const q = ($("#grid-search").val() || "").trim().toLowerCase();

        $("#roster-grid .roster-card-col").each(function () {
            const el = $(this);
            let show = true;
            if (dept && el.data("dept") !== dept) show = false;
            if (cls && el.data("class") !== cls) show = false;
            if (shift && String(el.data("shift")) !== shift) show = false;
            if (status && el.data("status") !== status) show = false;
            if (q && (el.data("search") || "").toString().indexOf(q) === -1) show = false;
            el.toggleClass("d-none", !show);
        });

        let anyGroupVisible = false;
        $("#roster-grid .roster-group").each(function () {
            const visibleCount = $(this).find(".roster-card-col").not(".d-none").length;
            $(this).toggleClass("d-none", visibleCount === 0);
            $(this).find(".roster-group-count").text(visibleCount);
            if (visibleCount > 0) anyGroupVisible = true;
        });
        $("#roster-grid-empty").toggleClass("d-none", anyGroupVisible);

        syncChecks();
    }

    function applyFilters() {
        exact(2, $("#f-dept").val());
        exact(3, $("#f-class").val());
        exact(6, $("#f-shift").val());
        exact(7, $("#f-status").val());
        table.draw();
        applyGridFilter();
    }

    // ---- Filters -------------------------------------------------------------
    $("#f-dept, #f-class, #f-shift, #f-status").on("change", applyFilters);
    $("#grid-search").on("keyup", applyGridFilter);

    // Default to Active employees on load
    $("#f-status").val("Active");
    applyFilters();

    table.on("draw", syncChecks);

    // ---- Selection (delegated so it works in both list rows and grid cards) --
    $(document).on("change", ".row-chk", function () {
        if (this.checked) selected.add(this.value); else selected.delete(this.value);
        updateCount(); syncChecks();
    });

    $("#chk-all").on("change", function () {
        const want = this.checked;
        const filtered = table.rows({ search: "applied" }).nodes();
        $(filtered).find(".row-chk").each(function () {
            if (want) selected.add(this.value); else selected.delete(this.value);
        });
        updateCount(); syncChecks();
    });

    $(document).on("change", ".roster-group-chk", function () {
        const want = this.checked;
        $(this).closest(".roster-group").find(".roster-card-col").not(".d-none").each(function () {
            const chk = $(this).find(".row-chk")[0];
            if (chk) { if (want) selected.add(chk.value); else selected.delete(chk.value); }
        });
        updateCount(); syncChecks();
    });

    // ---- Shared save call ----------------------------------------------------
    function postAssign(payload, btn) {
        if (btn) btn.prop("disabled", true);
        $.post("ajax.php?action=roster_assign_schedule", payload)
            .done(function (res) {
                let j = res; try { if (typeof res === "string") j = JSON.parse(res); } catch (e) {}
                if (j && j.result) {
                    Swal.fire({ icon: "success", title: "Saved", text: j.message, timer: 1400, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    if (btn) btn.prop("disabled", false);
                    const m = (j && j.message) || "Failed to save.";
                    Swal.fire({ icon: "error", title: "Error", text: m });
                }
            })
            .fail(function () {
                if (btn) btn.prop("disabled", false);
                Swal.fire({ icon: "error", title: "Error", text: "Request failed. Please try again." });
            });
    }

    // ---- Edit Shift modal (single employee) — same fields as employee-details' Assign Schedule form
    const $editModalEl = document.getElementById("modal-roster-edit-shift");
    const editModal = window.bootstrap ? bootstrap.Modal.getOrCreateInstance($editModalEl) : null;

    $(document).on("click", ".roster-edit-btn", function () {
        const btn = $(this);
        $("#redit-emp-id").val(btn.data("emp"));
        $("#redit-title").html('<i class="ri-time-line me-2 text-success"></i>Change Shift — ' + $("<div>").text(btn.data("name")).html());
        $("#redit-shift").val(btn.data("current") ? String(btn.data("current")) : "");
        setDatePill("redit-effective-pill", "redit-effective", "redit-effective-label", moment().format("YYYY-MM-DD"));
        $("#redit-notes").val("");
        if (editModal) editModal.show();
    });

    function editModalPayload() {
        return {
            emp: $("#redit-emp-id").val(),
            shift: $("#redit-shift").val(),
            eff: $("#redit-effective").val(),
            notes: $("#redit-notes").val(),
        };
    }

    // Apply now (immediate)
    $("#form-roster-edit-shift").on("submit", function (e) {
        e.preventDefault();
        const p = editModalPayload();
        if (!p.shift) { warn("Please choose a shift."); return; }
        if (!p.eff) { warn("Please set an 'Effective From' date."); return; }
        postAssign({ employee_id: p.emp, schedule_id: p.shift, effective_from: p.eff, notes: p.notes },
            $(this).find('button[type="submit"]'));
    });

    // Add to plan (staged, from the modal)
    $("#redit-add-plan").on("click", function () {
        const p = editModalPayload();
        if (!p.shift) { warn("Please choose a shift."); return; }
        if (!p.eff) { warn("Please set an 'Effective From' date."); return; }
        addToPlan({ employee_id: p.emp, schedule_id: p.shift, effective_from: p.eff, notes: p.notes }, $(this), true);
    });

    // ---- Bulk apply / plan ---------------------------------------------------
    function bulkValidate() {
        const ids = Array.from(selected);
        const shift = $("#bulk-shift").val();
        const eff = $("#eff-from").val();
        if (!ids.length) { warn("Select at least one employee (tick the checkboxes)."); return null; }
        if (!shift) { warn("Choose a shift first."); return null; }
        if (!eff) { warn("Set an 'Effective from' date."); return null; }
        return { ids: ids, shift: shift, eff: eff, notes: $("#bulk-notes").val() };
    }

    $("#btn-bulk-apply").on("click", function () {
        const v = bulkValidate(); if (!v) return;
        const shiftLabel = $("#bulk-shift option:selected").text();
        const btn = $(this);
        const doIt = () => postAssign({ employee_ids: v.ids, schedule_id: v.shift, effective_from: v.eff, notes: v.notes }, btn);
        Swal.fire({
            icon: "question", title: "Apply now?",
            html: "Assign <b>" + esc(shiftLabel) + "</b> to <b>" + v.ids.length +
                  "</b> employee(s), effective <b>" + v.eff + "</b>. Employees will be notified.",
            showCancelButton: true, confirmButtonText: "Yes, apply", confirmButtonColor: "#009688",
        }).then((r) => { if (r.isConfirmed) doIt(); });
    });

    $("#btn-bulk-plan").on("click", function () {
        const v = bulkValidate(); if (!v) return;
        addToPlan({ employee_ids: v.ids, schedule_id: v.shift, effective_from: v.eff, notes: v.notes }, $(this), false);
    });

    // ---- Planner (staging) ---------------------------------------------------
    function esc(s) { return $("<div>").text(s == null ? "" : s).html(); }

    function addToPlan(payload, btn, closeModal) {
        if (btn) btn.prop("disabled", true);
        $.post("ajax.php?action=plan_add_schedule", payload)
            .done(function (res) {
                let j = res; try { if (typeof res === "string") j = JSON.parse(res); } catch (e) {}
                if (btn) btn.prop("disabled", false);
                if (j && j.result) {
                    if (closeModal && editModal) editModal.hide();
                    loadPlan(true);
                    Swal.fire({ icon: "success", title: "Added to plan", text: j.message, timer: 1300, showConfirmButton: false });
                } else {
                    const m = (j && j.message) || "Failed to add to plan.";
                    Swal.fire({ icon: "error", title: "Error", text: m });
                }
            })
            .fail(function () {
                if (btn) btn.prop("disabled", false);
                Swal.fire({ icon: "error", title: "Error", text: "Request failed." });
            });
    }

    function fmtDate(d) {
        const t = Date.parse(d + "T00:00:00");
        if (isNaN(t)) return d;
        return new Date(t).toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
    }

    function loadPlan(scrollIntoView) {
        $.get("ajax.php?action=plan_list")
            .done(function (res) {
                let j = res; try { if (typeof res === "string") j = JSON.parse(res); } catch (e) {}
                const rows = (j && j.data) || [];
                $("#plan-count").text(rows.length);
                const $body = $("#plan-tbody").empty();
                if (!rows.length) {
                    $("#planner-card").addClass("d-none");
                    return;
                }
                rows.forEach(function (r) {
                    const cur = r.cur_shift_desc ? '<span class="plan-cur-old">' + esc(r.cur_shift_desc) + "</span>" : '<span class="text-muted">—</span>';
                    $body.append(
                        "<tr>" +
                        "<td><b>" + esc(r.emp_name) + "</b><div class='text-muted' style='font-size:11px;'>" + esc(r.employee_no) + "</div></td>" +
                        "<td>" + cur + "</td>" +
                        '<td><span class="plan-shift-badge">' + esc(r.shift_desc) + "</span></td>" +
                        "<td>" + fmtDate(r.effective_from) + "</td>" +
                        "<td class='text-muted' style='font-size:11px;'>" + esc(r.notes || "") + "</td>" +
                        '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger plan-remove-btn" data-id="' +
                        r.id + '" title="Remove"><i class="ri-close-line"></i></button></td>' +
                        "</tr>"
                    );
                });
                $("#planner-card").removeClass("d-none");
                if (scrollIntoView) $("#planner-card")[0].scrollIntoView({ behavior: "smooth", block: "nearest" });
            });
    }

    $(document).on("click", ".plan-remove-btn", function () {
        const id = $(this).data("id");
        $.post("ajax.php?action=plan_remove", { id: id })
            .done(function () { loadPlan(false); });
    });

    $("#btn-plan-clear").on("click", function () {
        const go = () => $.post("ajax.php?action=plan_clear").done(function () { loadPlan(false); });
        Swal.fire({ icon: "warning", title: "Clear all planned changes?", showCancelButton: true, confirmButtonText: "Clear", confirmButtonColor: "#d33" })
            .then((r) => { if (r.isConfirmed) go(); });
    });

    $("#btn-plan-apply").on("click", function () {
        const btn = $(this);
        const count = parseInt($("#plan-count").text(), 10) || 0;
        if (!count) { warn("There are no planned changes to apply."); return; }
        const go = () => {
            btn.prop("disabled", true);
            $.post("ajax.php?action=plan_apply_all")
                .done(function (res) {
                    let j = res; try { if (typeof res === "string") j = JSON.parse(res); } catch (e) {}
                    if (j && j.result) {
                        Swal.fire({ icon: "success", title: "Applied", text: j.message, timer: 1600, showConfirmButton: false }).then(() => location.reload());
                    } else {
                        btn.prop("disabled", false);
                        const m = (j && j.message) || "Failed to apply.";
                        Swal.fire({ icon: "error", title: "Error", text: m });
                    }
                })
                .fail(function () { btn.prop("disabled", false); Swal.fire({ icon: "error", title: "Error", text: "Request failed." }); });
        };
        Swal.fire({
            icon: "question", title: "Apply all planned changes?",
            html: "This will apply <b>" + count + "</b> change(s) and notify the affected employees.",
            showCancelButton: true, confirmButtonText: "Yes, apply all", confirmButtonColor: "#009688",
        }).then((r) => { if (r.isConfirmed) go(); });
    });

    loadPlan(false);

    function warn(msg) {
        Swal.fire({ icon: "warning", title: "Hold on", text: msg });
    }
});
