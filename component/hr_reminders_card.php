<?php
/**
 * Dashboard strip: the HR reminders that need action, one chip per kind, each
 * linking to HR Overview. Shown to Admin + HR only (home.php checks
 * page_allowed('hr-overview')). Says nothing when there is nothing to do —
 * birthdays and anniversaries alone do not earn a strip on the dashboard.
 */
require_once __DIR__ . '/../includes/hr/insights.php';

$hrc_groups = array_filter(hr_alerts($conn, 30), function ($g) { return $g['items'] && $g['tone'] !== 'good'; });
$hrc_total  = hr_alert_action_count($hrc_groups);
?>
<?php if ($hrc_total > 0): ?>
<style>
.hrc{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--v2-surface,#fff);border:1px solid var(--v2-border,#ece9f3);border-left:3px solid #e6a817;border-radius:10px;padding:9px 14px;margin-bottom:14px;}
.hrc-t{font-weight:800;font-size:13px;color:var(--v2-text,#2b2639);white-space:nowrap;}
.hrc a.chip{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px;text-decoration:none;background:var(--v2-surface-2,#f6f4fb);color:var(--v2-text-2,#5b5670);border:1px solid var(--v2-border,#ebe7f3);}
.hrc a.chip:hover{color:var(--app-primary,#673bb6);}
.hrc a.chip b{color:var(--v2-text,#2b2639);}
.hrc .more{margin-left:auto;font-size:12px;font-weight:700;white-space:nowrap;}
</style>
<div class="hrc">
    <span class="hrc-t"><i class="ri-user-heart-line me-1" style="color:#b07800;"></i>HR reminders</span>
    <?php foreach ($hrc_groups as $g): ?>
        <a class="chip" href="index.php?page=hr-overview&amp;days=30" title="<?= htmlspecialchars($g['title']) ?>"><i class="<?= htmlspecialchars($g['icon']) ?>"></i><b><?= count($g['items']) ?></b> <?= htmlspecialchars($g['title']) ?></a>
    <?php endforeach; ?>
    <a class="more" href="index.php?page=hr-overview">Open HR Overview <i class="ri-arrow-right-s-line"></i></a>
</div>
<?php endif; ?>
