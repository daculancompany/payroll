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
        $byShift.toggleClass("d-none", dept);
        $byDept.toggleClass("d-none", !dept);
        $btnShift.toggleClass("active", !dept);
        $btnDept.toggleClass("active", dept);
        try { localStorage.setItem("daily-board-group", mode); } catch (e) {}
        applyFilters();
    }
    $btnShift.on("click", function () { setGrouping("shift"); });
    $btnDept.on("click", function () { setGrouping("dept"); });

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

        // Hide groups with no visible cards; refresh the count badge to visible-count
        $board.find(".db-group").each(function () {
            var $g = $(this);
            var visible = $g.find(".db-card-col").not(".d-none").length;
            var total = $g.find(".db-card-col").length;
            $g.toggleClass("d-none", visible === 0);
            $g.find(".db-group-count").text(visible === total ? total : visible + " / " + total);
        });

        $("#db-no-match").toggle(!anyVisible);
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
    setGrouping(saved);
});
