<?php
/* v2 sidebar. Items come from includes/v2/menu.php, filtered by page_allowed(). */
$__counts = ['leaves' => 0, 'attendance-requests' => 0];
// Same pending counts (and department scoping) as the classic navbar.
foreach (['leaves' => 'leave_requests', 'attendance-requests' => 'attendance_requests'] as $__slug => $__tbl) {
    if (!page_allowed($__slug) || !isset($conn)) continue;
    try {
        require_once __DIR__ . '/../../dept-scope.php';
        $__q = $conn->query("SELECT COUNT(*) c FROM $__tbl WHERE status = 0 " . dept_scope_emp_sql('employee_id'));
        if ($__q) $__counts[$__slug] = (int) ($__q->fetch_assoc()['c'] ?? 0);
    } catch (Throwable $e) { /* never break the sidebar over a badge */ }
}
?>
<aside class="v2-sidebar" id="v2-sidebar" aria-label="Main navigation">
    <div class="v2-brand">
        <a href="app/<?= e(role_landing_page($login_role)) ?>" class="v2-brand__link" data-nav>
            <span class="v2-brand__mark"><img src="assets/images/favicon-192.png?v=2" alt=""></span>
            <span class="v2-brand__text">
                <strong><?= e($V2_BRAND) ?></strong>
                <small><?= e($V2_TAGLINE) ?></small>
            </span>
        </a>
        <button type="button" class="v2-icon-btn v2-sidebar__collapse d-none d-lg-inline-flex" data-v2-collapse title="Collapse sidebar" aria-label="Collapse sidebar">
            <i class="ri-side-bar-line"></i>
        </button>
    </div>

    <nav class="v2-nav">
        <?php foreach ($V2_MENU as $group):
            $items = array_filter($group['items'], function ($it) use ($login_role) { return page_allowed($it[0], $login_role); });
            if (!$items) continue; ?>
            <div class="v2-nav__group">
                <div class="v2-nav__title"><?= e($group['title']) ?></div>
                <?php foreach ($items as $it):
                    [$slug, $label, $icon] = $it;
                    $href   = !empty($it[3]) ? $it[3] : 'app/' . $slug;
                    $active = $page === $slug || in_array($page, $it[4] ?? [], true);
                    $extra  = !empty($it[3]) ? '' : ' data-nav';
                    $match  = implode(' ', array_merge([$slug], $it[4] ?? [])); ?>
                    <a href="<?= e($href) ?>" class="v2-nav__item<?= $active ? ' is-active' : '' ?>"<?= $extra ?> data-match="<?= e($match) ?>" title="<?= e($label) ?>">
                        <i class="<?= e($icon) ?>"></i>
                        <span><?= e($label) ?></span>
                        <?php if (!empty($__counts[$slug])): ?>
                            <em class="v2-nav__count"><?= $__counts[$slug] > 99 ? '99+' : $__counts[$slug] ?></em>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>

    <div class="v2-sidebar__foot">
        <button type="button" class="v2-nav__item" data-v2-settings>
            <i class="ri-palette-line"></i><span>Appearance</span>
        </button>
    </div>
</aside>
