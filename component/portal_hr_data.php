<?php
/**
 * The employee's HR data for the portal, loaded once near the top of
 * employee-portal.php so the tab strip, the More sheet and "Needs Your Action"
 * can show counts before the HR tab itself renders
 * (component/portal_hr_modules.php).
 *
 * Expects: $conn, $emp_id (from the portal session — never the request).
 * Every list is empty when the HR tables are not on this server yet, so the
 * portal looks exactly as it did before.
 */

require_once __DIR__ . '/../hr_class.php';

$phm      = new HrManagement($conn);
$phm_has  = $phm->tableExists('hr_announcements');
$phm_ann  = $phm_has ? $phm->announcementsForEmployee($emp_id) : [];
$phm_memo = $phm_has ? $phm->memos($emp_id) : [];
$phm_docs = $phm_has ? $phm->documents($emp_id, true) : [];
$phm_req  = $phm_has ? $phm->docRequestsForEmployee($emp_id) : [];
$phm_trn  = $phm_has ? $phm->trainings($emp_id) : [];
$phm_ast  = $phm_has ? $phm->assets($emp_id) : [];

$phm_unread = count(array_filter($phm_ann, function ($a) { return !(int) $a['is_read']; }));
$phm_unack  = count(array_filter($phm_memo, function ($m) {
    return (int) $m['requires_ack'] === 1 && !$m['acknowledged_at'] && $m['status'] === 'open';
}));
$phm_badge  = $phm_unread + $phm_unack;
