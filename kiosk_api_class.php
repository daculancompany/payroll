<?php
/**
 * Kiosk API — the UareUApp contract, served by payroll.
 *
 * UareUApp talks to `/kiosk/<endpoint>` (login, employees, attendance-status,
 * attendance, templates, faces, heartbeat, recognition-events). The contract
 * was defined by zweldo's Laravel KioskController; this class answers the same
 * URLs with the same request and response shapes, so one app build works
 * against either server by changing only its base URL.
 *
 * NEW FILE, kept apart on purpose: biometric-kiosk-api.php (the older
 * ?action= kiosk API) and biometric-api.php (desktop scanner) are untouched.
 *
 * Where payroll differs from zweldo, and why:
 *
 *   - Login is TIMEKEEPER-ONLY (role 5). Administrator accounts are refused —
 *     on payroll the timekeeper is the person who runs the kiosk.
 *   - Punch mode is always 'simple' (time in / time out). A payroll day is one
 *     DTR_details row whose `logs` JSON is paired first-in / last-out; there
 *     are no break slots to fill.
 *   - The attendance write is NOT reimplemented. Every punch goes through
 *     Action::save_biometric_attendance(), the one implementation of overnight
 *     re-dating, the double-tap debounce and DTR batching. A requested
 *     `action` is therefore advisory: the DTR pairing decides, exactly as it
 *     does for the desktop scanner, and a punch is never refused for state.
 *   - No geofencing: payroll has no attendance areas, so the kiosk is told
 *     "area set, check off" (has_area: true, geofence: null).
 *
 * Every public endpoint returns ['status' => <http code>, 'body' => array];
 * kiosk-api.php emits it. Success bodies are {result, status, data}, failures
 * {result:false, status:'error', message, [code]} — the same envelope as
 * zweldo, which the app's unwrap() and ApiError already understand.
 *
 * PHP 7.4 compatible (local XAMPP runs 7.4).
 */

require_once __DIR__ . '/biometric_kiosk_class.php';
// Site attendance area (Phase C): site_geofence(), geofence_measure(),
// geofence_refusal(), geofence_payload(). Shared with the web side.
require_once __DIR__ . '/includes/kiosk_geofence.php';

class KioskApi extends BiometricKiosk
{
    const PUNCH_TIME_IN  = 'TIME_IN';
    const PUNCH_TIME_OUT = 'TIME_OUT';
    const PUNCH_NONE     = 'NONE';
    /** A kiosk_punches row reserved for a punch that is still being written. */
    const PUNCH_PENDING  = 'PENDING';

    const STATE_NOT_TIMED_IN = 'not_timed_in';
    const STATE_WORKING      = 'working';
    const STATE_TIMED_OUT    = 'timed_out';

    const PUNCH_MODE_SIMPLE = 'simple';

    /** An open day still counts as "working" this long after its last punch. */
    const OPEN_SHIFT_HOURS = 16;

    /** A reservation older than this belongs to a request that died midway. */
    const STALE_RESERVATION_SECONDS = 120;

    /** Cosine similarity at which a new face counts as someone else's. */
    const FACE_DUPLICATE_THRESHOLD = 0.80;

    const MAX_RECOGNITION_EVENTS = 500;

    const LOGIN_MAX_ATTEMPTS = 5;
    const LOGIN_LOCK_MINUTES = 15;

    /* ─────────────────────────────────────────────────────────────────────
     * Envelope
     * ──────────────────────────────────────────────────────────────────── */

    protected function ok($data, $status = 200)
    {
        return ['status' => $status, 'body' => ['result' => true, 'status' => 'success', 'data' => $data]];
    }

    protected function fail($message, $status = 422, array $extra = [])
    {
        return ['status' => $status, 'body' => array_merge(
            ['result' => false, 'status' => 'error', 'message' => $message],
            $extra
        )];
    }

    /** One field error in the shape Laravel uses, which ApiError reads. */
    protected function invalid($field, $message)
    {
        return $this->fail($message, 422, ['errors' => [$field => [$message]]]);
    }

    /** Body first (JSON is merged into $_POST), then the query string. */
    protected function input($key, $default = null)
    {
        if (array_key_exists($key, $_POST)) return $_POST[$key];
        if (array_key_exists($key, $_GET))  return $_GET[$key];
        return $default;
    }

    /**
     * Turn a BiometricKiosk ['result' => bool, ...] answer into this API's
     * envelope. Lists carry `data`; everything else is returned whole, minus
     * the legacy result/message keys.
     */
    protected function wrap(array $res, $status = 200)
    {
        if (empty($res['result'])) {
            $message = (string) ($res['message'] ?? 'Request failed');
            $code    = stripos($message, 'not found') !== false ? 404 : 422;
            return $this->fail($message, $code);
        }
        if (array_key_exists('data', $res)) {
            return $this->ok($res['data'], $status);
        }
        unset($res['result'], $res['message']);
        return $this->ok($res, $status);
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Health + login
     * ──────────────────────────────────────────────────────────────────── */

    /** Public reachability probe — no token, no table reads. */
    public function health()
    {
        $now = date('Y-m-d H:i:s');
        return ['status' => 200, 'body' => [
            'result' => true, 'status' => 'ok', 'message' => 'ok', 'server_time' => $now,
        ]];
    }

    /**
     * Kiosk operator sign-in. Timekeepers only. Answers the biometric API key
     * as the bearer token plus the site every punch is posted against.
     */
    public function login()
    {
        $username = trim((string) $this->input('username', ''));
        $password = (string) $this->input('password', '');

        if ($username === '') return $this->invalid('username', 'The username field is required.');
        if ($password === '') return $this->invalid('password', 'The password field is required.');

        $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $identity = 'kiosk-login:' . strtolower($username) . '|' . $ip;

        $wait = $this->loginLockedFor($identity);
        if ($wait > 0) {
            return $this->fail('Too many sign-in attempts. Try again in ' . $wait . ' seconds.', 429);
        }

        $stmt = $this->db->prepare("SELECT id, name, site_id, password, role FROM users WHERE username = ? AND status = 1 LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user || empty($user['password']) || !password_verify($password, $user['password'])) {
            $this->recordFailedLogin($identity, $ip);
            return $this->fail('Invalid username or password', 401);
        }

        $this->clearLoginAttempts($identity);

        // Payroll's kiosk is run by the timekeeper. Administrators sign in to
        // the web app instead; their account is deliberately refused here.
        if ((int) $user['role'] !== ROLE_TIMEKEEPER) {
            return $this->fail('Only timekeeper accounts may operate the kiosk', 401);
        }

        $site = $this->siteForOperator($user);
        if (!$site) {
            return $this->fail('No active site is set up for this timekeeper. Ask an administrator to add one on Biometric Sites.', 401);
        }

        $parts = preg_split('/\s+/', trim((string) $user['name']), 2);

        return ['status' => 200, 'body' => [
            'result'       => true,
            'status'       => 'success',
            'message'      => 'Login successful',
            'access_token' => BIOMETRIC_API_KEY,
            'token_type'   => 'Bearer',
            'user_id'      => (int) $user['id'],
            'first_name'   => $parts[0] ?? (string) $user['name'],
            'last_name'    => $parts[1] ?? '',
            'site_id'      => (string) $site['id'],
            'role'         => (int) $user['role'],
            // The site's attendance area, so the kiosk can warn before it
            // posts. The server re-checks every punch regardless.
            'geofence'     => geofence_payload(site_geofence($this->db, (int) $site['id'])),
            'branches'     => $this->branchesForOperator($user, $site),
        ]];
    }

    /**
     * The site this timekeeper's punches are filed under. Each candidate is
     * checked against `sites` — users.site_id can point at a site that no
     * longer exists, and save_biometric_attendance() rejects a punch whose
     * site is missing, which would park every scan the kiosk sends.
     *   1. users.site_id         — explicitly set on the account
     *   2. sites.timekeeper_id   — the site this timekeeper is assigned to
     *   3. first active site     — single-site fallback
     */
    protected function siteForOperator(array $user)
    {
        $explicit = (int) ($user['site_id'] ?? 0);
        if ($explicit > 0) {
            $site = $this->activeSite($explicit);
            if ($site) return $site;
        }

        $stmt = $this->db->prepare("SELECT id, site_name FROM sites WHERE timekeeper_id = ? AND status = 1 ORDER BY id ASC LIMIT 1");
        $uid  = (int) $user['id'];
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $site = $stmt->get_result()->fetch_assoc();
        if ($site) return $site;

        $res = $this->db->query("SELECT id, site_name FROM sites WHERE status = 1 ORDER BY id ASC LIMIT 1");
        return $res ? ($res->fetch_assoc() ?: null) : null;
    }

    protected function activeSite($site_id)
    {
        $stmt = $this->db->prepare("SELECT id, site_name FROM sites WHERE id = ? AND status = 1 LIMIT 1");
        $stmt->bind_param('i', $site_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    /**
     * Sites the kiosk may pick from. has_area is always true: payroll has no
     * attendance areas, and has_area:false would make the app announce
     * "Punches are blocked" and grey the site out.
     */
    protected function branchesForOperator(array $user, array $resolved)
    {
        $stmt = $this->db->prepare("SELECT id, site_name FROM sites WHERE status = 1 AND (timekeeper_id = ? OR id = ?) ORDER BY site_name");
        $uid  = (int) $user['id'];
        $sid  = (int) $resolved['id'];
        $stmt->bind_param('ii', $uid, $sid);
        $stmt->execute();
        $res = $stmt->get_result();

        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'id'       => (string) $row['id'],
                'name'     => $row['site_name'],
                // Always true, and NOT "this site has a geofence".
                //
                // The app reads has_area as "this site may be punched
                // against": false disables the site in its branch picker and
                // blocks punching outright. On payroll an attendance area is
                // OPTIONAL — most installs will never set one — so reporting
                // the real answer here would stop every existing kiosk the
                // moment this code shipped. The area, when there is one,
                // travels in `geofence` below, and the app only restricts
                // anything when that says mode 'strict'.
                'has_area' => true,
                'geofence' => geofence_payload(site_geofence($this->db, (int) $row['id'])),
            ];
        }
        return $out;
    }

    /* Rate limiting rides the same login_attempts table as the web sign-in
     * (Action::login), under its own identifier prefix. Optional table: a
     * fresh install without it simply skips the throttle. */

    protected function hasLoginAttemptsTable()
    {
        static $has = null;
        if ($has === null) {
            $res = $this->db->query("SHOW TABLES LIKE 'login_attempts'");
            $has = $res && $res->num_rows > 0;
        }
        return $has;
    }

    protected function loginLockedFor($identity)
    {
        if (!$this->hasLoginAttemptsTable()) return 0;
        $stmt = $this->db->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), locked_until) AS secs FROM login_attempts WHERE identifier = ? AND locked_until > NOW()");
        $stmt->bind_param('s', $identity);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? max(1, (int) $row['secs']) : 0;
    }

    protected function recordFailedLogin($identity, $ip)
    {
        if (!$this->hasLoginAttemptsTable()) return;
        $max  = self::LOGIN_MAX_ATTEMPTS;
        $lock = self::LOGIN_LOCK_MINUTES;
        $stmt = $this->db->prepare("
            INSERT INTO login_attempts (identifier, ip, attempts, locked_until) VALUES (?, ?, 1, NULL)
            ON DUPLICATE KEY UPDATE attempts = attempts + 1,
                locked_until = IF(attempts + 1 >= $max, DATE_ADD(NOW(), INTERVAL $lock MINUTE), locked_until)
        ");
        $stmt->bind_param('ss', $identity, $ip);
        $stmt->execute();
    }

    protected function clearLoginAttempts($identity)
    {
        if (!$this->hasLoginAttemptsTable()) return;
        $stmt = $this->db->prepare("DELETE FROM login_attempts WHERE identifier = ?");
        $stmt->bind_param('s', $identity);
        $stmt->execute();
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Roster
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Active employees, name-sorted. `pin_hash` lets the kiosk check a PIN
     * offline without ever holding the PIN; null when no PIN is set.
     * Single-site deployment: no site filter, the same list the desktop
     * scanner sees.
     */
    public function employees()
    {
        $res = $this->db->query("
            SELECT e.id, e.employee_no, e.firstname, e.middlename, e.lastname, e.ext, e.kiosk_pin,
                   COALESCE(d.name, '') AS department, COALESCE(p.name, '') AS position,
                   fp.photo
            FROM employee e
            LEFT JOIN department d ON d.id = e.department_id
            LEFT JOIN position   p ON p.id = e.position_id
            LEFT JOIN (
                SELECT employee_id, MIN(photo) AS photo
                FROM biometric_kiosk_faces
                WHERE photo IS NOT NULL AND photo <> ''
                GROUP BY employee_id
            ) fp ON fp.employee_id = e.id
            WHERE e.status = 1
            ORDER BY e.lastname, e.firstname
        ");

        $data = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $data[] = [
                'id'                => (int) $row['id'],
                'id_no'             => $row['employee_no'],
                'employee_no'       => $row['employee_no'],
                'first_name'        => $row['firstname'],
                'middle_name'       => $row['middlename'],
                'last_name'         => $row['lastname'],
                'suffix'            => $row['ext'],
                'full_name'         => $this->fullName($row),
                'photo'             => $this->public_url($row['photo']),
                'employment_status' => null,
                'payroll_status'    => 1,
                'is_active'         => 1,
                'pin_hash'          => self::pinHash($row['id'], $row['kiosk_pin']),
                'department_name'   => $row['department'] !== '' ? $row['department'] : null,
                'position_name'     => $row['position'] !== '' ? $row['position'] : null,
            ];
        }

        return $this->ok($data);
    }

    /** sha256("<employee_id>:<pin>") — must match the app's pinHash(). */
    public static function pinHash($employee_id, $pin)
    {
        $pin = trim((string) $pin);
        return $pin === '' ? null : hash('sha256', $employee_id . ':' . $pin);
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Attendance status
     * ──────────────────────────────────────────────────────────────────── */

    /** What this employee may do right now — asked before a punch. */
    public function status()
    {
        $emp_raw = trim((string) $this->input('employee_id', ''));
        if (!ctype_digit($emp_raw)) return $this->invalid('employee_id', 'The employee id field is required.');

        $ts = $this->parseScanTime($this->input('scan_time'));
        if ($ts === false) return $this->invalid('scan_time', 'The scan time is not a valid date.');

        $emp = $this->findEmployee((int) $emp_raw);
        if (!$emp) return $this->fail('Employee not found', 404);

        $active = (int) $emp['status'] === 1;
        $day    = $this->dayState((int) $emp['id'], $ts);
        $valid  = $active ? $this->validActions($day['state']) : [];

        $schedule = function_exists('resolve_employee_schedule')
            ? resolve_employee_schedule($this->db, (int) $emp['id'], $day['date'])
            : null;

        return $this->ok([
            'employee_id'           => (int) $emp['id'],
            'employee_name'         => $this->fullName($emp),
            'date'                  => $day['date'],
            'server_time'           => date('Y-m-d H:i:s'),
            'active'                => $active,
            'punch_mode'            => self::PUNCH_MODE_SIMPLE,
            'state'                 => $day['state'],
            'valid_actions'         => $valid,
            'suggested_action'      => $valid[0] ?? null,
            'requires_confirmation' => false,
            'schedule_in'           => $schedule['start_time'] ?? null,
            'schedule_out'          => $schedule['end_time'] ?? null,
            'shift_ends_at'         => null,
            'am_in'                 => $day['am_in'],
            'pm_out'                => $day['pm_out'],
            'break_started_at'      => null,
            'break_count'           => 0,
        ]);
    }

    /**
     * Read the employee's state off DTR_details, the same rows the punch will
     * write into. Approximate on purpose — it only drives which button the
     * kiosk offers; the punch itself is filed by save_biometric_attendance(),
     * which never refuses on state.
     *
     *   yesterday open and its last punch ≤ OPEN_SHIFT_HOURS ago → working
     *   today: no punches → not timed in; complete → timed out; else working
     */
    protected function dayState($employee_id, $ts)
    {
        $today = date('Y-m-d', $ts);
        $prev  = date('Y-m-d', strtotime('-1 day', $ts));

        $stmt = $this->db->prepare("
            SELECT id, date_time, logs, is_complete FROM DTR_details
            WHERE employee_id = ? AND date_time IN (?, ?)
            ORDER BY id DESC
        ");
        $stmt->bind_param('iss', $employee_id, $today, $prev);
        $stmt->execute();
        $res = $stmt->get_result();

        // Several batches can hold a row for the same day (a scan after the
        // cutoff was approved opens a fresh batch) — the newest one wins.
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $d = date('Y-m-d', strtotime($row['date_time']));
            if (!isset($rows[$d])) $rows[$d] = $row;
        }

        if (isset($rows[$prev]) && !(int) $rows[$prev]['is_complete']) {
            $stamps = $this->logStamps($rows[$prev]['logs']);
            if ($stamps && $ts - max($stamps) <= self::OPEN_SHIFT_HOURS * 3600) {
                return $this->stateFrom(self::STATE_WORKING, $prev, $stamps, false);
            }
        }

        if (isset($rows[$today])) {
            $stamps = $this->logStamps($rows[$today]['logs']);
            if ($stamps) {
                $complete = (bool) (int) $rows[$today]['is_complete'];
                return $this->stateFrom($complete ? self::STATE_TIMED_OUT : self::STATE_WORKING, $today, $stamps, $complete);
            }
        }

        return ['state' => self::STATE_NOT_TIMED_IN, 'date' => $today, 'am_in' => null, 'pm_out' => null];
    }

    protected function stateFrom($state, $date, array $stamps, $complete)
    {
        sort($stamps);
        return [
            'state'  => $state,
            'date'   => $date,
            'am_in'  => date('H:i:s', $stamps[0]),
            'pm_out' => $complete && count($stamps) > 1 ? date('H:i:s', end($stamps)) : null,
        ];
    }

    /** Unix timestamps of a DTR_details.logs JSON array. */
    protected function logStamps($logs_json)
    {
        $out = [];
        foreach ((json_decode((string) $logs_json, true) ?: []) as $lg) {
            $t = strtotime($lg['dateTime'] ?? '');
            if ($t !== false) $out[] = $t;
        }
        return $out;
    }

    protected function validActions($state)
    {
        // A punch after time-out extends it (the pairing keeps the latest),
        // so TIME_OUT stays on offer — same table zweldo uses for 'simple'.
        return $state === self::STATE_NOT_TIMED_IN ? [self::PUNCH_TIME_IN] : [self::PUNCH_TIME_OUT];
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Punch
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Record one punch.
     *
     * Order matters:
     *   1. validate, then check the employee and (for a PIN punch) the PIN
     *   2. a known punch_ref replays the stored answer — the offline queue
     *      resends after a timeout and must not punch twice
     *   3. reserve the punch_ref (UNIQUE) before writing, so two copies of
     *      the same queued punch racing each other cannot both get through
     *   4. delegate to Action::save_biometric_attendance()
     *   5. attach the photo, finish the kiosk_punches row
     */
    public function punch()
    {
        $emp_raw = trim((string) $this->input('employee_id', ''));
        if (!ctype_digit($emp_raw)) return $this->invalid('employee_id', 'The employee id field is required.');

        $ts = $this->parseScanTime($this->input('scan_time'));
        if ($ts === false) return $this->invalid('scan_time', 'The scan time is not a valid date.');
        $scan_time = date('Y-m-d H:i:s', $ts);

        $entry_type = trim((string) $this->input('entry_type', '')) ?: 'bio';
        if (!in_array($entry_type, ['bio', 'manual', 'pin'], true)) {
            return $this->invalid('entry_type', 'The selected entry type is invalid.');
        }

        $pin_hash = strtolower(trim((string) $this->input('pin_hash', '')));
        if ($entry_type === 'pin' && !preg_match('/^[a-f0-9]{64}$/', $pin_hash)) {
            return $this->invalid('pin_hash', 'The pin hash field is required for a PIN punch.');
        }

        $action = strtoupper(trim((string) $this->input('action', '')));
        if ($action !== '' && !in_array($action, ['TIME_IN', 'BREAK_START', 'BREAK_END', 'TIME_OUT'], true)) {
            return $this->invalid('action', 'The selected action is invalid.');
        }

        $location = $this->readLocation();
        if (is_array($location) && isset($location['error'])) {
            return $this->invalid($location['field'], $location['error']);
        }

        $punch_ref     = self::cleanRef($this->input('punch_ref'));
        $device_id     = self::cleanDeviceId($this->input('device_id'));
        $site_id       = (int) $this->input('site_id', 0);
        $authorized_by = mb_substr(trim((string) $this->input('authorized_by', '')), 0, 80);
        $selfie_raw    = (string) $this->input('selfie', '');

        $emp = $this->findEmployee((int) $emp_raw);
        if (!$emp) return $this->fail('Employee not found', 404);
        if ((int) $emp['status'] !== 1) return $this->fail('This employee is no longer active', 422);

        if ($entry_type === 'pin') {
            $expected = self::pinHash($emp['id'], $emp['kiosk_pin']);
            if ($expected === null || !hash_equals($expected, $pin_hash)) {
                return $this->fail('The PIN does not match this employee', 422, ['code' => 'pin_mismatch']);
            }
        }

        if ($punch_ref !== null) {
            $seen = $this->punchByRef($punch_ref);
            if ($seen) {
                $stale = $seen['punch'] === self::PUNCH_PENDING
                    && time() - strtotime($seen['created_at']) > self::STALE_RESERVATION_SECONDS;
                if (!$stale) return $this->replay($seen, $emp);
                // A request that died midway left this reservation behind.
                // Clear it, or the queued punch could never be recorded.
                $this->deletePunchRow((int) $seen['id']);
            }
        }

        // ── Site attendance area ─────────────────────────────────────────
        // Measured BEFORE anything is written: a punch strict mode turns away
        // must leave no DTR entry and no kiosk_punches row behind. A replay
        // never reaches here, so a punch already accepted stays accepted even
        // if the area was tightened since.
        $fence    = site_geofence($this->db, $site_id);
        $measured = geofence_measure($fence, $location['latitude'] ?? null,
            $location['longitude'] ?? null, $location['location_accuracy'] ?? null);
        $refusal  = geofence_refusal($fence, $measured);
        if ($refusal !== null) {
            return $this->fail($refusal, 422, [
                'code'       => 'outside_area',
                'distance_m' => $measured['distance_m'],
                'geofence'   => geofence_payload($fence),
            ]);
        }

        $binary = null;
        if ($selfie_raw !== '') {
            $binary = $this->decode_image($selfie_raw);
            if ($binary === null) return $this->fail('Selfie is not valid base64 image data', 422);
        }

        // ── Reserve ──────────────────────────────────────────────────────
        $reserved = $this->reservePunch((int) $emp['id'], $punch_ref, $scan_time, $entry_type);
        if ($reserved === 'taken') {
            $seen = $this->punchByRef($punch_ref);
            return $seen ? $this->replay($seen, $emp) : $this->fail('This punch is still being recorded. Try again shortly.', 503);
        }
        if (!$reserved) return $this->fail('Could not record the scan', 500);
        $row_id = (int) $reserved;

        // ── Delegate the attendance write ────────────────────────────────
        // save_biometric_attendance() reads $_POST; set exactly what it uses.
        $_POST['employee_id']   = (string) $emp['id'];
        $_POST['scan_time']     = $scan_time;
        $_POST['site_id']       = (string) $site_id;
        $_POST['entry_type']    = $entry_type;
        $_POST['authorized_by'] = $authorized_by;

        try {
            require_once __DIR__ . '/admin_class.php';
            $legacy = new Action();
            $res    = $legacy->save_biometric_attendance();
        } catch (\Throwable $e) {
            $this->deletePunchRow($row_id);
            error_log('[kiosk-api] punch failed for employee ' . $emp['id'] . ': ' . $e->getMessage());
            return $this->fail('Could not record the scan', 500);
        }

        if (!is_array($res) || empty($res['result'])) {
            $this->deletePunchRow($row_id);
            return $this->fail((string) ($res['message'] ?? 'Could not record the scan'), 422);
        }

        $scan  = (string) ($res['scan'] ?? '');
        $punch = $scan === 'in' ? self::PUNCH_TIME_IN : ($scan === 'out' ? self::PUNCH_TIME_OUT : self::PUNCH_NONE);
        $dup   = $punch === self::PUNCH_NONE;

        $dtr_date  = (string) ($res['dtr_date'] ?? date('Y-m-d', $ts));
        $detail_id = $dup ? null : $this->detailIdFor((int) $emp['id'], $dtr_date);

        // ── Photo ─────────────────────────────────────────────────────────
        // A duplicate wrote nothing, so there is nothing to attach it to.
        $selfie = null;
        $selfie_error = null;
        if ($binary !== null && !$dup) {
            $selfie = $this->store_selfie((int) $emp['id'], $scan_time, $binary);
            if ($selfie) {
                $this->tagSelfieSource($selfie, $entry_type);
            } else {
                $selfie_error = 'Photo could not be saved';
            }
        }

        $day   = $this->dayState((int) $emp['id'], $ts);
        $pin_p = $entry_type === 'pin' && !$dup;

        $data = $res;
        unset($data['result']);
        $data = array_merge($data, [
            'id'            => $detail_id,
            'employee_id'   => (int) $emp['id'],
            'employee_name' => $this->fullName($emp),
            'date'          => $dtr_date,
            'punch'         => $punch,
            'punch_time'    => $dup ? null : date('H:i:s', $ts),
            'duplicate'     => $dup,
            'entry_type'    => $entry_type,
            'device_id'     => $device_id,
            'state'         => $day['state'],
            'valid_actions' => $this->validActions($day['state']),
            'punch_mode'    => self::PUNCH_MODE_SIMPLE,
            'selfie'        => $selfie,
            'selfie_url'    => $this->public_url($selfie),
            'am_in'         => $day['am_in'],
            'pm_out'        => $day['pm_out'],
            'break_count'   => 0,
            'approval'      => $pin_p ? 'pending' : null,
            'replayed'      => false,
            // What the area check found. null when the site has no area or
            // the punch carried no coordinates — the app shows nothing then.
            'geofence'      => geofence_payload($fence),
            'distance_m'    => $measured['distance_m'] ?? null,
            'outside_geofence' => $measured['outside'] ?? null,
        ]);
        if ($selfie_error !== null) $data['selfie_error'] = $selfie_error;

        $this->finishPunch($row_id, [
            'punch'         => $punch,
            'dtr_date'      => $dup ? null : $dtr_date,
            'dtr_detail_id' => $detail_id,
            'duplicate'     => $dup ? 1 : 0,
            'approval'      => $pin_p ? 'pending' : null,
            'authorized_by' => $entry_type === 'manual' && $authorized_by !== '' ? $authorized_by : null,
            'site_id'       => $site_id ?: null,
            'device_id'     => $device_id,
            'selfie'        => $selfie,
            'location'      => $location,
            'distance_m'    => $measured['distance_m'] ?? null,
            'outside'       => $measured['outside'] ?? null,
            'source'        => 'kiosk',
            'response'      => json_encode($data),
        ]);

        $this->touchDevice($device_id, $site_id);

        return $this->ok($data, $dup ? 200 : 201);
    }

    /** Answer a repeated punch_ref with what the first request recorded. */
    protected function replay(array $seen, array $emp)
    {
        if ((int) $seen['employee_id'] !== (int) $emp['id']) {
            return $this->fail('This punch reference belongs to another employee', 409, ['code' => 'ref_conflict']);
        }
        if ($seen['punch'] === self::PUNCH_PENDING) {
            // Still being written by another request — retry, do not park.
            return $this->fail('This punch is still being recorded. Try again shortly.', 503);
        }

        $data = json_decode((string) $seen['response'], true);
        if (!is_array($data)) {
            return $this->fail('This punch reference has no day record', 409, ['code' => 'ref_conflict']);
        }

        $day = $this->dayState((int) $emp['id'], strtotime($seen['scan_time']));
        $data['state']         = $day['state'];
        $data['valid_actions'] = $this->validActions($day['state']);
        $data['selfie']        = $seen['selfie'];
        $data['selfie_url']    = $this->public_url($seen['selfie']);
        $data['approval']      = $seen['approval'];
        $data['replayed']      = true;

        return $this->ok($data, 200);
    }

    /**
     * Attach the photo to a punch that was uploaded without one (the queue
     * sends the punch first and the image after, so a slow link cannot hold
     * the punch hostage).
     */
    public function attachSelfie()
    {
        $punch_ref = self::cleanRef($this->input('punch_ref'));
        if ($punch_ref === null) return $this->invalid('punch_ref', 'The punch ref field is required.');

        $selfie_raw = (string) $this->input('selfie', '');
        if ($selfie_raw === '') return $this->invalid('selfie', 'The selfie field is required.');

        $seen = $this->punchByRef($punch_ref);
        if (!$seen) return $this->fail('Unknown punch reference', 404);

        if ($seen['selfie'] || in_array($seen['punch'], [self::PUNCH_NONE, self::PUNCH_PENDING], true)) {
            return $this->ok([
                'punch_ref'  => $punch_ref,
                'selfie'     => $seen['selfie'],
                'selfie_url' => $this->public_url($seen['selfie']),
                'attached'   => false,
            ]);
        }

        $binary = $this->decode_image($selfie_raw);
        if ($binary === null) return $this->fail('Selfie is not valid base64 image data', 422);

        $path = $this->store_selfie((int) $seen['employee_id'], $seen['scan_time'], $binary);
        if (!$path) return $this->fail('Could not store the selfie', 500);
        $this->tagSelfieSource($path, $seen['entry_type']);

        $stmt = $this->db->prepare("UPDATE kiosk_punches SET selfie = ? WHERE id = ?");
        $id   = (int) $seen['id'];
        $stmt->bind_param('si', $path, $id);
        $stmt->execute();

        return $this->ok([
            'punch_ref'  => $punch_ref,
            'selfie'     => $path,
            'selfie_url' => $this->public_url($path),
            'attached'   => true,
        ]);
    }

    /** null, or ['error' => …, 'field' => …], or the location columns. */
    protected function readLocation()
    {
        $lat = $this->input('latitude');
        $lng = $this->input('longitude');
        $has_lat = $lat !== null && $lat !== '';
        $has_lng = $lng !== null && $lng !== '';

        if (!$has_lat && !$has_lng) {
            return ['latitude' => null, 'longitude' => null, 'location_accuracy' => null, 'location_mocked' => null,
                    'offline' => $this->truthy($this->input('offline')) ? 1 : 0];
        }
        if (!$has_lat) return ['error' => 'The latitude field is required when longitude is present.', 'field' => 'latitude'];
        if (!$has_lng) return ['error' => 'The longitude field is required when latitude is present.', 'field' => 'longitude'];
        if (!is_numeric($lat) || $lat < -90 || $lat > 90)    return ['error' => 'The latitude must be between -90 and 90.', 'field' => 'latitude'];
        if (!is_numeric($lng) || $lng < -180 || $lng > 180)  return ['error' => 'The longitude must be between -180 and 180.', 'field' => 'longitude'];

        $acc = $this->input('location_accuracy');
        $acc = ($acc !== null && $acc !== '' && is_numeric($acc) && $acc >= 0 && $acc <= 100000) ? (float) $acc : null;

        $mock = $this->input('location_mocked');
        $mock = ($mock === null || $mock === '') ? null : ($this->truthy($mock) ? 1 : 0);

        return [
            'latitude'          => (float) $lat,
            'longitude'         => (float) $lng,
            'location_accuracy' => $acc,
            'location_mocked'   => $mock,
            'offline'           => $this->truthy($this->input('offline')) ? 1 : 0,
        ];
    }

    protected function truthy($v)
    {
        if (is_bool($v)) return $v;
        $s = strtolower(trim((string) $v));
        return $s !== '' && $s !== '0' && $s !== 'false' && $s !== 'no';
    }

    /**
     * Insert the reservation row. Returns its id, 'taken' when the punch_ref
     * already exists, or false on any other failure. Handles both mysqli
     * error modes (return codes on 7.4, exceptions on 8.1+).
     */
    protected function reservePunch($employee_id, $punch_ref, $scan_time, $entry_type)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO kiosk_punches (employee_id, punch_ref, scan_time, entry_type, punch)
                VALUES (?, ?, ?, ?, 'PENDING')
            ");
            $stmt->bind_param('isss', $employee_id, $punch_ref, $scan_time, $entry_type);
            if ($stmt->execute()) return (int) $this->db->insert_id;
            return (int) $stmt->errno === 1062 ? 'taken' : false;
        } catch (\mysqli_sql_exception $e) {
            return (int) $e->getCode() === 1062 ? 'taken' : false;
        }
    }

    protected function finishPunch($row_id, array $f)
    {
        $loc = is_array($f['location']) ? $f['location'] : [];
        $lat = $loc['latitude'] ?? null;
        $lng = $loc['longitude'] ?? null;
        $acc = $loc['location_accuracy'] ?? null;
        $mck = $loc['location_mocked'] ?? null;
        $off = (int) ($loc['offline'] ?? 0);

        // Phase C columns. Measured once in punch() and passed through, so a
        // row can never disagree with the answer the device was given.
        // Written only when the columns exist, so this file keeps working on
        // an install where 2026_11_kiosk_geofence.sql has not run yet.
        $dist = $f['distance_m']  ?? null;
        $outs = isset($f['outside']) && $f['outside'] !== null ? (int) $f['outside'] : null;
        $src  = $f['source'] ?? 'kiosk';

        if (self::punchHasGeofenceColumns($this->db)) {
            $stmt = $this->db->prepare("
                UPDATE kiosk_punches SET
                    punch = ?, dtr_date = ?, dtr_detail_id = ?, duplicate = ?, approval = ?,
                    authorized_by = ?, site_id = ?, device_id = ?, selfie = ?,
                    latitude = ?, longitude = ?, location_accuracy = ?, location_mocked = ?, offline = ?,
                    distance_m = ?, outside_geofence = ?, source = ?,
                    response = ?
                WHERE id = ?
            ");
            $stmt->bind_param(
                'ssiississdddiiiissi',
                $f['punch'], $f['dtr_date'], $f['dtr_detail_id'], $f['duplicate'], $f['approval'],
                $f['authorized_by'], $f['site_id'], $f['device_id'], $f['selfie'],
                $lat, $lng, $acc, $mck, $off,
                $dist, $outs, $src,
                $f['response'], $row_id
            );
            $stmt->execute();
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE kiosk_punches SET
                punch = ?, dtr_date = ?, dtr_detail_id = ?, duplicate = ?, approval = ?,
                authorized_by = ?, site_id = ?, device_id = ?, selfie = ?,
                latitude = ?, longitude = ?, location_accuracy = ?, location_mocked = ?, offline = ?,
                response = ?
            WHERE id = ?
        ");
        $stmt->bind_param(
            'ssiississdddiisi',
            $f['punch'], $f['dtr_date'], $f['dtr_detail_id'], $f['duplicate'], $f['approval'],
            $f['authorized_by'], $f['site_id'], $f['device_id'], $f['selfie'],
            $lat, $lng, $acc, $mck, $off,
            $f['response'], $row_id
        );
        $stmt->execute();
    }

    /** Has 2026_11_kiosk_geofence.sql been applied? Asked once per request. */
    protected static function punchHasGeofenceColumns(mysqli $db): bool
    {
        static $has = null;
        if ($has === null) {
            $c = $db->query("SHOW COLUMNS FROM kiosk_punches LIKE 'outside_geofence'");
            $has = (bool) ($c && $c->num_rows);
        }
        return $has;
    }

    protected function deletePunchRow($row_id)
    {
        $stmt = $this->db->prepare("DELETE FROM kiosk_punches WHERE id = ? AND punch = 'PENDING'");
        $stmt->bind_param('i', $row_id);
        $stmt->execute();
    }

    protected function punchByRef($punch_ref)
    {
        if ($punch_ref === null) return null;
        $stmt = $this->db->prepare("SELECT * FROM kiosk_punches WHERE punch_ref = ? LIMIT 1");
        $stmt->bind_param('s', $punch_ref);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    /** The day row the punch landed in (newest batch first). */
    protected function detailIdFor($employee_id, $date)
    {
        $stmt = $this->db->prepare("SELECT id FROM DTR_details WHERE employee_id = ? AND date_time = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('is', $employee_id, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? (int) $row['id'] : null;
    }

    /** store_selfie() files every photo as 'face'; say what it really was. */
    protected function tagSelfieSource($path, $entry_type)
    {
        if ($entry_type === 'bio') return;
        $stmt = $this->db->prepare("UPDATE biometric_kiosk_selfies SET source = ? WHERE filename = ?");
        $stmt->bind_param('ss', $entry_type, $path);
        $stmt->execute();
    }

    /**
     * Is this tablet shut out?
     *
     * Installing needs no approval: an unknown device is simply new and is
     * allowed through. Only a device an administrator has explicitly BLOCKED
     * — lost, stolen or retired — is refused, and `health` stays open so the
     * app can still tell "offline" from "not welcome".
     *
     * Returns the refusal, or null to carry on.
     */
    public function deviceRefusal($route)
    {
        if ($route === 'health' || $route === 'login') return null;

        $device_id = self::cleanDeviceId($this->input('device_id'));
        if ($device_id === null) return null;   // nothing to check it against

        $stmt = $this->db->prepare("SELECT status, note FROM kiosk_devices WHERE device_id = ? LIMIT 1");
        $stmt->bind_param('s', $device_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row || ($row['status'] ?? 'active') !== 'blocked') return null;

        $why = trim((string) ($row['note'] ?? ''));
        return $this->fail(
            'This kiosk (' . $device_id . ') has been blocked by an administrator.'
                . ($why !== '' ? ' Reason: ' . rtrim($why, '.') . '.' : '')
                . ' Ask them to unblock it on the Kiosk Devices page.',
            403,
            ['code' => 'device_blocked', 'device_id' => $device_id]
        );
    }

    /**
     * Note which operator this tablet is being used by.
     *
     * The bearer token is shared by every kiosk, so a punch cannot say who is
     * signed in. An ENROLLMENT can: the app sends `operator_user_id` with a
     * fingerprint or face it stores, precisely so the audit row can name the
     * person months later. That is the one honest moment to learn it, so the
     * Kiosk Devices page shows who was last working on each tablet instead of
     * guessing from an IP address.
     */
    protected function rememberOperator()
    {
        $device_id = self::cleanDeviceId($this->input('device_id'));
        $user_id   = (int) $this->input('operator_user_id', 0);
        if ($device_id === null || $user_id <= 0) return;

        $stmt = $this->db->prepare("
            INSERT INTO kiosk_devices (device_id, operator_user_id, last_seen_at) VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE operator_user_id = VALUES(operator_user_id), last_seen_at = NOW()
        ");
        $stmt->bind_param('si', $device_id, $user_id);
        $stmt->execute();
    }

    /** Last-seen bookkeeping on any punch; the heartbeat does the rest. */
    protected function touchDevice($device_id, $site_id)
    {
        if ($device_id === null) return;
        $ip   = mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $site = $site_id ?: null;
        $stmt = $this->db->prepare("
            INSERT INTO kiosk_devices (device_id, site_id, last_seen_at, ip) VALUES (?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE last_seen_at = NOW(), ip = VALUES(ip),
                site_id = COALESCE(VALUES(site_id), site_id)
        ");
        $stmt->bind_param('sis', $device_id, $site, $ip);
        $stmt->execute();
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Fingerprint templates + faces (delegated to BiometricKiosk)
     * ──────────────────────────────────────────────────────────────────── */

    public function fingerprints()      { return $this->wrap($this->get_templates()); }
    public function storeFingerprint()  { $this->rememberOperator(); return $this->wrap($this->save_template(), 201); }
    public function fingerStatus()      { return $this->wrap($this->get_finger_status()); }
    public function deleteFingerprint() { return $this->wrap($this->delete_template()); }
    public function faces()             { return $this->wrap($this->get_faces()); }
    public function deleteFace()        { return $this->wrap($this->delete_face()); }

    /**
     * Store a face shot — after making sure it is not somebody else's face.
     * One person enrolled under two employees would let either punch for the
     * other, so a near-identical vector on another active employee is a 409.
     */
    public function storeFace()
    {
        $emp_id = (int) $this->input('employee_id', 0);
        if ($emp_id <= 0) return $this->invalid('employee_id', 'The employee id field is required.');

        $embedding = $this->normalize_embedding($this->input('embedding'));
        if ($embedding === null || count($embedding) < 16) {
            return $this->invalid('embedding', 'The embedding must be an array of at least 16 numbers.');
        }

        $this->rememberOperator();

        $model = trim((string) $this->input('model', '')) ?: self::FACE_MODEL_DEFAULT;
        $dupe  = $this->nearestOtherEmployee($emp_id, $model, $embedding);
        if ($dupe) {
            return $this->fail(
                'This face is already enrolled for ' . $dupe['employee_name'],
                409,
                ['code' => 'duplicate_face'] + $dupe
            );
        }

        return $this->wrap($this->save_face(), 201);
    }

    protected function nearestOtherEmployee($employee_id, $model, array $probe)
    {
        $dims = count($probe);
        $stmt = $this->db->prepare("
            SELECT f.employee_id, f.embedding, e.firstname, e.middlename, e.lastname, e.ext
            FROM biometric_kiosk_faces f
            INNER JOIN employee e ON e.id = f.employee_id AND e.status = 1
            WHERE f.model = ? AND f.dimensions = ? AND f.employee_id <> ?
        ");
        $stmt->bind_param('sii', $model, $dims, $employee_id);
        $stmt->execute();
        $res = $stmt->get_result();

        $best = null;
        while ($row = $res->fetch_assoc()) {
            $stored = json_decode((string) $row['embedding'], true);
            if (!is_array($stored)) continue;
            $score = self::cosine($probe, $stored);
            if ($score >= self::FACE_DUPLICATE_THRESHOLD && ($best === null || $score > $best['score'])) {
                $best = [
                    'employee_id'   => (int) $row['employee_id'],
                    'employee_name' => $this->fullName($row),
                    'score'         => round($score, 4),
                ];
            }
        }
        return $best;
    }

    protected static function cosine(array $a, array $b)
    {
        $n = count($a);
        if ($n === 0 || $n !== count($b)) return 0.0;
        $dot = $na = $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $na  += $x * $x;
            $nb  += $y * $y;
        }
        return ($na <= 0.0 || $nb <= 0.0) ? 0.0 : $dot / (sqrt($na) * sqrt($nb));
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Device health
     * ──────────────────────────────────────────────────────────────────── */

    /** "I am alive": queue depths, version, hardware state. */
    public function heartbeat()
    {
        $device_id = self::cleanDeviceId($this->input('device_id'));
        if ($device_id === null) return $this->fail('Missing or invalid device_id', 422);

        $short = function ($v, $max) {
            $v = trim((string) $v);
            return $v === '' ? null : mb_substr($v, 0, $max);
        };

        $last_sync = null;
        $raw_sync  = trim((string) $this->input('last_sync_at', ''));
        if ($raw_sync !== '' && ($t = strtotime($raw_sync)) !== false) $last_sync = date('Y-m-d H:i:s', $t);

        $site_id  = (int) $this->input('site_id', 0) ?: null;
        $name     = $short($this->input('name'), 80);
        $queue    = max(0, (int) $this->input('queue_depth', 0));
        $rejected = max(0, (int) $this->input('rejected_depth', 0));
        $version  = $short($this->input('app_version'), 32);
        $reader   = $short($this->input('reader'), 32);
        $camera   = $short($this->input('camera'), 32);
        $ip       = $short($_SERVER['REMOTE_ADDR'] ?? '', 45);

        $stmt = $this->db->prepare("
            INSERT INTO kiosk_devices
                (device_id, name, site_id, last_seen_at, last_sync_at, queue_depth, rejected_depth, app_version, reader, camera, ip)
            VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                name = COALESCE(VALUES(name), name),
                site_id = VALUES(site_id),
                last_seen_at = NOW(),
                last_sync_at = COALESCE(VALUES(last_sync_at), last_sync_at),
                queue_depth = VALUES(queue_depth),
                rejected_depth = VALUES(rejected_depth),
                app_version = VALUES(app_version),
                reader = VALUES(reader),
                camera = VALUES(camera),
                ip = VALUES(ip)
        ");
        $stmt->bind_param('ssisiissss', $device_id, $name, $site_id, $last_sync, $queue, $rejected, $version, $reader, $camera, $ip);
        if (!$stmt->execute()) return $this->fail('Could not record the heartbeat', 500);

        $site = $site_id ? $this->activeSite($site_id) : null;

        // The heartbeat is how a kiosk learns that the area changed: the app
        // stores what comes back (KioskGeofence.remember) and uses it for the
        // warning it shows before posting.
        $fence = $site ? geofence_payload(site_geofence($this->db, (int) $site['id'])) : null;

        return $this->ok([
            'device_id'   => $device_id,
            'server_time' => date('Y-m-d H:i:s'),
            'geofence'    => $fence,
            // has_area stays true whether or not an area is set — see
            // branchesForOperator() above for why.
            'site'        => $site ? ['id' => (string) $site['id'], 'name' => $site['site_name'],
                                      'has_area' => true] : null,
        ]);
    }

    /** A batch of recognition attempts (hit or miss) for the health report. */
    public function recognitionEvents()
    {
        $events = $this->input('events');
        if (!is_array($events) || $events === []) return $this->fail('Missing events', 422);

        $device_id = self::cleanDeviceId($this->input('device_id'));
        $accepted  = array_slice($events, 0, self::MAX_RECOGNITION_EVENTS);
        $skipped   = count($events) - count($accepted);

        $rows = [];
        foreach ($accepted as $event) {
            $row = $this->normaliseRecognitionEvent($event);
            if ($row === null) { $skipped++; continue; }
            $rows[] = $row;
        }

        if ($rows) {
            $stmt = $this->db->prepare("
                INSERT INTO kiosk_recognition_events (device_id, employee_id, method, outcome, score, threshold, occurred_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $this->db->begin_transaction();
            foreach ($rows as $r) {
                $stmt->bind_param('sissdds', $device_id, $r['employee_id'], $r['method'], $r['outcome'], $r['score'], $r['threshold'], $r['occurred_at']);
                $stmt->execute();
            }
            $this->db->commit();
        }

        return $this->ok(['stored' => count($rows), 'skipped' => $skipped]);
    }

    protected function normaliseRecognitionEvent($event)
    {
        if (!is_array($event)) return null;

        $method  = (string) ($event['method'] ?? '');
        $outcome = (string) ($event['outcome'] ?? '');
        if (!in_array($method, ['face', 'finger'], true)
            || !in_array($outcome, ['match', 'nomatch', 'ambiguous', 'quality', 'liveness'], true)) {
            return null;
        }

        $at = strtotime(trim((string) ($event['at'] ?? '')));
        if ($at === false) return null;

        $emp = trim((string) ($event['employee_id'] ?? ''));

        return [
            'employee_id' => ctype_digit($emp) ? (int) $emp : null,
            'method'      => $method,
            'outcome'     => $outcome,
            'score'       => is_numeric($event['score'] ?? null) ? round((float) $event['score'], 4) : null,
            'threshold'   => is_numeric($event['threshold'] ?? null) ? round((float) $event['threshold'], 4) : null,
            'occurred_at' => date('Y-m-d H:i:s', $at),
        ];
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Shared
     * ──────────────────────────────────────────────────────────────────── */

    protected function findEmployee($employee_id)
    {
        $stmt = $this->db->prepare("SELECT id, employee_no, firstname, middlename, lastname, ext, status, kiosk_pin FROM employee WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $employee_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    protected function fullName(array $row)
    {
        return trim(implode(' ', array_filter([
            $row['firstname'] ?? '', $row['middlename'] ?? '', $row['lastname'] ?? '', $row['ext'] ?? '',
        ], function ($p) { return trim((string) $p) !== ''; })));
    }

    /**
     * Unix time of the scan, now when none was sent, false when unreadable.
     * The app sends `Y-m-d H:i:s` in the kiosk's local (Manila) time.
     */
    protected function parseScanTime($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') return time();
        return strtotime($raw);
    }

    protected static function cleanRef($raw)
    {
        $raw = trim((string) $raw);
        return preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $raw) ? $raw : null;
    }

    protected static function cleanDeviceId($raw)
    {
        $raw = trim((string) $raw);
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $raw) ? $raw : null;
    }
}
