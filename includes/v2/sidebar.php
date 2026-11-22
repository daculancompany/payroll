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
// PIN punches awaiting review — same pill, but its own condition (and no
// department scope: the page is Administrator-only).
$__counts['pin-attendance'] = 0;
if (page_allowed('pin-attendance') && isset($conn)) {
    try {
        $__q = $conn->query("SELECT COUNT(*) c FROM kiosk_punches WHERE entry_type = 'pin' AND approval = 'pending'");
        if ($__q) $__counts['pin-attendance'] = (int) ($__q->fetch_assoc()['c'] ?? 0);
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
        <?php foreach ($V2_MENU as $gi => $group):
            $items = array_filter($group['items'], function ($it) use ($login_role) { return page_allowed($it[0], $login_role); });
            if (!$items) continue;
            $flat    = !empty($group['flat']);
            $gkey    = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($group['title'])), '-');
            $gActive = false;
            $gCount  = 0;
            ob_start();
            foreach ($items as $it):
                [$slug, $label, $icon] = $it;
                $href   = !empty($it[3]) ? $it[3] : 'app/' . $slug;
                $active = $page === $slug || in_array($page, $it[4] ?? [], true);
                $gActive = $gActive || $active;
                $gCount += (int) ($__counts[$slug] ?? 0);
                $extra  = !empty($it[3]) ? '' : ' data-nav';
                $match  = implode(' ', array_merge([$slug], $it[4] ?? [])); ?>
                <a href="<?= e($href) ?>" class="v2-nav__item<?= $flat ? '' : ' v2-tree__leaf' ?><?= $active ? ' is-active' : '' ?>"<?= $extra ?> data-match="<?= e($match) ?>" title="<?= e($label) ?>">
                    <i class="<?= e($icon) ?>"></i>
                    <span><?= e($label) ?></span>
                    <?php if (!empty($__counts[$slug])): ?>
                        <em class="v2-nav__count"><?= $__counts[$slug] > 99 ? '99+' : $__counts[$slug] ?></em>
                    <?php endif; ?>
                </a>
            <?php endforeach;
            $rows = ob_get_clean(); ?>
            <?php if ($flat): ?>
                <div class="v2-nav__group v2-nav__group--flat" data-title="<?= e($group['title']) ?>"><?= $rows ?></div>
            <?php else: ?>
                <div class="v2-nav__group v2-tree<?= $gActive ? ' is-open' : '' ?>" data-tree="<?= e($gkey) ?>" data-title="<?= e($group['title']) ?>">
                    <button type="button" class="v2-nav__item v2-tree__head" data-v2-tree aria-expanded="<?= $gActive ? 'true' : 'false' ?>" aria-controls="v2-tree-<?= e($gkey) ?>" title="<?= e($group['title']) ?>">
                        <i class="<?= e($group['icon'] ?? 'ri-folder-line') ?>"></i>
                        <span><?= e($group['title']) ?></span>
                        <?php if ($gCount): ?><b class="v2-tree__dot" title="<?= $gCount ?> pending"></b><?php endif; ?>
                        <i class="ri-arrow-down-s-line v2-tree__chev" aria-hidden="true"></i>
                    </button>
                    <div class="v2-tree__body" id="v2-tree-<?= e($gkey) ?>">
                        <div class="v2-tree__inner"><?= $rows ?></div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <script>
    /* Re-open the groups this viewer left open, before first paint (no flash). */
    (function () {
        try {
            var open = JSON.parse(localStorage.getItem('pr_nav_open') || '[]');
            open.forEach(function (k) {
                var g = document.querySelector('.v2-tree[data-tree="' + k + '"]');
                if (g) { g.classList.add('is-open'); g.querySelector('[data-v2-tree]').setAttribute('aria-expanded', 'true'); }
            });
        } catch (e) {}
    })();
    </script>

    <div class="v2-sidebar__foot">
        <button type="button" class="v2-nav__item" data-v2-settings title="Appearance">
            <i class="ri-palette-line"></i><span>Appearance</span>
        </button>

        <div class="dropup v2-side-user">
            <button type="button" class="v2-side-user__btn" data-bs-toggle="dropdown" data-bs-offset="0,8" aria-expanded="false" title="<?= e($user_name) ?>">
                <span class="v2-avatar"><?= e(v2_initials($user_name)) ?></span>
                <span class="v2-side-user__copy">
                    <strong><?= e($user_name) ?></strong>
                    <small><?= e(getRole($login_role)) ?></small>
                </span>
                <i class="ri-more-2-fill v2-side-user__more" aria-hidden="true"></i>
            </button>
            <div class="dropdown-menu v2-pop">
                <div class="v2-pop__head v2-pop__head--user">
                    <span class="v2-avatar v2-avatar--lg"><?= e(v2_initials($user_name)) ?></span>
                    <div><strong><?= e($user_name) ?></strong><small><?= e(getRole($login_role)) ?></small></div>
                </div>
                <a class="dropdown-item" href="app/profile" data-nav><i class="ri-user-3-line"></i> My profile</a>
                <a class="dropdown-item" href="#" data-pwa-install data-pwa-display="flex" style="display:none;"><i class="ri-smartphone-line"></i> Install app</a>
                <button type="button" class="dropdown-item" data-v2-classic><i class="ri-history-line"></i> Switch to classic UI</button>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item text-danger" href="ajax.php?action=logout"><i class="ri-logout-box-r-line"></i> Sign out</a>
            </div>
        </div>
    </div>
</aside>
