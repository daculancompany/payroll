// Daily Attendance Board — single-date picker (reuses the app's existing daterangepicker
// library, same one used on attendance.php) + client-side Shift/Department group toggle.
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
    }
    $btnShift.on("click", function () { setGrouping("shift"); });
    $btnDept.on("click", function () { setGrouping("dept"); });

    var saved = "dept";
    try { saved = localStorage.getItem("daily-board-group") || "dept"; } catch (e) {}
    setGrouping(saved);
});
