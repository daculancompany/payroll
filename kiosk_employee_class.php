<?php
/**
 * Employee mode of the UareUApp — kiosk Phase D.
 *
 * The kiosk half of the app (kiosk_api_class.php) is a tablet a timekeeper
 * operates: it signs in once, pulls the whole roster, and punches whoever
 * stands in front of it. This half is the other thing the same app can be —
 * one member of staff, on their own phone, punching only for themselves.
 *
 * Everything that follows comes from that one difference:
 *
 *   The token is the identity.  A kiosk punch names an employee_id; an
 *   employee punch does not, and must not — the bearer token says who it is.
 *   So these routes do NOT use the shared kiosk key. Each sign-in mints a
 *   token of its own (employee_api_tokens), stored only as a hash.
 *
 *   Nobody watched it happen.  A kiosk punch has a timekeeper standing
 *   beside it. A phone punch has nothing but what the phone says, which is
 *   why it is off for everybody until switched on per employee
 *   (employee.mobile_punch_enabled), why it is measured against the site's
 *   attendance area (Phase C), and why it is filed with source='mobile' so
 *   the two can always be told apart afterwards.
 *
 *   It is their own record.  History, face templates and the profile are
 *   scoped to the signed-in employee by construction: the queries take the
 *   id from the token, never from the request, so there is no id to tamper
 *   with and no "other employee" case to get wrong.
 *
 * Routes (the app's own paths, which must not change — see the app's
 * src/api/endpoints.ts):
 *
 *   /api/auth/employee/login                POST {username, password}
 *   /api/auth/employee/logout               POST
 *   /api/auth/employee/me                   POST
 *   /api/auth/employee/password             POST {current_password, password, password_confirmation}
 *   /api/auth/employee/faces                POST {model}
 *   /api/auth/employee/status               POST
 *   /api/auth/employee/attendance           POST {punch_ref, action?, scan_time?, lat/lng…, offline}
 *   /api/auth/employee/attendance/selfie    POST {punch_ref, selfie}
 *   /api/auth/employee/attendance/history   POST {from, to}
 *   /api/auth/employee/recognition-events   POST {device_id, events[]}
 *
 * Signing in uses the SAME credentials as the employee web portal
 * (employee_portal_accounts, or the legacy birthday/employee-no fallback):
 * one password per person, changed in one place.
 */

require_once __DIR__ . '/kiosk_api_class.php';
// kiosk_punch_meta() / kiosk_punch_meta_for(): the photo, coordinates and
// area verdict kept alongside each punch, which the history screen shows.
require_once __DIR__ . '/includes/kiosk_admin.php';

class KioskEmployeeApi extends KioskApi
{
    /** A sign-in lasts this long before the app must log in again. */
    const TOKEN_TTL_DAYS = 30;

    /** Plain-text length of a token, in bytes before hex encoding. */
    const TOKEN_BYTES = 32;

    /** How far back history may be asked for in one request. */
    const HISTORY_MAX_DAYS = 62;

    /** The signed-in employee row, resolved once from the bearer token. */
    protected $me = null;

    /* =====================================================================
     * Authentication
     * ================================================================== */

    /**
     * Sign in as an employee.
     *
     * Deliberately the same rules as the web portal's sign-in, including the
     * legacy fallback for staff who have never been given an account row
     * (their birthday as mmddyyyy, or their employee number). Two different
     * answers to "what is my password?" would be worse than the fallback.
     *
     * The response is the app's EmployeeSession shape — field names belong to
     * the app (src/employee/employee.adapter.ts) and are not ours to tidy.
     */
    public function employeeLogin()
    {
        $username = trim((string) $this->input('username', ''));
        $password = (string) $this->input('password', '');

        if ($username === '') return $this->invalid('username', 'The username field is required.');
        if ($password === '') return $this->invalid('password', 'The password field is required.');

        $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $identity = 'emp-api-login:' . strtolower($username) . '|' . $ip;

        $wait = $this->loginLockedFor($identity);
        if ($wait > 0) {
            return $this->fail('Too many sign-in attempts. Try again in ' . $wait . ' seconds.', 429);
        }

        $emp = $this->findPortalEmployee($username);
        if (!$emp || !$this->portalPasswordMatches($emp, $password)) {
            $this->recordFailedLogin($identity, $ip);
            return $this->fail('Invalid username or password', 401);
        }
        $this->clearLoginAttempts($identity);

        if ((int) $emp['status'] !== 1) {
            return $this->fail('This employee record is no longer active', 401);
        }
        if (isset($emp['acct_active']) && $emp['acct_pass'] !== null && (int) $emp['acct_active'] !== 1) {
            return $this->fail('This portal account has been switched off', 401);
        }
        if (!$this->mobilePunchAllowed($emp)) {
            return $this->fail(
                'Punching from your own phone is not switched on for your account. Ask HR to enable it.',
                403, ['code' => 'mobile_punch_disabled']
            );
        }

        $token = $this->issueToken((int) $emp['id']);

        return $this->ok(array_merge(
            [
                'access_token' => $token['token'],
                'token_type'   => 'Bearer',
                'expires_at'   => $token['expires_at'],
                'user_id'      => (string) $emp['id'],
            ],
            $this->profilePayload($emp)
        ));
    }

    /** Give up this device's token. Always answers OK — the app logs out regardless. */
    public function employeeLogout()
    {
        if ($this->me) {
            $hash = $this->bearerHash();
            if ($hash !== null) {
                $stmt = $this->db->prepare("UPDATE employee_api_tokens SET revoked_at = NOW() WHERE token_hash = ? AND revoked_at IS NULL");
                $stmt->bind_param('s', $hash);
                $stmt->execute();
            }
        }
        return $this->ok(['message' => 'Signed out']);
    }

    /** The profile again, so the app can refresh a stale copy. */
    public function employeeMe()
    {
        return $this->ok($this->profilePayload($this->me));
    }

    /**
     * Change my own password.
     *
     * Writes to employee_portal_accounts — the same row the web portal reads,
     * so a password changed on the phone works on the website and the other
     * way round. An employee who has no account row yet gets one created
     * here, which is also how they leave the legacy birthday password behind.
     *
     * Every other token for this employee is revoked: a password change is
     * what someone does when they think a phone is compromised, and leaving
     * the other sessions alive would defeat it. The token making the change
     * survives, so the app does not bounce the person straight back to login.
     */
    public function employeePassword()
    {
        $current = (string) $this->input('current_password', '');
        $next    = (string) $this->input('password', '');
        $confirm = (string) $this->input('password_confirmation', '');

        if ($next === '')        return $this->invalid('password', 'The password field is required.');
        if ($next !== $confirm)  return $this->invalid('password_confirmation', 'The passwords do not match.');
        if (mb_strlen($next) < 8) return $this->invalid('password', 'The password must be at least 8 characters.');
        if ($next === $current)  return $this->invalid('password', 'The new password must be different from the current one.');

        $emp = $this->findPortalEmployee((string) $this->me['employee_no']);
        if (!$emp || !$this->portalPasswordMatches($emp, $current)) {
            return $this->invalid('current_password', 'Your current password is not correct.');
        }

        $hash = password_hash($next, PASSWORD_DEFAULT);
        $eid  = (int) $this->me['id'];

        $has = $this->db->prepare("SELECT id FROM employee_portal_accounts WHERE employee_id = ? LIMIT 1");
        $has->bind_param('i', $eid);
        $has->execute();
        $row = $has->get_result()->fetch_assoc();

        if ($row) {
            $up = $this->db->prepare("UPDATE employee_portal_accounts SET password = ?, must_change = 0 WHERE employee_id = ?");
            $up->bind_param('si', $hash, $eid);
            if (!$up->execute()) return $this->fail('Could not change the password', 500);
        } else {
            $username = (string) $this->me['employee_no'];
            $ins = $this->db->prepare(
                "INSERT INTO employee_portal_accounts (employee_id, username, password, is_active, must_change)
                 VALUES (?, ?, ?, 1, 0)"
            );
            $ins->bind_param('iss', $eid, $username, $hash);
            if (!$ins->execute()) return $this->fail('Could not change the password', 500);
        }

        $keep = $this->bearerHash();
        $rev  = $this->db->prepare(
            "UPDATE employee_api_tokens SET revoked_at = NOW()
             WHERE employee_id = ? AND revoked_at IS NULL AND token_hash <> ?"
        );
        $rev->bind_param('is', $eid, $keep);
        $rev->execute();

        return $this->ok(['message' => 'Password changed']);
    }

    /* =====================================================================
     * Attendance
     * ================================================================== */

    /** My own state right now — which button the app should offer. */
    public function employeeStatus()
    {
        $_POST['employee_id'] = (string) $this->me['id'];
        unset($_POST['scan_time']);               // my status is always "now"
        $_GET['employee_id']  = (string) $this->me['id'];

        $res = parent::status();

        // Tell the app about the area it will be measured against, so it can
        // warn before it posts rather than after.
        if (isset($res['body']['data']) && is_array($res['body']['data'])) {
            $fence = site_geofence($this->db, $this->siteForEmployee());
            $res['body']['data']['geofence'] = geofence_payload($fence);
        }
        return $res;
    }

    /**
     * Punch for myself.
     *
     * The request carries no employee_id and no site_id: both are the
     * server's to decide. Everything else — the idempotency key, the replay,
     * the duplicate handling, the DTR write — is the kiosk path, reused
     * rather than rewritten, so a phone punch and a tablet punch land in
     * attendance identically and payroll never has to know which was which.
     */
    public function employeePunch()
    {
        $eid     = (int) $this->me['id'];
        $site_id = $this->siteForEmployee();
        if ($site_id <= 0) {
            return $this->fail('No active site is set up to file your attendance against. Ask HR.', 422);
        }

        // Compose the kiosk punch call from what is ours to say.
        $_POST['employee_id'] = (string) $eid;
        $_POST['site_id']     = (string) $site_id;
        $_POST['entry_type']  = 'bio';     // a phone punch is a face scan, never a PIN or a manual entry
        unset($_POST['pin_hash'], $_POST['authorized_by']);
        $_GET = [];

        $res = parent::punch();

        // Mark it as made on a phone. The row is already written by here, so
        // this is a correction of one column rather than a branch through the
        // whole punch path — the kiosk route stays exactly as it was.
        $ref = self::cleanRef($this->input('punch_ref'));
        if ($ref !== null && self::punchHasGeofenceColumns($this->db)) {
            $up = $this->db->prepare("UPDATE kiosk_punches SET source = 'mobile' WHERE punch_ref = ?");
            $up->bind_param('s', $ref);
            $up->execute();
        }

        return $res;
    }

    /** Attach the photo for a punch of mine that was posted without one. */
    public function employeeAttachSelfie()
    {
        $ref = self::cleanRef($this->input('punch_ref'));
        if ($ref === null) return $this->invalid('punch_ref', 'The punch ref field is required.');

        // The kiosk's attachSelfie takes the punch_ref on trust — every
        // tablet shares one token, so there is no "whose punch is this?" to
        // ask. Here there is: check the punch is mine before touching it.
        $own = $this->db->prepare("SELECT employee_id FROM kiosk_punches WHERE punch_ref = ? LIMIT 1");
        $own->bind_param('s', $ref);
        $own->execute();
        $row = $own->get_result()->fetch_assoc();
        if (!$row) return $this->fail('That punch is not on file', 404);
        if ((int) $row['employee_id'] !== (int) $this->me['id']) {
            return $this->fail('That punch is not yours', 403);
        }

        return parent::attachSelfie();
    }

    /**
     * My attendance, day by day, for the app's history screen.
     *
     * Reads the same DTR_details rows the web portal shows, so the figures
     * on the phone and the figures on the payslip come from one place.
     */
    public function employeeHistory()
    {
        $from = $this->parseDate($this->input('from'));
        $to   = $this->parseDate($this->input('to'));
        if ($from === null || $to === null) {
            return $this->invalid('from', 'Give a from and to date as YYYY-MM-DD.');
        }
        if (strtotime($from) > strtotime($to)) { [$from, $to] = [$to, $from]; }

        $span = (strtotime($to) - strtotime($from)) / 86400;
        if ($span > self::HISTORY_MAX_DAYS) {
            return $this->invalid('to', 'Ask for at most ' . self::HISTORY_MAX_DAYS . ' days at a time.');
        }

        $eid  = (int) $this->me['id'];
        $stmt = $this->db->prepare(
            "SELECT id, date_time, logs, work_hours, late, is_complete, day_type
             FROM DTR_details
             WHERE employee_id = ? AND date_time BETWEEN ? AND ?
             ORDER BY date_time ASC"
        );
        $stmt->bind_param('iss', $eid, $from, $to);
        $stmt->execute();
        $res = $stmt->get_result();

        $meta = function_exists('kiosk_punch_meta') ? kiosk_punch_meta($this->db, $from, $to, $eid) : [];

        $days = [];
        while ($res && ($r = $res->fetch_assoc())) {
            $logs = json_decode((string) $r['logs'], true) ?: [];
            usort($logs, function ($a, $b) {
                return strtotime($a['dateTime'] ?? '') <=> strtotime($b['dateTime'] ?? '');
            });

            $punches = [];
            foreach ($logs as $i => $lg) {
                $when = (string) ($lg['dateTime'] ?? '');
                if ($when === '') continue;
                $km = kiosk_punch_meta_for($meta, $eid, date('Y-m-d H:i:s', strtotime($when)));
                $punches[] = [
                    'punch'             => $i === 0 ? 'TIME_IN' : ($i === count($logs) - 1 ? 'TIME_OUT' : 'BREAK_START'),
                    'scanned_at'        => $when,
                    'source'            => ($km['source'] ?? 'kiosk') === 'mobile' ? 'mobile' : 'kiosk',
                    'offline'           => false,
                    'latitude'          => $km['lat'] ?? null,
                    'longitude'         => $km['lng'] ?? null,
                    'location_accuracy' => $km['accuracy'] ?? null,
                    'location_mocked'   => $km['mocked'] ?? null,
                    'distance_m'        => $km['distance_m'] ?? null,
                    'outside_geofence'  => !empty($km['outside']),
                    'selfie_url'        => isset($km['photo']) ? $this->public_url($km['photo']) : null,
                ];
            }

            // First punch in, last punch out — and only call it an out once
            // the day is closed, so an employee still at work does not see a
            // time-out they have not made.
            $first = $logs ? ($logs[0]['dateTime'] ?? null) : null;
            $last  = count($logs) > 1 ? ($logs[count($logs) - 1]['dateTime'] ?? null) : null;

            $days[] = [
                'id'              => (string) $r['id'],
                'date'            => $r['date_time'],
                'am_in'           => $first ? date('H:i:s', strtotime($first)) : null,
                'logged_in_break' => null,
                'pm_out'          => ($last && (int) $r['is_complete'] === 1)
                                     ? date('H:i:s', strtotime($last)) : null,
                'is_late'         => (float) $r['late'] > 0,
                'late_hrs'        => round(((float) $r['late']) / 60, 2),
                'est_hrs'         => (float) $r['work_hours'],
                'on_leave'        => in_array(strtolower((string) $r['day_type']), ['leave', 'on_leave'], true),
                'in_selfie_url'   => $punches[0]['selfie_url'] ?? null,
                'out_selfie_url'  => $punches ? end($punches)['selfie_url'] : null,
                'punches'         => $punches,
            ];
        }

        return $this->ok(['from' => $from, 'to' => $to, 'days' => $days]);
    }

    /* =====================================================================
     * Faces and recognition
     * ================================================================== */

    /** My enrolled face templates, so the phone can verify it is me. */
    public function employeeFaces()
    {
        $_POST['employee_id'] = (string) $this->me['id'];
        $_GET['employee_id']  = (string) $this->me['id'];
        return $this->wrap($this->get_faces());
    }

    /**
     * Recognition attempts from my phone, for the health report.
     *
     * The employee flavour sends 'at' where the kiosk sends 'occurred_at',
     * and leaves out employee_id — it is me. Both are fixed up here so the
     * kiosk's own handler stays the single place these are stored.
     */
    public function employeeRecognitionEvents()
    {
        $events = $this->input('events');
        if (!is_array($events)) return $this->fail('Missing events', 422);

        foreach ($events as $i => $e) {
            if (!is_array($e)) continue;
            if (!isset($e['occurred_at']) && isset($e['at'])) $events[$i]['occurred_at'] = $e['at'];
            $events[$i]['employee_id'] = (int) $this->me['id'];
            if (!isset($events[$i]['method'])) $events[$i]['method'] = 'face';
        }
        $_POST['events'] = $events;

        return parent::recognitionEvents();
    }

    /* =====================================================================
     * Token plumbing
     * ================================================================== */

    /**
     * Resolve the bearer token to an employee, or answer 401.
     *
     * Called by the router before any employee route but login. Returns null
     * when the request may proceed, or the refusal to send back.
     */
    public function requireEmployee()
    {
        $hash = $this->bearerHash();
        if ($hash === null) return $this->fail('Unauthenticated', 401);

        // The portal account is joined in so `must_change_password` survives
        // a refresh: without it /me would answer "no change needed" straight
        // after a login that said otherwise, and the app would stop asking.
        $stmt = $this->db->prepare(
            "SELECT t.id AS token_id, t.expires_at, e.*,
                    a.must_change AS acct_must_change, a.is_active AS acct_active, a.password AS acct_pass
             FROM employee_api_tokens t
             JOIN employee e ON e.id = t.employee_id
             LEFT JOIN employee_portal_accounts a ON a.employee_id = e.id
             WHERE t.token_hash = ? AND t.revoked_at IS NULL
               AND (t.expires_at IS NULL OR t.expires_at > NOW())
             LIMIT 1"
        );
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row)                        return $this->fail('Unauthenticated', 401);
        if ((int) $row['status'] !== 1)   return $this->fail('This employee record is no longer active', 401);
        if (!$this->mobilePunchAllowed($row)) {
            return $this->fail('Punching from your own phone has been switched off for your account.',
                403, ['code' => 'mobile_punch_disabled']);
        }

        $this->me = $row;

        $touch = $this->db->prepare("UPDATE employee_api_tokens SET last_used_at = NOW() WHERE id = ?");
        $touch->bind_param('i', $row['token_id']);
        $touch->execute();

        return null;
    }

    /** sha256 of the presented bearer token, or null when none was sent. */
    protected function bearerHash()
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $header = $v; break; }
            }
        }
        if (!preg_match('/^Bearer\s+(\S+)$/i', trim((string) $header), $m)) return null;
        return hash('sha256', $m[1]);
    }

    /** Mint a token for this employee and this device. */
    protected function issueToken($employee_id)
    {
        $plain   = bin2hex(random_bytes(self::TOKEN_BYTES));
        $hash    = hash('sha256', $plain);
        $expires = date('Y-m-d H:i:s', strtotime('+' . self::TOKEN_TTL_DAYS . ' days'));

        $device = self::cleanDeviceId($this->input('device_id'));
        $label  = mb_substr(trim((string) $this->input('device_label', '')), 0, 80) ?: null;
        $ip     = $_SERVER['REMOTE_ADDR'] ?? null;
        $agent  = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null;

        // One live token per device: signing in again on the same phone
        // replaces the old one instead of leaving a trail of usable tokens.
        if ($device !== null) {
            $old = $this->db->prepare(
                "UPDATE employee_api_tokens SET revoked_at = NOW()
                 WHERE employee_id = ? AND device_id = ? AND revoked_at IS NULL"
            );
            $old->bind_param('is', $employee_id, $device);
            $old->execute();
        }

        $ins = $this->db->prepare(
            "INSERT INTO employee_api_tokens (employee_id, token_hash, device_id, device_label, ip, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $ins->bind_param('issssss', $employee_id, $hash, $device, $label, $ip, $agent, $expires);
        $ins->execute();

        return ['token' => $plain, 'expires_at' => $expires];
    }

    /* =====================================================================
     * Helpers
     * ================================================================== */

    /**
     * The employee behind a username, by the portal's own rule: their
     * employee number OR the username on their portal account.
     */
    protected function findPortalEmployee($username)
    {
        $sql = "SELECT e.*, a.password AS acct_pass, a.is_active AS acct_active, a.must_change AS acct_must_change
                FROM employee e
                LEFT JOIN employee_portal_accounts a ON a.employee_id = e.id
                WHERE (e.employee_no = ? OR a.username = ?)
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ss', $username, $username);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    /**
     * Does this password open that employee's account?
     *
     * Mirrors the web portal exactly, legacy fallback and all: an employee
     * with no account row may still sign in with their birthday as mmddyyyy
     * or with their employee number, and is nudged to change it.
     */
    protected function portalPasswordMatches(array $emp, $password)
    {
        if (!empty($emp['acct_pass'])) {
            return password_verify($password, $emp['acct_pass']);
        }
        $birthday = !empty($emp['bday']) ? date('mdY', strtotime($emp['bday'])) : null;
        $number   = (string) $emp['employee_no'];
        return ($birthday !== null && hash_equals($birthday, (string) $password))
            || hash_equals($number, (string) $password);
    }

    /** Has an administrator switched phone punching on for this employee? */
    protected function mobilePunchAllowed(array $emp)
    {
        static $has_col = null;
        if ($has_col === null) {
            $c = $this->db->query("SHOW COLUMNS FROM employee LIKE 'mobile_punch_enabled'");
            $has_col = (bool) ($c && $c->num_rows);
        }
        // Before the migration there is no switch, so nobody is allowed —
        // failing closed is the only safe reading of a missing permission.
        if (!$has_col) return false;
        return (int) ($emp['mobile_punch_enabled'] ?? 0) === 1;
    }

    /**
     * Which site this employee's attendance is filed under.
     *
     * Their own recent history first — that is where they actually work —
     * then the only active site, which is the answer for a single-site
     * payroll. Returns 0 when there is genuinely nothing to file against,
     * and the punch says so rather than inventing a site.
     */
    protected function siteForEmployee()
    {
        $eid = (int) $this->me['id'];

        $stmt = $this->db->prepare(
            "SELECT d.site_id
             FROM DTR_details dd
             JOIN DTR d ON d.id = dd.ddtr_id
             JOIN sites s ON s.id = d.site_id AND s.status = 1
             WHERE dd.employee_id = ? AND dd.date_time >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
             ORDER BY dd.date_time DESC LIMIT 1"
        );
        $stmt->bind_param('i', $eid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row && (int) $row['site_id'] > 0) return (int) $row['site_id'];

        $one = $this->db->query("SELECT id FROM sites WHERE status = 1 ORDER BY id LIMIT 2");
        $ids = [];
        while ($one && ($r = $one->fetch_assoc())) $ids[] = (int) $r['id'];

        return count($ids) === 1 ? $ids[0] : 0;
    }

    /** The app's EmployeeSession profile fields for one employee row. */
    protected function profilePayload(array $emp)
    {
        $site  = 0;
        $saved = $this->me;
        $this->me = $emp;
        $site = $this->siteForEmployee();
        $this->me = $saved ?: $emp;

        $site_name = null;
        if ($site > 0) {
            $s = $this->activeSite($site);
            $site_name = $s['site_name'] ?? null;
        }

        $has_face = false;
        $fq = $this->db->prepare("SELECT 1 FROM biometric_kiosk_faces WHERE employee_id = ? LIMIT 1");
        if ($fq) {
            $eid = (int) $emp['id'];
            $fq->bind_param('i', $eid);
            $fq->execute();
            $has_face = (bool) $fq->get_result()->fetch_row();
        }

        return [
            'employee_id'          => (string) $emp['id'],
            'employee_name'        => $this->fullName($emp),
            'first_name'           => (string) $emp['firstname'],
            'last_name'            => (string) $emp['lastname'],
            'branch_id'            => $site > 0 ? (string) $site : null,
            'branch_name'          => $site_name,
            'geofence'             => geofence_payload(site_geofence($this->db, $site)),
            'has_face'             => $has_face,
            'must_change_password' => !empty($emp['acct_must_change']),
        ];
    }

    /** "YYYY-MM-DD" or null. */
    protected function parseDate($raw)
    {
        $raw = trim((string) $raw);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return null;
        return date('Y-m-d', strtotime($raw)) === $raw ? $raw : null;
    }
}
