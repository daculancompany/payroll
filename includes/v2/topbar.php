<?php
/* v2 topbar. The #notif-* ids are the ones the shared notifications poller
 * (includes/app_scripts.php) looks for, so it drives this dropdown as is. */
?>
<header class="v2-topbar">
    <button type="button" class="v2-icon-btn d-lg-none" data-v2-sidebar-open aria-label="Open menu">
        <i class="ri-menu-2-line"></i>
    </button>

    <div class="v2-topbar__title">
        <span class="v2-topbar__crumb" id="v2-crumb"><?php if ($page_group): ?><small class="d-none d-md-inline"><?= e($page_group) ?></small><?php endif; ?><?= e($page_title) ?></span>
    </div>

    <button type="button" class="v2-search" data-v2-palette aria-label="Search pages">
        <i class="ri-search-line"></i>
        <span>Search pages…</span>
        <kbd>⌘K</kbd>
    </button>

    <div class="v2-topbar__actions">
        <button type="button" class="v2-icon-btn" data-v2-mode-toggle title="Toggle light / dark" aria-label="Toggle light or dark mode">
            <i class="ri-moon-clear-line v2-when-light"></i>
            <i class="ri-sun-line v2-when-dark"></i>
        </button>

        <div class="dropdown" id="notificationDropdown">
            <button type="button" class="v2-icon-btn position-relative" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications">
                <i class="ri-notification-3-line"></i>
                <span class="v2-dot-badge" id="notif-badge" style="display:none;">0</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end v2-pop v2-notif p-0">
                <div class="v2-pop__head">
                    <strong>Notifications</strong>
                    <span class="badge rounded-pill text-bg-light" id="notif-unread-label">0 New</span>
                </div>
                <div id="notif-list" class="v2-notif__list"></div>
                <div class="v2-pop__foot">
                    <button type="button" class="btn btn-sm btn-light w-100" id="notif-mark-all">
                        <i class="ri-check-double-line me-1"></i> Mark all as read
                    </button>
                </div>
            </div>
        </div>

        <button type="button" class="v2-icon-btn d-none d-sm-inline-flex" data-v2-settings title="Appearance settings" aria-label="Appearance settings">
            <i class="ri-settings-3-line"></i>
        </button>

        <div class="dropdown">
            <button type="button" class="v2-user" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="v2-avatar"><?= e(v2_initials($user_name)) ?></span>
                <span class="v2-user__copy d-none d-md-flex">
                    <strong><?= e($user_name) ?></strong>
                    <small><?= e(getRole($login_role)) ?></small>
                </span>
                <i class="ri-arrow-down-s-line d-none d-md-inline"></i>
            </button>
            <div class="dropdown-menu dropdown-menu-end v2-pop">
                <div class="v2-pop__head v2-pop__head--user">
                    <span class="v2-avatar v2-avatar--lg"><?= e(v2_initials($user_name)) ?></span>
                    <div><strong><?= e($user_name) ?></strong><small><?= e(getRole($login_role)) ?></small></div>
                </div>
                <a class="dropdown-item" href="app/profile" data-nav><i class="ri-user-3-line"></i> My profile</a>
                <button type="button" class="dropdown-item" data-v2-settings><i class="ri-palette-line"></i> Appearance</button>
                <a class="dropdown-item" href="#" data-pwa-install data-pwa-display="flex" style="display:none;"><i class="ri-smartphone-line"></i> Install app</a>
                <button type="button" class="dropdown-item" data-v2-classic><i class="ri-history-line"></i> Switch to classic UI</button>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item text-danger" href="ajax.php?action=logout"><i class="ri-logout-box-r-line"></i> Sign out</a>
            </div>
        </div>
    </div>
</header>

<!-- Command palette: jump to any page you can open (⌘K / Ctrl+K). -->
<div class="v2-palette" id="v2-palette" hidden>
    <div class="v2-palette__scrim" data-v2-palette-close></div>
    <div class="v2-palette__box" role="dialog" aria-modal="true" aria-label="Search pages">
        <div class="v2-palette__input">
            <i class="ri-search-line"></i>
            <input type="text" id="v2-palette-input" placeholder="Jump to a page…" autocomplete="off" spellcheck="false">
            <kbd>Esc</kbd>
        </div>
        <div class="v2-palette__list" id="v2-palette-list" role="listbox"></div>
    </div>
</div>
