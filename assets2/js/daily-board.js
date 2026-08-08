// Daily Attendance Board — single-date picker (reuses the app's existing daterangepicker
// library, same one used on attendance.php) + client-side Shift/Department group toggle,
// live search, and status filtering via the summary cards.
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

    // ---- Group toggle: Department (default) vs Shift, persisted per browser --
    var $byShift = $("#db-board-shift");
    var $byDept = $("#db-board-dept");
    var $btnShift = $("#btn-group-shift");
    var $btnDept = $("#btn-group-dept");

    function setGrouping(mode) {
        var dept = mode === "dept";
        groupMode = dept ? "dept" : "shift";
        $byShift.toggleClass("d-none", dept);
        $byDept.toggleClass("d-none", !dept);
        $btnShift.toggleClass("active", !dept);
        $btnDept.toggleClass("active", dept);
        try { localStorage.setItem("daily-board-group", mode); } catch (e) {}
        applyFilters();
        syncCollapsedState(false); // switching boards shouldn't animate the incoming one
    }
    $btnShift.on("click", function () { setGrouping("shift"); });
    $btnDept.on("click", function () { setGrouping("dept"); });

    // ---- Collapsible groups -------------------------------------------------
    // Collapsed keys are namespaced per grouping mode, since a shift key and a
    // department key can collide.
    var groupMode = "dept";
    var collapsed = {}; // { "dept|Accounting": true, ... }

    function loadCollapsed() {
        try { collapsed = JSON.parse(localStorage.getItem("daily-board-collapsed") || "{}") || {}; }
        catch (e) { collapsed = {}; }
    }
    function saveCollapsed() {
        try { localStorage.setItem("daily-board-collapsed", JSON.stringify(collapsed)); } catch (e) {}
    }
    function groupId($g) { return groupMode + "|" + ($g.data("group-key") + ""); }

    // While a search / status filter is on, every matching group is forced open so
    // hits are never hidden behind a collapsed head. The saved state comes back
    // untouched once the filter clears.
    function syncCollapsedState(animate) {
        var filtering = !!(searchTerm || statusFilter);
        $(".db-group").each(function () {
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
        var $board = $byShift.hasClass("d-none") ? $byDept : $byShift;
        $board.find(".db-group").each(function () {
            var id = groupId($(this));
            if (state) collapsed[id] = true; else delete collapsed[id];
        });
        saveCollapsed();
        // An expand/collapse-all press is an explicit intent — drop the filter's
        // force-open so the result is actually visible.
        if (state) { searchTerm = ""; statusFilter = null; $("#db-search-input").val(""); $(".db-sum-card").removeClass("filter-on"); applyFilters(); }
        syncCollapsedState(true);
    }
    $("#btn-collapse-all").on("click", function () { setAllCollapsed(true); });
    $("#btn-expand-all").on("click", function () { setAllCollapsed(false); });

    // ---- Search + status filter --------------------------------------------
    var searchTerm = "";
    var statusFilter = null; // e.g. "Present", "Late" — null means all

    function applyFilters() {
        var anyVisible = false;
        var $board = $byShift.hasClass("d-none") ? $byDept : $byShift;

        $board.find(".db-card-col").each(function () {
            var $col = $(this);
            var okSearch = !searchTerm || ($col.data("search") + "").indexOf(searchTerm) !== -1;
            var okStatus = !statusFilter || $col.data("status") === statusFilter;
            var show = okSearch && okStatus;
            $col.toggleClass("d-none", !show);
            if (show) anyVisible = true;
        });

        // Hide groups with no visible cards; refresh the head badges to visible-counts
        $board.find(".db-group").each(function () {
            var $g = $(this);
            var $visible = $g.find(".db-card-col").not(".d-none");
            var total = $g.find(".db-card-col").length;
            $g.toggleClass("d-none", $visible.length === 0);
            $g.find(".db-group-count").text($visible.length === total ? total : $visible.length + " / " + total);

            // Re-tally the status chips + the "x in" bar against what's on screen
            var tally = {}, inCount = 0;
            $visible.each(function () {
                var s = $(this).data("status") + "";
                tally[s] = (tally[s] || 0) + 1;
                if (s === "Present" || s === "Late") inCount++;
            });
            $g.find(".db-stat-chip").each(function () {
                var n = tally[$(this).data("chip-status") + ""] || 0;
                $(this).toggleClass("d-none", n === 0).find(".db-chip-val").text(n);
            });
            $g.find(".db-group-in").html('<i class="ri-user-follow-line"></i> ' + inCount + " in");
            $g.find(".db-group-bar-fill").css("width", ($visible.length ? Math.round(inCount / $visible.length * 100) : 0) + "%");
        });

        $("#db-no-match").toggle(!anyVisible);
        syncCollapsedState(true);
    }

    $("#db-search-input").on("input", function () {
        searchTerm = $(this).val().trim().toLowerCase();
        applyFilters();
    });

    $(".db-sum-card[data-filter]").on("click", function () {
        var f = $(this).data("filter");
        statusFilter = statusFilter === f ? null : f;
        $(".db-sum-card").removeClass("filter-on");
        if (statusFilter) $(this).addClass("filter-on");
        applyFilters();
    });

    var saved = "dept";
    try { saved = localStorage.getItem("daily-board-group") || "dept"; } catch (e) {}
    loadCollapsed();
    setGrouping(saved);
});
