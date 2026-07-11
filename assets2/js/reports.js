// Shared init for report pages: Bootstrap datetimepicker + searchable select2 +
// a DataTable for list-style reports. Loaded (footer) only on report pages, so
// reusing the app's global .datetimepicker / .select2 conventions is safe here.
(function ($) {
    $(function () {
        // Date pickers (from/to on report filters)
        if ($.fn.datetimepicker) {
            $('.datetimepicker').datetimepicker({
                allowInputToggle: true,
                showClose: true,
                showClear: true,
                showTodayButton: true,
                format: "YYYY/MM/DD",
                icons: {
                    time: "fa fa-clock-o",
                    date: "fa fa-calendar",
                    up: "fa fa-chevron-up",
                    down: "fa fa-chevron-down",
                    previous: "fa fa-chevron-left",
                    next: "fa fa-chevron-right",
                    today: "fa fa-crosshairs",
                    clear: "fa fa-trash",
                    close: "fa fa-times"
                }
            });
        }

        // Searchable selects — bind each, anchoring the dropdown to its modal if any.
        $('.report-select2').each(function () {
            var $m = $(this).closest('.modal');
            $(this).select2($m.length ? { dropdownParent: $m } : {});
        });

        // List-style report table (e.g. Payroll List Report)
        if ($.fn.DataTable && $('#report-table').length) {
            $('#report-table').DataTable({
                order: [],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]]
            });
        }
    });
})(jQuery);
