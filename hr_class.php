<?php
/**
 * HR Management — the employee record beyond payroll.
 *
 * NEW CONTROLLER, on purpose. `admin_class.php` is a 15,000-line class that
 * owns payroll, the DTR engine and the money. Nothing here touches it: the
 * modules below are HR record-keeping that no payroll query reads, so they get
 * their own class, their own endpoint (hr-ajax.php) and their own tables.
 *
 * The one exception is the offboarding step (phase 9), which flips
 * `employee.status` — the same flag the existing deactivate action sets — and
 * says so where it does it.
 *
 * Modules:
 *   1. 201 profile + dependants + profile picture   (this file)
 *   2. Documents            includes/hr/documents.php
 *   3. Movements            includes/hr/movements.php
 *   4. Memos                includes/hr/memos.php
 *   5. Announcements        includes/hr/announcements.php
 *   6. Trainings            includes/hr/trainings.php
 *   7. Company property     includes/hr/assets.php
 *   8. Document requests    includes/hr/requests.php   (COE — PDF: hr-certificate.php)
 *   9. Offboarding          includes/hr/offboarding.php
 *  Reminders and reports are plain functions: includes/hr/insights.php
 *
 * Each module is a trait so the class stays one controller (hr-ajax.php calls
 * its methods by name) without being one 3,000-line file. The traits share the
 * helpers at the bottom of this class.
 *
 * The profile rules are a PORT of the HRIS app's 201 tab
 * (HRIS/admin_class.php::profile_field_map / collect_profile_fields /
 * upsert_employee_profile). Four behaviours there are deliberate and are kept
 * exactly — see normaliseProfile() for what they are and why a straightforward
 * rewrite breaks them.
 *
 * Every method answers ['result' => bool, 'message' => string, …] so the
 * caller's error path is always the same. PHP 7.4 compatible (XAMPP ships 7.4).
 */

require_once __DIR__ . '/includes/hr/documents.php';
require_once __DIR__ . '/includes/hr/movements.php';
require_once __DIR__ . '/includes/hr/memos.php';
require_once __DIR__ . '/includes/hr/announcements.php';
require_once __DIR__ . '/includes/hr/trainings.php';
require_once __DIR__ . '/includes/hr/assets.php';
require_once __DIR__ . '/includes/hr/requests.php';
require_once __DIR__ . '/includes/hr/offboarding.php';

class HrManagement
{
    use HrDocumentsModule, HrMovementsModule, HrMemosModule, HrAnnouncementsModule,
        HrTrainingsModule, HrAssetsModule, HrRequestsModule, HrOffboardingModule;

    /** @var mysqli */
    private $db;

    /**
     * Send phone/browser pushes along with the portal bell rows. Always true
     * in the app; only PHP code can turn it off (a script exercising these
     * modules against a copy of real data must not ring real phones).
     */
    public static $push = true;

    /** Where uploads live: outside the web root, served only by hr-file.php. */
    const STORAGE_DIR = 'storage/hr';
    const PHOTO_DIR   = 'storage/hr/photos';

    /** A phone photo fits in 4 MB; an accidental RAW file does not. */
    const PHOTO_MAX_BYTES = 4194304;

    /**
     * An allow-list of SNIFFED types, never the declared extension.
     * SVG is excluded deliberately: it is markup that can carry script, and it
     * would be served back from our own origin.
     */
    const PHOTO_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Who may do what
     *
     * Admin (1) and HR (9) own these records. HR is read-only on the payroll
     * screens (HR_READONLY_PAGES) — that is about PAY, not about people, and
     * the whole point of this module is that the people record is HR's.
     * ──────────────────────────────────────────────────────────────────── */

    private function role()
    {
        return (int) ($_SESSION['login_role'] ?? 0);
    }

    /** Staff who may read any employee's HR record. */
    public function canRead()
    {
        return in_array($this->role(), [1, ROLE_HR], true);
    }

    /** Staff who may change it. Same two roles — see the note above. */
    public function canWrite()
    {
        return $this->canRead();
    }

    private function actor()
    {
        return (int) ($_SESSION['login_id'] ?? 0) ?: null;
    }

    private function fail($message)
    {
        return ['result' => false, 'message' => $message];
    }

    private function denied()
    {
        return $this->fail('Your role does not have access to HR records.');
    }

    /** Does the schema this module needs exist yet? */
    public function tableExists($table)
    {
        static $seen = [];
        if (isset($seen[$table])) return $seen[$table];
        $res = $this->db->query("SHOW TABLES LIKE '" . $this->db->real_escape_string($table) . "'");
        return $seen[$table] = (bool) ($res && $res->num_rows);
    }

    private function missingSchema()
    {
        return $this->fail('The HR tables are missing. Run migrations/2026_11_hr_201_profile.sql on this database.');
    }

    private function employeeExists($employee_id)
    {
        $stmt = $this->db->prepare("SELECT id FROM employee WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $employee_id);
        $stmt->execute();
        return (bool) $stmt->get_result()->fetch_assoc();
    }

    /* ─────────────────────────────────────────────────────────────────────
     * 201 profile
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Every 201 column, how to clean it, and what it may contain.
     *
     *   str  → trimmed text, capped at max; `values` restricts it to a list
     *   date → Y-m-d, or NULL
     *   dec  → a number in (0, max]
     *
     * Ported from HRIS/admin_class.php::profile_field_map(). The value lists
     * are enforced here rather than as a database ENUM so that adding one
     * needs no ALTER against a live table.
     */
    public static function profileFieldMap()
    {
        return [
            // Personal
            'sex'                      => ['type' => 'str', 'max' => 10,  'values' => ['Male', 'Female']],
            'civil_status'             => ['type' => 'str', 'max' => 20,  'values' => ['Single', 'Married', 'Widowed', 'Separated', 'Annulled']],
            'blood_type'               => ['type' => 'str', 'max' => 5,   'values' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']],
            'nationality'              => ['type' => 'str', 'max' => 50],
            'religion'                 => ['type' => 'str', 'max' => 50],
            'birth_place'              => ['type' => 'str', 'max' => 150],
            'height_cm'                => ['type' => 'dec', 'max' => 300],
            'weight_kg'                => ['type' => 'dec', 'max' => 500],
            'allergies'                => ['type' => 'str', 'max' => 255],
            'medical_conditions'       => ['type' => 'str', 'max' => 255],
            // Contact
            'mobile_no'                => ['type' => 'str', 'max' => 30],
            'telephone_no'             => ['type' => 'str', 'max' => 30],
            'personal_email'           => ['type' => 'str', 'max' => 100],
            'present_address'          => ['type' => 'str', 'max' => 255],
            'permanent_address'        => ['type' => 'str', 'max' => 255],
            // Emergency contact
            'emergency_name'           => ['type' => 'str', 'max' => 120],
            'emergency_relation'       => ['type' => 'str', 'max' => 50],
            'emergency_mobile'         => ['type' => 'str', 'max' => 30],
            'emergency_address'        => ['type' => 'str', 'max' => 255],
            // Employment
            'date_hired'               => ['type' => 'date'],
            'employment_status'        => ['type' => 'str', 'max' => 30, 'values' => ['Probationary', 'Regular', 'Casual', 'Contractual', 'Project-based', 'Job Order', 'Part-time', 'Resigned', 'Terminated', 'Retired']],
            'regularization_date'      => ['type' => 'date'],
            'date_separated'           => ['type' => 'date'],
            'separation_reason'        => ['type' => 'str', 'max' => 255],
            // HMO / insurance
            'hmo_provider'             => ['type' => 'str', 'max' => 100],
            'hmo_card_no'              => ['type' => 'str', 'max' => 50],
            'hmo_effective_date'       => ['type' => 'date'],
            'hmo_expiry_date'          => ['type' => 'date'],
            'life_insurance_provider'  => ['type' => 'str', 'max' => 100],
            'life_insurance_policy_no' => ['type' => 'str', 'max' => 50],
            'insurance_beneficiary'    => ['type' => 'str', 'max' => 150],
            // PRC / licence
            'prc_license_no'           => ['type' => 'str', 'max' => 50],
            'prc_profession'           => ['type' => 'str', 'max' => 80],
            'prc_issued_date'          => ['type' => 'date'],
            'prc_expiry_date'          => ['type' => 'date'],
            // Education
            'highest_education'        => ['type' => 'str', 'max' => 60],
            'school_name'              => ['type' => 'str', 'max' => 150],
            'course'                   => ['type' => 'str', 'max' => 150],
            'year_graduated'           => ['type' => 'str', 'max' => 9],
        ];
    }

    /** Fields an employee may change about themselves from the portal. */
    const SELF_EDITABLE = [
        'mobile_no', 'telephone_no', 'personal_email',
        'present_address', 'permanent_address',
        'emergency_name', 'emergency_relation', 'emergency_mobile', 'emergency_address',
        'civil_status', 'blood_type', 'religion', 'allergies', 'medical_conditions',
    ];

    /** Labels the "Words From Column Name" default gets wrong. */
    private static function profileLabels()
    {
        return [
            'height_cm'                => 'Height (cm)',
            'weight_kg'                => 'Weight (kg)',
            'personal_email'           => 'Personal email',
            'mobile_no'                => 'Mobile no.',
            'telephone_no'             => 'Telephone no.',
            'emergency_mobile'         => 'Emergency contact no.',
            'prc_license_no'           => 'PRC licence no.',
            'prc_profession'           => 'PRC profession',
            'prc_issued_date'          => 'PRC date issued',
            'prc_expiry_date'          => 'PRC expiry date',
            'hmo_card_no'              => 'HMO card no.',
            'hmo_provider'             => 'HMO provider',
            'hmo_effective_date'       => 'HMO effective date',
            'hmo_expiry_date'          => 'HMO expiry date',
            'life_insurance_policy_no' => 'Life insurance policy no.',
        ];
    }

    /**
     * Pull the 201 fields out of a POST and clean them.
     *
     * Four behaviours carried over verbatim from the HRIS port, each of which
     * a naive rewrite breaks:
     *
     *  1. PARTIAL UPDATE, KEYED ON PRESENCE. Only keys actually present in the
     *     request come back. That is what lets the portal's small contact form
     *     and HR's full 201 tab share one writer without the small one
     *     blanking everything the big one filled in.
     *  2. BLANK MEANS NULL — never '', never '0000-00-00'. ABSENT means "leave
     *     it alone"; PRESENT-and-empty means "clear it".
     *  3. A value list is matched case-insensitively but STORED in its
     *     canonical spelling, because reports group on these strings.
     *  4. Rejecting a value returns a message naming the field, so the form can
     *     say which one was wrong.
     *
     * @param array $only Restrict to these columns (the portal's self-service).
     * @return array [$fields, $errorMessageOrNull]
     */
    public static function normaliseProfile(array $post, array $only = [])
    {
        $map    = self::profileFieldMap();
        $labels = self::profileLabels();
        $fields = [];

        foreach ($map as $col => $spec) {
            if (!array_key_exists($col, $post)) continue;           // not on this form
            if ($only && !in_array($col, $only, true)) continue;    // not theirs to set

            $label = $labels[$col] ?? ucwords(str_replace('_', ' ', $col));
            $raw   = is_string($post[$col]) ? trim($post[$col]) : $post[$col];

            // Blank always means "no answer" and is stored as NULL. A DATE
            // column given '' would land as 0000-00-00, which reads back as a
            // real date and formats as a nonsense one.
            if ($raw === '' || $raw === null) {
                $fields[$col] = null;
                continue;
            }

            switch ($spec['type']) {
                case 'date':
                    $ts = strtotime((string) $raw);
                    if ($ts === false) return [null, "$label is not a valid date."];
                    $fields[$col] = date('Y-m-d', $ts);
                    break;

                case 'dec':
                    if (!is_numeric($raw)) return [null, "$label must be a number."];
                    $n = (float) $raw;
                    if ($n <= 0 || $n > $spec['max']) return [null, "$label is out of range."];
                    $fields[$col] = number_format($n, 2, '.', '');
                    break;

                default: // str
                    $val = (string) $raw;
                    if (isset($spec['values'])) {
                        $match = null;
                        foreach ($spec['values'] as $allowed) {
                            if (strcasecmp($val, $allowed) === 0) { $match = $allowed; break; }
                        }
                        if ($match === null) return [null, "$label is not one of the allowed values."];
                        $val = $match;
                    }
                    if (mb_strlen($val) > $spec['max']) {
                        return [null, "$label is too long (max {$spec['max']} characters)."];
                    }
                    $fields[$col] = $val;
            }
        }

        return [$fields, null];
    }

    /** The stored 201 row, or [] when HR has recorded nothing yet. */
    public function profileRow($employee_id)
    {
        $employee_id = (int) $employee_id;
        if ($employee_id <= 0 || !$this->tableExists('employee_profile')) return [];

        $stmt = $this->db->prepare("SELECT * FROM employee_profile WHERE employee_id = ?");
        if (!$stmt) return [];
        $stmt->bind_param('i', $employee_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: [];
    }

    /**
     * Write the given columns, creating the row on first save.
     *
     * $actor is written to updated_by; null means the employee saved it
     * themselves from the portal.
     */
    private function upsertProfile($employee_id, array $fields, $actor)
    {
        $employee_id = (int) $employee_id;
        if ($employee_id <= 0) return false;
        if (!$fields) return true;                       // nothing posted
        if (!$this->tableExists('employee_profile')) return false;

        $map     = self::profileFieldMap();
        $cols    = ['`employee_id`', '`updated_by`'];
        $types   = 'ii';
        $values  = [$employee_id, $actor];
        $updates = ['`updated_by` = VALUES(`updated_by`)'];

        foreach ($fields as $col => $val) {
            if (!isset($map[$col])) continue;            // belt and braces
            $cols[]    = "`$col`";
            $types    .= ($map[$col]['type'] === 'dec' && $val !== null) ? 'd' : 's';
            $values[]  = $val;
            $updates[] = "`$col` = VALUES(`$col`)";
        }

        $sql = 'INSERT INTO employee_profile (' . implode(', ', $cols) . ') VALUES ('
             . implode(', ', array_fill(0, count($cols), '?')) . ') '
             . 'ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);

        $stmt = $this->db->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param($types, ...$values);
        return (bool) $stmt->execute();
    }

    /**
     * Dates that only make sense in one order.
     *
     * Checked against what is ALREADY STORED as well as what was posted: the
     * two halves of a pair are routinely edited in separate submits, so
     * validating the request alone would let "expiry before issue" through
     * whenever only one half was on the form.
     */
    private function dateOrderError(array $fields, array $stored)
    {
        $val = function ($col) use ($fields, $stored) {
            return array_key_exists($col, $fields) ? $fields[$col] : ($stored[$col] ?? null);
        };
        $pairs = [
            ['date_hired', 'regularization_date', 'Regularization date cannot be before the date hired.'],
            ['date_hired', 'date_separated',      'Date separated cannot be before the date hired.'],
            ['hmo_effective_date', 'hmo_expiry_date', 'HMO expiry cannot be before the effective date.'],
            ['prc_issued_date', 'prc_expiry_date',    'PRC expiry cannot be before the issue date.'],
        ];
        foreach ($pairs as list($earlier, $later, $message)) {
            $a = $val($earlier);
            $b = $val($later);
            if ($a && $b && strtotime($b) < strtotime($a)) return $message;
        }
        return null;
    }

    /** Shared by the HR form and the portal's self-service form. */
    private function writeProfile($employee_id, array $post, array $only, $actor)
    {
        if (!$this->tableExists('employee_profile')) return $this->missingSchema();
        if (!$this->employeeExists($employee_id))    return $this->fail('That employee no longer exists.');

        list($fields, $err) = self::normaliseProfile($post, $only);
        if ($err !== null) return $this->fail($err);
        if (!$fields)      return $this->fail('Nothing to save.');

        if (!empty($fields['personal_email']) && !filter_var($fields['personal_email'], FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Personal email is not a valid email address.');
        }

        $order = $this->dateOrderError($fields, $this->profileRow($employee_id));
        if ($order !== null) return $this->fail($order);

        if (!$this->upsertProfile($employee_id, $fields, $actor)) {
            return $this->fail('Could not save the 201 information.');
        }

        return ['result' => true, 'message' => '201 information saved.'];
    }

    /** HR saves any part of the 201 file. */
    public function save_profile()
    {
        if (!$this->canWrite()) return $this->denied();
        return $this->writeProfile((int) ($_POST['employee_id'] ?? 0), $_POST, [], $this->actor());
    }

    /**
     * The employee updates their own contact details from the portal.
     * The employee id comes from the session, never the request, and only the
     * SELF_EDITABLE columns are accepted — employment, pay, HMO and PRC
     * fields are HR's to set.
     */
    public function my_save_profile()
    {
        $employee_id = (int) ($_SESSION['emp_id'] ?? 0);
        if ($employee_id <= 0) return $this->fail('Please sign in again.');
        return $this->writeProfile($employee_id, $_POST, self::SELF_EDITABLE, null);
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Dependants / beneficiaries
     * ──────────────────────────────────────────────────────────────────── */

    /** @return array rows, oldest first */
    public function dependents($employee_id)
    {
        $employee_id = (int) $employee_id;
        if ($employee_id <= 0 || !$this->tableExists('employee_dependents')) return [];

        $stmt = $this->db->prepare("SELECT * FROM employee_dependents WHERE employee_id = ? ORDER BY is_beneficiary DESC, id");
        $stmt->bind_param('i', $employee_id);
        $stmt->execute();
        $res = $stmt->get_result();

        $rows = [];
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
        return $rows;
    }

    public function save_dependent()
    {
        if (!$this->canWrite()) return $this->denied();
        if (!$this->tableExists('employee_dependents')) return $this->missingSchema();

        $employee_id = (int) ($_POST['employee_id'] ?? 0);
        $id          = (int) ($_POST['id'] ?? 0);        // 0 = new row
        if ($employee_id <= 0) return $this->fail('No employee selected.');

        $name         = trim((string) ($_POST['name'] ?? ''));
        $relationship = trim((string) ($_POST['relationship'] ?? ''));
        $birthdate    = trim((string) ($_POST['birthdate'] ?? ''));
        $remarks      = trim((string) ($_POST['remarks'] ?? ''));
        $beneficiary  = !empty($_POST['is_beneficiary']) ? 1 : 0;

        if ($name === '')                  return $this->fail('Name is required.');
        if (mb_strlen($name) > 120)        return $this->fail('Name is too long (max 120 characters).');
        if (mb_strlen($relationship) > 40) return $this->fail('Relationship is too long (max 40 characters).');
        if (mb_strlen($remarks) > 255)     return $this->fail('Remarks are too long (max 255 characters).');

        if ($birthdate !== '') {
            $ts = strtotime($birthdate);
            if ($ts === false) return $this->fail('Birthdate is not a valid date.');
            if ($ts > time())  return $this->fail('Birthdate cannot be in the future.');
            $birthdate = date('Y-m-d', $ts);
        } else {
            $birthdate = null;                            // NULL, not ''
        }
        if ($relationship === '') $relationship = null;
        if ($remarks === '')      $remarks      = null;

        if ($id > 0) {
            // Ownership is its own SELECT, never the UPDATE's affected_rows:
            // MySQL counts CHANGED rows, so re-saving an unedited row also
            // reports zero and would be indistinguishable from "not yours".
            $own = $this->db->prepare("SELECT id FROM employee_dependents WHERE id = ? AND employee_id = ?");
            $own->bind_param('ii', $id, $employee_id);
            $own->execute();
            if (!$own->get_result()->fetch_assoc()) return $this->fail('That dependant is not on this employee.');

            // employee_id stays in the WHERE too, so a race cannot move the
            // row out from under the check above.
            $stmt = $this->db->prepare("UPDATE employee_dependents
                SET name = ?, relationship = ?, birthdate = ?, is_beneficiary = ?, remarks = ?
                WHERE id = ? AND employee_id = ?");
            if (!$stmt) return $this->fail('Could not save the dependant.');
            $stmt->bind_param('sssisii', $name, $relationship, $birthdate, $beneficiary, $remarks, $id, $employee_id);
        } else {
            $stmt = $this->db->prepare("INSERT INTO employee_dependents
                (employee_id, name, relationship, birthdate, is_beneficiary, remarks)
                VALUES (?, ?, ?, ?, ?, ?)");
            if (!$stmt) return $this->fail('Could not save the dependant.');
            $stmt->bind_param('isssis', $employee_id, $name, $relationship, $birthdate, $beneficiary, $remarks);
        }

        if (!$stmt->execute()) return $this->fail('Could not save the dependant.');

        return [
            'result'  => true,
            'id'      => $id > 0 ? $id : (int) $this->db->insert_id,
            'message' => $id > 0 ? 'Dependant updated.' : 'Dependant added.',
        ];
    }

    public function delete_dependent()
    {
        if (!$this->canWrite()) return $this->denied();
        if (!$this->tableExists('employee_dependents')) return $this->missingSchema();

        $id          = (int) ($_POST['id'] ?? 0);
        $employee_id = (int) ($_POST['employee_id'] ?? 0);
        if ($id <= 0 || $employee_id <= 0) return $this->fail('Nothing to delete.');

        $stmt = $this->db->prepare("DELETE FROM employee_dependents WHERE id = ? AND employee_id = ?");
        $stmt->bind_param('ii', $id, $employee_id);
        if (!$stmt->execute()) return $this->fail('Could not delete the dependant.');

        // Matching nothing is NOT success: with employee_id in the WHERE, a
        // forged id deletes zero rows, and reporting "done" would reload the
        // page with the row still sitting there.
        if (!$stmt->affected_rows) return $this->fail('That dependant is not on this employee.');

        return ['result' => true, 'message' => 'Dependant removed.'];
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Profile picture
     *
     * The column holds a generated FILENAME only — never a path, never a URL.
     * The file sits under storage/hr/photos/, which Apache refuses to serve
     * (storage/.htaccess), so every read goes through hr-file.php and the same
     * permission check as the rest of the record. A folder under the web root
     * would be readable by anyone who guessed a filename, and these are
     * photographs of identifiable staff.
     * ──────────────────────────────────────────────────────────────────── */

    private function storageRoot()
    {
        return __DIR__ . '/' . self::PHOTO_DIR;
    }

    /** Create storage/hr/photos and the deny-all rule on first use. */
    private function ensureStorage()
    {
        $dir = $this->storageRoot();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;

        // Defence in depth: the folder is outside the router's reach, but a
        // misconfigured vhost could still expose it.
        $deny = __DIR__ . '/' . self::STORAGE_DIR . '/.htaccess';
        if (!is_file($deny)) {
            @file_put_contents($deny, "# HR uploads: never served directly — hr-file.php streams them\n"
                . "Require all denied\n");
        }
        return true;
    }

    /** Absolute path of a stored photo, or null when the name is not ours. */
    public function photoPath($filename)
    {
        $filename = (string) $filename;
        // The name is generated by us; anything else cannot name a real file.
        if (!preg_match('/^emp\d+_[a-f0-9]{12}\.(jpg|png|webp)$/', $filename)) return null;

        $abs  = $this->storageRoot() . '/' . $filename;
        $real = realpath($abs);
        $root = realpath($this->storageRoot());
        if (!$real || !$root || strpos($real, $root) !== 0) return null;

        return $real;
    }

    /**
     * Store an uploaded photo for one employee.
     * $file is a $_FILES entry.
     */
    private function writePhoto($employee_id, array $file, $actor)
    {
        if (!$this->tableExists('employee_profile')) return $this->missingSchema();
        if (!$this->employeeExists($employee_id))    return $this->fail('That employee no longer exists.');

        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE)   return $this->fail('Choose a photo first.');
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return $this->fail('That photo is too large. The limit is 4 MB.');
        }
        if ($err !== UPLOAD_ERR_OK)        return $this->fail('The photo did not upload. Please try again.');

        $tmp = $file['tmp_name'] ?? '';
        if (!$tmp || !is_uploaded_file($tmp)) return $this->fail('The photo did not upload. Please try again.');
        if (filesize($tmp) > self::PHOTO_MAX_BYTES) return $this->fail('That photo is too large. The limit is 4 MB.');

        // The SNIFFED type decides, not the name: a .jpg is only a claim, and
        // this is the control that stops a script being written to disk.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);
        if (!isset(self::PHOTO_MIME[$mime])) {
            return $this->fail('That file is not a JPEG, PNG or WebP image.');
        }
        // It must also decode as an image of real size.
        $size = @getimagesize($tmp);
        if ($size === false || (int) $size[0] < 32 || (int) $size[1] < 32) {
            return $this->fail('That image could not be read.');
        }

        if (!$this->ensureStorage()) return $this->fail('The photo folder could not be created on the server.');

        // WE generate the name. The client's filename never touches the
        // filesystem — it is attacker-controlled text that may carry a path.
        $name = sprintf('emp%d_%s.%s', (int) $employee_id, bin2hex(random_bytes(6)), self::PHOTO_MIME[$mime]);
        if (!@move_uploaded_file($tmp, $this->storageRoot() . '/' . $name)) {
            return $this->fail('The photo could not be saved on the server.');
        }

        $previous = $this->profileRow($employee_id)['photo'] ?? null;

        if (!$this->upsertProfile($employee_id, [], $actor) || !$this->setPhotoColumn($employee_id, $name, $actor)) {
            @unlink($this->storageRoot() . '/' . $name);
            return $this->fail('Could not record the photo.');
        }

        // Or the folder grows by one orphan on every re-upload, for ever.
        $this->deletePhotoFile($previous);

        return ['result' => true, 'photo' => $name, 'message' => 'Photo updated.'];
    }

    /** photo is not in profileFieldMap (it is not a form field), so its own write. */
    private function setPhotoColumn($employee_id, $filename, $actor)
    {
        $stmt = $this->db->prepare("INSERT INTO employee_profile (employee_id, photo, updated_by) VALUES (?, ?, ?)
                                    ON DUPLICATE KEY UPDATE photo = VALUES(photo), updated_by = VALUES(updated_by)");
        if (!$stmt) return false;
        $stmt->bind_param('isi', $employee_id, $filename, $actor);
        return (bool) $stmt->execute();
    }

    private function deletePhotoFile($filename)
    {
        if (!$filename) return;
        $path = $this->photoPath($filename);
        if ($path) @unlink($path);
    }

    public function save_photo()
    {
        if (!$this->canWrite()) return $this->denied();
        return $this->writePhoto((int) ($_POST['employee_id'] ?? 0), $_FILES['photo'] ?? [], $this->actor());
    }

    /** The employee changes their own picture from the portal. */
    public function my_save_photo()
    {
        $employee_id = (int) ($_SESSION['emp_id'] ?? 0);
        if ($employee_id <= 0) return $this->fail('Please sign in again.');
        return $this->writePhoto($employee_id, $_FILES['photo'] ?? [], null);
    }

    private function removePhoto($employee_id, $actor)
    {
        if (!$this->tableExists('employee_profile')) return $this->missingSchema();

        $current = $this->profileRow($employee_id)['photo'] ?? null;
        if (!$current) return $this->fail('There is no photo to remove.');

        if (!$this->setPhotoColumn($employee_id, null, $actor)) return $this->fail('Could not remove the photo.');
        $this->deletePhotoFile($current);

        return ['result' => true, 'message' => 'Photo removed.'];
    }

    public function delete_photo()
    {
        if (!$this->canWrite()) return $this->denied();
        return $this->removePhoto((int) ($_POST['employee_id'] ?? 0), $this->actor());
    }

    public function my_delete_photo()
    {
        $employee_id = (int) ($_SESSION['emp_id'] ?? 0);
        if ($employee_id <= 0) return $this->fail('Please sign in again.');
        return $this->removePhoto($employee_id, null);
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Shared by the module traits
     * ──────────────────────────────────────────────────────────────────── */

    /** The signed-in employee (portal session), or 0. Never from the request. */
    private function selfId()
    {
        return (int) ($_SESSION['emp_id'] ?? 0);
    }

    private function needTable($table)
    {
        return $this->tableExists($table)
            ? null
            : $this->fail('The HR tables are missing. Run migrations/2026_11_hr_modules.sql on this database.');
    }

    /**
     * A trimmed text field from $src, or null when blank.
     * Returns [value, error]; error is null when the value is acceptable.
     */
    private function textField(array $src, $key, $label, $max, $required = false, array $values = [])
    {
        $v = trim((string) ($src[$key] ?? ''));
        if ($v === '') {
            return [null, $required ? $label . ' is required.' : null];
        }
        if (mb_strlen($v) > $max) {
            return [null, $label . ' is too long (max ' . $max . ' characters).'];
        }
        if ($values && !in_array($v, $values, true)) {
            return [null, $label . ' is not one of the allowed values.'];
        }
        return [$v, null];
    }

    /**
     * A date field as Y-m-d, or null when blank. Returns [value, error].
     * $rule: 'any', 'past' (today or earlier) or 'future' (today or later).
     */
    private function dateField(array $src, $key, $label, $required = false, $rule = 'any')
    {
        $v = trim((string) ($src[$key] ?? ''));
        if ($v === '') {
            return [null, $required ? $label . ' is required.' : null];
        }
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            return [null, $label . ' is not a valid date.'];
        }
        $today = date('Y-m-d');
        if ($rule === 'past' && $v > $today)   return [null, $label . ' cannot be in the future.'];
        if ($rule === 'future' && $v < $today) return [null, $label . ' cannot be in the past.'];
        return [$v, null];
    }

    /** A non-negative decimal, or null when blank. Returns [value, error]. */
    private function moneyField(array $src, $key, $label, $max = 99999999)
    {
        $v = trim(str_replace(',', '', (string) ($src[$key] ?? '')));
        if ($v === '') return [null, null];
        if (!is_numeric($v) || (float) $v < 0 || (float) $v > $max) {
            return [null, $label . ' must be a number between 0 and ' . number_format($max) . '.'];
        }
        return [round((float) $v, 2), null];
    }

    /** Does row $id of $table belong to $employee_id? (Own SELECT — see save_dependent.) */
    private function ownsRow($table, $id, $employee_id)
    {
        $stmt = $this->db->prepare("SELECT id FROM `$table` WHERE id = ? AND employee_id = ? LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('ii', $id, $employee_id);
        $stmt->execute();
        return (bool) $stmt->get_result()->fetch_assoc();
    }

    /** Rows of a prepared SELECT bound to one int. */
    private function rowsFor($sql, $id)
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        $id = (int) $id;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res  = $stmt->get_result();
        $rows = [];
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
        return $rows;
    }

    /** "Juan D. Cruz" for one employee, or '' when unknown. */
    public function employeeName($employee_id)
    {
        $stmt = $this->db->prepare("SELECT firstname, middlename, lastname, ext FROM employee WHERE id = ?");
        $employee_id = (int) $employee_id;
        $stmt->bind_param('i', $employee_id);
        $stmt->execute();
        $e = $stmt->get_result()->fetch_assoc();
        if (!$e) return '';
        $mi = trim((string) $e['middlename']) !== '' ? mb_substr(trim($e['middlename']), 0, 1) . '.' : '';
        return trim(preg_replace('/\s+/', ' ', $e['firstname'] . ' ' . $mi . ' ' . $e['lastname'] . ' ' . $e['ext']));
    }

    /**
     * Tell one employee something: the portal bell, plus a push to their
     * registered browsers when $push is true.
     */
    private function notifyEmployee($employee_id, $title, $message, $icon = 'ri-notification-3-line', $color = 'primary', $push = true)
    {
        $employee_id = (int) $employee_id;
        if ($employee_id <= 0) return;
        $link = 'employee-portal.php';
        $stmt = $this->db->prepare("INSERT INTO notifications (user_id, recipient_type, title, message, icon, color, link)
                                    VALUES (?, 'employee', ?, ?, ?, ?, ?)");
        if ($stmt) {
            $title   = mb_substr($title, 0, 150);
            $message = mb_substr($message, 0, 500);
            $stmt->bind_param('isssss', $employee_id, $title, $message, $icon, $color, $link);
            $stmt->execute();
        }
        if ($push && self::$push) {
            try {
                require_once __DIR__ . '/fcm.php';
                if (function_exists('fcm_push_employee')) {
                    fcm_push_employee($this->db, $employee_id, $title, $message, $link, 'hr');
                }
            } catch (\Throwable $e) { /* best-effort: the bell row above is the record */ }
        }
    }

    /** Tell Admin and HR staff: the bell for each, plus a push to their browsers. */
    private function notifyHrStaff($title, $message, $icon = 'ri-notification-3-line', $color = 'primary', $link = null)
    {
        $roles = [1, ROLE_HR];
        $res = $this->db->query("SELECT id FROM users WHERE role IN (" . implode(',', $roles) . ") AND status = 1");
        $stmt = $this->db->prepare("INSERT INTO notifications (user_id, recipient_type, title, message, icon, color, link)
                                    VALUES (?, 'user', ?, ?, ?, ?, ?)");
        $title   = mb_substr($title, 0, 150);
        $message = mb_substr($message, 0, 500);
        while ($res && $stmt && ($u = $res->fetch_assoc())) {
            $uid = (int) $u['id'];
            $stmt->bind_param('isssss', $uid, $title, $message, $icon, $color, $link);
            $stmt->execute();
        }
        if (!self::$push) return;
        try {
            require_once __DIR__ . '/fcm.php';
            if (function_exists('fcm_push_role')) {
                fcm_push_role($this->db, $roles, $title, $message, $link ?: 'index.php', 'hr');
            }
        } catch (\Throwable $e) { /* best-effort, like admin_class::notifyRole */ }
    }

    /**
     * Make storage/hr/<sub> (and the deny-all rule above it) on first use.
     * Returns the absolute folder, or null when it could not be created.
     */
    private function storageFolder($sub)
    {
        $dir = __DIR__ . '/' . self::STORAGE_DIR . '/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return null;
        $deny = __DIR__ . '/' . self::STORAGE_DIR . '/.htaccess';
        if (!is_file($deny)) {
            @file_put_contents($deny, "# HR uploads: never served directly — hr-file.php streams them\n"
                . "Require all denied\n");
        }
        return $dir;
    }
}

/* ─────────────────────────────────────────────────────────────────────────
 * Page helpers — used by the views, outside the class so a template can call
 * them without building a controller.
 * ──────────────────────────────────────────────────────────────────────── */

/**
 * The best picture for one employee, as a URL, or null for none.
 *
 * Order: the photo HR (or the employee) uploaded, then the face registered on
 * the kiosk, then nothing — every caller renders initials in that last case.
 * The uploaded photo wins because somebody chose it; the kiosk face is a frame
 * grabbed at enrollment.
 */
if (!function_exists('employee_avatar')) {
    function employee_avatar(mysqli $db, $employee_id)
    {
        $employee_id = (int) $employee_id;
        if ($employee_id <= 0) return null;

        $res = $db->query("SHOW TABLES LIKE 'employee_profile'");
        if ($res && $res->num_rows) {
            $stmt = $db->prepare("SELECT photo FROM employee_profile WHERE employee_id = ? AND photo IS NOT NULL AND photo <> ''");
            $stmt->bind_param('i', $employee_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                // Through the gatekeeper, never a direct file path.
                return 'hr-file.php?kind=photo&employee_id=' . $employee_id
                     . '&v=' . substr(md5($row['photo']), 0, 8);
            }
        }

        require_once __DIR__ . '/component/face_status.php';
        return employee_face_photo($db, $employee_id);
    }
}
