<?php
/**
 * Shared request handling for the biometric APIs.
 *
 * biometric-api.php (desktop scanner) and biometric-kiosk-api.php (mobile
 * kiosk) had drifted apart on how they read the request: one matched
 * Content-Type case-sensitively, only one looked at HTTP_CONTENT_TYPE, only one
 * accepted the action from a JSON body, and they disagreed on the status code
 * for a failed login. A client that worked against one could fail against the
 * other for reasons that had nothing to do with the action it called.
 *
 * Both now route every request through these helpers, so the envelope is
 * identical and only the action table differs — that genuinely differs, because
 * the two devices have different capabilities.
 *
 * Load AFTER db_connect.php: bio_api_require_bearer() needs BIOMETRIC_API_KEY.
 */

/**
 * Merge a JSON request body into $_POST so handlers can read either encoding.
 *
 * Case-insensitive, and falls back to HTTP_CONTENT_TYPE because some SAPI
 * configurations expose the header there instead of CONTENT_TYPE.
 */
if (!function_exists('bio_api_read_body')) {
    function bio_api_read_body(): void
    {
        $content_type = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (stripos($content_type, 'application/json') === false) {
            return;
        }
        $body = json_decode(file_get_contents('php://input'), true);
        if (is_array($body)) {
            $_POST = array_merge($_POST, $body);
        }
    }
}

/**
 * Resolve the requested action: query string, then form post, then JSON body.
 *
 * Call AFTER bio_api_read_body() so a client that can only send a JSON body
 * (no query string) still gets routed — biometric-api.php used to read $_POST
 * before the body was merged, silently falling through to its default action.
 *
 * $default keeps existing scanner firmware working: it posts a bare punch with
 * no action at all and must continue to mean save-attendance.
 */
if (!function_exists('bio_api_resolve_action')) {
    function bio_api_resolve_action(string $default = ''): string
    {
        $action = $_GET['action'] ?? '';
        if ($action === '') $action = $_POST['action'] ?? '';
        return $action !== '' ? (string) $action : $default;
    }
}

/** Reject anything but POST. Call after the public probes, which allow GET. */
if (!function_exists('bio_api_require_post')) {
    function bio_api_require_post(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            exit(json_encode(['result' => false, 'message' => 'Method not allowed']));
        }
    }
}

/**
 * Bearer-token gate. Exits 401 when the token is missing or wrong.
 *
 * Apache strips the Authorization header under some configurations, hence the
 * REDIRECT_ and apache_request_headers() fallbacks.
 */
if (!function_exists('bio_api_require_bearer')) {
    function bio_api_require_bearer(): void
    {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '');

        $provided_key = '';
        if (preg_match('/^Bearer\s+(\S+)$/i', (string) $auth_header, $m)) {
            $provided_key = $m[1];
        }

        if (!$provided_key || !hash_equals(BIOMETRIC_API_KEY, $provided_key)) {
            http_response_code(401);
            exit(json_encode(['result' => false, 'message' => 'Unauthorized']));
        }
    }
}

/**
 * Liveness payload. Both files expose it under BOTH names — the desktop
 * scanner asks for 'ping', the kiosk asks for 'health', and neither should 404
 * just because it probed the other server. Public on purpose: requiring a
 * token would make an expired session look like a dead network. It reveals
 * nothing but the server clock.
 */
if (!function_exists('bio_api_liveness')) {
    function bio_api_liveness(string $action): array
    {
        return [
            'result'      => true,
            'message'     => $action === 'ping' ? 'pong' : 'ok',
            'time'        => date('Y-m-d H:i:s'),
            'server_time' => date('Y-m-d H:i:s'),
        ];
    }
}

/**
 * Emit the handler's result. 200 on success; 401 for a rejected login (it is an
 * auth failure, not a validation failure) and 422 for everything else.
 */
if (!function_exists('bio_api_respond')) {
    function bio_api_respond($result, string $action = ''): void
    {
        $ok = is_array($result) && !empty($result['result']);
        if ($ok)                      http_response_code(200);
        elseif ($action === 'login')  http_response_code(401);
        else                          http_response_code(422);
        echo json_encode(is_array($result) ? $result : ['result' => false, 'message' => 'Handler returned no result']);
        exit;
    }
}

/** 404 for an action this endpoint does not implement. */
if (!function_exists('bio_api_unknown_action')) {
    function bio_api_unknown_action(string $action): void
    {
        http_response_code(404);
        exit(json_encode(['result' => false, 'message' => 'Unknown action: ' . $action]));
    }
}
