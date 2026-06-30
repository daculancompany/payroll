<?php
// Active Loans Dashboard — included via index.php
$loan_stats = $conn->query("
    SELECT
        COUNT(DISTINCT l.employee_id)          AS borrowers,
        COALESCE(SUM(l.loan_balance), 0)       AS total_balance,
        COALESCE(SUM(l.loan_amount), 0)        AS total_loaned,
        COALESCE(SUM(l.damount), 0)            AS total_monthly
    FROM loans l
    WHERE l.loan_status = 0 AND l.loan_balance > 0
")->fetch_assoc();

$loans_res = $conn->query("
    SELECT
        l.*,
        CONCAT(e.lastname, ', ', e.firstname) AS emp_name,
        e.employee_no,
        COALESCE(d.name, '—')                 AS dept_name,
        COALESCE(p.name, '—')                 AS position_name,
        COALESCE(clt.loan_type, '—')          AS loan_type_name,
        (l.loan_amount - l.loan_balance)       AS amount_paid,
        ROUND((l.loan_amount - l.loan_balance) / NULLIF(l.loan_amount,0) * 100, 1) AS pct_paid
    FROM loans l
    INNER JOIN employee e ON l.employee_id = e.id
    LEFT JOIN department d ON e.department_id = d.id
    LEFT JOIN position   p ON e.position_id  = p.id
    LEFT JOIN contribution_loan_types clt ON l.loan_type = clt.clt_id
    WHERE l.loan_status = 0 AND l.loan_balance > 0
    ORDER BY l.loan_balance DESC
");
?>

<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            <!-- Title -->
            <div class="row mb-3">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0"><i class="ri-bank-line me-2" style="color:#219688;"></i>Active Loans</h4>
                        <ol class="breadcrumb m-0">
                            <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                            <li class="breadcrumb-item active">Active Loans</li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- Stat cards -->
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <div class="card" style="border-top:3px solid #219688;">
                        <div class="card-body d-flex align-items-center gap-3 py-3">
                            <div style="width:46px;height:46px;border-radius:10px;background:#e8f7f5;display:flex;align-items:center;justify-content:center;font-size:22px;"><i class="ri-group-line" style="color:#219688;"></i></div>
                            <div>
                                <div style="font-size:22px;font-weight:800;color:#219688;"><?= number_format($loan_stats['borrowers']) ?></div>
                                <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.4px;">Borrowers</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card" style="border-top:3px solid #e83e8c;">
                        <div class="card-body d-flex align-items-center gap-3 py-3">
                            <div style="width:46px;height:46px;border-radius:10px;background:#fdf0f6;display:flex;align-items:center;justify-content:center;font-size:22px;"><i class="ri-money-dollar-circle-line" style="color:#e83e8c;"></i></div>
                            <div>
                                <div style="font-size:18px;font-weight:800;color:#e83e8c;">₱<?= number_format($loan_stats['total_balance'], 2) ?></div>
                                <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.4px;">Total Outstanding</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card" style="border-top:3px solid #6f42c1;">
                        <div class="card-body d-flex align-items-center gap-3 py-3">
                            <div style="width:46px;height:46px;border-radius:10px;background:#f2eefb;display:flex;align-items:center;justify-content:center;font-size:22px;"><i class="ri-wallet-3-line" style="color:#6f42c1;"></i></div>
                            <div>
                                <div style="font-size:18px;font-weight:800;color:#6f42c1;">₱<?= number_format($loan_stats['total_loaned'], 2) ?></div>
                                <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.4px;">Total Loaned</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card" style="border-top:3px solid #fd7e14;">
                        <div class="card-body d-flex align-items-center gap-3 py-3">
                            <div style="width:46px;height:46px;border-radius:10px;background:#fff4ec;display:flex;align-items:center;justify-content:center;font-size:22px;"><i class="ri-calendar-line" style="color:#fd7e14;"></i></div>
                            <div>
                                <div style="font-size:18px;font-weight:800;color:#fd7e14;">₱<?= number_format($loan_stats['total_monthly'], 2) ?></div>
                                <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.4px;">Deducted / Period</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="card">
                <div class="card-header d-flex align-items-center py-2">
                    <h6 class="card-title mb-0 flex-grow-1"><i class="ri-list-check me-2" style="color:#219688;"></i>Loan Records</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle mb-0" id="loans-table">
                            <thead>
                                <tr>
                                    <th style="background:#219688;color:#fff;padding:9px 12px;font-size:11px;border:none;">Employee</th>
                                    <th style="background:#219688;color:#fff;padding:9px 12px;font-size:11px;border:none;">Department</th>
                                    <th style="background:#219688;color:#fff;padding:9px 12px;font-size:11px;border:none;">Loan Type</th>
                                    <th style="background:#219688;color:#fff;padding:9px 12px;font-size:11px;border:none;text-align:right;">Loan Amount</th>
                                    <th style="background:#219688;color:#fff;padding:9px 12px;font-size:11px;border:none;text-align:right;">Paid</th>
                                    <th style="background:#219688;color:#fff;padding:9px 12px;font-size:11px;border:none;text-align:right;">Balance</th>
                                    <th style="background:#219688;color:#fff;padding:9px 12px;font-size:11px;border:none;text-align:right;">Per Period</th>
                                    <th style="background:#219688;color:#fff;padding:9px 12px;font-size:11px;border:none;">Progress</th>
                                    <th style="background:#219688;color:#fff;padding:9px 12px;font-size:11px;border:none;">Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($l = $loans_res->fetch_assoc()): $pct = max(0, min(100, (float)$l['pct_paid'])); ?>
                                    <tr>
                                        <td style="padding:8px 12px;">
                                            <div style="font-weight:700;font-size:12px;"><?= htmlspecialchars($l['emp_name']) ?></div>
                                            <div style="font-size:10px;color:#aaa;font-family:monospace;"><?= htmlspecialchars($l['employee_no']) ?></div>
                                        </td>
                                        <td style="padding:8px 12px;font-size:12px;"><?= htmlspecialchars($l['dept_name']) ?></td>
                                        <td style="padding:8px 12px;">
                                            <span style="background:#e8f7f5;color:#176358;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700;"><?= htmlspecialchars($l['loan_type_name']) ?></span>
                                        </td>
                                        <td style="padding:8px 12px;text-align:right;font-size:12px;">₱<?= number_format($l['loan_amount'], 2) ?></td>
                                        <td style="padding:8px 12px;text-align:right;font-size:12px;color:#28a745;">₱<?= number_format($l['amount_paid'], 2) ?></td>
                                        <td style="padding:8px 12px;text-align:right;font-size:12px;font-weight:800;color:#e83e8c;">₱<?= number_format($l['loan_balance'], 2) ?></td>
                                        <td style="padding:8px 12px;text-align:right;font-size:12px;">₱<?= number_format($l['damount'], 2) ?></td>
                                        <td style="padding:8px 12px;min-width:120px;">
                                            <div style="display:flex;align-items:center;gap:6px;">
                                                <div style="flex:1;height:6px;border-radius:3px;background:#eee;overflow:hidden;">
                                                    <div style="width:<?= $pct ?>%;height:100%;border-radius:3px;background:linear-gradient(90deg,#219688,#176358);"></div>
                                                </div>
                                                <span style="font-size:10px;color:#888;white-space:nowrap;"><?= $pct ?>%</span>
                                            </div>
                                        </td>
                                        <td style="padding:8px 12px;font-size:11px;color:#888;"><?= date('M d, Y', strtotime($l['loan_date'])) ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
    // DataTable: search / sort / paging (Progress column not sortable)
    document.addEventListener('DOMContentLoaded', function() {
        if (window.jQuery && jQuery.fn.DataTable && !jQuery.fn.DataTable.isDataTable('#loans-table')) {
            jQuery('#loans-table').DataTable({
                order: [
                    [0, 'asc']
                ],
                pageLength: 10,
                columnDefs: [{
                    orderable: false,
                    targets: 7
                }],
                language: {
                    search: '',
                    searchPlaceholder: 'Search employee…'
                }
            });
        }
    });
</script>