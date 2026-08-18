// Daily Attendance Board — single-date picker (reuses the app's existing daterangepicker
// library, same one used on attendance.php), Shift/Department regrouping, live search,
// status filtering via the summary cards, and a Live auto-refresh for today.
//
// The cards are rendered once by daily-board.php inside the Shift group set. Switching
// to Department MOVES those same nodes into the Department heads rather than rendering a
// second copy of the board — on a 400-employee day that is 400 cards in the DOM, not 800.
$(document).ready(function () {

    // ---- Date picker (single-date mode of the existing daterangepicker) ------
    var $pill = $("#db-date-pill");
    if ($pill.length && $.fn.daterangepicker) {
        var current = moment($("#db-date-input").val(), "YYYY-MM-DD");
        $pill.daterangepicker({
            singleDatePicker: true,
            autoUpdateInput: false,
            opens: "center",
            startDate: current.isValid() ? current : moment(),
            locale: { format: "YYYY-MM-DD" },
        });
        $pill.on("apply.daterangepicker", function (ev, picker) {
            window.location.href = "index.php?page=daily-board&date=" + picker.startDate.format("YYYY-MM-DD");
        });
    }

    // ---- Grouping: Shift (default, matches the server-rendered markup) vs Dept
    var $board = $("#db-board");
    var $btnShift = $("#btn-group-shift");
    var $btnDept = $("#btn-group-dept");
    var groupMode = "shift";

    function modePane(mode) { return $board.find('.db-board-mode[data-mode="' + mode + '"]'); }
    function activePane() { return modePane(groupMode); }

    // Re-parent every card into the group heads of $mode, in the server's name
    // order (data-ord), so a round trip between modes never scrambles a group.
    function moveCards(mode) {
        var $target = modePane(mode);
        if ($target.data("filled")) return;

        var cards = $board.find(".db-card-col").detach().get();
        cards.sort(function (a, b) {
            return (+a.getAttribute("data-ord")) - (+b.getAttribute("data-ord"));
        });

        var buckets = {};
        cards.forEach(function (el) {
            var k = el.getAttribute("data-" + mode + "-key");
            (buckets[k] = buckets[k] || []).push(el);
        });

        $board.find(".db-board-mode").removeData("filled");
        $target.find(".db-group").each(function () {
            var k = $(this).attr("data-group-key");
            if (buckets[k]) $(this).find(".row").first().append(buckets[k]);
        });
        $target.data("filled", true);
    }

    function setGrouping(mode) {
        if (mode !== "dept") mode = "shift";
        groupMode = mode;
        moveCards(mode);
        modePane("shift").toggleClass("d-none", mode !== "shift");
        modePane("dept").toggleClass("d-none", mode !== "dept");
        $board.toggleClass("mode-shift", mode === "shift").toggleClass("mode-dept", mode === "dept");
        $btnShift.toggleClass("active", mode === "shift");
        $btnDept.toggleClass("active", mode === "dept");
        try { localStorage.setItem("daily-board-group", mode); } catch (e) {}
        applyFilters();
        syncCollapsedState(false); // switching boards shouldn't animate the incoming one
    }
    $btnShift.on("click", function () { setGrouping("shift"); });
    $btnDept.on("click", function () { setGrouping("dept"); });

    // ---- Collapsible groups -------------------------------------------------
    // Collapsed keys are namespaced per grouping mode, since a shift key and a
    // department key can collide.
    var collapsed = {}; // { "dept|Accounting": true, ... }
    // A search normally forces every matching group open so hits are never hidden.
    // Pressing Collapse All is a louder instruction than that, so it suspends the
    // force-open until the filter itself changes.
    var forceOpenSuspended = false;

    function loadCollapsed() {
        try { collapsed = JSON.parse(localStorage.getItem("daily-board-collapsed") || "{}") || {}; }
        catch (e) { collapsed = {}; }
    }
    function saveCollapsed() {
        try { localStorage.setItem("daily-board-collapsed", JSON.stringify(collapsed)); } catch (e) {}
    }
    function groupId($g) { return groupMode + "|" + ($g.attr("data-group-key") + ""); }

    function syncCollapsedState(animate) {
        var filtering = !forceOpenSuspended && !!(searchTerm || statusFilter);
        activePane().find(".db-group").each(function () {
            var $g = $(this);
            var want = !filtering && !!collapsed[groupId($g)];
            if (want === $g.hasClass("collapsed")) return;
            if (!animate) $g.addClass("no-anim");
            $g.toggleClass("collapsed", want);
            $g.find(".db-group-head").attr("aria-expanded", want ? "false" : "true");
            if (!animate) $g[0].offsetHeight, $g.removeClass("no-anim"); // flush, then re-arm
        });
    }

    function toggleGroup($g) {
        var id = groupId($g);
        if (collapsed[id]) delete collapsed[id]; else collapsed[id] = true;
        // An explicit click on a head means the same thing Collapse All does.
        forceOpenSuspended = true;
        saveCollapsed();
        syncCollapsedState(true);
    }

    $(document).on("click", ".db-group-head", function (e) {
        if ($(e.target).closest("a, button, input").length) return;
        toggleGroup($(this).closest(".db-group"));
    });
    $(document).on("keydown", ".db-group-head", function (e) {
        if (e.key !== "Enter" && e.key !== " " && e.key !== "Spacebar") return;
        e.preventDefault();
        toggleGroup($(this).closest(".db-group"));
    });

    function setAllCollapsed(state) {
        activePane().find(".db-group").each(function () {
            var id = groupId($(this));
            if (state) collapsed[id] = true; else delete collapsed[id];
        });
        saveCollapsed();
        forceOpenSuspended = state; // collapsing wins over the filter's force-open
        syncCollapsedState(true);
    }
    $("#btn-collapse-all").on("click", function () { setAllCollapsed(true); });
    $("#btn-expand-all").on("click", function () { setAllCollapsed(false); });

    // ---- Search + status filter --------------------------------------------
    var searchTerm = "";
    var statusFilter = null; // e.g. "Present", "Late" — null means all

    // Keep Export pointed at exactly what is on screen.
    var $export = $("#db-export");
    var exportBase = $export.attr("href") || "";
    function syncExport() {
        var url = exportBase;
        if (statusFilter) url += "&status=" + encodeURIComponent(statusFilter);
        if (searchTerm) url += "&q=" + encodeURIComponent(searchTerm);
        url += "&group=" + groupMode;
        $export.attr("href", url);
    }

    function applyFilters() {
        var anyVisible = false;
        var $pane = activePane();

        $pane.find(".db-card-col").each(function () {
            var $col = $(this);
            var okSearch = !searchTerm || (this.getAttribute("data-search") || "").indexOf(searchTerm) !== -1;
            var okStatus = !statusFilter || this.getAttribute("data-status") === statusFilter;
            var show = okSearch && okStatus;
            $col.toggleClass("d-none", !show);
            if (show) anyVisible = true;
        });

        // Hide groups with no visible cards; refresh the head badges to visible-counts
        $pane.find(".db-group").each(function () {
            var $g = $(this);
            var $visible = $g.find(".db-card-col").not(".d-none");
            var total = $g.find(".db-card-col").length;
            $g.toggleClass("d-none", $visible.length === 0);
            $g.find(".db-group-count").text($visible.length === total ? total : $visible.length + " / " + total);

            // Re-tally the status chips + the "x in" bar against what's on screen
            var tally = {}, inCount = 0;
            $visible.each(function () {
                var s = this.getAttribute("data-status") || "";
                tally[s] = (tally[s] || 0) + 1;
                if (s === "Present" || s === "Late" || s === "No Time-out") inCount++;
            });
            $g.find(".db-stat-chip").each(function () {
                var n = tally[$(this).attr("data-chip-status") + ""] || 0;
                $(this).toggleClass("d-none", n === 0).find(".db-chip-val").text(n);
            });
            $g.find(".db-group-in").html('<i class="ri-user-follow-line"></i> ' + inCount + " in");
            $g.find(".db-group-bar-fill").css("width", ($visible.length ? Math.round(inCount / $visible.length * 100) : 0) + "%");
        });

        $("#db-no-match").toggle(!anyVisible);
        syncExport();
        syncCollapsedState(true);
    }

    $("#db-search-input").on("input", function () {
        searchTerm = $(this).val().trim().toLowerCase();
        forceOpenSuspended = false; // a new search re-arms the force-open
        applyFilters();
    });

    $(".db-sum-card[data-filter]").on("click", function () {
        var f = $(this).attr("data-filter");
        statusFilter = statusFilter === f ? null : f;
        $(".db-sum-card").removeClass("filter-on");
        if (statusFilter) $(this).addClass("filter-on");
        forceOpenSuspended = false;
        applyFilters();
    });

    var saved = "shift";
    try { saved = localStorage.getItem("daily-board-group") || "shift"; } catch (e) {}
    loadCollapsed();
    setGrouping(saved);

    // ---- Day navigation by keyboard ----------------------------------------
    $(document).on("keydown", function (e) {
        if (e.key !== "ArrowLeft" && e.key !== "ArrowRight") return;
        if (e.ctrlKey || e.metaKey || e.altKey || e.shiftKey) return;
        var t = e.target;
        if (t && (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable)) return;
        if ($(".modal.show").length || $(".daterangepicker:visible").length) return;
        var href = $(e.key === "ArrowLeft" ? "#db-prev-day" : "#db-next-day").attr("href");
        if (href) window.location.href = href;
    });

    // ---- Live refresh (today only) -----------------------------------------
    // A full reload is the honest way to refresh a board this server-rendered;
    // grouping and collapsed state survive it through the localStorage keys above.
    $("#db-refresh").on("click", function () { window.location.reload(); });

    var $live = $("#db-auto-refresh");
    if ($live.length) {
        var LIVE_MS = 120000;
        var liveTimer = null;
        var liveOn = true;
        try {
            var pref = localStorage.getItem("daily-board-live");
            if (pref !== null) liveOn = pref === "1";
        } catch (e) {}

        function liveTick() {
            // Never yank the page out from under someone mid-task.
            if (searchTerm || statusFilter) return;
            if (document.hidden) return;
            if ($(".modal.show").length || $(".daterangepicker:visible").length) return;
            var a = document.activeElement;
            if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) return;
            window.location.reload();
        }
        function setLive(on) {
            liveOn = on;
            $live.prop("checked", on);
            $("#db-live-wrap").toggleClass("on", on);
            try { localStorage.setItem("daily-board-live", on ? "1" : "0"); } catch (e) {}
            if (liveTimer) { clearInterval(liveTimer); liveTimer = null; }
            if (on) liveTimer = setInterval(liveTick, LIVE_MS);
        }
        $live.on("change", function () { setLive($(this).is(":checked")); });
        setLive(liveOn);
    }

    // ---- Duty-roster quick adjust --------------------------------------
    // Reuses the same duty_roster_save / duty_roster_recompute endpoints the
    // full grid (duty-roster.js) posts to — this is one cell, not a sheet.
    var $adjustModalEl = document.getElementById("db-adjust-modal");
    var adjustModal = $adjustModalEl && window.bootstrap ? new bootstrap.Modal($adjustModalEl) : null;
    var $adjustName = $("#db-adjust-name");
    var $adjustShift = $("#db-adjust-shift");
    var $adjustRest = $("#db-adjust-rest");
    var adjustEmpId = null;

    function toast(icon, title, text) {
        if (window.Swal) Swal.fire({ icon: icon, title: title, text: text, timer: icon === "success" ? 1800 : undefined, showConfirmButton: icon !== "success" });
        else alert(text || title);
    }

    function post(action, data) {
        return $.post("ajax.php?action=" + action, data).then(function (res) {
            var j = res;
            try { if (typeof res === "string") j = JSON.parse(res); } catch (e) { j = null; }
            if (!j) return $.Deferred().reject("Bad response from server.");
            return j;
        });
    }

    $(document).on("click", ".db-adjust-btn", function () {
        var $b = $(this);
        adjustEmpId = parseInt($b.data("adjust-emp"), 10);
        $adjustName.text($b.data("adjust-name") || "");
        var shiftId = parseInt($b.data("adjust-shift"), 10);
        $adjustShift.val(shiftId ? String(shiftId) : "");
        $adjustRest.prop("checked", !!parseInt($b.data("adjust-rest"), 10));
        if (adjustModal) adjustModal.show();
    });

    $("#db-adjust-save").on("click", function () {
        if (!adjustEmpId || !window.DB_ADJUST) return;
        var sidVal = $adjustShift.val();
        var cell = {
            e: adjustEmpId,
            d: window.DB_ADJUST.date,
            s: sidVal ? parseInt(sidVal, 10) : null,
            r: $adjustRest.prop("checked") ? 1 : 0,
        };
        var $btn = $(this).prop("disabled", true);
        post("duty_roster_save", { period: window.DB_ADJUST.period, cells: JSON.stringify([cell]) })
            .done(function (j) {
                $btn.prop("disabled", false);
                if (!j.result) { toast("error", "Error", j.message); return; }
                if (adjustModal) adjustModal.hide();
                // Group membership (Shift view) and the status badge both depend
                // on server-computed fields, so a reload is the reliable way to
                // reflect the change — grouping/collapsed state survives it via
                // the localStorage keys read above.
                if (j.needs_recompute && j.needs_recompute.length) {
                    Swal.fire({
                        icon: "question",
                        title: "Recompute attendance?",
                        text: "This employee already has attendance logged under the old shift for this cutoff. Recompute it now so the figures match?",
                        showCancelButton: true,
                        confirmButtonText: "Recompute",
                    }).then(function (r) {
                        if (!r.isConfirmed) { window.location.reload(); return; }
                        post("duty_roster_recompute", { period: window.DB_ADJUST.period, employee_ids: j.needs_recompute.join(",") })
                            .always(function () { window.location.reload(); });
                    });
                    return;
                }
                toast("success", "Saved", j.message);
                setTimeout(function () { window.location.reload(); }, 900);
            })
            .fail(function () { $btn.prop("disabled", false); toast("error", "Error", "Save failed."); });
    });
});
