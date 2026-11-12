<?php
/**
 * v2 shell bootstrap: auth gate, route table, role helpers, the user's saved
 * appearance settings. Included first by app.php.
 *
 * The routing and permission rules are the classic ones: page_allowed() and
 * role_landing_page() in db_connect.php, so the v2 shell can never show a page
 * the classic shell would refuse.
 */
include_once __DIR__ . '/../../db_connect.php';
require_once __DIR__ . '/../session_bootstrap.php';

if (!app_is_local()
    && empty($_SESSION['is_login'])
    && !empty($_SESSION['emp_is_login'])) {
    header('Location: employee-portal.php');
    exit;
}
if (empty($_SESSION['is_login'])) {
    if (!empty($_SERVER['HTTP_X_APP_NAV'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['result' => false, 'redirect' => 'login.php']);
        exit;
    }
    header('Location: login.php');
    exit;
}

$login_role = $_SESSION['login_role'];

// Standalone pages (payroll_calculations, dtr-documents, duty-roster) follow
// the shell the user came from: v2 look and theme here, classic otherwise.
$_SESSION['in_v2'] = true;

// Globals some routed fragments still expect from the classic index.php.
$allowed_values   = [1, 2, 3, 4];
$allowed_values_2 = [1, 2, 3];
$clasification_array = ['bg-primary', 'bg-secondary', 'bg-warning', 'bg-danger', 'bg-dark', 'bg-info', 'bg-primary'];

if (!function_exists('getRole')) {
    function getRole($login_role)
    {
        $names = [
            1 => 'Administrator', 2 => 'Staff', 3 => 'Auditor', 4 => 'Payroll Clerk',
            5 => 'Timekeeper', 6 => 'PIC', 7 => 'Auditor', 8 => 'Department Head',
            9 => 'HR', 10 => 'Supervisor', 11 => 'Section/Unit Head',
        ];
        return $names[(int) $login_role] ?? 'Access denied. Unknown role.';
    }
}

/** Route slug => fragment file (without .php). Same table as index.php. */
$V2_ROUTES = require __DIR__ . '/routes.php';

/**
 * Every routed page navigates in place (app-router.js gives each page a
 * re-runnable script lifecycle). Pages listed in $V2_SPA_FULL still do a
 * normal full load inside the v2 shell, for when a page's JS can't survive
 * being loaded, torn down and loaded again.
 */
$V2_SPA_FULL = [];
$V2_SPA_SAFE = array_values(array_diff(array_keys($V2_ROUTES), $V2_SPA_FULL));

/** The signed-in user's saved appearance settings (users.settings JSON). */
if (!isset($_SESSION['ui_settings'])) {
    $_SESSION['ui_settings'] = [];
    $uid = (int) ($_SESSION['login_id'] ?? 0);
    if ($uid > 0 && isset($conn)) {
        $st = $conn->prepare('SELECT settings FROM users WHERE id = ?');
        if ($st) {
            $st->bind_param('i', $uid);
            $st->execute();
            $r = $st->get_result()->fetch_assoc();
            $st->close();
            if ($r && $r['settings']) $_SESSION['ui_settings'] = json_decode($r['settings'], true) ?: [];
        }
    }
}
$V2_SETTINGS = $_SESSION['ui_settings'];

/** HTML-escape shorthand for the v2 templates. */
if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}

/** Initials for the avatar chip. */
function v2_initials($name)
{
    $parts = preg_split('/\s+/', trim((string) $name));
    $first = $parts[0] ?? '';
    $last  = count($parts) > 1 ? end($parts) : '';
    return strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1)) ?: 'U';
}
