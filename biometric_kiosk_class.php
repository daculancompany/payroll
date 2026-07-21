<?php
/**
 * Biometric Kiosk — mobile app support.
 *
 * NEW FILE. Nothing in the existing app is modified: the desktop scanner API
 * (biometric-api.php / Action) keeps working exactly as before, and this adds
 * only what the mobile kiosk needs on top:
 *
 *   - face profiles (enrol / list)
 *   - SourceAFIS fingerprint templates, kept apart from the desktop's
 *     DigitalPersona ones because the two SDKs cannot read each other's data
 *   - full-frame attendance photos written to a public folder, with only the
 *     filename stored in the database
 *
 * Attendance itself is NOT reimplemented here. Action::save_biometric_attendance()
 * already handles overnight shifts, double-tap debounce and the DTR_details
 * log array; this class delegates to it and then attaches the photo.
 *
 * Conventions follow the rest of the app: mysqli with prepared statements and
 * an array response shaped ['result' => bool, 'message' => string, ...].
 */

// admin_class.php is NOT required here on purpose. Loading it pulls in the
// Composer autoloader and starts a session, which the face, template and
// health paths have no use for. It is required lazily inside the one method
// that delegates to Action.

class BiometricKiosk
{
    /** @var mysqli */
    private $db;

    /** Network that produced a face embedding. */
    const FACE_MODEL_DEFAULT = 'facenet';

    /** SDK that produced a mobile fingerprint template. */
    const TEMPLATE_FORMAT_DEFAULT = 'sourceafis';

    /**
     * The 10 canonical finger positions, in NIST order (1..10). Enrollment
     * labels a template with one of these codes so the app can show which
     * finger sits where on the two-hand diagram. finger_index is still a free
     * varchar server-side (older rows may hold "finger-1"); these are just the
     * values the mobile app now sends and what get_finger_status() reports on.
     */
    const FINGERS = [
        'RIGHT_THUMB', 'RIGHT_INDEX', 'RIGHT_MIDDLE', 'RIGHT_RING', 'RIGHT_PINKY',
        'LEFT_THUMB',  'LEFT_INDEX',  'LEFT_MIDDLE',  'LEFT_RING',  'LEFT_PINKY',
    ];

    /** Upper bound for one attendance photo — a full frame, not a face crop. */
    const MAX_SELFIE_BYTES = 4194304; // 4 MB

    /** Where photos are written, relative to this file. Public, web-served. */
    const SELFIE_DIR = 'uploads/attendance';

    /** Where cropped enrollment faces are written. Public, web-served. */
    const FACE_DIR = 'uploads/faces';

    public function __construct()
    {
        // db_connect.php returns the mysqli handle and defines the app
        // constants; including it here keeps this file self-contained.
        $conn = include __DIR__ . '/db_connect.php';
        $this->db = $conn;
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Health
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Reachability probe for the kiosk's online/offline indicator.
     *
     * No auth and no table reads on purpose: the kiosk polls this on a timer,
     * and requiring a token would make an expired session look like a dead
     * network — sending punches to the offline queue for no reason.
     *
     * Returns the server clock so a kiosk can spot a drifting device clock,
     * which would otherwise file punches on the wrong day.
     */
    public function health()
    {
        return [
            'result'      => true,
            'message'     => 'ok',
            'server_time' => date('Y-m-d H:i:s'),
        ];
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Face profiles
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Store one face shot. An employee holds SEVERAL vectors — one per
     * `face_index` slot ("face-1"…"face-6") — because a live scan is matched
     * against every stored vector and a single angle recognises poorly.
     *
     * The slot is what keeps that bounded: re-enrolling "face-2" replaces
     * that row instead of growing the table on every visit.
     *
     * The cropped face image is optional and purely for display (roster
     * avatars, enrollment review). Recognition never reads it — a failed
     * image write therefore does not fail the enrollment.
     *
     * Expects POST: employee_id, embedding (JSON array or array),
     *               [model], [face_index], [photo] (base64 JPEG),
     *               [replace_all] — drop the employee's other slots, so a new
     *               registration REPLACES their face rather than adding to it
     */
    public function save_face()
    {
        $employee_id = intval($_POST['employee_id'] ?? 0);
        $model       = trim($_POST['model'] ?? '') ?: self::FACE_MODEL_DEFAULT;
        $face_index  = trim($_POST['face_index'] ?? '') ?: 'face-1';
        $raw         = $_POST['embedding'] ?? null;
        $photo_raw   = (string) ($_POST['photo'] ?? '');

        if (!$employee_id) {
            return ['result' => false, 'message' => 'Missing employee_id'];
        }

        $embedding = $this->normalize_embedding($raw);
        if ($embedding === null) {
            return ['result' => false, 'message' => 'Embedding must be an array of finite numbers'];
        }
        if (count($embedding) < 16) {
            return ['result' => false, 'message' => 'Embedding is too short to be a face vector'];
        }

        if (!$this->employee_exists($employee_id)) {
            return ['result' => false, 'message' => 'Employee not found or inactive'];
        }

        $json = json_encode($embedding);
        $dims = count($embedding);

        // Written before the row so a stored path always points at a real
        // file; an unusable image simply leaves the column untouched.
        $photo_path = null;
        if ($photo_raw !== '') {
            $binary = $this->decode_image($photo_raw);
            if ($binary !== null) {
                $photo_path = $this->store_face_photo($employee_id, $model, $face_index, $binary);
            }
        }

        // COALESCE on update: re-enrolling without an image must not blank the
        // photo an earlier shot already stored.
        $stmt = $this->db->prepare("
            INSERT INTO biometric_kiosk_faces
                (employee_id, model, face_index, dimensions, embedding, photo)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                embedding  = VALUES(embedding),
                dimensions = VALUES(dimensions),
                photo      = COALESCE(VALUES(photo), photo),
                updated_at = NOW()
        ");
        $stmt->bind_param('ississ', $employee_id, $model, $face_index, $dims, $json, $photo_path);

        if (!$stmt->execute()) {
            return ['result' => false, 'message' => 'Failed to save face: ' . $this->db->error];
        }

        // Re-registering replaces the employee's face rather than adding to
        // it. The other slots go AFTER the new row is safely in, so a failure
        // here can never leave the employee with no face at all.
        $replaced = 0;
        if (!empty($_POST['replace_all'])) {
            $replaced = $this->delete_other_face_slots($employee_id, $model, $face_index);
        }

        return [
            'result'      => true,
            'message'     => 'Face saved',
            'employee_id' => $employee_id,
            'model'       => $model,
            'face_index'  => $face_index,
            'dimensions'  => $dims,
            'photo'       => $photo_path,
            'photo_url'   => $this->public_url($photo_path),
            'replaced'    => $replaced,
        ];
    }

    /**
     * Every enrolled face for one model, so the kiosk can rebuild its local
     * matcher. Scoped to a single model because vectors from different
     * networks are not comparable.
     */
    public function get_faces()
    {
        $model = trim($_POST['model'] ?? $_GET['model'] ?? '') ?: self::FACE_MODEL_DEFAULT;

        $stmt = $this->db->prepare("
            SELECT f.id, f.employee_id, f.model, f.face_index, f.dimensions,
                   f.embedding, f.photo, e.firstname, e.lastname
            FROM biometric_kiosk_faces f
            INNER JOIN employee e ON e.id = f.employee_id AND e.status = 1
            WHERE f.model = ?
            ORDER BY f.employee_id, f.face_index
        ");
        $stmt->bind_param('s', $model);
        $stmt->execute();
        $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $data[] = [
                'id'            => (int) $row['id'],
                'employee_id'   => (int) $row['employee_id'],
                'employee_name' => trim($row['firstname'] . ' ' . $row['lastname']),
                'model'         => $row['model'],
                'face_index'    => $row['face_index'],
                'dimensions'    => (int) $row['dimensions'],
                'embedding'     => json_decode($row['embedding'], true) ?: [],
                'photo'         => $row['photo'],
                'photo_url'     => $this->public_url($row['photo']),
            ];
        }

        return ['result' => true, 'data' => $data, 'total' => count($data)];
    }

    /** Remove an employee's face profile(s). */
    public function delete_face()
    {
        $employee_id = intval($_POST['employee_id'] ?? 0);
        if (!$employee_id) {
            return ['result' => false, 'message' => 'Missing employee_id'];
        }

        $stmt = $this->db->prepare("DELETE FROM biometric_kiosk_faces WHERE employee_id = ?");
        $stmt->bind_param('i', $employee_id);
        $stmt->execute();

        return [
            'result'  => true,
            'message' => 'Face profile removed',
            'removed' => $stmt->affected_rows,
        ];
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Mobile fingerprint templates (SourceAFIS)
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Remove ONE enrolled finger for an employee, so it can be re-registered
     * from scratch or dropped entirely.
     *
     * Expects POST: employee_id, finger_index, [format].
     */
    public function delete_template()
    {
        $employee_id  = intval($_POST['employee_id'] ?? 0);
        $finger_index = trim($_POST['finger_index'] ?? '');
        $format       = trim($_POST['format'] ?? '') ?: self::TEMPLATE_FORMAT_DEFAULT;

        if (!$employee_id || $finger_index === '') {
            return ['result' => false, 'message' => 'Missing employee_id or finger_index'];
        }

        $stmt = $this->db->prepare("
            DELETE FROM biometric_kiosk_templates
            WHERE employee_id = ? AND finger_index = ? AND format = ?
        ");
        $stmt->bind_param('iss', $employee_id, $finger_index, $format);
        $stmt->execute();

        return [
            'result'       => true,
            'message'      => $stmt->affected_rows ? 'Fingerprint removed' : 'No matching fingerprint',
            'employee_id'  => $employee_id,
            'finger_index' => $finger_index,
            'removed'      => $stmt->affected_rows,
        ];
    }

    /**
     * Store a template captured by the mobile kiosk.
     *
     * Written to biometric_kiosk_templates, NOT employee_fingerprints: the
     * desktop app's DigitalPersona templates live there and the two formats
     * are not interchangeable.
     *
     * Expects POST: employee_id, finger_index, template (base64), [format]
     */
    public function save_template()
    {
        $employee_id  = intval($_POST['employee_id'] ?? 0);
        $finger_index = trim($_POST['finger_index'] ?? '');
        $template     = trim($_POST['template'] ?? '');
        $format       = trim($_POST['format'] ?? '') ?: self::TEMPLATE_FORMAT_DEFAULT;
        // Optional capture quality (0..100). NULL when the client doesn't report it.
        $quality      = isset($_POST['quality']) && $_POST['quality'] !== ''
            ? max(0, min(100, (int) $_POST['quality']))
            : null;

        if (!$employee_id || $finger_index === '' || $template === '') {
            return ['result' => false, 'message' => 'Missing employee_id, finger_index or template'];
        }

        if (!in_array($format, ['sourceafis', 'dpfp'], true)) {
            return ['result' => false, 'message' => 'Unknown template format: ' . $format];
        }

        // Strip a data: prefix if the client sent one, then verify it really
        // is base64 — a mangled template would fail silently at match time.
        $clean = preg_replace('/^data:[^;]+;base64,/', '', $template);
        if (base64_decode($clean, true) === false) {
            return ['result' => false, 'message' => 'Template is not valid base64'];
        }

        if (!$this->employee_exists($employee_id)) {
            return ['result' => false, 'message' => 'Employee not found or inactive'];
        }

        $stmt = $this->db->prepare("
            INSERT INTO biometric_kiosk_templates (employee_id, finger_index, format, template, quality)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE template = VALUES(template), quality = VALUES(quality), updated_at = NOW()
        ");
        $stmt->bind_param('isssi', $employee_id, $finger_index, $format, $clean, $quality);

        if (!$stmt->execute()) {
            return ['result' => false, 'message' => 'Failed to save template: ' . $this->db->error];
        }

        return [
            'result'       => true,
            'message'      => 'Template saved',
            'employee_id'  => $employee_id,
            'finger_index' => $finger_index,
            'format'       => $format,
            'quality'      => $quality,
        ];
    }

    /**
     * Per-finger enrollment status for ONE employee — the data the two-hand
     * enrollment screen renders. Returns all 10 canonical fingers, each marked
     * registered or not, with quality and last-updated when present. Any
     * non-canonical rows this employee has (e.g. legacy "finger-1") are
     * returned separately under `other` so nothing is silently hidden.
     *
     * Body: employee_id, [format]. Answers:
     *   { result, employee_id, registered_count, fingers: [
     *       {finger_index, label, hand, position, registered, quality, updated_at}
     *     ], other: [...] }
     */
    public function get_finger_status()
    {
        $employee_id = intval($_POST['employee_id'] ?? $_GET['employee_id'] ?? 0);
        $format      = trim($_POST['format'] ?? $_GET['format'] ?? '') ?: self::TEMPLATE_FORMAT_DEFAULT;

        if (!$employee_id) {
            return ['result' => false, 'message' => 'Missing employee_id'];
        }

        // Pull every stored finger for this employee/format, keyed by finger_index.
        $stmt = $this->db->prepare("
            SELECT finger_index, quality, updated_at
            FROM biometric_kiosk_templates
            WHERE employee_id = ? AND format = ?
        ");
        $stmt->bind_param('is', $employee_id, $format);
        $stmt->execute();
        $res = $stmt->get_result();

        $stored = [];
        while ($row = $res->fetch_assoc()) {
            $stored[$row['finger_index']] = $row;
        }

        // Build the canonical 10-finger list in NIST order.
        $fingers = [];
        $registered = 0;
        foreach (self::FINGERS as $pos => $code) {
            $has = isset($stored[$code]);
            if ($has) $registered++;
            [$hand, $name] = explode('_', $code, 2);
            $fingers[] = [
                'finger_index' => $code,
                'label'        => ucfirst(strtolower($hand)) . ' ' . ucfirst(strtolower($name)),
                'hand'         => strtolower($hand),                 // 'left' | 'right'
                'position'     => $pos + 1,                          // 1..10 (NIST)
                'registered'   => $has,
                'quality'      => $has && $stored[$code]['quality'] !== null ? (int) $stored[$code]['quality'] : null,
                'updated_at'   => $has ? $stored[$code]['updated_at'] : null,
            ];
            unset($stored[$code]);
        }

        // Anything left is non-canonical (legacy labels) — surface it, don't hide it.
        $other = [];
        foreach ($stored as $code => $row) {
            $other[] = [
                'finger_index' => $code,
                'registered'   => true,
                'quality'      => $row['quality'] !== null ? (int) $row['quality'] : null,
                'updated_at'   => $row['updated_at'],
            ];
        }

        return [
            'result'           => true,
            'employee_id'      => $employee_id,
            'format'           => $format,
            'registered_count' => $registered,
            'fingers'          => $fingers,
            'other'            => $other,
        ];
    }

    /**
     * Templates this kiosk can actually match. Defaults to SourceAFIS —
     * handing the mobile matcher DigitalPersona templates would only produce
     * failed scans.
     */
    public function get_templates()
    {
        $format = trim($_POST['format'] ?? $_GET['format'] ?? '') ?: self::TEMPLATE_FORMAT_DEFAULT;

        $sql = "
            SELECT t.id, t.employee_id, t.finger_index, t.format, t.template, t.quality,
                   e.firstname, e.lastname
            FROM biometric_kiosk_templates t
            INNER JOIN employee e ON e.id = t.employee_id AND e.status = 1
        ";

        if ($format !== 'all') {
            $sql .= " WHERE t.format = ? ";
        }
        $sql .= " ORDER BY t.employee_id, t.finger_index";

        $stmt = $this->db->prepare($sql);
        if ($format !== 'all') {
            $stmt->bind_param('s', $format);
        }
        $stmt->execute();
        $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $data[] = [
                'id'            => (int) $row['id'],
                'employee_id'   => (int) $row['employee_id'],
                'employee_name' => trim($row['firstname'] . ' ' . $row['lastname']),
                'finger_index'  => $row['finger_index'],
                'format'        => $row['format'],
                'template'      => $row['template'],
                'quality'       => $row['quality'] !== null ? (int) $row['quality'] : null,
            ];
        }

        return ['result' => true, 'data' => $data, 'total' => count($data)];
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Attendance + full-frame photo
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Record a scan and keep the photo of whoever made it.
     *
     * The attendance write is delegated to the existing
     * Action::save_biometric_attendance(), which already knows about overnight
     * shifts, the duplicate window and the DTR_details log array. Duplicating
     * that logic here would guarantee the two drift apart.
     *
     * The photo is the FULL camera frame, not the cropped face: the record is
     * meant to show who was actually standing at the kiosk.
     *
     * Expects POST: employee_id, scan_time, site_id, [selfie] (base64 JPEG)
     */
    public function save_attendance_with_selfie()
    {
        $employee_id = intval($_POST['employee_id'] ?? 0);
        $scan_time   = trim($_POST['scan_time'] ?? '');
        $selfie_raw  = (string) ($_POST['selfie'] ?? '');

        // Decode BEFORE touching attendance so a malformed image is a clean
        // rejection rather than a punch with a broken photo attached.
        $binary = null;
        if ($selfie_raw !== '') {
            $binary = $this->decode_image($selfie_raw);
            if ($binary === null) {
                return ['result' => false, 'message' => 'Selfie is not valid base64 image data'];
            }
        }

        // Loaded lazily — only this path needs the Action class.
        require_once __DIR__ . '/admin_class.php';

        $action = new Action();
        $result = $action->save_biometric_attendance();

        // Attendance failed (unknown employee, bad scan_time, …) — do not
        // leave an orphan image on disk for a punch that never happened.
        if (empty($result['result'])) {
            return $result;
        }

        $result['selfie']     = null;
        $result['selfie_url'] = null;

        if ($binary !== null) {
            $stored = $this->store_selfie($employee_id, $scan_time, $binary);
            if ($stored) {
                $result['selfie']     = $stored;
                $result['selfie_url'] = $this->public_url($stored);
            } else {
                // The attendance row is what matters; the photo is supporting
                // evidence. Report it without failing the punch.
                $result['selfie_error'] = 'Photo could not be saved';
            }
        }

        return $result;
    }

    /**
     * Photos for one employee (optionally one date) so the payroll screens can
     * show who clocked in.
     */
    public function get_selfies()
    {
        $employee_id = intval($_POST['employee_id'] ?? $_GET['employee_id'] ?? 0);
        $date        = trim($_POST['date'] ?? $_GET['date'] ?? '');

        if (!$employee_id) {
            return ['result' => false, 'message' => 'Missing employee_id'];
        }

        if ($date !== '') {
            $stmt = $this->db->prepare("
                SELECT id, employee_id, log_date, log_datetime, filename, source
                FROM biometric_kiosk_selfies
                WHERE employee_id = ? AND log_date = ?
                ORDER BY log_datetime DESC
            ");
            $stmt->bind_param('is', $employee_id, $date);
        } else {
            $stmt = $this->db->prepare("
                SELECT id, employee_id, log_date, log_datetime, filename, source
                FROM biometric_kiosk_selfies
                WHERE employee_id = ?
                ORDER BY log_datetime DESC
                LIMIT 100
            ");
            $stmt->bind_param('i', $employee_id);
        }

        $stmt->execute();
        $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $data[] = [
                'id'           => (int) $row['id'],
                'employee_id'  => (int) $row['employee_id'],
                'log_date'     => $row['log_date'],
                'log_datetime' => $row['log_datetime'],
                'filename'     => $row['filename'],
                'url'          => $this->public_url($row['filename']),
                'source'       => $row['source'],
            ];
        }

        return ['result' => true, 'data' => $data, 'total' => count($data)];
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Internals
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Write the image under uploads/attendance/YYYY/MM/ and record the path.
     * Returns the stored relative path, or null when it could not be saved.
     */
    private function store_selfie($employee_id, $scan_time, $binary)
    {
        $ts = strtotime($scan_time) ?: time();

        $rel_dir = self::SELFIE_DIR . '/' . date('Y', $ts) . '/' . date('m', $ts);
        $abs_dir = __DIR__ . '/' . $rel_dir;

        if (!is_dir($abs_dir) && !@mkdir($abs_dir, 0775, true) && !is_dir($abs_dir)) {
            return null;
        }

        // Employee + timestamp + random: readable when browsing the folder,
        // and collision-proof when two kiosks punch in the same second.
        $name = sprintf(
            'emp%d_%s_%s.jpg',
            $employee_id,
            date('Ymd_His', $ts),
            substr(bin2hex(random_bytes(4)), 0, 6)
        );

        $rel_path = $rel_dir . '/' . $name;
        if (@file_put_contents($abs_dir . '/' . $name, $binary) === false) {
            return null;
        }

        $log_date = date('Y-m-d', $ts);
        $log_dt   = date('Y-m-d H:i:s', $ts);
        $source   = 'face';

        $stmt = $this->db->prepare("
            INSERT INTO biometric_kiosk_selfies
                (employee_id, log_date, log_datetime, filename, source)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('issss', $employee_id, $log_date, $log_dt, $rel_path, $source);

        if (!$stmt->execute()) {
            // Row failed — remove the file so the folder does not accumulate
            // images nothing points at.
            @unlink($abs_dir . '/' . $name);
            return null;
        }

        return $rel_path;
    }

    /**
     * Decode a base64 image, tolerating a `data:image/jpeg;base64,` prefix.
     * Returns null when the payload is not actually an image.
     */
    private function decode_image($payload)
    {
        $clean  = preg_replace('/^data:image\/[a-zA-Z0-9.+-]+;base64,/', '', trim($payload));
        $binary = base64_decode($clean, true);

        if ($binary === false || strlen($binary) < 128) {
            return null;
        }
        if (strlen($binary) > self::MAX_SELFIE_BYTES) {
            return null;
        }
        // The path is shown back to HR — a text file must not land there.
        if (@getimagesizefromstring($binary) === false) {
            return null;
        }

        return $binary;
    }

    /**
     * Drop every OTHER face slot an employee holds for this model, and the
     * images behind them. Returns how many rows went.
     *
     * Used when a registration replaces the employee's face instead of adding
     * another angle to it.
     */
    private function delete_other_face_slots($employee_id, $model, $keep_index)
    {
        // Collect the photos first — once the rows are gone there is nothing
        // left pointing at the files, and the folder would keep them forever.
        $stale = [];
        $find = $this->db->prepare("
            SELECT photo FROM biometric_kiosk_faces
            WHERE employee_id = ? AND model = ? AND face_index <> ? AND photo IS NOT NULL
        ");
        $find->bind_param('iss', $employee_id, $model, $keep_index);
        $find->execute();
        $res = $find->get_result();
        while ($row = $res->fetch_assoc()) {
            $stale[] = $row['photo'];
        }

        $stmt = $this->db->prepare("
            DELETE FROM biometric_kiosk_faces
            WHERE employee_id = ? AND model = ? AND face_index <> ?
        ");
        $stmt->bind_param('iss', $employee_id, $model, $keep_index);
        if (!$stmt->execute()) {
            return 0;
        }
        $removed = $stmt->affected_rows;

        foreach ($stale as $rel_path) {
            // Never follow a path out of the faces folder, whatever the row says.
            $abs = realpath(__DIR__ . '/' . $rel_path);
            $root = realpath(__DIR__ . '/' . self::FACE_DIR);
            if ($abs && $root && strpos($abs, $root) === 0) {
                @unlink($abs);
            }
        }

        return $removed;
    }

    /**
     * Write one cropped enrollment face and return its relative path.
     *
     * The name is derived from (employee, model, slot) with no timestamp, so
     * re-enrolling a slot overwrites its image the same way the row is
     * replaced — the folder cannot accumulate orphans nothing points at.
     */
    private function store_face_photo($employee_id, $model, $face_index, $binary)
    {
        $abs_dir = __DIR__ . '/' . self::FACE_DIR;

        if (!is_dir($abs_dir) && !@mkdir($abs_dir, 0775, true) && !is_dir($abs_dir)) {
            return null;
        }

        // Slot labels reach the filesystem — keep them to a safe charset.
        $safe_model = preg_replace('/[^A-Za-z0-9._-]/', '', $model) ?: 'facenet';
        $safe_index = preg_replace('/[^A-Za-z0-9._-]/', '', $face_index) ?: 'face-1';

        $name     = sprintf('emp%d_%s_%s.jpg', $employee_id, $safe_model, $safe_index);
        $rel_path = self::FACE_DIR . '/' . $name;

        if (@file_put_contents($abs_dir . '/' . $name, $binary) === false) {
            return null;
        }

        return $rel_path;
    }

    /** Absolute URL for a stored relative path. */
    private function public_url($rel_path)
    {
        if (!$rel_path) {
            return null;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Directory this script lives in, so the URL works whether the app is
        // at the web root or in a subfolder.
        $base   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

        return $scheme . '://' . $host . $base . '/' . ltrim($rel_path, '/');
    }

    /**
     * Coerce an embedding to a list of finite floats.
     *
     * Rejects the whole vector if any entry is unusable: PHP casts null and ''
     * to 0.0, which would silently become a real coordinate and quietly
     * distort every comparison against it.
     */
    private function normalize_embedding($raw)
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        $out = [];
        foreach ($raw as $value) {
            if (is_int($value) || is_float($value)) {
                $number = (float) $value;
            } elseif (is_string($value) && is_numeric(trim($value))) {
                $number = (float) trim($value);
            } else {
                return null;
            }

            if (!is_finite($number)) {
                return null;
            }
            $out[] = $number;
        }

        return $out;
    }

    /** Active-employee guard, shared by every write path. */
    private function employee_exists($employee_id)
    {
        $stmt = $this->db->prepare("SELECT id FROM employee WHERE id = ? AND status = 1 LIMIT 1");
        $stmt->bind_param('i', $employee_id);
        $stmt->execute();

        return (bool) $stmt->get_result()->fetch_assoc();
    }
}
