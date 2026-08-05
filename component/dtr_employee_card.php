<?php
/**
 * One "By Employee" card for the DTR details screen.
 *
 * Shared by dtr-details.php (first page, rendered inline) and
 * dtr-employee-server.php (every subsequent page / search result) so the two
 * can never drift apart.
 *
 * @param int   $empId      employee.id
 * @param array $empGroup   ['employee_info' => row, 'dates' => [ 'Y-m-d' => [entry rows] ]]
 * @param array $empTotals  ['work_hours'=>, 'overtime'=>, 'undertime'=>, 'late'=>]
 * @param int   $login_role role of the viewer; 6 = read-only, no action buttons
 */
function render_dtr_employee_card($empId, array $empGroup, array $empTotals, $login_role)
{
    $info        = $empGroup['employee_info'];
    $empInitials = strtoupper(substr($info['firstname'], 0, 1) . substr($info['lastname'], 0, 1));
    $totalDays   = count($empGroup['dates']);
    $cardId      = 'emp-card-' . $empId;
    $empName     = $info['lastname'] . ', ' . $info['firstname'];

    // Per-employee approval summary (DTR_details.status: 0=pending, 1=approved, 2=disapproved)
    $empAppr = $empPend = $empDisa = 0;
    foreach ($empGroup['dates'] as $dEntries) {
        foreach ($dEntries as $e) {
            $s = (int)$e['status'];
            if ($s === 1)      $empAppr++;
            elseif ($s === 2)  $empDisa++;
            else               $empPend++;
        }
    }

    // Lower-cased haystack for the client-side filter of already-loaded cards
    $searchHay = strtolower(trim(
        $info['lastname'] . ' ' . $info['firstname'] . ' ' . ($info['middlename'] ?? '') . ' ' .
        $info['employee_no'] . ' ' . ($info['position'] ?? '') . ' ' . ($info['department'] ?? '')
    ));
?>
<div class="ecard" data-emp-id="<?= (int)$empId ?>" data-emp-search="<?= htmlspecialchars($searchHay, ENT_QUOTES) ?>">
    <div class="ecard-header" onclick="toggleEmpCard('<?= $cardId ?>')">
        <div class="ecard-left">
            <div class="dtr-emp-init"><?= $empInitials ?></div>
            <div>
                <div class="ecard-name" data-emp-quickview="<?= (int)$empId ?>" title="View employee details"><?= htmlspecialchars($info['lastname'] . ', ' . $info['firstname'] . ' ' . ($info['middlename'] ?? '')) ?></div>
                <div class="ecard-meta">
                    <span class="dtr-pos-chip"><?= htmlspecialchars($info['position']) ?></span>
                    <span class="ecard-days"><i class="ri-calendar-line"></i><?= $totalDays ?> day<?= $totalDays != 1 ? 's' : '' ?></span>
                    <span class="ecard-sum-chip appr" title="Approved records"><i class="ri-checkbox-circle-line"></i><span class="emp-sum-appr"><?= $empAppr ?></span></span>
                    <span class="ecard-sum-chip pend" title="Pending records"><i class="ri-time-line"></i><span class="emp-sum-pend"><?= $empPend ?></span></span>
                    <span class="ecard-sum-chip disa" title="Disapproved records"><i class="ri-close-circle-line"></i><span class="emp-sum-disa"><?= $empDisa ?></span></span>
                </div>
            </div>
        </div>
        <div class="ecard-right">
            <div class="dtr-emp-totals">
                <div class="dtr-tot-item"><span class="tot-lbl">Hrs</span><span class="tot-val"><?= number_format($empTotals['work_hours'], 2) ?></span></div>
                <div class="dtr-tot-item ot"><span class="tot-lbl">OT</span><span class="tot-val"><?= number_format($empTotals['overtime'], 2) ?></span></div>
                <div class="dtr-tot-item ut"><span class="tot-lbl">UT</span><span class="tot-val"><?= number_format($empTotals['undertime'], 2) ?></span></div>
                <div class="dtr-tot-item late"><span class="tot-lbl">Late</span><span class="tot-val"><?= number_format($empTotals['late'], 2) ?></span></div>
            </div>
            <?php if ($login_role !== 6): ?>
            <button type="button" class="xl-btn xl-btn-save ecard-approve-all" style="<?= $empPend > 0 ? '' : 'display:none;' ?>"
                onclick="event.stopPropagation(); approveEmployee(this, '<?= htmlspecialchars($empName, ENT_QUOTES) ?>');"
                title="Approve all pending records of this employee">
                <i class="ri-checkbox-circle-line"></i> Approve All (<span class="emp-appr-count"><?= $empPend ?></span>)
            </button>
            <?php endif; ?>
            <i class="ri-arrow-down-s-line ecard-chevron"></i>
        </div>
    </div>

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
                    <span style="color:#6642aa;font-weight:600;"><?= number_format($dayWH, 2) ?> hrs</span>
                    <?php if ($dayOT > 0): ?><span style="color:#c98a00;">OT <?= number_format($dayOT, 2) ?></span><?php endif; ?>
                    <?php if ($dayLate > 0): ?><span style="color:#c62828;">Late <?= number_format($dayLate, 2) ?></span><?php endif; ?>
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
                $pl = '';
                foreach ($logs2 as $li => $lg) {
                    $iB   = $lg->type === 'bio';
                    $lbl3 = ($li === 0) ? 'IN' : (($li === count($logs2) - 1) ? 'OUT' : '#' . ($li + 1));
                    $pl  .= '<div style="display:flex;align-items:center;gap:6px;padding:3px 0;"><span style="font-size:10px;font-weight:700;color:#888;min-width:26px;">' . $lbl3 . '</span><span class="dtr-log-chip ' . ($iB ? 'bio' : 'manual') . '"><i class="' . ($iB ? 'ri-fingerprint-line' : 'ri-edit-line') . '"></i>' . date("g:i A", strtotime($lg->dateTime)) . '</span></div>';
                }
                if (!$pl) $pl = '<span style="color:#aaa;font-size:11px;">No logs</span>';
                $pc = htmlspecialchars('<div style="min-width:150px;">' . $pl . '</div>');
                $recStatus2 = (int)$row['status'];
            ?>
            <div class="ecard-entry <?= $recStatus2 === 1 ? 'is-approved' : ($recStatus2 === 2 ? 'is-disapproved' : '') ?>"
                data-rec-id="<?= (int)$row['id'] ?>" data-rec-status="<?= $recStatus2 ?>" data-rec-date="<?= $date ?>">
                <div class="ecard-times">
                    <?php if ($login_role !== 6): ?>
                    <input type="checkbox" class="form-check-input rec-check" value="<?= (int)$row['id'] ?>" title="Select record" style="margin-right:2px;">
                    <?php endif; ?>
                    <span class="dtr-time-chip in"><?= $tIn ?: '—' ?></span>
                    <span style="color:#ccc;font-size:10px;">→</span>
                    <span class="dtr-time-chip <?= $tOut ? 'out' : 'na' ?>"><?= $tOut ?: '—' ?></span>
                    <?php if ($logCount > 0): ?>
                    <span class="dtr-logs-pill"
                        data-dtr-pop="1" data-bs-trigger="click"
                        data-bs-placement="top" data-bs-html="true"
                        data-bs-content="<?= $pc ?>" title="Logs" style="cursor:pointer;margin-left:4px;">
                        <span class="dtr-logs-count"><?= $logCount ?> log<?= $logCount > 1 ? 's' : '' ?></span>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="ecard-entry-stats">
                    <span class="ecard-stat"><span class="ecard-stat-lbl">Hrs</span><span class="ecard-stat-val"><?= $row['work_hours'] ?></span></span>
                    <span class="ecard-stat ot"><span class="ecard-stat-lbl">OT</span><span class="ecard-stat-val"><?= $row['overtime'] ?></span></span>
                    <span class="ecard-stat ut"><span class="ecard-stat-lbl">UT</span><span class="ecard-stat-val"><?= $row['undertime'] ?></span></span>
                    <span class="ecard-stat late"><span class="ecard-stat-lbl">Late</span><span class="ecard-stat-val"><?= $row['late'] ?></span></span>
                </div>
                <div class="ecard-entry-status">
                    <span data-rec-badge="<?= (int)$row['id'] ?>">
                        <?php if ($recStatus2 === 1): ?>
                            <span class="badge bg-success" style="font-size:10px;"><i class="ri-checkbox-circle-line me-1"></i>Approved</span>
                        <?php elseif ($recStatus2 === 2): ?>
                            <span class="badge bg-danger" style="font-size:10px;"><i class="ri-close-circle-line me-1"></i>Disapproved</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark" style="font-size:10px;"><i class="ri-time-line me-1"></i>Pending</span>
                        <?php endif; ?>
                    </span>
                    <?php if ($login_role !== 6): ?>
                    <div class="btn-group btn-group-sm">
                        <button title="Approve" onclick="decideRecord(<?= (int)$row['id'] ?>, 1)" class="btn btn-outline-success btn-rec-approve" <?= $recStatus2 === 1 ? 'disabled' : '' ?>><i class="ri-check-line"></i></button>
                        <button title="Disapprove" onclick="decideRecord(<?= (int)$row['id'] ?>, 2)" class="btn btn-outline-danger btn-rec-disapprove" <?= $recStatus2 === 2 ? 'disabled' : '' ?>><i class="ri-close-line"></i></button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php
}
