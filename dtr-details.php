<?php
if (!isset($_GET['id']) || !isset($_GET['device_id']) || !isset($_GET['site_id'])) {
    header("HTTP/1.1 405 Unauthorized");
    echo "Data not available";
    exit;
};

$id =  base64_decode($_GET['id']);
$device_id =  base64_decode($_GET['device_id']);
$site_id =  base64_decode($_GET['site_id']);
$timekeeper_name = base64_decode($_GET['timekeeper_name']);

$query = "SELECT DTR.*, sites.site_code, sites.site_name, employer_name FROM DTR
        LEFT JOIN sites ON sites.id = DTR.site_id
        LEFT JOIN employers ON sites.employer_id = employers.id
        WHERE DTR.id = ?";
$stmt = $conn->prepare($query);
if (!$stmt) {
    die('<b>MySQL prepare error:</b> ' . htmlspecialchars($conn->error) . '<br><b>Query:</b> ' . htmlspecialchars($query));
}
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$dtr = $result->fetch_assoc();

if (!$dtr) {
    header("HTTP/1.1 405 Unauthorized");
    echo "Data not available";
    exit;
}

function stringToArrayBySpaces($string)
{
    $split_array = preg_split('/\s{7}+/', $string, -1, PREG_SPLIT_NO_EMPTY);
    return $split_array;
}

function getDataBySpaces($dataString)
{
    $splitData = [];
    $currentElement = "";

    foreach (str_split($dataString) as $char) {
        if (ctype_space($char)) {
            if ($currentElement) {
                $splitData[] = $currentElement;
                $currentElement = "";
            }
        } else {
            $currentElement .= $char;
        }
    }

    if ($currentElement) {
        $splitData[] = $currentElement;
    }

    return $splitData;
}

$decoded_data = base64_decode(explode(",", $dtr['file'])[1]);
$result_array = stringToArrayBySpaces($decoded_data);
$is_duplicate = false;

// Fetch attendance data grouped by date and employee
$query = $conn->query("SELECT  
                        a.*, 
                        e.employee_no, 
                        e.lastname, 
                        e.firstname, 
                        e.middlename,  
                        d.name as department, 
                        p.name as position,
                        DATE(a.date_time) as attendance_date
                    FROM DTR_details a 
                    INNER JOIN employee e ON a.employee_id = e.id 
                    LEFT JOIN department d ON e.department_id = d.id 
                    LEFT JOIN position p ON e.position_id = p.id  
                    WHERE a.ddtr_id = $id 
                    ORDER BY a.date_time ASC");

$groupedData = [];
$employeeTotals = [];
$dateTotals = [];
$grandTotals = [
    'work_hours' => 0,
    'overtime' => 0,
    'undertime' => 0,
    'late' => 0
];

while ($row = $query->fetch_assoc()) {
    $date = $row['attendance_date'];
    $employeeId = $row['employee_id'];

    // Initialize date group if not exists
    if (!isset($groupedData[$date])) {
        $groupedData[$date] = [];
        $dateTotals[$date] = [
            'work_hours' => 0,
            'overtime' => 0,
            'undertime' => 0,
            'late' => 0
        ];
    }

    // Initialize employee group if not exists
    if (!isset($groupedData[$date][$employeeId])) {
        $groupedData[$date][$employeeId] = [
            'employee_info' => $row,
            'entries' => []
        ];

        // Initialize employee totals if not exists
        if (!isset($employeeTotals[$employeeId])) {
            $employeeTotals[$employeeId] = [
                'employee_info' => $row,
                'work_hours' => 0,
                'overtime' => 0,
                'undertime' => 0,
                'late' => 0
            ];
        }
    }

    // Add entry to the group
    $groupedData[$date][$employeeId]['entries'][] = $row;

    // Update totals
    $workHours = floatval($row['work_hours']);
    $overtime = floatval($row['overtime']);
    $undertime = floatval($row['undertime']);
    $late = floatval($row['late']);

    $employeeTotals[$employeeId]['work_hours'] += $workHours;
    $employeeTotals[$employeeId]['overtime'] += $overtime;
    $employeeTotals[$employeeId]['undertime'] += $undertime;
    $employeeTotals[$employeeId]['late'] += $late;

    $dateTotals[$date]['work_hours'] += $workHours;
    $dateTotals[$date]['overtime'] += $overtime;
    $dateTotals[$date]['undertime'] += $undertime;
    $dateTotals[$date]['late'] += $late;

    $grandTotals['work_hours'] += $workHours;
    $grandTotals['overtime'] += $overtime;
    $grandTotals['undertime'] += $undertime;
    $grandTotals['late'] += $late;
}

// Build by-employee grouping: employee → date → entries
$byEmployee = [];
foreach ($groupedData as $date => $employees) {
    foreach ($employees as $empId => $empData) {
        if (!isset($byEmployee[$empId])) {
            $byEmployee[$empId] = [
                'employee_info' => $empData['employee_info'],
                'dates' => [],
            ];
        }
        $byEmployee[$empId]['dates'][$date] = $empData['entries'];
    }
}
// Sort by employee lastname
uasort($byEmployee, function($a, $b) {
    return strcmp($a['employee_info']['lastname'], $b['employee_info']['lastname']);
});
?>

<link rel="stylesheet" href="assets2/css/my-style.css">
<style>
/* ── DTR Details — #219688 brand theme ── */
#print-section { display: none; }

/* ── Override my-style.css Excel-green headers → brand teal ── */
/* z-index:14 beats frozen body cols (z-index:12) so header stays on top when scrolling */
#table-1 thead th { background-color:#219688 !important; border-color:#176358 !important; z-index:14 !important; }
#table-1 thead tr:first-child th,
#table-1 thead tr:first-child th.primary-header,
#table-1 thead tr:first-child th.info-header,
#table-1 thead tr:first-child th.success-header,
#table-1 thead tr:first-child th.danger-header { background-color:#176358 !important; border-color:#124d44 !important; z-index:14 !important; }
/* Corner cells (top+left sticky) need highest z-index */
#table-1 thead tr:first-child th:nth-child(1),
#table-1 thead tr:first-child th:nth-child(2),
#table-1 thead tr:nth-child(2) th:nth-child(1),
#table-1 thead tr:nth-child(2) th:nth-child(2) { z-index:16 !important; }
#table-1 thead tr:nth-child(2) th.primary-header,
#table-1 thead tr:nth-child(2) th.info-header,
#table-1 thead tr:nth-child(2) th.success-header,
#table-1 thead tr:nth-child(2) th.danger-header { background-color:#219688 !important; border-color:#176358 !important; z-index:14 !important; }
.table-responsive2 { border-color:#219688 !important; }
#table-1 tbody td:nth-child(1), #table-1 tfoot th:nth-child(1) {
    background-color:#e6f5f3 !important; color:#176358 !important; border-right-color:#219688 !important;
}
#table-1 tbody td:nth-child(2), #table-1 tfoot th:nth-child(2) {
    background-color:#d4eeed !important; color:#176358 !important; border-right-color:#219688 !important;
}
#table-1 tbody tr:nth-child(even) td { background-color:#f0faf9 !important; }
#table-1 tbody tr:hover td { background-color:#cdeae7 !important; }
.xl-ribbon { border-top-color:#219688 !important; }
.xl-ribbon-title { color:#176358 !important; }
.xl-ribbon-title i { color:#219688 !important; }
.xl-btn i { color:#219688 !important; }
.xl-btn:hover { background:#e6f5f3 !important; border-color:#aad5d0 !important; color:#176358 !important; }
.xl-btn-save { background:#219688 !important; border-color:#176358 !important; animation:none !important; }
.xl-btn-save i { color:#fff !important; }
.xl-btn-save:hover { background:#176358 !important; color:#fff !important; }
.xl-search-wrap i { color:#219688 !important; }
.xl-search-wrap:focus-within { border-color:#219688 !important; box-shadow:0 0 0 2px rgba(33,150,136,0.15) !important; }

/* ── Summary stat cards ── */
.dtr-stats-row { display:flex; gap:8px; margin-bottom:10px; flex-wrap:wrap; }
.dtr-stat-box {
    flex:1; min-width:140px;
    border:1px solid #aad5d0; border-top:3px solid #219688; border-radius:3px; background:#fff;
    padding:9px 14px; display:flex; align-items:center; gap:10px;
    box-shadow:0 1px 3px rgba(0,0,0,0.06);
}
.dtr-stat-box .stat-icon {
    width:34px; height:34px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:16px; flex-shrink:0;
}
.dtr-stat-box .stat-val  { font-size:17px; font-weight:700; line-height:1.1; font-family:'Segoe UI',Arial,sans-serif; }
.dtr-stat-box .stat-lbl  { font-size:10px; color:#666; text-transform:uppercase; letter-spacing:0.5px; }

/* ── Collapsible date groups ── */
.date-separator { cursor:pointer; user-select:none; }
.dtr-chevron { font-size:16px; transition:transform 0.2s; }
.date-separator.collapsed .dtr-chevron { transform:rotate(-90deg); }
.dtr-group-row.dtr-hidden { display:none !important; }

/* ── New clean layout helpers ── */
.dtr-date-label { font-weight:700; font-size:12px; letter-spacing:0.2px; }
.dtr-emp-count  { font-size:10px; padding:1px 8px; border-radius:10px; background:rgba(255,255,255,0.18); margin-left:6px; }
.dtr-date-totals { display:flex; gap:14px; font-size:11px; opacity:0.9; }
.dtr-emp-init {
    width:28px; height:28px; border-radius:50%;
    background:#219688; color:#fff; font-size:10px; font-weight:700;
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
.dtr-emp-name { font-size:12px; color:#176358; font-weight:600; }
.dtr-emp-pos  { font-size:11px; color:#4a9b93; }
.dtr-emp-totals { display:flex; gap:10px; }
.dtr-tot-item { display:flex; flex-direction:column; align-items:center; min-width:40px; }
.dtr-tot-item .tot-lbl { font-size:9px; color:#888; text-transform:uppercase; letter-spacing:0.3px; }
.dtr-tot-item .tot-val { font-size:12px; font-weight:700; color:#219688; line-height:1.1; }
.dtr-tot-item.ot   .tot-val { color:#c98a00; }
.dtr-tot-item.ut   .tot-val { color:#1565c0; }
.dtr-tot-item.late .tot-val { color:#c62828; }
.dtr-time-chip { display:inline-block; padding:2px 7px; border-radius:2px; font-size:11px; font-weight:600; font-family:'Segoe UI',Arial,sans-serif; }
.dtr-time-chip.in  { background:#e6f5f3; color:#219688; border:1px solid #aad5d0; }
.dtr-time-chip.out { background:#fce4ec; color:#c62828; border:1px solid #f9a8b5; }
.dtr-time-chip.na  { background:#f5f5f5; color:#888;    border:1px solid #ddd; }
.dtr-pos-chip { display:inline-block; padding:1px 7px; border-radius:2px; font-size:10px; background:#f3f2f1; color:#323130; border:1px solid #e1dfdd; }
.dtr-log-chip { display:inline-flex; align-items:center; gap:3px; margin-bottom:1px; padding:1px 5px; border-radius:2px; font-size:10px; font-weight:500; }
.dtr-logs-pill { display:inline-flex; flex-direction:column; align-items:center; gap:1px; font-size:11px; line-height:1.3; }
.dtr-logs-count { font-size:9px; color:#a19f9d; margin-top:1px; }
.dtr-logs-pill:hover .dtr-logs-count { color:#219688; }

/* ── Segment toggle ── */
.dtr-segment { display:inline-flex; background:#e6f5f3; border-radius:8px; padding:3px; gap:2px; }
.dtr-seg-btn { border:none; background:transparent; border-radius:6px; padding:5px 14px; font-size:12px; font-weight:600; color:#219688; cursor:pointer; display:flex; align-items:center; gap:5px; transition:all .15s; }
.dtr-seg-btn.active { background:#219688; color:#fff; box-shadow:0 1px 4px rgba(33,150,136,.25); }
.dtr-seg-btn:not(.active):hover { background:#c5e8e4; }

/* ── Employee cards view ── */
.ecard { background:#fff; border:1px solid #e1dfdd; border-radius:10px; margin-bottom:10px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); transition:box-shadow .15s; }
.ecard:hover { box-shadow:0 3px 10px rgba(33,150,136,.12); }
.ecard-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; cursor:pointer; user-select:none; gap:12px; }
.ecard-header:hover { background:#f9fffe; }
.ecard-left { display:flex; align-items:center; gap:12px; flex:1; min-width:0; }
.ecard-right { display:flex; align-items:center; gap:12px; flex-shrink:0; }
.ecard-name { font-size:13px; font-weight:700; color:#323130; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ecard-meta { display:flex; align-items:center; gap:6px; margin-top:3px; }
.ecard-days { font-size:10px; color:#a19f9d; display:flex; align-items:center; gap:3px; }
.ecard-chevron { font-size:18px; color:#a19f9d; transition:transform .2s; flex-shrink:0; }
.ecard.open .ecard-chevron { transform:rotate(180deg); }
.ecard-body { display:none; border-top:1px solid #f3f2f1; padding:0 0 4px; }
.ecard.open .ecard-body { display:block; }
.ecard-date-group { margin:8px 12px 4px; }
.ecard-date-header { display:flex; align-items:center; justify-content:space-between; padding:5px 8px; background:#f3f2f1; border-radius:6px; margin-bottom:4px; }
.ecard-date-label { font-size:11px; font-weight:700; color:#605e5c; display:flex; align-items:center; gap:5px; }
.ecard-entry { display:flex; align-items:center; justify-content:space-between; padding:6px 8px; border-radius:6px; margin-bottom:2px; background:#fafafa; border:1px solid #f0f0f0; gap:8px; }
.ecard-times { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.ecard-entry-stats { display:flex; gap:8px; flex-shrink:0; }
.ecard-stat { display:flex; flex-direction:column; align-items:center; min-width:30px; }
.ecard-stat-lbl { font-size:9px; color:#a19f9d; text-transform:uppercase; letter-spacing:.3px; }
.ecard-stat-val { font-size:11px; font-weight:700; color:#219688; }
.ecard-stat.ot .ecard-stat-val  { color:#c98a00; }
.ecard-stat.ut .ecard-stat-val  { color:#1565c0; }
.ecard-stat.late .ecard-stat-val { color:#c62828; }
.dtr-log-chip.bio    { background:#e6f5f3; color:#219688; border:1px solid #aad5d0; }
.dtr-log-chip.manual { background:#fff8e1; color:#c98a00; border:1px solid #ffe082; }

/* ── Date group banner ── */
#table-1 tbody tr.date-separator td {
    background:#219688 !important; color:#fff !important;
    padding:7px 14px !important;
    border-top:1px solid #176358 !important;
    border-bottom:1px solid #176358 !important;
    font-family:'Segoe UI',Arial,sans-serif;
}
#table-1 tbody tr.date-separator td * { color:#fff !important; }
#table-1 tbody tr.date-separator td small,
#table-1 tbody tr.date-separator td .text-muted { color:rgba(255,255,255,0.75) !important; }

/* ── Employee card row ── */
#table-1 tbody tr.employee-header td {
    background:#f4fbfa !important;
    border-top:2px solid #aad5d0 !important;
    border-bottom:0 !important;
    padding:8px 12px 0 !important;
}
.dtr-emp-card {
    background:#fff;
    border:1px solid #c8e6e2;
    border-radius:8px;
    padding:8px 14px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    box-shadow:0 1px 4px rgba(33,150,136,.07);
    margin-bottom: 10px;
}

/* ── Entry rows under a card ── */
#table-1 tbody tr.attendance-entry td { background:#f4fbfa; }
#table-1 tbody tr.attendance-entry:last-of-type td { padding-bottom:10px !important; }

/* ── Duplicate entry ── */
#table-1 tbody tr.duplicate-entry td { background:#fff5f5 !important; border-left:3px solid #f06548 !important; }

/* ── Grand total row ── */
#table-1 tbody tr.grand-total-row td {
    background:#219688 !important; color:#fff !important;
    font-weight:700 !important; font-size:12px !important; padding:6px 8px !important;
    border-top:2px solid #176358 !important; border-right:1px solid #27b09f !important;
    position:sticky; bottom:0; z-index:13;
    box-shadow:0 -3px 8px rgba(0,0,0,0.18);
}
#table-1 tbody tr.grand-total-row td:nth-child(1),
#table-1 tbody tr.grand-total-row td:nth-child(2) { z-index:15 !important; background:#176358 !important; }

/* ── bg-soft utilities ── */
.bg-soft-primary   { background:rgba(33,150,136,0.10) !important; }
.bg-soft-secondary { background:rgba(108,117,125,0.08) !important; }
.bg-soft-success   { background:rgba(16,124,65,0.10) !important; }
.bg-soft-danger    { background:rgba(240,101,72,0.10) !important; }
.bg-soft-warning   { background:rgba(247,184,75,0.10) !important; }
.bg-soft-info      { background:rgba(80,165,241,0.10) !important; }
.bg-soft-light     { background:#f8f9fa !important; }

/* ── Editable fields ── */
.editable-field { display:flex; align-items:center; justify-content:center; gap:2px; }
.editable-field .form-control {
    border:1px solid #d4d4d4; border-radius:2px;
    font-size:11px; padding:2px 5px; height:24px;
    background:#f0faf9; transition:all 0.15s;
}
.editable-field .form-control:focus {
    border-color:#219688; box-shadow:0 0 0 2px rgba(33,150,136,0.15); background:#fff;
}
.update-dtr-field {
    display:inline-flex !important; align-items:center; justify-content:center;
    width:22px !important; height:22px !important; padding:0 !important; font-size:11px !important;
    background:#e6f5f3 !important; border:1px solid #aad5d0 !important; border-radius:2px !important;
    color:#219688 !important; cursor:pointer; flex-shrink:0;
    box-shadow:none !important; transition:background 0.1s, border-color 0.1s;
}
.update-dtr-field:hover { background:#219688 !important; border-color:#176358 !important; color:#fff !important; }

/* ── Action buttons ── */
#table-1 td .btn-group .btn { width:26px; height:26px; padding:0; font-size:13px; display:inline-flex; align-items:center; justify-content:center; border-radius:3px; box-shadow:none; }
#table-1 td .btn-group .btn-outline-danger { background:transparent; border:1px solid transparent; color:#a4262c; }
#table-1 td .btn-group .btn-outline-danger:hover { background:#fdeceb; border-color:#f1c0bd; }
#table-1 td .btn-group .btn-outline-warning,
#table-1 td .btn-group a.btn-outline-warning { background:transparent; border:1px solid transparent; color:#c98a00; }
#table-1 td .btn-group .btn-outline-warning:hover,
#table-1 td .btn-group a.btn-outline-warning:hover { background:#fff8e1; border-color:#ffe082; }

/* ── Logs ── */
.logs-container { max-height:90px; overflow-y:auto; }
.log-entry { line-height:1.3; }

/* ── Misc ── */
.stat-item, .total-item { min-width:55px; }
.stat-value, .total-value { font-size:1rem; }
.update-success { border-color:#219688 !important; background:rgba(33,150,136,0.08) !important; animation:pulse-ok 2s; }
@keyframes pulse-ok {
    0%   { box-shadow:0 0 0 0 rgba(33,150,136,0.6); }
    70%  { box-shadow:0 0 0 8px rgba(33,150,136,0); }
    100% { box-shadow:0 0 0 0 rgba(33,150,136,0); }
}

/* ── Print ── */
@media print {
    body * { visibility:hidden; margin:0; padding:0; }
    #print-section, #print-section * { visibility:visible; }
    #print-section { display:block !important; position:absolute; left:0; top:0; width:100%; padding:20px; font-family:Arial,sans-serif; font-size:12px; background:#fff; }
    .main-content,.page-content,.container-fluid,.card,.btn,.search-box { display:none !important; }
    .print-header { text-align:center; margin-bottom:20px; padding-bottom:15px; border-bottom:2px solid #333; }
    .print-header h2 { font-size:18px; margin-bottom:10px; color:#333; }
    .print-info { margin-bottom:15px; }
    .print-info p { margin:2px 0; font-size:11px; }
    .print-summary { display:flex; justify-content:center; gap:20px; margin:15px 0; padding:10px; background:#f5f5f5; border-radius:4px; }
    .summary-item { text-align:center; }
    .summary-item .label { display:block; font-size:10px; color:#666; }
    .summary-item .value { display:block; font-size:12px; font-weight:bold; color:#333; }
    .print-table { width:100%; border-collapse:collapse; margin:15px 0; font-size:10px; }
    .print-table th { background:#f8f9fa; border:1px solid #ddd; padding:6px 4px; text-align:center; font-weight:bold; }
    .print-table td { border:1px solid #ddd; padding:5px 3px; }
    .print-table .text-center { text-align:center; }
    .print-table .text-end { text-align:right; }
    .date-separator { background:#e9ecef; }
    .employee-row { page-break-inside:avoid; }
    .grand-total { background:#d1ecf1; font-weight:bold; }
    .print-footer { margin-top:20px; padding-top:10px; border-top:1px solid #ddd; text-align:center; font-size:10px; color:#666; }
}
</style>
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <!-- start page title -->
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <div class="page-title-enhanced">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <h4 class="mb-0 fw-bold">DTR Details</h4>
                                    <?php if ($dtr['status'] === 0): ?>
                                        <span class="payroll-status-badge bg-warning text-dark"><i class="ri-time-line me-1"></i>Open</span>
                                    <?php elseif ($dtr['status'] === 1): ?>
                                        <span class="payroll-status-badge bg-info text-white"><i class="ri-check-double-line me-1"></i>Pending Approval</span>
                                    <?php else: ?>
                                        <span class="payroll-status-badge bg-success text-white"><i class="ri-checkbox-circle-line me-1"></i>Approved</span>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted">
                                    <i class="ri-calendar-2-line me-1"></i><?= date('M d', strtotime($dtr['date_from'])) ?> &ndash; <?= date('M d, Y', strtotime($dtr['date_to'])) ?>
                                    &nbsp;&bull;&nbsp;<i class="ri-building-line me-1"></i><?= htmlspecialchars($dtr['site_name']) ?> (<?= htmlspecialchars($dtr['site_code']) ?>)
                                    &nbsp;&bull;&nbsp;<i class="ri-user-2-line me-1"></i><?= htmlspecialchars($dtr['employer_name']) ?>
                                    &nbsp;&bull;&nbsp;<i class="ri-shield-user-line me-1"></i><?= htmlspecialchars($timekeeper_name) ?>
                                </small>
                            </div>
                        </div>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item active">DTR Details</li>
                            </ol>
                        </div>
                    </div>
                </div>
                <!-- print section (hidden on screen, used by printDTRTable()) -->
                <!-- Add this CSS for print styling -->
                <style>
                    @media print {
                        body * {
                            visibility: hidden;
                            margin: 0;
                            padding: 0;
                        }

                        #print-section,
                        #print-section * {
                            visibility: visible;
                        }

                        #print-section {
                            display: block !important;
                            position: absolute;
                            left: 0;
                            top: 0;
                            width: 100%;
                            padding: 20px;
                            font-family: Arial, sans-serif;
                            font-size: 12px;
                            background: white;
                        }

                        /* Hide all other elements */
                        .main-content,
                        .page-content,
                        .container-fluid,
                        .card,
                        .btn,
                        .search-box {
                            display: none !important;
                        }

                        /* Print header styling */
                        .print-header {
                            text-align: center;
                            margin-bottom: 20px;
                            padding-bottom: 15px;
                            border-bottom: 2px solid #333;
                        }

                        .print-header h2 {
                            font-size: 18px;
                            margin-bottom: 10px;
                            color: #333;
                        }

                        .print-info {
                            margin-bottom: 15px;
                        }

                        .print-info p {
                            margin: 2px 0;
                            font-size: 11px;
                        }

                        .print-summary {
                            display: flex;
                            justify-content: center;
                            gap: 20px;
                            margin: 15px 0;
                            padding: 10px;
                            background-color: #f5f5f5;
                            border-radius: 4px;
                        }

                        .summary-item {
                            text-align: center;
                        }

                        .summary-item .label {
                            display: block;
                            font-size: 10px;
                            color: #666;
                        }

                        .summary-item .value {
                            display: block;
                            font-size: 12px;
                            font-weight: bold;
                            color: #333;
                        }

                        /* Print table styling */
                        .print-table {
                            width: 100%;
                            border-collapse: collapse;
                            margin: 15px 0;
                            font-size: 10px;
                        }

                        .print-table th {
                            background-color: #f8f9fa;
                            border: 1px solid #ddd;
                            padding: 6px 4px;
                            text-align: center;
                            font-weight: bold;
                        }

                        .print-table td {
                            border: 1px solid #ddd;
                            padding: 5px 3px;
                            text-align: left;
                        }

                        .print-table .text-center {
                            text-align: center;
                        }

                        .print-table .text-end {
                            text-align: right;
                        }

                        /* Date separator styling */
                        .date-separator {
                            background-color: #e9ecef;
                        }

                        .date-header {
                            padding: 8px 5px;
                            font-size: 11px;
                        }

                        .employee-count {
                            color: #666;
                            font-weight: normal;
                        }

                        .date-totals {
                            float: right;
                            font-weight: normal;
                            color: #666;
                        }

                        /* Employee row styling */
                        .employee-row td {
                            padding: 4px 3px;
                        }

                        .employee-name {
                            font-weight: 500;
                        }

                        /* Grand total styling */
                        .grand-total {
                            background-color: #d1ecf1;
                            font-weight: bold;
                        }

                        .grand-total td {
                            padding: 8px 3px;
                        }

                        /* Print footer */
                        .print-footer {
                            margin-top: 20px;
                            padding-top: 10px;
                            border-top: 1px solid #ddd;
                            text-align: center;
                            font-size: 10px;
                            color: #666;
                        }

                        /* Page break control */
                        .date-separator {
                            page-break-before: auto;
                            page-break-after: avoid;
                        }

                        .employee-row {
                            page-break-inside: avoid;
                        }
                    }

                    /* Screen styles for print button */
                    .btn-outline-info {
                        border-color: #17a2b8;
                        color: #17a2b8;
                    }

                    .btn-outline-info:hover {
                        background-color: #17a2b8;
                        color: white;
                    }

                    /* Ensure print section is hidden on screen */
                    #print-section {
                        display: none;
                    }
                </style>

                <!-- Add this hidden print table section at the bottom of your page, before the scripts -->
                <div id="print-section" style="display: none;">
                    <div class="print-header">
                        <h2>Daily Time Record (DTR) Details</h2>
                        <div class="print-info">
                            <p><strong>Period:</strong> <?= date('F d', strtotime($dtr['date_from'])) ?> - <?= date('F d, Y', strtotime($dtr['date_to'])) ?></p>
                            <p><strong>Site:</strong> <?= $dtr['site_name'] ?> (<?= $dtr['site_code'] ?>)</p>
                            <p><strong>Employer:</strong> <?= $dtr['employer_name'] ?></p>
                            <p><strong>Timekeeper:</strong> <?= $timekeeper_name ?></p>
                        </div>
                        <div class="print-summary">
                            <div class="summary-item">
                                <span class="label">Total Work Hours:</span>
                                <span class="value"><?= number_format($grandTotals['work_hours'], 2) ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="label">Total Overtime:</span>
                                <span class="value"><?= number_format($grandTotals['overtime'], 2) ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="label">Total Undertime:</span>
                                <span class="value"><?= number_format($grandTotals['undertime'], 2) ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="label">Total Late:</span>
                                <span class="value"><?= number_format($grandTotals['late'], 2) ?></span>
                            </div>
                        </div>
                    </div>

                    <table class="print-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Employee Name</th>
                                <th>Position</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Hours Worked</th>
                                <th>Overtime</th>
                                <th>Undertime</th>
                                <th>Late</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $currentDate = null;
                            $dateEmployeeCount = [];

                            // First pass to count employees per date
                            foreach ($groupedData as $date => $employees) {
                                $dateEmployeeCount[$date] = count($employees);
                            }

                            foreach ($groupedData as $date => $employees):
                                $dateTotal = $dateTotals[$date];
                                $isNewDate = $currentDate !== $date;
                                $currentDate = $date;
                            ?>
                                <?php if ($isNewDate): ?>
                                    <tr class="date-separator">
                                        <td colspan="9" class="date-header">
                                            <strong><?= date("F j, Y", strtotime($date)) ?></strong>
                                            <span class="employee-count">(<?= $dateEmployeeCount[$date] ?> employees)</span>
                                            <span class="date-totals">
                                                Hours: <?= number_format($dateTotal['work_hours'], 2) ?> |
                                                OT: <?= number_format($dateTotal['overtime'], 2) ?> |
                                                UT: <?= number_format($dateTotal['undertime'], 2) ?> |
                                                Late: <?= number_format($dateTotal['late'], 2) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php
                                $employeeCounter = 0;
                                foreach ($employees as $employeeId => $employeeData):
                                    $employeeTotal = $employeeTotals[$employeeId];
                                    $employeeCounter++;
                                    $entries = $employeeData['entries'];
                                    $firstEntry = reset($entries);
                                    $lastEntry = end($entries);

                                    // Get time in and time out from logs
                                    $timeIn = '';
                                    $timeOut = '';
                                    if (!empty($firstEntry['logs'])) {
                                        $logs = json_decode($firstEntry['logs'], true);
                                        if (is_array($logs) && count($logs) > 0) {
                                            $timeIn = date("g:i A", strtotime($logs[0]['dateTime']));
                                            $timeOut = count($logs) > 1 ? date("g:i A", strtotime(end($logs)['dateTime'])) : '';
                                        }
                                    }
                                ?>
                                    <tr class="employee-row">
                                        <td><?= date("m/d/Y", strtotime($date)) ?></td>
                                        <td class="employee-name">
                                            <?= $firstEntry['lastname'] ?>, <?= $firstEntry['firstname'] ?> <?= $firstEntry['middlename'] ?>
                                        </td>
                                        <td><?= $firstEntry['position'] ?></td>
                                        <td class="text-center"><?= $timeIn ?></td>
                                        <td class="text-center"><?= $timeOut ?></td>
                                        <td class="text-center"><?= number_format($employeeTotal['work_hours'], 2) ?></td>
                                        <td class="text-center"><?= number_format($employeeTotal['overtime'], 2) ?></td>
                                        <td class="text-center"><?= number_format($employeeTotal['undertime'], 2) ?></td>
                                        <td class="text-center"><?= number_format($employeeTotal['late'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>

                            <!-- Grand Total Row -->
                            <tr class="grand-total">
                                <td colspan="5" class="text-end"><strong>Grand Total:</strong></td>
                                <td class="text-center"><strong><?= number_format($grandTotals['work_hours'], 2) ?></strong></td>
                                <td class="text-center"><strong><?= number_format($grandTotals['overtime'], 2) ?></strong></td>
                                <td class="text-center"><strong><?= number_format($grandTotals['undertime'], 2) ?></strong></td>
                                <td class="text-center"><strong><?= number_format($grandTotals['late'], 2) ?></strong></td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="print-footer">
                        <p>Generated on: <?= date('F j, Y g:i A') ?></p>
                    </div>
                </div>
                <!-- Attendance List panel -->
                <div class="xl-panel" id="dtrDiv">
                    <div class="xl-ribbon">
                        <span class="xl-ribbon-title">
                            <i class="ri-time-line"></i> DTR Attendance
                        </span>
                        <div class="xl-ribbon-actions">
                            <div class="xl-search-wrap">
                                <i class="ri-search-2-line"></i>
                                <input id="myInput" type="text" placeholder="Search employee...">
                            </div>
                            <div class="xl-ribbon-sep"></div>
                            <button onclick="toggleAllGroups(true)"  class="xl-btn"><i class="ri-expand-up-down-line"></i> Expand All</button>
                            <button onclick="toggleAllGroups(false)" class="xl-btn"><i class="ri-contract-up-down-line"></i> Collapse All</button>
                            <div class="xl-ribbon-sep"></div>
                            <button onclick="printDTRTable()" class="xl-btn"><i class="ri-printer-line"></i> Print</button>
                            <?php if ($dtr['status'] === 0 && $login_role !== 6): ?>
                                <div class="xl-ribbon-sep"></div>
                                <button onclick="addSchedule(<?= $id ?>)" class="xl-btn"><i class="ri-add-line"></i> Add</button>
                            <?php endif; ?>
                            <?php if ($dtr['status'] === 1): ?>
                                <div class="xl-ribbon-sep"></div>
                                <button <?= $is_duplicate ? 'disabled' : '' ?> onclick="approveDtr(<?= $id ?>)" class="xl-btn xl-btn-save"><i class="ri-checkbox-circle-line"></i> Approve</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="xl-panel-body">
                        <!-- Summary stat cards -->
                        <div class="dtr-stats-row">
                            <div class="dtr-stat-box">
                                <div class="stat-icon" style="background:#e6f5f3;color:#219688;"><i class="ri-time-line"></i></div>
                                <div>
                                    <div class="stat-val" style="color:#219688;"><?= number_format($grandTotals['work_hours'], 2) ?></div>
                                    <div class="stat-lbl">Work Hours</div>
                                </div>
                            </div>
                            <div class="dtr-stat-box">
                                <div class="stat-icon" style="background:#fff8e1;color:#f7b84b;"><i class="ri-sun-line"></i></div>
                                <div>
                                    <div class="stat-val" style="color:#c98a00;"><?= number_format($grandTotals['overtime'], 2) ?></div>
                                    <div class="stat-lbl">Overtime</div>
                                </div>
                            </div>
                            <div class="dtr-stat-box">
                                <div class="stat-icon" style="background:#e3f2fd;color:#50a5f1;"><i class="ri-arrow-down-line"></i></div>
                                <div>
                                    <div class="stat-val" style="color:#1565c0;"><?= number_format($grandTotals['undertime'], 2) ?></div>
                                    <div class="stat-lbl">Undertime</div>
                                </div>
                            </div>
                            <div class="dtr-stat-box">
                                <div class="stat-icon" style="background:#fce4ec;color:#f06548;"><i class="ri-alarm-warning-line"></i></div>
                                <div>
                                    <div class="stat-val" style="color:#c62828;"><?= number_format($grandTotals['late'], 2) ?></div>
                                    <div class="stat-lbl">Late (min)</div>
                                </div>
                            </div>
                        </div>

                        <!-- Employee Notes Modal -->
                        <div class="modal fade" id="employeeNotesModal" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <div class="modal-header" style="border-bottom:2px solid #219688;">
                                        <h5 class="modal-title" style="color:#219688;"><i class="ri-sticky-note-line me-2"></i>Employee Notes</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <h6 id="modalEmployeeName" class="mb-1" style="color:#219688;font-weight:600;"></h6>
                                        <p id="modalEmployeePosition" class="text-muted small mb-0"></p>
                                        <p id="modalEmployeeDate" class="text-muted small mb-2"></p>
                                        <label class="form-label fw-semibold small">Notes:</label>
                                        <div id="modalNotesContent" class="p-3 bg-light rounded border small"></div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── Segment toggle ── -->
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:6px;">
                            <div class="dtr-segment" id="dtr-segment">
                                <button class="dtr-seg-btn active" data-view="day" onclick="switchDtrView('day',this)">
                                    <i class="ri-calendar-line"></i> By Day
                                </button>
                                <button class="dtr-seg-btn" data-view="employee" onclick="switchDtrView('employee',this)">
                                    <i class="ri-group-line"></i> By Employee
                                </button>
                            </div>
                            <span id="dtr-view-count" style="font-size:11px;color:#888;"></span>
                        </div>

                        <div class="table-responsive2" id="dtr-table-responsive">
                            <table cellspacing="0" id="table-1">
                                <thead>
                                    <tr>
                                        <th class="text-center primary-header">Date</th>
                                        <th class="text-center primary-header">Employee</th>
                                        <th class="text-center primary-header">Position</th>
                                        <th class="text-center primary-header">Time In</th>
                                        <th class="text-center primary-header">Time Out</th>
                                        <th class="text-center primary-header">Hours</th>
                                        <th class="text-center primary-header">OT</th>
                                        <th class="text-center primary-header">Undertime</th>
                                        <th class="text-center primary-header">Late</th>
                                        <th class="text-center primary-header">Logs</th>
                                        <th class="text-center primary-header">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-by-day">
                                    <?php foreach ($groupedData as $date => $employees):
                                        $dateTotal = $dateTotals[$date];
                                        $dateKey   = 'dg-' . md5($date);
                                    ?>
                                        <!-- Date header (collapsible) -->
                                        <tr class="date-separator" data-toggle-group="<?= $dateKey ?>">
                                            <td colspan="11">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div class="d-flex align-items-center">
                                                        <i class="ri-arrow-down-s-line dtr-chevron me-2"></i>
                                                        <span class="dtr-date-label"><?= date("l, F j, Y", strtotime($date)) ?></span>
                                                        <span class="dtr-emp-count"><?= count($employees) ?> employees</span>
                                                    </div>
                                                    <div class="dtr-date-totals">
                                                        <span><i class="ri-time-line me-1"></i><?= number_format($dateTotal['work_hours'], 2) ?> hrs</span>
                                                        <span style="color:#ffd166;">OT <?= number_format($dateTotal['overtime'], 2) ?></span>
                                                        <span style="color:#ff9090;">Late <?= number_format($dateTotal['late'], 2) ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>

                                        <?php
                                        foreach ($employees as $employeeId => $employeeData):
                                            $entries  = $employeeData['entries'];
                                            $empWH = $empOT = $empUT = $empLate = 0;
                                            foreach ($entries as $entry) {
                                                $empWH   += floatval($entry['work_hours']);
                                                $empOT   += floatval($entry['overtime']);
                                                $empUT   += floatval($entry['undertime']);
                                                $empLate += floatval($entry['late']);
                                            }
                                            $initials = strtoupper(
                                                substr($employeeData['employee_info']['firstname'], 0, 1) .
                                                substr($employeeData['employee_info']['lastname'],  0, 1)
                                            );
                                        ?>
                                            <!-- Employee card row -->
                                            <tr class="employee-header dtr-group-row" data-group="<?= $dateKey ?>">
                                                <td colspan="11">
                                                    <div class="dtr-emp-card">
                                                        <div class="d-flex align-items-center gap-2">
                                                            <div class="dtr-emp-init"><?= $initials ?></div>
                                                            <div>
                                                                <div>
                                                                    <span class="dtr-emp-name"><?= $employeeData['employee_info']['lastname'] ?>, <?= $employeeData['employee_info']['firstname'] ?> <?= $employeeData['employee_info']['middlename'] ?></span>
                                                                    <?php if (!empty($employeeData['employee_info']['notes'])): ?>
                                                                        <span class="badge bg-warning text-dark ms-1" style="font-size:10px;cursor:pointer;"
                                                                            onclick="showEmployeeNotes('<?= htmlspecialchars($employeeData['employee_info']['lastname'].', '.$employeeData['employee_info']['firstname'].' '.$employeeData['employee_info']['middlename']) ?>','<?= htmlspecialchars($employeeData['employee_info']['position'] ?? '') ?>','<?= htmlspecialchars($date) ?>',`<?= htmlspecialchars($employeeData['employee_info']['notes']) ?>`)">
                                                                            <i class="ri-sticky-note-line"></i> Notes
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <span class="dtr-emp-pos"><?= $employeeData['employee_info']['position'] ?></span>
                                                            </div>
                                                        </div>
                                                        <div class="dtr-emp-totals">
                                                            <div class="dtr-tot-item"><span class="tot-lbl">Hrs</span><span class="tot-val"><?= number_format($empWH, 2) ?></span></div>
                                                            <div class="dtr-tot-item ot"><span class="tot-lbl">OT</span><span class="tot-val"><?= number_format($empOT, 2) ?></span></div>
                                                            <div class="dtr-tot-item ut"><span class="tot-lbl">UT</span><span class="tot-val"><?= number_format($empUT, 2) ?></span></div>
                                                            <div class="dtr-tot-item late"><span class="tot-lbl">Late</span><span class="tot-val"><?= number_format($empLate, 2) ?></span></div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>

                                            <!-- Individual entries -->
                                            <?php foreach ($entries as $row):
                                                $logs = json_decode($row['logs']);
                                                $logs = isset($logs) ? $logs : [];
                                                $date_check  = date("Y-m-d", strtotime($row['date_time']));
                                                $employee_id = $row['employee_id'];
                                                $check_duplicate = $conn->query("SELECT DTR.*, timekeeper.name AS timekeeper_name, uploaded.name AS uploaded_by
                                                    FROM DTR_details
                                                    LEFT JOIN DTR ON DTR_details.ddtr_id = DTR.id
                                                    LEFT JOIN users AS timekeeper ON DTR.timekeeper_id = timekeeper.id
                                                    LEFT JOIN users AS uploaded ON DTR.uploaded_by = uploaded.id
                                                    WHERE date_time = '$date_check' AND employee_id = '$employee_id' AND ddtr_id != '$id'
                                                    GROUP BY date_time");
                                                $timekeeper_name = $device_id2 = $status = $site_id2 = $id_dtr = $site_name = '';
                                                if ($check_duplicate->num_rows) {
                                                    $is_duplicate = true;
                                                    while ($row_check = $check_duplicate->fetch_assoc()) {
                                                        $timekeeper_name = $row_check['timekeeper_name'];
                                                        $device_id2 = $row_check['device_id'];
                                                        $status     = $row_check['status'];
                                                        $site_id2   = $row_check['site_id'];
                                                        $id_dtr     = $row_check['id'];
                                                        $site_name  = $row_check['site_id'];
                                                    }
                                                } else {
                                                    $is_duplicate = false;
                                                }
                                                $timeIn = $timeOut = '';
                                                if (!empty($logs) && count($logs) > 0) {
                                                    $timeIn  = date("g:i A", strtotime($logs[0]->dateTime));
                                                    $timeOut = count($logs) > 1 ? date("g:i A", strtotime(end($logs)->dateTime)) : 'N/A';
                                                }
                                            ?>
                                                <tr class="attendance-entry dtr-group-row <?= $is_duplicate ? 'duplicate-entry' : '' ?>" data-group="<?= $dateKey ?>">
                                                    <td class="text-center">
                                                        <div class="fw-semibold" style="font-size:11px;"><?= date("M j", strtotime($row['date_time'])) ?></div>
                                                        <div class="text-muted" style="font-size:10px;"><?= date("D", strtotime($row['date_time'])) ?></div>
                                                    </td>
                                                    <td>
                                                        <div class="fw-semibold" style="font-size:11px;"><?= $row['lastname'] ?>, <?= $row['firstname'] ?></div>
                                                        <div class="text-muted" style="font-size:10px;"><?= $row['employee_no'] ?></div>
                                                    </td>
                                                    <td><span class="dtr-pos-chip"><?= $row['position'] ?></span></td>
                                                    <td class="text-center"><span class="dtr-time-chip in"><?= $timeIn ?: '—' ?></span></td>
                                                    <td class="text-center"><span class="dtr-time-chip <?= ($timeOut === 'N/A' || !$timeOut) ? 'na' : 'out' ?>"><?= $timeOut ?: '—' ?></span></td>
                                                    <td class="text-center">
                                                        <?php if ($login_role !== 6): ?>
                                                            <div class="editable-field">
                                                                <input type="text" value="<?= $row['work_hours'] ?>" class="form-control form-control-sm text-center" style="width:68px;">
                                                                <button class="update-dtr-field" data-id="<?= $row['id'] ?>" data-field="work_hours"><i class="ri-save-line"></i></button>
                                                            </div>
                                                        <?php else: ?><span><?= $row['work_hours'] ?></span><?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($login_role !== 6): ?>
                                                            <div class="editable-field">
                                                                <input type="text" value="<?= $row['overtime'] ?>" class="form-control form-control-sm text-center" style="width:68px;">
                                                                <button class="update-dtr-field" data-id="<?= $row['id'] ?>" data-field="overtime"><i class="ri-save-line"></i></button>
                                                            </div>
                                                        <?php else: ?><span><?= $row['overtime'] ?></span><?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($login_role !== 6): ?>
                                                            <div class="editable-field">
                                                                <input type="text" value="<?= $row['undertime'] ?>" class="form-control form-control-sm text-center" style="width:68px;">
                                                                <button class="update-dtr-field" data-id="<?= $row['id'] ?>" data-field="undertime"><i class="ri-save-line"></i></button>
                                                            </div>
                                                        <?php else: ?><span><?= $row['undertime'] ?></span><?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($login_role !== 6): ?>
                                                            <div class="editable-field">
                                                                <input type="text" value="<?= $row['late'] ?>" class="form-control form-control-sm text-center" style="width:68px;">
                                                                <button class="update-dtr-field" data-id="<?= $row['id'] ?>" data-field="late"><i class="ri-save-line"></i></button>
                                                            </div>
                                                        <?php else: ?><span><?= $row['late'] ?></span><?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php
                                                        $popLines = '';
                                                        foreach ($logs as $li => $log) {
                                                            $isBio = $log->type === 'bio';
                                                            $chip  = $isBio ? 'bio' : 'manual';
                                                            $icon  = $isBio ? 'ri-fingerprint-line' : 'ri-edit-line';
                                                            $label = ($li === 0) ? 'IN' : (($li === count($logs)-1) ? 'OUT' : '#'.($li+1));
                                                            $popLines .= '<div style="display:flex;align-items:center;gap:6px;padding:3px 0;">'
                                                                . '<span style="font-size:10px;font-weight:700;color:#888;min-width:26px;">' . $label . '</span>'
                                                                . '<span class="dtr-log-chip ' . $chip . '"><i class="' . $icon . '"></i>' . date("g:i A", strtotime($log->dateTime)) . '</span>'
                                                                . '</div>';
                                                        }
                                                        if (!$popLines) $popLines = '<span style="color:#aaa;font-size:11px;">No logs</span>';
                                                        $popContent = htmlspecialchars('<div style="min-width:150px;">' . $popLines . '</div>');
                                                        $totalLogs = count($logs);
                                                        ?>
                                                        <?php if ($totalLogs > 0): ?>
                                                        <span class="dtr-logs-pill"
                                                            data-bs-toggle="popover"
                                                            data-bs-trigger="click"
                                                            data-bs-placement="left"
                                                            data-bs-html="true"
                                                            data-bs-content="<?= $popContent ?>"
                                                            title="Logs"
                                                            style="cursor:pointer;">
                                                            <?php if ($timeIn): ?><span style="color:#219688;font-weight:600;"><?= $timeIn ?></span><?php endif; ?>
                                                            <?php if ($timeOut && $timeOut !== 'N/A'): ?><span style="color:#888;"> – </span><span style="color:#c62828;font-weight:600;"><?= $timeOut ?></span><?php endif; ?>
                                                            <span class="dtr-logs-count"><?= $totalLogs ?> log<?= $totalLogs > 1 ? 's' : '' ?></span>
                                                        </span>
                                                        <?php else: ?>
                                                            <span style="color:#ccc;font-size:11px;">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="btn-group btn-group-sm">
                                                            <?php if ($login_role !== 6): ?>
                                                                <button title="Delete" onclick="deleteDTRLogs(<?= $row['id'] ?>)" class="btn btn-outline-danger"><i class="ri-delete-bin-line"></i></button>
                                                            <?php endif; ?>
                                                            <?php if ($is_duplicate): ?>
                                                                <a target="_blank" title="Duplicate"
                                                                    href="index.php?page=dtr-details&id=<?= base64_encode($id_dtr) ?>&timekeeper_name=<?= base64_encode($timekeeper_name) ?>&device_id=<?= base64_encode($device_id2) ?>&site_id=<?= base64_encode($site_id2) ?>&status=<?= base64_encode($status) ?>"
                                                                    class="btn btn-outline-warning"><i class="ri-alert-line"></i></a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>

                                    <!-- Grand Total Row -->
                                    <tr class="grand-total-row">
                                        <td colspan="5" class="text-end">GRAND TOTAL</td>
                                        <td class="text-center"><?= number_format($grandTotals['work_hours'], 2) ?></td>
                                        <td class="text-center"><?= number_format($grandTotals['overtime'], 2) ?></td>
                                        <td class="text-center"><?= number_format($grandTotals['undertime'], 2) ?></td>
                                        <td class="text-center"><?= number_format($grandTotals['late'], 2) ?></td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tbody>

                            </table>
                        </div>

                        <!-- ── By Employee Card View ── -->
                        <div id="view-by-employee" style="display:none;padding:4px 2px 8px;">
                        <?php foreach ($byEmployee as $empId => $empGroup):
                            $info     = $empGroup['employee_info'];
                            $empTotals = $employeeTotals[$empId];
                            $empInitials = strtoupper(substr($info['firstname'],0,1).substr($info['lastname'],0,1));
                            $totalDays = count($empGroup['dates']);
                            $cardId = 'emp-card-' . $empId;
                        ?>
                        <div class="ecard">
                            <!-- Card header -->
                            <div class="ecard-header" onclick="toggleEmpCard('<?= $cardId ?>')">
                                <div class="ecard-left">
                                    <div class="dtr-emp-init"><?= $empInitials ?></div>
                                    <div>
                                        <div class="ecard-name"><?= htmlspecialchars($info['lastname'].', '.$info['firstname'].' '.($info['middlename']??'')) ?></div>
                                        <div class="ecard-meta">
                                            <span class="dtr-pos-chip"><?= htmlspecialchars($info['position']) ?></span>
                                            <span class="ecard-days"><i class="ri-calendar-line"></i><?= $totalDays ?> day<?= $totalDays!=1?'s':'' ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="ecard-right">
                                    <div class="dtr-emp-totals">
                                        <div class="dtr-tot-item"><span class="tot-lbl">Hrs</span><span class="tot-val"><?= number_format($empTotals['work_hours'],2) ?></span></div>
                                        <div class="dtr-tot-item ot"><span class="tot-lbl">OT</span><span class="tot-val"><?= number_format($empTotals['overtime'],2) ?></span></div>
                                        <div class="dtr-tot-item ut"><span class="tot-lbl">UT</span><span class="tot-val"><?= number_format($empTotals['undertime'],2) ?></span></div>
                                        <div class="dtr-tot-item late"><span class="tot-lbl">Late</span><span class="tot-val"><?= number_format($empTotals['late'],2) ?></span></div>
                                    </div>
                                    <i class="ri-arrow-down-s-line ecard-chevron"></i>
                                </div>
                            </div>

                            <!-- Card body: date entries -->
                            <div class="ecard-body" id="<?= $cardId ?>">
                            <?php foreach ($empGroup['dates'] as $date => $entries):
                                $dayWH = $dayOT = $dayLate = 0;
                                foreach ($entries as $e) {
                                    $dayWH   += floatval($e['work_hours']);
                                    $dayOT   += floatval($e['overtime']);
                                    $dayLate += floatval($e['late']);
                                }
                            ?>
                                <div class="ecard-date-group">
                                    <div class="ecard-date-header">
                                        <span class="ecard-date-label">
                                            <i class="ri-calendar-event-line"></i>
                                            <?= date("D, M j", strtotime($date)) ?>
                                        </span>
                                        <div style="display:flex;gap:10px;font-size:10px;">
                                            <span style="color:#219688;font-weight:600;"><?= number_format($dayWH,2) ?> hrs</span>
                                            <?php if ($dayOT > 0): ?><span style="color:#c98a00;">OT <?= number_format($dayOT,2) ?></span><?php endif; ?>
                                            <?php if ($dayLate > 0): ?><span style="color:#c62828;">Late <?= number_format($dayLate,2) ?></span><?php endif; ?>
                                        </div>
                                    </div>
                                    <?php foreach ($entries as $row):
                                        $logs2 = json_decode($row['logs']) ?: [];
                                        $tIn = $tOut = '';
                                        if (!empty($logs2)) {
                                            $tIn  = date("g:i A", strtotime($logs2[0]->dateTime));
                                            $tOut = count($logs2) > 1 ? date("g:i A", strtotime(end($logs2)->dateTime)) : '';
                                        }
                                        $logCount = count($logs2);
                                        // popover
                                        $pl = '';
                                        foreach ($logs2 as $li => $lg) {
                                            $iB = $lg->type==='bio';
                                            $lbl3 = ($li===0)?'IN':(($li===count($logs2)-1)?'OUT':'#'.($li+1));
                                            $pl .= '<div style="display:flex;align-items:center;gap:6px;padding:3px 0;"><span style="font-size:10px;font-weight:700;color:#888;min-width:26px;">'.$lbl3.'</span><span class="dtr-log-chip '.($iB?'bio':'manual').'"><i class="'.($iB?'ri-fingerprint-line':'ri-edit-line').'"></i>'.date("g:i A",strtotime($lg->dateTime)).'</span></div>';
                                        }
                                        if (!$pl) $pl = '<span style="color:#aaa;font-size:11px;">No logs</span>';
                                        $pc = htmlspecialchars('<div style="min-width:150px;">'.$pl.'</div>');
                                    ?>
                                    <div class="ecard-entry">
                                        <!-- Time in/out -->
                                        <div class="ecard-times">
                                            <span class="dtr-time-chip in"><?= $tIn ?: '—' ?></span>
                                            <span style="color:#ccc;font-size:10px;">→</span>
                                            <span class="dtr-time-chip <?= $tOut?'out':'na' ?>"><?= $tOut ?: '—' ?></span>
                                            <?php if ($logCount > 0): ?>
                                            <span class="dtr-logs-pill"
                                                data-bs-toggle="popover" data-bs-trigger="click"
                                                data-bs-placement="top" data-bs-html="true"
                                                data-bs-content="<?= $pc ?>" title="Logs" style="cursor:pointer;margin-left:4px;">
                                                <span class="dtr-logs-count"><?= $logCount ?> log<?= $logCount>1?'s':'' ?></span>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        <!-- Stats -->
                                        <div class="ecard-entry-stats">
                                            <span class="ecard-stat"><span class="ecard-stat-lbl">Hrs</span><span class="ecard-stat-val"><?= $row['work_hours'] ?></span></span>
                                            <span class="ecard-stat ot"><span class="ecard-stat-lbl">OT</span><span class="ecard-stat-val"><?= $row['overtime'] ?></span></span>
                                            <span class="ecard-stat ut"><span class="ecard-stat-lbl">UT</span><span class="ecard-stat-val"><?= $row['undertime'] ?></span></span>
                                            <span class="ecard-stat late"><span class="ecard-stat-lbl">Late</span><span class="ecard-stat-val"><?= $row['late'] ?></span></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>

                    </div><!-- end xl-panel-body -->
                </div><!-- end xl-panel -->

            </div>
            <!-- end page title -->
        </div>
        <!-- container-fluid -->
    </div>
    <!-- End Page-content -->
</div>
<?php
function human_time_diff(int $ts): string {
    $diff = time() - $ts;
    if ($diff < 60)    return 'Just now';
    if ($diff < 3600)  return floor($diff/60) . 'm ago';
    if ($diff < 86400) return floor($diff/3600) . 'h ago';
    if ($diff < 604800) return floor($diff/86400) . 'd ago';
    return date('M j, Y', $ts);
}
?>
<?php include 'component/add_attendance.php'; ?>
<script src="assets/js/dtr-details.js"></script>
<script>
    // Fit table height to viewport
    function fitDtrTable() {
        const c = document.getElementById('dtr-table-responsive');
        if (!c) return;
        const top = c.getBoundingClientRect().top + window.scrollY;
        const available = window.innerHeight - (top - window.scrollY) - 24;
        c.style.height = Math.max(available, 200) + 'px';
    }
    document.addEventListener('DOMContentLoaded', fitDtrTable);
    window.addEventListener('resize', fitDtrTable);

    // ── Collapsible date groups ──
    function setGroupVisible(key, visible) {
        document.querySelectorAll('.dtr-group-row[data-group="' + key + '"]').forEach(function (row) {
            row.style.display = visible ? '' : 'none';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.date-separator').forEach(function (sep) {
            sep.addEventListener('click', function () {
                var key = sep.getAttribute('data-toggle-group');
                var isCollapsed = sep.classList.toggle('collapsed');
                setGroupVisible(key, !isCollapsed);
            });
        });
    });

    function toggleAllGroups(expand) {
        document.querySelectorAll('.date-separator').forEach(function (sep) {
            var key = sep.getAttribute('data-toggle-group');
            if (expand) { sep.classList.remove('collapsed'); } else { sep.classList.add('collapsed'); }
            setGroupVisible(key, expand);
        });
    }
</script>


<script>
    // ── Segment switch ──
    document.addEventListener('DOMContentLoaded', function () {
        var days = document.querySelectorAll('#tbody-by-day tr.date-separator').length;
        document.getElementById('dtr-view-count').textContent = days + ' day' + (days !== 1 ? 's' : '');
    });

    function switchDtrView(view, btn) {
        document.querySelectorAll('.dtr-seg-btn').forEach(function(b){ b.classList.remove('active'); });
        btn.classList.add('active');

        var tableWrap = document.getElementById('dtr-table-responsive');
        var cardView  = document.getElementById('view-by-employee');
        if (view === 'day') {
            tableWrap.style.display = '';
            cardView.style.display  = 'none';
            var days = document.querySelectorAll('#tbody-by-day tr.date-separator').length;
            document.getElementById('dtr-view-count').textContent = days + ' day' + (days !== 1 ? 's' : '');
        } else {
            tableWrap.style.display = 'none';
            cardView.style.display  = '';
            var emps = document.querySelectorAll('.ecard').length;
            document.getElementById('dtr-view-count').textContent = emps + ' employee' + (emps !== 1 ? 's' : '');
        }
        document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function(el){
            if (!bootstrap.Popover.getInstance(el)) new bootstrap.Popover(el, { sanitize: false });
        });
    }

    function toggleEmpCard(id) {
        var body = document.getElementById(id);
        var card = body.closest('.ecard');
        card.classList.toggle('open');
    }

    // ── Popovers ──
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
            var pop = new bootstrap.Popover(el, { sanitize: false });
            // close other open popovers when a new one opens
            el.addEventListener('shown.bs.popover', function () {
                document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (other) {
                    if (other !== el) bootstrap.Popover.getInstance(other)?.hide();
                });
            });
        });
        // click outside closes all
        document.addEventListener('click', function (e) {
            if (!e.target.closest('[data-bs-toggle="popover"]')) {
                document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
                    bootstrap.Popover.getInstance(el)?.hide();
                });
            }
        });
    });

    // ── Search ──
    document.addEventListener('DOMContentLoaded', function () {
        const searchInput = document.getElementById('myInput');
        if (!searchInput) return;
        searchInput.addEventListener('keyup', function () {
            const filter = this.value.toLowerCase().trim();
            document.querySelectorAll('#table-1 tbody tr.attendance-entry').forEach(function (row) {
                if (row.classList.contains('dtr-hidden')) return;
                row.style.display = (filter === '' || row.textContent.toLowerCase().includes(filter)) ? '' : 'none';
            });
            // Show/hide emp headers and date rows based on visible entries
            document.querySelectorAll('.date-separator').forEach(function (sep) {
                var key = sep.getAttribute('data-toggle-group');
                var hasVisible = Array.from(document.querySelectorAll('.attendance-entry[data-group="' + key + '"]'))
                    .some(function (r) { return r.style.display !== 'none'; });
                sep.style.display = hasVisible ? '' : 'none';
                document.querySelectorAll('.employee-header[data-group="' + key + '"]').forEach(function (h) {
                    h.style.display = hasVisible ? '' : 'none';
                });
            });
        });
    });
</script>
<script>
    function printDTRTable() {
        // Create a new window for printing
        const printWindow = window.open('', '_blank');
        const printContent = document.getElementById('print-section').innerHTML;

        // Write the print content to the new window
        printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>DTR Details - <?= $dtr['site_name'] ?></title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    font-size: 12px;
                    margin: 0;
                    padding: 20px;
                    color: #333;
                }
                
                .print-header {
                    text-align: center;
                    margin-bottom: 20px;
                    padding-bottom: 15px;
                    border-bottom: 2px solid #333;
                }
                
                .print-header h2 {
                    font-size: 18px;
                    margin-bottom: 10px;
                    color: #333;
                }
                
                .print-info {
                    margin-bottom: 15px;
                }
                
                .print-info p {
                    margin: 2px 0;
                    font-size: 11px;
                }
                
                .print-summary {
                    display: flex;
                    justify-content: center;
                    gap: 20px;
                    margin: 15px 0;
                    padding: 10px;
                    background-color: #f5f5f5;
                    border-radius: 4px;
                }
                
                .summary-item {
                    text-align: center;
                }
                
                .summary-item .label {
                    display: block;
                    font-size: 10px;
                    color: #666;
                }
                
                .summary-item .value {
                    display: block;
                    font-size: 12px;
                    font-weight: bold;
                    color: #333;
                }
                
                .print-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 15px 0;
                    font-size: 10px;
                }
                
                .print-table th {
                    background-color: #f8f9fa;
                    border: 1px solid #ddd;
                    padding: 6px 4px;
                    text-align: center;
                    font-weight: bold;
                }
                
                .print-table td {
                    border: 1px solid #ddd;
                    padding: 5px 3px;
                    text-align: left;
                }
                
                .print-table .text-center {
                    text-align: center;
                }
                
                .print-table .text-end {
                    text-align: right;
                }
                
                .date-separator {
                    background-color: #e9ecef;
                }
                
                .date-header {
                    padding: 8px 5px;
                    font-size: 11px;
                }
                
                .employee-count {
                    color: #666;
                    font-weight: normal;
                }
                
                .date-totals {
                    float: right;
                    font-weight: normal;
                    color: #666;
                }
                
                .employee-row td {
                    padding: 4px 3px;
                }
                
                .employee-name {
                    font-weight: 500;
                }
                
                .grand-total {
                    background-color: #d1ecf1;
                    font-weight: bold;
                }
                
                .grand-total td {
                    padding: 8px 3px;
                }
                
                .print-footer {
                    margin-top: 20px;
                    padding-top: 10px;
                    border-top: 1px solid #ddd;
                    text-align: center;
                    font-size: 10px;
                    color: #666;
                }
                
                @media print {
                    body {
                        margin: 0;
                        padding: 15px;
                    }
                    
                    .print-table {
                        font-size: 9px;
                    }
                }
            </style>
        </head>
        <body>
            ${printContent}
            <script>
                window.onload = function() {
                    window.print();
                    setTimeout(function() {
                        window.close();
                    }, 500);
                };
            <\/script>
        </body>
        </html>
    `);

        printWindow.document.close();
    }

    // Optional: Add keyboard shortcut for printing (Ctrl+P)
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
            e.preventDefault();
            printDTRTable();
        }
    });

    // Add this JavaScript function to handle the modal
    function showEmployeeNotes(employeeName, position, date, notes) {
        // Set modal content
        document.getElementById('modalEmployeeName').textContent = employeeName;
        document.getElementById('modalEmployeePosition').textContent = 'Position: ' + (position || 'N/A');
        document.getElementById('modalEmployeeDate').textContent = 'Date: ' + (date ? new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        }) : 'N/A');

        // Format and set notes content
        const notesContent = document.getElementById('modalNotesContent');
        if (notes && notes.trim() !== '') {
            // Convert line breaks to <br> tags and preserve formatting
            const formattedNotes = notes.replace(/\n/g, '<br>');
            notesContent.innerHTML = formattedNotes;
            notesContent.classList.remove('text-muted', 'fst-italic');
        } else {
            notesContent.textContent = 'No notes available.';
            notesContent.classList.add('text-muted', 'fst-italic');
        }

        // Show the modal
        const modal = new bootstrap.Modal(document.getElementById('employeeNotesModal'));
        modal.show();
    }

    // Optional: Add keyboard shortcut to close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = bootstrap.Modal.getInstance(document.getElementById('employeeNotesModal'));
            if (modal) {
                modal.hide();
            }
        }
    });
</script>