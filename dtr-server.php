<?php
include 'db_connect.php';

// DataTable request parameters
$draw = $_POST['draw'];
$start = $_POST['start'];
$length = $_POST['length'];

// Optional date-range filter (passed from dtr.php DataTable ajax data)
$range_query = '';
if (!empty($_POST['date_from']) && !empty($_POST['date_to'])) {
    $df = $conn->real_escape_string($_POST['date_from']);
    $dt = $conn->real_escape_string($_POST['date_to']);
    $range_query = " AND DTR.date_from >= '$df' AND DTR.date_to <= '$dt'";
}

// Query to get total records
$totalRecordsQuery = "SELECT COUNT(*) as total FROM DTR WHERE status = 2 $range_query";
$totalRecordsResult = $conn->query($totalRecordsQuery);
$totalRecords = $totalRecordsResult->fetch_assoc()['total'];

// Fetch data with LIMIT for pagination
$query = "SELECT DTR.*, sites.site_code, sites.site_name, sites.site_address, 
                timekeeper.name AS timekeeper_name, uploaded.name AS uploaded_by, 
                employer_name, approved.name AS approve_by
          FROM DTR 
          LEFT JOIN sites ON DTR.site_id = sites.id 
          LEFT JOIN users AS timekeeper ON DTR.timekeeper_id = timekeeper.id 
          LEFT JOIN users AS uploaded ON DTR.uploaded_by = uploaded.id 
          LEFT JOIN users AS approved ON DTR.approved_by = approved.id  
          LEFT JOIN employers ON DTR.employer_id = employers.id 
          WHERE DTR.status = 2 $range_query
          ORDER BY DTR.id DESC
          LIMIT $start, $length";

$result = $conn->query($query);

$data = [];

while ($row = $result->fetch_assoc()) {
    $period = date("M d", strtotime($row['date_from'])) . ' &ndash; ' . date("M j, Y", strtotime($row['date_to']));

    $site_info = '<span class="dtr-site-code">' . htmlspecialchars($row['site_code']) . '</span>'
               . '<div class="dtr-site-name">' . htmlspecialchars($row['site_name']) . '</div>'
               . '<div class="dtr-site-addr">' . htmlspecialchars($row['site_address']) . '</div>';

    $action = '<div class="dtr-action">'
            . '<button class="btn btn-sm btn-outline-success view-dtr"'
            . ' data-id="' . base64_encode($row['id']) . '"'
            . ' data-timekeeper="' . base64_encode($row['timekeeper_name']) . '"'
            . ' data-device="' . base64_encode($row['device_id']) . '"'
            . ' data-site="' . base64_encode($row['site_id']) . '"'
            . ' data-status="' . base64_encode($row['status']) . '"'
            . ' data-bs-toggle="tooltip" data-bs-placement="top" title="View DTR Details">'
            . '<i class="ri-eye-line me-1"></i>View</button>'
            . '</div>';

    $status_badge = '<span class="badge bg-success"><i class="ri-checkbox-circle-line me-1"></i>Approved</span>';

    $data[] = [
        "period"     => '<div class="dtr-period"><i class="ri-calendar-2-line me-1 text-muted"></i>' . $period . '</div>',
        "site"       => $site_info,
        "status"     => '<div class="text-center">' . $status_badge . '</div>',
        "approve_by" => '<div class="dtr-user"><i class="ri-shield-check-line me-1 text-muted"></i>' . htmlspecialchars($row['approve_by']) . '</div>',
        "action"     => $action,
    ];
}

// Return JSON response
$response = [
    "draw" => intval($draw),
    "recordsTotal" => $totalRecords,
    "recordsFiltered" => $totalRecords,
    "data" => $data
];

echo json_encode($response);
?>
