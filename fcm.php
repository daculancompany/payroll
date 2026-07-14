<?php
/**
 * Firebase Cloud Messaging (HTTP v1) push helper — no SDK required.
 *
 * SETUP (one time):
 *   Firebase Console → Project settings → Service accounts →
 *   "Generate new private key" → save the downloaded file as
 *   firebase-service-account.json in this directory (keep it out of git /
 *   public download — Apache serves *.json, so also deny it, see below).
 *
 * Messages are sent DATA-ONLY; firebase-messaging-sw.js renders them, which
 * keeps one code path for foreground + background and avoids double display.
 */

define('FCM_PROJECT_ID', 'payroll-comc');
define('FCM_SERVICE_ACCOUNT_JSON', __DIR__ . '/firebase-service-account.json');

function fcm_available()
{
    return is_file(FCM_SERVICE_ACCOUNT_JSON) && function_exists('curl_init') && function_exists('openssl_sign');
}

function fcm_b64url($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * OAuth2 access token for the firebase.messaging scope, built from the
 * service account via a self-signed RS256 JWT. Cached on disk (~55 min).
 */
function fcm_access_token()
{
    static $mem = null;
    if ($mem && $mem['exp'] > time() + 60) {
        return $mem['token'];
    }

    $cacheFile = sys_get_temp_dir() . '/fcm_oauth_' . md5(FCM_SERVICE_ACCOUNT_JSON) . '.json';
    if (is_file($cacheFile)) {
        $c = json_decode((string)file_get_contents($cacheFile), true);
        if (!empty($c['token']) && $c['exp'] > time() + 60) {
            $mem = $c;
            return $c['token'];
        }
    }

    $sa = json_decode((string)file_get_contents(FCM_SERVICE_ACCOUNT_JSON), true);
    if (empty($sa['client_email']) || empty($sa['private_key'])) {
        return null;
    }

    $now = time();
    $jwtHead = fcm_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $jwtBody = fcm_b64url(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));
    $sig = '';
    if (!openssl_sign($jwtHead . '.' . $jwtBody, $sig, $sa['private_key'], 'sha256WithRSAEncryption')) {
        return null;
    }
    $jwt = $jwtHead . '.' . $jwtBody . '.' . fcm_b64url($sig);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (empty($resp['access_token'])) {
        return null;
    }

    $mem = ['token' => $resp['access_token'], 'exp' => $now + (int)($resp['expires_in'] ?? 3600)];
    @file_put_contents($cacheFile, json_encode($mem));
    return $mem['token'];
}

/**
 * Push a data message to every registered STAFF (admin/HR) browser.
 * Tokens FCM reports as gone (UNREGISTERED / INVALID_ARGUMENT) are deleted.
 *
 * @param mysqli $db
 * @return int number of browsers the push was delivered to
 */
function fcm_push_admins($db, $title, $body, $link = 'index.php', $tag = 'comc-payroll')
{
    return fcm_push_where($db, "recipient_type = 'user'", $title, $body, $link, $tag);
}

/**
 * Push a data message to one employee's registered browser(s).
 */
function fcm_push_employee($db, $employee_id, $title, $body, $link = 'employee-portal.php', $tag = 'comc-payroll')
{
    $employee_id = (int)$employee_id;
    if ($employee_id <= 0) {
        return 0;
    }
    return fcm_push_where($db, "recipient_type = 'employee' AND user_id = $employee_id", $title, $body, $link, $tag);
}

/**
 * Push a data message to every registered browser of active staff holding any
 * of the given role(s). Used for leave / attendance-request review alerts so
 * reviewers (Admin / Dept Head / HR) are notified even with the site closed.
 *
 * @param mysqli    $db
 * @param int|int[] $roles  one role id or a list of them
 */
function fcm_push_role($db, $roles, $title, $body, $link = 'index.php', $tag = 'comc-payroll')
{
    $roles = is_array($roles) ? $roles : [$roles];
    $roles = array_values(array_filter(array_map('intval', $roles), function ($r) { return $r > 0; }));
    if (!$roles) {
        return 0;
    }
    $inList = implode(',', $roles);
    return fcm_push_where(
        $db,
        "recipient_type = 'user' AND user_id IN (SELECT id FROM users WHERE role IN ($inList) AND status = 1)",
        $title, $body, $link, $tag
    );
}

function fcm_push_where($db, $where, $title, $body, $link, $tag)
{
    if (!fcm_available()) {
        return 0;
    }
    $access = fcm_access_token();
    if (!$access) {
        return 0;
    }

    $tokens = [];
    $res = $db->query("SELECT id, token FROM fcm_tokens WHERE $where");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $tokens[] = $r;
        }
    }
    if (!$tokens) {
        return 0;
    }

    $url = 'https://fcm.googleapis.com/v1/projects/' . FCM_PROJECT_ID . '/messages:send';
    $sent = 0;

    foreach ($tokens as $t) {
        $payload = json_encode(['message' => [
            'token' => $t['token'],
            'data'  => [
                'title' => (string)$title,
                'body'  => (string)$body,
                'link'  => (string)$link,
                'tag'   => (string)$tag,
            ],
            'webpush' => ['headers' => ['Urgency' => 'high']],
        ]]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $access,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $respBody = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200) {
            $sent++;
        } elseif ($code === 404 || $code === 400 || strpos($respBody, 'UNREGISTERED') !== false) {
            // Browser uninstalled / token rotated — stop trying it.
            $db->query("DELETE FROM fcm_tokens WHERE id = " . (int)$t['id']);
        }
    }
    return $sent;
}
