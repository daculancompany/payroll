<?php
/**
 * Employee details — TIMEKEEPER VIEW (role 5).
 *
 * A Timekeeper is a scanner operator, not an HR/payroll user: this page is a
 * deliberate near-empty stand-in for employee-details.php, carrying only the
 * employee's identity header and the enrolled-fingerprint status. No pay, no
 * loans/contributions/deductions, no government IDs, no schedule, no leave.
 *
 * Routed from index.php (?page=employee-details) whenever is_timekeeper() —
 * the full page is never included for this role, so there is nothing on the
 * wire to hide with CSS.
 */

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid Employee ID");
}

$emp_id = (int) $_GET['id'];

$stmt = $conn->prepare("
    SELECT e.id, e.employee_no, e.firstname, e.middlename, e.lastname, e.ext,
           e.status, e.department_id,
           p.name AS pname, d.name AS dept_name
    FROM employee e
    INNER JOIN position p ON e.position_id = p.id
    LEFT JOIN department d ON e.department_id = d.id
    WHERE e.id = ?
");
$stmt->bind_param("i", $emp_id);
$stmt->execute();
$emp = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$emp) {
    die("Employee not found.");
}

// Department-scoped accounts may only open their own department's employees.
require_once 'dept-scope.php';
if (dept_scope_id() > 0 && (int) $emp['department_id'] !== dept_scope_id()) {
    die("You don't have access to this employee's record.");
}

if (!function_exists('esc')) {
    function esc($v)
    {
        return htmlspecialchars((string) ($v ?? ''));
    }
}

$tk_fullname = trim($emp['lastname'] . ', ' . $emp['firstname'] . ' ' . ($emp['middlename'] ?? '') . ' ' . ($emp['ext'] ?? ''));
$tk_initials = strtoupper(substr($emp['firstname'], 0, 1) . substr($emp['lastname'], 0, 1));

require_once __DIR__ . '/component/finger_hands.php';
?>
<style>
    .tk-hdr-card { border: 1px solid #e3e9ee; border-radius: 8px; background: #fff; }
    .tk-avatar {
        width: 56px; height: 56px; border-radius: 50%;
        background: #673bb6; color: #fff; font-size: 20px; font-weight: 700;
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .tk-name { font-size: 17px; font-weight: 700; line-height: 1.2; }
    .tk-meta { font-size: 12px; color: #667085; }
    .tk-meta b { color: #344054; font-weight: 600; }
</style>

<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0">
                            <i class="ri-fingerprint-line me-2" style="color:#673bb6;"></i>Fingerprint Enrollment
                        </h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="employee">Employees</a></li>
                                <li class="breadcrumb-item active">Fingerprints</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Identity header — just enough to be sure it's the right person. -->
            <div class="card tk-hdr-card mb-3">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <div class="tk-avatar"><?= esc($tk_initials) ?></div>
                        <div class="flex-grow-1">
                            <div class="tk-name"><?= esc($tk_fullname) ?></div>
                            <div class="tk-meta">
                                <i class="ri-hashtag"></i><b><?= esc($emp['employee_no']) ?></b>
                                <span class="mx-2">&middot;</span>
                                <i class="ri-briefcase-4-line me-1"></i><?= esc($emp['pname']) ?>
                                <span class="mx-2">&middot;</span>
                                <i class="ri-building-3-line me-1"></i><?= esc($emp['dept_name'] ?: '—') ?>
                            </div>
                        </div>
                        <div>
                            <?php if ((int) $emp['status'] === 1): ?>
                                <span class="badge rounded-pill bg-success"><i class="ri-checkbox-circle-line me-1"></i>Active</span>
                            <?php else: ?>
                                <span class="badge rounded-pill bg-danger"><i class="ri-close-circle-line me-1"></i>Inactive</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <a href="employee" class="btn btn-sm btn-outline-secondary">
                                <i class="ri-arrow-left-line me-1"></i>Back to list
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- The only content a Timekeeper gets: enrolled fingerprints.
                 The card renders its own title/subtitle. -->
            <div class="row">
                <div class="col-lg-6 mb-3">
                    <?= render_finger_hands($conn, $emp_id, ['source' => 'device']) ?>
                </div>
            </div>

        </div>
    </div>
</div>
