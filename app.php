<?php
/**
 * v2 shell: new layout, theme engine, SPA-style navigation.
 *
 *   /app/<page>            full page (first load, refresh, deep link)
 *   same URL + X-App-Nav   JSON {html, scripts, title, page} for in-place
 *                          navigation (assets3/js/app-router.js)
 *
 * The routed page fragments are the same files the classic index.php
 * includes, behind the same page_allowed() gate, so the two shells never
 * disagree on who may see what. The partial endpoint applies that gate
 * itself: authorization never depends on the client.
 */
require __DIR__ . '/includes/v2/bootstrap.php';

$APP_V2   = true;
$V2_BASE  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
$V2_BRAND = getenv('APP_BRAND') ?: 'Payroll';
$V2_MENU  = require __DIR__ . '/includes/v2/menu.php';
$isPartial = !empty($_SERVER['HTTP_X_APP_NAV']);

// Standalone full-viewport pages keep their own documents.
$standalone = ['payroll_calculations' => 'payroll_calculations.php?id=' . (int) ($_GET['id'] ?? 0),
               'duty-roster' => 'duty-roster.php'];
$page = isset($_GET['page']) ? trim((string) $_GET['page']) : '';
if ($page === 'payroll-list') $page = 'payroll';
if (isset($standalone[$page])) {
    if ($isPartial) { header('Content-Type: application/json'); echo json_encode(['full' => $standalone[$page]]); exit; }
    header('Location: ' . $V2_BASE . $standalone[$page]);
    exit;
}

if ($page === '' || !array_key_exists($page, $V2_ROUTES)) $page = role_landing_page($login_role);
if (!page_allowed($page, $login_role)) $page = role_landing_page($login_role);
$denied = !page_allowed($page, $login_role);
$_GET['page'] = $page;   // fragments + page_scripts.php read it

/** Menu label for a slug, falling back to a prettified slug. */
function v2_title($slug, $menu)
{
    foreach ($menu as $g) foreach ($g['items'] as $it) {
        if ($it[0] === $slug || in_array($slug, $it[4] ?? [], true)) return $it[1];
    }
    $map = ['employee-details' => 'Employee', 'dtr-details' => 'DTR Details', 'payroll_items' => 'Payroll Items', 'profile' => 'My Profile'];
    return $map[$slug] ?? ucwords(str_replace(['-', '_'], ' ', $slug));
}
$page_title = v2_title($page, $V2_MENU);
$page_group = v2_group($page, $V2_MENU);

/** Sidebar group a slug belongs to (topbar breadcrumb). */
function v2_group($slug, $menu)
{
    foreach ($menu as $g) foreach ($g['items'] as $it) {
        if ($it[0] === $slug || in_array($slug, $it[4] ?? [], true)) return $g['title'];
    }
    return '';
}

/** Run the routed fragment and return its HTML. */
function v2_render_fragment($page, $denied, $routes)
{
    // Fragments run in this scope and expect the classic globals.
    global $conn, $login_role, $allowed_values, $allowed_values_2, $clasification_array;
    $tk = is_timekeeper($login_role);
    ob_start();
    if ($denied) {
        echo '<div class="main-content"><div class="page-content"><div class="container-fluid">'
           . '<div class="v2-empty"><i class="ri-lock-2-line"></i><h5>No access</h5>'
           . '<p>Your role does not have access to this screen.</p></div></div></div></div>';
    } elseif ($tk && $page === 'employee-details') {
        include __DIR__ . '/employee-details-timekeeper.php';
    } else {
        include __DIR__ . '/' . $routes[$page] . '.php';
    }
    return v2_defer_scripts(ob_get_clean());
}

/**
 * Hand the fragment's own <script> tags to the router (app-router.js) so they
 * run after the shared libraries, through the same re-runnable lifecycle on a
 * first load as on an in-place navigation. Non-JS types (templates, JSON,
 * modules) are left alone.
 */
function v2_defer_scripts($html)
{
    return preg_replace_callback('/<script\b([^>]*)>/i', function ($m) {
        $attrs = $m[1];
        if (preg_match('/\btype\s*=\s*["\']?([^"\'\s>]+)/i', $attrs, $t)) {
            if (!in_array(strtolower($t[1]), ['text/javascript', 'application/javascript'], true)) return $m[0];
            $attrs = preg_replace('/\btype\s*=\s*["\']?[^"\'\s>]+["\']?/i', '', $attrs);
        }
        return '<script type="text/v2-deferred"' . $attrs . '>';
    }, $html);
}

/** Script URLs the classic per-page chain would load for $page. */
function v2_page_scripts($page)
{
    ob_start();
    include __DIR__ . '/includes/page_scripts.php';
    $html = ob_get_clean();
    preg_match_all('/<script[^>]*\bsrc="([^"]+)"/i', $html, $m);
    return $m[1];
}

if ($isPartial) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if (!in_array($page, $V2_SPA_SAFE, true)) {
        // Not yet checked for in-place navigation: let the browser do a real load.
        echo json_encode(['full' => 'app/' . $page . v2_query_suffix()]);
        exit;
    }
    $html = v2_render_fragment($page, $denied, $V2_ROUTES);
    echo json_encode([
        'page'    => $page,
        'title'   => $page_title,
        'group'   => $page_group,
        'html'    => $html,
        'scripts' => v2_page_scripts($page),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

/** Current query string minus `page`, for rebuilding a clean URL. */
function v2_query_suffix()
{
    $q = $_GET;
    unset($q['page']);
    return $q ? '?' . http_build_query($q) : '';
}

$user_name = (string) ($_SESSION['login_name'] ?? 'User');
$fragment  = v2_render_fragment($page, $denied, $V2_ROUTES);

include __DIR__ . '/includes/v2/head.php';
?>
<body class="v2">
<div class="v2-bg" aria-hidden="true"></div>
<div class="v2-progress" id="v2-progress" aria-hidden="true"></div>

<div class="v2-app">
    <?php include __DIR__ . '/includes/v2/sidebar.php'; ?>
    <div class="v2-sidebar-scrim" data-v2-sidebar-close></div>

    <div class="v2-main">
        <?php include __DIR__ . '/includes/v2/topbar.php'; ?>
        <main id="main-content" class="v2-content" data-page="<?= e($page) ?>" tabindex="-1">
            <?= $fragment ?>
        </main>
        <!-- Right after the content, before the heavy vendor scripts: adapts
             page colours to the theme before the first paint. -->
        <script src="<?= av('assets3/js/theme-adapter.js') ?>"></script>
    </div>
</div>

<?php
include __DIR__ . '/component/employee_quick_view.php';
include __DIR__ . '/includes/v2/settings_modal.php';
include __DIR__ . '/includes/app_scripts.php';
?>
<script>
    window.V2 = {
        base: <?= json_encode($V2_BASE) ?>,
        page: <?= json_encode($page) ?>,
        brand: <?= json_encode($V2_BRAND) ?>,
        routes: <?= json_encode(array_keys($V2_ROUTES)) ?>,
        spaSafe: <?= json_encode($V2_SPA_SAFE) ?>,
        landing: <?= json_encode(role_landing_page($login_role)) ?>,
        initialScripts: <?= json_encode(v2_page_scripts($page), JSON_UNESCAPED_SLASHES) ?>
    };
</script>
<script src="<?= av('assets3/js/settings-modal.js') ?>"></script>
<script src="<?= av('assets3/js/app-shell.js') ?>"></script>
<script src="<?= av('assets3/js/app-router.js') ?>"></script>
</body>
</html>
