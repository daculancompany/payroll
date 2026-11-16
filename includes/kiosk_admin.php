<?php
/**
 * Web side of the UareUApp kiosk — kept out of admin_class.php on purpose.
 *
 *   set_pin / clear_pin       Employee Details → "Kiosk PIN"     (whoever can edit employee records)
 *   approve / reject          PIN Attendance page                (Admin)
 *
 * A PIN punch is written to the DTR the moment the kiosk sends it (see
 * kiosk_api_class.php) and parked in kiosk_punches as approval='pending'.
 * Approving only records the review. Rejecting takes the punch back out of
 * DTR_details.logs and recomputes the day, the same way the DTR punch editor
 * (Action::save_dtr_punches) re-derives a day after an edit.
 *
 * Called from ajax.php, which has already enforced the session, CSRF token and
 * ACTION_PAGE_MAP gate. The role checks here are a second, explicit line.
 */

// site_geofence() / geofence_measure() / geofence_modes(): the site
// attendance area, shared with the API side.
require_once __DIR__ . '/kiosk_geofence.php';

/**
 * Kiosk facts about punches in a date range, keyed "employeeId|Y-m-d H:i:s".
 *
 * A punch itself lives in DTR_details.logs (see dtr_punch_feed) and carries
 * nothing but a timestamp and a type. Everything the kiosk knows ON TOP of
 * that — the photo taken at the moment of the punch, whether a PIN punch is
 * still awaiting review, how far from the site it was made — lives in
 * kiosk_punches. Screens that list punches (Punch Logs, the portal's My
 * Scans) join the two here rather than each writing its own query.
 *
 * Punches made by the older kiosk API have no kiosk_punches row, so their
 * photo is recovered from biometric_kiosk_selfies.
 *
 * @return array<string, array{photo:?string, entry_type:string, approval:?string,
 *                             outside:bool, distance_m:?int, source:?string,
 *                             method:?string}>  method: 'face'|'finger'|null
 */
if (!function_exists('kiosk_punch_meta')) {
    function kiosk_punch_meta(mysqli $db, string $from, string $to, int $employee_id = 0): array
    {
        static $cols = null;
        if ($cols === null) {
            $cols = [];
            $q = $db->query("SHOW TABLES LIKE 'kiosk_punches'");
            if ($q && $q->num_rows) {
                $c = $db->query("SHOW COLUMNS FROM kiosk_punches");
                while ($c && ($r = $c->fetch_assoc())) $cols[$r['Field']] = true;
            }
        }

        $from = date('Y-m-d', strtotime($from)) . ' 00:00:00';
        $to   = date('Y-m-d', strtotime($to)) . ' 23:59:59';
        $out  = [];

        // Photos from the older kiosk API first, so a kiosk_punches row for
        // the same moment overwrites them with the richer record below.
        $sql = "SELECT employee_id, log_datetime, filename FROM biometric_kiosk_selfies
                WHERE log_datetime BETWEEN ? AND ?" . ($employee_id ? " AND employee_id = ?" : "");
        $st = $db->prepare($sql);
        if ($employee_id) $st->bind_param('ssi', $from, $to, $employee_id);
        else              $st->bind_param('ss', $from, $to);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $out[$r['employee_id'] . '|' . $r['log_datetime']] = [
                'photo' => $r['filename'], 'entry_type' => 'bio', 'approval' => null,
                'outside' => false, 'distance_m' => null, 'source' => null,
                // A selfie exists only for a face scan — the kiosk takes no
                // photo for a fingerprint (UareUApp src/services/facePunch.ts).
                'method' => 'face',
            ];
        }

        if (!$cols) return $out;

        // Phase C adds the location columns; ask only for what exists so this
        // works before and after that migration.
        $extra = '';
        foreach (['distance_m', 'outside_geofence', 'source'] as $c) {
            if (isset($cols[$c])) $extra .= ", kp.$c";
        }
        $sql = "SELECT kp.employee_id, kp.scan_time, kp.selfie, kp.entry_type, kp.approval,
                       kp.latitude, kp.longitude, kp.location_accuracy, kp.location_mocked$extra
                FROM kiosk_punches kp
                WHERE kp.punch IN ('TIME_IN','TIME_OUT') AND kp.scan_time BETWEEN ? AND ?"
             . ($employee_id ? " AND kp.employee_id = ?" : "");
        $st = $db->prepare($sql);
        if ($employee_id) $st->bind_param('ssi', $from, $to, $employee_id);
        else              $st->bind_param('ss', $from, $to);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $key   = $r['employee_id'] . '|' . $r['scan_time'];
            $photo = $r['selfie'] ?: ($out[$key]['photo'] ?? null);
            $out[$key] = [
                'photo'      => $photo,
                'entry_type' => $r['entry_type'],
                'approval'   => $r['approval'],
                'outside'    => !empty($r['outside_geofence']),
                'distance_m' => isset($r['distance_m']) && $r['distance_m'] !== null ? (int) $r['distance_m'] : null,
                'source'     => $r['source'] ?? null,
                // Face is certain when a photo came with the punch. Without
                // one it is NOT safe to call it a fingerprint: an offline
                // punch posts the photo in a second step (attachSelfie), so a
                // face scan looks photoless until the queue drains. Left null
                // here and settled below from the recognition feed, which is
                // positive evidence either way.
                'method'     => $photo ? 'face' : null,
                // Where the punch was made. The kiosk sends these on every
                // punch when the tablet grants it; they are null when
                // location is off or the punch came from the desktop reader.
                // 'mocked' is the device saying the fix came from a fake-GPS
                // app — worth showing, never worth acting on by itself.
                'lat'        => $r['latitude']  !== null ? (float) $r['latitude']  : null,
                'lng'        => $r['longitude'] !== null ? (float) $r['longitude'] : null,
                'accuracy'   => $r['location_accuracy'] !== null ? (float) $r['location_accuracy'] : null,
                'mocked'     => $r['location_mocked'] !== null ? (bool) $r['location_mocked'] : null,
            ];
        }

        // Recognition attempts the kiosk reports for its health screen. They
        // carry the one thing the punch itself never does — face or finger —
        // so a successful match within two minutes of a punch names its
        // method. Matching on time is safe at this width: one person cannot
        // match twice in that window and still punch twice.
        $need = [];
        foreach ($out as $k => $m) if (empty($m['method'])) $need[$k] = true;
        if ($need && ($q = $db->query("SHOW TABLES LIKE 'kiosk_recognition_events'")) && $q->num_rows) {
            $sql = "SELECT employee_id, method, occurred_at FROM kiosk_recognition_events
                    WHERE outcome = 'match' AND employee_id IS NOT NULL
                      AND occurred_at BETWEEN ? AND ?" . ($employee_id ? " AND employee_id = ?" : "");
            // Two minutes either side of the range, so a punch at a boundary
            // can still find the match that produced it.
            $ev_from = date('Y-m-d H:i:s', strtotime($from) - 120);
            $ev_to   = date('Y-m-d H:i:s', strtotime($to) + 120);
            $st = $db->prepare($sql);
            if ($employee_id) $st->bind_param('ssi', $ev_from, $ev_to, $employee_id);
            else              $st->bind_param('ss', $ev_from, $ev_to);
            $st->execute();
            $res = $st->get_result();
            $events = [];
            while ($res && ($r = $res->fetch_assoc())) {
                $events[(int) $r['employee_id']][] = [strtotime($r['occurred_at']), $r['method']];
            }
            foreach ($need as $k => $_) {
                [$eid, $when] = explode('|', $k, 2);
                $list = $events[(int) $eid] ?? [];
                $best = null; $bestGap = 121;
                foreach ($list as [$ts, $method]) {
                    $gap = abs($ts - strtotime($when));
                    if ($gap <= 120 && $gap < $bestGap) { $bestGap = $gap; $best = $method; }
                }
                if ($best !== null) $out[$k]['method'] = $best;
            }
        }

        return $out;
    }
}

/**
 * How a punch was made, as a chip: {label, icon, class, tip}.
 *
 * One definition for every screen that lists punches (Punch Logs, Attendance,
 * the portal) so the same punch never reads one way on one page and another
 * way on the next.
 *
 * $type is what the DTR log entry says — 'bio', 'manual', 'pin' or
 * 'incident'. $meta is that punch's kiosk_punch_meta_for() row, if any; its
 * 'method' narrows a plain biometric punch to face or fingerprint. When
 * nothing says which, the chip stays "BIO": vague is right, a guess is not.
 */
if (!function_exists('punch_source_chip')) {
    function punch_source_chip(?string $type, ?array $meta = null): array
    {
        $type   = strtolower(trim((string) $type)) ?: 'bio';
        $method = $meta['method'] ?? null;

        if ($type === 'bio' && $method === 'face') {
            return ['label' => 'FACE', 'icon' => 'ri-user-smile-line', 'class' => 'face',
                    'tip' => 'Face recognition'];
        }
        if ($type === 'bio' && $method === 'finger') {
            return ['label' => 'FINGER', 'icon' => 'ri-fingerprint-line', 'class' => 'finger',
                    'tip' => 'Fingerprint scan'];
        }
        switch ($type) {
            case 'pin':
                $tip = 'PIN typed on the kiosk';
                if (!empty($meta['approval'])) $tip .= ' — ' . $meta['approval'];
                return ['label' => 'PIN', 'icon' => 'ri-lock-password-line', 'class' => 'pin', 'tip' => $tip];
            case 'manual':
                return ['label' => 'MANUAL', 'icon' => 'ri-edit-box-line', 'class' => 'manual',
                        'tip' => 'Entered by hand, not scanned'];
            case 'incident':
                return ['label' => 'INCIDENT', 'icon' => 'ri-error-warning-line', 'class' => 'incident',
                        'tip' => 'Filed from an incident report'];
            default:
                return ['label' => 'BIO', 'icon' => 'ri-scan-line', 'class' => 'bio',
                        'tip' => 'Biometric scan'];
        }
    }
}

/**
 * Where a punch was made, as a chip — or '' when nothing was recorded.
 *
 * Three states, and the difference matters:
 *   plain pin   coordinates on file, and either no attendance area is set
 *               for the site or the punch was inside it
 *   warning     outside the site's attendance area (needs the geofence —
 *               kiosk Phase C — before it can ever be true)
 *   danger      the device reported a faked GPS fix
 *
 * The tooltip carries the coordinates and the accuracy. It deliberately does
 * NOT link out to a map: that would hand an employee's location to a third
 * party on page load. Copy the coordinates from the tooltip instead, or say
 * the word and I will add an opt-in map link.
 */
if (!function_exists('punch_location_html')) {
    function punch_location_html(?array $meta): string
    {
        if (!$meta || $meta['lat'] === null || $meta['lng'] === null) return '';

        $coords = number_format($meta['lat'], 6) . ', ' . number_format($meta['lng'], 6);
        $tip    = $coords;
        if (!empty($meta['accuracy'])) $tip .= ' (±' . (int) round($meta['accuracy']) . ' m)';

        $cls = 'geo';
        if (!empty($meta['mocked'])) {
            $cls = 'geo-fake';
            $tip = 'Device reported a FAKED GPS fix — ' . $tip;
        } elseif (!empty($meta['outside'])) {
            $cls = 'geo-out';
            $tip = 'Outside the site attendance area'
                 . ($meta['distance_m'] !== null ? ' by ' . (int) $meta['distance_m'] . ' m' : '')
                 . ' — ' . $tip;
        }

        return '<span class="pl-src ' . $cls . ' pl-src-ico"'
             . ' data-tip="' . htmlspecialchars($tip, ENT_QUOTES) . '"'
             . ' aria-label="' . htmlspecialchars($tip, ENT_QUOTES) . '">'
             . '<i class="' . (!empty($meta['mocked']) ? 'ri-spam-2-line' : 'ri-map-pin-line') . '"></i>'
             . '</span>';
    }
}

/** The chip as markup: icon only by default, label too when $withLabel. */
if (!function_exists('punch_source_html')) {
    function punch_source_html(?string $type, ?array $meta = null, bool $withLabel = false): string
    {
        $c = punch_source_chip($type, $meta);
        // data-tip, not title: assets2/js/app-tooltip.js is loaded on every
        // screen and renders these itself, with no per-page wiring and
        // nothing for a scrolling table to clip.
        return '<span class="pl-src ' . $c['class'] . ($withLabel ? '' : ' pl-src-ico') . '"'
             . ' data-tip="' . htmlspecialchars($c['tip'], ENT_QUOTES) . '"'
             . ' aria-label="' . htmlspecialchars($c['tip'], ENT_QUOTES) . '">'
             . '<i class="' . $c['icon'] . '"></i>'
             . ($withLabel ? '<span>' . $c['label'] . '</span>' : '')
             . '</span>';
    }
}

/** A punch's kiosk facts, or null. $punched_at is "Y-m-d H:i:s". */
if (!function_exists('kiosk_punch_meta_for')) {
    function kiosk_punch_meta_for(array $meta, $employee_id, string $punched_at): ?array
    {
        return $meta[((int) $employee_id) . '|' . $punched_at] ?? null;
    }
}

class KioskAdmin
{
    /** @var mysqli */
    private $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    private function role()
    {
        return (int) ($_SESSION['login_role'] ?? 0);
    }

    private function fail($message)
    {
        return ['result' => false, 'message' => $message];
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Kiosk PIN
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Same answer as every other write on Employee Details — can_edit() is
     * what ajax.php enforces for set_kiosk_pin / clear_kiosk_pin, and roles
     * that only read employee records (HR, Timekeeper, Auditor) get no PIN
     * buttons there either.
     */
    private function canManagePins()
    {
        // Admin and HR. HR is read-only on the rest of Employee Details —
        // that screen is about PAY — but handing an employee their kiosk PIN
        // when the tablet will not recognise their face is HR's own job, and
        // routing it through an administrator every time helps nobody.
        return in_array($this->role(), [1, ROLE_HR], true);
    }

    /**
     * Set an employee's 6-digit kiosk PIN. POST: employee_id and either
     * pin=<6 digits> or generate=1. A PIN can belong to one employee only —
     * the kiosk treats a shared PIN as unknown, so it would just stop working.
     */
    public function set_pin()
    {
        if (!$this->canManagePins()) return $this->fail('Your role cannot change kiosk PINs.');

        $emp = (int) ($_POST['employee_id'] ?? 0);
        if (!$emp || !$this->employeeExists($emp)) return $this->fail('Employee not found.');

        if (!empty($_POST['generate'])) {
            $pin = $this->unusedPin($emp);
            if ($pin === null) return $this->fail('Could not find a free PIN. Try again.');
        } else {
            $pin = trim((string) ($_POST['pin'] ?? ''));
            if (!preg_match('/^\d{6}$/', $pin)) return $this->fail('The PIN must be exactly 6 digits.');
            if ($this->isWeak($pin)) return $this->fail('That PIN is too easy to guess. Pick another, or use Generate.');
            if ($this->pinTaken($pin, $emp)) return $this->fail('Another employee already uses that PIN. Pick another, or use Generate.');
        }

        $stmt = $this->db->prepare("UPDATE employee SET kiosk_pin = ? WHERE id = ?");
        $stmt->bind_param('si', $pin, $emp);
        try {
            $ok = $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            $ok = false;
        }
        if (!$ok) return $this->fail('Another employee already uses that PIN. Pick another, or use Generate.');

        return [
            'result'  => true,
            'pin'     => $pin,
            'message' => 'Kiosk PIN saved. The kiosk picks it up on its next employee sync.',
        ];
    }

    public function clear_pin()
    {
        if (!$this->canManagePins()) return $this->fail('Your role cannot change kiosk PINs.');

        $emp = (int) ($_POST['employee_id'] ?? 0);
        if (!$emp) return $this->fail('Employee not found.');

        $stmt = $this->db->prepare("UPDATE employee SET kiosk_pin = NULL WHERE id = ?");
        $stmt->bind_param('i', $emp);
        $stmt->execute();

        return ['result' => true, 'message' => 'Kiosk PIN removed.'];
    }

    private function employeeExists($emp)
    {
        $stmt = $this->db->prepare("SELECT id FROM employee WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $emp);
        $stmt->execute();
        return (bool) $stmt->get_result()->fetch_assoc();
    }

    private function pinTaken($pin, $except_emp)
    {
        $stmt = $this->db->prepare("SELECT id FROM employee WHERE kiosk_pin = ? AND id <> ? LIMIT 1");
        $stmt->bind_param('si', $pin, $except_emp);
        $stmt->execute();
        return (bool) $stmt->get_result()->fetch_assoc();
    }

    /** 111111, 123456, 654321 and friends — the first PINs anyone tries. */
    private function isWeak($pin)
    {
        if (preg_match('/^(\d)\1{5}$/', $pin)) return true;
        return strpos('0123456789', $pin) !== false || strpos('9876543210', $pin) !== false;
    }

    private function unusedPin($emp)
    {
        for ($i = 0; $i < 50; $i++) {
            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            if (!$this->isWeak($pin) && !$this->pinTaken($pin, $emp)) return $pin;
        }
        return null;
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Registered face
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Remove every face an employee has registered on the kiosk, and the
     * images behind them.
     *
     * Administrator only — the same bar Action::delete_employee_fingerprint
     * sets, and for the same reason: this destroys an enrollment the employee
     * can only replace by standing at the tablet again. Punches already
     * recorded are untouched; so are the photos taken AT those punches
     * (uploads/attendance), which are attendance evidence, not enrollment.
     */
    public function delete_employee_face()
    {
        if ($this->role() !== 1) return $this->fail('Only an Administrator may remove a registered face.');

        $emp = (int) ($_POST['employee_id'] ?? 0);
        if (!$emp) return $this->fail('Employee not found.');

        $model = trim((string) ($_POST['model'] ?? ''));
        if ($model !== '' && !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $model)) {
            return $this->fail('Unknown face model.');
        }

        // Collect the files first: once the rows are gone nothing points at
        // them and the folder would keep them for good.
        $sql = "SELECT photo FROM biometric_kiosk_faces WHERE employee_id = ? AND photo IS NOT NULL AND photo <> ''"
             . ($model !== '' ? " AND model = ?" : "");
        $find = $this->db->prepare($sql);
        if ($model !== '') $find->bind_param('is', $emp, $model);
        else               $find->bind_param('i', $emp);
        $find->execute();
        $res = $find->get_result();
        $files = [];
        while ($row = $res->fetch_assoc()) $files[] = $row['photo'];

        $del = $this->db->prepare("DELETE FROM biometric_kiosk_faces WHERE employee_id = ?"
            . ($model !== '' ? " AND model = ?" : ""));
        if ($model !== '') $del->bind_param('is', $emp, $model);
        else               $del->bind_param('i', $emp);
        if (!$del->execute()) return $this->fail('Failed to remove the face: ' . $del->error);
        $removed = $del->affected_rows;

        // Never follow a stored path out of the faces folder, whatever the
        // row says — same guard BiometricKiosk uses when it prunes slots.
        $root = realpath(__DIR__ . '/../uploads/faces');
        foreach ($files as $rel) {
            $abs = realpath(__DIR__ . '/../' . $rel);
            if ($abs && $root && strpos($abs, $root) === 0) @unlink($abs);
        }

        return [
            'result'  => true,
            'removed' => $removed,
            'message' => $removed === 0
                ? 'No registered face found.'
                : 'Face removed. The employee must register again on the kiosk.',
        ];
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Kiosk devices
     *
     * A tablet lists itself here the first time it reports in — installing
     * one needs no approval. These actions exist for afterwards: naming a
     * tablet so the list is readable, and shutting one out when it is lost,
     * stolen or retired.
     * ──────────────────────────────────────────────────────────────────── */

    /** The device_id shape the app mints (deviceIdentity.ts). */
    private function deviceIdParam()
    {
        $id = trim((string) ($_POST['device_id'] ?? ''));
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) ? $id : null;
    }

    public function rename_kiosk_device()
    {
        if (!$this->canReview()) return $this->fail('Only an administrator can manage kiosk devices.');

        $id = $this->deviceIdParam();
        if ($id === null) return $this->fail('Unknown device.');

        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80);
        $name = $name === '' ? null : $name;

        $stmt = $this->db->prepare("UPDATE kiosk_devices SET name = ? WHERE device_id = ?");
        $stmt->bind_param('ss', $name, $id);
        $stmt->execute();

        if ($stmt->affected_rows === 0 && !$this->deviceExists($id)) return $this->fail('Unknown device.');

        return ['result' => true, 'message' => $name === null ? 'Name cleared.' : 'Device renamed.'];
    }

    /**
     * Block or unblock one tablet. Blocking takes effect on its very next
     * call (kiosk_api_class.php::deviceRefusal) — punches already recorded
     * are untouched, and anything still queued ON the tablet stays there.
     */
    public function block_kiosk_device()
    {
        if (!$this->canReview()) return $this->fail('Only an administrator can manage kiosk devices.');

        $id = $this->deviceIdParam();
        if ($id === null || !$this->deviceExists($id)) return $this->fail('Unknown device.');

        $block = !empty($_POST['block']);
        $by    = $this->reviewer();
        $note  = $this->note();

        if ($block) {
            $stmt = $this->db->prepare("UPDATE kiosk_devices
                SET status = 'blocked', blocked_by = ?, blocked_at = NOW(), note = NULLIF(?, '')
                WHERE device_id = ?");
            $stmt->bind_param('iss', $by, $note, $id);
        } else {
            $stmt = $this->db->prepare("UPDATE kiosk_devices
                SET status = 'active', blocked_by = NULL, blocked_at = NULL, note = NULL
                WHERE device_id = ?");
            $stmt->bind_param('s', $id);
        }
        if (!$stmt->execute()) return $this->fail('Could not update the device.');

        return [
            'result'  => true,
            'message' => $block
                ? 'Kiosk blocked. It is refused from its next request.'
                : 'Kiosk unblocked. It works again straight away.',
        ];
    }

    /**
     * Forget a tablet that will never report again (wiped, scrapped). It is
     * only a monitoring row — punches it made stay exactly where they are.
     * A tablet still in use simply re-appears on its next heartbeat, which
     * is why a blocked one may not be forgotten: that would unblock it.
     */
    public function forget_kiosk_device()
    {
        if (!$this->canReview()) return $this->fail('Only an administrator can manage kiosk devices.');

        $id = $this->deviceIdParam();
        if ($id === null || !$this->deviceExists($id)) return $this->fail('Unknown device.');

        $stmt = $this->db->prepare("DELETE FROM kiosk_devices WHERE device_id = ? AND status <> 'blocked'");
        $stmt->bind_param('s', $id);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            return $this->fail('A blocked kiosk cannot be forgotten — unblock it first, or it would be let back in.');
        }

        return ['result' => true, 'message' => 'Device removed from the list. It returns if it reports in again.'];
    }

    private function deviceExists($id)
    {
        $stmt = $this->db->prepare("SELECT id FROM kiosk_devices WHERE device_id = ? LIMIT 1");
        $stmt->bind_param('s', $id);
        $stmt->execute();
        return (bool) $stmt->get_result()->fetch_assoc();
    }

    /* ─────────────────────────────────────────────────────────────────────
     * PIN punch review
     * ──────────────────────────────────────────────────────────────────── */

    private function canReview()
    {
        return in_array($this->role(), PAYROLL_DTR_ROLES, true);
    }

    private function reviewer()
    {
        return (int) ($_SESSION['login_id'] ?? 0) ?: null;
    }

    private function note()
    {
        return mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);
    }

    /**
     * Bell notification on the employee's portal, mirrored as a browser push.
     *
     * Action::notifyEmployee is private to that class, so this is the same
     * two steps written once here: the row is what the portal reads, the push
     * is best-effort and must never fail the action that triggered it.
     */
    private function notifyEmployee($employee_id, $title, $message, $icon = 'ri-notification-3-line', $color = 'warning', $link = 'employee-portal.php?tab=logs')
    {
        $employee_id = (int) $employee_id;
        if ($employee_id <= 0) return;

        $title   = mb_substr($title, 0, 150);
        $message = mb_substr($message, 0, 500);

        $stmt = $this->db->prepare("INSERT INTO notifications (user_id, recipient_type, title, message, icon, color, link)
                                    VALUES (?, 'employee', ?, ?, ?, ?, ?)");
        $stmt->bind_param('isssss', $employee_id, $title, $message, $icon, $color, $link);
        $stmt->execute();

        try {
            require_once __DIR__ . '/../fcm.php';
            fcm_push_employee($this->db, $employee_id, $title, $message, $link);
        } catch (\Throwable $e) { /* a push hiccup is not a failure */ }
    }

    /** POST: id, or ids[] / "1,2,3" for Approve selected. */
    public function approve_pin_punch()
    {
        if (!$this->canReview()) return $this->fail('Only an administrator can review PIN punches.');

        $raw = $_POST['ids'] ?? ($_POST['id'] ?? '');
        $ids = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return $this->fail('Nothing selected.');

        $by   = $this->reviewer();
        $note = $this->note();
        $stmt = $this->db->prepare("
            UPDATE kiosk_punches
            SET approval = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_note = NULLIF(?, '')
            WHERE id = ? AND entry_type = 'pin' AND approval = 'pending'
        ");

        $done = 0;
        foreach ($ids as $id) {
            $stmt->bind_param('isi', $by, $note, $id);
            $stmt->execute();
            $done += $stmt->affected_rows;
        }

        return [
            'result'   => true,
            'approved' => $done,
            'message'  => $done === 1 ? '1 PIN punch approved.' : $done . ' PIN punches approved.',
        ];
    }

    /**
     * Reject one PIN punch: remove its {dateTime, type:'pin'} entry from the
     * day it was filed under, recompute that day, and mark the punch rejected.
     * Refused once the DTR batch is final-approved — that day is in payroll.
     * The photo stays on file as evidence.
     */
    public function reject_pin_punch()
    {
        if (!$this->canReview()) return $this->fail('Only an administrator can review PIN punches.');

        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) return $this->fail('Nothing selected.');

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM kiosk_punches WHERE id = ? FOR UPDATE");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $kp = $stmt->get_result()->fetch_assoc();

            if (!$kp || $kp['entry_type'] !== 'pin') {
                $this->db->rollback();
                return $this->fail('PIN punch not found.');
            }
            if ($kp['approval'] !== 'pending') {
                $this->db->rollback();
                return $this->fail('This PIN punch was already ' . $kp['approval'] . '.');
            }

            $removed = false;
            if (in_array($kp['punch'], ['TIME_IN', 'TIME_OUT'], true) && $kp['dtr_detail_id']) {
                $removed = $this->removeFromDay((int) $kp['dtr_detail_id'], (int) $kp['employee_id'], $kp['scan_time']);
                if (is_string($removed)) {
                    $this->db->rollback();
                    return $this->fail($removed);
                }
            }

            $by   = $this->reviewer();
            $note = $this->note();
            $up = $this->db->prepare("
                UPDATE kiosk_punches
                SET approval = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_note = NULLIF(?, '')
                WHERE id = ?
            ");
            $up->bind_param('isi', $by, $note, $id);
            if (!$up->execute()) throw new Exception($up->error);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            return $this->fail('Reject failed — nothing was changed. ' . $e->getMessage());
        }

        // The punch has just disappeared from the employee's own scan list and
        // from their hours. Telling them is the difference between a decision
        // and a silent deletion. Best-effort: never fail an applied rejection
        // over a notification.
        if ($removed === true) {
            try {
                $this->notifyEmployee(
                    (int) $kp['employee_id'],
                    'PIN punch not accepted',
                    'Your PIN punch on ' . date('M d, Y \a\t g:i A', strtotime($kp['scan_time']))
                        . ' was reviewed and removed from your record'
                        . ($note !== '' ? ': ' . $note : '.')
                        . ' Please see your timekeeper if this is wrong.'
                );
            } catch (\Throwable $e) { /* ignore */ }
        }

        return [
            'result'  => true,
            'message' => $removed
                ? 'PIN punch rejected and removed from the DTR.'
                : 'PIN punch rejected. It was no longer on the DTR, so nothing else changed.',
        ];
    }

    /**
     * Take one PIN punch out of a DTR_details row and re-derive the row.
     * Returns true when removed, false when it was not there any more (someone
     * edited the day already), or an error message string.
     */
    private function removeFromDay($detail_id, $employee_id, $scan_time)
    {
        $stmt = $this->db->prepare("
            SELECT d.id, d.employee_id, d.date_time, d.logs, b.status AS batch_status
            FROM DTR_details d INNER JOIN DTR b ON b.id = d.ddtr_id
            WHERE d.id = ? FOR UPDATE
        ");
        $stmt->bind_param('i', $detail_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row || (int) $row['employee_id'] !== $employee_id) return false;
        if ((int) $row['batch_status'] === 2) {
            return 'This DTR batch is already final-approved and locked — reopen it in DTR Review before rejecting the punch.';
        }

        $target = strtotime($scan_time);
        $logs   = json_decode((string) $row['logs'], true) ?: [];
        $kept   = [];
        $found  = false;
        foreach ($logs as $lg) {
            if (!$found && ($lg['type'] ?? '') === 'pin' && strtotime($lg['dateTime'] ?? '') === $target) {
                $found = true;   // drop exactly one matching entry
                continue;
            }
            $kept[] = $lg;
        }
        if (!$found) return false;

        $ts     = [];
        $manual = false;
        foreach ($kept as $lg) {
            $t = strtotime($lg['dateTime'] ?? '');
            if ($t !== false) $ts[] = $t;
            if (($lg['type'] ?? '') !== 'bio') $manual = true;
        }

        $date  = date('Y-m-d', strtotime($row['date_time']));
        $c     = dtr_compute_day($this->db, $employee_id, $date, $ts);
        $json  = json_encode($kept);
        $atype = $manual ? 'manual' : 'biometric';

        // Same write as the DTR punch editor: an edited day goes back to
        // pending review, with any earlier decision cleared.
        $up = $this->db->prepare(
            "UPDATE DTR_details SET logs=?, work_hours=?, overtime=?, undertime=?, late=?, nsd_hours=?,
             day_type=?, is_complete=?, attendance_type=?,
             schedule_id=?, day_hours=?, is_rest_day=?, sched_start=?, sched_end=?, sched_break=?, sched_graveyard=?,
             status=0, decision_note=NULL, decided_by=NULL, decided_at=NULL WHERE id=?"
        );
        $up->bind_param(
            'sdddddsisidissiii',
            $json, $c['work_hours'], $c['overtime'], $c['undertime'], $c['late'], $c['nsd_hours'],
            $c['day_type'], $c['is_complete'], $atype,
            $c['schedule_id'], $c['day_hours'], $c['is_rest_day'],
            $c['sched_start'], $c['sched_end'], $c['sched_break'], $c['sched_graveyard'], $detail_id
        );
        if (!$up->execute()) throw new Exception($up->error);

        return true;
    }

    /**
     * Switch punching from an employee's own phone on or off (Phase D).
     *
     * Off for everyone until someone turns it on, one person at a time. A
     * phone punch has no timekeeper standing over it, so this is a decision
     * about a particular person, never a default — and turning it off kills
     * their live sessions on the spot rather than waiting for the token to
     * expire.
     *
     * Same gate as the kiosk PIN (Admin + HR), for the same reason: it is
     * part of setting someone up to use the app.
     */
    public function set_mobile_punch()
    {
        if (!$this->canManagePins()) return $this->fail('You do not have access to change this.');

        $employee_id = (int) ($_POST['employee_id'] ?? 0);
        if ($employee_id <= 0) return $this->fail('Unknown employee.');

        $col = $this->db->query("SHOW COLUMNS FROM employee LIKE 'mobile_punch_enabled'");
        if (!$col || !$col->num_rows) {
            return $this->fail('Run migrations/2026_11_kiosk_employee_api.sql first.');
        }

        $q = $this->db->prepare("SELECT firstname, lastname FROM employee WHERE id = ? LIMIT 1");
        $q->bind_param('i', $employee_id);
        $q->execute();
        $emp = $q->get_result()->fetch_assoc();
        if (!$emp) return $this->fail('Unknown employee.');

        $on = !empty($_POST['enabled']) && $_POST['enabled'] !== '0' ? 1 : 0;

        $up = $this->db->prepare("UPDATE employee SET mobile_punch_enabled = ? WHERE id = ?");
        $up->bind_param('ii', $on, $employee_id);
        if (!$up->execute()) return $this->fail('Could not save that.');

        $revoked = 0;
        if (!$on) {
            // Switching it off has to mean off NOW. A token already in the
            // employee's phone would otherwise keep working for its full 30
            // days, which is not what anyone means by "turn it off".
            $tok = $this->db->query("SHOW TABLES LIKE 'employee_api_tokens'");
            if ($tok && $tok->num_rows) {
                $rv = $this->db->prepare(
                    "UPDATE employee_api_tokens SET revoked_at = NOW(), revoked_by = ?
                     WHERE employee_id = ? AND revoked_at IS NULL"
                );
                $by = $this->reviewer();
                $rv->bind_param('ii', $by, $employee_id);
                $rv->execute();
                $revoked = $rv->affected_rows;
            }
        }

        $name = trim(($emp['firstname'] ?? '') . ' ' . ($emp['lastname'] ?? ''));
        $msg  = $on
            ? $name . ' can now punch from their own phone with the employee app.'
            : $name . ' can no longer punch from their own phone.';
        if ($revoked > 0) {
            $msg .= ' ' . $revoked . ' signed-in device' . ($revoked === 1 ? '' : 's') . ' signed out.';
        }

        return ['result' => true, 'message' => $msg, 'enabled' => $on];
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Site attendance area (Phase C)
     *
     * Deliberately NOT part of save_site() in admin_class.php: the site's own
     * editor is payroll's, this is the kiosk's, and the two should not have
     * to be changed together.
     * ──────────────────────────────────────────────────────────────────── */

    public function save_site_area()
    {
        if (!$this->canReview()) return $this->fail('Only an administrator can set a site attendance area.');

        $site_id = (int) ($_POST['site_id'] ?? 0);
        if ($site_id <= 0) return $this->fail('Pick a site first.');

        $chk = $this->db->prepare("SELECT site_name FROM sites WHERE id = ? LIMIT 1");
        $chk->bind_param('i', $site_id);
        $chk->execute();
        $site = $chk->get_result()->fetch_assoc();
        if (!$site) return $this->fail('Unknown site.');

        $mode = (string) ($_POST['geofence_mode'] ?? 'off');
        if (!array_key_exists($mode, geofence_modes())) return $this->fail('Unknown mode.');

        // "off" clears the circle outright rather than leaving stale numbers
        // behind that would spring back the next time someone picks a mode.
        if ($mode === 'off') {
            $up = $this->db->prepare(
                "UPDATE sites SET latitude = NULL, longitude = NULL, geofence_radius_m = NULL,
                        geofence_mode = 'off', geofence_note = NULL,
                        geofence_set_by = ?, geofence_set_at = NOW()
                 WHERE id = ?"
            );
            $by = $this->reviewer();
            $up->bind_param('ii', $by, $site_id);
            $up->execute();
            return ['result' => true, 'message' => 'Attendance area removed for ' . $site['site_name'] . '.'];
        }

        $lat    = trim((string) ($_POST['latitude'] ?? ''));
        $lng    = trim((string) ($_POST['longitude'] ?? ''));
        $radius = (int) ($_POST['geofence_radius_m'] ?? 0);
        $note   = mb_substr(trim((string) ($_POST['geofence_note'] ?? '')), 0, 255) ?: null;

        if ($lat === '' || !is_numeric($lat) || $lat < -90 || $lat > 90) {
            return $this->fail('Latitude must be a number between -90 and 90.');
        }
        if ($lng === '' || !is_numeric($lng) || $lng < -180 || $lng > 180) {
            return $this->fail('Longitude must be a number between -180 and 180.');
        }
        // A radius under 20 m is smaller than a phone's own margin of error,
        // so it would turn people away at the door it is meant to cover.
        if ($radius < 20)    return $this->fail('Radius must be at least 20 metres — a smaller circle is inside the GPS error.');
        if ($radius > 50000) return $this->fail('Radius must be 50 km or less.');

        $lat = (float) $lat;
        $lng = (float) $lng;
        $by  = $this->reviewer();

        $up = $this->db->prepare(
            "UPDATE sites SET latitude = ?, longitude = ?, geofence_radius_m = ?,
                    geofence_mode = ?, geofence_note = ?, geofence_set_by = ?, geofence_set_at = NOW()
             WHERE id = ?"
        );
        $up->bind_param('ddissii', $lat, $lng, $radius, $mode, $note, $by, $site_id);
        if (!$up->execute()) return $this->fail('Could not save the attendance area.');

        // How many punches already on file would have fallen outside this
        // circle. Said out loud at save time, because the number is the whole
        // argument for choosing 'flag' before 'strict'.
        $would = 0;
        $q = $this->db->prepare(
            "SELECT latitude, longitude, location_accuracy FROM kiosk_punches
             WHERE site_id = ? AND latitude IS NOT NULL AND scan_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $q->bind_param('i', $site_id);
        $q->execute();
        $res   = $q->get_result();
        $total = 0;
        $fence = ['name' => $site['site_name'], 'lat' => $lat, 'lng' => $lng, 'radius_m' => $radius, 'mode' => $mode];
        while ($res && ($r = $res->fetch_assoc())) {
            $total++;
            $m = geofence_measure($fence, $r['latitude'], $r['longitude'], $r['location_accuracy']);
            if ($m && $m['outside']) $would++;
        }

        $msg = 'Attendance area saved for ' . $site['site_name'] . '.';
        if ($mode === 'strict') $msg .= ' Punches from outside it are now refused.';
        if ($total > 0) {
            $msg .= ' Of the last ' . $total . ' located punch' . ($total === 1 ? '' : 'es')
                  . ' here, ' . $would . ' would be outside this circle.';
        }

        return ['result' => true, 'message' => $msg];
    }
}
